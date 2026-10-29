@if (\Illuminate\Support\Facades\Route::has('projects.show'))
    <a href="{{ route('projects.show', ['id' => $project->id]) }}" class="btn btn-outline-secondary">{{ $label }}</a>
@else
    <a href="{{ route(auth()->user()->role === 'REPORTER' ? 'reporter.projects.index' : 'maintainer.dashboard') }}"
       class="btn btn-outline-secondary">Kembali ke Dashboard</a>
@endif
