{{--
    User avatar + name + email cell for the users DataTable.
    $user = App\Models\User instance.
--}}
<div class="d-flex align-items-center">
    <div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
        <div class="symbol-label fs-3 bg-light-primary text-primary fw-bold">
            {{ mb_strtoupper(mb_substr($user->name ?? '', 0, 1)) }}
        </div>
    </div>
    <div class="d-flex flex-column">
        <a href="{{ route('admin.users.view', $user->id) }}"
           class="text-gray-800 text-hover-primary mb-1 fw-bold">
            {{ $user->name }}
        </a>
        <span class="text-muted fs-7">{{ $user->email }}</span>
    </div>
</div>
