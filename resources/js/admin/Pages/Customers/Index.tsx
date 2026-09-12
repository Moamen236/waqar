import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import RowActions from '../../Components/RowActions';
import Pagination from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { Customer, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

// Ported from Admin Template/customer-list.html's table structure.
export default function CustomersIndex({ customers, q }: { customers: PaginatedData<Customer>; q: string | null }) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(q ?? '');

    function submitSearch(e: FormEvent) {
        e.preventDefault();
        router.get(route('admin.customers.index'), { q: search }, { preserveState: true });
    }

    return (
        <AdminLayout title={t('admin.customers')}>
            <Head title={t('admin.customers')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allCustomers')}</h4>
                            <form onSubmit={submitSearch} className="d-flex gap-2">
                                <input
                                    className="form-control form-control-sm"
                                    placeholder={t('admin.searchNameEmailPhone')}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </form>
                            <Link href={route('admin.customers.create')} className="btn btn-sm btn-primary">
                                {t('admin.addCustomer')}
                            </Link>
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
                                                <RowActions editHref={route('admin.customers.edit', customer.id)} />
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
                        {customers.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={customers} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
