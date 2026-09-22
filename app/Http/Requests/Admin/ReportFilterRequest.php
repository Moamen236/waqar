<?php

namespace App\Http\Requests\Admin;

use App\Enums\InventoryMovementType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Enums\ReturnStage;
use App\Enums\ReturnStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the standard filter bundle (spec A.3).
 *
 * The one rule here that is not ordinary type-checking is the **range
 * cap**: a report nobody can accidentally run over all time is a report
 * that never takes the site down. An operator reaching for "custom" and
 * leaving the start date blank should get a validation message, not a
 * query that scans four years of orders while everyone else waits.
 */
class ReportFilterRequest extends FormRequest
{
    /** Longest window any report will run, in days. */
    public const MAX_RANGE_DAYS = 366;

    public function authorize(): bool
    {
        // Report-level permission is enforced by the controller against the
        // report's own `permission()`, which this class cannot know.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset' => ['nullable', Rule::in([
                'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
                'this_quarter', 'last_quarter', 'this_year', 'last_year', 'custom',
            ])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'date_basis' => ['nullable', Rule::in(['placed', 'delivered', 'collected'])],
            'compare_to' => ['nullable', Rule::in(['none', 'previous_period', 'same_period_last_year'])],
            'granularity' => ['nullable', Rule::in(['day', 'week', 'month', 'quarter', 'year'])],
            // Which level of the geo tree SAL-04 groups on.
            'geo_level' => ['nullable', Rule::in(['governorate', 'city', 'district', 'area'])],

            'order_source' => ['nullable', Rule::in(array_column(OrderSource::cases(), 'value'))],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'payment_status' => ['nullable', 'array'],
            'payment_status.*' => [Rule::in(array_column(PaymentStatus::cases(), 'value'))],

            'employee_id' => ['nullable', 'array'],
            'employee_id.*' => ['integer', 'exists:employees,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer' => ['nullable', 'string', 'max:120'],

            'governorate_id' => ['nullable', 'integer', 'exists:governorates,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],

            'warehouse_id' => ['nullable', 'array'],
            'warehouse_id.*' => ['integer', 'exists:warehouses,id'],
            'category_id' => ['nullable', 'array'],
            'category_id.*' => ['integer', 'exists:categories,id'],
            'product_id' => ['nullable', 'array'],
            'product_id.*' => ['integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'array'],
            'variant_id.*' => ['integer', 'exists:product_variants,id'],

            'representative_id' => ['nullable', 'array'],
            'representative_id.*' => ['integer', 'exists:delivery_representatives,id'],
            'shipping_company_id' => ['nullable', 'array'],
            'shipping_company_id.*' => ['integer', 'exists:shipping_companies,id'],
            'assignment_type' => ['nullable', Rule::in(['representative', 'shipping_company'])],
            'coupon_id' => ['nullable', 'integer', 'exists:coupons,id'],

            // Audit-specific. `log_name` and `event` are free strings
            // rather than an enum: the domains come from each model's own
            // activityLogName(), and pinning a list here would go stale the
            // moment a model is added.
            'log_name' => ['nullable', 'array'],
            'log_name.*' => ['string', 'max:60'],
            'event' => ['nullable', 'array'],
            'event.*' => ['string', 'max:60'],
            'subject_type' => ['nullable', 'string', 'max:120'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],

            // Finance-specific.
            'treasury_id' => ['nullable', 'array'],
            'treasury_id.*' => ['integer', 'exists:treasuries,id'],
            'expense_category_id' => ['nullable', 'array'],
            'expense_category_id.*' => ['integer', 'exists:expense_categories,id'],
            'statement_status' => ['nullable', Rule::in(['open', 'settled'])],

            // Returns-specific.
            'return_stage' => ['nullable', Rule::in(array_column(ReturnStage::cases(), 'value'))],
            'return_status' => ['nullable', 'array'],
            'return_status.*' => [Rule::in(array_column(ReturnStatus::cases(), 'value'))],
            'refund_status' => ['nullable', Rule::in(array_column(RefundStatus::cases(), 'value'))],
            'refund_method' => ['nullable', Rule::in(array_column(RefundMethod::cases(), 'value'))],
            'min_units' => ['nullable', 'integer', 'min:1', 'max:100000'],

            // Inventory-specific.
            'movement_type' => ['nullable', 'array'],
            'movement_type.*' => [Rule::in(array_column(InventoryMovementType::cases(), 'value'))],
            'threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'variance_only' => ['nullable', Rule::in(['0', '1'])],

            'sort' => ['nullable', 'string', 'max:60'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->string('preset')->toString() !== 'custom') {
                return;
            }

            $from = $this->input('date_from');
            $to = $this->input('date_to');

            if (! $from || ! $to) {
                $validator->errors()->add('date_from', __('reports.validation.custom_range_required'));

                return;
            }

            if (CarbonImmutable::parse((string) $from)->diffInDays(CarbonImmutable::parse((string) $to)) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('date_to', __('reports.validation.range_too_long', [
                    'days' => self::MAX_RANGE_DAYS,
                ]));
            }
        });
    }

    /**
     * The validated filter set, with the report's own defaults filled in.
     *
     * @return array<string, mixed>
     */
    public function filters(string $defaultDateBasis): array
    {
        $filters = $this->validated();

        $filters['preset'] ??= 'this_month';
        $filters['date_basis'] ??= $defaultDateBasis;
        $filters['compare_to'] ??= 'none';
        $filters['granularity'] ??= 'day';

        return $filters;
    }
}
