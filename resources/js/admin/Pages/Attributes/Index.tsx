import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import { usePermissions } from '../../Hooks/usePermissions';
import { useTranslation } from '../../lib/useTranslation';

interface AttributeValue {
    id: number;
    value: string;
    color_hex: string | null;
}

interface AttributeRecord {
    id: number;
    name: string;
    sort_order: number;
    values: AttributeValue[];
}

// Card grid, matching Larkon's general card conventions — no direct
// Larkon page names an attribute-values screen, so this reuses the same
// card-header/card-body/list markup every other module uses.
export default function AttributesIndex({ attributes }: { attributes: AttributeRecord[] }) {
    const { t } = useTranslation();
    const { can } = usePermissions();
    const [newAttributeName, setNewAttributeName] = useState('');
    const [newValues, setNewValues] = useState<Record<number, { value: string; color_hex: string }>>({});

    function createAttribute() {
        if (!newAttributeName) return;
        router.post(
            route('admin.attributes.store'),
            { name: { en: newAttributeName }, sort_order: attributes.length },
            { onSuccess: () => setNewAttributeName('') },
        );
    }

    function addValue(attribute: AttributeRecord) {
        const draft = newValues[attribute.id];
        if (!draft?.value) return;
        router.post(
            route('admin.attributes.values.store', attribute.id),
            {
                value: { en: draft.value },
                color_hex: draft.color_hex || undefined,
                sort_order: attribute.values.length,
            },
            {
                preserveScroll: true,
                onSuccess: () => setNewValues({ ...newValues, [attribute.id]: { value: '', color_hex: '' } }),
            },
        );
    }

    async function removeValue(attribute: AttributeRecord, value: AttributeValue) {
        if (!(await confirmAction({ title: t('admin.deleteConfirmQ', { name: value.value }), danger: true }))) return;
        router.delete(route('admin.attributes.values.destroy', [attribute.id, value.id]), { preserveScroll: true });
    }

    async function removeAttribute(attribute: AttributeRecord) {
        if (!(await confirmAction({ title: t('admin.deleteConfirmQ', { name: attribute.name }), danger: true })))
            return;
        router.delete(route('admin.attributes.destroy', attribute.id));
    }

    return (
        <AdminLayout title={t('admin.attributes')}>
            <Head title={t('admin.attributes')} />

            {can('attributes.create') && (
                <div className="row">
                    <div className="col-lg-4">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.newAttribute')}</h4>
                            </div>
                            <div className="card-body d-flex gap-2">
                                <input
                                    className="form-control"
                                    placeholder={t('admin.eGColorSize')}
                                    value={newAttributeName}
                                    onChange={(e) => setNewAttributeName(e.target.value)}
                                />
                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={createAttribute}
                                    disabled={!newAttributeName}
                                >
                                    {t('admin.add')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            <div className="row">
                {attributes.map((attribute) => (
                    <div className="col-lg-6" key={attribute.id}>
                        <div className="card">
                            <div className="card-header d-flex justify-content-between align-items-center">
                                <h4 className="card-title">{attribute.name}</h4>
                                {can('attributes.delete') && (
                                    <button
                                        type="button"
                                        className="btn btn-soft-danger btn-sm"
                                        onClick={() => removeAttribute(attribute)}
                                    >
                                        <i className="bx bx-trash align-middle" />
                                    </button>
                                )}
                            </div>
                            <ul className="list-group list-group-flush">
                                {attribute.values.map((value) => (
                                    <li
                                        key={value.id}
                                        className="list-group-item d-flex justify-content-between align-items-center"
                                    >
                                        <span>
                                            {value.color_hex && (
                                                <span
                                                    className="d-inline-block me-2 rounded-circle border"
                                                    style={{ width: 14, height: 14, backgroundColor: value.color_hex }}
                                                />
                                            )}
                                            {value.value}
                                        </span>
                                        {can('attributes.delete') && (
                                            <button
                                                type="button"
                                                className="btn btn-soft-danger btn-sm"
                                                onClick={() => removeValue(attribute, value)}
                                            >
                                                <i className="bx bx-trash align-middle" />
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {can('attributes.create') && (
                                <div className="card-body d-flex gap-2">
                                    <input
                                        className="form-control form-control-sm"
                                        placeholder={t('admin.value')}
                                        value={newValues[attribute.id]?.value ?? ''}
                                        onChange={(e) =>
                                            setNewValues({
                                                ...newValues,
                                                [attribute.id]: {
                                                    ...newValues[attribute.id],
                                                    value: e.target.value,
                                                    color_hex: newValues[attribute.id]?.color_hex ?? '',
                                                },
                                            })
                                        }
                                    />
                                    <input
                                        type="color"
                                        className="form-control form-control-sm"
                                        style={{ width: 48 }}
                                        title={t('admin.optionalColorSwatch')}
                                        value={newValues[attribute.id]?.color_hex || '#ffffff'}
                                        onChange={(e) =>
                                            setNewValues({
                                                ...newValues,
                                                [attribute.id]: {
                                                    ...newValues[attribute.id],
                                                    color_hex: e.target.value,
                                                    value: newValues[attribute.id]?.value ?? '',
                                                },
                                            })
                                        }
                                    />
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-primary"
                                        onClick={() => addValue(attribute)}
                                    >
                                        {t('admin.add')}
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </AdminLayout>
    );
}
