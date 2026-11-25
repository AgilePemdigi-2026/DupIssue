<nav class="article-pagination" aria-label="Navigasi artikel">
    @if ($previousTopic)
        <a class="previous" href="{{ route('documentation', ['topic' => $previousTopic]) }}">
            <small>Previous</small>
            <strong>{{ $topics[$previousTopic]['title'] }}</strong>
        </a>
    @else
        <span></span>
    @endif
    @if ($nextTopic)
        <a class="next" href="{{ route('documentation', ['topic' => $nextTopic]) }}">
            <small>Next</small>
            <strong>{{ $topics[$nextTopic]['title'] }}</strong>
        </a>
    @endif
</nav>
