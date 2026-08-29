<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\IdempotencyKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyService
{
    private const DEFAULT_TTL_HOURS = 24;

    /**
     * Compute hash of the request method, path and body.
     */
    public function computeRequestHash(Request $request): string
    {
        $payload = $request->getContent();
        return hash('sha256', $request->method() . '|' . $request->path() . '|' . $payload);
    }

    /**
     * Look up existing idempotency record or atomically reserve a new one.
     *
     * @return array{status: 'new'|'in_progress'|'completed'|'mismatch', record?: IdempotencyKey}
     */
    public function checkOrAcquire(?int $playerId, string $key, string $requestHash): array
    {
        // Check for active (unexpired) existing record
        $existing = IdempotencyKey::where('playerId', $playerId)
            ->where('key', $key)
            ->where('expiresAt', '>', now())
            ->first();

        if ($existing !== null) {
            if ($existing->requestHash !== $requestHash) {
                return ['status' => 'mismatch', 'record' => $existing];
            }

            if ($existing->status === 'completed') {
                return ['status' => 'completed', 'record' => $existing];
            }

            return ['status' => 'in_progress', 'record' => $existing];
        }

        // Clean up any previously expired key with same name
        IdempotencyKey::where('playerId', $playerId)->where('key', $key)->delete();

        try {
            $created = IdempotencyKey::create([
                'playerId' => $playerId,
                'key' => $key,
                'requestHash' => $requestHash,
                'status' => 'in_progress',
                'createdAt' => now(),
                'expiresAt' => Carbon::now()->addHours(self::DEFAULT_TTL_HOURS),
            ]);

            return ['status' => 'new', 'record' => $created];
        } catch (\Throwable) {
            // Concurrent insert race condition caught by unique index
            $concurrent = IdempotencyKey::where('playerId', $playerId)->where('key', $key)->first();
            if ($concurrent !== null && $concurrent->status === 'completed') {
                return ['status' => 'completed', 'record' => $concurrent];
            }
            return ['status' => 'in_progress', 'record' => $concurrent];
        }
    }

    /**
     * Cache the final HTTP response for the idempotency key.
     */
    public function saveResponse(IdempotencyKey $record, Response $response): void
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            // Filter sensitive headers
            if (!in_array(strtolower($name), ['set-cookie', 'authorization', 'x-csrf-token'])) {
                $headers[$name] = $values;
            }
        }

        $record->update([
            'status' => 'completed',
            'responseCode' => $response->getStatusCode(),
            'responseBody' => $response->getContent(),
            'responseHeaders' => $headers,
        ]);
    }

    /**
     * Mark an idempotency key as failed so future retries can proceed.
     */
    public function markFailed(IdempotencyKey $record): void
    {
        $record->delete();
    }
}
