<div data-paginated-group="users" data-page-name="{{ $users->getPageName() }}" data-current-page="{{ $users->currentPage() }}" aria-label="Użytkownicy">
<div class="table-scroll">
<table class="simple-table">
<thead>
<tr>
<th>Użytkownik</th>
<th>E-mail</th>
<th>Firmy</th>
<th>Rola globalna</th>
</tr>
</thead>
<tbody>@foreach($users as $user)<tr>
<td><div class="person-cell"><span class="avatar avatar-{{ $user->isAdmin() ? 'blue' : 'purple' }}">{{ $user->initials() }}</span><span>{{ $user->name }}</span></div></td>
<td>{{ $user->email }}</td>
<td>@if($user->isAdmin())<span class="tag tag-purple">Wszystkie firmy</span>@else{{ $user->companies->pluck('name')->implode(', ') ?: 'Brak przypisania' }}@endif</td>
<td>
<form class="role-form" method="post" action="{{ route('admin.users.update', $user) }}">@csrf @method('patch')<select name="role" aria-label="Rola {{ $user->name }}">@foreach(['admin' => 'Administrator', 'manager' => 'Manager', 'user' => 'Użytkownik'] as $role => $label)<option value="{{ $role }}" @selected($role === $user->role)>{{ $label }}</option>@endforeach</select>
<button class="button button-secondary" type="submit">Zapisz</button>
</form>
</td>
</tr>@endforeach</tbody>
</table>
</div>
{{ $users->onEachSide(1)->links('components.group-pagination', ['label' => 'Użytkownicy']) }}
</div>
