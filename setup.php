<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: setup.php can only be executed from the command line.');
}

$dbUser = 'root';
$dbPass = '';
$dbHost = '127.0.0.1';
$dbPort = 3306;
$databaseNames = ['stacey_laundry_hub'];
$appDbUser = 'stacey_app';
$appDbPass = '2a7003aa0f23f5c2ece82883041c10d1';

$pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
foreach ($databaseNames as $name) {
    $pdo->exec("DROP DATABASE IF EXISTS `{$name}`;");
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

$pdo->exec("CREATE USER IF NOT EXISTS `{$appDbUser}`@`localhost` IDENTIFIED BY " . $pdo->quote($appDbPass));
$pdo->exec("CREATE USER IF NOT EXISTS `{$appDbUser}`@`127.0.0.1` IDENTIFIED BY " . $pdo->quote($appDbPass));
foreach ($databaseNames as $name) {
    $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$name}`.* TO `{$appDbUser}`@`localhost`, `{$appDbUser}`@`127.0.0.1`");
}
$pdo->exec('FLUSH PRIVILEGES');

$scriptPairs = [
    ['stacey_laundry', 'stacey_laundry_hub'],
];

foreach ($scriptPairs as $pair) {
    $folder = $pair[0];
    $dbName = $pair[1];
    $db = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    foreach (["__DIR__ . '/{$folder}/database/schema.sql'", "__DIR__ . '/{$folder}/database/seed.sql'"] as $pathExpr) {
        $path = eval('return ' . $pathExpr . ';');
        $script = file_get_contents($path);
        if ($script !== false) {
            foreach (array_filter(array_map('trim', preg_split('/;\s*(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $script))) as $statement) {
                if ($statement !== '') {
                    $db->exec($statement . ';');
                }
            }
        }
    }
    echo "Imported {$dbName}\n";
}

echo "Database setup complete.\n";