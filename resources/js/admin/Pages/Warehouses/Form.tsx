import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import FormField from '../../Components/Form/FormField';
import AdminLayout from '../../Layouts/AdminLayout';
import { useClearErrorsOnChange } from '../../lib/formErrors';
import { useTranslation } from '../../lib/useTranslation';

interface Warehouse {
    id: number;
    name: string;
    address: string;
    phone: string;
    manager_employee_id: number | null;
    is_active: boolean;
}

export default function WarehouseForm({
    warehouse,
    managers,
}: {
    warehouse: Warehouse | null;
    managers: { id: number; full_name: string }[];
}) {
    const { t } = useTranslation();
    const { data, setData, post, put, processing, errors, clearErrors } = useForm({
        name: warehouse?.name ?? '',
        address: warehouse?.address ?? '',
        phone: warehouse?.phone ?? '',
        manager_employee_id: warehouse?.manager_employee_id ? String(warehouse.manager_employee_id) : '',
        is_active: warehouse?.is_active ?? true,
    });
    useClearErrorsOnChange(data, errors, clearErrors);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (warehouse) {
            put(route('admin.warehouses.update', warehouse.id));
        } else {
            post(route('admin.warehouses.store'));
        }
    };

    const title = warehouse ? t('admin.editWarehouse') : t('admin.newWarehouse');

    return (
        <AdminLayout
            title={title}
            breadcrumbs={[{ label: t('admin.warehouses'), href: route('admin.warehouses.index') }]}
        >
            <Head title={title} />
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
                                            name="manager_employee_id"
                                            label={t('admin.warehouseManager')}
                                            error={errors.manager_employee_id}
                                        >
                                            <select
                                                className="form-control"
                                                value={data.manager_employee_id}
                                                onChange={(e) => setData('manager_employee_id', e.target.value)}
                                            >
                                                <option value="">{t('admin.none')}</option>
                                                {managers.map((manager) => (
                                                    <option key={manager.id} value={manager.id}>
                                                        {manager.full_name}
                                                    </option>
                                                ))}
                                            </select>
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="is_active"
                                            label={t('admin.status')}
                                            error={errors.is_active}
                                            className="mb-0"
                                            required
                                        >
                                            <select
                                                className="form-control"
                                                value={data.is_active ? '1' : '0'}
                                                onChange={(e) => setData('is_active', e.target.value === '1')}
                                            >
                                                <option value="1">{t('admin.active')}</option>
                                                <option value="0">{t('admin.inactive')}</option>
                                            </select>
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
