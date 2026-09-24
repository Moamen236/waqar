import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import FormField from '../../../Components/Form/FormField';
import AdminLayout from '../../../Layouts/AdminLayout';
import { useClearErrorsOnChange } from '../../../lib/formErrors';
import { useTranslation } from '../../../lib/useTranslation';

interface ShippingCompany {
    id: number;
    name: string;
    phone: string;
    address: string;
    delivery_fee: string;
    return_fee: string;
    status: string;
    contact_person: string | null;
    bank_name: string | null;
    bank_account_number: string | null;
    notes: string | null;
}

export default function ShippingCompanyForm({ shippingCompany }: { shippingCompany: ShippingCompany | null }) {
    const { t } = useTranslation();
    const { data, setData, post, put, processing, errors, clearErrors } = useForm({
        name: shippingCompany?.name ?? '',
        phone: shippingCompany?.phone ?? '',
        address: shippingCompany?.address ?? '',
        delivery_fee: shippingCompany?.delivery_fee ?? '',
        return_fee: shippingCompany?.return_fee ?? '',
        status: shippingCompany?.status ?? 'active',
        contact_person: shippingCompany?.contact_person ?? '',
        bank_name: shippingCompany?.bank_name ?? '',
        bank_account_number: shippingCompany?.bank_account_number ?? '',
        notes: shippingCompany?.notes ?? '',
    });
    useClearErrorsOnChange(data, errors, clearErrors);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (shippingCompany) {
            put(route('admin.delivery.shipping-companies.update', shippingCompany.id));
        } else {
            post(route('admin.delivery.shipping-companies.store'));
        }
    };

    return (
        <AdminLayout
            title={shippingCompany ? t('admin.editShippingCompany') : t('admin.newShippingCompany')}
            breadcrumbs={[
                { label: t('admin.shippingCompanies'), href: route('admin.delivery.shipping-companies.index') },
            ]}
        >
            <Head title={shippingCompany ? t('admin.editShippingCompany') : t('admin.newShippingCompany')} />
            <form onSubmit={submit}>
                <div className="row">
                    <div className="col-xl-9 col-lg-8">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.generalInformation')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="row">
                                    <div className="col-lg-6">
                                        <FormField name="name" label={t('admin.name')} error={errors.name} required>
                                            <input
                                                className="form-control"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField name="phone" label={t('admin.phone')} error={errors.phone} required>
                                            <input
                                                className="form-control"
                                                value={data.phone}
                                                onChange={(e) => setData('phone', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-12">
                                        <FormField
                                            name="address"
                                            label={t('admin.address')}
                                            error={errors.address}
                                            required
                                        >
                                            <input
                                                className="form-control"
                                                value={data.address}
                                                onChange={(e) => setData('address', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="delivery_fee"
                                            label={t('admin.deliveryFee')}
                                            error={errors.delivery_fee}
                                            required
                                        >
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                value={data.delivery_fee}
                                                onChange={(e) => setData('delivery_fee', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="return_fee"
                                            label={t('admin.returnFee')}
                                            error={errors.return_fee}
                                            required
                                        >
                                            <input
                                                type="number"
                                                step="0.01"
                                                className="form-control"
                                                value={data.return_fee}
                                                onChange={(e) => setData('return_fee', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-4">
                                        <FormField
                                            name="status"
                                            label={t('admin.status')}
                                            error={errors.status}
                                            required
                                        >
                                            <select
                                                className="form-control"
                                                value={data.status}
                                                onChange={(e) => setData('status', e.target.value)}
                                            >
                                                <option value="active">{t('admin.active')}</option>
                                                <option value="inactive">{t('admin.inactive')}</option>
                                            </select>
                                        </FormField>
                                    </div>
                                    <div className="col-lg-4">
                                        <FormField
                                            name="contact_person"
                                            label={t('admin.contactPerson')}
                                            error={errors.contact_person}
                                        >
                                            <input
                                                className="form-control"
                                                value={data.contact_person}
                                                onChange={(e) => setData('contact_person', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-4">
                                        <FormField
                                            name="bank_name"
                                            label={t('admin.bankName')}
                                            error={errors.bank_name}
                                        >
                                            <input
                                                className="form-control"
                                                value={data.bank_name}
                                                onChange={(e) => setData('bank_name', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="bank_account_number"
                                            label={t('admin.bankAccount')}
                                            error={errors.bank_account_number}
                                        >
                                            <input
                                                className="form-control"
                                                value={data.bank_account_number}
                                                onChange={(e) => setData('bank_account_number', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-12">
                                        <FormField
                                            name="notes"
                                            label={t('admin.notes')}
                                            error={errors.notes}
                                            className="mb-0"
                                        >
                                            <textarea
                                                className="form-control"
                                                rows={2}
                                                value={data.notes}
                                                onChange={(e) => setData('notes', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                </div>
                            </div>
                            <div className="card-footer border-top text-end">
                                <button type="submit" className="btn btn-primary" disabled={processing}>
                                    {t('admin.save')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
