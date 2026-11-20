<?php

namespace Webkul\GreenApi\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Webkul\GreenApi\Models\Message;
use Webkul\GreenApi\Repositories\ConversationRepository;
use Webkul\GreenApi\Repositories\MessageRepository;

class SendMessage
{
    public function __construct(
        private ConversationRepository $conversations,
        private MessageRepository $messages,
        private GreenApiClient $client,
        private WebhookProcessor $processor,
    ) {}

    public function send(int $conversationId, string $text, string $reference, int $userId): Message
    {
        $message = DB::transaction(function () use ($conversationId, $text, $reference, $userId) {
            $conversation = $this->conversations->getModel()->newQuery()->lockForUpdate()->findOrFail($conversationId);

            if ($conversation->opted_out || $conversation->mode === 'stopped') {
                throw ValidationException::withMessages(['message' => trans('green_api::app.stopped')]);
            }

            $existing = $this->messages->getModel()->newQuery()
                ->where('conversation_id', $conversationId)->where('client_reference', $reference)->first();

            if ($existing) {
                if ($existing->body !== $text) {
                    throw ValidationException::withMessages(['client_reference' => trans('green_api::app.reference-conflict')]);
                }

                return $existing;
            }

            $conversation->update(['mode' => 'human']);
            $conversation->touch();

            return $this->messages->create([
                'conversation_id' => $conversationId,
                'client_reference' => $reference,
                'direction' => 'outgoing',
                'body' => $text,
                'status' => 'pending',
                'sent_by' => $userId,
                'occurred_at' => now(),
            ]);
        }, 3);

        // Only the first request claims the send. A repeat with the same UUID returns the saved row.
        $claimed = $this->messages->getModel()->newQuery()->whereKey($message->id)
            ->where('status', 'pending')->update(['status' => 'sending']);

        if (! $claimed) {
            return $message->refresh();
        }

        $conversation = $this->conversations->findOrFail($conversationId);

        try {
            $externalId = $this->client->sendText($conversation->chat_id, $text);
        } catch (RuntimeException) {
            $message->update(['status' => 'unknown']);

            return $message->refresh();
        }

        DB::transaction(function () use ($message, $conversation, $externalId) {
            $this->conversations->getModel()->newQuery()->lockForUpdate()->findOrFail($conversation->id);
            $message->external_id = $externalId;
            $message->status = 'sent';
            $this->processor->reconcileStatus($message, $conversation->instance_id, $conversation->chat_id);
            $message->save();
        }, 3);

        return $message->refresh();
    }
}
