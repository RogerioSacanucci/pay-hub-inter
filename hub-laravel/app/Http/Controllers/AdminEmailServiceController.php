<?php

namespace App\Http\Controllers;

use App\Models\EmailServiceInstance;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class AdminEmailServiceController extends Controller
{
    public function logs(Request $request): JsonResponse
    {
        $request->validate([
            'instance_id' => ['sometimes', 'integer', 'exists:email_service_instances,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'email' => ['sometimes', 'string'],
        ]);

        $params = $request->only(['page', 'per_page', 'status', 'date_from', 'date_to', 'email']);

        if ($request->filled('instance_id')) {
            $instance = EmailServiceInstance::findOrFail($request->input('instance_id'));

            $response = Http::withToken($instance->api_key)
                ->timeout(5)
                ->connectTimeout(3)
                ->get($instance->url.'/api.php', [...$params, 'action' => 'email-logs']);

            $response->throw();
            $data = $response->json();

            $data['data'] = collect($data['data'] ?? [])->map(fn (array $item) => [
                ...$item,
                'instance_name' => $instance->name,
            ])->all();

            return response()->json($data);
        }

        return $this->aggregateLogs($params);
    }

    public function stats(Request $request): JsonResponse
    {
        $request->validate([
            'instance_id' => ['sometimes', 'integer', 'exists:email_service_instances,id'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
        ]);

        $params = $request->only(['date_from', 'date_to']);

        if ($request->filled('instance_id')) {
            $instance = EmailServiceInstance::findOrFail($request->input('instance_id'));

            $response = Http::withToken($instance->api_key)
                ->timeout(5)
                ->connectTimeout(3)
                ->get($instance->url.'/api.php', [...$params, 'action' => 'email-stats']);

            $response->throw();

            return response()->json($response->json());
        }

        return $this->aggregateStats($params);
    }

    public function users(Request $request): JsonResponse
    {
        if (! $request->filled('instance_id')) {
            return response()->json(['error' => 'instance_id is required'], 400);
        }

        $request->validate([
            'instance_id' => ['required', 'integer', 'exists:email_service_instances,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string'],
            'email' => ['sometimes', 'string'],
        ]);

        $instance = EmailServiceInstance::findOrFail($request->input('instance_id'));
        $params = $request->only(['page', 'status', 'email']);

        $response = Http::withToken($instance->api_key)
            ->timeout(5)
            ->connectTimeout(3)
            ->get($instance->url.'/api.php', [...$params, 'action' => 'wallet-users']);

        $response->throw();

        return response()->json($response->json());
    }

    private function aggregateLogs(array $params): JsonResponse
    {
        $instances = EmailServiceInstance::where('active', true)->get();

        if ($instances->isEmpty()) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'page' => 1, 'per_page' => 25],
            ]);
        }

        $responses = Http::pool(fn (Pool $pool) => $instances->map(fn (EmailServiceInstance $inst) => $pool->withToken($inst->api_key)
            ->timeout(5)
            ->connectTimeout(3)
            // Always fetch page 1 with a larger limit to allow for some aggregated pagination.
            ->get($inst->url.'/api.php', [...$params, 'action' => 'email-logs', 'per_page' => 100, 'page' => 1])
        )->all());

        $allItems = collect();

        foreach ($responses as $i => $response) {
            if ($response->failed()) {
                continue;
            }

            $data = $response->json();
            $instanceName = $instances[$i]->name;

            $items = collect($data['data'] ?? [])->map(fn (array $item) => [
                ...$item,
                'instance_name' => $instanceName,
            ]);

            $allItems = $allItems->merge($items);
        }

        $sorted = $allItems->sortByDesc('created_at')->values();

        $page = (int) ($params['page'] ?? 1);
        $perPage = 25;
        $paginated = $sorted->forPage($page, $perPage)->values();

        $total = $sorted->count();

        return response()->json([
            'data' => $paginated,
            'meta' => [
                // Report the actual number of items available, not the misleading sum of remote totals.
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ]);
    }

    private function aggregateStats(array $params): JsonResponse
    {
        $instances = EmailServiceInstance::where('active', true)->get();

        if ($instances->isEmpty()) {
            return response()->json([
                'data' => [
                    'total' => 0,
                    'failures' => 0,
                    'corrections' => 0,
                    'success_rate' => 0,
                    'chart' => [],
                ],
            ]);
        }

        $responses = Http::pool(fn (Pool $pool) => $instances->map(fn (EmailServiceInstance $inst) => $pool->withToken($inst->api_key)
            ->timeout(5)
            ->connectTimeout(3)
            ->get($inst->url.'/api.php', [...$params, 'action' => 'email-stats'])
        )->all());

        $total = 0;
        $failures = 0;
        $corrections = 0;
        /** @var Collection<string, array{sent: int, failed: int, corrections: int}> */
        $chartByDate = collect();

        foreach ($responses as $i => $response) {
            if ($response->failed()) {
                continue;
            }

            $data = $response->json('data') ?? $response->json();

            $total += (int) ($data['total'] ?? 0);
            $failures += (int) ($data['failures'] ?? 0);
            $corrections += (int) ($data['corrections'] ?? 0);

            foreach ($data['chart'] ?? [] as $entry) {
                $date = $entry['date'];
                $existing = $chartByDate->get($date, ['sent' => 0, 'failed' => 0, 'corrections' => 0]);

                $chartByDate->put($date, [
                    'sent' => $existing['sent'] + (int) ($entry['sent'] ?? 0),
                    'failed' => $existing['failed'] + (int) ($entry['failed'] ?? 0),
                    'corrections' => $existing['corrections'] + (int) ($entry['corrections'] ?? 0),
                ]);
            }
        }

        $successRate = $total > 0 ? round(($total - $failures) / $total * 100, 2) : 0;

        $chart = $chartByDate->map(fn (array $values, string $date) => [
            'date' => $date,
            ...$values,
        ])->sortKeys()->values()->all();

        return response()->json([
            'data' => [
                'total' => $total,
                'failures' => $failures,
                'corrections' => $corrections,
                'success_rate' => $successRate,
                'chart' => $chart,
            ],
        ]);
    }
}
