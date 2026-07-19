-- Sistema de Formularios Acción Honduras
-- Instalar una sola vez desde phpMyAdmin. No ejecuta ALTER TABLE durante la carga.

CREATE TABLE IF NOT EXISTS ah_forms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(220) NOT NULL,
    descripcion TEXT NULL,
    slug VARCHAR(220) NOT NULL UNIQUE,
    estado ENUM('borrador','publicado','cerrado') NOT NULL DEFAULT 'borrador',
    creado_por BIGINT NULL,
    tema_color VARCHAR(20) NOT NULL DEFAULT '#34859B',
    imagen_cabecera VARCHAR(255) NULL,
    mensaje_confirmacion TEXT NULL,
    configuracion_json LONGTEXT NULL,
    fecha_apertura DATETIME NULL,
    fecha_cierre DATETIME NULL,
    limite_respuestas INT NULL,
    requiere_login TINYINT(1) NOT NULL DEFAULT 0,
    una_respuesta TINYINT(1) NOT NULL DEFAULT 0,
    permitir_edicion TINYINT(1) NOT NULL DEFAULT 0,
    recopilar_correo TINYINT(1) NOT NULL DEFAULT 0,
    mostrar_progreso TINYINT(1) NOT NULL DEFAULT 1,
    notificar_correo VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_forms_estado (estado),
    INDEX idx_forms_creado_por (creado_por)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_form_sections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    titulo VARCHAR(220) NULL,
    descripcion TEXT NULL,
    orden INT NOT NULL DEFAULT 0,
    config_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_sections_form FOREIGN KEY (form_id) REFERENCES ah_forms(id) ON DELETE CASCADE,
    INDEX idx_sections_form_orden (form_id, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_form_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    section_id BIGINT UNSIGNED NULL,
    tipo VARCHAR(40) NOT NULL,
    titulo TEXT NOT NULL,
    descripcion TEXT NULL,
    requerido TINYINT(1) NOT NULL DEFAULT 0,
    orden INT NOT NULL DEFAULT 0,
    opciones_json LONGTEXT NULL,
    validacion_json LONGTEXT NULL,
    logica_json LONGTEXT NULL,
    config_json LONGTEXT NULL,
    puntos DECIMAL(8,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_questions_form FOREIGN KEY (form_id) REFERENCES ah_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_questions_section FOREIGN KEY (section_id) REFERENCES ah_form_sections(id) ON DELETE SET NULL,
    INDEX idx_questions_form_orden (form_id, orden),
    INDEX idx_questions_section_orden (section_id, orden),
    INDEX idx_questions_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_form_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    usuario_id BIGINT NULL,
    correo VARCHAR(190) NULL,
    nombre_respondiente VARCHAR(190) NULL,
    ip_hash CHAR(64) NULL,
    estado ENUM('iniciada','enviada','anulada') NOT NULL DEFAULT 'enviada',
    metadata_json LONGTEXT NULL,
    started_at DATETIME NULL,
    submitted_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_responses_form FOREIGN KEY (form_id) REFERENCES ah_forms(id) ON DELETE CASCADE,
    INDEX idx_responses_form_estado (form_id, estado),
    INDEX idx_responses_usuario (usuario_id),
    INDEX idx_responses_submitted (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_form_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    valor_texto LONGTEXT NULL,
    valor_json LONGTEXT NULL,
    archivo_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_answers_response FOREIGN KEY (response_id) REFERENCES ah_form_responses(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_answers_question FOREIGN KEY (question_id) REFERENCES ah_form_questions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_answer_response_question (response_id, question_id),
    INDEX idx_answers_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ah_form_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT NULL,
    evento VARCHAR(80) NOT NULL,
    detalle_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_audit_form FOREIGN KEY (form_id) REFERENCES ah_forms(id) ON DELETE CASCADE,
    INDEX idx_form_audit_fecha (form_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
