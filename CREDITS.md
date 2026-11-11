# Credits

## This plugin

Grav PDF plugin — Copyright (C) 2026 Roman Bannack.
Licensed under the GNU General Public License, version 2 only. See [LICENSE](LICENSE).

## Bundled dependencies

`vendor/` is a production-only Composer install. A release archive contains these:

| Package | Version | Licence | Copyright |
|---|---|---|---|
| [mpdf/mpdf](https://github.com/mpdf/mpdf) | 8.3.1 | GPL-2.0-only | Ian Back and contributors |
| [mpdf/psr-http-message-shim](https://github.com/mpdf/psr-http-message-shim) | 2.0.1 | MIT | mPDF contributors |
| [mpdf/psr-log-aware-trait](https://github.com/mpdf/psr-log-aware-trait) | 3.0.0 | MIT | mPDF contributors |
| [myclabs/deep-copy](https://github.com/myclabs/DeepCopy) | 1.14.0 | MIT | MyCLabs |
| [paragonie/random_compat](https://github.com/paragonie/random_compat) | 9.99.100 | MIT | Paragon Initiative Enterprises |
| [psr/http-message](https://github.com/php-fig/http-message) | 2.0 | MIT | PHP-FIG |
| [psr/log](https://github.com/php-fig/log) | 3.0.2 | MIT | PHP-FIG |
| [setasign/fpdi](https://github.com/Setasign/FPDI) | 2.6.9 | MIT | Setasign GmbH & Co. KG |

mPDF is GPL-2.0-only, which is why this plugin is licensed under the same terms rather than
under a permissive licence: distributing the two together cannot be done otherwise. The MIT
dependencies are compatible with it.

Each package keeps its own licence and credits inside its folder under `vendor/`; the
authoritative texts are those files. mPDF's `vendor/mpdf/mpdf/CREDITS.txt` lists the
components mPDF itself is built from (FPDF, HTML2FPDF and others) and the people behind
them.

## Fonts

`vendor/mpdf/mpdf/ttfonts/` carries a trimmed subset of mPDF's fonts — the DejaVu family and
the Free family.

* **DejaVu** is Copyright © 2003 Bitstream, Inc. (Bitstream Vera Fonts Copyright), with the
  DejaVu changes in the public domain and glyphs imported from Arev, Copyright © Tavmjong
  Bah. The full licence text is `vendor/mpdf/mpdf/ttfonts/DejaVuinfo.txt`.
* **Free** fonts are Copyright © GNU FreeFont contributors, under the GNU GPL with a font
  exception.

## Grav

This plugin is written for [Grav](https://getgrav.org), Copyright © RocketTheme, LLC,
under the MIT licence. Grav itself is not bundled.
