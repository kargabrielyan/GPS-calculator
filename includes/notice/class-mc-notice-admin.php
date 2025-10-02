<?php
/**
 * Notice Admin Class
 * 
 * Handles admin interface for calculator notices/banners.
 * Adds settings page and form processing.
 * 
 * @package Machine Calculator
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MC_Notice_Admin {
    
    /**
     * Option name for storing notice settings
     */
    const OPTION_NAME = 'machine_calc_notice';
    
    /**
     * Constructor
     */
    public function __construct() {
        // add_action('admin_menu', [$this, 'add_admin_menu'], 20); // Отключено - меню интегрировано в GPS-calculator
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_save_notice_settings', [$this, 'save_notice_settings']);
    }
    
    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Комментарии калькулятора', 'machine-calculator'),
            __('Комментарии калькулятора', 'machine-calculator'),
            'manage_options',
            'machine-calc-notices',
            [$this, 'admin_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'machine_calc_notice_group',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => $this->get_default_settings()
            ]
        );
    }
    
    /**
     * Get default settings
     */
    private function get_default_settings() {
        return [
            'enabled' => false,
            'position' => 'top',
            'variant' => 'accent',
            'global' => '',
            'type1' => '',
            'type2' => '',
            'type3' => ''
        ];
    }
    
    /**
     * Get current settings
     */
    public function get_settings() {
        $settings = get_option(self::OPTION_NAME, $this->get_default_settings());
        return wp_parse_args($settings, $this->get_default_settings());
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        
        // Boolean fields
        $sanitized['enabled'] = !empty($input['enabled']);
        
        // Whitelist for position
        $allowed_positions = ['top', 'bottom'];
        $sanitized['position'] = in_array($input['position'] ?? '', $allowed_positions) 
            ? $input['position'] 
            : 'top';
        
        // Whitelist for variant
        $allowed_variants = ['info', 'warning', 'success', 'accent'];
        $sanitized['variant'] = in_array($input['variant'] ?? '', $allowed_variants) 
            ? $input['variant'] 
            : 'accent';
        
        // Content fields with allowed HTML
        $allowed_html = [
            'a' => [
                'href' => [],
                'title' => [],
                'target' => []
            ],
            'p' => [],
            'br' => [],
            'strong' => [],
            'em' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'span' => [
                'class' => []
            ],
            'div' => [
                'class' => []
            ]
        ];
        
        $sanitized['global'] = wp_kses($input['global'] ?? '', $allowed_html);
        $sanitized['type1'] = wp_kses($input['type1'] ?? '', $allowed_html);
        $sanitized['type2'] = wp_kses($input['type2'] ?? '', $allowed_html);
        $sanitized['type3'] = wp_kses($input['type3'] ?? '', $allowed_html);
        
        return $sanitized;
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Скрипты теперь загружаются в GPS-calculator
        // if ('woocommerce_page_machine-calc-notices' !== $hook) {
        //     return;
        // }
        
        // wp_enqueue_editor();
        // wp_enqueue_style(
        //     'machine-calc-notice-admin',
        //     MACHINE_CALC_PLUGIN_URL . 'assets/css/notice-admin.css',
        //     [],
        //     MACHINE_CALC_VERSION
        // );
        
        // wp_enqueue_script(
        //     'machine-calc-notice-admin',
        //     MACHINE_CALC_PLUGIN_URL . 'assets/js/notice-admin.js',
        //     ['jquery', 'wp-util'],
        //     MACHINE_CALC_VERSION,
        //     true
        // );
        
        // wp_localize_script('machine-calc-notice-admin', 'mcNoticeAdmin', [
        //     'nonce' => wp_create_nonce('mc_notice_nonce'),
        //     'ajax_url' => admin_url('admin-ajax.php')
        // ]);
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        if (isset($_POST['submit']) && check_admin_referer('mc_notice_settings', 'mc_notice_nonce')) {
            $settings = $this->sanitize_settings($_POST);
            update_option(self::OPTION_NAME, $settings);
            
            echo '<div class="notice notice-success"><p>' . 
                 __('Настройки сохранены.', 'machine-calculator') . 
                 '</p></div>';
        }
        
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php _e('Комментарии калькулятора', 'machine-calculator'); ?></h1>
            
            <p><?php _e('Настройте отображение комментариев/баннеров в калькуляторе. Комментарии помогают привлечь внимание пользователей к важной информации.', 'machine-calculator'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('mc_notice_settings', 'mc_notice_nonce'); ?>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="enabled">
                                    <?php _e('Включить комментарии', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="enabled" name="enabled" value="1" <?php checked($settings['enabled']); ?> />
                                <p class="description">
                                    <?php _e('Включить отображение комментариев во всех табах калькулятора.', 'machine-calculator'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="position">
                                    <?php _e('Позиция', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="position" name="position">
                                    <option value="top" <?php selected($settings['position'], 'top'); ?>>
                                        <?php _e('Сверху (над формой)', 'machine-calculator'); ?>
                                    </option>
                                    <option value="bottom" <?php selected($settings['position'], 'bottom'); ?>>
                                        <?php _e('Снизу (под итогом)', 'machine-calculator'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php _e('Где отображать комментарий в каждом табе калькулятора.', 'machine-calculator'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="variant">
                                    <?php _e('Вариант оформления', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="variant" name="variant">
                                    <option value="accent" <?php selected($settings['variant'], 'accent'); ?>>
                                        <?php _e('Акцент (контрастный)', 'machine-calculator'); ?>
                                    </option>
                                    <option value="info" <?php selected($settings['variant'], 'info'); ?>>
                                        <?php _e('Информация (голубой)', 'machine-calculator'); ?>
                                    </option>
                                    <option value="warning" <?php selected($settings['variant'], 'warning'); ?>>
                                        <?php _e('Предупреждение (оранжевый)', 'machine-calculator'); ?>
                                    </option>
                                    <option value="success" <?php selected($settings['variant'], 'success'); ?>>
                                        <?php _e('Успех (зеленый)', 'machine-calculator'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php _e('Цветовая схема для привлечения внимания.', 'machine-calculator'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <h2><?php _e('Содержимое комментариев', 'machine-calculator'); ?></h2>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="global">
                                    <?php _e('Глобальный комментарий', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <?php
                                wp_editor($settings['global'], 'global', [
                                    'textarea_name' => 'global',
                                    'media_buttons' => false,
                                    'textarea_rows' => 5,
                                    'teeny' => true,
                                    'quicktags' => true
                                ]);
                                ?>
                                <p class="description">
                                    <?php _e('Этот текст будет отображаться во всех табах по умолчанию.', 'machine-calculator'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <h3><?php _e('Переопределения для отдельных табов', 'machine-calculator'); ?></h3>
                <p><?php _e('Если поле пустое, будет использован глобальный комментарий. Заполните только те табы, где нужен особый текст.', 'machine-calculator'); ?></p>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="type1">
                                    <?php _e('Таб 1: Մեքենայի շարժի վերահսկում', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <?php
                                wp_editor($settings['type1'], 'type1', [
                                    'textarea_name' => 'type1',
                                    'media_buttons' => false,
                                    'textarea_rows' => 4,
                                    'teeny' => true,
                                    'quicktags' => true
                                ]);
                                ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="type2">
                                    <?php _e('Таб 2: Վառելիքի վերահսկում', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <?php
                                wp_editor($settings['type2'], 'type2', [
                                    'textarea_name' => 'type2',
                                    'media_buttons' => false,
                                    'textarea_rows' => 4,
                                    'teeny' => true,
                                    'quicktags' => true
                                ]);
                                ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="type3">
                                    <?php _e('Таб 3: սառնարանի ջերմաստիճանի վերահսկում', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <?php
                                wp_editor($settings['type3'], 'type3', [
                                    'textarea_name' => 'type3',
                                    'media_buttons' => false,
                                    'textarea_rows' => 4,
                                    'teeny' => true,
                                    'quicktags' => true
                                ]);
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button(__('Сохранить настройки', 'machine-calculator')); ?>
            </form>
            
            <div class="mc-notice-preview" style="margin-top: 30px;">
                <h3><?php _e('Предварительный просмотр', 'machine-calculator'); ?></h3>
                <div id="mc-notice-preview-container">
                    <!-- Preview will be rendered here via JS -->
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for saving settings
     */
    public function save_notice_settings() {
        check_ajax_referer('mc_notice_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Недостаточно прав.', 'machine-calculator'));
        }
        
        $settings = $this->sanitize_settings($_POST);
        $result = update_option(self::OPTION_NAME, $settings);
        
        if ($result) {
            wp_send_json_success([
                'message' => __('Настройки сохранены.', 'machine-calculator')
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Ошибка при сохранении настроек.', 'machine-calculator')
            ]);
        }
    }
}
