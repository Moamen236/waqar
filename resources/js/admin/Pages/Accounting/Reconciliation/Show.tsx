import { Head, router } from '@inertiajs/react';
import 'flatpickr/dist/flatpickr.css';
import { useState } from 'react';
import Flatpickr from 'react-flatpickr';
import AdminLayout from '../../../Layouts/AdminLayout';
import { confirmAction } from '../../../lib/confirm';
import { useTranslation } from '../../../lib/useTranslation';

interface Statement {
    id: number;
    period_start: string;
    period_end: string;
    delivered_orders_count: number;
    expected_customer_collection: string;
    delivery_fees_owed: string;
    return_fees_owed: string;
    net_amount_expected: string;
    transferred_amount: string;
    outstanding_amount: string;
    status: string;
}

interface Draft {
    delivered_orders_count: number;
    expected_customer_collection: number;
    delivery_fees_owed: number;
    return_fees_owed: number;
    net_amount_expected: number;
}

interface Treasury {
    id: number;
    name: string;
    type: string;
}

export default function ReconciliationShow({
    shippingCompany,
    statements,
    draft,
    periodStart,
    periodEnd,
    treasuries,
}: {
    shippingCompany: { id: number; name: string };
    statements: Statement[];
    draft: Draft | null;
    periodStart: string | null;
    periodEnd: string | null;
    treasuries: Treasury[];
}) {
    const { t } = useTranslation();
    const [start, setStart] = useState(periodStart ?? '');
    const [end, setEnd] = useState(periodEnd ?? '');
    const [transferAmount, setTransferAmount] = useState<Record<number, string>>({});
    const [transferTreasury, setTransferTreasury] = useState<number | ''>(treasuries[0]?.id ?? '');

    function preview() {
        if (!start || !end) return;
        router.get(
            route('admin.accounting.reconciliation.show', shippingCompany.id),
            { period_start: start, period_end: end },
            { preserveState: true },
        );
    }

    function createStatement() {
        router.post(route('admin.accounting.reconciliation.store', shippingCompany.id), {
            period_start: start,
            period_end: end,
        });
    }

    async function recordTransfer(statement: Statement) {
        const amount = transferAmount[statement.id];
        if (!amount || !transferTreasury) return;
        if (!(await confirmAction({ title: 'Record this transfer?' }))) return;
        router.post(route('admin.accounting.reconciliation.transfer', statement.id), {
            treasury_id: transferTreasury,
            amount,
        });
    }

    return (
        <AdminLayout title={`Reconciliation — ${shippingCompany.name}`}>
            <Head title={`Reconciliation — ${shippingCompany.name}`} />

            <div className="card">
                <div className="card-header">
                    <h4 className="card-title">{t('admin.newStatement')}</h4>
                </div>
                <div className="card-body">
                    <div className="row g-3 align-items-end">
                        <div className="col-md-3">
                            <label className="form-label fs-13">{t('admin.periodStart')}</label>
                            <Flatpickr
                                className="form-control"
                                value={start}
                                onChange={([date]) => setStart(date ? date.toISOString().slice(0, 10) : '')}
                            />
                        </div>
                        <div className="col-md-3">
                            <label className="form-label fs-13">{t('admin.periodEnd')}</label>
                            <Flatpickr
                                className="form-control"
                                value={end}
                                onChange={([date]) => setEnd(date ? date.toISOString().slice(0, 10) : '')}
                            />
                        </div>
                        <div className="col-md-3">
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={preview}
                                disabled={!start || !end}
                            >
                                {t('admin.preview')}
                            </button>
                        </div>
                    </div>

                    {draft && (
                        <div className="mt-4">
                            <div className="table-responsive mb-3">
                                <table className="table table-sm mb-0">
                                    <tbody>
                                        <tr>
                                            <td>{t('admin.deliveredOrders')}</td>
                                            <td>{draft.delivered_orders_count}</td>
                                        </tr>
                                        <tr>
                                            <td>{t('admin.expectedCustomerCollection')}</td>
                                            <td>{draft.expected_customer_collection}</td>
                                        </tr>
                                        <tr>
                                            <td>{t('admin.deliveryFeesOwed')}</td>
                                            <td>-{draft.delivery_fees_owed}</td>
                                        </tr>
                                        <tr>
                                            <td>{t('admin.returnFeesOwed')}</td>
                                            <td>-{draft.return_fees_owed}</td>
                                        </tr>
                                        <tr className="fw-bold">
                                            <td>{t('admin.netAmountExpected')}</td>
                                            <td>{draft.net_amount_expected}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" className="btn btn-primary" onClick={createStatement}>
                                {t('admin.createStatement')}
                            </button>
                        </div>
                    )}
                </div>
            </div>

            <div className="card">
                <div className="card-header">
                    <h4 className="card-title">{t('admin.statements')}</h4>
                </div>
                <div className="table-responsive">
                    <table className="table align-middle mb-0 table-centered">
                        <thead className="bg-light-subtle">
                            <tr>
                                <th>{t('admin.period')}</th>
                                <th>{t('admin.netExpected')}</th>
                                <th>{t('admin.transferred')}</th>
                                <th>{t('admin.outstanding')}</th>
                                <th>{t('admin.status')}</th>
                                <th>{t('admin.recordTransfer')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {statements.map((statement) => (
                                <tr key={statement.id}>
                                    <td>
                                        {statement.period_start} – {statement.period_end}
                                    </td>
                                    <td>{statement.net_amount_expected}</td>
                                    <td>{statement.transferred_amount}</td>
                                    <td>{statement.outstanding_amount}</td>
                                    <td>
                                        <span
                                            className={`badge px-2 py-1 ${statement.status === 'settled' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'}`}
                                        >
                                            {statement.status}
                                        </span>
                                    </td>
                                    <td>
                                        {statement.status === 'open' && (
                                            <div className="d-flex gap-2">
                                                <select
                                                    className="form-control form-control-sm"
                                                    style={{ width: 140 }}
                                                    value={transferTreasury}
                                                    onChange={(e) => setTransferTreasury(Number(e.target.value))}
                                                >
                                                    {treasuries.map((t) => (
                                                        <option key={t.id} value={t.id}>
                                                            {t.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    className="form-control form-control-sm"
                                                    style={{ width: 100 }}
                                                    placeholder={t('admin.amount')}
                                                    value={transferAmount[statement.id] ?? ''}
                                                    onChange={(e) =>
                                                        setTransferAmount({
                                                            ...transferAmount,
                                                            [statement.id]: e.target.value,
                                                        })
                                                    }
                                                />
                                                <button
                                                    type="button"
                                                    className="btn btn-soft-primary btn-sm"
                                                    onClick={() => recordTransfer(statement)}
                                                >
                                                    {t('admin.record')}
                                                </button>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {statements.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-center text-muted py-4">
                                        {t('admin.noStatementsYet')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AdminLayout>
    );
}
