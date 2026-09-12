import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Breadcrumb from '../../Components/Breadcrumb';
import GeoCascade from '../../Components/GeoCascade';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { CartSummary, GeoCountry, GeoSelection } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface Voucher {
    code: string;
    type: string;
    value: string | number;
    minimum_order_amount: string | number | null;
}

/**
 * Cart — Anvogue's cart.html. Quantity/remove/coupon UI is reused as-is;
 * the template's free-text "shipping estimator" accordion is replaced by
 * the real geo cascade, which asks the server for the resolved rate
 * (Section 08's resolution, Section 11's Area → District → City →
 * Governorate fallback). The countdown "your cart will expire" banner is
 * dropped — carts don't expire in this system, and a fake timer would be
 * a lie to the customer.
 */
export default function CartIndex({
    cart,
    countries,
    vouchers,
}: {
    cart: CartSummary;
    countries: GeoCountry[];
    vouchers: Voucher[];
}) {
    const [code, setCode] = useState('');
    const { t, price } = useTranslation();
    const [geo, setGeo] = useState<GeoSelection>({
        country_id: null,
        governorate_id: null,
        city_id: null,
        district_id: null,
        area_id: null,
    });
    const [quote, setQuote] = useState<CartSummary | null>(null);
    const [quoting, setQuoting] = useState(false);

    const view = quote ?? cart;

    // country_id never leaves the browser — the governorate already
    // implies it, and the quote endpoint validates only the four levels
    // orders actually store (Section 24).
    const quotePayload = {
        governorate_id: geo.governorate_id,
        city_id: geo.city_id,
        district_id: geo.district_id,
        area_id: geo.area_id,
    };

    const requestQuote = async () => {
        if (geo.governorate_id === null || geo.city_id === null || geo.area_id === null) {
            return;
        }

        setQuoting(true);
        try {
            const response = await fetch(route('api.shipping.quote'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(quotePayload),
            });
            setQuote((await response.json()) as CartSummary);
        } finally {
            setQuoting(false);
        }
    };

    return (
        <StorefrontLayout>
            <Head title={t('cart.title')} />
            <Breadcrumb title={t('cart.title')} />

            <div className="cart-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main flex justify-between max-xl:flex-col gap-y-8">
                        <div className="xl:w-2/3 xl:pe-3 w-full">
                            <div className="list-product w-full sm:mt-7 mt-5">
                                <div className="w-full">
                                    <div className="heading bg-surface bora-4 pt-4 pb-4">
                                        <div className="flex">
                                            <div className="w-1/2">
                                                <div className="text-button text-center">{t('cart.colProducts')}</div>
                                            </div>
                                            <div className="w-1/12">
                                                <div className="text-button text-center">{t('cart.colPrice')}</div>
                                            </div>
                                            <div className="w-1/6">
                                                <div className="text-button text-center">{t('cart.colQuantity')}</div>
                                            </div>
                                            <div className="w-1/6">
                                                <div className="text-button text-center">{t('cart.colTotal')}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="list-product-main w-full mt-3">
                                        {cart.items.length === 0 && (
                                            <div className="caption1 text-secondary text-center py-10">
                                                {t('cart.empty')}{' '}
                                                <Link href={route('shop.index')} className="text-black underline">
                                                    {t('cart.startShopping')}
                                                </Link>
                                            </div>
                                        )}
                                        {cart.items.map((item) => (
                                            <div
                                                key={item.id}
                                                className="item flex md:mt-7 md:pb-7 mt-5 pb-5 border-b border-line w-full"
                                            >
                                                <div className="w-1/2">
                                                    <div className="flex items-center gap-6">
                                                        <div className="bg-img md:w-[100px] w-20 aspect-[3/4]">
                                                            <img
                                                                src={
                                                                    item.image ??
                                                                    '/storefront/images/generated/collection.svg'
                                                                }
                                                                alt={item.name}
                                                                className="w-full h-full object-cover rounded-lg"
                                                            />
                                                        </div>
                                                        <div>
                                                            <Link
                                                                href={route('product.show', item.slug)}
                                                                className="text-title"
                                                            >
                                                                {item.name}
                                                            </Link>
                                                            <div className="list-select mt-1 caption1 text-secondary">
                                                                {item.options || item.sku}
                                                            </div>
                                                            {item.available !== null &&
                                                                item.available < item.quantity && (
                                                                    <div className="caption1 text-red mt-1">
                                                                        {t('cart.onlyLeft', { count: item.available })}
                                                                    </div>
                                                                )}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="w-1/12 price flex items-center justify-center">
                                                    <div className="text-title text-center">
                                                        {price(item.unit_price)}
                                                    </div>
                                                </div>
                                                <div className="w-1/6 flex items-center justify-center">
                                                    <div className="quantity-block bg-surface md:p-3 p-2 flex items-center justify-between rounded-lg border border-line md:w-[100px] flex-shrink-0 w-20">
                                                        <i
                                                            className="ph-bold ph-minus cursor-pointer text-base max-md:text-sm"
                                                            onClick={() =>
                                                                router.patch(
                                                                    route('cart.update', item.id),
                                                                    { quantity: item.quantity - 1 },
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        ></i>
                                                        <div className="text-button quantity">{item.quantity}</div>
                                                        <i
                                                            className="ph-bold ph-plus cursor-pointer text-base max-md:text-sm"
                                                            onClick={() =>
                                                                router.patch(
                                                                    route('cart.update', item.id),
                                                                    { quantity: item.quantity + 1 },
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        ></i>
                                                    </div>
                                                </div>
                                                <div className="w-1/6 flex total-price items-center justify-center">
                                                    <div className="text-title text-center">{price(item.subtotal)}</div>
                                                </div>
                                                <div className="w-1/12 flex items-center justify-center">
                                                    <i
                                                        className="remove-btn ph ph-x-circle text-xl max-md:text-base text-red cursor-pointer hover:text-black duration-300"
                                                        onClick={() =>
                                                            router.delete(route('cart.destroy', item.id), {
                                                                preserveScroll: true,
                                                            })
                                                        }
                                                    ></i>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            <div className="input-block discount-code w-full h-12 sm:mt-7 mt-5">
                                <form
                                    className="w-full h-full relative"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        router.post(route('cart.coupon.apply'), { code }, { preserveScroll: true });
                                    }}
                                >
                                    <input
                                        type="text"
                                        value={code}
                                        onChange={(event) => setCode(event.target.value)}
                                        placeholder={t('cart.voucherPlaceholder')}
                                        className="w-full h-full bg-surface ps-4 pe-14 rounded-lg border border-line"
                                        required
                                    />
                                    <button
                                        type="submit"
                                        className="button-main absolute top-1 bottom-1 end-1 px-5 rounded-lg flex items-center justify-center"
                                    >
                                        {t('cart.applyCode')}
                                    </button>
                                </form>
                            </div>

                            {vouchers.length > 0 && (
                                <div className="list-voucher flex items-center gap-5 flex-wrap sm:mt-7 mt-5">
                                    {vouchers.map((voucher) => (
                                        <div key={voucher.code} className="item border border-line rounded-lg py-2">
                                            <div className="top flex gap-10 justify-between px-3 pb-2 border-b border-dashed border-line">
                                                <div className="left">
                                                    <div className="caption1">{t('cart.discounts')}</div>
                                                    <div className="caption1 font-bold">
                                                        {voucher.type === 'percentage'
                                                            ? `${Number(voucher.value)}% OFF`
                                                            : voucher.type === 'free_shipping'
                                                              ? 'FREE SHIPPING'
                                                              : `${price(Number(voucher.value))} OFF`}
                                                    </div>
                                                </div>
                                                <div className="right">
                                                    <div className="caption1">
                                                        {voucher.minimum_order_amount
                                                            ? `For orders from ${price(Number(voucher.minimum_order_amount))}`
                                                            : 'For all orders'}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="bottom gap-6 items-center flex justify-between px-3 pt-2">
                                                <div className="text-button-uppercase">Code: {voucher.code}</div>
                                                <button
                                                    type="button"
                                                    className="button-main py-1 px-2.5 capitalize text-xs"
                                                    onClick={() =>
                                                        router.post(
                                                            route('cart.coupon.apply'),
                                                            { code: voucher.code },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    {t('cart.applyCode')}
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="xl:w-1/3 xl:ps-12 w-full">
                            <div className="checkout-block bg-surface p-6 rounded-2xl">
                                <div className="heading5">{t('cart.orderSummary')}</div>
                                <div className="total-block py-5 flex justify-between border-b border-line">
                                    <div className="text-title">{t('cart.subtotal')}</div>
                                    <div className="text-title">{price(view.subtotal)}</div>
                                </div>
                                <div className="discount-block py-5 flex justify-between border-b border-line">
                                    <div className="text-title">
                                        Discounts
                                        {view.coupon && (
                                            <button
                                                type="button"
                                                className="caption1 text-red underline ms-2"
                                                onClick={() =>
                                                    router.delete(route('cart.coupon.remove'), { preserveScroll: true })
                                                }
                                            >
                                                {t('common.remove')}
                                            </button>
                                        )}
                                    </div>
                                    <div className="text-title">-{price(view.discount)}</div>
                                </div>

                                <div className="ship-block py-5 border-b border-line">
                                    <div className="flex justify-between">
                                        <div className="text-title">{t('cart.shipping')}</div>
                                        <div className="text-title">
                                            {view.shipping === null ? t('cart.enterAddress') : price(view.shipping)}
                                        </div>
                                    </div>
                                    <div className="caption1 text-secondary mt-2">{t('cart.shippingNote')}</div>
                                    <div className="grid gap-3 mt-4">
                                        <GeoCascade
                                            countries={countries}
                                            value={geo}
                                            onChange={setGeo}
                                            idPrefix="cart"
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        className="button-main w-full text-center mt-4 py-2 disabled:opacity-50"
                                        disabled={
                                            quoting ||
                                            geo.governorate_id === null ||
                                            geo.city_id === null ||
                                            geo.area_id === null
                                        }
                                        onClick={requestQuote}
                                    >
                                        {quoting ? t('cart.calculating') : t('cart.calculateShipping')}
                                    </button>
                                    {quote !== null && quote.shipping === null && (
                                        <div className="caption1 text-red mt-3">{t('cart.noRate')}</div>
                                    )}
                                </div>

                                <div className="total-cart-block pt-4 pb-4 flex justify-between">
                                    <div className="heading5">{t('cart.total')}</div>
                                    <div className="heading5">
                                        {view.total === null ? price(view.subtotal - view.discount) : price(view.total)}
                                    </div>
                                </div>
                                <div className="block-button flex flex-col items-center gap-y-4 mt-5">
                                    <Link
                                        href={route('checkout.index')}
                                        className="checkout-btn button-main text-center w-full"
                                    >
                                        {t('cart.proceedToCheckout')}
                                    </Link>
                                    <Link className="text-button hover-underline" href={route('shop.index')}>
                                        {t('cart.continueShopping')}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
