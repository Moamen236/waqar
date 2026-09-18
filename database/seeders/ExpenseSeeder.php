<?php

namespace Database\Seeders;

use App\Enums\TreasuryTransactionType;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Treasury;
use App\Services\Treasury\TreasuryService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Expense categories plus a few posted expenses (Section 11) — each one
 * also produces the matching treasury_transactions row the migration's
 * own comment describes, via TreasuryService (the only place a balance
 * ever changes), since no ExpenseController/Action exists yet to do that
 * itself.
 */
class ExpenseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $treasury = Treasury::where('name', 'Main Cash Register')->first();
        $accountant = Employee::where('email', 'accounting@waqar.test')->first();

        if ($treasury === null || $accountant === null) {
            return;
        }

        $categories = collect([
            'Delivery' => ['en' => 'Delivery', 'ar' => 'توصيل'],
            'Packaging' => ['en' => 'Packaging', 'ar' => 'تغليف'],
            'Warehouse' => ['en' => 'Warehouse', 'ar' => 'مخزن'],
            'Utilities' => ['en' => 'Utilities', 'ar' => 'مرافق'],
        ])->map(fn (array $name, string $key) => ExpenseCategory::firstOrCreate(
            ['name->en' => $name['en']],
            ['name' => $name, 'is_active' => true],
        ));

        $treasuryService = app(TreasuryService::class);

        foreach ([
            ['Packaging', 1250.00, now()->subDays(10), 'Boxes and mailers restock'],
            ['Utilities', 800.00, now()->subDays(5), 'Warehouse electricity bill'],
            ['Warehouse', 450.00, now()->subDays(2), 'Shelving repairs'],
        ] as [$categoryKey, $amount, $date, $description]) {
            $category = $categories->get($categoryKey);
            if ($category === null) {
                continue;
            }

            $expense = Expense::create([
                'expense_category_id' => $category->id,
                'treasury_id' => $treasury->id,
                'amount' => $amount,
                'expense_date' => $date,
                'created_by' => $accountant->id,
                'description' => $description,
            ]);

            $treasuryService->recordTransaction(
                $treasury,
                TreasuryTransactionType::Expense,
                -$amount,
                $accountant,
                $expense,
                $description,
            );
        }
    }
}
