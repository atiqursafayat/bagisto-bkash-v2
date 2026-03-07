<?php

namespace AtiqurSafayat\Bkash\Services;

use AtiqurSafayat\Bkash\Exceptions\ConfigurationException;
use AtiqurSafayat\Bkash\Exceptions\PaymentCreationException;
use AtiqurSafayat\Bkash\Exceptions\TokenException;
use AtiqurSafayat\Bkash\Models\BkashPayment;
use AtiqurSafayat\Bkash\PaymentStatus;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Checkout\Facades\Cart;
use Webkul\Sales\Models\OrderPayment;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Transformers\OrderResource;

class BkashPaymentService
{
    private const TOKEN_CACHE_KEY = 'bkash_token';

    private const REFRESH_TOKEN_CACHE_KEY = 'bkash_refresh_token';

    /**
     * Create a new service instance.
     */
    public function __construct(
        protected OrderRepository $orderRepository,
        protected InvoiceRepository $invoiceRepository
    ) {}

    /**
     * Get bKash API credentials from configuration.
     *
     * @throws ConfigurationException
     */
    public function getCredentials(): array
    {
        $sandbox = core()->getConfigData('sales.payment_methods.bkash.bkash_sandbox');

        $credentials = [
            'username' => core()->getConfigData('sales.payment_methods.bkash.bkash_username'),
            'password' => core()->getConfigData('sales.payment_methods.bkash.bkash_password'),
            'app_key' => core()->getConfigData('sales.payment_methods.bkash.bkash_app_key'),
            'app_secret' => core()->getConfigData('sales.payment_methods.bkash.bkash_app_secret'),
            'base_url' => $sandbox === '1'
                ? core()->getConfigData('sales.payment_methods.bkash.sandbox_base_url')
                : core()->getConfigData('sales.payment_methods.bkash.live_base_url'),
            'sandbox' => $sandbox === '1' || $sandbox === true,
        ];

        $this->validateCredentials($credentials);

        return $credentials;
    }

    /**
     * Validate that all required credentials are present.
     */
    private function validateCredentials(array $credentials): void
    {
        $requiredKeys = ['username', 'password', 'app_key', 'app_secret', 'base_url'];

        foreach ($requiredKeys as $key) {
            if (empty($credentials[$key])) {
                throw new ConfigurationException("Missing bkash configuration: {$key}");
            }
        }
    }

    /**
     * Get authorization token from bKash API (with caching).
     *
     * @throws TokenException
     */
    public function getToken(): string
    {
        if (cache()->has(self::TOKEN_CACHE_KEY)) {
            return cache()->get(self::TOKEN_CACHE_KEY);
        }

        $refreshToken = cache()->get(self::REFRESH_TOKEN_CACHE_KEY);

        if (! empty($refreshToken)) {
            try {
                return $this->refreshAndCacheToken($refreshToken);
            } catch (\Throwable $e) {
                Log::warning('bKash refresh token failed, falling back to grant token', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->fetchAndCacheToken();
    }

    /**
     * Fetch a new token from bKash and cache both access + refresh tokens.
     */
    private function fetchAndCacheToken(): string
    {
        $credentials = $this->getCredentials();
        $response = Http::bkash()
            ->timeout(30)
            ->withHeaders([
                'username' => $credentials['username'],
                'password' => $credentials['password'],
            ])
            ->post('/tokenized-checkout/auth/grant-token', [
                'app_key' => $credentials['app_key'],
                'app_secret' => $credentials['app_secret'],
            ]);

        return $this->cacheTokenFromResponse($response, 'Failed to get bkash token');
    }

    /**
     * Refresh an existing token and cache the new values.
     */
    private function refreshAndCacheToken(string $refreshToken): string
    {
        $credentials = $this->getCredentials();
        $response = Http::bkash()
            ->timeout(30)
            ->withHeaders([
                'username' => $credentials['username'],
                'password' => $credentials['password'],
            ])
            ->post('/tokenized-checkout/auth/refresh-token', [
                'app_key' => $credentials['app_key'],
                'app_secret' => $credentials['app_secret'],
                'refresh_token' => $refreshToken,
            ]);

        return $this->cacheTokenFromResponse($response, 'Failed to refresh bkash token');
    }

    /**
     * Validate token response and cache token values.
     */
    private function cacheTokenFromResponse(Response $response, string $prefix): string
    {
        $data = $response->json() ?? [];

        if (! $response->successful()) {
            throw new TokenException($prefix.': '.$this->extractApiErrorMessage($data, $response->status()));
        }

        $token = $data['id_token'] ?? $data['token'] ?? $data['access_token'] ?? null;

        if (empty($token)) {
            throw new TokenException('Token not found in bkash response');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $ttl = max(1, $expiresIn - 60);

        cache()->put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($ttl));

        if (! empty($data['refresh_token'])) {
            cache()->put(self::REFRESH_TOKEN_CACHE_KEY, $data['refresh_token'], now()->addDays(29));
        }

        return $token;
    }

    /**
     * Create a new bKash payment.
     */
    public function createPayment($cart): array
    {
        try {
            $credentials = $this->getCredentials();
            $token = $this->getToken();

            $payload = $this->buildPaymentPayload($cart);
            $paymentData = $this->sendPaymentRequest($token, $credentials['app_key'], $payload);

            $this->savePaymentRecord($paymentData, $token, $payload, $cart->id);

            return $paymentData;
        } catch (PaymentCreationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('bkash Create Payment Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new PaymentCreationException('Failed to create bkash payment: '.$e->getMessage());
        }
    }

    /**
     * Build the payment request payload.
     */
    private function buildPaymentPayload($cart): array
    {
        $callbackUrl = rtrim((string) config('app.url'), '/').'/bkash/callback';
        $mode = (string) (core()->getConfigData('sales.payment_methods.bkash.bkash_mode') ?: '0011');

        return [
            'mode' => $mode,
            'payerReference' => $this->resolvePayerReference($cart),
            'callbackURL' => $callbackUrl,
            'amount' => number_format((float) $cart->grand_total, 2, '.', ''),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => 'INV'.$cart->id,
        ];
    }

    /**
     * Resolve payer reference for bKash checkout.
     * bKash expects a payer identifier (commonly MSISDN), not email.
     */
    private function resolvePayerReference($cart): string
    {
        $candidates = [
            data_get($cart, 'billing_address.phone'),
            data_get($cart, 'billing_address.telephone'),
            data_get($cart, 'billing_address.mobile'),
            data_get($cart, 'customer.phone'),
            data_get($cart, 'customer_phone'),
            data_get($cart, 'customer_email'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate) || trim((string) $candidate) === '') {
                continue;
            }

            $raw = trim((string) $candidate);
            $digits = preg_replace('/\D+/', '', $raw) ?? '';

            // Prefer MSISDN-like values when available.
            if (strlen($digits) >= 10) {
                return $digits;
            }

            if (filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
                return $raw;
            }
        }

        return '01700000000';
    }

    /**
     * Send payment creation request to bKash.
     */
    private function sendPaymentRequest(string $token, string $appKey, array $payload): array
    {
        Log::info('bKash create payment request', [
            'endpoint' => '/tokenized-checkout/payment/create',
            'app_url' => config('app.url'),
            'payload' => $this->sanitizeCreatePayload($payload),
            'token_preview' => $this->maskToken($token),
            'app_key_preview' => $this->maskToken($appKey),
        ]);

        $response = Http::bkashWithToken($token, $appKey)
            ->timeout(30)
            ->post('/tokenized-checkout/payment/create', $payload);

        $paymentData = $response->json() ?? [];

        if (! $response->successful()) {
            Log::error('bKash create payment API error', [
                'endpoint' => '/tokenized-checkout/payment/create',
                'http_status' => $response->status(),
                'response' => $this->sanitizeResponseBody($paymentData),
                'request_ids' => $this->extractResponseRequestIds($response),
            ]);
        }

        $this->assertApiSuccess($response, $paymentData, 'Failed to create bkash payment');

        return $paymentData;
    }

    /**
     * Save the payment record to database.
     */
    private function savePaymentRecord(array $paymentData, string $token, array $payload, int $cartId): void
    {
        $transactionStatus = strtolower((string) ($paymentData['transactionStatus'] ?? ''));

        $status = match ($transactionStatus) {
            'initiated' => PaymentStatus::INITIATED->value,
            'completed' => PaymentStatus::COMPLETED,
            'failed' => PaymentStatus::FAILED->value,
            'cancelled' => PaymentStatus::CANCELLED->value,
            default => PaymentStatus::PENDING->value,
        };

        $paymentId = $this->getResponseValue($paymentData, ['paymentId', 'paymentID']);

        if (empty($paymentId)) {
            throw new PaymentCreationException('Failed to create bkash payment: paymentId missing in response');
        }

        BkashPayment::query()->create([
            'payment_id' => $paymentId,
            'token' => $token,
            'amount' => $payload['amount'],
            'invoice_number' => $payload['merchantInvoiceNumber'],
            'cart_id' => $cartId,
            'status' => $status,
            'meta' => json_encode($paymentData),
        ]);
    }

    /**
     * Execute a bKash payment.
     */
    public function executePayment(string $paymentId): array
    {
        try {
            $credentials = $this->getCredentials();
            $token = $this->getToken();

            Log::info('bKash execute payment:', [
                'paymentId' => $paymentId,
                'base_url' => $credentials['base_url'],
                'token_length' => strlen($token),
            ]);

            $response = Http::bkashWithToken($token, $credentials['app_key'])
                ->timeout(30)
                ->post('/tokenized-checkout/payment/execute', [
                    'paymentId' => $paymentId,
                ]);

            $data = $response->json() ?? [];

            Log::debug('bKash execute response:', [
                'status' => $response->status(),
                'body' => $data,
            ]);

            $this->assertApiSuccess($response, $data, 'Payment execution failed');

            return $data;
        } catch (PaymentCreationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('bKash execute payment error', [
                'paymentId' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new PaymentCreationException('Payment execution failed: '.$e->getMessage());
        }
    }

    /**
     * Query payment status.
     */
    public function queryPayment(string $paymentId): array
    {
        $credentials = $this->getCredentials();
        $token = $this->getToken();

        $response = Http::bkashWithToken($token, $credentials['app_key'])
            ->timeout(30)
            ->post('/tokenized-checkout/query/payment', [
                'paymentId' => $paymentId,
            ]);

        $data = $response->json() ?? [];

        $this->assertApiSuccess($response, $data, 'Payment query failed');

        return $data;
    }

    /**
     * Validate API response and standardize failures.
     */
    private function assertApiSuccess(Response $response, array $data, string $errorPrefix): void
    {
        if (! $response->successful()) {
            throw new PaymentCreationException($errorPrefix.': '.$this->extractApiErrorMessage($data, $response->status()));
        }

        // v1.2-beta uses statusCode, while v2 uses externalCode for errors.
        $statusCode = (string) ($data['statusCode'] ?? '');
        if ($statusCode !== '' && $statusCode !== '0000') {
            throw new PaymentCreationException($errorPrefix.': '.$this->extractApiErrorMessage($data, $response->status()));
        }

        if (! empty($data['externalCode']) && (string) $data['externalCode'] !== '0000') {
            throw new PaymentCreationException($errorPrefix.': '.$this->extractApiErrorMessage($data, $response->status()));
        }
    }

    /**
     * Keep payload logs safe and concise.
     */
    private function sanitizeCreatePayload(array $payload): array
    {
        return [
            'payerReference' => isset($payload['payerReference']) ? $this->maskToken((string) $payload['payerReference']) : null,
            'callbackURL' => $payload['callbackURL'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'intent' => $payload['intent'] ?? null,
            'merchantInvoiceNumber' => $payload['merchantInvoiceNumber'] ?? null,
            'mode' => $payload['mode'] ?? null,
        ];
    }

    /**
     * Avoid dumping entire response structures with sensitive values.
     */
    private function sanitizeResponseBody(array $body): array
    {
        $keys = [
            'statusCode',
            'statusMessage',
            'externalCode',
            'errorMessageEn',
            'message',
            'paymentId',
            'paymentID',
            'transactionStatus',
            'bkashURL',
        ];

        $sanitized = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $body)) {
                $sanitized[$key] = $body[$key];
            }
        }

        return $sanitized;
    }

    /**
     * Extract common correlation IDs for vendor support.
     */
    private function extractResponseRequestIds(Response $response): array
    {
        $headerCandidates = [
            'x-request-id',
            'x-correlation-id',
            'request-id',
            'trace-id',
        ];

        $ids = [];

        foreach ($headerCandidates as $header) {
            $values = $response->header($header);
            if (! empty($values)) {
                $ids[$header] = is_array($values) ? implode(',', $values) : $values;
            }
        }

        return $ids;
    }

    /**
     * Show only first and last chars of sensitive tokens.
     */
    private function maskToken(string $value): string
    {
        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4).str_repeat('*', max(0, $length - 8)).substr($value, -4);
    }

    /**
     * Extract best-effort message from bKash API responses.
     */
    private function extractApiErrorMessage(array $data, int $httpStatus): string
    {
        return (string) (
            $data['errorMessageEn']
            ?? $data['statusMessage']
            ?? $data['message']
            ?? ('HTTP '.$httpStatus)
        );
    }

    /**
     * Resolve a response field using legacy and v2 key variants.
     */
    private function getResponseValue(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return (string) $data[$key];
            }
        }

        return null;
    }

    /**
     * Process bKash callback and create order.
     */
    public function processCallback(Request $request)
    {
        Log::debug('bKash callback received', $request->all());

        try {
            $paymentId = (string) ($request->paymentID ?? $request->paymentId ?? '');
            $paymentStatus = (string) $request->status;

            if ($paymentId === '') {
                throw new PaymentCreationException('Payment callback is missing payment ID');
            }

            $bkashPayment = $this->findPaymentRecord($paymentId);

            if ($paymentStatus !== 'success') {
                $bkashPayment->update([
                    'status' => $paymentStatus,
                    'meta' => json_encode($request->all()),
                ]);

                session()->flash('error', 'Payment was cancelled. Please try again.');

                return redirect()->route('shop.checkout.cart.index');
            }

            $this->loadCart($bkashPayment->cart_id, $paymentId);

            $payment = $this->executePayment($paymentId);

            return $this->processSuccessfulPayment($bkashPayment, $paymentId, $payment);
        } catch (PaymentCreationException $e) {
            Log::error('Payment Creation Error: '.$e->getMessage());
            session()->flash('error', $e->getMessage());

            return redirect()->route('shop.checkout.cart.index');
        } catch (\Throwable $e) {
            Log::error('Callback Processing Error: '.$e->getMessage());
            session()->flash('error', 'Payment processing failed. Please try again.');

            return redirect()->route('shop.checkout.cart.index');
        }
    }

    /**
     * Find the payment record.
     */
    private function findPaymentRecord(string $paymentId)
    {
        return BkashPayment::query()
            ->where('payment_id', $paymentId)
            ->whereIn('status', [
                PaymentStatus::PENDING->value,
                PaymentStatus::INITIATED->value,
            ])
            ->firstOrFail();
    }

    /**
     * Load and validate cart.
     */
    private function loadCart(int $cartId, string $paymentId)
    {
        $cart = \Webkul\Checkout\Models\Cart::find($cartId);

        Log::debug('Cart found during callback', [
            'cart_id' => $cartId,
            'exists' => (bool) $cart,
        ]);

        if (! $cart) {
            Log::error('Cart not found during bKash callback', [
                'payment_id' => $paymentId,
                'cart_id' => $cartId,
            ]);

            throw new \Exception('Cart not found. Please contact support.');
        }

        Cart::setCart($cart);

        return $cart;
    }

    /**
     * Process successful payment and create order.
     */
    private function processSuccessfulPayment(BkashPayment $bkashPayment, string $paymentId, array $payment)
    {
        return DB::transaction(function () use ($bkashPayment, $paymentId, $payment) {
            $bkashPayment->update([
                'status' => PaymentStatus::SUCCESS->value,
                'meta' => json_encode($payment),
            ]);

            $order = $this->createOrder();

            $transactionId = $this->getResponseValue($payment, ['trxId', 'trxID']) ?? $paymentId;

            $this->savePaymentTransactionId($order->id, $transactionId);
            $this->createInvoiceIfPossible($order);

            session()->put('order_id', $order->id);
            Cart::deActivateCart();
            session()->flash('order', $order);

            Log::debug('bKash payment completed successfully', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
            ]);

            return redirect()->route('shop.checkout.onepage.success');
        });
    }

    /**
     * Create order from cart.
     */
    private function createOrder()
    {
        $data = (new OrderResource(Cart::getCart()))->jsonSerialize();

        return $this->orderRepository->create($data);
    }

    /**
     * Create invoice if possible.
     */
    private function createInvoiceIfPossible($order): void
    {
        if ($order->canInvoice()) {
            $this->invoiceRepository->create($this->prepareInvoiceData($order));
        }
    }

    /**
     * Prepare invoice data for the order.
     */
    protected function prepareInvoiceData($order): array
    {
        $invoiceData = [
            'order_id' => $order->id,
            'invoice' => ['items' => []],
        ];

        foreach ($order->items as $item) {
            $invoiceData['invoice']['items'][$item->id] = $item->qty_to_invoice;
        }

        return $invoiceData;
    }

    /**
     * Save payment transaction ID for the order.
     */
    protected function savePaymentTransactionId(int $orderId, string $transactionId): void
    {
        $jsonData = json_encode([
            'transaction_id' => $transactionId,
            'payment_method' => 'bkash',
            'status' => 'completed',
            'timestamp' => now()->toIso8601String(),
        ]);

        OrderPayment::where('order_id', $orderId)->update([
            'additional' => $jsonData,
        ]);
    }
}
