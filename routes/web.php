<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdSetupController;
use App\Http\Controllers\T4JamController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard/');

Route::get('/login/', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login/', [AuthController::class, 'login'])->name('login.store');
Route::get('/social-auth/login/google-oauth2/', [AuthController::class, 'redirectGoogle'])->name('google.login');
Route::get('/social-auth/complete/google-oauth2/', [AuthController::class, 'callbackGoogle'])->name('google.callback');
Route::get('/register/', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register/', [AuthController::class, 'register'])->name('register.store');
Route::get('/account/riset-password/', [AuthController::class, 'showReset'])->name('password.request');
Route::post('/account/riset-password/', [AuthController::class, 'reset'])->name('password.email');

Route::middleware('t4jam.auth')->group(function () {
    Route::get('/dashboard/', [T4JamController::class, 'dashboard'])->name('dashboard');
    Route::get('/automation-task/', [T4JamController::class, 'automation'])->name('automation');
    Route::get('/interest/', [T4JamController::class, 'interest'])->name('interest');
    Route::get('/riset-produk-toped/', [T4JamController::class, 'products'])->name('products');
    Route::get('/setup-iklan/', [AdSetupController::class, 'index'])->name('ad-setups.index');
    Route::post('/setup-iklan/', [AdSetupController::class, 'store'])->name('ad-setups.store');
    Route::post('/setup-iklan/{adSetup}/publish/', [AdSetupController::class, 'publish'])->name('ad-setups.publish');
    Route::get('/profile/', [T4JamController::class, 'profile'])->name('profile');
    Route::post('/profile/access-token/', [T4JamController::class, 'saveAccessToken'])->name('profile.token');
    Route::post('/profile/sync-meta-ads/', [T4JamController::class, 'syncMetaAds'])->name('profile.sync-meta');
    Route::get('/logout/', [AuthController::class, 'logout'])->name('logout');

    Route::get('/api/get-ad-account/', [T4JamController::class, 'adAccounts']);
    Route::get('/api/get-ad-insight/', [T4JamController::class, 'adInsights']);
    Route::post('/api/changed-ad-account/', [T4JamController::class, 'changeAdAccount']);
    Route::post('/api/changed-selected-campaign/', [T4JamController::class, 'changeSelectedCampaign']);
    Route::post('/api/changed-settings/', [T4JamController::class, 'changeSettings']);
    Route::post('/api/reload-ad-account/', [T4JamController::class, 'reloadAdAccount']);
    Route::get('/check-connection/', [T4JamController::class, 'checkConnection']);
    Route::get('/check-selected-account/', [T4JamController::class, 'checkSelectedAccount']);
    Route::get('/get-automation-task/', [T4JamController::class, 'automationTasks']);
    Route::get('/get-history-log/', [T4JamController::class, 'historyLog']);
    Route::post('/update-status-automation-tasks/', [T4JamController::class, 'updateStatusAutomation']);
    Route::get('/get-specific-task/', [T4JamController::class, 'specificTask']);
    Route::post('/create-automation-tasks/', [T4JamController::class, 'createAutomationTask']);
    Route::post('/update-automation-tasks/', [T4JamController::class, 'updateAutomationTask']);
    Route::post('/turun-budget-manual/', [T4JamController::class, 'turunBudget']);
    Route::get('/api/get-interest/', [T4JamController::class, 'getInterest']);
    Route::get('/api/get-produk/', [T4JamController::class, 'getProducts']);
    Route::get('/api/get-category-product/', [T4JamController::class, 'getCategoryProducts']);
    Route::patch('/update-profile/', [T4JamController::class, 'updateProfile']);
    Route::post('/api/v1/auth/users/set_password/', [T4JamController::class, 'updatePassword']);
});

Route::get('/help/privacy-policy/', [T4JamController::class, 'privacy'])->name('privacy');
Route::get('/help/term-of-service/', [T4JamController::class, 'terms'])->name('terms');
