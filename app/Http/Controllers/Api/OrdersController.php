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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrdersController extends Controller
{
    use InteractsWithMerchantScope;

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
        $items = $this->normalizeItems($merchantId, $data['items']);

        $order = DB::transaction(function () use ($data, $items, $merchantId): Order {
            $order = Order::create([
                'merchant_id' => $merchantId,
                'customer_id' => $data['customer_id'],
                'order_number' => 'ORD-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'status' => $data['status'] ?? OrderStatus::Pending,
                'payment_status' => $data['payment_status'] ?? OrderPaymentStatus::Unpaid,
                'notes' => $data['notes'] ?? null,
                'ordered_at' => $data['ordered_at'] ?? now(),
                'total_amount' => collect($items)->sum('total_price'),
            ]);

            $order->items()->createMany($items);

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

        DB::transaction(function () use ($data, $merchantId, $model): void {
            if (isset($data['items'])) {
                $items = $this->normalizeItems($merchantId, $data['items']);
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

        $model->update(['status' => $status]);

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

    protected function normalizeItems(int $merchantId, array $items): array
    {
        return collect($items)->map(function (array $item) use ($merchantId): array {
            $product = isset($item['product_id'])
                ? Product::where('merchant_id', $merchantId)->findOrFail($item['product_id'])
                : null;

            return [
                'product_id' => $product?->id,
                'product_name' => $item['product_name'] ?? $product?->name,
                'sku' => $item['sku'] ?? $product?->sku,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['quantity'] * $item['unit_price'],
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
