/**
 * Single currency, EGP (Section 23 — no multi-currency, and the
 * template's USD/EUR/GBP switcher is removed entirely for v1, Q11).
 * The template renders prices as `$45.00`; this is the same shape with
 * the real currency.
 */
export function price(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return `EGP ${value.toLocaleString('en-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
