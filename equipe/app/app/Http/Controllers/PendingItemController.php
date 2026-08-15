<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\PendingItem;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PendingItemController extends Controller
{
    public function store(Request $request, Admission $admission)
    {
        $this->authorize('update', $admission);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:500'],
        ]);

        $item = $admission->pendingItems()->create([
            'description' => $data['description'],
            'status' => 'OPEN',
            'created_by' => Auth::id(),
        ]);

        AuditLogger::log('CREATE_PENDING_ITEM', 'PendingItem', $item->id);

        return response()->json($item, 201);
    }

    public function resolve(Request $request, PendingItem $pendingItem)
    {
        $this->authorize('update', $pendingItem->admission);

        $data = $request->validate([
            'status' => ['required', 'in:DONE,CANCELLED'],
        ]);

        $pendingItem->update([
            'status' => $data['status'],
            'resolved_by' => Auth::id(),
            'resolved_at' => now(),
        ]);

        AuditLogger::log('RESOLVE_PENDING_ITEM', 'PendingItem', $pendingItem->id);

        return response()->json($pendingItem);
    }
}
