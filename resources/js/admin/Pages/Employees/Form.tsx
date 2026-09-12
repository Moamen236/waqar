import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';

interface EmployeeRecord {
    id: number;
    full_name: string;
    email: string;
    phone: string;
    residence_address: string;
    is_active: boolean;
    team_leader_id: number | null;
    roles: { id: number; name: string }[];
}

// Ported from Admin Template/role-add.html's General Information card
// layout, plus the department-role select this project actually needs
// (Section 17's audit found role-add.html itself has no real permission
// UI to reference — that's Roles/Edit.tsx instead).
export default function EmployeeForm({
    employee,
    roles,
    teamLeaders,
}: {
    employee: EmployeeRecord | null;
    roles: string[];
    teamLeaders: { id: number; full_name: string }[];
}) {
    const { t } = useTranslation();
    const { data, setData, post, put, processing, errors } = useForm({
        full_name: employee?.full_name ?? '',
        email: employee?.email ?? '',
        phone: employee?.phone ?? '',
        password: '',
        residence_address: employee?.residence_address ?? '',
        national_id_number: '',
        is_active: employee?.is_active ?? true,
        role: employee?.roles[0]?.name ?? roles[0] ?? '',
        team_leader_id: employee?.team_leader_id ?? ('' as number | ''),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (employee) {
            put(route('admin.employees.update', employee.id));
        } else {
            post(route('admin.employees.store'));
        }
    };

    const needsTeamLeader = data.role === 'Customer Service';

    return (
        <AdminLayout title={employee ? 'Edit Employee' : 'New Employee'}>
            <Head title={employee ? 'Edit Employee' : 'New Employee'} />
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
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.fullName')}</label>
                                            <input
                                                className="form-control"
                                                value={data.full_name}
                                                onChange={(e) => setData('full_name', e.target.value)}
                                            />
                                            {errors.full_name && (
                                                <div className="text-danger small mt-1">{errors.full_name}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.email')}</label>
                                            <input
                                                type="email"
                                                className="form-control"
                                                value={data.email}
                                                onChange={(e) => setData('email', e.target.value)}
                                            />
                                            {errors.email && (
                                                <div className="text-danger small mt-1">{errors.email}</div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.phone')}</label>
                                            <input
                                                className="form-control"
                                                value={data.phone}
                                                onChange={(e) => setData('phone', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">
                                                {employee ? 'New Password (optional)' : 'Password'}
                                            </label>
                                            <input
                                                type="password"
                                                className="form-control"
                                                value={data.password}
                                                onChange={(e) => setData('password', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.residenceAddress')}</label>
                                            <input
                                                className="form-control"
                                                value={data.residence_address}
                                                onChange={(e) => setData('residence_address', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">
                                                {employee ? 'New National ID (optional)' : 'National ID'}
                                            </label>
                                            <input
                                                className="form-control"
                                                value={data.national_id_number}
                                                onChange={(e) => setData('national_id_number', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <div className="col-lg-6">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.role')}</label>
                                            <select
                                                className="form-control"
                                                value={data.role}
                                                onChange={(e) => setData('role', e.target.value)}
                                            >
                                                {roles.map((r) => (
                                                    <option key={r} value={r}>
                                                        {r}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>
                                    {needsTeamLeader && (
                                        <div className="col-lg-6">
                                            <div className="mb-3">
                                                <label className="form-label">{t('admin.teamLeaderOptional')}</label>
                                                <select
                                                    className="form-control"
                                                    value={data.team_leader_id}
                                                    onChange={(e) =>
                                                        setData(
                                                            'team_leader_id',
                                                            e.target.value ? Number(e.target.value) : '',
                                                        )
                                                    }
                                                >
                                                    <option value="">{t('admin.none')}</option>
                                                    {teamLeaders.map((leader) => (
                                                        <option key={leader.id} value={leader.id}>
                                                            {leader.full_name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="card-footer border-top text-end">
                                <button type="submit" className="btn btn-primary" disabled={processing}>
                                    {t('admin.saveEmployee')}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="col-xl-3 col-lg-4">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.status')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="form-check">
                                    <input
                                        type="checkbox"
                                        className="form-check-input"
                                        id="active"
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="active">
                                        {t('admin.active')}
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
