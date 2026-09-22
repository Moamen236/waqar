import { useEffect, useRef, useState } from 'react';
import JsBarcode from 'jsbarcode';
import { useTranslation } from '../lib/useTranslation';

export interface LabelItem {
    id: number;
    quantity: number;
    product_name_snapshot: string;
    variant_sku_snapshot: string;
}

export interface LabelOrder {
    id: number;
    order_number: number;
    created_at: string;
    notes: string | null;
    shipping_recipient_name: string;
    shipping_phone: string;
    shipping_address_line: string;
    items: LabelItem[];
    delivery_representative: { id: number; name: string; phone: string } | null;
    shipping_company: { id: number; name: string } | null;
    shipping_governorate: { id: number; name: string } | null;
    shipping_city: { id: number; name: string } | null;
    shipping_district: { id: number; name: string } | null;
    shipping_area: { id: number; name: string } | null;
}

export interface Sender {
    name: string;
    address: string;
    phone: string;
}

/**
 * One courier shipping label. Lifted out of Pages/Orders/Label.tsx so the
 * batch print (Pages/Orders/Labels.tsx, H2) puts the same pouch label
 * through the printer rather than a second copy of it.
 *
 * The COD banner is the point of the whole thing. A courier misreading
 * how much to collect is the expensive mistake here, so the amount gets
 * the largest type on the label — and an already-paid order says so
 * loudly instead of showing a number that might get collected twice.
 *
 * The page around it owns the @page rules, the print button and the page
 * breaks — a label knows how it looks, not how many of it there are.
 */
export default function LabelSheet({
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
    const { t, price, dateTime } = useTranslation();
    const [logoOk, setLogoOk] = useState(true);
    const barcodeRef = useRef<SVGSVGElement | null>(null);

    // CODE128 straight into an inline <svg> — it stays vector, so it
    // prints sharp enough to scan at any size the courier's paper uses.
    useEffect(() => {
        if (barcodeRef.current === null) return;

        JsBarcode(barcodeRef.current, String(order.order_number), {
            format: 'CODE128',
            displayValue: false,
            margin: 0,
            height: 48,
            width: 2,
        });
    }, [order.order_number]);

    const destination = [
        order.shipping_area?.name,
        order.shipping_district?.name,
        order.shipping_city?.name,
        order.shipping_governorate?.name,
    ]
        .filter(Boolean)
        .join('، ');

    const carrier = order.delivery_representative?.name ?? order.shipping_company?.name ?? null;
    const pieces = order.items.reduce((sum, item) => sum + item.quantity, 0);
    const contents = order.items.map((item) => `${item.product_name_snapshot} ×${item.quantity}`).join('، ');

    return (
        <div className="label-sheet card shadow-sm mx-auto bg-white">
            <div className="card-body p-3 d-flex flex-column gap-2">
                {/* Sender */}
                <div className="d-flex justify-content-between align-items-start gap-2 border-bottom pb-2">
                    <div style={{ flexShrink: 0 }}>
                        {logoOk ? (
                            <img src={logo} alt="WAQAR" style={{ maxHeight: 34 }} onError={() => setLogoOk(false)} />
                        ) : (
                            <div className="fw-bold">WAQAR وقار</div>
                        )}
                    </div>
                    <div className="text-end fs-11 lh-sm">
                        {sender === null ? (
                            <div className="text-muted">{t('common.notSpecified')}</div>
                        ) : (
                            <>
                                <div className="fw-semibold">{sender.name}</div>
                                <div className="text-muted">{sender.address}</div>
                                <div className="text-muted" dir="ltr">
                                    {sender.phone}
                                </div>
                            </>
                        )}
                    </div>
                </div>

                {/* The number the courier must not get wrong. */}
                <div
                    className={`text-center rounded py-2 ${
                        codAmount === null ? 'bg-success-subtle' : 'bg-dark text-white'
                    }`}
                >
                    {codAmount === null ? (
                        <div className="fw-bold text-success">{t('admin.labelPaidNoCollection')}</div>
                    ) : (
                        <>
                            <div className="fs-11 text-uppercase opacity-75">{t('admin.labelCollect')}</div>
                            <div className="fw-bold fs-3 lh-1" dir="ltr">
                                {price(codAmount)}
                            </div>
                        </>
                    )}
                </div>

                {/* Recipient and order, side by side */}
                <div className="row g-2">
                    <div className="col-7 border-end">
                        <div className="fs-11 text-uppercase text-muted">{t('admin.labelRecipient')}</div>
                        <div className="fw-semibold">{order.shipping_recipient_name}</div>
                        <div dir="ltr">{order.shipping_phone}</div>
                        <div className="fs-12 lh-sm mt-1">{order.shipping_address_line}</div>
                        <div className="fs-12 text-muted lh-sm">{destination}</div>
                    </div>
                    <div className="col-5 fs-12 lh-sm">
                        <div className="fs-11 text-uppercase text-muted">{t('admin.order')}</div>
                        <div className="fw-semibold" dir="ltr">
                            #{order.order_number}
                        </div>
                        <div className="text-muted">{dateTime(order.created_at)}</div>
                        <div className="mt-1">
                            {t('admin.labelPieces')}: <span className="fw-semibold">{pieces}</span>
                        </div>
                        {carrier !== null && <div className="text-muted mt-1">{carrier}</div>}
                    </div>
                </div>

                <div className="fs-12 lh-sm border-top pt-2">
                    <span className="text-muted">{t('admin.labelContents')}: </span>
                    {contents}
                </div>

                {/* Barcode */}
                <div className="text-center mt-auto">
                    <svg ref={barcodeRef} />
                    <div className="fw-semibold" dir="ltr" style={{ letterSpacing: '0.15em' }}>
                        {order.order_number}
                    </div>
                </div>

                <div className="d-flex justify-content-between align-items-end gap-2 border-top pt-2">
                    <div className="fs-11 lh-sm flex-grow-1">
                        {order.notes !== null && order.notes !== '' ? (
                            <>
                                <span className="text-muted">{t('admin.labelInstructions')}: </span>
                                {order.notes}
                            </>
                        ) : null}
                    </div>
                    <div
                        className="border border-2 border-dark rounded px-2 py-1 text-center fs-11 fw-bold lh-1"
                        style={{ flexShrink: 0 }}
                    >
                        {t('admin.labelFragile')}
                    </div>
                </div>
            </div>
        </div>
    );
}
