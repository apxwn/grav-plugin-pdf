<?php

namespace Grav\Plugin\Pdf\Engine;

/**
 * A renderer that turns an assembled document into a PDF file.
 *
 * php-pdf is bundled with the plugin; Gotenberg is an external service the site
 * runs itself. Both receive the same Document — the same settings, the same
 * entries, the same resolved fonts — so a document does not look different
 * depending on which engine happened to build it.
 *
 * Only the Gotenberg engine talks to something that can be missing. It reports
 * that with Gotenberg\Unavailable, which is what the Router turns into a
 * fallback to php-pdf; php-pdf itself either produces a file or fails outright.
 */
interface Engine
{
    /** `php-pdf` or `gotenberg`; also part of the cache file name. */
    public function name(): string;

    /**
     * Write the document to $file.
     *
     * @throws \Grav\Plugin\Pdf\Engine\Gotenberg\Unavailable when the engine's
     *         service cannot be used; the Router decides what happens next.
     */
    public function render(Document $document, string $file): void;
}
