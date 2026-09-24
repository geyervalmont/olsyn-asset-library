@props(['sessions', 'selected' => null])

@if ($sessions->count() > 1 || ($sessions->isNotEmpty() && $selected === null))
    <label class="ui-consumer-target">
        <span>{{ __('Send to') }}</span>
        <select class="ui-select ui-select--sm" wire:model.live="consumerSessionId" aria-label="{{ __('Connected application') }}">
            <option value="">{{ __('Choose an application…') }}</option>
            @foreach ($sessions as $session)
                <option value="{{ $session->id }}">{{ $session->label() }}</option>
            @endforeach
        </select>
    </label>
@elseif ($selected)
    <span class="ui-consumer__target">{{ $selected->label() }}</span>
@else
    <a href="{{ route('connect') }}" wire:navigate class="ui-consumer__target">{{ __('Connect an application') }}</a>
@endif
