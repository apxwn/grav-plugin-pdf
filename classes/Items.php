<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;

/**
 * Which pages belong in a document.
 */
class Items
{
    /**
     * The pages that make up the document for $page.
     *
     * A book is built from the page's own `content:` block — the very same
     * definition the listing template renders — but evaluated with pagination
     * off, so a listing's `limit:` cannot silently truncate the book. Grav's
     * `translated` filter is injected by Page::collection(), which is what keeps
     * untranslated fallback pages out of a ru PDF.
     *
     * @return PageInterface[]
     */
    public static function resolve(PageInterface $page, Config $config): array
    {
        $header = new Data((array) $page->header());
        $params = $header->get((string) $config->forPage($page, 'collection', 'content'));

        if (is_array($params) && !empty($params['items'])) {
            $items = [];
            foreach ($page->collection($params, false) as $item) {
                if ($item instanceof PageInterface && self::keep($item)) {
                    $items[] = $item;
                }
            }

            return $items;
        }

        // No collection definition: fall back to the page's children.
        $descendants = (string) $config->forPage($page, 'children', 'direct') === 'descendants';
        $source = $descendants ? $page->descendants() : $page->children();

        $items = [];
        foreach ($source as $item) {
            if ($item instanceof PageInterface && self::keep($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The document shape for a page.
     */
    public static function mode(PageInterface $page, Config $config, array $items): string
    {
        $explicit = (string) $config->forPage($page, 'mode', '');

        if ($explicit === 'book' || $explicit === 'article') {
            return $explicit;
        }

        return $items ? 'book' : 'article';
    }

    /**
     * The cheap version of mode() used while rendering an HTML page: it looks at
     * the page's own declaration and its children instead of building the whole
     * collection, and so does not read 100+ page files just to draw a link.
     */
    public static function looksLikeBook(PageInterface $page, Config $config): bool
    {
        $header = new Data((array) $page->header());
        $params = $header->get((string) $config->forPage($page, 'collection', 'content'));

        if (is_array($params) && !empty($params['items'])) {
            return true;
        }

        return count($page->children()) > 0;
    }

    /**
     * Is $page available in the language currently being served?
     *
     * The requirement is that a PDF never mixes in fallback content, so a page
     * that only exists in another language is simply not exportable in this one.
     */
    public static function inLanguage(PageInterface $page): bool
    {
        $language = Grav::instance()['language'];

        if (!$language->enabled()) {
            return true;
        }

        return array_key_exists(
            (string) $language->getLanguage(),
            $page->translatedLanguages(true)
        );
    }

    /**
     * Publishable, routable, non-modular pages only.
     */
    private static function keep(PageInterface $page): bool
    {
        if (!$page->published() || !$page->routable() || $page->isModule()) {
            return false;
        }

        return self::inLanguage($page);
    }
}
