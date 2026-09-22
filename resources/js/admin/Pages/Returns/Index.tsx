import { Head, Link, router } from '@inertiajs/react';
import DateRangeFilter from '../../Components/DateRangeFilter';
import ExportButton from '../../Components/ExportButton';
import { PaginationFooter } from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface ReturnRecord {
    id: number;
    status: string;
    stage: string;
    created_at: string;
    order: { id: number; order_number: number; total: string };
    customer: { id: number; name: string; phone: string };
}

const STATUSES = ['requested', 'approved', 'received', 'inspected', 'refunded'];

export default function ReturnsIndex({
    returns,
    status,
    filters,
}: {
    returns: PaginatedData<ReturnRecord>;
    status: string | null;
    filters: { date_from: string | null; date_to: string | null };
}) {
    const { t, date } = useTranslation();
    const { can } = usePermissions();

    // Every navigation off this page carries the whole filter set: drop
    // date_from and the server reads it as "hasn't chosen" and puts the
    // list back on today, so changing the status would silently reset the
    // window. An empty string is a choice and survives; undefined does not.
    const query = (next: Partial<Record<string, string>> = {}) => ({
        status: status ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
        ...next,
    });

    const apply = (next: Partial<Record<string, string>>) =>
        router.get(route('admin.returns.index'), query(next), { preserveState: true, replace: true });

    return (
        <AdminLayout title={t('admin.returnsRefunds')}>
            <Head title={t('admin.returns')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h4 className="card-title flex-grow-1">{t('admin.allReturns')}</h4>
                            <DateRangeFilter
                                from={filters.date_from}
                                to={filters.date_to}
                                onApply={(range) => apply(range)}
                            />
                            <select
                                className="form-control form-control-sm"
                                style={{ width: 200 }}
                                value={status ?? ''}
                                onChange={(e) => apply({ status: e.target.value })}
                            >
                                <option value="">{t('admin.allStatuses')}</option>
                                {STATUSES.map((s) => (
                                    <option key={s} value={s}>
                                        {s}
                                    </option>
                                ))}
                            </select>
                            {can('returns.export') && (
                                // The same window the table is showing, so the
                                // download can't be wider than the list.
                                <ExportButton href={route('admin.returns.export', query())} />
                            )}
                            <Link href={route('admin.returns.create')} className="btn btn-sm btn-primary">
                                {t('admin.fileAReturn')}
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.order')}</th>
                                        <th>{t('admin.customer')}</th>
                                        <th>{t('admin.stage')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.filed')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {returns.data.map((r) => (
                                        <tr key={r.id}>
                                            <td className="fw-medium">#{r.order.order_number}</td>
                                            <td>
                                                {r.customer.name}
                                                <div className="text-muted fs-13">{r.customer.phone}</div>
                                            </td>
                                            <td>{t(`returnStage.${r.stage}`)}</td>
                                            <td>
                                                <StatusBadge status={r.status} />
                                            </td>
                                            <td>{date(r.created_at)}</td>
                                            <td>
                                                <Link
                                                    href={route('admin.returns.show', r.id)}
                                                    className="btn btn-soft-primary btn-sm"
                                                >
                                                    {t('admin.view')}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                    {returns.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                {t('admin.noReturnsFound')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={returns} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
