import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import ar from '../locales/ar.json';
import en from '../locales/en.json';
import { createTranslator, formatMoney, type Catalog, type Locale, type Translator } from '../../lib/i18n';
import type { SharedProps } from '../types';

const catalogs: Record<Locale, Catalog> = { ar, en };

/**
 * The admin's translation entry point — the same mechanism the storefront
 * uses, deliberately (Section 16: "never a separate architecture").
 *
 * Question 2 scopes v1 to Arabic-only for staff, but that is a *launch*
 * decision, not an architectural one: the English catalog is kept complete
 * alongside the Arabic one, so turning English on for staff later is a
 * routing change rather than a translation project.
 */
export function useTranslation(): {
    t: Translator;
    locale: Locale;
    direction: 'rtl' | 'ltr';
    isRtl: boolean;
    price: (value: number | null | undefined) => string;
} {
    const { locale } = usePage<SharedProps>().props;
    const current = locale.current;

    return useMemo(
        () => ({
            t: createTranslator(catalogs[current] ?? catalogs.ar, catalogs.en),
            locale: current,
            direction: locale.direction,
            isRtl: locale.direction === 'rtl',
            price: (value: number | null | undefined) => formatMoney(value, current),
        }),
        [current, locale.direction],
    );
}
