<?php

declare(strict_types=1);

// Platform-wide default shown as "Daily limit" until Epic 5 ships player-settable RG
// limits (REQ-RG-002) — this is not itself an enforced control, purely a display
// default matching the frontend's existing mock (see BlackRedController::show()).
return [
    'default_daily_limit_kobo' => (int) env('GAME_DEFAULT_DAILY_LIMIT_KOBO', 5_000_000),
];
