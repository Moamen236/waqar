import { useState } from 'react';
import StatusBadge from './StatusBadge';
import { useTranslation } from '../lib/useTranslation';

export interface InvoiceItem {
    id: number;
    quantity: number;
    unit_price: string;
    subtotal: string;
    product_name_snapshot: string;
    variant_sku_snapshot: string;
}

export interface InvoicePayment {
    id: number;
    amount: string;
    status: string;
    method: string | null;
    collected_method: string | null;
    created_at: string;
}

export interface InvoiceOrder {
    id: number;
    order_number: number;
    status: string;
    customer_status: string;
    payment_status: string;
    order_source: string;
    subtotal: string;
    discount_amount: string;
    shipping_amount: string;
    total: string;
    notes: string | null;
    created_at: string;
    shipping_recipient_name: string;
    shipping_phone: string;
    shipping_address_line: string;
    customer: { id: number; name: string; email: string; phone: string } | null;
    coupon: { id: number; code: string } | null;
    items: InvoiceItem[];
    payments: InvoicePayment[];
    delivery_representative: { id: number; name: string; phone: string } | null;
    shipping_company: { id: number; name: string } | null;
    created_by_employee: { id: number; full_name: string } | null;
    shipping_governorate: { id: number; name: string } | null;
    shipping_city: { id: number; name: string } | null;
    shipping_district: { id: number; name: string } | null;
    shipping_area: { id: number; name: string } | null;
}

/**
 * One printable invoice sheet. Lifted out of Pages/Orders/Invoice.tsx so
 * the batch print (Pages/Orders/Invoices.tsx, H2) renders the same paper
 * rather than a second copy of it that drifts the first time a field is
 * added.
 *
 * The WAQAR logo is served from
 * public/admin-theme/assets/images/logo-dark.png; if it ever goes missing
 * the header falls back to a WAQAR وقار wordmark so the page never
 * renders a broken image.
 *
 * The page around it owns the @page rules, the print button and the page
 * breaks — a sheet knows how it looks, not how many of it there are.
 */
export default function InvoiceSheet({ order, logo }: { order: InvoiceOrder; logo: string }) {
    const { t, price, dateTime } = useTranslation();
    const [logoOk, setLogoOk] = useState(true);

    const destination = [
        order.shipping_area?.name,
        order.shipping_district?.name,
        order.shipping_city?.name,
        order.shipping_governorate?.name,
    ]
        .filter(Boolean)
        .join('، ');

    const deliveredBy = order.delivery_representative?.name ?? order.shipping_company?.name ?? null;

    return (
        <div className="invoice-sheet card shadow-sm mx-auto" style={{ maxWidth: 900 }}>
            <div className="card-body p-4 p-md-5">
                <div className="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        {logoOk ? (
                            <img src={logo} alt="WAQAR" style={{ maxHeight: 72 }} onError={() => setLogoOk(false)} />
                        ) : (
                            <div>
                                <div className="fw-bold fs-2">WAQAR</div>
                                <div className="fw-bold fs-4">وقار</div>
                            </div>
                        )}
                    </div>
                    <div className="text-end">
                        <h2 className="mb-1">{t('admin.invoice')}</h2>
                        <div className="fw-medium" dir="ltr">
                            #{order.order_number}
                        </div>
                        <div className="text-muted small">
                            {t('admin.orderDate')}: <span dir="ltr">{dateTime(order.created_at)}</span>
                        </div>
                        <div className="d-flex gap-1 justify-content-end mt-2 flex-wrap">
                            <StatusBadge status={order.status} />
                            <StatusBadge status={order.payment_status} />
                        </div>
                    </div>
                </div>

                <hr />

                <div className="row g-4">
                    <div className="col-md-6">
                        <h5 className="fs-14 text-muted text-uppercase">{t('admin.billedTo')}</h5>
                        <div className="fw-medium">{order.customer?.name ?? '—'}</div>
                        <div className="text-muted small" dir="ltr">
                            {order.customer?.email ?? ''}
                        </div>
                        <div className="text-muted small" dir="ltr">
                            {order.customer?.phone ?? ''}
                        </div>
                    </div>
                    <div className="col-md-6">
                        <h5 className="fs-14 text-muted text-uppercase">{t('admin.shipTo')}</h5>
                        <div className="fw-medium">{order.shipping_recipient_name}</div>
                        <div className="text-muted small" dir="ltr">
                            {order.shipping_phone}
                        </div>
                        <div className="small">
                            {order.shipping_address_line}
                            {destination ? `، ${destination}` : ''}
                        </div>
                    </div>
                </div>

                <div className="table-responsive mt-4">
                    <table className="table align-middle table-bordered mb-0">
                        <thead className="bg-light-subtle">
                            <tr>
                                <th>#</th>
                                <th>{t('admin.product')}</th>
                                <th>SKU</th>
                                <th>{t('admin.qty')}</th>
                                <th>{t('admin.unitPrice')}</th>
                                <th>{t('admin.total')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {order.items.map((item, index) => (
                                <tr key={item.id}>
                                    <td dir="ltr">{index + 1}</td>
                                    <td className="fw-medium">{item.product_name_snapshot}</td>
                                    <td className="text-muted">
                                        <span dir="ltr" className="text-nowrap">
                                            {item.variant_sku_snapshot}
                                        </span>
                                    </td>
                                    <td dir="ltr">{item.quantity}</td>
                                    <td>
                                        <span dir="ltr" className="text-nowrap">
                                            {price(Number(item.unit_price))}
                                        </span>
                                    </td>
                                    <td>
                                        <span dir="ltr" className="text-nowrap">
                                            {price(Number(item.subtotal))}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="row mt-4">
                    <div className="col-md-6">
                        <h5 className="fs-14 text-muted text-uppercase">{t('admin.paymentInformation')}</h5>
                        {order.payments.length === 0 ? (
                            <p className="text-muted small mb-0">{t('admin.noPaymentsRecorded')}</p>
                        ) : (
                            order.payments.map((payment) => (
                                <div key={payment.id} className="d-flex justify-content-between gap-2 mb-2">
                                    <div>
                                        <span className="d-block fw-medium" dir="ltr">
                                            {price(Number(payment.amount))}
                                        </span>
                                        <span className="text-muted small">
                                            {payment.collected_method
                                                ? t(`collectedMethod.${payment.collected_method}`)
                                                : (payment.method ?? '—')}
                                        </span>
                                    </div>
                                    <StatusBadge status={payment.status} />
                                </div>
                            ))
                        )}
                        <ul className="list-unstyled small mt-3 mb-0">
                            <li>
                                <span className="text-muted">{t('admin.orderSource')}</span>
                                <span className="mx-1">:</span>
                                <span>{t(`orderSource.${order.order_source}`)}</span>
                            </li>
                            {deliveredBy && (
                                <li>
                                    <span className="text-muted">{t('admin.assignedTo')}</span>
                                    <span className="mx-1">:</span>
                                    <span>{deliveredBy}</span>
                                </li>
                            )}
                            {order.created_by_employee && (
                                <li>
                                    <span className="text-muted">{t('admin.placedBy')}</span>
                                    <span className="mx-1">:</span>
                                    <span>{order.created_by_employee.full_name}</span>
                                </li>
                            )}
                            {order.coupon && (
                                <li>
                                    <span className="text-muted">{t('admin.couponCodeOptional')}</span>
                                    <span className="mx-1">:</span>
                                    <span dir="ltr">{order.coupon.code}</span>
                                </li>
                            )}
                        </ul>
                        {order.notes && (
                            <div className="mt-3">
                                <h5 className="fs-14 mb-1">{t('admin.notes')}</h5>
                                <p className="text-muted small mb-0">{order.notes}</p>
                            </div>
                        )}
                    </div>
                    <div className="col-md-6">
                        <table className="table table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <td className="text-muted">{t('admin.subTotal')}</td>
                                    <td className="text-end" dir="ltr">
                                        {price(Number(order.subtotal))}
                                    </td>
                                </tr>
                                <tr>
                                    <td className="text-muted">{t('admin.discount')}</td>
                                    <td className="text-end" dir="ltr">
                                        {price(Number(order.discount_amount))}
                                    </td>
                                </tr>
                                <tr>
                                    <td className="text-muted">{t('admin.shippingPrice')}</td>
                                    <td className="text-end" dir="ltr">
                                        {price(Number(order.shipping_amount))}
                                    </td>
                                </tr>
                                <tr className="border-top">
                                    <td className="fw-bold">{t('admin.grandTotal')}</td>
                                    <td className="text-end fw-bold fs-5" dir="ltr">
                                        {price(Number(order.total))}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <hr />
                <p className="text-center text-muted small mb-0">{t('admin.invoiceThankYou')}</p>
            </div>
        </div>
    );
}
