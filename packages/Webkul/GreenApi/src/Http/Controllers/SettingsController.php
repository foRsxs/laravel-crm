<?php

namespace Webkul\GreenApi\Http\Controllers;

use RuntimeException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\GreenApi\Services\GreenApiClient;

class SettingsController extends Controller
{
    public function index(GreenApiClient $client)
    {
        return view('green_api::settings', ['configured' => $client->isConfigured()]);
    }

    public function status(GreenApiClient $client)
    {
        try {
            return response()->json(['state' => $client->state()['stateInstance'] ?? 'unknown']);
        } catch (RuntimeException) {
            return response()->json(['error' => trans('green_api::app.connection-error')], 502);
        }
    }
}
