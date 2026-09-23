import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ProductCard from '../../Components/ProductCard';
import Rate from '../../Components/Rate';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { ProductDetailData, ProductVariantData, SharedProps } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface ReviewRow {
    id: number;
    author: string;
    rating: number;
    title: string | null;
    comment: string | null;
    verified: boolean;
    created_at: string | null;
}

/**
 * Product detail — Anvogue's product-default.html. Section 17's three
 * required changes are applied: the `brand` field is gone entirely, the
 * selected variant's real SKU is shown and submitted, and the Size Guide
 * link opens the variant's own size-guide weight range (Section 06)
 * rather than a static table. The compare button is dropped (Q3), and
 * "Buy It Now" adds to the cart and goes straight to checkout rather
 * than skipping the cart entirely — checkout prices the cart, not a
 * client-supplied line.
 */
export default function ProductShow({
    product,
    reviews,
    related,
    canReview,
    inWishlist,
}: {
    product: ProductDetailData;
    reviews: ReviewRow[];
    related: ProductDetailData[];
    canReview: boolean;
    inWishlist: boolean;
}) {
    const { auth } = usePage<SharedProps>().props;
    const [colour, setColour] = useState<string | null>(null);
    const [size, setSize] = useState<string | null>(null);
    const [quantity, setQuantity] = useState(1);
    const [tab, setTab] = useState<'description' | 'reviews'>('description');
    const [sizeGuideOpen, setSizeGuideOpen] = useState(false);
    const [activeImage, setActiveImage] = useState(0);
    const { t, price } = useTranslation();

    const optionOf = (variant: ProductVariantData, attribute: string) =>
        variant.options.find((option) => option.attribute === attribute)?.value ?? null;

    // A variant is only "chosen" once every option the catalog actually
    // differentiates on has been picked — a product with no colour
    // variants shouldn't demand a colour.
    const hasColours = product.colors.length > 0;
    const hasSizes = product.sizes.length > 0;

    const variant = useMemo<ProductVariantData | null>(() => {
        const match = product.variants.find(
            (candidate) =>
                (!hasColours || optionOf(candidate, 'color') === colour) &&
                (!hasSizes || optionOf(candidate, 'size') === size),
        );

        return match ?? (product.variants.length === 1 ? product.variants[0] : null);
    }, [product.variants, colour, size, hasColours, hasSizes]);

    const outOfStock = variant !== null && variant.available !== null && variant.available <= 0;

    // Picking a colour swaps the gallery to that colour's photos. An
    // untagged catalogue has an empty map and every colour falls through
    // to the full set, so this changes nothing until someone tags images
    // in the admin product form.
    const gallery = useMemo(() => {
        const tagged = colour === null ? [] : (product.images_by_color[colour] ?? []);

        return tagged.length > 0 ? tagged : product.images;
    }, [product.images, product.images_by_color, colour]);

    // The weight range for the size just picked, shown inline so the
    // customer doesn't have to open the whole table to check one size.
    // Matched on size alone, not the fully-resolved variant: the range is
    // a property of the size, so it should appear before a colour is
    // chosen rather than waiting for both halves of the selection.
    const sizeGuide = useMemo(() => {
        if (size === null) return null;

        const match = product.variants.find((candidate) => optionOf(candidate, 'size') === size);
        if (!match || match.size_guide_weight_min === null || match.size_guide_weight_max === null) {
            return null;
        }

        return `${match.size_guide_weight_min} – ${match.size_guide_weight_max} kg`;
    }, [product.variants, size]);
    // One row per size, not per variant: the guide is a property of the
    // size, so a product in four colours would otherwise repeat every
    // size four times. First variant of each size wins — they all carry
    // the same pair, since the admin form writes it per size.
    const sizeGuideRows = useMemo(() => {
        const seen = new Map<string, { label: string; range: string | null }>();

        for (const item of product.variants) {
            const label = optionOf(item, 'size') ?? item.sku;
            if (seen.has(label)) continue;
            seen.set(label, {
                label,
                range:
                    item.size_guide_weight_min !== null && item.size_guide_weight_max !== null
                        ? `${item.size_guide_weight_min} – ${item.size_guide_weight_max} kg`
                        : null,
            });
        }

        return [...seen.values()];
    }, [product.variants]);

    const addToCart = (thenCheckout = false) => {
        if (variant === null) {
            return;
        }

        router.post(
            route('cart.store'),
            { product_variant_id: variant.id, quantity },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (thenCheckout) {
                        router.visit(route('checkout.index'));
                    }
                },
            },
        );
    };

    const reviewForm = useForm({ rating: 5, title: '', comment: '' });

    return (
        <StorefrontLayout>
            <Head title={product.name} />

            <div className="product-detail default">
                <div className="featured-product underwear filter-product-img md:py-20 py-14">
                    <div className="container flex justify-between gap-y-6 flex-wrap">
                        <div className="list-img md:w-1/2 md:pe-[45px] w-full flex-shrink-0">
                            <div className="sticky top-24">
                                <div className="rounded-2xl overflow-hidden bg-surface">
                                    <img
                                        src={
                                            gallery[activeImage] ??
                                            gallery[0] ??
                                            '/storefront/images/generated/collection.svg'
                                        }
                                        alt={product.name}
                                        className="w-full h-full object-cover aspect-[3/4]"
                                    />
                                </div>
                                {gallery.length > 1 && (
                                    <div className="grid grid-cols-4 gap-3 mt-3">
                                        {gallery.map((image, index) => (
                                            <div
                                                key={image}
                                                className={`rounded-xl overflow-hidden cursor-pointer border ${
                                                    index === activeImage ? 'border-black' : 'border-line'
                                                }`}
                                                onClick={() => setActiveImage(index)}
                                            >
                                                <img
                                                    src={image}
                                                    alt=""
                                                    className="w-full h-full object-cover aspect-square"
                                                />
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                        <div className="product-item product-infor md:w-1/2 w-full lg:ps-[15px] md:ps-2">
                            <div className="flex justify-between">
                                <div>
                                    <div className="product-category caption2 text-secondary font-semibold uppercase">
                                        {product.categories.join(', ')}
                                    </div>
                                    <div className="product-name heading4 mt-1">{product.name}</div>
                                </div>
                                <div
                                    className={`add-wishlist-btn w-10 h-10 flex-shrink-0 flex items-center justify-center border border-line cursor-pointer rounded-lg duration-300 hover:bg-black hover:text-white ${
                                        inWishlist ? 'bg-black text-white' : ''
                                    }`}
                                    onClick={() =>
                                        auth.customer
                                            ? router.post(
                                                  route('wishlist.toggle'),
                                                  { product_id: product.id },
                                                  { preserveScroll: true },
                                              )
                                            : router.visit(route('login'))
                                    }
                                >
                                    <i className={`ph${inWishlist ? '-fill' : ''} ph-heart text-xl`}></i>
                                </div>
                            </div>
                            <div className="flex items-center gap-1 mt-3">
                                <Rate value={product.rating} />
                                <span className="caption1 text-secondary">
                                    ({t('product.reviewCount', { count: product.review_count })})
                                </span>
                            </div>
                            <div className="flex items-center gap-3 flex-wrap mt-5 pb-6 border-b border-line">
                                <div className="product-price heading5">{price(variant?.price ?? product.price)}</div>
                                {product.origin_price !== null && (
                                    <>
                                        <div className="w-px h-4 bg-line"></div>
                                        <div className="product-origin-price font-normal text-secondary2">
                                            <del>{price(product.origin_price)}</del>
                                        </div>
                                        <div className="product-sale caption2 font-semibold bg-green px-3 py-0.5 inline-block rounded-full">
                                            -{product.sale_percent}%
                                        </div>
                                    </>
                                )}
                                {product.short_description && (
                                    <div className="product-description text-secondary mt-3">
                                        {product.short_description}
                                    </div>
                                )}
                            </div>

                            <div className="list-action mt-6">
                                {hasColours && (
                                    <div className="choose-color">
                                        <div className="text-title">
                                            {t('product.colors')}:{' '}
                                            <span className="text-title color">{colour ?? ''}</span>
                                        </div>
                                        <div className="list-color flex items-center gap-2 flex-wrap mt-3">
                                            {product.colors.map((option) => (
                                                <div
                                                    key={option.name}
                                                    className={`color-item w-12 h-12 rounded-xl duration-300 relative cursor-pointer border ${
                                                        colour === option.name ? 'border-black border-2' : 'border-line'
                                                    }`}
                                                    style={{ backgroundColor: option.hex ?? 'transparent' }}
                                                    onClick={() => {
                                                        setColour(option.name);
                                                        // The gallery under this swatch may be a
                                                        // different set of photos — reset here rather
                                                        // than in an effect that watches `gallery`.
                                                        setActiveImage(0);
                                                    }}
                                                >
                                                    <div className="tag-action bg-black text-white caption2 capitalize px-1.5 py-0.5 rounded-sm">
                                                        {option.name}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {hasSizes && (
                                    <div className="choose-size mt-5">
                                        <div className="heading flex items-center justify-between">
                                            <div className="text-title">
                                                {t('product.size')}:{' '}
                                                <span className="text-title size">{size ?? ''}</span>
                                            </div>
                                            <button
                                                type="button"
                                                className="caption1 size-guide text-red underline"
                                                onClick={() => setSizeGuideOpen(true)}
                                            >
                                                {t('product.sizeGuide')}
                                            </button>
                                        </div>
                                        <div className="list-size flex items-center gap-2 flex-wrap mt-3">
                                            {product.sizes.map((option) => (
                                                <div
                                                    key={option}
                                                    className={`size-item text-button w-[44px] h-[44px] flex items-center justify-center rounded-full border border-line cursor-pointer ${
                                                        size === option ? 'active bg-black text-white' : ''
                                                    }`}
                                                    onClick={() => setSize(option)}
                                                >
                                                    {option}
                                                </div>
                                            ))}
                                        </div>
                                        {size !== null && (
                                            <div className="caption1 text-secondary mt-3">
                                                {sizeGuide !== null
                                                    ? t('product.sizeFitsWeight', { size, range: sizeGuide })
                                                    : t('common.notSpecified')}
                                            </div>
                                        )}
                                    </div>
                                )}

                                <div className="text-title mt-5">{t('product.quantityLabel')}</div>
                                <div className="choose-quantity flex items-center max-xl:flex-wrap lg:justify-between gap-5 mt-3">
                                    <div className="quantity-block md:p-3 max-md:py-1.5 max-md:px-3 flex items-center justify-between rounded-lg border border-line sm:w-[140px] w-[120px] flex-shrink-0">
                                        <i
                                            className="ph-bold ph-minus cursor-pointer body1"
                                            onClick={() => setQuantity((value) => Math.max(1, value - 1))}
                                        ></i>
                                        <div className="quantity body1 font-semibold">{quantity}</div>
                                        <i
                                            className="ph-bold ph-plus cursor-pointer body1"
                                            onClick={() => setQuantity((value) => value + 1)}
                                        ></i>
                                    </div>
                                    <button
                                        type="button"
                                        className="add-cart-btn button-main whitespace-nowrap w-full text-center bg-white text-black border border-black disabled:opacity-50"
                                        disabled={variant === null || outOfStock}
                                        onClick={() => addToCart(false)}
                                    >
                                        {outOfStock ? t('product.outOfStock') : t('product.addToCart')}
                                    </button>
                                </div>
                                {variant === null && (
                                    <div className="caption1 text-red mt-3">
                                        Choose{' '}
                                        {[hasColours ? 'a colour' : null, hasSizes ? 'a size' : null]
                                            .filter(Boolean)
                                            .join(' and ')}{' '}
                                        to continue.
                                    </div>
                                )}
                                <div className="button-block mt-5">
                                    <button
                                        type="button"
                                        className="button-main w-full text-center disabled:opacity-50"
                                        disabled={variant === null || outOfStock}
                                        onClick={() => addToCart(true)}
                                    >
                                        {t('product.buyNow')}
                                    </button>
                                </div>

                                <div className="more-infor mt-6">
                                    <div className="flex items-center gap-1 mt-3">
                                        <i className="ph ph-money body1"></i>
                                        <div className="text-title">{t('product.paymentLabel')}</div>
                                        <div className="text-secondary">{t('footer.codTitle')}</div>
                                    </div>
                                    <div className="flex items-center gap-1 mt-3">
                                        <div className="text-title">{t('product.skuLabel')}</div>
                                        <div className="text-secondary">{variant?.sku ?? product.sku}</div>
                                    </div>
                                    <div className="flex items-center gap-1 mt-3">
                                        <div className="text-title">{t('product.categoriesLabel')}</div>
                                        <div className="list-category text-secondary">
                                            {product.categories.join(', ')}
                                        </div>
                                    </div>
                                    {variant?.available !== null && variant !== null && (
                                        <div className="flex items-center gap-1 mt-3">
                                            <div className="text-title">{t('product.availabilityLabel')}</div>
                                            <div className="text-secondary">
                                                {variant.available > 0
                                                    ? t('product.inStock', { count: variant.available })
                                                    : t('product.outOfStock')}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="desc-tab md:pb-20 pb-10">
                    <div className="container">
                        <div className="flex items-center justify-center w-full">
                            <div className="menu-tab flex items-center md:gap-[60px] gap-8">
                                <div
                                    className={`tab-item heading5 has-line-before text-secondary2 hover:text-black duration-300 cursor-pointer ${
                                        tab === 'description' ? 'active text-black' : ''
                                    }`}
                                    onClick={() => setTab('description')}
                                >
                                    {t('product.tabDescription')}
                                </div>
                                <div
                                    className={`tab-item heading5 has-line-before text-secondary2 hover:text-black duration-300 cursor-pointer ${
                                        tab === 'reviews' ? 'active text-black' : ''
                                    }`}
                                    onClick={() => setTab('reviews')}
                                >
                                    {t('product.tabReviews', { count: reviews.length })}
                                </div>
                            </div>
                        </div>

                        {tab === 'description' && (
                            <div className="desc-block mt-8">
                                <div
                                    className="body1 text-secondary"
                                    dangerouslySetInnerHTML={{ __html: product.description ?? '' }}
                                />
                            </div>
                        )}

                        {tab === 'reviews' && (
                            <div className="review-block md:pt-8 pt-6">
                                <div className="list-review">
                                    {reviews.length === 0 && (
                                        <div className="caption1 text-secondary">{t('product.noReviews')}</div>
                                    )}
                                    {reviews.map((review) => (
                                        <div key={review.id} className="item flex gap-4 py-6 border-b border-line">
                                            <div className="w-12 h-12 rounded-full bg-surface flex items-center justify-center flex-shrink-0">
                                                <span className="ph ph-user text-2xl text-secondary"></span>
                                            </div>
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <div className="text-title">{review.author}</div>
                                                    {review.verified && (
                                                        <span className="caption2 bg-green px-2 py-0.5 rounded-full">
                                                            {t('product.verified')}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-2 mt-1">
                                                    <Rate value={review.rating} />
                                                    <span className="caption2 text-secondary">{review.created_at}</span>
                                                </div>
                                                {review.title && <div className="text-button mt-2">{review.title}</div>}
                                                {review.comment && (
                                                    <div className="body1 text-secondary mt-1">{review.comment}</div>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                {auth.customer && canReview && (
                                    <form
                                        className="form-review md:mt-10 mt-6"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            reviewForm.post(route('product.review', product.slug), {
                                                preserveScroll: true,
                                                onSuccess: () => reviewForm.reset(),
                                            });
                                        }}
                                    >
                                        <div className="heading5">{t('product.leaveReview')}</div>
                                        <div className="flex items-center gap-1 mt-3">
                                            {[1, 2, 3, 4, 5].map((star) => (
                                                <i
                                                    key={star}
                                                    className={`ph-fill ph-star text-2xl cursor-pointer ${
                                                        star <= reviewForm.data.rating
                                                            ? 'text-yellow'
                                                            : 'text-secondary2'
                                                    }`}
                                                    onClick={() => reviewForm.setData('rating', star)}
                                                ></i>
                                            ))}
                                        </div>
                                        <input
                                            className="border-line px-4 py-3 w-full rounded-lg mt-4"
                                            type="text"
                                            placeholder={t('product.reviewTitlePlaceholder')}
                                            value={reviewForm.data.title}
                                            onChange={(event) => reviewForm.setData('title', event.target.value)}
                                        />
                                        <textarea
                                            className="border-line px-4 py-3 w-full rounded-lg mt-4"
                                            rows={4}
                                            placeholder={t('product.reviewBodyPlaceholder')}
                                            value={reviewForm.data.comment}
                                            onChange={(event) => reviewForm.setData('comment', event.target.value)}
                                        />
                                        <button
                                            type="submit"
                                            className="button-main mt-4"
                                            disabled={reviewForm.processing}
                                        >
                                            {t('product.submitReview')}
                                        </button>
                                        <div className="caption1 text-secondary mt-2">{t('product.reviewPending')}</div>
                                    </form>
                                )}
                                {!auth.customer && (
                                    <div className="caption1 text-secondary md:mt-10 mt-6">
                                        <Link href={route('login')} className="text-black underline">
                                            {t('product.signIn')}
                                        </Link>{' '}
                                        {t('product.toLeaveReview')}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {related.length > 0 && (
                    <div className="related-product md:pb-20 pb-10">
                        <div className="container">
                            <div className="heading3 text-center">{t('product.related')}</div>
                            <div className="list-product hide-product-sold grid xl:grid-cols-4 sm:grid-cols-3 grid-cols-2 md:gap-[30px] gap-4 md:mt-10 mt-6">
                                {related.map((item) => (
                                    <ProductCard key={item.id} product={item} />
                                ))}
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Size Guide — the template's static table replaced by the
                variants' own size-guide weight ranges (Section 06, Q1). */}
            <div className="modal-sizeguide-block" onClick={() => setSizeGuideOpen(false)}>
                <div
                    className={`modal-sizeguide-main p-10 rounded-[32px] ${sizeGuideOpen ? 'open' : ''}`}
                    onClick={(event) => event.stopPropagation()}
                >
                    <div className="heading5">{t('product.sizeGuide')}</div>
                    <div className="caption1 text-secondary mt-2">{t('product.sizeGuideBody')}</div>
                    <table className="w-full mt-5">
                        <thead className="border-b border-line">
                            <tr>
                                <th className="pb-3 text-start text-sm font-bold uppercase text-secondary">
                                    {t('geo.size')}
                                </th>
                                <th className="pb-3 text-start text-sm font-bold uppercase text-secondary">
                                    {t('product.weightRange')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {sizeGuideRows.map((row) => (
                                <tr key={row.label} className="border-b border-line">
                                    <td className="py-3 text-title">{row.label}</td>
                                    <td className="py-3 text-secondary">{row.range ?? t('common.notSpecified')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </StorefrontLayout>
    );
}
