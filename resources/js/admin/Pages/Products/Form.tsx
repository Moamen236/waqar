import { Head, router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { useDropzone } from 'react-dropzone';
import { Controller, type Control, useFieldArray, useForm } from 'react-hook-form';
import Tab from 'react-bootstrap/Tab';
import Tabs from 'react-bootstrap/Tabs';
import ReactQuill from 'react-quill-new';
import 'react-quill-new/dist/quill.snow.css';
import Select from 'react-select';
import type { FormDataConvertible } from '@inertiajs/core';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';

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

// Ported from Admin Template/apps-ecommerce-product-add.html's tabbed
// wizard (General Detail / Product Images / Meta Data / Finish) — its
// own General Detail tab already uses a Quill "snow" editor for the
// description and a dropzone for images, which is exactly what this form
// already did; the tabs themselves and their nav-tabs/card-header-tabs
// classes are the template's real markup. See [[admin-ui-use-larkon-template]].
export default function ProductForm({
    product,
    categories,
    collections,
    attributes,
}: {
    product: ProductRecord | null;
    categories: Option[];
    collections: Option[];
    attributes: AttributeOption[];
}) {
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
            sku: product?.sku ?? '',
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

    async function removeExistingImage(image: ProductImage) {
        if (!product) return;
        if (!(await confirmAction({ title: 'Remove this image?', danger: true }))) return;
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
        <AdminLayout title={product ? 'Edit Product' : 'New Product'}>
            <Head title={product ? 'Edit Product' : 'New Product'} />
            <form onSubmit={handleSubmit(onSubmit)}>
                <div className="row">
                    <div className="col-12">
                        <div className="card">
                            <div className="card-header">
                                <Tabs defaultActiveKey="general" className="nav-tabs card-header-tabs border-0">
                                    <Tab eventKey="general" title="General Detail">
                                        <div className="row">
                                            <div className="col-lg-6">
                                                <div className="mb-3">
                                                    <label className="form-label">Name (English)</label>
                                                    <input className="form-control" {...register('name_en')} />
                                                    {serverErrors['name.en'] && (
                                                        <div className="text-danger small mt-1">
                                                            {serverErrors['name.en']}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="col-lg-6">
                                                <div className="mb-3">
                                                    <label className="form-label">Name (Arabic)</label>
                                                    <input
                                                        className="form-control"
                                                        dir="rtl"
                                                        {...register('name_ar')}
                                                    />
                                                </div>
                                            </div>
                                            <div className="col-lg-6">
                                                <div className="mb-3">
                                                    <label className="form-label">SKU</label>
                                                    <input className="form-control" {...register('sku')} />
                                                    {serverErrors.sku && (
                                                        <div className="text-danger small mt-1">{serverErrors.sku}</div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="col-lg-6">
                                                <div className="mb-3">
                                                    <label className="form-label">Slug (auto-generated if blank)</label>
                                                    <input className="form-control" {...register('slug')} />
                                                </div>
                                            </div>
                                            <div className="col-lg-12">
                                                <div className="mb-3">
                                                    <label className="form-label">Description</label>
                                                    <ReactQuillField control={control} name="description_en" />
                                                </div>
                                            </div>
                                            <div className="col-lg-6">
                                                <div className="mb-3">
                                                    <label className="form-label">Short Description</label>
                                                    <textarea
                                                        className="form-control"
                                                        rows={4}
                                                        {...register('short_description_en')}
                                                    />
                                                </div>
                                            </div>
                                            <div className="col-lg-6">
                                                <div className="mb-3">
                                                    <label className="form-label">Categories</label>
                                                    <MultiSelectField
                                                        control={control}
                                                        name="category_ids"
                                                        options={categoryOptions}
                                                    />
                                                </div>
                                                <div className="mb-3">
                                                    <label className="form-label">Collections</label>
                                                    <MultiSelectField
                                                        control={control}
                                                        name="collection_ids"
                                                        options={collectionOptions}
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </Tab>

                                    <Tab eventKey="images" title="Product Images">
                                        <h5 className="fs-14 mb-1">Product Gallery</h5>
                                        <p className="text-muted fs-13">Add product gallery images.</p>
                                        <div className="d-flex flex-wrap gap-3 mb-3">
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
                                            {newImages.map((file, i) => (
                                                <img
                                                    key={i}
                                                    src={URL.createObjectURL(file)}
                                                    alt=""
                                                    style={{ width: 100, height: 100, objectFit: 'cover' }}
                                                    className="rounded border"
                                                />
                                            ))}
                                        </div>
                                        <div
                                            {...getRootProps()}
                                            className={`dropzone bg-light-subtle py-5 ${isDragActive ? 'bg-light' : ''}`}
                                        >
                                            <input {...getInputProps()} />
                                            <div className="dz-message needsclick text-center">
                                                <i className="bx bx-cloud-upload fs-48 text-primary" />
                                                <h3 className="mt-4">
                                                    Drop your images here, or{' '}
                                                    <span className="text-primary">click to browse</span>
                                                </h3>
                                            </div>
                                        </div>
                                    </Tab>

                                    <Tab eventKey="pricing" title="Pricing & Variants">
                                        <div className="row mb-4">
                                            <div className="col-lg-4">
                                                <div className="mb-3">
                                                    <label className="form-label">Price</label>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        className="form-control"
                                                        {...register('price')}
                                                    />
                                                    {serverErrors.price && (
                                                        <div className="text-danger small mt-1">
                                                            {serverErrors.price}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="col-lg-4">
                                                <div className="mb-3">
                                                    <label className="form-label">Sale Price</label>
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
                                                    <label className="form-label">Cost Price (internal only)</label>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        className="form-control"
                                                        {...register('cost_price')}
                                                    />
                                                </div>
                                            </div>
                                        </div>

                                        <h5 className="fs-14 mb-1">Variants</h5>
                                        <p className="text-muted fs-13">
                                            Each row is a distinct SKU — select its Color/Size etc. below.
                                        </p>
                                        <div className="table-responsive mb-2">
                                            <table className="table align-middle mb-0 table-centered">
                                                <thead className="bg-light-subtle">
                                                    <tr>
                                                        <th>SKU</th>
                                                        <th>Attributes</th>
                                                        <th>Price Override</th>
                                                        <th>Sale Price</th>
                                                        <th>Active</th>
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
                                                                            attribute_value_ids: selected.map(
                                                                                (s) => s.value,
                                                                            ),
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
                                            Add Variant
                                        </button>
                                        {serverErrors.variants && (
                                            <div className="text-danger small mt-2">{serverErrors.variants}</div>
                                        )}
                                    </Tab>

                                    <Tab eventKey="organization" title="Organization">
                                        <div className="row">
                                            <div className="col-lg-4">
                                                <div className="mb-3">
                                                    <label className="form-label">Product Type</label>
                                                    <select className="form-control" {...register('product_type')}>
                                                        <option value="real">Real (inventory-tracked)</option>
                                                        <option value="advertisement">
                                                            Advertisement (pre-order / not tracked)
                                                        </option>
                                                    </select>
                                                    {productType === 'advertisement' && (
                                                        <div className="form-text">
                                                            Checkout skips the stock check entirely for this product
                                                            until it&apos;s converted to Real.
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="col-lg-4">
                                                <div className="mb-3">
                                                    <label className="form-label">Sort Order</label>
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
                                                    Active
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
                                                    Featured
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
                                                    New
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
                                                    On Sale badge
                                                </label>
                                            </div>
                                        </div>
                                    </Tab>
                                </Tabs>
                            </div>
                            <div className="card-footer border-top text-end">
                                <button type="submit" className="btn btn-primary">
                                    Save Product
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
