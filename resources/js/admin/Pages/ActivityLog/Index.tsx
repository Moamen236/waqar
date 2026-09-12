import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Pagination from '../../Components/Pagination';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';
import type { PaginatedData } from '../../types';

interface ActivityRow {
    id: number;
    log_name: string | null;
    event: string | null;
    label: string | null;
    names: string[] | null;
    subject_type: string | null;
    subject_id: number | null;
    changes: { field: string; old: string | null; new: string | null }[];
    causer: string | null;
    causer_type: string | null;
    at: string | null;
}

/**
 * Entries render from stored keys, not stored sentences — `event` holds
 * the raw verb and is translated here, the same way order statuses are.
 * A sentence baked in at write time could never be shown in Arabic.
 */
export default function ActivityLogIndex({
    activities,
    logs,
    events,
    filters,
}: {
    activities: PaginatedData<ActivityRow>;
    logs: string[];
    events: string[];
    filters: { log: string; event: string; search: string };
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.search ?? '');
    const [expanded, setExpanded] = useState<number | null>(null);

    const apply = (next: Partial<{ log: string; event: string; search: string }>) => {
        router.get(
            route('admin.activity-log.index'),
            { log: filters.log, event: filters.event, search, ...next },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout title={t('admin.activityLog')}>
            <Head title={t('admin.activityLog')} />

            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h4 className="card-title flex-grow-1">{t('admin.auditTrail')}</h4>

                            <select
                                className="form-select form-select-sm w-auto"
                                value={filters.log}
                                onChange={(event) => apply({ log: event.target.value })}
                            >
                                <option value="">{t('admin.allDomains')}</option>
                                {logs.map((log) => (
                                    <option key={log} value={log}>
                                        {t(`activity.log.${log}`)}
                                    </option>
                                ))}
                            </select>

                            <select
                                className="form-select form-select-sm w-auto"
                                value={filters.event}
                                onChange={(event) => apply({ event: event.target.value })}
                            >
                                <option value="">{t('admin.allEvents')}</option>
                                {events.map((event) => (
                                    <option key={event} value={event}>
                                        {t(`activity.event.${event}`)}
                                    </option>
                                ))}
                            </select>

                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    apply({ search });
                                }}
                            >
                                <input
                                    type="search"
                                    className="form-control form-control-sm"
                                    placeholder={t('admin.searchSubject')}
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                />
                            </form>
                        </div>

                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.date')}</th>
                                        <th>{t('admin.domain')}</th>
                                        <th>{t('admin.event')}</th>
                                        <th>{t('admin.subject')}</th>
                                        <th>{t('admin.by')}</th>
                                        <th>{t('admin.details')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {activities.data.map((activity) => (
                                        <tr key={activity.id}>
                                            <td className="text-muted text-nowrap">{activity.at ?? '—'}</td>
                                            <td>
                                                <span className="badge bg-light text-dark">
                                                    {activity.log_name ? t(`activity.log.${activity.log_name}`) : '—'}
                                                </span>
                                            </td>
                                            <td>{activity.event ? t(`activity.event.${activity.event}`) : '—'}</td>
                                            <td>
                                                <span className="fw-medium">{activity.label ?? '—'}</span>
                                                {activity.subject_type && (
                                                    <small className="d-block text-muted">
                                                        {activity.subject_type} #{activity.subject_id}
                                                    </small>
                                                )}
                                            </td>
                                            <td>
                                                {activity.causer ?? (
                                                    <span className="text-muted fst-italic">{t('admin.system')}</span>
                                                )}
                                            </td>
                                            <td>
                                                {activity.names && activity.names.length > 0 && (
                                                    <span>{activity.names.join(', ')}</span>
                                                )}
                                                {activity.changes.length > 0 && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-sm btn-link p-0"
                                                        onClick={() =>
                                                            setExpanded(expanded === activity.id ? null : activity.id)
                                                        }
                                                    >
                                                        {expanded === activity.id
                                                            ? t('admin.hideChanges')
                                                            : t('admin.showChanges', {
                                                                  count: String(activity.changes.length),
                                                              })}
                                                    </button>
                                                )}
                                                {expanded === activity.id && (
                                                    <table className="table table-sm mt-2 mb-0">
                                                        <tbody>
                                                            {activity.changes.map((change) => (
                                                                <tr key={change.field}>
                                                                    <td className="text-muted">{change.field}</td>
                                                                    <td className="text-decoration-line-through text-muted">
                                                                        {change.old ?? '—'}
                                                                    </td>
                                                                    <td className="fw-medium">{change.new ?? '—'}</td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                )}
                                                {activity.changes.length === 0 &&
                                                    (!activity.names || activity.names.length === 0) &&
                                                    '—'}
                                            </td>
                                        </tr>
                                    ))}
                                    {activities.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                {t('admin.noActivityYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {activities.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={activities} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
