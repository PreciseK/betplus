<?php

declare(strict_types=1);

namespace Betplus\Ussd\Http;

use RuntimeException;

/**
 * Thin curl wrapper — no HTTP client library is a dependency here (composer.json
 * names only ext-curl/ext-json), matching this app's "holds no framework magic"
 * design (phpstan.neon.dist's own comment).
 */
final class HttpClient
{
    public function __construct(private readonly int $timeoutSeconds)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, json: array<string, mixed>}
     */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Could not initialise a curl handle for $url");
        }

        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Platform request to $url failed: $error");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode((string) $raw, true);

        return ['status' => $status, 'json' => is_array($json) ? $json : []];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, json: array<string, mixed>}
     */
    public function getJson(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Could not initialise a curl handle for $url");
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Platform request to $url failed: $error");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode((string) $raw, true);

        return ['status' => $status, 'json' => is_array($json) ? $json : []];
    }
}
