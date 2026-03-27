<?php

namespace Tests\Unit\Services;

use App\Services\AaPanelService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AaPanelServiceTest extends TestCase
{
    private string $panelUrl = 'https://panel.example.com:8888';

    private string $apiKey = 'test-api-key-123';

    public function test_get_file_content_returns_file_data(): void
    {
        Http::fake([
            'panel.example.com:8888/files' => Http::response([
                'status' => true,
                'data' => '<?php echo "hello";',
            ]),
        ]);

        $service = new AaPanelService($this->panelUrl, $this->apiKey);

        $content = $service->getFileContent('/www/wwwroot/site/index.php');

        $this->assertSame('<?php echo "hello";', $content);
    }

    public function test_get_file_content_sends_correct_params(): void
    {
        Http::fake([
            'panel.example.com:8888/files' => Http::response([
                'status' => true,
                'data' => 'content',
            ]),
        ]);

        $service = new AaPanelService($this->panelUrl, $this->apiKey);
        $service->getFileContent('/www/wwwroot/site/index.php');

        Http::assertSent(fn ($request) => $request['action'] === 'GetFileBody'
            && $request['path'] === '/www/wwwroot/site/index.php'
            && isset($request['request_time'])
            && isset($request['request_token'])
        );
    }

    public function test_get_file_content_throws_on_status_false(): void
    {
        Http::fake([
            'panel.example.com:8888/files' => Http::response([
                'status' => false,
                'msg' => 'File not found',
            ]),
        ]);

        $service = new AaPanelService($this->panelUrl, $this->apiKey);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $service->getFileContent('/www/wwwroot/site/missing.php');
    }

    public function test_save_file_content_sends_correct_params(): void
    {
        Http::fake([
            'panel.example.com:8888/files' => Http::response([
                'status' => true,
                'msg' => 'save_success',
            ]),
        ]);

        $service = new AaPanelService($this->panelUrl, $this->apiKey);
        $service->saveFileContent('/www/wwwroot/site/index.php', '<?php echo "updated";');

        Http::assertSent(fn ($request) => $request['action'] === 'SaveFileBody'
            && $request['path'] === '/www/wwwroot/site/index.php'
            && $request['data'] === '<?php echo "updated";'
            && $request['encoding'] === 'utf-8'
        );
    }

    public function test_save_file_content_throws_on_status_false(): void
    {
        Http::fake([
            'panel.example.com:8888/files' => Http::response([
                'status' => false,
                'msg' => 'Permission denied',
            ]),
        ]);

        $service = new AaPanelService($this->panelUrl, $this->apiKey);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Permission denied');

        $service->saveFileContent('/www/wwwroot/site/index.php', 'content');
    }

    public function test_auth_token_is_md5_of_timestamp_concatenated_with_md5_api_key(): void
    {
        Http::fake([
            'panel.example.com:8888/files' => Http::response([
                'status' => true,
                'data' => 'content',
            ]),
        ]);

        $service = new AaPanelService($this->panelUrl, $this->apiKey);
        $service->getFileContent('/www/wwwroot/site/index.php');

        Http::assertSent(function ($request) {
            $timestamp = $request['request_time'];
            $expectedToken = md5($timestamp.md5($this->apiKey));

            return $request['request_token'] === $expectedToken;
        });
    }
}
