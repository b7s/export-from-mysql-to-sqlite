<?php

declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

loadEnv(__DIR__ . '/.env');

// Set tables to ignore data export (but not schema)
$ignoredPatterns = ['%telescope%', 'audits'];

// Set output path - !!! the file will be overwritten if it exists !!!
$outputPath = $argv[1] ?? __DIR__ . '/database/database-export.sqlite';

// Set database connection to mysql or fail
$mysqlConnection = $_ENV['DB_CONNECTION'] ?? 'mysql';
if ($mysqlConnection !== 'mysql') {
    fwrite(STDERR, sprintf("DB_CONNECTION must be 'mysql', current value: %s\n", $mysqlConnection));
    exit(1);
}

// Set database connection parameters
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = (int) ($_ENV['DB_PORT'] ?? 3306);
$database = $_ENV['DB_DATABASE'] ?? '';
$username = $_ENV['DB_USERNAME'] ?? '';
$password = $_ENV['DB_PASSWORD'] ?? '';

if ($database === '') {
    fwrite(STDERR, "DB_DATABASE is missing in .env\n");
    exit(1);
}

// Set output directory
$outputDir = dirname($outputPath);

// Create sqlite file if not exists 
if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, sprintf("Failed to create destination directory: %s\n", $outputDir));
    exit(1);
}

if (file_exists($outputPath) && ! is_writable($outputPath)) {
    fwrite(STDERR, sprintf("Destination file is not writable: %s\n", $outputPath));
    exit(1);
}

// Remove existing SQLite database
if (file_exists($outputPath)) {
    unlink($outputPath);
}

// Create empty SQLite database
touch($outputPath);

// Connect to MySQL
try {
    $mysqlDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $mysql = new PDO($mysqlDsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]);
} catch (PDOException $exception) {
    fwrite(STDERR, sprintf("Failed to connect to MySQL: %s\n", $exception->getMessage()));
    exit(1);
}

if (! extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "The PDO SQLite extension is not enabled. Please enable pdo_sqlite in php.ini before running this script.\n");
    exit(1);
}

try {
    $sqlite = new PDO('sqlite:' . $outputPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $sqlite->exec('PRAGMA foreign_keys = OFF');
    $sqlite->exec('PRAGMA synchronous = OFF');
    $sqlite->exec('PRAGMA journal_mode = MEMORY');
} catch (PDOException $exception) {
    fwrite(STDERR, sprintf("Failed to initialise SQLite database: %s\n", $exception->getMessage()));
    exit(1);
}

$tableNames = fetchTableNames($mysql, $database, $ignoredPatterns);
if ($tableNames === []) {
    fwrite(STDOUT, "No tables found to export.\n");
    exit(0);
}

foreach ($tableNames as $index => $table) {
    fwrite(STDOUT, sprintf("[%d/%d] Exporting %s\n", $index + 1, count($tableNames), $table));

    $sqlite->exec(sprintf('DROP TABLE IF EXISTS "%s"', $table));
    $sqliteSchema = buildCreateStatement($mysql, $table);
    if ($sqliteSchema === null) {
        fwrite(STDERR, sprintf("  Skipping %s (unable to build SQLite schema)\n", $table));
        continue;
    }

    $sqlite->exec($sqliteSchema);

    if (tableShouldSkipData($table, $ignoredPatterns)) {
        continue;
    }

    $rowCount = (int) $mysql->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
    if ($rowCount === 0) {
        continue;
    }

    $dataStmt = $mysql->query(sprintf('SELECT * FROM `%s`', $table));
    $firstRow = $dataStmt->fetch();
    if ($firstRow === false) {
        continue;
    }

    $columns = array_keys($firstRow);
    $quotedColumns = array_map(static fn(string $column): string => '"' . str_replace('"', '""', $column) . '"', $columns);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $insertSql = sprintf('INSERT INTO "%s" (%s) VALUES (%s)', $table, implode(', ', $quotedColumns), $placeholders);
    $insertStmt = $sqlite->prepare($insertSql);

    $sqlite->beginTransaction();

    insertRow($insertStmt, $firstRow);
    while ($row = $dataStmt->fetch()) {
        insertRow($insertStmt, $row);
    }

    $sqlite->commit();
}

$sqlite->exec('PRAGMA foreign_keys = ON');

fwrite(STDOUT, sprintf("Export completed. SQLite file: %s\n", $outputPath));

function fetchTableNames(PDO $mysql, string $schema, array $ignoredPatterns): array
{
    $statement = $mysql->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES ' .
            'WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME'
    );
    $statement->execute(['schema' => $schema]);

    $tables = [];
    while ($tableName = $statement->fetchColumn()) {
        $tables[] = $tableName;
    }

    return $tables;
}

function tableShouldSkipData(string $tableName, array $ignoredPatterns): bool
{
    foreach ($ignoredPatterns as $pattern) {
        $regex = '/^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$/i';
        if (preg_match($regex, $tableName) === 1) {
            return true;
        }
    }

    return false;
}

function insertRow(PDOStatement $insertStmt, array $row): void
{
    $values = array_map(static function ($value) {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }, array_values($row));

    $insertStmt->execute($values);
}

function buildCreateStatement(PDO $mysql, string $table): ?string
{
    $columns = $mysql->query(sprintf('DESCRIBE `%s`', $table));
    if ($columns === false) {
        return null;
    }

    $columnDefinitions = [];
    $primaryKeys = [];
    $autoIncrementHandled = false;

    while ($column = $columns->fetch(PDO::FETCH_ASSOC)) {
        if (! isset($column['Field'], $column['Type'])) {
            continue;
        }

        $name = $column['Field'];
        $type = mapColumnType($column['Type']);
        $nullable = strtoupper((string) ($column['Null'] ?? '')) === 'YES';
        $defaultValue = $column['Default'] ?? null;
        $extra = strtolower((string) ($column['Extra'] ?? ''));
        $isPrimary = strtoupper((string) ($column['Key'] ?? '')) === 'PRI';

        if (strpos($extra, 'auto_increment') !== false) {
            $columnDefinitions[] = sprintf('"%s" INTEGER PRIMARY KEY AUTOINCREMENT', $name);
            $autoIncrementHandled = true;
            continue;
        }

        $definition = sprintf('"%s" %s', $name, $type);

        if (! $nullable) {
            $definition .= ' NOT NULL';
        }

        $defaultClause = buildDefaultClause($defaultValue, $type);
        if ($defaultClause !== '') {
            $definition .= ' ' . $defaultClause;
        }

        $columnDefinitions[] = $definition;

        if ($isPrimary) {
            $primaryKeys[] = $name;
        }
    }

    if (! $autoIncrementHandled && count($primaryKeys) > 0) {
        $quoted = array_map(static function (string $name): string {
            return '"' . str_replace('"', '""', $name) . '"';
        }, $primaryKeys);

        $columnDefinitions[] = 'PRIMARY KEY (' . implode(', ', $quoted) . ')';
    }

    if ($columnDefinitions === []) {
        return null;
    }

    return sprintf(
        "CREATE TABLE IF NOT EXISTS \"%s\" (\n  %s\n);",
        $table,
        implode(",\n  ", $columnDefinitions)
    );
}

function mapColumnType(string $mysqlType): string
{
    $normalized = strtolower($mysqlType);

    if (preg_match('/int/', $normalized) === 1) {
        return 'INTEGER';
    }

    if (preg_match('/decimal|numeric|double|float/', $normalized) === 1) {
        return 'NUMERIC';
    }

    if (preg_match('/blob|binary/', $normalized) === 1) {
        return 'BLOB';
    }

    if (preg_match('/(datetime|timestamp|date|time)/', $normalized) === 1) {
        return 'TEXT';
    }

    return 'TEXT';
}

function buildDefaultClause($defaultValue, string $sqliteType): string
{
    if ($defaultValue === null) {
        return '';
    }

    if (is_numeric($defaultValue) && $sqliteType === 'INTEGER') {
        return 'DEFAULT ' . $defaultValue;
    }

    $upper = strtoupper((string) $defaultValue);
    $keywords = ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'];

    if (in_array($upper, $keywords, true)) {
        return 'DEFAULT ' . $upper;
    }

    return "DEFAULT '" . str_replace("'", "''", (string) $defaultValue) . "'";
}

function stringStartsWithIgnoreCase(string $haystack, string $needle): bool
{
    return strncasecmp($haystack, $needle, strlen($needle)) === 0;
}

function stringEndsWith(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    $needleLength = strlen($needle);

    return substr($haystack, -$needleLength) === $needle;
}

function loadEnv(string $path): void
{
    if (! is_file($path) || ! is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        [$key, $value] = $parts;
        $key = trim($key);
        $value = trim($value);

        if ($value === '') {
            $parsed = '';
        } elseif ($value[0] === '"' && substr($value, -1) === '"') {
            $parsed = stripcslashes(substr($value, 1, -1));
        } elseif ($value[0] === "'" && substr($value, -1) === "'") {
            $parsed = substr($value, 1, -1);
        } else {
            $parsed = $value;
        }

        if ($key !== '') {
            $_ENV[$key] = $parsed;
            putenv(sprintf('%s=%s', $key, $parsed));
        }
    }
}
