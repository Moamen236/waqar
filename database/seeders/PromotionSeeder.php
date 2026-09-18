<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * One of each promotion type the schema supports (Section 24, Question
 * 17) — a bundle and a buy_x_get_y. Runs after ProductSeeder since both
 * reference real variants/categories rather than placeholder ids. The
 * pricing engine that actually applies these at checkout is Phase 9
 * marketing work — this seeder only establishes the data shape admin
 * screens and future engine code can be built against.
 */
class PromotionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $tshirt = Product::where('sku', 'CTS-013')->first();
        $cardigan = Product::where('sku', 'RKC-011')->first();
        $tshirtsCategory = Category::where('slug', 't-shirts')->first();

        if ($tshirt === null || $cardigan === null || $tshirtsCategory === null) {
            return;
        }

        $bundle = Promotion::firstOrCreate(
            ['name->en' => 'Tee & Cardigan Bundle'],
            [
                'name' => ['en' => 'Tee & Cardigan Bundle', 'ar' => 'باقة تي شيرت وكارديجان'],
                'description' => [
                    'en' => 'Buy the Classic T-Shirt with the Ribbed Knit Cardigan and save 15%.',
                    'ar' => 'اشترِ التي شيرت الكلاسيكي مع الكارديجان ووفر 15%.',
                ],
                'type' => 'bundle',
                'discount_type' => 'percentage',
                'discount_value' => 15,
                'starts_at' => now()->subWeek(),
                'ends_at' => now()->addMonths(2),
                'priority' => 10,
                'stackable_with_coupons' => false,
                'usage_limit' => null,
                'usage_limit_per_customer' => 1,
                'is_active' => true,
            ],
        );

        foreach ([$tshirt, $cardigan] as $product) {
            $variant = $product->variants()->first();
            if ($variant !== null) {
                $bundle->items()->firstOrCreate(['product_variant_id' => $variant->id], ['quantity' => 1]);
            }
        }

        $bxgy = Promotion::firstOrCreate(
            ['name->en' => 'Buy 2 T-Shirts, Get 1 Free'],
            [
                'name' => ['en' => 'Buy 2 T-Shirts, Get 1 Free', 'ar' => 'اشترِ 2 تي شيرت واحصل على 1 مجانًا'],
                'description' => [
                    'en' => 'Any two t-shirts, the third one on us.',
                    'ar' => 'أي تي شيرتين، والثالث علينا.',
                ],
                'type' => 'buy_x_get_y',
                'discount_type' => 'free',
                'discount_value' => null,
                'starts_at' => now()->subWeek(),
                'ends_at' => now()->addMonths(2),
                'priority' => 5,
                'stackable_with_coupons' => false,
                'usage_limit' => null,
                'usage_limit_per_customer' => null,
                'is_active' => true,
            ],
        );

        $bxgy->items()->firstOrCreate(['category_id' => $tshirtsCategory->id], ['quantity' => 2]);
        $bxgy->rewards()->firstOrCreate(['category_id' => $tshirtsCategory->id], ['quantity' => 1]);
    }
}
