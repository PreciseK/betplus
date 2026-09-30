<?php

declare(strict_types=1);

namespace Betplus\Ussd\Session;

/**
 * ponytail: Redis is the designed store (REQ-USSD-003) and no Redis server exists
 * in this dev sandbox (same situation Domain/Identity/SessionService.php documents
 * on the platform side) — this is the honest substitute for a single-box deployment
 * (REQ-HOST-001), not a permanent architecture. One JSON file per MSISDN (a phone
 * number only ever drives one live USSD session at a time in practice), flock()'d
 * for the "row-level locking per turn" requirement — genuinely a single-box
 * mechanism, not a distributed lock; swap for RedisSessionStore before scaling
 * beyond one host or before a real telco aggregator is contracted, whichever first.
 */
final class FileSessionStore implements SessionStore
{
    public function __construct(
        private readonly string $directory,
        private readonly ?string $encryptionKey = null,
    ) {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
    }

    public function get(string $sessionId, string $msisdn): ?Session
    {
        $session = $this->read($msisdn);

        return $session !== null && $session->sessionId === $sessionId ? $session : null;
    }

    public function put(Session $session): void
    {
        $path = $this->pathFor($session->msisdn);
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            return;
        }

        $payload = json_encode($session->toArray(), JSON_THROW_ON_ERROR);
        if ($this->encryptionKey !== null) {
            $payload = $this->encryptPayload($payload);
        }

        flock($fh, LOCK_EX);
        ftruncate($fh, 0);
        fwrite($fh, $payload);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    public function delete(string $sessionId, string $msisdn): void
    {
        $current = $this->read($msisdn);
        if ($current !== null && $current->sessionId === $sessionId) {
            @unlink($this->pathFor($msisdn));
        }
    }

    public function mostRecentFor(string $msisdn): ?Session
    {
        return $this->read($msisdn);
    }

    public function purgeExpired(int $ttlSeconds): int
    {
        $purged = 0;
        $cutoff = time() - $ttlSeconds;

        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $mtime = filemtime($path);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($path);
                $purged++;
            }
        }

        return $purged;
    }

    private function read(string $msisdn): ?Session
    {
        $path = $this->pathFor($msisdn);
        if (!is_file($path)) {
            return null;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            return null;
        }
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        if ($raw === false || $raw === '') {
            return null;
        }

        // Decrypt if a key is configured. Falls back to plaintext for files written
        // before encryption was enabled (migration-safe: plaintext JSON starts with '{').
        $json = $raw;
        if ($this->encryptionKey !== null && !str_starts_with(trim($raw), '{')) {
            $json = $this->decryptPayload($raw);
            if ($json === null) {
                return null;
            }
        }

        /** @var array{sessionId:string,msisdn:string,screen:string,data:array<string,mixed>,accessToken:?string,lastTouchedAt:int}|null $row */
        $row = json_decode($json, true);

        return $row !== null ? Session::fromArray($row) : null;
    }

    private function pathFor(string $msisdn): string
    {
        return $this->directory . '/' . hash('sha256', $msisdn) . '.json';
    }

    private function encryptPayload(string $json): string
    {
        /** @var string $key non-null: callers check before calling */
        $key = $this->encryptionKey;
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($json, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $ciphertext);
    }

    private function decryptPayload(string $data): ?string
    {
        /** @var string $key */
        $key = $this->encryptionKey;
        $raw = base64_decode($data, true);
        if ($raw === false || strlen($raw) < 17) {
            return null;
        }
        $iv = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $result = openssl_decrypt($ciphertext, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);

        return $result === false ? null : $result;
    }
}
