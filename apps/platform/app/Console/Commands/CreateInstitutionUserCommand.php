<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BackOffice\MfaSecretCipher;
use App\Domain\BackOffice\TotpService;
use App\Models\InstitutionUser;
use Illuminate\Console\Command;

/**
 * Back-office accounts have no self-registration path (unlike players) — someone has
 * to create the first System Admin. Prints the MFA secret once; there's no back-office
 * UI yet to display a QR code, so this is the enrolment path until one exists.
 */
class CreateInstitutionUserCommand extends Command
{
    protected $signature = 'backoffice:create-user {email} {role} {--name=}';
    protected $description = 'Story 6.1 — create an institution user (System Admin bootstrap, or any role)';

    public function handle(TotpService $totp, MfaSecretCipher $cipher): int
    {
        $email = $this->argument('email');
        $role = $this->argument('role');
        $validRoles = ['support_agent', 'support_lead', 'finance', 'compliance', 'game_ops', 'content_editor', 'cultural_reviewer', 'system_admin'];
        if (!in_array($role, $validRoles, true)) {
            $this->error('Invalid role. Must be one of: ' . implode(', ', $validRoles));

            return self::FAILURE;
        }

        if (InstitutionUser::where('email', $email)->exists()) {
            $this->error("$email already exists.");

            return self::FAILURE;
        }

        $password = bin2hex(random_bytes(12));
        $secret = $totp->generateSecret();

        InstitutionUser::create([
            'email' => $email,
            'displayName' => $this->option('name') ?? $email,
            'passwordHash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => $role,
            'status' => 'active',
            'mfaSecretEncrypted' => $cipher->encrypt($secret),
        ]);

        $this->info("Created $email ($role).");
        $this->warn('One-time password (record it now, it is not stored in plain text): ' . $password);
        $this->warn('TOTP secret for authenticator app enrolment: ' . $secret);

        return self::SUCCESS;
    }
}
