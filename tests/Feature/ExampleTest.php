<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The scaffold's "/ returns 200" no longer holds, by design: every page
     * lives under /{locale}/… since Phase 6, and a bare URL redirects into
     * the visitor's language rather than rendering one (Q20).
     * Phase6LocalizationTest covers that behaviour properly; this keeps the
     * smoke test honest rather than deleting it.
     */
    public function test_the_application_redirects_the_bare_root_into_a_locale(): void
    {
        $this->get('/')->assertRedirect('/ar');
    }
}
