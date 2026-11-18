-- ============================================================
-- Esquema de Base de Datos - Actividad SP2.2
-- Compatible con PostgreSQL (Supabase) y MySQL
-- ============================================================

-- Para PostgreSQL / Supabase:
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

CREATE TABLE IF NOT EXISTS tarjetas (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    bio TEXT NULL,
    avatar_url TEXT NULL,
    color VARCHAR(30) DEFAULT '#3b82f6',
    usuario_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- Para MySQL (opcional si se utiliza MySQL clásico):
-- ============================================================
/*
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
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tarjetas (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    bio TEXT NULL,
    avatar_url TEXT NULL,
    color VARCHAR(30) DEFAULT '#3b82f6',
    usuario_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/
