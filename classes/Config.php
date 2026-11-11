<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;

/**
 * Effective settings for a page.
 *
 * A page's own `pdf:` frontmatter wins over the plugin configuration. When
 * `inherit` is on (the default) the parent chain is consulted first, so flagging
 * a listing switches on its articles too — which is what makes "export this
 * collection and everything in it" a one-line change.
 *
 * Individual keys are resolved rather than whole arrays, so a page can override
 * just `toc: false` and keep inheriting everything else.
 */
class Config
{
    private Grav $grav;

    /** @var array<string, mixed> */
    private array $global;

    private bool $inherit;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        $this->global = (array) $grav['config']->get('plugins.' . \Grav\Plugin\PdfPlugin::SLUG, []);
        $this->inherit = (bool) ($this->global['inherit'] ?? true);
    }

    /**
     * A plugin-wide setting (never page specific).
     */
    public function global(string $key, $default = null)
    {
        return $this->global[$key] ?? $default;
    }

    /**
     * A setting as it applies to $page.
     */
    public function forPage(PageInterface $page, string $key, $default = null)
    {
        if ($this->inherit) {
            // Walks the page's ancestors, then falls back to the plugin config.
            return Plugin::inheritedConfigOption(\Grav\Plugin\PdfPlugin::SLUG, $key, $page, $default);
        }

        $value = (new Data((array) $page->header()))->get(\Grav\Plugin\PdfPlugin::SLUG . '.' . $key);

        if ($value !== null) {
            return $value;
        }

        return $this->global[$key] ?? $default;
    }

    /**
     * Has this page asked to be exportable?
     *
     * Handled separately from forPage() on purpose: `enabled` is also Grav's own
     * plugin switch (`plugins.pdf.enabled`), so the generic fallback would find
     * that and enable every page on the site. Here the plugin config supplies
     * only the default for pages that say nothing (`all_pages`).
     */
    public function optIn(PageInterface $page): bool
    {
        $value = $this->pageHeader($page, 'enabled');

        if ($value !== null) {
            return (bool) $value;
        }

        return (bool) $this->global('all_pages', false);
    }

    /**
     * The value of `pdf.<key>` from the page itself, or from the nearest parent
     * that sets it. Deliberately does not consult the plugin config.
     */
    private function pageHeader(PageInterface $page, string $key)
    {
        if (!$page->exists()) {
            return null;
        }

        $walk = $page;

        while ($walk && !$walk->root()) {
            $value = (new Data((array) $walk->header()))->get(\Grav\Plugin\PdfPlugin::SLUG . '.' . $key);

            if ($value !== null) {
                return $value;
            }

            // Without inheritance only the page itself counts.
            if (!$this->inherit) {
                break;
            }

            $walk = $walk->parent();
        }

        return null;
    }
}
