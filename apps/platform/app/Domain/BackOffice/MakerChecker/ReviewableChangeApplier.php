<?php

declare(strict_types=1);

namespace App\Domain\BackOffice\MakerChecker;

use App\Models\ReviewableChange;

/**
 * REQ-BO-015 — "adding a new reviewable action is registration against that workflow,
 * never a new bespoke approval path." Implement this and add one line to
 * MakerCheckerService::APPLIERS.
 */
interface ReviewableChangeApplier
{
    public function apply(ReviewableChange $change): void;
}
