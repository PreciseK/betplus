<?php

declare(strict_types=1);

namespace App\Domain\Jurisdiction\Signals;

/** §7.7 — one resolved signal, before precedence combination. */
final readonly class LocationSignal
{
    public function __construct(
        public string $stateCode,
        public float $confidence,
        public bool $vpnOrProxyDetected,
    ) {
    }
}
