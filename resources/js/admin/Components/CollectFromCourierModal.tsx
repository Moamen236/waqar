import { router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from 'react-bootstrap/Modal';
import { useTranslation } from '../lib/useTranslation';

export interface OwedOrder {
    id: number;
    order_number: number;
    /** Net of the courier's shipping fee, less anything already banked. */
    still_owed: number | string;
}

const cents = (value: number) => Math.round(value * 100) / 100;

/**
 * The same split CollectFromCourierAction makes on the server — oldest
 * order first, each paid in full before the next gets anything — so the
 * preview is exactly what will be banked. The server recomputes it
 * rather than trusting this.
 */
export function splitOldestFirst(orders: OwedOrder[], amount: number) {
    let remaining = cents(amount);

    return [...orders]
        .sort((a, b) => a.id - b.id)
        .map((order) => {
            const owed = cents(Number(order.still_owed));
            const applied = cents(Math.min(remaining, owed));
            remaining = cents(remaining - applied);

            return { ...order, owed, applied, balance: cents(owed - applied) };
        });
}

export default function CollectFromCourierModal({
    show,
    onHide,
    onDone,
    courierName,
    orders,
    treasuries,
}: {
    show: boolean;
    onHide: () => void;
    onDone: () => void;
    courierName: string;
    orders: OwedOrder[];
    treasuries: { id: number; name: string }[];
}) {
    const { t, price } = useTranslation();
    const [amount, setAmount] = useState('');
    const [treasuryId, setTreasuryId] = useState<number>(treasuries[0]?.id ?? 0);
    const [collectedMethod, setCollectedMethod] = useState('cash');
    const [processing, setProcessing] = useState(false);

    const collected = Number(amount) || 0;
    const lines = splitOldestFirst(orders, collected);
    const totalOwed = cents(lines.reduce((sum, line) => sum + line.owed, 0));
    const balance = cents(totalOwed - collected);
    const tooMuch = collected > totalOwed;
    const canSubmit = collected > 0 && !tooMuch && treasuryId > 0 && !processing;

    function close() {
        setAmount('');
        onHide();
    }

    function submit() {
        if (!canSubmit) return;
        setProcessing(true);
        router.post(
            route('admin.accounting.collect-from-courier'),
            {
                order_ids: orders.map((order) => order.id),
                amount: collected,
                treasury_id: treasuryId,
                collected_method: collectedMethod,
            },
            {
                // Keeps the page's open tab: the balance queue is where a
                // second collection is most often made from.
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setAmount('');
                    onDone();
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Modal show={show} onHide={close} centered size="lg">
            <Modal.Header closeButton>
                <Modal.Title>
                    {t('admin.collectFromCourier')} — {courierName}
                </Modal.Title>
            </Modal.Header>
            <Modal.Body>
                <p className="text-muted fs-13">{t('admin.collectFromCourierHint')}</p>

                <div className="table-responsive mb-3">
                    <table className="table table-sm align-middle mb-0">
                        <thead className="bg-light-subtle">
                            <tr>
                                <th>{t('admin.order')}</th>
                                <th className="text-end">{t('admin.owedByCourier')}</th>
                                <th className="text-end">{t('admin.applied')}</th>
                                <th className="text-end">{t('admin.stillOwed')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line) => (
                                <tr key={line.id}>
                                    <td className="fw-medium">#{line.order_number}</td>
                                    <td className="text-end" dir="ltr">
                                        {price(line.owed)}
                                    </td>
                                    <td className="text-end text-success" dir="ltr">
                                        {price(line.applied)}
                                    </td>
                                    <td
                                        className={`text-end ${line.balance > 0 ? 'text-danger fw-medium' : 'text-muted'}`}
                                        dir="ltr"
                                    >
                                        {price(line.balance)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="fw-semibold">
                            <tr>
                                <td>{t('admin.total')}</td>
                                <td className="text-end" dir="ltr">
                                    {price(totalOwed)}
                                </td>
                                <td className="text-end text-success" dir="ltr">
                                    {price(Math.min(collected, totalOwed))}
                                </td>
                                <td className={`text-end ${balance > 0 ? 'text-danger' : ''}`} dir="ltr">
                                    {price(Math.max(0, balance))}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div className="row g-2">
                    <div className="col-md-4">
                        <label className="form-label">{t('admin.amountCollected')}</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max={totalOwed}
                            className={`form-control ${tooMuch ? 'is-invalid' : ''}`}
                            value={amount}
                            onChange={(event) => setAmount(event.target.value)}
                            autoFocus
                        />
                        {tooMuch && <div className="invalid-feedback">{t('admin.moreThanCourierOwes')}</div>}
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">{t('admin.treasury')}</label>
                        <select
                            className="form-select"
                            value={treasuryId}
                            onChange={(event) => setTreasuryId(Number(event.target.value))}
                        >
                            {treasuries.map((treasury) => (
                                <option key={treasury.id} value={treasury.id}>
                                    {treasury.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">{t('admin.method')}</label>
                        <select
                            className="form-select"
                            value={collectedMethod}
                            onChange={(event) => setCollectedMethod(event.target.value)}
                        >
                            {['cash', 'bank_transfer', 'wallet', 'other'].map((method) => (
                                <option key={method} value={method}>
                                    {t(`admin.method_${method}`)}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </Modal.Body>
            <Modal.Footer>
                <button type="button" className="btn btn-light" onClick={close}>
                    {t('admin.cancel')}
                </button>
                <button type="button" className="btn btn-success" disabled={!canSubmit} onClick={submit}>
                    {t('admin.recordCollection')}
                </button>
            </Modal.Footer>
        </Modal>
    );
}
