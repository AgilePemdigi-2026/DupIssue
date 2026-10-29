{{-- The controller also enforces the role and active membership on GET/POST. --}}
@if (auth()->user()?->role === 'REPORTER'
    && $project->memberships()->where('user_id', auth()->id())->where('is_active', true)->exists())
    <a href="{{ route('issues.create', ['project' => $project]) }}" class="btn btn-primary">Submit Issue</a>
@endif
