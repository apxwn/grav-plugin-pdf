<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * The page authorizer the PDF plugin talks to.
 *
 * It answers the way the real Login plugin would for the shapes this suite
 * needs: `access:` rules apply to everybody who is not signed in, and only to
 * the page itself unless `parent_acl` is set. The two `pdf_test_*` flags are
 * scripted failures, not a real feature: a page uses them to make the
 * authorizer deny a signed-in visitor, or to throw, so the PDF plugin's own
 * behaviour can be tested without a second plugin in the fixture.
 *
 * The third argument matters: it is the merged `login:` configuration the PDF
 * plugin is expected to build (plugin defaults, page header on top). Reading
 * `parent_acl` from it — not from the page header alone — is what makes these
 * tests able to tell a proper merge from a bare `new Data()`.
 */
final class FakeLogin
{
    /** A page that denies every visitor, signed in or not. */
    public const DENY = 'pdf_test_deny';

    /** A page that makes the authorizer throw. */
    public const FAIL = 'pdf_test_throw';

    public function isUserAuthorizedForPage(UserInterface $user, PageInterface $page, ?Data $config = null): bool
    {
        $config ??= new Data();
        $login = self::header($page, 'login');

        if (!empty($login[self::FAIL])) {
            throw new \RuntimeException('The fixture Login plugin was told to fail for this page.');
        }

        if (!empty($login[self::DENY])) {
            return false;
        }

        if ($user->authenticated) {
            return true;
        }

        if (self::header($page, 'access') !== []) {
            return false;
        }

        // The one rule that exists only in the merged configuration: without
        // `parent_acl` an ancestor's `access:` rules do not reach its children,
        // and the page header is allowed to switch it off again.
        if (!empty($config['parent_acl'])) {
            for ($walk = $page->parent(); $walk instanceof PageInterface; $walk = $walk->parent()) {
                if (self::header($walk, 'access') !== []) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A header value as an array, whether Grav kept it an object or an array.
     *
     * @return array<string, mixed>
     */
    private static function header(PageInterface $page, string $name): array
    {
        $header = $page->header();

        if (is_array($header)) {
            $value = $header[$name] ?? null;
        } elseif (is_object($header)) {
            $value = $header->{$name} ?? null;
        } else {
            $value = null;
        }

        return is_object($value) ? (array) $value : (is_array($value) ? $value : []);
    }
}