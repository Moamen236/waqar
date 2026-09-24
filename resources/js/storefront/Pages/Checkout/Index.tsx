import { Head, Link, router, useForm } from '@inertiajs/react';
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
    city_id: number | null;
    district_id: number | null;
    area_id: number | null;
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
    customer: { name: string; email: string | null; phone: string } | null;
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
    // The quote is only valid for the address *and* the cart contents it
    // was priced from: removing a line reloads `cart`, and without the
    // lines in the key the old quote (old subtotal/total) kept showing.
    const quoteKey = JSON.stringify([geo, cart.items.map((item) => [item.id, item.quantity])]);
    // Only the governorate is required; the resolver falls back to it
    // when city/area are left blank.
    const complete = geo.governorate_id !== null;
    const view = quote !== null && quote.key === quoteKey ? quote.summary : cart;

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
            .then((summary: CartSummary) => setQuote({ key: quoteKey, summary }))
            .catch(() => undefined);

        return () => controller.abort();
    }, [geoKey, quoteKey, complete]);

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
        form.clearErrors();
    };

    // Every field shows its own server error right under it, and editing a
    // field clears its message.
    type TextField = 'email' | 'name' | 'phone' | 'address_line';
    const setField = (field: TextField, value: string) => {
        form.setData(field, value);
        form.clearErrors(field);
    };
    const fieldProps = (field: TextField) => ({
        id: `checkout-${field}`,
        'aria-invalid': form.errors[field] ? true : undefined,
        'aria-describedby': form.errors[field] ? `checkout-${field}-error` : undefined,
        className: `border px-4 py-3 w-full rounded-lg ${form.errors[field] ? 'border-red' : 'border-line'}`,
    });
    const fieldError = (field: TextField) =>
        form.errors[field] ? (
            <div id={`checkout-${field}-error`} className="caption1 text-red mt-1">
                {form.errors[field]}
            </div>
        ) : null;

    // After a rejected submit, bring the first invalid field into view: the
    // button sits far below the contact fields, so their errors would
    // otherwise be off-screen.
    const fieldOrder = [
        ['email', 'checkout-email'],
        ['name', 'checkout-name'],
        ['phone', 'checkout-phone'],
        ['governorate_id', 'checkout-governorate'],
        ['city_id', 'checkout-city'],
        ['district_id', 'checkout-district'],
        ['area_id', 'checkout-area'],
        ['address_line', 'checkout-address_line'],
    ];
    const focusFirstError = (errors: Record<string, string>) => {
        const id = fieldOrder.find(([field]) => field in errors)?.[1];
        const element = id ? document.getElementById(id) : null;
        element?.scrollIntoView({ block: 'center' });
        element?.focus({ preventScroll: true });
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
                                    form.post(route('checkout.store'), {
                                        preserveScroll: true,
                                        onError: focusFirstError,
                                    });
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
                                            {...fieldProps('email')}
                                            placeholder={t('checkout.emailPlaceholder')}
                                            value={form.data.email ?? ''}
                                            onChange={(event) => setField('email', event.target.value)}
                                        />
                                        {fieldError('email')}
                                    </div>
                                    <div>
                                        <input
                                            type="text"
                                            {...fieldProps('name')}
                                            placeholder={t('checkout.namePlaceholder')}
                                            value={form.data.name}
                                            onChange={(event) => setField('name', event.target.value)}
                                            required
                                        />
                                        {fieldError('name')}
                                    </div>
                                    <div>
                                        <input
                                            type="text"
                                            inputMode="numeric"
                                            maxLength={11}
                                            {...fieldProps('phone')}
                                            placeholder={t('checkout.phonePlaceholder')}
                                            value={form.data.phone}
                                            onChange={(event) => setField('phone', event.target.value)}
                                            required
                                        />
                                        {fieldError('phone')}
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
                                                onChange={(next) => {
                                                    form.setData((data) => ({ ...data, ...next }));
                                                    form.clearErrors(
                                                        'governorate_id',
                                                        'city_id',
                                                        'district_id',
                                                        'area_id',
                                                    );
                                                }}
                                                idPrefix="checkout"
                                                optionalBelowGovernorate
                                                errors={form.errors}
                                            />
                                            <div className="col-span-full">
                                                <label htmlFor="checkout-address_line" className="caption1 capitalize">
                                                    {t('address.street')} <span className="text-red">*</span>
                                                </label>
                                                <div className="mt-2">
                                                    <input
                                                        type="text"
                                                        {...fieldProps('address_line')}
                                                        placeholder={t('checkout.addressPlaceholder')}
                                                        value={form.data.address_line}
                                                        onChange={(event) =>
                                                            setField('address_line', event.target.value)
                                                        }
                                                        required
                                                    />
                                                    {fieldError('address_line')}
                                                </div>
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
                                    <div key={item.id} className="item flex items-start justify-between gap-5">
                                        <div className="flex items-start gap-5 min-w-0">
                                            <div className="bg_img relative flex-shrink-0 w-[92px] aspect-[4/5]">
                                                <img
                                                    src={item.image ?? '/storefront/images/generated/collection.svg'}
                                                    alt={item.name}
                                                    className="w-full h-full object-cover rounded-lg"
                                                />
                                                <span className="quantity flex items-center justify-center absolute -top-3 -end-3 w-7 h-7 rounded-full bg-black text-white">
                                                    {item.quantity}
                                                </span>
                                            </div>
                                            <div className="min-w-0">
                                                <Link
                                                    href={route('product.show', {
                                                        slug: item.slug,
                                                        sku: item.product_sku,
                                                    })}
                                                    className="name text-title hover:underline"
                                                >
                                                    {item.name}
                                                </Link>
                                                {/* What was actually chosen — colour (with its
                                                    swatch) and size — so the customer can check
                                                    each line before placing a cash-on-delivery order. */}
                                                {item.option_values.length > 0 && (
                                                    <dl className="mt-2 flex flex-col gap-1 caption1">
                                                        {item.option_values.map((option) => (
                                                            <div
                                                                key={option.attribute}
                                                                className="flex items-center gap-2"
                                                            >
                                                                <dt className="text-secondary">
                                                                    {option.attribute_label}:
                                                                </dt>
                                                                <dd className="flex items-center gap-1.5 text-title">
                                                                    {option.hex && (
                                                                        <span
                                                                            aria-hidden="true"
                                                                            className="inline-block w-3.5 h-3.5 rounded-full border border-line"
                                                                            style={{ backgroundColor: option.hex }}
                                                                        />
                                                                    )}
                                                                    {option.value}
                                                                </dd>
                                                            </div>
                                                        ))}
                                                    </dl>
                                                )}
                                                <div className="flex items-center gap-2 mt-2 caption2 text-secondary">
                                                    <span className="ph ph-tag" aria-hidden="true"></span>
                                                    <span className="code">{item.sku}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex flex-col items-end gap-1 flex-shrink-0">
                                            {item.quantity > 1 && (
                                                <span className="caption2 text-secondary">
                                                    {item.quantity} × {price(item.unit_price)}
                                                </span>
                                            )}
                                            <strong className="text-title price">{price(item.subtotal)}</strong>
                                            {/* Same control and route as the cart page — a customer
                                                who changes their mind here shouldn't have to go back
                                                to the cart to drop a line. */}
                                            <button
                                                type="button"
                                                aria-label={t('common.remove')}
                                                title={t('common.remove')}
                                                className="flex items-center justify-center w-8 h-8 rounded-full text-secondary hover:text-red hover:bg-white duration-300"
                                                onClick={() =>
                                                    router.delete(route('cart.destroy', item.id), {
                                                        preserveScroll: true,
                                                    })
                                                }
                                            >
                                                <i className="ph ph-trash text-xl" aria-hidden="true"></i>
                                            </button>
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
