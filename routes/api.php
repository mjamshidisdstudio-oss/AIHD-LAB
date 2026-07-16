<?php

use App\Http\Controllers\Admin\Catalog\InputController;
use App\Http\Controllers\Admin\Catalog\OptionController;
use App\Http\Controllers\Admin\Catalog\OptionDependencyController;
use App\Http\Controllers\Admin\Catalog\OutputController;
use App\Http\Controllers\Admin\Catalog\ServiceController;
use App\Http\Controllers\Admin\Catalog\VersionController;
use App\Http\Controllers\Admin\Catalog\WaitingTextController;
use App\Http\Controllers\Marketplace\BookmarkController;
use App\Http\Controllers\Marketplace\BroadcastAuthController;
use App\Http\Controllers\Marketplace\CatalogController;
use App\Http\Controllers\Marketplace\CommentController;
use App\Http\Controllers\Marketplace\DownloadController;
use App\Http\Controllers\Marketplace\InteractionController;
use App\Http\Controllers\Marketplace\VoteController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Ingest gateway (external services)
|--------------------------------------------------------------------------
|
| Result webhooks and the opaque media storage API. These are called by the
| external/dev service, NOT by a logged-in user — auth is per-service (HMAC
| signature for webhooks, service_key Bearer for storage), never Sanctum.
|
*/
Route::post('webhooks/{service}/results', [WebhookController::class, 'results'])
    ->name('webhooks.results');

Route::get('storage/{mediaId}', [StorageController::class, 'show'])->name('storage.show');
Route::post('storage', [StorageController::class, 'store'])->name('storage.store');

/*
|--------------------------------------------------------------------------
| Orders (site)
|--------------------------------------------------------------------------
|
| Submit an order and read its status/results. Reads are answered entirely
| from our database — never from the external service. Authenticated via the
| core identity service (auth.core), NOT Sanctum: end-customer identity is
| owned by the core team, not our own `users` table (admin accounts only).
|
*/
Route::middleware('auth.core')->group(function () {
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
});

/*
|--------------------------------------------------------------------------
| Marketplace (site consumer catalog + community)
|--------------------------------------------------------------------------
|
| The Nuxt marketplace client's read/community surface: browsing the catalog,
| voting, commenting, bookmarking, and result downloads. Authenticated the
| same way as orders — via the core identity token, never Sanctum — since
| votes/bookmarks/comments all need a real user_ref to key on.
|
*/
Route::middleware('auth.core')->prefix('marketplace')->name('marketplace.')->group(function () {
    Route::get('services', [CatalogController::class, 'index'])->name('services.index');
    Route::get('services/{service:slug}', [CatalogController::class, 'show'])->name('services.show');

    Route::post('services/{service}/vote', [VoteController::class, 'store'])->name('services.vote');
    Route::post('services/{service}/bookmark', [BookmarkController::class, 'store'])->name('services.bookmark');
    Route::post('services/{service}/external-click', [InteractionController::class, 'externalClick'])->name('services.external-click');

    Route::get('services/{service}/comments', [CommentController::class, 'index'])->name('services.comments.index');
    Route::post('services/{service}/comments', [CommentController::class, 'store'])->name('services.comments.store');

    Route::get('results/{result}/download', [DownloadController::class, 'show'])->name('results.download');

    // Bridges core-token identity into Laravel's broadcasting auth flow so
    // Echo can subscribe to a private orders.{userRef} channel — see
    // BroadcastAuthController for why this can't just be the framework's
    // auto-registered POST /broadcasting/auth route.
    Route::post('broadcasting/auth', [BroadcastAuthController::class, 'authenticate'])->name('broadcasting.auth');
});

/*
|--------------------------------------------------------------------------
| Catalog Admin API
|--------------------------------------------------------------------------
|
| Authenticated (Sanctum) and authorized (manage-catalog gate) endpoints for
| managing services and their versions. Version content (inputs/options) can
| only be edited while the version is a draft; publishing/retiring/duplicating
| are dedicated actions.
|
*/
Route::middleware(['auth:sanctum', 'can:manage-catalog'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::apiResource('services', ServiceController::class)
            ->only(['index', 'store', 'show', 'update']);

        Route::get('services/{service}/versions', [VersionController::class, 'index'])
            ->name('services.versions.index');
        Route::post('services/{service}/versions', [VersionController::class, 'store'])
            ->name('services.versions.store');

        Route::get('versions/{version}', [VersionController::class, 'show'])->name('versions.show');
        Route::patch('versions/{version}', [VersionController::class, 'update'])->name('versions.update');
        Route::delete('versions/{version}', [VersionController::class, 'destroy'])->name('versions.destroy');
        Route::post('versions/{version}/duplicate', [VersionController::class, 'duplicate'])->name('versions.duplicate');
        Route::post('versions/{version}/publish', [VersionController::class, 'publish'])->name('versions.publish');
        Route::post('versions/{version}/retire', [VersionController::class, 'retire'])->name('versions.retire');

        Route::post('versions/{version}/inputs', [InputController::class, 'store'])->name('versions.inputs.store');
        Route::patch('inputs/{input}', [InputController::class, 'update'])->name('inputs.update');
        Route::delete('inputs/{input}', [InputController::class, 'destroy'])->name('inputs.destroy');

        Route::post('inputs/{input}/options', [OptionController::class, 'store'])->name('inputs.options.store');
        Route::patch('options/{option}', [OptionController::class, 'update'])->name('options.update');
        Route::delete('options/{option}', [OptionController::class, 'destroy'])->name('options.destroy');

        Route::post('versions/{version}/outputs', [OutputController::class, 'store'])->name('versions.outputs.store');
        Route::patch('outputs/{output}', [OutputController::class, 'update'])->name('outputs.update');
        Route::delete('outputs/{output}', [OutputController::class, 'destroy'])->name('outputs.destroy');

        Route::post('versions/{version}/waiting-texts', [WaitingTextController::class, 'store'])->name('versions.waiting-texts.store');
        Route::patch('waiting-texts/{waitingText}', [WaitingTextController::class, 'update'])->name('waiting-texts.update');
        Route::delete('waiting-texts/{waitingText}', [WaitingTextController::class, 'destroy'])->name('waiting-texts.destroy');

        Route::post('versions/{version}/option-dependencies', [OptionDependencyController::class, 'store'])->name('versions.option-dependencies.store');
        Route::patch('option-dependencies/{optionDependency}', [OptionDependencyController::class, 'update'])->name('option-dependencies.update');
        Route::delete('option-dependencies/{optionDependency}', [OptionDependencyController::class, 'destroy'])->name('option-dependencies.destroy');
    });
