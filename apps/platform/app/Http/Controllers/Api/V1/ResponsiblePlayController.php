<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\ResponsibleGaming\LimitsService;
use App\Domain\ResponsibleGaming\NetPositionService;
use App\Domain\ResponsibleGaming\ProtectionService;
use App\Domain\ResponsibleGaming\Registries\RegistryCheckService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SelectProtectionOptionRequest;
use App\Http\Requests\Api\V1\UpdateLimitRequest;
use App\Models\Player;
use App\Models\PlayerLimit;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ResponsiblePlayController extends Controller
{
    private const LIMIT_PRESENTATION = [
        'deposit-daily' => ['label' => 'Daily deposit', 'scope' => 'Across every game'],
        'deposit-weekly' => ['label' => 'Weekly deposit', 'scope' => 'Across every game'],
        'deposit-monthly' => ['label' => 'Monthly deposit', 'scope' => 'Across every game'],
        'stake-daily' => ['label' => 'Daily stake', 'scope' => 'BlackRed and Heritage combined'],
        'stake-weekly' => ['label' => 'Weekly stake', 'scope' => 'BlackRed and Heritage combined'],
        'session-time' => ['label' => 'Session time', 'scope' => 'Web and app'],
    ];

    public function __construct(
        private readonly LimitsService $limits,
        private readonly ProtectionService $protection,
        private readonly NetPositionService $netPosition,
        private readonly RegistryCheckService $registry,
    ) {
    }

    /** GET /v1/responsible-play */
    public function show(): JsonResponse
    {
        $player = $this->player();
        [$status, $statusEndsAt] = $this->resolveStatus($player);

        return response()->json([
            'status' => $status,
            'status_ends_at' => $statusEndsAt,
            'limits' => array_map([$this, 'limitShape'], $this->limits->limitsFor($player)),
            'cool_off_options' => $this->optionShape($this->protection->coolOffOptions()),
            'self_exclusion_options' => $this->optionShape($this->protection->selfExclusionOptions()),
            'net_position_kobo' => $this->netPosition->positionsFor($player),
            'withdrawal_available' => true,
        ]);
    }

    /** POST /v1/responsible-play/limits */
    public function updateLimit(UpdateLimitRequest $request): JsonResponse
    {
        try {
            $limit = $this->limits->updateLimit(
                $this->player(),
                $request->string('key')->toString(),
                (int) $request->input('value'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->limitShape($limit));
    }

    /** POST /v1/responsible-play/cool-off */
    public function startCoolOff(SelectProtectionOptionRequest $request): JsonResponse
    {
        try {
            $event = $this->protection->startCoolOff($this->player(), $request->string('option_id')->toString());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'cool-off', 'status_ends_at' => $event->endsAt->toIso8601String()]);
    }

    /** POST /v1/responsible-play/self-exclude */
    public function selfExclude(SelectProtectionOptionRequest $request): JsonResponse
    {
        try {
            $event = $this->protection->selfExclude($this->player(), $request->string('option_id')->toString());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'self-excluded', 'status_ends_at' => $event->endsAt->toIso8601String()]);
    }

    /** @return array{0:string, 1:?string} */
    private function resolveStatus(Player $player): array
    {
        $active = $this->protection->activeEventFor($player);
        if ($active !== null) {
            // PlayerProtectionEvent.type is 'cool-off'|'self-exclusion'; the frontend
            // status value for the latter is the past-tense 'self-excluded' (mocks/
            // responsiblePlay.ts's ResponsiblePlayStatus), not the event type itself.
            $status = $active->type === 'self-exclusion' ? 'self-excluded' : 'cool-off';

            return [$status, $active->endsAt->toIso8601String()];
        }

        $registryStatus = $this->registry->statusFor($player);
        if ($registryStatus === 'excluded') {
            return ['registry-excluded', null];
        }
        if ($registryStatus === 'unavailable') {
            return ['registry-unavailable', null];
        }

        return ['active', null];
    }

    /** @return array<string, mixed> */
    private function limitShape(PlayerLimit $limit): array
    {
        $presentation = self::LIMIT_PRESENTATION[$limit->limitKey];

        return array_filter([
            'key' => $limit->limitKey,
            'label' => $presentation['label'],
            'scope' => $presentation['scope'],
            'unit' => $limit->unit,
            'current_value' => $limit->currentValue,
            'pending_value' => $limit->pendingValue,
            'pending_effective_at' => $limit->pendingEffectiveAt?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param list<array{id:string,label:string,detail:string,durationHours:int}> $options
     * @return list<array{id:string,label:string,detail:string,duration_hours:int}>
     */
    private function optionShape(array $options): array
    {
        return array_map(fn (array $option) => [
            'id' => $option['id'],
            'label' => $option['label'],
            'detail' => $option['detail'],
            'duration_hours' => $option['durationHours'],
        ], $options);
    }

    private function player(): Player
    {
        /** @var Player $player */
        $player = request()->attributes->get('player');

        return $player;
    }
}
