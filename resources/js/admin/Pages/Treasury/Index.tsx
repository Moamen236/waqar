import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Chart from 'react-apexcharts';
import Pagination from '../../Components/Pagination';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import type { PaginatedData } from '../../types';

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
        if (!(await confirmAction({ title: 'Record this transaction?' }))) return;
        router.post(
            route('admin.treasury.transactions.store'),
            { treasury_id: selected.id, type: txType, amount: txAmount, description: txDescription || undefined },
            { preserveScroll: true, onSuccess: () => setTxAmount('') },
        );
    }

    async function transfer() {
        if (!selected || !transferTo || !transferAmount) return;
        if (!(await confirmAction({ title: 'Record this transfer?' }))) return;
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
            categories: rows.map((r) => new Date(r.created_at).toLocaleDateString()),
            series: rows.map((r) => Number(r.amount)),
        };
    }, [transactions]);

    return (
        <AdminLayout title="Treasury">
            <Head title="Treasury" />

            <div className="row">
                <div className="col-xl-3">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">Accounts</h4>
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
                                            <div className="text-muted fs-13">{treasury.type}</div>
                                        </td>
                                        <td className="text-end">{treasury.current_balance}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">New Treasury Account</h4>
                        </div>
                        <div className="card-body">
                            <input
                                className="form-control mb-2"
                                placeholder="Name"
                                value={newName}
                                onChange={(e) => setNewName(e.target.value)}
                            />
                            <select
                                className="form-control mb-2"
                                value={newType}
                                onChange={(e) => setNewType(e.target.value)}
                            >
                                <option value="cash">Cash</option>
                                <option value="bank">Bank</option>
                                <option value="wallet">Wallet</option>
                            </select>
                            <input
                                type="number"
                                step="0.01"
                                className="form-control mb-2"
                                placeholder="Opening balance"
                                value={newBalance}
                                onChange={(e) => setNewBalance(e.target.value)}
                            />
                            <button
                                type="button"
                                className="btn btn-primary btn-sm"
                                onClick={createTreasury}
                                disabled={!newName}
                            >
                                Create
                            </button>
                        </div>
                    </div>
                </div>

                <div className="col-xl-9">
                    {selected && (
                        <>
                            <div className="card">
                                <div className="card-body d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 className="mb-1">{selected.name}</h5>
                                        <span className="badge bg-secondary-subtle text-secondary px-2 py-1">
                                            {selected.type}
                                        </span>
                                    </div>
                                    <h3 className="mb-0">{selected.current_balance}</h3>
                                </div>
                                {chartData.series.length > 1 && (
                                    <div className="card-body pt-0">
                                        <Chart
                                            type="bar"
                                            height={220}
                                            series={[{ name: 'Amount', data: chartData.series }]}
                                            options={{
                                                chart: { toolbar: { show: false } },
                                                xaxis: { categories: chartData.categories },
                                                dataLabels: { enabled: false },
                                            }}
                                        />
                                    </div>
                                )}
                            </div>

                            <div className="row">
                                <div className="col-lg-6">
                                    <div className="card">
                                        <div className="card-header">
                                            <h4 className="card-title">Record Transaction</h4>
                                        </div>
                                        <div className="card-body">
                                            <select
                                                className="form-control mb-2"
                                                value={txType}
                                                onChange={(e) => setTxType(e.target.value)}
                                            >
                                                <option value="income">Income</option>
                                                <option value="expense">Expense</option>
                                                <option value="adjustment">Adjustment</option>
                                            </select>
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control mb-2"
                                                placeholder="Amount (signed for adjustment)"
                                                value={txAmount}
                                                onChange={(e) => setTxAmount(e.target.value)}
                                            />
                                            <input
                                                className="form-control mb-2"
                                                placeholder="Description"
                                                value={txDescription}
                                                onChange={(e) => setTxDescription(e.target.value)}
                                            />
                                            <button
                                                type="button"
                                                className="btn btn-primary btn-sm"
                                                onClick={recordTransaction}
                                            >
                                                Record
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div className="col-lg-6">
                                    <div className="card">
                                        <div className="card-header">
                                            <h4 className="card-title">Transfer To Another Treasury</h4>
                                        </div>
                                        <div className="card-body">
                                            <select
                                                className="form-control mb-2"
                                                value={transferTo}
                                                onChange={(e) => setTransferTo(Number(e.target.value))}
                                            >
                                                <option value="">Select…</option>
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
                                                placeholder="Amount"
                                                value={transferAmount}
                                                onChange={(e) => setTransferAmount(e.target.value)}
                                            />
                                            <button
                                                type="button"
                                                className="btn btn-primary btn-sm"
                                                onClick={transfer}
                                                disabled={!transferTo}
                                            >
                                                Transfer
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="card">
                                <div className="card-header">
                                    <h4 className="card-title">Ledger</h4>
                                </div>
                                <div className="table-responsive">
                                    <table className="table align-middle mb-0 table-centered">
                                        <thead className="bg-light-subtle">
                                            <tr>
                                                <th>Type</th>
                                                <th>Amount</th>
                                                <th>Description</th>
                                                <th>By</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {transactions?.data.map((tx) => (
                                                <tr key={tx.id}>
                                                    <td>
                                                        <span
                                                            className={`badge px-2 py-1 ${Number(tx.amount) >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'}`}
                                                        >
                                                            {tx.type}
                                                        </span>
                                                    </td>
                                                    <td>{tx.amount}</td>
                                                    <td>{tx.description}</td>
                                                    <td>{tx.created_by?.full_name}</td>
                                                    <td>{new Date(tx.created_at).toLocaleString()}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {transactions && (
                                    <div className="card-footer border-top">
                                        <Pagination data={transactions} />
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                    {!selected && <p className="text-muted">Create a treasury account to get started.</p>}
                </div>
            </div>
        </AdminLayout>
    );
}
