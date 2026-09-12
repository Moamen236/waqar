import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from '../lib/useTranslation';
import type { SharedProps } from '../types';

const labels: Record<string, string> = { ar: 'العربية', en: 'English' };

/**
 * The real Arabic/English switcher that replaces Anvogue's cosmetic
 * English/Espana/France list (Section 16's resolved conflict; Spanish and
 * French removed per Q11).
 *
 * It navigates to the *sibling URL* under the other locale prefix rather
 * than re-rendering the same URL with different content — that is what
 * keeps every page independently crawlable, shareable and bookmarkable
 * per language (Q20). The sibling URLs are built server-side
 * (HandleInertiaRequests::alternates) so query strings survive the
 * switch: changing language mid-search keeps the search.
 *
 * A plain full-page `<Link>` visit, not a partial reload: the document's
 * `lang`/`dir` attributes live on the root view, so the direction only
 * flips when the document itself is re-rendered.
 */
export default function LocaleSwitcher({ className = '' }: { className?: string }) {
    const { locale } = usePage<SharedProps>().props;
    const { t } = useTranslation();

    return (
        <div className={`choose-type choose-language flex items-center gap-2 ${className}`}>
            <span className="sr-only">{t('nav.language')}</span>
            {locale.supported.map((code, index) => (
                <span key={code} className="flex items-center gap-2">
                    {index > 0 && <span className="w-px h-3 bg-white/40" aria-hidden="true" />}
                    {code === locale.current ? (
                        <span className="caption2 text-white font-semibold" aria-current="true">
                            {labels[code] ?? code}
                        </span>
                    ) : (
                        <Link
                            href={locale.alternates[code]}
                            // Inertia would swap the page component but not
                            // the <html dir>, so the switch must be a real
                            // document load.
                            preserveState={false}
                            preserveScroll={false}
                            className="caption2 text-white/70 hover:text-white duration-300"
                        >
                            {labels[code] ?? code}
                        </Link>
                    )}
                </span>
            ))}
        </div>
    );
}
