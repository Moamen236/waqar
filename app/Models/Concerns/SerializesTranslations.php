<?php

namespace App\Models\Concerns;

/**
 * Serialize translatable columns as the current locale's string.
 *
 * `spatie/laravel-translatable` overrides `getAttributeValue()`, so
 * `$product->name` correctly returns one string — but it does **not**
 * override `attributesToArray()`. Anything that serializes a model
 * (`toArray()`, `paginate()`, `->get()`, an Inertia prop) therefore emits
 * the raw `{"ar": "...", "en": "..."}` map instead.
 *
 * On the storefront that was invisible, because those controllers map
 * their props by hand. In the admin it is fatal: React cannot render an
 * object as a child, so every screen showing a translatable `name` threw
 * React error #31 and rendered a **blank page** — Products index,
 * Categories index, Attributes, Collections, Checking's order detail,
 * Accounting's order detail and Returns create, all at once.
 *
 * Fixing it at the serialization boundary rather than in each React page
 * is deliberate: the display sites are the rule and there are dozens of
 * them, while the handful of *authoring* screens that genuinely need both
 * languages at once ask for them explicitly with `getTranslations()` —
 * see ProductController::edit() and CategoryController::edit().
 *
 * This trait is only ever used alongside `HasTranslations`.
 */
trait SerializesTranslations
{
    /**
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        $locale = app()->getLocale();

        foreach ($this->getTranslatableAttributes() as $key) {
            // Only rewrite what is actually loaded — a query with a
            // `select` that skipped the column must not have it
            // resurrected here as an empty string.
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $this->getTranslation($key, $locale);
            }
        }

        return $attributes;
    }
}
