<?php

namespace Grav\Plugin\Pdf;

/**
 * The running header and footer, expanded from one place.
 *
 * Both engines print the same instructions — `{LOGO} {SITE} · {TITLE}` and so
 * on — but they express two of the tokens differently: the built-in engine
 * wants a `<span class="pageNumber">` it turns into a page field, Chromium
 * wants the same span filled in after layout, and the logo is a file path for
 * one and a data URI for the other. Everything else, including the decision
 * that a header which is nothing but an absent logo reserves no room at all, is
 * shared.
 */
final class Furniture
{
    /**
     * Expand the tokens in a header or footer.
     *
     * `$dialect` carries the two engine-specific pieces:
     *
     *   logo   the `<img>` (or empty string) that stands for `{LOGO}`
     *   page   what `{PAGENO}` becomes
     *   total  what `{NBPG}` becomes
     *
     * @param array{logo: string, page: string, total: string} $dialect
     */
    public static function expand(
        string $html,
        array $settings,
        string $title,
        string $site,
        string $today,
        array $dialect,
        bool $isHeader
    ): string {
        // `page_numbers` supplies the default footer; it never edits one the
        // author wrote, so a custom footer keeps its {PAGENO} either way.
        if (!$isHeader && !empty($settings['page_numbers']) && trim($html) === '') {
            $html = '<div class="pdf-footer">{PAGENO} / {NBPG}</div>';
        }

        $replace = [
            '{NBPG}' => $dialect['total'],
            '{SITE}' => htmlspecialchars($site, ENT_QUOTES, 'UTF-8'),
            '{TITLE}' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            '{TODAY}' => $today,
            '{LOGO}' => $dialect['logo'],
            // The engine's dialect decides how a page number is spelled: a
            // span the renderer fills in, or a placeholder it reads itself.
            '{PAGENO}' => $dialect['page'],
        ];

        $html = str_replace(array_keys($replace), array_values($replace), $html);

        // A header that is nothing but an absent logo should not reserve space,
        // but one that holds an image does count as content. Page placeholders
        // count too, whichever way the engine spells them.
        $probe = (string) preg_replace('#<img\b[^>]*>#i', ' . ', $html);
        $probe = str_replace(['{PAGENO}', '{NBPG}', '{nbpg}'], '.', $probe);
        $probe = (string) preg_replace('#<span class="(?:pageNumber|totalPages)"></span>#i', '.', $probe);

        if (trim(strip_tags($probe)) === '') {
            return '';
        }

        // The header is wrapped so the stylesheet's .pdf-header gives it its
        // size, colour and alignment, and so its measured height — the thing
        // the engine reserves room for — is that of one block. The footer
        // brings its own class with it in the default markup above. The wrap
        // happens after the probe, so an absent logo still reserves nothing.
        return $isHeader ? '<div class="pdf-header">' . $html . '</div>' : $html;
    }
}
