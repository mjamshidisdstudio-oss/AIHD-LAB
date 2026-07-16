<?php

use App\Http\Controllers\Admin\Catalog\InputController;
use App\Http\Controllers\Admin\Catalog\OptionController;
use App\Http\Controllers\Admin\Catalog\OptionDependencyController;
use App\Http\Controllers\Admin\Catalog\OutputController;
use App\Http\Controllers\Admin\Catalog\ServiceController;
use App\Http\Controllers\Admin\Catalog\VersionController;
use App\Http\Controllers\Admin\Catalog\WaitingTextController;
use App\Http\Controllers\OrderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

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
