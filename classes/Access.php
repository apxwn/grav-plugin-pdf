<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * Who may read a page.
 *
 * A PDF is served from `onPagesInitialized`, before Grav asks the page to
 * render, so the Login plugin's own authorization on `onPageInitialized` never
 * runs for a `.pdf` request. Without a check here a document would be easier to
 * get than the page it prints, and a book would carry entries the visitor may
 * not open at all.
 *
 * The Login plugin is the authority, because it is the thing that decides
 * whether the HTML page can be read: its `isUserAuthorizedForPage()` reads the
 * page's `access` rules, honours `parent_acl`, refuses a session that has not
 * finished its two-factor challenge, and lets any other plugin veto through
 * `PageAuthorizeEvent`. Asking it — rather than reimplementing the rules — is
 * what keeps a PDF and its page in agreement.
 */
final class Access
{
    /**
     * May the visitor of this request read $page?
     */
    public static function allows(Grav $grav, PageInterface $page): bool
    {
        $login = $grav['login'] ?? null;
        $user = $grav['user'] ?? null;

        if (is_object($login) && method_exists($login, 'isUserAuthorizedForPage') && $user instanceof UserInterface) {
            try {
                return (bool) $login->isUserAuthorizedForPage($user, $page, self::loginConfig($grav, $page));
            } catch (\Throwable $e) {
                // A listener that cannot answer is not an answer. Refusing is
                // the only failure mode that cannot leak a document.
                $grav['log']->error(sprintf(
                    'PDF: page access could not be resolved for "%s" (%s); refusing the document.',
                    (string) $page->route(),
                    self::bounded($e->getMessage())
                ));

                return false;
            }
        }

        // No Login plugin, or one too old to answer: `access` is inert in the
        // HTML too, so nothing would protect the page. The plugin must not be
        // the thing that makes a restricted page readable.
        return !self::declaresAccess($page);
    }

    /**
     * How the visitor is described in a log line.
     */
    public static function viewer(Grav $grav): string
    {
        $user = $grav['user'] ?? null;

        return $user instanceof UserInterface && $user->authenticated
            ? 'a signed-in visitor'
            : 'an anonymous visitor';
    }

    /**
     * The configuration the Login plugin's page authorizer expects.
     *
     * It is the plugin configuration with the page's own `login:` header merged
     * over it, which is what `Plugin::mergeConfig()` hands the same authorizer
     * on an HTML request. `parent_acl` lives there: with a bare `new Data()` the
     * PDF would ignore an ancestor's `access` rules that the page itself obeys.
     */
    private static function loginConfig(Grav $grav, PageInterface $page): Data
    {
        $defaults = (array) $grav['config']->get('plugins.login', []);
        $login = $page->header()->login ?? [];

        if (is_object($login)) {
            $login = (array) $login;
        } elseif ($login === true) {
            // `mergeConfig()` reads a boolean header the same way.
            $login = ['enabled' => true];
        } elseif (!is_array($login)) {
            $login = [];
        }

        return new Data(array_merge($defaults, $login));
    }

    /**
     * Does the page, or any page above it, declare access rules?
     *
     * Used only when no Login plugin can answer. The whole chain is consulted
     * because `parent_acl` could have been on, and without the plugin there is
     * nobody to say whether it was: refusing a page that might be restricted is
     * the safe side.
     */
    private static function declaresAccess(PageInterface $page): bool
    {
        for ($walk = $page; $walk instanceof PageInterface; $walk = $walk->parent()) {
            if (!empty((array) ($walk->header()->access ?? []))) {
                return true;
            }
        }

        return false;
    }

    /** Keep an untrusted message on one bounded line in the server log. */
    private static function bounded(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_strlen($message, 'UTF-8') > 500
            ? mb_substr($message, 0, 500, 'UTF-8') . '…'
            : $message;
    }
}
