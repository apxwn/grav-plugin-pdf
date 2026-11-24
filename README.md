# PDF

Plugin for the [Grav CMS](https://getgrav.org). Generates a PDF for a page of a [Grav](https://getgrav.org) site.

Two shapes of document, chosen from the page the plugin is invoked on:

| The page… | The PDF |
|---|---|
| owns a collection (a listing, a blog index, a section with children) | **a book**: cover page, table of contents, and one article per item, each starting on a fresh page |
| is a single article | **that article**, with the running header on page one |

Nothing is generated until a page asks for it. Every page of the site behaves exactly as
before until its frontmatter opts in, so the plugin can be shipped enabled.

* Requests are served from `<page-url>.pdf` — `/blog.pdf` next to `/blog`.
* A `.pdf` request that has not been opted in is a plain 404.
* A `.pdf` request never bypasses page access: a page the visitor may not read is refused, and
  a book carries only the entries they may read. See [Access control](#access-control).
* The text is only ever in the language being served. A page that exists only in German
  is simply not exportable as a Russian PDF.
* All assets are local. Fonts come from the DejaVu faces bundled with the plugin (Latin,
  Greek and Cyrillic), chosen with `font_body` and `font_headings`; families of your own
  can be added from a folder of `.ttf` files. Nothing is downloaded at render time.
* No theme file is touched or required. The print templates live in the plugin, in their
  own Twig namespace (`@pdf/…`), so the PDF looks the same whichever theme is active.

---

## 1. Install

### As a release archive

Unpack into `user/plugins/pdf/` — the renderer is bundled in `vendor/`, so there is
nothing to build:

```bash
cd user/plugins
unzip ~/grav-plugin-pdf-1.5.1.zip     # creates ./pdf/
```

Then clear Grav's cache:

```bash
bin/grav clear-cache
```

### From source

A checkout of the sources alone has no `vendor/`. Build it from the project root:

```bash
php bin/composer.phar --working-dir=user/plugins/pdf install --no-dev --no-interaction --optimize-autoloader
rm -rf cache/twig/*
```

### Requirements

| | |
|---|---|
| Grav | 2.0.11 or later (tested on 2.2.30) |
| PHP | 8.2 or later, with `ext-dom`, `ext-mbstring` and `ext-zlib` |
| php-pdf | bundled, always available as the default and fallback renderer |
| Gotenberg | 9 or later — optional external service; PHP cURL extension required |

### Plugin layout

```
user/plugins/pdf/
├── pdf.php                  the plugin
├── pdf.yaml                 default configuration
├── blueprints.yaml          the plugin's Admin screen
├── blueprints/pdf.yaml      the "PDF" tab added to every page in Admin
├── languages.yaml           button captions and Admin labels (en, de, ru)
├── classes/                 Config, Access, Items, Html, Store, Paths, Generator, State, Admin
├── classes/Engine/          the renderers: PhpPdf, Gotenberg, Router
├── fonts/                   the DejaVu faces the plugin embeds
├── templates/pdf/           print templates + the example button partial
├── composer.json            php-pdf dependency
└── vendor/                  php-pdf itself
```

### About the bundled renderer

`dskripchenko/php-pdf` is a pure-PHP PDF toolkit with no runtime dependencies of its own,
so `vendor/` is a single package of about 7 MB. It reads HTML and CSS, embeds and subsets
TrueType fonts, and writes the document straight to a file.

The fonts are the plugin's own, in `fonts/`: DejaVu Sans, DejaVu Sans Condensed, DejaVu
Serif, DejaVu Serif Condensed and DejaVu Sans Mono, each in regular, bold, italic and
bold-italic. Between them they cover Latin, Greek and Cyrillic, and none of them has to be
downloaded or installed. `font_body` and `font_headings` choose between them — in the
Admin they are a dropdown, not a text field.

A site that carries content in another script adds a font of its own; see
[Fonts](#fonts) below.

---

## 2. Turning it on

There are two switches, and they do different things.

**The plugin switch** — `plugins.pdf.enabled` in `user/config/plugins/pdf.yaml`
(Admin: *Plugins → PDF*). This is Grav's own switch; with it off the plugin does not load
at all. It ships **on**.

**The page opt-in** — `pdf: { enabled: true }` in a page's frontmatter. Ships **off**.

So the effective rule is: a page has a PDF when it says so. Add one line to the listing you
want to export, and nothing else on the site is affected:

```yaml
---
title: Blog
pdf:
    enabled: true
---
```

### Inheritance

With `inherit: true` (the default) a page inherits `pdf:` from its parents. That one line
on `/blog` is therefore enough to give the listing a book **and** every article in it its
own single-article PDF — no frontmatter on the articles themselves.

A child can still override anything, or switch itself off again:

```yaml
pdf:
    enabled: false
```

Set `inherit: false` in the plugin configuration if you want every page to be explicit.

### Every page at once

`all_pages: true` in the plugin configuration exports pages by default, unless a page (or,
with inheritance on, one of its ancestors) explicitly sets `pdf.enabled: false`. Useful for a
quick look at how a section turns out; `false` (the default) is what you want in production.

### Access control

A PDF is never easier to get than the page it prints. Opting a page in does not opt out of
its access rules: every `.pdf` request asks the Login plugin whether this visitor may read
the page — the same `isUserAuthorizedForPage()` check the HTML page goes through — so
`access:` frontmatter, `parent_acl`, and a session that still owes its two-factor answer all
apply.

* A page the visitor may not read answers `403` with a short plain-text message, not a
  redirect to the login form. The text is `PLUGIN_PDF.FORBIDDEN` in `languages.yaml`.
* A book carries only the entries the visitor may read. If entries existed and none of them
  survived, the request is refused as a whole rather than turned into a PDF of the listing
  page. `pdf: { enabled: false }` on an entry leaves it out for everyone.
* The Login plugin is the only authority consulted. Without it — not installed, or switched
  off — `access:` protects nothing on the site, so a page that declares access rules, on
  itself or on an ancestor, is refused instead of exported.
* Any other plugin that denies through the Login plugin's `PageAuthorizeEvent` is honoured
  too, because that is the event being dispatched.

What is *not* covered: a plugin that gates a page in its own `onPageInitialized` handler.
A `.pdf` request is answered before that event, so only page access is enforced here.

---

## 3. Putting a link on the site

The plugin exposes a `pdf` variable while a page renders, and a `pdf_url()` function.

```twig
{% if pdf.enabled|default(false) %}
    <a href="{{ pdf.url }}">{{ pdf.label }}</a>
{% endif %}
```

`pdf` is a plain array, so a theme needs to know nothing about the plugin:

| Key | Value |
|---|---|
| `enabled` | the page can be exported (and was opted in) |
| `mode` | `book` or `article` |
| `url` | the PDF URL, e.g. `/blog.pdf` |
| `label` | a translated caption, e.g. *Download as book* |
| `count` | the number of articles the visitor may read, or `null` (see `show_count`) |
| `title` | the page title |

`pdf_url()` builds the same link for any page:

```twig
{% set url = pdf_url(page) %}            {# the page being rendered #}
{% set url = pdf_url(some_other_page) %} {# any other page #}
```

Both return `null` when the page is not exportable, so `{% if url %}` is the guard.

> Always write `{% if pdf.enabled|default(false) %}`. If the plugin is switched off
> entirely, the `pdf` variable does not exist and a bare `pdf.enabled` is an error.

### The example button

`templates/pdf/button.html.twig` is a ready-made link, shipped so the markup does not have
to be invented per theme. Include it straight from a theme template:

```twig
{% include '@pdf/button.html.twig' %}
```

or copy it into the theme and change the classes. It writes:

```html
<a class="pdf-link" href="…" rel="nofollow" data-pdf-mode="book">
    <svg class="pdf-link__icon" …>…</svg>
    <span class="pdf-link__label">Download as book</span>
</a>
```

`pdf-link`, `pdf-link__icon` and `pdf-link__label` carry no styling of their own — they are
hooks for your CSS. There are no framework classes and no icon font in the markup; the icon
is inline SVG, which cannot silently disappear the way a missing icon-font glyph does. The
`data-pdf-mode` attribute is there for a theme that wants to style a book link differently
from an article link:

```css
[data-pdf-mode="book"] .pdf-link__icon { color: var(--accent); }
```

### Theme integration

Any theme can use it; the `@pdf` namespace belongs to the plugin. For a Bootstrap-style
site, a reasonable starting point:

```twig
<a class="btn btn-sm btn-outline-secondary pdf-link" href="{{ pdf.url }}">
    <svg class="pdf-link__icon" …>…</svg>
    <span class="pdf-link__label">{{ pdf.label }}</span>
</a>
```

For [Quark](https://github.com/getgrav/grav-theme-quark), the two template hooks that matter
are the article partial and the listing template:

| Where | What to add |
|---|---|
| the article partial, under the article body | `{% if pdf.enabled\|default(false) %}<a href="{{ pdf.url }}">{{ pdf.label }}</a>{% endif %}` |
| the listing template, above the items | the same block — on a page with a collection it links to the book |

---

## 4. Configuration

`user/config/plugins/pdf.yaml` overrides the shipped defaults in
`user/plugins/pdf/pdf.yaml`. Document options can also be set per page under `pdf:`. Renderer
settings are site-wide: a page cannot choose a host or engine.

### Renderer

php-pdf is bundled and remains the default. To use the optional external Gotenberg service,
set its address and select it as the engine:

```yaml
engine: gotenberg
gotenberg:
  url: '127.0.0.1:3000'
  timeout: 30
  connect_timeout: 2
  fallback: true
  retry_after: 60
  username: ''
  password: ''
  verify_tls: true
```

The address may be `host:port`, a hostname, or a full `http://` / `https://` URL. Gotenberg is not installed by the plugin; run Gotenberg 9 or newer separately and make sure the Grav host can reach it.
Admin's **Test Gotenberg connection** button and `bin/plugin pdf gotenberg:test` use the
configured address and credentials to check `/version` and `/health`.

With fallback enabled (the default), a failed conversion is automatically rendered by
php-pdf. The PDF response reports the actual renderer in `X-PDF-Engine`; failures are logged
and a short-lived breaker avoids waiting on an unavailable host for every request. Turning
fallback off makes a Gotenberg failure an error instead.

Gotenberg uses Chromium rather than php-pdf. A book's contents are links-only because page
numbers are unknown before layout; the cover and articles are kept in one HTML document so
anchors, footnotes and named destinations survive. Its PDF outline follows `toc_depth`, and the
finished document gets the catalog flag that opens the bookmarks panel, as with php-pdf.
Fonts used by the document are uploaded with each conversion. Running headers/footers are
separate Chromium documents and may use the host's fallback font. Use raster images for
Gotenberg documents: SVG images and logos are omitted because SVG can carry its own resource
references, and a repeated vector logo can make large books disproportionately large.

#### Gotenberg security and remote services

Gotenberg is a separate service, whether its URL points to `127.0.0.1`, another machine on a
private network, or a remote provider. **Neither this plugin nor Grav controls how that
instance is configured or secured.** Do not assume that a local address means the Grav
administrator controls the service: security settings must be handled by whoever operates or
controls the configured Gotenberg instance. Where that operator can configure it, consider
disabling Chromium JavaScript and restricting its outbound requests with Gotenberg's Chromium
deny-list, IP restrictions, or network policy. These are defense in depth, not settings the
plugin can enforce; disabling JavaScript alone does not prevent resource requests.

The plugin sanitizes page content and configured headers/footers for Gotenberg: it removes
active and resource-loading markup, event-handler and inline-style attributes, keeps common
print markup, and only permits safe web/mail links and local raster images (which are uploaded
with the conversion). Ordinary external hyperlinks may remain clickable in the PDF; opening one
is a reader action, not a resource fetch during conversion. This sanitizer applies only to
Gotenberg; php-pdf retains its existing behavior. The sanitizer is a defense-in-depth measure,
not a substitute for securing the service itself.

For a remote service, the rendered HTML and the fonts and images needed by the document are
uploaded to the configured endpoint. Use a service you trust or control, and use HTTPS where
available. TLS verification helps authenticate an HTTPS endpoint, and optional Basic Auth can
authenticate to the service; neither establishes whether its operator is trustworthy.

### Document

| Key | Default | Meaning |
|---|---|---|
| `cover` | `true` | cover page for a book (never for a single article) |
| `toc` | `true` | table of contents for a book |
| `toc_depth` | `1` | `1` = article titles only, `2` = also `<h2>`s inside articles |
| `page_numbers` | `true` | page numbers in the footer, except on the cover |
| `title` | `''` | cover title and PDF metadata; empty means the page title |
| `date_format` | `Y-m-d` | `date()` format for every date the document prints |

`page_numbers` supplies the default `{PAGENO} / {NBPG}` footer. It never edits a footer you
wrote yourself, so a custom footer keeps its `{PAGENO}` either way.

`date_format` applies to the dateline under each article title, the date on the cover and
the `{TODAY}` token. It is deliberately separate from `system.pages.dateformat.default`,
which Grav uses to *parse* frontmatter dates and which defaults to `Y-m-d H:i` — that reads
badly in print. Set it to `d.m.Y` or similar for a European layout.

### The dateline

The line under each article title is built from the article itself:

```
2026-09-27 · Sermon · Jane Doe
```

Which parts appear, and in what order, is `meta`:

```yaml
meta: [date, category, author]      # or, as Admin writes it:
meta: {date: 1, category: 1, author: 1}
```

The known parts are `date`, `category` and `author`. Each is dropped when the article has
nothing to say, so an article with only a category gets `2026-09-27 · Sermon`. An empty list
gives no dateline at all. Categories are printed with a capital first letter (`sermon` →
`Sermon`, `община` → `Община`); the rest of the word is left exactly as it is stored.

Three settings say where the values come from, because no two themes agree:

| Key | Default | Meaning |
|---|---|---|
| `meta_category_taxonomy` | `category` | the taxonomy the category is read from |
| `meta_author_field` | `author` | the frontmatter key (or dotted path) holding the author's name |

```yaml
author:
    name: 'Jane Doe'
```

A plain `author: 'Jane Doe'` is read as the same thing. A **list** — the `author: ['admin']`
shape that generated pages often carry — is deliberately ignored: it names the account that
posted the page, not a name to print. Leave `author.name` out and the dateline ends after
the category.

### Running header and footer

| Key | Default | Meaning |
|---|---|---|
| `header` | `{LOGO}` | HTML on every page except the cover of a book |
| `footer` | `''` | HTML; empty plus `page_numbers: true` gives `{PAGENO} / {NBPG}` |
| `logo` | `''` | image for the `{LOGO}` token; a path you write, or whatever the Admin file field stored |

Tokens, usable in either:

| Token | Becomes |
|---|---|
| `{LOGO}` | the configured logo, as an `<img>` |
| `{SITE}` | the site title from `user/config/site.yaml` |
| `{TITLE}` | the document title |
| `{TODAY}` | the date the PDF was generated |
| `{PAGENO}` | the current page number |
| `{NBPG}` | the total number of pages |

A header taller than the room `margin_top` leaves below `margin_header` — 13 mm with the
defaults — does not print over the body: it grows the top margin to fit. The document
measures the header, so one that already fits inside the configured margin changes nothing,
and the cover's own empty header never grows anything. A header holding nothing but a logo
that is not configured reserves no space at all.

The header is wrapped in `.pdf-header`, which is where its size, colour and alignment come
from: 8 pt, grey, left-aligned. That wrapper is also what the grown margin is measured
against, so the header stays one block as far as the measurement is concerned. The footer is
not wrapped — the default footer markup carries `.pdf-footer` on its own.

Text beside `{LOGO}` is centred on the logo. An inline image otherwise rests on the text
baseline, which puts the shorter text along the logo's bottom edge. The centring follows the
line, so a logo beside text that wraps lines up with the first line, not with the whole
block.

#### Where the logo comes from

Only the `logo` setting is consulted. It may be either

1. **a path you write** in the configuration or in a page's frontmatter —
   `theme://images/logo.svg`, `user://images/logo.png`, `user/themes/my-theme/logo.png`, or
   a path relative to the Grav root; or
2. **what the Admin file field stored** — a list of `{name, path, …}` descriptors.

Both are read, so the field can be filled in by hand or uploaded from Admin. When nothing is
set, or nothing that is set resolves to a file, the document simply has no logo. There is no
fallback to a theme setting: a plugin should not guess what a site's brand mark is, and a
theme that keeps its logo at a particular path can be given that path in one line of
configuration.

The resolved file and its timestamp are part of the cache fingerprint, so replacing the
image rebuilds the document without waiting for `cache_ttl`.

In Admin the field uploads into `user/plugins/pdf/logo/`, which is what its
`destination: 'self@:/logo'` says. That key is load-bearing: an Admin `type: file` field
*without* a `destination` is treated as page media and posts to `/pages/<route>/media`. A
plugin screen has no page route, so the request lands on a route that only accepts GET,
DELETE and PATCH, and the upload fails with:

> Failed to upload …: Method 'POST' is not allowed. Allowed: GET, DELETE, PATCH.

### Images

The PDF is text by default. Turn images on per page:

```yaml
pdf:
    images: true
```

Then each article contributes, in this order: the leading image and the gallery (honouring
`media_order`). Images placed inline in the body are left where they are and are not repeated
at the top, so no picture is printed twice.

| Key | Default | Meaning |
|---|---|---|
| `images_max` | `20` | images per article |
| `images_width` | `1200` | px; larger images are scaled down before embedding |
| `images_quality` | `75` | JPEG quality of those scaled copies |

Which frontmatter keys the images come from is configurable, since every theme names them
differently:

| Key | Default | Meaning |
|---|---|---|
| `image_field` | `''` | a header key holding one leading image, e.g. `featuredImage` |
| `image_skip_field` | `''` | a header key holding an image to leave out, e.g. `author.avatarImage` |
| `gallery_field` | `gallery` | a header key that, when true, means "every image on the page" |

With `image_field` empty, the first page image is used, skipping whatever `image_skip_field`
names — which is what stops a listing's article avatars from becoming the article's lead
picture. An `image_field` that is set but missing on a page falls back to that same rule.
Images already placed inline in the body are skipped, so the lead picture is the first one
the body does not print.

Scaled copies are cached next to the generated documents, so a book that reuses an image
pays for it once.

### Content

| Key | Default | Meaning |
|---|---|---|
| `collection` | `content` | the page header holding the collection definition |
| `children` | `direct` | fallback when there is no collection: `direct` children or the whole `descendants` subtree |
| `strip_shortcodes` | `[]` | shortcodes whose plugin is missing, which would otherwise print as literal text |

A book is built from the page's own `content:` block — the same definition the listing
template uses — but evaluated with pagination off, so a listing's `limit: 12` cannot
truncate the book.

`strip_shortcodes` is empty by default because which shortcodes a site does not have
installed is site-specific. List the prefixes whose text should never reach the paper:

```yaml
strip_shortcodes: [gallery, carousel]
```

### Layout

`paper` (`A4`), `orientation` (`P` or `L`), `font_size` (`11`), and the margins
`margin_top`/`margin_bottom` (`25`/`20`), `margin_left`/`margin_right` (`18`/`18`),
`margin_header`/`margin_footer` (`12`/`8`), all in mm or pt.

### Fonts

| Key | Default | Meaning |
|---|---|---|
| `font_body` | `dejavusans` | the text, tables, dateline, and the running header and footer |
| `font_headings` | `dejavusans` | headings `h1`–`h6`, the cover title, the contents heading and the lines of the contents |

Both are one of the five DejaVu faces the plugin ships — `dejavusans`,
`dejavusanscondensed`, `dejavuserif`, `dejavuserifcondensed`, `dejavusansmono` — so nothing
is downloaded and no font file is added. In the Admin they are a dropdown, so nobody has to
type a family name. Latin, Greek and Cyrillic are covered by all five, and Cyrillic and
Greek are set in the chosen face too, not in some substitute.

The running header and footer are HTML you write, so they keep the body font. Write a
heading in them and it follows `font_headings` like any other heading. A name the plugin
does not recognise falls back to `dejavusans` with a warning in the log, rather than
substituting a face silently.

#### Fonts of your own

Put the `.ttf` files in a folder, point `font_dir` at it, and pick the family in
`font_body` or `font_headings`. The family is read from the file names:

```
myfonts/MyFont-Regular.ttf      myfonts/MyFont-Bold.ttf
myfonts/MyFont-Italic.ttf       myfonts/MyFont-BoldItalic.ttf
```

```yaml
font_dir: 'user-data://pdf/fonts'
font_body: myfont
```

The family name is lower-cased and stripped to letters and digits, so `My Font` above is
`myfont`. Only the regular face is required; a missing bold or italic is substituted by the
nearest face the family has. `font_dir` accepts one path or a list, and each may be a stream
(`user-data://pdf/fonts`, a folder in Grav's data stream), an absolute path, or a path
relative to the Grav root.

Once `font_dir` is saved, the family appears in the Admin dropdowns alongside the built-in
faces — the list is built when the form is drawn, so reload the settings screen after
changing the directory.

When a site's file names do not follow that convention, declare the mapping instead — this
also works for a file outside `font_dir`, given as a path:

```yaml
font_dir: 'user-data://pdf/fonts'
fonts:
  myfont:
    R: MyFont-Regular.ttf
    B: MyFont-Bold.ttf
    I: MyFont-Italic.ttf
    BI: MyFont-BoldItalic.ttf
```

`R`, `B`, `I` and `BI` are regular, bold, italic and bold-italic. A declared entry wins
over what a file name implies.

A value may also be a path rather than a file name — an absolute path, a stream, or a path
relative to the Grav root — which is how a font that lives outside `font_dir` is used:

```yaml
fonts:
  myfont:
    R: '/srv/fonts/MyFont-Regular.ttf'
    B: 'user-data://pdf/fonts/MyFont-Bold.ttf'
```

The plugin resolves the path and hands the renderer a name that leads back to that exact
file, so two folders may hold fonts of the same name without one shadowing the other. A path
that does not exist stops the document with an error rather than printing in a substitute
face.

Fonts are embedded and subsetted, so a document grows by roughly the glyphs it actually
uses, not by the size of the face.

#### Scripts the bundled fonts do not cover

DejaVu covers Latin, Greek and Cyrillic. Arabic, Hebrew, the Indic scripts, Thai and the CJK
scripts need a font that has them. The practical route is a font from the
[Noto](https://fonts.google.com/noto) family for the script in question, added with
`font_dir` and the mapping above. Without such a font, a glyph outside DejaVu falls back to
nothing and is logged as a missing character.

### Cache and guards

| Key | Default | Meaning |
|---|---|---|
| `cache` | `true` | cache generated PDFs on disk |
| `cache_ttl` | `86400` | seconds; `0` never expires |
| `cache_path` | `''` | empty means `cache/pdf` in Grav's cache directory |
| `build_timeout` | `0` | seconds one build may take; `0` = PHP's own limit |
| `max_items` | `500` | refuse a book larger than this (`0` = no limit) |
| `inherit` | `true` | a page inherits `pdf:` from its parents |
| `all_pages` | `false` | export every page regardless of frontmatter |
| `show_count` | `false` | put the article count in `pdf.count` and the caption |

A cached document is named after a fingerprint of everything that produced it — the plugin
version, the cache namespace, the mode, the language, the effective settings, the logo file
and its timestamp, and each item's `route:modified`. Edit any source page and the name
changes, so a stale PDF can never be served. A book's entry list is the one that was actually
rendered, so narrowing it changes the name too. `cache_ttl` is only a backstop for changes
the fingerprint cannot see, such as an edit to a print template. Requests carry an `ETag`
and answer `If-None-Match` with `304`.

**A document rendered for a signed-in visitor is never cached.** Page content is rendered for
whoever asked — Twig and shortcodes can show one visitor something another must not see — so
a signed-in request reads nothing from the disk cache, writes nothing to it, and gets
`Cache-Control: private, no-store` with no validators. The file is deleted once it has been
streamed. One account may have only one PDF build in progress at a time, across all documents;
other accounts can build concurrently. A second request for the same account gets `HTTP 503`
with `Retry-After` rather than waiting or sharing the first request's output. This limit also
applies with `cache: false`. Guests share the cache, and a guest's rights are decided by the
page's access rules alone, so a guest document can safely be handed to any guest. Access itself
is checked on every request regardless, cache hit or not.

Generated documents live in Grav's cache stream, so `bin/grav clear-cache` empties them
along with everything else and nothing has to be writable inside the plugin folder.
`cache_path` moves them elsewhere; a stream, an absolute path or a Grav-root-relative path
all work. The directory is created on demand.

**For guests, one request builds a document, the others do not.** A cache miss is the expensive
path, and after an edit every concurrent hit on the same page misses at once. The first request
to take the lock builds; the others are answered from the newest document already on disk under
that key — even one past `cache_ttl`, because the key is the fingerprint and the content behind
it has not changed — or, when there is nothing to serve yet, which is the normal case right
after an edit because the edit produced a new key, with `HTTP 503` and `Retry-After`. Nothing is
queued, so no request is held waiting behind a build. The guest lock is advisory: it is not
taken with `cache: false` (that setting asks for a fresh document per request), and it fails
open when the cache directory cannot hold the lock file, so a filesystem without working
`flock` — some network mounts — behaves exactly as before. Signed-in account locks are separate,
required even when the document cache is off, and fail closed if the lock cannot be established.

A build writes to a hidden `.part` file and renames it into place, so a half-written document
is never served and never counted as a cached one. PHP does not run a request's cleanup when it
kills the script for exceeding its time limit, so `Store::prune()` also removes the working files
a killed build leaves behind once they are older than an hour — its `.part` scratch document,
the Gotenberg request body assembled beside it, and the HTML parts of a Gotenberg request — and
lock files that nothing has held for a day.

`build_timeout` bounds one build: reading the entries as well as drawing them, but not sending
the finished file, so a slow download is never cut off. PHP's own limit is restored afterwards,
and `0`, the default, leaves it alone. A build that hits the limit ends the request the way any
PHP timeout does, and the scratch file it left behind is removed by the sweep described above.
`gotenberg.timeout` bounds a wait on the service in addition.

`max_items` is a guard, not a limit: a collection larger than it produces `HTTP 413` with a
plain-text explanation, rather than a request that never finishes. A 200-page book takes a
few seconds the first time and is instant afterwards.

`show_count: true` makes `pdf.count` available and replaces `%count%` in the caption. It
costs one extra collection build on every HTML page render, which for a large listing is
real work, so it is off by default.

### Button captions

`book_label` and `article_label` override the translated captions. `%count%` is the number
of articles. The shipped translations are in `languages.yaml`:

| Language | Book | Article |
|---|---|---|
| en | Download as book | Download as PDF |
| de | Als Buch herunterladen | Als PDF herunterladen |
| ru | Скачать книгой | Скачать в PDF |

---

## 5. Per page reference

Everything above, in a page's frontmatter, under `pdf:`:

```yaml
---
title: Blog
pdf:
    enabled: true
    mode: book          # book | article — by default decided automatically
    title: Blog 2010–2026
    cover: true
    toc: true
    toc_depth: 1
    page_numbers: true
    date_format: 'd.m.Y'
    meta: [date, category, author]
    images: false
    images_max: 20
    font_body: dejavuserifcondensed
    font_headings: dejavusans
    header: '{LOGO}'
    footer: ''
    logo: 'theme://images/logo.svg'
    collection: content # the header holding the collection definition
    children: direct    # direct | descendants
    max_items: 500
---
```

A page with a collection becomes a book, anything else a single article. `mode` forces it
either way — `mode: article` on a listing gives one PDF of the listing's own text, and
`mode: book` on a page without a collection falls back to its children.

All of this is editable in Admin: the plugin adds a **PDF** tab to every page.

---

## 6. How it works

*The interception.* `.pdf` is not a Grav page type, so the route arrives with the extension
still attached. The plugin strips it in `onPagesInitialized` — before Grav decides the
request is a 404 — finds the page, checks the opt-in, streams the document and stops. No
page template and no theme template is involved in a `.pdf` request.

*The documents.* A print stylesheet, a cover, a table of contents and one article per entry
are rendered from the plugin's own Twig templates through Grav's Twig environment. The
built-in engine lays the result out as a `Section` with header and footer blocks, and gives
the cover a blank first page so the running header starts on page two.

*The contents.* Article titles are injected as `<h1>`, which is what the table of contents
and the PDF bookmarks are built from. Breadcrumb-style metadata (date, category, author)
sits under each title.

*The text.* Content is the page's rendered HTML with anything that cannot print removed:
`<script>`, `<style>`, `<iframe>`, `<form>`, `<video>`, `<audio>`, `<object>`, `<embed>`,
`<svg>`, `<canvas>`, `<map>`, `<noscript>`, form controls, and shortcodes whose plugin is
not installed. Images are dropped unless the page opted in; when they are kept they are
pointed at local files — a renderer fetching over HTTP breaks under a single-threaded dev
server, and behind a firewall it can hang — and scaled down first.

*The links.* A `[TOC]` entry, a footnote reference and its way back, and a hand-written
`](#…)` anchor all jump within the document. The built-in engine registers a link target for
a heading's `id` and for an `<a name="…">`, and for nothing else — an element carrying only
an `id` is not a target — so the body is given an `<a name>` for every `id` it carries. The
anchors are placed inside the element they name, where they join its first line, so nothing
shifts: the same content gives the same page count and the same page numbers. A list item
and a link are the exceptions, and take theirs in front; see `Html::anchors()` for why.

In a book every entry also gets a namespace of its own — `pdf3:fn:1` — on its ids and on the
fragment links pointing at them. Each article numbers its own footnotes from one and names
its headings after its own text, so names repeat by construction, and a named destination is
keyed by name alone: without the namespace every `fn:1` link in the book resolves to the last
article that had one. A single article is not namespaced.

*The language.* `Page::collection()` injects `filter: translated: true`, and the plugin
additionally requires the active language to be among the page's `translatedLanguages(true)`.
A PDF therefore never mixes in a language-fallback page.

*The fonts.* The DejaVu faces in `fonts/`, plus anything a site adds with `font_dir`.
Nothing is downloaded at render time.

---

## 7. Troubleshooting

**A `.pdf` URL 404s.** The page has not opted in. Add `pdf: { enabled: true }` to it or to
one of its parents, or set `all_pages: true` to check. Confirm `plugins.pdf.enabled` is on.

**An edit to a page does not change the PDF.** It should, by itself: the fingerprint covers
each item's modification time. If it does not, `bin/grav clear-cache`.

**A configuration change has no effect.** Grav caches its merged configuration. Clear it
with `bin/grav clear-cache`, or narrowly:

```bash
rm -rf cache/compiled/config
```

**The header is missing after the table of contents.** Do not switch to `SetHTMLHeader()`.
The page boxes are the working mechanism; the imperative API loses the header on the page
following the TOC, and with no TOC on the first page written after the call.

**Images are missing.** They are off by default. Set `pdf: { images: true }`. An image whose
`src` cannot be resolved to a local file is dropped rather than embedded broken. If the
wrong image leads, set `image_field` to the header key your theme uses, or `image_skip_field`
to the one it must ignore.

**An Admin upload fails with `Method 'POST' is not allowed.`** The file field has no
`destination`, so the upload is being posted to the page-media route, which a plugin or
theme screen cannot reach. See [Where the logo comes from](#where-the-logo-comes-from).

**The logo does not appear.** Only `logo` is read; there is no theme fallback. Check that the
path resolves — a `logo` of `{ }` — what Admin saves for an empty file field — counts as
empty. If the theme config is overridden per environment, that file wins.

**A glyph is missing.** The bundled DejaVu faces cover Latin, Greek and Cyrillic. Other
scripts need a font that has them; see
[Scripts the bundled fonts do not cover](#scripts-the-bundled-fonts-do-not-cover).

**`HTTP 413`.** The collection is bigger than `max_items`. Raise it, or set it to `0`.

**`HTTP 503`.** Another request is already building that exact document and there is nothing
cached yet to serve instead. Try again in a moment.

**A build stops part-way with a server error.** `build_timeout` — or PHP's own
`max_execution_time` — cut it short, and the error is whatever Grav reports for a fatal error.
Its temporary file is removed by the next build. Raise the limit, or set `build_timeout` to `0`
to leave PHP's own alone.

**The document is huge.** Turn `images` off, or lower `images_width`. Two hundred pages of
text with no images come to well under a megabyte.

---

## 8. Licence

MIT. See [LICENSE](LICENSE) and [CREDITS.md](CREDITS.md) for the bundled components and
their terms.
