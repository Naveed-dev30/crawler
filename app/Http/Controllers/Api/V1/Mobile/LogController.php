<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    use RespondsMobile;

    public function index(Request $request)
    {
        $logs = ActivityLog::involving($request->user()->id)
            ->with(['fromUser:id,name', 'toUser:id,name'])
            ->orderByDesc('created_at')
            ->paginate(50);

        return $this->okPaginated($logs, $logs->items(), 'Logs fetched successfully.');
    }
}
