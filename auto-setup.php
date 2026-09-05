<?php
/**
 * Auto-setup script for EspoCRM on Render with Supabase PostgreSQL.
 * Runs on first boot if data/config.php does not exist.
 */

$configFile = __DIR__ . '/data/config.php';

if (file_exists($configFile)) {
    echo "EspoCRM already configured. Skipping setup.\n";
    return;
}

$host = getenv('SPOC_DB_HOST') ?: 'aws-0-ap-northeast-2.pooler.supabase.com';
$port = getenv('SPOC_DB_PORT') ?: '5432';
$dbname = getenv('SPOC_DB_NAME') ?: 'postgres';
$user = getenv('SPOC_DB_USER') ?: 'postgres.nghztacvdcmizwjoaktf';
$password = getenv('SPOC_DB_PASSWORD') ?: 'MqjuNE5jJTDv9&B';
$driver = getenv('SPOC_DB_DRIVER') ?: 'pdo_pgsql';
$secretKey = getenv('SECRET_KEY') ?: bin2hex(random_bytes(32));
$instanceId = getenv('INSTANCE_ID') ?: bin2hex(random_bytes(16));

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

$configContent = "<?php\nreturn " . var_export($configData, true) . ";\n";
file_put_contents($configFile, $configContent);

echo "Config file created at $configFile\n";

include __DIR__ . '/bootstrap.php';

use Espo\Core\Application;

$app = new Application();

echo "Running rebuild (creating database schema)...\n";

try {
    $app->run(\Espo\Core\ApplicationRunners\Rebuild::class);
    echo "Database schema created successfully.\n";
} catch (Throwable $e) {
    echo "Rebuild error: " . $e->getMessage() . "\n";
}

echo "Creating admin user...\n";

try {
    $container = $app->getContainer();
    $passwordHash = password_hash('admin123', PASSWORD_BCRYPT);
    $entityManager = $container->getByClass(\Espo\ORM\EntityManager::class);

    $user = $entityManager->getRDBRepository('User')->getBuilder()
        ->where(['userName' => 'admin'])
        ->build()
        ->find();

    if (empty($user)) {
        $adminUser = $entityManager->getEntityFactory()->create('User');
        $adminUser->set('userName', 'admin');
        $adminUser->set('passwordHash', $passwordHash);
        $adminUser->set('firstName', 'Admin');
        $adminUser->set('lastName', '');
        $adminUser->set('roles', []);
        $adminUser->set('type', 'admin');
        $adminUser->set('status', 'active');
        $adminUser->set('portalAccess', false);
        $adminUser->set('defaultPortal', '');
        $adminUser->set('emailAddress', '');
        $adminUser->set('sendEmail', false);

        $entityManager->saveEntity($adminUser);
        echo "Admin user created (username: admin, password: admin123)\n";
    } else {
        echo "Admin user already exists.\n";
    }
} catch (Throwable $e) {
    echo "User creation error: " . $e->getMessage() . "\n";
}

echo "Setup complete.\n";
