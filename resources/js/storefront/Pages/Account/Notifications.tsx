import { Head, router } from '@inertiajs/react';
import AccountNav from '../../Components/AccountNav';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';

interface NotificationRow {
    id: string;
    type: string;
    data: Record<string, unknown>;
    read_at: string | null;
    created_at: string | null;
}

/**
 * The Notifications tab — required by the spec, absent from the template
 * (Section 13, Section 20 #16). Reads Laravel's standard `notifications`
 * table (Section 24). The events that write to it — order placed /
 * confirmed / shipped / delivered, return requested — are Section 23's
 * notification work and not wired in this phase, so this list is
 * genuinely empty until then rather than filled with placeholders.
 */
export default function AccountNotifications({ notifications }: { notifications: NotificationRow[] }) {
    const unread = notifications.filter((notification) => notification.read_at === null).length;

    return (
        <StorefrontLayout>
            <Head title="Notifications" />
            <Breadcrumb title="Notifications" />

            <div className="my-account-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main lg:px-[60px] md:px-4 flex gap-y-8 max-md:flex-col w-full">
                        <AccountNav active="notifications" />
                        <div className="right list-filter md:w-2/3 w-full pl-2.5">
                            <div className="text-content w-full p-7 border border-line rounded-xl">
                                <div className="flex items-center justify-between gap-3">
                                    <h6 className="heading6">Notifications</h6>
                                    {unread > 0 && (
                                        <button
                                            type="button"
                                            className="text-button underline"
                                            onClick={() =>
                                                router.post(
                                                    route('account.notifications.read'),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Mark all as read
                                        </button>
                                    )}
                                </div>
                                {notifications.length === 0 && (
                                    <div className="caption1 text-secondary mt-4">Nothing here yet.</div>
                                )}
                                {notifications.map((notification) => (
                                    <div
                                        key={notification.id}
                                        className={`flex items-start gap-4 py-4 border-b border-line ${
                                            notification.read_at === null ? '' : 'opacity-60'
                                        }`}
                                    >
                                        <span className="ph ph-bell text-2xl"></span>
                                        <div>
                                            <div className="text-title">
                                                {String(notification.data.title ?? notification.type)}
                                            </div>
                                            {notification.data.message !== undefined && (
                                                <div className="caption1 text-secondary mt-1">
                                                    {String(notification.data.message)}
                                                </div>
                                            )}
                                            <div className="caption2 text-secondary2 mt-1">
                                                {notification.created_at}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
