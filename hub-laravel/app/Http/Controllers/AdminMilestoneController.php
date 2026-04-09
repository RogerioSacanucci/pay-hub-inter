<?php

namespace App\Http\Controllers;

use App\Models\RevenueMilestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminMilestoneController extends Controller
{
    public function index(): JsonResponse
    {
        $milestones = RevenueMilestone::orderBy('order')->get();

        return response()->json([
            'data' => $milestones,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'numeric', 'min:0'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $milestone = DB::transaction(function () use ($data) {
            if (! isset($data['order'])) {
                $maxOrder = RevenueMilestone::query()->lockForUpdate()->max('order');
                $data['order'] = ($maxOrder ?? 0) + 1;
            }

            return RevenueMilestone::create($data);
        });

        return response()->json(['milestone' => $milestone], 201);
    }

    public function update(Request $request, RevenueMilestone $milestone): JsonResponse
    {
        $data = $request->validate([
            'value' => ['sometimes', 'numeric', 'min:0'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $milestone->update($data);

        return response()->json(['milestone' => $milestone]);
    }

    public function destroy(RevenueMilestone $milestone): JsonResponse
    {
        $milestone->delete();

        return response()->json(['message' => 'Milestone deleted']);
    }
}
