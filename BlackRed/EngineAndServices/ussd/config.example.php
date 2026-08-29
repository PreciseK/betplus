<?php
/**
 * BlackRed USSD config.
 *
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored — never commit credentials.
 *
 * On production: chmod 600 config.php (owner read/write only).
 */

return [
    // -------------------------------------------------------------------------
    // Database — SAME database as the web app
    // -------------------------------------------------------------------------
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'amoamvfc_br_mainDB',
        'user'    => 'amoamvfc_brCoreUser',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // -------------------------------------------------------------------------
    // USSD entry — what string Nalo sends on the first turn
    //
    // Telecel sends "*920*9#", MTN/AirtelTigo send "*920*9".
    // We accept all variants. Add the exact strings you see in ussd.log.
    // -------------------------------------------------------------------------
    'ussd' => [
        'triggers' => ['*920*9', '*920*9#'],
        'session_timeout_minutes' => 5,
    ],

    // -------------------------------------------------------------------------
    // ANM Orchard — MoMo gateway (filled in PR 3+)
    //
    // callback_url: URL ANM calls when a MoMo deposit/payout completes.
    // The USSD app has its OWN callback endpoint at /callbacks/momo.php,
    // separate from the web app's /api/callbacks/momo.
    //
    // ANM lets us override callback_url per request, so the USSD app passes
    // this URL on every deposit/withdraw API call. Web app's callbacks are
    // unaffected — they keep using their own URL via the same service_id.
    //
    // Dev (USSD app at /ussd/ on the same host as web):
    //   https://pncgw.engboxx.com/ussd/callbacks/momo.php
    //
    // Prod (TBD — set when web app cuts over to play.blackredgame.com):
    //   https://play.blackredgame.com/ussd/callbacks/momo.php
    //   (or whatever path the USSD app sits at in prod)
    // -------------------------------------------------------------------------
    'anm' => [
        'base_url'    => 'https://orchard-api.anmgw.com',
        'client_key'  => 'CHANGE_ME',
        'secret_key'  => 'CHANGE_ME',
        'service_id'  => 70,
        'callback_url' => 'https://pncgw.engboxx.com/ussd/callbacks/momo.php',
        'timeout_sec'  => 180,
    ],

    // -------------------------------------------------------------------------
    // Hubtel SMS — Programmable SMS API
    //
    // Auth: HTTP Basic Auth with client_id:client_secret encoded in the
    // Authorization header. Credentials NEVER appear in the URL or query
    // string (the GET-style "URL auth" sample in Hubtel's docs leaks creds
    // into HTTP access logs — we don't use it).
    //
    // sender_id must be pre-registered with Hubtel ("BlackRed", up to 11 chars).
    // -------------------------------------------------------------------------
    'hubtel' => [
        'base_url'      => 'https://smsc.hubtel.com/v1/messages/send',
        'client_id'     => 'CHANGE_ME',
        'client_secret' => 'CHANGE_ME',
        'sender_id'     => 'BlackRedGme',
        'timeout_sec'   => 15,
    ],

    // -------------------------------------------------------------------------
    // Deposit limits (mirrors web app DEPOSIT_MIN/MAX_PESEWAS)
    //
    // Player is credited the full amount. 1% fee (DEPOSIT_FEE_BPS in web app)
    // is recorded as MOMO_FEE_EXPENSE in the ledger — BlackRed absorbs it.
    //
    // reference: appears on the user's MoMo PIN prompt (≤10 chars per ANM).
    // -------------------------------------------------------------------------
    'deposit' => [
        'min_pesewas' => 200,      // GHS 2
        'max_pesewas' => 500000,   // GHS 5,000
        'reference'   => 'BlackRed',
    ],

    // -------------------------------------------------------------------------
    // Game tuning — mirror values from web app's systemConfig where possible.
    // These are fallbacks read at boot; once SystemConfigService is copied in
    // PR 4 we'll read from DB at runtime.
    // -------------------------------------------------------------------------
    'game' => [
        'min_stake_pesewas'         => 200,      // GHS 2
        'max_stake_pesewas'         => 200000,   // GHS 2,000
        'daily_stake_limit_pesewas' => 2000000,  // GHS 20,000
        // Engine tuning — mirror web app's systemConfig defaults.
        'house_win_threshold_pct'    => 20,      // engine.houseWinThresholdPct
        'daily_revenue_floor_pesewas'=> 50000,   // engine.dailyRevenueFloorPesewas (GHS 500)
    ],

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------
    'log' => [
        'path' => __DIR__ . '/logs/ussd.log',
    ],

    // -------------------------------------------------------------------------
    // Environment marker — affects error verbosity in responses
    // -------------------------------------------------------------------------
    'env' => 'production', // 'development' shows raw errors in USSD MSG (helpful for smoke testing)
];
