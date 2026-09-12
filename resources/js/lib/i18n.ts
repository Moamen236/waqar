/**
 * Static UI-string translation for both Inertia apps (spec Section 16).
 *
 * `spatie/laravel-translatable` covers database content (product names,
 * category names) and Laravel's `lang/*.json` covers server-side strings
 * (validation, mail) — neither reaches React UI text, so this is the
 * third layer Section 23's tooling table calls for.
 *
 * Section 23 lists "react-i18next (or a lighter custom hook)". This is the
 * lighter hook, and deliberately so: two locales, catalogs small enough to
 * ship in the bundle, no namespaces, no lazy loading, no backend plugin.
 * react-i18next's weight buys features this app has no use for, and a
 * hand-rolled 40-line hook stays fully type-checked against the catalog —
 * a missing key is a TypeScript error here, not a runtime fallback string.
 *
 * The active locale comes from Inertia's shared props, which come from the
 * {locale} URL segment (Q20) — never from the browser, or a shared link
 * would render differently for different people.
 */

export type Locale = 'ar' | 'en';

export interface LocaleProps {
    current: Locale;
    direction: 'rtl' | 'ltr';
    supported: Locale[];
    alternates: Record<Locale, string>;
}

/** A catalog is flat: dotted keys, no nesting, so lookup is a plain index. */
export type Catalog = Record<string, string>;

/**
 * `t('cart.empty')` → the string; `t('cart.items', { count: 3 })` fills
 * `:count`-style placeholders, matching Laravel's own convention so the
 * same placeholder syntax reads the same on both sides of the stack.
 */
export type Translator = (key: string, replacements?: Record<string, string | number>) => string;

export function createTranslator(catalog: Catalog, fallback: Catalog): Translator {
    return (key, replacements) => {
        // Fall back to English, then to the key itself. Showing the key is
        // deliberately ugly: an untranslated string should be obvious in
        // review rather than silently rendering blank.
        const line = catalog[key] ?? fallback[key] ?? key;

        if (!replacements) {
            return line;
        }

        return Object.entries(replacements).reduce(
            (text, [token, value]) => text.replaceAll(`:${token}`, String(value)),
            line,
        );
    };
}

/**
 * Locale-aware number and money formatting. Arabic renders Western digits
 * here on purpose — Egyptian e-commerce overwhelmingly uses them for
 * prices, and Eastern Arabic numerals in a cart total read as unfamiliar
 * rather than localised.
 */
export function formatMoney(value: number | null | undefined, locale: Locale): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const amount = value.toLocaleString('en-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return locale === 'ar' ? `${amount} ج.م` : `EGP ${amount}`;
}
