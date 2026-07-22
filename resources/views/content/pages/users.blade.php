@extends('layouts.layoutMaster')

@section('title', 'Users')

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.css') }}"/>
@endsection

@section('vendor-script')
    <script src="{{ asset('assets/vendor/libs/bootstrap-select/bootstrap-select.js') }}"></script>
@endsection

@section('page-script')
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const toast = document.getElementById('users-toast');
            if (toast) {
                new bootstrap.Toast(toast, { delay: 3500 }).show();
            }

            const usersBase = @json(url('/users'));
            const availableLadders = @json($availableLadders);

            // ---- View user modal ----
            const roleBadges = {
                admin: ['bg-label-primary', 'Admin'],
                mobile: ['bg-label-info', 'Mobile'],
                team: ['bg-label-secondary', 'Team'],
            };
            document.querySelectorAll('.js-view-user').forEach(btn => btn.addEventListener('click', () => {
                const set = (id, v) => document.getElementById(id).textContent = v || '—';
                const role = btn.dataset.role || '';
                const isMobile = role === 'mobile';
                const name = btn.dataset.name || '';

                document.getElementById('view-user-avatar').textContent = name
                    .split(/\s+/).slice(0, 2).map(w => w.charAt(0).toUpperCase()).join('');
                set('view-user-name', name);
                set('view-user-email', btn.dataset.email);

                const badge = document.getElementById('view-user-role-badge');
                const [badgeClass, badgeText] = roleBadges[role] || ['bg-label-secondary', role];
                badge.className = 'badge ' + badgeClass;
                badge.textContent = badgeText;

                // Routing + push fields only apply to mobile users. d-none class,
                // not inline display — rows are flex via utility classes below.
                ['view-row-fcm', 'view-row-ladder', 'view-row-prompt'].forEach(id => {
                    document.getElementById(id).classList.toggle('d-none', !isMobile);
                });
                document.getElementById('view-row-fcm').classList.toggle('d-flex', isMobile);
                document.getElementById('view-row-ladder').classList.toggle('d-flex', isMobile);
                if (isMobile) {
                    set('view-user-ladder', btn.dataset.ladder);
                    set('view-user-prompt', btn.dataset.prompt);
                    const fcm = document.getElementById('view-user-fcm');
                    fcm.className = 'badge ' + (btn.dataset.fcm ? 'bg-label-success' : 'bg-label-secondary');
                    fcm.textContent = btn.dataset.fcm ? 'Registered' : 'Not registered';
                }

                set('view-user-created', btn.dataset.created);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('viewUserModal')).show();
            }));

            // ---- Update user modal ----
            const editForm = document.getElementById('edit-user-form');
            const editRoleSelect = document.getElementById('edit-user-role');

            const buildLadderOptions = (current) => {
                const sel = document.getElementById('edit-user-ladder');
                const opts = [...availableLadders];
                const cur = current ? Number(current) : null;
                if (cur && !opts.includes(cur)) opts.push(cur);
                opts.sort((a, b) => a - b);
                sel.innerHTML = '<option value="">Choose position…</option>'
                    + opts.map(l => `<option value="${l}">${l}</option>`).join('');
                sel.value = cur ?? '';
            };

            const toggleEditMobileFields = () => {
                const isAdmin = editForm.dataset.role === 'admin';
                const isMobile = !isAdmin && editRoleSelect.value === 'mobile';
                document.getElementById('edit-role-field').style.display = isAdmin ? 'none' : '';
                document.getElementById('edit-mobile-only-fields').style.display = isMobile ? '' : 'none';
            };

            const openEdit = (btn) => {
                editForm.action = usersBase + '/' + btn.dataset.id;
                editForm.dataset.role = btn.dataset.role;
                document.getElementById('edit-user-id').value = btn.dataset.id;
                document.getElementById('edit-user-name').value = btn.dataset.name || '';
                document.getElementById('edit-user-email').value = btn.dataset.email || '';
                document.getElementById('edit-user-password').value = '';
                if (btn.dataset.role !== 'admin') {
                    editRoleSelect.value = btn.dataset.role;
                    document.getElementById('edit-user-profile-prompt').value = btn.dataset.prompt || '';
                    buildLadderOptions(btn.dataset.ladder);
                }
                toggleEditMobileFields();
                [...editForm.querySelectorAll('.is-invalid')].forEach(clearFieldError);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
            };

            document.querySelectorAll('.js-edit-user').forEach(btn => btn.addEventListener('click', () => openEdit(btn)));
            editRoleSelect.addEventListener('change', toggleEditMobileFields);

            editForm.addEventListener('submit', function (ev) {
                const name = document.getElementById('edit-user-name');
                const email = document.getElementById('edit-user-email');
                const password = document.getElementById('edit-user-password');
                const prompt = document.getElementById('edit-user-profile-prompt');
                const ladder = document.getElementById('edit-user-ladder');
                const isAdmin = editForm.dataset.role === 'admin';
                const isMobile = !isAdmin && editRoleSelect.value === 'mobile';

                [name, email, password, prompt, ladder].forEach(clearFieldError);

                const errors = [];
                if (!name.value.trim()) errors.push([name, 'Full name is required.']);
                if (!email.value.trim()) errors.push([email, 'Email is required.']);
                else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) errors.push([email, 'Enter a valid email address.']);
                if (password.value && password.value.length < 8) errors.push([password, 'Password must be at least 8 characters.']);
                if (isMobile && !prompt.value.trim()) errors.push([prompt, 'Profile prompt is required for mobile users.']);
                if (isMobile && !ladder.value) errors.push([ladder, 'Choose an escalation ladder position.']);

                if (errors.length) {
                    ev.preventDefault();
                    errors.forEach(([el, msg]) => setFieldError(el, msg));
                    errors[0][0].focus();
                }
            });
            editForm.addEventListener('input', ev => clearFieldError(ev.target));
            editForm.addEventListener('change', ev => clearFieldError(ev.target));

            // Team users take no part in chat routing — hide mobile-only fields.
            const roleSelect = document.getElementById('user-role');
            const mobileFields = document.getElementById('mobile-only-fields');
            const toggleMobileFields = () => {
                const isMobile = roleSelect.value === 'mobile';
                mobileFields.style.display = isMobile ? '' : 'none';
                // Only visibility + required. No disabled toggle / selectpicker
                // refresh — refresh on a hidden select duplicates its options,
                // and the server already nulls these fields for team users.
                mobileFields.querySelectorAll('textarea, select').forEach(el => {
                    el.toggleAttribute('required', isMobile);
                });
                if (!isMobile) {
                    document.getElementById('user-profile-prompt').value = '';
                    if (window.jQuery && jQuery.fn.selectpicker) {
                        jQuery('#user-ladder').selectpicker('val', '');
                    } else {
                        document.getElementById('user-ladder').value = '';
                    }
                }
                if (!isMobile) {
                    clearFieldError(document.getElementById('user-profile-prompt'));
                    clearFieldError(document.getElementById('user-ladder'));
                }
            };

            // Inline validation below each field instead of the browser bubbles.
            const setFieldError = (el, message) => {
                el.classList.add('is-invalid');
                let target = el.closest('.mb-3');
                let feedback = target.querySelector('.js-feedback');
                if (!feedback) {
                    feedback = document.createElement('div');
                    feedback.className = 'invalid-feedback d-block js-feedback';
                    target.appendChild(feedback);
                }
                feedback.textContent = message;
            };
            const clearFieldError = (el) => {
                el.classList.remove('is-invalid');
                const feedback = el.closest('.mb-3')?.querySelector('.js-feedback');
                if (feedback) feedback.remove();
            };

            const addUserForm = document.getElementById('add-user-form');
            addUserForm.addEventListener('submit', function (ev) {
                const name = document.getElementById('user-name');
                const email = document.getElementById('user-email');
                const password = document.getElementById('user-password');
                const prompt = document.getElementById('user-profile-prompt');
                const ladder = document.getElementById('user-ladder');
                const isMobile = roleSelect.value === 'mobile';

                [name, email, password, prompt, ladder].forEach(clearFieldError);

                const errors = [];
                if (!name.value.trim()) errors.push([name, 'Full name is required.']);
                if (!email.value.trim()) errors.push([email, 'Email is required.']);
                else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) errors.push([email, 'Enter a valid email address.']);
                if (!password.value) errors.push([password, 'Password is required.']);
                else if (password.value.length < 8) errors.push([password, 'Password must be at least 8 characters.']);
                if (isMobile && !prompt.value.trim()) errors.push([prompt, 'Profile prompt is required for mobile users.']);
                if (isMobile && !ladder.value) errors.push([ladder, 'Choose an escalation ladder position.']);

                if (errors.length) {
                    ev.preventDefault();
                    errors.forEach(([el, msg]) => setFieldError(el, msg));
                    errors[0][0].focus();
                }
            });

            // Clear a field's error as soon as the user fixes it.
            addUserForm.addEventListener('input', ev => clearFieldError(ev.target));
            addUserForm.addEventListener('change', ev => clearFieldError(ev.target));
            roleSelect.addEventListener('change', toggleMobileFields);
            toggleMobileFields();

            // Debounced search: submit the filter form 400ms after typing stops.
            const searchInput = document.getElementById('users-search');
            let searchTimer;
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => document.getElementById('users-filter-form').submit(), 400);
            });

            @if ($errors->any())
                @if (old('edit_user_id'))
                    // Server rejected an update — reopen the edit modal with the attempted values.
                    const failedBtn = document.querySelector('.js-edit-user[data-id="{{ old('edit_user_id') }}"]');
                    if (failedBtn) {
                        openEdit(failedBtn);
                        document.getElementById('edit-user-name').value = @json(old('name'));
                        document.getElementById('edit-user-email').value = @json(old('email'));
                        @if (old('role'))
                            editRoleSelect.value = @json(old('role'));
                        @endif
                        document.getElementById('edit-user-profile-prompt').value = @json(old('profile_prompt') ?? '');
                        buildLadderOptions(@json(old('escalation_ladder')));
                        toggleEditMobileFields();
                    }
                @else
                    new bootstrap.Modal(document.getElementById('addUserModal')).show();
                @endif
            @endif
        });
    </script>
@endsection

@section('content')
    @if (session('status'))
        <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090;">
            <div class="toast bg-white border-0 shadow-lg rounded-3 overflow-hidden" id="users-toast"
                 role="alert" aria-live="assertive" aria-atomic="true"
                 style="border-left: 4px solid #28c76f !important; min-width: 320px;">
                <div class="d-flex align-items-center p-3">
                    <span class="badge bg-label-success rounded-circle p-2 me-3 lh-1">
                        <i class="bx bx-check bx-sm"></i>
                    </span>
                    <div class="me-3">
                        <div class="fw-semibold text-body">Saved</div>
                        <small class="text-muted">{{ session('status') }}</small>
                    </div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <h5 class="mb-0">Users</h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form method="GET" action="{{ route('users') }}" id="users-filter-form"
                      class="d-flex align-items-center gap-2">
                    <input type="search" class="form-control" name="search" id="users-search"
                           placeholder="Search name or email…" value="{{ request('search') }}"
                           style="min-width: 220px;">
                    <select class="selectpicker" data-style="btn-default" data-width="140px"
                            name="role" onchange="this.form.submit()">
                        <option value="">All roles</option>
                        <option value="admin"@selected(request('role') === 'admin')>Admin</option>
                        <option value="team"@selected(request('role') === 'team')>Team</option>
                        <option value="mobile"@selected(request('role') === 'mobile')>Mobile</option>
                    </select>
                </form>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bx bx-plus me-1"></i>Add User
                </button>
            </div>
        </div>
        <div class="table-responsive text-nowrap">
            <table class="table table-hover">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Escalation Ladder</th>
                    <th>FCM</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody class="table-border-bottom-0">
                @forelse ($users as $user)
                    <tr>
                        <td class="fw-semibold">{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>
                            @if ($user->role === 'admin')
                                <span class="badge bg-label-primary">Admin</span>
                            @elseif ($user->role === 'mobile')
                                <span class="badge bg-label-info">Mobile</span>
                            @else
                                <span class="badge bg-label-secondary">{{ ucfirst($user->role) }}</span>
                            @endif
                        </td>
                        <td>{{ $user->escalation_ladder ?? '—' }}</td>
                        <td>
                            @if ($user->fcm_token)
                                <span class="badge bg-label-success">Registered</span>
                            @else
                                <span class="badge bg-label-secondary">—</span>
                            @endif
                        </td>
                        <td>{{ $user->created_at?->format('M j, Y') }}</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-icon btn-outline-secondary js-view-user"
                                    title="View user"
                                    data-name="{{ $user->name }}" data-email="{{ $user->email }}"
                                    data-role="{{ $user->role }}" data-ladder="{{ $user->escalation_ladder }}"
                                    data-prompt="{{ $user->profile_prompt }}"
                                    data-fcm="{{ $user->fcm_token ? '1' : '' }}"
                                    data-created="{{ $user->created_at?->format('M j, Y') }}">
                                <i class="bx bx-show"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-icon btn-outline-primary js-edit-user"
                                    title="Update user"
                                    data-id="{{ $user->id }}" data-name="{{ $user->name }}"
                                    data-email="{{ $user->email }}" data-role="{{ $user->role }}"
                                    data-ladder="{{ $user->escalation_ladder }}"
                                    data-prompt="{{ $user->profile_prompt }}">
                                <i class="bx bx-edit-alt"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No users yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($users->hasPages())
        <div class="mt-4 card px-4 pt-3">
            {{ $users->links('vendor.pagination.bootstrap-5') }}
        </div>
    @endif

    {{-- View user modal --}}
    <div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-0 pb-4 px-4">
                    <div class="text-center mb-4">
                        <div class="avatar avatar-lg mx-auto mb-3">
                            <span class="avatar-initial rounded-circle bg-label-primary fs-4" id="view-user-avatar"></span>
                        </div>
                        <h5 class="mb-1" id="view-user-name"></h5>
                        <p class="text-muted mb-2" id="view-user-email"></p>
                        <span class="badge" id="view-user-role-badge"></span>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item justify-content-between align-items-center px-0 d-none" id="view-row-fcm">
                            <span class="text-muted">Push Notifications</span>
                            <span id="view-user-fcm"></span>
                        </li>
                        <li class="list-group-item justify-content-between align-items-center px-0 d-none" id="view-row-ladder">
                            <span class="text-muted">Escalation Ladder</span>
                            <span class="fw-semibold" id="view-user-ladder"></span>
                        </li>
                        <li class="list-group-item px-0 d-none" id="view-row-prompt">
                            <div class="text-muted mb-1">Profile Prompt</div>
                            <div class="bg-lighter rounded p-3 small" id="view-user-prompt" style="white-space: pre-wrap;"></div>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="text-muted">Member Since</span>
                            <span id="view-user-created"></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Update user modal --}}
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="" id="edit-user-form" novalidate>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="edit_user_id" id="edit-user-id" value="{{ old('edit_user_id') }}">
                    <div class="modal-header">
                        <h5 class="modal-title">Update User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if ($errors->any() && old('edit_user_id'))
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <div class="mb-3">
                            <label class="form-label" for="edit-user-name">Full Name</label>
                            <input type="text" class="form-control" id="edit-user-name" name="name"
                                   value="{{ old('edit_user_id') ? old('name') : '' }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="edit-user-email">Email</label>
                            <input type="email" class="form-control" id="edit-user-email" name="email"
                                   value="{{ old('edit_user_id') ? old('email') : '' }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="edit-user-password">New Password</label>
                            <input type="password" class="form-control" id="edit-user-password" name="password"
                                   minlength="8" autocomplete="new-password">
                            <div class="form-text">Leave blank to keep the current password.</div>
                        </div>
                        <div class="mb-3" id="edit-role-field">
                            <label class="form-label" for="edit-user-role">Role</label>
                            <select class="form-select" id="edit-user-role" name="role">
                                <option value="mobile"@selected(old('role', 'mobile') === 'mobile')>Mobile</option>
                                <option value="team"@selected(old('role') === 'team')>Team</option>
                            </select>
                        </div>
                        <div id="edit-mobile-only-fields">
                            <div class="mb-3">
                                <label class="form-label" for="edit-user-profile-prompt">Profile Prompt</label>
                                <textarea class="form-control" id="edit-user-profile-prompt" name="profile_prompt"
                                          rows="4">{{ old('edit_user_id') ? old('profile_prompt') : '' }}</textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit-user-ladder">Escalation Ladder</label>
                                <select class="form-select" id="edit-user-ladder" name="escalation_ladder"></select>
                                <div class="form-text">Unanswered threads escalate to the next higher number. 1 is the first responder.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('users.store') }}" id="add-user-form" novalidate>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Add Mobile User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="user-name">Full Name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="user-name" name="name" value="{{ old('name') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="user-email">Email</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror"
                                   id="user-email" name="email" value="{{ old('email') }}" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="user-password">Password</label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                   id="user-password" name="password" required minlength="8">
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="user-role">Role</label>
                            <select class="selectpicker w-100 @error('role') is-invalid @enderror"
                                    data-style="btn-default" id="user-role" name="role" required>
                                <option value="mobile"@selected(old('role', 'mobile') === 'mobile')>Mobile</option>
                                <option value="team"@selected(old('role') === 'team')>Team</option>
                            </select>
                            @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div id="mobile-only-fields">
                            <div class="mb-3">
                                <label class="form-label" for="user-profile-prompt">Profile Prompt</label>
                                <textarea class="form-control @error('profile_prompt') is-invalid @enderror"
                                          id="user-profile-prompt" name="profile_prompt" rows="4"
                                          placeholder="Describe this user's skills and specialties — used by AI to route matching project threads to them"
                                          required>{{ old('profile_prompt') }}</textarea>
                                @error('profile_prompt')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="user-ladder">Escalation Ladder</label>
                                <select class="selectpicker w-100 @error('escalation_ladder') is-invalid @enderror"
                                        data-style="btn-default" title="Choose position…"
                                        id="user-ladder" name="escalation_ladder" required>
                                    @foreach ($availableLadders as $ladder)
                                        <option value="{{ $ladder }}"@selected((int) old('escalation_ladder') === $ladder)>{{ $ladder }}</option>
                                    @endforeach
                                </select>
                                @error('escalation_ladder')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Unanswered threads escalate to the next higher number. 1 is the first responder.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
