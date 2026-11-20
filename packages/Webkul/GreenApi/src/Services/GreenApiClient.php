<?php

namespace Webkul\GreenApi\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GreenApiClient
{
    public function isConfigured(): bool
    {
        return (bool) (config('green-api.enabled')
            && preg_match('/^[0-9]{1,12}$/D', (string) config('green-api.instance_id'))
            && preg_match('/^[a-zA-Z0-9_-]{16,256}$/D', (string) config('green-api.api_token'))
            && preg_match('~^https://(?:[a-z0-9-]+\\.)*(?:green-api\\.com|greenapi\\.com)/?$~D', (string) config('green-api.api_url')));
    }

    public function state(): array
    {
        return $this->request('getStateInstance');
    }

    public function sendText(string $chatId, string $text): string
    {
        $result = $this->request('sendMessage', ['chatId' => $chatId, 'message' => $text]);
        $id = $result['idMessage'] ?? null;

        if (! is_string($id) || $id === '' || strlen($id) > 191) {
            throw new RuntimeException('GREEN-API did not confirm the message identifier.');
        }

        return $id;
    }

    private function request(string $method, ?array $body = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('GREEN-API is not configured.');
        }

        $url = rtrim(config('green-api.api_url'), '/').'/waInstance'.config('green-api.instance_id')
            .'/'.$method.'/'.config('green-api.api_token');

        try {
            // A timed-out send may already have been accepted. No automatic HTTP retries.
            $request = Http::acceptJson()->asJson()->connectTimeout(5)
                ->timeout(config('green-api.timeout'))->withoutRedirecting();
            $response = $body === null ? $request->get($url) : $request->post($url, $body);
        } catch (Throwable) {
            // Provider URLs contain the secret token. Never expose or chain the HTTP exception.
            throw new RuntimeException('GREEN-API connection failed. Delivery may be unknown.');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('GREEN-API request failed. HTTP '.$response->status());
        }

        return $response->json();
    }
}
