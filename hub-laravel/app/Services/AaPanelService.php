<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AaPanelService
{
    public function __construct(
        private string $panelUrl,
        private string $apiKey,
    ) {
        $this->panelUrl = rtrim($panelUrl, '/');
    }

    /**
     * Read file content from the aaPanel server.
     */
    public function getFileContent(string $filePath): string
    {
        $result = $this->makeRequest('/files', [
            'action' => 'GetFileBody',
            'path' => $filePath,
        ]);

        if (($result['status'] ?? false) === false) {
            throw new RuntimeException($result['msg'] ?? 'Failed to read file');
        }

        return $result['data'];
    }

    /**
     * Save content to a file on the aaPanel server.
     */
    public function saveFileContent(string $filePath, string $content): void
    {
        $result = $this->makeRequest('/files', [
            'action' => 'SaveFileBody',
            'path' => $filePath,
            'data' => $content,
            'encoding' => 'utf-8',
        ]);

        if (($result['status'] ?? false) === false) {
            throw new RuntimeException($result['msg'] ?? 'Failed to save file');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRequest(string $path, array $params): array
    {
        $timestamp = time();
        $token = md5($timestamp.md5($this->apiKey));

        $response = Http::timeout(30)
            ->asForm()
            ->post($this->panelUrl.$path, array_merge($params, [
                'request_time' => $timestamp,
                'request_token' => $token,
            ]));

        return $response->json() ?? [];
    }
}
