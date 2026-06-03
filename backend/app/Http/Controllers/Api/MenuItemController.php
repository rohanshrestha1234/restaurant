<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMenuItemRequest;
use App\Http\Requests\UpdateMenuItemRequest;
use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuItemController extends Controller
{
    use ApiResponse;

    /**
     * Helper to enforce permission checks inline.
     */
    protected function authorizePermission(string $permission): void
    {
        $user = auth()->user();
        if (!$user) {
            abort(response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401));
        }

        $permissionsMap = [
            'super_admin' => ['*'],
            'owner' => ['*'],
            'manager' => [
                'view_pos', 'manage_pos',
                'view_inventory', 'manage_inventory',
                'view_customers', 'manage_customers',
                'view_products', 'manage_products',
                'view_menu', 'manage_menu',
                'view_tables', 'manage_tables'
            ],
            'staff' => [
                'view_pos', 'manage_pos',
                'view_customers',
                'view_products',
                'view_menu',
                'view_tables'
            ],
        ];

        $userRole = $user->role ?? 'staff';
        $userPerms = $permissionsMap[$userRole] ?? [];

        if (!in_array('*', $userPerms) && !in_array($permission, $userPerms)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Forbidden: You do not have permission to execute this operation'
            ], 403));
        }
    }

    /**
     * Display a listing of menu items.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('view_menu');

        // Eager-load category upfront to prevent N+1 queries: without this,
        // Laravel would fire a separate SELECT for each item's category when
        // the resource serializes the relationship.
        $query = MenuItem::with('category');

        // filled() guards against empty-string submissions (e.g. ?search=).
        // has() would pass an empty value through and generate
        // WHERE name ILIKE '%%', scanning the entire table uselessly.
        if ($request->filled('search')) {
            // ILIKE instead of LIKE: PostgreSQL's LIKE is case-sensitive, so
            // "momo" would not match "Chicken Momo". ILIKE is case-insensitive.
            // The btree index on `name` aids prefix searches; for heavy
            // contains-style workloads on large tables a GIN/trigram index
            // (pg_trgm) would be more effective.
            $query->where('name', 'ilike', '%' . $request->input('search') . '%');
        }

        // filled() used here for the same reason as search: category_id=''
        // should be ignored, not cast to 0 and matched against uncategorised rows.
        if ($request->filled('category_id')) {
            // integer() casts the value to int before binding, preventing
            // non-numeric strings from reaching the query.
            // Filtered via the dedicated btree index (menu_items_category_id_index),
            // avoiding a full table scan.
            $query->where('category_id', $request->integer('category_id'));
        }

        // has() is intentional here instead of filled(): false and "0" are valid
        // filter values for a boolean column. filled() treats "0" as empty
        // (PHP's empty("0") === true), so is_available=false would be silently
        // ignored. has() only checks key presence, which is the correct guard.
        // The composite index (deleted_at, is_available) covers this filter
        // together with soft-delete, avoiding a full table scan.
        if ($request->has('is_available')) {
            $query->where('is_available', $request->boolean('is_available'));
        }

        if ($request->boolean('show_deleted')) {
            $query->withTrashed();
        }

        // 10 per page instead of 15: smaller payload reduces serialisation time
        // and bandwidth, which matters more than an extra 5 rows per request.
        $menuItems = $query->paginate(10);

        // Explicit data/links/meta envelope instead of ->response()->getData(true):
        // the old approach re-parsed the resource's HTTP response object just to
        // extract the array, adding overhead and coupling the shape to the
        // resource's internal response wrapper. Building the envelope manually
        // gives a stable contract and uses the paginator helpers directly.
        return $this->success([
            'data'  => MenuItemResource::collection($menuItems)->resolve(),
            'links' => [
                'first' => $menuItems->url(1),
                'last'  => $menuItems->url($menuItems->lastPage()),
                'prev'  => $menuItems->previousPageUrl(),
                'next'  => $menuItems->nextPageUrl(),
            ],
            'meta'  => [
                'current_page' => $menuItems->currentPage(),
                'from'         => $menuItems->firstItem(),
                'last_page'    => $menuItems->lastPage(),
                'path'         => $menuItems->path(),
                'per_page'     => $menuItems->perPage(),
                'to'           => $menuItems->lastItem(),
                'total'        => $menuItems->total(),
            ],
        ], 'Menu items retrieved successfully');
    }

    /**
     * Store a newly created menu item.
     */
    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        $this->authorizePermission('manage_menu');

        $menuItem = MenuItem::create($request->validated());

        return $this->success(
            new MenuItemResource($menuItem->load('category')),
            'Menu item created successfully',
            201
        );
    }

    /**
     * Display the specified menu item.
     */
    public function show($tenant, $id): JsonResponse
    {
        $this->authorizePermission('view_menu');

        $menuItem = MenuItem::with('category')->findOrFail($id);

        return $this->success(
            new MenuItemResource($menuItem),
            'Menu item retrieved successfully'
        );
    }

    /**
     * Update the specified menu item in storage.
     */
    public function update(UpdateMenuItemRequest $request, $tenant, $id): JsonResponse
    {
        $this->authorizePermission('manage_menu');

        $menuItem = MenuItem::findOrFail($id);
        $menuItem->update($request->validated());

        return $this->success(
            new MenuItemResource($menuItem->load('category')),
            'Menu item updated successfully'
        );
    }

    /**
     * Remove the specified menu item from storage.
     */
    public function destroy($tenant, $id): JsonResponse
    {
        $this->authorizePermission('manage_menu');

        $menuItem = MenuItem::findOrFail($id);
        $menuItem->delete();

        return $this->success(
            null,
            'Menu item deleted successfully'
        );
    }
}
