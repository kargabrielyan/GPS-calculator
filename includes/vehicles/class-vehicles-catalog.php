<?php
/**
 * SmartGPS Vehicles Catalog Management
 * 
 * Полностью офлайновая система управления каталогом ТС
 * без внешних API - все данные хранятся в БД WordPress
 * 
 * @package Machine Calculator
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MC_Vehicles_Catalog {
    
    /**
     * Table names
     */
    const TABLE_MAKES = 'mc_makes';
    const TABLE_MODELS = 'mc_models';
    
    /**
     * Vehicle types
     */
    const TYPE_PASSENGER = 'mardatar';
    const TYPE_TRUCK = 'bernatar';
    
    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug = false;
    
    /**
     * Constructor
     */
    public function __construct($debug = false) {
        $this->debug = $debug;
    }
    
    /**
     * Install database tables
     */
    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        // Table for makes
        $sql_makes = "CREATE TABLE {$prefix}" . self::TABLE_MAKES . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            slug VARCHAR(64) NOT NULL,
            type ENUM('mardatar','bernatar') NOT NULL DEFAULT 'mardatar',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_type_name (type, name),
            UNIQUE KEY uniq_type_slug (type, slug),
            KEY idx_type (type)
        ) $charset;";
        
        // Table for models
        $sql_models = "CREATE TABLE {$prefix}" . self::TABLE_MODELS . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            make_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(64) NOT NULL,
            slug VARCHAR(64) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_make_name (make_id, name),
            UNIQUE KEY uniq_make_slug (make_id, slug),
            KEY idx_make (make_id),
            CONSTRAINT fk_make FOREIGN KEY (make_id) REFERENCES {$prefix}" . self::TABLE_MAKES . "(id) ON DELETE CASCADE
        ) $charset;";
        
        dbDelta($sql_makes);
        dbDelta($sql_models);
    }
    
    /**
     * Create slug from name
     *
     * @param string $name
     * @return string
     */
    public static function create_slug($name) {
        $name = remove_accents(trim($name));
        $name = preg_replace('~[^a-zA-Z0-9\- ]+~', '', $name);
        $name = strtolower(preg_replace('~\s+~', '-', $name));
        return substr($name, 0, 64);
    }
    
    /**
     * Get all makes by type
     *
     * @param string $type
     * @param string $search
     * @return array
     */
    public function get_makes($type = self::TYPE_PASSENGER, $search = '') {
        global $wpdb;
        
        $where = $wpdb->prepare("type = %s", $type);
        $params = [$type];
        
        if (!empty($search)) {
            $where .= " AND name LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $sql = "SELECT * FROM {$wpdb->prefix}" . self::TABLE_MAKES . " WHERE {$where} ORDER BY name";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Get all models by make ID
     *
     * @param int $make_id
     * @param string $search
     * @return array
     */
    public function get_models($make_id, $search = '') {
        global $wpdb;
        
        $where = $wpdb->prepare("make_id = %d", $make_id);
        $params = [$make_id];
        
        if (!empty($search)) {
            $where .= " AND name LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $sql = "SELECT * FROM {$wpdb->prefix}" . self::TABLE_MODELS . " WHERE {$where} ORDER BY name";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Add new make
     *
     * @param string $name
     * @param string $type
     * @return array ['success' => bool, 'id' => int, 'error' => string]
     */
    public function add_make($name, $type = self::TYPE_PASSENGER) {
        global $wpdb;
        
        // Check if tables exist, create if not
        $makes_table = $wpdb->prefix . self::TABLE_MAKES;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$makes_table'") === $makes_table;
        
        if (!$table_exists) {
            self::install();
        }
        
        $name = trim(sanitize_text_field($name));
        
        // Validation
        if (empty($name)) {
            return ['success' => false, 'id' => 0, 'error' => 'empty'];
        }
        
        if (strlen($name) < 2) {
            return ['success' => false, 'id' => 0, 'error' => 'short'];
        }
        
        // Allow only letters, spaces, and hyphens
        if (!preg_match('/^[a-zA-Zа-яА-Я0-9\s\-]+$/u', $name)) {
            return ['success' => false, 'id' => 0, 'error' => 'invalid'];
        }
        
        $type = in_array($type, [self::TYPE_PASSENGER, self::TYPE_TRUCK]) ? $type : self::TYPE_PASSENGER;
        $slug = self::create_slug($name);
        
        // Check for duplicates
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . self::TABLE_MAKES . " WHERE type = %s AND LOWER(name) = LOWER(%s)",
            $type, $name
        ));
        
        if ($exists) {
            return ['success' => false, 'id' => 0, 'error' => 'duplicate'];
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE_MAKES,
            [
                'name' => $name,
                'slug' => $slug,
                'type' => $type
            ],
            ['%s', '%s', '%s']
        );
        
        if ($result) {
            return ['success' => true, 'id' => $wpdb->insert_id, 'error' => ''];
        } else {
            // Get detailed error information
            $error = $wpdb->last_error;
            if (empty($error)) {
                $error = 'Unknown database error';
            }
            return ['success' => false, 'id' => 0, 'error' => 'database', 'db_error' => $error];
        }
    }
    
    /**
     * Add new model
     *
     * @param int $make_id
     * @param string $name
     * @return array ['success' => bool, 'id' => int, 'error' => string]
     */
    public function add_model($make_id, $name) {
        global $wpdb;
        
        $make_id = (int) $make_id;
        $name = trim(sanitize_text_field($name));
        
        // Validation
        if ($make_id <= 0) {
            return ['success' => false, 'id' => 0, 'error' => 'invalid_make'];
        }
        
        if (empty($name)) {
            return ['success' => false, 'id' => 0, 'error' => 'empty'];
        }
        
        if (strlen($name) < 1) {
            return ['success' => false, 'id' => 0, 'error' => 'short'];
        }
        
        // Allow only letters, spaces, and hyphens
        if (!preg_match('/^[a-zA-Zа-яА-Я0-9\s\-]+$/u', $name)) {
            return ['success' => false, 'id' => 0, 'error' => 'invalid'];
        }
        
        $slug = self::create_slug($name);
        
        // Check for duplicates
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . self::TABLE_MODELS . " WHERE make_id = %d AND LOWER(name) = LOWER(%s)",
            $make_id, $name
        ));
        
        if ($exists) {
            return ['success' => false, 'id' => 0, 'error' => 'duplicate'];
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . self::TABLE_MODELS,
            [
                'make_id' => $make_id,
                'name' => $name,
                'slug' => $slug
            ],
            ['%d', '%s', '%s']
        );
        
        if ($result) {
            return ['success' => true, 'id' => $wpdb->insert_id, 'error' => ''];
        } else {
            return ['success' => false, 'id' => 0, 'error' => 'database'];
        }
    }
    
    /**
     * Delete make and all its models
     *
     * @param int $make_id
     * @return bool
     */
    public function delete_make($make_id) {
        global $wpdb;
        
        $make_id = (int) $make_id;
        if ($make_id <= 0) {
            return false;
        }
        
        // Models will be deleted automatically due to CASCADE
        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE_MAKES,
            ['id' => $make_id],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Delete model
     *
     * @param int $model_id
     * @return bool
     */
    public function delete_model($model_id) {
        global $wpdb;
        
        $model_id = (int) $model_id;
        if ($model_id <= 0) {
            return false;
        }
        
        $result = $wpdb->delete(
            $wpdb->prefix . self::TABLE_MODELS,
            ['id' => $model_id],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Get make by ID
     *
     * @param int $make_id
     * @return object|null
     */
    public function get_make($make_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE_MAKES . " WHERE id = %d",
            $make_id
        ));
    }
    
    /**
     * Get model by ID
     *
     * @param int $model_id
     * @return object|null
     */
    public function get_model($model_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE_MODELS . " WHERE id = %d",
            $model_id
        ));
    }
    
    /**
     * Get statistics
     *
     * @return array
     */
    public function get_stats() {
        global $wpdb;
        
        $stats = [];
        
        // Count makes by type
        $makes_passenger = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_MAKES . " WHERE type = %s",
            self::TYPE_PASSENGER
        ));
        
        $makes_truck = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_MAKES . " WHERE type = %s",
            self::TYPE_TRUCK
        ));
        
        // Count total models
        $total_models = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_MODELS);
        
        return [
            'makes_passenger' => (int) $makes_passenger,
            'makes_truck' => (int) $makes_truck,
            'total_models' => (int) $total_models
        ];
    }
    
    /**
     * Import from CSV
     *
     * @param string $csv_content
     * @return array
     */
    public function import_csv($csv_content) {
        $lines = str_getcsv($csv_content, "\n");
        $imported = 0;
        $errors = [];
        
        foreach ($lines as $line_num => $line) {
            $line_num++;
            $data = str_getcsv($line);
            
            if (count($data) < 3) {
                $errors[] = "Строка {$line_num}: недостаточно данных";
                continue;
            }
            
            $type = trim($data[0]);
            $make_name = trim($data[1]);
            $model_name = trim($data[2]);
            
            // Validate type
            if (!in_array($type, [self::TYPE_PASSENGER, self::TYPE_TRUCK])) {
                $errors[] = "Строка {$line_num}: неверный тип '{$type}'";
                continue;
            }
            
            // Add make if not exists
            $make = $this->get_makes($type, $make_name);
            if (empty($make)) {
                $make_id = $this->add_make($make_name, $type);
                if (!$make_id) {
                    $errors[] = "Строка {$line_num}: не удалось добавить марку '{$make_name}'";
                    continue;
                }
            } else {
                $make_id = $make[0]->id;
            }
            
            // Add model if not exists
            $models = $this->get_models($make_id, $model_name);
            if (empty($models)) {
                $model_id = $this->add_model($make_id, $model_name);
                if (!$model_id) {
                    $errors[] = "Строка {$line_num}: не удалось добавить модель '{$model_name}'";
                    continue;
                }
            }
            
            $imported++;
        }
        
        return [
            'imported' => $imported,
            'errors' => $errors
        ];
    }
    
    /**
     * Export to CSV
     *
     * @return string
     */
    public function export_csv() {
        global $wpdb;
        
        $sql = "SELECT m.type, m.name as make_name, md.name as model_name 
                FROM {$wpdb->prefix}" . self::TABLE_MODELS . " md
                JOIN {$wpdb->prefix}" . self::TABLE_MAKES . " m ON md.make_id = m.id
                ORDER BY m.type, m.name, md.name";
        
        $results = $wpdb->get_results($sql);
        
        $csv = "type,make,model\n";
        foreach ($results as $row) {
            $csv .= sprintf("%s,%s,%s\n", 
                $row->type, 
                $row->make_name, 
                $row->model_name
            );
        }
        
        return $csv;
    }
    
    /**
     * Log message
     *
     * @param string $message
     */
    private function log($message) {
        if ($this->debug) {
            error_log('MC_Vehicles_Catalog: ' . $message);
        }
    }
}
