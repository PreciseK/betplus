<?php

declare(strict_types=1);

namespace App\Domain\Games\Heritage;

use App\Models\HeritageCatalogueItem;
use App\Models\Ticket;
use RuntimeException;

/**
 * Story 7.4/7.5 (REQ-HG-060..065). itemNumber is the catalogue's primary key
 * (REQ-HG-061), so "renumbering" and "changing which item a number maps to" are the
 * same operation: editing canonicalName/traditionOfOrigin on an existing row. This
 * refuses that specific edit once the first live Heritage ticket exists — before
 * that point the mapping isn't load-bearing for any settled outcome yet, so
 * correcting a draft item is still legitimate content work, not a compliance breach.
 */
final class HeritageCatalogueService
{
    /** Fields that define "which item a number maps to" — REQ-HG-061's protected identity. */
    private const IDENTITY_FIELDS = ['canonicalName', 'traditionOfOrigin'];

    /** @param array<string, mixed> $changes */
    public function updateItem(int $itemNumber, array $changes): void
    {
        $item = HeritageCatalogueItem::findOrFail($itemNumber);

        $touchesIdentity = array_intersect_key($changes, array_flip(self::IDENTITY_FIELDS)) !== [];
        if ($touchesIdentity && $this->firstLiveTicketExists()) {
            throw new RuntimeException(
                "Item {$itemNumber}'s number-to-item mapping cannot change after the first live Heritage ticket (REQ-HG-061)."
            );
        }

        $item->update($changes);
    }

    /** @return list<string> validation errors; empty means the item was published */
    public function publish(int $itemNumber): array
    {
        $item = HeritageCatalogueItem::findOrFail($itemNumber);

        if ($item->signOffRef === null) {
            // REQ-HG-064 — the launch gate. No named cultural advisor sign-off exists
            // in this codebase yet for any item (see the seeder's doc comment) — every
            // seeded item is refused here by design, not by omission.
            return ["Item {$itemNumber} has no recorded cultural-advisor sign-off reference (REQ-HG-064)."];
        }

        $item->update(['publishedAt' => now()]);

        return [];
    }

    private function firstLiveTicketExists(): bool
    {
        return Ticket::where('gameCode', 'HERITAGE')->exists();
    }
}
