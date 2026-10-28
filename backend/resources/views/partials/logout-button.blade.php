@auth
<form action="{{ route('logout') }}" method="POST" class="d-inline">
    @csrf
    <button type="submit" class="btn btn-outline-danger btn-sm">
        Keluar (Logout)
    </button>
</form>
@endauth