import { Head, Link } from '@inertiajs/react';
import Pagination from '../../../Components/Pagination';
import RowActions from '../../../Components/RowActions';
import StatusBadge from '../../../Components/StatusBadge';
import AdminLayout from '../../../Layouts/AdminLayout';
import type { PaginatedData } from '../../../types';
import { useTranslation } from '../../../lib/useTranslation';

interface Representative {
    id: number;
    name: string;
    phone: string;
    status: string;
    areas_count: number;
}

export default function RepresentativesIndex({ representatives }: { representatives: PaginatedData<Representative> }) {
    const { t } = useTranslation();
    return (
        <AdminLayout title={t('admin.deliveryRepresentatives')}>
            <Head title={t('admin.representatives')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allRepresentatives')}</h4>
                            <Link
                                href={route('admin.delivery.representatives.create')}
                                className="btn btn-sm btn-primary"
                            >
                                {t('admin.addRepresentative')}
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.name')}</th>
                                        <th>{t('admin.phone')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.coverageAreas')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {representatives.data.map((rep) => (
                                        <tr key={rep.id}>
                                            <td className="fw-medium">{rep.name}</td>
                                            <td>{rep.phone}</td>
                                            <td>
                                                <StatusBadge status={rep.status} />
                                            </td>
                                            <td>{rep.areas_count}</td>
                                            <td>
                                                <div className="d-flex gap-2">
                                                    <Link
                                                        href={route('admin.delivery.representatives.areas', rep.id)}
                                                        className="btn btn-light btn-sm"
                                                    >
                                                        <i className="bx bx-map align-middle fs-18" />
                                                    </Link>
                                                    <RowActions
                                                        editHref={route('admin.delivery.representatives.edit', rep.id)}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {representatives.data.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="text-center text-muted py-4">
                                                {t('admin.noRepresentativesYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {representatives.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={representatives} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
