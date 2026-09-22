<?php

namespace App\Reports;

use Illuminate\Support\Facades\File;

/**
 * Resolves report labels for server-rendered output — the .xlsx and .csv
 * headings, which are produced without a browser.
 *
 * It reads **the admin's own locale catalog**, the same JSON the React
 * table renders from, rather than a parallel PHP copy under `lang/`.
 * Three hundred column headings maintained in two places would drift the
 * first time someone renamed one, and the failure mode is silent: a
 * heading that is correct on screen and stale in the download.
 *
 * Falls back to the key itself, deliberately, the same way
 * `createTranslator()` does on the front end — an untranslated heading
 * should be obvious in review rather than rendering blank.
 */
class ReportTranslator
{
    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    public function get(string $key, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $fallback = (string) config('app.fallback_locale', 'en');

        return $this->catalog($locale)[$key]
            ?? $this->catalog($fallback)[$key]
            ?? $key;
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        if (! isset($this->catalogs[$locale])) {
            $path = resource_path("js/admin/locales/{$locale}.json");

            $decoded = File::exists($path)
                ? json_decode((string) File::get($path), true)
                : null;

            $this->catalogs[$locale] = is_array($decoded) ? $decoded : [];
        }

        return $this->catalogs[$locale];
    }
}
