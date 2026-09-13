import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useDropzone } from 'react-dropzone';
import { Controller, type Control, useFieldArray, useForm, useWatch } from 'react-hook-form';
import ReactQuill from 'react-quill-new';
import 'react-quill-new/dist/quill.snow.css';
import Select from 'react-select';
import type { FormDataConvertible } from '@inertiajs/core';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import { useTranslation } from '../../lib/useTranslation';

interface Option {
    id: number;
    name: string;
}

interface AttributeOption {
    id: number;
    name: string;
    values: { id: number; value: string; color_hex: string | null }[];
}

interface VariantRow {
    id: number | null;
    sku: string;
    barcode: string;
    price: string;
    sale_price: string;
    cost_price: string;
    status: boolean;
    attribute_value_ids: number[];
}

interface ProductImage {
    id: number;
    url: string;
}

interface ProductRecord {
    id: number;
    name: { en: string; ar: string };
    description: { en: string; ar: string } | null;
    short_description: { en: string; ar: string } | null;
    slug: string;
    sku: string;
    price: string;
    sale_price: string | null;
    cost_price: string | null;
    status: boolean;
    is_featured: boolean;
    is_new: boolean;
    is_on_sale: boolean;
    sort_order: number;
    product_type: 'advertisement' | 'real';
    categories: Option[];
    collections: Option[];
    variants: {
        id: number;
        sku: string;
        barcode: string | null;
        price: string | null;
        sale_price: string | null;
        cost_price: string | null;
        status: boolean;
        attribute_values: { id: number }[];
    }[];
    images: ProductImage[];
}

interface FormValues {
    name_en: string;
    name_ar: string;
    slug: string;
    sku: string;
    description_en: string;
    description_ar: string;
    short_description_en: string;
    short_description_ar: string;
    price: string;
    sale_price: string;
    cost_price: string;
    status: boolean;
    is_featured: boolean;
    is_new: boolean;
    is_on_sale: boolean;
    sort_order: number;
    product_type: 'advertisement' | 'real';
    category_ids: number[];
    collection_ids: number[];
    variants: VariantRow[];
}

// Ported from Admin Template/product-add.html — the template's own
// two-column create/edit layout: a preview card in `col-xl-3 col-lg-4`
// beside stacked form cards ("Add Product Photo", "Product Information",
// "Pricing Details") in `col-xl-9 col-lg-8`.
//
// This replaces a single card of react-bootstrap tabs. Beyond fidelity,
// the tabs were actively hiding things: a server-side validation error on
// a field in a non-active tab rendered into a panel with `display:none`,
// so a rejected save looked like nothing had happened at all. Stacked
// cards put every field — and every error — on one scrollable page,
// which is what the template does.
//
// The preview card is driven by `useWatch`, so it reflects what is
// actually typed rather than the template's static demo product. Its
// size/colour chips come from the real attribute values selected on the
// variant rows below. See [[admin-ui-use-larkon-template]].
export default function ProductForm({
    product,
    nextSku,
    categories,
    collections,
    attributes,
}: {
    product: ProductRecord | null;
    /** Only sent by create() — the SKU this product will be given. */
    nextSku?: string;
    categories: Option[];
    collections: Option[];
    attributes: AttributeOption[];
}) {
    const { t, price: money, isRtl } = useTranslation();
    const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
    const [newImages, setNewImages] = useState<File[]>([]);
    const [existingImages, setExistingImages] = useState<ProductImage[]>(product?.images ?? []);

    const attributeValueOptions = attributes.flatMap((attribute) =>
        attribute.values.map((value) => ({ value: value.id, label: `${attribute.name}: ${value.value}` })),
    );
    const categoryOptions = categories.map((c) => ({ value: c.id, label: c.name }));
    const collectionOptions = collections.map((c) => ({ value: c.id, label: c.name }));

    const { register, control, handleSubmit, watch } = useForm<FormValues>({
        defaultValues: {
            name_en: product?.name.en ?? '',
            name_ar: product?.name.ar ?? '',
            slug: product?.slug ?? '',
            sku: product?.sku ?? nextSku ?? '',
            description_en: product?.description?.en ?? '',
            description_ar: product?.description?.ar ?? '',
            short_description_en: product?.short_description?.en ?? '',
            short_description_ar: product?.short_description?.ar ?? '',
            price: product?.price ?? '',
            sale_price: product?.sale_price ?? '',
            cost_price: product?.cost_price ?? '',
            status: product?.status ?? true,
            is_featured: product?.is_featured ?? false,
            is_new: product?.is_new ?? false,
            is_on_sale: product?.is_on_sale ?? false,
            sort_order: product?.sort_order ?? 0,
            product_type: product?.product_type ?? 'real',
            category_ids: product?.categories.map((c) => c.id) ?? [],
            collection_ids: product?.collections.map((c) => c.id) ?? [],
            variants: product
                ? product.variants.map((v) => ({
                      id: v.id,
                      sku: v.sku,
                      barcode: v.barcode ?? '',
                      price: v.price ?? '',
                      sale_price: v.sale_price ?? '',
                      cost_price: v.cost_price ?? '',
                      status: v.status,
                      attribute_value_ids: v.attribute_values.map((av) => av.id),
                  }))
                : [
                      {
                          id: null,
                          sku: '',
                          barcode: '',
                          price: '',
                          sale_price: '',
                          cost_price: '',
                          status: true,
                          attribute_value_ids: [],
                      },
                  ],
        },
    });
    const { fields, append, remove, update } = useFieldArray({ control, name: 'variants' });
    const productType = watch('product_type');

    const onDrop = useCallback((files: File[]) => setNewImages((prev) => [...prev, ...files]), []);
    const { getRootProps, getInputProps, isDragActive } = useDropzone({ onDrop, accept: { 'image/*': [] } });

    // Object URLs were previously minted inline in the render body, which
    // allocates a fresh blob URL on **every** render and never revokes one —
    // a leak that grows for as long as the form stays open. Created once per
    // file here, and released when the file goes away or the page unmounts.
    const newImageUrls = useMemo(() => newImages.map((file) => URL.createObjectURL(file)), [newImages]);

    useEffect(() => () => newImageUrls.forEach((url) => URL.revokeObjectURL(url)), [newImageUrls]);

    // `useWatch` rather than `watch()` for the preview: it subscribes to
    // just these fields instead of re-rendering the whole form on every
    // keystroke anywhere in it.
    const preview = useWatch({
        control,
        name: ['name_en', 'name_ar', 'price', 'sale_price', 'category_ids', 'variants'],
    });
    const [previewNameEn, previewNameAr, previewPrice, previewSalePrice, previewCategoryIds, previewVariants] = preview;

    const previewName = (isRtl ? previewNameAr || previewNameEn : previewNameEn || previewNameAr)?.trim();
    const previewImage = newImageUrls[0] ?? existingImages[0]?.url ?? null;

    const previewCategories = categories
        .filter((category) => previewCategoryIds?.includes(category.id))
        .map((category) => category.name);

    const listPrice = Number(previewPrice) || 0;
    const nowPrice = Number(previewSalePrice) || 0;
    const discountPercent =
        listPrice > 0 && nowPrice > 0 && nowPrice < listPrice
            ? Math.round(((listPrice - nowPrice) / listPrice) * 100)
            : 0;

    // The template's preview hard-codes a Size and a Colors row. Ours
    // derives both from whatever attribute values the variant rows below
    // actually reference, so an attribute set that is neither size nor
    // colour still gets a row, and an unused one gets none.
    const selectedValueIds = new Set((previewVariants ?? []).flatMap((variant) => variant.attribute_value_ids ?? []));
    const previewAttributes = attributes
        .map((attribute) => ({
            name: attribute.name,
            values: attribute.values.filter((value) => selectedValueIds.has(value.id)),
        }))
        .filter((attribute) => attribute.values.length > 0);

    async function removeExistingImage(image: ProductImage) {
        if (!product) return;
        if (!(await confirmAction({ title: t('admin.removeThisImage'), danger: true }))) return;
        router.delete(route('admin.products.images.destroy', [product.id, image.id]), {
            preserveScroll: true,
            onSuccess: () => setExistingImages((prev) => prev.filter((i) => i.id !== image.id)),
        });
    }

    function onSubmit(values: FormValues) {
        const payload = {
            name: { en: values.name_en, ar: values.name_ar || undefined },
            description: values.description_en
                ? { en: values.description_en, ar: values.description_ar || undefined }
                : undefined,
            short_description: values.short_description_en
                ? { en: values.short_description_en, ar: values.short_description_ar || undefined }
                : undefined,
            slug: values.slug,
            sku: values.sku,
            price: values.price,
            sale_price: values.sale_price || undefined,
            cost_price: values.cost_price || undefined,
            status: values.status,
            is_featured: values.is_featured,
            is_new: values.is_new,
            is_on_sale: values.is_on_sale,
            sort_order: values.sort_order,
            product_type: values.product_type,
            category_ids: values.category_ids,
            collection_ids: values.collection_ids,
            variants: values.variants,
            images: newImages,
        };

        // Cast at the boundary rather than adding an index signature to
        // FormValues/VariantRow — that breaks react-hook-form's field-path
        // inference for useFieldArray.
        const requestPayload = payload as unknown as Record<string, FormDataConvertible>;
        const options = { onError: (errors: Record<string, string>) => setServerErrors(errors) };
        if (product) {
            router.put(route('admin.products.update', product.id), requestPayload, options);
        } else {
            router.post(route('admin.products.store'), requestPayload, options);
        }
    }

    return (
        <AdminLayout
            title={product ? t('admin.editProduct') : t('admin.newProduct')}
            breadcrumbs={[{ label: t('admin.products'), href: route('admin.products.index') }]}
        >
            <Head title={product ? t('admin.editProduct') : t('admin.newProduct')} />
            <form onSubmit={handleSubmit(onSubmit)}>
                <div className="row">
                    {/* Left column — product-add.html's preview card. */}
                    <div className="col-xl-3 col-lg-4">
                        <div className="card">
                            <div className="card-body">
                                {previewImage ? (
                                    <img src={previewImage} alt="" className="img-fluid rounded bg-light" />
                                ) : (
                                    <div className="rounded bg-light-subtle border border-dashed d-flex flex-column align-items-center justify-content-center text-muted py-5">
                                        <i className="bx bx-image fs-48" />
                                        <span className="fs-13 mt-2">{t('admin.noImageYet')}</span>
                                    </div>
                                )}

                                <div className="mt-3">
                                    <h4>
                                        {previewName || (
                                            <span className="text-muted">{t('admin.untitledProduct')}</span>
                                        )}
                                        {previewCategories.length > 0 && (
                                            <span className="fs-14 text-muted ms-1">
                                                ({previewCategories.join(', ')})
                                            </span>
                                        )}
                                    </h4>

                                    <h5 className="text-dark fw-medium mt-3">{t('admin.price')} :</h5>
                                    <h4 className="fw-semibold text-dark mt-2 d-flex align-items-center gap-2 flex-wrap">
                                        {discountPercent > 0 && (
                                            <span className="text-muted text-decoration-line-through" dir="ltr">
                                                {money(listPrice)}
                                            </span>
                                        )}
                                        <span dir="ltr">{money(nowPrice || listPrice)}</span>
                                        {discountPercent > 0 && (
                                            <small className="text-muted">
                                                (<span dir="ltr">{discountPercent}%</span> {t('admin.off')})
                                            </small>
                                        )}
                                    </h4>

                                    {previewAttributes.map((attribute) => (
                                        <div className="mt-3" key={attribute.name}>
                                            <h5 className="text-dark fw-medium">{attribute.name} :</h5>
                                            <div className="d-flex flex-wrap gap-2">
                                                {attribute.values.map((value) => (
                                                    <span
                                                        key={value.id}
                                                        className="btn btn-light avatar-sm rounded d-flex justify-content-center align-items-center pe-none"
                                                        title={value.value}
                                                    >
                                                        {value.color_hex ? (
                                                            <i
                                                                className="bx bxs-circle fs-18"
                                                                style={{ color: value.color_hex }}
                                                            />
                                                        ) : (
                                                            value.value
                                                        )}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    ))}

                                    <p className="text-muted fs-13 mt-3 mb-0">{t('admin.livePreviewHint')}</p>
                                </div>
                            </div>

                            <div className="card-footer bg-light-subtle">
                                <div className="row g-2">
                                    <div className="col-lg-6">
                                        <button type="submit" className="btn btn-primary w-100">
                                            {t('admin.saveProduct')}
                                        </button>
                                    </div>
                                    <div className="col-lg-6">
                                        <Link
                                            href={route('admin.products.index')}
                                            className="btn btn-outline-secondary w-100"
                                        >
                                            {t('admin.cancel')}
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Right column — the template's stacked form cards. */}
                    <div className="col-xl-9 col-lg-8">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.addProductPhoto')}</h4>
                            </div>
                            <div className="card-body">
                                <div
                                    {...getRootProps()}
                                    className={`dropzone bg-light-subtle py-5 ${isDragActive ? 'bg-light' : ''}`}
                                >
                                    <input {...getInputProps()} />
                                    <div className="dz-message needsclick text-center">
                                        <i className="bx bx-cloud-upload fs-48 text-primary" />
                                        <h3 className="mt-4">
                                            {t('admin.dropYourImagesHere')}{' '}
                                            <span className="text-primary">{t('admin.clickToBrowse')}</span>
                                        </h3>
                                        <span className="text-muted fs-13">{t('admin.imageSpecHint')}</span>
                                    </div>
                                </div>

                                {(existingImages.length > 0 || newImages.length > 0) && (
                                    <div className="d-flex flex-wrap gap-3 mt-3">
                                        {existingImages.map((image) => (
                                            <div key={image.id} className="position-relative">
                                                <img
                                                    src={image.url}
                                                    alt=""
                                                    style={{ width: 100, height: 100, objectFit: 'cover' }}
                                                    className="rounded border"
                                                />
                                                <button
                                                    type="button"
                                                    className="btn btn-danger btn-sm position-absolute top-0 end-0"
                                                    onClick={() => removeExistingImage(image)}
                                                >
                                                    &times;
                                                </button>
                                            </div>
                                        ))}
                                        {newImageUrls.map((url) => (
                                            <img
                                                key={url}
                                                src={url}
                                                alt=""
                                                style={{ width: 100, height: 100, objectFit: 'cover' }}
                                                className="rounded border"
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.productInformation')}</h4>
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
                                            <label className="form-label">{t('admin.nameArabic')}</label>
                                            <input className="form-control" dir="rtl" {...register('name_ar')} />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">SKU</label>
                                            {/* Disabled on create: the SKU is assigned by
                                                SkuGenerator when the product is inserted, so
                                                there is nothing here to edit. It is shown
                                                rather than hidden because the variant SKUs
                                                below are typed by hand and are conventionally
                                                based on it. Editing an existing product still
                                                allows a correction — an assigned SKU is a
                                                starting value, not a permanent one. */}
                                            <input
                                                className="form-control"
                                                disabled={product === null}
                                                {...register('sku')}
                                            />
                                            {product === null && (
                                                <div className="form-text">{t('admin.skuAssignedOnSave')}</div>
                                            )}
                                            {serverErrors.sku && (
                                                <div className="text-danger small mt-1">{serverErrors.sku}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.slugAutoGeneratedIfBlank')}</label>
                                            <input className="form-control" {...register('slug')} />
                                        </div>
                                    </div>
                                    <div className="col-lg-12">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.description')}</label>
                                            <ReactQuillField control={control} name="description_en" />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.shortDescription')}</label>
                                            <textarea
                                                className="form-control"
                                                rows={4}
                                                {...register('short_description_en')}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.categories')}</label>
                                            <MultiSelectField
                                                control={control}
                                                name="category_ids"
                                                options={categoryOptions}
                                            />
                                        </div>
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.collections')}</label>
                                            <MultiSelectField
                                                control={control}
                                                name="collection_ids"
                                                options={collectionOptions}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.pricingDetails')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="row mb-4">
                                    <div className="col-lg-4">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.price')}</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                {...register('price')}
                                            />
                                            {serverErrors.price && (
                                                <div className="text-danger small mt-1">{serverErrors.price}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-4">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.salePrice')}</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                {...register('sale_price')}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-4">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.costPriceInternalOnly')}</label>
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                {...register('cost_price')}
                                            />
                                        </div>
                                    </div>
                                </div>

                                <h5 className="fs-14 mb-1">{t('admin.variants')}</h5>
                                <p className="text-muted fs-13">{t('admin.eachRowIsADistinctSku')}</p>
                                <div className="table-responsive mb-2">
                                    <table className="table align-middle mb-0 table-centered">
                                        <thead className="bg-light-subtle">
                                            <tr>
                                                <th>SKU</th>
                                                <th>{t('admin.attributes')}</th>
                                                <th>{t('admin.priceOverride')}</th>
                                                <th>{t('admin.salePrice')}</th>
                                                <th>{t('admin.active')}</th>
                                                <th />
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {fields.map((field, index) => (
                                                <tr key={field.id}>
                                                    <td style={{ minWidth: 140 }}>
                                                        <input
                                                            className="form-control form-control-sm"
                                                            {...register(`variants.${index}.sku`)}
                                                        />
                                                    </td>
                                                    <td style={{ minWidth: 220 }}>
                                                        <Select
                                                            isMulti
                                                            options={attributeValueOptions}
                                                            value={attributeValueOptions.filter((o) =>
                                                                field.attribute_value_ids.includes(o.value),
                                                            )}
                                                            onChange={(selected) =>
                                                                update(index, {
                                                                    ...field,
                                                                    attribute_value_ids: selected.map((s) => s.value),
                                                                })
                                                            }
                                                        />
                                                    </td>
                                                    <td style={{ minWidth: 110 }}>
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            className="form-control form-control-sm"
                                                            {...register(`variants.${index}.price`)}
                                                        />
                                                    </td>
                                                    <td style={{ minWidth: 110 }}>
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            className="form-control form-control-sm"
                                                            {...register(`variants.${index}.sale_price`)}
                                                        />
                                                    </td>
                                                    <td>
                                                        <div className="form-check">
                                                            <input
                                                                type="checkbox"
                                                                className="form-check-input"
                                                                {...register(`variants.${index}.status`)}
                                                            />
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <button
                                                            type="button"
                                                            className="btn btn-soft-danger btn-sm"
                                                            onClick={() => remove(index)}
                                                        >
                                                            <i className="bx bx-trash align-middle" />
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-secondary"
                                    onClick={() =>
                                        append({
                                            id: null,
                                            sku: '',
                                            barcode: '',
                                            price: '',
                                            sale_price: '',
                                            cost_price: '',
                                            status: true,
                                            attribute_value_ids: [],
                                        })
                                    }
                                >
                                    {t('admin.addVariant')}
                                </button>
                                {serverErrors.variants && (
                                    <div className="text-danger small mt-2">{serverErrors.variants}</div>
                                )}
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.organization')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="row">
                                    <div className="col-lg-4">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.productType')}</label>
                                            <select className="form-control" {...register('product_type')}>
                                                <option value="real">{t('admin.realInventoryTracked')}</option>
                                                <option value="advertisement">
                                                    {t('admin.advertisementPreOrderNotTracked')}
                                                </option>
                                            </select>
                                            {productType === 'advertisement' && (
                                                <div className="form-text">
                                                    {t('admin.advertisementStockExplainer')}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-4">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.sortOrder')}</label>
                                            <input
                                                type="number"
                                                className="form-control"
                                                {...register('sort_order', { valueAsNumber: true })}
                                            />
                                        </div>
                                    </div>
                                </div>
                                <div className="d-flex gap-4">
                                    <div className="form-check">
                                        <input
                                            type="checkbox"
                                            className="form-check-input"
                                            id="status"
                                            {...register('status')}
                                        />
                                        <label className="form-check-label" htmlFor="status">
                                            {t('admin.active')}
                                        </label>
                                    </div>
                                    <div className="form-check">
                                        <input
                                            type="checkbox"
                                            className="form-check-input"
                                            id="featured"
                                            {...register('is_featured')}
                                        />
                                        <label className="form-check-label" htmlFor="featured">
                                            {t('admin.featured')}
                                        </label>
                                    </div>
                                    <div className="form-check">
                                        <input
                                            type="checkbox"
                                            className="form-check-input"
                                            id="isNew"
                                            {...register('is_new')}
                                        />
                                        <label className="form-check-label" htmlFor="isNew">
                                            {t('admin.new')}
                                        </label>
                                    </div>
                                    <div className="form-check">
                                        <input
                                            type="checkbox"
                                            className="form-check-input"
                                            id="onSale"
                                            {...register('is_on_sale')}
                                        />
                                        <label className="form-check-label" htmlFor="onSale">
                                            {t('admin.onSaleBadge')}
                                        </label>
                                    </div>
                                </div>
                            </div>
                            {/* The primary submit lives in the preview card's
                                footer, where product-add.html puts it. This
                                second one saves the reader a scroll back up
                                from the bottom of a long form. */}
                            <div className="card-footer border-top text-end">
                                <Link href={route('admin.products.index')} className="btn btn-outline-secondary me-2">
                                    {t('admin.cancel')}
                                </Link>
                                <button type="submit" className="btn btn-primary">
                                    {t('admin.saveProduct')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}

function MultiSelectField({
    control,
    name,
    options,
}: {
    control: Control<FormValues>;
    name: 'category_ids' | 'collection_ids';
    options: { value: number; label: string }[];
}) {
    return (
        <Controller
            control={control}
            name={name}
            render={({ field }) => (
                <Select
                    isMulti
                    options={options}
                    value={options.filter((o) => field.value?.includes(o.value))}
                    onChange={(selected) => field.onChange(selected.map((s) => s.value))}
                />
            )}
        />
    );
}

function ReactQuillField({ control, name }: { control: Control<FormValues>; name: 'description_en' }) {
    return (
        <Controller
            control={control}
            name={name}
            render={({ field }) => <ReactQuill theme="snow" value={field.value} onChange={field.onChange} />}
        />
    );
}
