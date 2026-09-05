<?php
$configFile = __DIR__ . '/data/config.php';

if (file_exists($configFile)) {
    echo "Already configured.\n";
    return;
}

$host = getenv('SPOC_DB_HOST');
$port = getenv('SPOC_DB_PORT') ?: '5432';
$dbname = getenv('SPOC_DB_NAME') ?: 'postgres';
$user = getenv('SPOC_DB_USER');
$password = getenv('SPOC_DB_PASSWORD');
$driver = getenv('SPOC_DB_DRIVER') ?: 'pdo_pgsql';
$secretKey = getenv('SECRET_KEY') ?: bin2hex(random_bytes(32));
$instanceId = getenv('INSTANCE_ID') ?: bin2hex(random_bytes(16));

echo "Connecting to database...\n";

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Database connected.\n";
} catch (PDOException $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
    return;
}

$configData = [
    'database' => [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'dbname' => $dbname,
        'username' => $user,
        'password' => $password,
    ],
    'siteUrl' => '',
    'language' => 'en_US',
    'secretKey' => $secretKey,
    'instanceId' => $instanceId,
    'cryptKey' => bin2hex(random_bytes(32)),
    'hashSecretKey' => bin2hex(random_bytes(32)),
    'theme' => 'Espo',
];

file_put_contents($configFile, "<?php\nreturn " . var_export($configData, true) . ";\n");
echo "Config written.\n";

echo "Running rebuild...\n";
chdir(__DIR__);
ob_start();
include 'rebuild.php';
$output = ob_get_clean();
echo $output . "\n";

echo "Creating admin user...\n";
$passwordHash = password_hash('admin123', PASSWORD_BCRYPT);
try {
    $pdo->exec(
        "INSERT INTO `user` (id, user_name, password_hash, last_name, type, created_at, modified_at, deleted)
         VALUES ('" . bin2hex(random_bytes(8)) . "', 'admin', '" . $passwordHash . "', 'Admin', 'admin', '" . date('Y-m-d H:i:s') . "', '" . date('Y-m-d H:i:s') . "', 0)
         ON CONFLICT (user_name, deleted) DO NOTHING"
    );
    echo "Admin user created (admin / admin123).\n";
} catch (PDOException $e) {
    echo "User create error: " . $e->getMessage() . "\n";
}

$installerConfigFile = __DIR__ . '/data/espocrm-internal/config.php';
@mkdir(dirname($installerConfigFile), 0777, true);
file_put_contents(
    $installerConfigFile,
    "<?php\nreturn ['isInstalled' => true];\n"
);

$mainConfigFile = $configFile;
$existingConfig = include $mainConfigFile;
$existingConfig['isInstalled'] = true;
file_put_contents(
    $mainConfigFile,
    "<?php\nreturn " . var_export($existingConfig, true) . ";\n"
);

echo "Setup complete.\n";
