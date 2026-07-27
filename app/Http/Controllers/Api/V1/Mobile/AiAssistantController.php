<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AiAssistantController extends Controller
{
    use RespondsMobile;

    public function show(Request $request)
    {
        return $this->ok($this->state($request->user()), 'AI assistant state.');
    }

    public function toggle(Request $request)
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);

        $user = $request->user();
        $now = Carbon::now('UTC');
        $user->ai_manual_state = $validated['enabled'];
        $user->ai_manual_until = $user->ai_schedule_enabled
            ? $user->nextBoundaryAfter($now)
            : null;
        $user->save();

        return $this->ok($this->state($user), 'AI assistant updated.');
    }

    public function schedule(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'start' => 'required_if:enabled,true|date_format:H:i',
            'end' => 'required_if:enabled,true|date_format:H:i|different:start',
            'timezone' => 'required_if:enabled,true|timezone',
        ]);

        $user = $request->user();
        $user->ai_schedule_enabled = $validated['enabled'];
        if ($validated['enabled']) {
            $user->ai_window_start = $validated['start'];
            $user->ai_window_end = $validated['end'];
            $user->ai_timezone = $validated['timezone'];
        }
        $user->save();

        return $this->ok($this->state($user), 'AI assistant schedule updated.');
    }

    private function state(User $user): array
    {
        $now = Carbon::now('UTC');
        $overrideLive = $user->ai_manual_state !== null
            && ($user->ai_manual_until === null || $now->lt($user->ai_manual_until));

        return [
            'active_now' => $user->aiActiveNow($now),
            'schedule_enabled' => (bool) $user->ai_schedule_enabled,
            'window' => $user->ai_schedule_enabled ? [
                'start' => $user->ai_window_start ? substr((string) $user->ai_window_start, 0, 5) : null,
                'end' => $user->ai_window_end ? substr((string) $user->ai_window_end, 0, 5) : null,
                'timezone' => $user->ai_timezone,
            ] : null,
            'manual_override' => $overrideLive ? [
                'state' => (bool) $user->ai_manual_state,
                'until' => $user->ai_manual_until?->toIso8601String(),
            ] : null,
        ];
    }
}
