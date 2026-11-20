<?php

namespace Webkul\GreenApi\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\GreenApi\Models\Conversation;
use Webkul\GreenApi\Repositories\ConversationRepository;
use Webkul\GreenApi\Services\ChatId;
use Webkul\GreenApi\Services\ConversationService;
use Webkul\GreenApi\Services\GreenApiClient;
use Webkul\GreenApi\Services\SendMessage;
use Webkul\Lead\Repositories\LeadRepository;

class ConversationController extends Controller
{
    public function __construct(private ConversationRepository $conversations) {}

    private function scopedQuery()
    {
        $query = $this->conversations->getModel()->newQuery()
            ->where('instance_id', (string) config('green-api.instance_id'));
        $ids = bouncer()->getAuthorizedUserIds();

        if ($ids !== null) {
            $query->whereIn('user_id', $ids)
                ->where(fn ($q) => $q->whereNull('person_id')->orWhereHas('person', fn ($p) => $p->whereIn('user_id', $ids)))
                ->where(fn ($q) => $q->whereNull('lead_id')->orWhereHas('lead', fn ($l) => $l->whereIn('user_id', $ids)));
        }

        return $query;
    }

    private function conversation(int $id): Conversation
    {
        return $this->scopedQuery()->findOrFail($id);
    }

    public function index()
    {
        $conversations = $this->scopedQuery()->with(['person.organization', 'lead'])
            ->orderByDesc('updated_at')->paginate(30);

        return view('green_api::index', compact('conversations'));
    }

    public function show(int $id)
    {
        $conversation = $this->conversation($id);
        $messages = $conversation->messages()->orderByDesc('id')->paginate(50);

        return view('green_api::show', compact('conversation', 'messages'));
    }

    public function messages(Request $request, int $id)
    {
        $conversation = $this->conversation($id);
        $request->validate(['after' => 'nullable|integer|min:0']);

        return response()->json($conversation->messages()
            ->where('id', '>', $request->integer('after'))->orderBy('id')->limit(100)->get());
    }

    public function start(Request $request, int $leadId, LeadRepository $leads, ConversationService $service)
    {
        abort_unless(bouncer()->hasPermission('leads.view'), 401);
        $lead = $leads->findOrFail($leadId);
        $this->preventUnauthorizedAccess($lead->user_id);
        if (! $lead->person) {
            throw ValidationException::withMessages(['phone' => trans('green_api::app.contact-required')]);
        }
        $this->preventUnauthorizedAccess($lead->person->user_id);
        $data = $request->validate(['phone' => 'required|string|max:50']);

        try {
            $chatId = ChatId::fromPhone($data['phone']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['phone' => trans('green_api::app.invalid-phone')]);
        }

        $allowed = collect($lead->person->contact_numbers ?? [])->contains(function ($number) use ($chatId) {
            try {
                return ChatId::fromPhone((string) ($number['value'] ?? '')) === $chatId;
            } catch (InvalidArgumentException) {
                return false;
            }
        });
        if (! $allowed) {
            throw ValidationException::withMessages(['phone' => trans('green_api::app.phone-not-on-contact')]);
        }

        $conversation = $service->findOrCreate($chatId, $lead->person->name);
        $this->conversation($conversation->id);
        $this->preventUnauthorizedAccess($conversation->user_id);

        DB::transaction(function () use ($conversation, $lead) {
            $locked = $this->conversations->getModel()->newQuery()->lockForUpdate()->findOrFail($conversation->id);
            if ($locked->person_id && $locked->person_id !== $lead->person_id) {
                throw ValidationException::withMessages(['phone' => trans('green_api::app.contact-conflict')]);
            }
            $locked->update(['person_id' => $lead->person_id, 'lead_id' => $lead->id, 'user_id' => $lead->user_id]);
        });

        return redirect()->route('admin.green_api.show', $conversation->id);
    }

    public function send(Request $request, int $id, SendMessage $sender, GreenApiClient $client)
    {
        $conversation = $this->conversation($id);
        abort_unless($client->isConfigured(), 503);
        $data = $request->validate([
            'message' => 'required|string|max:20000',
            'client_reference' => 'required|uuid',
        ]);

        $message = $sender->send($conversation->id, $data['message'], $data['client_reference'], auth('user')->id());

        if ($request->expectsJson()) {
            return response()->json($message, $message->status === 'unknown' ? 502 : 200);
        }

        if ($message->status === 'unknown') {
            return back()->withErrors(['message' => trans('green_api::app.unknown-delivery')]);
        }

        return back()->with('success', trans('green_api::app.accepted'));
    }

    public function update(Request $request, int $id)
    {
        $conversation = $this->conversation($id);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['bot', 'human', 'paused', 'stopped'])],
            'opted_out' => 'required|boolean',
        ]);

        DB::transaction(function () use ($conversation, $data) {
            $locked = $this->conversations->getModel()->newQuery()->lockForUpdate()->findOrFail($conversation->id);
            $locked->update([
                'mode' => $data['opted_out'] ? 'stopped' : $data['mode'],
                'opted_out' => (bool) $data['opted_out'],
            ]);
        });

        return back();
    }
}
