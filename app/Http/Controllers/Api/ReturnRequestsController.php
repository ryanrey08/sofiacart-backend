<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReturnRequestResource;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReturnRequestsController extends Controller
{
    use InteractsWithMerchantScope;

    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOperator($request);
        $query = $this->scopeMerchant(ReturnRequest::query()->with('items'), $request);
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->integer('order_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return ReturnRequestResource::collection($query->latest()->paginate($this->pageSize($request)));
    }

    public function show(Request $request, int $returnRequest)
    {
        $this->authorizeOperator($request);
        return ReturnRequestResource::make($this->scopeMerchant(ReturnRequest::query()->with('items'), $request)->findOrFail($returnRequest));
    }

    public function store(Request $request)
    {
        $this->authorizeOperator($request, 'orders.manage');
        $data = $request->validate([
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['required', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'evidence' => ['sometimes', 'array', 'max:5'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $merchantId = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $paths = [];

        try {
            $return = DB::transaction(function () use ($data, $merchantId, $request, &$paths): ReturnRequest {
                $order = Order::where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($data['order_id']);
                if ((int) $order->customer_id !== (int) $data['customer_id']) {
                    throw ValidationException::withMessages(['customer_id' => ['The customer does not own this order.']]);
                }
                if ($order->status !== OrderStatus::Completed
                    || ! in_array($order->payment_status, [OrderPaymentStatus::Paid, OrderPaymentStatus::PartiallyRefunded], true)
                    || ! $order->ordered_at
                    || $order->ordered_at->lt(now()->subDays(max(0, config('returns.window_days', 30))))) {
                    throw ValidationException::withMessages(['order_id' => ['The order is not eligible for a return.']]);
                }

                $orderItems = $order->items()->get()->keyBy('id');
                $reserved = DB::table('return_request_items')
                    ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
                    ->where('return_requests.order_id', $order->id)
                    ->where('return_requests.status', '!=', 'rejected')
                    ->select('order_item_id', DB::raw('SUM(quantity) as quantity'))
                    ->groupBy('order_item_id')
                    ->pluck('quantity', 'order_item_id');
                $items = [];
                foreach ($data['items'] as $index => $item) {
                    $orderedItem = $orderItems->get($item['order_item_id']);
                    if (! $orderedItem || $item['quantity'] + (int) $reserved->get($item['order_item_id'], 0) > $orderedItem->quantity) {
                        throw ValidationException::withMessages(["items.{$index}.quantity" => ['The requested quantity exceeds the remaining returnable quantity.']]);
                    }
                    $items[] = [
                        'order_item_id' => $orderedItem->id,
                        'quantity' => $item['quantity'],
                        'amount' => round((float) $orderedItem->total_price * $item['quantity'] / $orderedItem->quantity, 2),
                    ];
                }

                $evidence = [];
                foreach ($request->file('evidence', []) as $file) {
                    $path = $file->store('return-evidence', 'local');
                    if ($path === false) {
                        throw ValidationException::withMessages(['evidence' => ['Unable to store evidence.']]);
                    }
                    $paths[] = $path;
                    $evidence[] = ['path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()];
                }
                $return = ReturnRequest::create([
                    'merchant_id' => $merchantId,
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'status' => 'pending',
                    'reason' => $data['reason'],
                    'notes' => $data['notes'],
                    'amount' => round(collect($items)->sum('amount'), 2),
                    'evidence' => $evidence,
                ]);
                $return->items()->createMany($items);

                return $return;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($paths);
            throw $exception;
        }

        return ReturnRequestResource::make($return->load('items'));
    }

    public function review(Request $request, int $returnRequest)
    {
        $this->authorizeOperator($request, 'payments.refund');
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'processed'])],
            'refund_id' => ['nullable', 'prohibited_unless:status,processed', 'integer'],
        ]);
        $return = DB::transaction(function () use ($request, $returnRequest, $data): ReturnRequest {
            $candidate = $this->scopeMerchant(ReturnRequest::query(), $request)->findOrFail($returnRequest);
            $order = Order::whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $return = $this->scopeMerchant(ReturnRequest::query(), $request)->lockForUpdate()->findOrFail($returnRequest);
            if ($return->status === $data['status']) {
                return $return;
            }
            if (($return->status === 'pending' && $data['status'] === 'processed')
                || ! in_array($return->status, ['pending', 'approved'], true)
                || ($return->status === 'approved' && $data['status'] !== 'processed')) {
                throw ValidationException::withMessages(['status' => ['Invalid return status transition.']]);
            }

            if ($data['status'] === 'processed') {
                if ((float) $return->amount > 0) {
                    if (empty($data['refund_id'])) {
                        throw ValidationException::withMessages(['refund_id' => ['A processed refund is required.']]);
                    }
                    $refund = Refund::where('merchant_id', $return->merchant_id)
                        ->where('order_id', $order->id)->where('status', RefundStatus::Processed)
                        ->lockForUpdate()->findOrFail($data['refund_id']);
                    if ((int) round((float) $refund->amount * 100) !== (int) round((float) $return->amount * 100)
                        || ReturnRequest::where('refund_id', $refund->id)->exists()
                        || $refund->payment?->order_id !== $order->id
                        || ! in_array($refund->payment?->status, [
                            PaymentStatus::Completed, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded,
                        ], true)) {
                        throw ValidationException::withMessages(['refund_id' => ['A unique processed refund for this order and amount is required.']]);
                    }
                    $return->refund_id = $refund->id;
                } elseif (! empty($data['refund_id'])) {
                    throw ValidationException::withMessages(['refund_id' => ['No refund is needed for a zero-value return.']]);
                }

                $this->inventoryService->restoreStockForReturn($return, $order, $request->user()?->id);
            }
            $return->status = $data['status'];
            $return->save();
            return $return;
        });

        return ReturnRequestResource::make($return->load('items'));
    }

    public function evidence(Request $request, int $returnRequest, int $index)
    {
        $this->authorizeOperator($request);
        $return = $this->scopeMerchant(ReturnRequest::query(), $request)->findOrFail($returnRequest);
        $file = ($return->evidence ?? [])[$index] ?? null;
        abort_unless($file, 404);

        return Storage::disk('local')->download($file['path'], basename($file['name']));
    }

    private function authorizeOperator(Request $request, string $permission = 'orders.view'): void
    {
        if ($this->isAdmin($request)) {
            abort_unless($request->user()->isActiveAdmin()
                && $request->user()->currentAccessToken()?->can('admin')
                && $request->user()->hasAdminPermission($permission), 403);
        } else {
            $this->requiredMerchantId($request);
        }
    }
}
