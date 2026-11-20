@if (config('green-api.enabled') && bouncer()->hasPermission('green_api.send') && bouncer()->hasPermission('green_api.view'))
    @if ($greenApiPhones->isNotEmpty())
        <form method="POST" action="{{ route('admin.green_api.start', $lead->id) }}" class="flex items-center gap-2">
            @csrf
            <select name="phone" aria-label="{{ trans('green_api::app.phone') }}" class="border rounded bg-white dark:bg-gray-900">
                @foreach ($greenApiPhones as $phone)
                    <option value="{{ $phone }}">{{ $phone }}</option>
                @endforeach
            </select>
            <button class="secondary-button" type="submit">WhatsApp</button>
        </form>
    @endif
@endif
