import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Rate from './Rate';
import { useTranslation } from '../lib/useTranslation';
import type { ProductCardData, SharedProps } from '../types';

/**
 * Anvogue's `product-item grid-type` card, from the markup shop.js
 * generates for every listing page — ported to React so the same card
 * renders on the shop, search, wishlist, related-products and homepage
 * rails.
 *
 * Two of the template's three hover actions are dropped: Compare (Q3 —
 * dropped for v1) and Quick View (a modal over demo JSON data, with no
 * server-side equivalent built this phase). Add-To-Wishlist is real, and
 * the card links through to the product page for variant selection
 * rather than adding to cart blind — the cart is variant-level, and a
 * card can't know which size/colour the customer wants.
 */
export default function ProductCard({ product }: { product: ProductCardData }) {
    const { auth } = usePage<SharedProps>().props;
    const { t, price } = useTranslation();
    // Clicking a swatch swaps the photos in place (same rule as the product
    // page: that colour's tagged images, or every image if none are tagged)
    // instead of following the card's link.
    const [colour, setColour] = useState<string | null>(null);
    const tagged = colour === null ? [] : (product.images_by_color[colour] ?? []);
    const images = tagged.length > 0 ? tagged : product.images;
    const colourId = product.colors.find((item) => item.name === colour)?.id;
    const image = images[0] ?? '/storefront/images/generated/collection.svg';
    const hoverImage = images[1] ?? image;

    const pickColour = (event: React.SyntheticEvent, name: string) => {
        event.preventDefault();
        event.stopPropagation();
        setColour((current) => (current === name ? null : name));
    };

    const toggleWishlist = (event: React.MouseEvent) => {
        event.preventDefault();
        event.stopPropagation();

        if (!auth.customer) {
            router.visit(route('login'));

            return;
        }

        router.post(route('wishlist.toggle'), { product_id: product.id }, { preserveScroll: true });
    };

    return (
        <div className="product-item grid-type">
            <Link
                // A picked swatch travels to the product page as ?color=<attribute
                // value id> (ids, unlike the translated names, survive a language
                // switch), so the page opens on the colour the customer chose.
                href={route('product.show', {
                    slug: product.slug,
                    sku: product.sku,
                    ...(colourId !== undefined && { color: colourId }),
                })}
                className="product-main cursor-pointer block"
            >
                <div className="product-thumb bg-white relative overflow-hidden rounded-2xl">
                    {product.is_new && (
                        <div className="product-tag text-button-uppercase bg-green px-3 py-0.5 inline-block rounded-full absolute top-3 start-3 z-[1]">
                            {t('product.tagNew')}
                        </div>
                    )}
                    {!product.is_new && product.sale_percent > 0 && (
                        <div className="product-tag text-button-uppercase text-white bg-red px-3 py-0.5 inline-block rounded-full absolute top-3 start-3 z-[1]">
                            {t('product.tagSale')}
                        </div>
                    )}
                    {!product.in_stock && (
                        <div className="product-tag text-button-uppercase bg-surface px-3 py-0.5 inline-block rounded-full absolute top-3 start-3 z-[1]">
                            {t('product.outOfStock')}
                        </div>
                    )}
                    <div className="list-action-right absolute top-3 end-3 max-lg:hidden">
                        <div
                            className="add-wishlist-btn w-[32px] h-[32px] flex items-center justify-center rounded-full bg-white duration-300 relative"
                            onClick={toggleWishlist}
                        >
                            <div className="tag-action bg-black text-white caption2 px-1.5 py-0.5 rounded-sm">
                                {t('product.addToWishlist')}
                            </div>
                            <i className="ph ph-heart text-lg"></i>
                        </div>
                    </div>
                    <div className="product-img w-full h-full aspect-[3/4]">
                        <img className="w-full h-full object-cover duration-700" src={image} alt={product.name} />
                        <img className="w-full h-full object-cover duration-700" src={hoverImage} alt={product.name} />
                    </div>
                </div>
                <div className="product-infor mt-4 lg:mb-7">
                    <div className="product-name text-title duration-300">{product.name}</div>
                    {product.review_count > 0 && (
                        <div className="flex items-center gap-1 mt-1">
                            <Rate value={product.rating} />
                            <span className="caption2 text-secondary">({product.review_count})</span>
                        </div>
                    )}
                    {product.colors.length > 0 && (
                        <div className="list-color py-2 flex items-center md:gap-3 gap-2 flex-wrap duration-500">
                            {product.colors.map((color) => (
                                <div
                                    key={color.name}
                                    role="button"
                                    tabIndex={0}
                                    aria-label={color.name}
                                    aria-pressed={colour === color.name}
                                    className={`color-item md:w-8 md:h-8 w-6 h-6 rounded-full duration-300 relative border border-line cursor-pointer ${colour === color.name ? 'active' : ''}`}
                                    style={{ backgroundColor: color.hex ?? 'transparent' }}
                                    onClick={(event) => pickColour(event, color.name)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            pickColour(event, color.name);
                                        }
                                    }}
                                >
                                    <div className="tag-action bg-black text-white caption2 capitalize px-1.5 py-0.5 rounded-sm">
                                        {color.name}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="product-price-block flex items-center gap-2 flex-wrap mt-1 duration-300 relative z-[1]">
                        <div className="product-price text-title">{price(product.price)}</div>
                        {product.origin_price !== null && (
                            <>
                                <div className="product-origin-price caption1 text-secondary2">
                                    <del>{price(product.origin_price)}</del>
                                </div>
                                <div className="product-sale caption1 font-medium bg-green px-3 py-0.5 inline-block rounded-full">
                                    -{product.sale_percent}%
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </Link>
        </div>
    );
}
