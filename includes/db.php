<?php
// =============================================================
// GREAT SOLOMON MANPOWER SERVICES INC. — CORE TRANSACTION 4
// db.php — PDO connection + automatic local database bootstrap.
// =============================================================
require_once __DIR__.'/config.php';

/**
 * Validate a MySQL identifier before it is interpolated into SQL.
 */
function gsms_valid_identifier(string $name): bool {
    return (bool)preg_match('/^[A-Za-z0-9_]+$/', $name);
}

/**
 * Connect to the MySQL server without selecting a database.
 * This is used only when the configured local database does not exist yet.
 */
function gsms_server_pdo(): PDO {
    $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/**
 * Execute database/database.sql one statement at a time.
 *
 * The schema file intentionally contains CREATE TABLE IF NOT EXISTS and
 * migration statements, so it is safe to use on a brand-new local database
 * and on an existing installation.
 */
function gsms_bootstrap_schema(PDO $pdo): void {
    $schema = dirname(__DIR__).'/database/database.sql';
    if (!is_file($schema) || !is_readable($schema)) {
        throw new RuntimeException('Database schema file is missing: database/database.sql');
    }

    $sql = file_get_contents($schema);
    if ($sql === false) {
        throw new RuntimeException('Database schema file could not be read.');
    }

    // Remove SQL comments before splitting. The supplied schema does not use
    // stored procedures/functions, so semicolon-separated statements are safe.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', (string)$sql);

    foreach (preg_split('/;\s*(?:\r?\n|$)/', (string)$sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') continue;

        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            // Migration statements may legitimately fail when a column/index
            // already exists on an older MySQL/MariaDB version. CREATE TABLE
            // statements are not ignored because a broken initial schema
            // should be reported clearly.
            $normalized = strtolower($statement);
            $ignorableMigration =
                str_starts_with($normalized, 'alter table ') &&
                (
                    str_contains($normalized, 'add column if not exists') ||
                    str_contains($normalized, 'add index if not exists')
                );

            if (!$ignorableMigration) {
                throw $e;
            }
        }
    }
}

/**
 * Automatically create/select the local database and install its schema on
 * first use. Existing databases and existing data are preserved.
 */
function gsms_prepare_database(): void {
    static $prepared = false;
    if ($prepared) return;

    if (!gsms_valid_identifier(DB_NAME)) {
        throw new RuntimeException('Invalid database name configured in GSMS_DB_NAME.');
    }

    try {
        // First try the normal connection. This avoids requiring CREATE
        // DATABASE privilege on an already-provisioned database.
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        // Only attempt first-run database creation when MySQL explicitly says
        // the selected database does not exist (error 1049). Authentication,
        // DNS, network, and permission failures must preserve the real error.
        $mysqlErrorCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        if ($mysqlErrorCode !== 1049) {
            throw $e;
        }

        // A missing database is the normal first-run case on XAMPP/WAMP.
        // Try creating it through the server connection.
        $server = gsms_server_pdo();
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS `'.DB_NAME.'` '.
            'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        $pdo = new PDO(
            'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    // Bootstrap only when the schema has not been installed yet. This prevents
    // demo/seed records in database.sql from being duplicated on every request.
    $hasUsersTable = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'users'"
    )->fetchColumn();

    if (!$hasUsersTable) {
        gsms_bootstrap_schema($pdo);
    }

    $prepared = true;
}

/**
 * Singleton PDO connection factory.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    gsms_prepare_database();

    $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Additive, independently checked migrations for existing installations.
    // Every operation is isolated so one unsupported DDL statement cannot block the rest.
    $addColumn = static function(string $table, string $column, string $definition) use ($pdo): void {
        try {
            $check=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $check->execute([$table,$column]);
            if ((int)$check->fetchColumn()===0) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (Throwable $e) { error_log("CT4 migration {$table}.{$column} failed: ".$e->getMessage()); }
    };
    $addIndex = static function(string $table, string $index, string $columns) use ($pdo): void {
        try {
            $check=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
            $check->execute([$table,$index]);
            if ((int)$check->fetchColumn()===0) $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columns})");
        } catch (Throwable $e) { error_log("CT4 migration index {$table}.{$index} failed: ".$e->getMessage()); }
    };
    $addColumn('health_safety_files','requester_user_id','INT UNSIGNED NULL AFTER employee_name');
    $addColumn('health_safety_files','storage_file_id','BIGINT UNSIGNED NULL AFTER file_type');
    $addColumn('health_safety_files','released_at','DATETIME NULL AFTER notes');
    $addColumn('compliance_obligations','report_name','VARCHAR(120) NULL AFTER title');
    $addColumn('compliance_obligations','report_role','VARCHAR(120) NULL AFTER report_name');
    $addColumn('compliance_obligations','contact_no','VARCHAR(60) NULL AFTER report_role');
    $addColumn('compliance_obligations','compliance_note','TEXT NULL AFTER contact_no');
    $addColumn('compliance_obligations','reported_at','DATETIME NULL AFTER compliance_note');
    $addColumn('admin_notifications','reply_to_id','BIGINT UNSIGNED NULL AFTER sender_user_id');
    $addColumn('admin_notifications','deleted_at','DATETIME NULL AFTER is_read');
    $addIndex('admin_notifications','idx_notification_reply_to','reply_to_id');
    $addIndex('admin_notifications','idx_notification_deleted','deleted_at');

    // Existing feedback and notification records are intentionally preserved.
    // Additive task workflow migration; this never drops or rewrites existing records.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS workflow_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            priority ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
            status ENUM('Pending','In Progress','Blocked','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
            approval_status ENUM('Not Required','Pending','Approved','Rejected') NOT NULL DEFAULT 'Not Required',
            due_date DATE NULL,
            assigned_to INT UNSIGNED NULL,
            created_by INT UNSIGNED NULL,
            completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_task_status_due (status,due_date),
            INDEX idx_task_assigned (assigned_to),
            INDEX idx_task_created_by (created_by),
            CONSTRAINT fk_task_assigned_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_task_created_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->exec("ALTER TABLE workflow_tasks ADD COLUMN IF NOT EXISTS approval_status ENUM('Not Required','Pending','Approved','Rejected') NOT NULL DEFAULT 'Not Required' AFTER status");
    } catch (Throwable $e) {
        error_log('CT4 workflow task migration failed: '.$e->getMessage());
    }

    // Do not rewrite user roles or seed accounts automatically. Account roles and
    // credentials are application data and must be managed explicitly by an admin.

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_cases (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            notification_id BIGINT UNSIGNED NOT NULL UNIQUE,
            category VARCHAR(60) NOT NULL DEFAULT 'General Suggestion',
            priority ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
            status ENUM('New','Under Review','In Progress','Resolved','Closed') NOT NULL DEFAULT 'New',
            assigned_to INT UNSIGNED NULL,
            due_date DATE NULL,
            resolved_at DATETIME NULL,
            satisfaction_rating TINYINT UNSIGNED NULL,
            satisfaction_comment VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_feedback_status_priority (status,priority),
            INDEX idx_feedback_assignee (assigned_to),
            INDEX idx_feedback_created (created_at),
            CONSTRAINT fk_feedback_case_notification FOREIGN KEY (notification_id) REFERENCES admin_notifications(id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_case_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feedback_case_id BIGINT UNSIGNED NOT NULL,
            changed_by INT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_feedback_history_case (feedback_case_id,created_at),
            CONSTRAINT fk_feedback_history_case FOREIGN KEY (feedback_case_id) REFERENCES feedback_cases(id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_attachments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            feedback_case_id BIGINT UNSIGNED NOT NULL,
            uploaded_by INT UNSIGNED NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_feedback_attachment_case (feedback_case_id),
            CONSTRAINT fk_feedback_attachment_case FOREIGN KEY (feedback_case_id) REFERENCES feedback_cases(id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_attachment_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT IGNORE INTO feedback_cases (notification_id,category,priority,status,created_at)
            SELECT id,'General Suggestion','Medium',CASE WHEN is_read=1 THEN 'Under Review' ELSE 'New' END,created_at
            FROM admin_notifications WHERE type='feedback' AND deleted_at IS NULL");
    } catch (Throwable $e) { error_log('CT4 feedback migration failed: '.$e->getMessage()); }

    // One-time cleanup requested for the specific Network Printer record. The
    // migration marker prevents later re-registration of a new item from being
    // deleted on every request. Only this exact asset and its dependent
    // issuance/maintenance records are affected; unrelated database content is
    // left untouched. The asset UI also excludes it if this migration cannot run.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_migrations (
            migration_key VARCHAR(120) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $migrationKey = '20261010_remove_network_printer_sn_gsms_0003';
        $migrationCheck = $pdo->prepare('SELECT migration_key FROM app_migrations WHERE migration_key = ?');
        $migrationCheck->execute([$migrationKey]);
        if (!$migrationCheck->fetchColumn()) {
            $pdo->beginTransaction();
            $targetStmt = $pdo->prepare("SELECT id FROM assets
                WHERE asset_tag = ? AND name = ? AND serial_number = ? FOR UPDATE");
            $targetStmt->execute(['AST-0003', 'Network Printer', 'SN-GSMS-0003']);
            $targetIds = array_map('intval', $targetStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($targetIds) {
                $deleteIssuances = $pdo->prepare('DELETE FROM asset_issuances WHERE asset_id = ?');
                $deleteAsset = $pdo->prepare('DELETE FROM assets WHERE id = ?');
                foreach ($targetIds as $targetId) {
                    $deleteIssuances->execute([$targetId]);
                    // maintenance_records use ON DELETE CASCADE; issuance rows
                    // use RESTRICT, so only that asset's dependent rows are removed first.
                    $deleteAsset->execute([$targetId]);
                }
            }
            $markMigration = $pdo->prepare('INSERT INTO app_migrations (migration_key) VALUES (?)');
            $markMigration->execute([$migrationKey]);
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('CT4 asset cleanup migration failed: ' . $e->getMessage());
    }

    return $pdo;
}
