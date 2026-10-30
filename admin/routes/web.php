<?php

use App\Http\Controllers\ScanImageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('auth')->group(function () {
    Route::get('/scans/{scan}/image', [ScanImageController::class, 'image'])->name('scans.image');
    Route::get('/scans/{scan}/thumb', [ScanImageController::class, 'thumb'])->name('scans.thumb');
    Route::get('/exports/{export}/download', [ScanImageController::class, 'download'])->name('exports.download');
});
