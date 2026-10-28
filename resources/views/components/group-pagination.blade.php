<div class="group-pagination" data-group-pagination>
    <span class="group-page-summary" role="status" tabindex="-1">{{ number_format($paginator->firstItem() ?? 0, 0, ',', ' ') }}-{{ number_format($paginator->lastItem() ?? 0, 0, ',', ' ') }} z {{ number_format($paginator->total(), 0, ',', ' ') }}</span>
    @if($paginator->hasPages())
        <nav class="group-page-links" aria-label="Strony grupy {{ $label }}">
            @if($paginator->onFirstPage())
                <span class="group-page-button" aria-disabled="true"><x-icon name="chevron" :size="16" class="page-previous" /></span>
            @else
                <a class="group-page-button" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Poprzednia strona grupy {{ $label }}"><x-icon name="chevron" :size="16" class="page-previous" /></a>
            @endif
            @foreach($elements as $element)
                @if(is_string($element))
                    <span class="group-page-ellipsis" aria-hidden="true">…</span>
                @else
                    @foreach($element as $page => $url)
                        @if($page === $paginator->currentPage())
                            <span class="group-page-button is-current" aria-current="page" tabindex="-1" aria-label="Strona {{ $page }} grupy {{ $label }}">{{ $page }}</span>
                        @else
                            <a class="group-page-button" href="{{ $url }}" aria-label="Strona {{ $page }} grupy {{ $label }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if($paginator->hasMorePages())
                <a class="group-page-button" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Następna strona grupy {{ $label }}"><x-icon name="chevron" :size="16" class="page-next" /></a>
            @else
                <span class="group-page-button" aria-disabled="true"><x-icon name="chevron" :size="16" class="page-next" /></span>
            @endif
        </nav>
    @endif
</div>
