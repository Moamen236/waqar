import { Link, router, usePage } from '@inertiajs/react';
import type { SharedProps } from '../types';

/**
 * my-account.html's left `user-infor` card + `menu-tab list-category`
 * nav. The template's four tabs become real routes, plus the three the
 * spec requires and the template lacks — Reviews, Notifications,
 * Recently Viewed (Section 13, Section 20 #16). Its Billing tab is gone:
 * no card data exists anywhere in this system (Q12).
 */
const items = [
    { key: 'dashboard', label: 'Dashboard', icon: 'ph-house-line', routeName: 'account.dashboard' },
    { key: 'orders', label: 'History Orders', icon: 'ph-package', routeName: 'account.orders' },
    { key: 'addresses', label: 'My Address', icon: 'ph-tag', routeName: 'account.addresses' },
    { key: 'wishlist', label: 'Wishlist', icon: 'ph-heart', routeName: 'wishlist.index' },
    { key: 'reviews', label: 'My Reviews', icon: 'ph-star', routeName: 'account.reviews' },
    { key: 'notifications', label: 'Notifications', icon: 'ph-bell', routeName: 'account.notifications' },
    {
        key: 'recently-viewed',
        label: 'Recently Viewed',
        icon: 'ph-clock-counter-clockwise',
        routeName: 'account.recently-viewed',
    },
    { key: 'settings', label: 'Setting', icon: 'ph-gear-six', routeName: 'account.settings' },
] as const;

export default function AccountNav({ active }: { active: string }) {
    const { auth, storefront } = usePage<SharedProps>().props;
    const unread = storefront?.notificationCount ?? 0;

    return (
        <div className="left md:w-1/3 w-full xl:pe-[3.125rem] lg:pe-[28px] md:pe-[16px]">
            <div className="user-infor bg-surface md:px-8 px-5 md:py-10 py-6 md:rounded-[20px] rounded-xl">
                <div className="heading flex flex-col items-center justify-center">
                    <div className="avatar md:w-[140px] w-[120px] md:h-[140px] h-[120px] rounded-full bg-white flex items-center justify-center">
                        <span className="ph ph-user text-5xl text-secondary"></span>
                    </div>
                    <div className="name heading6 mt-4 text-center">{auth.customer?.name}</div>
                    <div className="mail heading6 font-normal normal-case text-secondary text-center mt-1">
                        {auth.customer?.email}
                    </div>
                </div>
                <div className="menu-tab list-category w-full max-w-none lg:mt-10 mt-6">
                    {items.map((item, index) => (
                        <Link
                            key={item.key}
                            href={route(item.routeName)}
                            className={`category-item flex items-center gap-3 w-full px-5 py-4 rounded-lg cursor-pointer duration-300 hover:bg-white ${
                                index === 0 ? '' : 'mt-1.5'
                            } ${active === item.key ? 'active bg-white' : ''}`}
                        >
                            <span className={`ph ${item.icon} text-xl`}></span>
                            <strong className="heading6">{item.label}</strong>
                            {item.key === 'notifications' && unread > 0 && (
                                <span className="caption2 ms-auto bg-red text-white rounded-full px-2 py-0.5">
                                    {unread}
                                </span>
                            )}
                        </Link>
                    ))}
                    <button
                        type="button"
                        className="category-item flex items-center gap-3 w-full px-5 py-4 rounded-lg cursor-pointer duration-300 hover:bg-white mt-1.5"
                        onClick={() => router.post(route('logout'))}
                    >
                        <span className="ph ph-sign-out text-xl"></span>
                        <strong className="heading6">Logout</strong>
                    </button>
                </div>
            </div>
        </div>
    );
}
