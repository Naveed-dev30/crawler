<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\RespondsMobile;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use RespondsMobile;

    /**
     * Mobile users other than the caller — the "assign to" picker list.
     */
    public function index(Request $request)
    {
        $users = User::mobile()
            ->where('id', '!=', $request->user()->id)
            ->orderBy('escalation_ladder')
            ->get(['id', 'name', 'email', 'escalation_ladder']);

        return $this->ok($users, 'Users fetched successfully.');
    }
}
