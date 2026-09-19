import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import Select from 'react-select';
import AdminLayout from '../../Layouts/AdminLayout';
import type { GeoTree, Warehouse } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface VariantOption {
    id: number;
    sku: string;
    label: string;
    price: number;
}

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
    city_id: number;
    district_id: number | null;
    area_id: number;
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
export default function OrdersCreate({
    customers,
    variants,
    warehouse,
    geoTree,
}: {
    customers: CustomerOption[];
    variants: VariantOption[];
    warehouse: Warehouse | null;
    geoTree: GeoTree;
}) {
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

    const governorateId = watch('governorate_id');
    const cityId = watch('city_id');
    const items = watch('items');
    const customerId = watch('customer_id');

    const governorate = geoTree.find((g) => g.id === governorateId);
    const city = governorate?.cities.find((c) => c.id === cityId);

    const customerOptions = customers.map((c) => ({ value: c.id, label: `${c.name} — ${c.phone}` }));
    const variantOptions = variants.map((v) => ({ value: v.id, label: v.label }));
    const savedAddresses = customers.find((c) => c.id === customerId)?.addresses ?? [];

    /** Copy a saved address into the shipping card — the whole point of storing them. */
    function applyAddress(address: SavedAddress) {
        setValue('governorate_id', address.governorate_id);
        setValue('city_id', address.city_id);
        setValue('district_id', address.district_id);
        setValue('area_id', address.area_id);
        setValue('address_line', address.address_line);
    }

    function selectCustomer(id: number | null) {
        setValue('customer_id', id);
        const customer = customers.find((c) => c.id === id);
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
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
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
                                        <div className="col-md-6">
                                            <label className="form-label">{t('admin.name')}</label>
                                            <input className="form-control" {...register('new_customer.name')} />
                                            {serverErrors['new_customer.name'] && (
                                                <div className="text-danger fs-13 mt-1">
                                                    {serverErrors['new_customer.name']}
                                                </div>
                                            )}
                                        </div>
                                        <div className="col-md-6">
                                            <label className="form-label">{t('admin.phone')}</label>
                                            <input className="form-control" {...register('new_customer.phone')} />
                                            {serverErrors['new_customer.phone'] && (
                                                <div className="text-danger fs-13 mt-1">
                                                    {serverErrors['new_customer.phone']}
                                                </div>
                                            )}
                                        </div>
                                        <div className="col-12">
                                            <p className="form-text mb-0">{t('admin.newCustomerAddressNote')}</p>
                                        </div>
                                    </div>
                                ) : (
                                    <>
                                        <Select
                                            options={customerOptions}
                                            placeholder={t('admin.searchByNameOrPhone')}
                                            onChange={(option) => selectCustomer(option?.value ?? null)}
                                        />
                                        {serverErrors.customer_id && (
                                            <div className="text-danger fs-13 mt-1">{serverErrors.customer_id}</div>
                                        )}
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
                                                            (savedAddresses.find((a) => a.is_default) ??
                                                                savedAddresses[0]).id
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
                                    <div className="col-md-6">
                                        <label className="form-label">{t('admin.recipientName')}</label>
                                        <input className="form-control" {...register('recipient_name')} />
                                        {serverErrors.recipient_name && (
                                            <div className="text-danger fs-13 mt-1">{serverErrors.recipient_name}</div>
                                        )}
                                    </div>
                                    <div className="col-md-6">
                                        <label className="form-label">{t('admin.phone')}</label>
                                        <input className="form-control" {...register('phone')} />
                                        {serverErrors.phone && (
                                            <div className="text-danger fs-13 mt-1">{serverErrors.phone}</div>
                                        )}
                                    </div>
                                    <div className="col-md-3">
                                        <label className="form-label">{t('admin.governorate')}</label>
                                        <select
                                            className="form-control"
                                            value={governorateId ?? ''}
                                            onChange={(e) => {
                                                setValue('governorate_id', Number(e.target.value));
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
                                    </div>
                                    <div className="col-md-3">
                                        <label className="form-label">{t('admin.city')}</label>
                                        <select
                                            className="form-control"
                                            value={cityId ?? ''}
                                            onChange={(e) => {
                                                setValue('city_id', Number(e.target.value));
                                                setValue('district_id', null);
                                                setValue('area_id', null);
                                            }}
                                        >
                                            <option value="">{t('admin.select')}</option>
                                            {governorate?.cities.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-3">
                                        <label className="form-label">{t('admin.districtOptional')}</label>
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
                                    </div>
                                    <div className="col-md-3">
                                        <label className="form-label">{t('admin.area')}</label>
                                        <select
                                            className="form-control"
                                            value={watch('area_id') ?? ''}
                                            onChange={(e) => setValue('area_id', Number(e.target.value))}
                                        >
                                            <option value="">{t('admin.select')}</option>
                                            {city?.areas.map((a) => (
                                                <option key={a.id} value={a.id}>
                                                    {a.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-12">
                                        <label className="form-label">{t('admin.addressLine')}</label>
                                        <input className="form-control" {...register('address_line')} />
                                        {serverErrors.address_line && (
                                            <div className="text-danger fs-13 mt-1">{serverErrors.address_line}</div>
                                        )}
                                    </div>
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
                                                <th>{t('admin.product')}</th>
                                                <th style={{ width: 100 }}>{t('admin.qty')}</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {fields.map((field, index) => (
                                                <tr key={field.id}>
                                                    <td>
                                                        <Select
                                                            options={variantOptions}
                                                            placeholder={t('admin.searchProduct')}
                                                            onChange={(option) =>
                                                                setValue(
                                                                    `items.${index}.product_variant_id`,
                                                                    option?.value ?? null,
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td>
                                                        <input
                                                            type="number"
                                                            min={1}
                                                            className="form-control form-control-sm"
                                                            {...register(`items.${index}.quantity`, {
                                                                valueAsNumber: true,
                                                            })}
                                                        />
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
                                            ))}
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
                                {serverErrors.items && (
                                    <div className="text-danger fs-13 mt-2">{serverErrors.items}</div>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="col-xl-4">
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
                                </div>
                                <div className="mb-3">
                                    <label className="form-label">{t('admin.couponCodeOptional')}</label>
                                    <input className="form-control" {...register('coupon_code')} />
                                    {totals?.coupon_error && (
                                        <div className="text-danger fs-13 mt-1">{totals.coupon_error}</div>
                                    )}
                                </div>
                                {serverErrors.warehouse_id && (
                                    <div className="text-danger fs-13 mb-2">{serverErrors.warehouse_id}</div>
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
