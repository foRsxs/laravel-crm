<?php

namespace Webkul\GreenApi\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\GreenApi\Models\Message;
use Webkul\GreenApi\Repositories\ConversationRepository;
use Webkul\GreenApi\Repositories\MessageRepository;
use Webkul\GreenApi\Repositories\WebhookEventRepository;

class WebhookProcessor
{
    public function __construct(
        private WebhookEventRepository $events,
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private ConversationService $conversationService,
    ) {}

    public function process(int $eventId): void
    {
        DB::transaction(function () use ($eventId) {
            $event = $this->events->getModel()->newQuery()->lockForUpdate()->findOrFail($eventId);

            if ($event->processed_at) {
                return;
            }

            $payload = $event->payload;
            $type = $event->type;

            if ($type === 'outgoingMessageStatus') {
                $this->updateStatus($payload);
            } elseif (in_array($type, ['incomingMessageReceived', 'outgoingMessageReceived'], true)) {
                $this->recordMessage($payload, $type);
            }
            // API echoes are receipt records only. The send action owns the outgoing message row.
            $event->update(['processed_at' => now()]);
        }, 3);
    }

    public function reconcileStatus(Message $message, string $instanceId, string $chatId): void
    {
        $events = $this->events->getModel()->newQuery()
            ->where('instance_id', $instanceId)->where('external_id', $message->external_id)
            ->where('type', 'outgoingMessageStatus')->sharedLock()->get();

        foreach ($events as $event) {
            if (($event->payload['chatId'] ?? null) === $chatId) {
                $message->status = MessageStatus::advance($message->status, $event->payload['status'] ?? '');
            }
        }
    }

    private function updateStatus(array $payload): void
    {
        $conversation = $this->conversations->getModel()->newQuery()
            ->where('instance_id', (string) $payload['instanceData']['idInstance'])
            ->where('chat_id', $payload['chatId'] ?? '')->lockForUpdate()->first();

        if (! $conversation) {
            return;
        }

        $message = $this->messages->getModel()->newQuery()->where('conversation_id', $conversation->id)
            ->where('external_id', $payload['idMessage'] ?? '')->where('direction', 'outgoing')->first();

        if ($message) {
            $message->update(['status' => MessageStatus::advance($message->status, $payload['status'] ?? '')]);
        }
    }

    private function recordMessage(array $payload, string $eventType): void
    {
        $chatId = $payload['senderData']['chatId'] ?? '';

        if (! ChatId::isPersonal($chatId)) {
            return;
        }

        $conversation = $this->conversationService->findOrCreate(
            $chatId,
            $payload['senderData']['chatName'] ?? null,
            (string) $payload['instanceData']['idInstance'],
        );
        $conversation = $this->conversations->getModel()->newQuery()->lockForUpdate()->findOrFail($conversation->id);
        $incoming = $eventType === 'incomingMessageReceived';
        $data = $payload['messageData'] ?? [];
        $file = $data['fileMessageData'] ?? [];
        $text = $data['textMessageData']['textMessage']
            ?? $data['extendedTextMessageData']['text'] ?? $file['caption'] ?? '';
        $url = $file['downloadUrl'] ?? null;

        $message = $this->messages->getModel()->newQuery()->firstOrCreate([
            'conversation_id' => $conversation->id,
            'external_id' => $payload['idMessage'],
        ], [
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'type' => mb_substr($data['typeMessage'] ?? 'unknown', 0, 50),
            'body' => $text,
            'media_url' => is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
                && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : null,
            'media_name' => isset($file['fileName']) ? mb_substr($file['fileName'], 0, 255) : null,
            'status' => $incoming ? 'received' : 'sent',
            'occurred_at' => isset($payload['timestamp']) ? Carbon::createFromTimestampUTC($payload['timestamp']) : now(),
        ]);

        $conversation->touch();

        if (! $incoming) {
            $this->reconcileStatus($message, $conversation->instance_id, $conversation->chat_id);
            $message->save();

            if (! $conversation->opted_out && $conversation->mode !== 'stopped') {
                $conversation->update(['mode' => 'human']);
            }
        } elseif ($message->wasRecentlyCreated) {
            DB::afterCommit(fn () => Event::dispatch('green-api.message.received', [$message->id, $conversation->id]));
        }
    }
}
