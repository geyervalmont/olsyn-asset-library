@if ($paginator->hasPages())
    <div class="ui-pagination">
        <span>
            {{ __('Showing :from–:to of :total', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
        </span>
        <nav aria-label="{{ __('Pagination') }}">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true">‹</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" wire:navigate>‹</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true">{{ $element }}</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" wire:navigate>{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" wire:navigate>›</a>
            @else
                <span aria-disabled="true">›</span>
            @endif
        </nav>
    </div>
@endif
