<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRestaurantTableRequest;
use App\Http\Requests\UpdateRestaurantTableRequest;
use App\Http\Resources\RestaurantTableResource;
use App\Models\RestaurantTable;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RestaurantTableController extends Controller
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
     * Display a listing of restaurant tables.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('view_tables');

        $tenantId = $request->route('tenant');
        $params   = $request->only(['search', 'restaurant_space_id', 'status', 'page']);

        // Search by table number
        $tvTables = Cache::get("t:{$tenantId}:tables:ver", 0);
        $tvSpaces = Cache::get("t:{$tenantId}:spaces:ver", 0);
        $cacheKey = "t:{$tenantId}:tables:v{$tvTables}s{$tvSpaces}:" . md5(serialize($params));

        $data = Cache::remember($cacheKey, 86400, function () use ($request) {
            $query = RestaurantTable::select(['id', 'restaurant_space_id', 'table_number', 'capacity', 'status', 'created_at', 'updated_at'])
                ->with(['space' => fn ($q) => $q->select(['id', 'name', 'is_active', 'created_at', 'updated_at'])->withCount('tables')]);

            if ($request->filled('search')) {
                $query->where('table_number', 'like', '%' . $request->input('search') . '%');
            }
            if ($request->filled('restaurant_space_id')) {
                $query->where('restaurant_space_id', $request->input('restaurant_space_id'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            return RestaurantTableResource::collection($query->paginate(15))->response()->getData(true);
        });

        return $this->success(
            $data, 
            'Restaurant tables retrieved successfully'
        );
    }

    /**
     * Store a newly created restaurant table.
     */
    public function store(StoreRestaurantTableRequest $request): JsonResponse
    {
        $this->authorizePermission('manage_tables');

        $table = RestaurantTable::create($request->validated());
        $tenantId = $request->route('tenant');
        Cache::increment("t:{$tenantId}:tables:ver");
        Cache::increment("t:{$tenantId}:spaces:ver");

        return $this->success(
            new RestaurantTableResource($table->load(['space' => fn ($q) => $q->withCount('tables')])),
            'Restaurant table created successfully',
            201
        );
    }

    /**
     * Display the specified restaurant table.
     */
    public function show($tenant, $id): JsonResponse
    {
        $this->authorizePermission('view_tables');

        $cacheKey = "t:{$tenant}:table:show:{$id}";
        $data = Cache::remember($cacheKey, 86400, function () use ($tenant, $id) {
            $table = RestaurantTable::select(['id', 'restaurant_space_id', 'table_number', 'capacity', 'status', 'created_at', 'updated_at'])
                ->with(['space' => fn ($q) => $q->select(['id', 'name', 'is_active', 'created_at', 'updated_at'])->withCount('tables')])
                ->findOrFail($id);
            return new RestaurantTableResource($table);
        });

        return $this->success(
            $data,
            'Restaurant table retrieved successfully'
        );
    }

    /**
     * Update the specified restaurant table in storage.
     */
    public function update(UpdateRestaurantTableRequest $request, $tenant, $id): JsonResponse
    {
        $this->authorizePermission('manage_tables');

        $table = RestaurantTable::findOrFail($id);
        $table->update($request->validated());
        Cache::increment("t:{$tenant}:tables:ver");
        Cache::increment("t:{$tenant}:spaces:ver"); 
        Cache::forget("t:{$tenant}:table:show:{$id}");

        return $this->success(
            new RestaurantTableResource($table->load(['space' => fn ($q) => $q->withCount('tables')])),
            'Restaurant table updated successfully'
        );
    }

    /**
     * Remove the specified restaurant table from storage.
     */
    public function destroy($tenant, $id): JsonResponse
    {
        $this->authorizePermission('manage_tables');

        $table = RestaurantTable::findOrFail($id);
        $table->delete();
        Cache::increment("t:{$tenant}:tables:ver");
        Cache::increment("t:{$tenant}:spaces:ver");
        Cache::forget("t:{$tenant}:table:show:{$id}");

        return $this->success(
            null,
            'Restaurant table deleted successfully'
        );
    }
}
