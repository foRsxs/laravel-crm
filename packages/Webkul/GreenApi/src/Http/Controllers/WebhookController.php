<?php

namespace Webkul\GreenApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Webkul\GreenApi\Jobs\ProcessWebhook;
use Webkul\GreenApi\Repositories\WebhookEventRepository;

class WebhookController
{
    public function __invoke(Request $request, WebhookEventRepository $events): JsonResponse
    {
        abort_unless(config('green-api.enabled'), 503);
        $token = (string) config('green-api.webhook_token');

        abort_unless(strlen($token) >= 32 && hash_equals($token, (string) $request->bearerToken()), 401);
        if (strlen($request->getContent()) > 262144) {
            return response()->json(['error' => 'Payload too large.'], 413);
        }

        $payload = $request->json()->all();
        Validator::make($payload, [
            'typeWebhook' => 'required|string|max:64',
            'instanceData' => 'required|array',
            'instanceData.idInstance' => ['required', 'regex:/^[0-9]{1,12}$/D'],
            'senderData' => 'nullable|array',
            'messageData' => 'nullable|array',
            'messageData.textMessageData' => 'nullable|array',
            'messageData.extendedTextMessageData' => 'nullable|array',
            'messageData.fileMessageData' => 'nullable|array',
            'idMessage' => 'nullable|string|max:191',
            'timestamp' => 'nullable|integer|min:0|max:4102444800',
            'senderData.chatId' => 'nullable|string|max:100',
            'senderData.chatName' => 'nullable|string|max:255',
            'messageData.typeMessage' => 'nullable|string|max:50',
            'messageData.textMessageData.textMessage' => 'nullable|string|max:20000',
            'messageData.extendedTextMessageData.text' => 'nullable|string|max:20000',
            'messageData.fileMessageData.caption' => 'nullable|string|max:20000',
            'messageData.fileMessageData.downloadUrl' => 'nullable|string|max:8192',
            'messageData.fileMessageData.fileName' => 'nullable|string|max:255',
            'chatId' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:32',
        ])->validate();

        abort_unless((string) $payload['instanceData']['idInstance'] === (string) config('green-api.instance_id'), 403);
        $type = $payload['typeWebhook'];

        if (in_array($type, ['incomingMessageReceived', 'outgoingMessageReceived', 'outgoingAPIMessageReceived', 'outgoingMessageStatus'], true)) {
            Validator::make($payload, ['idMessage' => 'required|string|max:191'])->validate();
        }

        // Delivery status changes share an idMessage. Include the status in the deduplication key.
        $identity = [
            $type, (string) $payload['instanceData']['idInstance'], $payload['idMessage'] ?? '',
            $payload['status'] ?? '', $payload['chatId'] ?? $payload['senderData']['chatId'] ?? '',
        ];
        if (empty($payload['idMessage'])) {
            $identity[] = hash('sha256', $request->getContent());
        }

        $event = $events->getModel()->newQuery()->firstOrCreate([
            'event_key' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
        ], [
            'instance_id' => (string) $payload['instanceData']['idInstance'],
            'external_id' => $payload['idMessage'] ?? null,
            'type' => $type,
            'payload' => $payload,
        ]);

        if (! $event->processed_at) {
            // Receipt is durable before queueing. A failed dispatch returns 500 so GREEN-API retries.
            ProcessWebhook::dispatch($event->id);
        }

        return response()->json(['received' => true]);
    }
}
