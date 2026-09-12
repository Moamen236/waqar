#!/bin/sh
# Vendor the Arabic webface into resources/css/fonts/.
#
# Why vendored rather than @import'ed from Google:
#   * the storefront already self-hosts Phosphor and icomoon, so this
#     matches how every other font in the build is handled;
#   * an `@import url(https://fonts.googleapis.com/...)` written in
#     storefront.css is silently dropped by the Tailwind/Vite CSS pipeline
#     (verified — only theme.css's own import survives into the bundle);
#   * a store should not need a third-party request to render its own
#     language.
#
# Only the arabic and latin subsets are kept. Google's css2 response splits
# each family into unicode-range'd subsets, so the browser downloads the
# Arabic file only when Arabic glyphs are actually on the page.
#
# Run inside a container that has network + wget:
#   docker run --rm -v "$PWD":/w -w /w waqar-shot sh tools/fetch-arabic-font.sh
set -eu

FAMILY="Cairo:wght@400;500;600;700"
OUT_CSS="resources/css/fonts/cairo.css"
OUT_DIR="resources/css/fonts/files"
UA="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36"

mkdir -p "$OUT_DIR"
tmp=$(mktemp)

wget -q -U "$UA" -O "$tmp" "https://fonts.googleapis.com/css2?family=${FAMILY}&display=swap"

{
    echo "/* GENERATED — see tools/fetch-arabic-font.sh. Cairo, arabic + latin subsets."
    echo " *"
    echo " * The theme's own family (Instrument Sans) carries no Arabic glyphs, so"
    echo " * Arabic rendered as tofu boxes without this. Cairo sits *after*"
    echo " * Instrument Sans in --font-sans: font fallback is per-glyph, so Latin"
    echo " * keeps the theme face and Arabic picks this one up automatically."
    echo " */"
} > "$OUT_CSS"

# Walk the response, keeping only the subsets we want. Google emits a
# `/* subset */` comment immediately before each @font-face block.
awk -v outdir="$OUT_DIR" '
    /^\/\* / { subset = $2; next }
    /@font-face/ { block = $0 "\n"; inface = 1; next }
    inface { block = block $0 "\n" }
    inface && /}/ {
        inface = 0
        if (subset == "arabic" || subset == "latin") {
            printf "/* %s */\n%s", subset, block
        }
    }
' "$tmp" >> "$OUT_CSS"

# Pull every referenced file down and point the CSS at the local copy.
grep -o 'https://fonts.gstatic.com/[^)]*' "$OUT_CSS" | sort -u | while read -r url; do
    name=$(basename "$url")
    wget -q -O "$OUT_DIR/$name" "$url"
    # `|` as the sed delimiter — the URLs are full of slashes.
    sed -i "s|$url|./files/$name|g" "$OUT_CSS"
done

rm -f "$tmp"
echo "wrote $OUT_CSS"
ls -la "$OUT_DIR"
