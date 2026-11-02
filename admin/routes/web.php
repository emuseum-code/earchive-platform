<?php

use App\Http\Controllers\ScanImageController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Швидкий вхід під демо-користувачами (для тестування). Вимикається DEMO_QUICK_LOGIN=false.
Route::post('/demo-login', function (Request $request) {
    abort_unless(config('services.demo_login'), 404);
    $email = (string) $request->input('email');
    abort_unless(in_array($email, User::DEMO_LOGINS, true), 403);
    $user = User::where('email', $email)->where('is_active', true)->firstOrFail();
    Auth::login($user);
    $request->session()->regenerate();

    return redirect('/admin');
})->middleware('throttle:20,1')->name('demo-login');

Route::middleware('auth')->group(function () {
    Route::get('/scans/{scan}/image', [ScanImageController::class, 'image'])->name('scans.image');
    Route::get('/scans/{scan}/thumb', [ScanImageController::class, 'thumb'])->name('scans.thumb');
    Route::get('/exports/{export}/download', [ScanImageController::class, 'download'])->name('exports.download');
});
