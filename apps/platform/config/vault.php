<?php

declare(strict_types=1);

// Deliberately not app.php/APP_KEY — REQ-ID-023 requires independent access control,
// which a shared encryption key would quietly undermine.
return [
    'encryption_key' => env('VAULT_ENCRYPTION_KEY'),
];
