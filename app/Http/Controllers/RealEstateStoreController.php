<?php

namespace App\Http\Controllers;

use App\Mail\PropertyInquiryEmail;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyInquiry;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Serves the Real Estate storefront theme. These pages are rendered when the
 * active store theme (StoreSetting->theme) is "real_estate". They are fully
 * isolated from the default eCommerce storefront.
 */
class RealEstateStoreController extends Controller
{
    /**
     * Real estate homepage: hero search, featured + latest properties,
     * categories and featured locations.
     */
    public function home(Request $request)
    {
        $s = StoreSetting::firstOrFail();

        $categories = PropertyCategory::withCount('properties')
            ->orderBy('name')
            ->get();

        $featured = Property::with('category:id,name')
            ->where('featured', 1)
            ->where('status', 'available')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        $latest = Property::with('category:id,name')
            ->where('status', 'available')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        // Featured locations: top cities by available-property count.
        $locations = Property::query()
            ->selectRaw('city, COUNT(*) as total')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->groupBy('city')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        return view('store.realestate.home', [
            's'          => $s,
            'categories' => $categories,
            'featured'   => $featured,
            'latest'     => $latest,
            'locations'  => $locations,
        ]);
    }

    /**
     * Property listings with search/filters/sorting, grid or list view.
     */
    public function listings(Request $request)
    {
        $s = StoreSetting::firstOrFail();

        $q        = trim((string) $request->get('q', ''));
        $category = $request->get('category');           // id or slug
        $purpose  = $request->get('purpose');            // sale|rent
        $location = trim((string) $request->get('location', ''));
        $minPrice = $request->get('min_price');
        $maxPrice = $request->get('max_price');
        $bedrooms = $request->get('bedrooms');
        $bathrooms = $request->get('bathrooms');
        $minArea  = $request->get('min_area');
        $sort     = $request->get('sort', 'latest');     // latest|price_asc|price_desc
        $view     = $request->get('view', 'grid') === 'list' ? 'list' : 'grid';

        $query = Property::with('category:id,name')
            ->when($q !== '', fn ($w) => $w->where('title', 'like', "%{$q}%"))
            ->when($category, function ($w) use ($category) {
                if (is_numeric($category)) {
                    $w->where('property_category_id', (int) $category);
                } else {
                    $w->whereHas('category', fn ($c) => $c->where('slug', $category));
                }
            })
            ->when(in_array($purpose, ['sale', 'rent'], true), fn ($w) => $w->where('purpose', $purpose))
            ->when($location !== '', function ($w) use ($location) {
                $w->where(function ($x) use ($location) {
                    $x->where('city', 'like', "%{$location}%")
                        ->orWhere('region', 'like', "%{$location}%")
                        ->orWhere('address', 'like', "%{$location}%");
                });
            })
            ->when(is_numeric($minPrice), fn ($w) => $w->where('price', '>=', (float) $minPrice))
            ->when(is_numeric($maxPrice), fn ($w) => $w->where('price', '<=', (float) $maxPrice))
            ->when(is_numeric($bedrooms), fn ($w) => $w->where('bedrooms', '>=', (int) $bedrooms))
            ->when(is_numeric($bathrooms), fn ($w) => $w->where('bathrooms', '>=', (int) $bathrooms))
            ->when(is_numeric($minArea), fn ($w) => $w->where('area', '>=', (float) $minArea));

        if ($sort === 'price_asc') {
            $query->orderBy('price', 'asc');
        } elseif ($sort === 'price_desc') {
            $query->orderBy('price', 'desc');
        } else {
            $query->orderByDesc('created_at');
        }

        $properties = $query->paginate(9)->withQueryString();
        $categories = PropertyCategory::orderBy('name')->get(['id', 'name', 'slug']);

        return view('store.realestate.listings', [
            's'          => $s,
            'properties' => $properties,
            'categories' => $categories,
            'filters'    => compact('q', 'category', 'purpose', 'location', 'minPrice', 'maxPrice', 'bedrooms', 'bathrooms', 'minArea', 'sort', 'view'),
        ]);
    }

    /**
     * Property detail page.
     */
    public function show(Request $request, $slug)
    {
        $s = StoreSetting::firstOrFail();

        $property = Property::with('category:id,name')
            ->where('slug', $slug)
            ->firstOrFail();

        // Lightweight view counter (non-blocking).
        Property::where('id', $property->id)->increment('views');

        $related = Property::with('category:id,name')
            ->where('id', '!=', $property->id)
            ->where('property_category_id', $property->property_category_id)
            ->where('status', 'available')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        if ($related->count() < 3) {
            $extra = Property::with('category:id,name')
                ->where('id', '!=', $property->id)
                ->whereNotIn('id', $related->pluck('id'))
                ->where('status', 'available')
                ->orderByDesc('created_at')
                ->limit(3 - $related->count())
                ->get();
            $related = $related->concat($extra);
        }

        return view('store.realestate.show', [
            's'        => $s,
            'property' => $property,
            'related'  => $related,
        ]);
    }

    /**
     * Public inquiry submission (AJAX). Stores the inquiry and optionally
     * emails the property agent / store administrator.
     */
    public function inquiry(Request $request)
    {
        // Honeypot: silently accept bots that fill the hidden "company" field.
        if ($request->filled('company')) {
            return response()->json(['message' => __('messages.InquirySent')]);
        }

        $data = $request->validate([
            'property_id' => 'nullable|integer|exists:properties,id',
            'name'        => 'required|string|max:150',
            'phone'       => 'nullable|string|max:50',
            'email'       => 'nullable|email|max:190',
            'message'     => 'required|string|max:2000',
        ]);

        $inquiry = PropertyInquiry::create([
            'property_id' => $data['property_id'] ?? null,
            'name'        => $data['name'],
            'phone'       => $data['phone'] ?? null,
            'email'       => $data['email'] ?? null,
            'message'     => $data['message'],
            'status'      => 'new',
        ]);

        // Optional email notification to the property agent or store admin.
        $this->notifyInquiry($inquiry);

        return response()->json(['message' => __('messages.InquirySent')]);
    }

    /**
     * Send a best-effort notification email; never block the visitor response.
     */
    private function notifyInquiry(PropertyInquiry $inquiry): void
    {
        try {
            $s = StoreSetting::first();
            $property = $inquiry->property_id ? Property::find($inquiry->property_id) : null;

            $recipient = $property?->agent_email ?: ($s?->contact_email ?: null);
            if (! $recipient) {
                return;
            }

            Mail::to($recipient)->send(new PropertyInquiryEmail([
                'store_name'     => $s?->store_name ?? 'Online Store',
                'property_title' => $property?->title,
                'property_url'   => $property ? route('store.realestate.show', $property->slug) : null,
                'name'           => $inquiry->name,
                'email'          => $inquiry->email,
                'phone'          => $inquiry->phone,
                'message'        => $inquiry->message,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Property inquiry email failed: '.$e->getMessage());
        }
    }
}
