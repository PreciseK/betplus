<?php

declare(strict_types=1);

namespace Betplus\Ussd\Tests;

use Betplus\Ussd\Session\FileSessionStore;
use Betplus\Ussd\Session\Session;
use PHPUnit\Framework\TestCase;

final class FileSessionStoreTest extends TestCase
{
    private string $dir;
    private FileSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/betplus-ussd-store-test-' . bin2hex(random_bytes(6));
        $this->store = new FileSessionStore($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testPutThenGetRoundTrips(): void
    {
        $session = new Session('sess-1', '+2348031234567', 'main_menu', ['x' => 1], 'tok', time());
        $this->store->put($session);

        $found = $this->store->get('sess-1', '+2348031234567');

        $this->assertNotNull($found);
        $this->assertSame('main_menu', $found->screen);
        $this->assertSame(['x' => 1], $found->data);
        $this->assertSame('tok', $found->accessToken);
    }

    public function testGetReturnsNullForAMismatchedSessionId(): void
    {
        $this->store->put(new Session('sess-1', '+2348031234567', 'main_menu', [], null, time()));

        $this->assertNull($this->store->get('sess-2', '+2348031234567'));
    }

    public function testGetReturnsNullWhenNothingIsStored(): void
    {
        $this->assertNull($this->store->get('sess-1', '+2348031234567'));
    }

    public function testDeleteRemovesOnlyAMatchingSession(): void
    {
        $this->store->put(new Session('sess-1', '+2348031234567', 'main_menu', [], null, time()));

        $this->store->delete('sess-2', '+2348031234567'); // mismatched — no-op
        $this->assertNotNull($this->store->get('sess-1', '+2348031234567'));

        $this->store->delete('sess-1', '+2348031234567');
        $this->assertNull($this->store->get('sess-1', '+2348031234567'));
    }

    public function testMostRecentForFindsBySessionIdIndependentMsisdnLookup(): void
    {
        $this->store->put(new Session('sess-1', '+2348031234567', 'blackred_length', [], null, time()));

        $found = $this->store->mostRecentFor('+2348031234567');

        $this->assertNotNull($found);
        $this->assertSame('sess-1', $found->sessionId);
    }

    public function testPurgeExpiredRemovesOnlyStaleFiles(): void
    {
        $this->store->put(new Session('sess-fresh', '+2348031234567', 'main_menu', [], null, time()));
        $this->store->put(new Session('sess-stale', '+2348039999999', 'main_menu', [], null, time()));
        // Backdate the "stale" file's mtime directly — put() always writes "now".
        touch($this->dir . '/' . hash('sha256', '+2348039999999') . '.json', time() - 3600);

        $purged = $this->store->purgeExpired(600);

        $this->assertSame(1, $purged);
        $this->assertNotNull($this->store->get('sess-fresh', '+2348031234567'));
        $this->assertNull($this->store->get('sess-stale', '+2348039999999'));
    }
}
