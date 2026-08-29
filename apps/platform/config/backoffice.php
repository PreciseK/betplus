<?php

declare(strict_types=1);

// Deliberately a separate key from both APP_KEY and VAULT_ENCRYPTION_KEY — see
// MfaSecretCipher's doc comment.
return [
    'mfa_encryption_key' => env('BACKOFFICE_MFA_ENCRYPTION_KEY'),
];
