import { Head, Link } from '@inertiajs/react';
import Pagination from '../../Components/Pagination';
import RowActions from '../../Components/RowActions';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { PaginatedData } from '../../types';

interface CategoryRecord {
    id: number;
    name: string;
    slug: string;
    status: boolean;
    parent: { id: number; name: string } | null;
}

// Ported from Admin Template/category-list.html's table structure.
export default function CategoriesIndex({ categories }: { categories: PaginatedData<CategoryRecord> }) {
    return (
        <AdminLayout title="Categories">
            <Head title="Categories" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">All Categories</h4>
                            <Link href={route('admin.categories.create')} className="btn btn-sm btn-primary">
                                Add Category
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Name</th>
                                        <th>Slug</th>
                                        <th>Parent</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {categories.data.map((category) => (
                                        <tr key={category.id}>
                                            <td className="fw-medium">{category.name}</td>
                                            <td className="text-muted">{category.slug}</td>
                                            <td>{category.parent?.name ?? '—'}</td>
                                            <td>
                                                <StatusBadge status={category.status ? 'active' : 'inactive'} />
                                            </td>
                                            <td>
                                                <RowActions editHref={route('admin.categories.edit', category.id)} />
                                            </td>
                                        </tr>
                                    ))}
                                    {categories.data.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="text-center text-muted py-4">
                                                No categories yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {categories.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={categories} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
