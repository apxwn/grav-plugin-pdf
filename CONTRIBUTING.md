# Contributing

## Working on the plugin

The plugin has no front-end build step. The whole loop is: edit a PHP file or a Twig
template, clear the cache, request a PDF.

```bash
php -S 127.0.0.1:8123 system/router.php     # from the Grav root
```

`bin/grav server` is not a substitute: under the Symfony CLI it redirects to HTTPS and then
serves a "requires a router" stub, so it does not render Grav at all.

After a configuration change — and after editing a blueprint or a language file:

```bash
rm -rf cache/twig/* cache/compiled/config
```

Grav caches its merged configuration, so a changed `pdf.yaml` value is otherwise ignored
until the next cache clear.

A quick check of a generated document:

```bash
curl -s -o /tmp/x.pdf http://127.0.0.1:8123/blog.pdf
pdfinfo /tmp/x.pdf | grep Pages
pdftotext -f 1 -l 2 /tmp/x.pdf - | sed '/^$/d'
pdfimages -list /tmp/x.pdf | head
```

## Layout

| File | Responsibility |
|---|---|
| `pdf.php` | event wiring: request interception, Twig namespace, `pdf` global, blueprint extension |
| `classes/Config.php` | effective settings for a page, including inheritance |
| `classes/Access.php` | whether the requesting visitor may read a page, and with which fallback when the Login plugin cannot answer |
| `classes/Items.php` | what goes into the document: the collection, the language filter, the mode, the readable subset |
| `classes/Html.php` | the page's rendered HTML turned into printable HTML: images, anchors, dropped elements |
| `classes/Generator.php` | settings normalisation, document assembly, engine invocation |
| `classes/Admin.php` | values the Admin screens cannot get from a static blueprint (the font dropdowns) |
| `classes/Store.php` | the on-disk document cache |
| `classes/Paths.php` | where documents and the plugin's working files live |
| `classes/State.php` | the `pdf` Twig variable |
| `classes/Engine/` | the renderers: `PhpPdf` (bundled), `Gotenberg` (external), and the `Router` that picks one |
| `classes/Engine/Gotenberg/HtmlSanitizer.php` | Gotenberg-only allowlist for page content and running furniture before Chromium conversion |
| `classes/Engine/Gotenberg/Outline.php` | adds the viewer flag that opens the outline Chromium wrote |
| `fonts/` | the DejaVu faces the plugin embeds |
| `templates/pdf/` | print stylesheets, cover, contents, article, book layout, example button |

Nothing in `classes/` or `templates/` may name a theme, a site or a specific frontmatter
key. Every key the document reads is a setting with a generic default; a site that names
things differently sets it in `user/config/plugins/pdf.yaml`.

## Conventions

* **PHP 8.2+**, typed properties, `declare(strict_types)` is not used (Grav does not).
* `private const` for fixed tables, `private static function` when no state is involved.
* Comments explain *why*, not *what*. A comment that restates the code is noise; a comment
  that records why `SetHTMLHeader()` is not used, or why an anchor sits inside its element,
  saves the next person from undoing the fix.
* User-visible strings go through `PLUGIN_PDF.*` and exist in all three languages.
* New settings need: a default in `pdf.yaml`, a field in `blueprints.yaml`, a translation
  key, and a row in the README table.
* Every Twig line the plugin emits for the document keeps its classes in
  `php-pdf-style.html.twig` (and its Chromium counterpart, `gotenberg-style.html.twig`).
  Do not put colours or sizes in the markup.

## Tests

The suite lives in `tests/` and is development-only: it is excluded from the release archive and
from the plugin's shipped `vendor/`. Its own dependencies are installed from
`tests/composer.json`.

```bash
cd tests
composer install
vendor/bin/phpunit                   # both tiers
vendor/bin/phpunit --testsuite unit  # no Grav needed
```

| Tier | Needs | Covers |
|---|---|---|
| `tests/Unit` | nothing but PHP | `Lock`, `Store`, Gotenberg HTML sanitizer and uploaded asset filtering |
| `tests/E2e` | a Grav 2.x checkout | `.pdf` routing, cache/ETag/304, one build per document, `503`, `build_timeout`, sanitized Gotenberg multipart upload to a local fake endpoint |

The end-to-end suite builds a disposable site in `tests/.run/`. It symlinks `index.php`,
`system/` and `vendor/` from the Grav installation it finds — `GRAV_ROOT`, else the checkout the
plugin sits in — and gives the site its own `user/`, `cache/` and `logs/`. Your own site is never
read or written. Without a Grav installation the end-to-end classes are skipped with a clear
message, so the unit suite runs anywhere.

`PDFTEST_BOOK_ITEMS` sizes the generated book (default is set per test class); `PDFTEST_KEEP=1`
keeps the run directory and the server log after a failure.

Fixture conventions that are easy to get wrong:

* Grav reads the theme from `system.pages.theme`, not from a top-level `theme`, and renders
  nothing without one — hence the tiny fixture theme;
* a page needs a language suffix (`.de.md`); a page without one is not exportable in any
  language, while its HTML still renders;
* one folder is one page, and its children live in subfolders. Sibling `.md` files in one folder
  are one page in several languages;
* Grav's own caches are not safe against a cold parallel burst (two workers compiling the same
  template answer `500`), so the harness warms them before a test may fire one;
* the harness drops every `GRAV_*` variable from the server's environment and points
  `GRAV_ROOT` at the fixture root. Without that, a `GRAV_ROOT` exported for the test process —
  as CI does — makes the server boot that installation instead of the fixture, and every
  request answers 404 from the bare Grav skeleton.

The end-to-end classes start the site with a generous readiness budget, because a cold Grav
compiles its configuration, blueprints and pages on the first request. The failure message
names the last status and cURL error, which is usually enough to tell "still compiling" from
"wrong site".

"The document is being rebuilt" is tested by taking the plugin's own lock from the test process,
not by making a build slow, so those cases cannot flake. The parallel-burst test does depend on
the build overlapping, and only requires `503`s when the measured build was slow enough.

Test code declares `strict_types`; the plugin itself does not.

### What still needs a hand before a release

1. `pdftotext` output is identical to the previous release's for the same content, and
   `pdfinfo` shows the expected page counts;
2. with a real Gotenberg service selected, rendered text and page counts are sensible, links and
   local raster images work, and no unexpected network fetches occur;
3. both engines' PDFs carry `/PageMode /UseOutlines`, and `mutool show <file> outline` lists the
   expected article titles;
4. a page with `access:` rules answers `403` to a guest and `200` to a signed-in account that
   holds the permission — both when the rules sit on the page and when they are inherited from
   an ancestor with `login.parent_acl: true`;
5. a book of mixed entries carries only the readable ones (`pdftotext`), and a book with no
   readable entry answers `403` instead of becoming a PDF of the listing page;
6. a signed-in request leaves nothing in `cache/pdf/`, answers `Cache-Control: private,
   no-store`, and sends no `ETag`;
7. the Admin screens load — the plugin configuration and a page's **PDF** tab;
8. `php -l` passes on every PHP file:

   ```bash
   find . -name '*.php' -not -path './vendor/*' -exec php -l {} +
   ```

## Release

1. Bump `VERSION` in `pdf.php`, `version:` in `blueprints.yaml`, and the top entry in
   `CHANGELOG.md`.
2. Rebuild the autoloader so the bundled `vendor/` matches `composer.lock`:

   ```bash
   php bin/composer.phar --working-dir=user/plugins/pdf install --no-dev --no-interaction --optimize-autoloader
   ```

3. Build the archive:

   ```bash
   ./build-release.sh          # writes ../grav-plugin-pdf-<version>.zip
   ```

   The script ships the sources and the trimmed `vendor/`, and excludes `.git/`, `node_modules/`,
   the test suite (`tests/`), and anything the plugin writes at runtime (`cache/`, `tmp/`,
   `logo/` uploads).

4. Install the archive into a clean Grav and repeat the checks above.

## Translations

`languages.yaml` is en, de and ru. Copy a block, translate the values, change nothing else.
Keys missing from a language fall back to English, so a partial translation is usable.
