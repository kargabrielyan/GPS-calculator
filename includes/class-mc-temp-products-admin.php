<?php
/**
 * Админ настройки для временных товаров
 */

if (!defined('ABSPATH')) exit;

class MC_Temp_Products_Admin {
    
    /**
     * Экземпляр cleaner
     *
     * @var MC_Temp_Products_Cleaner
     */
    private $cleaner;
    
    /**
     * Конструктор
     *
     * @param MC_Temp_Products_Cleaner $cleaner
     */
    public function __construct($cleaner) {
        $this->cleaner = $cleaner;
        $this->init_hooks();
    }
    
    /**
     * Инициализация хуков
     */
    private function init_hooks() {
        add_action('admin_init', [$this, 'register_settings']);
        
        // AJAX обработчики
        add_action('wp_ajax_mc_cleanup_expired_products', [$this, 'ajax_cleanup_expired_products']);
        add_action('wp_ajax_mc_get_temp_products_stats', [$this, 'ajax_get_temp_products_stats']);
    }
    
    /**
     * Регистрация настроек
     */
    public function register_settings() {
        $options_group = 'machine_calc_temp_products_options';
        
        register_setting($options_group, 'machine_calc_temp_products_enabled');
        register_setting($options_group, 'machine_calc_temp_products_ttl_minutes');
        register_setting($options_group, 'machine_calc_temp_products_delete_mode');
        
        add_settings_section(
            'machine_calc_temp_products_section',
            __('Настройки временных товаров', 'machine-calculator'),
            [$this, 'settings_section_callback'],
            $options_group
        );
        
        add_settings_field(
            'enabled',
            __('Включить автоудаление', 'machine-calculator'),
            [$this, 'enabled_field_callback'],
            $options_group,
            'machine_calc_temp_products_section'
        );
        
        add_settings_field(
            'ttl_minutes',
            __('TTL (минуты)', 'machine-calculator'),
            [$this, 'ttl_field_callback'],
            $options_group,
            'machine_calc_temp_products_section'
        );
        
        add_settings_field(
            'delete_mode',
            __('Режим удаления', 'machine-calculator'),
            [$this, 'delete_mode_field_callback'],
            $options_group,
            'machine_calc_temp_products_section'
        );
    }
    
    /**
     * Добавляет таб в админ-панель (убрано - интегрировано в основную админку)
     *
     * @param array $tabs
     * @return array
     */
    public function add_admin_tab($tabs) {
        $tabs['temp_products'] = __('Временные товары', 'machine-calculator');
        return $tabs;
    }
    
    /**
     * Рендерит содержимое таба
     */
    public function render_admin_tab() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('У вас нет прав для доступа к этой странице.', 'machine-calculator'));
        }
        
        // Обработка сохранения настроек
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['_wpnonce'], 'machine_calc_temp_products_options-options')) {
            update_option('machine_calc_temp_products_enabled', isset($_POST['machine_calc_temp_products_enabled']));
            update_option('machine_calc_temp_products_ttl_minutes', max(1, intval($_POST['machine_calc_temp_products_ttl_minutes'])));
            update_option('machine_calc_temp_products_delete_mode', sanitize_text_field($_POST['machine_calc_temp_products_delete_mode']));
            
            echo '<div class="notice notice-success"><p>' . __('Настройки сохранены.', 'machine-calculator') . '</p></div>';
        }
        
        $stats = $this->cleaner->get_temp_products_stats();
        ?>
        <!-- Не нужен wrap div, так как мы уже внутри админки -->
        <h2><?php _e('Управление временными товарами', 'machine-calculator'); ?></h2>
            
            <!-- Статистика -->
            <div class="mc-temp-products-stats">
                <h3><?php _e('Статистика', 'machine-calculator'); ?></h3>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong><?php _e('Всего временных товаров:', 'machine-calculator'); ?></strong></td>
                            <td id="mc-total-temp-products"><?php echo esc_html($stats['total_temp_products']); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Просроченных товаров:', 'machine-calculator'); ?></strong></td>
                            <td id="mc-expired-temp-products"><?php echo esc_html($stats['expired_temp_products']); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Статус функции:', 'machine-calculator'); ?></strong></td>
                            <td>
                                <span class="mc-status-badge mc-status-<?php echo $stats['enabled'] ? 'enabled' : 'disabled'; ?>">
                                    <?php echo $stats['enabled'] ? __('Включено', 'machine-calculator') : __('Отключено', 'machine-calculator'); ?>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <p>
                    <button type="button" class="button button-secondary" id="mc-refresh-stats">
                        <?php _e('Обновить статистику', 'machine-calculator'); ?>
                    </button>
                    
                    <?php if ($stats['expired_temp_products'] > 0): ?>
                        <button type="button" class="button button-primary" id="mc-cleanup-expired">
                            <?php _e('Очистить просроченные', 'machine-calculator'); ?>
                        </button>
                    <?php endif; ?>
                </p>
            </div>
            
            <!-- Настройки -->
            <form method="post" action="">
                <?php wp_nonce_field('machine_calc_temp_products_options-options'); ?>
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="machine_calc_temp_products_enabled">
                                    <?php _e('Включить автоудаление временных товаров', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" 
                                       id="machine_calc_temp_products_enabled" 
                                       name="machine_calc_temp_products_enabled" 
                                       value="1" 
                                       <?php checked($stats['enabled']); ?>>
                                <p class="description">
                                    <?php _e('Автоматически удалять временные товары после оплаты или по истечении TTL.', 'machine-calculator'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="machine_calc_temp_products_ttl_minutes">
                                    <?php _e('TTL для брошенных чекаутов (минуты)', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="machine_calc_temp_products_ttl_minutes" 
                                       name="machine_calc_temp_products_ttl_minutes" 
                                       value="<?php echo esc_attr($stats['ttl_minutes']); ?>" 
                                       min="1" 
                                       max="10080" 
                                       class="small-text">
                                <p class="description">
                                    <?php _e('Время в минутах, через которое неоплаченные товары будут удалены (1-10080 минут).', 'machine-calculator'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="machine_calc_temp_products_delete_mode">
                                    <?php _e('Режим удаления', 'machine-calculator'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="machine_calc_temp_products_delete_mode" name="machine_calc_temp_products_delete_mode">
                                    <option value="trash" <?php selected($stats['delete_mode'], 'trash'); ?>>
                                        <?php _e('В корзину (можно восстановить)', 'machine-calculator'); ?>
                                    </option>
                                    <option value="force_delete" <?php selected($stats['delete_mode'], 'force_delete'); ?>>
                                        <?php _e('Полное удаление (безвозвратно)', 'machine-calculator'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php _e('Выберите, как удалять временные товары.', 'machine-calculator'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button(__('Сохранить настройки', 'machine-calculator')); ?>
            </form>
            
            <!-- Логи -->
            <div class="mc-temp-products-logs">
                <h3><?php _e('Последние события', 'machine-calculator'); ?></h3>
                <div id="mc-logs-container">
                    <?php $this->render_logs(); ?>
                </div>
                <p>
                    <button type="button" class="button button-secondary" id="mc-refresh-logs">
                        <?php _e('Обновить логи', 'machine-calculator'); ?>
                    </button>
                </p>
            </div>
        
        <style>
        .mc-temp-products-stats {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
        }
        
        .mc-status-badge {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .mc-status-enabled {
            background: #d4edda;
            color: #155724;
        }
        
        .mc-status-disabled {
            background: #f8d7da;
            color: #721c24;
        }
        
        .mc-logs-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .mc-logs-table th,
        .mc-logs-table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .mc-logs-table th {
            background-color: #f9f9f9;
            font-weight: bold;
        }
        
        .mc-log-level-error { color: #d32f2f; }
        .mc-log-level-warning { color: #f57c00; }
        .mc-log-level-info { color: #1976d2; }
        .mc-log-level-debug { color: #616161; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Обновление статистики
            $('#mc-refresh-stats').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('<?php _e('Обновление...', 'machine-calculator'); ?>');
                
                $.post(ajaxurl, {
                    action: 'mc_get_temp_products_stats',
                    nonce: '<?php echo wp_create_nonce('mc_temp_products_admin'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#mc-total-temp-products').text(response.data.total_temp_products);
                        $('#mc-expired-temp-products').text(response.data.expired_temp_products);
                    }
                }).always(function() {
                    button.prop('disabled', false).text('<?php _e('Обновить статистику', 'machine-calculator'); ?>');
                });
            });
            
            // Очистка просроченных товаров
            $('#mc-cleanup-expired').on('click', function() {
                if (!confirm('<?php _e('Вы уверены, что хотите удалить все просроченные временные товары?', 'machine-calculator'); ?>')) {
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('<?php _e('Удаление...', 'machine-calculator'); ?>');
                
                $.post(ajaxurl, {
                    action: 'mc_cleanup_expired_products',
                    nonce: '<?php echo wp_create_nonce('mc_temp_products_admin'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('<?php _e('Удалено товаров: ', 'machine-calculator'); ?>' + response.data.deleted_count);
                        $('#mc-refresh-stats').click();
                    } else {
                        alert('<?php _e('Ошибка: ', 'machine-calculator'); ?>' + response.data);
                    }
                }).always(function() {
                    button.prop('disabled', false).text('<?php _e('Очистить просроченные', 'machine-calculator'); ?>');
                });
            });
            
            // Обновление логов
            $('#mc-refresh-logs').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('<?php _e('Обновление...', 'machine-calculator'); ?>');
                
                $('#mc-logs-container').load(location.href + ' #mc-logs-container > *', function() {
                    button.prop('disabled', false).text('<?php _e('Обновить логи', 'machine-calculator'); ?>');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Рендерит таблицу логов
     */
    private function render_logs() {
        $logs = $this->cleaner->get_recent_logs(15);
        
        if (empty($logs)) {
            echo '<p>' . __('Логи не найдены.', 'machine-calculator') . '</p>';
            return;
        }
        
        echo '<table class="mc-logs-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . __('Время', 'machine-calculator') . '</th>';
        echo '<th>' . __('Уровень', 'machine-calculator') . '</th>';
        echo '<th>' . __('Сообщение', 'machine-calculator') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($logs as $log) {
            $level_class = 'mc-log-level-' . esc_attr($log->level);
            echo '<tr>';
            echo '<td>' . esc_html(date('Y-m-d H:i:s', strtotime($log->timestamp))) . '</td>';
            echo '<td class="' . $level_class . '">' . esc_html(strtoupper($log->level)) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($log->message, 20)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    /**
     * AJAX: Получение статистики
     */
    public function ajax_get_temp_products_stats() {
        check_ajax_referer('mc_temp_products_admin', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Недостаточно прав.', 'machine-calculator'));
        }
        
        $stats = $this->cleaner->get_temp_products_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Очистка просроченных товаров
     */
    public function ajax_cleanup_expired_products() {
        check_ajax_referer('mc_temp_products_admin', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Недостаточно прав.', 'machine-calculator'));
        }
        
        // Запускаем cron задачу для очистки
        $this->cleaner->cron_worker();
        
        // Получаем обновленную статистику
        $stats = $this->cleaner->get_temp_products_stats();
        
        wp_send_json_success([
            'deleted_count' => $stats['expired_temp_products'],
            'stats' => $stats
        ]);
    }
    
    /**
     * Callback для секции настроек
     */
    public function settings_section_callback() {
        echo '<p>' . __('Настройте автоматическое удаление временных товаров, создаваемых калькулятором.', 'machine-calculator') . '</p>';
    }
    
    /**
     * Callback для поля включения функции
     */
    public function enabled_field_callback() {
        $value = get_option('machine_calc_temp_products_enabled', true);
        echo '<input type="checkbox" name="machine_calc_temp_products_enabled" value="1" ' . checked($value, true, false) . '>';
    }
    
    /**
     * Callback для поля TTL
     */
    public function ttl_field_callback() {
        $value = get_option('machine_calc_temp_products_ttl_minutes', 60);
        echo '<input type="number" name="machine_calc_temp_products_ttl_minutes" value="' . esc_attr($value) . '" min="1" max="10080" class="small-text">';
        echo '<p class="description">' . __('Время в минутах (1-10080)', 'machine-calculator') . '</p>';
    }
    
    /**
     * Callback для поля режима удаления
     */
    public function delete_mode_field_callback() {
        $value = get_option('machine_calc_temp_products_delete_mode', 'trash');
        
        echo '<select name="machine_calc_temp_products_delete_mode">';
        echo '<option value="trash"' . selected($value, 'trash', false) . '>' . __('В корзину', 'machine-calculator') . '</option>';
        echo '<option value="force_delete"' . selected($value, 'force_delete', false) . '>' . __('Полное удаление', 'machine-calculator') . '</option>';
        echo '</select>';
    }
}
