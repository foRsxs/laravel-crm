<?php

use Illuminate\Support\Facades\Route;
use Webkul\GreenApi\Http\Controllers\WebhookController;

// No session or CSRF bypass in core: this is a separate, bearer-authenticated API endpoint.
Route::middleware('api')->post('/green-api/webhook', WebhookController::class)->name('green_api.webhook');
