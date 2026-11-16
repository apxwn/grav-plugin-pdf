<?php

namespace Grav\Plugin\Pdf\Engine\Gotenberg;

/**
 * Reduce user-authored HTML to print markup that cannot load external resources
 * or execute event handlers in Gotenberg's Chromium process.
 *
 * This is deliberately applied only at the Gotenberg boundary. The bundled
 * renderer keeps its existing HTML behavior, and Gotenberg's own templates
 * continue to provide the uploaded fonts, images and print stylesheet.
 */
final class HtmlSanitizer
{
    /** Elements whose contents must not be reinterpreted as printable markup. */
    private const DROP_CONTENT = [
        'audio', 'button', 'canvas', 'embed', 'form', 'iframe', 'input', 'link', 'math',
        'meta', 'noscript', 'object', 'option', 'script', 'select', 'source', 'style',
        'svg', 'template', 'textarea', 'track', 'video',
    ];

    /** Useful document markup; attributes are filtered separately. */
    private const ELEMENTS = [
        'a', 'abbr', 'article', 'aside', 'b', 'blockquote', 'br', 'caption', 'cite', 'code',
        'col', 'colgroup', 'dd', 'del', 'details', 'div', 'dl', 'dt', 'em', 'figcaption',
        'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'li',
        'main', 'mark', 'ol', 'p', 'pre', 'q', 's', 'section', 'small', 'span', 'strong',
        'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
        'u', 'ul',
    ];

    /** Attributes that are inert and useful to the print templates. */
    private const ATTRIBUTES = [
        'abbr', 'alt', 'class', 'colspan', 'dir', 'headers', 'height', 'id', 'lang', 'name',
        'rowspan', 'scope', 'start', 'title', 'value', 'width',
    ];

    public static function sanitize(string $html, bool $allowTrustedDataImages = false): string
    {
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $rootId = 'pdf-sanitize-' . bin2hex(random_bytes(16));

        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="UTF-8"></head><body><div id="' . $rootId . '">'
                . $html
                . '</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );

            if (!$loaded) {
                // Fail closed if the parser cannot build a tree.
                return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            $root = $document->getElementById($rootId);

            if (!$root instanceof \DOMElement) {
                return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            self::cleanChildren($root, $allowTrustedDataImages);

            $result = '';

            foreach ($root->childNodes as $child) {
                $result .= $document->saveHTML($child);
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function cleanChildren(\DOMNode $parent, bool $allowTrustedDataImages): void
    {
        // The live list changes as nodes are removed or unwrapped, so walk a
        // snapshot and check each node still belongs to its original parent.
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (!$node->parentNode || $node->parentNode !== $parent) {
                continue;
            }

            if (!$node instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_CONTENT, true)) {
                $parent->removeChild($node);
                continue;
            }

            self::cleanAttributes($node, $tag, $allowTrustedDataImages);
            self::cleanChildren($node, $allowTrustedDataImages);

            if (in_array($tag, self::ELEMENTS, true)) {
                continue;
            }

            // Unknown wrappers contribute no behavior once their attributes
            // are discarded; retain their already-sanitized text and children.
            while ($node->firstChild) {
                $parent->insertBefore($node->firstChild, $node);
            }

            $parent->removeChild($node);
        }
    }

    private static function cleanAttributes(\DOMElement $element, string $tag, bool $allowTrustedDataImages): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            if ($name === 'href' && $tag === 'a' && self::safeLink($value)) {
                continue;
            }

            // An image's source is subsequently resolved to a local Grav file
            // and rewritten as an uploaded asset by Assets::images().
            if ($name === 'src' && $tag === 'img' && self::localImagePath($value)) {
                continue;
            }

            if ($name === 'src' && $tag === 'img' && $allowTrustedDataImages && self::isSafeImageDataUri($value)) {
                continue;
            }

            if (in_array($name, self::ATTRIBUTES, true)) {
                continue;
            }

            $element->removeAttributeNode($attribute);
        }
    }

    private static function safeLink(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || str_starts_with($value, '#')) {
            return true;
        }

        // Reject control/whitespace-obfuscated schemes before checking them.
        $compact = preg_replace('/[\x00-\x20\x7f]+/', '', $value) ?? '';

        if (preg_match('/\Ahttps?:\/\//i', $compact) === 1) {
            return true;
        }

        return preg_match('/\Amailto:/i', $compact) === 1;
    }

    private static function localImagePath(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || str_starts_with($value, '//') || str_starts_with($value, '#')) {
            return false;
        }

        // Only paths can reach the asset resolver; reject data:, file:, http(s),
        // and other schemes instead of letting Chromium interpret them.
        return preg_match('/\A[a-z][a-z0-9+.-]*:/i', $value) !== 1;
    }

    public static function isSafeImageDataUri(string $value): bool
    {
        return preg_match('#\Adata:image/(?:png|jpe?g|gif|webp|avif|bmp);base64,[a-z0-9+/=\r\n]+\z#i', trim($value)) === 1;
    }
}
