<?php

use Illuminate\Support\Facades\Route;
use Webkul\GreenApi\Http\Controllers\ConversationController;
use Webkul\GreenApi\Http\Controllers\SettingsController;

Route::middleware(['web', 'admin_locale', 'user'])
    ->prefix(config('app.admin_path').'/green-api')
    ->name('admin.green_api.')
    ->group(function () {
        Route::get('/', [ConversationController::class, 'index'])->name('index');
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::post('/status', [SettingsController::class, 'status'])->name('status');
        Route::post('/leads/{leadId}', [ConversationController::class, 'start'])->whereNumber('leadId')->name('start');
        Route::get('/conversations/{id}', [ConversationController::class, 'show'])->whereNumber('id')->name('show');
        Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages'])->whereNumber('id')->name('messages');
        Route::post('/conversations/{id}/messages', [ConversationController::class, 'send'])->whereNumber('id')->name('send');
        Route::patch('/conversations/{id}', [ConversationController::class, 'update'])->whereNumber('id')->name('update');
    });
