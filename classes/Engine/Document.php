<?php

namespace Grav\Plugin\Pdf\Engine;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;

/**
 * Everything an engine needs, composed once.
 *
 * The Generator resolves the settings, the entries and the fonts; an engine
 * only turns that into a file. Splitting it this way keeps the two engines in
 * step: a setting added to `settings()` is visible to both without another
 * pass over the configuration, and neither engine can quietly read frontmatter
 * the other one does not.
 */
final class Document
{
    /**
     * @param array<string, mixed> $settings  Generator::settings() — every key that can change the output
     * @param array<string, mixed> $data      the Twig data: settings, entries, logo, document
     * @param array<string, array<string, string>> $fonts family => style => absolute path, custom families only
     */
    public function __construct(
        private readonly Grav $grav,
        public readonly PageInterface $page,
        public readonly string $mode,
        public readonly array $settings,
        public readonly array $data,
        public readonly array $fonts,
        public readonly string $title,
        public readonly string $site,
    ) {
    }

    /**
     * Render one of the `@pdf/…` templates.
     *
     * The engine passes the couple of variables that are its own business —
     * the page-break markup, for instance — rather than the Generator having
     * to guess which engine will run.
     *
     * @param array<string, mixed> $extra
     */
    public function render(string $template, array $extra = []): string
    {
        return $this->grav['twig']->twig()->render($template, $this->data + $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function setting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /** The dateline style date the cover and the {TODAY} token print. */
    public function today(): string
    {
        return (string) ($this->data['document']['date'] ?? '');
    }

    /** The logo as a file on disk, or null when nothing resolves to one. */
    public function logoFile(): ?string
    {
        $file = $this->data['logo'] ?? null;

        return is_string($file) && $file !== '' ? $file : null;
    }
}
