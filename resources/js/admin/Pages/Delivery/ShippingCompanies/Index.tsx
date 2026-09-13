import { Head, Link } from '@inertiajs/react';
import { PaginationFooter } from '../../../Components/Pagination';
import RowActions from '../../../Components/RowActions';
import StatusBadge from '../../../Components/StatusBadge';
import AdminLayout from '../../../Layouts/AdminLayout';
import type { PaginatedData } from '../../../types';
import { useTranslation } from '../../../lib/useTranslation';

interface ShippingCompany {
    id: number;
    name: string;
    phone: string;
    delivery_fee: string;
    return_fee: string;
    status: string;
}

export default function ShippingCompaniesIndex({
    shippingCompanies,
}: {
    shippingCompanies: PaginatedData<ShippingCompany>;
}) {
    const { t } = useTranslation();
    return (
        <AdminLayout title={t('admin.shippingCompanies')}>
            <Head title={t('admin.shippingCompanies')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allShippingCompanies')}</h4>
                            <Link
                                href={route('admin.delivery.shipping-companies.create')}
                                className="btn btn-sm btn-primary"
                            >
                                {t('admin.addShippingCompany')}
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.name')}</th>
                                        <th>{t('admin.phone')}</th>
                                        <th>{t('admin.deliveryFee')}</th>
                                        <th>{t('admin.returnFee')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {shippingCompanies.data.map((company) => (
                                        <tr key={company.id}>
                                            <td className="fw-medium">{company.name}</td>
                                            <td>{company.phone}</td>
                                            <td>{company.delivery_fee}</td>
                                            <td>{company.return_fee}</td>
                                            <td>
                                                <StatusBadge status={company.status} />
                                            </td>
                                            <td>
                                                <RowActions
                                                    editHref={route(
                                                        'admin.delivery.shipping-companies.edit',
                                                        company.id,
                                                    )}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {shippingCompanies.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                {t('admin.noShippingCompaniesYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={shippingCompanies} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
