<?php

use App\Http\Controllers\Dev\LocalCoreStubController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Local Core Stub Routes (non-production only)
|--------------------------------------------------------------------------
|
| A local mock of the core team's identity + coin service, mirroring their
| contract exactly. config('core.base_url') points here by default. Swapping
| to the real core is a config-only change — see CoreApiClient/CoreCoinService
| /CoreTokenAuthenticator.
|
*/
Route::prefix('dev/core')->name('dev.core.')->group(function () {
    Route::post('verify-token', [LocalCoreStubController::class, 'verifyToken'])->name('verify-token');
    Route::post('coins/deduct', [LocalCoreStubController::class, 'deduct'])->name('coins.deduct');
    Route::post('coins/settle', [LocalCoreStubController::class, 'settle'])->name('coins.settle');
    Route::post('coins/refund', [LocalCoreStubController::class, 'refund'])->name('coins.refund');
    Route::get('coins/balance', [LocalCoreStubController::class, 'balance'])->name('coins.balance');
});
