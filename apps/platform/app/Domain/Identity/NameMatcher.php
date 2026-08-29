<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use Illuminate\Support\Collection;

/** Shared by NinVerificationService and BvnVerificationService for kycRecord.opayNameMatch. */
final class NameMatcher
{
    public static function match(?string $vendorName, string $opayName): string
    {
        if ($vendorName === null) {
            return 'not_checked';
        }

        $vendorTokens = self::normalize($vendorName);
        $opayTokens = self::normalize($opayName);

        if ($vendorTokens->all() === $opayTokens->all()) {
            return 'exact';
        }

        return $vendorTokens->intersect($opayTokens)->isNotEmpty() ? 'partial' : 'mismatch';
    }

    /** @return Collection<int, non-falsy-string> */
    private static function normalize(string $name): Collection
    {
        return collect(preg_split('/\s+/', mb_strtoupper(trim($name))))
            ->filter()
            ->sort()
            ->values();
    }
}
