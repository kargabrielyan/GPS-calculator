<?php
/**
 * SmartGPS Vehicles Admin Interface
 * 
 * Админ-интерфейс для управления каталогом ТС
 * 
 * @package Machine Calculator
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MC_Vehicles_Admin {
    
    /**
     * Catalog instance
     *
     * @var MC_Vehicles_Catalog
     */
    private $catalog;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->catalog = new MC_Vehicles_Catalog();
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // add_action('admin_menu', [$this, 'add_admin_menu']); // Отключено - меню интегрировано в GPS-calculator
        // add_action('admin_init', [$this, 'handle_post_requests']); // Отключено - теперь используется AJAX
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Каталог ТС',
            'Каталог ТС',
            'manage_options',
            'gps-calculator',
            [$this, 'admin_page'],
            'dashicons-car',
            59
        );
    }
    
    /**
     * Handle POST requests
     */
    public function handle_post_requests() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'sgc_nonce')) {
            return;
        }
        
        $action = sanitize_text_field($_POST['action'] ?? '');
        
        switch ($action) {
            case 'add_make':
                $this->handle_add_make();
                break;
            case 'add_model':
                $this->handle_add_model();
                break;
            case 'delete_make':
                $this->handle_delete_make();
                break;
            case 'delete_model':
                $this->handle_delete_model();
                break;
            case 'import_csv':
                $this->handle_import_csv();
                break;
            case 'export_csv':
                $this->handle_export_csv();
                break;
        }
    }
    
    /**
     * Handle add make
     */
    private function handle_add_make() {
        $name = trim(sanitize_text_field($_POST['name'] ?? ''));
        $type = sanitize_text_field($_POST['type'] ?? MC_Vehicles_Catalog::TYPE_PASSENGER);
        
        $result = $this->catalog->add_make($name, $type);
        
        if ($result['success']) {
            add_settings_error('sgc', 'success', 'Марка "' . $name . '" добавлена', 'updated');
        } else {
            switch ($result['error']) {
                case 'empty':
                    add_settings_error('sgc', 'empty', 'Введите название марки');
                    break;
                case 'short':
                    add_settings_error('sgc', 'short', 'Название марки должно содержать минимум 2 символа');
                    break;
                case 'invalid':
                    add_settings_error('sgc', 'invalid', 'Название может содержать только буквы, цифры, пробелы и дефисы');
                    break;
                case 'duplicate':
                    add_settings_error('sgc', 'duplicate', 'Марка "' . $name . '" уже существует');
                    break;
                case 'database':
                    $db_error = isset($result['db_error']) ? $result['db_error'] : 'Неизвестная ошибка';
                    add_settings_error('sgc', 'database', 'Ошибка базы данных при добавлении марки: ' . $db_error);
                    break;
                default:
                    add_settings_error('sgc', 'error', 'Неизвестная ошибка при добавлении марки');
            }
        }
    }
    
    /**
     * Handle add model
     */
    private function handle_add_model() {
        $make_id = (int) ($_POST['make_id'] ?? 0);
        $name = trim(sanitize_text_field($_POST['name'] ?? ''));
        
        $result = $this->catalog->add_model($make_id, $name);
        
        if ($result['success']) {
            add_settings_error('sgc', 'success', 'Модель "' . $name . '" добавлена', 'updated');
        } else {
            switch ($result['error']) {
                case 'invalid_make':
                    add_settings_error('sgc', 'invalid_make', 'Выберите марку');
                    break;
                case 'empty':
                    add_settings_error('sgc', 'empty', 'Введите название модели');
                    break;
                case 'short':
                    add_settings_error('sgc', 'short', 'Название модели не может быть пустым');
                    break;
                case 'invalid':
                    add_settings_error('sgc', 'invalid', 'Название может содержать только буквы, цифры, пробелы и дефисы');
                    break;
                case 'duplicate':
                    add_settings_error('sgc', 'duplicate', 'Модель "' . $name . '" уже существует');
                    break;
                case 'database':
                    add_settings_error('sgc', 'database', 'Ошибка базы данных при добавлении модели');
                    break;
                default:
                    add_settings_error('sgc', 'error', 'Неизвестная ошибка при добавлении модели');
            }
        }
    }
    
    /**
     * Handle delete make
     */
    private function handle_delete_make() {
        $make_id = (int) ($_POST['make_id'] ?? 0);
        
        if ($make_id <= 0) {
            add_settings_error('sgc', 'error', 'Неверный ID марки');
            return;
        }
        
        $result = $this->catalog->delete_make($make_id);
        
        if ($result) {
            add_settings_error('sgc', 'success', 'Марка и все её модели удалены', 'updated');
        } else {
            add_settings_error('sgc', 'error', 'Ошибка при удалении марки');
        }
    }
    
    /**
     * Handle delete model
     */
    private function handle_delete_model() {
        $model_id = (int) ($_POST['model_id'] ?? 0);
        
        if ($model_id <= 0) {
            add_settings_error('sgc', 'error', 'Неверный ID модели');
            return;
        }
        
        $result = $this->catalog->delete_model($model_id);
        
        if ($result) {
            add_settings_error('sgc', 'success', 'Модель удалена', 'updated');
        } else {
            add_settings_error('sgc', 'error', 'Ошибка при удалении модели');
        }
    }
    
    /**
     * Handle CSV import
     */
    private function handle_import_csv() {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            add_settings_error('sgc', 'error', 'Ошибка загрузки файла');
            return;
        }
        
        $csv_content = file_get_contents($_FILES['csv_file']['tmp_name']);
        $result = $this->catalog->import_csv($csv_content);
        
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                add_settings_error('sgc', 'import_error', $error);
            }
        }
        
        if ($result['imported'] > 0) {
            add_settings_error('sgc', 'success', "Импортировано {$result['imported']} записей", 'updated');
        }
    }
    
    /**
     * Handle CSV export
     */
    private function handle_export_csv() {
        $csv_content = $this->catalog->export_csv();
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="vehicles_catalog.csv"');
        header('Content-Length: ' . strlen($csv_content));
        
        echo $csv_content;
        exit;
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Скрипты теперь загружаются в GPS-calculator
        // if ($hook !== 'toplevel_page_gps-calculator') {
        //     return;
        // }
        
        // wp_enqueue_style('sgc-admin', MACHINE_CALC_PLUGIN_URL . 'assets/css/admin.css', [], MACHINE_CALC_VERSION);
        // wp_enqueue_script('sgc-admin', MACHINE_CALC_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], MACHINE_CALC_VERSION, true);
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        $type = sanitize_text_field($_GET['type'] ?? MC_Vehicles_Catalog::TYPE_PASSENGER);
        $qmake = sanitize_text_field($_GET['qmake'] ?? '');
        $sel_make = (int) ($_GET['make_id'] ?? 0);
        $qmodel = sanitize_text_field($_GET['qmodel'] ?? '');
        
        // Get data
        $makes = $this->catalog->get_makes($type, $qmake);
        $models = [];
        $selected_make = null;
        
        if ($sel_make > 0) {
            $models = $this->catalog->get_models($sel_make, $qmodel);
            $selected_make = $this->catalog->get_make($sel_make);
        }
        
        $stats = $this->catalog->get_stats();
        
        // Display admin page
        $this->render_admin_page($type, $qmake, $sel_make, $qmodel, $makes, $models, $selected_make, $stats);
    }
    
    /**
     * Render admin page
     */
    private function render_admin_page($type, $qmake, $sel_make, $qmodel, $makes, $models, $selected_make, $stats) {
        settings_errors('sgc');
        ?>
        <div class="wrap">
            <h1>🚗 Каталог ТС</h1>
            
            <!-- Type Tabs -->
            <div class="sgc-tabs">
                <a class="sgc-tab sgc-type-tab <?php echo $type === MC_Vehicles_Catalog::TYPE_PASSENGER ? 'active' : ''; ?>" 
                   href="#"
                   data-type="<?php echo MC_Vehicles_Catalog::TYPE_PASSENGER; ?>">
                    Մարդատար
                </a>
                <a class="sgc-tab sgc-type-tab <?php echo $type === MC_Vehicles_Catalog::TYPE_TRUCK ? 'active' : ''; ?>" 
                   href="#"
                   data-type="<?php echo MC_Vehicles_Catalog::TYPE_TRUCK; ?>">
                    Բեռնատար
                </a>
            </div>
            
            <!-- Two Column Layout -->
            <div class="sgc-two-columns">
                <!-- Left Column: Makes -->
                <div class="sgc-column sgc-makes-column">
                    <div class="sgc-column-header">
                        <h2>Մակնիշ</h2>
                        
                        <!-- Search Makes -->
                        <div class="sgc-search-form">
                            <input type="text" name="qmake" value="<?php echo esc_attr($qmake); ?>" placeholder="Поиск марки..." class="sgc-search-input" data-target="makes">
                            <button type="button" class="button sgc-search-btn" data-target="makes">🔍</button>
                            <?php if (!empty($qmake)): ?>
                                <button type="button" class="button sgc-clear-search" data-target="makes">❌</button>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Add Make -->
                        <form class="sgc-add-form sgc-ajax-form" data-action="add_make">
                            <?php wp_nonce_field('sgc_nonce', 'nonce'); ?>
                            <input type="hidden" name="type" value="<?php echo esc_attr($type); ?>">
                            <input type="text" name="name" placeholder="Новая марка" required class="sgc-input" minlength="2">
                            <button type="submit" class="button button-primary">➕</button>
                        </form>
                    </div>
                    
                    <!-- Makes List -->
                    <div class="sgc-list-container">
                        <?php if (empty($makes)): ?>
                            <p class="sgc-empty">Марок пока нет</p>
                        <?php else: ?>
                            <ul class="sgc-list">
                                <?php foreach ($makes as $make): ?>
                                    <li class="sgc-list-item">
                                        <a href="#" 
                                           class="sgc-item-link sgc-make-link <?php echo $sel_make == $make->id ? 'active' : ''; ?>"
                                           data-make-id="<?php echo $make->id; ?>"
                                           data-make-name="<?php echo esc_attr($make->name); ?>">
                                            <?php echo esc_html($make->name); ?>
                                        </a>
                                        <div class="sgc-item-actions">
                                            <button class="button button-small sgc-delete-btn sgc-ajax-delete" 
                                                    data-action="delete_make" 
                                                    data-id="<?php echo $make->id; ?>"
                                                    data-confirm="Удалить марку и все её модели?">🗑️</button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Right Column: Models -->
                <div class="sgc-column sgc-models-column">
                    <?php if ($sel_make > 0 && $selected_make): ?>
                        <div class="sgc-column-header">
                            <h2>Модели марки: <?php echo esc_html($selected_make->name); ?></h2>
                            
                            <!-- Search Models -->
                            <div class="sgc-search-form">
                                <input type="text" name="qmodel" value="<?php echo esc_attr($qmodel); ?>" placeholder="Поиск модели..." class="sgc-search-input" data-target="models">
                                <button type="button" class="button sgc-search-btn" data-target="models">🔍</button>
                                <?php if (!empty($qmodel)): ?>
                                    <button type="button" class="button sgc-clear-search" data-target="models">❌</button>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Add Model -->
                            <form class="sgc-add-form sgc-ajax-form" data-action="add_model">
                                <?php wp_nonce_field('sgc_nonce', 'nonce'); ?>
                                <input type="hidden" name="make_id" value="<?php echo $sel_make; ?>">
                                <input type="text" name="name" placeholder="Новая модель" required class="sgc-input" minlength="1">
                                <button type="submit" class="button button-primary">➕</button>
                            </form>
                        </div>
                        
                        <!-- Models List -->
                        <div class="sgc-list-container">
                            <?php if (empty($models)): ?>
                                <p class="sgc-empty">Моделей пока нет</p>
                            <?php else: ?>
                                <ul class="sgc-list">
                                    <?php foreach ($models as $model): ?>
                                        <li class="sgc-list-item">
                                            <span class="sgc-item-name"><?php echo esc_html($model->name); ?></span>
                                            <div class="sgc-item-actions">
                                                <button class="button button-small sgc-delete-btn sgc-ajax-delete" 
                                                        data-action="delete_model" 
                                                        data-id="<?php echo $model->id; ?>"
                                                        data-confirm="Удалить модель?">🗑️</button>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="sgc-column-header">
                            <h2>Մոդել</h2>
                        </div>
                        <div class="sgc-list-container">
                            <p class="sgc-empty">Выберите марку для просмотра моделей</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Import/Export Section -->
            <div class="sgc-section">
                <h2>Импорт/Экспорт</h2>
                
                <div class="sgc-import-export">
                    <!-- Import -->
                    <div class="sgc-import">
                        <h3>📥 Импорт CSV</h3>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field('sgc_nonce'); ?>
                            <input type="hidden" name="action" value="import_csv">
                            <input type="file" name="csv_file" accept=".csv" required>
                            <button type="submit" class="button button-primary">Импортировать</button>
                        </form>
                        <p class="description">Формат: type,make,model (type: mardatar или bernatar)</p>
                    </div>
                    
                    <!-- Export -->
                    <div class="sgc-export">
                        <h3>📤 Экспорт CSV</h3>
                        <form method="post">
                            <?php wp_nonce_field('sgc_nonce'); ?>
                            <input type="hidden" name="action" value="export_csv">
                            <button type="submit" class="button">Скачать CSV</button>
                        </form>
                        <p class="description">Скачать весь каталог в формате CSV</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
