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

        $query = MenuItem::with('category');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->has('is_available')) {
            $query->where('is_available', $request->boolean('is_available'));
        }

        if ($request->boolean('show_deleted')) {
            $query->withTrashed();
        }

        $menuItems = $query->paginate(10);

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
