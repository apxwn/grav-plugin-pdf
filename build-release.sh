#!/usr/bin/env bash
#
# Build the release archive.
#
# The archive is what a site installs: the plugin sources plus the bundled
# vendor/ folder, and nothing that belongs to a development checkout or to the
# site this plugin happens to be developed on.
#
#     ./build-release.sh              writes ../grav-plugin-pdf-<version>.zip
#     ./build-release.sh /tmp/out     writes into /tmp/out
#
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
out="${1:-$(dirname "$here")}"

version="$(sed -n "s/.*VERSION = '\([0-9.]*\)'.*/\1/p" "$here/pdf.php" | head -1)"

if [ -z "$version" ]; then
    echo "Could not read the version from pdf.php" >&2
    exit 1
fi

if [ ! -f "$here/vendor/autoload.php" ]; then
    echo "vendor/ is missing. Run:" >&2
    echo "  php bin/composer.phar --working-dir=user/plugins/pdf install --no-dev" >&2
    exit 1
fi

name="grav-plugin-pdf-$version"
zip="$out/$name.zip"
stage="$(mktemp -d)"

cleanup() { rm -rf "$stage"; }
trap cleanup EXIT

# Stage first, so the archive is built from a clean tree rather than from a
# list of exclusions: an empty logo/ is shipped (the Admin file field uploads
# into it), a populated one is not. The patterns are anchored with a leading
# slash so they match the plugin root only — `vendor/` is shipped as Composer
# built it, `.github/` and `.gitignore` files included.
mkdir -p "$stage/pdf"
rsync -a \
    --exclude '/.git/' \
    --exclude '/.github/' \
    --exclude '/node_modules/' \
    --exclude '/cache/' \
    --exclude '/tmp/' \
    --exclude '/build-release.sh' \
    --exclude '/.DS_Store' \
    "$here/" "$stage/pdf/"

# The working directories are recreated empty, so a site has them on install
# without inheriting anything a developer left behind.
mkdir -p "$stage/pdf/logo"

mkdir -p "$out"
rm -f "$zip"
( cd "$stage" && zip -r -q -X "$zip" "pdf/" )

echo "$zip  ($(du -h "$zip" | cut -f1))"
echo
echo "Contents: $(unzip -l "$zip" | tail -1 | awk '{print $2}') files"
echo "Upload with:"
echo "  unzip -o $name.zip -d /path/to/grav/user/plugins/"
echo "  cd /path/to/grav && bin/grav clear-cache"
