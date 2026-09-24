import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useDropzone } from 'react-dropzone';
import { Controller, type Control, useFieldArray, useForm, useWatch } from 'react-hook-form';
import ReactQuill from 'react-quill-new';
import 'react-quill-new/dist/quill.snow.css';
import Select from 'react-select';
import type { FormDataConvertible } from '@inertiajs/core';
import FieldError from '../../Components/Form/FieldError';
import FormField from '../../Components/Form/FormField';
import ValidationSummary from '../../Components/Form/ValidationSummary';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import { invalidClass, invalidProps, pickError, useClearServerErrorsOnChange } from '../../lib/formErrors';
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
    /** Which colour this photo shows. Null means every colour. */
    attribute_value_id: number | null;
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
        size_guide_weight_min: string | null;
        size_guide_weight_max: string | null;
        attribute_values: { id: number }[];
    }[];
    images: ProductImage[];
    /** The colours this product is actually made in, for image tagging. */
    colors: { id: number; name: string; hex: string | null }[];
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
    colorAttributeIds = [],
    sizeAttributeIds = [],
}: {
    product: ProductRecord | null;
    /** Only sent by create() — the SKU this product will be given. */
    nextSku?: string;
    categories: Option[];
    collections: Option[];
    attributes: AttributeOption[];
    /** Ids of the attributes that count as "colour" (English name `Color`). */
    colorAttributeIds?: number[];
    /** Ids of the attributes that count as "size" (English name `Size`). */
    sizeAttributeIds?: number[];
}) {
    const { t, price: money, isRtl } = useTranslation();
    const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
    const [newImages, setNewImages] = useState<File[]>([]);
    // Parallel to newImages by index: which colour each not-yet-saved photo
    // shows. Null means every colour — same meaning as ProductImage's tag.
    const [newImageColourIds, setNewImageColourIds] = useState<(number | null)[]>([]);
    const [existingImages, setExistingImages] = useState<ProductImage[]>(product?.images ?? []);
    // Every attribute value that belongs to a size attribute. Used to
    // keep the size guide off colour values, which a variant carries in
    // the same list.
    const sizeValueIds = useMemo(
        () =>
            new Set(
                attributes
                    .filter((attribute) => sizeAttributeIds.includes(attribute.id))
                    .flatMap((attribute) => attribute.values)
                    .map((value) => value.id),
            ),
        [attributes, sizeAttributeIds],
    );

    // Weight range per size, keyed by the size's attribute_value_id.
    // Seeded from the variants: the columns are per variant, but every
    // variant of one size carries the same pair, so the first one that
    // has a value wins.
    const [sizeGuides, setSizeGuides] = useState<Record<number, { min: string; max: string }>>(() => {
        const seeded: Record<number, { min: string; max: string }> = {};
        for (const variant of product?.variants ?? []) {
            if (variant.size_guide_weight_min === null && variant.size_guide_weight_max === null) continue;
            for (const value of variant.attribute_values) {
                if (!sizeValueIds.has(value.id) || seeded[value.id]) continue;
                seeded[value.id] = {
                    min: variant.size_guide_weight_min ?? '',
                    max: variant.size_guide_weight_max ?? '',
                };
            }
        }
        return seeded;
    });

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
    const { fields, append, remove } = useFieldArray({ control, name: 'variants' });

    // Edits clear their own errors. The form's flat names map onto the
    // translatable keys the payload sends (onSubmit); the rest match.
    const SERVER_KEY: Record<string, string> = {
        name_en: 'name.en',
        name_ar: 'name.ar',
        description_en: 'description.en',
        short_description_en: 'short_description.en',
    };
    useClearServerErrorsOnChange(watch, setServerErrors, (name) => SERVER_KEY[name] ?? name);

    /** For inputs outside react-hook-form (the size guide table). */
    const clearServerErrors = (prefix: string) =>
        setServerErrors((current) =>
            Object.fromEntries(
                Object.entries(current).filter(([key]) => key !== prefix && !key.startsWith(`${prefix}.`)),
            ),
        );
    const productType = watch('product_type');

    const onDrop = useCallback((files: File[]) => {
        setNewImages((prev) => [...prev, ...files]);
        // A dropped photo starts untagged ("every colour") — the operator
        // picks a colour from the select beside its thumbnail, if any.
        setNewImageColourIds((prev) => [...prev, ...files.map(() => null)]);
    }, []);
    const { getRootProps, getInputProps, isDragActive } = useDropzone({ onDrop, accept: { 'image/*': [] } });

    function removeNewImage(index: number) {
        setNewImages((prev) => prev.filter((_, i) => i !== index));
        setNewImageColourIds((prev) => prev.filter((_, i) => i !== index));
    }

    function tagNewImageColour(index: number, attributeValueId: number | null) {
        setNewImageColourIds((prev) => prev.map((id, i) => (i === index ? attributeValueId : id)));
    }

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

    // Colours a not-yet-saved photo can be tagged with: the colour values
    // currently picked on the variant rows. Same rule as the edit form's
    // `product.colors` (only what the product is actually made in), but
    // live from the form instead of from the saved record — on create
    // there is no saved record yet.
    const colorAttributeIdSet = useMemo(() => new Set(colorAttributeIds), [colorAttributeIds]);
    const availableNewImageColours = useMemo(
        () =>
            attributes
                .filter((attribute) => colorAttributeIdSet.has(attribute.id))
                .flatMap((attribute) => attribute.values)
                .filter((value) => selectedValueIds.has(value.id)),
        // selectedValueIds is rebuilt every render from previewVariants —
        // depend on the watched rows themselves instead.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [attributes, colorAttributeIdSet, previewVariants],
    );

    // The sizes this product is actually made in, from the variant rows —
    // one size-guide row each, however many colours repeat it.
    const availableSizes = useMemo(
        () =>
            attributes
                .filter((attribute) => sizeAttributeIds.includes(attribute.id))
                .flatMap((attribute) => attribute.values)
                .filter((value) => selectedValueIds.has(value.id)),
        // Same as availableNewImageColours: selectedValueIds is rebuilt
        // every render, so depend on the watched rows instead.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [attributes, sizeAttributeIds, previewVariants],
    );

    // A tag pointing at a colour that is no longer picked on any variant
    // would save an image for a colour the product isn't made in — fall
    // back to "every colour" instead of posting a stale id.
    const selectedColourSignature = JSON.stringify([...selectedValueIds].sort((a, b) => (a as number) - (b as number)));
    useEffect(() => {
        setNewImageColourIds((prev) => {
            if (prev.length === 0) return prev;
            let changed = false;
            const next = prev.map((id) => {
                if (id !== null && !selectedValueIds.has(id)) {
                    changed = true;
                    return null;
                }
                return id;
            });
            return changed ? next : prev;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedColourSignature]);

    async function removeExistingImage(image: ProductImage) {
        if (!product) return;
        if (!(await confirmAction({ title: t('admin.removeThisImage'), danger: true }))) return;
        router.delete(route('admin.products.images.destroy', [product.id, image.id]), {
            preserveScroll: true,
            onSuccess: () => setExistingImages((prev) => prev.filter((i) => i.id !== image.id)),
        });
    }

    // Re-tagging a saved photo is its own small write, not part of the
    // product save — the tag belongs to an image that already exists.
    // Optimistic: the select shows the new value immediately and the PATCH
    // follows. A not-yet-saved photo is tagged differently: its colour
    // rides along with the upload itself (image_attribute_value_ids, see
    // onSubmit), because there is no media row to PATCH yet.
    function tagImageColour(image: ProductImage, attributeValueId: number | null) {
        if (!product) return;
        setExistingImages((prev) =>
            prev.map((i) => (i.id === image.id ? { ...i, attribute_value_id: attributeValueId } : i)),
        );
        router.patch(
            route('admin.products.images.update', [product.id, image.id]),
            { attribute_value_id: attributeValueId },
            { preserveScroll: true },
        );
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
            // One row per size on show, so a blank pair clears the guide
            // rather than leaving a stale range on the variants.
            size_guides: availableSizes.map((size) => ({
                attribute_value_id: size.id,
                weight_min: sizeGuides[size.id]?.min ?? '',
                weight_max: sizeGuides[size.id]?.max ?? '',
            })),
            images: newImages,
            // Parallel to `images` by index — the colour each new photo
            // shows, or null for every colour. An empty string survives the
            // FormData round-trip as null via ConvertEmptyStringsToNull.
            image_attribute_value_ids: newImageColourIds.map((id) => id ?? ''),
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
                {/* A long, multi-card form: every error listed at the top as
                    well as under its field, each one a link to that field. */}
                <ValidationSummary errors={serverErrors} />
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
                                            <div key={image.id} style={{ width: 100 }}>
                                                <div className="position-relative">
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
                                                {/* Only offered once the product has colours to tag
                                                    with — a single-colour product has nothing to swap. */}
                                                {(product?.colors.length ?? 0) > 0 && (
                                                    <select
                                                        className="form-select form-select-sm mt-1"
                                                        value={image.attribute_value_id ?? ''}
                                                        onChange={(e) =>
                                                            tagImageColour(
                                                                image,
                                                                e.target.value === '' ? null : Number(e.target.value),
                                                            )
                                                        }
                                                    >
                                                        <option value="">{t('admin.allColors')}</option>
                                                        {product?.colors.map((colour) => (
                                                            <option key={colour.id} value={colour.id}>
                                                                {colour.name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                )}
                                            </div>
                                        ))}
                                        {newImageUrls.map((url, index) => {
                                            // On edit the saved colours are already known; on
                                            // create only the colours picked on the variant rows
                                            // below exist. Merged so a photo added alongside a
                                            // brand-new variant colour can be tagged at once.
                                            const optionById = new Map<number, string>();
                                            product?.colors.forEach((colour) => optionById.set(colour.id, colour.name));
                                            availableNewImageColours.forEach((colour) => {
                                                if (!optionById.has(colour.id)) optionById.set(colour.id, colour.value);
                                            });
                                            const colourOptions = [...optionById.entries()].map(([id, name]) => ({
                                                id,
                                                name,
                                            }));

                                            return (
                                                <div key={url} style={{ width: 100 }}>
                                                    <div className="position-relative">
                                                        <img
                                                            src={url}
                                                            alt=""
                                                            style={{ width: 100, height: 100, objectFit: 'cover' }}
                                                            className="rounded border"
                                                        />
                                                        <button
                                                            type="button"
                                                            className="btn btn-danger btn-sm position-absolute top-0 end-0"
                                                            onClick={() => removeNewImage(index)}
                                                        >
                                                            &times;
                                                        </button>
                                                    </div>
                                                    {/* Same rule as saved photos: nothing to tag
                                                        with until the product has colours. */}
                                                    {colourOptions.length > 0 && (
                                                        <select
                                                            className="form-select form-select-sm mt-1"
                                                            value={newImageColourIds[index] ?? ''}
                                                            onChange={(e) =>
                                                                tagNewImageColour(
                                                                    index,
                                                                    e.target.value === ''
                                                                        ? null
                                                                        : Number(e.target.value),
                                                                )
                                                            }
                                                        >
                                                            <option value="">{t('admin.allColors')}</option>
                                                            {colourOptions.map((colour) => (
                                                                <option key={colour.id} value={colour.id}>
                                                                    {colour.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    )}
                                                </div>
                                            );
                                        })}
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
                                        <FormField
                                            name="name.ar"
                                            label={t('admin.nameArabic')}
                                            error={serverErrors['name.ar']}
                                        >
                                            <input className="form-control" dir="rtl" {...register('name_ar')} />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        {/* Disabled on create: the SKU is assigned by
                                            SkuGenerator when the product is inserted, so
                                            there is nothing here to edit. It is shown
                                            rather than hidden because the variant SKUs
                                            below are derived from it. Editing an existing
                                            product still allows a correction — an assigned
                                            SKU is a starting value, not a permanent one.
                                            Required only then, for the same reason. */}
                                        <FormField
                                            name="sku"
                                            label="SKU"
                                            error={serverErrors.sku}
                                            required={product !== null}
                                            hint={product === null ? t('admin.skuAssignedOnSave') : undefined}
                                        >
                                            <input
                                                className="form-control"
                                                disabled={product === null}
                                                {...register('sku')}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="slug"
                                            label={t('admin.slugAutoGeneratedIfBlank')}
                                            error={serverErrors.slug}
                                        >
                                            <input className="form-control" {...register('slug')} />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-12">
                                        <FormField
                                            name="description.en"
                                            label={t('admin.description')}
                                            error={serverErrors['description.en']}
                                        >
                                            <ReactQuillField control={control} name="description_en" />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="short_description.en"
                                            label={t('admin.shortDescription')}
                                            error={serverErrors['short_description.en']}
                                        >
                                            <textarea
                                                className="form-control"
                                                rows={4}
                                                {...register('short_description_en')}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            {...pickError(serverErrors, 'category_ids')}
                                            label={t('admin.categories')}
                                        >
                                            <MultiSelectField
                                                control={control}
                                                name="category_ids"
                                                options={categoryOptions}
                                            />
                                        </FormField>
                                        <FormField
                                            {...pickError(serverErrors, 'collection_ids')}
                                            label={t('admin.collections')}
                                        >
                                            <MultiSelectField
                                                control={control}
                                                name="collection_ids"
                                                options={collectionOptions}
                                            />
                                        </FormField>
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
                                        <FormField
                                            name="price"
                                            label={t('admin.price')}
                                            error={serverErrors.price}
                                            required
                                        >
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                {...register('price')}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-4">
                                        <FormField
                                            name="sale_price"
                                            label={t('admin.salePrice')}
                                            error={serverErrors.sale_price}
                                        >
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                {...register('sale_price')}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-4">
                                        <FormField
                                            name="cost_price"
                                            label={t('admin.costPriceInternalOnly')}
                                            error={serverErrors.cost_price}
                                        >
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                {...register('cost_price')}
                                            />
                                        </FormField>
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
                                                        {/* Always disabled: variant SKUs are
                                                            assigned by SkuGenerator on save and
                                                            never edited afterwards. Rendered as a
                                                            field rather than plain text so an
                                                            existing variant's SKU still reads in
                                                            the column it has always been in. */}
                                                        <input
                                                            className="form-control form-control-sm"
                                                            disabled
                                                            placeholder={t('admin.skuAssignedOnSave')}
                                                            {...register(`variants.${index}.sku`)}
                                                        />
                                                    </td>
                                                    {/* Variants post as-is, so the server's
                                                        variants.N is this row. */}
                                                    <td style={{ minWidth: 220 }}>
                                                        <FormField
                                                            {...pickError(
                                                                serverErrors,
                                                                `variants.${index}.attribute_value_ids`,
                                                            )}
                                                            className=""
                                                        >
                                                            <MultiSelectField
                                                                control={control}
                                                                name={`variants.${index}.attribute_value_ids`}
                                                                options={attributeValueOptions}
                                                            />
                                                        </FormField>
                                                    </td>
                                                    <td style={{ minWidth: 110 }}>
                                                        <FormField
                                                            name={`variants.${index}.price`}
                                                            error={serverErrors[`variants.${index}.price`]}
                                                            className=""
                                                        >
                                                            <input
                                                                type="number"
                                                                step="0.01"
                                                                className="form-control form-control-sm"
                                                                {...register(`variants.${index}.price`)}
                                                            />
                                                        </FormField>
                                                    </td>
                                                    <td style={{ minWidth: 110 }}>
                                                        <FormField
                                                            name={`variants.${index}.sale_price`}
                                                            error={serverErrors[`variants.${index}.sale_price`]}
                                                            className=""
                                                        >
                                                            <input
                                                                type="number"
                                                                step="0.01"
                                                                className="form-control form-control-sm"
                                                                {...register(`variants.${index}.sale_price`)}
                                                            />
                                                        </FormField>
                                                    </td>
                                                    <td>
                                                        <div className="form-check">
                                                            <input
                                                                type="checkbox"
                                                                className={`form-check-input${invalidClass(serverErrors[`variants.${index}.status`])}`}
                                                                {...invalidProps(
                                                                    `variants.${index}.status`,
                                                                    serverErrors[`variants.${index}.status`],
                                                                )}
                                                                aria-label={t('admin.active')}
                                                                {...register(`variants.${index}.status`)}
                                                            />
                                                        </div>
                                                        <FieldError
                                                            name={`variants.${index}.status`}
                                                            message={serverErrors[`variants.${index}.status`]}
                                                        />
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
                                <FieldError name="variants" message={serverErrors.variants} />

                                {availableSizes.length > 0 && (
                                    <>
                                        <h5 className="fs-14 mb-1 mt-4">{t('admin.sizeGuide')}</h5>
                                        <p className="text-muted fs-13">{t('admin.sizeGuideHint')}</p>
                                        <div className="table-responsive">
                                            <table className="table align-middle mb-0 table-centered">
                                                <thead className="bg-light-subtle">
                                                    <tr>
                                                        <th>{t('admin.size')}</th>
                                                        <th>{t('admin.weightMinKg')}</th>
                                                        <th>{t('admin.weightMaxKg')}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {/* size_guides is posted in this same order
                                                        (see onSubmit), so size_guides.N is row N. */}
                                                    {availableSizes.map((size, row) => (
                                                        <tr key={size.id}>
                                                            <td style={{ minWidth: 140 }}>{size.value}</td>
                                                            {(['min', 'max'] as const).map((bound) => {
                                                                const key = `size_guides.${row}.weight_${bound}`;

                                                                return (
                                                                    <td key={bound} style={{ minWidth: 110 }}>
                                                                        <FormField
                                                                            name={key}
                                                                            error={serverErrors[key]}
                                                                            className=""
                                                                        >
                                                                            <input
                                                                                type="number"
                                                                                step="0.01"
                                                                                min="0"
                                                                                className="form-control form-control-sm"
                                                                                aria-label={`${size.value} — ${t(
                                                                                    bound === 'min'
                                                                                        ? 'admin.weightMinKg'
                                                                                        : 'admin.weightMaxKg',
                                                                                )}`}
                                                                                value={
                                                                                    sizeGuides[size.id]?.[bound] ?? ''
                                                                                }
                                                                                onChange={(event) => {
                                                                                    setSizeGuides((prev) => ({
                                                                                        ...prev,
                                                                                        [size.id]: {
                                                                                            min:
                                                                                                prev[size.id]?.min ??
                                                                                                '',
                                                                                            max:
                                                                                                prev[size.id]?.max ??
                                                                                                '',
                                                                                            [bound]: event.target.value,
                                                                                        },
                                                                                    }));
                                                                                    clearServerErrors(
                                                                                        `size_guides.${row}`,
                                                                                    );
                                                                                }}
                                                                            />
                                                                        </FormField>
                                                                    </td>
                                                                );
                                                            })}
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                        <FieldError name="size_guides" message={serverErrors.size_guides} />
                                    </>
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
                                        <FormField
                                            name="product_type"
                                            label={t('admin.productType')}
                                            error={serverErrors.product_type}
                                            required
                                            hint={
                                                productType === 'advertisement'
                                                    ? t('admin.advertisementStockExplainer')
                                                    : undefined
                                            }
                                        >
                                            <select className="form-control" {...register('product_type')}>
                                                <option value="real">{t('admin.realInventoryTracked')}</option>
                                                <option value="advertisement">
                                                    {t('admin.advertisementPreOrderNotTracked')}
                                                </option>
                                            </select>
                                        </FormField>
                                    </div>
                                    <div className="col-lg-4">
                                        <FormField
                                            name="sort_order"
                                            label={t('admin.sortOrder')}
                                            error={serverErrors.sort_order}
                                            required
                                        >
                                            <input
                                                type="number"
                                                className="form-control"
                                                {...register('sort_order', { valueAsNumber: true })}
                                            />
                                        </FormField>
                                    </div>
                                </div>
                                <div className="d-flex flex-wrap gap-4">
                                    {(
                                        [
                                            ['status', 'status', 'admin.active'],
                                            ['is_featured', 'featured', 'admin.featured'],
                                            ['is_new', 'isNew', 'admin.new'],
                                            ['is_on_sale', 'onSale', 'admin.onSaleBadge'],
                                        ] as const
                                    ).map(([name, id, label]) => (
                                        <div className="form-check" key={name}>
                                            <input
                                                type="checkbox"
                                                className={`form-check-input${invalidClass(serverErrors[name])}`}
                                                {...invalidProps(name, serverErrors[name], id)}
                                                {...register(name)}
                                            />
                                            <label className="form-check-label" htmlFor={id}>
                                                {t(label)}
                                            </label>
                                            <FieldError name={name} message={serverErrors[name]} id={id} />
                                        </div>
                                    ))}
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
    name: 'category_ids' | 'collection_ids' | `variants.${number}.attribute_value_ids`;
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
