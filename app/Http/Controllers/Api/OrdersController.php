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
        $this->authorizeOperator($request);
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
        $this->authorizeOperator($request, true);
        $data = $request->validated();
        $merchantId = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureCustomerBelongsToMerchant($merchantId, $data['customer_id']);

        $order = DB::transaction(function () use ($data, $merchantId, $request): Order {
            $lockedProducts = $this->inventoryService->lockAndValidateProductsForOrder($merchantId, $data['items']);
            $items = $this->normalizeItems($merchantId, $data['items'], $lockedProducts);

            $order = Order::create([
                'merchant_id' => $merchantId,
                'customer_id' => $data['customer_id'],
                'order_number' => 'ORD-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'status' => OrderStatus::Pending,
                'payment_status' => OrderPaymentStatus::Unpaid,
                'notes' => $data['notes'] ?? null,
                'ordered_at' => now(),
                'total_amount' => collect($items)->sum('total_price'),
                'subtotal' => collect($items)->sum('total_price'),
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'shipping_address' => Customer::where('merchant_id', $merchantId)->findOrFail($data['customer_id'])->address,
                'inventory_restored' => false,
            ]);

            $order->items()->createMany($items);

            $this->inventoryService->deductStockForOrder($order, $items, $lockedProducts, $request->user()?->id);

            return $order;
        });

        return OrderResource::make($order->load(['customer', 'items']));
    }

    public function show(Request $request, int $order): OrderResource
    {
        $this->authorizeOperator($request);
        return OrderResource::make($this->scopeMerchant(Order::query()->with(['customer', 'items']), $request)->findOrFail($order));
    }

    public function update(UpdateOrderRequest $request, int $order): OrderResource
    {
        $this->authorizeOperator($request, true);
        $data = $request->validated();
        $model = DB::transaction(function () use ($data, $order, $request): Order {
            $model = $this->scopeMerchant(Order::query(), $request)->lockForUpdate()->findOrFail($order);
            if (isset($data['status'])) {
                $newStatus = OrderStatus::from($data['status']);
                $this->ensureValidStatusTransition($model->status, $newStatus, $model->payment_status);
                if ($newStatus === OrderStatus::Cancelled && ! $model->inventory_restored) {
                    $this->inventoryService->restoreStockForOrder($model, 'Order cancelled: ' . $model->order_number, $request->user()?->id);
                }
            }

            $model->update($data);
            return $model;
        });

        return OrderResource::make($model->fresh()->load(['customer', 'items']));
    }

    public function updateStatus(UpdateOrderStatusRequest $request, int $order): OrderResource
    {
        $this->authorizeOperator($request, true);
        $status = OrderStatus::from($request->validated('status'));

        $model = DB::transaction(function () use ($order, $status, $request): Order {
            $model = $this->scopeMerchant(Order::query(), $request)->lockForUpdate()->findOrFail($order);
            $this->ensureValidStatusTransition($model->status, $status, $model->payment_status);
            if ($status === OrderStatus::Cancelled && ! $model->inventory_restored) {
                $this->inventoryService->restoreStockForOrder($model, 'Order cancelled: ' . $model->order_number, $request->user()?->id);
            }

            $model->update(['status' => $status]);
            return $model;
        });

        return OrderResource::make($model->fresh()->load(['customer', 'items']));
    }

    public function destroy(Request $request, int $order)
    {
        $this->authorizeOperator($request, true);
        $this->scopeMerchant(Order::query(), $request)->findOrFail($order);
        abort(409, 'Orders cannot be deleted after inventory has been reserved.');
    }

    protected function ensureCustomerBelongsToMerchant(int $merchantId, int $customerId): void
    {
        Customer::where('merchant_id', $merchantId)->findOrFail($customerId);
    }

    private function authorizeOperator(Request $request, bool $write = false): void
    {
        if ($this->isAdmin($request)) {
            abort_unless($request->user()->isActiveAdmin()
                && $request->user()->currentAccessToken()?->can('admin')
                && $request->user()->hasAdminPermission($write ? 'orders.manage' : 'orders.view'), 403);
        } else {
            $this->requiredMerchantId($request);
        }
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
            $variant = isset($item['product_variant_id'])
                ? $product?->getRelation('lockedVariants')->get((int) $item['product_variant_id'])
                : null;
            $unitPrice = (float) ($variant?->price ?? $product?->price ?? $item['unit_price'] ?? 0);

            return [
                'product_id' => $product?->id,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'product_name' => $product?->name ?? $item['product_name'] ?? 'Custom Item',
                'sku' => $variant?->sku ?? $product?->sku ?? $item['sku'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $quantity * $unitPrice,
            ];
        })->all();
    }

    protected function ensureValidStatusTransition(OrderStatus $currentStatus, OrderStatus $newStatus, OrderPaymentStatus $paymentStatus): void
    {
        if ($currentStatus === $newStatus) {
            return;
        }
        if (($newStatus === OrderStatus::Completed && $paymentStatus !== OrderPaymentStatus::Paid)
            || ($newStatus === OrderStatus::Cancelled && ! in_array($paymentStatus, [OrderPaymentStatus::Unpaid, OrderPaymentStatus::Refunded], true))) {
            throw ValidationException::withMessages(['status' => ['Payment must be settled before fulfillment, or unpaid/fully refunded before cancellation.']]);
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
