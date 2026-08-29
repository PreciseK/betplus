<?php

declare(strict_types=1);

// PRD C-04: identity vendor for NIN/BVN verification is an open procurement decision,
// not yet contracted. 'stub' is the only implementation until one is chosen — see
// Domain/Identity/Verification/StubIdentityVendor.php.
return [
    'driver' => env('IDENTITY_VENDOR', 'stub'),
];
