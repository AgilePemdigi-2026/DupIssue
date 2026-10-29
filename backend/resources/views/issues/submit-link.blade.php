{{-- Include from Reza's projects.show once that view is integrated. --}}
@if (auth()->user()?->role === 'REPORTER'
    && $project->memberships()->where('user_id', auth()->id())->where('is_active', true)->exists())
    <a href="{{ route('issues.create', ['project' => $project]) }}" class="btn btn-primary">Submit Issue</a>
@endif
