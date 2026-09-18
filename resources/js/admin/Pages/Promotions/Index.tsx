import { Head, Link, router } from '@inertiajs/react';
import { confirmAction } from '../../lib/confirm';
import { PaginationFooter } from '../../Components/Pagination';
import RowActions from '../../Components/RowActions';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
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
    const { can } = usePermissions();

    async function remove(id: number, name: string) {
        if (
            !(await confirmAction({
                title: t('admin.deleteConfirmQ', { name }),
                confirmText: t('admin.delete'),
                danger: true,
            }))
        ) {
            return;
        }

        router.delete(route('admin.promotions.destroy', id), { preserveScroll: true });
    }

    return (
        <AdminLayout title={t('admin.promotions')}>
            <Head title={t('admin.promotions')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allPromotions')}</h4>
                            {can('promotions.create') && (
                                <Link href={route('admin.promotions.create')} className="btn btn-sm btn-primary">
                                    {t('admin.addPromotion')}
                                </Link>
                            )}
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
                                                <RowActions
                                                    editHref={
                                                        can('promotions.update')
                                                            ? route('admin.promotions.edit', promotion.id)
                                                            : undefined
                                                    }
                                                    onDelete={
                                                        can('promotions.delete')
                                                            ? () => remove(promotion.id, promotion.name)
                                                            : undefined
                                                    }
                                                />
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
