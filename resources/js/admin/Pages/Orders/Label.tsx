import { Head, Link } from '@inertiajs/react';
import LabelSheet, { type LabelOrder, type Sender } from '../../Components/LabelSheet';
import { useTranslation } from '../../lib/useTranslation';

/**
 * /admin/orders/{order}/label — the courier shipping label.
 *
 * Same mechanics as Orders/Invoice.tsx (outside AdminLayout, own @page
 * rule, window.print()), different paper: A6 portrait, which is the
 * standard courier pouch size and tiles four-up on an A4 sheet.
 *
 * The label itself lives in Components/LabelSheet so the batch print
 * (Orders/Labels) puts the same pouch label through the printer.
 */
export default function OrderLabel({
    order,
    logo,
    sender,
    codAmount,
}: {
    order: LabelOrder;
    logo: string;
    sender: Sender | null;
    codAmount: number | null;
}) {
    const { t, direction } = useTranslation();

    return (
        <div dir={direction} className="label-page bg-light min-vh-100 py-4">
            <Head title={`${t('admin.shippingLabel')} #${order.order_number}`} />
            <style>{`
                /* A6 with no page margin, for the same reason the invoice
                   uses none: with nothing to print into, Chrome drops its
                   own header/footer and only the label lands on the
                   pouch. The sheet carries its own padding. */
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

                <LabelSheet order={order} logo={logo} sender={sender} codAmount={codAmount} />
            </div>
        </div>
    );
}
