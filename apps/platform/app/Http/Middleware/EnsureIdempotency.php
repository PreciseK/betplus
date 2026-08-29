<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Payments\IdempotencyService;
use App\Models\Player;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function __construct(private readonly IdempotencyService $service)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce on state-mutating requests (POST, PUT, PATCH, DELETE)
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key')
            ?? $request->header('X-Idempotency-Key')
            ?? $request->input('idempotency_key');

        // If no idempotency key is passed, allow request to proceed normally
        if (empty($idempotencyKey) || !is_string($idempotencyKey)) {
            return $next($request);
        }

        /** @var Player|null $player */
        $player = $request->attributes->get('player');
        $playerId = $player?->id;

        $requestHash = $this->service->computeRequestHash($request);
        $result = $this->service->checkOrAcquire($playerId, $idempotencyKey, $requestHash);

        if ($result['status'] === 'mismatch') {
            return response()->json([
                'message' => 'Idempotency key mismatch: this key was already used with a different request payload.',
                'error' => 'IDEMPOTENCY_KEY_PAYLOAD_MISMATCH',
            ], 422);
        }

        if ($result['status'] === 'completed' && isset($result['record'])) {
            $record = $result['record'];
            $response = new JsonResponse(
                data: json_decode((string) $record->responseBody, true),
                status: (int) $record->responseCode,
                headers: (array) $record->responseHeaders,
            );
            $response->headers->set('Idempotent-Replay', 'true');
            $response->headers->set('Idempotency-Key', $idempotencyKey);
            return $response;
        }

        if ($result['status'] === 'in_progress') {
            return response()->json([
                'message' => 'A transaction with this idempotency key is currently processing. Please wait.',
                'error' => 'IDEMPOTENCY_CONCURRENT_REQUEST',
            ], 409);
        }

        // New request: process and save response
        $record = $result['record'] ?? null;
        try {
            $response = $next($request);

            if ($record !== null) {
                // Cache successful responses and clean client errors
                if ($response->getStatusCode() < 500) {
                    $this->service->saveResponse($record, $response);
                } else {
                    $this->service->markFailed($record);
                }
            }

            return $response;
        } catch (\Throwable $e) {
            if ($record !== null) {
                $this->service->markFailed($record);
            }
            throw $e;
        }
    }
}
