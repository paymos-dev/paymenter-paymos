<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos;

require_once __DIR__ . '/src/Autoloader.php';
Internal\Autoloader::register();

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use Illuminate\Http\Request;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\AmountGuard;
use Paymenter\Extensions\Gateways\Paymos\Internal\Config;
use Paymenter\Extensions\Gateways\Paymos\Internal\DbDriverInterface;
use Paymenter\Extensions\Gateways\Paymos\Internal\GatewayService;
use Paymenter\Extensions\Gateways\Paymos\Internal\PdoDbDriver;

#[ExtensionMeta(
    name: 'Paymos',
    description: 'Accept USDT, USDC, USD1, DAI stablecoins and XAUT payments via Paymos.',
    version: '1.0.0',
    author: 'Paymos Labs',
    url: 'https://paymos.io',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCIgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0Ij48cmVjdCB3aWR0aD0iNjQiIGhlaWdodD0iNjQiIHJ4PSIxNCIgZmlsbD0iIzBCMEYxQSIvPjxwYXRoIGQ9Ik0yMiAxOGgxNGM3LjczIDAgMTMgNC43IDEzIDEycy01LjI3IDEyLTEzIDEyaC03djhoLTdWMTh6bTcgMTdoNi42YzMuODcgMCA2LjQtMi4wMiA2LjQtNXMtMi41My01LTYuNC01SDI5djEweiIgZmlsbD0iIzEwQjk4MSIvPjwvc3ZnPg=='
)]
class Paymos extends Gateway
{
    private ?DbDriverInterface $dbDriver = null;
    private ?TransportInterface $transport = null;

    /** @var callable|null */
    private $clock = null;

    /** @var callable|null */
    private $logger = null;

    /** @var list<array{platform_ref: string, note: string, context: array<string, mixed>}> */
    private array $recordedNotes = [];

    public function boot(): void
    {
        require __DIR__ . '/routes.php';
    }

    public function installed(): void
    {
        try {
            $driver = $this->resolveDbDriver();
            $driver->ensureSchema();
        } catch (\Throwable $e) {
            // Tables are also lazily ensured on first checkout/webhook/refund call.
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return list<array<string, mixed>>
     */
    public function getConfig($values = []): array
    {
        $webhookUrl = 'https://<panel>/extensions/gateways/paymos/webhook';
        if (function_exists('url')) {
            try {
                $generated = \url('/extensions/gateways/paymos/webhook');
                if (is_string($generated) && trim($generated) !== '') {
                    $webhookUrl = $generated;
                }
            } catch (\Throwable $e) {
                // Keep canonical placeholder outside a booted Laravel request.
            }
        }

        return [
            [
                'name' => 'environment',
                'label' => 'Environment',
                'type' => 'select',
                'description' => 'Select Live for mainnet payments or Sandbox for testing.',
                'required' => true,
                'default' => Config::ENV_LIVE,
                'options' => [
                    Config::ENV_LIVE => 'Live (Production)',
                    Config::ENV_SANDBOX => 'Sandbox (Test)',
                ],
            ],
            [
                'name' => 'project_id',
                'label' => 'Project ID',
                'type' => 'text',
                'description' => 'Your Paymos Project UUID from Paymos Dashboard.',
                'required' => true,
            ],
            [
                'name' => 'api_key',
                'label' => 'Payment API Key',
                'type' => 'text',
                'description' => 'Payment API Key (pk_live_* for Live or pk_test_* for Sandbox). Payout keys (rk_*) are rejected.',
                'required' => true,
            ],
            [
                'name' => 'api_secret',
                'label' => 'API Secret',
                'type' => 'password',
                'description' => 'API Secret (sk_live_* or sk_test_*) paired with your Payment API Key.',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'Webhook Signing Secret',
                'type' => 'password',
                'description' => 'Register ' . $webhookUrl
                    . ' (Invoice events) in Paymos Dashboard → Developer → Webhooks and paste the whsec_* signing secret here.',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'base_url',
                'label' => 'API Base URL',
                'type' => 'text',
                'description' => 'HTTPS Paymos API endpoint (default: https://api.paymos.io).',
                'required' => false,
                'default' => Config::DEFAULT_BASE_URL,
            ],
            [
                'name' => 'debug_logging',
                'label' => 'Debug Logging',
                'type' => 'checkbox',
                'description' => 'Enable sanitized debug logging (API and webhook secrets are never logged).',
                'required' => false,
                'default' => false,
            ],
        ];
    }

    /**
     * @param mixed                $total
     * @param mixed                $currency
     * @param mixed                $type
     * @param array<int, mixed>    $items
     */
    public function canUseGateway($total, $currency, $type, $items = []): bool
    {
        try {
            Config::fromArray($this->readRawConfig());
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $normalized = AmountGuard::normalizeDecimal($total);
        if ($normalized === null || $normalized === '0' || $normalized[0] === '-') {
            return false;
        }

        if (!is_string($currency) || trim($currency) === '') {
            return false;
        }

        return true;
    }

    /**
     * Create a Paymos hosted invoice and return its payment URL.
     *
     * @param mixed $total
     */
    public function pay(Invoice $invoice, $total): string
    {
        $service = $this->makeService();
        $invoiceId = trim((string) $invoice->id);
        $currency = $this->extractInvoiceCurrency($invoice);
        $clientId = $this->extractClientId($invoice);

        $checkout = $service->createCheckout($invoiceId, $total, $currency, $clientId);

        return $checkout['payment_url'];
    }

    /**
     * Handle signed Paymos webhooks on POST /extensions/gateways/paymos/webhook.
     *
     * @return mixed
     */
    public function webhook(Request $request)
    {
        try {
            $service = $this->makeService();
        } catch (\Throwable $e) {
            return $this->respondJson(500, ['error' => 'configuration_error']);
        }

        $rawBody = (string) $request->getContent();
        $signatureHeader = $this->extractWebhookSignature($request);

        $loadBillingInvoice = function (string $platformRef): ?array {
            $invoice = Invoice::find($platformRef);
            if (!$invoice instanceof Invoice) {
                return null;
            }

            $amount = $this->extractInvoiceTotal($invoice);
            $currency = $this->extractInvoiceCurrency($invoice);
            $status = strtolower(trim((string) ($invoice->status ?? '')));

            return [
                'id' => (string) $invoice->id,
                'amount' => $amount,
                'currency' => $currency,
                'is_paid' => ($status === 'paid'),
            ];
        };

        $applyPayment = static function (string $platformRef, string $amount, string $transactionId, array $eventData): void {
            $invoiceId = ctype_digit($platformRef) ? (int) $platformRef : $platformRef;
            ExtensionHelper::addPayment($invoiceId, 'Paymos', $amount, transactionId: $transactionId);
        };

        $recordNote = function (string $platformRef, string $note, array $context): void {
            $safeNote = Config::redactSecrets($note);
            $this->recordedNotes[] = [
                'platform_ref' => $platformRef,
                'note' => $safeNote,
                'context' => $context,
            ];
        };

        $result = $service->handleWebhook(
            $rawBody,
            $signatureHeader,
            $loadBillingInvoice,
            $applyPayment,
            $recordNote
        );

        return $this->respondJson($result['status'], $result['body']);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * Issue a full refund via Paymos Refunds API.
     *
     * @param mixed $amount
     */
    public function refund(InvoiceTransaction $transaction, $amount): bool
    {
        $service = $this->makeService();
        $transactionId = isset($transaction->transaction_id) && is_scalar($transaction->transaction_id)
            ? trim((string) $transaction->transaction_id)
            : '';
        $platformRef = isset($transaction->invoice_id) && is_scalar($transaction->invoice_id)
            ? trim((string) $transaction->invoice_id)
            : null;
        $transactionAmount = isset($transaction->amount) && is_scalar($transaction->amount)
            ? trim((string) $transaction->amount)
            : null;

        $service->refundTransaction($transactionId, $amount, $platformRef, $transactionAmount);

        return true;
    }

    public function setDbDriver(DbDriverInterface $dbDriver): self
    {
        $this->dbDriver = $dbDriver;

        return $this;
    }

    public function setTransport(TransportInterface $transport): self
    {
        $this->transport = $transport;

        return $this;
    }

    public function setClock(callable $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return list<array{platform_ref: string, note: string, context: array<string, mixed>}>
     */
    public function getRecordedNotes(): array
    {
        return $this->recordedNotes;
    }

    /**
     * All Extension::config() calls MUST live directly inside Paymos because
     * Paymenter's base Extension::config() resolves settings via debug_backtrace()[1]['class'].
     *
     * @return array<string, mixed>
     */
    private function readRawConfig(): array
    {
        return [
            'environment' => $this->config('environment'),
            'project_id' => $this->config('project_id'),
            'api_key' => $this->config('api_key'),
            'api_secret' => $this->config('api_secret'),
            'webhook_secret' => $this->config('webhook_secret'),
            'base_url' => $this->config('base_url'),
            'debug_logging' => $this->config('debug_logging'),
        ];
    }

    private function makeService(): GatewayService
    {
        $config = Config::fromArray($this->readRawConfig());
        $db = $this->resolveDbDriver();

        return new GatewayService(
            $config,
            $db,
            $this->transport,
            $this->clock,
            $this->resolveLogger()
        );
    }

    private function resolveDbDriver(): DbDriverInterface
    {
        if ($this->dbDriver !== null) {
            return $this->dbDriver;
        }

        $this->dbDriver = PdoDbDriver::fromLaravel();

        return $this->dbDriver;
    }

    private function resolveLogger(): callable
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        return static function (string $level, string $message, array $context): void {
            if (!class_exists(\Illuminate\Support\Facades\Log::class)) {
                return;
            }
            try {
                \Illuminate\Support\Facades\Log::log($level, '[Paymos] ' . $message, $context);
            } catch (\Throwable $e) {
                // Ignore logging backend errors outside a fully booted Laravel app.
            }
        };
    }

    private function extractInvoiceCurrency(Invoice $invoice): string
    {
        if (isset($invoice->currency_code) && is_string($invoice->currency_code) && trim($invoice->currency_code) !== '') {
            return strtoupper(trim($invoice->currency_code));
        }

        if (isset($invoice->currency)) {
            if (is_string($invoice->currency) && trim($invoice->currency) !== '') {
                return strtoupper(trim($invoice->currency));
            }
            if (is_object($invoice->currency) && isset($invoice->currency->code) && is_string($invoice->currency->code)) {
                $code = trim($invoice->currency->code);
                if ($code !== '') {
                    return strtoupper($code);
                }
            }
        }

        throw new \InvalidArgumentException('Paymenter invoice is missing currency_code.');
    }

    private function extractInvoiceTotal(Invoice $invoice): string
    {
        // Paymenter passes $invoice->remaining into pay($invoice, $total), so partially
        // credited or partially paid invoices must compare against remaining first.
        if (isset($invoice->remaining) && is_scalar($invoice->remaining) && trim((string) $invoice->remaining) !== '') {
            return trim((string) $invoice->remaining);
        }
        if (isset($invoice->total) && is_scalar($invoice->total) && trim((string) $invoice->total) !== '') {
            return trim((string) $invoice->total);
        }

        return '';
    }

    /**
     * Extract native numeric/string user ID — NEVER an email address.
     */
    private function extractClientId(Invoice $invoice): ?string
    {
        if (isset($invoice->user_id) && is_scalar($invoice->user_id)) {
            $id = trim((string) $invoice->user_id);
            if ($id !== '' && !str_contains($id, '@')) {
                return $id;
            }
        }

        if (isset($invoice->user) && is_object($invoice->user) && isset($invoice->user->id) && is_scalar($invoice->user->id)) {
            $id = trim((string) $invoice->user->id);
            if ($id !== '' && !str_contains($id, '@')) {
                return $id;
            }
        }

        return null;
    }

    private function extractWebhookSignature(Request $request): string
    {
        $header = $request->header('X-Webhook-Signature');
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $lower = $request->header('x-webhook-signature');
        if (is_string($lower) && trim($lower) !== '') {
            return trim($lower);
        }

        if (isset($request->headers) && is_object($request->headers) && method_exists($request->headers, 'all')) {
            $all = $request->headers->all();
            if (is_array($all)) {
                foreach ($all as $name => $values) {
                    if (strtolower((string) $name) !== 'x-webhook-signature') {
                        continue;
                    }
                    if (is_array($values) && isset($values[0]) && is_string($values[0])) {
                        return trim($values[0]);
                    }
                    if (is_string($values)) {
                        return trim($values);
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $body
     * @return mixed
     */
    private function respondJson(int $status, array $body)
    {
        if (function_exists('response')) {
            $factory = \response();
            if (is_object($factory) && method_exists($factory, 'json')) {
                return $factory->json($body, $status);
            }
        }

        return [
            'status' => $status,
            'body' => $body,
        ];
    }
}
