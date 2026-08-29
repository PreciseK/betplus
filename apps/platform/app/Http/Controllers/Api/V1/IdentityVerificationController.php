<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\BvnVerificationService;
use App\Domain\Identity\NinVerificationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VerifyBvnRequest;
use App\Http\Requests\Api\V1\VerifyNinRequest;
use App\Models\Player;
use Illuminate\Http\JsonResponse;

class IdentityVerificationController extends Controller
{
    public function __construct(
        private readonly NinVerificationService $nin,
        private readonly BvnVerificationService $bvn,
    ) {
    }

    public function verifyNin(VerifyNinRequest $request): JsonResponse
    {
        /** @var Player $player */
        $player = $request->attributes->get('player');

        return response()->json($this->nin->verify(
            $player,
            $request->string('date_of_birth')->toString(),
            $request->string('nin')->toString(),
        ));
    }

    public function verifyBvn(VerifyBvnRequest $request): JsonResponse
    {
        /** @var Player $player */
        $player = $request->attributes->get('player');

        return response()->json($this->bvn->verify($player, $request->string('bvn')->toString()));
    }
}
