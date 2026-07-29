<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'profile_prompt',
        'fcm_token',
        'ai_schedule_enabled',
        'ai_window_start',
        'ai_window_end',
        'ai_timezone',
        'ai_manual_state',
        'ai_manual_until',
    ];

    /**
     * Whether the user has admin (settings) access.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Whether the user is a mobile-app chat user.
     */
    public function isMobile(): bool
    {
        return $this->role === 'mobile';
    }

    public function scopeMobile($query)
    {
        return $query->where('role', 'mobile');
    }

    public function threads()
    {
        return $this->hasMany(Thread::class, 'assigned_user_id');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'fcm_token',
    ];

    /**
     * The storage format for date/time attributes.
     * Explicitly set so datetime casts do not require a live DB connection
     * (avoids getConnection() calls in getDateFormat() during unit tests).
     */
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'ai_schedule_enabled' => 'boolean',
        'ai_manual_state' => 'boolean',
        'ai_manual_until' => 'datetime',
    ];

    private function timezone(): string
    {
        return $this->ai_timezone ?: config('app.timezone', 'UTC');
    }

    public function withinWindow(Carbon $now): bool
    {
        if (! $this->ai_window_start || ! $this->ai_window_end) {
            return false;
        }
        $t = $now->copy()->setTimezone($this->timezone())->format('H:i:s');
        $start = (string) $this->ai_window_start;
        $end = (string) $this->ai_window_end;

        return $start <= $end
            ? ($t >= $start && $t < $end)   // same-day window
            : ($t >= $start || $t < $end);  // overnight wrap
    }

    public function nextBoundaryAfter(Carbon $now): ?Carbon
    {
        if (! $this->ai_schedule_enabled || ! $this->ai_window_start || ! $this->ai_window_end) {
            return null;
        }
        $tz = $this->timezone();
        $local = $now->copy()->setTimezone($tz);

        $edges = [];
        foreach ([-1, 0, 1] as $offset) {
            $day = $local->copy()->addDays($offset)->startOfDay();
            $edges[] = $day->copy()->setTimeFromTimeString((string) $this->ai_window_start);
            $edges[] = $day->copy()->setTimeFromTimeString((string) $this->ai_window_end);
        }
        $future = array_values(array_filter($edges, fn ($e) => $e->gt($local)));
        usort($future, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $future === [] ? null : $future[0]->copy()->setTimezone('UTC');
    }

    public function aiActiveNow(Carbon $now): bool
    {
        if ($this->ai_manual_state !== null
            && ($this->ai_manual_until === null || $now->lt($this->ai_manual_until))) {
            return (bool) $this->ai_manual_state;
        }

        return $this->ai_schedule_enabled && $this->withinWindow($now);
    }
}
