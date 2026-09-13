import { Head, Link, router } from '@inertiajs/react';
import { confirmAction } from '../../lib/confirm';
import { EmptyRow } from '../../Components/EmptyState';
import { PaginationFooter } from '../../Components/Pagination';
import SearchFilter from '../../Components/SearchFilter';
import StatusBadge from '../../Components/StatusBadge';
import RowActions from '../../Components/RowActions';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

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
    thumbnail: string | null;
}

// Ported from Admin Template/product-list.html's card/table structure —
// card-header (title + Add action), table.table-hover.table-centered
// with a bg-light-subtle thead, card-footer pagination. See
// [[admin-ui-use-larkon-template]] in memory for why this isn't a
// generic react-bootstrap Card/Table.
export default function ProductsIndex({ products, q }: { products: PaginatedData<ProductRecord>; q: string | null }) {
    const { t, price } = useTranslation();
    const { can } = usePermissions();

    function submitSearch(term: string) {
        router.get(route('admin.products.index'), { q: term }, { preserveState: true });
    }

    // Soft delete: the controller calls $product->delete() on a model using
    // SoftDeletes, so the row survives with a `deleted_at` stamp and the
    // order lines and inventory movements that reference it stay intact.
    // The dialog says so, because "delete" otherwise reads as permanent.
    async function remove(id: number, name: string) {
        if (
            !(await confirmAction({
                title: t('admin.deleteProductQ'),
                text: `${name} — ${t('admin.deleteProductHint')}`,
                confirmText: t('admin.delete'),
                danger: true,
            }))
        ) {
            return;
        }

        router.delete(route('admin.products.destroy', id), { preserveScroll: true });
    }

    return (
        <AdminLayout title={t('admin.products')}>
            <Head title={t('admin.products')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allProducts')}</h4>
                            <SearchFilter
                                value={q ?? ''}
                                placeholder={t('admin.searchNameOrSku')}
                                onSubmit={submitSearch}
                            />
                            {can('products.create') && (
                                <Link
                                    href={route('admin.products.create')}
                                    className="btn btn-sm btn-primary d-flex align-items-center"
                                >
                                    <i className="bx bx-plus me-1" />
                                    {t('admin.addProduct')}
                                </Link>
                            )}
                        </div>
                        <div>
                            <div className="table-responsive">
                                <table className="table align-middle mb-0 table-hover table-centered">
                                    <thead className="bg-light-subtle">
                                        <tr>
                                            <th>{t('admin.product')}</th>
                                            <th>SKU</th>
                                            <th>{t('admin.price')}</th>
                                            <th>{t('admin.type')}</th>
                                            <th>{t('admin.categories')}</th>
                                            <th>{t('admin.status')}</th>
                                            <th>{t('admin.action')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {products.data.map((product) => (
                                            <tr key={product.id}>
                                                <td>
                                                    {/* product-list.html's own lead cell: a fixed
                                                        `avatar-md` tile, the name as a `fs-15` link and
                                                        the variant count as its `fs-13` subtitle. */}
                                                    <div className="d-flex align-items-center gap-2">
                                                        <div className="rounded bg-light avatar-md d-flex align-items-center justify-content-center flex-shrink-0 overflow-hidden">
                                                            {product.thumbnail ? (
                                                                <img
                                                                    src={product.thumbnail}
                                                                    alt=""
                                                                    className="avatar-md object-fit-cover"
                                                                />
                                                            ) : (
                                                                <i className="bx bx-image text-muted fs-24" />
                                                            )}
                                                        </div>
                                                        <div>
                                                            <Link
                                                                href={route('admin.products.show', product.id)}
                                                                className="text-dark fw-medium fs-15"
                                                            >
                                                                {product.name}
                                                            </Link>
                                                            <p className="text-muted mb-0 mt-1 fs-13">
                                                                {product.variants_count} {t('admin.variants')}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="text-muted">
                                                    <span dir="ltr" className="text-nowrap">
                                                        {product.sku}
                                                    </span>
                                                </td>
                                                <td>
                                                    {/* Stacked rather than inline. A struck original beside
                                                        a sale price is two mixed-direction runs (Latin
                                                        digits, an Arabic currency suffix) on one line, and
                                                        the bidi algorithm reordered them into each other —
                                                        the struck figure landed on the wrong side of the
                                                        one actually being charged. One run per line, each
                                                        isolated, cannot reorder. */}
                                                    <span className="fw-medium d-block text-nowrap" dir="ltr">
                                                        {price(Number(product.sale_price ?? product.price))}
                                                    </span>
                                                    {product.sale_price && (
                                                        <span
                                                            className="text-decoration-line-through text-muted fs-13 d-block text-nowrap"
                                                            dir="ltr"
                                                        >
                                                            {price(Number(product.price))}
                                                        </span>
                                                    )}
                                                </td>
                                                <td>
                                                    <span
                                                        className={`badge px-2 py-1 ${product.product_type === 'real' ? 'bg-success-subtle text-success' : 'bg-info-subtle text-info'}`}
                                                    >
                                                        {t(`productType.${product.product_type}`)}
                                                    </span>
                                                </td>
                                                <td>{product.categories.map((c) => c.name).join(', ')}</td>
                                                <td>
                                                    <StatusBadge status={product.status ? 'active' : 'inactive'} />
                                                </td>
                                                <td>
                                                    <RowActions
                                                        viewHref={route('admin.products.show', product.id)}
                                                        editHref={
                                                            can('products.update')
                                                                ? route('admin.products.edit', product.id)
                                                                : undefined
                                                        }
                                                        onDelete={
                                                            can('products.delete')
                                                                ? () => remove(product.id, product.name)
                                                                : undefined
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                        {products.data.length === 0 && (
                                            <EmptyRow
                                                colSpan={7}
                                                message={t('admin.noProductsFound')}
                                                icon="bx-package"
                                            />
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        {/* The footer is conditional on there actually being
                            more than one page — an empty `card-footer` renders
                            as a stray grey strip under the last row. */}
                        {products.last_page > 1 && <PaginationFooter data={products} />}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
