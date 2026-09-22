import { Link, router, usePage } from '@inertiajs/react';
import { Fragment, type PropsWithChildren, type ReactNode, useCallback, useEffect, useState } from 'react';
import Dropdown from 'react-bootstrap/Dropdown';
import SimpleBar from 'simplebar-react';
import 'simplebar-react/dist/simplebar.min.css';
import Breadcrumb, { type Crumb } from '../Components/Breadcrumb';
import LocaleSwitcher from '../Components/LocaleSwitcher';
import NotificationBell from '../Components/NotificationBell';
import ThemeToggle from '../Components/ThemeToggle';
import { usePermissions } from '../Hooks/usePermissions';
import { notifyError, notifySuccess } from '../lib/confirm';
import type { SharedProps } from '../types';
import { useTranslation } from '../lib/useTranslation';

interface NavItem {
    label: string;
    href: string;
    icon: string;
    permission?: string;
    /**
     * Report-group key. The item shows only when the server says that group
     * actually contains a report this employee can open — holding the
     * permission is not enough, because reports land group by group and a
     * link into an empty catalogue reads as a broken screen.
     */
    reportGroup?: string;
}

interface NavGroup {
    label: string;
    items: NavItem[];
}

/** The width below which Larkon switches its sidebar to off-canvas. */
const LARKON_MENU_BREAKPOINT = 1140;

/** How often the topbar bell re-checks for new notifications. */
const NOTIFICATION_POLL_MS = 60_000;

/**
 * The sidebar sizes app.min.css actually defines, minus `hidden` (which is
 * derived from the viewport, never chosen).
 *
 * `sm-hover-active` is **Larkon's own shipped default** — `config.js`'s
 * `defaultConfig.menu.size` — not `default`. That matters for more than
 * fidelity: `.button-sm-hover`, the chevron in the sidebar header that
 * collapses it, is `display:none` in every mode *except* `sm-hover-active`
 * and a hovered `sm-hover`. Running with `default` is why the collapse
 * control was invisible.
 */
type DesktopMenuSize = 'sm-hover-active' | 'sm-hover' | 'condensed';

const DEFAULT_MENU_SIZE: DesktopMenuSize = 'sm-hover-active';
const MENU_SIZE_KEY = 'waqar.admin.menuSize';

/**
 * Strip query/hash and a trailing slash so `/ar/admin/` and
 * `/ar/admin?page=2` compare equal to `/ar/admin`.
 */
function normalizePath(path: string): string {
    const bare = path.split(/[?#]/)[0];

    if (bare.length > 1 && bare.endsWith('/')) return bare.slice(0, -1);

    return bare;
}

function pathOf(href: string): string {
    try {
        return normalizePath(new URL(href, window.location.origin).pathname);
    } catch {
        return normalizePath(href);
    }
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
                label: 'admin.navAllOrders',
                href: route('admin.orders.index'),
                icon: 'bx-receipt',
                permission: 'orders.view',
            },
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
                label: 'admin.navOutForDelivery',
                href: route('admin.delivery.orders'),
                icon: 'bx-package',
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
                permission: 'delivery.representatives.view',
            },
            {
                label: 'admin.navShippingCompanies',
                href: route('admin.delivery.shipping-companies.index'),
                icon: 'bx-buildings',
                permission: 'delivery.companies.view',
            },
            {
                label: 'admin.navShippingRates',
                href: route('admin.delivery.shipping-rates.index'),
                icon: 'bx-map-pin',
                permission: 'delivery.rates.view',
            },
            {
                label: 'admin.navGeography',
                href: route('admin.geo.index', 'governorates'),
                icon: 'bx-map',
                permission: 'geo.view',
            },
            {
                label: 'admin.navReconciliation',
                href: route('admin.accounting.reconciliation.index'),
                icon: 'bx-receipt',
                permission: 'accounting.reconciliation.view',
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
                permission: 'categories.view',
            },
            {
                label: 'admin.navAttributes',
                href: route('admin.attributes.index'),
                icon: 'bx-palette',
                permission: 'attributes.view',
            },
            {
                label: 'admin.navCollections',
                href: route('admin.collections.index'),
                icon: 'bx-collection',
                permission: 'collections.view',
            },
            {
                label: 'admin.navPromotions',
                href: route('admin.promotions.index'),
                icon: 'bxs-megaphone',
                permission: 'promotions.view',
            },
            {
                label: 'admin.navInventory',
                href: route('admin.inventory.index'),
                icon: 'bx-box',
                // .view, not .adjust — plenty of roles have reason to see
                // stock levels without being able to correct them.
                permission: 'inventory.view',
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
        // One entry per report *group*, not per report. The group page is
        // the catalogue filtered to that group, so the sidebar stays eight
        // lines however many reports the module grows to.
        label: 'admin.navReports',
        items: [
            {
                label: 'reports.group.executive',
                href: `${route('admin.reports.index')}?group=executive`,
                icon: 'bx-trending-up',
                permission: 'reports.executive.view',
                reportGroup: 'executive',
            },
            {
                label: 'reports.group.orders',
                href: `${route('admin.reports.index')}?group=orders`,
                icon: 'bx-receipt',
                permission: 'reports.orders.view',
                reportGroup: 'orders',
            },
            {
                label: 'reports.group.sales',
                href: `${route('admin.reports.index')}?group=sales`,
                icon: 'bx-line-chart',
                permission: 'reports.sales.view',
                reportGroup: 'sales',
            },
            {
                label: 'reports.group.inventory',
                href: `${route('admin.reports.index')}?group=inventory`,
                icon: 'bx-package',
                permission: 'reports.inventory.view',
                reportGroup: 'inventory',
            },
            {
                label: 'reports.group.returns',
                href: `${route('admin.reports.index')}?group=returns`,
                icon: 'bx-undo',
                permission: 'reports.returns.view',
                reportGroup: 'returns',
            },
            {
                label: 'reports.group.finance',
                href: `${route('admin.reports.index')}?group=finance`,
                icon: 'bx-wallet',
                permission: 'reports.finance.view',
                reportGroup: 'finance',
            },
            {
                label: 'reports.group.employees',
                href: `${route('admin.reports.index')}?group=employees`,
                icon: 'bx-user-check',
                permission: 'reports.employees.view',
                reportGroup: 'employees',
            },
            {
                label: 'reports.group.audit',
                href: `${route('admin.reports.index')}?group=audit`,
                icon: 'bx-search-alt',
                permission: 'reports.audit.view',
                reportGroup: 'audit',
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
                permission: 'roles.view',
            },
            {
                label: 'admin.navActivityLog',
                href: route('admin.activity-log.index'),
                icon: 'bx-history',
                permission: 'activity.view',
            },
        ],
    },
];

export default function AdminLayout({
    title,
    breadcrumbs,
    actions,
    children,
}: PropsWithChildren<{
    title: string;
    /** Trail shown opposite the title in Larkon's `page-title-box`. The
     *  current page is appended automatically, so callers pass ancestors
     *  only (usually none, or the module's list page from a form). */
    breadcrumbs?: Crumb[];
    /** Page-level buttons, rendered in the same flex row as the title —
     *  which is what Larkon's `page-title-box` is laid out for. */
    actions?: ReactNode;
}>) {
    const { t } = useTranslation();
    const { flash, admin } = usePage<SharedProps>().props;
    const { employee, can } = usePermissions();
    const currentUrl = usePage().url;
    const currentPath = pathOf(currentUrl);

    /**
     * One predicate for both the active-section calculation and the render,
     * so the sidebar can never highlight a section it does not draw.
     *
     * A report item needs the permission *and* a group the server says is
     * populated; everything else needs only the permission.
     */
    const reportGroups = admin?.reportGroups ?? [];
    const isVisible = useCallback(
        (item: NavItem): boolean => {
            if (item.permission && !can(item.permission)) return false;

            return !item.reportGroup || reportGroups.includes(item.reportGroup);
        },
        [can, reportGroups],
    );

    // One active link at a time: every href is a prefix of its own
    // sub-pages (`/orders` also prefixes `/orders/create`), and whole
    // sections nest under each other (`/admin` prefixes everything,
    // `/delivery` prefixes `/delivery/representatives`), so a plain
    // startsWith marks Dashboard + Delivery Board + Representatives all
    // active on `/ar/admin/delivery/representatives`. Instead collect
    // every visible item whose path matches on a segment boundary and
    // keep only the longest — i.e. the most specific section.
    const visiblePaths = NAV.flatMap((group) => group.items.filter(isVisible).map((item) => pathOf(item.href)));
    const longestActiveLength = visiblePaths
        .filter((itemPath) => currentPath === itemPath || currentPath.startsWith(`${itemPath}/`))
        .reduce((max, itemPath) => Math.max(max, itemPath.length), 0);
    const isActive = (href: string): boolean => {
        if (longestActiveLength === 0) return false;
        const itemPath = pathOf(href);

        return (
            itemPath.length === longestActiveLength &&
            (currentPath === itemPath || currentPath.startsWith(`${itemPath}/`))
        );
    };
    const [sidebarOpen, setSidebarOpen] = useState(false);
    // The desktop menu size, in Larkon's own vocabulary. Read in the
    // initialiser rather than an effect so the sidebar does not paint at one
    // size and snap to another on the next frame. Safe to touch localStorage
    // here because the admin bundle is client-rendered (no Inertia SSR
    // entry), and the read is guarded anyway — a browser with site data
    // blocked throws on access rather than returning null.
    const [narrow, setNarrow] = useState(false);
    const [menuSize, setMenuSize] = useState<DesktopMenuSize>(() => {
        try {
            const stored = window.localStorage.getItem(MENU_SIZE_KEY);

            return stored === 'sm-hover' || stored === 'condensed' ? stored : DEFAULT_MENU_SIZE;
        } catch {
            return DEFAULT_MENU_SIZE;
        }
    });

    // The dashboard is both the breadcrumb root and a real page, so on the
    // dashboard itself the root crumb *is* the current page — emitting both
    // rendered "Dashboard › Dashboard".
    const root: Crumb = { label: t('admin.navDashboard'), href: route('admin.dashboard') };
    const ancestors = breadcrumbs ?? [];
    const trail: Crumb[] =
        ancestors.length === 0 && title === root.label ? [{ label: title }] : [root, ...ancestors, { label: title }];

    useEffect(() => {
        if (flash.success) notifySuccess(flash.success);
        if (flash.error) notifyError(flash.error);
    }, [flash.success, flash.error]);

    // Keep the topbar bell current without a websocket. There is no
    // broadcasting stack in this project at all (no config/broadcasting.php,
    // no Echo, no Reverb), and adding one for a counter would mean a new
    // server process and two more dependencies — so this polls instead.
    //
    // A partial reload, so only the `admin` prop is recomputed: the page's
    // own props, its scroll position and any open form all survive. Paused
    // while the tab is hidden, because a backgrounded admin tab left open
    // overnight would otherwise fire ~500 pointless requests.
    useEffect(() => {
        const tick = () => {
            if (!document.hidden) {
                router.reload({ only: ['admin'] });
            }
        };

        const timer = window.setInterval(tick, NOTIFICATION_POLL_MS);

        return () => window.clearInterval(timer);
    }, []);

    // Larkon's sidebar sizes are driven by `data-menu-size` on <html>, and
    // the breakpoint that picks one is **JavaScript, not CSS** — app.min.css
    // has no media query for the sidebar at all, only attribute selectors.
    // Its own config.js/app.js set `hidden` below 1140px and re-evaluate on
    // resize; without that the fixed-position sidebar stayed on screen at
    // every width, overlapping the content on tablets and phones. Porting
    // the shell's markup without this rule is what left that broken.
    //
    // `sidebar-enable` (the off-canvas open state) is likewise only
    // meaningful under `data-menu-size=hidden` — that is the one selector
    // app.min.css scopes it to — so the topbar toggle only does anything
    // once the layout is in its narrow mode, exactly as in the template.
    useEffect(() => {
        const apply = () => setNarrow(window.innerWidth <= LARKON_MENU_BREAKPOINT);

        apply();
        window.addEventListener('resize', apply);

        return () => window.removeEventListener('resize', apply);
    }, []);

    useEffect(() => {
        document.documentElement.setAttribute('data-menu-size', narrow ? 'hidden' : menuSize);
    }, [narrow, menuSize]);

    // Only the *desktop* choice is persisted — `hidden` is derived from the
    // viewport every load, never remembered, or a phone-sized visit would
    // leave the sidebar collapsed on the next desktop one.
    useEffect(() => {
        try {
            window.localStorage.setItem(MENU_SIZE_KEY, menuSize);
        } catch {
            // Preference is a convenience; a browser refusing storage just
            // means the choice does not survive the next full page load.
        }
    }, [menuSize]);

    // Larkon's app.js wires its two controls differently, and the difference
    // is the point:
    //
    // - `.button-sm-hover` (the chevron in the sidebar header) flips between
    //   the full sidebar and the icon-only rail that widens on hover. On
    //   desktop this is *the* collapse control, which is why the template
    //   hides the topbar hamburger outright in both those modes
    //   (`html[data-menu-size=sm-hover…] .button-toggle-menu{display:none}`).
    // - `.button-toggle-menu` (the topbar hamburger) toggles `condensed`
    //   while the sidebar is on screen, and opens the off-canvas drawer once
    //   the viewport has put it in `hidden`.
    const toggleSmHover = useCallback(
        () => setMenuSize((size) => (size === 'sm-hover' ? 'sm-hover-active' : 'sm-hover')),
        [],
    );

    const toggleMenu = useCallback(() => {
        if (narrow) {
            setSidebarOpen((open) => !open);

            return;
        }

        setMenuSize((size) => (size === 'condensed' ? DEFAULT_MENU_SIZE : 'condensed'));
    }, [narrow]);

    // Closing on navigation happens in each nav Link's onClick below, not in
    // an effect reacting to the URL — setting state unconditionally inside an
    // effect is the anti-pattern react-hooks/set-state-in-effect flags.
    useEffect(() => {
        document.documentElement.classList.toggle('sidebar-enable', sidebarOpen);
    }, [sidebarOpen]);

    const closeSidebar = useCallback(() => setSidebarOpen(false), []);

    return (
        <div className="wrapper">
            <header className="topbar">
                <div className="container-fluid">
                    <div className="navbar-header">
                        <div className="d-flex align-items-center">
                            {/* The `.topbar-item` wrapper is load-bearing, not
                                decoration: app.min.css styles this button only
                                as `.topbar .topbar-item .button-toggle-menu`,
                                so outside it the element kept the browser's
                                default `2px outset` button border and rendered
                                as a boxed control instead of a bare glyph. */}
                            <div className="topbar-item">
                                <button type="button" className="button-toggle-menu me-2" onClick={toggleMenu}>
                                    <i className="bx bx-menu fs-24 align-middle" />
                                </button>
                            </div>

                            <div className="topbar-item">
                                <h4 className="fw-bold topbar-button pe-none text-uppercase mb-0 d-none d-sm-block">
                                    WAQAR Admin
                                </h4>
                            </div>
                        </div>

                        <div className="d-flex align-items-center gap-1">
                            {/* Template order: the light/dark switch is the
                                leftmost of the topbar's right-hand controls
                                (index.html's "Theme Color (Light/Dark)"
                                block), ahead of everything else. */}
                            <ThemeToggle />

                            <LocaleSwitcher />

                            <NotificationBell />

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
                                            {employee.roles.map((r) => t(`role.${r}`)).join('، ')}
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
                {/* Larkon's logo box carries *two* links, not one:
                    `.logo-dark` for a light sidebar and `.logo-light` for a
                    dark one, with app.min.css showing exactly one —
                    `html[data-menu-color=dark] … .logo-dark{display:none}`.
                    admin.blade.php sets `data-menu-color="dark"`, so shipping
                    only the `.logo-dark` half meant the rule hid it and the
                    sidebar had **no logo at all** (a `.logo-box` measuring
                    0px tall) since the shell was first ported.

                    Each carries the wordmark twice, because the condensed and
                    hover rails are ~70px wide and swap `logo-lg` for
                    `logo-sm`. The template's own image files are
                    Larkon-branded, so these are WAQAR's wordmark in the same
                    two sizes rather than the shipped PNGs. */}
                <div className="logo-box">
                    <Link href={route('admin.dashboard')} className="logo-dark">
                        <img src="/admin-theme/assets/images/logo-sm-dark.png" alt="WAQAR" className="logo-sm" />
                        <img src="/admin-theme/assets/images/logo-dark.png" alt="WAQAR" className="logo-lg" />
                    </Link>

                    <Link href={route('admin.dashboard')} className="logo-light">
                        <img src="/admin-theme/assets/images/logo-sm-light.png" alt="WAQAR" className="logo-sm" />
                        <img src="/admin-theme/assets/images/logo-light.png" alt="WAQAR" className="logo-lg" />
                    </Link>
                </div>

                {/* Rendered unconditionally, exactly as the template does:
                    app.min.css decides when it is visible (always under
                    `sm-hover-active`, on sidebar hover under `sm-hover`,
                    never otherwise) and rotates the glyph 180° for the
                    collapsed direction, so React must not second-guess it
                    with its own `display` logic. */}
                <button
                    type="button"
                    className="button-sm-hover"
                    aria-label={t(menuSize === 'sm-hover' ? 'admin.expandSidebar' : 'admin.collapseSidebar')}
                    aria-pressed={menuSize === 'sm-hover'}
                    onClick={toggleSmHover}
                >
                    <i className="bx bx-chevrons-right button-sm-hover-icon align-middle" />
                </button>

                <SimpleBar className="scrollbar">
                    <ul className="navbar-nav" id="navbar-nav">
                        {NAV.map((group) => {
                            const items = group.items.filter(isVisible);
                            if (items.length === 0) return null;

                            return (
                                <Fragment key={group.label}>
                                    <li className="menu-title">{t(group.label)}</li>
                                    {items.map((item) => (
                                        <li className="nav-item" key={item.href}>
                                            <Link
                                                href={item.href}
                                                onClick={closeSidebar}
                                                className={`nav-link ${isActive(item.href) ? 'active' : ''}`}
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

            {narrow && sidebarOpen && (
                <div
                    className="offcanvas-backdrop fade show"
                    role="presentation"
                    onClick={closeSidebar}
                    aria-hidden="true"
                />
            )}

            <div className="page-content">
                <div className="container-fluid">
                    <div className="row">
                        <div className="col-12">
                            {/* `page-title-box` is a flex space-between row in
                                app.min.css — it was carrying only the title,
                                so the half it was laid out for sat empty. */}
                            <div className="page-title-box">
                                <h4 className="page-title">{title}</h4>
                                <div className="d-flex align-items-center flex-wrap gap-2">
                                    {actions}
                                    <Breadcrumb items={trail} />
                                </div>
                            </div>
                        </div>
                    </div>
                    {children}
                </div>

                <footer className="footer">
                    <div className="container-fluid">
                        <div className="row">
                            <div className="col-12 text-center">
                                <span dir="ltr">© {new Date().getFullYear()}</span> WAQAR —{' '}
                                {t('admin.allRightsReserved')}
                            </div>
                        </div>
                    </div>
                </footer>
            </div>
        </div>
    );
}
