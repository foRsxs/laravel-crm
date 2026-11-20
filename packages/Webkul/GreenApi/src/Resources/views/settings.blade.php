<x-admin::layouts>
    <x-slot:title>@lang('green_api::app.settings')</x-slot>
    <h1 class="text-xl font-bold mb-4">@lang('green_api::app.settings')</h1>
    <p class="mb-4">{{ $configured ? trans('green_api::app.configured') : trans('green_api::app.disabled') }}</p>
    <p class="mb-4">@lang('green_api::app.configure-note')</p>
    <p class="mb-4">Webhook: <code>{{ route('green_api.webhook') }}</code></p>
    <p class="mb-4">@lang('green_api::app.webhook-note')</p>
    <form method="POST" action="{{ route('admin.green_api.status') }}">
        @csrf
        <button class="secondary-button" type="submit">@lang('green_api::app.check-connection')</button>
    </form>
</x-admin::layouts>
