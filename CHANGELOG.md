# Changelog

## 1.2.0 — 2026-11-11

Makes the plugin theme- and site-independent, so it can be released on its own.

### Added

* **Fonts of your own.** `font_dir` names a folder of `.ttf` files and each family is read
  from its file names — `MyFont-Regular.ttf`, `MyFont-Bold.ttf`, `MyFont-Italic.ttf`,
  `MyFont-BoldItalic.ttf`. A family is then usable in `font_body` and `font_headings`
  alongside the four DejaVu faces. A `fonts` map declares the mapping explicitly when a
  site's names do not follow that convention, or when a file lives elsewhere — a value may
  be an absolute path, a stream or a Grav-root-relative path, and is resolved to that exact
  file. Declared entries win over what the file names imply.
* **The dateline is configurable.** `meta` lists the parts to print and their order
  (`date`, `category`, `author`), `meta_category_taxonomy` says which taxonomy the category
  comes from, and `meta_author_field` says which frontmatter key holds the author's name —
  a dotted path works. An empty `meta` list gives no dateline at all.
* **The frontmatter keys for images are settings.** `image_field`, `image_skip_field` and
  `gallery_field` say where a theme keeps its leading image, which image to leave out and
  which key marks a gallery. Previously the first two were hard-coded to one theme's header
  keys.
* An unknown font name now logs a warning and falls back to `dejavusans`, instead of mPDF
  silently substituting a face.
* `Paths`, a small class that resolves where the plugin writes: Grav's own `cache://` stream
  by default, or `cache_path`.
* `LICENSE` (GPL-2.0), `CREDITS.md`, `CONTRIBUTING.md`, and `build-release.sh`, which
  produces the installable archive from a clean staging tree.

### Changed

* **Runtime files moved out of the plugin folder.** Generated documents now live in
  `cache/pdf` inside Grav's cache directory and mPDF's working files in `cache/pdf/tmp`, so
  `bin/grav clear-cache` empties them, a read-only plugin directory is no longer a problem,
  and a plugin update cannot leave a stale cache behind. `cache_path` still moves the cache
  wherever a site wants it; a stream, an absolute path and a Grav-root-relative path all
  work.
* `date_format` now formats **every** date the plugin prints — the dateline, the cover date
  and `{TODAY}`. Its default is the generic `Y-m-d`; the per-site value belongs in
  `user/config/plugins/pdf.yaml`.
* `strip_shortcodes` defaults to `[]` rather than one site's list. Which shortcodes a site
  does not have installed is site-specific, so it is now opt-in.
* `font_body` and `font_headings` are free-text fields in Admin rather than a fixed list of
  four faces, since a site can now add families of its own.
* The example button uses `pdf-link`, `pdf-link__icon` and `pdf-link__label`. The old
  `stsi-pdf-link*` classes named a particular site and carried no styling of their own.
* The logo comes only from the `logo` setting. The fallback to the active theme's `favicon`
  is gone: a plugin should not guess what a site's brand mark is, and a theme that keeps its
  logo at a path can be given that path in one line of configuration.
* The README is rewritten for a general audience: install, quick start, a full configuration
  reference, theme integration including a Bootstrap example, fonts including the scripts
  the bundled faces do not cover, and troubleshooting. Development notes moved to
  `CONTRIBUTING.md`.

### Fixed

* A font file name is split at the *last* recognised style word, so
  `MyFont-Bold-Italic.ttf` is the bold-italic face of `MyFont` rather than the italic face of
  `MyFont-Bold`.

### Notes

* Requires PHP 8.1 or later and Grav 1.7 or later. mPDF 8.3.1 is bundled.
* Output is unchanged for a site that sets `date_format: 'd.m.Y'`, its own
  `strip_shortcodes`, and its own `image_field`/`image_skip_field`. This repository does so in
  `user/config/plugins/pdf.yaml`.
* Licence changed from MIT to GPL-2.0-only, because mPDF is GPL-2.0-only and the two cannot
  be distributed together otherwise.

## 1.1.0 — 2026-10-17

### Added

* **A typeface for the text and one for the headings.** `font_body` and `font_headings`
  choose between the four DejaVu faces mPDF carries — Sans and Serif, each plain and
  condensed — for the running text and for the headings (`h1`–`h6`, the cover title, the
  contents heading and the lines of the contents). Nothing is downloaded and no font file
  is added. Both default to `dejavusans`, so a document is unchanged until one of them is
  set, whether site-wide in the plugin configuration or in a page's own `pdf:` frontmatter.

  The running header and footer stay in the body font, because they are HTML you write;
  put a heading of your own in them and it follows `font_headings` like any other.

  A name the plugin does not know falls back to `dejavusans`, so a typo in a page's
  frontmatter cannot quietly produce a document other than the one the settings describe.

### Fixed

* The table of contents was set in DejaVu Sans Condensed without anyone asking. mPDF's own
  default stylesheet gives `div.mpdf_toc` the generic `sans-serif`, which mPDF resolves to
  the first entry of its sans list — `dejavusanscondensed` — while the same book's text was
  DejaVu Sans. The contents now follows `font_headings`, like the titles it lists.

* Text in a non-Latin script ignored the configuration. mPDF's `autoLangToFont` maps a
  detected script to a face of its own — Cyrillic and Greek to DejaVu Sans Condensed — and
  applied it on top of the stylesheet, so a Russian passage came out in condensed sans
  inside a document whose face was anything but. It is off: every script the four DejaVu
  faces cover is set in the face that was chosen.

### Notes

* The contents is a little wider in the default case, now that it is no longer condensed,
  so in a long book it wraps onto a few more lines and can push the document over a page
  boundary. In general a different face means different metrics: page counts and the
  contents' page numbers move with the choice.

## 1.0.5 — 2026-10-15

### Fixed

* A footnote in a book led to another article's footnote. All articles are written into one
  document and each numbers its own footnotes from one, so `id="fn:1"` and
  `id="fnref1:1"` appear once per article. mPDF keys its anchors by name alone and a
  repeated `<a name="…">` overwrites the earlier one, so every link to a name resolved to
  whichever article defined it last: in one 222-page book ten of the eleven footnote
  references jumped into the last article that shared the name, and each `↩` returned to
  that article's text instead of its own.

* The same collision lost heading targets. The page-toc plugin names a heading after its
  own text and with no page identity, so two articles that both carry a "Fußnoten" heading
  both get `id="fu-noten"`, and a `[TOC]` entry or `](#fu-noten)` link went to whichever
  article had one last.

### Changed

* A book now gives every entry a namespace of its own — `pdf3:fn:1` — applied to its ids
  and to the fragment links pointing at them, so a name cannot repeat. The prefix is the
  entry's position and holds a colon, which a page-toc slug (lower-case letters, digits and
  hyphens, 25 characters at most) can never contain. A single article is not namespaced and
  is unchanged.

### Notes

* Nothing moves. The 222-page book renders the same text in the same number of pages as
  1.0.4, the table of contents still lists the same page numbers, and a single article
  comes out byte for byte as it did, the version string aside.

## 1.0.4 — 2026-10-15

### Fixed

* Links that pointed at a fragment of the same document led nowhere. mPDF registers a link
  target only for an anchor written as `<a name="…">`, and every target on this site is an
  `id`: the ids the page-toc plugin puts on headings for the `[TOC]` entries, the
  `fn:1` / `fnref1:1` pair Markdown Extra footnotes are built from, and the two articles
  that anchor their own sections with a bare `<a id="…"></a>`. An `id` is never registered,
  so those links were written with no destination at all — a clickable annotation whose
  `/Dest` named the page tree itself instead of a page. The rendered body now carries an
  `<a name>` for every `id` it contains, and the links resolve: the 22 broken links in a
  222-page book went down to zero.

### Notes

* The anchors cost no space. An anchor is inline content, and an empty bit of inline
  content dropped in front of a block claims a line of its own — in a book that is enough
  to lengthen and renumber every page. So an anchor normally sits just inside the element
  it names, where it joins that element's first line. Two elements take it in front
  instead: a list item, which may open with a block, and a link, which may not hold an
  anchor at all (mPDF's `A::close()` clears HREF and the link is lost). Output is
  unchanged page for page: 1.0.3's text and the new text are byte-identical for single
  articles and for the 222-page book.

## 1.0.3 — 2026-10-15

### Fixed

* Text next to `{LOGO}` sat at the foot of the logo. An inline image rests on the text
  baseline, and the 8 mm logo is twice the height of the header text, so the text landed
  along the image's bottom edge. It is centred on the logo now.

* A header of several lines printed over the body. The geometry reserved a fixed
  `margin_top - margin_header` — 13 mm with the defaults — for the header whatever its
  real height. mPDF now measures the header and grows the top margin to fit it, leaving
  the configured margin untouched whenever the header fits.

### Changed

* The header is wrapped in `.pdf-header`, so the size, colour and alignment the
  stylesheet had always specified for it finally apply: 8 pt, grey, left-aligned.
  Nothing emitted that class before, so the header inherited the body's 11 pt
  near-black text instead.

* `templates/pdf/print-style.html.twig` is bare CSS with no `<style>` wrapper, because it
  is now loaded through mPDF's `HTMLParserMode::HEADER_CSS`. That load happens *before*
  the running header is defined, which is load-bearing: mPDF measures an `html_*` header
  the moment it is defined, and the grown top margin uses that measurement. Loaded
  afterwards, as it was, the logo would be measured at its natural size — about 130 mm —
  and every page would reserve a top margin tall enough to hold it.

## 1.0.2 — 2026-10-15

### Added

* The author is printed in the dateline under each article title, read from the page's
  `author.name` frontmatter (a plain `author: '…'` is accepted too). The line is
  `date · category · author`, and each part is dropped when the article has nothing to say.
  The `author: ['admin']` list form is ignored on purpose — it holds the account that
  posted the page, not a display name.

### Changed

* Category names in the dateline get a capital first letter (`predigt` → `Predigt`,
  `община` → `Община`). Only the first letter is touched, so the rest of the word is
  printed exactly as stored.

## 1.0.1 — 2026-10-15

### Fixed

* The logo field on the plugin's configuration screen could not be uploaded to. An Admin
  `type: file` field without a `destination` is treated as page media and posted to
  `/pages/<route>/media` — a plugin screen has no page route, so the request landed on a
  route that only accepts GET, DELETE and PATCH and failed with *Method 'POST' is not
  allowed*. The field now declares `destination: 'self@:/logo'` and uploads into
  `user/plugins/pdf/logo/`.

### Changed

* The logo is resolved from the configured value — either a hand-written path or the
  descriptor list Admin stores — and falls back to the active theme's own `favicon`
  setting when nothing is configured. That key is read generically, so no theme is named
  and a theme without a favicon simply produces no logo.
* The resolved logo file and its timestamp are now part of the cache fingerprint, so
  replacing the image rebuilds the document rather than waiting for `cache_ttl` to expire.
* The logo fields accept images only.

## 1.0.0 — 2026-10-15

First release.

### Added

* PDF generation for a single page, served from `<page-url>.pdf`.
* Book generation for any page that owns a collection: cover, table of contents, and one
  article per item, each starting on a new page.
* Per-page opt-in through `pdf: { enabled: true }` in frontmatter, with inheritance from
  parent pages, so a listing can be opted in once and cover every article beneath it.
  A page that has not opted in is a plain 404.
* Full plugin configuration with a per-page override of every key: cover, TOC, TOC depth,
  page numbers, title, date format, running header and footer, logo, images, collection
  source, paper size, orientation, font size, margins, cache and guards.
* HTML running header and footer through mPDF page boxes, with `{LOGO}`, `{SITE}`,
  `{TITLE}`, `{TODAY}`, `{PAGENO}` and `{NBPG}` tokens.
* A `pdf` Twig variable (`enabled`, `mode`, `url`, `label`, `count`, `title`) and a
  `pdf_url(page)` function for building a link from any theme or template.
* `templates/pdf/button.html.twig`, an example link partial a theme can include as
  `{% include '@pdf/button.html.twig' %}`. Neutral markup, a documented class hook, and an
  inline SVG icon — never a Font Awesome class.
* Button captions and Admin labels in English, German and Russian.
* A **PDF** tab added to every page in Admin through `onBlueprintCreated`.
* Text-only output by default, with a per-page `images: true` opt-in. Featured image,
  gallery (honouring `media_order`) and inline body images are then embedded in that
  order, scaled down and cached.
* Disk cache keyed by plugin version, mode, language, effective settings and each item's
  `route:modified`, with `ETag` and `304` support.
* An `HTTP 413` guard for collections larger than `max_items`.

### Notes

* Requires PHP 8.3+ and uses mPDF 8.3.1, bundled in `vendor/`. mPDF's unreachable font
  files (CJK, Arabic, Hebrew, Indic and specialist scripts, 68 MB) are not shipped; the
  DejaVu family and the Free family are kept. See the README for how to restore them.
* Fonts are mPDF's bundled DejaVu Sans (Latin and Cyrillic). Nothing is downloaded at
  render time and no theme asset is added.
* No theme file is modified. The print templates are owned by the plugin and live in their
  own Twig namespace, `@pdf/…`, so output does not depend on the active theme.
* Content is restricted to the language being served:
  `Page::collection()` filters to translated pages and the plugin additionally requires the
  active language to be among the page's `translatedLanguages(true)`.
* The cache and the scaled-image working files live inside the plugin folder
  (`cache/`, `tmp/`); nothing is written outside `user/`.
