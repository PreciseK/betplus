<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\SmsLog;

/**
 * Sends templated, versioned SMS (REQ-NOT-002). No provider is wired yet — this
 * logs the message as queued and returns. Swap the body of send() for a real
 * provider call (see BlackRed/EngineAndServices for the old Hubtel integration
 * as reference) once Nigeria's SMS provider is chosen; smsLog's provider/
 * providerMsgId/status columns already exist for that.
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

        return SmsLog::create([
            'playerId' => $playerId,
            'msisdn' => $msisdn,
            'category' => explode('.', $templateKey)[0],
            'templateVersion' => $templateKey,
            'messageBody' => $body,
            'status' => 'queued',
            'queuedAt' => now(),
        ]);
    }
}
