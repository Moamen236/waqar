import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ExportButton from '../../Components/ExportButton';
import { PaginationFooter } from '../../Components/Pagination';
import SearchFilter from '../../Components/SearchFilter';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import { useTranslation } from '../../lib/useTranslation';
import type { PaginatedData } from '../../types';

interface StockRow {
    id: number;
    warehouse: string | null;
    sku: string | null;
    product: string | null;
    variant_id: number;
    warehouse_id: number;
    quantity: number;
    reserved_quantity: number;
    available: number;
}

interface MovementRow {
    id: number;
    sku: string | null;
    warehouse: string | null;
    type: string;
    quantity: number;
    notes: string | null;
    by: string | null;
    at: string | null;
}

export default function InventoryIndex({
    stock,
    warehouses,
    movementTypes,
    filters,
    recentMovements,
}: {
    stock: PaginatedData<StockRow>;
    warehouses: { id: number; name: string }[];
    movementTypes: string[];
    filters: { warehouse: number | null; search: string };
    recentMovements: MovementRow[];
}) {
    const { t } = useTranslation();
    const { can } = usePermissions();
    const [adjusting, setAdjusting] = useState<StockRow | null>(null);

    const form = useForm({
        product_variant_id: 0,
        warehouse_id: 0,
        quantity: '',
        type: movementTypes[0] ?? 'adjustment',
        reason: '',
    });

    const open = (row: StockRow) => {
        form.setData({
            product_variant_id: row.variant_id,
            warehouse_id: row.warehouse_id,
            quantity: '',
            type: movementTypes[0] ?? 'adjustment',
            reason: '',
        });
        setAdjusting(row);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(route('admin.inventory.adjust'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAdjusting(null);
            },
        });
    };

    const applyFilters = (next: { warehouse?: string; search?: string }) => {
        router.get(
            route('admin.inventory.index'),
            {
                warehouse: next.warehouse ?? filters.warehouse ?? '',
                search: next.search ?? filters.search ?? '',
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout title={t('admin.inventory')}>
            <Head title={t('admin.inventory')} />

            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h4 className="card-title flex-grow-1">{t('admin.stockOnHand')}</h4>
                            <SearchFilter
                                value={filters.search ?? ''}
                                placeholder={t('admin.searchBySku')}
                                onSubmit={(term) => applyFilters({ search: term })}
                            >
                                <select
                                    className="form-select form-select-sm w-auto"
                                    aria-label={t('admin.allWarehouses')}
                                    value={filters.warehouse ?? ''}
                                    onChange={(event) => applyFilters({ warehouse: event.target.value })}
                                >
                                    <option value="">{t('admin.allWarehouses')}</option>
                                    {warehouses.map((warehouse) => (
                                        <option key={warehouse.id} value={warehouse.id}>
                                            {warehouse.name}
                                        </option>
                                    ))}
                                </select>
                            </SearchFilter>
                            {can('inventory.export') && (
                                <ExportButton
                                    href={route('admin.inventory.export', {
                                        warehouse: filters.warehouse ?? '',
                                        search: filters.search ?? '',
                                    })}
                                />
                            )}
                        </div>

                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.sku')}</th>
                                        <th>{t('admin.product')}</th>
                                        <th>{t('admin.warehouse')}</th>
                                        <th>{t('admin.onHand')}</th>
                                        <th>{t('admin.reserved')}</th>
                                        <th>{t('admin.available')}</th>
                                        {can('inventory.adjust') && <th>{t('admin.action')}</th>}
                                    </tr>
                                </thead>
                                <tbody>
                                    {stock.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="fw-medium">{row.sku ?? '—'}</td>
                                            <td>{row.product ?? '—'}</td>
                                            <td>{row.warehouse ?? '—'}</td>
                                            <td>{row.quantity}</td>
                                            <td>{row.reserved_quantity}</td>
                                            <td className={row.available <= 0 ? 'text-danger fw-semibold' : ''}>
                                                {row.available}
                                            </td>
                                            {can('inventory.adjust') && (
                                                <td>
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-soft-primary"
                                                        onClick={() => open(row)}
                                                    >
                                                        {t('admin.adjust')}
                                                    </button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                    {stock.data.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="text-center text-muted py-4">
                                                {t('admin.noStockRows')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={stock} />
                    </div>
                </div>
            </div>

            {adjusting && (
                <div className="row">
                    <div className="col-xl-12">
                        <div className="card border-primary">
                            <div className="card-header d-flex justify-content-between align-items-center">
                                <h4 className="card-title">
                                    {t('admin.adjustStock')} — {adjusting.sku}
                                </h4>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-soft-secondary"
                                    onClick={() => setAdjusting(null)}
                                >
                                    {t('admin.cancel')}
                                </button>
                            </div>
                            <form onSubmit={submit} className="card-body">
                                <div className="row g-3">
                                    <div className="col-md-3">
                                        <label className="form-label">{t('admin.movementType')}</label>
                                        <select
                                            className="form-select"
                                            value={form.data.type}
                                            onChange={(event) => form.setData('type', event.target.value)}
                                        >
                                            {movementTypes.map((type) => (
                                                <option key={type} value={type}>
                                                    {t(`inventory.type.${type}`)}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-3">
                                        <label className="form-label">{t('admin.quantityChange')}</label>
                                        <input
                                            type="number"
                                            className={`form-control ${form.errors.quantity ? 'is-invalid' : ''}`}
                                            value={form.data.quantity}
                                            onChange={(event) => form.setData('quantity', event.target.value)}
                                            placeholder="-3"
                                        />
                                        <div className="form-text">{t('admin.quantityChangeHint')}</div>
                                        {form.errors.quantity && (
                                            <div className="invalid-feedback">{form.errors.quantity}</div>
                                        )}
                                    </div>
                                    <div className="col-md-6">
                                        <label className="form-label">{t('admin.reason')}</label>
                                        <input
                                            type="text"
                                            className={`form-control ${form.errors.reason ? 'is-invalid' : ''}`}
                                            value={form.data.reason}
                                            onChange={(event) => form.setData('reason', event.target.value)}
                                        />
                                        {form.errors.reason && (
                                            <div className="invalid-feedback">{form.errors.reason}</div>
                                        )}
                                    </div>
                                </div>
                                <div className="mt-3">
                                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                                        {t('admin.saveAdjustment')}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}

            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.recentAdjustments')}</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.date')}</th>
                                        <th>{t('admin.sku')}</th>
                                        <th>{t('admin.warehouse')}</th>
                                        <th>{t('admin.movementType')}</th>
                                        <th>{t('admin.quantityChange')}</th>
                                        <th>{t('admin.reason')}</th>
                                        <th>{t('admin.by')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recentMovements.map((movement) => (
                                        <tr key={movement.id}>
                                            <td className="text-muted">{movement.at ?? '—'}</td>
                                            <td className="fw-medium">{movement.sku ?? '—'}</td>
                                            <td>{movement.warehouse ?? '—'}</td>
                                            <td>{t(`inventory.type.${movement.type}`)}</td>
                                            <td className={movement.quantity < 0 ? 'text-danger' : 'text-success'}>
                                                {movement.quantity > 0 ? `+${movement.quantity}` : movement.quantity}
                                            </td>
                                            <td>{movement.notes ?? '—'}</td>
                                            <td>{movement.by ?? t('admin.system')}</td>
                                        </tr>
                                    ))}
                                    {recentMovements.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="text-center text-muted py-4">
                                                {t('admin.noAdjustmentsYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
