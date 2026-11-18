<?php
/**
 * ------------------------------------------------------
 * Conexión a la Base de Datos (Soporta PostgreSQL / Supabase y MySQL)
 * ------------------------------------------------------
 * Compatible con variables de entorno de Vercel y ejecución local.
 */
function getDB() {
    static $pdoInstance = null;
    if ($pdoInstance !== null) {
        return $pdoInstance;
    }

    // 1. Detectar si viene una URL de conexión completa (ej. Supabase DATABASE_URL)
    $databaseUrl = getenv('DATABASE_URL');
    if ($databaseUrl) {
        $dbParts = parse_url($databaseUrl);
        $driver  = ($dbParts['scheme'] === 'postgres' || $dbParts['scheme'] === 'postgresql') ? 'pgsql' : 'mysql';
        $db_host = $dbParts['host'] ?? 'localhost';
        $db_port = $dbParts['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
        $db_user = urldecode($dbParts['user'] ?? '');
        $db_pass = urldecode($dbParts['pass'] ?? '');
        $db_name = ltrim($dbParts['path'] ?? 'postgres', '/');
    } else {
        // 2. Parámetros individuales por variables de entorno o valores de Supabase (Pooler IPv4)
        $driver  = getenv('DB_TYPE') ?: 'pgsql';
        $db_host = getenv('DB_HOST') ?: 'aws-0-us-east-2.pooler.supabase.com';
        $db_name = getenv('DB_NAME') ?: 'postgres';
        $db_user = getenv('DB_USER') ?: 'postgres.ppdffwlgaovdbcqtscft';
        $db_pass = getenv('DB_PASS') ?: 'mnlnjztinR3lLnzp';
        $db_port = getenv('DB_PORT') ?: 6543;
    }

    try {
        if ($driver === 'pgsql') {
            // Conexión para Supabase / PostgreSQL
            $dsn = "pgsql:host={$db_host};port={$db_port};dbname={$db_name};sslmode=require";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 7
            ]);
        } else {
            // Conexión para MySQL
            $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 7
            ]);
        }

        // Auto-creación de tablas si no existen aún (idempotente)
        inicializarTablas($pdo, $driver);

        $pdoInstance = $pdo;
        return $pdo;
    } catch (PDOException $e) {
        error_log('Error de conexión a la BD: ' . $e->getMessage());
        if (function_exists('responderError')) {
            responderError('Error de conexión a la base de datos: ' . $e->getMessage(), 500);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
            exit;
        }
    }
}

/**
 * Crea automáticamente las tablas necesarias si aún no existen
 */
function inicializarTablas($pdo, $driver) {
    try {
        if ($driver === 'pgsql') {
            // Tabla usuarios para PostgreSQL / Supabase
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuarios (
                    id SERIAL PRIMARY KEY,
                    nombre_completo VARCHAR(255) NOT NULL,
                    fecha_nacimiento DATE NULL,
                    nivel_educativo VARCHAR(100) NULL,
                    telefono VARCHAR(50) NULL,
                    horas_programando NUMERIC(5,2) NULL,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    nickname VARCHAR(100) UNIQUE NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");

            // Tabla tarjetas para PostgreSQL (almacenamiento persistente en Supabase)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS tarjetas (
                    id VARCHAR(64) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    bio TEXT NULL,
                    avatar_url TEXT NULL,
                    color VARCHAR(30) DEFAULT '#3b82f6',
                    usuario_id INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");
        } else {
            // Tabla usuarios para MySQL
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usuarios (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nombre_completo VARCHAR(255) NOT NULL,
                    fecha_nacimiento DATE NULL,
                    nivel_educativo VARCHAR(100) NULL,
                    telefono VARCHAR(50) NULL,
                    horas_programando DECIMAL(5,2) NULL,
                    email VARCHAR(255) UNIQUE NOT NULL,
                    nickname VARCHAR(100) UNIQUE NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Tabla tarjetas para MySQL
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS tarjetas (
                    id VARCHAR(64) PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    bio TEXT NULL,
                    avatar_url TEXT NULL,
                    color VARCHAR(30) DEFAULT '#3b82f6',
                    usuario_id INT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
    } catch (Exception $e) {
        // Si no tiene permisos de DDL o ya existen, continuamos silenciosamente
    }
}
?>
