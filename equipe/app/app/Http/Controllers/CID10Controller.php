<?php

namespace App\Http\Controllers;

use App\Models\CID10;
use Illuminate\Http\Request;

class CID10Controller extends Controller
{
    public function search(Request $request)
    {
        $term = $request->string('q')->toString();

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json(
            CID10::search($term)->orderBy('code')->limit(10)->get(['code', 'description', 'category'])
        );
    }
}
