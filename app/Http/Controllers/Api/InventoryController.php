<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustInventoryRequest;
use App\Http\Resources\InventoryLogResource;
use App\Http\Resources\ProductResource;
use App\Models\InventoryLog;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = InventoryLog::query()->with('product');
        $this->scopeMerchant($query, $request);

        if ($productId = $request->integer('product_id')) {
            $query->where('product_id', $productId);
        }

        if ($reason = $request->string('reason')->toString()) {
            $query->where('reason', 'like', "%{$reason}%");
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        return InventoryLogResource::collection($query->latest('created_at')->paginate($this->pageSize($request)));
    }

    public function adjust(AdjustInventoryRequest $request): JsonResponse
    {
        $merchantId = $this->requiredMerchantId($request);
        $user = $this->userOrFail($request);

        [$product, $log] = DB::transaction(function () use ($request, $merchantId, $user) {
            $product = Product::where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($request->integer('product_id'));
            $newStock = $product->stock_quantity + $request->integer('quantity_change');

            if ($newStock < 0) {
                throw ValidationException::withMessages([
                    'quantity_change' => ['The resulting stock cannot be negative.'],
                ]);
            }

            $product->update(['stock_quantity' => $newStock]);

            $log = InventoryLog::create([
                'merchant_id' => $merchantId,
                'product_id' => $product->id,
                'user_id' => $user->id,
                'reason' => $request->string('reason')->toString(),
                'quantity_change' => $request->integer('quantity_change'),
                'resulting_stock' => $newStock,
                'notes' => $request->input('notes'),
                'created_at' => now(),
            ]);

            return [$product->fresh()->load('category'), $log->load('product')];
        });

        return response()->json([
            'message' => 'Inventory adjusted successfully.',
            'product' => ProductResource::make($product),
            'inventory_log' => InventoryLogResource::make($log),
        ]);
    }
}
