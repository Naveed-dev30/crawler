<?php

namespace App\Http\Controllers;

use App\Models\Proposal;

class NotQualifiedController extends Controller
{
    public function index()
    {
        $proposals = Proposal::notQualified()
            ->latest()
            ->paginate(50);

        return view('content.pages.not-qualified', ['proposals' => $proposals]);
    }
}
