<?php

namespace App\Http\Controllers\Admin\Delivery;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRepresentative;
use App\Models\DeliveryRepresentativeArea;
use App\Support\GeoTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/delivery/representatives (Section 14) — plain CRUD, no
 * dedicated Action needed (no cross-cutting business rule beyond what
 * validation already covers).
 */
class RepresentativeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Delivery/Representatives/Index', [
            'representatives' => DeliveryRepresentative::query()->withCount('areas')->latest('id')->paginate(20),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Delivery/Representatives/Form', ['representative' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DeliveryRepresentative::create($data);

        return redirect()->route('admin.delivery.representatives.index')->with('success', 'Representative created.');
    }

    public function edit(DeliveryRepresentative $representative): Response
    {
        return Inertia::render('Delivery/Representatives/Form', ['representative' => $representative]);
    }

    public function update(Request $request, DeliveryRepresentative $representative): RedirectResponse
    {
        $representative->update($this->validated($request));

        return redirect()->route('admin.delivery.representatives.index')->with('success', 'Representative updated.');
    }

    public function areas(DeliveryRepresentative $representative): Response
    {
        return Inertia::render('Delivery/Representatives/Areas', [
            'representative' => $representative,
            'areas' => $representative->areas()->latest('id')->get(),
            'geoTree' => GeoTree::tree(),
        ]);
    }

    public function storeArea(Request $request, DeliveryRepresentative $representative): RedirectResponse
    {
        $data = $request->validate([
            'geo_type' => ['required', Rule::in(['governorate', 'city', 'district', 'area'])],
            'geo_id' => ['required', 'integer'],
        ]);

        $representative->areas()->create($data);

        return back()->with('success', 'Coverage area added.');
    }

    public function destroyArea(DeliveryRepresentative $representative, DeliveryRepresentativeArea $area): RedirectResponse
    {
        abort_unless($area->delivery_representative_id === $representative->id, 404);

        $area->delete();

        return back()->with('success', 'Coverage area removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
