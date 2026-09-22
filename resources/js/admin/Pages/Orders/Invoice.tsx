import { Head, Link } from '@inertiajs/react';
import InvoiceSheet, { type InvoiceOrder } from '../../Components/InvoiceSheet';
import { useTranslation } from '../../lib/useTranslation';

/**
 * /admin/orders/{order}/invoice — the printable order invoice. Deliberately
 * outside AdminLayout: sidebars and nav chrome have no place on paper, and
 * the print stylesheet below strips everything but the sheet itself.
 *
 * The sheet itself lives in Components/InvoiceSheet so the batch print
 * (Orders/Invoices) puts the same paper through the printer.
 */
export default function OrderInvoice({ order, logo }: { order: InvoiceOrder; logo: string }) {
    const { t, direction } = useTranslation();

    return (
        <div dir={direction} className="invoice-page bg-light min-vh-100 py-4">
            <Head title={`${t('admin.invoice')} #${order.order_number}`} />
            <style>{`
                /* A4 landscape, zero page margin: with no margin to print
                   into, Chrome drops its own header/footer (URL, date,
                   page numbers), so only the invoice itself lands on
                   paper. The sheet carries its own padding instead. */
                @page { size: A4 landscape; margin: 0; }
                @media print {
                    .no-print { display: none !important; }
                    .invoice-page { background: #fff !important; padding: 0 !important; }
                    .invoice-sheet { box-shadow: none !important; border: none !important; margin: 0 !important; max-width: 100% !important; }
                    .invoice-sheet .card-body { padding: 12mm !important; }
                }
            `}</style>

            <div className="container">
                <div className="d-flex justify-content-end gap-2 mb-3 no-print">
                    <Link href={route('admin.orders.show', order.id)} className="btn btn-soft-secondary">
                        {t('admin.backToOrder')}
                    </Link>
                    <button type="button" className="btn btn-primary" onClick={() => window.print()}>
                        {t('admin.print')}
                    </button>
                </div>

                <InvoiceSheet order={order} logo={logo} />
            </div>
        </div>
    );
}
