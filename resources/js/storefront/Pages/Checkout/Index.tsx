import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import GeoCascade from '../../Components/GeoCascade';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { CartSummary, GeoCountry, GeoSelection } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface SavedAddress {
    id: number;
    label: string;
    recipient_name: string;
    phone: string;
    governorate_id: number;
    city_id: number;
    district_id: number | null;
    area_id: number;
    address_line: string;
}

/**
 * Checkout — Anvogue's checkout2.html, rebuilt to the business rules
 * rather than wired as-is (Section 17 marks it "Needs Rebuild"):
 *
 *  - the Credit Card / PayPal / Apple Pay options and every card-number,
 *    expiry and CVV field are gone — Cash on Delivery is the only method
 *    and no cardholder data is ever collected (Q12);
 *  - "Pickup in store" is gone — no retail locations exist (Q4);
 *  - Country / State / City / Zip Code becomes the Governorate → City →
 *    District → Area cascade, with Postal Code dropped (Section 08/11).
 *
 * The order total shown here is the server's own recomputation, returned
 * by the shipping-quote endpoint; the form posts an address and nothing
 * about money, and CreateOrderAction prices the order again from stored
 * records regardless.
 */
export default function CheckoutIndex({
    cart,
    countries,
    customer,
    addresses,
}: {
    cart: CartSummary;
    countries: GeoCountry[];
    customer: { name: string; email: string; phone: string } | null;
    addresses: SavedAddress[];
}) {
    // Keyed by the address it was quoted for, so an address change simply
    // stops matching (falling back to the un-shipped cart) instead of
    // needing a setState() inside the effect to reset it.
    const [quote, setQuote] = useState<{ key: string; summary: CartSummary } | null>(null);
    const { t, price } = useTranslation();

    const form = useForm({
        name: customer?.name ?? '',
        email: customer?.email ?? '',
        phone: customer?.phone ?? '',
        governorate_id: null as number | null,
        city_id: null as number | null,
        district_id: null as number | null,
        area_id: null as number | null,
        address_line: '',
        save_address: false,
    });

    const geo: GeoSelection = {
        governorate_id: form.data.governorate_id,
        city_id: form.data.city_id,
        district_id: form.data.district_id,
        area_id: form.data.area_id,
    };

    const geoKey = JSON.stringify(geo);
    const complete = geo.governorate_id !== null && geo.city_id !== null && geo.area_id !== null;
    const view = quote !== null && quote.key === geoKey ? quote.summary : cart;

    // Re-quote whenever the address is complete enough for
    // ShippingRateResolver to have something to match on.
    useEffect(() => {
        if (!complete) {
            return;
        }

        const controller = new AbortController();

        fetch(route('api.shipping.quote'), {
            method: 'POST',
            signal: controller.signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
            body: geoKey,
        })
            .then((response) => response.json())
            .then((summary: CartSummary) => setQuote({ key: geoKey, summary }))
            .catch(() => undefined);

        return () => controller.abort();
    }, [geoKey, complete]);

    const applySavedAddress = (address: SavedAddress) => {
        form.setData((data) => ({
            ...data,
            name: address.recipient_name,
            phone: address.phone,
            governorate_id: address.governorate_id,
            city_id: address.city_id,
            district_id: address.district_id,
            area_id: address.area_id,
            address_line: address.address_line,
        }));
    };

    return (
        <StorefrontLayout>
            <Head title={t('checkout.title')} />

            <div className="checkout-block relative md:pt-10 pt-6">
                <div className="content-main flex max-lg:flex-col-reverse justify-between">
                    <div className="left flex lg:justify-end w-full">
                        <div className="lg:max-w-[716px] flex-shrink-0 w-full lg:pt-20 pt-12 lg:pe-[70px] ps-[16px] max-lg:pe-[16px]">
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(route('checkout.store'));
                                }}
                            >
                                <div className="login flex justify-between gap-4">
                                    <h4 className="heading4">{t('checkout.contact')}</h4>
                                    {!customer && (
                                        <Link href={route('login')} className="text-button underline">
                                            {t('checkout.loginHere')}
                                        </Link>
                                    )}
                                </div>
                                <div className="grid sm:grid-cols-2 gap-4 gap-y-5 mt-5">
                                    <div className="col-span-full">
                                        <input
                                            type="email"
                                            className="border-line px-4 py-3 w-full rounded-lg"
                                            placeholder={t('checkout.emailPlaceholder')}
                                            value={form.data.email}
                                            onChange={(event) => form.setData('email', event.target.value)}
                                            required
                                        />
                                        {form.errors.email && (
                                            <div className="caption1 text-red mt-1">{form.errors.email}</div>
                                        )}
                                    </div>
                                    <div>
                                        <input
                                            type="text"
                                            className="border-line px-4 py-3 w-full rounded-lg"
                                            placeholder={t('checkout.namePlaceholder')}
                                            value={form.data.name}
                                            onChange={(event) => form.setData('name', event.target.value)}
                                            required
                                        />
                                        {form.errors.name && (
                                            <div className="caption1 text-red mt-1">{form.errors.name}</div>
                                        )}
                                    </div>
                                    <div>
                                        <input
                                            type="text"
                                            className="border-line px-4 py-3 w-full rounded-lg"
                                            placeholder={t('checkout.phonePlaceholder')}
                                            value={form.data.phone}
                                            onChange={(event) => form.setData('phone', event.target.value)}
                                            required
                                        />
                                        {form.errors.phone && (
                                            <div className="caption1 text-red mt-1">{form.errors.phone}</div>
                                        )}
                                    </div>
                                </div>

                                <div className="information md:mt-10 mt-6">
                                    <div className="heading5">{t('checkout.delivery')}</div>

                                    {addresses.length > 0 && (
                                        <div className="saved-addresses grid gap-3 mt-5">
                                            {addresses.map((address) => (
                                                <button
                                                    key={address.id}
                                                    type="button"
                                                    className="item flex items-center justify-between gap-3 px-5 py-4 border border-line rounded-lg text-start hover:border-black duration-300"
                                                    onClick={() => applySavedAddress(address)}
                                                >
                                                    <span>
                                                        <strong className="text-title">{address.label}</strong>
                                                        <span className="caption1 text-secondary block">
                                                            {address.address_line}
                                                        </span>
                                                    </span>
                                                    <span className="text-button-uppercase">{t('checkout.use')}</span>
                                                </button>
                                            ))}
                                        </div>
                                    )}

                                    <div className="form-checkout mt-5">
                                        <div className="grid sm:grid-cols-2 gap-4 gap-y-5 flex-wrap">
                                            <GeoCascade
                                                countries={countries}
                                                value={geo}
                                                onChange={(next) => form.setData((data) => ({ ...data, ...next }))}
                                                idPrefix="checkout"
                                            />
                                            <div className="col-span-full">
                                                <label htmlFor="address_line" className="caption1 capitalize">
                                                    {t('address.street')} <span className="text-red">*</span>
                                                </label>
                                                <input
                                                    id="address_line"
                                                    className="border-line px-4 py-3 w-full rounded-lg mt-2"
                                                    type="text"
                                                    placeholder={t('checkout.addressPlaceholder')}
                                                    value={form.data.address_line}
                                                    onChange={(event) =>
                                                        form.setData('address_line', event.target.value)
                                                    }
                                                    required
                                                />
                                            </div>
                                            {customer && (
                                                <div className="col-span-full flex items-center">
                                                    <div className="block-input">
                                                        <input
                                                            type="checkbox"
                                                            id="save_address"
                                                            checked={form.data.save_address}
                                                            onChange={(event) =>
                                                                form.setData('save_address', event.target.checked)
                                                            }
                                                        />
                                                        <i className="ph-fill ph-check-square icon-checkbox text-2xl"></i>
                                                    </div>
                                                    <label
                                                        htmlFor="save_address"
                                                        className="text-title ps-2 cursor-pointer"
                                                    >
                                                        {t('checkout.saveAddress')}
                                                    </label>
                                                </div>
                                            )}
                                        </div>

                                        <h4 className="heading4 md:mt-10 mt-6">{t('checkout.shippingMethod')}</h4>
                                        <div className="body1 text-secondary2 py-6 px-5 border border-line rounded-lg bg-surface mt-5">
                                            {view.shipping === null
                                                ? t('checkout.chooseArea')
                                                : t('checkout.standardDelivery', {
                                                      amount: price(view.shipping),
                                                  })}
                                        </div>

                                        <div className="payment-block md:mt-10 mt-6">
                                            <h4 className="heading4">{t('checkout.payment')}</h4>
                                            <p className="body1 text-secondary2 mt-3">{t('checkout.codNote')}</p>
                                            <div className="list-payment mt-5">
                                                <div className="item">
                                                    <div className="type flex items-center justify-between bg-linear p-5 border border-black rounded-lg">
                                                        <strong className="text-title">{t('checkout.cod')}</strong>
                                                        <span className="ph ph-money text-2xl"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="block-button md:mt-10 mt-6">
                                            <button
                                                type="submit"
                                                className="button-main w-full tracking-widest disabled:opacity-50"
                                                disabled={form.processing || view.shipping === null}
                                            >
                                                {form.processing ? t('checkout.placing') : t('checkout.placeOrder')}
                                            </button>
                                            {view.shipping === null && (
                                                <div className="caption1 text-secondary mt-3 text-center">
                                                    {t('checkout.pickAreaFirst')}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div className="right justify-start flex-shrink-0 lg:w-[47%] bg-surface lg:py-20 py-12">
                        <div className="lg:sticky lg:top-24 h-fit lg:max-w-[606px] w-full flex-shrink-0 lg:ps-[80px] pe-[16px] max-lg:ps-[16px]">
                            <div className="list_prd flex flex-col gap-7">
                                {cart.items.map((item) => (
                                    <div key={item.id} className="item flex items-center justify-between gap-6">
                                        <div className="flex items-center gap-6">
                                            <div className="bg_img relative flex-shrink-0 w-[100px] h-[100px]">
                                                <img
                                                    src={item.image ?? '/storefront/images/generated/collection.svg'}
                                                    alt={item.name}
                                                    className="w-full h-full object-cover rounded-lg"
                                                />
                                                <span className="quantity flex items-center justify-center absolute -top-3 -end-3 w-7 h-7 rounded-full bg-black text-white">
                                                    {item.quantity}
                                                </span>
                                            </div>
                                            <div>
                                                <strong className="name text-title">{item.name}</strong>
                                                <div className="flex items-center gap-2 mt-2">
                                                    <span className="ph ph-tag text-secondary"></span>
                                                    <span className="code text-secondary">{item.sku}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex flex-col gap-1">
                                            <strong className="text-title price">{price(item.subtotal)}</strong>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="subtotal flex items-center justify-between mt-8">
                                <strong className="heading6">{t('cart.subtotal')}</strong>
                                <strong className="heading6">{price(view.subtotal)}</strong>
                            </div>
                            {view.discount > 0 && (
                                <div className="ship-block flex items-center justify-between mt-4">
                                    <strong className="heading6">{t('cart.discounts')}</strong>
                                    <span className="body1 text-secondary">-{price(view.discount)}</span>
                                </div>
                            )}
                            <div className="ship-block flex items-center justify-between mt-4">
                                <strong className="heading6">{t('cart.shipping')}</strong>
                                <span className="body1 text-secondary">
                                    {view.shipping === null ? t('checkout.enterAddress') : price(view.shipping)}
                                </span>
                            </div>
                            <div className="total-cart-block flex items-center justify-between mt-4">
                                <strong className="heading4">{t('cart.total')}</strong>
                                <div className="flex items-end gap-2">
                                    <strong className="heading4">
                                        {view.total === null ? '—' : price(view.total)}
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
