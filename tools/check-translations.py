#!/usr/bin/env python3
"""
Guard against translation drift in both Inertia apps.

Reports keys used in code but absent from a catalog (which render as the
raw key — deliberately ugly, so they are obvious in review) and keys present
in a catalog but never used.

    python3 tools/check-translations.py            # both apps
    python3 tools/check-translations.py storefront

Exits non-zero if any key is missing, so this can gate a build. Unused keys
are reported but never fail: several are looked up dynamically —
`t(`status.${order.status}`)`, `t(item.label)` — and a static scan cannot
see those.
"""

from __future__ import annotations

import json
import pathlib
import re
import sys

APPS = ("storefront", "admin")
ROOT = pathlib.Path("resources/js")

# Keys reached through a template literal or a variable rather than a
# literal. Anything under these prefixes is exempt from the unused check.
DYNAMIC_PREFIXES = ("status.", "review.", "faq.", "account.filter", "account.nav", "admin.nav")

USE = re.compile(r"\bt\(\s*'([^']+)'")


def check(app: str) -> int:
    used: set[str] = set()
    for path in (ROOT / app).rglob("*.ts*"):
        used |= set(USE.findall(path.read_text()))

    failures = 0
    for locale in ("en", "ar"):
        path = ROOT / app / "locales" / f"{locale}.json"
        catalog = json.loads(path.read_text()) if path.exists() else {}

        missing = sorted(used - set(catalog))
        unused = sorted(
            key
            for key in set(catalog) - used
            if not key.startswith(DYNAMIC_PREFIXES)
        )

        status = "FAIL" if missing else "ok"
        print(f"[{app}/{locale}] {status} — {len(used)} used, {len(catalog)} in catalog, {len(missing)} missing")
        for key in missing:
            print(f"    missing: {key}")
        for key in unused:
            print(f"    unused : {key}")
        failures += len(missing)

    return failures


def main() -> int:
    apps = sys.argv[1:] or list(APPS)
    return 1 if sum(check(app) for app in apps) else 0


if __name__ == "__main__":
    raise SystemExit(main())
