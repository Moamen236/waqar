import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import Select from 'react-select';
import AdminLayout from '../../Layouts/AdminLayout';
import type { Customer, GeoTree, Warehouse } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface VariantOption {
    id: number;
    sku: string;
    label: string;
    price: number;
}

interface FormValues {
    customer_id: number | null;
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
    customers: Customer[];
    variants: VariantOption[];
    warehouse: Warehouse | null;
    geoTree: GeoTree;
}) {
    const { t, price } = useTranslation();
    const [serverErrors, setServerErrors] = useState<Record<string, string>>({});

    const { register, control, handleSubmit, watch, setValue } = useForm<FormValues>({
        defaultValues: {
            customer_id: null,
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

    const governorate = geoTree.find((g) => g.id === governorateId);
    const city = governorate?.cities.find((c) => c.id === cityId);

    const customerOptions = customers.map((c) => ({ value: c.id, label: `${c.name} — ${c.phone}` }));
    const variantOptions = variants.map((v) => ({ value: v.id, label: v.label }));

    const subtotal = useMemo(
        () =>
            items.reduce((sum, item) => {
                const variant = variants.find((v) => v.id === item.product_variant_id);
                return sum + (variant ? variant.price * (item.quantity || 0) : 0);
            }, 0),
        [items, variants],
    );

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
                                <Select
                                    options={customerOptions}
                                    placeholder={t('admin.searchByNameOrPhone')}
                                    onChange={(option) => setValue('customer_id', option?.value ?? null)}
                                />
                                {serverErrors.customer_id && (
                                    <div className="text-danger fs-13 mt-1">{serverErrors.customer_id}</div>
                                )}
                                <div className="form-text">
                                    {t('admin.customerNotFound')}{' '}
                                    <a href={route('admin.customers.create')} target="_blank" rel="noreferrer">
                                        {t('admin.createOne')}
                                    </a>
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
                                <div className="text-end fw-bold mt-3">
                                    {t('admin.subtotalBeforeExtras')}:{' '}
                                    <span dir="ltr" className="text-nowrap">
                                        {price(subtotal)}
                                    </span>
                                </div>
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
                                </div>
                                {serverErrors.warehouse_id && (
                                    <div className="text-danger fs-13 mb-2">{serverErrors.warehouse_id}</div>
                                )}
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
