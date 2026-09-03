<?php

use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('/imports/{import}', [ImportController::class, 'show'])->whereNumber('import')->name('imports.show');
