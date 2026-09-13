import { Head, Link } from '@inertiajs/react';
import { PaginationFooter } from '../../Components/Pagination';
import RowActions from '../../Components/RowActions';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

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
    const { t } = useTranslation();
    return (
        <AdminLayout title={t('admin.promotions')}>
            <Head title={t('admin.promotions')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allPromotions')}</h4>
                            <Link href={route('admin.promotions.create')} className="btn btn-sm btn-primary">
                                {t('admin.addPromotion')}
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.name')}</th>
                                        <th>{t('admin.type')}</th>
                                        <th>{t('admin.discount')}</th>
                                        <th>{t('admin.used')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.action')}</th>
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
                                                {t('admin.noPromotionsYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={promotions} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
