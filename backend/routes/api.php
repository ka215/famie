<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/status', StatusController::class);
    Route::post('/auth/email/verify', [EmailController::class, 'verify'])->middleware('throttle:20,1');
    Route::post('/auth/password/forgot', [PasswordResetController::class, 'store'])->middleware('throttle:account-mail-ip');
    Route::post('/auth/password/reset', [PasswordResetController::class, 'update'])->middleware('throttle:20,1');
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/register/username-availability', [AuthController::class, 'usernameAvailability'])->middleware('throttle:30,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/me/email', [EmailController::class, 'store'])->middleware('throttle:account-mail-ip');
        Route::post('/auth/me/email/resend', [EmailController::class, 'resend'])->middleware('throttle:account-mail-ip');
        Route::delete('/auth/me/email', [EmailController::class, 'destroy'])->middleware('throttle:10,1');
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me', [AuthController::class, 'updateMe']);
        Route::put('/auth/me/password', [AuthController::class, 'updatePassword']);

        Route::prefix('/groups/{group}')->middleware('group.member')->group(function () {
            Route::get('/', [GroupController::class, 'show']);
            Route::patch('/', [GroupController::class, 'update']);
            Route::get('/members', [UserController::class, 'index']);
            Route::post('/members', [UserController::class, 'store']);
            Route::get('/members/inactive', [UserController::class, 'inactive']);
            Route::patch('/members/{member}', [UserController::class, 'update'])->whereNumber('member');
            Route::put('/members/{member}/status', [UserController::class, 'updateStatus'])->whereNumber('member');
            Route::get('/categories', [CategoryController::class, 'index']);
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
            Route::apiResource('logs', ActivityLogController::class);
        });
    });
});
