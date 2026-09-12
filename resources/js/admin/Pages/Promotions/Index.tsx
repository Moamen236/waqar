import { Head, Link } from '@inertiajs/react';
import Pagination from '../../Components/Pagination';
import RowActions from '../../Components/RowActions';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { PaginatedData } from '../../types';

interface PromotionRecord {
    id: number;
    name: string;
    type: string;
    discount_type: string;
    discount_value: string | null;
    is_active: boolean;
    times_used: number;
}

// Ported from Admin Template/coupons-list.html's table conventions —
// the closest domain match for a discount-campaign list.
export default function PromotionsIndex({ promotions }: { promotions: PaginatedData<PromotionRecord> }) {
    return (
        <AdminLayout title="Promotions">
            <Head title="Promotions" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">All Promotions</h4>
                            <Link href={route('admin.promotions.create')} className="btn btn-sm btn-primary">
                                Add Promotion
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Discount</th>
                                        <th>Used</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {promotions.data.map((promotion) => (
                                        <tr key={promotion.id}>
                                            <td className="fw-medium">{promotion.name}</td>
                                            <td>{promotion.type === 'bundle' ? 'Bundle' : 'Buy X Get Y'}</td>
                                            <td>
                                                {promotion.discount_type}
                                                {promotion.discount_value ? ` — ${promotion.discount_value}` : ''}
                                            </td>
                                            <td>{promotion.times_used}</td>
                                            <td>
                                                <StatusBadge status={promotion.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td>
                                                <RowActions editHref={route('admin.promotions.edit', promotion.id)} />
                                            </td>
                                        </tr>
                                    ))}
                                    {promotions.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                No promotions yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {promotions.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={promotions} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
