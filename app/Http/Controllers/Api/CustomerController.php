<?php

namespace App\Http\Controllers\Api;

use App\Enums\CustomerType;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\Order;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    use InteractsWithMerchantScope;

    public const RECENT_ORDERS_LIMIT = 5;

    public const SUMMARY_DEFAULT_DAYS = 30;

    public const SORTS = ['newest', 'oldest', 'name_asc', 'name_desc', 'orders_desc', 'spent_desc', 'last_order_desc'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'customer_type' => ['nullable', Rule::enum(CustomerType::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'registered_from' => ['nullable', 'date_format:Y-m-d'],
            'registered_to' => ['nullable', 'date_format:Y-m-d', ...$this->afterOrEqualRule($request, 'registered_from')],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'merchant_id' => ['nullable', 'integer'],
        ]);

        $query = $this->customersQuery($request)->withOrderStats();

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['customer_type'])) {
            $query->where('customer_type', $filters['customer_type']);
        }

        match ($filters['status'] ?? null) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default => null,
        };

        if (! empty($filters['registered_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['registered_from'])->startOfDay());
        }

        if (! empty($filters['registered_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['registered_to'])->endOfDay());
        }

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->oldest()->orderBy('id'),
            'name_asc' => $query->orderBy('name')->orderBy('id'),
            'name_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            'orders_desc' => $query->orderByDesc('orders_count')->orderByDesc('id'),
            'spent_desc' => $query->orderByDesc('total_spent')->orderByDesc('id'),
            'last_order_desc' => $query->orderByDesc('orders_max_ordered_at')->orderByDesc('id'),
            default => $query->latest()->orderByDesc('id'),
        };

        return CustomerResource::collection($query->paginate($this->pageSize($request))->withQueryString());
    }

    public function summary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...$this->afterOrEqualRule($request, 'date_from')],
            'merchant_id' => ['nullable', 'integer'],
        ]);

        $to = isset($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : now()->endOfDay();
        $from = isset($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : $to->copy()->subDays(self::SUMMARY_DEFAULT_DAYS - 1)->startOfDay();

        if (isset($filters['date_from']) && ! isset($filters['date_to']) && $from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'date_from' => ['The date from field must be a date before or equal to today.'],
            ]);
        }

        $ordersInPeriod = fn (Builder $orders) => $orders->whereBetween('ordered_at', [$from, $to]);

        $totalCustomers = $this->customersQuery($request)->count();
        $activeCustomers = $this->customersQuery($request)->where('is_active', true)->count();
        $newCustomers = $this->customersQuery($request)->whereBetween('created_at', [$from, $to])->count();
        $returningCustomers = $this->customersQuery($request)
            ->whereHas('orders', $ordersInPeriod)
            ->whereHas('orders', fn (Builder $orders) => $orders->where('ordered_at', '<', $from))
            ->count();
        $totalOrders = Order::query()
            ->whereIn('customer_id', $this->customersQuery($request)->select('id'))
            ->where($ordersInPeriod)
            ->count();

        return response()->json([
            'data' => [
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'total_customers' => $totalCustomers,
                'active_customers' => $activeCustomers,
                'inactive_customers' => $totalCustomers - $activeCustomers,
                'new_customers' => $newCustomers,
                'returning_customers' => $returningCustomers,
                'total_orders' => $totalOrders,
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $data = $request->customerAttributes();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $request->validated('merchant_id'));

        $customer = Customer::create($data);

        return CustomerResource::make($this->loadDetails($customer))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $customer): CustomerResource
    {
        return CustomerResource::make($this->loadDetails($this->findCustomer($request, $customer)));
    }

    public function update(UpdateCustomerRequest $request, int $customer): CustomerResource
    {
        $model = $this->findCustomer($request, $customer);
        $data = $request->customerAttributes($model);
        $data['merchant_id'] = $this->merchantIdForWrite($request, $request->validated('merchant_id') ?? $model->merchant_id);

        if ($data['merchant_id'] !== $model->merchant_id && $model->orders()->exists()) {
            throw ValidationException::withMessages([
                'merchant_id' => ['Customers with orders cannot be transferred between merchants.'],
            ]);
        }

        $model->update($data);

        return CustomerResource::make($this->loadDetails($model));
    }

    public function destroy(Request $request, int $customer): JsonResponse
    {
        $model = $this->findCustomer($request, $customer);

        abort_if(
            $model->orders()->exists(),
            409,
            'Customers with orders cannot be deleted. Mark the customer as inactive instead.',
        );

        $model->delete();

        return response()->json(status: 204);
    }

    protected function customersQuery(Request $request): Builder
    {
        $query = $this->scopeMerchant(Customer::query(), $request);

        if ($this->isAdmin($request) && $request->filled('merchant_id')) {
            $query->where('merchant_id', $request->integer('merchant_id'));
        }

        return $query;
    }

    protected function findCustomer(Request $request, int $customer): Customer
    {
        return $this->scopeMerchant(Customer::query(), $request)->findOrFail($customer);
    }

    protected function loadDetails(Customer $customer): Customer
    {
        $details = Customer::query()->withOrderStats()->findOrFail($customer->id);

        return $details->setRelation(
            'recentOrders',
            $details->orders()->latest('ordered_at')->latest('id')->limit(self::RECENT_ORDERS_LIMIT)->get(),
        );
    }

    /**
     * Require the end date to be on or after the start date, comparing only once both are valid dates.
     *
     * @return list<Closure>
     */
    protected function afterOrEqualRule(Request $request, string $startField): array
    {
        return [function (string $attribute, mixed $value, Closure $fail) use ($request, $startField): void {
            $start = $request->input($startField);

            if (! is_string($start) || ! is_string($value)
                || ! Carbon::canBeCreatedFromFormat($start, 'Y-m-d') || ! Carbon::canBeCreatedFromFormat($value, 'Y-m-d')) {
                return;
            }

            if (Carbon::createFromFormat('!Y-m-d', $value)->lt(Carbon::createFromFormat('!Y-m-d', $start))) {
                $fail("The {$attribute} field must be a date after or equal to {$startField}.");
            }
        }];
    }
}
