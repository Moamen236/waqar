#!/usr/bin/env python3
"""
Wire the admin's JSX strings to the translation hook.

Question 2 ships the internal-operations UI in Arabic for v1, and the admin
is 41 files deep. Hand-editing every label is both slow and easy to do
incompletely, so this does the mechanical half: it finds translatable JSX
text nodes and attributes, replaces them with `t('admin.<key>')`, inserts
the hook where it is missing, and writes the English catalog keyed by the
original copy. The Arabic catalog is then translated from that — by hand,
because machine-ordering someone's admin vocabulary is not a thing to
automate.

Usage:
    python3 tools/extract-admin-strings.py --check
    python3 tools/extract-admin-strings.py --write

Deliberately skipped:
  * anything already inside t(...)
  * strings that are only punctuation, digits, or a single character
  * `&nbsp;`-style entities and JSX expressions
"""

from __future__ import annotations

import json
import pathlib
import re
import sys

ROOT = pathlib.Path("resources/js/admin")
CATALOG_EN = ROOT / "locales" / "en.json"

# A JSX text node: between > and <, with no braces or tags inside, and on
# a single line — a multi-line match means we have wandered into a comment
# block, where backticked code samples contain stray > and < characters.
TEXT = re.compile(r"(?<=>)([ \t]*)([A-Za-z][^<>{}\n]*?)([ \t]*)(?=<)")

# The same thing, but for copy that sits on its own line between tags —
# which is how most of these components are formatted. Safe to match across
# newlines here only because comments are excluded before this runs.
TEXT_BLOCK = re.compile(r"(?<=>)(\s*\n\s*)([A-Za-z][^<>{}\n]*?)(\s*\n\s*)(?=<)")
ATTR = re.compile(r"\b(placeholder|aria-label|title|alt)=\"([^\"]+)\"")

SKIP_EXACT = {"WAQAR", "WAQAR Admin", "EGP", "SKU", "ID", "%", "—", "·"}


def key_for(text: str) -> str:
    """admin.someReadableKey, derived from the copy itself."""
    words = re.findall(r"[A-Za-z]+", text)
    if not words:
        return "admin.unknown"
    head = words[0].lower()
    rest = "".join(w.capitalize() for w in words[1:6])
    return f"admin.{head}{rest}"


def translatable(text: str) -> bool:
    stripped = text.strip()
    if len(stripped) < 2 or stripped in SKIP_EXACT:
        return False

    # TypeScript generics use the same angle brackets JSX does, so
    # `foo(errors as Record<string, string>)` looks exactly like a text node
    # sitting between a `>` and a `<`. Real UI copy is not code: it has no
    # assignments or statement terminators, and any parentheses in it are
    # balanced ("Free shipping over (optional)").
    if any(ch in stripped for ch in "=;"):
        return False
    if stripped.count("(") != stripped.count(")"):
        return False
    if " as " in stripped:
        return False

    # needs at least one run of two letters to be prose rather than a symbol
    return bool(re.search(r"[A-Za-z]{2}", stripped))


def insert_hook(source: str) -> str:
    """Add the import and `const { t } = ...` to a component that lacks them."""
    if "useTranslation" in source:
        return source

    lines = source.split("\n")
    last_import = max((i for i, l in enumerate(lines) if l.startswith("import ")), default=-1)
    depth = len(pathlib.Path("x").parts)  # placeholder; rel computed by caller
    lines.insert(last_import + 1, "__IMPORT__")

    # Insert the hook on the line after the component signature closes.
    #
    # "first line ending in {" is wrong: these components destructure their
    # props, so `export default function Form({` ends in a brace while the
    # parameter list is still open — the hook landed inside it. Track
    # parenthesis depth instead, and only accept a `{` once the argument
    # list has actually closed.
    for i, line in enumerate(lines):
        if not line.startswith("export default function"):
            continue

        depth = 0
        seen_open = False
        for j in range(i, min(i + 60, len(lines))):
            depth += lines[j].count("(") - lines[j].count(")")
            if lines[j].count("("):
                seen_open = True
            if seen_open and depth == 0 and lines[j].rstrip().endswith("{"):
                lines.insert(j + 1, "    const { t } = useTranslation();")
                return "\n".join(lines)
        break

    return "\n".join(lines)


COMMENT = re.compile(r"/\*.*?\*/|//[^\n]*", re.S)


def apply_outside_comments(source: str, transform) -> str:
    """Run `transform` on the code parts only.

    Comments are prose, and this file's own JSDoc contains backticked code
    samples with stray > and < in them — matching inside one produced a
    catalog entry made of documentation. Splitting on comment spans and
    rejoining keeps them untouched and their positions intact.
    """
    out: list[str] = []
    cursor = 0
    for match in COMMENT.finditer(source):
        out.append(transform(source[cursor:match.start()]))
        out.append(match.group(0))
        cursor = match.end()
    out.append(transform(source[cursor:]))
    return "".join(out)


def main() -> int:
    write = "--write" in sys.argv
    if not write and "--check" not in sys.argv:
        print(__doc__)
        return 2

    catalog: dict[str, str] = {}
    touched = 0

    for path in sorted(ROOT.rglob("*.tsx")):
        source = path.read_text()
        original = source
        found: list[str] = []

        def text_repl(match: re.Match[str]) -> str:
            lead, body, trail = match.group(1), match.group(2), match.group(3)
            if not translatable(body):
                return match.group(0)
            key = key_for(body)
            catalog[key] = body.strip()
            found.append(key)
            return f"{lead}{{t('{key}')}}{trail}"

        def attr_repl(match: re.Match[str]) -> str:
            name, body = match.group(1), match.group(2)
            if not translatable(body):
                return match.group(0)
            key = key_for(body)
            catalog[key] = body.strip()
            found.append(key)
            return f"{name}={{t('{key}')}}"

        source = apply_outside_comments(source, lambda chunk: TEXT.sub(text_repl, chunk))
        source = apply_outside_comments(source, lambda chunk: TEXT_BLOCK.sub(text_repl, chunk))
        source = apply_outside_comments(source, lambda chunk: ATTR.sub(attr_repl, chunk))

        if found:
            rel = "../" * (len(path.relative_to(ROOT).parts) - 1)
            source = insert_hook(source).replace(
                "__IMPORT__", f"import {{ useTranslation }} from '{rel}lib/useTranslation';"
            )
            touched += 1
            if write:
                path.write_text(source)
        elif source != original and write:
            path.write_text(source)

    if write:
        # Merge, never replace: a second run sees only the strings not yet
        # wired (everything already converted now reads `{t('…')}`), so
        # rewriting the file from scratch would silently drop every key
        # captured by the previous run.
        existing = json.loads(CATALOG_EN.read_text()) if CATALOG_EN.exists() else {}
        existing.update(catalog)
        CATALOG_EN.write_text(json.dumps(dict(sorted(existing.items())), ensure_ascii=False, indent=4) + "\n")

    print(f"{'wired' if write else 'would wire'} {touched} files, {len(catalog)} distinct strings")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
