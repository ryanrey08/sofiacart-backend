<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsMerchant(): Merchant
    {
        $merchant = Merchant::factory()->create();
        Sanctum::actingAs($merchant->user);

        return $merchant;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function orderFor(Customer $customer, array $attributes = []): Order
    {
        return Order::factory()->create([
            'merchant_id' => $customer->merchant_id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Paid,
            'total_amount' => 100,
            ...$attributes,
        ]);
    }

    public function test_customer_is_created_with_profile_fields_and_structured_address(): void
    {
        $merchant = $this->actingAsMerchant();

        $response = $this->postJson('/api/v1/customers', [
            'merchant_id' => Merchant::factory()->create()->id,
            'first_name' => ' Maria ',
            'last_name' => 'Santos',
            'email' => ' Maria.Santos@Example.com ',
            'phone' => '09171234567',
            'customer_type' => 'vip',
            'is_active' => false,
            'birthday' => '1990-05-10',
            'gender' => 'female',
            'tin' => '123-456-789-000',
            'default_address' => [
                'line1' => '123 Rizal St.',
                'line2' => 'Unit 4B',
                'city' => 'Makati',
                'province' => 'Metro Manila',
                'postal_code' => '1200',
                'country' => 'Philippines',
            ],
            'notes' => 'Prefers morning delivery.',
            'tags' => ['Loyal', ' Bulk buyer '],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.merchant_id', $merchant->id)
            ->assertJsonPath('data.name', 'Maria Santos')
            ->assertJsonPath('data.first_name', 'Maria')
            ->assertJsonPath('data.last_name', 'Santos')
            ->assertJsonPath('data.email', 'maria.santos@example.com')
            ->assertJsonPath('data.customer_type', 'vip')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.birthday', '1990-05-10')
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.tin', '123-456-789-000')
            ->assertJsonPath('data.default_address', [
                'line1' => '123 Rizal St.',
                'line2' => 'Unit 4B',
                'city' => 'Makati',
                'province' => 'Metro Manila',
                'postal_code' => '1200',
                'country' => 'Philippines',
            ])
            ->assertJsonPath('data.address', '123 Rizal St., Unit 4B, Makati, Metro Manila, 1200, Philippines')
            ->assertJsonPath('data.tags', ['Loyal', 'Bulk buyer'])
            ->assertJsonPath('data.orders_count', 0)
            ->assertJsonPath('data.total_spent', '0.00')
            ->assertJsonPath('data.last_order_at', null)
            ->assertJsonPath('data.recent_orders', []);

        $this->assertDatabaseHas('customers', [
            'id' => $response->json('data.id'),
            'merchant_id' => $merchant->id,
            'name' => 'Maria Santos',
            'city' => 'Makati',
        ]);
    }

    public function test_legacy_payload_and_existing_rows_remain_supported(): void
    {
        $merchant = $this->actingAsMerchant();
        $legacyId = DB::table('customers')->insertGetId([
            'merchant_id' => $merchant->id,
            'name' => 'Existing Customer',
            'address' => 'Old address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->postJson('/api/v1/customers', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'address' => 'Quezon City',
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Juan Dela Cruz')
            ->assertJsonPath('data.first_name', null)
            ->assertJsonPath('data.address', 'Quezon City')
            ->assertJsonPath('data.customer_type', 'regular')
            ->assertJsonPath('data.is_active', true);
        $this->getJson("/api/v1/customers/{$legacyId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Existing Customer')
            ->assertJsonPath('data.address', 'Old address')
            ->assertJsonPath('data.customer_type', 'regular')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.tags', [])
            ->assertJsonPath('data.default_address.line1', null);
    }

    public function test_customer_validation_rules(): void
    {
        $merchant = $this->actingAsMerchant();
        Customer::factory()->create(['merchant_id' => $merchant->id, 'email' => 'taken@example.com']);

        $this->postJson('/api/v1/customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'first_name']);

        $this->postJson('/api/v1/customers', [
            'first_name' => 'Ana',
            'email' => 'TAKEN@example.com',
            'customer_type' => 'platinum',
            'gender' => 'unknown',
            'birthday' => now()->addDay()->toDateString(),
            'tin' => '<script>',
            'address' => 'Somewhere',
            'default_address' => ['city' => 'Pasig', 'planet' => 'Mars'],
            'tags' => array_fill(0, 21, 'tag'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email' => 'A customer with this email already exists in this store.',
                'customer_type', 'gender', 'birthday',
                'tin' => 'The TIN may only contain letters, numbers, spaces, and hyphens.',
                'address', 'default_address', 'tags',
            ]);

        $this->postJson('/api/v1/customers', [
            'first_name' => 'Ana',
            'default_address' => ['city' => 'Pasig'],
            'tags' => ['VIP', 'vip'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'default_address.line1' => 'Address line 1 is required when providing an address.',
                'tags.0', 'tags.1',
            ]);
    }

    public function test_email_is_unique_per_merchant_only(): void
    {
        $merchant = $this->actingAsMerchant();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id, 'email' => 'shared@example.com']);
        Customer::factory()->create(['email' => 'other-store@example.com']);

        $this->postJson('/api/v1/customers', ['name' => 'Other', 'email' => 'other-store@example.com'])
            ->assertCreated();
        $this->patchJson("/api/v1/customers/{$customer->id}", ['email' => 'shared@example.com'])
            ->assertOk();
    }

    public function test_update_recomputes_name_and_keeps_untouched_fields(): void
    {
        $merchant = $this->actingAsMerchant();
        $customer = Customer::factory()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Maria Santos',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'tin' => '111-222-333',
            'tags' => ['VIP'],
        ]);
        $legacy = Customer::factory()->create(['merchant_id' => $merchant->id, 'name' => 'Legacy Name']);

        $this->patchJson("/api/v1/customers/{$customer->id}", ['last_name' => 'Reyes', 'customer_type' => 'wholesale'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Maria Reyes')
            ->assertJsonPath('data.customer_type', 'wholesale')
            ->assertJsonPath('data.tin', '111-222-333')
            ->assertJsonPath('data.tags', ['VIP']);
        $this->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'M. Reyes'])
            ->assertOk()
            ->assertJsonPath('data.name', 'M. Reyes')
            ->assertJsonPath('data.first_name', null)
            ->assertJsonPath('data.last_name', null);
        $this->patchJson("/api/v1/customers/{$legacy->id}", ['last_name' => 'Only'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name' => 'The first name field is required when providing a last name.']);
        $this->patchJson("/api/v1/customers/{$customer->id}", ['default_address' => null, 'tags' => []])
            ->assertOk()
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.tags', []);

        $this->assertDatabaseHas('customers', ['id' => $legacy->id, 'name' => 'Legacy Name', 'last_name' => null]);
    }

    public function test_list_filters_sorts_and_reports_paid_spend(): void
    {
        $merchant = $this->actingAsMerchant();
        $vip = Customer::factory()->ofType(CustomerType::Vip)->create([
            'merchant_id' => $merchant->id, 'name' => 'Ana Cruz', 'created_at' => '2026-09-05 10:00:00',
        ]);
        $wholesale = Customer::factory()->ofType(CustomerType::Wholesale)->inactive()->create([
            'merchant_id' => $merchant->id, 'name' => 'Ben Lim', 'phone' => '09998887777', 'created_at' => '2026-08-01 10:00:00',
        ]);
        $regular = Customer::factory()->create([
            'merchant_id' => $merchant->id, 'name' => 'Carla Diaz', 'created_at' => '2026-09-20 10:00:00',
        ]);
        Customer::factory()->create(['name' => 'Ana Foreign']);

        $this->orderFor($vip, ['total_amount' => 500, 'ordered_at' => '2026-09-10 08:00:00']);
        $this->orderFor($vip, ['total_amount' => 999, 'payment_status' => OrderPaymentStatus::Unpaid, 'status' => OrderStatus::Pending]);
        $this->orderFor($vip, ['total_amount' => 300, 'payment_status' => OrderPaymentStatus::Refunded]);
        $partial = $this->orderFor($regular, ['total_amount' => 800, 'payment_status' => OrderPaymentStatus::PartiallyRefunded, 'ordered_at' => '2026-09-25 08:00:00']);
        $payment = Payment::create([
            'merchant_id' => $merchant->id, 'order_id' => $partial->id, 'reference' => 'PAY-1',
            'gateway' => 'manual', 'status' => PaymentStatus::PartiallyRefunded, 'amount' => 800,
        ]);
        foreach ([[RefundStatus::Processed, 200], [RefundStatus::Pending, 100]] as $index => [$status, $amount]) {
            Refund::create([
                'merchant_id' => $merchant->id, 'payment_id' => $payment->id, 'order_id' => $partial->id,
                'reference' => "REF-{$index}", 'amount' => $amount, 'status' => $status,
            ]);
        }

        $this->getJson('/api/v1/customers?sort=spent_desc')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $regular->id)
            ->assertJsonPath('data.0.total_spent', '600.00')
            ->assertJsonPath('data.0.paid_orders_count', 1)
            ->assertJsonPath('data.1.id', $vip->id)
            ->assertJsonPath('data.1.total_spent', '500.00')
            ->assertJsonPath('data.1.orders_count', 3)
            ->assertJsonPath('data.2.id', $wholesale->id);
        $this->getJson('/api/v1/customers?search=ana')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vip->id);
        $this->getJson('/api/v1/customers?search=99988')->assertJsonPath('data.0.id', $wholesale->id);
        $this->getJson('/api/v1/customers?customer_type=wholesale&status=inactive')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wholesale->id);
        $this->getJson('/api/v1/customers?status=active&registered_from=2026-09-01&registered_to=2026-09-10')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vip->id);
        $this->getJson('/api/v1/customers?sort=name_desc&per_page=2')
            ->assertJsonPath('data.0.id', $regular->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_list_rejects_invalid_filters(): void
    {
        $this->actingAsMerchant();

        $this->getJson('/api/v1/customers?customer_type=gold&status=archived&sort=id;drop&registered_from=2026-09-10&registered_to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_type', 'status', 'sort', 'registered_to']);
        $this->getJson('/api/v1/customers?registered_from=not-a-date&registered_to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registered_from'])
            ->assertJsonMissingValidationErrors(['registered_to']);
    }

    public function test_summary_reports_metrics_for_the_requested_period(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        $merchant = $this->actingAsMerchant();
        $returning = Customer::factory()->create(['merchant_id' => $merchant->id, 'created_at' => '2026-06-01 00:00:00']);
        $newCustomer = Customer::factory()->inactive()->create(['merchant_id' => $merchant->id, 'created_at' => '2026-09-15 00:00:00']);
        $lapsed = Customer::factory()->create(['merchant_id' => $merchant->id, 'created_at' => '2026-05-01 00:00:00']);
        $this->orderFor($returning, ['ordered_at' => '2026-07-01 00:00:00']);
        $this->orderFor($returning, ['ordered_at' => '2026-09-20 00:00:00']);
        $this->orderFor($newCustomer, ['ordered_at' => '2026-09-16 00:00:00', 'status' => OrderStatus::Cancelled]);
        $this->orderFor($lapsed, ['ordered_at' => '2026-05-02 00:00:00']);
        $foreign = Customer::factory()->create(['created_at' => '2026-09-20 00:00:00']);
        $this->orderFor($foreign, ['ordered_at' => '2026-07-01 00:00:00']);
        $this->orderFor($foreign, ['ordered_at' => '2026-09-21 00:00:00']);

        $this->getJson('/api/v1/customers/summary')
            ->assertOk()
            ->assertExactJson(['data' => [
                'period' => ['from' => '2026-09-02', 'to' => '2026-10-01'],
                'total_customers' => 3,
                'active_customers' => 2,
                'inactive_customers' => 1,
                'new_customers' => 1,
                'returning_customers' => 1,
                'total_orders' => 2,
            ]]);
        $this->getJson('/api/v1/customers/summary?date_from=2026-05-01&date_to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('data.period', ['from' => '2026-05-01', 'to' => '2026-06-30'])
            ->assertJsonPath('data.new_customers', 2)
            ->assertJsonPath('data.returning_customers', 0)
            ->assertJsonPath('data.total_orders', 1);
        $this->getJson('/api/v1/customers/summary?date_from=2026-09-10&date_to=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_detail_includes_latest_recent_orders(): void
    {
        $merchant = $this->actingAsMerchant();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $orders = collect(range(1, 6))->map(fn (int $day) => $this->orderFor($customer, [
            'ordered_at' => "2026-09-0{$day} 10:00:00",
        ]));

        $response = $this->getJson("/api/v1/customers/{$customer->id}");

        $response->assertOk()
            ->assertJsonPath('data.orders_count', 6)
            ->assertJsonPath('data.total_spent', '600.00')
            ->assertJsonPath('data.last_order_at', '2026-09-06T10:00:00.000000Z')
            ->assertJsonCount(5, 'data.recent_orders')
            ->assertJsonPath('data.recent_orders.0.id', $orders->last()->id)
            ->assertJsonPath('data.recent_orders.0.order_number', $orders->last()->order_number)
            ->assertJsonMissingPath('data.recent_orders.0.customer');
        $this->assertNotContains($orders->first()->id, $response->json('data.recent_orders.*.id'));
    }

    public function test_customers_of_other_merchants_are_not_accessible(): void
    {
        $this->actingAsMerchant();
        $foreign = Customer::factory()->create(['name' => 'Foreign']);

        $this->getJson("/api/v1/customers/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/customers/{$foreign->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/v1/customers/{$foreign->id}")->assertNotFound();

        $this->assertDatabaseHas('customers', ['id' => $foreign->id, 'name' => 'Foreign']);
    }

    public function test_customer_with_orders_cannot_be_deleted(): void
    {
        $merchant = $this->actingAsMerchant();
        $withOrders = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $order = $this->orderFor($withOrders);
        $withoutOrders = Customer::factory()->create(['merchant_id' => $merchant->id]);

        $this->deleteJson("/api/v1/customers/{$withOrders->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Customers with orders cannot be deleted. Mark the customer as inactive instead.');
        $this->deleteJson("/api/v1/customers/{$withoutOrders->id}")->assertNoContent();

        $this->assertModelExists($withOrders);
        $this->assertModelExists($order);
        $this->assertModelMissing($withoutOrders);
    }
}
