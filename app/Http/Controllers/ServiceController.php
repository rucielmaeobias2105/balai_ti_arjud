<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer Flows 4 & 15 — Browse Services / Refined Grid View.
 * Public and read-only: no auth required to view.
 */
class ServiceController extends Controller
{
    /**
     * Customer Flow 4 — Browse Services.
     *
     * One section per category: the name and its services on the left, one
     * representative image on the right.
     *
     * Pagination is gone, deliberately. It paginated the flat service list, so
     * a category could straddle a page boundary — the second half of a category
     * with no heading above it, and half the "See More" button's meaning
     * depending on which page you were on. Categories are the unit now, and a
     * category that is not split across pages is what lets the layout put a
     * heading, a description and a single image beside the whole list.
     *
     * The whole active catalogue is a few dozen rows, so this costs nothing to
     * hold in memory, and the "See More" disclosure keeps a category with
     * twenty services from dominating the page.
     */
    public function index(Request $request): View
    {
        $services = $this->filtered($request)->get();

        return view('services.index', [
            'categories' => $this->byCategory($services),
            'filters' => $request->only(['search', 'category', 'min_price', 'max_price']),
        ]);
    }

    /**
     * Group the catalogue by category, each entry carrying its services and the
     * one image that represents it.
     *
     * The image is resolved through `ServiceCategory::imageUrl()` rather than
     * assembled here, so the same rule — a real uploaded service photo first,
     * the configured category image second — applies wherever a category is
     * pictured and nobody has to re-derive it.
     *
     * Ordering is the admin's `sort_order` and then the name, so the page reads
     * the way the panel has arranged the catalogue rather than alphabetically
     * by a field the admin never chose. A category with no `service_categories`
     * row still appears, at the end: it has services, so hiding it would be
     * worse than showing it without a palette colour.
     *
     * @param  \Illuminate\Support\Collection<int, Service>  $services
     * @return \Illuminate\Support\Collection<int, array{category: self, services: \Illuminate\Support\Collection<int, Service>, image: string, count: int}>
     */
    protected function byCategory($services)
    {
        $rows = ServiceCategory::query()->get()->keyBy('name');

        return $services
            ->groupBy('category')
            ->map(fn ($group, $name) => [
                'category' => $rows->get($name) ?? new ServiceCategory(['name' => $name]),
                'services' => $group->values(),
                'image' => ($rows->get($name) ?? new ServiceCategory(['name' => $name]))->imageUrl(),
                'count' => $group->count(),
            ])
            ->sortBy(function (array $row) {
                $order = $row['category']->exists ? (int) $row['category']->sort_order : PHP_INT_MAX;

                // A missing category row keeps a stable position by name, so the
                // page does not reshuffle between requests.
                return [$order, (string) $row['category']->name];
            })
            ->values();
    }

    /**
     * Customer Flow 15 — Refined Grid View (category-tabbed service grid).
     */
    public function refined(Request $request): View
    {
        $categories = Service::categories();

        $active = $request->string('category')->toString();

        if ($active !== '' && ! in_array($active, $categories, true)) {
            $active = 'all';
        }

        $services = Service::query()
            ->active()
            ->search($request->input('search'))
            ->category($active === 'all' ? null : $active)
            ->orderBy('is_featured', 'desc')
            ->orderBy('name')
            ->paginate(18)
            ->withQueryString();

        return view('services.refined', [
            'services' => $services,
            'categories' => $categories,
            'activeCategory' => $active,
            'search' => $request->input('search'),
        ]);
    }

    /**
     * Service detail.
     */
    public function show(Service $service): View
    {
        abort_unless($service->is_active, 404);

        return view('services.show', [
            'service' => $service,
            'related' => Service::query()
                ->active()
                ->where('category', $service->category)
                ->whereKeyNot($service->id)
                ->take(4)
                ->get(),
        ]);
    }

    /**
     * Shared filter pipeline for the list view.
     */
    private function filtered(Request $request)
    {
        return Service::query()
            ->active()
            ->with('variants')
            ->search($request->input('search'))
            ->category($request->input('category'))
            // Filtered on base_price, not price: price is the advertised
            // string, so `price >= 1000` on it is a string comparison that would
            // put "249/499" above "5,000".
            ->when($request->filled('min_price'), fn ($q) => $q->where('base_price', '>=', (float) $request->input('min_price')))
            ->when($request->filled('max_price'), fn ($q) => $q->where('base_price', '<=', (float) $request->input('max_price')))
            ->orderBy('is_featured', 'desc')
            ->orderBy('name');
    }
}
