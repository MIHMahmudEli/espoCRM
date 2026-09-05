<?php
/**
 * Auto-setup script for EspoCRM on Render.
 * Bypasses the broken CLI installer by writing config directly,
 * running rebuild.php for schema, and creating admin via PDO.
 */

$basePath = dirname(__FILE__);
$configPath = $basePath . '/data/config.php';
$markerPath = $basePath . '/data/.setup-done';

if (file_exists($markerPath)) {
    echo "Already installed. Skipping.\n";
    return;
}

echo "=== EspoCRM Auto-Setup ===\n";

// Clean up any stale config files from previous failed attempts
foreach (['data/config.php', 'data/config-internal.php', 'data/config-internal-override.php', 'data/config-state.php'] as $f) {
    $p = $basePath . '/' . $f;
    if (file_exists($p)) {
        unlink($p);
        echo "Removed stale: $f\n";
    }
}

// Clean stale cache
$cacheDir = $basePath . '/data/cache';
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*') as $file) {
        if (is_file($file)) unlink($file);
    }
}

// Step 1: Write data/config.php directly
echo "\n[1/4] Writing config.php...\n";

$configData = [
    'database' => [
        'host' => 'aws-0-ap-northeast-2.pooler.supabase.com',
        'port' => '5432',
        'charset' => null,
        'dbname' => 'postgres',
        'user' => 'postgres.nghztacvdcmizwjoaktf',
        'password' => 'MqjuNE5jJTDv9&B',
        'driver' => 'pdo_pgsql',
        'platform' => 'Postgresql',
    ],
    'language' => 'en_US',
    'siteUrl' => 'https://espocrm-plwh.onrender.com',
    'cryptKey' => bin2hex(random_bytes(32)),
    'hashSecretKey' => bin2hex(random_bytes(32)),
    'theme' => 'Espo',
    'isInstalled' => true,
    'defaultCurrency' => 'USD',
    'baseCurrency' => 'USD',
    'currencyList' => ['USD'],
    'currencyRates' => [],
    'currencyNoJoinMode' => true,
    'timeZone' => 'UTC',
    'dateFormat' => 'DD.MM.YYYY',
    'timeFormat' => 'HH:mm',
    'weekStart' => 0,
    'thousandSeparator' => ',',
    'decimalMark' => '.',
    'useCache' => true,
];

$phpContent = "<?php\nreturn " . var_export($configData, true) . ";\n";
file_put_contents($configPath, $phpContent, LOCK_EX);

// Verify
$config = include $configPath;
if (!isset($config['database']) || empty($config['database']['host'])) {
    echo "ERROR: Failed to write config!\n";
    exit(1);
}
echo "Config written successfully.\n";
echo "Database host: {$config['database']['host']}\n";
echo "Database name: {$config['database']['dbname']}\n";

// Write install/config.php early so bootstrap works
$installConfigPath = $basePath . '/install/config.php';
file_put_contents($installConfigPath, "<?php\nreturn ['isInstalled' => true];\n", LOCK_EX);
echo "install/config.php written.\n";

// Step 2: Test DB connection via PDO
echo "\n[2/4] Testing DB connection and creating schema via rebuild.php...\n";

$db = $config['database'];
try {
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['dbname']}";
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "PDO connection: OK\n";
    $stmt = $pdo->query('SELECT version()');
    echo "PostgreSQL: " . $stmt->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "PDO connection FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Check if schema already exists
$stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
$tableCount = $stmt->fetchColumn();
echo "Tables in database: $tableCount\n";

// Check if schema is complete (user table must have is_admin column)
$schemaComplete = false;
if ($tableCount >= 100) {
    try {
        $stmt = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'user' AND column_name = 'user_name')");
        $schemaComplete = $stmt->fetchColumn();
    } catch (Throwable $e) {}
}

if ($schemaComplete) {
    echo "Schema already exists and is complete ($tableCount tables). Skipping rebuild.\n";
} else {
    if ($tableCount > 0) {
        echo "Schema is incomplete (missing columns). Dropping and rebuilding...\n";
        // Drop all tables in public schema
        $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET session_replication_role = replica;');
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS "' . $t . '" CASCADE;');
        }
        $pdo->exec('SET session_replication_role = origin;');
        // Drop sequences
        $stmt = $pdo->query("SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public'");
        $seqs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($seqs as $s) {
            $pdo->exec('DROP SEQUENCE IF EXISTS "' . $s . '" CASCADE;');
        }
        echo "Dropped " . count($tables) . " tables.\n";
        $tableCount = 0;
    }

    // Run rebuild.php for schema creation
    chdir($basePath);

    echo "Attempting rebuild.php...\n";
    $output = [];
    $exitCode = 0;
    exec('php -d display_errors=1 -d error_reporting=E_ALL rebuild.php 2>&1', $output, $exitCode);
    echo "rebuild.php output:\n";
    echo implode("\n", $output) . "\n";
    echo "rebuild.php exit code: $exitCode\n";

    if ($exitCode !== 0) {
        echo "\nrebuild.php failed. Reading log file...\n";
        
        $logDir = $basePath . '/data/logs';
        if (is_dir($logDir)) {
            foreach (glob($logDir . '/espo*.log') as $logFile) {
                echo "--- " . basename($logFile) . " ---\n";
                echo file_get_contents($logFile);
                echo "\n--- end ---\n";
            }
        }
        
        echo "\nAttempting in-process rebuild...\n";
        
        try {
            restore_error_handler();
            restore_exception_handler();
            
            include_once $basePath . '/bootstrap.php';
            
            $app = new \Espo\Core\Application();
            $dm = $app->getContainer()->getByClass(\Espo\Core\DataManager::class);
            $dm->rebuild();
            echo "In-process rebuild succeeded!\n";
        } catch (\Throwable $e) {
            echo "In-process rebuild FAILED: " . $e->getMessage() . "\n";
            
            if (is_dir($logDir)) {
                foreach (glob($logDir . '/espo*.log') as $logFile) {
                    echo "--- " . basename($logFile) . " (after in-process) ---\n";
                    echo file_get_contents($logFile);
                    echo "\n--- end ---\n";
                }
            }
        }
    }

    // Recheck table count
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
    $tableCount = $stmt->fetchColumn();
    echo "Tables after rebuild: $tableCount\n";

    if ($tableCount < 10) {
        echo "Schema not created. Attempting EspoCRM CLI rebuild...\n";
        exec('php install/cli.php -a buildDatabase 2>&1', $output2, $exitCode2);
        echo implode("\n", $output2) . "\n";
        echo "cli buildDatabase exit code: $exitCode2\n";

        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
        $tableCount = $stmt->fetchColumn();
        echo "Tables after CLI rebuild: $tableCount\n";
    }
}

// Step 3: Create admin user using EspoCRM Installer
echo "\n[3/4] Creating admin user...\n";

if ($tableCount >= 10) {
    try {
        require_once $basePath . '/bootstrap.php';
        require_once $basePath . '/install/core/Installer.php';

        $installer = new \Installer();
        $result = $installer->createUser('admin', 'admin12@#');

        if ($result) {
            echo "Admin user created successfully.\n";
        } else {
            echo "Admin user may already exist.\n";
        }

        echo "Login: admin / Password: admin12@#\n";
    } catch (Throwable $e) {
        echo "ERROR creating admin user: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
} else {
    echo "ERROR: Schema not created, cannot create admin user.\n";
    exit(1);
}

// Step 4: Finalize
echo "\n[4/4] Finalizing installation...\n";

// Set permissions
chmod($configPath, 0664);
if (file_exists($installConfigPath)) chmod($installConfigPath, 0664);

// Create marker
touch($markerPath);
chmod($markerPath, 0664);

echo "\n=== Setup complete! ===\n";
echo "URL: https://espocrm-plwh.onrender.com\n";
echo "Login: admin\n";
echo "Password: admin12@#\n";
