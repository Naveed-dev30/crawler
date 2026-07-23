<?php

use App\Models\Thread;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Per-user feed: assignments, escalations. Admins may listen to anyone's.
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id || $user->role === 'admin';
}, ['guards' => ['web', 'sanctum']]);

// Live conversation: only the thread's assigned user or an admin.
Broadcast::channel('thread.{threadId}', function ($user, $threadId) {
    if ($user->role === 'admin') {
        return true;
    }

    return Thread::where('id', $threadId)
        ->where('assigned_user_id', $user->id)
        ->exists();
}, ['guards' => ['web', 'sanctum']]);
