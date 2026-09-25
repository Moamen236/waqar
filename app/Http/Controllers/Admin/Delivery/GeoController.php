<?php

namespace App\Http\Controllers\Admin\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\District;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\ShippingRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/geo/{level} — the Governorate → City → District → Area tables
 * behind every address in the system (Section 11).
 *
 * The tables, models and GeoTree have existed since Phase 1; only the
 * screens were missing, so adding a city meant a seeder or a SQL client.
 *
 * One controller over a `{level}` segment rather than four near-identical
 * ones. The four levels differ only in which model they write, which
 * parent they hang off, and — for districts alone — whether the active
 * flag is called `status` or `is_active`. ShippingRateController already
 * resolves its four geo levels with exactly this `match`, so the shape is
 * the house idiom rather than an invention; four copies of this file
 * would be the unusual choice.
 */
class GeoController extends Controller implements HasMiddleware
{
    /**
     * Everything that varies between the four levels, in one table.
     *
     * `active` differs because `districts` was added in a later migration
     * than its three siblings and named the column `status`. Normalising
     * that in the UI rather than with a migration keeps this change to
     * screens only — the column name is load-bearing for GeoTree and the
     * storefront cascade.
     *
     * @var array<string, array{model: class-string<Model>, parent: string|null, parentModel: class-string<Model>|null, active: string, label: string}>
     */
    private const LEVELS = [
        'governorates' => [
            'model' => Governorate::class,
            'parent' => 'country_id',
            'parentModel' => Country::class,
            'active' => 'is_active',
            'label' => 'Governorate',
        ],
        'cities' => [
            'model' => City::class,
            'parent' => 'governorate_id',
            'parentModel' => Governorate::class,
            'active' => 'is_active',
            'label' => 'City',
        ],
        'districts' => [
            'model' => District::class,
            'parent' => 'city_id',
            'parentModel' => City::class,
            'active' => 'status',
            'label' => 'District',
        ],
        'areas' => [
            'model' => Area::class,
            'parent' => 'city_id',
            'parentModel' => City::class,
            'active' => 'is_active',
            'label' => 'Area',
        ],
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('permission:geo.view', only: ['index']),
            new Middleware('permission:geo.create', only: ['store']),
            new Middleware('permission:geo.update', only: ['update']),
            new Middleware('permission:geo.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request, string $level): Response
    {
        $config = self::config($level);
        $model = $config['model'];
        $parentId = $request->integer('parent') ?: null;

        $rows = $model::query()
            ->when($parentId !== null, fn ($query) => $query->where($config['parent'], $parentId))
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Model $row) => $this->present($row, $config, $level));

        return Inertia::render('Delivery/Geo/Index', [
            'level' => $level,
            'levels' => array_keys(self::LEVELS),
            'rows' => $rows,
            'parentId' => $parentId,
            'parents' => $this->parentOptions($config),
            // Areas alone carry a second, optional parent: a district
            // inside the chosen city (Section 24, confirmation #12).
            'districts' => $level === 'areas'
                ? District::query()->orderBy('id')->get()->map(fn (District $district) => [
                    'id' => $district->id,
                    'city_id' => $district->city_id,
                    'name' => $this->name($district),
                ])->values()
                : null,
        ]);
    }

    public function store(Request $request, string $level): RedirectResponse
    {
        $config = self::config($level);
        $config['model']::create($this->validated($request, $config, $level));

        return back()->with('success', __(':level added.', ['level' => __($config['label'])]));
    }

    public function update(Request $request, string $level, int $id): RedirectResponse
    {
        $config = self::config($level);
        $row = $config['model']::findOrFail($id);
        $row->update($this->validated($request, $config, $level));

        return back()->with('success', __(':level updated.', ['level' => __($config['label'])]));
    }

    /**
     * Deleting a place that anything still points at would orphan real
     * records — an address, or worse a delivered order whose shipping
     * snapshot names it. There is no `deleted_at` on these four tables, so
     * rather than add soft deletes to all of them, a referenced row is
     * refused outright and the operator is pointed at the inactive flag,
     * which is what "retire a city" actually means: GeoTree already
     * filters on it, so the place disappears from checkout while every
     * historical record still reads back correctly.
     */
    public function destroy(string $level, int $id): RedirectResponse
    {
        $config = self::config($level);
        $row = $config['model']::findOrFail($id);
        $blockers = $this->blockers($level, $id);

        if ($blockers !== []) {
            return back()->with('error', __(
                'That :level is still used by :what — deactivate it instead of deleting it.',
                ['level' => mb_strtolower(__($config['label'])), 'what' => implode('، ', $blockers)],
            ));
        }

        $row->delete();

        return back()->with('success', __(':level removed.', ['level' => __($config['label'])]));
    }

    /**
     * @param  array{model: class-string<Model>, parent: string|null, parentModel: class-string<Model>|null, active: string, label: string}  $config
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $config, string $level): array
    {
        $rules = [
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            $config['parent'] => ['required', 'integer', 'exists:'.(new $config['parentModel'])->getTable().',id'],
        ];

        if ($level === 'areas') {
            $rules['district_id'] = ['nullable', 'integer', 'exists:districts,id'];
        }

        $data = $request->validate($rules);

        $attributes = [
            'name' => ['ar' => $data['name']['ar'], 'en' => $data['name']['en']],
            $config['parent'] => $data[$config['parent']],
            // Normalised here so the form posts one field name whichever
            // level it is editing.
            $config['active'] => (bool) ($data['is_active'] ?? true),
        ];

        if ($level === 'areas') {
            $districtId = $data['district_id'] ?? null;

            // A district belongs to a city; letting an area name one from
            // a different city would produce an address the cascade can
            // never rebuild.
            if ($districtId !== null) {
                abort_unless(
                    District::whereKey($districtId)->where('city_id', $data['city_id'])->exists(),
                    422,
                    __('That district is not in the chosen city.'),
                );
            }

            $attributes['district_id'] = $districtId;
        }

        return $attributes;
    }

    /**
     * Everything that would be orphaned by deleting this row, named in
     * the operator's language rather than as table names.
     *
     * @return list<string>
     */
    private function blockers(string $level, int $id): array
    {
        $column = match ($level) {
            'governorates' => 'governorate_id',
            'cities' => 'city_id',
            'districts' => 'district_id',
            default => 'area_id',
        };

        $found = [];

        $children = match ($level) {
            'governorates' => City::where('governorate_id', $id)->count(),
            'cities' => District::where('city_id', $id)->count() + Area::where('city_id', $id)->count(),
            'districts' => Area::where('district_id', $id)->count(),
            default => 0,
        };

        if ($children > 0) {
            $found[] = __(':count places inside it', ['count' => $children]);
        }

        $addresses = DB::table('addresses')->where($column, $id)->count();
        if ($addresses > 0) {
            $found[] = __(':count saved addresses', ['count' => $addresses]);
        }

        $orders = Order::where('shipping_'.$column, $id)->count();
        if ($orders > 0) {
            $found[] = __(':count orders', ['count' => $orders]);
        }

        $rates = ShippingRate::where('geo_type', rtrim($level, 's'))->where('geo_id', $id)->count();
        if ($rates > 0) {
            $found[] = __('a shipping rate');
        }

        return $found;
    }

    /**
     * @param  array{model: class-string<Model>, parent: string|null, parentModel: class-string<Model>|null, active: string, label: string}  $config
     * @return array<string, mixed>
     */
    private function present(Model $row, array $config, string $level): array
    {
        return [
            'id' => $row->id,
            'name' => $this->name($row),
            'name_ar' => $row->getTranslation('name', 'ar'),
            'name_en' => $row->getTranslation('name', 'en'),
            'parent_id' => $row->{$config['parent']},
            'district_id' => $level === 'areas' ? $row->district_id : null,
            'is_active' => (bool) $row->{$config['active']},
        ];
    }

    /**
     * @param  array{model: class-string<Model>, parent: string|null, parentModel: class-string<Model>|null, active: string, label: string}  $config
     * @return Collection<int, array<string, mixed>>
     */
    private function parentOptions(array $config)
    {
        return $config['parentModel']::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Model $row) => ['id' => $row->id, 'name' => $this->name($row)])
            ->values();
    }

    private function name(Model $row): string
    {
        return $row->getTranslation('name', app()->getLocale());
    }

    /**
     * @return array{model: class-string<Model>, parent: string|null, parentModel: class-string<Model>|null, active: string, label: string}
     */
    private static function config(string $level): array
    {
        abort_unless(array_key_exists($level, self::LEVELS), 404);

        return self::LEVELS[$level];
    }
}
