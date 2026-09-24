import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import FieldError from '../../Components/Form/FieldError';
import FormField from '../../Components/Form/FormField';
import AdminLayout from '../../Layouts/AdminLayout';
import { invalidClass, invalidProps, useClearErrorsOnChange } from '../../lib/formErrors';
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
    const { data, setData, post, put, processing, errors, clearErrors } = useForm({
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
    useClearErrorsOnChange(data, errors, clearErrors);

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
        <AdminLayout
            title={employee ? t('admin.editEmployee') : t('admin.newEmployee')}
            breadcrumbs={[{ label: t('admin.employees'), href: route('admin.employees.index') }]}
        >
            <Head title={employee ? t('admin.editEmployee') : t('admin.newEmployee')} />
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
                                        <FormField
                                            name="full_name"
                                            label={t('admin.fullName')}
                                            error={errors.full_name}
                                            required
                                        >
                                            <input
                                                className="form-control"
                                                value={data.full_name}
                                                onChange={(e) => setData('full_name', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField name="email" label={t('admin.email')} error={errors.email} required>
                                            <input
                                                type="email"
                                                className="form-control"
                                                value={data.email}
                                                onChange={(e) => setData('email', e.target.value)}
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
                                    <div className="col-lg-6">
                                        {/* Required only when creating: an edit keeps the
                                            current password unless a new one is typed. */}
                                        <FormField
                                            name="password"
                                            label={employee ? t('admin.newPasswordOptional') : t('admin.password')}
                                            error={errors.password}
                                            required={!employee}
                                        >
                                            <input
                                                type="password"
                                                className="form-control"
                                                autoComplete="new-password"
                                                value={data.password}
                                                onChange={(e) => setData('password', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="residence_address"
                                            label={t('admin.residenceAddress')}
                                            error={errors.residence_address}
                                            required
                                        >
                                            <input
                                                className="form-control"
                                                value={data.residence_address}
                                                onChange={(e) => setData('residence_address', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField
                                            name="national_id_number"
                                            label={employee ? t('admin.newNationalIdOptional') : t('admin.nationalId')}
                                            error={errors.national_id_number}
                                            required={!employee}
                                        >
                                            <input
                                                className="form-control"
                                                value={data.national_id_number}
                                                onChange={(e) => setData('national_id_number', e.target.value)}
                                            />
                                        </FormField>
                                    </div>
                                    <div className="col-lg-6">
                                        <FormField name="role" label={t('admin.role')} error={errors.role} required>
                                            <select
                                                className="form-control"
                                                value={data.role}
                                                onChange={(e) => setData('role', e.target.value)}
                                            >
                                                {roles.map((r) => (
                                                    <option key={r} value={r}>
                                                        {t(`role.${r}`)}
                                                    </option>
                                                ))}
                                            </select>
                                        </FormField>
                                    </div>
                                    {needsTeamLeader && (
                                        <div className="col-lg-6">
                                            <FormField
                                                name="team_leader_id"
                                                label={t('admin.teamLeaderOptional')}
                                                error={errors.team_leader_id}
                                            >
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
                                            </FormField>
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
                                        className={`form-check-input${invalidClass(errors.is_active)}`}
                                        {...invalidProps('is_active', errors.is_active, 'active')}
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                    />
                                    <label className="form-check-label" htmlFor="active">
                                        {t('admin.active')}
                                    </label>
                                </div>
                                <FieldError name="is_active" message={errors.is_active} id="active" />
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
