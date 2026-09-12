<?php

use Illuminate\Support\Facades\Route;

// Storefront (Section 08/13/17, Phase 5). The {locale} prefix (/ar/…,
// /en/…, Q20) is Phase 6 and will wrap this group rather than change
// anything inside it.
Route::group([], base_path('routes/store.php'));

// Admin / Internal Operations surface (Section 14, Phase 4) — split out
// per CLAUDE.md's routing convention (routes/store.php / routes/admin.php,
// both loaded from here rather than growing this file further).
Route::prefix('admin')->name('admin.')->group(base_path('routes/admin.php'));
