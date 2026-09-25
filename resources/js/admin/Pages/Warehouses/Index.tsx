import { Head, Link, router } from '@inertiajs/react';
import { confirmAction } from '../../lib/confirm';
import { PaginationFooter } from '../../Components/Pagination';
import RowActions from '../../Components/RowActions';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface Warehouse {
    id: number;
    name: string;
    address: string;
    phone: string;
    is_active: boolean;
    stock_quantity: string | number | null;
    manager: { id: number; full_name: string } | null;
}

export default function WarehousesIndex({ warehouses }: { warehouses: PaginatedData<Warehouse> }) {
    const { t } = useTranslation();
    const { can } = usePermissions();

    async function remove(id: number, name: string) {
        if (
            !(await confirmAction({
                title: t('admin.deleteConfirmQ', { name }),
                confirmText: t('admin.delete'),
                danger: true,
            }))
        ) {
            return;
        }

        router.delete(route('admin.warehouses.destroy', id), { preserveScroll: true });
    }

    return (
        <AdminLayout title={t('admin.warehouses')}>
            <Head title={t('admin.warehouses')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allWarehouses')}</h4>
                            {can('warehouses.create') && (
                                <Link href={route('admin.warehouses.create')} className="btn btn-sm btn-primary">
                                    {t('admin.addWarehouse')}
                                </Link>
                            )}
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.name')}</th>
                                        <th>{t('admin.address')}</th>
                                        <th>{t('admin.phone')}</th>
                                        <th>{t('admin.warehouseManager')}</th>
                                        <th>{t('admin.stockUnits')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {warehouses.data.map((warehouse) => (
                                        <tr key={warehouse.id}>
                                            <td className="fw-medium">{warehouse.name}</td>
                                            <td>{warehouse.address}</td>
                                            <td>{warehouse.phone}</td>
                                            <td>{warehouse.manager?.full_name ?? '—'}</td>
                                            <td>{Number(warehouse.stock_quantity ?? 0)}</td>
                                            <td>
                                                <StatusBadge status={warehouse.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td>
                                                <RowActions
                                                    editHref={
                                                        can('warehouses.update')
                                                            ? route('admin.warehouses.edit', warehouse.id)
                                                            : undefined
                                                    }
                                                    onDelete={
                                                        can('warehouses.delete')
                                                            ? () => remove(warehouse.id, warehouse.name)
                                                            : undefined
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {warehouses.data.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="text-center text-muted py-4">
                                                {t('admin.noWarehousesYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={warehouses} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
