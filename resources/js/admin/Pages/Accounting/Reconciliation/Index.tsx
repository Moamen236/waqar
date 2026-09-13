import { Head, Link } from '@inertiajs/react';
import { EmptyRow } from '../../../Components/EmptyState';
import AdminLayout from '../../../Layouts/AdminLayout';
import { useTranslation } from '../../../lib/useTranslation';

interface Company {
    id: number;
    name: string;
    delivery_fee: string;
    return_fee: string;
    open_statements_count: number;
}

export default function ReconciliationIndex({ shippingCompanies }: { shippingCompanies: Company[] }) {
    const { t, price } = useTranslation();
    return (
        <AdminLayout title={t('admin.shippingCompanyReconciliation')}>
            <Head title={t('admin.reconciliation')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.shippingCompanies')}</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.company')}</th>
                                        <th>{t('admin.deliveryFee')}</th>
                                        <th>{t('admin.returnFee')}</th>
                                        <th>{t('admin.openStatements')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {shippingCompanies.map((company) => (
                                        <tr key={company.id}>
                                            <td className="fw-medium">{company.name}</td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(company.delivery_fee))}
                                                </span>
                                            </td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(company.return_fee))}
                                                </span>
                                            </td>
                                            <td>
                                                {company.open_statements_count > 0 ? (
                                                    <span className="badge bg-warning-subtle text-warning px-2 py-1">
                                                        {company.open_statements_count} {t('admin.open')}
                                                    </span>
                                                ) : (
                                                    <span className="badge bg-success-subtle text-success px-2 py-1">
                                                        {t('admin.settled')}
                                                    </span>
                                                )}
                                            </td>
                                            <td>
                                                <Link
                                                    href={route('admin.accounting.reconciliation.show', company.id)}
                                                    className="btn btn-soft-primary btn-sm"
                                                >
                                                    {t('admin.viewStatements')}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                    {shippingCompanies.length === 0 && (
                                        <EmptyRow
                                            colSpan={5}
                                            message={t('admin.noShippingCompaniesYet')}
                                            icon="bx-buildings"
                                        />
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
