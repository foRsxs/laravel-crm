<x-admin::layouts>
    <x-slot:title>{{ $conversation->name ?: $conversation->chat_id }}</x-slot>
    <div class="flex justify-between items-center mb-4">
        <div>
            <a href="{{ route('admin.green_api.index') }}">@lang('green_api::app.title')</a>
            <h1 class="text-xl font-bold">{{ $conversation->name ?: $conversation->chat_id }}</h1>
            <p>{{ $conversation->chat_id }} · {{ $conversation->lead?->title }}</p>
        </div>
        <a class="secondary-button" href="{{ route('admin.green_api.show', $conversation->id) }}">@lang('green_api::app.refresh')</a>
    </div>
    @if ($errors->any())
        <div class="mb-4 p-3 border border-red-400" role="alert">{{ $errors->first() }}</div>
    @endif
    @if (session('success'))
        <p class="mb-4" role="status">{{ session('success') }}</p>
    @endif
    @if (bouncer()->hasPermission('green_api.manage'))
        <form method="POST" action="{{ route('admin.green_api.update', $conversation->id) }}" class="flex gap-3 flex-wrap items-center mb-4">
            @csrf @method('PATCH')
            <label>@lang('green_api::app.mode')
                <select name="mode" class="border rounded p-2 bg-white dark:bg-gray-900">
                    @foreach (['human', 'bot', 'paused', 'stopped'] as $mode)
                        <option value="{{ $mode }}" @selected($conversation->mode === $mode)>{{ trans('green_api::app.modes.'.$mode) }}</option>
                    @endforeach
                </select>
            </label>
            <input type="hidden" name="opted_out" value="0">
            <label><input type="checkbox" name="opted_out" value="1" @checked($conversation->opted_out)> @lang('green_api::app.opted-out')</label>
            <button class="secondary-button" type="submit">@lang('green_api::app.save')</button>
        </form>
        <p class="mb-4 text-sm">@lang('green_api::app.bot-note')</p>
    @endif
    @if ($conversation->opted_out || $conversation->mode === 'stopped')
        <p class="mb-4" role="alert">@lang('green_api::app.stopped')</p>
    @endif
    @if (bouncer()->hasPermission('green_api.send') && ! $conversation->opted_out && $conversation->mode !== 'stopped')
        <form method="POST" action="{{ route('admin.green_api.send', $conversation->id) }}" class="mb-6">
            @csrf
            <input type="hidden" name="client_reference" value="{{ Illuminate\Support\Str::uuid() }}">
            <label for="green-api-message">@lang('green_api::app.message')</label>
            <textarea id="green-api-message" name="message" required maxlength="20000" rows="3" class="block w-full border rounded p-3 bg-white dark:bg-gray-900"></textarea>
            <button class="primary-button mt-2" type="submit">@lang('green_api::app.send')</button>
        </form>
    @endif
    <div class="flex flex-col gap-3">
        @foreach ($messages as $message)
            <article class="border rounded p-4 {{ $message->direction === 'outgoing' ? 'border-blue-300' : 'border-gray-300' }}">
                <div class="text-sm mb-2">
                    {{ trans('green_api::app.'.$message->direction) }} · {{ $message->occurred_at }} · {{ trans('green_api::app.statuses.'.$message->status) }}
                </div>
                <p class="whitespace-pre-wrap break-words">{{ $message->body }}</p>
                @if ($message->media_url)
                    <a href="{{ $message->media_url }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" class="text-blue-600">{{ $message->media_name ?: trans('green_api::app.attachment') }}</a>
                @elseif (! $message->body)
                    <p>{{ $message->type }}</p>
                @endif
                @if ($message->status === 'unknown')
                    <p role="alert">@lang('green_api::app.unknown-delivery')</p>
                @endif
            </article>
        @endforeach
    </div>
    {{ $messages->links() }}
</x-admin::layouts>
