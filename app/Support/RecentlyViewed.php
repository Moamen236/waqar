<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Recently-Viewed backing store for the product page and the account
 * tab the spec requires but the template lacks (Section 20 #16).
 *
 * Deliberately a cookie, not a table: Section 24's schema defines no
 * recently_viewed table, and the list has to work for a guest browsing
 * before login just as much as for a signed-in customer. Product ids
 * only — everything displayed is re-read from the catalog, so a
 * tampered cookie can at worst list products the customer could already
 * browse to.
 */
class RecentlyViewed
{
    private const COOKIE = 'recently_viewed';

    private const LIMIT = 12;

    /**
     * @return array<int, int>
     */
    public static function ids(Request $request): array
    {
        $raw = $request->cookie(self::COOKIE);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_slice(array_filter(array_map('intval', $decoded)), 0, self::LIMIT));
    }

    /**
     * Most recent first, de-duplicated. Queues the cookie rather than
     * returning it so the caller stays a plain Inertia response.
     */
    public static function remember(Request $request, int $productId): void
    {
        $ids = array_values(array_diff(self::ids($request), [$productId]));
        array_unshift($ids, $productId);

        Cookie::queue(self::COOKIE, (string) json_encode(array_slice($ids, 0, self::LIMIT)), 60 * 24 * 30);
    }
}
