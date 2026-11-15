<?php

namespace Grav\Plugin\Pdf\Engine\Gotenberg;

use Grav\Plugin\Pdf\Paths;

/**
 * Stop asking a host that has just failed.
 *
 * Without this, a Gotenberg container that is down would cost every PDF
 * request a connect attempt — a couple of seconds each with a blackholed host
 * — and the site would look slow rather than degraded. After a failure the
 * engine is skipped for `retry_after` seconds and the built-in engine builds
 * the document directly.
 *
 * The state is one small JSON file next to the document cache, so it survives
 * between requests without a database, and `bin/grav clear-cache` forgets it
 * along with everything else. It is written on a best-effort basis: a cache
 * directory the site cannot write only costs the optimisation.
 */
final class Breaker
{
    public function __construct(private readonly string $dir, private readonly int $retryAfter)
    {
    }

    /**
     * Is the engine known to be unusable right now?
     */
    public function open(): bool
    {
        $state = $this->read();

        if ($state === null) {
            return false;
        }

        if ((int) ($state['until'] ?? 0) > time()) {
            return true;
        }

        // The window has passed; the next attempt decides again.
        $this->clear();

        return false;
    }

    /**
     * The reason recorded when the breaker last tripped.
     */
    public function reason(): string
    {
        return (string) ($this->read()['reason'] ?? '');
    }

    /**
     * When the host will be tried again (a unix timestamp, 0 when closed).
     */
    public function until(): int
    {
        return (int) ($this->read()['until'] ?? 0);
    }

    /**
     * The address the failure came from, for the log line when skipping.
     */
    public function url(): string
    {
        return (string) ($this->read()['url'] ?? '');
    }

    public function trip(string $reason, string $url = ''): void
    {
        if ($this->retryAfter <= 0) {
            return;
        }

        if (!Paths::ensure($this->dir)) {
            return;
        }

        $payload = [
            'url' => $url,
            'reason' => $reason,
            'at' => time(),
            'until' => time() + $this->retryAfter,
        ];

        @file_put_contents($this->file(), (string) json_encode($payload), LOCK_EX);
    }

    public function clear(): void
    {
        $file = $this->file();

        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        $file = $this->file();

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private function file(): string
    {
        return rtrim($this->dir, '/') . '/gotenberg.json';
    }
}
