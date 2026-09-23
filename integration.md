# OPay Integration & Refactoring Specification

**Document Version:** 1.0.0  
**Target Platform:** Betplus / Buzzycash  
**Scope:** Migration from read-only wallet validation / simulated advance to full OPay Server-Side Collections (`BankAccount`, `input-pin`, `input-otp`, Webhooks, & Status Polling).

---

## 1. Executive Summary & Objective

In earlier iterations of Betplus, deposit funding was adapted to an interim "verified advance" model (`FundingService::collect()`) using OPay's Payout API (`/opay-wallet-validate` and `/balance`). While this verified that an active OPay wallet existed with matching KYC names, **it did not debit funds from the customer's wallet**, requiring the merchant to take 100% credit risk.

This specification details the comprehensive refactoring plan to implement OPay's official **Server-Side APIs** ([https://documentation.opaycheckout.com/](https://documentation.opaycheckout.com/)):
1. **USSD Channel**: Seamless in-session payment authorization using **`input-pin`** (eliminating SMS OTP latency and avoiding the 20–30 second telco USSD session timeout).
2. **Web Channel**: Support for direct wallet debit with dynamic challenge handling (`INPUT_PIN`, `INPUT_OTP`, and `REDIRECT_3DS`).
3. **Webhook & Verification**: Resilient callback ingestion with `HMAC-SHA3-512` signature verification and IP whitelisting.
4. **Status Polling & Settlement**: Scheduled status check fallback (`/cashier/status` / `/payment/status`) and reconciliation against the double-entry ledger.

---

## 2. Architectural Architecture & Dual-Rail Separation

Betplus maintains a strict cryptographic and domain separation between **Collections** (inbound customer funds) and **Payouts** (outbound winnings/disbursements).

```
┌────────────────────────────────────────────────────────────────────────┐
│                        CHANNELS & CLIENTS                              │
│   Web (Next.js)      USSD Gateway (Telco)      Mobile / Admin Portals  │
└─────────┬──────────────────────┬──────────────────────────┬────────────┘
          │                      │                          │
          └──────────────────────┼──────────────────────────┘
                                 │ HTTPS (JSON API / v1)
                                 ▼
┌────────────────────────────────────────────────────────────────────────┐
│                     BETPLUS PLATFORM (Laravel)                         │
│                                                                        │
│   ┌────────────────────────────────────────────────────────────────┐   │
│   │                      Domain: Payments                          │   │
│   │                                                                │   │
│   │  [Collections Rail - Inbound]      [Payouts Rail - Outbound]   │   │
│   │  - OpayCollectionGateway           - OpayPayoutGateway         │   │
│   │  - OpayCollectionSigner (HMAC)     - OpayPayoutSigner (RSA)    │   │
│   │  - Callback & Webhook Verifier     - Payout Callback Verifier  │   │
│   │  - Input-PIN / Input-OTP Service   - Balance & Float Monitor   │   │
│   └──────────────────────┬─────────────────────────┬───────────────┘   │
│                          │                         │                   │
│                          ▼                         ▼                   │
│              Double-Entry Ledger & Wallet Service                      │
│              (PLAYER_PLAY, SUSPENSE, PAYMENT_CLEARING:OPAY)            │
└──────────────────────────┬─────────────────────────┬───────────────────┘
                           │                         │
        HMAC-SHA512 Secret │                         │ 2048-bit RSA Key
                           ▼                         ▼
┌────────────────────────────────────────────────────────────────────────┐
│                        OPAY PAYMENT GATEWAY                            │
│                                                                        │
│   [Server-Side APIs - Collections]     [Payout APIs - Disbursements]   │
│   - /payment/create                    - /payout/createSingleOrder     │
│   - /payment/action/input-pin          - /payout/queryorder            │
│   - /payment/input-otp                 - /payout/opay-wallet-validate  │
│   - /cashier/status                    - /payout/balance               │
└────────────────────────────────────────────────────────────────────────┘
```

### Signature Separation Matrix

| Rail | Direction | Auth Header | Algorithm | Key Material |
| :--- | :--- | :--- | :--- | :--- |
| **Collections** | Inbound (Deposit) | `Authorization: Bearer {signature}` | `HMAC-SHA512` | Merchant `SecretKey` |
| **Callbacks** | Webhook Notification | Included in body: `"sha512": "..."` | `HMAC-SHA3-512` | Formatted payload + Merchant `SecretKey` |
| **Payouts** | Outbound (Withdrawal) | `Authorization: Bearer {signature}` | `RSA-SHA256` | 2048-bit Merchant Private Key |

---

## 3. Environment & Configuration Settings

Add the following environment variables to `apps/platform/.env` and `apps/platform/.env.example`:

```dotenv
# OPay Collections (Server-Side APIs)
OPAY_COLLECTION_BASE_URL=https://testapi.opaycheckout.com
OPAY_COLLECTION_MERCHANT_ID=2566XXXXXXXXXXX
OPAY_COLLECTION_SECRET_KEY=OPAYPRVXXXXXXXXXXXXXXXXXXXXXXXXXXXX
OPAY_COLLECTION_CALLBACK_URL=https://api.betplus.com.ng/internal/opay/callback/collection

# CIDR source ranges for incoming callbacks (comma-separated; empty in local dev)
OPAY_CALLBACK_IP_ALLOWLIST=102.164.0.0/16,197.210.0.0/16

# OPay Payouts & Disbursements (RSA-SHA256)
OPAY_PAYOUT_BASE_URL=https://testapi.opaycheckout.com
OPAY_PAYOUT_MERCHANT_ID=2566XXXXXXXXXXX
OPAY_PAYOUT_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----"
OPAY_PAYOUT_NOTIFY_URL=https://api.betplus.com.ng/internal/opay/callback/payout
```

---

## 4. OPay Server-Side API Specifications

### 4.1 Payment Initiation (`/payment/create`)
* **Endpoint**: `POST /api/v1/international/payment/create`
* **Headers**:
  ```http
  Authorization: Bearer {hmac_sha512_signature}
  MerchantId: {merchant_id}
  Content-Type: application/json
  ```
* **Request Body**:
  ```json
  {
    "reference": "col_01HZX87654ABCD1234",
    "country": "NG",
    "payAmount": {
      "currency": "NGN",
      "total": 50000
    },
    "payMethod": "BankAccount",
    "bankAccount": {
      "bankAccountNumber": "8031234567",
      "bankCode": "033",
      "customerName": "John Doe"
    },
    "product": {
      "name": "Play Balance Deposit",
      "description": "Betplus Wallet Funding"
    },
    "callbackUrl": "https://api.betplus.com.ng/internal/opay/callback/collection",
    "userPhone": "+2348031234567"
  }
  ```
* **Response (`actionType: INPUT_PIN`)**:
  ```json
  {
    "code": "00000",
    "message": "SUCCESSFUL",
    "data": {
      "reference": "col_01HZX87654ABCD1234",
      "orderNo": "220106144664358274",
      "status": "PENDING",
      "nextAction": {
        "actionType": "INPUT_PIN"
      },
      "amount": {
        "total": 50000,
        "currency": "NGN"
      }
    }
  }
  ```

---

### 4.2 Submit PIN (`/payment/action/input-pin`)
Used on the **USSD channel** and embedded in-app checkout modals.
* **Endpoint**: `POST /api/v1/payment/action/input-pin`
* **Request Body**:
  ```json
  {
    "country": "NG",
    "orderNo": "220106144664358274",
    "pin": "1234"
  }
  ```
* **Response**:
  ```json
  {
    "code": "00000",
    "message": "SUCCESSFUL",
    "data": {
      "reference": "col_01HZX87654ABCD1234",
      "orderNo": "220106144664358274",
      "status": "SUCCESS"
    }
  }
  ```

---

### 4.3 Submit OTP (`/payment/input-otp`)
Used for browser/web flows requiring SMS OTP confirmation.
* **Endpoint**: `POST /api/v1/international/payment/input-otp`
* **Request Body**:
  ```json
  {
    "country": "NG",
    "orderNo": "220106144664358274",
    "otp": "543210"
  }
  ```

---

### 4.4 Payment Status Query (`/cashier/status`)
* **Endpoint**: `POST /api/v1/international/cashier/status`
* **Request Body**:
  ```json
  {
    "country": "NG",
    "reference": "col_01HZX87654ABCD1234"
  }
  ```

---

## 5. End-to-End Implementation Flows

### 5.1 Flow 1: USSD Direct In-Turn Payment (`input-pin`)

The USSD channel cannot wait for SMS delivery without timing out. The flow uses in-session PIN prompt:

```
Player (Phone)           MenuEngine (USSD)          Platform API                OPay API
      │                         │                         │                        │
      │── 1. Dial Stake ₦500 ──>│                         │                        │
      │                         │── 2. Check balance ────>│                        │
      │                         │   (shortfall = ₦500)    │                        │
      │                         │── 3. Init Collection ──>│── 4. /payment/create ─>│
      │                         │                         │<─ 5. action: INPUT_PIN │
      │                         │<─ 6. Prompt for PIN ────│                        │
      │<─ 7. "Enter OPay PIN" ──│                         │                        │
      │                         │                         │                        │
      │── 8. Enters "1234" ────>│                         │                        │
      │                         │── 9. Submit PIN ───────>│── 10. /action/input-pin>│
      │                         │                         │<─ 11. status: SUCCESS ─│
      │                         │                         │                        │
      │                         │                         │── 12. Credit Ledger ───┤
      │                         │                         │   (Play Balance +₦500) │
      │                         │                         │── 13. Deduct Stake ────┤
      │                         │                         │   (Debit Play -₦500)   │
      │                         │<─ 14. Ticket Placed ────┤                        │
      │<─ 15. Win/Loss Screen ──┤                         │                        │
      │   + SMS Reference       │                         │                        │
```

#### Detailed MenuEngine State Machine Changes
1. **Screen `stake_confirm`**:
   * If play balance shortfall exists:
     * Call `PlatformClient::initFunding(msisdn, shortfallKobo)`.
     * Store returned `orderNo` and `reference` in `$session->data['opayOrderNo']`.
     * Transition session screen to `opay_pin_entry`.
     * Render: `CON Enter your 4-digit OPay PIN to approve NGN 500:`.
2. **Screen `opay_pin_entry`**:
   * Receive 4-digit PIN input.
   * Call `PlatformClient::submitFundingPin($orderNo, $pin)`.
   * If status is `SUCCESS`: proceed immediately to ticket placement (`completeBlackRedPurchase`, etc.).
   * If status is `FAILED`: render `END Payment authorization failed. Please check your PIN and balance.`.

---

### 5.2 Flow 2: Web Direct Modal (`OpayDirectCheckoutModal`)

1. User clicks **Pay via OPay**.
2. Frontend calls `walletGateway.initDeposit(amountKobo)`.
3. Platform calls `/payment/create` and returns `{ actionType: "INPUT_PIN", orderNo: "..." }`.
4. The modal shows a secure PIN keypad.
5. User enters PIN; frontend submits `walletGateway.submitDepositPin(orderNo, pin)`.
6. Platform calls `/payment/action/input-pin`, verifies debit, credits ledger, and closes modal.

---

### 5.3 Flow 3: Webhook Verification & Processing

* **Route**: `POST /internal/opay/callback/collection`
* **Middleware**: `VerifyOpayCollectionCallback`
  1. Verify client IP against `config('opay.callback_ip_allowlist')`.
  2. Parse JSON payload:
     ```php
     $payload = $request->input('payload');
     $signature = $request->input('sha512');
     ```
  3. Reconstruct canonical signature string:
     ```php
     $authString = sprintf(
         '{Amount:"%s",Currency:"%s",Reference:"%s",Refunded:%s,Status:"%s",Timestamp:"%s",Token:"%s",TransactionID:"%s"}',
         $payload['amount'],
         $payload['currency'],
         $payload['reference'],
         $payload['refunded'] ? 't' : 'f',
         $payload['status'],
         $payload['timestamp'],
         $payload['token'],
         $payload['transactionId']
     );
     $expected = hash_hmac('sha3-512', $authString, config('opay.collection_secret_key'));
     if (!hash_equals($expected, $signature)) {
         abort(401, 'Invalid signature');
     }
     ```
  4. Idempotently update `Collection` record:
     * If already `paid`: return `200 OK` (noop).
     * If `pending`: mark `paid`, credit double-entry ledger (`PLAYER_PLAY`), record analytics event.
  5. Return `response()->json(['code' => '00000', 'message' => 'SUCCESSFUL'], 200)`.

---

## 6. Codebase Refactoring Tasks

### 6.1 Platform API (`apps/platform`)

| Component / File | Action | Purpose |
| :--- | :--- | :--- |
| `app/Domain/Payments/Providers/Opay/OpayCollectionSigner.php` | **Update** | Support HMAC-SHA512 for API requests and HMAC-SHA3-512 for callback validation. |
| `app/Domain/Payments/Providers/Opay/OpayCollectionGateway.php` | **Create** | Dedicated gateway for `/payment/create`, `/action/input-pin`, `/input-otp`, `/cashier/status`. |
| `app/Domain/Payments/Providers/Opay/OpayGateway.php` | **Refactor** | Retain strictly for Payouts (`createSingleOrder`, `opay-wallet-validate`, `/balance`). |
| `app/Domain/Wallet/FundingService.php` | **Refactor** | Replace simulated wallet advance with real collection initiation (`initCollection`, `submitPin`, `submitOtp`). |
| `app/Http/Controllers/Api/V1/WalletController.php` | **Update** | Expose `/v1/wallet/funding/init`, `/v1/wallet/funding/pin`, `/v1/wallet/funding/otp`. |
| `app/Http/Controllers/Internal/OpayCallbackController.php` | **Update** | Route and process collection callbacks with canonical string verification. |
| `app/Http/Middleware/VerifyOpayCollectionCallback.php` | **Create** | Authenticate incoming OPay webhooks. |
| `database/migrations/xxxx_add_order_no_to_collections_table.php` | **Create** | Add `opay_order_no` column to `collections` table. |

### 6.2 USSD Gateway (`apps/ussd`)

| Component / File | Action | Purpose |
| :--- | :--- | :--- |
| `src/MenuEngine.php` | **Refactor** | Implement `screenOpayPinEntry` to handle in-session PIN collection during shortfall funding. |
| `src/PlatformClientInterface.php` | **Update** | Add `initFunding()` and `submitFundingPin()`. |
| `src/PlatformClient.php` | **Update** | HTTP client calls to platform's new funding endpoints. |
| `tests/MenuEngineTest.php` | **Update** | Test end-to-end USSD session turns with PIN challenge. |

### 6.3 Web Application (`apps/web` & `packages/api-client`)

| Component / File | Action | Purpose |
| :--- | :--- | :--- |
| `packages/api-client/src/walletGateway.ts` | **Update** | Add API methods for initiating payment, submitting PIN, and submitting OTP. |
| `apps/web/src/components/wallet/OpayDirectCheckoutModal/` | **Refactor** | Add interactive PIN / OTP step depending on OPay `nextAction`. |
| `apps/web/src/components/wallet/FundingFlow/` | **Update** | Provide real-time debit confirmation UI. |

---

## 7. Security, Integrity & Compliance Guardrails

1. **Zero Logging of Sensitive Credentials**:
   * Raw PINs and OTPs must **never** be written to database tables or log files (`OpayApiCallLog`, Laravel logs, or USSD session tables).
   * Request sanitizers must mask `"pin"` and `"otp"` fields before persisting call logs.
2. **Strict Idempotency**:
   * All `/payment/create` calls supply a client-generated `reference` (ULID).
   * Webhook handlers and polling status checks match by `reference` and guarantee append-only journal entries.
3. **Integer Arithmetic**:
   * All monetary values remain integer kobo (`BIGINT`) with explicit `NGN` currency tags.
4. **Failure Modes & Fallback**:
   * If OPay is unreachable during USSD PIN submission, prompt the user with a graceful error and never credit the ledger uncollateralized.

---

## 8. Verification & Test Plan

1. **Unit & Gateway Tests**:
   * Signature generation against OPay documentation vector examples (`HMAC-SHA512` and `HMAC-SHA3-512`).
   * Payload serialization with unescaped slashes.
2. **USSD Menu Tests**:
   * Simulate a player with insufficient Play Balance attempting a bet.
   * Verify screen transition to PIN entry prompt.
   * Verify session continuation within the 20-second timeout.
3. **Webhook Simulation**:
   * Send valid and tampered HMAC-SHA3-512 payloads to `/internal/opay/callback/collection` to ensure invalid requests are rejected with `401`.
4. **End-to-End Staging Testing**:
   * Test with OPay sandbox credentials and test cards / bank accounts.
   * Verify real-time ledger balance updates in `ledgerEntry` and `walletTransaction`.

---

## 9. Environment Variables Configuration Reference

The following environment variables configure OPay across the platform in `apps/platform/.env` (mirrored in `apps/platform/.env.example` and loaded via `config/opay.php`):

| Variable | Required | Default | Description |
| :--- | :---: | :--- | :--- |
| `OPAY_BASE_URL` | Yes | `https://testapi.opaycheckout.com` | Base API URL. Production: `https://api.opaycheckout.com` |
| `OPAY_MERCHANT_ID` | Yes | - | Default OPay Merchant ID (provided by OPay) |
| `OPAY_PUBLIC_KEY` | Optional | - | Public key for client/cashier identification |
| **Collections (Inbound Deposits & USSD In-Session PIN)** | | | |
| `OPAY_COLLECTION_BASE_URL` | Optional | `${OPAY_BASE_URL}` | Endpoint for Collections Server APIs |
| `OPAY_COLLECTION_MERCHANT_ID` | Optional | `${OPAY_MERCHANT_ID}` | Merchant ID for collection endpoints |
| `OPAY_COLLECTION_SECRET_KEY` | **Yes** | - | Secret Key for HMAC-SHA512 request signing & HMAC-SHA3-512 callback verification |
| `OPAY_COLLECTION_PUBLIC_KEY` | Optional | `${OPAY_PUBLIC_KEY}` | Public key for collection services |
| `OPAY_COLLECTION_CALLBACK_URL` | Optional | `${APP_URL}/internal/opay/callback/collection` | Public webhook URL registered for collection payment notifications |
| **Payouts (Outbound Withdrawals)** | | | |
| `OPAY_PAYOUT_BASE_URL` | Optional | `${OPAY_BASE_URL}` | Endpoint for Payouts / Disbursements |
| `OPAY_PAYOUT_MERCHANT_ID` | Optional | `${OPAY_MERCHANT_ID}` | Merchant ID for payouts |
| `OPAY_PAYOUT_PRIVATE_KEY` | Yes (for payouts) | - | RSA Private Key (PEM format, PKCS#8) for signing payout requests |
| `OPAY_PAYOUT_PUBLIC_KEY` | Optional | - | RSA Public Key |
| `OPAY_PAYOUT_CALLBACK_SECRET` | Yes (for payouts) | - | Secret string to verify payout notification callbacks |
| `OPAY_PAYOUT_NOTIFY_URL` | Optional | `${APP_URL}/internal/opay/callback/payout` | Public webhook URL for payout notifications |
| **Security & Firewalling** | | | |
| `OPAY_CALLBACK_IP_ALLOWLIST` | Recommended | `""` (unrestricted in dev) | Comma-separated list of authorized OPay IP addresses/CIDRs |

