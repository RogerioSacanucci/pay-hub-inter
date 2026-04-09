<?php

namespace App\Http\Controllers;

use App\Models\RevenueMilestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        if (! isset($data['order'])) {
            $data['order'] = (RevenueMilestone::max('order') ?? 0) + 1;
        }

        $milestone = RevenueMilestone::create($data);

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
