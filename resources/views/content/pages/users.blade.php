@extends('layouts.layoutMaster')

@section('title', 'Users')

@section('page-script')
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const toast = document.getElementById('users-toast');
            if (toast) {
                new bootstrap.Toast(toast, { delay: 3500 }).show();
            }

            @if ($errors->any())
                new bootstrap.Modal(document.getElementById('addUserModal')).show();
            @endif

            // Team users take no part in chat routing — hide mobile-only fields.
            const roleSelect = document.getElementById('user-role');
            const mobileFields = document.getElementById('mobile-only-fields');
            const toggleMobileFields = () => {
                const isMobile = roleSelect.value === 'mobile';
                mobileFields.style.display = isMobile ? '' : 'none';
                mobileFields.querySelectorAll('textarea, select').forEach(el => {
                    el.toggleAttribute('required', isMobile);
                    el.toggleAttribute('disabled', !isMobile);
                });
            };
            roleSelect.addEventListener('change', toggleMobileFields);
            toggleMobileFields();
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
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Users</h5>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="bx bx-plus me-1"></i>Add User
            </button>
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
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No users yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="card-footer d-flex justify-content-end align-items-center gap-3">
                <span class="text-muted">
                    Showing <strong>{{ $users->firstItem() }}</strong> to <strong>{{ $users->lastItem() }}</strong>
                    of <strong>{{ $users->total() }}</strong> results
                </span>
                {{ $users->links('vendor.pagination.bootstrap-5-pager') }}
            </div>
        @endif
    </div>

    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('users.store') }}">
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
                            <select class="form-select @error('role') is-invalid @enderror" id="user-role" name="role" required>
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
                                <select class="form-select @error('escalation_ladder') is-invalid @enderror"
                                        id="user-ladder" name="escalation_ladder" required>
                                    <option value="" disabled {{ old('escalation_ladder') ? '' : 'selected' }}>Choose position…</option>
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
