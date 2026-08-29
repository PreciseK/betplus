<?php

declare(strict_types=1);

namespace Betplus\Ussd\Tests;

use Betplus\Ussd\CharacterLimit;
use Betplus\Ussd\Screen;
use PHPUnit\Framework\TestCase;

final class CharacterLimitTest extends TestCase
{
    public function testAShortScreenFits(): void
    {
        $this->assertTrue(CharacterLimit::fits(Screen::continue('1. Play BlackRed')));
    }

    public function testTheLimitIsExactlyOneSixty(): void
    {
        // "CON " (4 chars) + 156 chars = 160 exactly.
        $screen = Screen::continue(str_repeat('a', 156));
        $this->assertSame(160, mb_strlen($screen->render()));
        $this->assertTrue(CharacterLimit::fits($screen));
    }

    public function testOneCharacterOverOverflows(): void
    {
        $screen = Screen::continue(str_repeat('a', 157));
        $this->assertFalse(CharacterLimit::fits($screen));
    }

    public function testMultibyteCharactersAreCountedAsSingleCharacters(): void
    {
        // Diacritics/local-language characters must not be double-counted.
        $screen = Screen::continue(str_repeat('é', 156));
        $this->assertSame(160, mb_strlen($screen->render()));
        $this->assertTrue(CharacterLimit::fits($screen));
    }
}
