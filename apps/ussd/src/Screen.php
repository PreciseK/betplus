<?php

declare(strict_types=1);

namespace Betplus\Ussd;

/** One rendered USSD screen — CON continues the session, END terminates it. */
final class Screen
{
    private function __construct(public readonly string $text, public readonly bool $continues)
    {
    }

    public static function continue(string $text): self
    {
        return new self($text, true);
    }

    public static function end(string $text): self
    {
        return new self($text, false);
    }

    /** Africa's Talking-style envelope — see MenuEngine's doc comment on why this convention. */
    public function render(): string
    {
        return ($this->continues ? 'CON ' : 'END ') . $this->text;
    }
}
