<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;

/**
 * Sends templated, versioned SMS (REQ-NOT-002) via Africa's Talking.
 * Populates provider, providerMsgId, costKobo, and sent status in smsLog.
 */
final class SmsSender
{
    /** @param array<string, string> $vars */
    public function send(string $msisdn, string $templateKey, array $vars = [], ?int $playerId = null): SmsLog
    {
        $template = config("smsTemplates.$templateKey");
        abort_unless($template !== null, 500, "Unknown SMS template: $templateKey");

        $body = strtr($template, array_combine(
            array_map(fn (string $k) => ":$k", array_keys($vars)),
            array_values($vars),
        ));

        $apiKey = (string) config('services.africastalking.api_key');
        $username = (string) config('services.africastalking.username', 'sandbox');
        $isSandbox = (bool) config('services.africastalking.sandbox', true);
        $from = config('services.africastalking.from');

        $provider = null;
        $providerMsgId = null;
        $status = 'queued';
        $failureReason = null;
        $costKobo = null;
        $sentAt = null;

        if ($apiKey !== '') {
            $endpoint = $isSandbox
                ? 'https://api.sandbox.africastalking.com/version1/messaging'
                : 'https://api.africastalking.com/version1/messaging';

            $postData = [
                'username' => $username,
                'to' => $msisdn,
                'message' => $body,
            ];

            if (!empty($from)) {
                $postData['from'] = (string) $from;
            }

            try {
                $response = Http::asForm()
                    ->withHeaders([
                        'apiKey' => $apiKey,
                        'Accept' => 'application/json',
                    ])
                    ->timeout(10)
                    ->post($endpoint, $postData);

                $provider = 'africastalking';

                if ($response->successful()) {
                    $json = $response->json();
                    $recipients = $json['SMSMessageData']['Recipients'] ?? [];
                    $firstRecipient = $recipients[0] ?? null;

                    if ($firstRecipient !== null && in_array($firstRecipient['status'] ?? '', ['Success', 'Sent'], true)) {
                        $status = 'sent';
                        $providerMsgId = (string) ($firstRecipient['messageId'] ?? '');
                        $sentAt = now();

                        // Parse cost if present, e.g. "NGN 2.2000"
                        if (isset($firstRecipient['cost']) && preg_match('/([\d\.]+)/', (string) $firstRecipient['cost'], $matches)) {
                            $costKobo = (int) round(((float) $matches[1]) * 100);
                        }
                    } else {
                        $status = 'failed';
                        $failureReason = (string) ($firstRecipient['status'] ?? ($json['SMSMessageData']['Message'] ?? 'Failed to send'));
                    }
                } else {
                    $status = 'failed';
                    $failureReason = 'HTTP ' . $response->status() . ': ' . $response->body();
                }
            } catch (\Throwable $e) {
                $status = 'failed';
                $failureReason = $e->getMessage();
            }
        }

        return SmsLog::create([
            'playerId' => $playerId,
            'msisdn' => $msisdn,
            'category' => explode('.', $templateKey)[0],
            'templateVersion' => $templateKey,
            'messageBody' => $body,
            'provider' => $provider,
            'providerMsgId' => $providerMsgId,
            'status' => $status,
            'failureReason' => $failureReason,
            'costKobo' => $costKobo,
            'sentAt' => $sentAt,
            'queuedAt' => now(),
        ]);
    }
}
