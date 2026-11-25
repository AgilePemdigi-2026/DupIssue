<?php

use App\Http\Controllers\ExtensionAuthController;
use App\Http\Controllers\ExtensionRecommendationController;
use App\Http\Controllers\ExtensionRepositoryController;
use App\Http\Controllers\ExtensionVoteController;
use App\Http\Middleware\AuthenticateExtension;
use Illuminate\Support\Facades\Route;

Route::post('/github/webhook', \App\Http\Controllers\GithubWebhookController::class)
    ->middleware('throttle:120,1,github-webhook')->name('github.webhook');

Route::prefix('extension/v1')
    ->name('extension.api.')
    ->group(function (): void {

        Route::post(
            '/pair',
            [ExtensionAuthController::class, 'exchange']
        )
            ->middleware('throttle:10,1,extension-exchange')
            ->name('exchange');

        Route::middleware([
            AuthenticateExtension::class,
            'throttle:60,1,extension-authenticated',
        ])->group(function (): void {

            Route::match(
                ['GET', 'POST', 'DELETE'],
                '/votes',
                ExtensionVoteController::class
            )->name('votes');

            Route::post(
                '/recommendations',
                ExtensionRecommendationController::class
            )->name('recommendations');

            Route::post(
                '/repositories/resolve',
                [ExtensionRepositoryController::class, 'resolve']
            )->name('repositories.resolve');

            Route::get(
                '/me',
                [ExtensionAuthController::class, 'me']
            )->name('me');

            Route::delete(
                '/session',
                [ExtensionAuthController::class, 'disconnect']
            )->name('disconnect');
        });
    });
