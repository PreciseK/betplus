<?php

declare(strict_types=1);

return [
    // Explicit activation, separate from configuration publication and game registry status.
    'enabled' => (bool) env('DOORS_ENABLED', false),
];
