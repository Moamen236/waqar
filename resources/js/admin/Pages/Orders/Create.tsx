import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import Select from 'react-select';
import AdminLayout from '../../Layouts/AdminLayout';
import type { Customer, GeoTree, Warehouse } from '../../types';

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
    warehouses,
    geoTree,
}: {
    customers: Customer[];
    variants: VariantOption[];
    warehouses: Warehouse[];
    geoTree: GeoTree;
}) {
    const [serverErrors, setServerErrors] = useState<Record<string, string>>({});

    const { register, control, handleSubmit, watch, setValue } = useForm<FormValues>({
        defaultValues: {
            customer_id: null,
            warehouse_id: warehouses[0]?.id ?? null,
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
        <AdminLayout title="Create Order (Customer Service)">
            <Head title="Create Order" />
            <form onSubmit={handleSubmit(onSubmit)}>
                <div className="row">
                    <div className="col-xl-8">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">Personal Details</h4>
                            </div>
                            <div className="card-body">
                                <Select
                                    options={customerOptions}
                                    placeholder="Search by name or phone…"
                                    onChange={(option) => setValue('customer_id', option?.value ?? null)}
                                />
                                {serverErrors.customer_id && (
                                    <div className="text-danger fs-13 mt-1">{serverErrors.customer_id}</div>
                                )}
                                <div className="form-text">
                                    Customer not found?{' '}
                                    <a href={route('admin.customers.create')} target="_blank" rel="noreferrer">
                                        Create one
                                    </a>{' '}
                                    first.
                                </div>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">Items</h4>
                            </div>
                            <div className="card-body">
                                <div className="table-responsive mb-2">
                                    <table className="table align-middle mb-0 table-centered">
                                        <thead className="bg-light-subtle">
                                            <tr>
                                                <th>Product</th>
                                                <th style={{ width: 100 }}>Qty</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {fields.map((field, index) => (
                                                <tr key={field.id}>
                                                    <td>
                                                        <Select
                                                            options={variantOptions}
                                                            placeholder="Search product…"
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
                                    Add Item
                                </button>
                                {serverErrors.items && (
                                    <div className="text-danger fs-13 mt-2">{serverErrors.items}</div>
                                )}
                                <div className="text-end fw-bold mt-3">
                                    Subtotal (before shipping/coupon): {subtotal.toFixed(2)}
                                </div>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">Shipping Details</h4>
                            </div>
                            <div className="card-body">
                                <div className="row g-3">
                                    <div className="col-md-6">
                                        <label className="form-label">Recipient Name</label>
                                        <input className="form-control" {...register('recipient_name')} />
                                        {serverErrors.recipient_name && (
                                            <div className="text-danger fs-13 mt-1">{serverErrors.recipient_name}</div>
                                        )}
                                    </div>
                                    <div className="col-md-6">
                                        <label className="form-label">Phone</label>
                                        <input className="form-control" {...register('phone')} />
                                        {serverErrors.phone && (
                                            <div className="text-danger fs-13 mt-1">{serverErrors.phone}</div>
                                        )}
                                    </div>
                                    <div className="col-md-3">
                                        <label className="form-label">Governorate</label>
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
                                            <option value="">Select…</option>
                                            {geoTree.map((g) => (
                                                <option key={g.id} value={g.id}>
                                                    {g.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-3">
                                        <label className="form-label">City</label>
                                        <select
                                            className="form-control"
                                            value={cityId ?? ''}
                                            onChange={(e) => {
                                                setValue('city_id', Number(e.target.value));
                                                setValue('district_id', null);
                                                setValue('area_id', null);
                                            }}
                                        >
                                            <option value="">Select…</option>
                                            {governorate?.cities.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-3">
                                        <label className="form-label">District (optional)</label>
                                        <select
                                            className="form-control"
                                            value={watch('district_id') ?? ''}
                                            onChange={(e) =>
                                                setValue('district_id', e.target.value ? Number(e.target.value) : null)
                                            }
                                        >
                                            <option value="">None</option>
                                            {city?.districts.map((d) => (
                                                <option key={d.id} value={d.id}>
                                                    {d.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-3">
                                        <label className="form-label">Area</label>
                                        <select
                                            className="form-control"
                                            value={watch('area_id') ?? ''}
                                            onChange={(e) => setValue('area_id', Number(e.target.value))}
                                        >
                                            <option value="">Select…</option>
                                            {city?.areas.map((a) => (
                                                <option key={a.id} value={a.id}>
                                                    {a.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="col-md-12">
                                        <label className="form-label">Address Line</label>
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
                                <h4 className="card-title">Order Summary</h4>
                            </div>
                            <div className="card-body">
                                <div className="mb-3">
                                    <label className="form-label">Warehouse</label>
                                    <select
                                        className="form-control"
                                        {...register('warehouse_id', { valueAsNumber: true })}
                                    >
                                        {warehouses.map((w) => (
                                            <option key={w.id} value={w.id}>
                                                {w.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="mb-3">
                                    <label className="form-label">Coupon Code (optional)</label>
                                    <input className="form-control" {...register('coupon_code')} />
                                </div>
                                {serverErrors.warehouse_id && (
                                    <div className="text-danger fs-13 mb-2">{serverErrors.warehouse_id}</div>
                                )}
                            </div>
                            <div className="card-footer border-top">
                                <button type="submit" className="btn btn-primary w-100">
                                    Create Order
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
