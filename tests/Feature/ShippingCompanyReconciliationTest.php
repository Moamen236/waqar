<?php

use App\Models\Employee;
use App\Models\ShippingCompany;
use App\Models\ShippingCompanyStatement;
use App\Models\Treasury;
use App\Services\Treasury\ReconciliationService;

// Reconciliation after Phase C (feature-backlog-plan.md): the courier
// takes their fee out of the cash at the door, on a delivery and on a
// refusal alike, so what they hand over is already net of everything
// they are owed. A statement therefore only ever tracks the goods cash
// still travelling back to us — and a statement where WE owe THEM is an
// open obligation, not a closed one.

function statementFor(float $netExpected, ?Employee $author = null): ShippingCompanyStatement
{
    $author ??= reconciliationEmployee();

    $company = ShippingCompany::create([
        'name' => 'Courier Co', 'phone' => '01012345678', 'address' => 'Cairo',
        'delivery_fee' => 50, 'return_fee' => 25,
    ]);

    return ShippingCompanyStatement::create([
        'shipping_company_id' => $company->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'delivered_orders_count' => 1,
        'expected_customer_collection' => $netExpected,
        'delivery_fees_owed' => 0,
        'return_fees_owed' => 0,
        'net_amount_expected' => $netExpected,
        'transferred_amount' => 0,
        'outstanding_amount' => $netExpected,
        'status' => 'open',
        'created_by' => $author->id,
    ]);
}

function reconciliationEmployee(): Employee
{
    return Employee::create([
        'full_name' => 'Accountant', 'email' => 'r-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
}

it('settles a statement only when it actually balances', function () {
    $statement = statementFor(100.0);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash']);

    $partly = app(ReconciliationService::class)
        ->recordTransfer($statement, 60.0, $treasury, reconciliationEmployee());

    expect($partly->status)->toBe('open')
        ->and((float) $partly->outstanding_amount)->toBe(40.0);

    $settled = app(ReconciliationService::class)
        ->recordTransfer($partly, 40.0, $treasury, reconciliationEmployee());

    expect($settled->status)->toBe('settled')
        ->and((float) $settled->outstanding_amount)->toBe(0.0)
        ->and((float) $treasury->fresh()->current_balance)->toBe(100.0);
});

it('keeps a statement open when we owe the company, instead of silently marking it settled', function () {
    // An adjustment has pushed the statement negative: the company is
    // owed 30 rather than owing us. The old rule closed anything <= 0 as
    // settled, so the debt vanished from the screen and nobody was paid.
    $statement = statementFor(-30.0);
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash']);

    $result = app(ReconciliationService::class)
        ->recordTransfer($statement, 0.0, $treasury, reconciliationEmployee());

    expect($result->status)->toBe('open')
        ->and((float) $result->outstanding_amount)->toBe(-30.0);
});
