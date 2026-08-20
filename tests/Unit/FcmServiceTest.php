<?php

namespace Tests\Unit;

use App\Services\FcmService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FcmServiceTest extends TestCase
{
    private function serviceWithMockedAuth(MockHandler $handler): FcmService
    {
        $client = new Client(['handler' => HandlerStack::create($handler)]);

        $credentialsPath = tempnam(sys_get_temp_dir(), 'fcm-test-');
        file_put_contents($credentialsPath, '{}');

        config([
            'services.firebase.project_id' => 'test-project',
            'services.firebase.credentials_path' => $credentialsPath,
        ]);

        $service = \Mockery::mock(FcmService::class, [$client])->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('getAccessToken')->andReturn('fake-access-token');

        return $service;
    }

    private function errorResponse(int $status, string $errorCode): Response
    {
        return new Response($status, [], json_encode([
            'error' => [
                'code' => $status,
                'status' => 'ERROR',
                'details' => [
                    [
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => $errorCode,
                    ],
                ],
            ],
        ]));
    }

    public function test_missing_project_id_fails_safely(): void
    {
        config([
            'services.firebase.project_id' => null,
            'services.firebase.credentials_path' => '/tmp/does-not-matter.json',
        ]);

        $service = new FcmService(new Client());
        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => false, 'error_code' => 'CONFIG_MISSING'], $result);
    }

    public function test_missing_credentials_path_fails_safely(): void
    {
        config([
            'services.firebase.project_id' => 'test-project',
            'services.firebase.credentials_path' => null,
        ]);

        $service = new FcmService(new Client());
        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => false, 'error_code' => 'CONFIG_MISSING'], $result);
    }

    public function test_missing_credentials_file_fails_safely(): void
    {
        config([
            'services.firebase.project_id' => 'test-project',
            'services.firebase.credentials_path' => '/tmp/definitely-missing-' . uniqid() . '.json',
        ]);

        $service = new FcmService(new Client());
        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => false, 'error_code' => 'CONFIG_MISSING'], $result);
    }

    public function test_successful_response_maps_to_success_true(): void
    {
        $handler = new MockHandler([new Response(200, [], json_encode(['name' => 'projects/x/messages/1']))]);
        $service = $this->serviceWithMockedAuth($handler);

        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => true, 'error_code' => null], $result);
    }

    public function test_unregistered_error_maps_correctly(): void
    {
        $handler = new MockHandler([$this->errorResponse(404, 'UNREGISTERED')]);
        $service = $this->serviceWithMockedAuth($handler);

        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => false, 'error_code' => 'UNREGISTERED'], $result);
    }

    public function test_invalid_argument_error_maps_correctly(): void
    {
        $handler = new MockHandler([$this->errorResponse(400, 'INVALID_ARGUMENT')]);
        $service = $this->serviceWithMockedAuth($handler);

        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => false, 'error_code' => 'INVALID_ARGUMENT'], $result);
    }

    public function test_sender_id_mismatch_error_maps_correctly(): void
    {
        $handler = new MockHandler([$this->errorResponse(403, 'SENDER_ID_MISMATCH')]);
        $service = $this->serviceWithMockedAuth($handler);

        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => false, 'error_code' => 'SENDER_ID_MISMATCH'], $result);
    }

    public function test_unavailable_error_maps_correctly(): void
    {
        $handler = new MockHandler([$this->errorResponse(503, 'UNAVAILABLE')]);
        $service = $this->serviceWithMockedAuth($handler);

        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => false, 'error_code' => 'UNAVAILABLE'], $result);
    }

    public function test_quota_exceeded_error_maps_correctly(): void
    {
        $handler = new MockHandler([$this->errorResponse(429, 'QUOTA_EXCEEDED')]);
        $service = $this->serviceWithMockedAuth($handler);

        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => false, 'error_code' => 'QUOTA_EXCEEDED'], $result);
    }

    public function test_internal_error_maps_correctly(): void
    {
        $handler = new MockHandler([$this->errorResponse(500, 'INTERNAL')]);
        $service = $this->serviceWithMockedAuth($handler);

        $result = $service->sendToToken('token-abc', 'title', 'body');

        $this->assertSame(['success' => false, 'error_code' => 'INTERNAL'], $result);
    }

    public function test_data_values_are_normalized_to_strings(): void
    {
        $container = [];
        $history = \GuzzleHttp\Middleware::history($container);
        $handler = new MockHandler([new Response(200, [], json_encode(['name' => 'ok']))]);
        $stack = HandlerStack::create($handler);
        $stack->push($history);

        $credentialsPath = tempnam(sys_get_temp_dir(), 'fcm-test-');
        file_put_contents($credentialsPath, '{}');
        config([
            'services.firebase.project_id' => 'test-project',
            'services.firebase.credentials_path' => $credentialsPath,
        ]);

        $client = new Client(['handler' => $stack]);
        $service = \Mockery::mock(FcmService::class, [$client])->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('getAccessToken')->andReturn('fake-access-token');

        $service->sendToToken('token-abc', 'title', 'body', [
            'entity_id' => 42,
            'is_urgent' => true,
            'is_resolved' => false,
            'note' => null,
            'entity_type' => 'sos_request',
        ]);

        $sentBody = json_decode((string) $container[0]['request']->getBody(), true);
        $data = $sentBody['message']['data'];

        $this->assertSame('42', $data['entity_id']);
        $this->assertSame('true', $data['is_urgent']);
        $this->assertSame('false', $data['is_resolved']);
        $this->assertSame('', $data['note']);
        $this->assertSame('sos_request', $data['entity_type']);

        foreach ($data as $value) {
            $this->assertIsString($value);
        }
    }

    public function test_raw_fcm_token_never_appears_in_logged_context(): void
    {
        Log::spy();
        $secretToken = 'super-secret-token-' . uniqid();

        config([
            'services.firebase.project_id' => null,
            'services.firebase.credentials_path' => null,
        ]);

        $service = new FcmService(new Client());
        $service->sendToToken($secretToken, 'title', 'body');

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) use ($secretToken) {
                $this->assertStringNotContainsString($secretToken, $message);
                $this->assertStringNotContainsString($secretToken, json_encode($context));

                return true;
            });
    }
}
