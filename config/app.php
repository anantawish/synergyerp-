<?php

$localConfig = __DIR__ . '/app.local.php';
if (is_file($localConfig)) {
    return require $localConfig;
}

return [
    'app_name' => getenv('SYNERGYERP_APP_NAME') ?: 'SynergyERP',
    'timezone' => getenv('SYNERGYERP_TIMEZONE') ?: 'Asia/Bangkok',
    'schema_cache_ttl' => 300,
    'db' => [
        'host' => getenv('SYNERGYERP_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('SYNERGYERP_DB_PORT') ?: 3306),
        'database' => getenv('SYNERGYERP_DB_DATABASE') ?: 'synergyerp',
        'username' => getenv('SYNERGYERP_DB_USERNAME') ?: 'root',
        'password' => getenv('SYNERGYERP_DB_PASSWORD') ?: '',
        'charset' => getenv('SYNERGYERP_DB_CHARSET') ?: 'utf8mb4',
    ],
];
