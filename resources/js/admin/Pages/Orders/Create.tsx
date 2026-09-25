import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import AsyncSelect from 'react-select/async';
import FieldError from '../../Components/Form/FieldError';
import FormField, { RequiredMark } from '../../Components/Form/FormField';
import VariantPicker, { type PickerVariant } from '../../Components/VariantPicker';
import AdminLayout from '../../Layouts/AdminLayout';
import { fieldId, useClearServerErrorsOnChange } from '../../lib/formErrors';
import type { GeoTree, Warehouse } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

/** What admin.orders.quote answers with — a null shipping means no rate is configured. */
interface Quote {
    subtotal: number;
    discount: number;
    shipping: number | null;
    total: number;
    coupon_error: string | null;
}

interface SavedAddress {
    id: number;
    label: string | null;
    governorate_id: number;
    // Optional below the governorate — see the shipping card.
    city_id: number | null;
    district_id: number | null;
    area_id: number | null;
    address_line: string;
    is_default: boolean;
}

interface CustomerOption {
    id: number;
    name: string;
    phone: string;
    addresses: SavedAddress[];
}

interface FormValues {
    customer_id: number | null;
    new_customer: { name: string; phone: string } | null;
    warehouse_id: number | null;
    items: { product_variant_id: number | null; quantity: number }[];
    governorate_id: number | null;
    city_id: number | null;
    district_id: number | null;
    area_id: number | null;
    address_line: string;
    recipient_name: string;
    phone: string;
    coupon_code: string;
}

// Ported from Admin Template/order-checkout.html's Personal Details /
// Shipping Details / Order Summary card layout.
/**
 * Customers matching what the agent typed — searched on the server
 * (OrderController::customerSearch) rather than the page carrying every
 * customer, a list that grows with every walk-in and phone order.
 */
async function searchCustomers(term: string): Promise<{ value: number; label: string; customer: CustomerOption }[]> {
    if (term.trim() === '') return [];

    const response = await fetch(`${route('admin.orders.customer-search')}?q=${encodeURIComponent(term)}`, {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) return [];

    const body: { customers: CustomerOption[] } = await response.json();

    return body.customers.map((customer) => ({
        value: customer.id,
        label: `${customer.name} — ${customer.phone}`,
        customer,
    }));
}

export default function OrdersCreate({ warehouse, geoTree }: { warehouse: Warehouse | null; geoTree: GeoTree }) {
    const { t, price } = useTranslation();
    const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
    // Filing a new customer happens here rather than on /admin/customers/create
    // — Customer Service is usually on the phone with them.
    const [isNewCustomer, setIsNewCustomer] = useState(false);

    const { register, control, handleSubmit, watch, setValue } = useForm<FormValues>({
        defaultValues: {
            customer_id: null,
            new_customer: null,
            warehouse_id: warehouse?.id ?? null,
            items: [{ product_variant_id: null, quantity: 1 }],
            governorate_id: null,
            city_id: null,
            district_id: null,
            area_id: null,
            address_line: '',
            recipient_name: '',
            phone: '',
            coupon_code: '',
        },
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'items' });
    // Form names are the server's keys here (the form posts as-is), so no aliases.
    useClearServerErrorsOnChange(watch, setServerErrors);

    const governorateId = watch('governorate_id');
    const cityId = watch('city_id');
    const items = watch('items');
    const customerId = watch('customer_id');

    const governorate = geoTree.find((g) => g.id === governorateId);
    const city = governorate?.cities.find((c) => c.id === cityId);

    // The picked customer, kept whole: their saved addresses came with the
    // search result and there is no other copy on the page.
    const [pickedCustomer, setPickedCustomer] = useState<CustomerOption | null>(null);
    const savedAddresses = pickedCustomer?.id === customerId ? pickedCustomer.addresses : [];

    // Each row remembers the product and the chosen colour/size so the
    // resolved variant can be recomputed, and so the line can show its
    // price without waiting for the debounced server summary. Display
    // only — CreateOrderAction reprices everything on submit.
    const [picked, setPicked] = useState<Record<number, PickerVariant | null>>({});

    /** Copy a saved address into the shipping card — the whole point of storing them. */
    function applyAddress(address: SavedAddress) {
        setValue('governorate_id', address.governorate_id);
        setValue('city_id', address.city_id);
        setValue('district_id', address.district_id);
        setValue('area_id', address.area_id);
        setValue('address_line', address.address_line);
    }

    function selectCustomer(customer: CustomerOption | null) {
        setPickedCustomer(customer);
        setValue('customer_id', customer?.id ?? null);
        if (!customer) return;

        setValue('recipient_name', customer.name);
        setValue('phone', customer.phone);
        const preferred = customer.addresses.find((a) => a.is_default) ?? customer.addresses[0];
        if (preferred) applyAddress(preferred);
    }

    function switchMode(newCustomer: boolean) {
        setIsNewCustomer(newCustomer);
        // Only one of the two ever reaches the server; the other must be
        // null or the request satisfies both required_without rules.
        setValue('customer_id', null);
        setValue('new_customer', newCustomer ? { name: '', phone: '' } : null);
    }

    // Totals come from the server, not from arithmetic here: shipping is
    // resolved by geo and the coupon by its own rules, and a figure the
    // browser invented would be a figure the created order disagrees with.
    // Keyed by its request so a stale reply can't overwrite a newer one.
    const quoteRequest = JSON.stringify({
        customer_id: customerId,
        items: items.filter((i) => i.product_variant_id !== null),
        governorate_id: governorateId,
        city_id: cityId,
        district_id: watch('district_id'),
        area_id: watch('area_id'),
        coupon_code: watch('coupon_code') || null,
    });
    const [quote, setQuote] = useState<{ key: string; totals: Quote } | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('admin.orders.quote'), {
                method: 'POST',
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                body: quoteRequest,
            })
                .then((response) => response.json())
                .then((totals: Quote) => setQuote({ key: quoteRequest, totals }))
                .catch(() => undefined);
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [quoteRequest]);

    const totals = quote?.key === quoteRequest ? quote.totals : null;

    function onSubmit(data: FormValues) {
        router.post(route('admin.orders.store'), data as unknown as Record<string, string | number | null>, {
            onError: (errors) => setServerErrors(errors as Record<string, string>),
        });
    }

    return (
        <AdminLayout title={t('admin.createOrderCustomerService')}>
            <Head title={t('admin.createOrder')} />
            <form onSubmit={handleSubmit(onSubmit)}>
                <div className="row">
                    <div className="col-xl-8">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.personalDetails')}</h4>
                            </div>
                            <div className="card-body">
                                <ul className="nav nav-tabs nav-justified mb-3">
                                    <li className="nav-item">
                                        <button
                                            type="button"
                                            className={`nav-link ${isNewCustomer ? '' : 'active'}`}
                                            onClick={() => switchMode(false)}
                                        >
                                            {t('admin.existingCustomer')}
                                        </button>
                                    </li>
                                    <li className="nav-item">
                                        <button
                                            type="button"
                                            className={`nav-link ${isNewCustomer ? 'active' : ''}`}
                                            onClick={() => switchMode(true)}
                                        >
                                            {t('admin.newCustomer')}
                                        </button>
                                    </li>
                                </ul>

                                {isNewCustomer ? (
                                    <div className="row g-3">
                                        <FormField
                                            name="new_customer.name"
                                            label={t('admin.name')}
                                            error={serverErrors['new_customer.name']}
                                            required
                                            className="col-md-6"
                                        >
                                            <input className="form-control" {...register('new_customer.name')} />
                                        </FormField>
                                        <FormField
                                            name="new_customer.phone"
                                            label={t('admin.phone')}
                                            error={serverErrors['new_customer.phone']}
                                            required
                                            className="col-md-6"
                                        >
                                            <input className="form-control" {...register('new_customer.phone')} />
                                        </FormField>
                                        <div className="col-12">
                                            <p className="form-text mb-0">{t('admin.newCustomerAddressNote')}</p>
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        <FormField
                                            name="customer_id"
                                            label={t('admin.customer')}
                                            error={serverErrors.customer_id}
                                            required
                                            className=""
                                        >
                                            <AsyncSelect
                                                inputId={fieldId('customer_id')}
                                                cacheOptions
                                                defaultOptions={false}
                                                loadOptions={searchCustomers}
                                                placeholder={t('admin.searchByNameOrPhone')}
                                                noOptionsMessage={() => t('admin.typeToSearchCustomers')}
                                                onChange={(option) => selectCustomer(option?.customer ?? null)}
                                            />
                                        </FormField>
                                        {customerId !== null && (
                                            <div className="mt-3">
                                                <label className="form-label">{t('admin.savedAddresses')}</label>
                                                {savedAddresses.length === 0 ? (
                                                    <p className="text-muted fs-13 mb-0">
                                                        {t('admin.noSavedAddresses')}
                                                    </p>
                                                ) : (
                                                    <select
                                                        className="form-control"
                                                        // Remount per customer so the shown row
                                                        // matches the one applyAddress() just used.
                                                        key={customerId}
                                                        defaultValue={
                                                            (
                                                                savedAddresses.find((a) => a.is_default) ??
                                                                savedAddresses[0]
                                                            ).id
                                                        }
                                                        onChange={(e) => {
                                                            const address = savedAddresses.find(
                                                                (a) => a.id === Number(e.target.value),
                                                            );
                                                            if (address) applyAddress(address);
                                                        }}
                                                    >
                                                        {savedAddresses.map((a) => (
                                                            <option key={a.id} value={a.id}>
                                                                {a.label ? `${a.label} — ` : ''}
                                                                {a.address_line}
                                                            </option>
                                                        ))}
                                                    </select>
                                                )}
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.shippingDetails')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="row g-3">
                                    <FormField
                                        name="recipient_name"
                                        label={t('admin.recipientName')}
                                        error={serverErrors.recipient_name}
                                        required
                                        className="col-md-6"
                                    >
                                        <input className="form-control" {...register('recipient_name')} />
                                    </FormField>
                                    <FormField
                                        name="phone"
                                        label={t('admin.phone')}
                                        error={serverErrors.phone}
                                        required
                                        className="col-md-6"
                                    >
                                        <input
                                            className="form-control"
                                            inputMode="numeric"
                                            maxLength={11}
                                            {...register('phone')}
                                        />
                                    </FormField>
                                    <FormField
                                        name="governorate_id"
                                        label={t('admin.governorate')}
                                        error={serverErrors.governorate_id}
                                        required
                                        className="col-md-3"
                                    >
                                        <select
                                            className="form-control"
                                            value={governorateId ?? ''}
                                            onChange={(e) => {
                                                setValue(
                                                    'governorate_id',
                                                    e.target.value ? Number(e.target.value) : null,
                                                );
                                                setValue('city_id', null);
                                                setValue('district_id', null);
                                                setValue('area_id', null);
                                            }}
                                        >
                                            <option value="">{t('admin.select')}</option>
                                            {geoTree.map((g) => (
                                                <option key={g.id} value={g.id}>
                                                    {g.name}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>
                                    {/* City, district and area are optional, as on
                                        storefront checkout: shipping prices from
                                        whichever levels are picked, and the courier
                                        works from the street address. */}
                                    <FormField
                                        name="city_id"
                                        label={t('admin.city')}
                                        error={serverErrors.city_id}
                                        className="col-md-3"
                                    >
                                        <select
                                            className="form-control"
                                            value={cityId ?? ''}
                                            onChange={(e) => {
                                                setValue('city_id', e.target.value ? Number(e.target.value) : null);
                                                setValue('district_id', null);
                                                setValue('area_id', null);
                                            }}
                                        >
                                            <option value="">{t('admin.none')}</option>
                                            {governorate?.cities.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>
                                    <FormField
                                        name="district_id"
                                        label={t('admin.district')}
                                        error={serverErrors.district_id}
                                        className="col-md-3"
                                    >
                                        <select
                                            className="form-control"
                                            value={watch('district_id') ?? ''}
                                            onChange={(e) =>
                                                setValue('district_id', e.target.value ? Number(e.target.value) : null)
                                            }
                                        >
                                            <option value="">{t('admin.none')}</option>
                                            {city?.districts.map((d) => (
                                                <option key={d.id} value={d.id}>
                                                    {d.name}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>
                                    <FormField
                                        name="area_id"
                                        label={t('admin.area')}
                                        error={serverErrors.area_id}
                                        className="col-md-3"
                                    >
                                        <select
                                            className="form-control"
                                            value={watch('area_id') ?? ''}
                                            onChange={(e) =>
                                                setValue('area_id', e.target.value ? Number(e.target.value) : null)
                                            }
                                        >
                                            <option value="">{t('admin.none')}</option>
                                            {city?.areas.map((a) => (
                                                <option key={a.id} value={a.id}>
                                                    {a.name}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>
                                    <FormField
                                        name="address_line"
                                        label={t('admin.addressLine')}
                                        error={serverErrors.address_line}
                                        required
                                        className="col-md-12"
                                    >
                                        <input className="form-control" {...register('address_line')} />
                                    </FormField>
                                </div>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.items')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="table-responsive mb-2">
                                    <table className="table align-middle mb-0 table-centered">
                                        <thead className="bg-light-subtle">
                                            <tr>
                                                <th>
                                                    {t('admin.product')}
                                                    <RequiredMark />
                                                </th>
                                                <th style={{ width: 120 }} className="text-end">
                                                    {t('admin.unitPrice')}
                                                </th>
                                                <th style={{ width: 100 }}>
                                                    {t('admin.qty')}
                                                    <RequiredMark />
                                                </th>
                                                <th style={{ width: 120 }} className="text-end">
                                                    {t('admin.lineTotal')}
                                                </th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {fields.map((field, index) => {
                                                const unitPrice = picked[index]?.price ?? null;
                                                const quantity = Number(items?.[index]?.quantity) || 0;

                                                return (
                                                    <tr key={field.id}>
                                                        <td style={{ minWidth: 280 }}>
                                                            {/* Row errors come back as items.N.field —
                                                                the form's rows are sent as-is, so N is
                                                                this row's index. */}
                                                            <FormField
                                                                name={`items.${index}.product_variant_id`}
                                                                error={
                                                                    serverErrors[`items.${index}.product_variant_id`]
                                                                }
                                                                className=""
                                                            >
                                                                <VariantPicker
                                                                    searchUrl={route('admin.orders.product-search')}
                                                                    onResolve={(variant) => {
                                                                        setPicked((current) => ({
                                                                            ...current,
                                                                            [index]: variant,
                                                                        }));
                                                                        setValue(
                                                                            `items.${index}.product_variant_id`,
                                                                            variant?.id ?? null,
                                                                            { shouldDirty: true },
                                                                        );
                                                                    }}
                                                                />
                                                            </FormField>
                                                        </td>
                                                        <td className="text-end" dir="ltr">
                                                            {unitPrice === null ? (
                                                                <span className="text-muted">—</span>
                                                            ) : (
                                                                price(unitPrice)
                                                            )}
                                                        </td>
                                                        <td>
                                                            <FormField
                                                                name={`items.${index}.quantity`}
                                                                error={serverErrors[`items.${index}.quantity`]}
                                                                className=""
                                                            >
                                                                <input
                                                                    type="number"
                                                                    min={1}
                                                                    className="form-control form-control-sm"
                                                                    {...register(`items.${index}.quantity`, {
                                                                        valueAsNumber: true,
                                                                    })}
                                                                />
                                                            </FormField>
                                                        </td>
                                                        <td className="text-end fw-medium" dir="ltr">
                                                            {unitPrice === null ? (
                                                                <span className="text-muted">—</span>
                                                            ) : (
                                                                price(unitPrice * quantity)
                                                            )}
                                                        </td>
                                                        <td>
                                                            <button
                                                                type="button"
                                                                className="btn btn-soft-danger btn-sm"
                                                                onClick={() => remove(index)}
                                                            >
                                                                <i className="bx bx-trash align-middle" />
                                                            </button>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-secondary"
                                    onClick={() => append({ product_variant_id: null, quantity: 1 })}
                                >
                                    {t('admin.addItem')}
                                </button>
                                <FieldError name="items" message={serverErrors.items} />
                            </div>
                        </div>
                    </div>

                    {/* Sticky beside the taller left column: totals and the
                        create button stay visible while scrolling items.
                        align-self keeps the column content-sized so there
                        is distance to stick across; top clears the 100px
                        fixed topbar with a small gap. */}
                    <div className="col-xl-4 align-self-start position-sticky" style={{ top: 112 }}>
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.orderSummary')}</h4>
                            </div>
                            <div className="card-body">
                                {/* Not selectable — every admin-created order
                                    reserves against the main warehouse. */}
                                <div className="mb-3">
                                    <label className="form-label">{t('admin.warehouse')}</label>
                                    <input type="hidden" {...register('warehouse_id', { valueAsNumber: true })} />
                                    <input className="form-control" value={warehouse?.name ?? ''} readOnly disabled />
                                    <FieldError name="warehouse_id" message={serverErrors.warehouse_id} />
                                </div>
                                <FormField
                                    name="coupon_code"
                                    label={t('admin.couponCodeOptional')}
                                    error={serverErrors.coupon_code}
                                >
                                    <input className="form-control" {...register('coupon_code')} />
                                </FormField>
                                {/* The live quote's verdict on the code, before submit. */}
                                {!serverErrors.coupon_code && totals?.coupon_error && (
                                    <div className="text-danger fs-13 mt-n2 mb-3">{totals.coupon_error}</div>
                                )}

                                <table className="table mb-0">
                                    <tbody>
                                        <tr>
                                            <td className="px-0">{t('admin.subTotal')}</td>
                                            <td className="text-end text-dark fw-medium px-0">
                                                <span dir="ltr">{price(totals?.subtotal ?? 0)}</span>
                                            </td>
                                        </tr>
                                        {(totals?.discount ?? 0) > 0 && (
                                            <tr>
                                                <td className="px-0">{t('admin.discount')}</td>
                                                <td className="text-end text-success fw-medium px-0">
                                                    <span dir="ltr">-{price(totals?.discount ?? 0)}</span>
                                                </td>
                                            </tr>
                                        )}
                                        <tr>
                                            <td className="px-0">{t('admin.deliveryCharge')}</td>
                                            <td className="text-end text-dark fw-medium px-0">
                                                {/* null, not 0: no rate configured for this address at
                                                    any level, which store() will refuse outright. */}
                                                {totals === null || totals.shipping === null ? (
                                                    <span className="text-muted fs-13">
                                                        {totals === null
                                                            ? '—'
                                                            : t('admin.noShippingRateForThisAddress')}
                                                    </span>
                                                ) : (
                                                    <span dir="ltr">{price(totals.shipping)}</span>
                                                )}
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot className="border-top">
                                        <tr>
                                            <td className="px-0 fw-semibold text-dark">{t('admin.grandTotal')}</td>
                                            <td className="text-end px-0 fw-semibold text-dark">
                                                <span dir="ltr">{price(totals?.total ?? 0)}</span>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div className="card-footer border-top">
                                <button type="submit" className="btn btn-primary w-100">
                                    {t('admin.createOrder')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
