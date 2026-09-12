import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { type UseFieldArrayReturn, useFieldArray, useForm } from 'react-hook-form';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';

type TargetType = 'variant' | 'category' | 'collection';

interface TargetRow {
    target_type: TargetType;
    target_id: number | null;
    quantity: number;
}

interface Option {
    id: number;
    label?: string;
    name?: string;
}

interface PromotionRecord {
    id: number;
    name: string;
    description: string | null;
    type: 'bundle' | 'buy_x_get_y';
    discount_type: string;
    discount_value: string | null;
    starts_at: string | null;
    ends_at: string | null;
    priority: number;
    stackable_with_coupons: boolean;
    usage_limit: number | null;
    usage_limit_per_customer: number | null;
    is_active: boolean;
    items: {
        product_variant_id: number | null;
        category_id: number | null;
        collection_id: number | null;
        quantity: number;
    }[];
    rewards: {
        product_variant_id: number | null;
        category_id: number | null;
        collection_id: number | null;
        quantity: number;
    }[];
}

function toRows(rows: PromotionRecord['items']): TargetRow[] {
    return rows.map((r) => ({
        target_type: r.product_variant_id ? 'variant' : r.category_id ? 'category' : 'collection',
        target_id: r.product_variant_id ?? r.category_id ?? r.collection_id,
        quantity: r.quantity,
    }));
}

function toPayload(rows: TargetRow[]) {
    return rows
        .filter((r) => r.target_id !== null)
        .map((r) => ({
            product_variant_id: r.target_type === 'variant' ? r.target_id : null,
            category_id: r.target_type === 'category' ? r.target_id : null,
            collection_id: r.target_type === 'collection' ? r.target_id : null,
            quantity: r.quantity,
        }));
}

interface PromotionFormValues {
    name_en: string;
    description_en: string;
    type: 'bundle' | 'buy_x_get_y';
    discount_type: string;
    discount_value: string;
    starts_at: string;
    ends_at: string;
    priority: number;
    stackable_with_coupons: boolean;
    usage_limit: string;
    usage_limit_per_customer: string;
    is_active: boolean;
    items: TargetRow[];
    rewards: TargetRow[];
}

// Ported from Admin Template/coupons-add.html's General Information card
// layout (the closest domain match — a discount campaign), reused for
// both bundle and buy-X-get-Y promotion types (Question 17).
export default function PromotionForm({
    promotion,
    variants,
    categories,
    collections,
}: {
    promotion: PromotionRecord | null;
    variants: Option[];
    categories: Option[];
    collections: Option[];
}) {
    const { t } = useTranslation();
    const [serverErrors, setServerErrors] = useState<Record<string, string>>({});

    const { register, control, handleSubmit, watch } = useForm<PromotionFormValues>({
        defaultValues: {
            name_en: promotion?.name ?? '',
            description_en: promotion?.description ?? '',
            type: promotion?.type ?? 'bundle',
            discount_type: promotion?.discount_type ?? 'percentage',
            discount_value: promotion?.discount_value ?? '',
            starts_at: promotion?.starts_at ?? '',
            ends_at: promotion?.ends_at ?? '',
            priority: promotion?.priority ?? 0,
            stackable_with_coupons: promotion?.stackable_with_coupons ?? false,
            usage_limit: promotion?.usage_limit?.toString() ?? '',
            usage_limit_per_customer: promotion?.usage_limit_per_customer?.toString() ?? '',
            is_active: promotion?.is_active ?? true,
            items: promotion ? toRows(promotion.items) : [{ target_type: 'variant', target_id: null, quantity: 1 }],
            rewards: promotion ? toRows(promotion.rewards) : [],
        },
    });

    const itemsArray = useFieldArray({ control, name: 'items' });
    const rewardsArray = useFieldArray({ control, name: 'rewards' });
    const type = watch('type');

    function optionsFor(targetType: TargetType): Option[] {
        if (targetType === 'variant') return variants;
        if (targetType === 'category') return categories;
        return collections;
    }

    function onSubmit(values: PromotionFormValues) {
        const payload = {
            name: { en: values.name_en },
            description: values.description_en ? { en: values.description_en } : null,
            type: values.type,
            discount_type: values.discount_type,
            discount_value: values.discount_value || null,
            starts_at: values.starts_at || null,
            ends_at: values.ends_at || null,
            priority: Number(values.priority),
            stackable_with_coupons: values.stackable_with_coupons,
            usage_limit: values.usage_limit || null,
            usage_limit_per_customer: values.usage_limit_per_customer || null,
            is_active: values.is_active,
            items: toPayload(values.items),
            rewards: values.type === 'buy_x_get_y' ? toPayload(values.rewards) : [],
        };

        const options = { onError: (errors: Record<string, string>) => setServerErrors(errors) };
        if (promotion) {
            router.put(route('admin.promotions.update', promotion.id), payload, options);
        } else {
            router.post(route('admin.promotions.store'), payload, options);
        }
    }

    function TargetRows({
        array,
        name,
    }: {
        array: UseFieldArrayReturn<PromotionFormValues, 'items' | 'rewards'>;
        name: 'items' | 'rewards';
    }) {
        return (
            <>
                <div className="table-responsive mb-2">
                    <table className="table align-middle mb-0 table-centered">
                        <thead className="bg-light-subtle">
                            <tr>
                                <th>{t('admin.targetType')}</th>
                                <th>{t('admin.target')}</th>
                                <th style={{ width: 100 }}>{t('admin.qty')}</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {array.fields.map((field, index) => {
                                const targetType = watch(`${name}.${index}.target_type`);
                                return (
                                    <tr key={field.id}>
                                        <td>
                                            <select
                                                className="form-control form-control-sm"
                                                {...register(`${name}.${index}.target_type`)}
                                            >
                                                <option value="variant">{t('admin.productVariant')}</option>
                                                <option value="category">{t('admin.category')}</option>
                                                <option value="collection">{t('admin.collection')}</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select
                                                className="form-control form-control-sm"
                                                {...register(`${name}.${index}.target_id`, { valueAsNumber: true })}
                                            >
                                                <option value="">{t('admin.select')}</option>
                                                {optionsFor(targetType).map((o) => (
                                                    <option key={o.id} value={o.id}>
                                                        {o.label ?? o.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                min={1}
                                                className="form-control form-control-sm"
                                                {...register(`${name}.${index}.quantity`, { valueAsNumber: true })}
                                            />
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                className="btn btn-soft-danger btn-sm"
                                                onClick={() => array.remove(index)}
                                            >
                                                <i className="bx bx-trash align-middle" />
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    onClick={() => array.append({ target_type: 'variant', target_id: null, quantity: 1 })}
                >
                    {t('admin.addRow')}
                </button>
            </>
        );
    }

    return (
        <AdminLayout title={promotion ? 'Edit Promotion' : 'New Promotion'}>
            <Head title={promotion ? 'Edit Promotion' : 'New Promotion'} />
            <form onSubmit={handleSubmit(onSubmit)}>
                <div className="row">
                    <div className="col-xl-8">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.generalInformation')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="row">
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.nameEnglish')}</label>
                                            <input className="form-control" {...register('name_en')} />
                                            {serverErrors['name.en'] && (
                                                <div className="text-danger small mt-1">{serverErrors['name.en']}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.type')}</label>
                                            <select className="form-control" {...register('type')}>
                                                <option value="bundle">{t('admin.bundle')}</option>
                                                <option value="buy_x_get_y">{t('admin.buyXGetY')}</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div className="col-lg-12">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.descriptionEnglish')}</label>
                                            <textarea
                                                className="form-control"
                                                rows={2}
                                                {...register('description_en')}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.discountType')}</label>
                                            <select className="form-control" {...register('discount_type')}>
                                                <option value="percentage">{t('admin.percentage')}</option>
                                                <option value="fixed_amount">{t('admin.fixedAmount')}</option>
                                                <option value="fixed_price">{t('admin.fixedPrice')}</option>
                                                <option value="free">{t('admin.free')}</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.discountValue')}</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                {...register('discount_value')}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">
                                    {type === 'bundle' ? 'Bundle Components' : 'Trigger Items (Buy)'}
                                </h4>
                            </div>
                            <div className="card-body">
                                <TargetRows array={itemsArray} name="items" />
                            </div>
                        </div>

                        {type === 'buy_x_get_y' && (
                            <div className="card">
                                <div className="card-header">
                                    <h4 className="card-title">{t('admin.rewardItemsGet')}</h4>
                                </div>
                                <div className="card-body">
                                    <TargetRows array={rewardsArray} name="rewards" />
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="col-xl-4">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.rules')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="mb-3">
                                    <label className="form-label">{t('admin.startsAt')}</label>
                                    <input type="datetime-local" className="form-control" {...register('starts_at')} />
                                </div>
                                <div className="mb-3">
                                    <label className="form-label">{t('admin.endsAt')}</label>
                                    <input type="datetime-local" className="form-control" {...register('ends_at')} />
                                </div>
                                <div className="mb-3">
                                    <label className="form-label">{t('admin.priority')}</label>
                                    <input
                                        type="number"
                                        className="form-control"
                                        {...register('priority', { valueAsNumber: true })}
                                    />
                                </div>
                                <div className="mb-3">
                                    <label className="form-label">{t('admin.usageLimitTotal')}</label>
                                    <input type="number" className="form-control" {...register('usage_limit')} />
                                </div>
                                <div className="mb-3">
                                    <label className="form-label">{t('admin.usageLimitPerCustomer')}</label>
                                    <input
                                        type="number"
                                        className="form-control"
                                        {...register('usage_limit_per_customer')}
                                    />
                                </div>
                                <div className="form-check mb-2">
                                    <input
                                        type="checkbox"
                                        className="form-check-input"
                                        id="stackable"
                                        {...register('stackable_with_coupons')}
                                    />
                                    <label className="form-check-label" htmlFor="stackable">
                                        {t('admin.stackableWithCoupons')}
                                    </label>
                                </div>
                                <div className="form-check mb-3">
                                    <input
                                        type="checkbox"
                                        className="form-check-input"
                                        id="active"
                                        {...register('is_active')}
                                    />
                                    <label className="form-check-label" htmlFor="active">
                                        {t('admin.active')}
                                    </label>
                                </div>
                            </div>
                            <div className="card-footer border-top">
                                <button type="submit" className="btn btn-primary w-100">
                                    {t('admin.savePromotion')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
