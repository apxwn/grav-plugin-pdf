<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;

/**
 * The `pdf` Twig global.
 *
 * A plain array, so themes can use it without knowing anything about the plugin:
 *
 *     {% if pdf.enabled|default(false) %}
 *       <a href="{{ pdf.url }}">{{ pdf.label }}</a>
 *     {% endif %}
 *
 * `count` is null unless `show_count` is on, because knowing it means building
 * the collection — which is real work to do on every HTML page render.
 */
class State
{
    /**
     * @return array<string, mixed>
     */
    public static function forPage(Grav $grav, ?PageInterface $page): array
    {
        $state = [
            'enabled' => false,
            'mode' => null,
            'url' => null,
            'label' => '',
            'count' => null,
            'title' => null,
        ];

        if (!$page instanceof PageInterface || !$page->exists()) {
            return $state;
        }

        $config = new Config($grav);
        $generator = new Generator($grav, $config);

        if (!$generator->isEnabled($page)) {
            return $state;
        }

        $mode = Items::looksLikeBook($page, $config) ? 'book' : 'article';
        $count = null;

        if ((bool) $config->forPage($page, 'show_count', false)) {
            $count = count(Items::resolve($page, $config));
        }

        return [
            'enabled' => true,
            'mode' => $mode,
            'url' => $page->url() . '.pdf',
            'label' => self::label($grav, $config, $page, $mode, $count),
            'count' => $count,
            'title' => (string) $page->title(),
        ];
    }

    /**
     * The button caption: an explicit config value wins, else the translation.
     */
    private static function label(Grav $grav, Config $config, PageInterface $page, string $mode, ?int $count): string
    {
        $key = $mode === 'book' ? 'book_label' : 'article_label';
        $label = trim((string) $config->forPage($page, $key, ''));

        if ($label === '') {
            $label = (string) $grav['language']->translate(
                'PLUGIN_PDF.' . ($mode === 'book' ? 'BOOK_LABEL' : 'ARTICLE_LABEL')
            );
        }

        if ($count !== null) {
            $label = str_replace('%count%', (string) $count, $label);
        }

        return $label;
    }
}
