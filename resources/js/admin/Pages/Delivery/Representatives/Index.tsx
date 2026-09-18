import { Head, Link, router } from '@inertiajs/react';
import { confirmAction } from '../../../lib/confirm';
import { PaginationFooter } from '../../../Components/Pagination';
import RowActions from '../../../Components/RowActions';
import StatusBadge from '../../../Components/StatusBadge';
import AdminLayout from '../../../Layouts/AdminLayout';
import { usePermissions } from '../../../Hooks/usePermissions';
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

        router.delete(route('admin.delivery.representatives.destroy', id), { preserveScroll: true });
    }

    return (
        <AdminLayout title={t('admin.deliveryRepresentatives')}>
            <Head title={t('admin.representatives')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allRepresentatives')}</h4>
                            {can('delivery.representatives.create') && (
                                <Link
                                    href={route('admin.delivery.representatives.create')}
                                    className="btn btn-sm btn-primary"
                                >
                                    {t('admin.addRepresentative')}
                                </Link>
                            )}
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
                                                        editHref={
                                                            can('delivery.representatives.update')
                                                                ? route('admin.delivery.representatives.edit', rep.id)
                                                                : undefined
                                                        }
                                                        onDelete={
                                                            can('delivery.representatives.delete')
                                                                ? () => remove(rep.id, rep.name)
                                                                : undefined
                                                        }
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
                        <PaginationFooter data={representatives} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
