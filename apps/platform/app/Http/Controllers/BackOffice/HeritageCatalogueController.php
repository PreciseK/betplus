<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Domain\Games\Heritage\HeritageCatalogueService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HeritageCatalogueItem;
use App\Models\InstitutionUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Content — thin HTTP wrapper over HeritageCatalogueService, which already had
 * updateItem()/publish() but zero route reaching either. No new business logic here.
 */
class HeritageCatalogueController extends Controller
{
    public function __construct(private readonly HeritageCatalogueService $catalogue)
    {
    }

    /** GET /backoffice/v1/heritage-catalogue */
    public function index(): JsonResponse
    {
        $items = HeritageCatalogueItem::orderBy('itemNumber')->get();

        return response()->json(['items' => $items->map(fn (HeritageCatalogueItem $item) => $this->shape($item))->values()]);
    }

    /** PATCH /backoffice/v1/heritage-catalogue/{itemNumber} */
    public function update(Request $request, int $itemNumber): JsonResponse
    {
        $before = HeritageCatalogueItem::findOrFail($itemNumber)->getAttributes();
        $changes = $request->only(['canonicalName', 'localName', 'traditionOfOrigin', 'culturalDescription', 'bodySlot', 'leaderApplicability', 'layerPriority', 'depictionConstraint', 'signOffRef']);

        try {
            $this->catalogue->updateItem($itemNumber, $changes);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $item = HeritageCatalogueItem::findOrFail($itemNumber);
        $this->logChange('heritage_catalogue_item_updated', $itemNumber, $before, $item->getAttributes());

        return response()->json($this->shape($item));
    }

    /** POST /backoffice/v1/heritage-catalogue/{itemNumber}/publish */
    public function publish(int $itemNumber): JsonResponse
    {
        $before = HeritageCatalogueItem::findOrFail($itemNumber)->getAttributes();
        $errors = $this->catalogue->publish($itemNumber);
        $item = HeritageCatalogueItem::findOrFail($itemNumber);

        if ($errors === []) {
            $this->logChange('heritage_catalogue_item_published', $itemNumber, $before, $item->getAttributes());
        }

        return response()->json(array_merge($this->shape($item), ['gate_errors' => $errors]));
    }

    /** @return array<string, mixed> */
    private function shape(HeritageCatalogueItem $item): array
    {
        return [
            'number' => $item->itemNumber,
            'canonical_name' => $item->canonicalName,
            'local_name' => $item->localName,
            'origin' => $item->traditionOfOrigin,
            'context' => $item->culturalDescription,
            'slot' => $item->bodySlot,
            'layer_priority' => $item->layerPriority,
            'depiction_constraints' => $item->depictionConstraint ?? 'none',
            'advisor_sign_off_reference' => $item->signOffRef,
            'published_at' => $item->publishedAt?->toIso8601String(),
            'publication_status' => $item->signOffRef !== null && $item->publishedAt !== null ? 'approved' : 'preview-only',
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function logChange(string $action, int $itemNumber, array $before, array $after): void
    {
        /** @var InstitutionUser $actor */
        $actor = request()->attributes->get('institutionUser');
        AuditLog::create([
            'actorType' => 'institutionUser',
            'actorId' => $actor->id,
            'action' => $action,
            'targetTable' => 'heritageCatalogueItem',
            'targetId' => $itemNumber,
            'before' => $before,
            'after' => $after,
            'ipAddress' => request()->ip(),
            'userAgent' => request()->userAgent(),
        ]);
    }
}
