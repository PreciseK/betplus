<?php

declare(strict_types=1);

namespace BlackRed\Auth;

use BlackRed\Bootstrap\Config;
use RuntimeException;

/**
 * Password hashing using Argon2id.
 *
 * Argon2id is the modern recommendation (winner of the Password Hashing
 * Competition, recommended by OWASP). PHP has supported it natively since
 * 7.3 with no extension required.
 *
 * We tune the cost parameters from .env so we can adjust without code
 * changes if hash time becomes a problem in production.
 *
 * Defaults match PHP's defaults (memory=65536KB, time=4, threads=1) which
 * give a hash time around 50-100ms on typical shared hosting hardware.
 * That's the sweet spot — fast enough not to DoS the server with a few
 * concurrent logins, slow enough that brute-forcing a stolen hash is
 * infeasible.
 */
final class PasswordHasher
{
    private array $options;

    public function __construct(Config $config)
    {
        $this->options = [
            'memory_cost' => $config->int('PASSWORD_MEMORY_COST', PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
            'time_cost'   => $config->int('PASSWORD_TIME_COST', PASSWORD_ARGON2_DEFAULT_TIME_COST),
            'threads'     => $config->int('PASSWORD_THREADS', PASSWORD_ARGON2_DEFAULT_THREADS),
        ];
    }

    /**
     * Hash a plaintext password. Returns the encoded hash string ready to
     * store in `player.passwordHash`.
     */
    public function hash(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new RuntimeException('Cannot hash an empty password');
        }
        $hash = password_hash($plaintext, PASSWORD_ARGON2ID, $this->options);
        if ($hash === false) {
            throw new RuntimeException('Password hashing failed');
        }
        return $hash;
    }

    /**
     * Verify a plaintext password against a stored hash. Returns true on match.
     *
     * Internally uses a constant-time comparison via password_verify(), which
     * defeats timing attacks.
     */
    public function verify(string $plaintext, string $hash): bool
    {
        if ($plaintext === '' || $hash === '') {
            return false;
        }
        return password_verify($plaintext, $hash);
    }

    /**
     * Check whether a hash was generated with weaker parameters than current.
     * If true, the caller should re-hash and update the stored hash on next
     * successful login.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
