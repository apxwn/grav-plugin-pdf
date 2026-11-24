<?php

/**
 * A stand-in for the Login plugin, for the end-to-end suite only.
 *
 * The PDF plugin does not implement page access itself: it asks the Login
 * plugin. Testing that contract against the real plugin would drag accounts,
 * sessions and redirects into a fixture that exists to test this plugin, and CI
 * installs a bare Grav with no Login plugin at all. This one implements the
 * narrow surface the PDF plugin relies on — `$grav['user']` and a service with
 * `isUserAuthorizedForPage()` — and nothing else.
 *
 * It is disabled by default: a fixture site behaves as if the Login plugin were
 * not installed until a test writes `plugins.login.enabled: true`, which is what
 * makes the fallback path testable too.
 *
 * Who the visitor is comes from a request header, so one class can cover guests
 * and members without a session:
 *
 *     X-PDFTest-Viewer: member
 *
 * Everything else is a guest.
 */

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\User\DataUser\User;

require_once __DIR__ . '/FakeLogin.php';

class LoginPlugin extends Plugin
{
    /** The request header that makes the visitor a signed-in member. */
    public const VIEWER_HEADER = 'HTTP_X_PDFTEST_VIEWER';

    public const MEMBER = 'member';

    /**
     * @return array<string, mixed>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Before `onPagesInitialized`, which is where the PDF plugin looks
            // the visitor up.
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        $viewer = strtolower(trim((string) ($_SERVER[self::VIEWER_HEADER] ?? '')));

        // DataUser\User rather than the account collection: the fixture needs
        // an object that is a UserInterface and has no storage behind it.
        // `authenticated` is deliberately not a constructor argument — the
        // class strips it.
        $isMember = $viewer === self::MEMBER || str_starts_with($viewer, self::MEMBER . ':');
        $username = $viewer === self::MEMBER
            ? 'fixture-viewer'
            : (str_starts_with($viewer, self::MEMBER . ':')
                ? substr($viewer, strlen(self::MEMBER) + 1)
                : 'fixture-viewer');

        $user = new User(['username' => $username]);
        $user->authenticated = $isMember;

        $this->grav['user'] = $user;
        $this->grav['login'] = new FakeLogin();
    }
}
