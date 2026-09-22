import { Head, Link } from '@inertiajs/react';
import InvoiceSheet, { type InvoiceOrder } from '../../Components/InvoiceSheet';
import { useTranslation } from '../../lib/useTranslation';

/**
 * /admin/orders/invoices?ids[]= — the selected orders as one print job
 * (H2). Same sheet as the single invoice, one per page.
 *
 * Nothing here opens the print dialog on load: a page that prints itself
 * is impossible to check before it reaches paper, and the selection came
 * from checkboxes that are easy to get wrong by one row.
 */
export default function OrderInvoices({ orders, logo }: { orders: InvoiceOrder[]; logo: string }) {
    const { t, direction } = useTranslation();

    return (
        <div dir={direction} className="invoice-page bg-light min-vh-100 py-4">
            <Head title={t('admin.printSelected')} />
            <style>{`
                /* Same zero-margin A4 landscape as the single invoice, so
                   Chrome drops its own header/footer — plus a forced break
                   between sheets. The last one takes no break, or the job
                   ends on a blank page. */
                @page { size: A4 landscape; margin: 0; }
                @media print {
                    .no-print { display: none !important; }
                    .invoice-page { background: #fff !important; padding: 0 !important; }
                    .invoice-sheet { box-shadow: none !important; border: none !important; margin: 0 !important; max-width: 100% !important; }
                    .invoice-sheet .card-body { padding: 12mm !important; }
                    /* The break goes on the wrapper, not the sheet: each
                       sheet is the only child of its own wrapper, so
                       :last-child on the sheet would match every one. */
                    .invoice-page-break { margin: 0 !important; }
                    .invoice-page-break:not(:last-child) { break-after: page; page-break-after: always; }
                }
            `}</style>

            <div className="container">
                <div className="d-flex justify-content-between align-items-center gap-2 mb-3 no-print">
                    <span className="text-muted">{t('admin.invoicesToPrint', { count: orders.length })}</span>
                    <div className="d-flex gap-2">
                        <Link href={route('admin.orders.index')} className="btn btn-soft-secondary">
                            {t('admin.backToOrders')}
                        </Link>
                        <button type="button" className="btn btn-primary" onClick={() => window.print()}>
                            {t('admin.print')}
                        </button>
                    </div>
                </div>

                {orders.map((order) => (
                    <div key={order.id} className="invoice-page-break mb-4">
                        <InvoiceSheet order={order} logo={logo} />
                    </div>
                ))}
            </div>
        </div>
    );
}
