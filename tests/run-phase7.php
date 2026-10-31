<?php
declare(strict_types=1);

/*
 * Phase 7 admin operations harness. Same isolation as tests/run-phase6.php: every suite gets a fresh *_test
 * database migrated from 001, its own PHP development server (and loopback fake Maya when needed) started with
 * the SAME environment as the test runner, and only processes started here are stopped. parkngo_db is never used.
 *
 *   php tests/run-phase7.php             # all Phase 7 suites
 *   php tests/run-phase7.php p7_refunds  # selected suites
 * Run tests/run-phase6.php as well for the Phase 6 and earlier regression gate.
 */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$php = PHP_BINARY;
$run = date('Ymd_His');
$appPort = 8091; $mayaPort = 8093;
$suites = [
    'p7_permissions' => ['tests/phase7-permissions.php', 'server' => true, 'maya' => false],
    'p7_occupancy' => ['tests/phase7-occupancy.php', 'server' => true, 'maya' => false],
    'p7_concurrency' => ['tests/phase7-concurrency.php', 'server' => false, 'maya' => false],
    'p7_reconciliation' => ['tests/phase7-reconciliation.php', 'server' => true, 'maya' => true],
    'p7_refunds' => ['tests/phase7-refunds.php', 'server' => true, 'maya' => true],
    'p7_pricing' => ['tests/phase7-pricing.php', 'server' => true, 'maya' => true],
    'p7_reports' => ['tests/phase7-reports.php', 'server' => true, 'maya' => false],
    'p7_browser' => ['tests/phase7-browser.mjs', 'server' => true, 'maya' => true],
    'p7_migration' => ['tests/phase7-migration.php', 'server' => false, 'maya' => false],
];
$selected = array_slice($argv, 1) ?: array_keys($suites);
foreach ($selected as $name) if (!isset($suites[$name])) { fwrite(STDERR, "Unknown suite: $name\n"); exit(1); }

function portOpen(int $port): bool { $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.3); if ($socket) { fclose($socket); return true; } return false; }
function startProcess(array $command, array $env, string $log, string $cwd): array
{
    $process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, $env);
    if (!is_resource($process)) throw new RuntimeException('Unable to start ' . $command[0]);
    return ['handle' => $process, 'pid' => proc_get_status($process)['pid']];
}
function stopProcess(?array $process): void
{
    if (!$process || !is_resource($process['handle'])) return;
    if (proc_get_status($process['handle'])['running']) proc_terminate($process['handle']);
    proc_close($process['handle']);
}
function waitForPort(int $port): void { $deadline = microtime(true) + 10; while (!portOpen($port)) { if (microtime(true) > $deadline) throw new RuntimeException("Port $port did not open."); usleep(100000); } }

$results = [];
foreach ($selected as $name) {
    $suite = $suites[$name];
    $database = 'parkngo_' . $name . '_' . $run . '_test';
    $work = $root . '/storage/test-runtime/phase7/' . $run . '/' . $name;
    @mkdir($work . '/maya/checkouts', 0700, true);
    file_put_contents($work . '/clock.txt', '');
    $env = array_merge(getenv(), [
        'APP_ENV' => 'local', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => $database,
        'APP_URL' => "http://127.0.0.1:$appPort", 'MAIL_TRANSPORT' => 'log', 'PARKNGO_TEST_BASE_URL' => "http://127.0.0.1:$appPort", 'TEST_BASE_URL' => "http://127.0.0.1:$appPort",
        'PARKNGO_TEST_CLOCK_FILE' => $work . '/clock.txt', 'FAKE_MAYA_STATE' => $work . '/maya',
        'MAYA_ENABLED' => 'false', 'MAYA_MODE' => 'sandbox', 'MAYA_PUBLIC_KEY' => '', 'MAYA_SECRET_KEY' => '', 'PHP_BINARY' => $php,
    ]);
    if ($suite['maya']) {
        // Random per-suite fake keys prove MayaClient sends the public key for checkout and the secret key for retrieval.
        $env = array_merge($env, ['MAYA_ENABLED' => 'true', 'MAYA_TEST_API_ORIGIN' => "http://127.0.0.1:$mayaPort",
            'FAKE_MAYA_PUBLIC_KEY' => 'pk-test-' . bin2hex(random_bytes(8)), 'FAKE_MAYA_SECRET_KEY' => 'sk-test-' . bin2hex(random_bytes(8))]);
        $env['MAYA_PUBLIC_KEY'] = $env['FAKE_MAYA_PUBLIC_KEY']; $env['MAYA_SECRET_KEY'] = $env['FAKE_MAYA_SECRET_KEY'];
    }
    echo "\n=== $name ($database) ===\n";
    $app = $maya = null; $status = 1; $output = '';
    try {
        $migrate = proc_open([$php, 'database/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $migration = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $expectedMigrations = count(glob($root . '/database/migrations/*.sql'));
        if (proc_close($migrate) !== 0 || substr_count($migration, 'Applied:') !== $expectedMigrations) throw new RuntimeException("Migration failed:\n$migration");
        echo "Fresh database migrated: all $expectedMigrations migrations applied.\n";
        if (!empty($suite['seed'])) {
            // Development seed prints local-only demo passwords; keep them out of the harness output.
            $seed = proc_open([$php, 'database/seeders/development.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
            stream_get_contents($pipes[1]); $seedErrors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($seed) !== 0) throw new RuntimeException('Development seed failed: ' . $seedErrors);
            echo "Development seed applied to the test database.\n";
        }
        if ($suite['server'] || $suite['maya']) foreach ([$appPort, $mayaPort] as $port) if (portOpen($port)) throw new RuntimeException("Port $port is already in use; not stopping a process this harness did not start.");
        if ($suite['maya']) { $maya = startProcess([$php, '-S', "127.0.0.1:$mayaPort", 'tests/fixtures/fake-maya.php'], $env, $work . '/fake-maya.log', $root); waitForPort($mayaPort); }
        if ($suite['server']) {
            $app = startProcess([$php, '-S', "127.0.0.1:$appPort", '-t', 'public', 'tests/fixtures/clock-router.php'], $env, $work . '/app-server.log', $root); waitForPort($appPort);
            $probe = @get_headers("http://127.0.0.1:$appPort/index.php?page=home", true) ?: [];
            $expected = hash('sha256', '127.0.0.1:3306:' . $database);
            if (($probe['X-Parkngo-Test-Database'] ?? '') !== $expected) throw new RuntimeException('Server and runner are not using the same test database.');
            echo "App server PID {$app['pid']} confirmed on the same APP_ENV/DB_DATABASE.\n";
        }
        $command = str_ends_with($suite[0], '.mjs') ? ['node', $suite[0]] : [$php, $suite[0]];
        $test = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        stream_set_blocking($pipes[2], false);
        while (!feof($pipes[1])) { $line = fgets($pipes[1]); if ($line !== false) { echo $line; $output .= $line; } }
        $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($test);
        if ($errors !== '') { echo $errors; $output .= $errors; }
    } catch (Throwable $error) { echo 'HARNESS ERROR: ' . $error->getMessage() . "\n"; $status = 1; }
    finally { stopProcess($app); stopProcess($maya); }
    $passes = substr_count($output, "PASS ");
    $results[$name] = [$status === 0 ? 'PASSED' : 'FAILED', $passes, $database];
}
echo "\n=== Summary (run $run) ===\n";
foreach ($results as $name => [$state, $passes, $database]) printf("%-17s %-7s %3d checks  %s\n", $name, $state, $passes, $database);
exit(in_array('FAILED', array_column($results, 0), true) ? 1 : 0);
