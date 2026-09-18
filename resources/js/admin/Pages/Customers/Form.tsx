import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import AdminLayout from '../../Layouts/AdminLayout';
import type { GeoTree } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface CustomerRecord {
    id: number;
    name: string;
    email: string | null;
    phone: string;
    is_active: boolean;
}

interface FormValues {
    name: string;
    email: string;
    phone: string;
    password: string;
    is_active: boolean;
    address: {
        governorate_id: number | null;
        city_id: number | null;
        district_id: number | null;
        area_id: number | null;
        address_line: string;
    };
}

// Ported from Admin Template/customer-add.html's General Information card
// layout. A customer added here (customer === null) is Customer Service
// filing one on someone's behalf — a phone order, a walk-in — so there is
// no email/password to collect, and an address is taken up front instead
// (see CustomerController::store()). Editing an existing customer keeps
// the email/password fields: that record may already be a real,
// self-registered account.
export default function CustomerForm({
    customer,
    geoTree,
}: {
    customer: CustomerRecord | null;
    geoTree?: GeoTree;
}) {
    const { t } = useTranslation();
    const { data, setData, post, put, processing, errors } = useForm<FormValues>({
        name: customer?.name ?? '',
        email: customer?.email ?? '',
        phone: customer?.phone ?? '',
        password: '',
        is_active: customer?.is_active ?? true,
        address: {
            governorate_id: null,
            city_id: null,
            district_id: null,
            area_id: null,
            address_line: '',
        },
    });

    const governorate = geoTree?.find((g) => g.id === data.address.governorate_id);
    const city = governorate?.cities.find((c) => c.id === data.address.city_id);

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
                                                        <div className="text-danger small mt-1">
                                                            {errors.password}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>

                        {!customer && geoTree && (
                            <div className="card">
                                <div className="card-header">
                                    <h4 className="card-title">{t('admin.address')}</h4>
                                </div>
                                <div className="card-body">
                                    <div className="row g-3">
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.governorate')}</label>
                                            <select
                                                className="form-control"
                                                value={data.address.governorate_id ?? ''}
                                                onChange={(e) =>
                                                    setData('address', {
                                                        ...data.address,
                                                        governorate_id: Number(e.target.value),
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
                                            {errors['address.governorate_id'] && (
                                                <div className="text-danger small mt-1">
                                                    {errors['address.governorate_id']}
                                                </div>
                                            )}
                                        </div>
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.city')}</label>
                                            <select
                                                className="form-control"
                                                value={data.address.city_id ?? ''}
                                                onChange={(e) =>
                                                    setData('address', {
                                                        ...data.address,
                                                        city_id: Number(e.target.value),
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
                                            {errors['address.city_id'] && (
                                                <div className="text-danger small mt-1">
                                                    {errors['address.city_id']}
                                                </div>
                                            )}
                                        </div>
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.districtOptional')}</label>
                                            <select
                                                className="form-control"
                                                value={data.address.district_id ?? ''}
                                                onChange={(e) =>
                                                    setData('address', {
                                                        ...data.address,
                                                        district_id: e.target.value ? Number(e.target.value) : null,
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
                                                value={data.address.area_id ?? ''}
                                                onChange={(e) =>
                                                    setData('address', {
                                                        ...data.address,
                                                        area_id: Number(e.target.value),
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
                                            {errors['address.area_id'] && (
                                                <div className="text-danger small mt-1">
                                                    {errors['address.area_id']}
                                                </div>
                                            )}
                                        </div>
                                        <div className="col-md-8">
                                            <label className="form-label">{t('admin.addressLine')}</label>
                                            <input
                                                className="form-control"
                                                value={data.address.address_line}
                                                onChange={(e) =>
                                                    setData('address', {
                                                        ...data.address,
                                                        address_line: e.target.value,
                                                    })
                                                }
                                            />
                                            {errors['address.address_line'] && (
                                                <div className="text-danger small mt-1">
                                                    {errors['address.address_line']}
                                                </div>
                                            )}
                                        </div>
                                    </div>
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
