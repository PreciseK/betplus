import type { Metadata } from "next";
import { LegalPage, type LegalSection } from "@/components/legal/LegalPage";

export const metadata: Metadata = {
  title: "Privacy Policy | Betplus",
  description: "How Betplus handles account, identity, payment and gameplay information, and the choices available to you.",
};

const sections: LegalSection[] = [
  { id: "about", title: "About this policy", content: <>
    <p>This policy explains how Betplus handles personal information when you browse our website, create an account, use a wallet or play BlackRed, Heritage and Caged. It also covers related mobile and USSD services where offered.</p>
    <p>Betplus is operated by <strong>Betplus Integrated Services Limited</strong>, 27th Amore Street, Toyin, Allen, Lagos, Nigeria. We are the data controller for account and gameplay information. Contact us at <a href="mailto:support@betplus.com.ng">support@betplus.com.ng</a>. Payment providers and telecommunications providers may also handle information under their own privacy notices.</p>
  </> },
  { id: "information", title: "Information we handle", content: <>
    <ul>
      <li><strong>Account details:</strong> your phone number, registered name, verification status and account preferences.</li>
      <li><strong>Identity checks:</strong> identity information submitted for verification, which may include NIN or BVN, and verification results needed for your account tier.</li>
      <li><strong>Wallet activity:</strong> payment references, deposit and withdrawal amounts, linked OPay details, balances and transaction status.</li>
      <li><strong>Gameplay and protection:</strong> tickets, stakes, selections, outcomes, winnings, play limits, exclusions and account restrictions.</li>
      <li><strong>Technical and support records:</strong> IP address, browser and device information, security logs, session identifiers and information you provide when raising an issue.</li>
    </ul>
    <p>Information comes from you, your use of the service, and providers that confirm identity or payment results. Do not send passwords, verification codes or payment PINs in a support message.</p>
  </> },
  { id: "purposes", title: "Why we use information", content: <>
    <p>We use account and transaction information to register and authenticate you, maintain your wallet, process tickets, settle winnings and provide receipts. These activities are necessary to provide the service you request.</p>
    <p>Identity, transaction and protection records also support applicable verification, financial-recordkeeping and regulatory obligations. Security and usage records help us investigate errors, prevent fraud and protect accounts, on the basis of legitimate interests where those interests do not override your rights.</p>
    <p>Where consent is required for optional marketing or other optional processing, it must be requested separately. You can withdraw that consent without affecting earlier lawful processing. Essential account and transaction messages are separate from marketing.</p>
  </> },
  { id: "sharing", title: "Who may receive information", content: <>
    <p>Relevant information may be shared with OPay for collections and payouts, identity-verification providers, SMS and telecommunications providers, and hosting or security providers that support the service. Access should be limited to what is needed for their role.</p>
    <p>Information may also be disclosed to competent authorities when legally required, or to professional advisers for disputes, audits and legal obligations. A corporate transfer involving personal information requires appropriate safeguards and notice where required.</p>
  </> },
  { id: "storage", title: "Cookies and device storage", content: <>
    <p>The web app uses session cookies and a sign-in indicator to support account access. It also uses local storage for preferences such as language, game sound and animations. Security checks, including Cloudflare Turnstile where enabled, may process technical browser and network information to distinguish legitimate requests from automated abuse.</p>
    <p>You can clear cookies and local storage through your browser. Doing so may sign you out or reset saved preferences. Required security and session storage supports the service; any optional tracking introduced later must be explained and appropriate choices provided before use.</p>
  </> },
  { id: "retention", title: "Keeping and protecting records", content: <>
    <p>Account records are kept while needed to provide the service. Transaction, verification, fraud-prevention and exclusion records may need to remain after account closure to meet applicable obligations, resolve disputes or enforce a requested exclusion. Closing an account does not automatically erase those records.</p>
    <p>Retention depends on the record type and applicable legal requirements. You can ask us for the retention period or criteria that apply to your records. Information is to be deleted or anonymised once it is no longer needed for those purposes.</p>
    <p>Access controls and technical safeguards help protect information. No online service can guarantee complete security. Keep your device and verification codes secure and report suspected account misuse promptly.</p>
  </> },
  { id: "transfers", title: "Processing outside Nigeria", content: <>
    <p>Where a service provider processes information outside Nigeria, the transfer requires a lawful basis and appropriate protection under the Nigeria Data Protection Act. Contact us for information about the recipients, processing locations and safeguards relevant to your information.</p>
  </> },
  { id: "rights", title: "Your privacy rights", content: <>
    <p>Subject to the conditions and exceptions in Nigerian data protection law, you can ask to access or correct your information, request erasure or restriction, object to processing, and request portability where applicable. You can also withdraw consent for processing that relies on it.</p>
    <p>If an automated decision has a legal or similarly significant effect on you, you may request human intervention and challenge it, subject to the applicable rules. We may need to verify your identity before responding to a request.</p>
    <p>You can raise a concern with the <a href="https://ndpc.gov.ng/">Nigeria Data Protection Commission</a>. See the Commission’s <a href="https://ndpc.gov.ng/download/nigeria-data-protection-act-2023">Nigeria Data Protection Act resources</a> for the legal framework.</p>
  </> },
  { id: "age", title: "Adults only", content: <>
    <p>Betplus is for people aged 18 and over. People under 18 must not create an account, submit identity information or play. If you believe a minor has supplied information, report it so the account and records can be reviewed.</p>
  </> },
  { id: "contact", title: "Requests and contact details", content: <>
    <p>Email <a href="mailto:support@betplus.com.ng?subject=Privacy%20request">support@betplus.com.ng</a> with the subject “Privacy request”, or write to Betplus Integrated Services Limited, 27th Amore Street, Toyin, Allen, Lagos, Nigeria. You do not need to sign in to make a request. Describe your request and provide enough information for us to identify the relevant account securely; we will explain any further verification needed and respond within the applicable legal timeframe.</p>
    <p>For account information and play-limit controls, visit <a href="/account">Account</a>. Privacy requests are separate from changing your play limits or closing your account.</p>
  </> },
  { id: "changes", title: "Changes to this policy", content: <>
    <p>The date and version above identify this document. Material changes should be clearly communicated before they apply, with new consent requested where necessary. Read our <a href="/terms-and-conditions">Terms &amp; Conditions</a> for the rules governing the service.</p>
  </> },
];

export default function PrivacyPolicyPage() {
  return <LegalPage kind="privacy" title="Privacy Policy" intro="Understand what information Betplus handles, why it is needed and the choices you have." sections={sections} />;
}
