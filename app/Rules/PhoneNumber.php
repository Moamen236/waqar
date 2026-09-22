<?php

namespace App\Rules;

/**
 * Egyptian mobile numbers are exactly 11 digits (01X followed by eight).
 * Laravel's `digits` rule already says that, so this is a shared
 * constant rather than a Rule object — the value is that the ten call
 * sites which previously each carried their own `max:30` cannot drift
 * apart again, not that the check itself is clever.
 *
 * Deliberately not enforcing the `01` prefix: the business asked for
 * eleven digits, and a stricter rule at a trust boundary this hot risks
 * rejecting a real order for a number the staff consider fine.
 *
 * Separators are not accepted. `orders.shipping_phone` is searched with
 * a LIKE in Order::scopeFiltered(), so a number stored as "010 1234
 * 5678" would silently stop matching a search for "01012345678".
 */
final class PhoneNumber
{
    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'digits:11'];
    }

    /**
     * @return list<string>
     */
    public static function optional(): array
    {
        return ['nullable', 'string', 'digits:11'];
    }
}
