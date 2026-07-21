<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserManagementController extends Controller
{
    public function index()
    {
        $users = User::orderByRaw("role = 'mobile'")
            ->orderBy('escalation_ladder')
            ->orderBy('name')
            ->get();

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
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'profile_prompt' => ['required', 'string'],
            'escalation_ladder' => ['required', 'integer', 'between:1,10', 'unique:users,escalation_ladder'],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'profile_prompt' => $validated['profile_prompt'],
            'escalation_ladder' => $validated['escalation_ladder'],
            'role' => 'mobile',
        ]);

        return redirect()->route('users')->with('status', 'User created.');
    }
}
