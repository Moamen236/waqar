import { useTranslation } from '../lib/useTranslation';

interface OrderTotals {
    subtotal: string;
    discount_amount: string;
    shipping_amount: string;
    total: string;
}

/** order-detail.html's Order Summary table — shared by Orders and Checking. */
export default function OrderSummaryCard({ order }: { order: OrderTotals }) {
    const { t, price } = useTranslation();

    return (
        <div className="card">
            <div className="card-header">
                <h4 className="card-title">{t('admin.orderSummary')}</h4>
            </div>
            <div className="card-body">
                <div className="table-responsive">
                    <table className="table mb-0">
                        <tbody>
                            <SummaryRow
                                icon="bx-clipboard"
                                label={t('admin.subTotal')}
                                value={price(Number(order.subtotal))}
                            />
                            <SummaryRow
                                icon="bx-purchase-tag"
                                label={t('admin.discount')}
                                value={`-${price(Number(order.discount_amount))}`}
                            />
                            <SummaryRow
                                icon="bxs-truck"
                                label={t('admin.deliveryCharge')}
                                value={price(Number(order.shipping_amount))}
                            />
                        </tbody>
                        <tfoot className="border-top">
                            <tr>
                                <td className="px-0 fw-semibold text-dark">{t('admin.grandTotal')}</td>
                                <td className="text-end px-0 fw-semibold text-dark">
                                    <span dir="ltr">{price(Number(order.total))}</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    );
}

function SummaryRow({ icon, label, value }: { icon: string; label: string; value: string }) {
    return (
        <tr>
            <td className="px-0">
                <p className="d-flex mb-0 align-items-center gap-1">
                    <i className={`bx ${icon} align-middle`} />
                    {label}
                </p>
            </td>
            <td className="text-end text-dark fw-medium px-0">
                <span dir="ltr">{value}</span>
            </td>
        </tr>
    );
}
