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
use Illuminate\Support\Facades\Cache;

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

        $tenantId = $request->route('tenant');
        $params   = $request->only(['search', 'category_id', 'is_available', 'show_deleted', 'page']);
        $version  = Cache::get("t:{$tenantId}:mi:ver", 0);
        $cacheKey = "t:{$tenantId}:mi:v{$version}:" . md5(serialize($params));

        $data = Cache::remember($cacheKey, 86400, function () use ($request) {
            $query = MenuItem::with('category')
                ->select(['id', 'category_id', 'name', 'description', 'price', 'is_available', 'created_at', 'updated_at', 'deleted_at']);

            if ($request->filled('search')) {
                $query->where('name', 'like', '%' . $request->input('search') . '%');
            }
            if ($request->filled('category_id')) {
                $query->where('category_id', $request->input('category_id'));
            }
            if ($request->has('is_available')) {
                $query->where('is_available', $request->boolean('is_available'));
            }
            if ($request->boolean('show_deleted')) {
                $query->withTrashed();
            }

            return MenuItemResource::collection($query->paginate(15))->response()->getData(true);
        });

        return $this->success(
            $data, 
            'Menu items retrieved successfully'
        );
    }

    /**
     * Store a newly created menu item.
     */
    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        $this->authorizePermission('manage_menu');

        $menuItem = MenuItem::create($request->validated());
        Cache::increment("t:{$request->route('tenant')}:mi:ver");

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

        $cacheKey = "t:{$tenant}:menu_item:show:{$id}";
        $data = Cache::remember($cacheKey, 86400, function () use ($tenant, $id) {
            $menuItem = MenuItem::with('category')
                ->select(['id', 'category_id', 'name', 'description', 'price', 'is_available', 'created_at', 'updated_at', 'deleted_at'])
                ->findOrFail($id);
            return new MenuItemResource($menuItem);
        });
        return $this->success(
            $data,
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
        Cache::increment("t:{$tenant}:mi:ver");
        Cache::forget("t:{$tenant}:menu_item:show:{$id}");

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
        Cache::increment("t:{$tenant}:mi:ver");
        Cache::forget("t:{$tenant}:menu_item:show:{$id}");

        return $this->success(
            null,
            'Menu item deleted successfully'
        );
    }
}
