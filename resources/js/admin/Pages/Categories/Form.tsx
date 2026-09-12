import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import AdminLayout from '../../Layouts/AdminLayout';

interface CategoryRecord {
    id: number;
    parent_id: number | null;
    name: { en: string; ar: string };
    description: { en: string; ar: string } | null;
    slug: string;
    status: boolean;
    sort_order: number;
}

interface CategoryOption {
    id: number;
    parent_id: number | null;
    name: string;
}

// Ported from Admin Template/category-add.html's General Information
// card layout.
export default function CategoryForm({
    category,
    categories,
}: {
    category: CategoryRecord | null;
    categories: CategoryOption[];
}) {
    const { data, setData, post, put, processing, errors } = useForm<{
        parent_id: number | '';
        name: { en: string; ar: string };
        description: { en: string; ar: string };
        slug: string;
        status: boolean;
        sort_order: number;
    }>({
        parent_id: category?.parent_id ?? '',
        name: { en: category?.name.en ?? '', ar: category?.name.ar ?? '' },
        description: { en: category?.description?.en ?? '', ar: category?.description?.ar ?? '' },
        slug: category?.slug ?? '',
        status: category?.status ?? true,
        sort_order: category?.sort_order ?? 0,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (category) {
            put(route('admin.categories.update', category.id));
        } else {
            post(route('admin.categories.store'));
        }
    };

    return (
        <AdminLayout title={category ? 'Edit Category' : 'New Category'}>
            <Head title={category ? 'Edit Category' : 'New Category'} />
            <form onSubmit={submit}>
                <div className="row">
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
                                            <label className="form-label">Parent Category</label>
                                            <select
                                                className="form-control"
                                                value={data.parent_id}
                                                onChange={(e) =>
                                                    setData('parent_id', e.target.value ? Number(e.target.value) : '')
                                                }
                                            >
                                                <option value="">None (top-level)</option>
                                                {categories.map((c) => (
                                                    <option key={c.id} value={c.id}>
                                                        {c.name}
                                                    </option>
                                                ))}
                                            </select>
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
                                            <textarea
                                                className="form-control bg-light-subtle"
                                                rows={5}
                                                value={data.description.en}
                                                onChange={(e) =>
                                                    setData('description', { ...data.description, en: e.target.value })
                                                }
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="card-footer border-top text-end">
                                <button type="submit" className="btn btn-primary" disabled={processing}>
                                    Save Category
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="col-xl-3 col-lg-4">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">Status</h4>
                            </div>
                            <div className="card-body">
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
                                        id="status"
                                        checked={data.status}
                                        onChange={(e) => setData('status', e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="status">
                                        Active
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
