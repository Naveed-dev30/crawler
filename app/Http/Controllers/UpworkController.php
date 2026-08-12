<?php

namespace App\Http\Controllers;

use App\Models\UpworkJob;
use Illuminate\Http\Request;

class UpworkController extends Controller
{
    public function index()
    {
        return view('content.pages.upwork');
    }

    public function data(Request $request)
    {
        $jobs = UpworkJob::orderByDesc('posted_at')->orderByDesc('id')->paginate(50);

        $rowsHtml = '';
        foreach ($jobs as $job) {
            $rowsHtml .= view('_partials.upwork-row', ['job' => $job])->render();
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="5" class="text-center text-muted py-4">No Upwork jobs yet.</td></tr>';
        }

        return response()->json([
            'rowsHtml' => $rowsHtml,
            'paginationHtml' => $jobs->links('vendor.pagination.bootstrap-5')->render(),
        ]);
    }
}
