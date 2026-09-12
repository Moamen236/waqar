import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import Pagination from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import RowActions from '../../Components/RowActions';
import AdminLayout from '../../Layouts/AdminLayout';
import type { PaginatedData } from '../../types';

interface ProductRecord {
    id: number;
    name: string;
    sku: string;
    price: string;
    sale_price: string | null;
    status: boolean;
    product_type: string;
    variants_count: number;
    categories: { id: number; name: string }[];
}

// Ported from Admin Template/product-list.html's card/table structure —
// card-header (title + Add action), table.table-hover.table-centered
// with a bg-light-subtle thead, card-footer pagination. See
// [[admin-ui-use-larkon-template]] in memory for why this isn't a
// generic react-bootstrap Card/Table.
export default function ProductsIndex({ products, q }: { products: PaginatedData<ProductRecord>; q: string | null }) {
    const [search, setSearch] = useState(q ?? '');

    function submitSearch(e: FormEvent) {
        e.preventDefault();
        router.get(route('admin.products.index'), { q: search }, { preserveState: true });
    }

    return (
        <AdminLayout title="Products">
            <Head title="Products" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">All Products</h4>
                            <form onSubmit={submitSearch} className="d-flex gap-2">
                                <input
                                    type="text"
                                    className="form-control form-control-sm"
                                    placeholder="Search name or SKU…"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </form>
                            <Link href={route('admin.products.create')} className="btn btn-sm btn-primary">
                                Add Product
                            </Link>
                        </div>
                        <div>
                            <div className="table-responsive">
                                <table className="table align-middle mb-0 table-hover table-centered">
                                    <thead className="bg-light-subtle">
                                        <tr>
                                            <th>Product</th>
                                            <th>SKU</th>
                                            <th>Price</th>
                                            <th>Type</th>
                                            <th>Categories</th>
                                            <th>Variants</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {products.data.map((product) => (
                                            <tr key={product.id}>
                                                <td className="fw-medium">{product.name}</td>
                                                <td className="text-muted">{product.sku}</td>
                                                <td>
                                                    {product.sale_price ? (
                                                        <>
                                                            <span className="text-decoration-line-through text-muted me-1">
                                                                {product.price}
                                                            </span>
                                                            {product.sale_price}
                                                        </>
                                                    ) : (
                                                        product.price
                                                    )}
                                                </td>
                                                <td>
                                                    <span
                                                        className={`badge px-2 py-1 ${product.product_type === 'real' ? 'bg-success-subtle text-success' : 'bg-info-subtle text-info'}`}
                                                    >
                                                        {product.product_type}
                                                    </span>
                                                </td>
                                                <td>{product.categories.map((c) => c.name).join(', ')}</td>
                                                <td>{product.variants_count}</td>
                                                <td>
                                                    <StatusBadge status={product.status ? 'active' : 'inactive'} />
                                                </td>
                                                <td>
                                                    <RowActions editHref={route('admin.products.edit', product.id)} />
                                                </td>
                                            </tr>
                                        ))}
                                        {products.data.length === 0 && (
                                            <tr>
                                                <td colSpan={8} className="text-center text-muted py-4">
                                                    No products found.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        {products.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={products} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
