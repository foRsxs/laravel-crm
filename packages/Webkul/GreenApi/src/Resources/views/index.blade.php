<x-admin::layouts>
    <x-slot:title>@lang('green_api::app.title')</x-slot>
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold">@lang('green_api::app.title')</h1>
        @if (bouncer()->hasPermission('green_api.settings'))
            <a class="secondary-button" href="{{ route('admin.green_api.settings') }}">@lang('green_api::app.settings')</a>
        @endif
    </div>
    @unless (config('green-api.enabled'))
        <p class="mb-4">@lang('green_api::app.disabled')</p>
    @endunless
    <div class="rounded border border-gray-300 dark:border-gray-800 overflow-x-auto">
        <table class="w-full text-left">
            <thead><tr class="border-b">
                <th class="p-3">@lang('green_api::app.contact')</th>
                <th class="p-3">@lang('green_api::app.lead')</th>
                <th class="p-3">@lang('green_api::app.mode')</th>
                <th class="p-3">@lang('green_api::app.updated')</th>
            </tr></thead>
            <tbody>
            @forelse ($conversations as $conversation)
                <tr class="border-b">
                    <td class="p-3">
                        @if (bouncer()->hasPermission('green_api.view'))
                            <a class="text-blue-600" href="{{ route('admin.green_api.show', $conversation->id) }}">{{ $conversation->name ?: $conversation->chat_id }}</a>
                        @else
                            {{ $conversation->name ?: $conversation->chat_id }}
                        @endif
                        <div>{{ $conversation->person?->name }} {{ $conversation->person?->organization?->name }}</div>
                    </td>
                    <td class="p-3">{{ $conversation->lead?->title }}</td>
                    <td class="p-3">{{ trans('green_api::app.modes.'.$conversation->mode) }}</td>
                    <td class="p-3">{{ $conversation->updated_at }}</td>
                </tr>
            @empty
                <tr><td class="p-3" colspan="4">@lang('green_api::app.empty')</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $conversations->links() }}
</x-admin::layouts>
