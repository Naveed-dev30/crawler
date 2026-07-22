<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $role = $request->query('role', '');

        $users = User::query()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(in_array($role, ['admin', 'team', 'mobile'], true), fn ($q) => $q->where('role', $role))
            ->orderByRaw("role = 'mobile'")
            ->orderBy('escalation_ladder')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $usedLadders = User::whereNotNull('escalation_ladder')
            ->pluck('escalation_ladder')
            ->map(fn ($l) => (int) $l)
            ->all();

        $availableLadders = array_values(array_diff(range(1, 10), $usedLadders));

        return view('content.pages.users', [
            'users' => $users,
            'availableLadders' => $availableLadders,
        ]);
    }

    public function store(Request $request)
    {
        // Admins can only be seeded manually — never created from this form.
        $request->merge(['role' => $request->input('role', 'mobile')]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:mobile,team'],
            'profile_prompt' => ['required_if:role,mobile', 'nullable', 'string'],
            'escalation_ladder' => [
                'required_if:role,mobile', 'nullable', 'integer', 'between:1,10',
                'unique:users,escalation_ladder',
            ],
        ]);

        $role = $validated['role'] ?? 'mobile';

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $role,
            // Team users take no part in chat routing/escalation.
            'profile_prompt' => $role === 'mobile' ? ($validated['profile_prompt'] ?? null) : null,
            'escalation_ladder' => $role === 'mobile' ? ($validated['escalation_ladder'] ?? null) : null,
        ]);

        return redirect()->route('users')->with('status', 'User created.');
    }

    public function update(Request $request, User $user)
    {
        // Admin accounts: role and routing fields stay untouched — only identity/password.
        if ($user->role === 'admin') {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'password' => ['nullable', 'string', 'min:8'],
            ]);
        } else {
            $request->merge(['role' => $request->input('role', $user->role)]);

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'password' => ['nullable', 'string', 'min:8'],
                'role' => ['required', 'in:mobile,team'],
                'profile_prompt' => ['required_if:role,mobile', 'nullable', 'string'],
                'escalation_ladder' => [
                    'required_if:role,mobile', 'nullable', 'integer', 'between:1,10',
                    Rule::unique('users', 'escalation_ladder')->ignore($user->id),
                ],
            ]);

            $role = $validated['role'];
            $user->role = $role;
            // Team users take no part in chat routing/escalation.
            $user->profile_prompt = $role === 'mobile' ? ($validated['profile_prompt'] ?? null) : null;
            $user->escalation_ladder = $role === 'mobile' ? ($validated['escalation_ladder'] ?? null) : null;
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        $user->save();

        return redirect()->route('users')->with('status', 'User updated.');
    }
}
