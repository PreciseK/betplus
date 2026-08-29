<?php

declare(strict_types=1);

namespace BlackRed\Http\Controllers;

use BlackRed\Http\Middleware\HttpException;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Logging\Logger;
use BlackRed\Support\Money;
use BlackRed\Validation\Validator;
use BlackRed\Wallet\GameEngineService;

/**
 * GameController — single endpoint /api/game/play
 *
 *   POST /api/game/play
 *   Body:
 *     {
 *       "gameType": 3,
 *       "colorPicks": ["red","black","red"],
 *       "stake": "10.00",
 *       "lockedDeck": ["7H","KS","AC","2D","TD","9S","3H","JS","5C","6H","QH","8S"]
 *     }
 *
 *   Response 200:
 *     {
 *       "refNumber":     "STK-1234567890ab",
 *       "outcome":       "win" | "loss",
 *       "drawnCards":    ["7H","KS","AC"],
 *       "stake":         "10.00",
 *       "stakePesewas":  1000,
 *       "payout":        "200.00",
 *       "payoutPesewas": 20000,
 *       "multiplier":    20,
 *       "gameType":      3,
 *       "colorPicks":    ["red","black","red"],
 *       "playBalance":   "5.00",
 *       "playBalancePesewas":   500,
 *       "payoutBalance": "200.00",
 *       "payoutBalancePesewas": 20000
 *     }
 *
 * Errors map to standard HttpException 400/422/500/503 with `{error, message}`.
 *
 * Auth required (player_id from AuthMiddleware). The engine never trusts
 * client-supplied playerId.
 */
final class GameController
{
    public function __construct(
        private readonly GameEngineService $engine,
        private readonly Logger $logger,
    ) {
    }

    public function play(Request $request, array $params): Response
    {
        $playerId = $request->getAttribute('player_id');
        if (!is_int($playerId)) {
            throw HttpException::unauthorized('Authentication required');
        }

        $v = new Validator($request->json());

        // gameType: integer 1..5 (Validator's requireString won't help here
        // since this is a numeric field — read directly and validate)
        $body = $request->json();
        $gameType = $body['gameType'] ?? null;
        if (!is_int($gameType) || $gameType < 1 || $gameType > 5) {
            throw new HttpException(422, 'invalid_game_type', 'gameType must be an integer 1 to 5.');
        }

        // colorPicks: array of 'red'/'black'
        $colorPicks = $body['colorPicks'] ?? null;
        if (!is_array($colorPicks)) {
            throw new HttpException(422, 'invalid_color_picks', 'colorPicks must be an array.');
        }

        // stake: string GHS amount
        $stakeStr = $v->requireString('stake', 1, 20);
        $stakePesewas = Money::parseGhsToPesewas($stakeStr);
        if ($stakePesewas === null) {
            throw new HttpException(422, 'invalid_stake', 'stake must be a number with up to 2 decimal places, e.g. 10.00.');
        }

        // lockedDeck: array of strings
        $lockedDeck = $body['lockedDeck'] ?? null;
        if (!is_array($lockedDeck)) {
            throw new HttpException(422, 'invalid_deck', 'lockedDeck must be an array.');
        }

        // Hand off to engine; it will throw HttpException for any business-level reject
        $result = $this->engine->play(
            $playerId,
            $gameType,
            $colorPicks,
            $stakePesewas,
            $lockedDeck,
            $request->clientIp ?? null,
            $request->headers['user-agent'] ?? null
        );

        return Response::json([
            'refNumber'             => $result['refNumber'],
            'outcome'               => $result['outcome'],
            'drawnCards'            => $result['drawnCards'],
            'stake'                 => Money::pesewasToString($result['stakePesewas']),
            'stakePesewas'          => $result['stakePesewas'],
            'payout'                => Money::pesewasToString($result['payoutPesewas']),
            'payoutPesewas'         => $result['payoutPesewas'],
            'multiplier'            => $result['multiplier'],
            'gameType'              => $result['gameType'],
            'colorPicks'            => $result['colorPicks'],
            'playBalance'           => Money::pesewasToString($result['playBalance']),
            'playBalancePesewas'    => $result['playBalance'],
            'payoutBalance'         => Money::pesewasToString($result['payoutBalance']),
            'payoutBalancePesewas'  => $result['payoutBalance'],
        ], 200);
    }
}