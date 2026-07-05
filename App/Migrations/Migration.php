<?php

namespace App\Migrations;

use DateTime;
use Core\Logger;
use Core\Database\Database;
use Core\Exception\ConfigurationException;

class Migration
{
    private const RADIUS_TABLES = [
        'nas',
        'nasreload',
        'radacct',
        'radcheck',
        'radgroupcheck',
        'radgroupreply',
        'radpostauth',
        'radreply',
        'radusergroup',
    ];

    private const APP_TABLES = [
        'jobs',
        'failed_jobs',
        'job_locks',
        'admins',
        'users',
        'groupes',
        'userGroup',
        'adminGroup',
        'groupInvites',
        'radcheck_user',
        'radreply_user',
        'policy_presets',
        'policy_preset_items',
        'user_applied_policies',
        'group_applied_policies',
        'radacct_daily_stats',
        'radacct_monthly_stats',
        'group_daily_stats',
        'app_nas',
        // 'dns_logs',
        'sites',
        'site_journal'
    ];

    private const APP_VIEWS = [
        'radcheck_view',
        'radreply_view',
        'user_stats_with_online',
        'user_monthly_stats_with_online',
        // 'active_sessions',
        // 'session_history',
        // 'user_activity',
        // 'user_stats',
        // 'group_stats',
        // 'live_sessions',
        // 'user_consumption',
        // 'active_user_groups',
        // 'v_radacct',
        'radreply_preset_view',
        'radreply_effective_view',
    ];

    private const APP_INDEXES = [
        'idx_radacct_stop' => [
            'table' => 'radacct',
            'column' => 'acctstoptime'
        ],
        'idx_radacct_username' => [
            'table' => 'radacct',
            'column' => 'username'
        ],
        'idx_radacct_start' => [
            'table' => 'radacct',
            'column' => 'acctstarttime'
        ],
        'idx_usergroup_group' => [
            'table' => 'userGroup',
            'column' => 'group_id'
        ],
        //  Index pour optimiser les agrégations
        'idx_radacct_start_date' => [
            'table' => 'radacct',
            'column' => 'acctstarttime'
        ],
        //  Critique pour toutes les requêtes "sessions actives"
        'idx_radacct_active_sessions' => [
            'table'   => 'radacct',
            'columns' => ['acctstoptime', 'username'],
        ],

        //  Pour les stats journalières par user
        'idx_daily_stats_user_date' => [
            'table'   => 'radacct_daily_stats',
            'columns' => ['user_id', 'stat_date'],
        ],
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
    }

    /**
     * Configure et vérifie le schéma de la base de données
     * 
     * @throws ConfigurationException Si les tables RADIUS requises sont manquantes
     * @return void
     */
    public function setupSchema(): void
    {
        try {
            $this->db->beginTransaction();
            $checkResults = $this->checkSchema(['app_views', 'app_tables', 'radius_tables']);

            $this->validateRadiusTables($checkResults['radius_tables']);
            $this->createMissingEntities($checkResults['app_tables'], 'table');
            $this->createMissingEntities($checkResults['app_views'], 'view');
            $this->createIndexes();

            //  Créer les procédures et événements pour les stats
            $this->setupStatisticsInfrastructure();

            Logger::info('Configuration du schéma terminée avec succès');
            $this->db->commitTransaction();
        } catch (\Exception $e) {
            Logger::info('Problème lors de la configuration des schèmas!');
            Logger::debug($e->getMessage() . ' ' . $e->getCode());
            $this->db->rollbackTransaction();
            throw $e;
        }
    }

    private function createMissingEntities(array $result, string $type): void
    {
        if (!$result['exists']) {
            $items = $type === 'table' ? $result['tables'] : $result['views'];
            foreach ($items as $item) {
                $this->createEntity($type, $item);
            }
        }
    }

    /**
     * Valide que toutes les tables RADIUS requises existent
     * 
     * @throws ConfigurationException Si des tables sont manquantes
     */
    private function validateRadiusTables(array $result): void
    {
        if (!$result['complete']) {
            throw new ConfigurationException(
                errors: [
                    $result['message'],
                    'Tables manquantes: ' . $result['missing']
                ]
            );
        }
    }
    /**
     * Vérifie l'existence réelle d'une table en base (ne se fie jamais à une variable .env)
     */
    public function tableExists(string $tableName): bool
    {
        return in_array($tableName, $this->getExistingTableNames(), true);
    }
    /**
     * Vérifie l'état du schéma de la base de données
     * 
     * @param array $types Types d'éléments à vérifier
     * @return array Résultats de la vérification
     */
    private function checkSchema(array $types = []): array
    {
        $results = [];

        if ($this->shouldCheckTables($types)) {
            $tableNames = $this->getExistingTableNames();

            if (in_array('radius_tables', $types)) {
                $results['radius_tables'] = $this->checkRadiusTables($tableNames);
            }

            if (in_array('app_tables', $types)) {
                $results['app_tables'] = $this->checkAppTables($tableNames);
            }
        }

        if (in_array('app_views', $types)) {
            $results['app_views'] = $this->checkAppViews();
        }

        return $results;
    }

    private function shouldCheckTables(array $types): bool
    {
        return in_array('radius_tables', $types) || in_array('app_tables', $types);
    }

    private function getExistingTableNames(): array
    {
        $tables = $this->db->listTables();
        if (!$tables) {
            return [];
        }

        return array_map(fn($item) => $item['TABLE_NAME'] ?? $item['table_name'], $tables);
    }

    private function checkRadiusTables(array $existingTables): array
    {
        if (empty($existingTables)) {
            return [
                'complete' => false,
                'message' => 'Impossible de trouver les table du service radius!',
                'missing' => implode(', ', self::RADIUS_TABLES)
            ];
        }

        $missingTables = array_diff(self::RADIUS_TABLES, $existingTables);

        if (!empty($missingTables)) {
            return [
                'complete' => false,
                'message' => 'Tables RADIUS nécessaires sont introuvables.',
                'missing' => implode(', ', $missingTables)
            ];
        }

        return ['complete' => true];
    }

    private function checkAppTables(array $existingTables): array
    {
        $missingTables = array_diff(self::APP_TABLES, $existingTables);

        if (!empty($missingTables)) {
            return [
                'exists' => false,
                'tables' => array_values($missingTables)
            ];
        }

        return ['exists' => true];
    }

    private function checkAppViews(): array
    {
        $views = $this->db->listViews();
        $viewNames = array_map(fn($item) => $item['view_name'], $views);
        $missingViews = array_diff(self::APP_VIEWS, $viewNames);

        if (!empty($missingViews)) {
            return [
                'exists' => false,
                'views' => array_values($missingViews)
            ];
        }

        return ['exists' => true];
    }

    /**
     * Crée une entité (table ou vue) dans la base de données
     * 
     * @param string $type Type d'entité ('table' ou 'view')
     * @param string $name Nom de l'entité
     */
    private function createEntity(string $type, string $name): void
    {
        $methodName = $this->getCreationMethodName($type, $name);

        if (!method_exists($this, $methodName)) {
            Logger::warning("Méthode '$methodName' introuvable pour créer $type '$name'");
            return;
        }

        try {
            $success = $this->{$methodName}();

            if ($success) {
                Logger::info(ucfirst($type) . " '$name' créé(e) avec succès");
            } else {
                Logger::error("Échec de la création de $type '$name'");
            }
        } catch (\Exception $e) {
            Logger::error("Erreur lors de la création de $type '$name': " . $e->getMessage());
            throw $e;
        }
    }

    private function getCreationMethodName(string $type, string $name): string
    {
        $camelCaseName = str_replace('_', '', ucwords($name, '_'));
        $suffix = ($type === 'table') ? 'Table' : '';

        return 'create' . $camelCaseName . $suffix;
    }
    /**
     * Retourne un aperçu de l'état du schéma sans rien modifier
     *
     * @return array{
     *   radius: array{complete: bool, found: string[], missing: string[]},
     *   app_tables: array{to_create: string[], existing: string[]},
     *   app_views: array{to_create: string[], existing: string[]}
     * }
     */
    public function getSchemaPreview(): array
    {
        $tableNames = $this->getExistingTableNames();
        $views      = $this->db->listViews();
        $viewNames  = array_map(fn($v) => $v['view_name'], $views);

        // Tables RADIUS
        $missingRadius = array_diff(self::RADIUS_TABLES, $tableNames);
        $foundRadius   = array_intersect(self::RADIUS_TABLES, $tableNames);

        // Tables app
        $missingAppTables  = array_diff(self::APP_TABLES, $tableNames);
        $existingAppTables = array_intersect(self::APP_TABLES, $tableNames);

        // Vues app
        $missingViews  = array_diff(self::APP_VIEWS, $viewNames);
        $existingViews = array_intersect(self::APP_VIEWS, $viewNames);

        return [
            'radius' => [
                'complete' => empty($missingRadius),
                'found'    => array_values($foundRadius),
                'missing'  => array_values($missingRadius),
            ],
            'app_tables' => [
                'to_create' => array_values($missingAppTables),
                'existing'  => array_values($existingAppTables),
            ],
            'app_views' => [
                'to_create' => array_values($missingViews),
                'existing'  => array_values($existingViews),
            ],
        ];
    }
    // ========== Création des tables ==========

    private function createUsersTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            fullname VARCHAR(100),
            password_hash VARCHAR(255) NOT NULL,
            status ENUM('active', 'suspended', 'expired') NOT NULL DEFAULT 'active',
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_status (status),
            INDEX idx_expires_at (expires_at)
        )";

        return $this->db->createTable($sql);
    }

    private function createJobsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS jobs (
                    id            VARCHAR(64)   NOT NULL,
                    queue         VARCHAR(128)  NOT NULL,
                    job_class     VARCHAR(512)  NOT NULL,
                    encoded_data  LONGTEXT  NOT NULL,
                    attempts      INT UNSIGNED  NOT NULL DEFAULT 0,
                    max_attempts  INT UNSIGNED  NOT NULL DEFAULT 3,
                    timeout       INT UNSIGNED  NOT NULL DEFAULT 60,
                    available_at  INT UNSIGNED  NOT NULL,
                    created_at    INT UNSIGNED  NOT NULL,
                    status        ENUM('pending','processing')  NOT NULL DEFAULT 'pending',
                    reserved_at   INT UNSIGNED  NULL DEFAULT NULL,

                    PRIMARY KEY (id),
                    INDEX idx_queue_pop     (queue, status, available_at),
                    INDEX idx_reserved_at   (queue, status, reserved_at)
                );";

        return $this->db->createTable($sql);
    }
    private function createFailedJobsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS failed_jobs (
                    id            VARCHAR(64)                    NOT NULL,
                    queue         VARCHAR(128)                   NOT NULL,
                    job_class     VARCHAR(512)                   NOT NULL,
                    encoded_data  LONGTEXT                       NOT NULL,
                    attempts      INT UNSIGNED                   NOT NULL DEFAULT 0,
                    status        ENUM('failed')                 NOT NULL DEFAULT 'failed',
                    failed_at     INT UNSIGNED                   NOT NULL,
                    failed_reason TEXT                           NULL DEFAULT NULL,

                    PRIMARY KEY (id),
                    INDEX idx_failed_queue (queue),
                    INDEX idx_failed_class (job_class(191)),
                    INDEX idx_failed_at    (failed_at)
                );";

        return $this->db->createTable($sql);
    }
    private function createJobLocksTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS job_locks (
                    lock_key    VARCHAR(191)  NOT NULL,
                    locked_at   INT UNSIGNED  NOT NULL,
                    expires_at  INT UNSIGNED  NOT NULL,

                    PRIMARY KEY (lock_key),
                    INDEX idx_expires_at (expires_at)
                );";

        return $this->db->createTable($sql);
    }

    private function createAdminsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fullname VARCHAR(64),
            username VARCHAR(64) UNIQUE,
            email VARCHAR(120) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            password_crypt VARCHAR(255) NOT NULL,
            type ENUM('root', 'moderator') NOT NULL DEFAULT 'moderator',
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_type (type)
        )";

        return $this->db->createTable($sql);
    }

    private function createUserPoliciesTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS user_policies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_timeout INT NULL COMMENT 'Timeout de session en secondes',
            daily_time_limit INT NULL COMMENT 'Limite journalière en secondes',
            total_time_limit INT NULL COMMENT 'Limite totale en secondes',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_policy_user
                FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT uq_user_policy UNIQUE (user_id),
            INDEX idx_user_id (user_id)
        )";

        return $this->db->createTable($sql);
    }

    private function createGroupesTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS `groupes` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            status ENUM('active','inactive','expired') NOT NULL DEFAULT 'active',
            max_members INT(64) NOT NULL DEFAULT 40,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";

        return $this->db->createTable($sql);
    }

    private function createUserGroupTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS userGroup (
            user_id INT NOT NULL,
            group_id INT NOT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, group_id),
            CONSTRAINT fk_user_group_user
                FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_user_group_group
                FOREIGN KEY (group_id)
                REFERENCES `groupes`(id)
                ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_group_id (group_id)
        )";

        return $this->db->createTable($sql);
    }

    private function createAdminGroupTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS adminGroup (
            admin_id INT NOT NULL,
            group_id INT NOT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (admin_id, group_id),
            CONSTRAINT fk_admin_group_admin
                FOREIGN KEY (admin_id)
                REFERENCES admins(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_admin_group_group
                FOREIGN KEY (group_id)
                REFERENCES `groupes`(id)
                ON DELETE CASCADE,
            INDEX idx_admin_id (admin_id),
            INDEX idx_admin_group_id (group_id)
        )";

        return $this->db->createTable($sql);
    }

    private function createGroupInvitesTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS groupInvites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            token CHAR(64) NOT NULL UNIQUE,
            max_uses INT NOT NULL DEFAULT 1,
            used_count INT NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            status ENUM('active', 'expired', 'revoked') DEFAULT 'active',
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            CONSTRAINT fk_invite_group
                FOREIGN KEY (group_id) 
                REFERENCES `groupes`(id)
                ON DELETE CASCADE,
            INDEX idx_token (token),
            INDEX idx_group_id (group_id),
            INDEX idx_status (status)
        )";

        return $this->db->createTable($sql);
    }

    private function createRadreplyUserTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS radreply_user (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            attribute VARCHAR(64) NOT NULL,
            op CHAR(2) NOT NULL DEFAULT ':=',
            value VARCHAR(253) NOT NULL,
            priority INT NOT NULL DEFAULT 100,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_radreply_user_user
                FOREIGN KEY (user_id) 
                REFERENCES users(id)
                ON DELETE CASCADE,
            INDEX idx_user (user_id),
            INDEX idx_attribute (attribute),
            INDEX idx_enabled (enabled),
            INDEX idx_priority (priority)
        )";

        return $this->db->createTable($sql);
    }

    private function createRadCheckUserTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS radcheck_user (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            attribute VARCHAR(64) NOT NULL,
            op VARCHAR(8) NOT NULL DEFAULT ':=',
            value VARCHAR(255) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            reason VARCHAR(255) NULL,
            expires_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_user (user_id),
            INDEX idx_attr (attribute),
            CONSTRAINT fk_radcheck_user_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE
        )";

        return $this->db->createTable($sql);
    }

    private function createPolicyPresetsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS policy_presets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";

        return $this->db->createTable($sql);
    }

    private function createPolicyPresetItemsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS policy_preset_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            preset_id INT NOT NULL,
            attribute VARCHAR(64) NOT NULL,
            op CHAR(2) NOT NULL DEFAULT ':=',
            value VARCHAR(253) NOT NULL,
            priority INT NOT NULL DEFAULT 50,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            CONSTRAINT fk_preset_item_preset FOREIGN KEY (preset_id)
                REFERENCES policy_presets(id)
                ON DELETE CASCADE,
            INDEX idx_preset (preset_id),
            INDEX idx_enabled (enabled),
            INDEX idx_priority (priority)
        )";

        return $this->db->createTable($sql);
    }

    private function createUserAppliedPoliciesTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS user_applied_policies (
            user_id INT NOT NULL,
            preset_id INT NOT NULL,
            scope ENUM('normal', 'special') NOT NULL DEFAULT 'normal',
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, preset_id),
            CONSTRAINT fk_user_applied_policy_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_applied_policy_preset FOREIGN KEY (preset_id)
                REFERENCES policy_presets(id) ON DELETE CASCADE
        )";

        return $this->db->createTable($sql);
    }

    private function createGroupAppliedPoliciesTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS group_applied_policies (
            group_id INT NOT NULL,
            preset_id INT NOT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, preset_id),
            FOREIGN KEY (group_id) REFERENCES groupes(id) ON DELETE CASCADE,
            FOREIGN KEY (preset_id) REFERENCES policy_presets(id) ON DELETE CASCADE
        )";

        return $this->db->createTable($sql);
    }
    private function createSitesTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS  sites (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    domain VARCHAR(255) NOT NULL UNIQUE,
                    first_seen DATE NOT NULL,
                    last_seen DATE NOT NULL,
                    total_visits BIGINT DEFAULT 0,
                    INDEX idx_domain (domain),
                    INDEX idx_last_seen (last_seen)
                )";

        return $this->db->createTable($sql);
    }
    private function createSiteJournalTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS site_journal (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    site_id INT NOT NULL,
                    visit_date DATE NOT NULL,
                    visit_count INT DEFAULT 0,
                    UNIQUE KEY unique_site_date (site_id, visit_date),
                    INDEX idx_date (visit_date),
                    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
                )";

        return $this->db->createTable($sql);
    }
    //  ========== Tables de statistiques agrégées ==========

    private function createRadacctDailyStatsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS radacct_daily_stats (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        
        user_id INT NOT NULL,
        stat_date DATE NOT NULL,
        
        total_sessions INT DEFAULT 0,
        total_time BIGINT DEFAULT 0,
        total_download BIGINT DEFAULT 0,
        total_upload BIGINT DEFAULT 0,
        total_consumption BIGINT DEFAULT 0,
        
        unique_devices INT DEFAULT 0,
        first_login DATETIME,
        last_login DATETIME,
        
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_daily_stats_user FOREIGN KEY (user_id)
            REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uk_user_date (user_id, stat_date),
        INDEX idx_date (stat_date),
        INDEX idx_user_id (user_id),
        INDEX idx_date_range (stat_date, user_id)
    ) ENGINE=InnoDB";

        return $this->db->createTable($sql);
    }

    private function createRadacctMonthlyStatsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS radacct_monthly_stats (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,

        user_id INT NOT NULL,
        stat_year YEAR NOT NULL,
        stat_month TINYINT NOT NULL,
        
        total_sessions INT DEFAULT 0,
        total_time BIGINT DEFAULT 0,
        total_download BIGINT DEFAULT 0,
        total_upload BIGINT DEFAULT 0,
        total_consumption BIGINT DEFAULT 0,
        
        unique_devices INT DEFAULT 0,
        
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        CONSTRAINT fk_monthly_stats_user FOREIGN KEY (user_id)
            REFERENCES users(id) ON DELETE CASCADE,
        
        UNIQUE KEY uk_user_month (user_id, stat_year, stat_month),
        INDEX idx_month (stat_year, stat_month),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB";

        return $this->db->createTable($sql);
    }

    private function createGroupDailyStatsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS group_daily_stats (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            stat_date DATE NOT NULL,
            
            total_sessions INT DEFAULT 0,
            total_time BIGINT DEFAULT 0,
            total_download BIGINT DEFAULT 0,
            total_upload BIGINT DEFAULT 0,
            total_consumption BIGINT DEFAULT 0,
            
            active_users INT DEFAULT 0,
            unique_devices INT DEFAULT 0,
            
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY uk_group_date (group_id, stat_date),
            FOREIGN KEY (group_id) REFERENCES groupes(id) ON DELETE CASCADE,
            INDEX idx_date (stat_date),
            INDEX idx_group_id (group_id)
        ) ENGINE=InnoDB COMMENT='Statistiques groupe agrégées par jour'";

        return $this->db->createTable($sql);
    }
    private function createAppNasTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS app_nas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(253) NOT NULL,
            ip_address VARCHAR(253) NOT NULL,
            zone_name VARCHAR(253),
            port int,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB COMMENT='Les clients NAS'";

        return $this->db->createTable($sql);
    }
    private function createDnsLogsTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS dns_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                source_ip VARCHAR(45) NOT NULL,
                domain VARCHAR(255) NOT NULL,

                query_type VARCHAR(10) NULL,     -- A, AAAA, MX, etc.
                transport ENUM('udp','tcp') NULL,

                logged_at DATETIME NOT NULL,

                INDEX idx_domain (domain),
                INDEX idx_logged_at (logged_at),
                INDEX idx_domain_time (domain, logged_at)
            );";

        return $this->db->createTable($sql);
    }

    // ========== Création des vues ==========

    private function createRadcheckView(): bool
    {
        $sql = "SELECT 
                    id,
                    username,
                    attribute,
                    op,
                    value
                FROM (
                    -- Crypt-Password
                    SELECT 
                        u.id AS id,
                        u.username COLLATE utf8mb4_unicode_ci AS username,
                        'Crypt-Password' COLLATE utf8mb4_unicode_ci AS attribute,
                        ':=' AS op,
                        u.password_hash COLLATE utf8mb4_unicode_ci AS value
                    FROM users u
                    WHERE u.status = 'active'
                        AND (u.expires_at IS NULL OR u.expires_at > NOW())

                    UNION ALL

                    -- Simultaneous-Use
                    SELECT 
                        NULL AS id, 
                        r.username COLLATE utf8mb4_unicode_ci AS username,
                        r.attribute COLLATE utf8mb4_unicode_ci AS attribute,
                        r.op COLLATE utf8mb4_unicode_ci AS op,
                        r.value COLLATE utf8mb4_unicode_ci AS value
                    FROM radreply_effective_view r
                    WHERE r.attribute = 'Simultaneous-Use'
                ) t";

        return $this->db->createView('radcheck_view', $sql, true);
    }

    private function createRadreplyView(): bool
    {
        $sql = "SELECT
                ROW_NUMBER() OVER (ORDER BY username, attribute) AS id,
                username,
                attribute,
                op,
                value
            FROM radreply_effective_view";

        return $this->db->createView('radreply_view', $sql, true);
    }

    private function createRadreplyPresetView(): bool
    {
        $sql = "SELECT u.id         AS id,
                    u.username      AS username,
                    ppi.attribute   AS attribute,
                    ppi.op          AS op,
                    ppi.value       AS value,
                    ppi.priority    AS priority
                FROM users u
                JOIN user_applied_policies uap
                    ON uap.user_id = u.id
                JOIN policy_presets pp
                    ON pp.id = uap.preset_id
                    AND pp.status = 'active'
                    AND (pp.expires_at IS NULL OR pp.expires_at > NOW())
                JOIN policy_preset_items ppi
                    ON ppi.preset_id = pp.id
                    AND ppi.enabled = 1
                WHERE
                    u.status = 'active'
                    AND (u.expires_at IS NULL OR u.expires_at > NOW())";

        return $this->db->createView('radreply_preset_view', $sql, true);
    }

    private function createRadreplyEffectiveView(): bool
    {
        $sql = "SELECT
                t.username,
                t.attribute,
                t.op,
                t.value
            FROM (
                SELECT
                    t.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY t.username, t.attribute 
                        ORDER BY t.final_priority ASC
                    ) AS rn
                FROM (
                    SELECT
                        u.username COLLATE utf8mb4_unicode_ci AS username,
                        ppi.attribute COLLATE utf8mb4_unicode_ci AS attribute,
                        ppi.op COLLATE utf8mb4_unicode_ci AS op,
                        ppi.value COLLATE utf8mb4_unicode_ci AS value,
                        ppi.priority AS priority,
                        CASE
                            WHEN uap.scope = 'special' THEN ppi.priority
                            ELSE ppi.priority + 0.5
                        END AS final_priority,
                        uap.scope AS scope,
                        u.id AS user_id
                    FROM user_applied_policies uap
                    JOIN policy_presets pp ON pp.id = uap.preset_id
                        AND pp.status = 'active'
                        AND (pp.expires_at IS NULL OR pp.expires_at > NOW())
                    JOIN policy_preset_items ppi ON ppi.preset_id = pp.id
                        AND ppi.enabled = 1
                    JOIN users u ON u.id = uap.user_id
                        AND u.status = 'active'
                        AND (u.expires_at IS NULL OR u.expires_at > NOW())

                    UNION ALL

                    SELECT
                        u.username COLLATE utf8mb4_unicode_ci AS username,
                        ppi.attribute COLLATE utf8mb4_unicode_ci AS attribute,
                        ppi.op COLLATE utf8mb4_unicode_ci AS op,
                        ppi.value COLLATE utf8mb4_unicode_ci AS value,
                        ppi.priority AS priority,
                        ppi.priority AS final_priority,
                        'normal' AS scope,
                        u.id AS user_id
                    FROM userGroup ug
                    JOIN group_applied_policies gap ON gap.group_id = ug.group_id
                    JOIN policy_presets pp ON pp.id = gap.preset_id
                        AND pp.status = 'active'
                        AND (pp.expires_at IS NULL OR pp.expires_at > NOW())
                    JOIN policy_preset_items ppi ON ppi.preset_id = pp.id
                        AND ppi.enabled = 1
                    JOIN users u ON u.id = ug.user_id
                        AND u.status = 'active'
                        AND (u.expires_at IS NULL OR u.expires_at > NOW())
                    WHERE NOT EXISTS (
                        SELECT 1 
                        FROM user_applied_policies uap2
                        WHERE uap2.user_id = u.id 
                        AND uap2.scope = 'special'
                    )
                ) t
            ) t
            WHERE t.rn = 1";

        return $this->db->createView('radreply_effective_view', $sql, true);
    }

    private function createActiveSessions(): bool
    {
        $sql = "SELECT
            r.acctsessionid,
            r.username COLLATE utf8mb4_unicode_ci AS username,
            r.callingstationid AS mac_address,
            r.framedipaddress AS ip_address,
            r.acctstarttime,
            TIMESTAMPDIFF(SECOND, r.acctstarttime, NOW()) AS current_duration
        FROM radacct r
        WHERE r.acctstoptime IS NULL";

        return $this->db->createView('active_sessions', $sql, true);
    }

    private function createSessionHistory(): bool
    {
        $sql = "SELECT
            r.username COLLATE utf8mb4_unicode_ci AS username,
            r.callingstationid AS mac_address,
            r.framedipaddress AS ip_address,
            r.acctstarttime,
            r.acctstoptime,
            r.acctsessiontime,
            r.acctterminatecause
        FROM radacct r
        WHERE r.acctstoptime IS NOT NULL
        ORDER BY r.acctstarttime DESC";

        return $this->db->createView('session_history', $sql, true);
    }

    private function createUserActivity(): bool
    {
        $sql = "SELECT
            u.id,
            u.username,
            u.status,
            u.expires_at,
            COUNT(r.acctsessionid) AS total_sessions,
            IFNULL(SUM(r.acctsessiontime), 0) AS total_time
        FROM users u
        LEFT JOIN radacct r ON r.username COLLATE utf8mb4_unicode_ci = u.username
        GROUP BY u.id, u.username, u.status, u.expires_at";

        return $this->db->createView('user_activity', $sql, true);
    }

    private function createUserStats(): bool
    {
        $sql = "SELECT
                    u.id,
                    u.username,
                    u.status,
                    u.expires_at,

                    COUNT(r.acctsessionid) AS total_sessions,
                    IFNULL(SUM(r.acctsessiontime), 0) AS total_time,
                    MAX(r.acctstarttime) AS last_login_at,
                    AVG(r.acctsessiontime) AS avg_session_time,
                    COUNT(DISTINCT r.callingstationid) AS unique_devices,

                    (
                        SUM(
                            CASE
                                WHEN r.acctsessionid IS NOT NULL
                                AND r.acctstoptime IS NULL
                                THEN 1
                                ELSE 0
                            END
                        ) > 0
                    ) AS is_online

                FROM users u
                LEFT JOIN radacct r
                    ON r.username COLLATE utf8mb4_unicode_ci
                    = u.username COLLATE utf8mb4_unicode_ci

                GROUP BY u.id";

        return $this->db->createView('user_stats', $sql, true);
    }
    private function createUserStatsWithOnline(): bool
    {
        $sql = "SELECT 
                u.id        AS user_id,
                u.username,
                rds.stat_date,

                COALESCE(rds.total_sessions,    0) AS total_sessions,
                COALESCE(rds.total_time,        0) AS total_time,
                COALESCE(rds.total_download,    0) AS total_download,
                COALESCE(rds.total_upload,      0) AS total_upload,
                COALESCE(rds.total_consumption, 0) AS total_consumption,
                COALESCE(rds.unique_devices,    0) AS unique_devices,
                rds.first_login,
                rds.last_login,

                CASE WHEN active.username IS NOT NULL THEN 1 ELSE 0 END AS is_online,
                active.callingstationid     AS mac_address,
                active.framedipaddress      AS ip_address,
                active.current_duration     AS current_duration,
                active.avg_session_time     AS avg_session_time,
                active.active_sessions_count AS active_sessions_count

            FROM users u

            LEFT JOIN radacct_daily_stats rds ON rds.user_id = u.id

            LEFT JOIN (
                SELECT
                    r.username                                         AS username,
                    MAX(r.callingstationid)                            AS callingstationid,
                    MAX(r.framedipaddress)                             AS framedipaddress,
                    TIMESTAMPDIFF(SECOND, MIN(r.acctstarttime), NOW()) AS current_duration,
                    AVG(r.acctsessiontime)                             AS avg_session_time,
                    COUNT(*)                                           AS active_sessions_count
                FROM radacct r
                WHERE r.acctstoptime IS NULL
                GROUP BY r.username
            ) active ON active.username = u.username COLLATE utf8mb4_unicode_ci";

        return $this->db->createView('user_stats_with_online', $sql, true);
    }
    private function createUserMonthlyStatsWithOnline(): bool
    {
        $sql = "
        SELECT 
            rms.user_id,
            u.username,
            rms.stat_year,
            rms.stat_month,
            rms.total_sessions,
            rms.total_time,
            rms.total_download,
            rms.total_upload,
            rms.total_consumption,
            rms.unique_devices,

            -- Online status
            CASE 
                WHEN ro.username IS NOT NULL THEN 1
                ELSE 0
            END AS is_online,

            ro.callingstationid AS mac_address,
            ro.framedipaddress AS ip_address,
            ro.current_duration,
            ro.avg_session_time,
            ro.active_sessions_count

        FROM radacct_monthly_stats rms
        INNER JOIN users u ON u.id = rms.user_id

        LEFT JOIN (
            SELECT 
                r.username,
                MAX(r.callingstationid) AS callingstationid,
                MAX(r.framedipaddress) AS framedipaddress,
                TIMESTAMPDIFF(SECOND, MIN(r.acctstarttime), NOW()) AS current_duration,
                AVG(r.acctsessiontime) AS avg_session_time,
                COUNT(*) AS active_sessions_count
            FROM radacct r
            WHERE r.acctstoptime IS NULL
            GROUP BY r.username
        ) ro ON ro.username = u.username COLLATE utf8mb4_unicode_ci
        ";

        return $this->db->createView('user_monthly_stats_with_online', $sql, true);
    }

    private function createGroupStats(): bool
    {
        $sql = "SELECT
            g.id AS group_id,
            g.name AS group_name,
            COUNT(DISTINCT ug.user_id) AS total_users,
            COUNT(DISTINCT CASE WHEN u.status = 'active' THEN u.id END) AS active_users,
            SUM(
                CASE
                    WHEN r.acctsessionid IS NOT NULL
                    AND r.acctstoptime IS NULL
                    THEN 1
                    ELSE 0
                END
            ) > 0 AS is_online,
            COUNT(r.acctsessionid) AS total_sessions,
            IFNULL(SUM(r.acctsessiontime), 0) AS total_time,
            IFNULL(SUM(
                IFNULL(r.acctinputoctets, 0) +
                IFNULL(r.acctoutputoctets, 0)
            ), 0) AS total_consumption,
            IFNULL(SUM(r.acctinputoctets), 0)  AS total_download,
            IFNULL(SUM(r.acctoutputoctets), 0) AS total_upload
        FROM `groupes` g
        LEFT JOIN userGroup ug ON ug.group_id = g.id
        LEFT JOIN users u ON u.id = ug.user_id
        LEFT JOIN radacct r ON r.username COLLATE utf8mb4_unicode_ci = u.username
        GROUP BY g.id, g.name";

        return $this->db->createView('group_stats', $sql, true);
    }

    private function createLiveSessions(): bool
    {
        $sql = "SELECT
            r.acctsessionid,
            u.username,
            g.name AS group_name,
            r.callingstationid AS mac_address,
            r.framedipaddress AS ip_address,
            r.acctstarttime,
            TIMESTAMPDIFF(SECOND, r.acctstarttime, NOW()) AS duration
        FROM radacct r
        JOIN users u ON u.username = r.username COLLATE utf8mb4_unicode_ci
        LEFT JOIN userGroup ug ON ug.user_id = u.id
        LEFT JOIN `groupes` g ON g.id = ug.group_id
        WHERE r.acctstoptime IS NULL";

        return $this->db->createView('live_sessions', $sql, true);
    }

    private function createUserConsumption(): bool
    {
        $sql = "SELECT username,
            SUM(acctinputoctets)  AS total_download,
            SUM(acctoutputoctets) AS total_upload,
            SUM(acctinputoctets + acctoutputoctets) AS total_consumption,
            SUM(acctsessiontime) AS total_time
        FROM radacct
        GROUP BY username";

        return $this->db->createView('user_consumption', $sql, true);
    }

    private function createActiveUserGroups(): bool
    {
        $sql = "SELECT 
            ug.user_id,
            g.id AS group_id,
            g.name
        FROM userGroup ug
        JOIN `groupes` g ON g.id = ug.group_id
        WHERE g.status = 'active'
            AND (g.expires_at IS NULL OR g.expires_at > NOW())";

        return $this->db->createView('active_user_groups', $sql, true);
    }

    private function createVRadacct(): bool
    {
        $sql = "SELECT 
                username COLLATE utf8mb4_0900_ai_ci AS username,
                acctsessionid,
                acctinputoctets,
                acctoutputoctets,
                acctsessiontime,
                acctstarttime
            FROM radacct";

        return $this->db->createView('v_radacct', $sql, true);
    }

    // ========== Création des indexes ==========

    private function createIndexes(): void
    {
        foreach (self::APP_INDEXES as $indexName => $config) {
            try {
                if (!$this->db->indexExists($config['table'], $indexName)) {
                    $success = $this->db->createIndex(
                        table: $config['table'],
                        indexName: $indexName,
                        columns: [$config['column']]
                    );

                    if ($success) {
                        Logger::info("Index '$indexName' créé sur {$config['table']}.{$config['column']}");
                    } else {
                        Logger::warning("Échec de création de l'index '$indexName'");
                    }
                } else {
                    Logger::debug("Index '$indexName' existe déjà sur {$config['table']}");
                }
            } catch (\Exception $e) {
                Logger::error("Erreur lors de la création de l'index '$indexName': " . $e->getMessage());
            }
        }
    }

    //  ========== Configuration de l'infrastructure de statistiques ==========

    private function setupStatisticsInfrastructure(): void
    {
        try {
            $this->createStoredProcedures();
            $this->createScheduledEvents();
            Logger::info('Infrastructure de statistiques configurée avec succès');
        } catch (\Exception $e) {
            Logger::error('Erreur lors de la configuration des statistiques: ' . $e->getMessage());
            throw $e;
        }
    }

    private function createStoredProcedures(): void
    {
        // Procédure pour mise à jour des stats journalières
        $sql = "DROP PROCEDURE IF EXISTS update_daily_stats";
        $this->db->execQuery($sql);

        $sql = "CREATE PROCEDURE update_daily_stats(IN target_date DATE)
            BEGIN
            INSERT INTO radacct_daily_stats (
                user_id, stat_date, total_sessions, total_time,
                total_download, total_upload, total_consumption,
                unique_devices, first_login, last_login
            )
            SELECT 
                u.id as user_id,
                target_date as stat_date,
                COUNT(*) as total_sessions,
                IFNULL(SUM(r.acctsessiontime), 0) as total_time,
                IFNULL(SUM(r.acctinputoctets), 0) as total_download,
                IFNULL(SUM(r.acctoutputoctets), 0) as total_upload,
                IFNULL(SUM(r.acctinputoctets + r.acctoutputoctets), 0) as total_consumption,
                COUNT(DISTINCT r.callingstationid) as unique_devices,
                MIN(r.acctstarttime) as first_login,
                MAX(r.acctstarttime) as last_login
            FROM radacct r
            INNER JOIN users u ON u.username = r.username COLLATE utf8mb4_unicode_ci
            WHERE DATE(r.acctstarttime) = target_date  
            GROUP BY u.id  
            ON DUPLICATE KEY UPDATE
                total_sessions = VALUES(total_sessions),
                total_time = VALUES(total_time),
                total_download = VALUES(total_download),
                total_upload = VALUES(total_upload),
                total_consumption = VALUES(total_consumption),
                unique_devices = VALUES(unique_devices),
                first_login = VALUES(first_login),
                last_login = VALUES(last_login),
                updated_at = CURRENT_TIMESTAMP;
            INSERT INTO group_daily_stats (
                group_id, stat_date, total_sessions, total_time,
                total_download, total_upload, total_consumption, active_users, unique_devices
            )
            SELECT 
                ug.group_id,
                target_date as stat_date, 
                COUNT(*) as total_sessions,
                IFNULL(SUM(r.acctsessiontime), 0) as total_time,
                IFNULL(SUM(r.acctinputoctets), 0) as total_download,
                IFNULL(SUM(r.acctoutputoctets), 0) as total_upload,
                IFNULL(SUM(r.acctinputoctets + r.acctoutputoctets), 0) as total_consumption,
                COUNT(DISTINCT u.id) as active_users,
                COUNT(DISTINCT r.callingstationid) as unique_devices
            FROM radacct r
            INNER JOIN users u ON u.username = r.username COLLATE utf8mb4_unicode_ci
            INNER JOIN userGroup ug ON ug.user_id = u.id
            WHERE DATE(r.acctstarttime) = target_date
            GROUP BY ug.group_id  
            ON DUPLICATE KEY UPDATE
                total_sessions = VALUES(total_sessions),
                total_time = VALUES(total_time),
                total_download = VALUES(total_download),
                total_upload = VALUES(total_upload),
                total_consumption = VALUES(total_consumption),
                active_users = VALUES(active_users),
                unique_devices = VALUES(unique_devices),
                updated_at = CURRENT_TIMESTAMP;
            DELETE FROM radacct
            WHERE DATE(acctstarttime) = target_date
            AND acctstoptime IS NOT NULL;
            END";

        $this->db->execQuery($sql);
        Logger::info('Procédure update_daily_stats créée');

        $sql = "DROP PROCEDURE IF EXISTS cleanup_radius_logs";
        $this->db->execQuery($sql);

        $sql = "CREATE PROCEDURE cleanup_radius_logs()
            BEGIN

                -- Nettoyage des authentifications
                DELETE FROM radpostauth
                WHERE authdate < NOW() - INTERVAL 1 DAY;

                -- Nettoyage des sessions terminées
                DELETE FROM radacct
                WHERE acctstoptime IS NOT NULL
                AND acctstarttime < NOW() - INTERVAL 1 DAY;

            END";

        $this->db->execQuery($sql);
        Logger::info('Procédure cleanup_radius_logs créée');

        // Procédure pour mise à jour des stats mensuelles
        $sql = "DROP PROCEDURE IF EXISTS update_monthly_stats";
        $this->db->execQuery($sql);

        $sql = "CREATE PROCEDURE update_monthly_stats(IN target_year YEAR, IN target_month TINYINT)
                BEGIN
                INSERT INTO radacct_monthly_stats (
                        user_id,
                        stat_year,
                        stat_month,
                        total_sessions,
                        total_time,
                        total_download,
                        total_upload,
                        total_consumption,
                        unique_devices
                    )
                    SELECT
                        user_id,
                        YEAR(stat_date)  AS stat_year,
                        MONTH(stat_date) AS stat_month,
                        SUM(total_sessions),
                        SUM(total_time),
                        SUM(total_download),
                        SUM(total_upload),
                        SUM(total_consumption),
                        MAX(unique_devices)
                    FROM radacct_daily_stats
                    GROUP BY
                        user_id,
                        YEAR(stat_date),
                        MONTH(stat_date)
                    ON DUPLICATE KEY UPDATE
                        total_sessions     = VALUES(total_sessions),
                        total_time         = VALUES(total_time),
                        total_download     = VALUES(total_download),
                        total_upload       = VALUES(total_upload),
                        total_consumption  = VALUES(total_consumption),
                        unique_devices     = VALUES(unique_devices),
                        updated_at         = CURRENT_TIMESTAMP;

            END";

        $this->db->execQuery($sql);
        Logger::info('Procédure update_monthly_stats créée');
    }
    private function createScheduledEvents(): void
    {
        // Vérifier si le scheduler est activé
        $result = $this->db->query("SHOW VARIABLES LIKE 'event_scheduler'");
        if ($result && $result[0]['Value'] !== 'ON') {
            Logger::warning('Event scheduler est désactivé. Activez-le avec: SET GLOBAL event_scheduler = ON;');
        }
        // Événement pour supprimer les logs (à 1h du matin)
        $sql = "DROP EVENT IF EXISTS clean_logs";
        $this->db->execQuery($sql);

        $sql = "CREATE EVENT IF NOT EXISTS clean_logs
        ON SCHEDULE EVERY 1 DAY
        STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 1 HOUR)
        DO
            CALL cleanup_radius_logs()";

        $this->db->execQuery($sql);
        Logger::info('Événement clean_logs créé');

        // Événement pour mise à jour quotidienne (à 1h du matin)
        $sql = "DROP EVENT IF EXISTS daily_stats_update";
        $this->db->execQuery($sql);

        $sql = "CREATE EVENT IF NOT EXISTS daily_stats_update
        ON SCHEDULE EVERY 1 DAY
        STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 1 HOUR)
        DO
            CALL update_daily_stats(CURRENT_DATE - INTERVAL 1 DAY)";

        $this->db->execQuery($sql);
        Logger::info('Événement daily_stats_update créé');

        // Événement pour mise à jour mensuelle (le 1er de chaque mois à 2h)
        $sql = "DROP EVENT IF EXISTS monthly_stats_update";
        $this->db->execQuery($sql);

        $sql = "CREATE EVENT IF NOT EXISTS monthly_stats_update
        ON SCHEDULE EVERY 1 MONTH
        STARTS (DATE_FORMAT(CURRENT_DATE + INTERVAL 1 MONTH, '%Y-%m-01') + INTERVAL 2 HOUR)
        DO
        BEGIN
            DECLARE prev_month TINYINT;
            DECLARE prev_year YEAR;
            
            SET prev_month = MONTH(CURRENT_DATE - INTERVAL 1 MONTH);
            SET prev_year = YEAR(CURRENT_DATE - INTERVAL 1 MONTH);
            
            CALL update_monthly_stats(prev_year, prev_month);
        END";

        $this->db->execQuery($sql);
        Logger::info('Événement monthly_stats_update créé');
    }

    /**
     *  Initialiser les statistiques pour les données historiques
     * À exécuter une seule fois après l'installation
     */
    public function initializeHistoricalStats(?DateTime $startDate = null): void
    {
        try {
            $this->db->beginTransaction();

            // Par défaut, prendre les 90 derniers jours
            if ($startDate === null) {
                $startDate = new DateTime('-90 days');
            }

            $endDate = new DateTime('yesterday');

            Logger::info("Initialisation des statistiques historiques depuis " . $startDate->format('Y-m-d'));

            $currentDate = clone $startDate;
            while ($currentDate <= $endDate) {
                $this->db->execQuery(
                    "CALL update_daily_stats(?)",
                    [$currentDate->format('Y-m-d')]
                );
                Logger::info("Stats créées pour " . $currentDate->format('Y-m-d'));
                $currentDate->modify('+1 day');
            }

            Logger::info('Initialisation des statistiques historiques terminée');
            $this->db->commitTransaction();
        } catch (\Exception $e) {
            Logger::error('Erreur lors de l\'initialisation: ' . $e->getMessage());
            $this->db->rollbackTransaction();
            throw $e;
        }
    }
}
