import type { LocaleProps } from '../lib/i18n';

/**
 * Shared prop shapes for the admin Inertia pages. Kept intentionally
 * loose (most list/detail records pass straight through from an Eloquent
 * ->toArray()/API resource-less response) — this isn't a full API
 * contract layer, just enough structure for the pages that read these
 * fields directly.
 */

export interface AuthEmployee {
    id: number;
    full_name: string;
    email: string;
    roles: string[];
    is_super_admin: boolean;
    permissions: string[];
}

/**
 * One row in the staff inbox. `type` is a slug, not a sentence — the UI
 * renders `t('notification.' + type, params)` because there is no
 * employee locale to render against on the server (see
 * app/Notifications/Staff/StaffNotification.php). `linked` says whether
 * the row goes anywhere; the URL itself is never sent, the server
 * resolves it on click.
 */
export interface StaffNotification {
    id: string;
    type: string;
    params: Record<string, string | number>;
    linked: boolean;
    read_at: string | null;
    created_at: string | null;
}

export interface SharedProps {
    [key: string]: unknown;
    /** Driven by the {locale} URL segment, same as the storefront (Q20). */
    locale: LocaleProps;
    auth: { employee: AuthEmployee | null };
    flash: { success?: string | null; warning?: string | null; error?: string | null };
    /** Admin shell data; null on storefront requests. */
    admin: {
        notificationCount: number;
        notifications: StaffNotification[];
        /**
         * Report groups with at least one report this employee can open.
         * Holding the permission is not enough — a group only appears once
         * a report has actually been registered in it.
         */
        reportGroups: string[];
    } | null;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface GeoDistrict {
    id: number;
    name: string;
}

export interface GeoArea {
    id: number;
    district_id: number | null;
    name: string;
}

export interface GeoCity {
    id: number;
    name: string;
    districts: GeoDistrict[];
    areas: GeoArea[];
}

export interface GeoGovernorate {
    id: number;
    name: string;
    cities: GeoCity[];
}

export type GeoTree = GeoGovernorate[];

export interface Customer {
    id: number;
    name: string;
    email: string | null;
    phone: string;
    is_active: boolean;
    orders_count?: number;
}

export interface Warehouse {
    id: number;
    name: string;
}

export interface OrderSummary {
    id: number;
    order_number: number;
    status: string;
    customer_status: string;
    payment_status: string;
    total: string;
    // Optional because some listings select an explicit column list. Where
    // it is present the screen can show the net figure — what actually
    // reaches the treasury once the courier keeps their fee.
    shipping_amount?: string;
    created_at: string;
    customer?: Customer;
    delivery_representative?: { id: number; name: string } | null;
    shipping_company?: { id: number; name: string } | null;
    // Present only where the controller eager-loads them — the delivery
    // screens, which route by destination.
    shipping_governorate?: GeoName | null;
    shipping_city?: GeoName | null;
    shipping_district?: GeoName | null;
    shipping_area?: GeoName | null;
}

export interface GeoName {
    id: number;
    name: string;
}
