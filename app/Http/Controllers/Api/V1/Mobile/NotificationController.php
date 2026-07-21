<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = MobileNotification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($notifications);
    }

    public function markRead(Request $request, MobileNotification $notification)
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 403);

        $notification->read_at = now();
        $notification->save();

        return response()->json(['read' => true]);
    }
}
