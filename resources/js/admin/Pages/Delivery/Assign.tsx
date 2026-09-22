import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import OrderSummaryCard from '../../Components/OrderSummaryCard';
import ShippingAddressCard from '../../Components/ShippingAddressCard';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import type { GeoName } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface Assignee {
    id: number;
    name: string;
}

interface OrderDetail {
    id: number;
    order_number: number;
    status: string;
    customer: { name: string; email: string | null; phone: string } | null;
    shipping_recipient_name: string;
    shipping_phone: string;
    shipping_address_line: string;
    shipping_governorate: GeoName | null;
    shipping_city: GeoName | null;
    shipping_district: GeoName | null;
    shipping_area: GeoName | null;
    subtotal: string;
    discount_amount: string;
    shipping_amount: string;
    total: string;
}

// One order's hand-off, split out of the old combined board so the queue
// is a listing and this is a form.
export default function DeliveryAssign({
    order,
    representatives,
    shippingCompanies,
}: {
    order: OrderDetail;
    representatives: Assignee[];
    shippingCompanies: Assignee[];
}) {
    const { t } = useTranslation();
    // "representative:3" / "shipping_company:1" — one select over two
    // lists, because the order goes to exactly one of them.
    const [choice, setChoice] = useState('');

    async function assign() {
        if (!choice) return;
        const [type, id] = choice.split(':');
        if (!(await confirmAction({ title: t('admin.assignThisOrder') }))) return;
        router.post(route('admin.delivery.assign', order.id), {
            assignment_type: type,
            assignee_id: Number(id),
        });
    }

    return (
        <AdminLayout
            title={t('admin.orderTitle', { number: order.order_number })}
            breadcrumbs={[{ label: t('admin.deliveryAssignmentBoard'), href: route('admin.delivery.index') }]}
        >
            <Head title={t('admin.orderNumber', { number: order.order_number })} />

            <div className="row">
                <div className="col-xl-8">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center">
                            <h4 className="card-title">{t('admin.assignTo')}</h4>
                            <StatusBadge status={order.status} />
                        </div>
                        <div className="card-body">
                            <div className="mb-3">
                                <label className="form-label">{t('admin.assignTo')}</label>
                                <select
                                    className="form-control"
                                    value={choice}
                                    onChange={(e) => setChoice(e.target.value)}
                                >
                                    <option value="">{t('admin.select')}</option>
                                    <optgroup label={t('admin.representatives')}>
                                        {representatives.map((rep) => (
                                            <option key={`rep-${rep.id}`} value={`representative:${rep.id}`}>
                                                {rep.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                    <optgroup label={t('admin.shippingCompanies')}>
                                        {shippingCompanies.map((company) => (
                                            <option key={`co-${company.id}`} value={`shipping_company:${company.id}`}>
                                                {company.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                </select>
                            </div>
                            <button type="button" className="btn btn-primary" disabled={!choice} onClick={assign}>
                                {t('admin.assign')}
                            </button>
                        </div>
                    </div>

                    <ShippingAddressCard order={order} />
                </div>

                <div className="col-xl-4">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.customerDetails')}</h4>
                        </div>
                        <div className="card-body">
                            <p className="mb-1 fw-medium">{order.customer?.name}</p>
                            <span className="text-muted">{order.customer?.email}</span>

                            <h5 className="mt-3">{t('admin.contactNumber')}</h5>
                            <p className="mb-0">{order.customer?.phone}</p>
                        </div>
                    </div>

                    <OrderSummaryCard order={order} />
                </div>
            </div>
        </AdminLayout>
    );
}
