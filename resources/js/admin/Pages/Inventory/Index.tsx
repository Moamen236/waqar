import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Modal from 'react-bootstrap/Modal';
import ExportButton from '../../Components/ExportButton';
import FormField from '../../Components/Form/FormField';
import { PaginationFooter } from '../../Components/Pagination';
import SearchFilter from '../../Components/SearchFilter';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import { useClearErrorsOnChange } from '../../lib/formErrors';
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

export default function InventoryIndex({
    stock,
    warehouses,
    movementTypes,
    filters,
}: {
    stock: PaginatedData<StockRow>;
    warehouses: { id: number; name: string }[];
    movementTypes: string[];
    filters: { warehouse: number | null; search: string };
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
    useClearErrorsOnChange(form.data, form.errors, form.clearErrors);

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

    const close = () => {
        form.reset();
        form.clearErrors();
        setAdjusting(null);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(route('admin.inventory.adjust'), {
            preserveScroll: true,
            onSuccess: () => {
                close();
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
                            <Link
                                href={route('admin.inventory.movements')}
                                className="btn btn-sm btn-soft-info"
                            >
                                {t('admin.adjustmentHistory')}
                            </Link>
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

            <Modal show={adjusting !== null} onHide={close} centered>
                <Modal.Header closeButton>
                    <Modal.Title>
                        {t('admin.adjustStock')}
                        {adjusting && ` — ${adjusting.sku}`}
                    </Modal.Title>
                </Modal.Header>
                <form onSubmit={submit}>
                    <Modal.Body>
                        {adjusting && (
                            <p className="text-muted">
                                {adjusting.product} · {adjusting.warehouse} · {t('admin.onHand')}:{' '}
                                <span className="text-dark fw-semibold">{adjusting.quantity}</span>
                            </p>
                        )}
                        <FormField name="type" label={t('admin.movementType')} error={form.errors.type} required>
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
                        </FormField>
                        <FormField
                            name="quantity"
                            label={t('admin.quantityChange')}
                            error={form.errors.quantity}
                            hint={t('admin.quantityChangeHint')}
                            required
                        >
                            <input
                                type="number"
                                className="form-control"
                                value={form.data.quantity}
                                onChange={(event) => form.setData('quantity', event.target.value)}
                                placeholder="-3"
                            />
                        </FormField>
                        <FormField
                            name="reason"
                            label={t('admin.reason')}
                            error={form.errors.reason}
                            required
                            className="mb-0"
                        >
                            <input
                                type="text"
                                className="form-control"
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                            />
                        </FormField>
                    </Modal.Body>
                    <Modal.Footer>
                        <button type="button" className="btn btn-soft-secondary" onClick={close}>
                            {t('admin.cancel')}
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={form.processing}>
                            {t('admin.saveAdjustment')}
                        </button>
                    </Modal.Footer>
                </form>
            </Modal>
        </AdminLayout>
    );
}
