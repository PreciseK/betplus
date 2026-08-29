<?php

declare(strict_types=1);

return [
    // Shared secret between apps/ussd and the platform for /internal/ussd/* — see
    // UssdGatewaySigner's doc comment on why this is a generic HMAC rather than a
    // specific vendor's scheme.
    'gateway_shared_secret' => env('USSD_GATEWAY_SHARED_SECRET', ''),
    'gateway_ip_allowlist' => env('USSD_GATEWAY_IP_ALLOWLIST', ''),

    // REQ-USSD-004 — redialling within this window offers "Continue your last play".
    'resume_window_minutes' => 10,
    // REQ-USSD-003 — 7-minute application-side session window; background cleanup
    // purges anything idle beyond it.
    'session_ttl_minutes' => 7,
];
