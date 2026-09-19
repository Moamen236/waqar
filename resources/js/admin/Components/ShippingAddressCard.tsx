import type { GeoName } from '../types';
import { useTranslation } from '../lib/useTranslation';

interface ShippingAddress {
    shipping_recipient_name: string;
    shipping_phone: string;
    shipping_address_line: string;
    shipping_governorate: GeoName | null;
    shipping_city: GeoName | null;
    shipping_district: GeoName | null;
    shipping_area: GeoName | null;
}

/**
 * Where the order is going, spelled out to all four levels — shared by
 * the delivery and accounting screens, which both route or settle by
 * destination. Reads the snapshot columns on the order, not the
 * customer's address book: an address edited after the order was placed
 * is not where this one is going.
 */
export default function ShippingAddressCard({ order }: { order: ShippingAddress }) {
    const { t } = useTranslation();

    return (
        <div className="card">
            <div className="card-header">
                <h4 className="card-title">{t('admin.shippingAddress')}</h4>
            </div>
            <div className="card-body">
                <p className="mb-1 fw-medium">{order.shipping_recipient_name}</p>
                <p className="mb-1" dir="ltr">
                    {order.shipping_phone}
                </p>
                <p className="mb-2 text-muted">{order.shipping_address_line}</p>
                <ul className="list-unstyled mb-0 fs-13">
                    <GeoRow label={t('admin.governorate')} value={order.shipping_governorate?.name} />
                    <GeoRow label={t('admin.city')} value={order.shipping_city?.name} />
                    <GeoRow label={t('admin.district')} value={order.shipping_district?.name} />
                    <GeoRow label={t('admin.area')} value={order.shipping_area?.name} />
                </ul>
            </div>
        </div>
    );
}

/** One level of the destination — district is the only optional one. */
function GeoRow({ label, value }: { label: string; value?: string }) {
    return (
        <li className="d-flex justify-content-between gap-2">
            <span className="text-muted">{label}</span>
            <span className="text-dark">{value ?? '—'}</span>
        </li>
    );
}
