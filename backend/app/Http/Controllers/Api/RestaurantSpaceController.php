<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRestaurantSpaceRequest;
use App\Http\Requests\UpdateRestaurantSpaceRequest;
use App\Http\Resources\RestaurantSpaceResource;
use App\Models\RestaurantSpace;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RestaurantSpaceController extends Controller
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

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('view_tables');

        $tenantId = $request->route('tenant');
        $params   = $request->only(['search', 'is_active', 'page']);
        $version  = Cache::get("t:{$tenantId}:spaces:ver", 0);
        $cacheKey = "t:{$tenantId}:spaces:v{$version}:" . md5(serialize($params));

        $data = Cache::remember($cacheKey, 86400, function () use ($request) {
            $query = RestaurantSpace::select(['id', 'name', 'is_active', 'created_at', 'updated_at'])
                ->withCount('tables');

            if ($request->filled('search')) {
                $query->where('name', 'like', '%' . $request->input('search') . '%');
            }
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            return RestaurantSpaceResource::collection($query->paginate(15))->response()->getData(true);
        });

        return $this->success($data, 'Restaurant spaces retrieved successfully');
    }

    public function store(StoreRestaurantSpaceRequest $request): JsonResponse
    {
        $this->authorizePermission('manage_tables');

        $space = RestaurantSpace::create($request->validated());
        $space->loadCount('tables');
        Cache::increment("t:{$request->route('tenant')}:spaces:ver");

        return $this->success(
            new RestaurantSpaceResource($space),
            'Restaurant space created successfully',
            201
        );
    }

    public function show($tenant, $id): JsonResponse
    {
        $this->authorizePermission('view_tables');

        $cacheKey = "t:{$tenant}:space:show:{$id}";
        $data = Cache::remember($cacheKey, 86400, function () use ($tenant, $id) {
            $space = RestaurantSpace::select(['id', 'name', 'is_active', 'created_at', 'updated_at'])
                ->withCount('tables')
                ->findOrFail($id);
            return new RestaurantSpaceResource($space);
        });

        return $this->success(
            $data,
            'Restaurant space retrieved successfully'
        );
    }

    public function update(UpdateRestaurantSpaceRequest $request, $tenant, $id): JsonResponse
    {
        $this->authorizePermission('manage_tables');

        $space = RestaurantSpace::findOrFail($id);
        $space->update($request->validated());
        $space->loadCount('tables');
        Cache::increment("t:{$tenant}:spaces:ver");
        Cache::forget("t:{$tenant}:space:show:{$id}");

        return $this->success(
            new RestaurantSpaceResource($space),
            'Restaurant space updated successfully'
        );
    }

    public function destroy($tenant, $id): JsonResponse
    {
        $this->authorizePermission('manage_tables');

        $space = RestaurantSpace::findOrFail($id);
        $space->delete();
        Cache::increment("t:{$tenant}:spaces:ver");
        Cache::forget("t:{$tenant}:space:show:{$id}");

        return $this->success(null, 'Restaurant space deleted successfully');
    }
}
