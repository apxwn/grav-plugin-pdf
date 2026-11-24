<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\Unit;

use Grav\Plugin\Pdf\Lock;
use PHPUnit\Framework\TestCase;

/**
 * The lock that keeps a cache miss from turning into one build per request.
 */
final class LockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pdf-lock-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($this->dir, 0777, true));
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/.*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->dir);
    }

    public function testALockIsExclusive(): void
    {
        $file = $this->dir . '/.key.lock';

        $first = Lock::acquire($file);

        self::assertInstanceOf(Lock::class, $first);
        self::assertNull(Lock::acquire($file), 'A second request must not get the same lock.');

        $first->release();

        $again = Lock::acquire($file);
        self::assertInstanceOf(Lock::class, $again);

        $again->release();
    }

    public function testReleasingIsIdempotentAndHappensOnDestruction(): void
    {
        $file = $this->dir . '/.key.lock';

        $lock = Lock::acquire($file);
        self::assertInstanceOf(Lock::class, $lock);

        $lock->release();
        $lock->release();

        $second = Lock::acquire($file);
        self::assertInstanceOf(Lock::class, $second);
        unset($second);

        // The destructor must have given the lock back, or this would be null.
        self::assertInstanceOf(Lock::class, Lock::acquire($file));
    }

    public function testLockingFailsOpenWhenTheFileCannotBeCreated(): void
    {
        // Locking is an optimisation, not a correctness requirement: a cache
        // directory the site cannot write to must still attempt the build and
        // fail with the cache directory's own, actionable error.
        $lock = Lock::acquire($this->dir . '/missing/.key.lock');

        self::assertInstanceOf(Lock::class, $lock);

        $lock->release();

        self::assertDirectoryDoesNotExist($this->dir . '/missing');
    }

    public function testRequiredLockFailsClosedWhenTheFileCannotBeCreated(): void
    {
        self::assertNull(Lock::acquireRequired($this->dir . '/missing/.account.lock'));
        self::assertDirectoryDoesNotExist($this->dir . '/missing');
    }

    public function testRequiredLockIsExclusive(): void
    {
        $file = $this->dir . '/.account.lock';
        $first = Lock::acquireRequired($file);

        self::assertInstanceOf(Lock::class, $first);
        self::assertNull(Lock::acquireRequired($file));

        $first->release();

        self::assertInstanceOf(Lock::class, Lock::acquireRequired($file));
    }

    public function testHoldingTheLockKeepsTheFileYoung(): void
    {
        $file = $this->dir . '/.key.lock';
        touch($file, time() - 3 * 86400);

        $lock = Lock::acquire($file);
        self::assertInstanceOf(Lock::class, $lock);

        clearstatcache(true, $file);
        self::assertGreaterThan(time() - 60, (int) filemtime($file));

        $lock->release();
    }
}
