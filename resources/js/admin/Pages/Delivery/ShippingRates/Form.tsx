import { Head, useForm } from '@inertiajs/react';
import { useMemo, type FormEventHandler } from 'react';
import AdminLayout from '../../../Layouts/AdminLayout';
import type { GeoGovernorate } from '../../../types';
import { useTranslation } from '../../../lib/useTranslation';

interface ShippingRateRecord {
    id: number;
    geo_type: string;
    geo_id: number;
    price: string;
    free_shipping_threshold: string;
    is_active: boolean;
}

/**
 * A rate attaches to exactly one level of the geography (Section 11's
 * level-flexible shipping_rates, §20 #20). The level picker drives which
 * location list is offered, so a "district" rate can only ever point at a
 * real district row — the controller re-checks that server-side, since
 * geo_id is a polymorphic pointer no `exists:` rule can validate alone.
 */
export default function ShippingRateForm({
    rate,
    geoTree,
}: {
    rate: ShippingRateRecord | null;
    geoTree: GeoGovernorate[];
}) {
    const { t } = useTranslation();
    const { data, setData, post, put, processing, errors } = useForm({
        geo_type: rate?.geo_type ?? 'governorate',
        geo_id: rate?.geo_id ? String(rate.geo_id) : '',
        price: rate?.price ?? '',
        free_shipping_threshold: rate?.free_shipping_threshold ?? '',
        is_active: rate?.is_active ?? true,
    });

    // Flattened once per level so the location select is a plain list with
    // its parent shown for context ("Nasr City — Cairo"), rather than a
    // second cascade the maintainer has to walk for every single rate.
    const locations = useMemo(() => {
        if (data.geo_type === 'governorate') {
            return geoTree.map((governorate) => ({ id: governorate.id, label: governorate.name }));
        }

        const rows: { id: number; label: string }[] = [];

        geoTree.forEach((governorate) => {
            governorate.cities.forEach((city) => {
                if (data.geo_type === 'city') {
                    rows.push({ id: city.id, label: `${city.name} — ${governorate.name}` });

                    return;
                }

                if (data.geo_type === 'district') {
                    city.districts.forEach((district) =>
                        rows.push({ id: district.id, label: `${district.name} — ${city.name}` }),
                    );

                    return;
                }

                city.areas.forEach((area) => rows.push({ id: area.id, label: `${area.name} — ${city.name}` }));
            });
        });

        return rows;
    }, [geoTree, data.geo_type]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (rate) {
            put(route('admin.delivery.shipping-rates.update', rate.id));
        } else {
            post(route('admin.delivery.shipping-rates.store'));
        }
    };

    return (
        <AdminLayout title={rate ? 'Edit Shipping Rate' : 'New Shipping Rate'}>
            <Head title={rate ? t('admin.editShippingRate') : t('admin.addShippingRate')} />
            <form onSubmit={submit}>
                <div className="row">
                    <div className="col-xl-9 col-lg-8">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.rate')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="row">
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.level')}</label>
                                            <select
                                                className="form-control"
                                                value={data.geo_type}
                                                onChange={(e) => {
                                                    setData((current) => ({
                                                        ...current,
                                                        geo_type: e.target.value,
                                                        // The previous id belongs to a different table
                                                        geo_id: '',
                                                    }));
                                                }}
                                            >
                                                <option value="governorate">{t('admin.governorate')}</option>
                                                <option value="city">{t('admin.city')}</option>
                                                <option value="district">{t('admin.district')}</option>
                                                <option value="area">{t('admin.area')}</option>
                                            </select>
                                            {errors.geo_type && (
                                                <div className="text-danger fs-13 mt-1">{errors.geo_type}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.location')}</label>
                                            <select
                                                className="form-control"
                                                value={data.geo_id}
                                                onChange={(e) => setData('geo_id', e.target.value)}
                                            >
                                                <option value="">{t('admin.chooseALocation')}</option>
                                                {locations.map((location) => (
                                                    <option key={location.id} value={location.id}>
                                                        {location.label}
                                                    </option>
                                                ))}
                                            </select>
                                            {locations.length === 0 && (
                                                <div className="text-muted fs-13 mt-1">
                                                    {t('admin.noLocationsExistAtThisLevel')}
                                                </div>
                                            )}
                                            {errors.geo_id && (
                                                <div className="text-danger fs-13 mt-1">{errors.geo_id}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.shippingPrice')}</label>
                                            <input
                                                className="form-control"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={data.price}
                                                onChange={(e) => setData('price', e.target.value)}
                                            />
                                            {errors.price && (
                                                <div className="text-danger fs-13 mt-1">{errors.price}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.freeShippingOverOptional')}</label>
                                            <input
                                                className="form-control"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={data.free_shipping_threshold}
                                                onChange={(e) => setData('free_shipping_threshold', e.target.value)}
                                            />
                                            <div className="text-muted fs-13 mt-1">
                                                {t('admin.leaveEmptyForNeverFreeAt')}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="col-xl-3 col-lg-4">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.status')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="form-check form-switch">
                                    <input
                                        className="form-check-input"
                                        type="checkbox"
                                        id="rate-active"
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="rate-active">
                                        {t('admin.active')}
                                    </label>
                                </div>
                                <p className="text-muted fs-13 mt-2 mb-0">{t('admin.inactiveRateExplainer')}</p>
                            </div>
                        </div>
                        <div className="p-3 bg-light-subtle rounded border">
                            <div className="row g-2">
                                <div className="col-lg-6">
                                    <button type="submit" className="btn btn-primary w-100" disabled={processing}>
                                        {rate ? 'Save Rate' : 'Create Rate'}
                                    </button>
                                </div>
                                <div className="col-lg-6">
                                    <a
                                        href={route('admin.delivery.shipping-rates.index')}
                                        className="btn btn-outline-secondary w-100"
                                    >
                                        {t('admin.cancel')}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
