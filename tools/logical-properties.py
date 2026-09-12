#!/usr/bin/env python3
"""
Rewrite physical-direction Tailwind utilities to logical ones in the
storefront JSX, so the markup mirrors itself from the document's `dir`.

Why
---
Section 16 requires a real RTL pass. `tools/build-rtl-css.py` handles
Anvogue's compiled theme, but the ported markup also carries hundreds of
Tailwind utilities (`pl-4`, `left-3`, `text-left`, `ml-auto`, …) that are
physical, not logical, and would not flip. Tailwind v4 ships logical
equivalents that key off `dir` natively — `ps-4`, `start-3`, `text-start`,
`ms-auto` — so converting once is better than shipping an `rtl:` override
for every one of them.

Usage:
    python3 tools/logical-properties.py --check    # report, change nothing
    python3 tools/logical-properties.py --write

Deliberately left alone
-----------------------
  * `left-1/2` / `right-1/2` — the centering idiom, paired with
    `-translate-x-1/2`. Both would have to flip together; neither should.
  * `-translate-x-*`, `translate-x-*` — direction-agnostic here.
  * `border-line` and friends — matched by a token boundary so a colour
    utility is never mistaken for `border-l`.
  * `left-content` / `right-content` — structural class names from the
    template's own markup, not utilities. The value guard below rejects
    any suffix that is not a real Tailwind value, which also keeps the
    codemod out of prose in comments.
"""

from __future__ import annotations

import pathlib
import re
import sys

# Ordered longest-first so `rounded-l-` wins over a bare `l` prefix.
MAPPINGS: list[tuple[str, str]] = [
    ("rounded-tl", "rounded-ss"),
    ("rounded-tr", "rounded-se"),
    ("rounded-bl", "rounded-es"),
    ("rounded-br", "rounded-ee"),
    ("rounded-l", "rounded-s"),
    ("rounded-r", "rounded-e"),
    ("border-l", "border-s"),
    ("border-r", "border-e"),
    ("scroll-ml", "scroll-ms"),
    ("scroll-mr", "scroll-me"),
    ("scroll-pl", "scroll-ps"),
    ("scroll-pr", "scroll-pe"),
    ("pl", "ps"),
    ("pr", "pe"),
    ("ml", "ms"),
    ("mr", "me"),
    ("left", "start"),
    ("right", "end"),
]

STANDALONE = {
    "text-left": "text-start",
    "text-right": "text-end",
}

# A class token sits between quotes, whitespace, braces or backticks; a
# variant prefix (`md:`, `hover:`, `max-lg:`) ends in a colon.
BEFORE = r"(?<=[\s\"'`{:])"
AFTER = r"(?=[\s\"'`}])"

# Centering idiom — must not be converted.
KEEP = re.compile(r"^-?(left|right)-1/2$")

# Only convert when the suffix is a real Tailwind value. Without this the
# matcher eats structural class names carried over from the template
# markup (`left-content`, `right-content`) and even prose inside comments
# ("the template's left-hand panel"), none of which are utilities.
VALUE = r"(?:\d+(?:\.\d+)?(?:/\d+)?|px|auto|full|screen|min|max|fit|\[[^\]]*\])"

TARGETS = [pathlib.Path("resources/js/storefront")]


def build_patterns() -> list[tuple[re.Pattern[str], str, str]]:
    patterns = []
    for physical, logical in MAPPINGS:
        # optional leading `-` for negative utilities, then `-value`
        pattern = re.compile(BEFORE + r"(-?)" + re.escape(physical) + r"(-" + VALUE + r")" + AFTER)
        patterns.append((pattern, physical, logical))
    return patterns


def convert(text: str, counts: dict[str, int]) -> str:
    for physical, logical in STANDALONE.items():
        pattern = re.compile(BEFORE + re.escape(physical) + AFTER)
        text, n = pattern.subn(logical, text)
        if n:
            counts[f"{physical} -> {logical}"] = counts.get(f"{physical} -> {logical}", 0) + n

    for pattern, physical, logical in build_patterns():

        def repl(match: re.Match[str]) -> str:
            whole = match.group(0)
            if KEEP.match(whole):
                return whole
            key = f"{physical} -> {logical}"
            counts[key] = counts.get(key, 0) + 1
            return match.group(1) + logical + match.group(2)

        text = pattern.sub(repl, text)

    return text


def main() -> int:
    write = "--write" in sys.argv
    if not write and "--check" not in sys.argv:
        print(__doc__)
        return 2

    counts: dict[str, int] = {}
    touched = 0

    for target in TARGETS:
        for path in sorted(target.rglob("*.tsx")):
            original = path.read_text()
            updated = convert(original, counts)
            if updated != original:
                touched += 1
                if write:
                    path.write_text(updated)

    for key in sorted(counts, key=lambda k: -counts[k]):
        print(f"  {counts[key]:>4}  {key}")
    print(f"{'rewrote' if write else 'would rewrite'} {touched} files, {sum(counts.values())} utilities")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
