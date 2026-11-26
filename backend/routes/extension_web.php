<?php

use App\Http\Controllers\ExtensionAuthController;
use Illuminate\Support\Facades\Route;

// This file is loaded through routes/web.php.
// Keep the normal session, authentication and CSRF middleware.
Route::middleware([
    'auth',
    'role:REPORTER',
])->group(function (): void {
    Route::delete('/extension/sessions/{session}', [ExtensionAuthController::class, 'revokeOwned'])
        ->whereNumber('session')->middleware('throttle:30,1,extension-revoke')
        ->name('extension.sessions.revoke');

    Route::get(
        '/extension/connect',
        [ExtensionAuthController::class, 'show']
    )->name('extension.connect');

    Route::post(
        '/extension/pairing',
        [ExtensionAuthController::class, 'createPairing']
    )
        ->middleware('throttle:5,1,extension-pairing')
        ->name('extension.pairing.create');
});
