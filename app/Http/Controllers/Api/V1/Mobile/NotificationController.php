<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Http\Resources\MobileNotificationResource;
use App\Models\MobileNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use RespondsMobile;

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $notifications = MobileNotification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate(50);

        // The client counted unread over the loaded page only, so the tab and
        // app-icon badges were wrong as soon as there were more than 50 alerts.
        $unreadCount = MobileNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        return $this->okPaginated(
            $notifications,
            MobileNotificationResource::collection($notifications->items()),
            'Notifications fetched successfully.',
            ['unread_count' => $unreadCount],
        );
    }

    public function markRead(Request $request, MobileNotification $notification)
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 403);

        $notification->read_at = now();
        $notification->save();

        return $this->ok(['read' => true], 'Notification marked as read.');
    }
}
