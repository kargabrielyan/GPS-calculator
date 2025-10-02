<?php
/**
 * Plugin Name: Machine Calculator
 * Description: Երեք տարբերակով (ներդիրներ) հաշվիչ և WooCommerce/Subscriptions-ի միջոցով ձևակերպում: Ստեղծում է անհատական ապրանք և տանում է ձևակերպման:
 * Version: 1.3.2
 */

if (!defined('ABSPATH')) exit;

define('MACHINE_CALC_VERSION', '1.3.2');
define('MACHINE_CALC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MACHINE_CALC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include vehicle data classes
require_once MACHINE_CALC_PLUGIN_DIR . 'includes/vehicles/class-vehicles-source.php';
require_once MACHINE_CALC_PLUGIN_DIR . 'includes/vehicles/class-vehicles-rest.php';
require_once MACHINE_CALC_PLUGIN_DIR . 'includes/vehicles/class-vehicles-catalog.php';
require_once MACHINE_CALC_PLUGIN_DIR . 'includes/vehicles/class-vehicles-admin.php';

// Include notice/banner system
require_once MACHINE_CALC_PLUGIN_DIR . 'includes/notice/class-mc-notice-admin.php';
require_once MACHINE_CALC_PLUGIN_DIR . 'includes/notice/class-mc-notice-render.php';

// Include temporary products system
require_once MACHINE_CALC_PLUGIN_DIR . 'includes/class-mc-temp-products-cleaner.php';
require_once MACHINE_CALC_PLUGIN_DIR . 'includes/class-mc-temp-products-admin.php';

class MachineCalculator {
    private $debug = true;
    
    /**
     * Vehicles source instance
     *
     * @var MC_Vehicles_Source
     */
    private $vehicles_source;
    
    /**
     * Vehicles REST instance
     *
     * @var MC_Vehicles_REST
     */
    private $vehicles_rest;
    
    /**
     * Notice admin instance
     *
     * @var MC_Notice_Admin
     */
    private $notice_admin;
    
    /**
     * Notice render instance
     *
     * @var MC_Notice_Render
     */
    private $notice_render;
    
    /**
     * Temp products cleaner instance
     *
     * @var MC_Temp_Products_Cleaner
     */
    private $temp_products_cleaner;
    
    /**
     * Temp products admin instance
     *
     * @var MC_Temp_Products_Admin
     */
    private $temp_products_admin;
    
    /**
     * Vehicles catalog instance
     *
     * @var MC_Vehicles_Catalog
     */
    private $vehicles_catalog;
    
    /**
     * Vehicles admin instance
     *
     * @var MC_Vehicles_Admin
     */
    private $vehicles_admin;

    public function __construct() {
        // Initialize vehicle data classes
        $this->vehicles_source = new MC_Vehicles_Source($this->debug);
        $this->vehicles_rest = new MC_Vehicles_REST($this->vehicles_source, $this->debug);
        
        // Initialize notice system
        $this->notice_admin = new MC_Notice_Admin();
        $this->notice_render = new MC_Notice_Render();
        
        // Initialize temporary products system
        $this->temp_products_cleaner = new MC_Temp_Products_Cleaner();
        $this->temp_products_admin = new MC_Temp_Products_Admin($this->temp_products_cleaner);
        
        // Initialize vehicles catalog system
        $this->vehicles_catalog = new MC_Vehicles_Catalog($this->debug);
        $this->vehicles_admin = new MC_Vehicles_Admin();
        
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_create_machine_order', [$this, 'create_machine_order']);
        add_action('wp_ajax_nopriv_create_machine_order', [$this, 'create_machine_order']);
        add_action('wp_ajax_get_calculator_price', [$this, 'get_calculator_price']);
        add_action('wp_ajax_nopriv_get_calculator_price', [$this, 'get_calculator_price']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_init', [$this, 'init_admin']);
        add_action('wp_ajax_save_calculator_rule', [$this, 'save_calculator_rule']);
        add_action('wp_ajax_delete_calculator_rule', [$this, 'delete_calculator_rule']);
        
        // Vehicles catalog AJAX handlers
        add_action('wp_ajax_add_vehicle_make', [$this, 'ajax_add_vehicle_make']);
        add_action('wp_ajax_add_vehicle_model', [$this, 'ajax_add_vehicle_model']);
        add_action('wp_ajax_delete_vehicle_make', [$this, 'ajax_delete_vehicle_make']);
        add_action('wp_ajax_delete_vehicle_model', [$this, 'ajax_delete_vehicle_model']);
        add_action('wp_ajax_get_vehicle_makes', [$this, 'ajax_get_vehicle_makes']);
        add_action('wp_ajax_get_vehicle_models', [$this, 'ajax_get_vehicle_models']);
        add_shortcode('machine_calculator', [$this, 'display_calculator']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        // Перенаправляем сразу на checkout после add-to-cart из нашего калькулятора
        add_filter('woocommerce_add_to_cart_redirect', [$this, 'maybe_redirect_to_checkout']);
        
        // Хук для обновления связи временного товара с заказом
        add_action('woocommerce_new_order_item', [$this, 'on_new_order_item'], 10, 3);
        
        // Хуки для автозаполнения комментариев в checkout
        add_action('wp_ajax_get_calculator_checkout_comment', [$this, 'get_calculator_checkout_comment']);
        add_action('wp_ajax_nopriv_get_calculator_checkout_comment', [$this, 'get_calculator_checkout_comment']);
    }

    private function log($msg) {
        if ($this->debug) error_log('[Machine Calculator] ' . $msg);
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }
        
        // Принудительная очистка просроченных товаров при каждой инициализации
        // Но не чаще чем раз в 10 минут чтобы не перегружать систему
        $last_cleanup = get_transient('mc_last_forced_cleanup');
        if (!$last_cleanup) {
            $this->temp_products_cleaner->cron_worker();
            set_transient('mc_last_forced_cleanup', time(), 600); // 10 минут
        }
    }

    public function activate() {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('Պլագինի աշխատանքի համար անհրաժեշտ է WooCommerce:');
        }
        // Создание таблицы при активации
        $this->create_rules_table();
        
        // Install vehicles catalog tables
        MC_Vehicles_Catalog::install();
    }
    
    public function deactivate() {
        // Очистка cron событий при деактивации
        MC_Temp_Products_Cleaner::cleanup_on_deactivation();
    }
    
    /**
     * Обновляет связь временного товара с заказом при добавлении товара в заказ
     *
     * @param int $item_id ID элемента заказа
     * @param WC_Order_Item $item Элемент заказа
     * @param int $order_id ID заказа
     */
    public function on_new_order_item($item_id, $item, $order_id) {
        if ($item instanceof WC_Order_Item_Product) {
            $product_id = $item->get_product_id();
            if ($product_id && $this->temp_products_cleaner->is_temp_product($product_id)) {
                $this->temp_products_cleaner->update_product_order_link($product_id, $order_id);
            }
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>Machine Calculator-ը պահանջում է WooCommerce աշխատանքի համար:</p></div>';
    }

    public function enqueue_scripts() {
        // CSS
        wp_enqueue_style('machine-calculator-css', MACHINE_CALC_PLUGIN_URL . 'assets/css/calculator.css', [], MACHINE_CALC_VERSION);
        // JS
        wp_enqueue_script('machine-calculator-js', MACHINE_CALC_PLUGIN_URL . 'assets/js/calculator.js', ['jquery'], MACHINE_CALC_VERSION, true);
        
        // Подключаем скрипт для checkout страницы
        if (is_checkout()) {
            wp_enqueue_script(
                'machine-calculator-checkout',
                MACHINE_CALC_PLUGIN_URL . 'assets/js/checkout.js',
                ['jquery'],
                MACHINE_CALC_VERSION,
                true
            );
            
            wp_localize_script('machine-calculator-checkout', 'machineCalculatorCheckout', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('machine_calc_checkout_nonce')
            ]);
        }
        
        wp_localize_script('machine-calculator-js', 'machine_calc_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('machine_calc_nonce'),
            'rest_base' => MC_Vehicles_REST::get_rest_base_url(),
            'vehicles_dynamic' => true,
            'prices' => [
                'type1_in_am'  => (float) get_option('machine_calc_price_type1_in_am', 1),
                'type1_out_am' => (float) get_option('machine_calc_price_type1_out_am', 1),
                'type2_fuel'   => (float) get_option('machine_calc_price_type2_fuel', 1),
                'type2_tank'   => (float) get_option('machine_calc_price_type2_tank', 0),
                'type3_ref'    => (float) get_option('machine_calc_price_type3_ref', 1),
            ],
            'debug' => [
                'rules_count' => count($this->get_rules()),
                'use_rules_system' => true
            ],
            'i18n' => [
                'order_creating' => __('Անհատական ապրանքի ստեղծում...', 'machine-calculator'),
                'order_button'   => __('Ձևակերպել պատվեր', 'machine-calculator'),
                'need_login'     => __('Պետք է մուտք գործել', 'machine-calculator'),
                'loading_makes' => __('Մակնիշների բեռնում...', 'machine-calculator'),
                'loading_models' => __('Մոդելների բեռնում...', 'machine-calculator'),
                'loading_years' => __('Տարիների բեռնում...', 'machine-calculator'),
                'select_make' => __('Ընտրել', 'machine-calculator'),
                'select_model' => __('Ընտրել', 'machine-calculator'),
                'error_loading_data' => __('Տվյալների բեռնման սխալ', 'machine-calculator'),
                'currency_symbol' => '֏'
            ]
        ]);
        
        // Добавляем отладочную информацию в консоль если debug включен
        if ($this->debug) {
            wp_add_inline_script('machine-calculator-js', '
                console.log("Machine Calculator v1.3.2: Загружено правил из БД:", machine_calc_ajax.debug.rules_count);
                console.log("Machine Calculator: Используется новая система правил");
                console.log("Machine Calculator: REST API базовый URL:", machine_calc_ajax.rest_base);
                console.log("Machine Calculator: Динамические данные автомобилей включены:", machine_calc_ajax.vehicles_dynamic);
                console.log("Machine Calculator: Обновлено - Գնացք заменен на Մարդատար");
            ');
        }
        
    }
    
    // Получить цену для конкретного правила
    private function get_rule_price($zone_type, $brand, $model, $year_code = null, $machine_count = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'machine_calculator_rules';
        
        // Пытаемся найти точное совпадение
        $where_conditions = [
            'zone_type = %s',
            'brand = %s',
            'model = %s'
        ];
        $where_values = [$zone_type, $brand, $model];
        
        // Добавляем год если указан - ТРЕБУЕМ ТОЧНОЕ СОВПАДЕНИЕ
        if ($year_code && $year_code > 0) {
            $where_conditions[] = 'year_code = %d';
            $where_values[] = $year_code;
        }
        
        // Добавляем условие по количеству машин (правило должно покрывать нужное количество)
        if ($machine_count && $machine_count > 0) {
            $where_conditions[] = 'machine_count <= %d';
            $where_values[] = $machine_count;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Ищем точное совпадение (включая год если он указан)
        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE $where_clause ORDER BY machine_count DESC, year_code DESC LIMIT 1",
            ...$where_values
        ), ARRAY_A);
        
        if ($rule) {
            if ($this->debug) {
                error_log("[Machine Calculator] Найдено точное правило: зона={$zone_type}, марка={$brand}, модель={$model}, год={$year_code}, количество={$machine_count}, цена={$rule['filter_price']}");
            }
            return (float)$rule['filter_price'];
        }
        
        // УБИРАЕМ ПОИСК БЕЗ ГОДА - если год указан, но правило не найдено, возвращаем null
        if ($this->debug) {
            error_log("[Machine Calculator] Правило не найдено: зона={$zone_type}, марка={$brand}, модель={$model}, год={$year_code}, количество={$machine_count}");
        }
        
        return null;
    }

    public function display_calculator() {
        ob_start(); ?>
        <div class="mc-wrapper">
            <div class="mc-tabs" role="tablist">
                <button type="button" class="mc-tab active" data-tab="mc-tab-1">Մեքենայի շարժի վերահսկում</button>
                <button type="button" class="mc-tab" data-tab="mc-tab-2">Վառելիքի վերահսկում</button>
                <button type="button" class="mc-tab" data-tab="mc-tab-3">սառնարանի ջերմաստիճանի վերահսկում</button>
            </div>

            <div class="mc-panels">
                <!-- TAB 1: Մեքենայի շարժի վերահսկում -->
                <div id="mc-tab-1" class="mc-panel active">
                    <?php do_action('machine_calculator/notice/top', 'type1'); ?>
                    <div class="mc-grid">
                        <label class="mc-span-2">
                            <span>Գործողության տարածք</span>
                            <div class="mc-options">
                                <label>
                                    <input type="radio" name="mc1-location" id="mc1-loc-in" value="in_am" checked>
                                    <span>ՀՀ-ում</span>
                                </label>
                                <label>
                                    <input type="radio" name="mc1-location" id="mc1-loc-out" value="out_am">
                                    <span>ՀՀ-ից դուրս</span>
                                </label>
                            </div>
                        </label>
                        <div class="mc-vehicle-row">
                            <label>
                                <span>Մեքենայի տիպը</span>
                                <select id="mc1-vehicle-type" required>
                                    <option value="">Ընտրել տիպը</option>
                                    <option value="mardatar">Մարդատար</option>
                                    <option value="bernatar">Բեռնատար</option>
                                </select>
                            </label>
                            <label>
                                <span>Մեքենայի մակնիշը</span>
                                <select id="mc1-make" disabled required></select>
                            </label>
                            <label>
                                <span>Մեքենայի մոդելը</span>
                                <select id="mc1-model" disabled required></select>
                            </label>
                        </div>
                        <label>
                            <span>Արտադր տարում</span>
                            <input type="number" id="mc1-year" min="1900" max="2027" placeholder="2020" required />
                        </label>
                        <label>
                            <span>Մեքենաների քանակ</span>
                            <input type="number" id="mc1-cars" min="1" value="1" required />
                        </label>
                    </div>
                    <?php do_action('machine_calculator/notice/bottom', 'type1'); ?>
                </div>

                <!-- TAB 2: Վառելիքի վերահսկում -->
                <div id="mc-tab-2" class="mc-panel">
                    <?php do_action('machine_calculator/notice/top', 'type2'); ?>
                    <div class="mc-grid">
                        <div class="mc-vehicle-row">
                            <label>
                                <span>Մեքենայի տիպը</span>
                                <select id="mc2-vehicle-type" required>
                                    <option value="">Ընտրել տիպը</option>
                                    <option value="mardatar">Մարդատար</option>
                                    <option value="bernatar">Բեռնատար</option>
                                </select>
                            </label>
                            <label>
                                <span>Մեքենայի մակնիշը</span>
                                <select id="mc2-make" disabled required></select>
                            </label>
                            <label>
                                <span>Մեքենայի մոդելը</span>
                                <select id="mc2-model" disabled required></select>
                            </label>
                        </div>
                        <label>
                            <span>Արտադր տարում</span>
                            <input type="number" id="mc2-year" min="1900" max="2027" placeholder="2020" required />
                        </label>
                        <label>
                            <span>Մեքենաների քանակ</span>
                            <input type="number" id="mc2-cars" min="1" value="1" required />
                        </label>
                        <label>
                            <span>Մեքենաների վառելիքի բաքերի քանակ</span>
                            <input type="number" id="mc2-tanks" min="0" value="0" required />
                        </label>
                    </div>
                    <?php do_action('machine_calculator/notice/bottom', 'type2'); ?>
                </div>

                <!-- TAB 3: սառնարանի ջերմաստիճանի վերահսկում -->
                <div id="mc-tab-3" class="mc-panel">
                    <?php do_action('machine_calculator/notice/top', 'type3'); ?>
                    <div class="mc-grid">
                        <label>
                            <span>Սառնարանների (Ref-երի) քանակ</span>
                            <input type="number" id="mc3-refs" min="1" value="1" required />
                        </label>
                        <label>
                            <span>սառնարանի սենսորների քանակ</span>
                            <input type="number" id="mc3-sensors" min="1" value="1" required />
                        </label>
                    </div>
                    <?php do_action('machine_calculator/notice/bottom', 'type3'); ?>
                </div>
            </div>

            <div class="mc-footer">
                <div class="mc-total">Ընդամենը: <strong><span id="mc-total-price">0</span></strong></div>
                <button id="create-order-btn" class="button button-primary">Ձևակերպել պատվեր</button>
            </div>
            <div id="calculator-message" class="mc-message" style="display:none"></div>
        </div>
        <?php return ob_get_clean();
    }

    public function get_calculator_price() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'machine_calc_nonce')) {
            wp_send_json_error('Անվտանգության սխալ');
        }
        
        $zone_type = sanitize_text_field($_POST['zone_type'] ?? '');
        $brand = sanitize_text_field($_POST['brand'] ?? '');
        $model = sanitize_text_field($_POST['model'] ?? '');
        $year_code = intval($_POST['year_code'] ?? 0);
        $machine_count = intval($_POST['machine_count'] ?? 1);
        $calc_type = sanitize_text_field($_POST['calc_type'] ?? 'type1');
        
        if ($this->debug) {
            error_log("[Machine Calculator] get_calculator_price вызван с параметрами: зона={$zone_type}, марка={$brand}, модель={$model}, год={$year_code}, количество={$machine_count}, тип={$calc_type}");
        }
        
        // Определяем правильную зону в зависимости от типа калькулятора
        $search_zone = $zone_type;
        if ($calc_type === 'type2') {
            $search_zone = 'everywhere'; // Для топлива всегда "везде"
        } elseif ($calc_type === 'type3') {
            $search_zone = 'refrigerator'; // Для холодильника - специальная зона
            // Для type3 не требуется марка и модель машины
            $brand = 'refrigerator';
            $model = 'standard';
        }
        
        if (empty($brand) || empty($model) || empty($search_zone)) {
            if ($this->debug) {
                error_log("[Machine Calculator] Недостаточно параметров для поиска правила");
            }
            wp_send_json_error('Անբավարար պարամետրեր');
        }
        
        // Ищем цену по правилу
        $rule_price = $this->get_rule_price($search_zone, $brand, $model, $year_code > 0 ? $year_code : null, $machine_count);
        
        if ($rule_price !== null) {
            if ($this->debug) {
                error_log("[Machine Calculator] Найдено правило с ценой: {$rule_price}");
            }
            wp_send_json_success([
                'price' => $rule_price,
                'found_rule' => true,
                'message' => "Найдено правило: {$search_zone}, {$brand}, {$model}, год: {$year_code}, количество: {$machine_count}"
            ]);
        } else {
            // Возвращаем базовую цену если правило не найдено
            $base_price = 1;
            if ($calc_type === 'type1') {
                $base_price = ($zone_type === 'outside_armenia')
                    ? (float) get_option('machine_calc_price_type1_out_am', 1)
                    : (float) get_option('machine_calc_price_type1_in_am', 1);
            } elseif ($calc_type === 'type2') {
                $base_price = (float) get_option('machine_calc_price_type2_fuel', 1);
            } elseif ($calc_type === 'type3') {
                $base_price = (float) get_option('machine_calc_price_type3_ref', 1);
            }
            
            if ($this->debug) {
                error_log("[Machine Calculator] Правило не найдено, используется базовая цена: {$base_price}");
            }
            
            wp_send_json_success([
                'price' => $base_price,
                'found_rule' => false,
                'message' => "Правило не найдено, используется базовая цена: {$base_price}"
            ]);
        }
    }

    public function create_machine_order() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'machine_calc_nonce')) {
            wp_send_json_error('Անվտանգության սխալ');
        }

        $user_id = get_current_user_id();
        if (!$user_id) wp_send_json_error('Պետք է մուտք գործել');

        $calc_type = isset($_POST['calc_type']) ? sanitize_text_field($_POST['calc_type']) : 'type1';
        
        // Server-side validation
        $validation_errors = $this->validate_calculator_form($calc_type, $_POST);
        if (!empty($validation_errors)) {
            wp_send_json_error('Վավերացման սխալներ: ' . implode('; ', $validation_errors));
        }

        $count = 0;
        $meta  = [ 'calc_type' => $calc_type ];

        switch ($calc_type) {
            case 'type1': // Մեքենայի շարժի վերահսկում
                $count = max(1, intval($_POST['cars_count'] ?? 1));
                $meta['location'] = sanitize_text_field($_POST['location'] ?? 'in_am');
                $meta['car_make'] = sanitize_text_field($_POST['car_make'] ?? '');
                $meta['car_model'] = sanitize_text_field($_POST['car_model'] ?? '');
                $meta['car_year'] = sanitize_text_field($_POST['car_year'] ?? '');
                break;
            case 'type2': // Վառելիքի վերահսկում
                $count = max(1, intval($_POST['cars_count_2'] ?? 1));
                $meta['car_make'] = sanitize_text_field($_POST['car_make_2'] ?? '');
                $meta['car_model'] = sanitize_text_field($_POST['car_model_2'] ?? '');
                $meta['car_year'] = sanitize_text_field($_POST['car_year_2'] ?? '');
                $meta['tanks_count'] = max(0, intval($_POST['tanks_count'] ?? 0));
                break;
            case 'type3': // սառնարանի ջերմաստիճանի վերահսկում
                $refs_count = max(1, intval($_POST['refs_count'] ?? 1));
                $sensors_count = max(1, intval($_POST['sensors_count'] ?? 1));
                $meta['refs_count'] = $refs_count;
                $meta['sensors_count'] = $sensors_count;
                $count = $sensors_count; // Считаем по количеству датчиков
                break;
        }

        // Простая логика расчёта цены без сложной системы правил
        if ($calc_type === 'type1') {
            $loc = $meta['location'] ?? 'in_am';
            // Цена за машину в зависимости от зоны
            $price_unit = ($loc === 'out_am')
                ? (float) get_option('machine_calc_price_type1_out_am', 4000)
                : (float) get_option('machine_calc_price_type1_in_am', 4000);
            
        } elseif ($calc_type === 'type2') {
            // Цена за бак
            $tank_price = (float) get_option('machine_calc_price_type2_tank', 1000);
            $tank_count = max(0, intval($meta['tanks_count'] ?? 0));
            $price_total = $tank_count * $tank_price;
            
        } elseif ($calc_type === 'type3') {
            // Цена за датчик
            $price_unit = (float) get_option('machine_calc_price_type3_ref', 500);
        }

        // Для type1 и type3 считаем как count * price_unit
        if ($calc_type !== 'type2') {
            $price_total = max(0, $count * $price_unit);
        }

        // Create product name based on calc_type
        $product_name = sprintf('Подписка на %d ед. - Пользователь #%d', $count, $user_id);
        if ($calc_type === 'type1') {
            $product_name = sprintf('Մեքենայի շարժի վերահսկում - %d машин (%s) - Пользователь #%d', 
                $count, ($meta['location'] === 'out_am' ? 'ՀՀ-ից դուրս' : 'ՀՀ-ում'), $user_id);
        } elseif ($calc_type === 'type2') {
            $product_name = sprintf('Վառելիքի վերահսկում - %d баков - Пользователь #%d', 
                $count, $user_id);
        } elseif ($calc_type === 'type3') {
            $refs_count = $meta['refs_count'] ?? 1;
            $sensors_count = $meta['sensors_count'] ?? 1;
            $product_name = sprintf('սառնարանի ջերմաստիճանի վերահսկում - %d սառնարան, %d սենսորներ - Пользователь #%d', 
                $refs_count, $sensors_count, $user_id);
        }

        $product_id = $this->create_subscription_product($count, $user_id, $meta, $price_total, $product_name);

        // Сохраняем параметры калькулятора для отображения в checkout
        $this->save_calculator_params_for_checkout($calc_type, $meta, $count, $price_total);

        // Не трогаем корзину в AJAX: вернем ссылку на checkout с параметром add-to-cart
        $redirect = add_query_arg([
            'add-to-cart' => $product_id,
        ], wc_get_checkout_url());

        wp_send_json_success(['redirect_url' => $redirect]);
    }

    public function maybe_redirect_to_checkout($url) { return $url; }

    /**
     * Server-side validation for calculator form
     * @param string $calc_type
     * @param array $post_data
     * @return array Array of validation errors
     */
    private function validate_calculator_form($calc_type, $post_data) {
        $errors = [];

        switch ($calc_type) {
            case 'type1':
                // Vehicle Movement Monitoring validation
                $car_make = sanitize_text_field($post_data['car_make'] ?? '');
                $car_model = sanitize_text_field($post_data['car_model'] ?? '');
                $car_year = sanitize_text_field($post_data['car_year'] ?? '');
                $cars_count = intval($post_data['cars_count'] ?? 0);

                if (empty($car_make)) {
                    $errors[] = 'Անհրաժեշտ է ընտրել ավտոմեքենայի մակնիշը';
                }
                if (empty($car_model)) {
                    $errors[] = 'Անհրաժեշտ է ընտրել ավտոմեքենայի մոդելը';
                }
                if (empty($car_year)) {
                    $errors[] = 'Անհրաժեշտ է նշել ավտոմեքենայի արտադրության տարին';
                } else {
                    $year_num = intval($car_year);
                    if ($year_num < 1900 || $year_num > 2027) {
                        $errors[] = 'Արտադրության տարին պետք է լինի 1900-2027 թվականների միջև';
                    }
                }
                if ($cars_count < 1) {
                    $errors[] = 'Ավտոմեքենաների քանակը պետք է լինի առնվազն 1';
                }
                break;

            case 'type2':
                // Fuel Monitoring validation
                $car_make_2 = sanitize_text_field($post_data['car_make_2'] ?? '');
                $car_model_2 = sanitize_text_field($post_data['car_model_2'] ?? '');
                $car_year_2 = sanitize_text_field($post_data['car_year_2'] ?? '');
                $cars_count_2 = intval($post_data['cars_count_2'] ?? 0);
                $tanks_count = $post_data['tanks_count'] ?? '';

                if (empty($car_make_2)) {
                    $errors[] = 'Անհրաժեշտ է ընտրել ավտոմեքենայի մակնիշը';
                }
                if (empty($car_model_2)) {
                    $errors[] = 'Անհրաժեշտ է ընտրել ավտոմեքենայի մոդելը';
                }
                if (empty($car_year_2)) {
                    $errors[] = 'Անհրաժեշտ է նշել ավտոմեքենայի արտադրության տարին';
                } else {
                    $year_num = intval($car_year_2);
                    if ($year_num < 1900 || $year_num > 2027) {
                        $errors[] = 'Արտադրության տարին պետք է լինի 1900-2027 թվականների միջև';
                    }
                }
                if ($cars_count_2 < 1) {
                    $errors[] = 'Ավտոմեքենաների քանակը պետք է լինի առնվազն 1';
                }
                if ($tanks_count === '' || !is_numeric($tanks_count) || intval($tanks_count) < 0) {
                    $errors[] = 'Անհրաժեշտ է նշել վառելիքի բաքերի քանակը (0 կամ ավելի)';
                }
                break;

            case 'type3':
                // Refrigerator Temperature Monitoring validation
                $refs_count = intval($post_data['refs_count'] ?? 0);
                $sensors_count = intval($post_data['sensors_count'] ?? 0);

                if ($refs_count < 1) {
                    $errors[] = 'Սառնարանների քանակը պետք է լինի առնվազն 1';
                }
                if ($sensors_count < 1) {
                    $errors[] = 'Սենսորների քանակը պետք է լինի առնվազն 1';
                }
                break;

            default:
                $errors[] = 'Անհայտ հաշվիչի տեսակ';
                break;
        }

        return $errors;
    }

    private function create_subscription_product($count, $user_id, $extra_meta = [], $price_override = null, $custom_name = null) {
        $price_per_machine = get_option('machine_calc_price_per_machine', 1);
        $price = is_null($price_override) ? $count * $price_per_machine : (float) $price_override;
        $name = $custom_name ?: sprintf('Подписка на %d ед. - Пользователь #%d', $count, $user_id);

        if (class_exists('WC_Product_Subscription')) {
            $product = new WC_Product_Subscription();
            $product->set_name($name);
            $product->set_regular_price($price);
            $product->set_virtual(true);
            $product->update_meta_data('_subscription_price', $price);
            $product->update_meta_data('_subscription_period', 'month');
            $product->update_meta_data('_subscription_period_interval', 1);
            $product->update_meta_data('_subscription_length', 0);
        } else {
            $product = new WC_Product_Simple();
            $product->set_name($name);
            $product->set_regular_price($price);
            $product->set_virtual(true);
        }

        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->add_meta_data('_machine_calc_user_id', $user_id);
        $product->add_meta_data('_machine_calc_machine_count', $count);
        if (!empty($extra_meta) && is_array($extra_meta)) {
            foreach ($extra_meta as $k => $v) {
                $product->add_meta_data('_mc_' . sanitize_key($k), $v);
            }
        }
        $product_id = $product->save();

        // Дополнительная очистка просроченных товаров перед созданием нового
        $this->temp_products_cleaner->cron_worker();

        // Помечаем товар как временный и планируем его очистку
        $this->temp_products_cleaner->mark_temp_product($product_id, 0); // order_id = 0, так как заказ еще не создан
        $this->temp_products_cleaner->schedule_cleanup($product_id, $this->temp_products_cleaner->get_option_ttl());

        return $product_id;
    }

    public function add_admin_menu() {
        add_menu_page(
            'GPS-calculator',
            'GPS-calculator',
            'manage_options',
            'gps-calculator',
            [$this, 'admin_page'],
            'dashicons-calculator',
            30
        );
    }

    public function admin_assets($hook) {
        if ($hook !== 'toplevel_page_gps-calculator') return;
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('wp-util');
        wp_enqueue_editor();
        
        // Основные скрипты GPS-calculator
        wp_enqueue_script(
            'machine-calculator-admin',
            MACHINE_CALC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            MACHINE_CALC_VERSION,
            true
        );
        
        // Скрипты для каталога ТС
        wp_enqueue_script(
            'sgc-admin',
            MACHINE_CALC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            MACHINE_CALC_VERSION,
            true
        );
        
        // Скрипты для комментариев калькулятора
        wp_enqueue_script(
            'machine-calc-notice-admin',
            MACHINE_CALC_PLUGIN_URL . 'assets/js/notice-admin.js',
            ['jquery', 'wp-util'],
            MACHINE_CALC_VERSION,
            true
        );
        
        wp_localize_script('machine-calculator-admin', 'machineCalculatorAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('machine_calculator_admin'),
            'sgc_nonce' => wp_create_nonce('sgc_nonce')
        ]);
        
        wp_localize_script('machine-calc-notice-admin', 'mcNoticeAdmin', [
            'nonce' => wp_create_nonce('mc_notice_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
        ]);
        
        // Основные стили GPS-calculator
        wp_enqueue_style(
            'machine-calculator-admin',
            MACHINE_CALC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            MACHINE_CALC_VERSION
        );
        
        // Стили для каталога ТС
        wp_enqueue_style(
            'sgc-admin',
            MACHINE_CALC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            MACHINE_CALC_VERSION
        );
        
        // Стили для комментариев калькулятора
        wp_enqueue_style(
            'machine-calc-notice-admin',
            MACHINE_CALC_PLUGIN_URL . 'assets/css/notice-admin.css',
            [],
            MACHINE_CALC_VERSION
        );
    }

    public function init_admin() {
        $this->create_rules_table();
    }

    private function create_rules_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'machine_calculator_rules';
        
        // Проверяем, существует ли таблица
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                zone_type varchar(50) NOT NULL,
                brand varchar(50) NOT NULL,
                model varchar(50) NOT NULL,
                year_code int(4) NOT NULL,
                machine_count int(11) NOT NULL,
                filter_price decimal(10,2) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY zone_brand_model (zone_type, brand, model),
                KEY year_code (year_code),
                KEY machine_count (machine_count)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            if ($this->debug) {
                error_log('[Machine Calculator] Таблица ' . $table_name . ' создана');
            }
        }
    }

    public function admin_page() {
        $rules = $this->get_rules();
        ?>
        <div class="wrap">
            <h1>Հաշվիչի Կանոններ</h1>
            
            <div class="machine-calculator-admin">
                <div class="nav-tab-wrapper">
                    <a href="#movement-rules" class="nav-tab nav-tab-active">Մեքենայի շարժի վերահսկում</a>
                    <a href="#fuel-rules" class="nav-tab">Վառելիքի վերահսկում</a>
                    <a href="#refrigerator-rules" class="nav-tab">Սառնարանի ջերմաստիճանի վերահսկում</a>
                    <a href="#temp-products" class="nav-tab">Ժամանակավոր ապրանքներ</a>
                    <a href="#vehicles-catalog" class="nav-tab">Каталог ТС</a>
                    <a href="#calculator-notices" class="nav-tab">Комментарии калькулятора</a>
                </div>
                
                <!-- TAB 1: Մեքենայի շարժի վերահսկում -->
                <div id="movement-rules" class="tab-content">
                    <h2>Մեքենայի շարժի վերահսկում - Правила</h2>
                    
                    <!-- Форма добавления нового правила -->
                    <div class="add-rule-form">
                        <h3>+ Добавить Правила для Մեքենայի շարժի վերահսկում</h3>
                        <form id="add-movement-rule-form" class="rule-form" data-calc-type="type1">
                            <table class="form-table">
                                <tr>
                                    <th><label for="movement_zone_type">Тип зоны</label></th>
                                    <td>
                                        <select id="movement_zone_type" name="zone_type" required>
                                            <option value="">Выберите тип зоны</option>
                                            <option value="in_armenia">ՀՀ-ում</option>
                                            <option value="outside_armenia">ՀՀ-ից դուրս</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="movement_filter_price">Цена фильтрации за машину</label></th>
                                    <td>
                                        <input type="number" id="movement_filter_price" name="filter_price" step="0.01" min="0" required>
                                        <p class="description">Цена за одну машину. Итоговая цена будет умножена на количество машин (1 машина = 4 000, 2 машины = 8 000 и т.д.)</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary">Сохранить правило</button>
                            </p>
                        </form>
                    </div>
                    
                    <!-- Список существующих правил -->
                    <div class="rules-list">
                        <h3>Текущие настройки цен для Մեքենայի շարժի վերահսկում</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Тип зоны</th>
                                    <th>Цена за машину</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>ՀՀ-ում (В Армении)</td>
                                    <td><?php echo esc_html(get_option('machine_calc_price_type1_in_am', 4000)); ?></td>
                                </tr>
                                <tr>
                                    <td>ՀՀ-ից դուրս (Вне Армении)</td>
                                    <td><?php echo esc_html(get_option('machine_calc_price_type1_out_am', 4000)); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- TAB 2: Վառելիքի վերահսկում -->
                <div id="fuel-rules" class="tab-content" style="display: none;">
                    <h2>Վառելիքի վերահսկում - Правила</h2>
                    
                    <!-- Форма добавления нового правила -->
                    <div class="add-rule-form">
                        <h3>+ Добавить Правила для Վառելիքի վերահսկում</h3>
                        <form id="add-fuel-rule-form" class="rule-form" data-calc-type="type2">
                            <table class="form-table">
                                <tr>
                                    <th><label for="fuel_tank_price">Цена за один бак</label></th>
                                    <td>
                                        <input type="number" id="fuel_tank_price" name="tank_price" step="0.01" min="0" required>
                                        <p class="description">Цена за один топливный бак. Итоговая цена будет умножена на количество баков, указанное клиентом.</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary">Сохранить правило</button>
                            </p>
                        </form>
                    </div>
                    
                    <!-- Список существующих правил -->
                    <div class="rules-list">
                        <h3>Текущие настройки цен для Վառելիքի վերահսկում</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Описание</th>
                                    <th>Цена за единицу</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Цена за один топливный бак</td>
                                    <td><?php echo esc_html(get_option('machine_calc_price_type2_tank', 1000)); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- TAB 3: Սառնարանի ջերմաստիճանի վերահսկում -->
                <div id="refrigerator-rules" class="tab-content" style="display: none;">
                    <h2>Սառնարանի ջերմաստիճանի վերահսկում - Правила</h2>
                    
                    <!-- Форма добавления нового правила -->
                    <div class="add-rule-form">
                        <h3>+ Добавить Правила для Սառնարանի ջերմաստիճանի վերահսկում</h3>
                        <form id="add-refrigerator-rule-form" class="rule-form" data-calc-type="type3">
                            <table class="form-table">
                                <tr>
                                    <th><label for="refrigerator_sensor_price">Цена за один датчик</label></th>
                                    <td>
                                        <input type="number" id="refrigerator_sensor_price" name="sensor_price" step="0.01" min="0" required>
                                        <p class="description">Цена за один датчик температуры. Итоговая цена будет умножена на количество датчиков, выбранное клиентом.</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary">Сохранить правило</button>
                            </p>
                        </form>
                    </div>
                    
                    <!-- Список существующих правил -->
                    <div class="rules-list">
                        <h3>Текущие настройки цен для Սառնարանի ջերմաստիճանի վերահսկում</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Описание</th>
                                    <th>Цена за единицу</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Цена за один датчик температуры</td>
                                    <td><?php echo esc_html(get_option('machine_calc_price_type3_ref', 500)); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                
                <!-- TAB 4: Временные товары -->
                <div id="temp-products" class="tab-content" style="display: none;">
                    <?php $this->temp_products_admin->render_admin_tab(); ?>
                </div>
                
                
                <!-- TAB 5: Каталог ТС -->
                <div id="vehicles-catalog" class="tab-content" style="display: none;">
                    <?php $this->vehicles_admin->admin_page(); ?>
                </div>
                
                <!-- TAB 6: Комментарии калькулятора -->
                <div id="calculator-notices" class="tab-content" style="display: none;">
                    <?php $this->notice_admin->admin_page(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function get_zone_display_name($zone_type) {
        $zones = [
            'everywhere' => 'Ամենուր',
            'in_armenia' => 'ՀՀ-ում',
            'outside_armenia' => 'ՀՀ-ից դուրս',
            'refrigerator' => 'Սառնարանի վերահսկում'
        ];
        return isset($zones[$zone_type]) ? $zones[$zone_type] : $zone_type;
    }

    public function save_calculator_rule() {
        check_ajax_referer('machine_calculator_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        
        $calc_type = sanitize_text_field($_POST['calc_type']);
        
        // Вместо сохранения в базу правил, обновляем настройки WordPress
        if ($calc_type === 'type1') {
            $zone_type = sanitize_text_field($_POST['zone_type']);
            $filter_price = floatval($_POST['filter_price']);
            
            if ($zone_type === 'in_armenia') {
                update_option('machine_calc_price_type1_in_am', $filter_price);
            } else {
                update_option('machine_calc_price_type1_out_am', $filter_price);
            }
            
        } elseif ($calc_type === 'type2') {
            $tank_price = floatval($_POST['tank_price']);
            update_option('machine_calc_price_type2_tank', $tank_price);
            
        } elseif ($calc_type === 'type3') {
            $sensor_price = floatval($_POST['sensor_price']);
            update_option('machine_calc_price_type3_ref', $sensor_price);
        }
        
        wp_send_json_success(['message' => 'Цена успешно сохранена']);
    }
    
    public function delete_calculator_rule() {
        check_ajax_referer('machine_calculator_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }
        
        $rule_id = intval($_POST['rule_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'machine_calculator_rules';
        
        $result = $wpdb->delete(
            $table_name,
            ['id' => $rule_id],
            ['%d']
        );
        
        if ($result !== false) {
            wp_send_json_success(['message' => 'Правило успешно удалено']);
        } else {
            wp_send_json_error(['message' => 'Ошибка при удалении правила']);
        }
    }
    
    /**
     * Сохраняет параметры калькулятора для отображения в checkout
     *
     * @param string $calc_type Тип калькулятора
     * @param array $meta Мета данные
     * @param int $count Количество
     * @param float $price_total Общая цена
     */
    private function save_calculator_params_for_checkout($calc_type, $meta, $count, $price_total) {
        if (!WC()->session) {
            return;
        }
        
        $comment_text = $this->format_calculator_comment($calc_type, $meta, $count, $price_total);
        
        // Сохраняем в сессию WooCommerce
        WC()->session->set('machine_calc_checkout_comment', $comment_text);
        
        $this->log("Сохранены параметры калькулятора для checkout: " . $comment_text);
    }
    
    /**
     * Форматирует комментарий с параметрами калькулятора
     *
     * @param string $calc_type Тип калькулятора
     * @param array $meta Мета данные
     * @param int $count Количество
     * @param float $price_total Общая цена
     * @return string Отформатированный комментарий
     */
    private function format_calculator_comment($calc_type, $meta, $count, $price_total) {
        $comment_lines = [];
        $comment_lines[] = "=== ПАРАМЕТРЫ КАЛЬКУЛЯТОРА ===";
        
        switch ($calc_type) {
            case 'type1': // Մեքենայի շարժի վերահսկում
                $comment_lines[] = "Услуга: Мониторинг движения машин (Մեքենայի շարժի վերահսկում)";
                $comment_lines[] = "Количество машин: " . $count;
                
                $location = $meta['location'] ?? 'in_am';
                $location_text = ($location === 'out_am') ? 'Вне Армении (ՀՀ-ից դուրս)' : 'В Армении (ՀՀ-ում)';
                $comment_lines[] = "Зона обслуживания: " . $location_text;
                
                if (!empty($meta['car_make'])) {
                    $comment_lines[] = "Марка машины: " . $meta['car_make'];
                }
                if (!empty($meta['car_model'])) {
                    $comment_lines[] = "Модель машины: " . $meta['car_model'];
                }
                if (!empty($meta['car_year'])) {
                    $comment_lines[] = "Год машины: " . $meta['car_year'];
                }
                break;
                
            case 'type2': // Վառելիքի վերահսկում
                $comment_lines[] = "Услуга: Мониторинг топлива (Վառելիքի վերահսկում)";
                $comment_lines[] = "Количество машин: " . $count;
                
                $tanks_count = $meta['tanks_count'] ?? 0;
                $comment_lines[] = "Количество баков: " . $tanks_count;
                
                if (!empty($meta['car_make'])) {
                    $comment_lines[] = "Марка машины: " . $meta['car_make'];
                }
                if (!empty($meta['car_model'])) {
                    $comment_lines[] = "Модель машины: " . $meta['car_model'];
                }
                if (!empty($meta['car_year'])) {
                    $comment_lines[] = "Год машины: " . $meta['car_year'];
                }
                break;
                
            case 'type3': // սառնարանի ջերմաստիճանի վերահսկում
                $comment_lines[] = "Услуга: Мониторинг температуры холодильников (սառնարանի ջերմաստիճանի վերահսկում)";
                
                $refs_count = $meta['refs_count'] ?? 1;
                $sensors_count = $meta['sensors_count'] ?? 1;
                $comment_lines[] = "Количество холодильников: " . $refs_count;
                $comment_lines[] = "Количество датчиков: " . $sensors_count;
                break;
        }
        
        $comment_lines[] = "Общая стоимость: " . number_format($price_total, 2) . " ֏";
        $comment_lines[] = "Дата расчета: " . date('d.m.Y H:i');
        $comment_lines[] = "==============================";
        
        return implode("\n", $comment_lines);
    }
    
    /**
     * AJAX обработчик для получения комментария калькулятора
     */
    public function get_calculator_checkout_comment() {
        check_ajax_referer('machine_calc_checkout_nonce', 'nonce');
        
        if (!WC()->session) {
            wp_send_json_error('Сессия недоступна');
        }
        
        $comment = WC()->session->get('machine_calc_checkout_comment');
        
        if ($comment) {
            // Очищаем сессию после получения
            WC()->session->__unset('machine_calc_checkout_comment');
            wp_send_json_success(['comment' => $comment]);
        } else {
            wp_send_json_error('Комментарий не найден');
        }
    }
    
    private function get_rules() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'machine_calculator_rules';
        
        // Проверяем, существует ли таблица
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            $this->create_rules_table();
        }
        
        $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC", ARRAY_A);
        
        return is_array($results) ? $results : [];
    }
    
    private function get_rules_by_type($calc_type) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'machine_calculator_rules';
        
        // Проверяем, существует ли таблица
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            $this->create_rules_table();
        }
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE calc_type = %s ORDER BY created_at DESC",
            $calc_type
        ));
        
        return is_array($results) ? $results : [];
    }

    public function register_settings() {
        // Старые настройки убраны, теперь используем базу данных для правил
    }
    
    /**
     * Get vehicles source instance
     *
     * @return MC_Vehicles_Source
     */
    public function get_vehicles_source() {
        return $this->vehicles_source;
    }
    
    
    /**
     * Refresh vehicles data from API (for admin use)
     *
     * @return array
     */
    public function refresh_vehicles_data() {
        if ($this->vehicles_source) {
            return $this->vehicles_source->refresh_data();
        }
        return [];
    }
    
    /**
     * AJAX handler for adding vehicle make
     */
    public function ajax_add_vehicle_make() {
        // Debug: Log the request
        error_log('AJAX add_vehicle_make called with POST data: ' . print_r($_POST, true));
        
        check_ajax_referer('sgc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $name = sanitize_text_field($_POST['name'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'mardatar');
        
        error_log("Adding make: name='$name', type='$type'");
        
        $result = $this->vehicles_catalog->add_make($name, $type);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => 'Марка "' . $name . '" добавлена',
                'make_id' => $result['id'],
                'make_name' => $name
            ]);
        } else {
            $error_message = '';
            switch ($result['error']) {
                case 'empty':
                    $error_message = 'Введите название марки';
                    break;
                case 'short':
                    $error_message = 'Название марки должно содержать минимум 2 символа';
                    break;
                case 'invalid':
                    $error_message = 'Название может содержать только буквы, цифры, пробелы и дефисы';
                    break;
                case 'duplicate':
                    $error_message = 'Марка "' . $name . '" уже существует';
                    break;
                case 'database':
                    $db_error = isset($result['db_error']) ? $result['db_error'] : 'Неизвестная ошибка';
                    $error_message = 'Ошибка базы данных: ' . $db_error;
                    break;
                default:
                    $error_message = 'Неизвестная ошибка при добавлении марки';
            }
            
            wp_send_json_error(['message' => $error_message]);
        }
    }
    
    /**
     * AJAX handler for adding vehicle model
     */
    public function ajax_add_vehicle_model() {
        check_ajax_referer('sgc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $make_id = (int) ($_POST['make_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        
        $result = $this->vehicles_catalog->add_model($make_id, $name);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => 'Модель "' . $name . '" добавлена',
                'model_id' => $result['id'],
                'model_name' => $name
            ]);
        } else {
            $error_message = '';
            switch ($result['error']) {
                case 'invalid_make':
                    $error_message = 'Выберите марку';
                    break;
                case 'empty':
                    $error_message = 'Введите название модели';
                    break;
                case 'short':
                    $error_message = 'Название модели не может быть пустым';
                    break;
                case 'invalid':
                    $error_message = 'Название может содержать только буквы, цифры, пробелы и дефисы';
                    break;
                case 'duplicate':
                    $error_message = 'Модель "' . $name . '" уже существует';
                    break;
                case 'database':
                    $db_error = isset($result['db_error']) ? $result['db_error'] : 'Неизвестная ошибка';
                    $error_message = 'Ошибка базы данных: ' . $db_error;
                    break;
                default:
                    $error_message = 'Неизвестная ошибка при добавлении модели';
            }
            
            wp_send_json_error(['message' => $error_message]);
        }
    }
    
    /**
     * AJAX handler for deleting vehicle make
     */
    public function ajax_delete_vehicle_make() {
        check_ajax_referer('sgc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $make_id = (int) ($_POST['make_id'] ?? 0);
        
        if ($make_id <= 0) {
            wp_send_json_error(['message' => 'Неверный ID марки']);
        }
        
        $result = $this->vehicles_catalog->delete_make($make_id);
        
        if ($result) {
            wp_send_json_success(['message' => 'Марка удалена']);
        } else {
            wp_send_json_error(['message' => 'Ошибка при удалении марки']);
        }
    }
    
    /**
     * AJAX handler for deleting vehicle model
     */
    public function ajax_delete_vehicle_model() {
        check_ajax_referer('sgc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $model_id = (int) ($_POST['model_id'] ?? 0);
        
        if ($model_id <= 0) {
            wp_send_json_error(['message' => 'Неверный ID модели']);
        }
        
        $result = $this->vehicles_catalog->delete_model($model_id);
        
        if ($result) {
            wp_send_json_success(['message' => 'Модель удалена']);
        } else {
            wp_send_json_error(['message' => 'Ошибка при удалении модели']);
        }
    }
    
    /**
     * AJAX handler for getting vehicle makes
     */
    public function ajax_get_vehicle_makes() {
        check_ajax_referer('sgc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $type = sanitize_text_field($_POST['type'] ?? 'mardatar');
        $search = sanitize_text_field($_POST['search'] ?? '');
        
        $makes = $this->vehicles_catalog->get_makes($type, $search);
        
        wp_send_json_success(['makes' => $makes]);
    }
    
    /**
     * AJAX handler for getting vehicle models
     */
    public function ajax_get_vehicle_models() {
        check_ajax_referer('sgc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $make_id = (int) ($_POST['make_id'] ?? 0);
        $search = sanitize_text_field($_POST['search'] ?? '');
        
        if ($make_id <= 0) {
            wp_send_json_error(['message' => 'Неверный ID марки']);
        }
        
        $models = $this->vehicles_catalog->get_models($make_id, $search);
        
        wp_send_json_success(['models' => $models]);
    }

}

new MachineCalculator();
