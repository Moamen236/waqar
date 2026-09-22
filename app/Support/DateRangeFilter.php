<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The date window a history list shows, defaulting to today.
 *
 * Four admin screens are histories rather than work queues — the order
 * book, returns, the activity log and treasury movement — and every one
 * of them grew without bound, so opening any of them meant paging back
 * through every row ever written to reach this morning's.
 *
 * Absence and emptiness mean different things here, and that is what
 * lets one query string carry both states without inventing a second
 * parameter for it: a request with no `date_from` at all has not chosen,
 * so it gets today, while `?date_from=` has chosen "all dates" and gets
 * no lower bound. The reset control in the UI is just that empty
 * parameter, and a bookmark of a wide-open list keeps working.
 *
 * Work queues — Checking, Accounting, Delivery — deliberately do not use
 * this. They hold unfinished business from previous days, and defaulting
 * them to today strands orders nobody would think to go looking for.
 */
class DateRangeFilter
{
    /**
     * Validates the two parameters and resolves the window, so a caller
     * never has to decide separately what a missing date means.
     *
     * @return array{date_from: string|null, date_to: string|null}
     */
    public static function fromRequest(Request $request): array
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            // Only comparable when there is something to compare against:
            // "all dates" sends an empty date_from, which no date is after.
            'date_to' => array_filter([
                'nullable',
                'date',
                $request->filled('date_from') ? 'after_or_equal:date_from' : null,
            ]),
        ]);

        $from = $request->has('date_from')
            ? trim((string) $request->query('date_from'))
            : now()->toDateString();

        $to = trim((string) $request->query('date_to'));

        return [
            'date_from' => $from === '' ? null : $from,
            'date_to' => $to === '' ? null : $to,
        ];
    }

    /**
     * @param  array{date_from: string|null, date_to: string|null}  $range
     */
    public static function apply(Builder $query, array $range, string $column = 'created_at'): Builder
    {
        return $query
            ->when(
                $range['date_from'] !== null,
                fn (Builder $inner) => $inner->whereDate($column, '>=', $range['date_from']),
            )
            ->when(
                $range['date_to'] !== null,
                fn (Builder $inner) => $inner->whereDate($column, '<=', $range['date_to']),
            );
    }
}
