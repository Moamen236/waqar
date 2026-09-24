import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { type UseFieldArrayReturn, useFieldArray, useForm } from 'react-hook-form';
import FieldError from '../../Components/Form/FieldError';
import FormField from '../../Components/Form/FormField';
import AdminLayout from '../../Layouts/AdminLayout';
import { invalidClass, invalidProps, useClearServerErrorsOnChange } from '../../lib/formErrors';
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

    const sentRows = useRef<{ items: number[]; rewards: number[] }>({ items: [], rewards: [] });
    // Form names → the keys the server answers with (see onSubmit's payload).
    useClearServerErrorsOnChange(watch, setServerErrors, (name) => {
        if (name === 'name_en') return 'name.en';
        if (name === 'description_en') return 'description.en';
        if (name.startsWith('items.')) return 'items';
        if (name.startsWith('rewards.')) return 'rewards';

        return name;
    });

    /** The server key for a field on a form row, or null if that row wasn't sent. */
    function rowKey(list: 'items' | 'rewards', formIndex: number, field: string): string | null {
        const sentIndex = sentRows.current[list].indexOf(formIndex);

        return sentIndex === -1 ? null : `${list}.${sentIndex}.${field}`;
    }

    const TARGET_FIELD: Record<TargetType, string> = {
        variant: 'product_variant_id',
        category: 'category_id',
        collection: 'collection_id',
    };

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

        // toPayload() drops rows with no target, so the server's
        // `items.2` may be the form's fourth row. Remember which form row
        // each sent row came from, to put its error back on that row.
        sentRows.current = {
            items: values.items.flatMap((row, i) => (row.target_id !== null ? [i] : [])),
            rewards: values.rewards.flatMap((row, i) => (row.target_id !== null ? [i] : [])),
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
                                const targetKey = rowKey(name, index, TARGET_FIELD[targetType]);
                                const quantityKey = rowKey(name, index, 'quantity');
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
                                            <FormField
                                                name={targetKey ?? `${name}.${index}.target_id`}
                                                error={targetKey ? serverErrors[targetKey] : undefined}
                                                className=""
                                            >
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
                                            </FormField>
                                        </td>
                                        <td>
                                            <FormField
                                                name={quantityKey ?? `${name}.${index}.quantity`}
                                                error={quantityKey ? serverErrors[quantityKey] : undefined}
                                                className=""
                                            >
                                                <input
                                                    type="number"
                                                    min={1}
                                                    className="form-control form-control-sm"
                                                    {...register(`${name}.${index}.quantity`, { valueAsNumber: true })}
                                                />
                                            </FormField>
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
                {/* About the list as a whole — empty, or missing for Buy X Get Y. */}
                <FieldError name={name} message={serverErrors[name]} />
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
        <AdminLayout
            title={promotion ? t('admin.editPromotion') : t('admin.newPromotion')}
            breadcrumbs={[{ label: t('admin.promotions'), href: route('admin.promotions.index') }]}
        >
            <Head title={promotion ? t('admin.editPromotion') : t('admin.newPromotion')} />
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
                                        <FormField
                                            name="name.en"
                                            label={t('admin.nameEnglish')}
                                            error={serverErrors['name.en']}
                                            required
                                        >
                                            <input className="form-control" {...register('name_en')} />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField name="type" label={t('admin.type')} error={serverErrors.type} required>
                                            <select className="form-control" {...register('type')}>
                                                <option value="bundle">{t('admin.bundle')}</option>
                                                <option value="buy_x_get_y">{t('admin.buyXGetY')}</option>
                                            </select>
                                        </FormField>
                                    </div>
                                    <div className="col-lg-12">
                                        <FormField
                                            name="description.en"
                                            label={t('admin.descriptionEnglish')}
                                            error={serverErrors['description.en']}
                                        >
                                            <textarea
                                                className="form-control"
                                                rows={2}
                                                {...register('description_en')}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="discount_type"
                                            label={t('admin.discountType')}
                                            error={serverErrors.discount_type}
                                            required
                                        >
                                            <select className="form-control" {...register('discount_type')}>
                                                <option value="percentage">{t('admin.percentage')}</option>
                                                <option value="fixed_amount">{t('admin.fixedAmount')}</option>
                                                <option value="fixed_price">{t('admin.fixedPrice')}</option>
                                                <option value="free">{t('admin.free')}</option>
                                            </select>
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="discount_value"
                                            label={t('admin.discountValue')}
                                            error={serverErrors.discount_value}
                                        >
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                {...register('discount_value')}
                                            />
                                        </FormField>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">
                                    {type === 'bundle' ? t('admin.bundleComponents') : t('admin.triggerItemsBuy')}
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
                                <FormField name="starts_at" label={t('admin.startsAt')} error={serverErrors.starts_at}>
                                    <input type="datetime-local" className="form-control" {...register('starts_at')} />
                                </FormField>
                                <FormField name="ends_at" label={t('admin.endsAt')} error={serverErrors.ends_at}>
                                    <input type="datetime-local" className="form-control" {...register('ends_at')} />
                                </FormField>
                                <FormField
                                    name="priority"
                                    label={t('admin.priority')}
                                    error={serverErrors.priority}
                                    required
                                >
                                    <input
                                        type="number"
                                        className="form-control"
                                        {...register('priority', { valueAsNumber: true })}
                                    />
                                </FormField>
                                <FormField
                                    name="usage_limit"
                                    label={t('admin.usageLimitTotal')}
                                    error={serverErrors.usage_limit}
                                >
                                    <input type="number" className="form-control" {...register('usage_limit')} />
                                </FormField>
                                <FormField
                                    name="usage_limit_per_customer"
                                    label={t('admin.usageLimitPerCustomer')}
                                    error={serverErrors.usage_limit_per_customer}
                                >
                                    <input
                                        type="number"
                                        className="form-control"
                                        {...register('usage_limit_per_customer')}
                                    />
                                </FormField>
                                <div className="form-check mb-2">
                                    <input
                                        type="checkbox"
                                        className={`form-check-input${invalidClass(serverErrors.stackable_with_coupons)}`}
                                        {...invalidProps(
                                            'stackable_with_coupons',
                                            serverErrors.stackable_with_coupons,
                                            'stackable',
                                        )}
                                        {...register('stackable_with_coupons')}
                                    />
                                    <label className="form-check-label" htmlFor="stackable">
                                        {t('admin.stackableWithCoupons')}
                                    </label>
                                    <FieldError
                                        name="stackable_with_coupons"
                                        message={serverErrors.stackable_with_coupons}
                                        id="stackable"
                                    />
                                </div>
                                <div className="form-check mb-3">
                                    <input
                                        type="checkbox"
                                        className={`form-check-input${invalidClass(serverErrors.is_active)}`}
                                        {...invalidProps('is_active', serverErrors.is_active, 'active')}
                                        {...register('is_active')}
                                    />
                                    <label className="form-check-label" htmlFor="active">
                                        {t('admin.active')}
                                    </label>
                                    <FieldError name="is_active" message={serverErrors.is_active} id="active" />
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
