import { Head, Link, router } from '@inertiajs/react';
import { PaginationFooter } from '../../../Components/Pagination';
import RowActions from '../../../Components/RowActions';
import StatusBadge from '../../../Components/StatusBadge';
import AdminLayout from '../../../Layouts/AdminLayout';
import { confirmAction } from '../../../lib/confirm';
import type { PaginatedData } from '../../../types';
import { useTranslation } from '../../../lib/useTranslation';

interface ShippingRateRow {
    id: number;
    geo_type: string;
    geo_id: number;
    geo_label: string;
    price: string;
    free_shipping_threshold: string | null;
    is_active: boolean;
}

export default function ShippingRatesIndex({
    rates,
    uncoveredGovernorates,
}: {
    rates: PaginatedData<ShippingRateRow>;
    uncoveredGovernorates: string[];
}) {
    const { t } = useTranslation();
    const remove = async (id: number) => {
        const confirmed = await confirmAction({
            title: t('admin.removeThisShippingRate'),
            text: t('admin.shippingRateFallbackWarning'),
            confirmText: t('admin.remove'),
            danger: true,
        });

        if (confirmed) {
            router.delete(route('admin.delivery.shipping-rates.destroy', id));
        }
    };

    return (
        <AdminLayout title={t('admin.shippingRates')}>
            <Head title={t('admin.shippingRates')} />

            {uncoveredGovernorates.length > 0 && (
                <div className="alert alert-warning d-flex align-items-center gap-2" role="alert">
                    <i className="bx bx-error-circle fs-20" />
                    <div>{t('admin.noRateConfiguredFor', { places: uncoveredGovernorates.join('، ') })}</div>
                </div>
            )}

            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allShippingRates')}</h4>
                            <Link
                                href={route('admin.delivery.shipping-rates.create')}
                                className="btn btn-sm btn-primary"
                            >
                                {t('admin.addShippingRate')}
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.level')}</th>
                                        <th>{t('admin.location')}</th>
                                        <th>{t('admin.price')}</th>
                                        <th>{t('admin.freeOver')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rates.data.map((rate) => (
                                        <tr key={rate.id}>
                                            <td className="text-capitalize">{rate.geo_type}</td>
                                            <td className="fw-medium">{rate.geo_label}</td>
                                            <td>{rate.price}</td>
                                            <td>{rate.free_shipping_threshold ?? '—'}</td>
                                            <td>
                                                <StatusBadge status={rate.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td>
                                                <RowActions
                                                    editHref={route('admin.delivery.shipping-rates.edit', rate.id)}
                                                    onDelete={() => remove(rate.id)}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {rates.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                {t('admin.noShippingRatesYet')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={rates} />
                    </div>
                </div>
            </div>

            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.howARateIsChosen')}</h4>
                        </div>
                        <div className="card-body">
                            <p className="text-muted mb-0">
                                {t('admin.atCheckoutMostSpecificWins')}{' '}
                                <strong>{t('admin.areaDistrictCityGovernorate')}</strong>. {t('admin.rateBaselineNote')}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
