<?php

namespace App\Services\Catalog;

use App\Models\Product;

/**
 * Assigns the next product SKU, so the admin never types one.
 *
 * The catalog's SKUs are `<PREFIX>-<NNN>` — MSH-001, RAG-002 … PRJ-014.
 * The seeded prefixes are hand-picked mnemonics of the product name
 * (MSH = Mesh Shirt, BLU = Off-the-Shoulder *Blouse*), and no mechanical
 * rule reproduces them: they skip words, and two of them take letters
 * from inside a word rather than its initial. Rather than approximate a
 * rule that would sometimes agree and sometimes not, generated SKUs take
 * one fixed prefix and carry the convention in the part that actually has
 * to be unique — the number. Nothing parses the prefix, so the existing
 * mnemonics stay valid alongside.
 *
 * The number continues the existing sequence rather than counting rows:
 * `count() + 1` repeats a number the moment anything is deleted, and the
 * column is UNIQUE, so that would surface as a QueryException on save.
 *
 * **Trashed products are included deliberately.** `products.sku` is
 * UNIQUE and `Product` is soft-deleted, so a deleted product still owns
 * its SKU in the index — generating against live rows only would hand out
 * a number the database then refuses. (The same shape of bug as the
 * shipping-rate revive in Phase 4's delete pass.)
 */
class SkuGenerator
{
    private const PREFIX = 'PRD';

    /** Matches the seeded width (001), and simply grows past 999. */
    private const PAD = 3;

    public function nextProductSku(): string
    {
        $taken = Product::withTrashed()->pluck('sku');

        $highest = $taken
            ->map(static fn (string $sku): int => preg_match('/(\d+)$/', $sku, $match) === 1 ? (int) $match[1] : 0)
            ->max() ?? 0;

        $existing = $taken->flip();

        // The highest trailing number is not by itself proof the next one
        // is free: a SKU can be shaped differently (PRD-015-A), or a
        // number can already be held under another prefix. Stepping until
        // the candidate is actually unused keeps this a generator rather
        // than a guess.
        do {
            $candidate = self::PREFIX.'-'.str_pad((string) ++$highest, self::PAD, '0', STR_PAD_LEFT);
        } while ($existing->has($candidate));

        return $candidate;
    }
}
