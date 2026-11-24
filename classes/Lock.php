<?php

namespace Grav\Plugin\Pdf;

/**
 * Non-blocking file lock used to coordinate PDF builds.
 *
 * A cache miss is the expensive path — a large book is seconds of CPU and a lot
 * of memory, and the file it produces is completely determined by its name — so
 * two requests that miss the same key have nothing to gain from both building
 * it. This is the handle that decides which of them does the work.
 *
 * It is an advisory lock on a file next to the documents, taken without
 * waiting: a request that finds the lock held is answered from what is already
 * on disk, or told to come back, rather than queued behind the build. Its use is
 * best effort, in the same spirit as the Gotenberg breaker — locking protects
 * against pile-ups, it is not a correctness requirement, because a document only
 * ever appears under its final name by rename.
 */
final class Lock
{
    /**
     * A held lock, or one that holds nothing when locking is not possible.
     *
     * @param resource|null $handle
     */
    private function __construct(private $handle)
    {
    }

    /**
     * Take a best-effort lock, or report that somebody else holds it.
     *
     * `null` means the document is being built elsewhere right now. A file that
     * cannot be opened, or a filesystem without working locks, hands back a
     * lock that holds nothing. This is suitable for the guest cache lock, where
     * a final document is still safe to publish atomically.
     */
    public static function acquire(string $file): ?self
    {
        return self::take($file, true);
    }

    /**
     * Take a lock, treating an unavailable lock mechanism as contention.
     *
     * Private-build limits must not silently fail open: if an account lock
     * cannot be established, the request does not start another build.
     */
    public static function acquireRequired(string $file): ?self
    {
        return self::take($file, false);
    }

    /**
     * @param bool $failOpen Whether an unavailable lock mechanism may proceed
     *                       with an empty lock, as the shared-cache lock does.
     */
    private static function take(string $file, bool $failOpen): ?self
    {
        $handle = @fopen($file, 'c');

        if ($handle === false) {
            return $failOpen ? new self(null) : null;
        }

        // Capture the reason without letting a warning reach the response: the
        // two outcomes look the same to flock().
        $warning = '';

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        $locked = flock($handle, LOCK_EX | LOCK_NB);

        restore_error_handler();

        if (!$locked) {
            fclose($handle);

            // Normally this means another request holds the lock. Some network
            // filesystems report that locking is unsupported instead; only the
            // best-effort guest lock treats that as permission to proceed.
            return $failOpen && self::unlockable($warning) ? new self(null) : null;
        }

        // Keep the file young, so that Store::prune() can tell a lock that is in
        // use from one whose document nobody has asked for in a day.
        @touch($file);

        return new self($handle);
    }

    /**
     * Does this warning mean the filesystem cannot lock, rather than that the
     * lock is taken by somebody else?
     */
    private static function unlockable(string $warning): bool
    {
        return $warning !== ''
            && preg_match('/not supported|no locks available|not implemented/i', $warning) === 1;
    }

    /**
     * Give the lock back.
     *
     * The kernel does this for a process that dies, so a request killed by a
     * timeout cannot wedge a document until someone removes the file by hand.
     */
    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
