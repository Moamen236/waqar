import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AccountNav from '../../Components/AccountNav';
import Breadcrumb from '../../Components/Breadcrumb';
import GeoCascade from '../../Components/GeoCascade';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { GeoCountry, GeoSelection } from '../../types';

interface AddressRow extends GeoSelection {
    id: number;
    label: string;
    recipient_name: string;
    phone: string;
    address_line: string;
    is_default: boolean;
    summary: string;
}

/**
 * my-account.html's Address tab. Its Billing/Shipping accordion pair is
 * dropped — no billing concept exists in a COD-only system with no card
 * storage (Q12) — and its Country/State/City/ZIP text inputs become the
 * real geo cascade (Section 20 #14). Customers keep a list of addresses
 * rather than one fixed pair, which is what `addresses` has always
 * modelled (Section 24).
 */
export default function AccountAddresses({
    addresses,
    countries,
}: {
    addresses: AddressRow[];
    countries: GeoCountry[];
}) {
    const [editing, setEditing] = useState<AddressRow | null>(null);

    const form = useForm({
        label: '',
        recipient_name: '',
        phone: '',
        governorate_id: null as number | null,
        city_id: null as number | null,
        district_id: null as number | null,
        area_id: null as number | null,
        address_line: '',
        is_default: false,
    });

    const startEdit = (address: AddressRow) => {
        setEditing(address);
        form.setData({
            label: address.label,
            recipient_name: address.recipient_name,
            phone: address.phone,
            governorate_id: address.governorate_id,
            city_id: address.city_id,
            district_id: address.district_id,
            area_id: address.area_id,
            address_line: address.address_line,
            is_default: address.is_default,
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (editing !== null) {
            form.put(route('account.addresses.update', editing.id), {
                preserveScroll: true,
                onSuccess: () => {
                    setEditing(null);
                    form.reset();
                },
            });

            return;
        }

        form.post(route('account.addresses.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <StorefrontLayout>
            <Head title="My Address" />
            <Breadcrumb title="My Address" />

            <div className="my-account-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main lg:px-[60px] md:px-4 flex gap-y-8 max-md:flex-col w-full">
                        <AccountNav active="addresses" />
                        <div className="right list-filter md:w-2/3 w-full pl-2.5">
                            <div className="tab_address text-content w-full p-7 border border-line rounded-xl">
                                <strong className="heading6">Saved addresses</strong>
                                <div className="grid gap-4 mt-4">
                                    {addresses.length === 0 && (
                                        <div className="caption1 text-secondary">No addresses saved yet.</div>
                                    )}
                                    {addresses.map((address) => (
                                        <div
                                            key={address.id}
                                            className="flex flex-wrap items-start justify-between gap-3 p-5 border border-line rounded-lg"
                                        >
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <strong className="text-title">{address.label}</strong>
                                                    {address.is_default && (
                                                        <span className="caption2 bg-green px-2 py-0.5 rounded-full">
                                                            Default
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="caption1 text-secondary mt-1">
                                                    {address.recipient_name} · {address.phone}
                                                </div>
                                                <div className="caption1 text-secondary mt-1">{address.summary}</div>
                                            </div>
                                            <div className="flex items-center gap-4">
                                                <button
                                                    type="button"
                                                    className="text-button underline"
                                                    onClick={() => startEdit(address)}
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    className="text-button text-red underline"
                                                    onClick={() =>
                                                        router.delete(route('account.addresses.destroy', address.id), {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <form onSubmit={submit} className="mt-10">
                                    <strong className="heading6">
                                        {editing === null ? 'Add a new address' : `Edit “${editing.label}”`}
                                    </strong>
                                    <div className="grid sm:grid-cols-2 gap-4 gap-y-5 mt-5">
                                        <div>
                                            <label htmlFor="label" className="caption1 capitalize">
                                                Label <span className="text-red">*</span>
                                            </label>
                                            <input
                                                id="label"
                                                className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                                type="text"
                                                placeholder="Home, Work…"
                                                value={form.data.label}
                                                onChange={(event) => form.setData('label', event.target.value)}
                                                required
                                            />
                                        </div>
                                        <div>
                                            <label htmlFor="recipient_name" className="caption1 capitalize">
                                                Recipient name <span className="text-red">*</span>
                                            </label>
                                            <input
                                                id="recipient_name"
                                                className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                                type="text"
                                                value={form.data.recipient_name}
                                                onChange={(event) => form.setData('recipient_name', event.target.value)}
                                                required
                                            />
                                        </div>
                                        <div>
                                            <label htmlFor="phone" className="caption1 capitalize">
                                                Phone <span className="text-red">*</span>
                                            </label>
                                            <input
                                                id="phone"
                                                className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                                type="text"
                                                value={form.data.phone}
                                                onChange={(event) => form.setData('phone', event.target.value)}
                                                required
                                            />
                                        </div>
                                        <GeoCascade
                                            countries={countries}
                                            value={{
                                                governorate_id: form.data.governorate_id,
                                                city_id: form.data.city_id,
                                                district_id: form.data.district_id,
                                                area_id: form.data.area_id,
                                            }}
                                            onChange={(next) => form.setData((data) => ({ ...data, ...next }))}
                                            idPrefix="address"
                                        />
                                        <div className="col-span-full">
                                            <label htmlFor="address_line" className="caption1 capitalize">
                                                Street address <span className="text-red">*</span>
                                            </label>
                                            <input
                                                id="address_line"
                                                className="border-line mt-2 px-4 py-3 w-full rounded-lg"
                                                type="text"
                                                value={form.data.address_line}
                                                onChange={(event) => form.setData('address_line', event.target.value)}
                                                required
                                            />
                                        </div>
                                        <div className="col-span-full flex items-center">
                                            <div className="block-input">
                                                <input
                                                    type="checkbox"
                                                    id="is_default"
                                                    checked={form.data.is_default}
                                                    onChange={(event) =>
                                                        form.setData('is_default', event.target.checked)
                                                    }
                                                />
                                                <i className="ph-fill ph-check-square icon-checkbox text-2xl"></i>
                                            </div>
                                            <label htmlFor="is_default" className="text-title pl-2 cursor-pointer">
                                                Make this my default address
                                            </label>
                                        </div>
                                    </div>
                                    <div className="block-button lg:mt-10 mt-6 flex items-center gap-4">
                                        <button
                                            type="submit"
                                            className="button-main bg-black"
                                            disabled={form.processing}
                                        >
                                            {editing === null ? 'Save address' : 'Update address'}
                                        </button>
                                        {editing !== null && (
                                            <button
                                                type="button"
                                                className="text-button underline"
                                                onClick={() => {
                                                    setEditing(null);
                                                    form.reset();
                                                }}
                                            >
                                                Cancel
                                            </button>
                                        )}
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
