<?php

declare(strict_types=1);

namespace App\Domain\Identity;

enum MobileNetwork: string
{
    case Mtn = 'mtn';
    case Glo = 'glo';
    case Airtel = 'airtel';
    case T2 = 't2';

    public function label(): string
    {
        return match ($this) {
            self::Mtn => 'MTN',
            self::Glo => 'Glo',
            self::Airtel => 'Airtel',
            self::T2 => 'T2',
        };
    }
}
