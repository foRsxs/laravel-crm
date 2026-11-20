<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Webkul\Contact\Models\Person;
use Webkul\GreenApi\Jobs\ProcessWebhook;
use Webkul\GreenApi\Models\Conversation;
use Webkul\GreenApi\Models\Message;
use Webkul\GreenApi\Models\WebhookEvent;
use Webkul\GreenApi\Services\ChatId;
use Webkul\GreenApi\Services\GreenApiClient;
use Webkul\GreenApi\Services\MessageStatus;
use Webkul\GreenApi\Services\WebhookProcessor;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\Role;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config([
        'green-api.enabled' => true,
        'green-api.instance_id' => '7103000000',
        'green-api.api_token' => str_repeat('a', 32),
        'green-api.webhook_token' => str_repeat('w', 48),
        'green-api.api_url' => 'https://api.green-api.com',
        'queue.default' => 'sync',
    ]);
    Http::preventStrayRequests();
    $this->withoutVite();
});

function greenPayload(string $id = 'incoming-1', string $type = 'incomingMessageReceived'): array
{
    return [
        'typeWebhook' => $type,
        'instanceData' => ['idInstance' => 7103000000],
        'timestamp' => 1795160000,
        'idMessage' => $id,
        'senderData' => ['chatId' => '77010001122@c.us', 'chatName' => 'Clinic'],
        'messageData' => ['typeMessage' => 'textMessage', 'textMessageData' => ['textMessage' => 'Hello']],
    ];
}

function greenConversation(?int $owner = null): Conversation
{
    return Conversation::create([
        'instance_id' => '7103000000',
        'chat_id' => '77010001122@c.us',
        'user_id' => $owner,
        'mode' => 'bot',
    ]);
}

function greenUser(array $permissions, string $scope = 'individual'): User
{
    $role = Role::create([
        'name' => 'Green '.Str::uuid(),
        'permission_type' => 'custom',
        'permissions' => $permissions,
    ]);

    return User::create([
        'name' => 'Operator',
        'email' => Str::uuid().'@example.invalid',
        'password' => bcrypt('test-only-password'),
        'role_id' => $role->id,
        'status' => 1,
        'view_permission' => $scope,
    ]);
}

function greenLead(User $owner): Lead
{
    $person = Person::withoutEvents(fn () => Person::create([
        'name' => 'Clinic', 'emails' => [], 'user_id' => $owner->id,
        'contact_numbers' => [['value' => '+77010001122', 'label' => 'work']],
        'unique_id' => (string) Str::uuid(),
    ]));
    $pipeline = securityTestPipeline();

    return Lead::create([
        'title' => 'Clinic enquiry', 'user_id' => $owner->id, 'person_id' => $person->id,
        'lead_pipeline_id' => $pipeline->id, 'lead_pipeline_stage_id' => $pipeline->stages()->first()->id,
    ]);
}

it('normalizes international numbers and excludes groups', function () {
    expect(ChatId::fromPhone('+7 (701) 000-11-22'))->toBe('77010001122@c.us')
        ->and(ChatId::isPersonal('77010001122@c.us'))->toBeTrue()
        ->and(ChatId::isPersonal('123456789@g.us'))->toBeFalse();

    expect(fn () => ChatId::fromPhone('hello/../../'))->toThrow(InvalidArgumentException::class);
});

it('authenticates the webhook before accepting a receipt', function () {
    $this->postJson('/green-api/webhook', greenPayload())->assertUnauthorized();
    $this->withToken('wrong')->postJson('/green-api/webhook', greenPayload())->assertUnauthorized();
    expect(WebhookEvent::count())->toBe(0);
});

it('fails closed when the webhook secret is empty or the package is disabled', function () {
    config(['green-api.webhook_token' => '']);
    $this->postJson('/green-api/webhook', greenPayload())->assertUnauthorized();
    config(['green-api.enabled' => false]);
    $this->postJson('/green-api/webhook', greenPayload())->assertServiceUnavailable();
});

it('rejects a different instance and malformed nested message data', function () {
    $payload = greenPayload();
    $payload['instanceData']['idInstance'] = 123;
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', $payload)->assertForbidden();

    $payload = greenPayload();
    $payload['messageData']['textMessageData']['textMessage'] = ['bad'];
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', $payload)->assertUnprocessable();
    expect(WebhookEvent::count())->toBe(0);
});

it('durably stores incoming messages once across webhook redeliveries', function () {
    $payload = greenPayload();
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', $payload)->assertSuccessful();
    $payload['timestamp']++;
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', $payload)->assertSuccessful();
    expect(WebhookEvent::where('type', 'incomingMessageReceived')->count())->toBe(1)
        ->and(Message::count())->toBe(1)
        ->and(Message::first()->body)->toBe('Hello')
        ->and(WebhookEvent::first()->processed_at)->not->toBeNull();
});

it('stores the full supported Unicode message length for incoming messages', function () {
    $text = str_repeat('🙂', 20000);
    $payload = greenPayload('long-unicode-incoming');
    $payload['messageData']['textMessageData']['textMessage'] = $text;

    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', $payload)->assertSuccessful();

    expect(Message::sole()->body)->toBe($text);
});

it('stores the full supported Unicode message length before sending', function () {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    $text = str_repeat('🙂', 20000);
    Http::fake(['*' => Http::response(['idMessage' => 'long-unicode-outgoing'])]);

    $this->actingAs($admin, 'user')->postJson(route('admin.green_api.send', $conversation->id, false), [
        'message' => $text, 'client_reference' => (string) Str::uuid(),
    ])->assertSuccessful();

    expect(Message::sole()->body)->toBe($text);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['message'] === $text && strlen($request->body()) < 100000);
});

it('stores the receipt before dispatching to the configured queue', function () {
    Queue::fake();
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', greenPayload())->assertSuccessful();
    expect(WebhookEvent::count())->toBe(1)->and(Message::count())->toBe(0);
    Queue::assertPushed(ProcessWebhook::class);
});

it('links a uniquely matching formatted CRM phone number', function () {
    $person = Person::withoutEvents(fn () => Person::create([
        'name' => 'Matched Clinic',
        'contact_numbers' => [['value' => '+7 (701) 000-11-22', 'label' => 'work']],
        'unique_id' => (string) Str::uuid(),
        'emails' => [],
    ]));
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', greenPayload())->assertSuccessful();
    expect(Conversation::first()->person_id)->toBe($person->id);
});

it('leaves ambiguous phone matches unlinked', function () {
    foreach (['Clinic A', 'Clinic B'] as $name) {
        Person::withoutEvents(fn () => Person::create([
            'name' => $name,
            'contact_numbers' => [['value' => '+77010001122', 'label' => 'work']],
            'unique_id' => (string) Str::uuid(),
            'emails' => [],
        ]));
    }
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', greenPayload())->assertSuccessful();
    expect(Conversation::first()->person_id)->toBeNull();
});

it('handles unsupported events and groups without creating a conversation', function () {
    $payload = greenPayload();
    $payload['senderData']['chatId'] = '123456789@g.us';
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', $payload)->assertSuccessful();
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', [
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 7103000000],
        'stateInstance' => 'authorized',
    ])->assertSuccessful();
    expect(Message::count())->toBe(0)->and(Conversation::count())->toBe(0);
});

it('captures phone replies and hands automation over to a human', function () {
    $conversation = greenConversation();
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', greenPayload('phone-1', 'outgoingMessageReceived'))->assertSuccessful();
    expect($conversation->refresh()->mode)->toBe('human')
        ->and(Message::first()->direction)->toBe('outgoing');
});

it('sends text once per client reference and stores the provider identifier', function () {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    Http::fake(['*' => Http::response(['idMessage' => 'provider-1'])]);
    $data = ['message' => 'Hello clinic', 'client_reference' => (string) Str::uuid()];
    $url = route('admin.green_api.send', $conversation->id, false);

    $this->actingAs($admin, 'user')->postJson($url, $data)->assertSuccessful()->assertJsonPath('external_id', 'provider-1');
    $this->postJson($url, $data)->assertSuccessful();
    Http::assertSentCount(1);
    expect(Message::where('conversation_id', $conversation->id)->count())->toBe(1)
        ->and($conversation->refresh()->mode)->toBe('human');
    Http::assertSent(fn ($request) => $request['chatId'] === $conversation->chat_id && $request['message'] === 'Hello clinic');
    $this->postJson($url, [...$data, 'message' => 'Different text'])->assertUnprocessable();
});

it('does not duplicate an outgoing message when its API echo arrives', function () {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    Http::fake(['*' => Http::response(['idMessage' => 'provider-echo'])]);
    $this->actingAs($admin, 'user')->postJson(route('admin.green_api.send', $conversation->id, false), [
        'message' => 'Hello', 'client_reference' => (string) Str::uuid(),
    ])->assertSuccessful();

    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', greenPayload('provider-echo', 'outgoingAPIMessageReceived'))->assertSuccessful();

    expect($conversation->messages()->count())->toBe(1)
        ->and(WebhookEvent::sole()->processed_at)->not->toBeNull();
    Http::assertSentCount(1);
});

it('links a new conversation only to a phone on an accessible lead contact', function () {
    $user = greenUser(['green_api', 'green_api.view', 'green_api.send', 'leads.view']);
    $lead = greenLead($user);
    $url = route('admin.green_api.start', $lead->id, false);

    $this->actingAs($user, 'user')->postJson($url, ['phone' => '+77010001123'])->assertUnprocessable();
    expect(Conversation::count())->toBe(0);

    $this->post($url, ['phone' => '+7 (701) 000-11-22'])->assertRedirect();
    $conversation = Conversation::sole();
    expect($conversation->person_id)->toBe($lead->person_id)
        ->and($conversation->lead_id)->toBe($lead->id)
        ->and($conversation->user_id)->toBe($user->id);
    Http::assertNothingSent();
});

it('requires global scope to link existing unassigned history before the operator can access it', function () {
    $admin = getDefaultAdmin();
    $user = greenUser(['green_api', 'green_api.view', 'green_api.send', 'leads.view']);
    $conversation = greenConversation();
    $lead = greenLead($user);
    $url = route('admin.green_api.start', $lead->id, false);

    $this->actingAs($user, 'user')->postJson($url, ['phone' => '+77010001122'])->assertNotFound();
    expect($conversation->refresh()->user_id)->toBeNull();

    $this->actingAs($admin, 'user')->post($url, ['phone' => '+77010001122'])->assertRedirect();
    expect($conversation->refresh()->user_id)->toBe($user->id)
        ->and($conversation->lead_id)->toBe($lead->id);

    $this->actingAs($user, 'user')->getJson(route('admin.green_api.messages', $conversation->id, false))->assertSuccessful();
});

it('prevents starting a conversation through another operators lead', function () {
    $lead = greenLead(getDefaultAdmin());
    $user = greenUser(['green_api', 'green_api.view', 'green_api.send', 'leads.view']);

    $this->actingAs($user, 'user')->postJson(route('admin.green_api.start', $lead->id, false), ['phone' => '+77010001122'])->assertUnauthorized();
    expect(Conversation::count())->toBe(0);
    Http::assertNothingSent();
});

it('marks an ambiguous HTTP failure unknown and does not resend it automatically', function () {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    Http::fake(fn () => throw new ConnectionException('secret URL must not escape'));
    $data = ['message' => 'Hello', 'client_reference' => (string) Str::uuid()];
    $url = route('admin.green_api.send', $conversation->id, false);

    $this->actingAs($admin, 'user')->postJson($url, $data)->assertStatus(502)->assertJsonPath('status', 'unknown');
    $this->postJson($url, $data)->assertStatus(502);
    expect(Message::count())->toBe(1)->and(Message::first()->status)->toBe('unknown');
});

it('blocks outbound messages after an opt out', function () {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    $conversation->update(['opted_out' => true, 'mode' => 'stopped']);
    $this->actingAs($admin, 'user')->postJson(route('admin.green_api.send', $conversation->id, false), [
        'message' => 'Do not send', 'client_reference' => (string) Str::uuid(),
    ])->assertUnprocessable();
    Http::assertNothingSent();
    expect(Message::count())->toBe(0);
});

it('never regresses a read delivery status on out of order callbacks', function () {
    $conversation = greenConversation();
    $message = Message::create(['conversation_id' => $conversation->id, 'external_id' => 'sent-1', 'direction' => 'outgoing', 'status' => 'sent']);
    foreach (['read', 'sent', 'delivered', 'read'] as $status) {
        $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', [
            'typeWebhook' => 'outgoingMessageStatus',
            'instanceData' => ['idInstance' => 7103000000],
            'idMessage' => 'sent-1',
            'chatId' => $conversation->chat_id,
            'status' => $status,
        ])->assertSuccessful();
    }
    expect($message->refresh()->status)->toBe('read')->and(WebhookEvent::count())->toBe(3);
});

it('reconciles a delivery callback that arrives before the send HTTP response', function () {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    Http::fake(function () use ($conversation) {
        $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', [
            'typeWebhook' => 'outgoingMessageStatus', 'instanceData' => ['idInstance' => 7103000000],
            'idMessage' => 'early-status', 'chatId' => $conversation->chat_id, 'status' => 'read',
        ])->assertSuccessful();

        return Http::response(['idMessage' => 'early-status']);
    });
    $this->actingAs($admin, 'user')->postJson(route('admin.green_api.send', $conversation->id, false), [
        'message' => 'Hello', 'client_reference' => (string) Str::uuid(),
    ])->assertSuccessful()->assertJsonPath('status', 'read');
});

it('requires the exact action permission on a direct send', function () {
    $user = greenUser(['green_api', 'green_api.view']);
    $conversation = greenConversation($user->id);
    $this->actingAs($user, 'user')->postJson(route('admin.green_api.send', $conversation->id, false), [
        'message' => 'Do not send', 'client_reference' => (string) Str::uuid(),
    ])->assertUnauthorized();
    Http::assertNothingSent();
});

it('restricts message history and mutations to the users record scope', function () {
    $owner = getDefaultAdmin();
    $user = greenUser(['green_api', 'green_api.view', 'green_api.send', 'green_api.manage']);
    $conversation = greenConversation($owner->id);
    $this->actingAs($user, 'user')->getJson(route('admin.green_api.messages', $conversation->id, false))->assertNotFound();
    $this->patchJson(route('admin.green_api.update', $conversation->id, false), ['mode' => 'human', 'opted_out' => false])->assertNotFound();
    $this->postJson(route('admin.green_api.send', $conversation->id, false), [
        'message' => 'Do not send', 'client_reference' => (string) Str::uuid(),
    ])->assertNotFound();
    Http::assertNothingSent();
});

it('allows an authorized operator to read their own history', function () {
    $user = greenUser(['green_api', 'green_api.view']);
    $conversation = greenConversation($user->id);
    Message::create(['conversation_id' => $conversation->id, 'direction' => 'incoming', 'body' => 'Visible only to owner']);
    $this->actingAs($user, 'user')->getJson(route('admin.green_api.messages', $conversation->id, false))
        ->assertSuccessful()->assertJsonPath('0.body', 'Visible only to owner');
});

it('hides history if an associated contact has moved outside the users scope', function () {
    $user = greenUser(['green_api', 'green_api.view']);
    $other = getDefaultAdmin();
    $person = Person::withoutEvents(fn () => Person::create(['name' => 'Private', 'emails' => [], 'user_id' => $other->id, 'unique_id' => (string) Str::uuid()]));
    $conversation = greenConversation($user->id);
    $conversation->update(['person_id' => $person->id]);
    $this->actingAs($user, 'user')->getJson(route('admin.green_api.messages', $conversation->id, false))->assertNotFound();
});

it('requires login and rejects inactive users', function () {
    $conversation = greenConversation();
    $this->get(route('admin.green_api.show', $conversation->id, false))->assertRedirect(route('admin.session.create'));
    $user = greenUser(['green_api', 'green_api.view'], 'global');
    $user->update(['status' => 0]);
    $this->actingAs($user, 'user')->get(route('admin.green_api.show', $conversation->id, false))->assertRedirect(route('admin.session.create'));
});

it('does not expose provider tokens in failed connection responses', function () {
    $admin = getDefaultAdmin();
    Http::fake(fn () => throw new ConnectionException('contains '.str_repeat('a', 32)));
    $response = $this->actingAs($admin, 'user')->postJson(route('admin.green_api.status', [], false));
    $response->assertStatus(502);
    expect($response->getContent())->not->toContain(str_repeat('a', 32));
});

it('rejects arbitrary API hosts before transmitting credentials', function () {
    config(['green-api.api_url' => 'https://example.invalid']);
    expect(app(GreenApiClient::class)->isConfigured())->toBeFalse();
    expect(fn () => app(GreenApiClient::class)->state())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('keeps unknown statuses from modifying delivery state', function () {
    expect(MessageStatus::advance('read', 'delivered'))->toBe('read')
        ->and(MessageStatus::advance('sent', 'unrecognized'))->toBe('sent');
});

it('renders escaped history and settings without exposing credentials', function () {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    Message::create(['conversation_id' => $conversation->id, 'direction' => 'incoming', 'body' => '<script>alert(1)</script>', 'status' => 'received']);
    $this->actingAs($admin, 'user')->get(route('admin.green_api.show', $conversation->id, false))
        ->assertSuccessful()->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    $this->get(route('admin.green_api.settings', [], false))->assertSuccessful()
        ->assertDontSee(str_repeat('a', 32))->assertDontSee(str_repeat('w', 48));
});

it('keeps stopped conversations blocked after a delayed phone webhook', function (bool $optedOut) {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    $conversation->update(['mode' => 'stopped', 'opted_out' => $optedOut]);

    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', greenPayload('phone-1', 'outgoingMessageReceived'))->assertSuccessful();

    expect($conversation->refresh()->mode)->toBe('stopped')
        ->and($conversation->opted_out)->toBe($optedOut)
        ->and($conversation->messages()->count())->toBe(1);

    $this->actingAs($admin, 'user')->postJson(route('admin.green_api.send', $conversation->id, false), [
        'message' => 'Do not send', 'client_reference' => (string) Str::uuid(),
    ])->assertUnprocessable();
    Http::assertNothingSent();
})->with([false, true]);

it('records provider delivery errors without allowing stale callbacks to hide them', function (string $status) {
    $conversation = greenConversation();
    $message = Message::create(['conversation_id' => $conversation->id, 'external_id' => 'failed-1', 'direction' => 'outgoing', 'status' => 'sent']);
    foreach ([$status, 'sent'] as $callbackStatus) {
        $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', [
            'typeWebhook' => 'outgoingMessageStatus',
            'instanceData' => ['idInstance' => 7103000000],
            'idMessage' => $message->external_id,
            'chatId' => $conversation->chat_id,
            'status' => $callbackStatus,
        ])->assertSuccessful();
    }

    expect($message->refresh()->status)->toBe('failed');
})->with(['failed', 'noAccount', 'suspended', 'yellowCard', 'notInGroup']);

it('preserves confirmed delivery over delayed errors', function (string $status) {
    expect(MessageStatus::advance($status, 'failed'))->toBe($status)
        ->and(MessageStatus::advance('failed', $status))->toBe($status);
})->with(['delivered', 'read']);

it('reconciles a failed callback that arrives before the send HTTP response', function () {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    Http::fake(function () use ($conversation) {
        $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', [
            'typeWebhook' => 'outgoingMessageStatus', 'instanceData' => ['idInstance' => 7103000000],
            'idMessage' => 'early-failure', 'chatId' => $conversation->chat_id, 'status' => 'failed',
        ])->assertSuccessful();

        return Http::response(['idMessage' => 'early-failure']);
    });

    $this->actingAs($admin, 'user')->postJson(route('admin.green_api.send', $conversation->id, false), [
        'message' => 'Hello', 'client_reference' => (string) Str::uuid(),
    ])->assertSuccessful()->assertJsonPath('status', 'failed');
});

it('reconciles a phone message whose delivery status arrived first', function (string $status) {
    $conversation = greenConversation();
    $this->withToken(str_repeat('w', 48))->postJson('/green-api/webhook', [
        'typeWebhook' => 'outgoingMessageStatus', 'instanceData' => ['idInstance' => 7103000000],
        'idMessage' => 'early-phone-status', 'chatId' => $conversation->chat_id, 'status' => $status,
    ])->assertSuccessful();

    $this->postJson('/green-api/webhook', greenPayload('early-phone-status', 'outgoingMessageReceived'))->assertSuccessful();

    expect($conversation->messages()->sole()->status)->toBe($status);
})->with(['read', 'failed']);

it('reconciles a status committed after the worker established its database snapshot', function () {
    $conversation = greenConversation();
    $message = Message::create(['conversation_id' => $conversation->id, 'external_id' => 'concurrent-status', 'direction' => 'outgoing', 'status' => 'sent']);
    WebhookEvent::query()->count();

    config(['database.connections.green_api_concurrent' => config('database.connections.'.config('database.default'))]);
    $connection = DB::connection('green_api_concurrent');
    $eventKey = hash('sha256', (string) Str::uuid());
    $this->beforeApplicationDestroyed(function () use ($connection, $eventKey) {
        $connection->table('green_api_webhook_events')->where('event_key', $eventKey)->delete();
        DB::purge('green_api_concurrent');
    });

    $connection->table('green_api_webhook_events')->insert([
        'event_key' => $eventKey,
        'instance_id' => $conversation->instance_id,
        'external_id' => $message->external_id,
        'type' => 'outgoingMessageStatus',
        'payload' => json_encode(['chatId' => $conversation->chat_id, 'status' => 'read'], JSON_THROW_ON_ERROR),
        'processed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(WebhookProcessor::class)->reconcileStatus($message, $conversation->instance_id, $conversation->chat_id);

    expect($message->status)->toBe('read');
});

it('renders contact phone choices through the package view', function () {
    $this->actingAs(getDefaultAdmin(), 'user');
    $lead = new Lead;
    $lead->id = 123;
    $lead->setRelation('person', new Person([
        'contact_numbers' => [['value' => '+7 (701) 000-11-22', 'label' => 'work'], ['value' => '', 'label' => 'home']],
    ]));

    $html = view('green_api::lead-action', compact('lead'))->render();

    expect($html)->toContain('+7 (701) 000-11-22')
        ->not->toContain('<option value="">');
});

it('forces stopped mode when an operator records an opt out', function () {
    $admin = getDefaultAdmin();
    $conversation = greenConversation($admin->id);
    $this->actingAs($admin, 'user')->patch(route('admin.green_api.update', $conversation->id, false), ['mode' => 'bot', 'opted_out' => true])->assertRedirect();
    expect($conversation->fresh()->mode)->toBe('stopped')->and($conversation->fresh()->opted_out)->toBeTrue();
});
