import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import AdminLayout from '../../Layouts/AdminLayout';
import type { GeoTree } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface AddressEntry {
    id?: number;
    label: string;
    governorate_id: number | null;
    city_id: number | null;
    district_id: number | null;
    area_id: number | null;
    address_line: string;
    is_default: boolean;
}

interface CustomerRecord {
    id: number;
    name: string;
    email: string | null;
    phone: string;
    is_active: boolean;
    addresses?: AddressEntry[] | null;
    // Pre multi-address payload — still accepted so older renders keep
    // working until the new form ships everywhere.
    default_address?: AddressEntry | null;
}

interface FormValues {
    name: string;
    email: string;
    phone: string;
    password: string;
    is_active: boolean;
    addresses: AddressEntry[];
}

const blankAddress = (isDefault = false): AddressEntry => ({
    label: '',
    governorate_id: null,
    city_id: null,
    district_id: null,
    area_id: null,
    address_line: '',
    is_default: isDefault,
});

function initialAddresses(customer: CustomerRecord | null): AddressEntry[] {
    if (customer?.addresses?.length) {
        return customer.addresses.map((a) => ({ ...a, label: a.label ?? '' }));
    }
    if (customer?.default_address) {
        return [{ ...blankAddress(true), ...customer.default_address, label: '' }];
    }
    return [blankAddress(true)];
}

// Ported from Admin Template/customer-add.html's General Information card
// layout. A customer added here (customer === null) is Customer Service
// filing one on someone's behalf — a phone order, a walk-in — so there is
// no email/password to collect, and addresses are taken up front instead
// (see CustomerController::store()). Both create and edit support several
// addresses (home + work, …) with exactly one default.
export default function CustomerForm({ customer, geoTree }: { customer: CustomerRecord | null; geoTree?: GeoTree }) {
    const { t } = useTranslation();
    const { data, setData, post, put, processing, errors } = useForm<FormValues>({
        name: customer?.name ?? '',
        email: customer?.email ?? '',
        phone: customer?.phone ?? '',
        password: '',
        is_active: customer?.is_active ?? true,
        addresses: initialAddresses(customer),
    });

    const patchAddress = (index: number, patch: Partial<AddressEntry>) => {
        setData(
            'addresses',
            data.addresses.map((a, i) => (i === index ? { ...a, ...patch } : a)),
        );
    };

    const setDefault = (index: number) => {
        setData(
            'addresses',
            data.addresses.map((a, i) => ({ ...a, is_default: i === index })),
        );
    };

    const addAddress = () => {
        setData('addresses', [...data.addresses, blankAddress(false)]);
    };

    const removeAddress = (index: number) => {
        if (data.addresses.length <= 1) {
            return;
        }
        const next = data.addresses.filter((_, i) => i !== index);
        // Keep exactly one default — if the removed row was the default,
        // fall back to the first remaining row.
        if (!next.some((a) => a.is_default)) {
            next[0] = { ...next[0], is_default: true };
        }
        setData('addresses', next);
    };

    const errorFor = (index: number, field: keyof AddressEntry): string | undefined =>
        (errors as Record<string, string>)[`addresses.${index}.${field}`];

    const toNullableId = (value: string): number | null => (value === '' ? null : Number(value));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (customer) {
            put(route('admin.customers.update', customer.id));
        } else {
            post(route('admin.customers.store'));
        }
    };

    return (
        <AdminLayout
            title={customer ? t('admin.editCustomer') : t('admin.newCustomer')}
            breadcrumbs={[{ label: t('admin.customers'), href: route('admin.customers.index') }]}
        >
            <Head title={customer ? t('admin.editCustomer') : t('admin.newCustomer')} />
            <form onSubmit={submit}>
                <div className="row">
                    <div className="col-xl-9 col-lg-8">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.generalInformation')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="row">
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.name')}</label>
                                            <input
                                                className="form-control"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                            />
                                            {errors.name && <div className="text-danger small mt-1">{errors.name}</div>}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.phone')}</label>
                                            <input
                                                className="form-control"
                                                value={data.phone}
                                                onChange={(e) => setData('phone', e.target.value)}
                                            />
                                            {errors.phone && (
                                                <div className="text-danger small mt-1">{errors.phone}</div>
                                            )}
                                        </div>
                                    </div>
                                    {customer && (
                                        <>
                                            <div className="col-lg-6">
                                                <div className="mb-3">
                                                    <label className="form-label">{t('admin.email')}</label>
                                                    <input
                                                        type="email"
                                                        className="form-control"
                                                        value={data.email}
                                                        onChange={(e) => setData('email', e.target.value)}
                                                    />
                                                    {errors.email && (
                                                        <div className="text-danger small mt-1">{errors.email}</div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="col-lg-6">
                                                <div className="mb-3">
                                                    <label className="form-label">
                                                        {t('admin.newPasswordKeepBlank')}
                                                    </label>
                                                    <input
                                                        type="password"
                                                        className="form-control"
                                                        value={data.password}
                                                        onChange={(e) => setData('password', e.target.value)}
                                                    />
                                                    {errors.password && (
                                                        <div className="text-danger small mt-1">{errors.password}</div>
                                                    )}
                                                </div>
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>

                        {geoTree && (
                            <div className="card">
                                <div className="card-header d-flex align-items-center justify-content-between">
                                    <h4 className="card-title mb-0">{t('admin.addresses')}</h4>
                                    <button type="button" className="btn btn-sm btn-soft-primary" onClick={addAddress}>
                                        {t('admin.addAddress')}
                                    </button>
                                </div>
                                <div className="card-body">
                                    {(errors as Record<string, string>).addresses && (
                                        <div className="alert alert-danger py-2">
                                            {(errors as Record<string, string>).addresses}
                                        </div>
                                    )}
                                    {data.addresses.map((address, index) => {
                                        const governorate = geoTree.find((g) => g.id === address.governorate_id);
                                        const city = governorate?.cities.find((c) => c.id === address.city_id);
                                        return (
                                            <div key={address.id ?? `new-${index}`} className="border rounded p-3 mb-3">
                                                <div className="d-flex align-items-center justify-content-between mb-3">
                                                    <strong>
                                                        {t('admin.address')} #{index + 1}
                                                        {address.is_default && (
                                                            <span className="badge bg-success ms-2">
                                                                {t('admin.defaultAddress')}
                                                            </span>
                                                        )}
                                                    </strong>
                                                    {data.addresses.length > 1 && (
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-soft-danger"
                                                            onClick={() => removeAddress(index)}
                                                        >
                                                            {t('admin.removeThisAddress')}
                                                        </button>
                                                    )}
                                                </div>
                                                <div className="row g-3">
                                                    <div className="col-md-4">
                                                        <label className="form-label">{t('admin.label')}</label>
                                                        <input
                                                            className="form-control"
                                                            placeholder={t('admin.labelPlaceholder')}
                                                            value={address.label}
                                                            onChange={(e) =>
                                                                patchAddress(index, {
                                                                    label: e.target.value,
                                                                })
                                                            }
                                                        />
                                                        {errorFor(index, 'label') && (
                                                            <div className="text-danger small mt-1">
                                                                {errorFor(index, 'label')}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="col-md-4">
                                                        <label className="form-label">{t('admin.governorate')}</label>
                                                        <select
                                                            className="form-control"
                                                            value={address.governorate_id ?? ''}
                                                            onChange={(e) =>
                                                                patchAddress(index, {
                                                                    governorate_id: toNullableId(e.target.value),
                                                                    city_id: null,
                                                                    district_id: null,
                                                                    area_id: null,
                                                                })
                                                            }
                                                        >
                                                            <option value="">{t('admin.select')}</option>
                                                            {geoTree.map((g) => (
                                                                <option key={g.id} value={g.id}>
                                                                    {g.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {errorFor(index, 'governorate_id') && (
                                                            <div className="text-danger small mt-1">
                                                                {errorFor(index, 'governorate_id')}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="col-md-4">
                                                        <label className="form-label">{t('admin.city')}</label>
                                                        <select
                                                            className="form-control"
                                                            value={address.city_id ?? ''}
                                                            onChange={(e) =>
                                                                patchAddress(index, {
                                                                    city_id: toNullableId(e.target.value),
                                                                    district_id: null,
                                                                    area_id: null,
                                                                })
                                                            }
                                                        >
                                                            <option value="">{t('admin.select')}</option>
                                                            {governorate?.cities.map((c) => (
                                                                <option key={c.id} value={c.id}>
                                                                    {c.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {errorFor(index, 'city_id') && (
                                                            <div className="text-danger small mt-1">
                                                                {errorFor(index, 'city_id')}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="col-md-4">
                                                        <label className="form-label">
                                                            {t('admin.districtOptional')}
                                                        </label>
                                                        <select
                                                            className="form-control"
                                                            value={address.district_id ?? ''}
                                                            onChange={(e) =>
                                                                patchAddress(index, {
                                                                    district_id: toNullableId(e.target.value),
                                                                })
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
                                                    <div className="col-md-4">
                                                        <label className="form-label">{t('admin.area')}</label>
                                                        <select
                                                            className="form-control"
                                                            value={address.area_id ?? ''}
                                                            onChange={(e) =>
                                                                patchAddress(index, {
                                                                    area_id: toNullableId(e.target.value),
                                                                })
                                                            }
                                                        >
                                                            <option value="">{t('admin.select')}</option>
                                                            {city?.areas.map((a) => (
                                                                <option key={a.id} value={a.id}>
                                                                    {a.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {errorFor(index, 'area_id') && (
                                                            <div className="text-danger small mt-1">
                                                                {errorFor(index, 'area_id')}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="col-md-8">
                                                        <label className="form-label">{t('admin.addressLine')}</label>
                                                        <input
                                                            className="form-control"
                                                            value={address.address_line}
                                                            onChange={(e) =>
                                                                patchAddress(index, {
                                                                    address_line: e.target.value,
                                                                })
                                                            }
                                                        />
                                                        {errorFor(index, 'address_line') && (
                                                            <div className="text-danger small mt-1">
                                                                {errorFor(index, 'address_line')}
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div className="col-12">
                                                        <div className="form-check">
                                                            <input
                                                                type="radio"
                                                                className="form-check-input"
                                                                name="default-address"
                                                                checked={address.is_default}
                                                                onChange={() => setDefault(index)}
                                                            />
                                                            <label className="form-check-label">
                                                                {t('admin.defaultAddress')}
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="col-xl-3 col-lg-4">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.status')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="form-check">
                                    <input
                                        type="checkbox"
                                        className="form-check-input"
                                        id="active"
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="active">
                                        {t('admin.active')}
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div className="card">
                    <div className="card-footer border-top text-end">
                        <button type="submit" className="btn btn-primary" disabled={processing}>
                            {t('admin.saveCustomer')}
                        </button>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
