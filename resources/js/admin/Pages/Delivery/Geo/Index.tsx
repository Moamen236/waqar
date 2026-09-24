import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import FieldError from '../../../Components/Form/FieldError';
import FormField from '../../../Components/Form/FormField';
import { PaginationFooter } from '../../../Components/Pagination';
import StatusBadge from '../../../Components/StatusBadge';
import AdminLayout from '../../../Layouts/AdminLayout';
import { confirmAction } from '../../../lib/confirm';
import { invalidClass, invalidProps, useClearErrorsOnChange } from '../../../lib/formErrors';
import type { PaginatedData } from '../../../types';
import { useTranslation } from '../../../lib/useTranslation';
import { usePermissions } from '../../../Hooks/usePermissions';

interface GeoRow {
    id: number;
    name: string;
    name_ar: string;
    name_en: string;
    parent_id: number;
    district_id: number | null;
    is_active: boolean;
}

interface Option {
    id: number;
    name: string;
}

interface DistrictOption extends Option {
    city_id: number;
}

/**
 * /admin/geo/{level} — the Governorate → City → District → Area tables.
 *
 * One page for all four levels, matching the single controller behind
 * them. Add and edit share one form rather than living on a separate
 * screen: this is reference data entered in runs (twenty areas for one
 * city), and a full page round-trip per row would make that miserable.
 */
export default function GeoIndex({
    level,
    levels,
    rows,
    parentId,
    parents,
    districts,
}: {
    level: string;
    levels: string[];
    rows: PaginatedData<GeoRow>;
    parentId: number | null;
    parents: Option[];
    districts: DistrictOption[] | null;
}) {
    const { t } = useTranslation();
    const { can } = usePermissions();
    const [editing, setEditing] = useState<GeoRow | null>(null);
    const writable = can('geo.manage');

    const form = useForm({
        name: { ar: '', en: '' },
        parent: parentId ?? parents[0]?.id ?? 0,
        district_id: null as number | null,
        is_active: true,
    });

    // Errors are keyed by the payload the server saw (submit() below), so
    // the parent's error sits under its column name, not `parent`.
    useClearErrorsOnChange(form.data, form.errors, form.clearErrors);
    const error = form.errors as Partial<Record<string, string>>;

    // The parent field is named for the column the level actually uses,
    // so the payload matches what the controller validates.
    const parentField = level === 'governorates' ? 'country_id' : level === 'cities' ? 'governorate_id' : 'city_id';

    const parentLabel =
        level === 'governorates' ? t('admin.country') : level === 'cities' ? t('admin.governorate') : t('admin.city');

    function startEdit(row: GeoRow) {
        setEditing(row);
        form.setData({
            name: { ar: row.name_ar, en: row.name_en },
            parent: row.parent_id,
            district_id: row.district_id,
            is_active: row.is_active,
        });
    }

    function cancelEdit() {
        setEditing(null);
        form.reset();
        form.clearErrors();
    }

    function submit(event: React.FormEvent) {
        event.preventDefault();

        const payload = {
            name: form.data.name,
            [parentField]: form.data.parent,
            is_active: form.data.is_active,
            ...(level === 'areas' ? { district_id: form.data.district_id } : {}),
        };

        const options = {
            preserveScroll: true,
            onSuccess: () => cancelEdit(),
            // router.post, not form.post (the payload is reshaped above),
            // so the form's own errors are never filled in by the visit —
            // every message was being dropped. Hand them over explicitly.
            onError: (errors: Record<string, string>) => form.setError(errors as never),
        };

        if (editing === null) {
            router.post(route('admin.geo.store', level), payload, options);
        } else {
            router.put(route('admin.geo.update', [level, editing.id]), payload, options);
        }
    }

    async function remove(row: GeoRow) {
        const confirmed = await confirmAction({
            title: t('admin.removeThisPlace', { name: row.name }),
            text: t('admin.removePlaceHint'),
            confirmText: t('admin.remove'),
            danger: true,
        });

        if (confirmed) {
            router.delete(route('admin.geo.destroy', [level, row.id]), { preserveScroll: true });
        }
    }

    // Areas hang off a city but may optionally name a district inside it,
    // so the district list narrows as the city changes.
    const districtsForCity = (districts ?? []).filter((d) => d.city_id === form.data.parent);

    return (
        <AdminLayout title={t('admin.geography')}>
            <Head title={t('admin.geography')} />

            <ul className="nav nav-pills mb-3">
                {levels.map((item) => (
                    <li className="nav-item" key={item}>
                        <Link
                            href={route('admin.geo.index', item)}
                            // All four levels render this same component, so
                            // Inertia would otherwise keep its state — leaving
                            // a half-typed city in the form while the table
                            // below has switched to areas.
                            preserveState={false}
                            className={`nav-link ${item === level ? 'active' : ''}`}
                        >
                            {t(`admin.geo_${item}`)}
                        </Link>
                    </li>
                ))}
            </ul>

            <div className="row">
                {writable && (
                    <div className="col-xl-4">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">
                                    {editing === null
                                        ? t('admin.addPlace', { level: t(`admin.geo_${level}`) })
                                        : t('admin.editPlace', { name: editing.name })}
                                </h4>
                            </div>
                            <div className="card-body">
                                <form onSubmit={submit}>
                                    <FormField name={parentField} label={parentLabel} error={error[parentField]} required>
                                        <select
                                            className="form-select"
                                            value={form.data.parent}
                                            onChange={(event) => {
                                                form.setData('parent', Number(event.target.value));
                                                form.setData('district_id', null);
                                                form.clearErrors(parentField as never);
                                            }}
                                        >
                                            {parents.map((option) => (
                                                <option key={option.id} value={option.id}>
                                                    {option.name}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>

                                    {level === 'areas' && (
                                        <FormField
                                            name="district_id"
                                            label={
                                                <>
                                                    {t('admin.district')}{' '}
                                                    <span className="text-muted fs-12">({t('admin.optional')})</span>
                                                </>
                                            }
                                            error={error.district_id}
                                        >
                                            <select
                                                className="form-select"
                                                value={form.data.district_id ?? ''}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'district_id',
                                                        event.target.value === '' ? null : Number(event.target.value),
                                                    )
                                                }
                                            >
                                                <option value="">—</option>
                                                {districtsForCity.map((option) => (
                                                    <option key={option.id} value={option.id}>
                                                        {option.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </FormField>
                                    )}

                                    <FormField name="name.ar" label={t('admin.nameArabic')} error={error['name.ar']} required>
                                        <input
                                            className="form-control"
                                            dir="rtl"
                                            value={form.data.name.ar}
                                            onChange={(event) =>
                                                form.setData('name', { ...form.data.name, ar: event.target.value })
                                            }
                                            required
                                        />
                                    </FormField>

                                    <FormField name="name.en" label={t('admin.nameEnglish')} error={error['name.en']} required>
                                        <input
                                            className="form-control"
                                            dir="ltr"
                                            value={form.data.name.en}
                                            onChange={(event) =>
                                                form.setData('name', { ...form.data.name, en: event.target.value })
                                            }
                                            required
                                        />
                                    </FormField>

                                    <div className="form-check mb-3">
                                        <input
                                            type="checkbox"
                                            className={`form-check-input${invalidClass(error.is_active)}`}
                                            {...invalidProps('is_active', error.is_active, 'is_active')}
                                            checked={form.data.is_active}
                                            onChange={(event) => form.setData('is_active', event.target.checked)}
                                        />
                                        <label className="form-check-label" htmlFor="is_active">
                                            {t('admin.active')}
                                        </label>
                                        <FieldError name="is_active" message={error.is_active} id="is_active" />
                                    </div>

                                    <div className="d-flex gap-2">
                                        <button type="submit" className="btn btn-primary">
                                            {editing === null ? t('admin.add') : t('admin.saveChanges')}
                                        </button>
                                        {editing !== null && (
                                            <button
                                                type="button"
                                                className="btn btn-soft-secondary"
                                                onClick={cancelEdit}
                                            >
                                                {t('admin.cancel')}
                                            </button>
                                        )}
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                )}

                <div className={writable ? 'col-xl-8' : 'col-xl-12'}>
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-2">
                            <h4 className="card-title flex-grow-1">{t(`admin.geo_${level}`)}</h4>
                            <select
                                className="form-select form-select-sm"
                                style={{ maxWidth: 220 }}
                                value={parentId ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        route('admin.geo.index', level),
                                        event.target.value === '' ? {} : { parent: event.target.value },
                                        { preserveState: true, replace: true },
                                    )
                                }
                            >
                                <option value="">{t('admin.allOf', { parent: parentLabel })}</option>
                                {parents.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.name')}</th>
                                        <th>{parentLabel}</th>
                                        <th>{t('admin.status')}</th>
                                        {writable && <th>{t('admin.action')}</th>}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="fw-medium">{row.name}</td>
                                            <td className="text-muted">
                                                {parents.find((p) => p.id === row.parent_id)?.name ?? '—'}
                                            </td>
                                            <td>
                                                <StatusBadge status={row.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            {writable && (
                                                <td>
                                                    <div className="d-flex gap-2">
                                                        <button
                                                            type="button"
                                                            className="btn btn-soft-primary btn-sm"
                                                            onClick={() => startEdit(row)}
                                                        >
                                                            <i className="bx bx-edit-alt align-middle fs-18" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="btn btn-soft-danger btn-sm"
                                                            onClick={() => remove(row)}
                                                        >
                                                            <i className="bx bx-trash align-middle fs-18" />
                                                        </button>
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                    {rows.data.length === 0 && (
                                        <tr>
                                            <td colSpan={writable ? 4 : 3} className="text-center text-muted py-4">
                                                {t('admin.nothingHereYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={rows} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
