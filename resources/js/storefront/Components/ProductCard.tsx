import { Link, router, usePage } from '@inertiajs/react';
import { price } from '../lib/format';
import Rate from './Rate';
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
    const image = product.images[0] ?? '/storefront/images/generated/collection.svg';
    const hoverImage = product.images[1] ?? image;

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
            <Link href={route('product.show', product.slug)} className="product-main cursor-pointer block">
                <div className="product-thumb bg-white relative overflow-hidden rounded-2xl">
                    {product.is_new && (
                        <div className="product-tag text-button-uppercase bg-green px-3 py-0.5 inline-block rounded-full absolute top-3 left-3 z-[1]">
                            New
                        </div>
                    )}
                    {!product.is_new && product.sale_percent > 0 && (
                        <div className="product-tag text-button-uppercase text-white bg-red px-3 py-0.5 inline-block rounded-full absolute top-3 left-3 z-[1]">
                            Sale
                        </div>
                    )}
                    {!product.in_stock && (
                        <div className="product-tag text-button-uppercase bg-surface px-3 py-0.5 inline-block rounded-full absolute top-3 left-3 z-[1]">
                            Out of stock
                        </div>
                    )}
                    <div className="list-action-right absolute top-3 right-3 max-lg:hidden">
                        <div
                            className="add-wishlist-btn w-[32px] h-[32px] flex items-center justify-center rounded-full bg-white duration-300 relative"
                            onClick={toggleWishlist}
                        >
                            <div className="tag-action bg-black text-white caption2 px-1.5 py-0.5 rounded-sm">
                                Add To Wishlist
                            </div>
                            <i className="ph ph-heart text-lg"></i>
                        </div>
                    </div>
                    <div className="product-img w-full h-full aspect-[3/4]">
                        <img className="w-full h-full object-cover duration-700" src={image} alt={product.name} />
                        <img className="w-full h-full object-cover duration-700" src={hoverImage} alt={product.name} />
                    </div>
                    <div className="list-action grid grid-cols-1 gap-3 px-5 absolute w-full bottom-5 max-lg:hidden">
                        <div className="quick-view-btn w-full text-button-uppercase py-2 text-center rounded-full duration-300 bg-white hover:bg-black hover:text-white">
                            View product
                        </div>
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
                        <div className="list-color py-2 max-md:hidden flex items-center gap-3 flex-wrap duration-500">
                            {product.colors.map((color) => (
                                <div
                                    key={color.name}
                                    className="color-item w-8 h-8 rounded-full duration-300 relative border border-line"
                                    style={{ backgroundColor: color.hex ?? 'transparent' }}
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
