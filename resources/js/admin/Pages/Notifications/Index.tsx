import { Head, router } from '@inertiajs/react';
import EmptyState from '../../Components/EmptyState';
import { PaginationFooter } from '../../Components/Pagination';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';
import type { PaginatedData, StaffNotification } from '../../types';

/**
 * The full inbox behind the topbar bell's "view all".
 *
 * Rows translate from a slug and params rather than rendering a stored
 * sentence — same reason as the activity log next door, and the same
 * reason the bell does it: a sentence baked in at write time could never
 * be shown in the other language. See StaffNotification.
 */
export default function NotificationsIndex({ notifications }: { notifications: PaginatedData<StaffNotification> }) {
    const { t } = useTranslation();

    const unread = notifications.data.filter((row) => row.read_at === null).length;

    return (
        <AdminLayout title={t('admin.notifications')}>
            <Head title={t('admin.notifications')} />

            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h4 className="card-title flex-grow-1">{t('admin.notifications')}</h4>

                            {unread > 0 && (
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-secondary"
                                    onClick={() =>
                                        router.post(route('admin.notifications.read'), {}, { preserveScroll: true })
                                    }
                                >
                                    <i className="bx bx-check-double me-1 align-middle" />
                                    {t('admin.markAllRead')}
                                </button>
                            )}
                        </div>

                        {notifications.data.length === 0 ? (
                            <EmptyState
                                icon="bx-bell"
                                title={t('admin.notificationsEmpty')}
                                description={t('admin.notificationsEmptyHint')}
                            />
                        ) : (
                            <div className="list-group list-group-flush">
                                {notifications.data.map((row) => (
                                    <button
                                        key={row.id}
                                        type="button"
                                        disabled={!row.linked}
                                        onClick={() =>
                                            router.post(
                                                route('admin.notifications.open', { notification: row.id }),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="list-group-item list-group-item-action d-flex align-items-start gap-3 text-start"
                                    >
                                        <i
                                            className={`bx bx-bell fs-20 mt-1 ${
                                                row.read_at === null ? 'text-primary' : 'text-muted opacity-50'
                                            }`}
                                        />
                                        <div className="flex-grow-1">
                                            <div className={row.read_at === null ? 'fw-semibold' : 'text-muted'}>
                                                {t(`notification.${row.type}`, row.params)}
                                            </div>
                                            <small className="text-muted">{row.created_at}</small>
                                        </div>
                                        {row.read_at === null && (
                                            <span className="badge bg-primary-subtle text-primary">
                                                {t('admin.unread')}
                                            </span>
                                        )}
                                    </button>
                                ))}
                            </div>
                        )}

                        <PaginationFooter data={notifications} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
