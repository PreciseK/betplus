<?php

declare(strict_types=1);

// §7.9. No state registry vendor is contracted yet — mirrors identityVendor.php and
// jurisdiction.php's 'stub' driver situation.
return [
    'registry_driver' => env('REGISTRY_VENDOR', 'stub'),
];
