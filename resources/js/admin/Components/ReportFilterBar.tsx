import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from '../lib/useTranslation';

export interface ReportFilters {
    preset?: string;
    date_from?: string;
    date_to?: string;
    date_basis?: string;
    compare_to?: string;
    granularity?: string;
    order_source?: string;
    status?: string[];
    payment_status?: string[];
    employee_id?: number[];
    warehouse_id?: number[];
    category_id?: number[];
    governorate_id?: number | string;
    representative_id?: number[];
    shipping_company_id?: number[];
    customer?: string;
    coupon_id?: number | string;
    [key: string]: unknown;
}

interface Option {
    id: number;
    name?: string;
    full_name?: string;
    code?: string;
}

const PRESETS = [
    'today',
    'yesterday',
    'this_week',
    'last_week',
    'this_month',
    'last_month',
    'this_quarter',
    'last_quarter',
    'this_year',
    'last_year',
    'custom',
];

const ORDER_STATUSES = [
    'New',
    'Checking',
    'Confirmed',
    'Postponed',
    'Backorder',
    'Cancelled',
    'Assigned',
    'Out for Delivery',
    'Delivered',
    'Partially Returned',
    'Returned',
];

const PAYMENT_STATUSES = ['pending', 'collected', 'partially_collected', 'not_collected', 'refunded'];

/**
 * The standard filter bundle (spec A.3) as one component.
 *
 * It renders only the controls the report declared in `filters()`, so a
 * stock snapshot never shows a date-basis switch and an orders report
 * never shows a warehouse picker it would ignore. `available` is the
 * report's own list, arriving from the server.
 */
export default function ReportFilterBar({
    reportKey,
    available,
    dateBases,
    supportsComparison,
    filters,
    options,
}: {
    reportKey: string;
    available: string[];
    dateBases: string[];
    supportsComparison: boolean;
    filters: ReportFilters;
    options: Record<string, Option[] | unknown>;
}) {
    const { t } = useTranslation();
    const [draft, setDraft] = useState<ReportFilters>(filters);

    const has = (key: string) => available.includes(key);
    const set = (key: string, value: unknown) => setDraft((prev) => ({ ...prev, [key]: value }));

    const apply = () => {
        router.get(route('admin.reports.show', reportKey), draft as unknown as Record<string, string>, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const reset = () => {
        const cleared: ReportFilters = { preset: 'this_month', granularity: 'day' };
        setDraft(cleared);
        router.get(route('admin.reports.show', reportKey), cleared as unknown as Record<string, string>, {
            preserveState: true,
            replace: true,
        });
    };

    const multi = (key: string, list: Option[] | undefined, labelOf: (o: Option) => string) => (
        <select
            multiple
            className="form-select form-select-sm"
            size={4}
            value={((draft[key] as (number | string)[]) ?? []).map(String)}
            onChange={(event) =>
                set(
                    key,
                    Array.from(event.target.selectedOptions).map((option) => Number(option.value)),
                )
            }
        >
            {(list ?? []).map((option) => (
                <option key={option.id} value={option.id}>
                    {labelOf(option)}
                </option>
            ))}
        </select>
    );

    return (
        <div className="card mb-3">
            <div className="card-body">
                <div className="row g-2 align-items-end">
                    <div className="col-md-3 col-lg-2">
                        <label className="form-label small mb-1">{t('reports.filter.period')}</label>
                        <select
                            className="form-select form-select-sm"
                            value={draft.preset ?? 'this_month'}
                            onChange={(event) => set('preset', event.target.value)}
                        >
                            {PRESETS.map((preset) => (
                                <option key={preset} value={preset}>
                                    {t(`reports.preset.${preset}`)}
                                </option>
                            ))}
                        </select>
                    </div>

                    {draft.preset === 'custom' && (
                        <>
                            <div className="col-md-3 col-lg-2">
                                <label className="form-label small mb-1">{t('reports.filter.from')}</label>
                                <input
                                    type="date"
                                    className="form-control form-control-sm"
                                    value={draft.date_from ?? ''}
                                    onChange={(event) => set('date_from', event.target.value)}
                                />
                            </div>
                            <div className="col-md-3 col-lg-2">
                                <label className="form-label small mb-1">{t('reports.filter.to')}</label>
                                <input
                                    type="date"
                                    className="form-control form-control-sm"
                                    value={draft.date_to ?? ''}
                                    onChange={(event) => set('date_to', event.target.value)}
                                />
                            </div>
                        </>
                    )}

                    {dateBases.length > 1 && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.dateBasis')}</label>
                            <select
                                className="form-select form-select-sm"
                                value={draft.date_basis ?? dateBases[0]}
                                onChange={(event) => set('date_basis', event.target.value)}
                            >
                                {dateBases.map((basis) => (
                                    <option key={basis} value={basis}>
                                        {t(`reports.basis.${basis}`)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {has('granularity') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.granularity')}</label>
                            <select
                                className="form-select form-select-sm"
                                value={draft.granularity ?? 'day'}
                                onChange={(event) => set('granularity', event.target.value)}
                            >
                                {['day', 'week', 'month', 'quarter', 'year'].map((value) => (
                                    <option key={value} value={value}>
                                        {t(`reports.granularity.${value}`)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {supportsComparison && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.compare')}</label>
                            <select
                                className="form-select form-select-sm"
                                value={draft.compare_to ?? 'none'}
                                onChange={(event) => set('compare_to', event.target.value)}
                            >
                                {['none', 'previous_period', 'same_period_last_year'].map((value) => (
                                    <option key={value} value={value}>
                                        {t(`reports.compare.${value}`)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {has('order_source') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.source')}</label>
                            <select
                                className="form-select form-select-sm"
                                value={(draft.order_source as string) ?? ''}
                                onChange={(event) => set('order_source', event.target.value)}
                            >
                                <option value="">{t('reports.filter.all')}</option>
                                <option value="website">{t('reports.source.website')}</option>
                                <option value="customer_service">{t('reports.source.customer_service')}</option>
                            </select>
                        </div>
                    )}

                    {has('governorate_id') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.governorate')}</label>
                            <select
                                className="form-select form-select-sm"
                                value={(draft.governorate_id as string) ?? ''}
                                onChange={(event) => set('governorate_id', event.target.value)}
                            >
                                <option value="">{t('reports.filter.all')}</option>
                                {((options.geoTree as { id: number; name: string }[] | undefined) ?? []).map(
                                    (governorate) => (
                                        <option key={governorate.id} value={governorate.id}>
                                            {governorate.name}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                    )}

                    {has('status') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.status')}</label>
                            <select
                                multiple
                                size={4}
                                className="form-select form-select-sm"
                                value={draft.status ?? []}
                                onChange={(event) =>
                                    set(
                                        'status',
                                        Array.from(event.target.selectedOptions).map((o) => o.value),
                                    )
                                }
                            >
                                {ORDER_STATUSES.map((status) => (
                                    <option key={status} value={status}>
                                        {t(`status.${status}`)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {has('payment_status') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.paymentStatus')}</label>
                            <select
                                multiple
                                size={4}
                                className="form-select form-select-sm"
                                value={draft.payment_status ?? []}
                                onChange={(event) =>
                                    set(
                                        'payment_status',
                                        Array.from(event.target.selectedOptions).map((o) => o.value),
                                    )
                                }
                            >
                                {PAYMENT_STATUSES.map((status) => (
                                    <option key={status} value={status}>
                                        {t(`status.${status}`)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {has('warehouse_id') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.warehouse')}</label>
                            {multi('warehouse_id', options.warehouses as Option[], (o) => o.name ?? '')}
                        </div>
                    )}

                    {has('employee_id') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.employee')}</label>
                            {multi('employee_id', options.employees as Option[], (o) => o.full_name ?? '')}
                        </div>
                    )}

                    {has('category_id') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.category')}</label>
                            {multi('category_id', options.categories as Option[], (o) =>
                                typeof o.name === 'string' ? o.name : '',
                            )}
                        </div>
                    )}

                    {has('shipping_company_id') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.shippingCompany')}</label>
                            {multi('shipping_company_id', options.shippingCompanies as Option[], (o) => o.name ?? '')}
                        </div>
                    )}

                    {has('representative_id') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.representative')}</label>
                            {multi('representative_id', options.representatives as Option[], (o) => o.name ?? '')}
                        </div>
                    )}

                    {has('customer') && (
                        <div className="col-md-3 col-lg-2">
                            <label className="form-label small mb-1">{t('reports.filter.customer')}</label>
                            <input
                                type="search"
                                className="form-control form-control-sm"
                                value={(draft.customer as string) ?? ''}
                                onChange={(event) => set('customer', event.target.value)}
                            />
                        </div>
                    )}

                    <div className="col-md-3 col-lg-2 d-flex gap-2">
                        <button type="button" className="btn btn-sm btn-primary" onClick={apply}>
                            <i className="bx bx-filter-alt me-1" />
                            {t('reports.filter.apply')}
                        </button>
                        <button type="button" className="btn btn-sm btn-soft-secondary" onClick={reset}>
                            {t('reports.filter.reset')}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
