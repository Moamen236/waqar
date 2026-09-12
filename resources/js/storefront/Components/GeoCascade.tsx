import { useEffect, useMemo } from 'react';
import type { GeoCountry, GeoSelection } from '../types';

/**
 * The Country → Governorate → City → District → Area cascade (Section
 * 08's resolution, Section 20 #14) that replaces the template's generic
 * Country / State / City / Zip Code fields on checkout, the cart's
 * shipping estimator, and the account address form. Postal Code is
 * dropped — it isn't part of either source document's address model.
 *
 * Country is a real select driven by the `countries` table, not a
 * hard-coded option. When only one country is configured it is selected
 * automatically so nobody has to pick from a list of one, but the level is
 * live: seeding a second country makes it a genuine choice with no code
 * change. Only governorate_id downward is ever submitted — the country is
 * implied by the governorate, which is why `orders`/`addresses` carry no
 * country_id (Section 24).
 *
 * The tree arrives whole with the page (GeoTree::countries()) rather than
 * over four AJAX round-trips — it's a small dataset, and the
 * /api/shipping/* endpoints stay available for callers that want them.
 */
export default function GeoCascade({
    countries,
    value,
    onChange,
    idPrefix = 'geo',
}: {
    countries: GeoCountry[];
    value: GeoSelection;
    onChange: (next: GeoSelection) => void;
    idPrefix?: string;
}) {
    const country = useMemo(
        () =>
            countries.find((item) => item.id === value.country_id) ??
            // An address loaded from a saved record knows its governorate
            // but not its country; infer it rather than making the
            // customer re-pick.
            countries.find((item) => item.governorates.some((g) => g.id === value.governorate_id)) ??
            (countries.length === 1 ? countries[0] : null),
        [countries, value.country_id, value.governorate_id],
    );

    // Single-country stores shouldn't ask a question with one answer.
    useEffect(() => {
        if (value.country_id == null && countries.length === 1) {
            onChange({ ...value, country_id: countries[0].id });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [countries]);

    const governorate = useMemo(
        () => country?.governorates.find((item) => item.id === value.governorate_id) ?? null,
        [country, value.governorate_id],
    );

    const city = useMemo(
        () => governorate?.cities.find((item) => item.id === value.city_id) ?? null,
        [governorate, value.city_id],
    );

    // An area belongs to a city and *optionally* to a district — picking a
    // district narrows the list, but a city with no districts still
    // offers all of its areas (Section 11: district is a level between
    // city and area, not a required one).
    const areas = useMemo(() => {
        if (city === null) {
            return [];
        }

        return value.district_id === null
            ? city.areas
            : city.areas.filter((area) => area.district_id === value.district_id);
    }, [city, value.district_id]);

    return (
        <>
            <div className="select-block">
                <label htmlFor={`${idPrefix}-country`} className="caption1 capitalize">
                    Country <span className="text-red">*</span>
                </label>
                <select
                    id={`${idPrefix}-country`}
                    className="border border-line px-4 py-3 w-full rounded-lg mt-2"
                    value={country?.id ?? ''}
                    onChange={(event) =>
                        onChange({
                            country_id: event.target.value ? Number(event.target.value) : null,
                            governorate_id: null,
                            city_id: null,
                            district_id: null,
                            area_id: null,
                        })
                    }
                >
                    <option value="">Choose country</option>
                    {countries.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="select-block">
                <label htmlFor={`${idPrefix}-governorate`} className="caption1 capitalize">
                    Governorate <span className="text-red">*</span>
                </label>
                <select
                    id={`${idPrefix}-governorate`}
                    className="border border-line px-4 py-3 w-full rounded-lg mt-2"
                    value={value.governorate_id ?? ''}
                    disabled={country === null}
                    onChange={(event) =>
                        onChange({
                            country_id: country?.id ?? null,
                            governorate_id: event.target.value ? Number(event.target.value) : null,
                            city_id: null,
                            district_id: null,
                            area_id: null,
                        })
                    }
                >
                    <option value="">Choose governorate</option>
                    {country?.governorates.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="select-block">
                <label htmlFor={`${idPrefix}-city`} className="caption1 capitalize">
                    City <span className="text-red">*</span>
                </label>
                <select
                    id={`${idPrefix}-city`}
                    className="border border-line px-4 py-3 w-full rounded-lg mt-2"
                    value={value.city_id ?? ''}
                    disabled={governorate === null}
                    onChange={(event) =>
                        onChange({
                            ...value,
                            city_id: event.target.value ? Number(event.target.value) : null,
                            district_id: null,
                            area_id: null,
                        })
                    }
                >
                    <option value="">Choose city</option>
                    {governorate?.cities.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="select-block">
                <label htmlFor={`${idPrefix}-district`} className="caption1 capitalize">
                    District
                </label>
                <select
                    id={`${idPrefix}-district`}
                    className="border border-line px-4 py-3 w-full rounded-lg mt-2"
                    value={value.district_id ?? ''}
                    disabled={city === null || city.districts.length === 0}
                    onChange={(event) =>
                        onChange({
                            ...value,
                            district_id: event.target.value ? Number(event.target.value) : null,
                            area_id: null,
                        })
                    }
                >
                    <option value="">
                        {city !== null && city.districts.length === 0 ? 'No districts in this city' : 'Choose district'}
                    </option>
                    {city?.districts.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="select-block">
                <label htmlFor={`${idPrefix}-area`} className="caption1 capitalize">
                    Area <span className="text-red">*</span>
                </label>
                <select
                    id={`${idPrefix}-area`}
                    className="border border-line px-4 py-3 w-full rounded-lg mt-2"
                    value={value.area_id ?? ''}
                    disabled={city === null}
                    onChange={(event) =>
                        onChange({ ...value, area_id: event.target.value ? Number(event.target.value) : null })
                    }
                >
                    <option value="">Choose area</option>
                    {areas.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </div>
        </>
    );
}
