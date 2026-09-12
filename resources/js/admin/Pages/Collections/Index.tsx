import { Head, Link } from '@inertiajs/react';
import Pagination from '../../Components/Pagination';
import RowActions from '../../Components/RowActions';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { PaginatedData } from '../../types';

interface CollectionRecord {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    products_count: number;
}

export default function CollectionsIndex({ collections }: { collections: PaginatedData<CollectionRecord> }) {
    return (
        <AdminLayout title="Collections">
            <Head title="Collections" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">All Collections</h4>
                            <Link href={route('admin.collections.create')} className="btn btn-sm btn-primary">
                                Add Collection
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Name</th>
                                        <th>Slug</th>
                                        <th>Products</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {collections.data.map((collection) => (
                                        <tr key={collection.id}>
                                            <td className="fw-medium">{collection.name}</td>
                                            <td className="text-muted">{collection.slug}</td>
                                            <td>{collection.products_count}</td>
                                            <td>
                                                <StatusBadge status={collection.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td>
                                                <RowActions editHref={route('admin.collections.edit', collection.id)} />
                                            </td>
                                        </tr>
                                    ))}
                                    {collections.data.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="text-center text-muted py-4">
                                                No collections yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {collections.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={collections} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
