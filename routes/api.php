<?php

use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\PaymentAdminController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\ESimController;
use App\Http\Controllers\Api\KycComplianceController;
use App\Http\Controllers\Api\MetaMapController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SimSwapController;
use App\Http\Controllers\Api\SubscriberController;
use Illuminate\Support\Facades\Route;

Route::post('/subscriber-lookup', [SubscriberController::class, 'lookup']);
Route::post('/subscriber-upload', [SubscriberController::class, 'upload']);
Route::post('/otp/send', [OtpController::class, 'send']);
Route::post('/otp/verify', [OtpController::class, 'verify']);
Route::post('/payments/record', [PaymentController::class, 'record']);
Route::post('/metamap/config', [MetaMapController::class, 'config']);
Route::post('/metamap/webhook', [MetaMapController::class, 'webhook']);

Route::prefix('esim')->group(function () {
    Route::post('/start', [ESimController::class, 'start']);
    Route::post('/{request}/terms', [ESimController::class, 'acceptTerms']);
    Route::post('/{request}/payment', [ESimController::class, 'pay']);
    Route::get('/{request}/numbers', [ESimController::class, 'numbers']);
    Route::post('/{request}/number', [ESimController::class, 'selectNumber']);
    Route::post('/{request}/registration', [ESimController::class, 'registration']);
    Route::post('/{request}/kyc/start', [ESimController::class, 'startKyc']);
    Route::get('/{request}/kyc/status', [ESimController::class, 'kycStatus']);
    Route::post('/{request}/confirm-kyc', [ESimController::class, 'confirmKyc']);
    Route::post('/{request}/activate', [ESimController::class, 'activate']);
});

Route::prefix('simswap')->group(function () {
    Route::post('/start', [SimSwapController::class, 'start']);
    Route::post('/{request}/number', [SimSwapController::class, 'number']);
    Route::post('/{request}/otp/send', [SimSwapController::class, 'sendOtp']);
    Route::post('/{request}/otp/verify', [SimSwapController::class, 'verifyOtp']);
    Route::post('/{request}/payment', [SimSwapController::class, 'pay']);
    Route::post('/{request}/kyc/start', [SimSwapController::class, 'startKyc']);
    Route::get('/{request}/kyc/status', [SimSwapController::class, 'kycStatus']);
    Route::post('/{request}/sim-type', [SimSwapController::class, 'simType']);
    Route::post('/{request}/esim/finalize', [SimSwapController::class, 'finalizeEsim']);
    Route::post('/{request}/shop/select', [SimSwapController::class, 'selectShop']);
});

Route::prefix('kyc-compliance')->group(function () {
    Route::post('/start', [KycComplianceController::class, 'start']);
    Route::post('/{requestId}/terms', [KycComplianceController::class, 'acceptTerms']);
    Route::post('/{requestId}/number', [KycComplianceController::class, 'number']);
    Route::post('/{requestId}/registration', [KycComplianceController::class, 'registration']);
    Route::post('/{requestId}/kyc/start', [KycComplianceController::class, 'startKyc']);
    Route::get('/{requestId}/status', [KycComplianceController::class, 'status']);
    Route::post('/{requestId}/complete', [KycComplianceController::class, 'complete']);
});

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/create-admin', [UserController::class, 'createAdmin']);
    Route::post('/users/assign-role', [UserController::class, 'assignRole']);
    Route::post('/users/remove-role', [UserController::class, 'removeRole']);
    Route::patch('/users/{user}/make-admin', [UserController::class, 'makeAdmin']);
    Route::patch('/users/{user}/remove-admin', [UserController::class, 'removeAdmin']);
    Route::get('/payments', [PaymentAdminController::class, 'index']);
});
