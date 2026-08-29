<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 6.1 (REQ-BO-001/011). Roles are the exact eight named in the PRD —
        // Support Agent, Support Lead, Finance, Compliance, Game Ops, Content Editor,
        // Cultural Reviewer, System Admin. One role per account (least privilege via a
        // small fixed set beats a permissions matrix nobody built yet).
        Schema::create('institutionUser', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150)->unique('uniq_institutionUser_email');
            $table->string('displayName', 150);
            $table->string('passwordHash');
            $table->string('role', 30)->comment('support_agent|support_lead|finance|compliance|game_ops|content_editor|cultural_reviewer|system_admin');
            $table->string('status', 20)->default('active')->comment('active|suspended');

            // MFA is mandatory for every account (REQ-BO-011) — mfaSecret is set at
            // account creation and mfaConfirmedAt is only set once the operator proves
            // possession by submitting one valid code, mirroring standard TOTP
            // enrolment flows. No account can sign in with mfaConfirmedAt null.
            $table->text('mfaSecretEncrypted');
            $table->dateTime('mfaConfirmedAt')->nullable();

            // Comma-separated CIDR-or-exact IPs. Enforced only for privileged roles
            // (system_admin, finance, compliance) per REQ-BO-011 — see
            // InstitutionAuthService::PRIVILEGED_ROLES.
            $table->string('ipAllowlist', 500)->nullable();

            $table->dateTime('lastLoginAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();
            $table->dateTime('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index('role', 'idx_institutionUser_role');
            $table->index('status', 'idx_institutionUser_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutionUser');
    }
};
