<?php

use App\Http\Controllers\ImportController;
use App\Http\Controllers\PropertyController;
use Illuminate\Support\Facades\Route;

Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('/imports/{import}', [ImportController::class, 'show'])->whereNumber('import')->name('imports.show');

Route::get('/properties', [PropertyController::class, 'index'])->name('properties.index');
