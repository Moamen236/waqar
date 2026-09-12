import AdminLayout from '../Layouts/AdminLayout';
import { useTranslation } from '../lib/useTranslation';

/**
 * Role-scoped widget content (Question 18) is explicitly deferred by the
 * spec itself ("screen layout/components... designed in a separate
 * follow-up") — this stays a placeholder, restyled with Larkon's own
 * widget-card convention (Admin Template/index.html) rather than a
 * generic Bootstrap card.
 */
export default function Dashboard() {
    const { t } = useTranslation();
    return (
        <AdminLayout title={t('admin.dashboard')}>
            <div className="row">
                <div className="col-12">
                    <div className="card">
                        <div className="card-body">
                            <div className="d-flex align-items-center gap-3">
                                <div className="avatar-md bg-primary-subtle rounded d-flex align-items-center justify-content-center">
                                    <i className="bx bx-store fs-24 text-primary" />
                                </div>
                                <div>
                                    <h4 className="mb-1">{t('admin.welcomeToWaqarAdmin')}</h4>
                                    <p className="text-muted mb-0">
                                        Use the sidebar to reach your department&apos;s work queue. The role-scoped
                                        dashboard widgets (Question 18) are a follow-up design pass, not yet built here.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
