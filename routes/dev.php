<?php

use App\Http\Controllers\Dev\DevServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dev Service Routes (non-production only)
|--------------------------------------------------------------------------
|
| A local mock of an external AI provider used by the walking skeleton. The
| seeded "Seasonal Views" service points its post_url/get_url here.
|
*/
Route::prefix('dev/services')->name('dev.services.')->group(function () {
    Route::post('generate', [DevServiceController::class, 'generate'])->name('generate');
    Route::get('result', [DevServiceController::class, 'result'])->name('result');
});
