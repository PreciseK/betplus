<?php

declare(strict_types=1);

namespace BlackRed\Http\Controllers;

use BlackRed\Database\Connection;
use BlackRed\Http\Middleware\HttpException;
use BlackRed\Http\Request;
use BlackRed\Http\Response;
use BlackRed\Support\GhanaPhone;
use BlackRed\Support\Money;

/**
 * Account / "who am I" endpoint.
 *
 *   GET /api/me  — returns the authenticated player, KYC status, and dual balance.
 *
 * Authentication is handled by AuthMiddleware on the route — this controller
 * trusts that request->getAttribute('player_id') is a valid player ID.
 *
 * The balance comes from the vw_walletDualBalance view which already applies
 * the BR-AML-001 50% rule for max-withdrawable amounts on Play Balance.
 */
final class MeController
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function show(Request $request, array $params): Response
    {
        $playerId = $request->getAttribute('player_id');
        if (!is_int($playerId) || $playerId <= 0) {
            // Should never happen if AuthMiddleware did its job — defensive.
            throw HttpException::unauthorized();
        }

        $player = $this->db->fetchOne(
            'SELECT id, msisdn, paymentProvider, registeredName, displayName, email,
                    kycStatus, accountStatus, registrationChannel, lastLoginAt,
                    passwordUpdatedAt, createdAt
             FROM player
             WHERE id = :id AND deletedAt IS NULL LIMIT 1',
            ['id' => $playerId]
        );

        if ($player === null) {
            // Player was deleted between session creation and now.
            throw HttpException::unauthorized('Account no longer exists');
        }

        $balance = $this->db->fetchOne(
            'SELECT playBalancePesewas, payoutBalancePesewas, totalBalancePesewas,
                    maxPlayWithdrawablePesewas, maxPayoutWithdrawablePesewas,
                    maxTotalWithdrawablePesewas, playVersion, payoutVersion
             FROM vw_walletDualBalance
             WHERE playerId = :id LIMIT 1',
            ['id' => $playerId]
        );

        // Defensive: every player should have a wallet row (created at signup).
        // If the view returned nothing, fail safe rather than show "0".
        if ($balance === null) {
            throw new \RuntimeException("Wallet missing for player {$playerId}");
        }

        $playPesewas = (int)$balance['playBalancePesewas'];
        $payoutPesewas = (int)$balance['payoutBalancePesewas'];
        $totalPesewas = (int)$balance['totalBalancePesewas'];
        $maxPlayWithdrawable = (int)$balance['maxPlayWithdrawablePesewas'];
        $maxPayoutWithdrawable = (int)$balance['maxPayoutWithdrawablePesewas'];
        $maxTotalWithdrawable = (int)$balance['maxTotalWithdrawablePesewas'];

        return Response::json([
            'player' => [
                'id'                  => (int)$player['id'],
                'phone'               => (string)$player['msisdn'],
                'phoneFormatted'      => GhanaPhone::formatForDisplay((string)$player['msisdn']),
                'paymentProvider'     => (string)$player['paymentProvider'],
                'registeredName'      => (string)$player['registeredName'],
                'displayName'         => $player['displayName'] !== null ? (string)$player['displayName'] : null,
                'email'               => $player['email'] !== null ? (string)$player['email'] : null,
                'kycStatus'           => (string)$player['kycStatus'],
                'accountStatus'       => (string)$player['accountStatus'],
                'registrationChannel' => (string)$player['registrationChannel'],
                'lastLoginAt'         => $player['lastLoginAt'],
                'passwordUpdatedAt'   => $player['passwordUpdatedAt'],
                'createdAt'           => (string)$player['createdAt'],
            ],
            'balance' => [
                // Raw pesewas (BIGINT) — source of truth for math.
                'playPesewas'              => $playPesewas,
                'payoutPesewas'            => $payoutPesewas,
                'totalPesewas'             => $totalPesewas,
                'maxPlayWithdrawable'      => $maxPlayWithdrawable,
                'maxPayoutWithdrawable'    => $maxPayoutWithdrawable,
                'maxTotalWithdrawable'     => $maxTotalWithdrawable,
                // Display-ready formatted strings — for UI binding.
                'playFormatted'            => Money::pesewasToString($playPesewas),
                'payoutFormatted'          => Money::pesewasToString($payoutPesewas),
                'totalFormatted'           => Money::pesewasToString($totalPesewas),
                'maxPlayWithdrawableFormatted'   => Money::pesewasToString($maxPlayWithdrawable),
                'maxPayoutWithdrawableFormatted' => Money::pesewasToString($maxPayoutWithdrawable),
                'maxTotalWithdrawableFormatted'  => Money::pesewasToString($maxTotalWithdrawable),
                'currency'                 => 'GHS',
                'fiftyPercentRuleApplied'  => true,
                'fiftyPercentRuleNote'     => 'Per AML policy BR-AML-001, only 50% of Play Balance is withdrawable per request. Payout Balance is fully withdrawable.',
            ],
        ]);
    }
}