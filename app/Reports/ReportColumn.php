<?php

namespace App\Reports;

/**
 * One column of a report, described once and consumed three times: by the
 * Inertia table, by the .xlsx/.csv export and by the print view.
 *
 * `label` is a translation key, never a written string — admin is Arabic
 * (Q2), and a heading baked in English at definition time can never be
 * translated afterward. The screen resolves it through the same
 * `useTranslation()` lookup every other admin label uses; the export
 * resolves it server-side against the request locale, so an Arabic user's
 * download has Arabic headers.
 */
class ReportColumn
{
    /**
     * @param  string  $key  Row array key this column reads.
     * @param  string  $label  Translation key for the heading.
     * @param  'text'|'number'|'money'|'percent'|'date'|'datetime'|'enum'  $type
     * @param  bool  $sortable  Whether the column may drive `sort`.
     * @param  string|null  $gate  Extra permission required to see this column at all.
     * @param  string|null  $enumPrefix  Translation-key prefix for `enum` values.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $sortable = false,
        public readonly ?string $gate = null,
        public readonly ?string $enumPrefix = null,
    ) {}

    public static function text(string $key, string $label, bool $sortable = false): self
    {
        return new self($key, $label, 'text', $sortable);
    }

    public static function number(string $key, string $label, bool $sortable = true, ?string $gate = null): self
    {
        return new self($key, $label, 'number', $sortable, $gate);
    }

    /**
     * Money columns carry raw decimals into the export, never preformatted
     * strings — a currency-formatted cell is text to Excel, and a report
     * whose totals column cannot be summed by the person who downloaded it
     * has failed at the one thing a spreadsheet is for. Formatting happens
     * in the browser and in the print view only.
     */
    public static function money(string $key, string $label, bool $sortable = true, ?string $gate = null): self
    {
        return new self($key, $label, 'money', $sortable, $gate);
    }

    public static function percent(string $key, string $label, bool $sortable = true, ?string $gate = null): self
    {
        return new self($key, $label, 'percent', $sortable, $gate);
    }

    public static function date(string $key, string $label, bool $sortable = true): self
    {
        return new self($key, $label, 'date', $sortable);
    }

    public static function datetime(string $key, string $label, bool $sortable = true): self
    {
        return new self($key, $label, 'datetime', $sortable);
    }

    /**
     * A stored enum value (order status, payment status, movement type)
     * rendered through a translation key rather than shown raw.
     */
    public static function enum(string $key, string $label, string $enumPrefix, bool $sortable = false): self
    {
        return new self($key, $label, 'enum', $sortable, null, $enumPrefix);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'sortable' => $this->sortable,
            'enum_prefix' => $this->enumPrefix,
        ];
    }
}
