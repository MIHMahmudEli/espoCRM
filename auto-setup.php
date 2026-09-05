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

// Run rebuild.php for schema creation with error display enabled
chdir($basePath);

echo "Attempting rebuild.php (with error display)...\n";
$output = [];
$exitCode = 0;
exec('php -d display_errors=1 -d error_reporting=E_ALL rebuild.php 2>&1', $output, $exitCode);
echo "rebuild.php output:\n";
echo implode("\n", $output) . "\n";
echo "rebuild.php exit code: $exitCode\n";

if ($exitCode !== 0) {
    echo "\nrebuild.php failed. Attempting in-process rebuild...\n";
    
    try {
        // Reset error handlers from auto-setup.php
        restore_error_handler();
        restore_exception_handler();
        
        include_once $basePath . '/bootstrap.php';
        
        $app = new \Espo\Core\Application();
        $app->run(\Espo\Core\ApplicationRunners\Rebuild::class);
        echo "In-process rebuild succeeded!\n";
    } catch (\Throwable $e) {
        echo "In-process rebuild FAILED: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
    }
}

// Check if tables were created
$stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
$tableCount = $stmt->fetchColumn();
echo "Tables in database: $tableCount\n";

if ($tableCount < 10) {
    echo "Schema not created by rebuild.php. Attempting EspoCRM CLI rebuild...\n";
    exec('php install/cli.php -a buildDatabase 2>&1', $output2, $exitCode2);
    echo implode("\n", $output2) . "\n";
    echo "cli buildDatabase exit code: $exitCode2\n";

    // Recheck
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'");
    $tableCount = $stmt->fetchColumn();
    echo "Tables after CLI rebuild: $tableCount\n";
}

// Step 3: Create admin user
echo "\n[3/4] Creating admin user...\n";

if ($tableCount >= 10) {
    try {
        // Check if user table exists
        $stmt = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'user')");
        $userTableExists = $stmt->fetchColumn();

        if ($userTableExists) {
            // Check if admin user already exists
            $stmt = $pdo->prepare('SELECT "id" FROM "user" WHERE "userName" = ?');
            $stmt->execute(['admin']);
            $existing = $stmt->fetch();

            if ($existing) {
                echo "Admin user already exists.\n";
            } else {
                // Generate password hash using PHP's password_hash
                $passwordHash = password_hash('admin12@#', PASSWORD_BCRYPT);

                $adminId = bin2hex(random_bytes(16));

                // Get default team ID (should be created by schema)
                $teamId = null;
                try {
                    $stmt = $pdo->query('SELECT "id" FROM "team" WHERE "deleted" = false LIMIT 1');
                    $team = $stmt->fetch();
                    if ($team) $teamId = $team['id'];
                } catch (Throwable $e) {
                    // Team table might not exist yet
                }

                $stmt = $pdo->prepare('INSERT INTO "user" ("id", "userName", "password", "lastName", "type", "createdAt", "modifiedAt", "deleted", "isAdmin", "isActive") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $adminId,
                    'admin',
                    $passwordHash,
                    'Admin',
                    'admin',
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                    false,
                    true,
                    true,
                ]);

                // Assign to admin team if team exists
                if ($teamId) {
                    try {
                        $stmt = $pdo->prepare('INSERT INTO "team_user" ("id", "userId", "teamId", "role") VALUES (?, ?, ?, ?)');
                        $stmt->execute([
                            bin2hex(random_bytes(16)),
                            $adminId,
                            $teamId,
                            'admin',
                        ]);
                    } catch (Throwable $e) {
                        echo "Note: Could not assign team: " . $e->getMessage() . "\n";
                    }
                }

                echo "Admin user created successfully.\n";
            }
        } else {
            echo "ERROR: User table does not exist.\n";
            exit(1);
        }
    } catch (Throwable $e) {
        echo "ERROR creating admin user: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "ERROR: Schema not created, cannot create admin user.\n";
    exit(1);
}

// Step 4: Create install/config.php and preferences
echo "\n[4/4] Finalizing installation...\n";

// Write install/config.php
$installConfigPath = $basePath . '/install/config.php';
$installConfig = [
    'isInstalled' => true,
];
$installContent = "<?php\nreturn " . var_export($installConfig, true) . ";\n";
file_put_contents($installConfigPath, $installContent, LOCK_EX);
echo "install/config.php written.\n";

// Insert default settings if settings table exists
try {
    $stmt = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'settings')");
    $settingsExists = $stmt->fetchColumn();

    if ($settingsExists) {
        // Check if settings already exist
        $stmt = $pdo->query('SELECT COUNT(*) FROM "settings"');
        $settingsCount = $stmt->fetchColumn();

        if ($settingsCount == 0) {
            $settings = [
                ['language', 'en_US'],
                ['dateFormat', 'DD.MM.YYYY'],
                ['timeFormat', 'HH:mm'],
                ['timeZone', 'UTC'],
                ['weekStart', '0'],
                ['defaultCurrency', 'USD'],
                ['baseCurrency', 'USD'],
                ['thousandSeparator', ','],
                ['decimalMark', '.'],
                ['theme', 'Espo'],
            ];

            foreach ($settings as [$name, $value]) {
                $stmt = $pdo->prepare('INSERT INTO "settings" ("id", "name", "value", "deleted") VALUES (?, ?, ?, false)');
                $stmt->execute([bin2hex(random_bytes(16)), $name, $value]);
            }
            echo "Default settings inserted.\n";
        }
    }
} catch (Throwable $e) {
    echo "Note: Could not insert settings: " . $e->getMessage() . "\n";
}

// Insert default preferences if preferences table exists
try {
    $stmt = $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'preferences')");
    $prefsExists = $stmt->fetchColumn();

    if ($prefsExists) {
        $stmt = $pdo->query('SELECT COUNT(*) FROM "preferences" WHERE "deleted" = false');
        $prefsCount = $stmt->fetchColumn();

        if ($prefsCount == 0 && isset($adminId)) {
            $prefsData = json_encode(['language' => 'en_US']);
            $stmt = $pdo->prepare('INSERT INTO "preferences" ("id", "userId", "data", "deleted") VALUES (?, ?, ?, false)');
            $stmt->execute([bin2hex(random_bytes(16)), $adminId, $prefsData]);
            echo "Default preferences inserted.\n";
        }
    }
} catch (Throwable $e) {
    echo "Note: Could not insert preferences: " . $e->getMessage() . "\n";
}

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
