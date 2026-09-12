<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role-scoped widget content (spec Question 18) is explicitly deferred
 * per the spec itself — "screen layout/components... designed in a
 * separate follow-up." This phase only moves the Phase 0 placeholder
 * behind real employee auth; the dashboard's actual content is Phase 4+
 * follow-up work, not a regression.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Dashboard');
    }
}
