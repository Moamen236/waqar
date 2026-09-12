import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import { useCallback, useState } from 'react';
import { useDropzone } from 'react-dropzone';
import ReactQuill from 'react-quill-new';
import 'react-quill-new/dist/quill.snow.css';
import AdminLayout from '../../Layouts/AdminLayout';

interface CollectionRecord {
    id: number;
    name: string;
    description: string | null;
    slug: string;
    image: string | null;
    is_active: boolean;
    sort_order: number;
}

// Ported from Admin Template/category-add.html's General Information +
// Add Thumbnail Photo card layout.
export default function CollectionForm({ collection }: { collection: CollectionRecord | null }) {
    const [preview, setPreview] = useState<string | null>(collection?.image ? `/storage/${collection.image}` : null);

    const { data, setData, post, put, processing, errors } = useForm<{
        name: { en: string; ar: string };
        description: { en: string; ar: string };
        slug: string;
        is_active: boolean;
        sort_order: number;
        image: File | null;
    }>({
        name: { en: collection?.name ?? '', ar: '' },
        description: { en: collection?.description ?? '', ar: '' },
        slug: collection?.slug ?? '',
        is_active: collection?.is_active ?? true,
        sort_order: collection?.sort_order ?? 0,
        image: null,
    });

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
        <AdminLayout title={collection ? 'Edit Collection' : 'New Collection'}>
            <Head title={collection ? 'Edit Collection' : 'New Collection'} />
            <form onSubmit={submit}>
                <div className="row">
                    <div className="col-xl-3 col-lg-4">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">Thumbnail</h4>
                            </div>
                            <div className="card-body">
                                <div
                                    {...getRootProps()}
                                    className={`dropzone bg-light-subtle py-4 ${isDragActive ? 'bg-light' : ''}`}
                                >
                                    <input {...getInputProps()} />
                                    {preview ? (
                                        <img src={preview} alt="Preview" className="img-fluid rounded" />
                                    ) : (
                                        <div className="dz-message needsclick text-center">
                                            <i className="bx bx-cloud-upload fs-36 text-primary" />
                                            <p className="mb-0 text-muted fs-13">Drag an image, or click to select</p>
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="card-footer border-top">
                                <div className="mb-3">
                                    <label className="form-label">Sort Order</label>
                                    <input
                                        type="number"
                                        className="form-control"
                                        value={data.sort_order}
                                        onChange={(e) => setData('sort_order', Number(e.target.value))}
                                    />
                                </div>
                                <div className="form-check">
                                    <input
                                        type="checkbox"
                                        className="form-check-input"
                                        id="active"
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="active">
                                        Active
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="col-xl-9 col-lg-8">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">General Information</h4>
                            </div>
                            <div className="card-body">
                                <div className="row">
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">Name (English)</label>
                                            <input
                                                className="form-control"
                                                value={data.name.en}
                                                onChange={(e) => setData('name', { ...data.name, en: e.target.value })}
                                            />
                                            {errors['name.en'] && (
                                                <div className="text-danger small mt-1">{errors['name.en']}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">Name (Arabic)</label>
                                            <input
                                                className="form-control"
                                                dir="rtl"
                                                value={data.name.ar}
                                                onChange={(e) => setData('name', { ...data.name, ar: e.target.value })}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">Slug (auto-generated if blank)</label>
                                            <input
                                                className="form-control"
                                                value={data.slug}
                                                onChange={(e) => setData('slug', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-12">
                                        <div className="mb-0">
                                            <label className="form-label">Description (English)</label>
                                            <ReactQuill
                                                theme="snow"
                                                value={data.description.en}
                                                onChange={(v) => setData('description', { ...data.description, en: v })}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="card-footer border-top text-end">
                                <button type="submit" className="btn btn-primary" disabled={processing}>
                                    Save Collection
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
