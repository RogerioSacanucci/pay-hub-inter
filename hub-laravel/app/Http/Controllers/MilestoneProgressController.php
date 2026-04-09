<?php

namespace App\Http\Controllers;

use App\Models\CartpandaOrder;
use App\Models\RevenueMilestone;
use App\Models\UserMilestoneAchievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilestoneProgressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $total = round((float) CartpandaOrder::where('user_id', $userId)
            ->where('status', 'COMPLETED')
            ->sum('amount'), 2);

        $milestones = RevenueMilestone::orderBy('order')->get();

        $achievements = UserMilestoneAchievement::where('user_id', $userId)
            ->get()
            ->keyBy('milestone_id');

        $nextMilestone = null;
        $achieved = [];
        $allMilestones = [];

        foreach ($milestones as $milestone) {
            $isAchieved = $achievements->has($milestone->id);

            if ($isAchieved) {
                $achieved[] = [
                    'id' => $milestone->id,
                    'value' => (float) $milestone->value,
                    'achieved_at' => $achievements[$milestone->id]->achieved_at,
                ];
            }

            $allMilestones[] = [
                'id' => $milestone->id,
                'value' => (float) $milestone->value,
                'achieved' => $isAchieved,
            ];

            if (! $isAchieved && $nextMilestone === null) {
                $progressPct = (float) $milestone->value > 0
                    ? min(100, round($total / (float) $milestone->value * 100, 1))
                    : 100;

                $nextMilestone = [
                    'id' => $milestone->id,
                    'value' => (float) $milestone->value,
                    'progress_pct' => $progressPct,
                ];
            }
        }

        return response()->json([
            'total' => $total,
            'next_milestone' => $nextMilestone,
            'achieved' => $achieved,
            'all_milestones' => $allMilestones,
        ]);
    }
}
