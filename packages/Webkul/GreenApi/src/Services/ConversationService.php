<?php

namespace Webkul\GreenApi\Services;

use InvalidArgumentException;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\GreenApi\Models\Conversation;
use Webkul\GreenApi\Repositories\ConversationRepository;

class ConversationService
{
    public function __construct(
        private ConversationRepository $conversations,
        private PersonRepository $persons,
    ) {}

    public function findOrCreate(string $chatId, ?string $name = null, ?string $instanceId = null): Conversation
    {
        $identity = ['instance_id' => $instanceId ?? (string) config('green-api.instance_id'), 'chat_id' => $chatId];
        $existing = $this->conversations->getModel()->newQuery()->where($identity)->first();

        if ($existing) {
            return $existing;
        }

        // Resolve only when first opening a conversation. Ambiguous contacts remain unlinked.
        $matches = [];

        foreach ($this->persons->getModel()->newQuery()->select(['id', 'name', 'user_id', 'contact_numbers'])->cursor() as $person) {
            foreach ($person->contact_numbers ?? [] as $number) {
                try {
                    if (ChatId::fromPhone((string) ($number['value'] ?? '')) === $chatId) {
                        $matches[$person->id] = $person;
                    }
                } catch (InvalidArgumentException) {
                    // A local or incomplete CRM number cannot identify an international chat.
                }
            }

            if (count($matches) > 1) {
                break;
            }
        }

        $person = count($matches) === 1 ? reset($matches) : null;

        return $this->conversations->getModel()->newQuery()->firstOrCreate($identity, [
            'name' => $name,
            'person_id' => $person?->id,
            'user_id' => $person?->user_id ?? config('green-api.default_user_id'),
            'mode' => 'human',
        ]);
    }
}
