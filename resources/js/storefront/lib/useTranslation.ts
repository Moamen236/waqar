import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import ar from '../locales/ar.json';
import en from '../locales/en.json';
import { createTranslator, formatMoney, type Catalog, type Locale, type Translator } from '../../lib/i18n';
import type { SharedProps } from '../types';

const catalogs: Record<Locale, Catalog> = { ar, en };

/**
 * The storefront's translation entry point. Both catalogs ship in the
 * bundle — they are a few KB each, and loading them statically means a
 * language switch never waits on a fetch.
 *
 * Returns the money formatter alongside `t` because almost every screen
 * that needs one needs the other, and currency placement differs by
 * locale (see formatMoney).
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
            t: createTranslator(catalogs[current] ?? catalogs.en, catalogs.en),
            locale: current,
            direction: locale.direction,
            isRtl: locale.direction === 'rtl',
            price: (value: number | null | undefined) => formatMoney(value, current),
        }),
        [current, locale.direction],
    );
}
