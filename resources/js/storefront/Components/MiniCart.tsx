import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { price } from '../lib/format';
import type { CartSummary, SharedProps } from '../types';

/**
 * Anvogue's `modal-cart-block` mini-cart. The template's left-hand "You
 * May Also Like" panel is dropped (it renders Quick-View cards over demo
 * JSON, and Quick View isn't built), as are its Note / Shipping / Coupon
 * accordions — shipping is resolved from the real geo cascade on the
 * cart and checkout pages, not estimated in a drawer, and the cart page
 * owns coupon entry.
 *
 * Contents are fetched on open rather than shared on every page render,
 * so the header badge (which is shared) stays the only per-request cost.
 */
export default function MiniCart({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { storefront } = usePage<SharedProps>().props;
    const [cart, setCart] = useState<CartSummary | null>(null);
    const count = storefront?.cartCount ?? 0;

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        fetch(route('cart.summary'), {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((summary: CartSummary) => setCart(summary))
            .catch(() => undefined);

        return () => controller.abort();
    }, [open, count]);

    return (
        <div className="modal-cart-block" onClick={onClose}>
            <div className={`modal-cart-main flex ${open ? 'open' : ''}`} onClick={(event) => event.stopPropagation()}>
                <div className="right cart-block w-full py-6 relative overflow-hidden">
                    <div className="heading px-6 pb-3 flex items-center justify-between relative">
                        <div className="heading5">Shopping Cart</div>
                        <div
                            className="close-btn absolute end-6 top-0 w-6 h-6 rounded-full bg-surface flex items-center justify-center duration-300 cursor-pointer hover:bg-black hover:text-white"
                            onClick={onClose}
                        >
                            <i className="ph ph-x text-sm"></i>
                        </div>
                    </div>
                    <div className="list-product px-6">
                        {cart === null && <p className="mt-1 caption1 text-secondary">Loading…</p>}
                        {cart !== null && cart.items.length === 0 && <p className="mt-1">No product in cart</p>}
                        {cart?.items.map((item) => (
                            <div
                                key={item.id}
                                className="item py-5 flex items-center justify-between gap-3 border-b border-line"
                            >
                                <div className="infor flex items-center gap-3 w-full">
                                    <div className="bg-img w-[100px] aspect-square flex-shrink-0 rounded-lg overflow-hidden">
                                        <img
                                            src={item.image ?? '/storefront/images/generated/collection.svg'}
                                            alt={item.name}
                                            className="w-full h-full object-cover"
                                        />
                                    </div>
                                    <div className="w-full">
                                        <div className="flex items-center justify-between w-full">
                                            <div className="name text-button">{item.name}</div>
                                            <button
                                                type="button"
                                                className="remove-cart-btn remove-btn caption1 font-semibold text-red underline cursor-pointer"
                                                onClick={() =>
                                                    router.delete(route('cart.destroy', item.id), {
                                                        preserveScroll: true,
                                                        onSuccess: () => setCart(null),
                                                    })
                                                }
                                            >
                                                Remove
                                            </button>
                                        </div>
                                        <div className="flex items-center justify-between gap-2 mt-3 w-full">
                                            <div className="flex items-center text-secondary2 capitalize">
                                                {item.options || item.sku} × {item.quantity}
                                            </div>
                                            <div className="product-price text-title">{price(item.subtotal)}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="footer-modal bg-white absolute bottom-0 start-0 w-full">
                        <div className="flex items-center justify-between pt-6 px-6">
                            <div className="heading5">Subtotal</div>
                            <div className="heading5 total-cart">{price(cart?.subtotal ?? 0)}</div>
                        </div>
                        <div className="block-button text-center p-6">
                            <div className="flex items-center gap-4">
                                <Link
                                    href={route('cart.index')}
                                    className="button-main basis-1/2 bg-white border border-black text-black text-center uppercase"
                                >
                                    View cart
                                </Link>
                                <Link
                                    href={route('checkout.index')}
                                    className="button-main basis-1/2 text-center uppercase"
                                >
                                    Check Out
                                </Link>
                            </div>
                            <div
                                className="text-button-uppercase continue mt-4 text-center has-line-before cursor-pointer inline-block"
                                onClick={onClose}
                            >
                                Or continue shopping
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
