<?php

declare(strict_types=1);

namespace App\Attributes {
    #[\Attribute(\Attribute::TARGET_CLASS)]
    class ExtensionMeta
    {
        public string $name;
        public string $description;
        public string $version;
        public string $author;
        public string $url;
        public string $icon;

        public function __construct(
            string $name,
            string $description,
            string $version,
            string $author,
            string $url = '',
            string $icon = ''
        ) {
            $this->name = $name;
            $this->description = $description;
            $this->version = $version;
            $this->author = $author;
            $this->url = $url;
            $this->icon = $icon;
        }
    }
}

namespace App\Classes\Extension {
    /**
     * Realistic Paymenter v1 Extension stub enforcing debug_backtrace()[1]['class'] locality.
     */
    abstract class Extension
    {
        /** @var array<string, mixed> */
        protected array $config = [];

        /**
         * @param array<string, mixed> $config
         */
        public function __construct(array $config = [])
        {
            $this->config = $config;
        }

        /**
         * @param array<string, mixed> $config
         */
        public function setStubConfig(array $config): void
        {
            $this->config = $config;
        }

        /**
         * Mirrors Paymenter's Extension::config($key) which inspects debug_backtrace()[1]['class'].
         *
         * @return mixed
         */
        public function config(string $key)
        {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $callerClass = $trace[1]['class'] ?? null;
            if ($callerClass !== static::class) {
                throw new \LogicException(
                    'Paymenter Extension::config() must be called directly from '
                    . static::class . ', got ' . (string) ($callerClass ?? 'global')
                );
            }

            return $this->config[$key] ?? null;
        }
    }

    abstract class Gateway extends Extension
    {
        public function boot(): void
        {
        }

        public function installed(): void
        {
        }

        public function supportsRefunds(): bool
        {
            return false;
        }
    }
}

namespace App\Models {
    class Invoice
    {
        /** @var array<string, self> */
        public static array $store = [];

        public int|string $id;
        public string $currency_code;
        public string|float|int $total;
        public string|float|int $remaining;
        public string $status;
        public int|string|null $user_id;
        public ?object $user;

        public function __construct(
            int|string $id,
            string|float|int $total,
            string $currencyCode = 'USD',
            string $status = 'pending',
            int|string|null $userId = null,
            ?object $user = null
        ) {
            $this->id = $id;
            $this->total = $total;
            $this->remaining = $total;
            $this->currency_code = $currencyCode;
            $this->status = $status;
            $this->user_id = $userId;
            $this->user = $user;
        }

        public static function resetStore(): void
        {
            self::$store = [];
        }

        public static function put(self $invoice): void
        {
            self::$store[(string) $invoice->id] = $invoice;
        }

        public static function find(int|string $id): ?self
        {
            return self::$store[(string) $id] ?? null;
        }
    }

    class InvoiceTransaction
    {
        public int|string $id;
        public int|string $invoice_id;
        public string $transaction_id;
        public string|float|int $amount;

        public function __construct(
            int|string $id,
            int|string $invoiceId,
            string $transactionId,
            string|float|int $amount
        ) {
            $this->id = $id;
            $this->invoice_id = $invoiceId;
            $this->transaction_id = $transactionId;
            $this->amount = $amount;
        }
    }
}

namespace App\Helpers {
    use App\Models\Invoice;

    class ExtensionHelper
    {
        /** @var list<array{invoice_id: int|string, gateway: string, amount: string|float|int, fee: ?float, transaction_id: ?string}> */
        public static array $payments = [];

        public static ?\Throwable $throwOnNextCall = null;

        public static function reset(): void
        {
            self::$payments = [];
            self::$throwOnNextCall = null;
        }

        public static function addPayment(
            int|string $invoiceId,
            string $gateway,
            string|float|int $amount,
            ?float $fee = null,
            ?string $transactionId = null
        ): void {
            if (self::$throwOnNextCall !== null) {
                $err = self::$throwOnNextCall;
                self::$throwOnNextCall = null;
                throw $err;
            }

            self::$payments[] = [
                'invoice_id' => $invoiceId,
                'gateway' => $gateway,
                'amount' => $amount,
                'fee' => $fee,
                'transaction_id' => $transactionId,
            ];

            $invoice = Invoice::find($invoiceId);
            if ($invoice !== null) {
                $invoice->status = 'paid';
                $invoice->remaining = '0';
            }
        }
    }
}

namespace Illuminate\Foundation\Http\Middleware {
    class PreventRequestForgery
    {
    }
}

namespace Illuminate\Support\Facades {
    class RegisteredRoute
    {
        public string $method;
        public string $uri;

        /** @var array<int, mixed> */
        public array $action;

        /** @var list<string> */
        public array $withoutMiddleware = [];

        public ?string $name = null;

        /**
         * @param array<int, mixed> $action
         */
        public function __construct(string $method, string $uri, array $action)
        {
            $this->method = $method;
            $this->uri = $uri;
            $this->action = $action;
        }

        /**
         * @param list<string> $middleware
         */
        public function withoutMiddleware(array $middleware): self
        {
            $this->withoutMiddleware = array_values(array_merge($this->withoutMiddleware, $middleware));

            return $this;
        }

        public function name(string $name): self
        {
            $this->name = $name;

            return $this;
        }
    }

    class Route
    {
        /** @var list<RegisteredRoute> */
        public static array $registered = [];

        public static function reset(): void
        {
            self::$registered = [];
        }

        /**
         * @param array<int, mixed> $action
         */
        public static function post(string $uri, array $action): RegisteredRoute
        {
            $route = new RegisteredRoute('POST', $uri, $action);
            self::$registered[] = $route;

            return $route;
        }
    }
}

namespace Illuminate\Http {
    class HeaderBag
    {
        /** @var array<string, list<string>> */
        private array $headers = [];

        /**
         * @param array<string, string|list<string>> $headers
         */
        public function __construct(array $headers = [])
        {
            foreach ($headers as $key => $value) {
                $this->headers[strtolower($key)] = is_array($value) ? array_values($value) : [(string) $value];
            }
        }

        /**
         * @return array<string, list<string>>
         */
        public function all(): array
        {
            return $this->headers;
        }

        public function get(string $key, ?string $default = null): ?string
        {
            $normalized = strtolower($key);
            if (!isset($this->headers[$normalized][0])) {
                return $default;
            }

            return $this->headers[$normalized][0];
        }
    }

    class Request
    {
        public HeaderBag $headers;
        private string $content;

        /**
         * @param array<string, string|list<string>> $headers
         */
        public function __construct(string $content = '', array $headers = [])
        {
            $this->content = $content;
            $this->headers = new HeaderBag($headers);
        }

        public function getContent(): string
        {
            return $this->content;
        }

        public function header(string $key, ?string $default = null): ?string
        {
            return $this->headers->get($key, $default);
        }
    }
}

namespace PaymenterPaymosTests {
    use App\Attributes\ExtensionMeta;
    use App\Classes\Extension\Gateway;
    use App\Helpers\ExtensionHelper;
    use App\Models\Invoice;
    use App\Models\InvoiceTransaction;
    use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Route;
    use Paymos\Client;
    use Paymos\Http\HttpResponse;
    use Paymos\Http\MockTransport;
    use Paymos\Http\RequestSigner;
    use Paymos\Webhook\WebhookVerifier;
    use Paymenter\Extensions\Gateways\Paymos\Internal\Autoloader;
    use Paymenter\Extensions\Gateways\Paymos\Internal\Config;
    use Paymenter\Extensions\Gateways\Paymos\Internal\DbDriverInterface;
    use Paymenter\Extensions\Gateways\Paymos\Internal\GatewayService;
    use Paymenter\Extensions\Gateways\Paymos\Internal\PdoDbDriver;
    use Paymenter\Extensions\Gateways\Paymos\Internal\PdoEventStore;
    use Paymenter\Extensions\Gateways\Paymos\Internal\SnapshotStore;
    use Paymenter\Extensions\Gateways\Paymos\Paymos;

    require_once dirname(__DIR__) . '/Paymos.php';

    /**
     * Deterministic relational storage driver for PHP environments without pdo_sqlite,
     * mirroring PdoDbDriver unique-key, CAS, ordering, and bounded-delete semantics.
     */
    final class InMemoryDbDriver implements DbDriverInterface
    {
        public bool $schemaEnsured = false;

        /** @var array<string, array<string, array<string, mixed>>> */
        private array $tables = [
            PdoEventStore::TABLE => [],
            SnapshotStore::TABLE => [],
        ];

        public function ensureSchema(): void
        {
            $this->schemaEnsured = true;
            foreach ([PdoEventStore::TABLE, SnapshotStore::TABLE] as $table) {
                if (!isset($this->tables[$table])) {
                    $this->tables[$table] = [];
                }
            }
        }

        public function insertUnique(string $table, array $row, string $primaryKey): bool
        {
            $this->ensureSchema();
            $pk = (string) ($row[$primaryKey] ?? '');
            if ($pk === '') {
                throw new \InvalidArgumentException('Primary key cannot be empty.');
            }
            if (array_key_exists($pk, $this->tables[$table])) {
                return false;
            }
            $this->tables[$table][$pk] = $row;

            return true;
        }

        public function upsert(string $table, array $row, string $primaryKey): void
        {
            $this->ensureSchema();
            $pk = (string) ($row[$primaryKey] ?? '');
            if ($pk === '') {
                throw new \InvalidArgumentException('Primary key cannot be empty.');
            }
            $this->tables[$table][$pk] = $row;
        }

        public function selectOne(string $table, array $where, ?string $orderBy = null): ?array
        {
            $rows = $this->selectAll($table, $where, $orderBy, 1);

            return $rows[0] ?? null;
        }

        public function selectAll(string $table, array $where, ?string $orderBy = null, ?int $limit = null): array
        {
            $this->ensureSchema();
            $matches = [];
            foreach ($this->tables[$table] as $row) {
                if ($this->matchesPredicates($row, $where, [], [])) {
                    $matches[] = $row;
                }
            }

            if ($orderBy !== null && trim($orderBy) !== '') {
                $clauses = array_values(array_filter(array_map('trim', explode(',', $orderBy))));
                usort($matches, static function (array $a, array $b) use ($clauses): int {
                    foreach ($clauses as $clause) {
                        $parts = preg_split('/\s+/', $clause);
                        $col = $parts[0] ?? '';
                        $dir = strtoupper($parts[1] ?? 'ASC');
                        $va = $a[$col] ?? null;
                        $vb = $b[$col] ?? null;
                        if ($va == $vb) {
                            continue;
                        }
                        $cmp = ($va < $vb) ? -1 : 1;
                        return $dir === 'DESC' ? -$cmp : $cmp;
                    }
                    return 0;
                });
            }

            if ($limit !== null) {
                return array_slice($matches, 0, max(0, $limit));
            }

            return $matches;
        }

        public function updateWhere(string $table, array $values, array $where, array $lteWhere = []): int
        {
            $this->ensureSchema();
            $affected = 0;
            foreach ($this->tables[$table] as $pk => $row) {
                if (!$this->matchesPredicates($row, $where, [], $lteWhere)) {
                    continue;
                }
                foreach ($values as $col => $val) {
                    $row[$col] = $val;
                }
                $this->tables[$table][$pk] = $row;
                $affected++;
            }

            return $affected;
        }

        public function deleteWhere(
            string $table,
            array $where,
            array $ltWhere = [],
            ?int $limit = null,
            array $lteWhere = []
        ): int {
            $this->ensureSchema();
            $deleted = 0;
            foreach ($this->tables[$table] as $pk => $row) {
                if ($limit !== null && $deleted >= $limit) {
                    break;
                }
                if (!$this->matchesPredicates($row, $where, $ltWhere, $lteWhere)) {
                    continue;
                }
                unset($this->tables[$table][$pk]);
                $deleted++;
            }

            return $deleted;
        }

        public function countRows(string $table): int
        {
            return count($this->tables[$table] ?? []);
        }

        /**
         * @param array<string, mixed>       $row
         * @param array<string, scalar|null> $where
         * @param array<string, scalar>      $ltWhere
         * @param array<string, scalar>      $lteWhere
         */
        private function matchesPredicates(array $row, array $where, array $ltWhere, array $lteWhere): bool
        {
            foreach ($where as $col => $val) {
                $actual = $row[$col] ?? null;
                if ($val === null) {
                    if ($actual !== null) {
                        return false;
                    }
                } elseif ((string) $actual !== (string) $val) {
                    return false;
                }
            }
            foreach ($ltWhere as $col => $val) {
                if (!isset($row[$col]) || !($row[$col] < $val)) {
                    return false;
                }
            }
            foreach ($lteWhere as $col => $val) {
                if (!isset($row[$col]) || !($row[$col] <= $val)) {
                    return false;
                }
            }

            return true;
        }
    }

    /**
     * Negative probe proving the Extension stub truly fails if a non-Paymos class calls config().
     */
    final class ExternalConfigCaller
    {
        public static function readFromOutside(Paymos $gateway, string $key): mixed
        {
            return $gateway->config($key);
        }
    }

    /**
     * Lightweight PDOStatement double for verifying real PdoDbDriver SQL across mysql/pgsql/sqlite.
     */
    final class RecordingPdoStatement extends \PDOStatement
    {
        private string $sql;

        /** @var callable(string, array<int, mixed>): array{rows?: list<array<string, mixed>>, column?: mixed, rowCount?: int} */
        private $handler;

        /** @var list<array<string, mixed>> */
        private array $rows = [];

        private mixed $columnValue = null;
        private int $affected = 0;

        /**
         * @param callable(string, array<int, mixed>): array{rows?: list<array<string, mixed>>, column?: mixed, rowCount?: int} $handler
         */
        public function __construct(string $sql, callable $handler)
        {
            $this->sql = $sql;
            $this->handler = $handler;
        }

        public function execute(?array $params = null): bool
        {
            $result = ($this->handler)($this->sql, array_values($params ?? []));
            $this->rows = $result['rows'] ?? [];
            $this->columnValue = $result['column'] ?? null;
            $this->affected = (int) ($result['rowCount'] ?? 0);

            return true;
        }

        public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return $this->rows;
        }

        public function fetchColumn(int $column = 0): mixed
        {
            return $this->columnValue;
        }

        public function rowCount(): int
        {
            return $this->affected;
        }
    }

    /**
     * Recording PDO double exercising real PdoDbDriver DDL guards, metadata checks, and conflict-safe SQL.
     */
    final class RecordingPdo extends \PDO
    {
        public string $driver;
        public bool $tablesExist;
        public bool $txActive;

        /** @var list<string> */
        public array $execCalls = [];

        /** @var list<array{sql: string, params: array<int, mixed>}> */
        public array $preparedCalls = [];

        /** @var array<string, bool> */
        public array $insertedEventIds = [];

        /** @var list<array<string, mixed>> */
        public array $snapshotRows = [];

        public function __construct(string $driver, bool $tablesExist = false, bool $txActive = false)
        {
            $this->driver = $driver;
            $this->tablesExist = $tablesExist;
            $this->txActive = $txActive;
        }

        public function getAttribute(int $attribute): mixed
        {
            if ($attribute === \PDO::ATTR_DRIVER_NAME) {
                return $this->driver;
            }

            return null;
        }

        public function inTransaction(): bool
        {
            return $this->txActive;
        }

        public function exec(string $statement): int|false
        {
            $this->execCalls[] = $statement;
            if (stripos($statement, 'CREATE TABLE') === 0) {
                $this->tablesExist = true;
            }

            return 0;
        }

        public function prepare(string $query, array $options = []): \PDOStatement|false
        {
            return new RecordingPdoStatement($query, function (string $sql, array $params): array {
                $this->preparedCalls[] = ['sql' => $sql, 'params' => $params];

                // Read-only metadata check (information_schema.tables / sqlite_master)
                if (stripos($sql, 'information_schema.tables') !== false || stripos($sql, 'sqlite_master') !== false) {
                    return ['column' => $this->tablesExist ? 2 : 0];
                }

                // Conflict-safe insertUnique on paymos_webhook_events
                if (stripos($sql, 'INSERT IGNORE INTO paymos_webhook_events') === 0
                    || (stripos($sql, 'INSERT INTO paymos_webhook_events') === 0 && stripos($sql, 'ON CONFLICT (event_id) DO NOTHING') !== false)) {
                    $eventId = (string) ($params[0] ?? '');
                    if (isset($this->insertedEventIds[$eventId])) {
                        return ['rowCount' => 0];
                    }
                    $this->insertedEventIds[$eventId] = true;
                    return ['rowCount' => 1];
                }

                // SELECT on paymos_invoice_snapshots
                if (stripos($sql, 'SELECT * FROM paymos_invoice_snapshots') === 0) {
                    return ['rows' => $this->snapshotRows];
                }

                return ['rowCount' => 1];
            });
        }
    }

    function assertTrue(bool $cond, string $message): void
    {
        if (!$cond) {
            throw new \AssertionError($message);
        }
    }

    function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \AssertionError(
                $message . ' | expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    function validLiveConfig(array $overrides = []): array
    {
        return array_merge([
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'api_key' => 'pk_live_unitkey123',
            'api_secret' => 'sk_live_unitsecret123',
            'webhook_secret' => 'whsec_unitsecret123',
            'base_url' => 'https://api.paymos.io',
            'debug_logging' => true,
        ], $overrides);
    }

    function jsonResponse(int $status, array $body, array $headers = []): HttpResponse
    {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
        assertTrue(is_string($encoded), 'JSON encoding failed in test fixture.');

        return new HttpResponse($status, $encoded, array_merge(['content-type' => 'application/json'], $headers));
    }

    function signWebhook(string $secret, int $timestamp, string $rawBody, ?string $extraInvalidV1 = null): string
    {
        $sig = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if ($extraInvalidV1 !== null) {
            return 't=' . $timestamp . ',v1=' . $extraInvalidV1 . ',v1=' . $sig;
        }

        return 't=' . $timestamp . ',v1=' . $sig;
    }

    /**
     * @return array<string, mixed>
     */
    function loadContract(): array
    {
        $path = dirname(__DIR__, 2) . '/sdk-conformance/contract.json';
        assertTrue(is_file($path), 'contract.json must exist at ' . $path);
        $decoded = json_decode((string) file_get_contents($path), true);
        assertTrue(is_array($decoded), 'contract.json must decode to array.');

        return $decoded;
    }

    function makeGateway(
        array $config,
        ?InMemoryDbDriver &$db = null,
        ?MockTransport $transport = null,
        ?callable $clock = null,
        ?array &$logs = null
    ): Paymos {
        $db = $db ?? new InMemoryDbDriver();
        $logs = [];
        $gateway = new Paymos($config);
        $gateway->setDbDriver($db);
        if ($transport !== null) {
            $gateway->setTransport($transport);
        }
        if ($clock !== null) {
            $gateway->setClock($clock);
        }
        $gateway->setLogger(static function (string $level, string $message, array $context) use (&$logs): void {
            $logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
        });

        return $gateway;
    }

    // ── 1. Reject payout API key before network call ──────────────────────────
    function testRejectPayoutKey(): void
    {
        $transport = new MockTransport([]);
        $db = new InMemoryDbDriver();
        $gateway = makeGateway(validLiveConfig(['api_key' => 'rk_live_123']), $db, $transport);

        assertSame(false, $gateway->canUseGateway('49.90', 'USD', 'invoice'), 'canUseGateway must reject rk_live_*');

        $invoice = new Invoice(77, '49.90', 'USD', 'pending', 42);
        $threw = false;
        try {
            $gateway->pay($invoice, '49.90');
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            assertTrue(str_contains($e->getMessage(), 'rk_*'), 'Error should explain payout key rejection.');
        }
        assertTrue($threw, 'pay() must throw InvalidArgumentException for rk_live_123.');
        assertSame(0, count($transport->requests()), '0 HTTP requests may be sent for payout key.');

        // Also test rk_test_ in sandbox
        $sandboxGateway = makeGateway(
            validLiveConfig(['environment' => 'sandbox', 'api_key' => 'rk_test_123', 'api_secret' => 'sk_test_unit']),
            $db,
            $transport
        );
        assertSame(false, $sandboxGateway->canUseGateway('49.90', 'USD', 'invoice'), 'canUseGateway must reject rk_test_*');
        assertSame(0, count($transport->requests()), '0 HTTP requests may be sent for sandbox payout key.');
    }

    // ── 2. Reject non-HTTPS base_url & env mismatches ─────────────────────────
    function testRejectNonHttps(): void
    {
        $transport = new MockTransport([]);
        $db = new InMemoryDbDriver();

        foreach (['http://api.paymos.io', 'file:///etc/passwd', 'https://user:pass@api.paymos.io', 'not-a-url'] as $badUrl) {
            $gateway = makeGateway(validLiveConfig(['base_url' => $badUrl]), $db, $transport);
            assertSame(false, $gateway->canUseGateway('49.90', 'USD', 'invoice'), 'canUseGateway must reject ' . $badUrl);

            $threw = false;
            try {
                $gateway->pay(new Invoice(77, '49.90', 'USD'), '49.90');
            } catch (\InvalidArgumentException $e) {
                $threw = true;
            }
            assertTrue($threw, 'pay() must reject non-HTTPS/invalid base_url: ' . $badUrl);
        }

        // Environment / key prefix mismatch
        $mismatch = makeGateway(validLiveConfig(['environment' => 'live', 'api_key' => 'pk_test_wrong']), $db, $transport);
        assertSame(false, $mismatch->canUseGateway('49.90', 'USD', 'invoice'), 'Live env must reject pk_test_* key');

        $secretMismatch = makeGateway(validLiveConfig(['environment' => 'live', 'api_secret' => 'sk_test_wrong']), $db, $transport);
        assertSame(false, $secretMismatch->canUseGateway('49.90', 'USD', 'invoice'), 'Live env must reject sk_test_* secret');

        assertSame(0, count($transport->requests()), '0 HTTP calls may occur on invalid URL/config.');
    }

    // ── 3. Protect secrets in storage, config() locality, and logs ────────────
    function testSecretProtection(): void
    {
        $cfg = validLiveConfig([
            'api_secret' => 'sk_live_TOPSECRETVALUE999',
            'webhook_secret' => 'whsec_TOPWEBHOOKSECRET999',
        ]);
        $db = new InMemoryDbDriver();
        $gateway = makeGateway($cfg, $db);

        // Verify getConfig() encrypted flags & webhook URL guidance
        $fields = $gateway->getConfig();
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field['name']] = $field;
        }
        assertTrue(isset($byName['api_secret']), 'api_secret field must exist in getConfig().');
        assertSame(true, $byName['api_secret']['encrypted'] ?? null, 'api_secret must have encrypted => true.');
        assertTrue(isset($byName['webhook_secret']), 'webhook_secret field must exist in getConfig().');
        assertSame(true, $byName['webhook_secret']['encrypted'] ?? null, 'webhook_secret must have encrypted => true.');
        assertTrue(
            str_contains((string) ($byName['webhook_secret']['description'] ?? ''), '/extensions/gateways/paymos/webhook'),
            'webhook_secret description must include exact webhook path.'
        );
        assertTrue(
            str_contains((string) ($byName['webhook_secret']['description'] ?? ''), 'Paymos Dashboard'),
            'webhook_secret description must instruct registration in Paymos Dashboard.'
        );
        assertSame(Config::DEFAULT_BASE_URL, $byName['base_url']['default'] ?? null, 'base_url default must be https://api.paymos.io.');

        // Prove Extension::config() locality works from Paymos...
        assertSame(true, $gateway->canUseGateway('10.00', 'USD', 'invoice'), 'Paymos::canUseGateway must read config directly from Paymos.');

        // ...and throws LogicException if called from outside Paymos
        $localityFailed = false;
        try {
            ExternalConfigCaller::readFromOutside($gateway, 'api_key');
        } catch (\LogicException $e) {
            $localityFailed = true;
        }
        assertTrue($localityFailed, 'Stub must reject Extension::config() calls from outside Paymos.');

        // Verify Config::__debugInfo redacts secrets
        $parsed = Config::fromArray($cfg);
        $debugDump = var_export($parsed->__debugInfo(), true);
        assertTrue(!str_contains($debugDump, 'sk_live_TOPSECRETVALUE999'), '__debugInfo must omit api_secret.');
        assertTrue(!str_contains($debugDump, 'whsec_TOPWEBHOOKSECRET999'), '__debugInfo must omit webhook_secret.');

        // Verify error & log redaction even if upstream echoes secret
        $transport = new MockTransport([
            jsonResponse(401, [
                'type' => 'about:blank',
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => 'Bad credential sk_live_TOPSECRETVALUE999 / whsec_TOPWEBHOOKSECRET999',
                'code' => 'authentication_failed',
            ]),
        ]);
        $logs = [];
        $gateway2 = makeGateway($cfg, $db, $transport, null, $logs);
        $caught = '';
        try {
            $gateway2->pay(new Invoice(11, '10.00', 'USD'), '10.00');
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }
        assertTrue($caught !== '', 'API 401 must throw.');
        assertTrue(!str_contains($caught, 'sk_live_TOPSECRETVALUE999'), 'Exception must redact api_secret.');
        assertTrue(!str_contains($caught, 'whsec_TOPWEBHOOKSECRET999'), 'Exception must redact webhook_secret.');
        $serializedLogs = json_encode($logs);
        assertTrue(is_string($serializedLogs) && !str_contains($serializedLogs, 'sk_live_TOPSECRETVALUE999'), 'Logs must redact api_secret.');
        assertTrue(!str_contains($serializedLogs, 'whsec_TOPWEBHOOKSECRET999'), 'Logs must redact webhook_secret.');
    }

    // ── 4. Checkout create, amount normalization, and contract.json signing ──
    function testCheckoutCreateAndRedirect(): void
    {
        $now = 1700000000;
        $clock = static fn (): int => $now;
        $db = new InMemoryDbDriver();
        $transport = new MockTransport([
            jsonResponse(201, [
                'invoice_id' => 'inv_77_live',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'awaiting_client',
                'is_final' => false,
                'is_test' => false,
                'payment_url' => 'https://pay.paymos.io/i/inv_77_live',
                'order' => [
                    'external_id' => 'placeholder',
                    'amount' => '49.9',
                    'currency' => 'USD',
                ],
                'expires_at' => $now + 1800,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
        ]);

        $gateway = makeGateway(validLiveConfig(), $db, $transport, $clock);
        $user = (object) ['id' => 42, 'email' => 'buyer@example.com'];
        $invoice = new Invoice(77, '49.90', 'USD', 'pending', 42, $user);

        // Also assert zero/negative amounts rejected before HTTP
        assertSame(false, $gateway->canUseGateway('0', 'USD', 'invoice'), 'Zero amount must be rejected.');
        assertSame(false, $gateway->canUseGateway('-10', 'USD', 'invoice'), 'Negative amount must be rejected.');
        foreach (['0', '-10'] as $badAmount) {
            $rejected = false;
            try {
                $gateway->pay($invoice, $badAmount);
            } catch (\InvalidArgumentException $e) {
                $rejected = true;
            }
            assertTrue($rejected, 'pay() must reject invalid amount ' . $badAmount);
        }
        assertSame(0, count($transport->requests()), 'No HTTP call for zero/negative amount.');

        $url = $gateway->pay($invoice, '49.90');
        assertSame('https://pay.paymos.io/i/inv_77_live', $url, 'pay() must return payment_url string.');
        assertSame(1, count($transport->requests()), 'Exactly 1 POST /v1/invoices request expected.');

        $req = $transport->requests()[0];
        assertSame('POST', $req['method'], 'Request method must be POST.');
        assertSame('https://api.paymos.io/v1/invoices', $req['url'], 'Request URL must target /v1/invoices.');
        assertTrue(isset($req['headers']['Authorization']) && str_starts_with($req['headers']['Authorization'], 'HMAC-SHA256 pk_live_unitkey123:'), 'Request must carry HMAC-SHA256 Authorization header.');
        assertSame((string) $now, (string) ($req['headers']['X-Request-Timestamp'] ?? ''), 'X-Request-Timestamp must match clock.');

        $body = json_decode($req['body'], true);
        assertTrue(is_array($body), 'Outgoing invoice body must be valid JSON.');
        assertSame('prj_paymenter_live_001', $body['project_id'] ?? null, 'project_id must match config.');
        assertSame('49.9', $body['amount'] ?? null, 'Amount 49.90 must normalize to plain decimal string "49.9".');
        assertSame('USD', $body['currency'] ?? null, 'Currency must be USD.');
        assertSame('42', $body['client_id'] ?? null, 'client_id must be native user id, never email.');
        assertTrue(str_starts_with((string) ($body['external_order_id'] ?? ''), 'pmt-77-'), 'external_order_id must use pmt-77- prefix.');
        assertTrue(!str_starts_with((string) ($body['external_order_id'] ?? ''), 'wgt_'), 'external_order_id must never start with wgt_.');
        assertTrue(!array_key_exists('merchant_id', $body), 'Payload must NOT include merchant_id.');
        assertTrue(!array_key_exists('ttl', $body) && !array_key_exists('expires_in', $body) && !array_key_exists('lifetime', $body), 'Payload must NOT include TTL.');
        assertTrue(!str_contains($req['body'], 'buyer@example.com'), 'Email must NEVER appear in payload.');

        // Verify snapshot persisted
        $snapshots = new SnapshotStore($db, $clock);
        $snap = $snapshots->findByExternalOrderId((string) $body['external_order_id']);
        assertTrue($snap !== null, 'Snapshot must be saved.');
        assertSame('inv_77_live', $snap['paymos_invoice_id'], 'Snapshot paymos_invoice_id must match.');
        assertSame('49.9', $snap['amount'], 'Snapshot amount must be normalized.');
        assertSame($now + 1800, (int) $snap['expires_at'], 'Snapshot expires_at must be saved.');
        assertSame(0, (int) $snap['renewal_attempt'], 'Initial renewal_attempt must be 0.');

        // Verify contract.json canonical request signing vectors directly via Paymos\Http\RequestSigner
        $contract = loadContract();
        foreach (['post_signing', 'post_empty_body_signing', 'get_query_signing'] as $vectorName) {
            $vec = $contract['vectors'][$vectorName];
            $sts = RequestSigner::stringToSign($vec['timestamp'], $vec['method'], $vec['path'], $vec['query'], $vec['body']);
            $sig = RequestSigner::sign($vec['api_secret'], $sts);
            assertSame($vec['signature'], $sig, 'RequestSigner must match contract.json vector: ' . $vectorName);
        }
    }

    // ── 5. Amount/currency change and InvoiceRenewal (-r1) ────────────────────
    function testAmountChangeAndRenewal(): void
    {
        $now = 1700000000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $db = new InMemoryDbDriver();

        $transport = new MockTransport([
            // 1. Initial 49.90 USD
            jsonResponse(201, [
                'invoice_id' => 'inv_77_v1',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'awaiting_client',
                'payment_url' => 'https://pay.paymos.io/i/inv_77_v1',
                'expires_at' => $now + 1800,
            ]),
            // 2. Edited amount 55.00 USD
            jsonResponse(201, [
                'invoice_id' => 'inv_77_v2',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'awaiting_client',
                'payment_url' => 'https://pay.paymos.io/i/inv_77_v2',
                'expires_at' => $now + 1800,
            ]),
            // 3. Currency change 49.90 EUR
            jsonResponse(201, [
                'invoice_id' => 'inv_77_eur',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'awaiting_client',
                'payment_url' => 'https://pay.paymos.io/i/inv_77_eur',
                'expires_at' => $now + 1800,
            ]),
            // 4a. GET live status check for expired inv_77_v1 (server confirms expired)
            jsonResponse(200, [
                'invoice_id' => 'inv_77_v1',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'expired',
                'is_final' => true,
                'expires_at' => $now + 1800,
            ]),
            // 4b. POST renewed invoice (-r1)
            jsonResponse(201, [
                'invoice_id' => 'inv_77_r1',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'awaiting_client',
                'payment_url' => 'https://pay.paymos.io/i/inv_77_r1',
                'expires_at' => $now + 5000,
            ]),
            // 5a. GET live status check for inv_77_r1 when buyer already picked network (confirming -> NO renewal)
            jsonResponse(200, [
                'invoice_id' => 'inv_77_r1',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'confirming',
                'is_final' => false,
                'expires_at' => $now + 5000,
            ]),
            // 5b. POST reuses same -r1 external_order_id
            jsonResponse(200, [
                'invoice_id' => 'inv_77_r1',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'confirming',
                'payment_url' => 'https://pay.paymos.io/i/inv_77_r1',
                'expires_at' => $now + 5000,
            ]),
        ]);

        $gateway = makeGateway(validLiveConfig(), $db, $transport, $clock);

        $gateway->pay(new Invoice(77, '49.90', 'USD'), '49.90');
        $gateway->pay(new Invoice(77, '55.00', 'USD'), '55.00');
        $gateway->pay(new Invoice(77, '49.90', 'EUR'), '49.90');

        $reqs = $transport->requests();
        $order1 = json_decode($reqs[0]['body'], true)['external_order_id'];
        $order2 = json_decode($reqs[1]['body'], true)['external_order_id'];
        $order3 = json_decode($reqs[2]['body'], true)['external_order_id'];

        assertTrue($order1 !== $order2, 'Amount edit (49.90 -> 55.00) must produce a distinct external_order_id.');
        assertTrue($order1 !== $order3, 'Currency change (USD -> EUR) must produce a distinct external_order_id.');

        // Advance clock past expires_at + InvoiceRenewal::CLOCK_SKEW_SECONDS (1800 + 300)
        $now += 2200;
        $renewedUrl = $gateway->pay(new Invoice(77, '49.90', 'USD'), '49.90');
        assertSame('https://pay.paymos.io/i/inv_77_r1', $renewedUrl, 'Expired invoice must mint renewed checkout.');

        $reqs = $transport->requests();
        assertSame('GET', $reqs[3]['method'], 'Must fetch live invoice status before renewing.');
        assertTrue(str_ends_with($reqs[3]['url'], '/v1/invoices/inv_77_v1'), 'GET must check inv_77_v1.');
        $renewedOrderId = json_decode($reqs[4]['body'], true)['external_order_id'];
        assertSame($order1 . '-r1', $renewedOrderId, 'Renewed invoice must append -r1 suffix.');

        // Advance clock past inv_77_r1 expires_at + 300, but server reports confirming -> MUST NOT increment to -r2
        $now += 3500;
        $gateway->pay(new Invoice(77, '49.90', 'USD'), '49.90');
        $reqs = $transport->requests();
        assertSame('GET', $reqs[5]['method'], 'Must fetch live status for inv_77_r1.');
        $reusedOrderId = json_decode($reqs[6]['body'], true)['external_order_id'];
        assertSame($order1 . '-r1', $reusedOrderId, 'Confirming invoice must NOT be renewed to -r2.');
    }

    // ── 6. RFC 9457 Problem Details surfaced safely ───────────────────────────
    function testProblemDetails(): void
    {
        $contract = loadContract();
        $db = new InMemoryDbDriver();

        $rateLimitBody = [
            'type' => 'https://paymos.io/docs/errors/codes#rate_limited',
            'title' => 'Too Many Requests',
            'status' => 429,
            'detail' => 'Rate limit exceeded for POST /v1/invoices.',
            'code' => 'rate_limited',
        ];
        $flat409 = $contract['vectors']['problem_details']['flat'];
        $multi400 = $contract['vectors']['problem_details']['multi'];

        $transport = new MockTransport([
            jsonResponse(429, $rateLimitBody, ['content-type' => 'application/problem+json', 'retry-after' => '5']),
            jsonResponse(409, $flat409, ['content-type' => 'application/problem+json']),
            jsonResponse(400, $multi400, ['content-type' => 'application/problem+json']),
        ]);

        // Use retry-less MockTransport directly (RetryingTransport is only used when transport is null)
        $gateway = makeGateway(validLiveConfig(), $db, $transport);
        $invoice = new Invoice(88, '25.00', 'USD');

        foreach ([
            ['429', 'Rate limit exceeded for POST /v1/invoices.', 'rate_limited'],
            ['409', $flat409['detail'], $flat409['code']],
            ['400', $multi400['detail'], $multi400['code']],
        ] as [$statusLabel, $expectedDetail, $expectedCode]) {
            $thrownMessage = '';
            try {
                $gateway->pay($invoice, '25.00');
            } catch (\RuntimeException $e) {
                $thrownMessage = $e->getMessage();
            }
            assertTrue($thrownMessage !== '', 'HTTP ' . $statusLabel . ' must throw exception.');
            assertTrue(str_contains($thrownMessage, $expectedDetail), 'Exception must contain RFC 9457 detail for HTTP ' . $statusLabel);
            assertTrue(str_contains($thrownMessage, $expectedCode), 'Exception must contain RFC 9457 code for HTTP ' . $statusLabel);
            assertTrue(!str_contains($thrownMessage, 'sk_live_unitsecret123'), 'Exception must never leak API secret.');
        }
    }

    // ── 7. Webhook reject forged, stale, malformed + contract.json vectors ───
    function testWebhookRejectForgedAndStale(): void
    {
        ExtensionHelper::reset();
        Invoice::resetStore();
        $now = 1700000000;
        $clock = static fn (): int => $now;
        $db = new InMemoryDbDriver();
        $gateway = makeGateway(validLiveConfig(), $db, new MockTransport([]), $clock);

        $payload = json_encode([
            'event_id' => 'evt_bad_1',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => ['invoice_id' => 'inv_1', 'project_id' => 'prj_paymenter_live_001', 'status' => 'paid', 'is_test' => false],
        ], JSON_UNESCAPED_SLASHES);
        assertTrue(is_string($payload), 'Payload encode failed.');

        // Missing signature -> 401
        $resMissing = $gateway->webhook(new Request($payload, []));
        assertSame(401, $resMissing['status'], 'Missing signature must return HTTP 401.');

        // Forged signature -> 401
        $resForged = $gateway->webhook(new Request($payload, [
            'X-Webhook-Signature' => 't=' . $now . ',v1=' . str_repeat('a', 64),
        ]));
        assertSame(401, $resForged['status'], 'Forged signature must return HTTP 401.');

        // Stale timestamp (>300s old) -> 401
        $staleTs = $now - 301;
        $resStale = $gateway->webhook(new Request($payload, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $staleTs, $payload),
        ]));
        assertSame(401, $resStale['status'], 'Stale timestamp (>300s) must return HTTP 401.');

        // Empty body -> 401 (bad signature) or 400 (valid sig on invalid JSON)
        $resEmpty = $gateway->webhook(new Request('', [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, ''),
        ]));
        assertSame(400, $resEmpty['status'], 'Empty body with valid signature must return HTTP 400.');

        // Malformed JSON with valid signature -> 400
        $badJson = '{not-json';
        $resBadJson = $gateway->webhook(new Request($badJson, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $badJson),
        ]));
        assertSame(400, $resBadJson['status'], 'Invalid JSON payload must return HTTP 400.');

        assertSame(0, count(ExtensionHelper::$payments), '0 addPayment calls allowed on rejected webhooks.');

        // Verify contract.json webhook dual-v1 rotation + TON vectors via SDK WebhookVerifier
        $contract = loadContract();
        $whVec = $contract['vectors']['webhook'];
        $verifier = new WebhookVerifier($whVec['secret'], (int) $whVec['tolerance_seconds']);
        $decoded = $verifier->decodeVerifiedPayload($whVec['header'], $whVec['raw_body'], (int) $whVec['now']);
        assertSame('evt_123', $decoded['event_id'], 'contract.json dual-v1 webhook vector must verify.');

        $ton = $contract['vectors']['ton_webhooks'];
        $tonVerifier = new WebhookVerifier($ton['secret'], 300);
        foreach ($ton['vectors'] as $tv) {
            $tvDecoded = $tonVerifier->decodeVerifiedPayload($tv['header'], $tv['raw_body'], (int) $ton['now']);
            assertSame($tv['event_id'], $tvDecoded['event_id'], 'TON contract vector must verify: ' . $tv['name']);
        }
    }

    // ── 8. Complete verified invoice (dual-v1, reverse-verify, tx_hash, commit) ──
    function testWebhookCompleteVerified(): void
    {
        ExtensionHelper::reset();
        Invoice::resetStore();
        $now = 1700000000;
        $clock = static fn (): int => $now;
        $db = new InMemoryDbDriver();

        $invoice = new Invoice(77, '49.90', 'USD', 'pending', 42);
        Invoice::put($invoice);

        $extOrderId = 'pmt-77-' . substr(hash('sha256', '49.9:USD'), 0, 12);
        $snapshots = new SnapshotStore($db, $clock);
        $snapshots->save([
            'external_order_id' => $extOrderId,
            'paymos_invoice_id' => 'inv_77_verified',
            'platform_ref' => '77',
            'client_id' => '42',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_77_verified',
            'expires_at' => $now + 1800,
            'renewal_attempt' => 0,
            'final_status' => null,
        ]);

        $invoiceApiPayload = [
            'invoice_id' => 'inv_77_verified',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'paid',
            'is_final' => true,
            'is_test' => false,
            'order' => [
                'external_id' => $extOrderId,
                'amount' => '49.9',
                'currency' => 'USD',
            ],
            'payment' => [
                'currency' => 'USDT',
                'network' => 'TRC20',
                'transfers' => [
                    ['tx_hash' => '0xpending_1', 'amount' => '49.9', 'status' => 'confirming'],
                    ['tx_hash' => '0xconfirmed_tx_hash_99', 'amount' => '49.9', 'status' => 'confirmed'],
                ],
            ],
        ];

        $rawBody = (string) json_encode([
            'event_id' => 'evt_paid_77',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $invoiceApiPayload,
        ], JSON_UNESCAPED_SLASHES);

        // Dual-v1 rotation header + lowercase header name + tampered X-Webhook-Id
        $dualSig = signWebhook('whsec_unitsecret123', $now, $rawBody, str_repeat('f', 64));

        // First attempt: simulate addPayment throwing -> lock MUST be released (NOT committed!), HTTP 500
        ExtensionHelper::$throwOnNextCall = new \RuntimeException('Simulated DB outage during addPayment');
        $transport1 = new MockTransport([jsonResponse(200, $invoiceApiPayload)]);
        $gateway1 = makeGateway(validLiveConfig(), $db, $transport1, $clock);
        $failRes = $gateway1->webhook(new Request($rawBody, [
            'x-webhook-signature' => $dualSig,
            'X-Webhook-Id' => 'evt_spoofed_header_id',
        ]));
        assertSame(500, $failRes['status'], 'Failed native mutation must return HTTP 500.');
        assertSame(0, count(ExtensionHelper::$payments), 'No payment recorded when addPayment throws.');
        $eventStore = new PdoEventStore($db, $clock);
        assertSame(false, $eventStore->isCommitted('evt_paid_77'), 'Failed delivery MUST release lock and NOT commit.');

        // Retry succeeds: reverse-verify + addPayment + snapshot transaction_id + commit -> HTTP 200
        $transport2 = new MockTransport([jsonResponse(200, $invoiceApiPayload)]);
        $gateway2 = makeGateway(validLiveConfig(), $db, $transport2, $clock);
        $okRes = $gateway2->webhook(new Request($rawBody, [
            'x-webhook-signature' => $dualSig,
            'X-Webhook-Id' => 'evt_another_spoofed_header',
        ]));
        assertSame(200, $okRes['status'], 'Verified webhook must return HTTP 200.');
        assertSame(1, count($transport2->requests()), 'Reverse-verify GET /v1/invoices/inv_77_verified must execute.');
        assertSame('GET', $transport2->requests()[0]['method'], 'Reverse-verify must use GET.');
        assertSame(1, count(ExtensionHelper::$payments), 'ExtensionHelper::addPayment must be called once.');
        assertSame(77, ExtensionHelper::$payments[0]['invoice_id'], 'addPayment invoice_id must be 77.');
        assertSame('Paymos', ExtensionHelper::$payments[0]['gateway'], 'addPayment gateway must be Paymos.');
        assertSame('49.9', ExtensionHelper::$payments[0]['amount'], 'addPayment amount must be 49.9.');
        assertSame('0xconfirmed_tx_hash_99', ExtensionHelper::$payments[0]['transaction_id'], 'addPayment must receive confirmed tx_hash.');
        assertSame('paid', Invoice::find(77)?->status, 'Paymenter invoice must now be paid.');
        assertSame(true, $eventStore->isCommitted('evt_paid_77'), 'Event must be committed strictly after mutation.');

        $updatedSnap = $snapshots->findByTransactionId('0xconfirmed_tx_hash_99');
        assertTrue($updatedSnap !== null, 'Snapshot must be retrievable by transaction_id after payment.');
        assertSame('inv_77_verified', $updatedSnap['paymos_invoice_id'], 'Snapshot transaction_id lookup must resolve inv_77_verified.');
        assertSame('paid', $updatedSnap['final_status'], 'Snapshot final_status must be paid.');

        // Sandbox fallback: empty transfers[] -> transaction_id falls back to paymos_invoice_id
        $sbInvoice = new Invoice(78, '10', 'USD', 'pending', 43);
        Invoice::put($sbInvoice);
        $sbExt = 'pmt-78-' . substr(hash('sha256', '10:USD'), 0, 12);
        $snapshots->save([
            'external_order_id' => $sbExt,
            'paymos_invoice_id' => 'inv_78_sandbox',
            'platform_ref' => '78',
            'amount' => '10',
            'currency' => 'USD',
            'environment' => 'sandbox',
            'project_id' => 'prj_paymenter_sb_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_78_sandbox',
        ]);
        $sbApi = [
            'invoice_id' => 'inv_78_sandbox',
            'project_id' => 'prj_paymenter_sb_001',
            'status' => 'paid',
            'is_final' => true,
            'is_test' => true,
            'order' => ['external_id' => $sbExt, 'amount' => '10', 'currency' => 'USD'],
        ];
        $sbBody = (string) json_encode([
            'event_id' => 'evt_sb_paid_78',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $sbApi,
        ], JSON_UNESCAPED_SLASHES);
        $sbGateway = makeGateway(validLiveConfig([
            'environment' => 'sandbox',
            'project_id' => 'prj_paymenter_sb_001',
            'api_key' => 'pk_test_unitkey123',
            'api_secret' => 'sk_test_unitsecret123',
        ]), $db, new MockTransport([jsonResponse(200, $sbApi)]), $clock);
        $sbRes = $sbGateway->webhook(new Request($sbBody, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $sbBody),
        ]));
        assertSame(200, $sbRes['status'], 'Sandbox webhook must succeed.');
        assertSame('inv_78_sandbox', ExtensionHelper::$payments[1]['transaction_id'], 'Empty transfers[] must fallback transaction_id to paymos_invoice_id.');
    }

    // ── 9. Hold for manual_review on AmountGuard or reverse-verify mismatch ──
    function testWebhookHoldOnMismatch(): void
    {
        ExtensionHelper::reset();
        Invoice::resetStore();
        $now = 1700000000;
        $clock = static fn (): int => $now;
        $db = new InMemoryDbDriver();

        // Case A: Billing invoice amount changed after Paymos invoice creation (49.9 -> 55.00)
        Invoice::put(new Invoice(90, '55.00', 'USD', 'pending', 10));
        $ext90 = 'pmt-90-' . substr(hash('sha256', '49.9:USD'), 0, 12);
        $snapshots = new SnapshotStore($db, $clock);
        $snapshots->save([
            'external_order_id' => $ext90,
            'paymos_invoice_id' => 'inv_90_amt',
            'platform_ref' => '90',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_90_amt',
        ]);

        $api90 = [
            'invoice_id' => 'inv_90_amt',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'paid',
            'is_final' => true,
            'is_test' => false,
            'order' => ['external_id' => $ext90, 'amount' => '49.9', 'currency' => 'USD'],
        ];
        $body90 = (string) json_encode([
            'event_id' => 'evt_amt_mismatch_90',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $api90,
        ], JSON_UNESCAPED_SLASHES);

        $logs = [];
        $gateway = makeGateway(validLiveConfig(), $db, new MockTransport([jsonResponse(200, $api90)]), $clock, $logs);
        $resAmt = $gateway->webhook(new Request($body90, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $body90),
        ]));

        assertSame(400, $resAmt['status'], 'AmountGuard mismatch must return HTTP 400.');
        assertSame('manual_review', $resAmt['body']['error'] ?? null, 'Error body must indicate manual_review.');
        assertSame(0, count(ExtensionHelper::$payments), '0 addPayment calls allowed on AmountGuard mismatch.');
        $snap90 = $snapshots->findByExternalOrderId($ext90);
        assertSame(null, $snap90['final_status'], 'AmountGuard mismatch must NOT set final_status.');
        $notes = $gateway->getRecordedNotes();
        assertTrue(count($notes) > 0 && str_contains($notes[0]['note'], 'manual_review'), 'manual_review note must be logged.');
        $eventStore = new PdoEventStore($db, $clock);
        assertSame(false, $eventStore->isCommitted('evt_amt_mismatch_90'), 'Lock must be released on AmountGuard mismatch.');
        assertSame(true, $eventStore->remember('evt_amt_mismatch_90', 604800), 'Released lock must be re-acquirable.');
        $eventStore->release('evt_amt_mismatch_90');

        // Case B: Reverse-verify mismatch (server says awaiting_payment while webhook claims paid)
        Invoice::put(new Invoice(91, '49.90', 'USD', 'pending', 11));
        $ext91 = 'pmt-91-' . substr(hash('sha256', '49.9:USD'), 0, 12);
        $snapshots->save([
            'external_order_id' => $ext91,
            'paymos_invoice_id' => 'inv_91_rv',
            'platform_ref' => '91',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_91_rv',
        ]);
        $wh91 = [
            'event_id' => 'evt_rv_mismatch_91',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => [
                'invoice_id' => 'inv_91_rv',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'paid',
                'is_final' => true,
                'is_test' => false,
                'order' => ['external_id' => $ext91, 'amount' => '49.9', 'currency' => 'USD'],
            ],
        ];
        $live91 = $wh91['data'];
        $live91['status'] = 'awaiting_payment';
        $body91 = (string) json_encode($wh91, JSON_UNESCAPED_SLASHES);
        $gatewayRv = makeGateway(validLiveConfig(), $db, new MockTransport([jsonResponse(200, $live91)]), $clock);
        $resRv = $gatewayRv->webhook(new Request($body91, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $body91),
        ]));
        assertSame(400, $resRv['status'], 'Reverse-verify mismatch must return HTTP 400.');
        assertSame(0, count(ExtensionHelper::$payments), '0 addPayment calls allowed on reverse-verify mismatch.');
        assertSame(null, $snapshots->findByExternalOrderId($ext91)['final_status'], 'final_status must remain null on reverse-verify mismatch.');
        assertSame(false, $eventStore->isCommitted('evt_rv_mismatch_91'), 'Lock must be released on reverse-verify mismatch.');

        // Case C: is_test mismatch & project_id mismatch -> HTTP 400 + released
        $whEnvBad = $wh91;
        $whEnvBad['event_id'] = 'evt_env_bad_91';
        $whEnvBad['data']['is_test'] = true;
        $bodyEnvBad = (string) json_encode($whEnvBad, JSON_UNESCAPED_SLASHES);
        $resEnv = $gatewayRv->webhook(new Request($bodyEnvBad, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $bodyEnvBad),
        ]));
        assertSame(400, $resEnv['status'], 'is_test mismatch must return HTTP 400.');

        $whProjBad = $wh91;
        $whProjBad['event_id'] = 'evt_proj_bad_91';
        $whProjBad['data']['project_id'] = 'prj_other_999';
        $bodyProjBad = (string) json_encode($whProjBad, JSON_UNESCAPED_SLASHES);
        $resProj = $gatewayRv->webhook(new Request($bodyProjBad, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $bodyProjBad),
        ]));
        assertSame(400, $resProj['status'], 'project_id mismatch must return HTTP 400.');
    }

    // ── 10. Dedup committed (200) vs in-flight (409), CAS takeover, lost lock ──
    function testWebhookDedupAndInFlight(): void
    {
        ExtensionHelper::reset();
        Invoice::resetStore();
        $now = 1700000000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $db = new InMemoryDbDriver();
        $worker1 = new PdoEventStore($db, $clock);
        $worker2 = new PdoEventStore($db, $clock);

        assertSame(300, PdoEventStore::RESERVATION_SECONDS, 'RESERVATION_SECONDS must be 300.');

        // Worker 1 reserves evt_inflight_1
        assertSame(true, $worker1->remember('evt_inflight_1', 604800), 'Worker 1 must acquire initial lock.');
        assertSame(false, $worker2->isCommitted('evt_inflight_1'), 'In-flight lock must NOT appear committed.');

        // Concurrent HTTP webhook for evt_inflight_1 must return HTTP 409 (NOT 200!)
        $inflightBody = (string) json_encode([
            'event_id' => 'evt_inflight_1',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => ['invoice_id' => 'inv_1', 'project_id' => 'prj_paymenter_live_001', 'status' => 'paid', 'is_test' => false],
        ], JSON_UNESCAPED_SLASHES);
        $gateway = makeGateway(validLiveConfig(), $db, new MockTransport([]), $clock);
        $res409 = $gateway->webhook(new Request($inflightBody, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $inflightBody),
        ]));
        assertSame(409, $res409['status'], 'In-flight concurrent delivery must return HTTP 409.');

        // Reservation expires at exactly $now + 300 (exercises CAS takeover while row still exists!)
        $now += PdoEventStore::RESERVATION_SECONDS;
        assertSame(true, $worker2->remember('evt_inflight_1', 604800), 'Worker 2 must take over expired reservation via CAS.');

        // Stale Worker 1 attempts commit() -> MUST return false (lost lock)
        assertSame(false, $worker1->commit('evt_inflight_1'), 'Stale owner commit() must return false on lost lock.');

        // Worker 2 commits its active reservation cleanly
        assertSame(false, $worker2->isCommitted('evt_inflight_1'), 'Worker 2 lock still processing, not committed.');
        assertSame(true, $worker2->commit('evt_inflight_1'), 'Worker 2 must still own and commit its reservation.');
        assertSame(true, $worker2->isCommitted('evt_inflight_1'), 'Committed event must report isCommitted === true.');

        // Separate stale Worker A attempts release() directly (without prior commit()) after Worker B takes over -> MUST NOT delete Worker B's lock
        $workerA = new PdoEventStore($db, $clock);
        $workerB = new PdoEventStore($db, $clock);
        assertSame(true, $workerA->remember('evt_stale_release_1', 604800), 'Worker A acquires evt_stale_release_1.');
        $now += PdoEventStore::RESERVATION_SECONDS;
        assertSame(true, $workerB->remember('evt_stale_release_1', 604800), 'Worker B takes over expired evt_stale_release_1.');
        $workerA->release('evt_stale_release_1');
        assertSame(true, $workerB->commit('evt_stale_release_1'), 'Stale Worker A release() must leave Worker B reservation intact so Worker B can commit.');
        assertSame(true, $workerB->isCommitted('evt_stale_release_1'), 'Worker B committed event must stay committed.');

        // Committed duplicate webhook (even with different X-Webhook-Id!) -> HTTP 200
        $res200Dup = $gateway->webhook(new Request($inflightBody, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $inflightBody),
            'X-Webhook-Id' => 'evt_different_header_id',
        ]));
        assertSame(200, $res200Dup['status'], 'Committed duplicate must return HTTP 200.');
        assertSame('duplicate', $res200Dup['body']['status'] ?? null, 'Duplicate body status must be "duplicate".');

        // Expired committed record must report isCommitted() === false
        $now += 604800;
        assertSame(false, $worker2->isCommitted('evt_inflight_1'), 'Expired committed row (expires_at <= now) must return isCommitted() === false.');

        // Bounded cleanup: seed 125 expired rows; one remember() deletes at most 100 expired rows
        $dbClean = new InMemoryDbDriver();
        $cleanStore = new PdoEventStore($dbClean, $clock);
        for ($i = 0; $i < 125; $i++) {
            $dbClean->insertUnique(PdoEventStore::TABLE, [
                'event_id' => 'evt_old_' . $i,
                'owner_token' => 'tok_' . $i,
                'state' => 'committed',
                'expires_at' => $now - 10,
                'created_at' => $now - 1000,
            ], 'event_id');
        }
        assertSame(true, $cleanStore->remember('evt_fresh_after_cleanup', 604800), 'remember() must succeed after bounded cleanup.');
        // 125 - 100 deleted + 1 newly inserted = 26 rows remaining
        assertSame(26, $dbClean->countRows(PdoEventStore::TABLE), 'Bounded cleanup must delete at most 100 expired rows per batch.');
    }

    // ── 11. Roll-back guard on paid invoice + note-only invoice.paid_late ────
    function testRollbackGuardAndPaidLate(): void
    {
        ExtensionHelper::reset();
        Invoice::resetStore();
        $now = 1700000000;
        $clock = static fn (): int => $now;
        $db = new InMemoryDbDriver();
        $snapshots = new SnapshotStore($db, $clock);

        // Already-paid invoice #101 receives stale invoice.cancelled and invoice.confirming
        Invoice::put(new Invoice(101, '49.90', 'USD', 'paid', 50));
        $ext101 = 'pmt-101-' . substr(hash('sha256', '49.9:USD'), 0, 12);
        $snapshots->save([
            'external_order_id' => $ext101,
            'paymos_invoice_id' => 'inv_101_paid',
            'platform_ref' => '101',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_101_paid',
            'transaction_id' => '0xpaid101',
            'final_status' => 'paid',
        ]);

        $transport = new MockTransport([]); // 0 HTTP calls needed because rollback guard triggers before reverse-verify
        $gateway = makeGateway(validLiveConfig(), $db, $transport, $clock);

        foreach ([
            ['evt_cancel_late_101', 'invoice.cancelled', 'cancelled'],
            ['evt_confirm_late_101', 'invoice.confirming', 'confirming'],
        ] as [$evtId, $evtType, $evtStatus]) {
            $body = (string) json_encode([
                'event_id' => $evtId,
                'event_type' => $evtType,
                'version' => 1,
                'occurred_at' => $now,
                'data' => [
                    'invoice_id' => 'inv_101_paid',
                    'project_id' => 'prj_paymenter_live_001',
                    'status' => $evtStatus,
                    'is_test' => false,
                    'order' => ['external_id' => $ext101, 'amount' => '49.9', 'currency' => 'USD'],
                ],
            ], JSON_UNESCAPED_SLASHES);

            $res = $gateway->webhook(new Request($body, [
                'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $body),
            ]));
            assertSame(200, $res['status'], 'Roll-back guarded event must return HTTP 200.');
            assertSame('ignored_final', $res['body']['status'] ?? null, 'Roll-back guarded event must be ignored_final.');
        }

        assertSame('paid', Invoice::find(101)?->status, 'Paid invoice must remain paid.');
        assertSame('paid', $snapshots->findByExternalOrderId($ext101)['final_status'], 'Snapshot final_status must remain paid.');
        assertSame(0, count(ExtensionHelper::$payments), '0 addPayment calls on rollback events.');

        // Expired invoice #102 receives invoice.paid_late (twice with distinct event_ids -> idempotent single note!)
        Invoice::put(new Invoice(102, '30.00', 'USD', 'pending', 51));
        $ext102 = 'pmt-102-' . substr(hash('sha256', '30:USD'), 0, 12);
        $snapshots->save([
            'external_order_id' => $ext102,
            'paymos_invoice_id' => 'inv_102_exp',
            'platform_ref' => '102',
            'amount' => '30',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_102_exp',
            'final_status' => 'expired',
        ]);

        $gatewayLate = makeGateway(validLiveConfig(), $db, new MockTransport([]), $clock);
        foreach (['evt_paid_late_1', 'evt_paid_late_2'] as $lateEvtId) {
            $lateBody = (string) json_encode([
                'event_id' => $lateEvtId,
                'event_type' => 'invoice.paid_late',
                'version' => 1,
                'occurred_at' => $now,
                'data' => [
                    'invoice_id' => 'inv_102_exp',
                    'project_id' => 'prj_paymenter_live_001',
                    'status' => 'expired',
                    'is_test' => false,
                    'paid_late_at' => $now - 5,
                    'order' => ['external_id' => $ext102, 'amount' => '30', 'currency' => 'USD'],
                ],
            ], JSON_UNESCAPED_SLASHES);

            $lateRes = $gatewayLate->webhook(new Request($lateBody, [
                'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $lateBody),
            ]));
            assertSame(200, $lateRes['status'], 'invoice.paid_late must return HTTP 200.');
            assertSame('paid_late_noted', $lateRes['body']['status'] ?? null, 'paid_late status must be paid_late_noted.');
        }

        assertSame(0, count(ExtensionHelper::$payments), 'invoice.paid_late MUST NOT call ExtensionHelper::addPayment.');
        assertSame('pending', Invoice::find(102)?->status, 'invoice.paid_late MUST NOT change invoice payment status.');
        assertSame('expired', $snapshots->findByExternalOrderId($ext102)['final_status'], 'invoice.paid_late MUST keep expired final_status.');
        assertSame(1, count($gatewayLate->getRecordedNotes()), 'invoice.paid_late note must be recorded idempotently once.');
    }

    // ── 12. Full refund with unambiguous transaction_id lookup & withdrawal_id ──
    function testFullRefund(): void
    {
        $contract = loadContract();
        $now = 1700000000;
        $clock = static fn (): int => $now;
        $db = new InMemoryDbDriver();
        $snapshots = new SnapshotStore($db, $clock);

        // Multiple snapshots for the SAME Paymenter invoice #77
        $snapshots->save([
            'external_order_id' => 'pmt-77-old-expired',
            'paymos_invoice_id' => 'inv_77_expired',
            'platform_ref' => '77',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_77_expired',
            'transaction_id' => null,
            'final_status' => 'expired',
            'updated_at' => $now - 200,
        ]);
        $snapshots->save([
            'external_order_id' => 'pmt-77-paid-a',
            'paymos_invoice_id' => 'inv_77_paid_a',
            'platform_ref' => '77',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_77_paid_a',
            'transaction_id' => '0xtx_paid_a',
            'final_status' => 'paid',
            'updated_at' => $now - 100,
        ]);
        $snapshots->save([
            'external_order_id' => 'pmt-77-paid-b',
            'paymos_invoice_id' => 'inv_77_paid_b',
            'platform_ref' => '77',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_77_paid_b',
            'transaction_id' => '0xtx_paid_b',
            'final_status' => 'paid',
            'updated_at' => $now - 50,
        ]);

        $refundWire = [
            'withdrawal_id' => 'wdr_refund_77_b',
            'invoice_id' => 'inv_77_paid_b',
            'mode' => 'full',
            'amount' => '49.9',
            'currency' => 'USDT',
            'network' => 'TRC20',
            'status' => 'pending',
            'first_sender' => 'TFirstSenderAddress123',
            'created_at' => $now,
            'type' => 'refund',
            'project_id' => 'prj_paymenter_live_001',
        ];
        // Verify fixture covers every required RefundStatusContract field from contract.json
        foreach ($contract['vectors']['refunds']['wire']['required_fields'] as $field) {
            assertTrue(array_key_exists($field, $refundWire), 'RefundStatusContract field missing in fixture: ' . $field);
        }

        $refundCompleted = array_merge($refundWire, ['status' => 'completed']);
        $refundMissingWithdrawal = array_merge($refundWire, ['withdrawal_id' => '', 'refund_id' => 'fake_ignored']);
        $refundFailed = array_merge($refundWire, ['status' => 'failed']);
        $refundCancelled = array_merge($refundWire, ['status' => 'cancelled']);

        $transport = new MockTransport([
            jsonResponse(201, $refundWire),
            jsonResponse(200, $refundCompleted),
            jsonResponse(201, $refundMissingWithdrawal),
            jsonResponse(201, $refundFailed),
            jsonResponse(201, $refundCancelled),
        ]);

        $gateway = makeGateway(validLiveConfig(), $db, $transport, $clock);
        assertSame(true, $gateway->supportsRefunds(), 'supportsRefunds() must return true.');

        $txB = new InvoiceTransaction(501, 77, '0xtx_paid_b', '49.90');
        assertSame(true, $gateway->refund($txB, '49.90'), 'Full refund (pending) must return true.');

        $req = $transport->requests()[0];
        assertSame('POST', $req['method'], 'Refund must use POST.');
        assertSame('https://api.paymos.io/v1/refunds', $req['url'], 'Refund must target /v1/refunds.');
        $payload = json_decode($req['body'], true);
        assertTrue(is_array($payload), 'Refund body must be valid JSON.');
        $keys = array_keys($payload);
        sort($keys);
        assertSame(['external_order_id', 'invoice_id', 'mode'], $keys, 'POST /v1/refunds must send EXACTLY 3 keys.');
        assertSame('inv_77_paid_b', $payload['invoice_id'], 'Must resolve exact inv_77_paid_b via findByTransactionId().');
        assertSame('full', $payload['mode'], 'Refund mode must be "full".');
        foreach ($contract['vectors']['refunds']['create']['rejected_fields'] as $forbiddenField) {
            assertTrue(!array_key_exists($forbiddenField, $payload), 'Forbidden refund field sent: ' . $forbiddenField);
        }

        // Completed status also succeeds
        assertSame(true, $gateway->refund($txB, '49.90'), 'Full refund (completed) must return true.');

        // Fail-closed: empty withdrawal_id (even if bogus refund_id exists) throws RuntimeException
        foreach (['missing withdrawal_id', 'failed status', 'cancelled status'] as $case) {
            $threw = false;
            try {
                $gateway->refund($txB, '49.90');
            } catch (\RuntimeException $e) {
                $threw = true;
            }
            assertTrue($threw, 'refund() must fail closed on ' . $case . '.');
        }

        // Fail-closed: ambiguous fallback when transaction_id is unknown and >1 paid snapshots exist for invoice #77
        $ambiguousTx = new InvoiceTransaction(502, 77, '0xunknown_tx', '49.90');
        $ambiguousFailed = false;
        try {
            $gateway->refund($ambiguousTx, '49.90');
        } catch (\RuntimeException $e) {
            $ambiguousFailed = true;
        }
        assertTrue($ambiguousFailed, 'Ambiguous invoice lookup with multiple paid snapshots must fail closed.');
    }

    // ── 13. Reject unsupported partial refund amount before network ──────────
    function testRejectPartialRefund(): void
    {
        $now = 1700000000;
        $clock = static fn (): int => $now;
        $db = new InMemoryDbDriver();
        $snapshots = new SnapshotStore($db, $clock);
        $snapshots->save([
            'external_order_id' => 'pmt-77-full',
            'paymos_invoice_id' => 'inv_77_full',
            'platform_ref' => '77',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_77_full',
            'transaction_id' => '0xtx_77_full',
            'final_status' => 'paid',
        ]);

        $transport = new MockTransport([]);
        $gateway = makeGateway(validLiveConfig(), $db, $transport, $clock);
        $tx = new InvoiceTransaction(600, 77, '0xtx_77_full', '49.90');

        foreach (['10.00', '0', '-5.00', '50.00'] as $badAmount) {
            $threw = false;
            try {
                $gateway->refund($tx, $badAmount);
            } catch (\InvalidArgumentException $e) {
                $threw = true;
            }
            assertTrue($threw, 'refund() must throw InvalidArgumentException for amount ' . $badAmount);
        }

        assertSame(0, count($transport->requests()), '0 HTTP calls may occur for partial/invalid refund amounts.');
    }

    // ── 14. Packaging, Reflection #[ExtensionMeta], Internal\, routes, php -l ─
    function testPackagingMetaRoutesAndLint(): void
    {
        // Reflection on #[ExtensionMeta] and Gateway inheritance
        $ref = new \ReflectionClass(Paymos::class);
        assertTrue($ref->isSubclassOf(Gateway::class), 'Paymos must extend App\\Classes\\Extension\\Gateway.');

        $attrs = $ref->getAttributes(ExtensionMeta::class);
        assertSame(1, count($attrs), 'Paymos must declare exactly one #[ExtensionMeta] attribute.');
        /** @var ExtensionMeta $meta */
        $meta = $attrs[0]->newInstance();
        assertSame('Paymos', $meta->name, 'ExtensionMeta::name must be Paymos.');
        assertSame('1.0.0', $meta->version, 'ExtensionMeta::version must be 1.0.0.');
        assertSame('Paymos Labs', $meta->author, 'ExtensionMeta::author must be Paymos Labs.');
        assertSame('https://paymos.io', $meta->url, 'ExtensionMeta::url must be https://paymos.io.');
        assertTrue(str_contains($meta->description, 'USDT') && str_contains($meta->description, 'XAUT'), 'Description must name supported assets.');
        assertTrue(!preg_match('/xaut[^.]*stablecoin/i', $meta->description), 'Description must never call XAUT a stablecoin.');
        assertTrue(str_starts_with($meta->icon, 'data:image/svg+xml;base64,'), 'ExtensionMeta::icon must be a base64 SVG data URI.');
        $decodedSvg = base64_decode(substr($meta->icon, strlen('data:image/svg+xml;base64,')), true);
        assertTrue(is_string($decodedSvg) && str_starts_with($decodedSvg, '<svg') && str_ends_with($decodedSvg, '</svg>'), 'Decoded icon must be valid SVG.');

        // Verify Internal\ autoloader & class loading without root PSR-4 collision
        Autoloader::register();
        foreach ([
            Config::class,
            DbDriverInterface::class,
            PdoDbDriver::class,
            PdoEventStore::class,
            SnapshotStore::class,
            GatewayService::class,
            Client::class,
        ] as $className) {
            assertTrue(class_exists($className) || interface_exists($className), 'Autoloader must load ' . $className);
        }

        // Verify composer.json PSR-4 Internal\ mapping
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        assertTrue(is_array($composer), 'composer.json must be valid JSON.');
        assertSame('src/', $composer['autoload']['psr-4']['Paymenter\\Extensions\\Gateways\\Paymos\\Internal\\'] ?? null, 'composer.json must map Internal\\ to src/.');
        assertSame('.', $composer['autoload']['psr-4']['Paymenter\\Extensions\\Gateways\\Paymos\\'] ?? null, 'composer.json must map root gateway namespace to ".".');
        assertTrue(str_contains((string) ($composer['require']['php'] ?? ''), '8.2'), 'composer.json must require PHP >= 8.2.');

        // Verify boot() registers CSRF-exempt webhook route and installed() ensures schema
        Route::reset();
        $installDb = new InMemoryDbDriver();
        $gateway = new Paymos(validLiveConfig());
        $gateway->setDbDriver($installDb);
        $gateway->installed();
        assertSame(true, $installDb->schemaEnsured, 'installed() must ensure DB schema.');
        $gateway->boot();
        assertSame(1, count(Route::$registered), 'boot() must register exactly 1 webhook route.');
        $route = Route::$registered[0];
        assertSame('POST', $route->method, 'Webhook route must be POST.');
        assertSame('/extensions/gateways/paymos/webhook', $route->uri, 'Webhook route URI mismatch.');
        assertSame([Paymos::class, 'webhook'], $route->action, 'Webhook route action mismatch.');
        assertTrue(in_array(PreventRequestForgery::class, $route->withoutMiddleware, true), 'Webhook route must exempt PreventRequestForgery.');
        assertSame('extensions.gateways.paymos.webhook', $route->name, 'Webhook route name mismatch.');

        // If pdo_sqlite is available in this runtime (e.g. CI/node), exercise real PdoDbDriver too
        if (extension_loaded('pdo_sqlite')) {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdoDriver = new PdoDbDriver($pdo);
            $pdoDriver->ensureSchema();

            // Fresh PdoDbDriver inside an open transaction must see tables via sqlite_master and emit 0 DDL
            $pdo->beginTransaction();
            $freshDriver = new PdoDbDriver($pdo);
            $pdoEvents = new PdoEventStore($freshDriver, static fn (): int => 1700000000);
            assertSame(true, $pdoEvents->remember('evt_sqlite_1', 604800), 'SQLite PdoEventStore remember() failed.');
            assertSame(false, $pdoEvents->remember('evt_sqlite_1', 604800), 'SQLite duplicate remember() must return false inside tx.');
            assertSame(false, $pdoEvents->isCommitted('evt_sqlite_1'), 'SQLite in-flight event must not be committed.');
            assertSame(true, $pdoEvents->commit('evt_sqlite_1'), 'SQLite commit() must return true.');
            assertSame(true, $pdoEvents->isCommitted('evt_sqlite_1'), 'SQLite committed event must report true.');
            $pdo->commit();
        }

        // Run php -l on every PHP file under plugins/paymenter-paymos/
        $root = dirname(__DIR__);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $checked = 0;
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains(str_replace('\\', '/', $path), '/vendor/')) {
                continue;
            }
            $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1';
            $out = [];
            $code = 0;
            exec($cmd, $out, $code);
            assertSame(0, $code, 'php -l failed for ' . $path . ': ' . implode("\n", $out));
            $checked++;
        }
        assertTrue($checked >= 10, 'Expected at least 10 PHP files linted, checked ' . $checked);
    }

    // ── 15. R2: Transaction-safe schema check (0 DDL in tx) & conflict-safe insertUnique ──
    function testTransactionSafeSchemaAndInsertUnique(): void
    {
        $now = 1700000000;
        $clock = static fn (): int => $now;

        // 15a. MySQL inside active DB::transaction() during Paymos::refund() when tables exist -> 0 DDL statements!
        $mysqlPdo = new RecordingPdo('mysql', tablesExist: true, txActive: true);
        $mysqlPdo->snapshotRows = [[
            'external_order_id' => 'pmt-77-txsafe',
            'paymos_invoice_id' => 'inv_77_txsafe',
            'platform_ref' => '77',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_77_txsafe',
            'transaction_id' => '0xtx_txsafe_1',
            'final_status' => 'paid',
            'updated_at' => $now,
        ]];
        $mysqlDriver = new PdoDbDriver($mysqlPdo);
        $transport = new MockTransport([
            jsonResponse(201, [
                'withdrawal_id' => 'wdr_txsafe_1',
                'invoice_id' => 'inv_77_txsafe',
                'mode' => 'full',
                'amount' => '49.9',
                'currency' => 'USDT',
                'network' => 'TRC20',
                'status' => 'pending',
                'first_sender' => 'TSender123',
                'created_at' => $now,
                'type' => 'refund',
                'project_id' => 'prj_paymenter_live_001',
            ]),
        ]);
        $gateway = new Paymos(validLiveConfig());
        $gateway->setDbDriver($mysqlDriver)->setTransport($transport)->setClock($clock);
        $tx = new InvoiceTransaction(701, 77, '0xtx_txsafe_1', '49.90');
        assertSame(true, $gateway->refund($tx, '49.90'), 'refund() inside active MySQL tx must succeed.');
        assertSame(0, count($mysqlPdo->execCalls), 'ensureSchema() MUST emit 0 DDL statements when tables already exist.');
        assertTrue(
            count($mysqlPdo->preparedCalls) >= 1 && str_contains($mysqlPdo->preparedCalls[0]['sql'], 'information_schema.tables'),
            'MySQL table check must query information_schema.tables.'
        );

        // 15b. Tables missing + active transaction -> refuses DDL, throws BEFORE any HTTP call!
        foreach (['mysql', 'pgsql'] as $dialect) {
            $missingPdo = new RecordingPdo($dialect, tablesExist: false, txActive: true);
            $missingDriver = new PdoDbDriver($missingPdo);
            $emptyTransport = new MockTransport([]);
            $gw = new Paymos(validLiveConfig());
            $gw->setDbDriver($missingDriver)->setTransport($emptyTransport)->setClock($clock);
            $threw = false;
            try {
                $gw->refund($tx, '49.90');
            } catch (\RuntimeException $e) {
                $threw = true;
                assertTrue(str_contains($e->getMessage(), 'active database transaction'), 'Error must explain transaction DDL guard.');
            }
            assertTrue($threw, $dialect . ' must fail closed when tables missing inside active tx.');
            assertSame(0, count($missingPdo->execCalls), $dialect . ' must emit 0 DDL inside active tx.');
            assertSame(0, count($emptyTransport->requests()), '0 HTTP requests may fire if schema missing in tx.');
        }

        // 15c. Outside tx when tables missing -> creates tables once, then fresh driver sees them and emits 0 DDL
        foreach (['mysql', 'pgsql', 'sqlite'] as $dialect) {
            $initPdo = new RecordingPdo($dialect, tablesExist: false, txActive: false);
            $driver1 = new PdoDbDriver($initPdo);
            $driver1->ensureSchema();
            assertTrue(count($initPdo->execCalls) >= 2, $dialect . ' initial ensureSchema() must create tables.');
            if ($dialect === 'mysql') {
                assertSame(2, count($initPdo->execCalls), 'MySQL must inline indexes inside CREATE TABLE (2 DDL statements total).');
            }

            $initPdo->execCalls = [];
            $driver2 = new PdoDbDriver($initPdo);
            $driver2->ensureSchema();
            assertSame(0, count($initPdo->execCalls), $dialect . ' fresh PdoDbDriver must skip DDL after tables exist.');
        }

        // 15d. Conflict-safe insertUnique() SQL for mysql (INSERT IGNORE) and pgsql/sqlite (ON CONFLICT DO NOTHING)
        $mysqlInsertPdo = new RecordingPdo('mysql', tablesExist: true, txActive: true);
        $mysqlInsertDriver = new PdoDbDriver($mysqlInsertPdo);
        $eventRow = ['event_id' => 'evt_cs_1', 'owner_token' => 'tok1', 'state' => 'processing', 'expires_at' => $now + 300, 'created_at' => $now];
        assertSame(true, $mysqlInsertDriver->insertUnique(PdoEventStore::TABLE, $eventRow, 'event_id'), 'MySQL first insertUnique must return true.');
        assertSame(false, $mysqlInsertDriver->insertUnique(PdoEventStore::TABLE, $eventRow, 'event_id'), 'MySQL duplicate insertUnique must return false without throwing.');
        $mysqlInsertSql = $mysqlInsertPdo->preparedCalls[0]['sql'];
        assertTrue(str_starts_with($mysqlInsertSql, 'INSERT IGNORE INTO paymos_webhook_events'), 'MySQL insertUnique must use INSERT IGNORE.');

        foreach (['pgsql', 'sqlite'] as $dialect) {
            $conflictPdo = new RecordingPdo($dialect, tablesExist: true, txActive: true);
            $conflictDriver = new PdoDbDriver($conflictPdo);
            assertSame(true, $conflictDriver->insertUnique(PdoEventStore::TABLE, $eventRow, 'event_id'), $dialect . ' first insertUnique must return true.');
            assertSame(false, $conflictDriver->insertUnique(PdoEventStore::TABLE, $eventRow, 'event_id'), $dialect . ' duplicate insertUnique must return false without aborting tx.');
            $sql = $conflictPdo->preparedCalls[0]['sql'];
            assertTrue(str_contains($sql, 'ON CONFLICT (event_id) DO NOTHING'), $dialect . ' insertUnique must use ON CONFLICT (event_id) DO NOTHING.');
        }
    }

    // ── 16. R6: Partially credited invoice compares against $invoice->remaining ──
    function testPartiallyCreditedInvoiceRemainingAmount(): void
    {
        ExtensionHelper::reset();
        Invoice::resetStore();
        $now = 1700000000;
        $clock = static fn (): int => $now;
        $db = new InMemoryDbDriver();

        // Invoice total is 100.00 USD, but 40.00 credit already applied -> remaining is 60.00 USD
        $invoice = new Invoice(120, '100.00', 'USD', 'pending', 60);
        $invoice->remaining = '60.00';
        Invoice::put($invoice);

        $ext120 = 'pmt-120-' . substr(hash('sha256', '60:USD'), 0, 12);
        $api120 = [
            'invoice_id' => 'inv_120_partial',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'paid',
            'is_final' => true,
            'is_test' => false,
            'order' => ['external_id' => $ext120, 'amount' => '60', 'currency' => 'USD'],
            'payment' => [
                'transfers' => [
                    ['tx_hash' => '0xpartial_credit_tx_120', 'amount' => '60', 'status' => 'confirmed'],
                ],
            ],
        ];

        $transport = new MockTransport([
            jsonResponse(201, [
                'invoice_id' => 'inv_120_partial',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'awaiting_client',
                'payment_url' => 'https://pay.paymos.io/i/inv_120_partial',
                'expires_at' => $now + 1800,
            ]),
            jsonResponse(200, $api120),
        ]);

        $gateway = makeGateway(validLiveConfig(), $db, $transport, $clock);
        $payUrl = $gateway->pay($invoice, $invoice->remaining);
        assertSame('https://pay.paymos.io/i/inv_120_partial', $payUrl, 'Checkout for remaining balance must succeed.');

        $rawBody = (string) json_encode([
            'event_id' => 'evt_partial_120',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $api120,
        ], JSON_UNESCAPED_SLASHES);

        $res = $gateway->webhook(new Request($rawBody, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $rawBody),
        ]));
        assertSame(200, $res['status'], 'Webhook for partially credited invoice (total=100, remaining=60) must return HTTP 200, not 400 manual_review.');
        assertSame(1, count(ExtensionHelper::$payments), 'addPayment must be called once for partially credited invoice.');
        assertSame('60', ExtensionHelper::$payments[0]['amount'], 'Credited amount must match remaining 60.');
        assertSame('0xpartial_credit_tx_120', ExtensionHelper::$payments[0]['transaction_id'], 'Transaction ID must match confirmed tx_hash.');

        // Also verify fallback to $invoice->total when $invoice->remaining is empty
        $invoiceFallback = new Invoice(121, '25.00', 'USD', 'pending', 61);
        $invoiceFallback->remaining = '';
        Invoice::put($invoiceFallback);
        $ext121 = 'pmt-121-' . substr(hash('sha256', '25:USD'), 0, 12);
        $api121 = [
            'invoice_id' => 'inv_121_fallback',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'paid',
            'is_final' => true,
            'is_test' => false,
            'order' => ['external_id' => $ext121, 'amount' => '25', 'currency' => 'USD'],
        ];
        $transport2 = new MockTransport([
            jsonResponse(201, [
                'invoice_id' => 'inv_121_fallback',
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'awaiting_client',
                'payment_url' => 'https://pay.paymos.io/i/inv_121_fallback',
            ]),
            jsonResponse(200, $api121),
        ]);
        $gw2 = makeGateway(validLiveConfig(), $db, $transport2, $clock);
        $gw2->pay($invoiceFallback, '25.00');
        $body121 = (string) json_encode([
            'event_id' => 'evt_fallback_121',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $api121,
        ], JSON_UNESCAPED_SLASHES);
        $res121 = $gw2->webhook(new Request($body121, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $body121),
        ]));
        assertSame(200, $res121['status'], 'Fallback to $invoice->total when remaining is empty must succeed.');
    }

    // ── 17. R8: whsec_ validation, rk_*/HMAC-SHA256 redaction, payment_url validation ──
    function testWebhookSecretRedactionAndPaymentUrlValidation(): void
    {
        $db = new InMemoryDbDriver();
        $emptyTransport = new MockTransport([]);

        // Invalid webhook_secret values rejected before network
        foreach (['plain_secret_value', 'whsec_', 'WHSEC_wrongprefix'] as $badSecret) {
            $gw = makeGateway(validLiveConfig(['webhook_secret' => $badSecret]), $db, $emptyTransport);
            assertSame(false, $gw->canUseGateway('10.00', 'USD', 'invoice'), 'canUseGateway must reject invalid webhook_secret: ' . $badSecret);
            $threw = false;
            try {
                Config::fromArray(validLiveConfig(['webhook_secret' => $badSecret]));
            } catch (\InvalidArgumentException $e) {
                $threw = true;
            }
            assertTrue($threw, 'Config::fromArray must reject webhook_secret: ' . $badSecret);
        }
        assertSame(0, count($emptyTransport->requests()), '0 HTTP calls for invalid webhook_secret.');

        // Config::redactSecrets() scrubs rk_*, pk_*, sk_*, whsec_*, and HMAC-SHA256 Authorization headers
        $rawLog = 'Authorization: HMAC-SHA256 pk_live_abc123:SGVsbG8rV29ybGQ9== '
            . 'keys rk_live_PayoutKey99 rk_test_SandboxKey88 pk_test_PubKey77 sk_live_Sec66 whsec_Hook55';
        $scrubbed = Config::redactSecrets($rawLog);
        assertTrue(!str_contains($scrubbed, 'SGVsbG8rV29ybGQ9=='), 'HMAC-SHA256 signature must be redacted.');
        assertTrue(!str_contains($scrubbed, 'rk_live_PayoutKey99'), 'rk_live_* must be redacted.');
        assertTrue(!str_contains($scrubbed, 'rk_test_SandboxKey88'), 'rk_test_* must be redacted.');
        assertTrue(!str_contains($scrubbed, 'pk_live_abc123'), 'pk_live_* must be redacted.');
        assertTrue(!str_contains($scrubbed, 'pk_test_PubKey77'), 'pk_test_* must be redacted.');
        assertTrue(!str_contains($scrubbed, 'sk_live_Sec66'), 'sk_live_* must be redacted.');
        assertTrue(!str_contains($scrubbed, 'whsec_Hook55'), 'whsec_* must be redacted.');

        // payment_url validation rejects hostless, non-HTTPS, and CRLF-injected URLs
        $badUrls = [
            'https://',
            'https:///i/inv_nohost',
            'http://pay.paymos.io/i/inv_http',
            "https://pay.paymos.io/i/inv_crlf\r\nSet-Cookie: evil=1",
            "https://pay.paymos.io/i/inv_trail\r\n",
            'not-a-valid-url',
        ];
        $responses = [];
        foreach ($badUrls as $idx => $url) {
            $responses[] = jsonResponse(201, [
                'invoice_id' => 'inv_badurl_' . $idx,
                'project_id' => 'prj_paymenter_live_001',
                'status' => 'awaiting_client',
                'payment_url' => $url,
            ]);
        }
        $responses[] = jsonResponse(201, [
            'invoice_id' => 'inv_goodurl',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'awaiting_client',
            'payment_url' => 'https://pay.paymos.io/i/inv_goodurl?lang=en',
        ]);

        $gwUrl = makeGateway(validLiveConfig(), $db, new MockTransport($responses));
        foreach ($badUrls as $idx => $badUrl) {
            $threw = false;
            try {
                $gwUrl->pay(new Invoice(200 + $idx, '15.00', 'USD'), '15.00');
            } catch (\RuntimeException $e) {
                $threw = true;
            }
            assertTrue($threw, 'createCheckout must reject malformed payment_url: ' . var_export($badUrl, true));
        }

        $goodUrl = $gwUrl->pay(new Invoice(299, '15.00', 'USD'), '15.00');
        assertSame('https://pay.paymos.io/i/inv_goodurl?lang=en', $goodUrl, 'Valid HTTPS payment_url with query params must be accepted.');
    }

    // ── 18. R10: extractTransactionId() API fallback & project_id renewal filter ──
    function testVerifiedInvoiceTxHashFallbackAndProjectIsolation(): void
    {
        ExtensionHelper::reset();
        Invoice::resetStore();
        $now = 1700000000;
        $clock = static fn (): int => $now;
        $db = new InMemoryDbDriver();
        $snapshots = new SnapshotStore($db, $clock);

        // Part A: Webhook omits payment.transfers[], while reverse-verified GET /v1/invoices/{id} has confirmed tx_hash
        Invoice::put(new Invoice(130, '49.90', 'USD', 'pending', 70));
        $ext130 = 'pmt-130-' . substr(hash('sha256', '49.9:USD'), 0, 12);
        $snapshots->save([
            'external_order_id' => $ext130,
            'paymos_invoice_id' => 'inv_130_api_tx',
            'platform_ref' => '130',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_paymenter_live_001',
            'payment_url' => 'https://pay.paymos.io/i/inv_130_api_tx',
        ]);

        $webhookDataWithoutTransfers = [
            'invoice_id' => 'inv_130_api_tx',
            'project_id' => 'prj_paymenter_live_001',
            'status' => 'paid',
            'is_final' => true,
            'is_test' => false,
            'order' => ['external_id' => $ext130, 'amount' => '49.9', 'currency' => 'USD'],
        ];
        $verifiedApiInvoiceWithTransfers = array_merge($webhookDataWithoutTransfers, [
            'payment' => [
                'currency' => 'USDC',
                'network' => 'ARB',
                'transfers' => [
                    ['tx_hash' => '0xapi_verified_confirmed_tx_130', 'amount' => '49.9', 'status' => 'confirmed'],
                ],
            ],
        ]);
        $rawBody = (string) json_encode([
            'event_id' => 'evt_api_tx_fallback_130',
            'event_type' => 'invoice.paid',
            'version' => 1,
            'occurred_at' => $now,
            'data' => $webhookDataWithoutTransfers,
        ], JSON_UNESCAPED_SLASHES);

        $gwA = makeGateway(validLiveConfig(), $db, new MockTransport([jsonResponse(200, $verifiedApiInvoiceWithTransfers)]), $clock);
        $resA = $gwA->webhook(new Request($rawBody, [
            'X-Webhook-Signature' => signWebhook('whsec_unitsecret123', $now, $rawBody),
        ]));
        assertSame(200, $resA['status'], 'Webhook with API-side confirmed transfer must succeed.');
        assertSame(1, count(ExtensionHelper::$payments), 'addPayment must be called once.');
        assertSame(
            '0xapi_verified_confirmed_tx_130',
            ExtensionHelper::$payments[0]['transaction_id'],
            'extractTransactionId() must fall back to reverse-verified API invoice confirmed tx_hash.'
        );
        assertSame(
            'inv_130_api_tx',
            $snapshots->findByTransactionId('0xapi_verified_confirmed_tx_130')['paymos_invoice_id'] ?? null,
            'Snapshot transaction_id must record API-verified confirmed tx_hash.'
        );

        // Part B: Switching project_id ignores snapshots from the old project during resolveRenewalAttempt()
        $snapshots->save([
            'external_order_id' => 'pmt-131-' . substr(hash('sha256', '49.9:USD'), 0, 12),
            'paymos_invoice_id' => 'inv_131_old_project',
            'platform_ref' => '131',
            'amount' => '49.9',
            'currency' => 'USD',
            'environment' => 'live',
            'project_id' => 'prj_old_project',
            'payment_url' => 'https://pay.paymos.io/i/inv_131_old_project',
            'expires_at' => $now - 5000,
            'renewal_attempt' => 2,
            'final_status' => 'expired',
            'updated_at' => $now - 10,
        ]);

        $transportNewProj = new MockTransport([
            jsonResponse(201, [
                'invoice_id' => 'inv_131_new_project',
                'project_id' => 'prj_new_project',
                'status' => 'awaiting_client',
                'payment_url' => 'https://pay.paymos.io/i/inv_131_new_project',
                'expires_at' => $now + 1800,
            ]),
        ]);
        $gwNewProj = makeGateway(validLiveConfig(['project_id' => 'prj_new_project']), $db, $transportNewProj, $clock);
        $urlNew = $gwNewProj->pay(new Invoice(131, '49.90', 'USD'), '49.90');
        assertSame('https://pay.paymos.io/i/inv_131_new_project', $urlNew, 'Checkout under new project_id must succeed.');
        assertSame(1, count($transportNewProj->requests()), 'Switching project_id must NOT call GET on old project invoice.');
        $sentBody = json_decode($transportNewProj->requests()[0]['body'], true);
        assertSame('prj_new_project', $sentBody['project_id'] ?? null, 'Outgoing invoice must use new project_id.');
        assertTrue(
            !str_ends_with((string) ($sentBody['external_order_id'] ?? ''), '-r3')
                && !str_ends_with((string) ($sentBody['external_order_id'] ?? ''), '-r1'),
            'Snapshot from old project_id must not bump renewal_attempt for new project_id.'
        );
    }

    // ── Test Runner ───────────────────────────────────────────────────────────
    $tests = [
        'testRejectPayoutKey',
        'testRejectNonHttps',
        'testSecretProtection',
        'testCheckoutCreateAndRedirect',
        'testAmountChangeAndRenewal',
        'testProblemDetails',
        'testWebhookRejectForgedAndStale',
        'testWebhookCompleteVerified',
        'testWebhookHoldOnMismatch',
        'testWebhookDedupAndInFlight',
        'testRollbackGuardAndPaidLate',
        'testFullRefund',
        'testRejectPartialRefund',
        'testPackagingMetaRoutesAndLint',
        'testTransactionSafeSchemaAndInsertUnique',
        'testPartiallyCreditedInvoiceRemainingAmount',
        'testWebhookSecretRedactionAndPaymentUrlValidation',
        'testVerifiedInvoiceTxHashFallbackAndProjectIsolation',
    ];

    $passed = 0;
    foreach ($tests as $testName) {
        $fn = __NAMESPACE__ . '\\' . $testName;
        $fn();
        $passed++;
        echo "[PASS] {$testName}\n";
    }

    echo sprintf("\nPaymenter Paymos conformance suite: %d/%d passed.\n", $passed, count($tests));
}
