<?php

/**
 * Server-only report strings.
 *
 * Deliberately small. Column headings and report titles are NOT duplicated
 * here — the .xlsx/.csv export resolves those from the admin's own locale
 * catalog through `ReportTranslator`, so there is one source of truth for
 * three hundred labels instead of two copies that drift apart silently.
 *
 * What stays: strings produced by the server where no browser is involved
 * and no front-end catalog applies — validation messages, and the label for
 * a system-triggered action with no employee behind it.
 */
return [
    'system' => 'System',

    'validation' => [
        'custom_range_required' => 'A custom period needs both a start and an end date.',
        'range_too_long' => 'A report can cover at most :days days.',
    ],
];
