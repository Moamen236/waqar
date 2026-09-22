import { Head, Link, router } from '@inertiajs/react';
import { PaginationFooter } from '../../Components/Pagination';
import SearchFilter from '../../Components/SearchFilter';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';
import type { PaginatedData } from '../../types';

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

export default function InventoryMovements({
    movements,
    warehouses,
    movementTypes,
    filters,
}: {
    movements: PaginatedData<MovementRow>;
    warehouses: { id: number; name: string }[];
    movementTypes: string[];
    filters: { warehouse: number | null; type: string; search: string };
}) {
    const { t } = useTranslation();

    const apply = (next: { warehouse?: string; type?: string; search?: string }) => {
        router.get(
            route('admin.inventory.movements'),
            {
                warehouse: next.warehouse ?? filters.warehouse ?? '',
                type: next.type ?? filters.type ?? '',
                search: next.search ?? filters.search ?? '',
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout title={t('admin.adjustmentHistory')}>
            <Head title={t('admin.adjustmentHistory')} />

            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h4 className="card-title flex-grow-1">{t('admin.adjustmentHistory')}</h4>
                            <Link href={route('admin.inventory.index')} className="btn btn-sm btn-soft-secondary">
                                {t('admin.backToInventory')}
                            </Link>
                            <SearchFilter
                                value={filters.search ?? ''}
                                placeholder={t('admin.searchBySku')}
                                onSubmit={(term) => apply({ search: term })}
                            >
                                <select
                                    className="form-select form-select-sm w-auto"
                                    aria-label={t('admin.allWarehouses')}
                                    value={filters.warehouse ?? ''}
                                    onChange={(event) => apply({ warehouse: event.target.value })}
                                >
                                    <option value="">{t('admin.allWarehouses')}</option>
                                    {warehouses.map((warehouse) => (
                                        <option key={warehouse.id} value={warehouse.id}>
                                            {warehouse.name}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className="form-select form-select-sm w-auto"
                                    aria-label={t('admin.movementType')}
                                    value={filters.type ?? ''}
                                    onChange={(event) => apply({ type: event.target.value })}
                                >
                                    <option value="">{t('admin.allTypes')}</option>
                                    {movementTypes.map((type) => (
                                        <option key={type} value={type}>
                                            {t(`inventory.type.${type}`)}
                                        </option>
                                    ))}
                                </select>
                            </SearchFilter>
                        </div>

                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
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
                                    {movements.data.map((movement) => (
                                        <tr key={movement.id}>
                                            <td className="text-muted text-nowrap">{movement.at ?? '—'}</td>
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
                                    {movements.data.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="text-center text-muted py-4">
                                                {t('admin.noAdjustmentsYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={movements} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
