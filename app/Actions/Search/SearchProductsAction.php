<?php

namespace App\Actions\Search;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * Plain MySQL query scopes, not Laravel Scout (spec Section 23, Question
 * 20's sibling decision) — no search-index service to run or keep in
 * sync. Searches the translatable name/description JSON columns via
 * MySQL's JSON path extraction, plus sku directly.
 */
class SearchProductsAction
{
    public function execute(string $term, string $locale = 'en', int $limit = 20): Collection
    {
        $term = trim($term);
        if ($term === '') {
            return new Collection;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return Product::query()
            ->where('status', true)
            ->where(function ($query) use ($like, $locale) {
                $query->where('sku', 'like', $like)
                    ->orWhere("name->{$locale}", 'like', $like)
                    ->orWhere("description->{$locale}", 'like', $like);
            })
            ->limit($limit)
            ->get();
    }
}
