<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$config = require __DIR__ . '/config/app.php';
$legacyModuleGroups = require __DIR__ . '/config/legacy_modules.php';

$moduleFiles = glob(__DIR__ . '/config/*_modules.php');
if (is_array($moduleFiles)) {
    sort($moduleFiles);
    $legacyFile = realpath(__DIR__ . '/config/legacy_modules.php');

    foreach ($moduleFiles as $file) {
        if (realpath($file) === $legacyFile) {
            continue;
        }

        $groups = require $file;
        if (is_array($groups)) {
            $legacyModuleGroups = array_merge($legacyModuleGroups, $groups);
        }
    }
}

date_default_timezone_set($config['timezone'] ?? 'UTC');

spl_autoload_register(function (string $class): void {
    $prefix = 'Stock2\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$database = new Stock2\Database($config['db']);
$schemaService = new Stock2\SchemaService($database->pdo(), $config);
$tableService = new Stock2\TableService($database->pdo(), $schemaService);
$authService = new Stock2\AuthService($database->pdo());
$legacyModuleService = new Stock2\LegacyModuleService($legacyModuleGroups);
$documentNumberService = new Stock2\DocumentNumberService($database->pdo());
$legacyProcessService = new Stock2\LegacyProcessService(
    $database->pdo(),
    $schemaService,
    $legacyModuleService,
    $documentNumberService
);
$reportService = new Stock2\ReportService($database->pdo(), $schemaService, $legacyModuleService);
$businessService = new Stock2\BusinessService($database->pdo());
$manufacturingService = new Stock2\ManufacturingService($database->pdo(), $schemaService);
$erpService = new Stock2\ErpService(
    $database->pdo(),
    $tableService,
    $legacyProcessService,
    $manufacturingService,
    $businessService
);
$inventoryBarcodeService = new Stock2\InventoryBarcodeService($database->pdo());
