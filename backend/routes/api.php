<?php

use App\Http\Controllers\Api\ActivityImageController;
use App\Http\Controllers\Api\ActivityLikeController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/status', StatusController::class);
    Route::post('/auth/email/verify', [EmailController::class, 'verify'])->middleware('throttle:20,1');
    Route::post('/auth/password/forgot', [PasswordResetController::class, 'store'])->middleware('throttle:account-mail-ip');
    Route::post('/auth/password/reset', [PasswordResetController::class, 'update'])->middleware('throttle:20,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login-ip');
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/register/username-availability', [AuthController::class, 'usernameAvailability'])->middleware('throttle:30,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/me/email', [EmailController::class, 'store'])->middleware('throttle:account-mail-ip');
        Route::post('/auth/me/email/resend', [EmailController::class, 'resend'])->middleware('throttle:account-mail-ip');
        Route::delete('/auth/me/email', [EmailController::class, 'destroy'])->middleware('throttle:10,1');
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me', [AuthController::class, 'updateMe']);
        Route::put('/auth/me/password', [AuthController::class, 'updatePassword'])->middleware('throttle:10,1');
        Route::post('/auth/me/password-warning/snooze', [AuthController::class, 'snoozePasswordWarning'])->middleware('throttle:10,1');

        Route::prefix('/groups/{group}')->middleware('group.member')->group(function () {
            Route::get('/', [GroupController::class, 'show']);
            Route::get('/rewards/manage', [RewardController::class, 'manage']);
            Route::get('/rewards', [RewardController::class, 'index']);
            Route::post('/rewards', [RewardController::class, 'store']);
            Route::post('/rewards/prepare', [RewardController::class, 'prepare']);
            Route::get('/rewards/awards', [RewardController::class, 'awards']);
            Route::post('/rewards/notifications', [RewardController::class, 'notifications']);
            Route::put('/rewards/{reward}/status', [RewardController::class, 'status'])->whereNumber('reward');
            Route::put('/rewards/{reward}', [RewardController::class, 'update'])->whereNumber('reward');
            Route::delete('/rewards/{reward}', [RewardController::class, 'destroy'])->whereNumber('reward');
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
            Route::get('/logs/counts', [ActivityLogController::class, 'counts']);
            Route::get('/gallery', [ActivityLogController::class, 'gallery']);
            Route::get('/images/{image}', [ActivityImageController::class, 'show'])->whereNumber('image');
            Route::apiResource('logs', ActivityLogController::class);
        });
        Route::prefix('/groups/{group}/logs/{log}/like')->middleware(['throttle:activity-likes', 'group.member'])->whereNumber('log')->group(function () {
            Route::put('/', [ActivityLikeController::class, 'update']);
            Route::delete('/', [ActivityLikeController::class, 'destroy']);
        });
    });
});
