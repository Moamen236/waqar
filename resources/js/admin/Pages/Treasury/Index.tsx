import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Chart from 'react-apexcharts';
import EmptyState, { EmptyRow } from '../../Components/EmptyState';
import { PaginationFooter } from '../../Components/Pagination';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import { usePermissions } from '../../Hooks/usePermissions';
import type { PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface TreasuryRecord {
    id: number;
    name: string;
    type: string;
    current_balance: string;
    is_active: boolean;
}

interface TransactionRecord {
    id: number;
    type: string;
    amount: string;
    description: string | null;
    created_at: string;
    created_by: { full_name: string } | null;
}

export default function TreasuryIndex({
    treasuries,
    selected,
    transactions,
}: {
    treasuries: TreasuryRecord[];
    selected: TreasuryRecord | null;
    transactions: PaginatedData<TransactionRecord> | null;
}) {
    const { t, price, date, dateTime } = useTranslation();
    const { can } = usePermissions();
    const [txType, setTxType] = useState('income');
    const [txAmount, setTxAmount] = useState('');
    const [txDescription, setTxDescription] = useState('');
    const [transferTo, setTransferTo] = useState<number | ''>('');
    const [transferAmount, setTransferAmount] = useState('');
    const [newName, setNewName] = useState('');
    const [newType, setNewType] = useState('cash');
    const [newBalance, setNewBalance] = useState('0');

    function selectTreasury(id: number) {
        router.get(route('admin.treasury.index'), { treasury_id: id }, { preserveState: true });
    }

    async function recordTransaction() {
        if (!selected || !txAmount) return;
        if (!(await confirmAction({ title: t('admin.recordThisTransaction') }))) return;
        router.post(
            route('admin.treasury.transactions.store'),
            { treasury_id: selected.id, type: txType, amount: txAmount, description: txDescription || undefined },
            { preserveScroll: true, onSuccess: () => setTxAmount('') },
        );
    }

    async function transfer() {
        if (!selected || !transferTo || !transferAmount) return;
        if (!(await confirmAction({ title: t('admin.recordThisTransfer') }))) return;
        router.post(
            route('admin.treasury.transfer'),
            { from_treasury_id: selected.id, to_treasury_id: transferTo, amount: transferAmount },
            { preserveScroll: true, onSuccess: () => setTransferAmount('') },
        );
    }

    function createTreasury() {
        if (!newName) return;
        router.post(
            route('admin.treasury.store'),
            { name: newName, type: newType, current_balance: newBalance },
            { onSuccess: () => setNewName('') },
        );
    }

    const chartData = useMemo(() => {
        const rows = transactions?.data.slice(0, 15).reverse() ?? [];
        return {
            categories: rows.map((r) => date(r.created_at)),
            series: rows.map((r) => Number(r.amount)),
        };
    }, [transactions, date]);

    return (
        <AdminLayout title={t('admin.treasury')}>
            <Head title={t('admin.treasury')} />

            <div className="row">
                <div className="col-xl-3">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.accounts')}</h4>
                        </div>
                        <table className="table table-hover mb-0">
                            <tbody>
                                {treasuries.map((treasury) => (
                                    <tr
                                        key={treasury.id}
                                        onClick={() => selectTreasury(treasury.id)}
                                        style={{ cursor: 'pointer' }}
                                        className={selected?.id === treasury.id ? 'table-active' : ''}
                                    >
                                        <td>
                                            {treasury.name}
                                            <div className="text-muted fs-13">
                                                {t(`treasury.type.${treasury.type}`)}
                                            </div>
                                        </td>
                                        <td className="text-end">
                                            <span dir="ltr" className="text-nowrap">
                                                {price(Number(treasury.current_balance))}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                                {treasuries.length === 0 && (
                                    <EmptyRow colSpan={2} message={t('admin.noTreasuryAccounts')} icon="bx-money" />
                                )}
                            </tbody>
                        </table>
                    </div>

                    {can('treasury.create') && (
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.newTreasuryAccount')}</h4>
                            </div>
                            <div className="card-body">
                                <input
                                    className="form-control mb-2"
                                    placeholder={t('admin.name')}
                                    value={newName}
                                    onChange={(e) => setNewName(e.target.value)}
                                />
                                <select
                                    className="form-select mb-2"
                                    value={newType}
                                    onChange={(e) => setNewType(e.target.value)}
                                >
                                    <option value="cash">{t('admin.cash')}</option>
                                    <option value="bank">{t('admin.bank')}</option>
                                    <option value="wallet">{t('admin.wallet')}</option>
                                </select>
                                <input
                                    type="number"
                                    step="0.01"
                                    className="form-control mb-2"
                                    placeholder={t('admin.openingBalance')}
                                    value={newBalance}
                                    onChange={(e) => setNewBalance(e.target.value)}
                                />
                                <button
                                    type="button"
                                    className="btn btn-primary btn-sm"
                                    onClick={createTreasury}
                                    disabled={!newName}
                                >
                                    {t('admin.create')}
                                </button>
                            </div>
                        </div>
                    )}
                </div>

                <div className="col-xl-9">
                    {selected && (
                        <>
                            <div className="card">
                                <div className="card-body d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 className="mb-1">{selected.name}</h5>
                                        <span className="badge bg-secondary-subtle text-secondary px-2 py-1">
                                            {t(`treasury.type.${selected.type}`)}
                                        </span>
                                    </div>
                                    <h3 className="mb-0" dir="ltr">
                                        {price(Number(selected.current_balance))}
                                    </h3>
                                </div>
                                {chartData.series.length > 1 && (
                                    <div className="card-body pt-0">
                                        <Chart
                                            type="bar"
                                            height={220}
                                            series={[{ name: t('admin.amount'), data: chartData.series }]}
                                            options={{
                                                chart: { toolbar: { show: false } },
                                                xaxis: { categories: chartData.categories },
                                                dataLabels: { enabled: false },
                                            }}
                                        />
                                    </div>
                                )}
                            </div>

                            {(can('treasury.transactions.create') || can('treasury.transfer')) && (
                                <div className="row">
                                    {can('treasury.transactions.create') && (
                                        <div className="col-lg-6">
                                            <div className="card">
                                                <div className="card-header">
                                                    <h4 className="card-title">{t('admin.recordTransaction')}</h4>
                                                </div>
                                                <div className="card-body">
                                                    <select
                                                        className="form-select mb-2"
                                                        value={txType}
                                                        onChange={(e) => setTxType(e.target.value)}
                                                    >
                                                        <option value="income">{t('admin.income')}</option>
                                                        <option value="expense">{t('admin.expense')}</option>
                                                        <option value="adjustment">{t('admin.adjustment')}</option>
                                                    </select>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        className="form-control mb-2"
                                                        placeholder={t('admin.amountSignedForAdjustment')}
                                                        value={txAmount}
                                                        onChange={(e) => setTxAmount(e.target.value)}
                                                    />
                                                    <input
                                                        className="form-control mb-2"
                                                        placeholder={t('admin.description')}
                                                        value={txDescription}
                                                        onChange={(e) => setTxDescription(e.target.value)}
                                                    />
                                                    <button
                                                        type="button"
                                                        className="btn btn-primary btn-sm"
                                                        onClick={recordTransaction}
                                                    >
                                                        {t('admin.record')}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                    {can('treasury.transfer') && (
                                        <div className="col-lg-6">
                                            <div className="card">
                                                <div className="card-header">
                                                    <h4 className="card-title">
                                                        {t('admin.transferToAnotherTreasury')}
                                                    </h4>
                                                </div>
                                                <div className="card-body">
                                                    <select
                                                        className="form-select mb-2"
                                                        value={transferTo}
                                                        onChange={(e) => setTransferTo(Number(e.target.value))}
                                                    >
                                                        <option value="">{t('admin.select')}</option>
                                                        {treasuries
                                                            .filter((t) => t.id !== selected.id)
                                                            .map((t) => (
                                                                <option key={t.id} value={t.id}>
                                                                    {t.name}
                                                                </option>
                                                            ))}
                                                    </select>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        className="form-control mb-2"
                                                        placeholder={t('admin.amount')}
                                                        value={transferAmount}
                                                        onChange={(e) => setTransferAmount(e.target.value)}
                                                    />
                                                    <button
                                                        type="button"
                                                        className="btn btn-primary btn-sm"
                                                        onClick={transfer}
                                                        disabled={!transferTo}
                                                    >
                                                        {t('admin.transfer')}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            <div className="card">
                                <div className="card-header">
                                    <h4 className="card-title">{t('admin.ledger')}</h4>
                                </div>
                                <div className="table-responsive">
                                    <table className="table align-middle mb-0 table-centered">
                                        <thead className="bg-light-subtle">
                                            <tr>
                                                <th>{t('admin.type')}</th>
                                                <th>{t('admin.amount')}</th>
                                                <th>{t('admin.description')}</th>
                                                <th>{t('admin.by')}</th>
                                                <th>{t('admin.date')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {transactions?.data.map((tx) => (
                                                <tr key={tx.id}>
                                                    <td>
                                                        <span
                                                            className={`badge px-2 py-1 ${Number(tx.amount) >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'}`}
                                                        >
                                                            {t(`treasury.tx.${tx.type}`)}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span dir="ltr" className="text-nowrap">
                                                            {price(Number(tx.amount))}
                                                        </span>
                                                    </td>
                                                    <td>{tx.description}</td>
                                                    <td>{tx.created_by?.full_name}</td>
                                                    <td>
                                                        <span dir="ltr" className="text-nowrap">
                                                            {dateTime(tx.created_at)}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                            {transactions?.data.length === 0 && (
                                                <EmptyRow
                                                    colSpan={5}
                                                    message={t('admin.noTransactionsYet')}
                                                    icon="bx-receipt"
                                                />
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                                {transactions && <PaginationFooter data={transactions} />}
                            </div>
                        </>
                    )}
                    {!selected && (
                        <div className="card">
                            <EmptyState
                                title={t('admin.noTreasuryAccounts')}
                                description={t('admin.createATreasuryAccountToGet')}
                                icon="bx-money"
                            />
                        </div>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
