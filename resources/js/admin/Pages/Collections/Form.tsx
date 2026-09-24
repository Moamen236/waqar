import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import { useCallback, useState } from 'react';
import { useDropzone } from 'react-dropzone';
import ReactQuill from 'react-quill-new';
import 'react-quill-new/dist/quill.snow.css';
import FieldError from '../../Components/Form/FieldError';
import FormField from '../../Components/Form/FormField';
import AdminLayout from '../../Layouts/AdminLayout';
import { invalidClass, invalidProps, useClearErrorsOnChange } from '../../lib/formErrors';
import { useTranslation } from '../../lib/useTranslation';

interface CollectionRecord {
    id: number;
    // Both languages: this is an authoring screen, so the controller
    // sends getTranslations() rather than the serialized current-locale
    // string. Typed as a string it silently dropped the Arabic name on
    // every edit — the form posted `ar: ''` back over it.
    name: { en: string; ar: string };
    description: { en: string; ar: string } | null;
    slug: string;
    image: string | null;
    is_active: boolean;
    sort_order: number;
}

// Ported from Admin Template/category-add.html's General Information +
// Add Thumbnail Photo card layout.
export default function CollectionForm({ collection }: { collection: CollectionRecord | null }) {
    const { t } = useTranslation();
    const [preview, setPreview] = useState<string | null>(collection?.image ? `/storage/${collection.image}` : null);

    const { data, setData, post, put, processing, errors, clearErrors } = useForm<{
        name: { en: string; ar: string };
        description: { en: string; ar: string };
        slug: string;
        is_active: boolean;
        sort_order: number;
        image: File | null;
    }>({
        name: { en: collection?.name.en ?? '', ar: collection?.name.ar ?? '' },
        description: { en: collection?.description?.en ?? '', ar: collection?.description?.ar ?? '' },
        slug: collection?.slug ?? '',
        is_active: collection?.is_active ?? true,
        sort_order: collection?.sort_order ?? 0,
        image: null,
    });
    useClearErrorsOnChange(data, errors, clearErrors);
    const error = errors as Partial<Record<string, string>>;

    const onDrop = useCallback(
        (files: File[]) => {
            const file = files[0];
            if (!file) return;
            setData('image', file);
            setPreview(URL.createObjectURL(file));
        },
        [setData],
    );
    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop,
        accept: { 'image/*': [] },
        maxFiles: 1,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (collection) {
            put(route('admin.collections.update', collection.id));
        } else {
            post(route('admin.collections.store'));
        }
    };

    return (
        <AdminLayout
            title={collection ? t('admin.editCollection') : t('admin.newCollection')}
            breadcrumbs={[{ label: t('admin.collections'), href: route('admin.collections.index') }]}
        >
            <Head title={collection ? t('admin.editCollection') : t('admin.newCollection')} />
            <form onSubmit={submit}>
                <div className="row">
                    <div className="col-xl-3 col-lg-4">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.thumbnail')}</h4>
                            </div>
                            <div className="card-body">
                                <FormField name="image" error={error.image} className="mb-0">
                                    <div
                                        {...getRootProps()}
                                        className={`dropzone bg-light-subtle py-4 ${isDragActive ? 'bg-light' : ''}`}
                                    >
                                        <input {...getInputProps()} />
                                        {preview ? (
                                            <img src={preview} alt={t('admin.preview')} className="img-fluid rounded" />
                                        ) : (
                                            <div className="dz-message needsclick text-center">
                                                <i className="bx bx-cloud-upload fs-36 text-primary" />
                                                <p className="mb-0 text-muted fs-13">
                                                    {t('admin.dragAnImageOrClickTo')}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </FormField>
                            </div>
                            <div className="card-footer border-top">
                                <FormField
                                    name="sort_order"
                                    label={t('admin.sortOrder')}
                                    error={error.sort_order}
                                    required
                                >
                                    <input
                                        type="number"
                                        className="form-control"
                                        value={data.sort_order}
                                        onChange={(e) => setData('sort_order', Number(e.target.value))}
                                    />
                                </FormField>
                                <div className="form-check">
                                    <input
                                        type="checkbox"
                                        className={`form-check-input${invalidClass(error.is_active)}`}
                                        {...invalidProps('is_active', error.is_active, 'active')}
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="active">
                                        {t('admin.active')}
                                    </label>
                                </div>
                                <FieldError name="is_active" message={error.is_active} id="active" />
                            </div>
                        </div>
                    </div>

                    <div className="col-xl-9 col-lg-8">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.generalInformation')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="row">
                                    <div className="col-lg-6">
                                        <FormField
                                            name="name.en"
                                            label={t('admin.nameEnglish')}
                                            error={error['name.en']}
                                            required
                                        >
                                            <input
                                                className="form-control"
                                                value={data.name.en}
                                                onChange={(e) => setData('name', { ...data.name, en: e.target.value })}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="name.ar"
                                            label={t('admin.nameArabic')}
                                            error={error['name.ar']}
                                        >
                                            <input
                                                className="form-control"
                                                dir="rtl"
                                                value={data.name.ar}
                                                onChange={(e) => setData('name', { ...data.name, ar: e.target.value })}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="slug"
                                            label={t('admin.slugAutoGeneratedIfBlank')}
                                            error={error.slug}
                                        >
                                            <input
                                                className="form-control"
                                                value={data.slug}
                                                onChange={(e) => setData('slug', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-12">
                                        <FormField
                                            name="description.en"
                                            label={t('admin.descriptionEnglish')}
                                            error={error['description.en']}
                                            className="mb-0"
                                        >
                                            <ReactQuill
                                                theme="snow"
                                                value={data.description.en}
                                                onChange={(v) => setData('description', { ...data.description, en: v })}
                                            />
                                        </FormField>
                                    </div>
                                </div>
                            </div>
                            <div className="card-footer border-top text-end">
                                <button type="submit" className="btn btn-primary" disabled={processing}>
                                    {t('admin.saveCollection')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
