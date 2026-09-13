import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AdminLayout from '../../../Layouts/AdminLayout';
import { confirmAction } from '../../../lib/confirm';
import type { GeoTree } from '../../../types';
import { useTranslation } from '../../../lib/useTranslation';

interface Area {
    id: number;
    geo_type: 'governorate' | 'city' | 'district' | 'area';
    geo_id: number;
}

export default function RepresentativeAreas({
    representative,
    areas,
    geoTree,
}: {
    representative: { id: number; name: string };
    areas: Area[];
    geoTree: GeoTree;
}) {
    const { t } = useTranslation();
    const [geoType, setGeoType] = useState<Area['geo_type']>('governorate');
    const [governorateId, setGovernorateId] = useState<number | ''>('');
    const [cityId, setCityId] = useState<number | ''>('');
    const [selectedId, setSelectedId] = useState<number | ''>('');

    const governorate = geoTree.find((g) => g.id === governorateId);
    const city = governorate?.cities.find((c) => c.id === cityId);

    const options = useMemo(() => {
        if (geoType === 'governorate') return geoTree.map((g) => ({ id: g.id, name: g.name }));
        if (geoType === 'city') return governorate?.cities.map((c) => ({ id: c.id, name: c.name })) ?? [];
        if (geoType === 'district') return city?.districts ?? [];
        return city?.areas ?? [];
    }, [geoType, governorate, city, geoTree]);

    function labelFor(area: Area): string {
        for (const g of geoTree) {
            if (area.geo_type === 'governorate' && g.id === area.geo_id) return `${g.name} (Governorate)`;
            for (const c of g.cities) {
                if (area.geo_type === 'city' && c.id === area.geo_id) return `${c.name} (City)`;
                for (const d of c.districts) {
                    if (area.geo_type === 'district' && d.id === area.geo_id) return `${d.name} (District)`;
                }
                for (const a of c.areas) {
                    if (area.geo_type === 'area' && a.id === area.geo_id) return `${a.name} (Area)`;
                }
            }
        }
        return `#${area.geo_id}`;
    }

    function addArea() {
        if (!selectedId) return;
        router.post(
            route('admin.delivery.representatives.areas.store', representative.id),
            { geo_type: geoType, geo_id: selectedId },
            { preserveScroll: true, onSuccess: () => setSelectedId('') },
        );
    }

    async function removeArea(area: Area) {
        if (!(await confirmAction({ title: t('admin.removeThisCoverageArea'), danger: true }))) return;
        router.delete(route('admin.delivery.representatives.areas.destroy', [representative.id, area.id]), {
            preserveScroll: true,
        });
    }

    return (
        <AdminLayout
            title={t('admin.coverageAreasFor', { name: representative.name })}
            breadcrumbs={[
                { label: t('admin.deliveryRepresentatives'), href: route('admin.delivery.representatives.index') },
            ]}
        >
            <Head title={t('admin.coverageAreas')} />
            <div className="row">
                <div className="col-lg-6">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.addCoverageArea')}</h4>
                        </div>
                        <div className="card-body">
                            <div className="mb-3">
                                <label className="form-label">{t('admin.level')}</label>
                                <select
                                    className="form-control"
                                    value={geoType}
                                    onChange={(e) => {
                                        setGeoType(e.target.value as Area['geo_type']);
                                        setSelectedId('');
                                    }}
                                >
                                    <option value="governorate">{t('admin.governorate')}</option>
                                    <option value="city">{t('admin.city')}</option>
                                    <option value="district">{t('admin.district')}</option>
                                    <option value="area">{t('admin.area')}</option>
                                </select>
                            </div>

                            {geoType !== 'governorate' && (
                                <div className="mb-3">
                                    <label className="form-label">{t('admin.governorate')}</label>
                                    <select
                                        className="form-control"
                                        value={governorateId}
                                        onChange={(e) => {
                                            setGovernorateId(Number(e.target.value));
                                            setCityId('');
                                            setSelectedId('');
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
                            )}

                            {(geoType === 'district' || geoType === 'area') && (
                                <div className="mb-3">
                                    <label className="form-label">{t('admin.city')}</label>
                                    <select
                                        className="form-control"
                                        value={cityId}
                                        onChange={(e) => {
                                            setCityId(Number(e.target.value));
                                            setSelectedId('');
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
                            )}

                            <div className="mb-3">
                                <label className="form-label">{geoType[0].toUpperCase() + geoType.slice(1)}</label>
                                <select
                                    className="form-control"
                                    value={selectedId}
                                    onChange={(e) => setSelectedId(Number(e.target.value))}
                                >
                                    <option value="">{t('admin.select')}</option>
                                    {options.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <button type="button" className="btn btn-primary" onClick={addArea} disabled={!selectedId}>
                                {t('admin.add')}
                            </button>
                        </div>
                    </div>
                </div>

                <div className="col-lg-6">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.currentCoverage')}</h4>
                        </div>
                        <ul className="list-group list-group-flush">
                            {areas.map((area) => (
                                <li
                                    key={area.id}
                                    className="list-group-item d-flex justify-content-between align-items-center"
                                >
                                    {labelFor(area)}
                                    <button
                                        type="button"
                                        className="btn btn-soft-danger btn-sm"
                                        onClick={() => removeArea(area)}
                                    >
                                        <i className="bx bx-trash align-middle" />
                                    </button>
                                </li>
                            ))}
                            {areas.length === 0 && (
                                <li className="list-group-item text-muted">{t('admin.noCoverageAreasYet')}</li>
                            )}
                        </ul>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
