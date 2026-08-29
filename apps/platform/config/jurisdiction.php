<?php

declare(strict_types=1);

// §7.7. geo_min_confidence default per REQ-GEO-005. stub_state_code/stub_confidence
// feed StubLocationSignalProvider until a real geolocation vendor is contracted.
return [
    'driver' => env('LOCATION_SIGNAL_PROVIDER', 'stub'),
    'min_confidence' => (float) env('GEO_MIN_CONFIDENCE', 0.80),
    'stub_state_code' => env('GEO_STUB_STATE_CODE', 'LAG'),
    'stub_confidence' => (float) env('GEO_STUB_CONFIDENCE', 0.95),
    'ruleset_version' => env('JURISDICTION_RULESET_VERSION', '2026.1'),
];
