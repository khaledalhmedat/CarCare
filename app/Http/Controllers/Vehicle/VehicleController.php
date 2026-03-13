<?php

namespace App\Http\Controllers\Vehicle;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Services\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function __construct(
        protected VehicleService $vehicleService
    ) {}

    //عرض جميع المركبات
    public function index(Request $request): JsonResponse
    {
        try {
            $vehicles = $this->vehicleService->getUserVehicles(
                $request->user(),
                true,
                $request->get('per_page', 15)
            );

            return response()->json([
                'success' => true,
                'data' => VehicleResource::collection($vehicles)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //اضافة مركبة
    public function store(StoreVehicleRequest $request): JsonResponse
{
    try {
        $data = $request->validated();
        
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }
        
        $vehicle = $this->vehicleService->createVehicle(
            $request->user(),
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة المركبة بنجاح',
            'data' => new VehicleResource($vehicle)
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ: ' . $e->getMessage()
        ], 500);
    }
}

    //عرض مركبة محددة
    public function show(Request $request, int $id): JsonResponse 
    {
        try {
            $vehicle = $this->vehicleService->getVehicle($id, $request->user());

            return response()->json([
                'success' => true,
                'data' => new VehicleResource($vehicle)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    //تحديث مركبة
   public function update(UpdateVehicleRequest $request, int $id): JsonResponse
{
    try {
        $data = $request->validated();
        
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }
        
        $vehicle = $this->vehicleService->updateVehicle(
            $id,
            $request->user(),
            $data
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المركبة بنجاح',
            'data' => new VehicleResource($vehicle)
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 400);
    }
}

    //حذف مركبة
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->vehicleService->deleteVehicle($id, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'تم حذف المركبة بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    //سجل استهلاك الوقود
    public function fuelLogs(Request $request, int $id): JsonResponse
    {
        try {
            $vehicle = $this->vehicleService->getVehicle($id, $request->user());

            $fuelLogs = $vehicle->fuelLogs()
                ->latest()
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $fuelLogs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    //تنبيهات الصيانة 
    public function alerts(Request $request, int $id): JsonResponse
    {
        try {
            $vehicle = $this->vehicleService->getVehicle($id, $request->user());

            $alerts = $vehicle->maintenanceAlerts()
                ->where('is_active', true)
                ->latest()
                ->get();

            return response()->json([
                'success' => true,
                'data' => $alerts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    //تاريخ الصيانة 
    public function maintenanceHistory(Request $request, int $id): JsonResponse
    {
        try {
            $history = $this->vehicleService->getMaintenanceHistory($id, $request->user());

            return response()->json([
                'success' => true,
                'data' => $history
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }
}
