import { Link, router, usePage } from '@inertiajs/react';
import Dropdown from 'react-bootstrap/Dropdown';
import SimpleBar from 'simplebar-react';
import { useTranslation } from '../lib/useTranslation';
import type { SharedProps, StaffNotification } from '../types';

/** Larkon's own cap for the topbar dropdown's scroll area (index.html). */
const LIST_MAX_HEIGHT = 280;

/**
 * The topbar bell (spec Section 23's "Admin Notifications", the one
 * Larkon block the template audit marked Needs Modification — its shell
 * is reusable, its placeholder content was social-network chatter).
 *
 * Two things here are not obvious:
 *
 * - **Rows are translated client-side.** The payload stores a type slug
 *   and its params, never a rendered sentence, because there is no
 *   employee locale to render against at dispatch time. See
 *   StaffNotification for the full reasoning. A slug with no catalog
 *   entry renders as the key itself, which is deliberately ugly — and
 *   NotificationCatalogTest fails the build before it can reach anyone.
 *
 * - **Clicking POSTs rather than navigating.** The server marks the row
 *   read and redirects to wherever it points, in one request. The
 *   destination is a route *name* in the payload, resolved server-side
 *   inside a request that has been through SetLocale, so an Arabic
 *   session lands on /ar/… even when the event was triggered by someone
 *   working in English.
 */
export default function NotificationBell() {
    const { admin } = usePage<SharedProps>().props;
    const { t } = useTranslation();

    if (!admin) {
        return null;
    }

    const { notificationCount, notifications } = admin;

    const open = (row: StaffNotification) => {
        if (!row.linked) {
            return;
        }

        router.post(route('admin.notifications.open', { notification: row.id }), {}, { preserveScroll: true });
    };

    return (
        <Dropdown align="end" className="topbar-item">
            <Dropdown.Toggle
                as="button"
                type="button"
                className="topbar-button position-relative border-0 bg-transparent"
                aria-label={t('admin.notifications')}
            >
                <i className="bx bx-bell fs-24 align-middle" />
                {notificationCount > 0 && (
                    <span className="position-absolute topbar-badge fs-10 translate-middle badge bg-danger rounded-pill">
                        {notificationCount > 99 ? '99+' : notificationCount}
                        <span className="visually-hidden">{t('admin.notifications')}</span>
                    </span>
                )}
            </Dropdown.Toggle>

            <Dropdown.Menu className="dropdown-lg dropdown-menu-end py-0">
                <div className="d-flex align-items-center justify-content-between gap-2 border-bottom p-3">
                    <h6 className="mb-0 fw-semibold">{t('admin.notifications')}</h6>
                    {notificationCount > 0 && (
                        <button
                            type="button"
                            className="btn btn-link btn-sm p-0 text-decoration-underline"
                            onClick={() => router.post(route('admin.notifications.read'), {}, { preserveScroll: true })}
                        >
                            {t('admin.markAllRead')}
                        </button>
                    )}
                </div>

                {notifications.length === 0 ? (
                    <div className="text-muted p-3 small">{t('admin.notificationsEmpty')}</div>
                ) : (
                    <SimpleBar style={{ maxHeight: LIST_MAX_HEIGHT }}>
                        {notifications.map((row) => (
                            <button
                                key={row.id}
                                type="button"
                                onClick={() => open(row)}
                                // A row with nowhere to go is still worth
                                // reading — it just isn't a control.
                                className={`dropdown-item border-bottom text-wrap py-2 text-start ${
                                    row.linked ? '' : 'pe-none'
                                } ${row.read_at === null ? 'fw-semibold' : 'text-muted'}`}
                            >
                                <span className="d-block">{t(`notification.${row.type}`, row.params)}</span>
                                <small className="text-muted">{row.created_at}</small>
                            </button>
                        ))}
                    </SimpleBar>
                )}

                <Link
                    href={route('admin.notifications.index')}
                    className="text-reset border-top d-block p-2 text-center small text-decoration-none"
                >
                    {t('admin.viewAll')}
                </Link>
            </Dropdown.Menu>
        </Dropdown>
    );
}
