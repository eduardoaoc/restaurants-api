<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;

// No `/` route on purpose: an unmatched URL is a plain 404 that never runs
// the `web` group, so it never starts a session (with the database driver,
// each cookieless hit would otherwise insert a row into `sessions`).

// Sanctum's own route (disabled via sanctum.routes = false), re-registered
// with the same controller and name plus a per-IP throttle — see the
// `csrf-cookie` limiter in AppServiceProvider.
Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
    ->middleware('throttle:csrf-cookie')
    ->name('sanctum.csrf-cookie');
