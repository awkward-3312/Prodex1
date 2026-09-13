<?php

declare(strict_types=1);

namespace Tests\Unit\Paddle;

use App\Exceptions\PaddleApiException;
use App\Services\Paddle\PaddleSubscriptionApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PRODEX has zero outbound calls to Paddle's REST API today — cancelling a
 * subscription locally never told Paddle to stop billing. This class is the
 * first real integration point, so every request shape (URL, auth, body) is
 * pinned here rather than trusted by inspection.
 */
class PaddleSubscriptionApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paddle.api_key' => 'test_api_key_123',
            'services.paddle.environment' => 'sandbox',
        ]);
    }

    public function test_cancel_posts_to_sandbox_host_by_default(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'active']], 200)]);

        (new PaddleSubscriptionApi())->cancel('sub_123', 'next_billing_period');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://sandbox-api.paddle.com/subscriptions/sub_123/cancel';
        });
    }

    public function test_cancel_posts_to_live_host_when_environment_is_live(): void
    {
        config(['services.paddle.environment' => 'live']);
        Http::fake(['*' => Http::response(['data' => ['status' => 'active']], 200)]);

        (new PaddleSubscriptionApi())->cancel('sub_123', 'next_billing_period');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.paddle.com/subscriptions/sub_123/cancel';
        });
    }

    public function test_cancel_sends_bearer_token_and_effective_from_body(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'active']], 200)]);

        (new PaddleSubscriptionApi())->cancel('sub_123', 'next_billing_period');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test_api_key_123')
                && $request['effective_from'] === 'next_billing_period';
        });
    }

    public function test_cancel_rejects_an_invalid_effective_from_value_without_calling_paddle(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        (new PaddleSubscriptionApi())->cancel('sub_123', 'whenever_i_feel_like_it');

        Http::assertNothingSent();
    }

    public function test_cancel_throws_paddle_api_exception_on_non_2xx_response(): void
    {
        Http::fake(['*' => Http::response(['error' => ['detail' => 'not found']], 404)]);

        $this->expectException(PaddleApiException::class);

        (new PaddleSubscriptionApi())->cancel('sub_missing', 'immediately');
    }

    public function test_cancel_wraps_a_connection_failure_into_paddle_api_exception(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host.');
        });

        $this->expectException(PaddleApiException::class);

        (new PaddleSubscriptionApi())->cancel('sub_123', 'immediately');
    }

    public function test_cancel_returns_decoded_response_data_on_success(): void
    {
        Http::fake(['*' => Http::response([
            'data' => ['status' => 'active', 'scheduled_change' => ['action' => 'cancel']],
        ], 200)]);

        $result = (new PaddleSubscriptionApi())->cancel('sub_123', 'next_billing_period');

        $this->assertSame('active', $result['status']);
        $this->assertSame('cancel', $result['scheduled_change']['action']);
    }

    public function test_remove_scheduled_cancellation_patches_subscription_with_null_scheduled_change(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'active', 'scheduled_change' => null]], 200)]);

        (new PaddleSubscriptionApi())->removeScheduledCancellation('sub_123');

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && $request->url() === 'https://sandbox-api.paddle.com/subscriptions/sub_123'
                && array_key_exists('scheduled_change', $request->data())
                && $request['scheduled_change'] === null;
        });
    }

    public function test_remove_scheduled_cancellation_throws_paddle_api_exception_on_failure(): void
    {
        Http::fake(['*' => Http::response(['error' => ['detail' => 'gone']], 500)]);

        $this->expectException(PaddleApiException::class);

        (new PaddleSubscriptionApi())->removeScheduledCancellation('sub_123');
    }
}
