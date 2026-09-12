import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import AdminLayout from '../../../Layouts/AdminLayout';

interface Representative {
    id: number;
    name: string;
    phone: string;
    status: string;
    notes: string | null;
}

export default function RepresentativeForm({ representative }: { representative: Representative | null }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: representative?.name ?? '',
        phone: representative?.phone ?? '',
        status: representative?.status ?? 'active',
        notes: representative?.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (representative) {
            put(route('admin.delivery.representatives.update', representative.id));
        } else {
            post(route('admin.delivery.representatives.store'));
        }
    };

    return (
        <AdminLayout title={representative ? 'Edit Representative' : 'New Representative'}>
            <Head title={representative ? 'Edit Representative' : 'New Representative'} />
            <form onSubmit={submit}>
                <div className="row">
                    <div className="col-xl-8 col-lg-9">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">General Information</h4>
                            </div>
                            <div className="card-body">
                                <div className="row">
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">Name</label>
                                            <input
                                                className="form-control"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                            />
                                            {errors.name && <div className="text-danger small mt-1">{errors.name}</div>}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">Phone</label>
                                            <input
                                                className="form-control"
                                                value={data.phone}
                                                onChange={(e) => setData('phone', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">Status</label>
                                            <select
                                                className="form-control"
                                                value={data.status}
                                                onChange={(e) => setData('status', e.target.value)}
                                            >
                                                <option value="active">Active</option>
                                                <option value="inactive">Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div className="col-lg-12">
                                        <div className="mb-0">
                                            <label className="form-label">Notes</label>
                                            <textarea
                                                className="form-control"
                                                rows={3}
                                                value={data.notes}
                                                onChange={(e) => setData('notes', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="card-footer border-top text-end">
                                <button type="submit" className="btn btn-primary" disabled={processing}>
                                    Save
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
