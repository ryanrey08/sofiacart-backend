<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrdersController extends Controller
{
    use InteractsWithMerchantScope;

    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function index(Request $request)
    {
        $query = Order::query()->with(['customer', 'items']);
        $this->scopeMerchant($query, $request);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"));
            });
        }

        foreach (['status', 'payment_status', 'customer_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('ordered_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('ordered_at', '<=', $request->date('date_to'));
        }

        return OrderResource::collection($query->latest('ordered_at')->paginate($this->pageSize($request)));
    }

    public function store(StoreOrderRequest $request): OrderResource
    {
        $data = $request->validated();
        $merchantId = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureCustomerBelongsToMerchant($merchantId, $data['customer_id']);

        $order = DB::transaction(function () use ($data, $merchantId, $request): Order {
            $lockedProducts = $this->inventoryService->lockAndValidateProductsForOrder($merchantId, $data['items']);
            $items = $this->normalizeItems($merchantId, $data['items'], $lockedProducts);

            $isCancelled = ($data['status'] ?? null) === OrderStatus::Cancelled->value
                || ($data['status'] ?? null) === OrderStatus::Cancelled;

            $order = Order::create([
                'merchant_id' => $merchantId,
                'customer_id' => $data['customer_id'],
                'order_number' => 'ORD-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'status' => $data['status'] ?? OrderStatus::Pending,
                'payment_status' => $data['payment_status'] ?? OrderPaymentStatus::Unpaid,
                'notes' => $data['notes'] ?? null,
                'ordered_at' => $data['ordered_at'] ?? now(),
                'total_amount' => collect($items)->sum('total_price'),
                'inventory_restored' => $isCancelled,
            ]);

            $order->items()->createMany($items);

            if (! $isCancelled) {
                $this->inventoryService->deductStockForOrder($order, $items, $lockedProducts, $request->user()?->id);
            }

            return $order;
        });

        return OrderResource::make($order->load(['customer', 'items']));
    }

    public function show(Request $request, int $order): OrderResource
    {
        return OrderResource::make($this->scopeMerchant(Order::query()->with(['customer', 'items']), $request)->findOrFail($order));
    }

    public function update(UpdateOrderRequest $request, int $order): OrderResource
    {
        $model = $this->scopeMerchant(Order::query()->with(['customer', 'items']), $request)->findOrFail($order);
        $data = $request->validated();
        $merchantId = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);

        if (isset($data['customer_id'])) {
            $this->ensureCustomerBelongsToMerchant($merchantId, $data['customer_id']);
        }

        if (isset($data['status'])) {
            $newStatus = OrderStatus::from($data['status']);
            $this->ensureValidStatusTransition($model->status, $newStatus);
        }

        DB::transaction(function () use ($data, $merchantId, $model, $request): void {
            if (isset($data['status'])) {
                $newStatus = OrderStatus::from($data['status']);
                if ($newStatus === OrderStatus::Cancelled && ! $model->inventory_restored) {
                    $this->inventoryService->restoreStockForOrder($model, 'Order cancelled: ' . $model->order_number, $request->user()?->id);
                }
            }

            if (isset($data['items'])) {
                $lockedProducts = $this->inventoryService->lockAndValidateProductsForOrder($merchantId, $data['items']);
                $items = $this->normalizeItems($merchantId, $data['items'], $lockedProducts);
                $model->items()->delete();
                $model->items()->createMany($items);
                $data['total_amount'] = collect($items)->sum('total_price');
            }

            $model->update($data);
        });

        return OrderResource::make($model->fresh()->load(['customer', 'items']));
    }

    public function updateStatus(UpdateOrderStatusRequest $request, int $order): OrderResource
    {
        $model = $this->scopeMerchant(Order::query()->with(['customer', 'items']), $request)->findOrFail($order);
        $status = OrderStatus::from($request->validated('status'));

        $this->ensureValidStatusTransition($model->status, $status);

        DB::transaction(function () use ($model, $status, $request): void {
            if ($status === OrderStatus::Cancelled && ! $model->inventory_restored) {
                $this->inventoryService->restoreStockForOrder($model, 'Order cancelled: ' . $model->order_number, $request->user()?->id);
            }

            $model->update(['status' => $status]);
        });

        return OrderResource::make($model->fresh()->load(['customer', 'items']));
    }

    public function destroy(Request $request, int $order)
    {
        $this->scopeMerchant(Order::query(), $request)->findOrFail($order)->delete();

        return response()->json(status: 204);
    }

    protected function ensureCustomerBelongsToMerchant(int $merchantId, int $customerId): void
    {
        Customer::where('merchant_id', $merchantId)->findOrFail($customerId);
    }

    protected function normalizeItems(int $merchantId, array $items, ?Collection $products = null): array
    {
        return collect($items)->map(function (array $item) use ($merchantId, $products): array {
            $product = null;
            if (isset($item['product_id'])) {
                $product = $products?->get((int) $item['product_id'])
                    ?? Product::where('merchant_id', $merchantId)->findOrFail($item['product_id']);
            }

            $quantity = (int) $item['quantity'];
            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : (float) ($product?->price ?? 0);

            return [
                'product_id' => $product?->id,
                'product_name' => $item['product_name'] ?? $product?->name ?? 'Custom Item',
                'sku' => $item['sku'] ?? $product?->sku,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $quantity * $unitPrice,
            ];
        })->all();
    }

    protected function ensureValidStatusTransition(OrderStatus $currentStatus, OrderStatus $newStatus): void
    {
        if ($currentStatus === $newStatus) {
            return;
        }

        $allowedTransitions = [
            OrderStatus::Pending->value => [OrderStatus::Processing->value, OrderStatus::Cancelled->value],
            OrderStatus::Processing->value => [OrderStatus::Completed->value, OrderStatus::Cancelled->value],
            OrderStatus::Completed->value => [],
            OrderStatus::Cancelled->value => [],
        ];

        if (! in_array($newStatus->value, $allowedTransitions[$currentStatus->value] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ["The order cannot transition from {$currentStatus->value} to {$newStatus->value}."],
            ]);
        }
    }
}
