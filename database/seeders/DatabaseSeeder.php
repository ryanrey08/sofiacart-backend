<?php

namespace Database\Seeders;

use App\Enums\MerchantStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\RefundStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Transaction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminAuthorizationSeeder::class);

        Merchant::factory()->count(3)->create()->each(function (Merchant $merchant): void {
            $merchant->update(['status' => MerchantStatus::Verified]);

            $categories = Category::factory()->count(3)->create([
                'merchant_id' => $merchant->id,
            ]);

            $products = collect();
            foreach ($categories as $category) {
                $products = $products->merge(
                    Product::factory()->count(4)->create([
                        'merchant_id' => $merchant->id,
                        'category_id' => $category->id,
                        'status' => ProductStatus::Active,
                    ])
                );
            }

            $customers = Customer::factory()->count(5)->create([
                'merchant_id' => $merchant->id,
            ]);

            foreach ($customers->take(3) as $customer) {
                $selectedProducts = $products->random(2);
                $total = $selectedProducts->sum(fn (Product $product) => $product->price * 2);

                $order = Order::create([
                    'merchant_id' => $merchant->id,
                    'customer_id' => $customer->id,
                    'order_number' => 'ORD-'.fake()->unique()->numerify('######'),
                    'status' => OrderStatus::Completed,
                    'payment_status' => OrderPaymentStatus::Paid,
                    'total_amount' => $total,
                    'notes' => fake()->sentence(),
                    'ordered_at' => fake()->dateTimeBetween('-30 days', 'now'),
                ]);

                foreach ($selectedProducts as $product) {
                    $order->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'quantity' => 2,
                        'unit_price' => $product->price,
                        'total_price' => $product->price * 2,
                    ]);
                }

                $payment = Payment::create([
                    'merchant_id' => $merchant->id,
                    'order_id' => $order->id,
                    'reference' => 'PAY-'.fake()->unique()->numerify('######'),
                    'gateway' => fake()->randomElement(['gcash', 'paymaya', 'bank_transfer']),
                    'status' => PaymentStatus::Completed,
                    'amount' => $order->total_amount,
                    'paid_at' => $order->ordered_at,
                    'metadata' => ['channel' => 'seeded'],
                ]);

                Transaction::create([
                    'merchant_id' => $merchant->id,
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'reference' => 'TXN-'.fake()->unique()->numerify('######'),
                    'type' => TransactionType::Credit,
                    'status' => TransactionStatus::Completed,
                    'amount' => $payment->amount,
                    'description' => 'Seeded payment capture',
                    'transacted_at' => $payment->paid_at,
                    'metadata' => ['source' => 'seeder'],
                ]);
            }

            $refundOrder = $merchant->orders()->first();
            if ($refundOrder && $refundOrder->payments()->exists()) {
                Refund::create([
                    'merchant_id' => $merchant->id,
                    'payment_id' => $refundOrder->payments()->first()->id,
                    'order_id' => $refundOrder->id,
                    'reference' => 'REF-'.fake()->unique()->numerify('######'),
                    'amount' => $refundOrder->total_amount / 2,
                    'reason' => 'Seeded partial refund',
                    'status' => RefundStatus::Processed,
                    'refunded_at' => now()->subDays(2),
                    'metadata' => ['source' => 'seeder'],
                ]);
            }

            foreach ($products->take(5) as $product) {
                InventoryLog::create([
                    'merchant_id' => $merchant->id,
                    'product_id' => $product->id,
                    'user_id' => $merchant->user_id,
                    'reason' => fake()->randomElement(['restock', 'manual_adjustment']),
                    'quantity_change' => fake()->numberBetween(1, 10),
                    'resulting_stock' => $product->stock_quantity,
                    'notes' => fake()->sentence(),
                    'created_at' => fake()->dateTimeBetween('-30 days', 'now'),
                ]);
            }
        });
    }
}
