<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fairness\SeedChain;
use App\Domain\Fairness\SeedIssuer;
use App\Models\FairnessSeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FairnessSeedChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_issued_seed_chains_to_its_predecessors_hash(): void
    {
        $issuer = app(SeedIssuer::class);
        $first = $issuer->issue();
        $second = $issuer->issue();

        $this->assertNull($first->previousHash);
        $this->assertSame($first->hash, $second->previousHash);
    }

    public function test_the_chain_verifies_as_valid_after_several_issuances(): void
    {
        $issuer = app(SeedIssuer::class);
        for ($i = 0; $i < 5; $i++) {
            $issuer->issue();
        }

        $result = app(SeedChain::class)->verify();
        $this->assertTrue($result['valid']);
    }

    public function test_a_row_whose_hash_does_not_match_its_own_payload_is_detected(): void
    {
        // The append-only trigger blocks UPDATE/DELETE (covered below), so the only way
        // a bad row could exist is at INSERT — e.g. a bug elsewhere, or a privileged
        // attacker bypassing the app. Simulate that directly rather than fighting the
        // trigger, to test SeedChain's own verification math in isolation.
        $issuer = app(SeedIssuer::class);
        $issuer->issue();

        $tampered = FairnessSeed::create([
            'seedHex' => bin2hex(random_bytes(32)),
            'algorithm' => 'CTR_DRBG-random_bytes',
            'previousHash' => 'not-the-real-previous-hash',
            'hash' => hash('sha256', 'garbage'),
            'issuedAt' => now(),
        ]);

        $result = app(SeedChain::class)->verify();
        $this->assertFalse($result['valid']);
        $this->assertSame($tampered->id, $result['brokenAtId']);
    }

    public function test_the_append_only_trigger_rejects_an_update_through_eloquent(): void
    {
        $seed = app(SeedIssuer::class)->issue();

        $this->expectException(\Illuminate\Database\QueryException::class);
        FairnessSeed::where('id', $seed->id)->update(['seedHex' => bin2hex(random_bytes(32))]);
    }
}
