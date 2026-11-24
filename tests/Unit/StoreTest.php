<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\Unit;

use Grav\Plugin\Pdf\Lock;
use Grav\Plugin\Pdf\Store;
use PHPUnit\Framework\TestCase;

/**
 * The document cache: what counts as fresh, which document a waiting request
 * may be given, and what the sweep after a build is allowed to delete.
 */
final class StoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pdf-store-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($this->dir, 0777, true));
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/{,.}*', GLOB_BRACE) as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->dir);
    }

    public function testTempNamesAreHiddenUniqueAndNotDocuments(): void
    {
        $store = new Store($this->dir);
        $first = $store->temp('abc');
        $second = $store->temp('abc');

        self::assertNotSame($first, $second);
        self::assertStringStartsWith($this->dir . '/.abc.', $first);
        self::assertStringEndsWith('.part', $first);
        self::assertStringNotContainsString('.pdf', $first);
    }

    public function testFreshnessFollowsTheTtl(): void
    {
        $store = new Store($this->dir, 60);
        $file = $store->path('abc.php-pdf');

        self::assertFalse($store->isFresh($file), 'A missing file is never fresh.');

        file_put_contents($file, 'pdf');
        self::assertTrue($store->isFresh($file));

        touch($file, time() - 120);
        clearstatcache(true, $file);
        self::assertFalse($store->isFresh($file), 'An expired document is not fresh.');

        $unlimited = new Store($this->dir, 0);
        self::assertTrue($unlimited->isFresh($file), 'A ttl of 0 never expires.');
    }

    public function testNothingIsFreshWhenTheCacheIsOff(): void
    {
        $file = $this->dir . '/abc.php-pdf.pdf';
        file_put_contents($file, 'pdf');

        $store = new Store($this->dir, 60, false);

        self::assertFalse($store->isFresh($file));
        self::assertFalse($store->cacheEnabled());
    }

    public function testLatestPicksTheNewestDocumentAndNamesTheEngineThatMadeIt(): void
    {
        $store = new Store($this->dir);
        $key = 'abc';

        $older = $store->path($key . '.php-pdf');
        $newer = $store->path($key . '.gotenberg');
        file_put_contents($older, 'pdf');
        file_put_contents($newer, 'pdf');
        touch($older, time() - 120);

        self::assertSame([$newer, 'gotenberg'], $store->latest($key));

        self::assertNull($store->latest('no-such-key'));
    }

    public function testLatestIgnoresScratchFilesAndOtherKeys(): void
    {
        $store = new Store($this->dir);
        file_put_contents($store->temp('abc'), 'partial');
        file_put_contents($store->path('other.php-pdf'), 'pdf');

        self::assertNull($store->latest('abc'));
    }

    public function testPruneDropsExpiredDocumentsAndKeepsTheOneJustWritten(): void
    {
        $store = new Store($this->dir, 60);
        $keep = $store->path('keep.php-pdf');
        $expired = $store->path('expired.php-pdf');
        $recent = $store->path('recent.php-pdf');

        foreach ([$keep, $expired, $recent] as $file) {
            file_put_contents($file, 'pdf');
        }
        touch($expired, time() - 600);

        $store->prune($keep);

        self::assertFileExists($keep);
        self::assertFileExists($recent);
        self::assertFileDoesNotExist($expired, 'A stale document is removed by the next write.');
    }

    public function testPruneLeavesDocumentsAloneWhenTheCacheIsOff(): void
    {
        $file = $this->dir . '/expired.php-pdf.pdf';
        file_put_contents($file, 'pdf');
        touch($file, time() - 600);

        $store = new Store($this->dir, 60, false);
        $store->prune($this->dir . '/unrelated.pdf');

        // The plug-in no longer intends to serve from this directory, but the
        // sweep is not a cache purge: only the files it knows are abandoned go.
        self::assertFileExists($file);
    }

    public function testPruneRemovesScratchFilesOfKilledRequests(): void
    {
        $store = new Store($this->dir);
        $abandoned = $this->dir . '/.abc.deadbeef.part';
        $inFlight = $this->dir . '/.abc.cafebabe.part';

        file_put_contents($abandoned, 'partial');
        touch($abandoned, time() - 2 * 3600);
        file_put_contents($inFlight, 'partial');

        $store->prune($store->path('keep.php-pdf'));

        self::assertFileDoesNotExist($abandoned, 'A build killed by PHP leaves this behind.');
        self::assertFileExists($inFlight, 'A build that is still running must keep its scratch file.');
    }

    public function testPruneRemovesDerivedScratchFilesOfKilledRequests(): void
    {
        $store = new Store($this->dir);

        // What a renderer writes next to the scratch document: the assembled
        // Gotenberg request body, the outline rewrite's new copy, and the HTML
        // parts of a Gotenberg request. They carry a suffix past `.part`, so
        // the base pattern alone would step over every one of them.
        $body = $this->dir . '/.abc.deadbeef.part.multipart';
        $outline = $this->dir . '/.abc.deadbeef.part.outline';
        $part = $this->dir . '/.index-deadbeef.html.part';
        $live = $this->dir . '/.abc.cafebabe.part.multipart';

        foreach ([$body, $outline, $part, $live] as $file) {
            file_put_contents($file, 'partial');
        }

        foreach ([$body, $outline, $part] as $file) {
            touch($file, time() - 2 * 3600);
        }

        $store->prune($store->path('keep.php-pdf'));

        self::assertFileDoesNotExist($body, 'A killed Gotenberg build leaves its request body behind.');
        self::assertFileDoesNotExist($outline, 'A killed outline rewrite leaves its new copy behind.');
        self::assertFileDoesNotExist($part, 'A killed Gotenberg build leaves its HTML parts behind.');
        self::assertFileExists($live, 'A build that is still running must keep its request body.');
    }

    public function testPruneRemovesLockFilesNobodyHolds(): void
    {
        $store = new Store($this->dir);
        $abandoned = $this->dir . '/.abc.lock';
        $young = $this->dir . '/.def.lock';

        file_put_contents($abandoned, '');
        touch($abandoned, time() - 2 * 86400);
        file_put_contents($young, '');

        $store->prune($store->path('keep.php-pdf'));

        self::assertFileDoesNotExist($abandoned);
        self::assertFileExists($young);
    }

    public function testPruneKeepsALockThatIsHeld(): void
    {
        $store = new Store($this->dir);
        $file = $this->dir . '/.abc.lock';
        file_put_contents($file, '');
        touch($file, time() - 2 * 86400);

        $lock = Lock::acquire($file);
        self::assertInstanceOf(Lock::class, $lock);

        $store->prune($store->path('keep.php-pdf'));

        self::assertFileExists($file, 'A lock that is in use must not be swept away.');
        $lock->release();
    }
}
