#!/usr/bin/env python3
"""
Generate the storefront's RTL stylesheet from Anvogue's compiled LTR theme.

Why this exists
---------------
Section 16 requires a genuine `[dir=rtl]` pass over every storefront
component — the mega-menu, mini-cart, filters, gallery, modals and forms —
and is explicit that it must not be assumed to "just work" from setting the
direction attribute. Anvogue ships no RTL companion sheet (unlike Larkon,
whose `app-rtl.min.css` the admin side uses), and its compiled theme carries
~175 hard-coded directional declarations. Hand-mirroring those would be both
enormous and quietly wrong the first time the theme is re-vendored.

So the RTL sheet is *generated* from the LTR one and committed. Re-run this
whenever `resources/css/anvogue/theme.css` is replaced:

    python3 tools/build-rtl-css.py

This is deliberately a standalone script rather than an artisan command or an
npm step: it is build-time codegen whose output is committed and reviewed, not
something the app or the Vite pipeline ever runs.

What it does
------------
Every rule is rescoped under `[dir="rtl"]` and its directional declarations
mirrored. `[dir="rtl"] .foo` outranks `.foo` on specificity, so the generated
sheet only has to load in the same cascade layer to win.

Deliberately NOT flipped (these are direction-agnostic and flipping them
breaks the layout):
  * `left: 50%` / `right: 50%` — centering idioms, paired with a translate
    that would have to flip in lockstep.
  * anything inside `@font-face`.
  * `background-position` and shorthand `border-radius`, which the theme only
    uses symmetrically.
"""

from __future__ import annotations

import pathlib
import re
import sys

SOURCE = pathlib.Path("resources/css/anvogue/theme.css")
TARGET = pathlib.Path("resources/css/anvogue/theme-rtl.css")

HEADER = """/* GENERATED FILE — DO NOT EDIT BY HAND.
 *
 * Produced by tools/build-rtl-css.py from theme.css. Re-run that script if
 * the Anvogue theme is ever re-vendored; hand edits here are lost.
 *
 * Section 16's RTL requirement: Anvogue ships no RTL companion sheet, so
 * every directional declaration in its compiled theme is mirrored here and
 * rescoped under [dir="rtl"], which outranks the LTR rule on specificity.
 */
"""

# property-name pairs that swap wholesale
PROP_SWAPS = [
    ("margin-left", "margin-right"),
    ("padding-left", "padding-right"),
    ("border-left", "border-right"),
    ("border-top-left-radius", "border-top-right-radius"),
    ("border-bottom-left-radius", "border-bottom-right-radius"),
    ("left", "right"),
]

# Properties whose *values* are direction words.
VALUE_SWAP_PROPS = ("text-align", "float", "clear")

CENTERING = re.compile(r"^\s*(50%|1/2)\s*$")


def swap_properties(declaration: str) -> str | None:
    """Return the mirrored declaration, or None if it is not directional."""
    if ":" not in declaration:
        return None

    prop, _, value = declaration.partition(":")
    prop = prop.strip()
    value = value.strip()

    for a, b in PROP_SWAPS:
        # border-left-width, border-left-color, ... all follow the same rule.
        if prop == a or prop.startswith(a + "-"):
            # Centering idiom: `left: 50%` pairs with a translate that this
            # script cannot see. Mirroring one without the other is wrong.
            if prop == "left" and CENTERING.match(value):
                return None
            return prop.replace(a, b, 1) + ": " + value
        if prop == b or prop.startswith(b + "-"):
            if prop == "right" and CENTERING.match(value):
                return None
            return prop.replace(b, a, 1) + ": " + value

    if prop in VALUE_SWAP_PROPS and value in ("left", "right"):
        return prop + ": " + ("right" if value == "left" else "left")

    if prop in ("transform", "-webkit-transform") and (
        "translateX(" in value or "translate(" in value
    ):
        mirrored = negate_translate(value)
        return prop + ": " + mirrored if mirrored != value else None

    # 4-value shorthands: top right bottom left -> top left bottom right
    if prop in ("margin", "padding"):
        parts = value.split()
        if len(parts) == 4:
            return f"{prop}: {parts[0]} {parts[3]} {parts[2]} {parts[1]}"

    return None


def negate_translate(value: str) -> str:
    """Flip the sign of the X component of translateX()/translate()."""

    def flip(number: str) -> str:
        number = number.strip()
        if number.startswith("-"):
            return number[1:]
        if number in ("0", "0px", "0%"):
            return number
        return "-" + number

    def repl_x(match: re.Match[str]) -> str:
        return f"translateX({flip(match.group(1))})"

    def repl_xy(match: re.Match[str]) -> str:
        x, y = match.group(1).split(",", 1)
        return f"translate({flip(x)},{y})"

    value = re.sub(r"translateX\(([^)]+)\)", repl_x, value)
    value = re.sub(r"translate\(([^)]+,[^)]+)\)", repl_xy, value)
    return value


def rescope(selector_list: str) -> str | None:
    """Prefix each selector in a comma-separated list with [dir="rtl"]."""
    out = []
    for selector in selector_list.split(","):
        selector = selector.strip()
        if not selector or selector.startswith("@") or selector.startswith("%"):
            return None
        if selector in (":root", "html", "body"):
            out.append(f'[dir="rtl"]{"" if selector == ":root" else ""}{selector if selector != ":root" else ":root"}')
        else:
            out.append(f'[dir="rtl"] {selector}')
    return ", ".join(out)


def main() -> int:
    if not SOURCE.exists():
        print(f"source not found: {SOURCE}", file=sys.stderr)
        return 1

    css = SOURCE.read_text()
    # Strip comments so a `{` inside one cannot desync the brace scanner.
    css = re.sub(r"/\*.*?\*/", "", css, flags=re.S)

    out: list[str] = [HEADER]
    media_stack: list[str] = []
    buffer = ""
    i = 0
    written = 0

    while i < len(css):
        char = css[i]
        if char == "{":
            head = buffer.strip()
            buffer = ""
            if head.startswith("@"):
                # at-rule with a block: keep media/supports context, skip the
                # rest (font-face, keyframes) entirely.
                block, i = read_block(css, i)
                if head.startswith("@media") or head.startswith("@supports"):
                    media_stack.append(head)
                    nested = transform_block(block, media_stack)
                    if nested:
                        out.append(f"{head} {{\n{nested}}}\n")
                        written += 1
                    media_stack.pop()
                continue

            block, i = read_block(css, i)
            rule = build_rule(head, block, indent="")
            if rule:
                out.append(rule)
                written += 1
            continue

        buffer += char
        i += 1

    TARGET.write_text("".join(out))
    print(f"wrote {TARGET} ({written} rules, {TARGET.stat().st_size} bytes)")
    return 0


def read_block(css: str, open_index: int) -> tuple[str, int]:
    """Return the text inside the block starting at `open_index`, and the index after it."""
    depth = 0
    i = open_index
    start = open_index + 1
    while i < len(css):
        if css[i] == "{":
            depth += 1
        elif css[i] == "}":
            depth -= 1
            if depth == 0:
                return css[start:i], i + 1
        i += 1
    return css[start:], len(css)


def build_rule(head: str, block: str, indent: str) -> str | None:
    selector = rescope(head)
    if selector is None:
        return None

    mirrored = []
    for declaration in block.split(";"):
        declaration = declaration.strip()
        if not declaration:
            continue
        swapped = swap_properties(declaration)
        if swapped:
            mirrored.append(swapped)

    if not mirrored:
        return None

    body = "".join(f"{indent}    {d};\n" for d in mirrored)
    return f"{indent}{selector} {{\n{body}{indent}}}\n"


def transform_block(block: str, media_stack: list[str]) -> str:
    """Transform the rules nested inside an @media/@supports block."""
    out: list[str] = []
    buffer = ""
    i = 0
    while i < len(block):
        if block[i] == "{":
            head = buffer.strip()
            buffer = ""
            inner, i = read_block(block, i)
            if head.startswith("@"):
                continue
            rule = build_rule(head, inner, indent="    ")
            if rule:
                out.append(rule)
            continue
        buffer += block[i]
        i += 1
    return "".join(out)


if __name__ == "__main__":
    raise SystemExit(main())
