import { Head, Link, router } from '@inertiajs/react';
import { confirmAction } from '../../lib/confirm';
import RowActions from '../../Components/RowActions';
import { PaginationFooter } from '../../Components/Pagination';
import SearchFilter from '../../Components/SearchFilter';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { Customer, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

// Ported from Admin Template/customer-list.html's table structure.
export default function CustomersIndex({ customers, q }: { customers: PaginatedData<Customer>; q: string | null }) {
    const { t } = useTranslation();
    const { can } = usePermissions();

    function submitSearch(term: string) {
        router.get(route('admin.customers.index'), { q: term }, { preserveState: true });
    }

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

        router.delete(route('admin.customers.destroy', id), { preserveScroll: true });
    }

    return (
        <AdminLayout title={t('admin.customers')}>
            <Head title={t('admin.customers')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allCustomers')}</h4>
                            <SearchFilter
                                value={q ?? ''}
                                placeholder={t('admin.searchNameEmailPhone')}
                                onSubmit={submitSearch}
                            />
                            {can('customers.create') && (
                                <Link
                                    href={route('admin.customers.create')}
                                    className="btn btn-sm btn-primary d-flex align-items-center"
                                >
                                    <i className="bx bx-plus me-1" />
                                    {t('admin.addCustomer')}
                                </Link>
                            )}
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.name')}</th>
                                        <th>{t('admin.email')}</th>
                                        <th>{t('admin.phone')}</th>
                                        <th>{t('admin.orders')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {customers.data.map((customer) => (
                                        <tr key={customer.id}>
                                            <td className="fw-medium">{customer.name}</td>
                                            <td>{customer.email}</td>
                                            <td>{customer.phone}</td>
                                            <td>{customer.orders_count}</td>
                                            <td>
                                                <StatusBadge status={customer.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td>
                                                <RowActions
                                                    editHref={
                                                        can('customers.update')
                                                            ? route('admin.customers.edit', customer.id)
                                                            : undefined
                                                    }
                                                    onDelete={
                                                        can('customers.delete')
                                                            ? () => remove(customer.id, customer.name)
                                                            : undefined
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {customers.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                {t('admin.noCustomersFound')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={customers} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
