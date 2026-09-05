<?php
/**
 * EspoCRM 10.0.6 Rebuild Diagnostic Script
 * 
 * Purpose: Trace the exact chain of calls that `php rebuild.php` performs
 * and identify every possible failure point that could cause exit code 255
 * with no output.
 *
 * Usage: php test-rebuild.php
 * 
 * Output: Step-by-step trace of the bootstrap and rebuild process,
 *         with exact failure identification.
 */

// ============================================================
// PHASE 0: PRE-BOOTSTRAP DIAGNOSTICS
// ============================================================
echo "=================================================================\n";
echo "EspoCRM 10.0.6 Rebuild Diagnostic Script\n";
echo "=================================================================\n\n";

echo "--- PHP Environment ---\n";
echo "PHP Version:           " . PHP_VERSION . " (Required: >= 8.3.0)\n";
echo "SAPI:                  " . php_sapi_name() . "\n";
echo "OS:                    " . PHP_OS . "\n";
echo "Memory Limit:          " . ini_get('memory_limit') . "\n";
echo "display_errors:        " . ini_get('display_errors') . "\n";
echo "error_reporting:       " . ini_get('error_reporting') . "\n";
echo "\n";

// Check required extensions
echo "--- Required PHP Extensions ---\n";
$required = ['json', 'openssl', 'mbstring', 'zip', 'gd', 'iconv', 'pdo', 'pdo_mysql'];
foreach ($required as $ext) {
    $loaded = extension_loaded($ext);
    echo sprintf("  %-20s %s\n", $ext, $loaded ? "[OK]" : "[MISSING - FATAL]");
}
echo "\n";

// ============================================================
// PHASE 0b: INSTALL ALL ERROR HANDLERS
// ============================================================
echo "--- Installing Error/Exception Handlers ---\n";
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    echo "  [PHP ERROR] $errstr in $errfile:$errline\n";
    return false;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null) {
        echo "\n  [SHUTDOWN FATAL] Type: {$error['type']}\n";
        echo "  Message: {$error['message']}\n";
        echo "  File: {$error['file']}\n";
        echo "  Line: {$error['line']}\n";
    }
});

// Force display errors on for CLI
ini_set('display_errors', '1');
ini_set('error_reporting', E_ALL);
echo "  Handlers installed. Errors will be visible.\n\n";

// ============================================================
// PHASE 1: PRE-FLIGHT FILE CHECKS
// ============================================================
echo "--- Pre-flight File/Directory Checks ---\n";
$basePath = __DIR__;
$checks = [
    'bootstrap.php'                     => 'Bootstrap file',
    'vendor/autoload.php'               => 'Composer autoloader',
    'rebuild.php'                       => 'Rebuild entry point',
    'data/config.php'                   => '*** CRITICAL: Database config ***',
    'data/config-internal.php'          => 'Internal config',
    'data/config-override.php'          => 'Override config',
    'data/config-internal-override.php' => 'Internal override config',
    'data/state.php'                    => 'State config',
    'data/cache'                        => 'Cache directory',
    'data/logs'                         => 'Logs directory',
    'data/tmp'                          => 'Temp directory',
    'data/logs/espo.log'                => 'Log file',
    'application/Espo/Resources/defaults/systemConfig.php' => 'System config defaults',
    'application/Espo/Resources/autoload.json'             => 'Core autoload.json',
    'application/Espo/Modules/Crm/Resources/autoload.json' => 'CRM module autoload.json',
];

foreach ($checks as $path => $desc) {
    $fullPath = $basePath . '/' . $path;
    $exists = file_exists($fullPath);
    $isDir = is_dir($fullPath);
    $status = $exists ? ($isDir ? "[DIR EXISTS]" : "[EXISTS]") : "[MISSING]";
    echo sprintf("  %-50s %s  %s\n", $path, $status, $desc);
}
echo "\n";

// ============================================================
// PHASE 2: BOOTSTRAP (vendor/autoload.php)
// ============================================================
echo "=================================================================\n";
echo "PHASE 2: Bootstrap (Composer Autoloader)\n";
echo "=================================================================\n";
echo "  Loading: vendor/autoload.php ... ";

try {
    // Temporarily raise error level to catch everything
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    
    ob_start();
    $result = @include __DIR__ . "/bootstrap.php";
    ob_end_clean();
    
    if ($result === false) {
        echo "FAILED - include returned false\n";
        echo "  => This means bootstrap.php could not be loaded.\n";
        echo "  => Check if vendor/ directory exists and composer install was run.\n";
        exit(255);
    }
    
    echo "OK\n\n";
} catch (Throwable $e) {
    echo "EXCEPTION during bootstrap:\n";
    echo "  " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\n  => bootstrap.php or vendor/autoload.php failed to load.\n";
    exit(255);
}

// ============================================================
// PHASE 3: Application CONSTRUCTOR (Container Build)
// ============================================================
echo "=================================================================\n";
echo "PHASE 3: Application Constructor (Container Build)\n";
echo "=================================================================\n";

use Espo\Core\Application;
use Espo\Core\ApplicationRunners\Rebuild;

echo "  Step 3.1: new Application() ...\n";
echo "    This calls: initContainer() -> initAutoloads() -> initPreloads()\n";
echo "    initContainer builds: Config -> FileManager -> DataCache -> Module\n";
echo "    -> EspoBindingLoader -> BindingContainer -> Container\n";
echo "    -> ContainerConfiguration (needs Log, Metadata)\n\n";

try {
    ob_start();
    $app = new Application();
    ob_end_clean();
    
    echo "    [OK] Application constructed successfully.\n\n";
} catch (Throwable $e) {
    // Collect any buffered output
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        echo "    Buffered output before exception:\n    $output\n";
    }
    
    echo "    [FAIL] Exception during Application construction:\n";
    echo "    Class:    " . get_class($e) . "\n";
    echo "    Message:  " . $e->getMessage() . "\n";
    echo "    File:     " . $e->getFile() . "\n";
    echo "    Line:     " . $e->getLine() . "\n";
    echo "    Code:     " . $e->getCode() . "\n";
    echo "\n    Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    
    echo "\n  === DIAGNOSIS ===\n";
    echo "  Exit code 255 with no output occurs because:\n";
    echo "  - An uncaught exception was thrown in Application::__construct()\n";
    echo "  - There is NO try-catch in rebuild.php around new Application()\n";
    echo "  - display_errors was Off (or error was swallowed by Monolog handler)\n";
    echo "  - PHP exits with code 255 on unhandled fatal exceptions\n\n";
    
    // Diagnose the specific failure
    diagnoseException($e);
    
    exit(255);
}

// ============================================================
// PHASE 4: Verify Container Services
// ============================================================
echo "=================================================================\n";
echo "PHASE 4: Verify Key Container Services\n";
echo "=================================================================\n";

$services = [
    'config'           => \Espo\Core\Utils\Config::class,
    'metadata'         => \Espo\Core\Utils\Metadata::class,
    'dataManager'      => \Espo\Core\DataManager::class,
    'module'           => \Espo\Core\Utils\Module::class,
    'log'              => \Espo\Core\Utils\Log::class,
    'fileManager'      => \Espo\Core\Utils\File\Manager::class,
    'injectableFactory' => \Espo\Core\InjectableFactory::class,
];

foreach ($services as $name => $class) {
    try {
        $svc = $app->getContainer()->get($name);
        echo "  [OK]   $name ($class)\n";
    } catch (Throwable $e) {
        echo "  [FAIL] $name ($class): " . $e->getMessage() . "\n";
    }
}
echo "\n";

// ============================================================
// PHASE 5: Verify Config Values
// ============================================================
echo "=================================================================\n";
echo "PHASE 5: Verify Critical Config Values\n";
echo "=================================================================\n";

try {
    $config = $app->getContainer()->get('config');
    
    $checks = [
        'isInstalled'            => 'Must be true for rebuild',
        'database.host'          => 'Database host',
        'database.dbName'        => 'Database name',
        'database.user'          => 'Database user',
        'database.platform'      => 'Database platform (Mysql/Postgresql)',
        'defaultPermissions'     => 'Default file permissions',
        'useCache'               => 'Cache setting',
    ];
    
    foreach ($checks as $key => $desc) {
        $val = $config->get($key);
        $display = is_array($val) ? json_encode($val) : var_export($val, true);
        $ok = $val !== null && $val !== false && $val !== '';
        echo sprintf("  %-25s = %-40s %s %s\n", 
            $key, 
            mb_substr($display, 0, 40),
            $ok ? "[SET]" : "[EMPTY/NULL]",
            $desc
        );
    }
    echo "\n";
    
    if (!$config->get('isInstalled')) {
        echo "  *** WARNING: isInstalled is false/null ***\n";
        echo "  This means config.php was not found or does not have isInstalled=true.\n";
        echo "  The rebuild WILL fail because there is no database configuration.\n\n";
    }
    
    if (!$config->get('database.host') && !$config->get('database')) {
        echo "  *** WARNING: No database configuration found ***\n";
        echo "  data/config.php is missing or does not contain database settings.\n";
        echo "  DataManager::rebuild() -> rebuildDatabase() will fail.\n\n";
    }
} catch (Throwable $e) {
    echo "  [FAIL] Could not read config: " . $e->getMessage() . "\n\n";
}

// ============================================================
// PHASE 6: Test Rebuild Runner (isolation)
// ============================================================
echo "=================================================================\n";
echo "PHASE 6: Test Rebuild Runner Execution\n";
echo "=================================================================\n";

echo "  This simulates what Application::run(Rebuild::class) does:\n";
echo "  1. Create RunnerRunner via InjectableFactory\n";
echo "  2. RunnerRunner creates Rebuild (needs DataManager, Log)\n";
echo "  3. Rebuild::run() calls DataManager::rebuild()\n";
echo "  4. DataManager::rebuild() does:\n";
echo "     a) clearCache() - removes data/cache/*\n";
echo "     b) disableHooks()\n";
echo "     c) checkModules()\n";
echo "     d) rebuildMetadata() - rebuilds from JSON files\n";
echo "     e) populateConfigParameters() - calls ConfigWriter::save()\n";
echo "     f) rebuildDatabase() - connects to DB, rebuilds schema\n";
echo "     g) rebuildActionProcessor->process()\n";
echo "     h) configMissingDefaultParamsSaver->process()\n";
echo "     i) enableHooks()\n\n";

echo "  Step 6.1: Trying \$app->run(Rebuild::class) ...\n";

try {
    ob_start();
    $app->run(Rebuild::class);
    ob_end_clean();
    
    echo "    [OK] Rebuild completed successfully.\n\n";
} catch (Throwable $e) {
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        echo "    Buffered output before exception:\n    $output\n";
    }
    
    echo "    [FAIL] Exception during run():\n";
    echo "    Class:    " . get_class($e) . "\n";
    echo "    Message:  " . $e->getMessage() . "\n";
    echo "    File:     " . $e->getFile() . "\n";
    echo "    Line:     " . $e->getLine() . "\n";
    echo "    Code:     " . $e->getCode() . "\n";
    echo "\n    Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
    
    echo "\n  === DIAGNOSIS ===\n";
    diagnoseException($e);
}

echo "\n=================================================================\n";
echo "DIAGNOSTIC COMPLETE\n";
echo "=================================================================\n";

// ============================================================
// DIAGNOSTIC HELPER FUNCTION
// ============================================================
function diagnoseException(Throwable $e): void
{
    $msg = $e->getMessage();
    $class = get_class($e);
    $file = $e->getFile();
    
    echo "  Likely root cause:\n\n";
    
    // Check for config file issues
    if (str_contains($msg, 'config') && str_contains($msg, 'not found')) {
        echo "  ** data/config.php is missing **\n";
        echo "  The ConfigWriter::save() method requires data/config.php to exist.\n";
        echo "  DataManager::rebuild() calls populateConfigParameters() which\n";
        echo "  calls configWriter->save(), which throws RuntimeException if\n";
        echo "  data/config.php is not found.\n\n";
        echo "  FIX: Create data/config.php with at minimum:\n";
        echo "  <?php return ['database' => ['host' => 'localhost', 'dbName' => '...',\n";
        echo "    'user' => '...', 'password' => '...', 'charset' => 'utf8mb4'],\n";
        echo "    'isInstalled' => true];\n\n";
    }
    
    // Check for database connection issues
    if (str_contains($msg, 'database') || str_contains($msg, 'PDOException') || 
        str_contains($msg, 'connection') || str_contains($msg, 'SQLSTATE') ||
        str_contains($msg, 'pdo') || str_contains($msg, 'mysql')) {
        echo "  ** Database connection failed **\n";
        echo "  DataManager::rebuild() -> rebuildDatabase() tries to connect\n";
        echo "  to the database via EntityManager -> PDO.\n";
        echo "  If the DB is not running, credentials are wrong, or the\n";
        echo "  database schema hasn't been created yet, this will fail.\n\n";
    }
    
    // Check for file permission issues
    if ($class === \Espo\Core\Utils\File\Exceptions\PermissionError::class ||
        str_contains($msg, 'Permission')) {
        echo "  ** File permission error **\n";
        echo "  The application cannot create/write files in data/ directory.\n";
        echo "  Check folder permissions for data/, data/cache/, data/logs/\n\n";
    }
    
    // Check for injection/DI issues
    if (str_contains($msg, 'InjectableFactory') || str_contains($msg, 'injectable') ||
        str_contains($msg, 'could not be resolved') || str_contains($msg, 'not resolved')) {
        echo "  ** Dependency injection failure **\n";
        echo "  A class constructor dependency could not be resolved.\n";
        echo "  This could indicate missing autoloaded classes.\n\n";
    }
    
    // Check for module issues
    if (str_contains($msg, 'module') || str_contains($msg, 'Module')) {
        echo "  ** Module loading issue **\n";
        echo "  Check that application/Espo/Modules/Crm/ exists and has\n";
        echo "  proper Resources/module.json and autoload.json.\n\n";
    }
    
    // Check for metadata issues
    if (str_contains($file, 'Metadata') || str_contains($file, 'metadata') ||
        str_contains($msg, 'metadata')) {
        echo "  ** Metadata loading failure **\n";
        echo "  Metadata is built by reading JSON files from module directories.\n";
        echo "  If any JSON file is malformed, it will throw.\n\n";
    }
    
    // Check for autoloading issues
    if (str_contains($msg, 'does not exist') && str_contains($msg, 'class')) {
        echo "  ** Class not found **\n";
        echo "  A required class could not be autoloaded.\n";
        echo "  Run: composer dump-autoload\n";
        echo "  Check that all vendor dependencies are installed.\n\n";
    }
    
    // General exit 255 explanation
    echo "  WHY exit code 255 with no output:\n";
    echo "  ─────────────────────────────────────\n";
    echo "  PHP exit code 255 = unhandled fatal error/exception.\n";
    echo "  'No output' occurs because:\n";
    echo "  1. display_errors is likely Off in your php.ini (CLI)\n";
    echo "  2. The exception propagates out of rebuild.php uncaught\n";
    echo "     (rebuild.php has NO try-catch around new Application())\n";
    echo "  3. OR: Monolog's error handler catches it but cannot write\n";
    echo "     to data/logs/espo.log (directory doesn't exist)\n";
    echo "  4. In both cases, PHP's default behavior is exit(255)\n\n";
    
    echo "  RECOMMENDED FIXES (in order):\n";
    echo "  1. Ensure data/config.php exists with database + isInstalled=true\n";
    echo "  2. Ensure data/cache/ directory exists (create it)\n";
    echo "  3. Ensure data/logs/ directory exists (create it)\n";
    echo "  4. Ensure the database is running and schema is created\n";
    echo "  5. Run: php rebuild.php -d display_errors=1 -d error_reporting=E_ALL\n";
    echo "     to see the actual error message\n";
}
