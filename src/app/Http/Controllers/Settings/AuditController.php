<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        return view('settings.audit', [
            'logs' => AuditLog::when($request->query('q'), function ($q, $s) {
                $like = '%'.mb_strtolower($s).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(username) like ?', [$like])
                    ->orWhereRaw('lower(action) like ?', [$like])->orWhereRaw('lower(subject) like ?', [$like]));
            })
                ->latest('created_at')->paginate(50)->withQueryString(),
        ]);
    }
}
