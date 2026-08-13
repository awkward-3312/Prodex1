<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyInquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealEstateController extends Controller
{
    /**
     * Ensure the authenticated user owns the given permission (via any of its roles).
     */
    private function checkPermission(Request $request, string $permissionName): void
    {
        $user = $request->user('api');
        $permission = Permission::where('name', $permissionName)->first();

        if (! $user || ! $permission || ! $user->hasRole($permission->roles)) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /**
     * Store an uploaded file under public/images/properties and return its
     * public-relative path. Stocky serves uploads from public/images directly.
     */
    private function storeUpload($file): string
    {
        $destination = public_path('images/properties');
        if (! is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        $filename = Str::random(24).'.'.$file->getClientOriginalExtension();
        $file->move($destination, $filename);

        return 'images/properties/'.$filename;
    }

    /**
     * Build a unique slug for the given title within the properties table.
     */
    private function uniquePropertySlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: Str::random(8);
        $slug = $base;
        $i = 1;
        while (Property::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    // ======================== DASHBOARD ========================

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Property::class);

        $stats = [
            'total_properties'     => Property::count(),
            'available_properties' => Property::where('status', 'available')->count(),
            'sold_properties'      => Property::where('status', 'sold')->count(),
            'rented_properties'    => Property::where('status', 'rented')->count(),
            'featured_properties'  => Property::where('featured', 1)->count(),
            'total_categories'     => PropertyCategory::count(),
            'new_inquiries'        => PropertyInquiry::where('status', 'new')->count(),
            'total_inquiries'      => PropertyInquiry::count(),
        ];

        $latest_properties = Property::with('category:id,name')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $latest_inquiries = PropertyInquiry::with('property:id,title')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $by_purpose = Property::select('purpose', DB::raw('count(*) as count'))
            ->groupBy('purpose')->pluck('count', 'purpose')->toArray();

        $by_status = Property::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->pluck('count', 'status')->toArray();

        return response()->json([
            'stats'             => $stats,
            'latest_properties' => $latest_properties,
            'latest_inquiries'  => $latest_inquiries,
            'by_purpose'        => $by_purpose,
            'by_status'         => $by_status,
        ]);
    }

    // ======================== PROPERTIES ========================

    public function properties_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Property::class);

        $perPage  = (int) $request->get('limit', 10);
        $page     = (int) $request->get('page', 1);
        $search   = trim((string) $request->get('search', ''));
        $sortBy   = $request->get('SortField', 'id');
        $sortType = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        $allowedSort = ['id', 'title', 'price', 'status', 'purpose', 'created_at'];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'id';
        }

        $query = Property::with('category:id,name')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('title', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('region', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('category'), fn ($q) => $q->where('property_category_id', (int) $request->get('category')))
            ->when($request->filled('purpose'), fn ($q) => $q->where('purpose', $request->get('purpose')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->orderBy($sortBy, $sortType === 'asc' ? 'asc' : 'desc');

        $totalRows = $query->count();
        $properties = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'totalRows'  => $totalRows,
            'properties' => $properties,
        ]);
    }

    public function properties_show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Property::class);

        $property = Property::with('category:id,name')->findOrFail($id);

        return response()->json(['property' => $property]);
    }

    public function properties_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Property::class);

        $data = $this->validateProperty($request);

        $property = new Property;
        $this->fillProperty($property, $request, $data, true);
        $property->slug = $this->uniquePropertySlug($data['title']);
        $property->created_by = $request->user('api')?->id;
        $property->save();

        return response()->json(['success' => true, 'id' => $property->id]);
    }

    public function properties_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Property::class);

        $property = Property::findOrFail($id);
        $data = $this->validateProperty($request);

        $this->fillProperty($property, $request, $data, false);
        if ($property->isDirty('title') || empty($property->slug)) {
            $property->slug = $this->uniquePropertySlug($data['title'], $property->id);
        }
        $property->save();

        return response()->json(['success' => true, 'id' => $property->id]);
    }

    public function properties_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Property::class);

        Property::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function properties_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Property::class);

        $ids = $request->input('selectedIds', []);
        Property::whereIn('id', $ids)->delete();

        return response()->json(['success' => true]);
    }

    private function validateProperty(Request $request): array
    {
        // Normalize boolean
        if ($request->has('featured')) {
            $request->merge(['featured' => (int) filter_var($request->input('featured'), FILTER_VALIDATE_BOOLEAN)]);
        }

        return $request->validate([
            'title'                => 'required|string|max:190',
            'property_category_id' => 'nullable|integer|exists:property_categories,id',
            'description'          => 'nullable|string',
            'purpose'              => 'nullable|in:sale,rent',
            'status'               => 'nullable|in:available,sold,rented',
            'featured'             => 'nullable|in:0,1',
            'price'                => 'nullable|numeric|min:0',
            'area'                 => 'nullable|numeric|min:0',
            'area_unit'            => 'nullable|string|max:12',
            'bedrooms'             => 'nullable|integer|min:0',
            'bathrooms'            => 'nullable|integer|min:0',
            'garage'               => 'nullable|integer|min:0',
            'address'              => 'nullable|string|max:255',
            'city'                 => 'nullable|string|max:120',
            'region'               => 'nullable|string|max:120',
            'latitude'             => 'nullable|numeric',
            'longitude'            => 'nullable|numeric',
            'amenities'            => 'nullable',
            'agent_name'           => 'nullable|string|max:150',
            'agent_phone'          => 'nullable|string|max:50',
            'agent_email'          => 'nullable|email|max:190',
            'agent_whatsapp'       => 'nullable|string|max:50',
            'seo_title'            => 'nullable|string|max:190',
            'seo_description'      => 'nullable|string|max:500',
            'seo_keywords'         => 'nullable|string|max:255',
            'gallery_existing'     => 'nullable',
            'featured_image_file'  => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'gallery_files.*'      => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
        ]);
    }

    /**
     * Copy validated scalar fields and process image uploads onto the model.
     */
    private function fillProperty(Property $property, Request $request, array $data, bool $isNew): void
    {
        $scalar = [
            'title', 'property_category_id', 'description', 'purpose', 'status', 'featured',
            'price', 'area', 'area_unit', 'bedrooms', 'bathrooms', 'garage',
            'address', 'city', 'region', 'latitude', 'longitude',
            'agent_name', 'agent_phone', 'agent_email', 'agent_whatsapp',
            'seo_title', 'seo_description', 'seo_keywords',
        ];
        foreach ($scalar as $key) {
            if (array_key_exists($key, $data)) {
                $property->{$key} = $data[$key];
            }
        }

        $property->purpose = $property->purpose ?: 'sale';
        $property->status = $property->status ?: 'available';
        $property->area_unit = $property->area_unit ?: 'm²';

        // Amenities: accept JSON string or array of strings
        if ($request->has('amenities')) {
            $amenities = $request->input('amenities');
            if (is_string($amenities)) {
                $decoded = json_decode($amenities, true);
                $amenities = json_last_error() === JSON_ERROR_NONE ? $decoded : array_filter(array_map('trim', explode(',', $amenities)));
            }
            $property->amenities = array_values(array_filter((array) $amenities, fn ($v) => $v !== null && $v !== ''));
        }

        // Featured image
        if ($request->hasFile('featured_image_file')) {
            $property->featured_image = $this->storeUpload($request->file('featured_image_file'));
        }

        // Gallery: kept existing paths + newly uploaded files
        $existing = $request->input('gallery_existing', $isNew ? [] : null);
        if (is_string($existing)) {
            $decoded = json_decode($existing, true);
            $existing = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        $gallery = is_array($existing) ? array_values($existing) : (array) ($property->gallery ?? []);

        if ($request->hasFile('gallery_files')) {
            foreach ($request->file('gallery_files') as $file) {
                if ($file) {
                    $gallery[] = $this->storeUpload($file);
                }
            }
        }
        $property->gallery = array_values(array_unique(array_filter($gallery)));
    }

    // ======================== CATEGORIES ========================

    public function categories_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PropertyCategory::class);

        $perPage  = (int) $request->get('limit', 10);
        $page     = (int) $request->get('page', 1);
        $search   = trim((string) $request->get('search', ''));
        $sortBy   = $request->get('SortField', 'id');
        $sortType = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sortBy, ['id', 'name', 'created_at'], true)) {
            $sortBy = 'id';
        }

        $query = PropertyCategory::withCount('properties')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy($sortBy, $sortType === 'asc' ? 'asc' : 'desc');

        $totalRows = $query->count();
        $categories = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'totalRows'  => $totalRows,
            'categories' => $categories,
        ]);
    }

    public function categories_all(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Property::class);

        return response()->json([
            'categories' => PropertyCategory::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function categories_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PropertyCategory::class);

        $data = $request->validate([
            'name'        => 'required|string|max:190',
            'description' => 'nullable|string|max:1000',
            'image_file'  => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $category = new PropertyCategory;
        $category->name = $data['name'];
        $category->slug = $this->uniqueCategorySlug($data['name']);
        $category->description = $data['description'] ?? null;
        if ($request->hasFile('image_file')) {
            $category->image = $this->storeUpload($request->file('image_file'));
        }
        $category->save();

        return response()->json(['success' => true, 'id' => $category->id]);
    }

    public function categories_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PropertyCategory::class);

        $category = PropertyCategory::findOrFail($id);
        $data = $request->validate([
            'name'        => 'required|string|max:190',
            'description' => 'nullable|string|max:1000',
            'image_file'  => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        if ($category->name !== $data['name']) {
            $category->slug = $this->uniqueCategorySlug($data['name'], $category->id);
        }
        $category->name = $data['name'];
        $category->description = $data['description'] ?? null;
        if ($request->hasFile('image_file')) {
            $category->image = $this->storeUpload($request->file('image_file'));
        }
        $category->save();

        return response()->json(['success' => true, 'id' => $category->id]);
    }

    public function categories_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PropertyCategory::class);

        $category = PropertyCategory::findOrFail($id);
        // Detach properties from this category rather than orphaning FK integrity.
        Property::where('property_category_id', $category->id)->update(['property_category_id' => null]);
        $category->delete();

        return response()->json(['success' => true]);
    }

    private function uniqueCategorySlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: Str::random(8);
        $slug = $base;
        $i = 1;
        while (PropertyCategory::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    // ======================== INQUIRIES ========================

    public function inquiries_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PropertyInquiry::class);

        $perPage  = (int) $request->get('limit', 10);
        $page     = (int) $request->get('page', 1);
        $search   = trim((string) $request->get('search', ''));
        $sortBy   = $request->get('SortField', 'id');
        $sortType = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sortBy, ['id', 'name', 'status', 'created_at'], true)) {
            $sortBy = 'id';
        }

        $query = PropertyInquiry::with('property:id,title,slug')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->orderBy($sortBy, $sortType === 'asc' ? 'asc' : 'desc');

        $totalRows = $query->count();
        $inquiries = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'inquiries' => $inquiries,
        ]);
    }

    public function inquiries_show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', PropertyInquiry::class);

        $inquiry = PropertyInquiry::with('property:id,title,slug')->findOrFail($id);

        // Auto-mark as read when opened, if still new.
        if ($inquiry->status === 'new') {
            $inquiry->status = 'read';
            $inquiry->save();
        }

        return response()->json(['inquiry' => $inquiry]);
    }

    public function inquiries_update_status(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PropertyInquiry::class);

        $data = $request->validate([
            'status' => 'required|in:new,read,responded,closed',
        ]);

        $inquiry = PropertyInquiry::findOrFail($id);
        $inquiry->status = $data['status'];
        $inquiry->save();

        return response()->json(['success' => true]);
    }

    public function inquiries_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PropertyInquiry::class);

        PropertyInquiry::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function inquiries_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PropertyInquiry::class);

        $ids = $request->input('selectedIds', []);
        PropertyInquiry::whereIn('id', $ids)->delete();

        return response()->json(['success' => true]);
    }
}
