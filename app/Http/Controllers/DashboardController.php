<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\DemoTrafficService;
use App\Services\Observability\EventLog;
use App\Services\Observability\Metrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard');
    }

    public function metrics(Metrics $metrics): JsonResponse
    {
        return response()->json($metrics->snapshot());
    }

    public function logs(EventLog $eventLog, Request $request): JsonResponse
    {
        $hasTraceFilter = $request->filled('correlation_id') || $request->filled('notification_id');
        $entries = $hasTraceFilter ? $eventLog->retained() : $eventLog->recent();

        if ($request->filled('correlation_id')) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $e) => ($e['correlation_id'] ?? null) === $request->input('correlation_id'),
            ));
        }

        if ($request->filled('notification_id')) {
            $needle = strtolower((string) $request->input('notification_id'));
            $entries = array_values(array_filter(
                $entries,
                static fn (array $e) => str_contains(
                    strtolower((string) ($e['notification_id'] ?? '')),
                    $needle,
                ),
            ));
        }

        if ($request->filled('event')) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $e) => str_contains((string) ($e['event'] ?? ''), $request->input('event')),
            ));
        }

        if ($request->filled('level')) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $e) => ($e['level'] ?? null) === $request->input('level'),
            ));
        }

        return response()->json($entries);
    }

    public function notifications(Request $request): JsonResponse
    {
        $query = Notification::query()
            ->select(['id', 'batch_id', 'channel', 'priority', 'status', 'attempt_count', 'scheduled_at', 'created_at', 'last_error'])
            ->orderByDesc('created_at')
            ->limit(50);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }

        return response()->json($query->get());
    }

    public function health(): JsonResponse
    {
        $checks = [];

        // Redis
        try {
            Redis::connection()->ping();
            $checks['redis'] = ['ok' => true, 'label' => 'Redis'];
        } catch (Throwable) {
            $checks['redis'] = ['ok' => false, 'label' => 'Redis'];
        }

        // Database
        try {
            DB::connection()->getPdo();
            $checks['db'] = ['ok' => true, 'label' => 'Database'];
        } catch (Throwable) {
            $checks['db'] = ['ok' => false, 'label' => 'Database'];
        }

        // Queue worker — detected via Redis heartbeat written by Queue::looping() in AppServiceProvider
        $workerRunning = false;
        try {
            $heartbeat = Redis::connection()->get('worker:heartbeat');
            $workerRunning = $heartbeat !== null;
        } catch (Throwable) {
            // Redis already checked above; worker status unknown
        }
        $checks['worker'] = ['ok' => $workerRunning, 'label' => 'Queue Worker'];

        // Provider webhook config
        $providerUrl = (string) config('notifications.provider.webhook_url', '');
        $checks['provider'] = ['ok' => $providerUrl !== '', 'label' => 'Provider Webhook'];

        $allOk = array_reduce(
            $checks,
            static fn (bool $carry, array $c) => $carry && $c['ok'],
            true,
        );

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'checks' => $checks,
            'refreshed_at' => now()->toIso8601String(),
        ]);
    }

    public function runTests(Request $request): JsonResponse
    {
        $group = $request->input('group', 'smoke');

        $filterMap = [
            'smoke'         => 'HealthEndpointTest|MetricsEndpointTest',
            'notifications' => 'NotificationApiTest|NotificationProcessingTest',
            'queue'         => 'RedisQueueIntegrationTest',
            'load'          => 'HighThroughputLoadTest',
            'templates'     => 'ScheduledNotificationsAndTemplatesTest',
        ];

        if (! array_key_exists($group, $filterMap)) {
            return response()->json(['error' => 'Unknown test group.'], 422);
        }

        $runId      = (string) Str::uuid();
        $logFile    = sys_get_temp_dir() . '/dashboard-test-' . $runId . '.log';
        $scriptFile = sys_get_temp_dir() . '/dashboard-runner-' . $runId . '.php';
        $phpBin     = PHP_BINARY;
        $phpUnit    = base_path('vendor/bin/phpunit');
        $config     = base_path('phpunit.xml');
        $basePath   = base_path();
        $filter     = $filterMap[$group];
        $marker     = '__TEST_DONE__';

        // Build an isolated PHP runner script — avoids all shell quoting and
        // backgrounding issues. The script runs phpunit synchronously and writes
        // a sentinel marker when done; the web process returns immediately after
        // launching it via nohup.
        $args = array_map(
            static fn (string $v) => var_export($v, true),
            [$phpBin, $phpUnit, '--configuration', $config, '--no-coverage', '--colors=never', '--filter', $filter],
        );

        $argsLiteral   = implode(', ', $args);
        $loadEnvLine   = $group === 'load' ? "putenv('RUN_LOAD_TESTS=true');" : '';
        $logFileExport = var_export($logFile, true);
        $markerExport  = var_export($marker, true);
        $basePathExport = var_export($basePath, true);

        $script = <<<PHP
<?php
chdir($basePathExport);
$loadEnvLine
\$logFile  = $logFileExport;
\$marker   = $markerExport;
\$forcedEnv = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

foreach (\$forcedEnv as \$envKey => \$envValue) {
    putenv("{\$envKey}=\{\$envValue}");
    \$_ENV[\$envKey] = \$envValue;
    \$_SERVER[\$envKey] = \$envValue;
}

\$logHandle = fopen(\$logFile, 'w');
\$proc = proc_open(
    [$argsLiteral],
    [0 => ['pipe', 'r'], 1 => \$logHandle, 2 => \$logHandle],
    \$pipes,
    null,
    \$forcedEnv,
);
fclose(\$logHandle);
\$exitCode = proc_close(\$proc);
file_put_contents(\$logFile, \$marker . ':' . \$exitCode . PHP_EOL, FILE_APPEND);
PHP;

        file_put_contents($scriptFile, $script);

        shell_exec(sprintf('nohup %s %s &', escapeshellarg($phpBin), escapeshellarg($scriptFile)));

        Cache::put('dashboard:test:' . $runId, [
            'group'       => $group,
            'log_file'    => $logFile,
            'script_file' => $scriptFile,
            'marker'      => $marker,
            'started_at'  => now()->toIso8601String(),
        ], now()->addHour());

        return response()->json([
            'run_id'     => $runId,
            'group'      => $group,
            'started_at' => now()->toIso8601String(),
        ]);
    }

    public function testStatus(string $runId): JsonResponse
    {
        $runId = preg_replace('/[^a-f0-9\-]/', '', $runId);

        /** @var array{group: string, log_file: string, marker: string, started_at: string}|null $info */
        $info = Cache::get('dashboard:test:' . $runId);

        if (! $info) {
            return response()->json(['error' => 'Run not found.'], 404);
        }

        $logFile = $info['log_file'];
        $marker  = $info['marker'] ?? '__TEST_DONE__';
        $raw     = file_exists($logFile) ? (string) file_get_contents($logFile) : '';

        $done     = str_contains($raw, $marker);
        $exitCode = null;

        if ($done && preg_match('/' . preg_quote($marker, '/') . ':(\d+)/', $raw, $m)) {
            $exitCode = (int) $m[1];
        }

        // Strip the sentinel line from the displayed output
        $output = preg_replace('/' . preg_quote($marker, '/') . ':\d+\s*$/m', '', $raw);

        return response()->json([
            'run_id'     => $runId,
            'group'      => $info['group'],
            'started_at' => $info['started_at'],
            'is_running' => ! $done,
            'exit_code'  => $exitCode,
            'output'     => $output,
        ]);
    }

    public function startDemoTraffic(Request $request, DemoTrafficService $demoTraffic): JsonResponse
    {
        if ($demoTraffic->hasActiveRun()) {
            return response()->json([
                'error' => 'Demo traffic is already running.',
                'status' => $demoTraffic->status(),
            ], 409);
        }

        $durationSeconds = max(15, min(120, $request->integer('duration_seconds', 60)));
        $runId = (string) Str::uuid();
        $scriptFile = sys_get_temp_dir().'/dashboard-demo-runner-'.$runId.'.php';
        $phpBin = PHP_BINARY;
        $basePathExport = var_export(base_path(), true);
        $runIdExport = var_export($runId, true);
        $durationExport = $durationSeconds;

        $demoTraffic->initializeRun($runId, $durationSeconds, $scriptFile);

        $script = <<<PHP
<?php
require $basePathExport.'/vendor/autoload.php';

\$app = require $basePathExport.'/bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

app(App\Services\DemoTrafficService::class)->run($runIdExport, $durationExport);
PHP;

        file_put_contents($scriptFile, $script);
        shell_exec(sprintf('nohup %s %s >/dev/null 2>&1 &', escapeshellarg($phpBin), escapeshellarg($scriptFile)));

        return response()->json($demoTraffic->status(), 202);
    }

    public function demoTrafficStatus(DemoTrafficService $demoTraffic): JsonResponse
    {
        return response()->json($demoTraffic->status());
    }

    public function clearDemoTraffic(DemoTrafficService $demoTraffic): JsonResponse
    {
        $result = $demoTraffic->clear();

        return response()->json([
            'deleted' => $result['deleted'],
            'run_id' => $result['run_id'],
            'status' => $demoTraffic->status(),
        ]);
    }
}
