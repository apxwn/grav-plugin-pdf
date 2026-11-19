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
#     ./build-release.sh --allow-dirty --allow-untagged /tmp/out
#
# Release builds are checked: the version must agree in pdf.php, blueprints.yaml
# and CHANGELOG.md, PHP syntax must pass, the tree must be clean and tagged with
# that version, and the finished archive is inspected. The two --allow-* flags
# are for local experiments only.
#
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

fail() {
    echo "$*" >&2
    exit 1
}

usage() {
    cat <<'EOF'
Usage: ./build-release.sh [--allow-dirty] [--allow-untagged] [output-dir]
EOF
}

allow_dirty=0
allow_untagged=0

while [ "$#" -gt 0 ]; do
    case "$1" in
        --allow-dirty)   allow_dirty=1; shift ;;
        --allow-untagged) allow_untagged=1; shift ;;
        -h|--help)       usage; exit 0 ;;
        --)              shift; break ;;
        -*)              fail "Unknown option: $1" ;;
        *)               break ;;
    esac
done

[ "$#" -le 1 ] || fail "Expected at most one output directory"
out="${1:-$(dirname "$here")}"

# ---------------------------------------------------------------------------
# Preflight: refuse an archive whose version or source state is ambiguous.
# ---------------------------------------------------------------------------

version="$(sed -n "s/.*VERSION = '\([0-9.]*\)'.*/\1/p" "$here/pdf.php" | head -1)"
[ -n "$version" ] || fail "Could not read the version from pdf.php"

manifest_version="$(sed -n 's/^version:[[:space:]]*\([0-9.]*\)[[:space:]]*$/\1/p' "$here/blueprints.yaml" | head -1)"
changelog_version="$(sed -n 's/^## \([0-9][0-9.]*\) .*/\1/p' "$here/CHANGELOG.md" | head -1)"

[ "$manifest_version" = "$version" ] || fail "Version mismatch: pdf.php=$version, blueprints.yaml=${manifest_version:-<missing>}"
[ "$changelog_version" = "$version" ] || fail "Version mismatch: pdf.php=$version, CHANGELOG.md=${changelog_version:-<missing>}"

for tool in git php find xargs rsync zip unzip mktemp du sed awk grep; do
    command -v "$tool" >/dev/null 2>&1 || fail "Required tool is missing: $tool"
done

git -C "$here" rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "Not a Git checkout: $here"

if [ "$allow_dirty" -eq 0 ] && [ -n "$(git -C "$here" status --porcelain=v1)" ]; then
    echo "Working tree is not clean:" >&2
    git -C "$here" status --short >&2
    fail "Commit the release changes, or rebuild with --allow-dirty"
fi

head_tag="$(git -C "$here" describe --tags --exact-match HEAD 2>/dev/null || true)"
if [ -z "$head_tag" ]; then
    [ "$allow_untagged" -eq 1 ] || fail "HEAD is not tagged. Tag this commit as $version, or use --allow-untagged"
elif [ "$head_tag" != "$version" ]; then
    fail "HEAD is tagged '$head_tag' but the release version is '$version'"
fi

find "$here/classes" "$here/cli" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
php -l "$here/pdf.php" >/dev/null

[ -f "$here/vendor/autoload.php" ] || fail "vendor/ is missing. Run:
  php bin/composer.phar --working-dir=user/plugins/pdf install --no-dev --no-interaction --optimize-autoloader"

echo "Preflight OK: version $version (tag $head_tag), clean source, PHP syntax valid"

name="grav-plugin-pdf-$version"
zip="$out/$name.zip"
stage="$(mktemp -d)"

cleanup() { rm -rf "$stage"; }
trap cleanup EXIT

# Stage first, so the archive is built from a clean tree rather than from a
# list of exclusions: an empty logo/ is shipped (the Admin file field uploads
# into it), a populated one is not. Root-anchored patterns drop development
# files: .git/, .github/, AGENTS.md, .gitignore, .gitattributes and this script.
# vendor/ is shipped as Composer built it; its own dev folders are trimmed below.
#
# The bundled package's own development material is dropped: its tests, docs,
# examples and static-analysis configs are several megabytes and nothing in
# `src/` needs them. `resources/` stays — it carries the ICC profile the
# library's PDF/A support reads.
mkdir -p "$stage/pdf"
rsync -a \
    --exclude '/.git/' \
    --exclude '/.github/' \
    --exclude '/AGENTS.md' \
    --exclude '/.gitignore' \
    --exclude '/.gitattributes' \
    --exclude '/node_modules/' \
    --exclude '/cache/' \
    --exclude '/tmp/' \
    --exclude '/tests/' \
    --exclude '/build-release.sh' \
    --exclude '/.DS_Store' \
    --exclude '/vendor/*/*/tests/' \
    --exclude '/vendor/*/*/docs/' \
    --exclude '/vendor/*/*/examples/' \
    --exclude '/vendor/*/*/samples/' \
    --exclude '/vendor/*/*/scripts/' \
    --exclude '/vendor/*/*/.github/' \
    --exclude '/vendor/*/*/phpunit.xml*' \
    --exclude '/vendor/*/*/phpstan*' \
    --exclude '/vendor/*/*/psalm*' \
    --exclude '/vendor/*/*/.gitignore' \
    --exclude '/vendor/*/*/.gitattributes' \
    "$here/" "$stage/pdf/"

# The working directories are recreated empty, so a site has them on install
# without inheriting anything a developer left behind.
mkdir -p "$stage/pdf/logo"

mkdir -p "$out"
rm -f "$zip"
( cd "$stage" && zip -r -q -X "$zip" "pdf/" )

# ---------------------------------------------------------------------------
# Verify the shipped archive, not just the staging tree.
# ---------------------------------------------------------------------------

entries="$(unzip -Z1 "$zip")"
for required in \
    pdf/pdf.php \
    pdf/blueprints.yaml \
    pdf/pdf.yaml \
    pdf/vendor/autoload.php \
    pdf/vendor/dskripchenko/php-pdf/resources/ \
    pdf/fonts/DejaVuSans.ttf \
    pdf/templates/pdf/article.html.twig \
    pdf/logo/.gitkeep
do
    printf '%s\n' "$entries" | grep -F -q "$required" || fail "Archive is missing: $required"
done

while IFS= read -r entry; do
    case "$entry" in
        pdf/tests/*|pdf/.git/*|pdf/.github/*|pdf/AGENTS.md|pdf/.gitignore|pdf/.gitattributes|pdf/build-release.sh)
            fail "Archive contains a development-only file: $entry"
            ;;
    esac
done <<< "$entries"

archived_version="$(unzip -p "$zip" pdf/blueprints.yaml | sed -n 's/^version:[[:space:]]*\([0-9.]*\)[[:space:]]*$/\1/p' | head -1)"
[ "$archived_version" = "$version" ] || fail "Archived version is '${archived_version:-<missing>}', expected '$version'"

echo "$zip  ($(du -h "$zip" | cut -f1))"
echo
echo "Contents: $(printf '%s\n' "$entries" | wc -l | awk '{print $1}') entries"
echo "Upload with:"
echo "  unzip -o $name.zip -d /path/to/grav/user/plugins/"
echo "  cd /path/to/grav && bin/grav clear-cache"
