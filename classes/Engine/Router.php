<?php

namespace Grav\Plugin\Pdf\Engine;

use Grav\Common\Grav;
use Grav\Plugin\Pdf\Config;
use Grav\Plugin\Pdf\Engine\Gotenberg\Breaker;
use Grav\Plugin\Pdf\Engine\Gotenberg\Client;
use Grav\Plugin\Pdf\Engine\Gotenberg\Unavailable;
use Grav\Plugin\Pdf\Paths;

/**
 * Picks an engine, and steps in when the chosen one cannot deliver.
 *
 * The site asks for an engine; the Router decides which one actually builds
 * the document. Gotenberg is an external service, so "the address answers"
 * is not something the plugin can assume: when it does not, the document is
 * still produced, by the built-in engine, and the response says which engine
 * ran.
 *
 * Two rules keep that predictable:
 *
 *  - the engine is part of the cache file name, so a fallback document is
 *    never mistaken for a Gotenberg one after the service comes back;
 *  - a host that has just failed is skipped for `retry_after` seconds, so an
 *    outage costs one connect attempt per minute rather than one per PDF.
 */
final class Router
{
    public function __construct(private readonly Grav $grav, private readonly Config $config)
    {
    }

    /**
     * Which engine will be attempted for a fresh document.
     *
     * This is what the cache looks for, so it has to answer without touching
     * the network: an address that is configured but down still counts as
     * Gotenberg until a request proves otherwise.
     */
    public function plan(): string
    {
        if ($this->config->engine() !== 'gotenberg') {
            return 'php-pdf';
        }

        if ($this->url() === '') {
            return 'php-pdf';
        }

        $breaker = $this->breaker();

        if ($breaker->open()) {
            $this->grav['log']->info(sprintf(
                'PDF: skipping Gotenberg at %s until %s (%s).',
                $this->url(),
                gmdate('c', $breaker->until()),
                $breaker->reason()
            ));

            return 'php-pdf';
        }

        return 'gotenberg';
    }

    /**
     * Build the document and report which engine produced it.
     *
     * @return string `php-pdf` or `gotenberg`
     *
     * @throws Unavailable when Gotenberg failed and falling back is turned off
     */
    public function render(Document $document, string $file): string
    {
        if ($this->plan() !== 'gotenberg') {
            (new PhpPdf($this->grav, $this->config))->render($document, $file);

            return 'php-pdf';
        }

        $breaker = $this->breaker();

        try {
            (new Gotenberg($this->grav, $this->config))->render($document, $file);
            $breaker->clear();

            return 'gotenberg';
        } catch (Unavailable $e) {
            if (!$this->fallback()) {
                throw $e;
            }

            $this->grav['log']->warning(sprintf(
                'PDF: Gotenberg at %s could not build the document (%s); falling back to php-pdf.',
                $this->url(),
                $e->getMessage()
            ));

            if ($e->tripsBreaker()) {
                $breaker->trip($e->getMessage(), $this->url());
            }

            (new PhpPdf($this->grav, $this->config))->render($document, $file);

            return 'php-pdf';
        }
    }

    /**
     * The configured address, normalised, or '' when there is none.
     */
    public function url(): string
    {
        return Client::normaliseUrl((string) $this->config->gotenberg('url', ''));
    }

    /**
     * Is a Gotenberg failure allowed to degrade to the built-in engine?
     */
    private function fallback(): bool
    {
        return (bool) $this->config->gotenberg('fallback', true);
    }

    private function breaker(): Breaker
    {
        return new Breaker(
            Paths::cacheDir($this->grav, $this->config),
            (int) $this->config->gotenberg('retry_after', 60)
        );
    }
}
