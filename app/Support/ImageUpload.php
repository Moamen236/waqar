<?php

namespace App\Support;

/**
 * The one definition of "an image we will accept and then serve back from
 * our own origin".
 *
 * Two things this guards that the obvious rule does not:
 *
 * - **Laravel's `image` rule allows SVG.** An SVG is an XML document that
 *   may carry `<script>`, and everything uploaded here is served from the
 *   application's own origin, so an uploaded SVG is stored XSS against
 *   the admin session that opens it. Raster formats only, named
 *   explicitly.
 * - **`mimes:` checks the *content*, not the filename.** Laravel guesses
 *   the type from the file itself, so renaming `payload.html` to
 *   `photo.png` fails here rather than landing in `storage/app/public`
 *   and being served as text/html.
 *
 * @see PHASE-7-HANDOVER.md — this closes a confirmed High from the Phase 5
 *      security review that Phase 6 left open.
 */
final class ImageUpload
{
    /**
     * @var list<string>
     */
    public const RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

    /**
     * Same rules, for a field that may legitimately be absent (an edit
     * form that isn't replacing the existing picture).
     *
     * @return list<string>
     */
    public static function optional(): array
    {
        return ['nullable', ...self::RULES];
    }
}
