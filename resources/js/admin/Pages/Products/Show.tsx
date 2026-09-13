import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import EmptyState, { EmptyRow } from '../../Components/EmptyState';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import { useTranslation } from '../../lib/useTranslation';

interface VariantStock {
    warehouse: string | null;
    quantity: number;
    reserved_quantity: number;
    available: number;
}

interface Variant {
    id: number;
    sku: string;
    status: boolean;
    price: string | null;
    sale_price: string | null;
    attributes: { attribute: string | null; value: string; color_hex: string | null }[];
    stock: VariantStock[];
    units_sold: number;
}

interface ProductRecord {
    id: number;
    name: string;
    slug: string;
    sku: string;
    description: string | null;
    short_description: string | null;
    price: string;
    sale_price: string | null;
    cost_price: string | null;
    status: boolean;
    is_featured: boolean;
    is_new: boolean;
    is_on_sale: boolean;
    sort_order: number;
    product_type: 'advertisement' | 'real';
    inventory_tracking_enabled: boolean;
    created_at: string | null;
    updated_at: string | null;
    categories: { id: number; name: string }[];
    collections: { id: number; name: string }[];
    images: { id: number; url: string }[];
    variants: Variant[];
}

interface ReviewRecord {
    id: number;
    customer: string | null;
    rating: number;
    title: string | null;
    comment: string | null;
    status: string;
    created_at: string | null;
}

/**
 * Ported from Admin Template/product-details.html: its `col-lg-4` gallery
 * card (image + thumbnail strip, actions in a `card-footer border-top`)
 * beside a `col-lg-8` summary card, then a second row of two `col-lg-6`
 * cards — "Items Detail" and the reviews panel.
 *
 * The template's page is a **storefront** product page, so the parts of it
 * that only a shopper would use are simply absent here rather than
 * rendered as dead controls: there is no Add-to-Cart / Buy-Now / wishlist
 * trio and no quantity stepper. This is a read-only record view. The one
 * action it offers is Edit, and it sits in the page header rather than
 * under the gallery, so the cards themselves stay pure content — going
 * back is what the breadcrumb is for.
 *
 * Where the template's quantity stepper sat, the same slot carries real
 * per-warehouse stock, which is the thing an operator opens this page to
 * find. The layout, spacing and classes are the template's throughout.
 *
 * Its carousel is Bootstrap's, driven by `data-bs-*` attributes that need
 * the bundled JS to act on the DOM. Larkon's `app.js` is not loaded (it
 * assumes a non-React lifecycle — see AdminLayout), so the thumbnail
 * strip selects through React state instead and keeps the template's own
 * `carousel-indicators` markup for the strip itself.
 */
export default function ProductShow({
    product,
    reviews,
    reviewSummary,
}: {
    product: ProductRecord;
    reviews: ReviewRecord[];
    reviewSummary: { count: number; average: number };
}) {
    const { t, price, dateTime } = useTranslation();
    const { can } = usePermissions();
    const [activeImage, setActiveImage] = useState(0);

    const listPrice = Number(product.price) || 0;
    const nowPrice = Number(product.sale_price) || 0;
    const discountPercent =
        listPrice > 0 && nowPrice > 0 && nowPrice < listPrice
            ? Math.round(((listPrice - nowPrice) / listPrice) * 100)
            : 0;

    const flags = [
        product.is_featured && t('admin.featured'),
        product.is_new && t('admin.new'),
        product.is_on_sale && t('admin.onSaleBadge'),
    ].filter(Boolean) as string[];

    return (
        <AdminLayout
            title={product.name}
            breadcrumbs={[{ label: t('admin.products'), href: route('admin.products.index') }]}
            actions={
                can('products.update') && (
                    <Link
                        href={route('admin.products.edit', product.id)}
                        className="btn btn-sm btn-soft-primary d-flex align-items-center gap-1"
                    >
                        <i className="bx bx-edit-alt" />
                        {t('admin.edit')}
                    </Link>
                )
            }
        >
            <Head title={product.name} />

            <div className="row">
                <div className="col-lg-4">
                    <div className="card">
                        <div className="card-body">
                            {product.images.length > 0 ? (
                                <>
                                    <img
                                        src={product.images[activeImage]?.url ?? product.images[0].url}
                                        alt={product.name}
                                        className="img-fluid bg-light rounded w-100"
                                    />
                                    {product.images.length > 1 && (
                                        <div className="carousel-indicators m-0 mt-2 position-static h-100 d-flex flex-wrap gap-2">
                                            {product.images.map((image, index) => (
                                                <button
                                                    key={image.id}
                                                    type="button"
                                                    aria-label={`${index + 1}`}
                                                    aria-current={index === activeImage}
                                                    onClick={() => setActiveImage(index)}
                                                    className={`w-auto h-auto rounded bg-light border-0 p-0 ${index === activeImage ? 'active' : ''}`}
                                                >
                                                    <img src={image.url} className="d-block avatar-xl" alt="" />
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </>
                            ) : (
                                <div className="rounded bg-light-subtle border border-dashed d-flex flex-column align-items-center justify-content-center text-muted py-5">
                                    <i className="bx bx-image fs-48" />
                                    <span className="fs-13 mt-2">{t('admin.noImageYet')}</span>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className="col-lg-8">
                    <div className="card">
                        <div className="card-body">
                            <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <StatusBadge status={product.status ? 'active' : 'inactive'} />
                                <span
                                    className={`badge px-2 py-1 ${product.product_type === 'real' ? 'bg-success-subtle text-success' : 'bg-info-subtle text-info'}`}
                                >
                                    {t(`productType.${product.product_type}`)}
                                </span>
                                {flags.map((flag) => (
                                    <span key={flag} className="badge bg-primary-subtle text-primary px-2 py-1">
                                        {flag}
                                    </span>
                                ))}
                            </div>

                            <p className="mb-1">
                                <span className="fs-24 text-dark fw-medium">{product.name}</span>
                            </p>

                            <div className="d-flex gap-2 align-items-center flex-wrap">
                                <ul className="d-flex text-warning m-0 fs-20 list-unstyled">
                                    {[1, 2, 3, 4, 5].map((star) => (
                                        <li key={star}>
                                            <i
                                                className={`bx ${star <= Math.round(reviewSummary.average) ? 'bxs-star' : 'bx-star'}`}
                                            />
                                        </li>
                                    ))}
                                </ul>
                                <span className="text-muted fs-14">
                                    {t('admin.reviewsCount', { count: reviewSummary.count })}
                                </span>
                            </div>

                            <h4 className="fw-semibold text-dark mt-3 d-flex align-items-center gap-2 flex-wrap">
                                {discountPercent > 0 && (
                                    <span className="text-muted text-decoration-line-through" dir="ltr">
                                        {price(listPrice)}
                                    </span>
                                )}
                                <span dir="ltr">{price(nowPrice || listPrice)}</span>
                                {discountPercent > 0 && (
                                    <small className="text-muted">
                                        (<span dir="ltr">{discountPercent}%</span> {t('admin.discountLabel')})
                                    </small>
                                )}
                            </h4>

                            {product.short_description && (
                                <p className="text-muted mt-2 mb-0">{product.short_description}</p>
                            )}

                            {product.description && (
                                <div className="mt-3">
                                    <h5 className="text-dark fw-medium">{t('admin.description')} :</h5>
                                    {/* Sanitised on write at the single choke point both
                                        store() and update() pass through (Phase 7's
                                        RichTextSanitizer), so the stored value is already
                                        safe to render as the rich text it is. */}
                                    <div
                                        className="text-muted"
                                        dangerouslySetInnerHTML={{ __html: product.description }}
                                    />
                                </div>
                            )}
                        </div>

                        {/* product-details.html puts a quantity stepper here. A
                            shopper picks a quantity; an operator needs to know what
                            is actually on hand, so the slot carries real stock. */}
                        <div className="card-header border-top">
                            <div className="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <h4 className="card-title">{t('admin.stockByVariant')}</h4>
                                {product.inventory_tracking_enabled ? (
                                    can('inventory.view') && (
                                        <Link
                                            href={route('admin.inventory.index')}
                                            className="btn btn-sm btn-soft-primary"
                                        >
                                            {t('admin.manageStock')}
                                        </Link>
                                    )
                                ) : (
                                    <span className="badge bg-info-subtle text-info px-2 py-1">
                                        {t('admin.trackingDisabled')}
                                    </span>
                                )}
                            </div>
                        </div>

                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th className="ps-3">SKU</th>
                                        <th>{t('admin.attributes')}</th>
                                        <th>{t('admin.warehouse')}</th>
                                        <th>{t('admin.onHand')}</th>
                                        <th>{t('admin.reserved')}</th>
                                        <th>{t('admin.available')}</th>
                                        <th className="pe-3">{t('admin.unitsSold')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {product.variants.map((variant) => (
                                        <tr key={variant.id} className={variant.status ? '' : 'opacity-50'}>
                                            <td className="ps-3">
                                                <span className="fw-medium text-nowrap" dir="ltr">
                                                    {variant.sku}
                                                </span>
                                            </td>
                                            <td>
                                                <div className="d-flex flex-wrap gap-1">
                                                    {variant.attributes.map((attribute, i) => (
                                                        <span
                                                            key={`${variant.id}-${i}`}
                                                            className="badge bg-light text-dark px-2 py-1 d-inline-flex align-items-center gap-1"
                                                        >
                                                            {attribute.color_hex && (
                                                                <i
                                                                    className="bx bxs-circle"
                                                                    style={{ color: attribute.color_hex }}
                                                                />
                                                            )}
                                                            {attribute.value}
                                                        </span>
                                                    ))}
                                                </div>
                                            </td>
                                            {variant.stock.length === 0 ? (
                                                <td colSpan={4} className="text-muted">
                                                    <i className="bx bx-info-circle me-1 align-middle" />
                                                    {t('admin.notStocked')}
                                                </td>
                                            ) : (
                                                <>
                                                    <td>
                                                        {variant.stock.map((row, i) => (
                                                            <div key={i}>{row.warehouse ?? '—'}</div>
                                                        ))}
                                                    </td>
                                                    <td>
                                                        {variant.stock.map((row, i) => (
                                                            <div key={i} dir="ltr">
                                                                {row.quantity}
                                                            </div>
                                                        ))}
                                                    </td>
                                                    <td>
                                                        {variant.stock.map((row, i) => (
                                                            <div key={i} dir="ltr">
                                                                {row.reserved_quantity}
                                                            </div>
                                                        ))}
                                                    </td>
                                                    <td>
                                                        {variant.stock.map((row, i) => (
                                                            <div key={i}>
                                                                <span
                                                                    className={`badge px-2 py-1 ${row.available <= 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'}`}
                                                                    dir="ltr"
                                                                >
                                                                    {row.available}
                                                                </span>
                                                            </div>
                                                        ))}
                                                    </td>
                                                </>
                                            )}
                                            <td className="pe-3" dir="ltr">
                                                {variant.units_sold}
                                            </td>
                                        </tr>
                                    ))}
                                    {product.variants.length === 0 && (
                                        <EmptyRow colSpan={7} message={t('admin.noVariantsYet')} icon="bx-package" />
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {product.inventory_tracking_enabled &&
                            product.variants.length > 0 &&
                            product.variants.every((variant) => variant.stock.length === 0) && (
                                <div className="card-body border-top">
                                    <div className="alert alert-warning mb-0" role="alert">
                                        <i className="bx bx-error-circle me-1 align-middle" />
                                        {t('admin.notStockedHint')}
                                    </div>
                                </div>
                            )}
                    </div>
                </div>
            </div>

            <div className="row">
                <div className="col-lg-6">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.itemsDetail')}</h4>
                        </div>
                        <div className="card-body">
                            <ul className="d-flex flex-column gap-2 list-unstyled fs-14 text-muted mb-0">
                                <SpecRow label="SKU" value={product.sku} ltr />
                                <SpecRow label={t('admin.slug')} value={product.slug} ltr />
                                <SpecRow
                                    label={t('admin.productType')}
                                    value={t(`productType.${product.product_type}`)}
                                />
                                <SpecRow
                                    label={t('admin.categories')}
                                    value={product.categories.map((c) => c.name).join(', ') || t('admin.none')}
                                />
                                <SpecRow
                                    label={t('admin.collections')}
                                    value={product.collections.map((c) => c.name).join(', ') || t('admin.none')}
                                />
                                <SpecRow label={t('admin.price')} value={price(listPrice)} ltr />
                                {product.sale_price && (
                                    <SpecRow label={t('admin.salePrice')} value={price(nowPrice)} ltr />
                                )}
                                {/* Cost price is margin data: the controller only sends
                                    it to someone who can already edit the product. */}
                                {product.cost_price !== null && (
                                    <SpecRow
                                        label={t('admin.costPriceInternalOnly')}
                                        value={price(Number(product.cost_price))}
                                        ltr
                                    />
                                )}
                                <SpecRow label={t('admin.flags')} value={flags.join(', ') || t('admin.none')} />
                                <SpecRow label={t('admin.sortOrder')} value={String(product.sort_order)} ltr />
                                <SpecRow label={t('admin.createdAt')} value={dateTime(product.created_at)} ltr />
                                <SpecRow label={t('admin.updatedAt')} value={dateTime(product.updated_at)} ltr />
                            </ul>
                        </div>
                    </div>
                </div>

                <div className="col-lg-6">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.customerReviews')}</h4>
                        </div>

                        {reviews.length === 0 ? (
                            <EmptyState title={t('admin.noReviewsYet')} icon="bx-star" />
                        ) : (
                            <div className="card-body">
                                {reviews.map((review) => (
                                    <div key={review.id} className="d-flex align-items-start gap-2 mb-3">
                                        <div className="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center flex-shrink-0">
                                            <i className="bx bx-user fs-18 text-muted" />
                                        </div>
                                        <div className="flex-grow-1">
                                            <div className="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                                <h5 className="mb-0">{review.customer ?? '—'}</h5>
                                                <StatusBadge status={review.status} />
                                            </div>
                                            <ul className="d-flex text-warning m-0 fs-14 list-unstyled">
                                                {[1, 2, 3, 4, 5].map((star) => (
                                                    <li key={star}>
                                                        <i
                                                            className={`bx ${star <= review.rating ? 'bxs-star' : 'bx-star'}`}
                                                        />
                                                    </li>
                                                ))}
                                            </ul>
                                            {review.title && <p className="fw-medium mb-0 mt-1">{review.title}</p>}
                                            {review.comment && <p className="text-muted mb-0">{review.comment}</p>}
                                            <span className="text-muted fs-13" dir="ltr">
                                                {dateTime(review.created_at)}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}

/** One line of product-details.html's "Items Detail" spec list. */
function SpecRow({ label, value, ltr = false }: { label: string; value: string; ltr?: boolean }) {
    return (
        <li>
            <span className="fw-medium text-dark">{label}</span>
            <span className="mx-2">:</span>
            {ltr ? <span dir="ltr">{value}</span> : value}
        </li>
    );
}
