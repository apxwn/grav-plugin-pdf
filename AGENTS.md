# Agent operating manual

## 1. Purpose and scope

This plugin exports an opted-in Grav page as an article PDF or exports a page collection as a book. It owns its print templates and fonts; it does not take over a site theme or change normal page rendering.

Keep the plugin generic and GPM-installable. Do not add site-specific theme assumptions, enable exports globally by default, or turn this into a general-purpose page renderer. Keep documents opt-in, with inheritance as configured.

## 2. Tech stack

- Grav 2.0.11+ is declared in `README.md` and `blueprints.yaml`, which also sets `compatibility: grav: ['2.0']`; PHP 8.2+ is declared in all three. Composer requires `ext-dom`, `ext-mbstring`, `ext-zlib`, and `dskripchenko/php-pdf` ^1.0. The local host checked for this manual runs Grav 2.2.30; that is not the minimum requirement.
- `php-pdf` is the bundled default and fallback renderer. Optional Gotenberg 9+ renders through Chromium as a separately operated service and requires PHP cURL.
- Five DejaVu families are bundled under `fonts/`; they cover Latin, Greek, and Cyrillic. Site fonts can be added through `font_dir` and `fonts`.
- Twig print templates are registered in the plugin namespace `@pdf/...`; no theme template or asset is required.
- The plugin and Composer package declare MIT. Check `LICENSE` and `CREDITS.md` before adding or replacing bundled dependencies or font assets.

## 3. Architecture map

- `pdf.php` — plugin events; `.pdf` request interception; `@pdf` registration; `pdf` Twig global and `pdf_url()`; Admin page blueprint extension; optional Gotenberg test API route.
- `classes/Config.php` — global settings and per-page effective settings, including ancestor inheritance and explicit opt-out.
- `classes/Access.php` — whether the requesting visitor may read a page: the Login plugin's `isUserAuthorizedForPage()` with the same merged `login:` configuration an HTML request gets, and a conservative fallback when no Login plugin can answer.
- `classes/Items.php` — collection/child selection, article-vs-book mode, published/routable/language filtering, and the readable-subset filter used for books.
- `classes/Html.php` — printable body preparation, removal of non-printing elements/shortcodes, local image resolution and resize, fragment anchors and per-entry ID namespacing.
- `classes/Generator.php` — normalized document settings/data/fonts, fingerprint, cache lookup, renderer invocation, response headers and PDF streaming.
- `classes/Engine/Document.php` — shared resolved input and rendering access for engines. `Engine.php` is the renderer contract.
- `classes/Engine/Router.php` — engine selection, Gotenberg failure handling, fallback, and circuit-breaker use. `PhpPdf.php` and `Gotenberg.php` implement the engines; `Engine/Gotenberg/` holds the HTTP client, upload assets, breaker, and failure type. `Engine/PhpPdf/` holds font and HTML element adapters.
- `classes/Furniture.php` — shared header/footer token expansion with engine-specific placeholders.
- `classes/Store.php` and `classes/Paths.php` — generated-document cache, temporary paths, and cleanup. Default runtime output is under Grav's `cache://pdf`, not inside the plugin.
- `classes/State.php` — the Twig `pdf` state. `classes/Admin.php` supplies dynamic font choices.
- `templates/pdf/` — shared article/book/cover/button markup plus separate php-pdf and Gotenberg document, TOC, style, and furniture templates.
- `pdf.yaml` — shipped plugin defaults. `user/config/plugins/pdf.yaml` overrides site-wide settings. Page frontmatter under `pdf:` overrides supported document settings; page blueprint fields are in `blueprints/pdf.yaml`. Plugin Admin fields are in `blueprints.yaml`.

## 4. Invariants — do not break

- Keep the plugin switch (`plugins.pdf.enabled`) separate from export opt-in. A page exports only when its own or inherited `pdf.enabled` is true, unless the deliberate global `all_pages` option is enabled. An explicit child `false` must be respected. `inherit` controls parent lookup.
- Do not use the plugin-wide `enabled` setting as the page opt-in default. It is also Grav's plugin load switch. Preserve the distinct `all_pages` behavior.
- Keep the default runtime path MIT-compatible. Do not reintroduce GPL runtime dependencies such as mPDF. Verify the license of every new dependency and bundled asset.
- Keep renderers behind `Engine`, `Document`, and `Router`. Do not call a renderer directly from unrelated classes or let engines independently re-read page configuration. Resolve common settings/data once in `Generator`.
- Preserve public Twig integration: `pdf` state (`enabled`, `mode`, `url`, `label`, `count`, `title`), `pdf_url(page)`, and the `@pdf/...` namespace. Return null from URL helpers for non-exportable pages and guard a missing `pdf` global when the plugin is off.
- Keep assets local-first. Do not add remote font/CSS/JS dependencies or leave remote image URLs for a renderer to fetch. Resolve images to local files; Gotenberg receives its HTML, fonts, and images as uploaded assets. Never add surprise network fetches during document rendering beyond the explicitly configured Gotenberg service.
- Keep page-language filtering: do not include untranslated fallback pages in a PDF for the active language. Keep collection pagination disabled for book export so listing limits do not truncate books.
- Keep cache names tied to the effective document inputs and the engine that actually rendered. A php-pdf fallback must not be cached under the Gotenberg identity. Preserve `ETag`/304 behavior and `X-PDF-Engine` on both cached and fresh responses.
- A `.pdf` request runs before `onPageInitialized`, so it must re-check page access itself on every request, cache hit or not. The Login plugin is the authority (`isUserAuthorizedForPage()` with the page's merged `login:` config, so `parent_acl` applies); without it, refuse a page that declares `access` on itself or an ancestor. Never serve or cache a book whose entries the visitor may not read, and never downgrade an all-denied book to a PDF of the listing page.
- Never read from the shared document cache for a signed-in visitor, and never write one there: page content is request-aware, so a document rendered for one viewer must not become a cache hit for another. `CACHE_VERSION` in `Generator` is the namespace to bump when a behavior change makes documents already on disk unsafe.
- Treat renderer differences as real. Both engines receive the same resolved document, but Chromium and php-pdf are different layout engines. In particular, the TOC is links-only; Gotenberg's header/footer are separate documents and do not share document fonts/styles/files; its running-header logo handling differs; layout and pagination can differ. Do not claim pixel-identical or page-number-identical output.
- Preserve fragment links and per-entry anchor namespacing in books. Repeated footnote and heading IDs otherwise collide. Avoid moving anchors in ways that create extra lines and shift pagination.
- Keep normal theme output unaffected. `.pdf` requests are handled directly; do not render through the active theme or require theme files.

## 5. How to work in this repo

- In `README.md` GitHub-flavoured Markdown tables, escape literal pipe characters inside cells as `\|`, including pipes inside inline code. Otherwise GitHub splits the cell at the pipe (for example, Twig filters such as `value\|default(false)`); verify rendered tables when documenting Twig snippets.

### Change the right layer

- Engine behavior belongs in `classes/Engine/` and its matching `templates/pdf/*` files. Keep shared input in `Document`; routing/fallback belongs in `Router`.
- Collection rules, language checks, or mode selection belong in `Items.php`. Printable HTML cleanup, anchors, and image resolution belong in `Html.php`.
- Effective settings, document data, fingerprints, cache coordination, and response handling belong in `Config.php`, `Generator.php`, `Store.php`, and `Paths.php` as appropriate.
- Shared print structure belongs in `article.html.twig`, `book.html.twig`, and `cover.html.twig`. Keep engine-specific styling/templates paired: `php-pdf-style.html.twig` with `gotenberg-style.html.twig`, and their respective document/TOC/furniture templates.
- Edit `pdf.yaml` for defaults, `blueprints.yaml` for plugin Admin settings, `blueprints/pdf.yaml` for per-page Admin fields, and `languages.yaml` for user-facing Admin strings. Do not add a per-page engine or Gotenberg host setting: renderer/endpoint/credentials are server-wide by design.

### Configuration scope

- `plugins.pdf.enabled` is Grav's plugin master switch. `inherit`, `all_pages`, `engine`, `gotenberg.*`, cache settings, `show_count`, and global button labels are site-level controls.
- Supported document settings resolve from the page's `pdf:` frontmatter, then (when `inherit: true`) the nearest ancestor that sets that key, then plugin configuration/defaults. Resolution is per key, so changing one field should not erase inherited siblings.
- The engine and `gotenberg` block are read only from plugin configuration. Do not let page frontmatter choose a renderer, service URL, credentials, or TLS policy.
- For the complete supported key list and defaults, consult `pdf.yaml` and the README configuration/per-page references; do not duplicate an incomplete list here.

### Test and review

The automated suite lives in `tests/` and is development-only (excluded from release archives).

- `tests/Unit` needs nothing but PHP: `Lock` and `Store`. Run `cd tests && composer install && vendor/bin/phpunit --testsuite unit`.
- `tests/E2e` runs a real Grav 2.x under `php -S` against a disposable fixture site in `tests/.run/`, and covers `.pdf` routing, cache/ETag/304, the one-build-per-document lock and its `503`, and `build_timeout`. It finds Grav through `GRAV_ROOT`, else the checkout the plugin sits in, and **skips** cleanly when none is found. Run `vendor/bin/phpunit --testsuite e2e` from `tests/`.

The fixture site owns its `user/`, `cache/` and `logs/` and only symlinks `index.php`, `system/` and `vendor/` from the Grav checkout: never point a test at the site under development, and never commit generated pages or run directories. `PDFTEST_KEEP=1` keeps a run directory for debugging; `PDFTEST_BOOK_ITEMS` sizes the generated book. `CONTRIBUTING.md` documents the fixture conventions (theme via `system.pages.theme`, language-suffixed pages, children as subfolders).

What the suite does not cover still has to be checked by hand before a release: rendered text and page counts (`pdftotext`, `pdfinfo`), images (`pdfimages`), Cyrillic/Greek coverage, fragment and footnote links in a book, Gotenberg sanitizing and fallback (`bin/plugin pdf gotenberg:test`), and the Admin screens. Compare semantics, not exact pagination across engines. From the Grav root, use `php -S 127.0.0.1:8123 system/router.php` (not `bin/grav server`).

After configuration, blueprint, or language changes, clear Grav caches as documented in `CONTRIBUTING.md` (`cache/twig/*` and `cache/compiled/config`). Run `php -l` over non-vendor PHP files. This local Grav checkout reports 2.2.30; the manifest requires Grav 2.0.11+, so the stated compatibility now matches the suite's Grav 2 target — the fixture harness relies on the Grav 2 layout.

Update related documentation whenever behavior changes: `README.md`, `CHANGELOG.md`, blueprints/defaults/translations, and `CONTRIBUTING.md` if the development workflow or test process changes. New settings need a default, appropriate Admin field/translation, documentation, and per-page support only if intended.

## 6. History and lessons learned

- The changelog records migration from mPDF to `dskripchenko/php-pdf` in 1.4.0 and the switch to MIT. The owner brief states the reason: mPDF's GPL license blocked clean GPM distribution. Keep the default dependency/license path MIT-compatible.
- Gotenberg was added as an optional Chromium engine in 1.3.0; php-pdf remains bundled, default, and fallback. The router, circuit breaker, engine-specific cache identity, and `X-PDF-Engine` are intentional reliability and observability behavior.
- The two renderers have documented output differences. Both TOCs omit page numbers. Gotenberg lays out in Chromium, sends running header/footer as separate documents, and cannot use the document's fonts/files there; its running vector logo is cover-only. Do not “fix” these by assuming engine parity.
- Preserve UTF-8 through HTML, Twig, font selection, metadata, and PDF checks; use `mb_*` operations for multibyte text. DejaVu covers Latin/Greek/Cyrillic, not all scripts; add and map an appropriate local font for others.
- Keep anchor changes pagination-neutral. The changelog records that moving an empty anchor before a block can add a line and renumber a long book. Keep book entry IDs namespaced to avoid repeated footnote/heading destinations.
- Header/footer models are renderer-specific. The README documents why the built-in renderer uses page boxes rather than `SetHTMLHeader()`; Gotenberg renders furniture separately with inline styles/assets. Test after changing either path.
- The changelog documents earlier font-style filename parsing, EXIF orientation, duplicate inline images, and php-pdf glyph-cache/empty-link issues. Preserve coverage for these sharp edges when touching fonts, image preparation, or parser adapters.
- The owner reports that implementation was developed across AI-assisted sessions under owner direction. That process history does not establish additional requirements; follow the repository code and documented behavior.

## 7. Open work and non-goals

- Do not assume exact layout parity between engines, themed PDF rendering, global export by default, remote asset loading, or per-page renderer/service selection are goals.
- WeasyPrint is not referenced or implemented in the current repository. Any prior evaluation is `Unklar / im Repo nicht belegt`; treat a local/CLI engine only as optional future work requiring an explicit design and implementation request.
- Beyond the behavior and limitations described in the current README/changelog, additional known gaps are `Unklar / im Repo nicht belegt`. Verify a proposed gap against the current code before documenting or addressing it.
