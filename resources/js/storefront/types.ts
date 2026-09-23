import type { LocaleProps } from '../lib/i18n';

/**
 * Shared prop shapes for the storefront Inertia pages. These mirror what
 * App\Support\ProductPresenter and the Store\* controllers actually send
 * — one shape per screen family, not a full API contract layer.
 */

export interface ProductColor {
    /** The attribute_value_id — what an image is tagged with. */
    id: number;
    name: string;
    hex: string | null;
}

/** ProductPresenter::card() — the Anvogue `product-item` card payload. */
export interface ProductCardData {
    id: number;
    slug: string;
    sku: string;
    name: string;
    short_description: string | null;
    price: number;
    origin_price: number | null;
    sale_percent: number;
    images: string[];
    /** Colour name → that colour's photos (tagged images only; see ProductPresenter). */
    images_by_color: Record<string, string[]>;
    colors: ProductColor[];
    sizes: string[];
    categories: string[];
    is_new: boolean;
    is_on_sale: boolean;
    rating: number;
    review_count: number;
    in_stock: boolean;
    tracked: boolean;
}

export interface VariantOption {
    attribute: string;
    attribute_label: string;
    value: string;
    hex: string | null;
}

export interface ProductVariantData {
    id: number;
    sku: string;
    price: number;
    /** null on an Advertisement product — nothing to track (Section 05). */
    available: number | null;
    size_guide_weight_min: number | null;
    size_guide_weight_max: number | null;
    options: VariantOption[];
}

/** ProductPresenter::detail(). */
export interface ProductDetailData extends ProductCardData {
    description: string | null;
    variants: ProductVariantData[];
    collections: { slug: string; name: string }[];
}

export interface CartLine {
    id: number;
    variant_id: number;
    product_id: number;
    slug: string;
    product_sku: string;
    name: string;
    sku: string;
    image: string | null;
    options: string;
    unit_price: number;
    quantity: number;
    subtotal: number;
    available: number | null;
}

export interface CartSummary {
    items: CartLine[];
    subtotal: number;
    discount: number;
    /** null until the geo cascade has resolved a rate (Section 11). */
    shipping: number | null;
    total: number | null;
    coupon: { code: string; type: string; value: number } | null;
    coupon_error: string | null;
    count: number;
}

export interface GeoArea {
    id: number;
    district_id: number | null;
    name: string;
}

export interface GeoCity {
    id: number;
    name: string;
    districts: { id: number; name: string }[];
    areas: GeoArea[];
}

export interface GeoGovernorate {
    id: number;
    name: string;
    cities: GeoCity[];
}

export interface GeoCountry {
    id: number;
    code: string;
    name: string;
    governorates: GeoGovernorate[];
}

export interface GeoSelection {
    /** Never submitted — governorate_id already implies it; it only drives the cascade. */
    country_id?: number | null;
    governorate_id: number | null;
    city_id: number | null;
    district_id: number | null;
    area_id: number | null;
}

export interface NavCategory {
    slug: string;
    name: string;
    children: { slug: string; name: string }[];
}

export interface SharedProps {
    [key: string]: unknown;
    /** Driven by the {locale} URL segment, never the browser (Q20). */
    locale: LocaleProps;
    auth: { customer: { id: number; name: string; email: string } | null };
    flash: { success?: string | null; error?: string | null };
    storefront: {
        cartCount: number;
        wishlistCount: number;
        notificationCount: number;
        nav: { categories: NavCategory[]; collections: { slug: string; name: string }[] };
    } | null;
}

export interface Pagination {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

export interface OrderTimelineData {
    stages: { label: string; reached: boolean; current: boolean }[];
    state: string;
    off_track: boolean;
    history: { status: string; at: string | null }[];
}
