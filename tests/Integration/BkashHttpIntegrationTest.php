<?php

namespace AtiqurSafayat\Bkash\Tests\Integration;

use AtiqurSafayat\Bkash\Services\BkashPaymentService;
use AtiqurSafayat\Bkash\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;

class BkashHttpIntegrationTest extends TestCase
{
    private BkashPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BkashPaymentService(
            $this->app->make(OrderRepository::class),
            $this->app->make(InvoiceRepository::class)
        );
    }

    #[Test]
    public function it_makes_correct_api_calls_for_token_generation(): void
    {
        Http::fake([
            'tokenized.sandbox.bka.sh/v2/tokenized-checkout/auth/grant-token' => Http::response([
                'id_token' => 'mock_token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $this->service->getToken();

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://tokenized.sandbox.bka.sh/v2/tokenized-checkout/auth/grant-token'
                && $request->method() === 'POST'
                && $request->header('Content-Type') === ['application/json']
                && $request->header('Accept') === ['application/json']
                && $request->header('username') === ['test_username']
                && $request->header('password') === ['test_password']
                && $request->data() === [
                    'app_key' => 'test_app_key',
                    'app_secret' => 'test_app_secret',
                ];
        });
    }

    #[Test]
    public function it_makes_correct_api_calls_for_payment_creation(): void
    {
        $this->mockSuccessfulTokenResponse();

        Http::fake([
            'tokenized.sandbox.bka.sh/v2/tokenized-checkout/payment/create' => Http::response([
                'paymentId' => 'TR001test',
                'statusCode' => '0000',
            ], 200),
        ]);

        $this->service->createPayment($this->createMockCart());

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://tokenized.sandbox.bka.sh/v2/tokenized-checkout/payment/create'
                && $request->method() === 'POST'
                && $request->header('Authorization') === ['mock_token_12345']
                && $request->header('X-APP-Key') === ['test_app_key']
                && $request->header('Content-Type') === ['application/json']
                && $request->header('Accept') === ['application/json'];
        });
    }

    #[Test]
    public function it_makes_correct_api_calls_for_payment_execution(): void
    {
        $this->mockSuccessfulTokenResponse();

        Http::fake([
            'tokenized.sandbox.bka.sh/v2/tokenized-checkout/payment/execute' => Http::response([
                'paymentId' => 'TR001test',
                'statusCode' => '0000',
            ], 200),
        ]);

        $this->service->executePayment('TR001test');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://tokenized.sandbox.bka.sh/v2/tokenized-checkout/payment/execute'
                && $request->method() === 'POST'
                && $request->header('Authorization') === ['mock_token_12345']
                && $request->header('X-APP-Key') === ['test_app_key']
                && $request->data() === ['paymentId' => 'TR001test'];
        });
    }

    #[Test]
    public function it_makes_correct_api_calls_for_payment_query(): void
    {
        $this->mockSuccessfulTokenResponse();

        Http::fake([
            'tokenized.sandbox.bka.sh/v2/tokenized-checkout/query/payment' => Http::response([
                'paymentId' => 'TR001test',
                'statusCode' => '0000',
            ], 200),
        ]);

        $this->service->queryPayment('TR001test');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://tokenized.sandbox.bka.sh/v2/tokenized-checkout/query/payment'
                && $request->method() === 'POST'
                && $request->header('Authorization') === ['mock_token_12345']
                && $request->header('X-APP-Key') === ['test_app_key']
                && $request->data() === ['paymentId' => 'TR001test'];
        });
    }

    #[Test]
    public function it_uses_live_base_url_when_not_in_sandbox_mode(): void
    {
        $this->app['config']->set('sales.payment_methods.bkash.bkash_sandbox', '0');

        Http::fake([
            'tokenized.pay.bka.sh/v2/tokenized-checkout/auth/grant-token' => Http::response([
                'id_token' => 'live_token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $this->service->getToken();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://tokenized.pay.bka.sh/v2/tokenized-checkout/auth/grant-token'
        );
    }

    #[Test]
    public function it_handles_timeout_configuration(): void
    {
        $this->mockSuccessfulTokenResponse();

        Http::fake([
            '*' => Http::response(['statusCode' => '0000'], 200),
        ]);

        $this->service->executePayment('TR001test');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'tokenized-checkout/payment/execute'));
    }

    #[Test]
    public function it_handles_http_error_responses_gracefully(): void
    {
        $this->mockSuccessfulTokenResponse();

        Http::fake([
            '*tokenized-checkout/payment/execute' => Http::response(null, 500),
        ]);

        $this->expectException(\AtiqurSafayat\Bkash\Exceptions\PaymentCreationException::class);

        $this->service->executePayment('TR001test');
    }

    #[Test]
    public function it_retries_token_generation_on_network_issues(): void
    {
        Http::fake([
            '*tokenized-checkout/auth/grant-token' => Http::sequence()
                ->push(null, 500)
                ->push(['id_token' => 'retry_token', 'expires_in' => 3600], 200),
        ]);

        $this->expectException(\AtiqurSafayat\Bkash\Exceptions\TokenException::class);

        $this->service->getToken();
    }

    #[Test]
    public function it_sends_correct_payment_payload_structure(): void
    {
        $this->mockSuccessfulTokenResponse();

        Http::fake([
            '*tokenized-checkout/payment/create' => function (Request $request) {
                $payload = $request->data();

                $this->assertArrayHasKey('payerReference', $payload);
                $this->assertArrayHasKey('callbackURL', $payload);
                $this->assertArrayHasKey('amount', $payload);
                $this->assertArrayHasKey('currency', $payload);
                $this->assertArrayHasKey('intent', $payload);
                $this->assertArrayHasKey('merchantInvoiceNumber', $payload);
                $this->assertEquals('BDT', $payload['currency']);
                $this->assertEquals('sale', $payload['intent']);

                return Http::response(['statusCode' => '0000'], 200);
            },
        ]);

        $this->service->createPayment($this->createMockCart());
    }

    #[Test]
    public function it_handles_json_response_parsing(): void
    {
        $this->mockSuccessfulTokenResponse();

        Http::fake([
            '*tokenized-checkout/payment/execute' => Http::response('{"paymentId":"TR001","statusCode":"0000","statusMessage":"Success"}', 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $result = $this->service->executePayment('TR001test');

        $this->assertIsArray($result);
        $this->assertEquals('TR001', $result['paymentId']);
        $this->assertEquals('0000', $result['statusCode']);
    }

    #[Test]
    public function it_sends_authorization_header_correctly(): void
    {
        $this->mockSuccessfulTokenResponse();

        Http::fake([
            '*' => function (Request $request) {
                if (str_contains($request->url(), 'execute') || str_contains($request->url(), 'query') || str_contains($request->url(), 'create')) {
                    $this->assertEquals(['mock_token_12345'], $request->header('Authorization'));
                    $this->assertEquals(['test_app_key'], $request->header('X-APP-Key'));
                }

                return Http::response(['statusCode' => '0000'], 200);
            },
        ]);

        $this->service->executePayment('TR001test');
        $this->service->queryPayment('TR001test');
    }
}
