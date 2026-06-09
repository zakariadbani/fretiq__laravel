{{--
    Role badges for the PermissionsDataTable assigned_to column.
    $roles = collection of Role models.
--}}
@if($roles->isEmpty())
    <span class="text-muted">—</span>
@else
    <div class="d-flex flex-wrap gap-1">
        @foreach($roles as $role)
            <span class="badge badge-light-primary">{{ ucfirst($role->name) }}</span>
        @endforeach
    </div>
@endif
