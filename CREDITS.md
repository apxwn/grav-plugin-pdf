# Credits

## This plugin

Grav PDF plugin — Copyright (C) 2026 Roman Bannack.
Licensed under the MIT License. See [LICENSE](LICENSE).

## Bundled dependencies

`vendor/` is a production-only Composer install. A release archive contains these:

| Package | Version | Licence | Copyright |
|---|---|---|---|
| [dskripchenko/php-pdf](https://github.com/dskripchenko/php-pdf) | 1.28.0 | MIT | Denis Skripchenko |

`dskripchenko/php-pdf` has no runtime dependencies of its own: it needs only PHP
with `ext-dom`, `ext-mbstring` and `ext-zlib`. Each package keeps its own licence
and credits inside its folder under `vendor/`; the authoritative text is that
file.

## Fonts

`fonts/` carries the DejaVu family, which the plugin embeds into the documents it
builds:

* **DejaVu** is Copyright © 2003 Bitstream, Inc. (Bitstream Vera Fonts Copyright),
  with the DejaVu changes in the public domain and glyphs imported from Arev,
  Copyright © Tavmjong Bah. The full licence text is `fonts/DejaVuinfo.txt`.

The faces shipped are DejaVu Sans, DejaVu Sans Condensed, DejaVu Serif, DejaVu
Serif Condensed and DejaVu Sans Mono, each in regular, bold, italic and
bold-italic. Between them they cover Latin, Greek and Cyrillic.

## Grav

This plugin is written for [Grav](https://getgrav.org), Copyright © RocketTheme, LLC,
under the MIT licence. Grav itself is not bundled.
