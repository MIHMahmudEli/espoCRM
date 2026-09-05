#!/bin/sh

MARKER="/var/www/html/data/.setup-done"

if [ -f "$MARKER" ]; then
    echo "Already installed. Skipping setup."
    exec supervisord -c /etc/supervisord.conf -n
fi

echo "=== EspoCRM auto-setup ==="

chown -R 82:82 /var/www/html/data /var/www/html/custom
chmod -R 777 /var/www/html/data /var/www/html/custom

rm -f /var/www/html/data/config.php
rm -f /var/www/html/install/config.php
rm -f /var/www/html/data/logs/espo.log
rm -f /var/www/html/data/cache/*

cd /var/www/html

echo "[1/5] Saving database settings..."
cd /var/www/html/install
php cli.php -a saveSettings -d 'host-name=aws-0-ap-northeast-2.pooler.supabase.com:5432&db-name=postgres&db-user-name=postgres.nghztacvdcmizwjoaktf&db-user-password=MqjuNE5jJTDv9%26B&db-platform=Postgresql&user-lang=en_US' 2>&1

echo ""
echo "--- Config file contents ---"
cat /var/www/html/data/config.php
echo "--- End config ---"

echo ""
echo "--- PDO connection test ---"
php -r "
\$config = include '/var/www/html/data/config.php';
\$db = \$config['database'];
echo 'Host: ' . \$db['host'] . PHP_EOL;
echo 'Port: ' . var_export(\$db['port'], true) . PHP_EOL;
echo 'DbName: ' . \$db['dbname'] . PHP_EOL;
echo 'User: ' . \$db['user'] . PHP_EOL;
echo 'Platform: ' . var_export(\$db['platform'] ?? 'NOT SET', true) . PHP_EOL;
try {
    \$dsn = 'pgsql:host=' . \$db['host'] . ';port=' . \$db['port'] . ';dbname=' . \$db['dbname'];
    \$pdo = new PDO(\$dsn, \$db['user'], \$db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo 'Connection: SUCCESS' . PHP_EOL;
    \$stmt = \$pdo->query('SELECT version()');
    echo 'PostgreSQL version: ' . \$stmt->fetchColumn() . PHP_EOL;
    \$stmt = \$pdo->query('SELECT schemaname, tablename FROM pg_tables WHERE schemaname = \'public\' ORDER BY tablename');
    \$tables = \$stmt->fetchAll(PDO::FETCH_ASSOC);
    echo 'Existing tables: ' . count(\$tables) . PHP_EOL;
    foreach (\$tables as \$t) echo '  - ' . \$t['tablename'] . PHP_EOL;
} catch (Throwable \$e) {
    echo 'Connection FAILED: ' . \$e->getMessage() . PHP_EOL;
}
" 2>&1

echo ""
echo "[2/5] Building database schema..."
cd /var/www/html
php rebuild.php 2>&1
REBUILD_EXIT=$?
echo "rebuild.php exit code: $REBUILD_EXIT"

echo ""
echo "--- Log files ---"
find /var/www/html/data -name "*.log" -type f 2>/dev/null
ls -la /var/www/html/data/logs/ 2>&1
cat /var/www/html/data/logs/espo.log 2>/dev/null || echo "No espo.log found"

echo ""
echo "[3/5] Creating admin user..."
cd /var/www/html/install
php cli.php -a createUser -d 'user-name=admin&user-pass=admin12%40%23' 2>&1

echo ""
echo "[4/5] Saving preferences..."
php cli.php -a savePreferences -d 'language=en_US' 2>&1

echo ""
echo "[5/5] Finalizing installation..."
php cli.php -a finish 2>&1

chown -R 82:82 /var/www/html/data /var/www/html/custom /var/www/html/install/config.php
chmod -R 777 /var/www/html/data /var/www/html/custom

touch "$MARKER"
echo "=== Setup complete ==="

exec supervisord -c /etc/supervisord.conf -n
