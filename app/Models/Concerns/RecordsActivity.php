<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The project's one activity-log convention (spec Section 23), so every
 * audited model records the same shape rather than each re-deciding.
 *
 * A model opts in by using this trait and overriding `activityLogName()`
 * (the audit domain) plus `activityLogAttributes()` (what is worth
 * keeping). It may override `activitySubjectLabel()` to say how it should
 * read in the log viewer.
 *
 * Two deliberate choices:
 *
 * - **The description stays the raw event name** (`created`/`updated`/
 *   `deleted`/`restored`) rather than a written-out English sentence.
 *   Admin is Arabic (Q2), and a description baked at write time can never
 *   be translated afterward; the viewer renders the event through the
 *   same key-lookup Phase 6 used for order statuses.
 * - **A label snapshot rides along in the properties** of every entry.
 *   The subject row can be soft-deleted, and an audit entry that can no
 *   longer say *which* product was deleted fails the only bar this
 *   feature has.
 */
trait RecordsActivity
{
    use LogsActivity {
        attributeValuesToBeLogged as private spatieAttributeValuesToBeLogged;
    }

    /**
     * Credentials and PII never belong in an audit trail — it is read by
     * more people than the record itself is (Section 15), and entries are
     * retained for a year (config/activitylog.php).
     *
     * @var list<string>
     */
    private const NEVER_LOGGED = [
        'password',
        'remember_token',
        'national_id_number',
    ];

    /**
     * The audit domain this model's entries file under.
     *
     * Declared as methods rather than properties on purpose: a class that
     * redeclares a trait *property* with a different default is a fatal
     * composition error in PHP, so every model overriding the domain
     * would break the moment the trait carried a default of its own.
     */
    protected function activityLogName(): string
    {
        return 'default';
    }

    /**
     * Columns worth keeping. Deliberately explicit per model rather than
     * `['*']`: an audit trail that logs every column logs `updated_at` on
     * every write and buries the changes that matter.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['*'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName($this->activityLogName())
            ->logOnly($this->activityLogAttributes())
            ->logExcept(self::NEVER_LOGGED)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * How this record should read in the log viewer once its own row may
     * no longer be there to ask.
     *
     * Translatable names are resolved in one fixed locale rather than the
     * actor's. `$product->name` returns the *current* locale's string, so
     * the same product deleted by an Arabic-speaking operator and edited
     * by an English-speaking one would produce two entries that look like
     * two different products. An audit trail has to be comparable across
     * its own rows, so the label is pinned to the fallback locale.
     */
    public function activitySubjectLabel(): string
    {
        foreach (['name', 'full_name', 'title', 'order_number'] as $attribute) {
            $value = $this->activityLabelValue($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($this).' #'.$this->getKey();
    }

    private function activityLabelValue(string $attribute): ?string
    {
        $translatable = property_exists($this, 'translatable') ? $this->translatable : [];

        if (! in_array($attribute, $translatable, true)) {
            $value = $this->getAttribute($attribute);

            return is_string($value) ? $value : null;
        }

        // Read the stored JSON rather than calling getTranslation(): only
        // some of the models using this trait are translatable at all, so
        // a dynamic call here is an undefined method on the other half —
        // and the column being a JSON map is already this project's
        // schema decision (CLAUDE.md), not an implementation detail of
        // the package.
        $raw = $this->getAttributes()[$attribute] ?? null;
        $translations = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($translations)) {
            return is_string($raw) ? $raw : null;
        }

        $locale = (string) config('app.fallback_locale', 'en');
        $value = $translations[$locale] ?? null;

        // A record genuinely written only in Arabic still deserves a
        // readable label, so fall back to whatever translation exists.
        if (! is_string($value) || $value === '') {
            $value = collect($translations)->filter(fn ($item) => is_string($item) && $item !== '')->first();
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Spatie's properties payload, plus the label snapshot. Overriding
     * here rather than registering a `LoggablePipe` per model: the pipe
     * registry is a static on the trait, so it would need re-registering
     * for every class that uses it.
     *
     * @return array<string, mixed>
     */
    public function attributeValuesToBeLogged(string $processingEvent): array
    {
        $properties = $this->spatieAttributeValuesToBeLogged($processingEvent);

        $properties['label'] = $this->activitySubjectLabel();

        return $properties;
    }
}
