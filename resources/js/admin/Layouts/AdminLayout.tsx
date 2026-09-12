import { Link, router, usePage } from '@inertiajs/react';
import { Fragment, type PropsWithChildren, useEffect, useState } from 'react';
import Dropdown from 'react-bootstrap/Dropdown';
import SimpleBar from 'simplebar-react';
import 'simplebar-react/dist/simplebar.min.css';
import { usePermissions } from '../Hooks/usePermissions';
import { notifyError, notifySuccess } from '../lib/confirm';
import type { SharedProps } from '../types';
import { useTranslation } from '../lib/useTranslation';

interface NavItem {
    label: string;
    href: string;
    icon: string;
    permission?: string;
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

// Icon classes are Boxicons (`bx bx-*` / the solid `bxs-*` family) — the
// icon font Larkon's own icons.min.css actually ships, not the
// iconify-icon web component the raw template markup uses (that needs an
// external runtime script; Boxicons is a plain bundled font, no CDN
// dependency).
const NAV: NavGroup[] = [
    {
        label: 'admin.navOverview',
        items: [{ label: 'admin.navDashboard', href: route('admin.dashboard'), icon: 'bx-grid-alt' }],
    },
    {
        label: 'admin.navOrders',
        items: [
            {
                label: 'admin.navCreateOrder',
                href: route('admin.orders.create'),
                icon: 'bx-cart-add',
                permission: 'orders.create',
            },
            {
                label: 'admin.navChecking',
                href: route('admin.checking.index'),
                icon: 'bx-check-square',
                permission: 'orders.view',
            },
            {
                label: 'admin.navDeliveryBoard',
                href: route('admin.delivery.index'),
                icon: 'bxs-truck',
                permission: 'orders.view',
            },
            {
                label: 'admin.navAccounting',
                href: route('admin.accounting.index'),
                icon: 'bx-wallet',
                permission: 'orders.view',
            },
        ],
    },
    {
        label: 'admin.navDelivery',
        items: [
            {
                label: 'admin.navRepresentatives',
                href: route('admin.delivery.representatives.index'),
                icon: 'bx-user-pin',
                permission: 'delivery.representatives.manage',
            },
            {
                label: 'admin.navShippingCompanies',
                href: route('admin.delivery.shipping-companies.index'),
                icon: 'bx-buildings',
                permission: 'delivery.companies.manage',
            },
            {
                label: 'admin.navShippingRates',
                href: route('admin.delivery.shipping-rates.index'),
                icon: 'bx-map-pin',
                permission: 'delivery.rates.manage',
            },
            {
                label: 'admin.navReconciliation',
                href: route('admin.accounting.reconciliation.index'),
                icon: 'bx-receipt',
                permission: 'accounting.reconciliation.manage',
            },
        ],
    },
    {
        label: 'admin.navReturns',
        items: [
            {
                label: 'admin.navReturnsRefunds',
                href: route('admin.returns.index'),
                icon: 'bx-undo',
                // Everyone who holds returns.manage also holds
                // returns.create per PermissionSeeder's defaults — .create
                // is the broader set (also Customer Service), so it's the
                // right gate for just showing the nav link.
                permission: 'returns.create',
            },
        ],
    },
    {
        label: 'admin.navCatalogMarketing',
        items: [
            {
                label: 'admin.navProducts',
                href: route('admin.products.index'),
                icon: 'bx-package',
                permission: 'products.view',
            },
            {
                label: 'admin.navCategories',
                href: route('admin.categories.index'),
                icon: 'bx-category',
                permission: 'categories.manage',
            },
            {
                label: 'admin.navAttributes',
                href: route('admin.attributes.index'),
                icon: 'bx-palette',
                permission: 'attributes.manage',
            },
            {
                label: 'admin.navCollections',
                href: route('admin.collections.index'),
                icon: 'bx-collection',
                permission: 'collections.manage',
            },
            {
                label: 'admin.navPromotions',
                href: route('admin.promotions.index'),
                icon: 'bxs-megaphone',
                permission: 'promotions.manage',
            },
        ],
    },
    {
        label: 'admin.navPeople',
        items: [
            {
                label: 'admin.navCustomers',
                href: route('admin.customers.index'),
                icon: 'bx-group',
                permission: 'customers.view',
            },
            {
                label: 'admin.navEmployees',
                href: route('admin.employees.index'),
                icon: 'bx-id-card',
                permission: 'employees.view',
            },
        ],
    },
    {
        label: 'admin.navFinance',
        items: [
            {
                label: 'admin.navTreasury',
                href: route('admin.treasury.index'),
                icon: 'bx-money',
                permission: 'treasury.view',
            },
        ],
    },
    {
        label: 'admin.navSystem',
        items: [
            {
                label: 'admin.navRolesPermissions',
                href: route('admin.roles.index'),
                icon: 'bx-lock-alt',
                permission: 'roles.manage',
            },
        ],
    },
];

export default function AdminLayout({ title, children }: PropsWithChildren<{ title: string }>) {
    const { t } = useTranslation();
    const { flash } = usePage<SharedProps>().props;
    const { employee, can } = usePermissions();
    const currentUrl = usePage().url;
    const [sidebarOpen, setSidebarOpen] = useState(false);

    useEffect(() => {
        if (flash.success) notifySuccess(flash.success);
        if (flash.error) notifyError(flash.error);
    }, [flash.success, flash.error]);

    // Mirrors Larkon's own app.js: html.sidebar-enable is the class its
    // CSS keys the mobile off-canvas sidebar's visibility on. The
    // desktop condensed/hover-collapse menu sizes app.js also supports
    // aren't ported — the sidebar just stays full-width on desktop,
    // which is a perfectly fine default, not a missing feature. Closing
    // it on navigation happens directly in each nav Link's onClick
    // below, not via an effect reacting to the URL — setting state
    // unconditionally inside an effect is the anti-pattern
    // react-hooks/set-state-in-effect flags.
    useEffect(() => {
        document.documentElement.classList.toggle('sidebar-enable', sidebarOpen);
    }, [sidebarOpen]);

    return (
        <div className="wrapper">
            <header className="topbar">
                <div className="container-fluid">
                    <div className="navbar-header">
                        <div className="d-flex align-items-center gap-2">
                            <button
                                type="button"
                                className="button-toggle-menu"
                                onClick={() => setSidebarOpen((v) => !v)}
                            >
                                <i className="bx bx-menu fs-24 align-middle" />
                            </button>
                            <h4 className="fw-bold topbar-button pe-none text-uppercase mb-0 d-none d-sm-block">
                                WAQAR Admin
                            </h4>
                        </div>

                        <div className="d-flex align-items-center gap-1">
                            {employee && (
                                <Dropdown align="end" className="topbar-item">
                                    <Dropdown.Toggle
                                        as="button"
                                        type="button"
                                        className="topbar-button d-flex align-items-center gap-1 border-0 bg-transparent"
                                    >
                                        <i className="bx bx-user-circle fs-24 align-middle" />
                                        <span className="d-none d-md-inline">{employee.full_name}</span>
                                    </Dropdown.Toggle>
                                    <Dropdown.Menu className="dropdown-menu-end">
                                        <Dropdown.ItemText className="text-muted small">
                                            {employee.roles.join(', ')}
                                        </Dropdown.ItemText>
                                        <Dropdown.Divider />
                                        <Dropdown.Item
                                            onClick={() => router.post(route('admin.logout'))}
                                            className="text-danger"
                                        >
                                            <i className="bx bx-log-out me-1 align-middle" />
                                            {t('admin.logOut')}
                                        </Dropdown.Item>
                                    </Dropdown.Menu>
                                </Dropdown>
                            )}
                        </div>
                    </div>
                </div>
            </header>

            <div className="main-nav">
                <div className="logo-box">
                    <Link href={route('admin.dashboard')} className="logo-dark">
                        <span className="logo-lg fw-bold fs-4 text-white">WAQAR</span>
                    </Link>
                </div>

                <SimpleBar className="scrollbar">
                    <ul className="navbar-nav" id="navbar-nav">
                        {NAV.map((group) => {
                            const items = group.items.filter((item) => !item.permission || can(item.permission));
                            if (items.length === 0) return null;

                            return (
                                <Fragment key={group.label}>
                                    <li className="menu-title">{t(group.label)}</li>
                                    {items.map((item) => (
                                        <li className="nav-item" key={item.href}>
                                            <Link
                                                href={item.href}
                                                onClick={() => setSidebarOpen(false)}
                                                className={`nav-link ${currentUrl.startsWith(new URL(item.href).pathname) ? 'active' : ''}`}
                                            >
                                                <span className="nav-icon">
                                                    <i className={`bx ${item.icon}`} />
                                                </span>
                                                <span className="nav-text">{t(item.label)}</span>
                                            </Link>
                                        </li>
                                    ))}
                                </Fragment>
                            );
                        })}
                    </ul>
                </SimpleBar>
            </div>

            <div className="page-content">
                <div className="container-fluid">
                    <div className="row">
                        <div className="col-12">
                            <div className="page-title-box">
                                <h4 className="page-title">{title}</h4>
                            </div>
                        </div>
                    </div>
                    {children}
                </div>

                <footer className="footer">
                    <div className="container-fluid">
                        <div className="row">
                            <div className="col-12 text-center">WAQAR Admin</div>
                        </div>
                    </div>
                </footer>
            </div>
        </div>
    );
}
