<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SystemController extends Controller
{
    public function integrityCheck()
    {
        Gate::authorize('viewAny', User::class);

        $result = DB::select('PRAGMA integrity_check;');

        return response()->json(['result' => $result]);
    }
}
