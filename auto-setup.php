<?php

$markerFile = __DIR__ . '/data/.setup-done';

if (file_exists($markerFile)) {
    echo "Already installed.\n";
    return;
}

$startTime = microtime(true);

echo "=== EspoCRM Auto-Setup (single process) ===\n";

chdir(dirname(__FILE__));
set_include_path(dirname(__FILE__));

require_once 'vendor/autoload.php';

$_SERVER['SERVER_SOFTWARE'] = 'AutoSetup';
$_SERVER['HTTP_HOST'] = '';
$_SERVER['REQUEST_URI'] = '';

require_once 'install/core/InstallerConfig.php';

$installerConfig = new InstallerConfig();

if ($installerConfig->get('isInstalled')) {
    echo "Already marked as installed.\n";
    touch($markerFile);
    return;
}

require_once 'install/core/Installer.php';

echo "[1/4] Initializing Installer...\n";

try {
    $installer = new Installer();
    echo "  Installer initialized.\n";
} catch (\Throwable $e) {
    echo "  FATAL: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "[2/4] Saving database settings...\n";

try {
    $saveData = [
        'database' => [
            'host' => 'aws-0-ap-northeast-2.pooler.supabase.com',
            'port' => '5432',
            'dbname' => 'postgres',
            'user' => 'postgres.nghztacvdcmizwjoaktf',
            'password' => 'MqjuNE5jJTDv9&B',
            'platform' => 'Postgresql',
            'driver' => 'pdo_pgsql',
        ],
        'language' => 'en_US',
        'siteUrl' => '',
        'theme' => 'Espo',
    ];

    $result = $installer->saveData($saveData);
    echo "  Config saved: " . ($result ? "OK" : "FAILED") . "\n";
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "[3/4] Building database schema & creating admin user...\n";

try {
    $installer->rebuild();
    echo "  Schema built.\n";
} catch (\Throwable $e) {
    echo "  ERROR during rebuild: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

try {
    $installer->createUser('admin', 'admin12@#');
    echo "  Admin user created (admin / admin12@#).\n";
} catch (\Throwable $e) {
    echo "  ERROR creating user: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "[4/4] Finalizing installation...\n";

try {
    $installer->setSuccess();
    echo "  Installation finalized.\n";
} catch (\Throwable $e) {
    echo "  ERROR finalizing: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

touch($markerFile);

$elapsed = round(microtime(true) - $startTime, 2);
echo "=== Setup complete in {$elapsed}s ===\n";
