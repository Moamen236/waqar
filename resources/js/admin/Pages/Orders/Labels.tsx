import { Head, Link } from '@inertiajs/react';
import LabelSheet, { type LabelOrder, type Sender } from '../../Components/LabelSheet';
import { useTranslation } from '../../lib/useTranslation';

interface LabelRow {
    order: LabelOrder;
    cod_amount: number | null;
}

/**
 * /admin/orders/labels?ids[]= — the selected orders' courier labels as one
 * print job (H2). Same A6 pouch label as the single one, one per page.
 *
 * Each row carries its own COD amount rather than the page recomputing
 * it: whether an order is already paid is a server fact, and the single
 * label answers it server-side too.
 */
export default function OrderLabels({
    labels,
    logo,
    sender,
}: {
    labels: LabelRow[];
    logo: string;
    sender: Sender | null;
}) {
    const { t, direction } = useTranslation();

    return (
        <div dir={direction} className="label-page bg-light min-vh-100 py-4">
            <Head title={t('admin.printLabels')} />
            <style>{`
                /* Same A6 portrait as the single label, plus a forced
                   break between pouches. The last one takes no break, or
                   the job ends on a blank page. */
                @page { size: A6 portrait; margin: 0; }
                .label-sheet { width: 105mm; min-height: 148mm; }
                @media print {
                    .no-print { display: none !important; }
                    .label-page { background: #fff !important; padding: 0 !important; }
                    .label-sheet {
                        box-shadow: none !important;
                        border: none !important;
                        margin: 0 !important;
                        border-radius: 0 !important;
                    }
                    /* The break goes on the wrapper, not the sheet: each
                       sheet is the only child of its own wrapper, so
                       :last-child on the sheet would match every one. */
                    .label-page-break { margin: 0 !important; }
                    .label-page-break:not(:last-child) { break-after: page; page-break-after: always; }
                }
            `}</style>

            <div className="container">
                <div className="d-flex justify-content-between align-items-center gap-2 mb-3 no-print">
                    <span className="text-muted">{t('admin.labelsToPrint', { count: labels.length })}</span>
                    <div className="d-flex gap-2">
                        <Link href={route('admin.orders.index')} className="btn btn-soft-secondary">
                            {t('admin.backToOrders')}
                        </Link>
                        <button type="button" className="btn btn-primary" onClick={() => window.print()}>
                            {t('admin.print')}
                        </button>
                    </div>
                </div>

                {labels.map((row) => (
                    <div key={row.order.id} className="label-page-break mb-4">
                        <LabelSheet order={row.order} logo={logo} sender={sender} codAmount={row.cod_amount} />
                    </div>
                ))}
            </div>
        </div>
    );
}
