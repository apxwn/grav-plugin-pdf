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
| `classes/Items.php` | what goes into the document: the collection, the language filter, the mode |
| `classes/Html.php` | the page's rendered HTML turned into printable HTML: images, anchors, dropped elements |
| `classes/Generator.php` | settings normalisation, document assembly, mPDF invocation |
| `classes/Store.php` | the on-disk document cache |
| `classes/Paths.php` | where documents and mPDF's working files live |
| `classes/State.php` | the `pdf` Twig variable |
| `templates/pdf/` | print stylesheet, cover, contents, article, book layout, example button |

Nothing in `classes/` or `templates/` may name a theme, a site or a specific frontmatter
key. Every key the document reads is a setting with a generic default; a site that names
things differently sets it in `user/config/plugins/pdf.yaml`.

## Conventions

* **PHP 8.1+**, typed properties, `declare(strict_types)` is not used (Grav does not).
* `private const` for fixed tables, `private static function` when no state is involved.
* Comments explain *why*, not *what*. A comment that restates the code is noise; a comment
  that records why `SetHTMLHeader()` is not used, or why an anchor sits inside its element,
  saves the next person from undoing the fix.
* User-visible strings go through `PLUGIN_PDF.*` and exist in all three languages.
* New settings need: a default in `pdf.yaml`, a field in `blueprints.yaml`, a translation
  key, and a row in the README table.
* Every Twig line the plugin emits for the document keeps its classes in `print-style.html.twig`.
  Do not put colours or sizes in the markup.

## Tests

There is no test suite. Before a release, verify at minimum:

1. a single-article PDF and a book PDF both return `200` with the expected page counts;
2. `pdftotext` output is identical to the previous release's for the same content;
3. a page that has not opted in still 404s;
4. `php -l` passes on every PHP file:

   ```bash
   find . -name '*.php' -not -path './vendor/*' -exec php -l {} +
   ```

5. the Admin screens load — the plugin configuration and a page's **PDF** tab;
6. the new files land in `cache/pdf/`, and `bin/grav clear-cache` removes them.

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

   The script ships the sources and the trimmed `vendor/`, and excludes `.git/`,
   `node_modules/`, and anything the plugin writes at runtime (`cache/`, `tmp/`, `logo/`
   uploads).

4. Install the archive into a clean Grav and repeat the checks above.

## Translations

`languages.yaml` is en, de and ru. Copy a block, translate the values, change nothing else.
Keys missing from a language fall back to English, so a partial translation is usable.
