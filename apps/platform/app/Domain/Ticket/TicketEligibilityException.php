<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use RuntimeException;

/**
 * One shared, typed error channel for every eligibility gate in the two-phase ticket
 * pipeline (REQ-TKT-002). $code matches apps/web's BlackRedEligibilityCode union
 * exactly (see apps/web/src/mocks/blackred.ts) so the controller can pass it straight
 * through as the API's error code.
 */
final class TicketEligibilityException extends RuntimeException
{
    // Named errorCode, not code — Exception already declares a non-readonly $code
    // property, and PHP forbids redeclaring it as readonly.
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
