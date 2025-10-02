<?php
/**
 * Класс для управления временными товарами
 * Автоматическое удаление товаров после оплаты или по таймеру
 */

if (!defined('ABSPATH')) exit;

class MC_Temp_Products_Cleaner {
    
    /**
     * Текстовый домен плагина
     */
    const TEXT_DOMAIN = 'machine-calculator';
    
    /**
     * Канал логирования
     */
    const LOG_CHANNEL = 'mc_temp_products';
    
    /**
     * Экшн для cron события
     */
    const CRON_ACTION = 'mc_delete_temp_products_event';
    
    /**
     * Префикс опций
     */
    const OPTIONS_PREFIX = 'machine_calc_temp_products_';
    
    /**
     * Инициализация класса
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Инициализация хуков
     */
    private function init_hooks() {
        // Хуки для удаления после оплаты
        add_action('woocommerce_payment_complete', [$this, 'on_payment_complete'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'on_payment_complete'], 10, 1);
        add_action('woocommerce_thankyou', [$this, 'on_payment_complete'], 10, 1);
        
        // Хук для cron задачи
        add_action(self::CRON_ACTION, [$this, 'cron_worker']);
        
        // Дополнительные хуки для очистки при отмене заказов
        add_action('woocommerce_order_status_cancelled', [$this, 'on_order_cancelled'], 10, 1);
        add_action('woocommerce_order_status_failed', [$this, 'on_order_cancelled'], 10, 1);
        
        // Дополнительный хук для принудительной очистки каждые 30 минут
        add_action('wp_loaded', [$this, 'maybe_force_cleanup']);
    }
    
    /**
     * Помечает товар как временный
     *
     * @param int $product_id ID товара
     * @param int $order_id ID заказа (0 если неизвестен)
     * @param int|null $ttl_minutes TTL в минутах (null для дефолтного значения)
     * @return void
     */
    public function mark_temp_product($product_id, $order_id = 0, $ttl_minutes = null) {
        if (!$this->is_enabled()) {
            return;
        }
        
        $product_id = intval($product_id);
        $order_id = intval($order_id);
        
        if (!$product_id || !wc_get_product($product_id)) {
            $this->log('error', 'Попытка пометить несуществующий товар как временный', [
                'product_id' => $product_id
            ]);
            return;
        }
        
        $ttl = $ttl_minutes ?: $this->get_option_ttl();
        $created_at = time();
        
        // Устанавливаем мета-поля
        update_post_meta($product_id, '_temp_product', 1);
        update_post_meta($product_id, '_temp_product_order_id', $order_id);
        update_post_meta($product_id, '_temp_product_created_at', $created_at);
        update_post_meta($product_id, '_temp_product_ttl_minutes', $ttl);
        
        $this->log('info', 'Товар помечен как временный', [
            'product_id' => $product_id,
            'order_id' => $order_id,
            'ttl_minutes' => $ttl,
            'created_at' => $created_at
        ]);
    }
    
    /**
     * Планирует удаление товара через TTL
     *
     * @param int $product_id ID товара
     * @param int $ttl_minutes TTL в минутах
     * @return void
     */
    public function schedule_cleanup($product_id, $ttl_minutes) {
        if (!$this->is_enabled()) {
            return;
        }
        
        $product_id = intval($product_id);
        $ttl_minutes = intval($ttl_minutes);
        
        if (!$product_id || $ttl_minutes <= 0) {
            return;
        }
        
        $cleanup_time = time() + ($ttl_minutes * 60);
        
        // Планируем разовое событие
        if (!wp_next_scheduled(self::CRON_ACTION, [$product_id])) {
            wp_schedule_single_event($cleanup_time, self::CRON_ACTION, [$product_id]);
            
            $this->log('info', 'Запланирована очистка временного товара', [
                'product_id' => $product_id,
                'cleanup_time' => date('Y-m-d H:i:s', $cleanup_time),
                'ttl_minutes' => $ttl_minutes
            ]);
        }
    }
    
    /**
     * Удаляет временный товар
     *
     * @param int $product_id ID товара
     * @param bool|null $force_delete Принудительное удаление (null для настройки)
     * @return bool Успешно ли удален товар
     */
    public function delete_temp_product($product_id, $force_delete = null) {
        $product_id = intval($product_id);
        
        if (!$product_id) {
            return false;
        }
        
        // Проверяем, что товар существует и помечен как временный
        if (!$this->is_temp_product($product_id)) {
            return false;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            // Товар уже не существует, очищаем мета данные
            $this->cleanup_product_meta($product_id);
            return true;
        }
        
        // Определяем режим удаления
        $force = $force_delete !== null ? $force_delete : $this->get_option_delete_mode() === 'force_delete';
        
        $result = false;
        if ($force) {
            $result = wp_delete_post($product_id, true);
            $mode = 'force_delete';
        } else {
            $result = wp_trash_post($product_id);
            $mode = 'trash';
        }
        
        if ($result) {
            $this->log('info', 'Временный товар удален', [
                'product_id' => $product_id,
                'delete_mode' => $mode,
                'product_name' => $product->get_name()
            ]);
            
            // Очищаем запланированные события для этого товара
            $this->clear_scheduled_cleanup($product_id);
        } else {
            $this->log('error', 'Ошибка удаления временного товара', [
                'product_id' => $product_id,
                'delete_mode' => $mode
            ]);
        }
        
        return (bool) $result;
    }
    
    /**
     * Обработчик успешной оплаты
     *
     * @param int $order_id ID заказа
     * @return void
     */
    public function on_payment_complete($order_id) {
        if (!$this->is_enabled()) {
            return;
        }
        
        $order_id = intval($order_id);
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $this->log('info', 'Обработка завершения оплаты заказа', [
            'order_id' => $order_id,
            'order_status' => $order->get_status()
        ]);
        
        // Находим и удаляем все временные товары, связанные с заказом
        $temp_products = $this->get_temp_products_by_order($order_id);
        
        // Также ищем товары из этого заказа, которые могут быть временными
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if ($this->is_temp_product($product_id)) {
                $temp_products[] = $product_id;
            }
        }
        
        $temp_products = array_unique($temp_products);
        
        foreach ($temp_products as $product_id) {
            $this->delete_temp_product($product_id);
        }
        
        if (!empty($temp_products)) {
            $this->log('info', 'Удалены временные товары после оплаты', [
                'order_id' => $order_id,
                'deleted_products' => $temp_products,
                'count' => count($temp_products)
            ]);
        }
    }
    
    /**
     * Обработчик отмены заказа
     *
     * @param int $order_id ID заказа
     * @return void
     */
    public function on_order_cancelled($order_id) {
        if (!$this->is_enabled()) {
            return;
        }
        
        // При отмене заказа также удаляем связанные временные товары
        $this->on_payment_complete($order_id);
    }
    
    /**
     * Cron задача для очистки просроченных товаров
     *
     * @param int|null $specific_product_id ID конкретного товара для проверки
     * @return void
     */
    public function cron_worker($specific_product_id = null) {
        if (!$this->is_enabled()) {
            return;
        }
        
        $this->log('info', 'Запуск cron задачи очистки временных товаров', [
            'specific_product_id' => $specific_product_id
        ]);
        
        if ($specific_product_id) {
            // Проверяем конкретный товар
            $this->check_and_cleanup_product($specific_product_id);
        } else {
            // Проверяем все просроченные товары
            $this->cleanup_expired_products();
        }
    }
    
    /**
     * Проверяет и удаляет конкретный товар, если он просрочен
     *
     * @param int $product_id ID товара
     * @return void
     */
    private function check_and_cleanup_product($product_id) {
        if (!$this->is_temp_product($product_id)) {
            return;
        }
        
        $created_at = get_post_meta($product_id, '_temp_product_created_at', true);
        $ttl_minutes = get_post_meta($product_id, '_temp_product_ttl_minutes', true);
        $order_id = get_post_meta($product_id, '_temp_product_order_id', true);
        
        $current_time = time();
        $expiry_time = intval($created_at) + (intval($ttl_minutes) * 60);
        
        // Проверяем, не просрочен ли товар
        if ($current_time < $expiry_time) {
            return; // Товар еще не просрочен
        }
        
        // Если товар связан с заказом, проверяем статус заказа
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order && in_array($order->get_status(), ['completed', 'processing', 'on-hold'])) {
                // Заказ оплачен или в процессе - не удаляем товар через TTL
                $this->log('info', 'Товар не удален через TTL - заказ оплачен', [
                    'product_id' => $product_id,
                    'order_id' => $order_id,
                    'order_status' => $order->get_status()
                ]);
                return;
            }
        }
        
        // Удаляем просроченный товар
        $this->delete_temp_product($product_id);
    }
    
    /**
     * Очищает все просроченные временные товары
     *
     * @return void
     */
    private function cleanup_expired_products() {
        global $wpdb;
        
        $current_time = time();
        
        // Находим все просроченные временные товары
        $query = $wpdb->prepare("
            SELECT p.ID, pm1.meta_value as created_at, pm2.meta_value as ttl_minutes, pm3.meta_value as order_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_temp_product' AND pm.meta_value = '1'
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_temp_product_created_at'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_temp_product_ttl_minutes'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_temp_product_order_id'
            WHERE p.post_type = 'product'
            AND p.post_status IN ('publish', 'private', 'draft')
            AND (pm1.meta_value + (pm2.meta_value * 60)) <= %d
        ", $current_time);
        
        $expired_products = $wpdb->get_results($query);
        
        $deleted_count = 0;
        foreach ($expired_products as $product_data) {
            $product_id = intval($product_data->ID);
            $order_id = intval($product_data->order_id);
            
            // Если товар связан с заказом, проверяем статус заказа
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order && in_array($order->get_status(), ['completed', 'processing', 'on-hold'])) {
                    continue; // Пропускаем оплаченные заказы
                }
            }
            
            if ($this->delete_temp_product($product_id)) {
                $deleted_count++;
            }
        }
        
        $this->log('info', 'Завершена массовая очистка просроченных товаров', [
            'found_expired' => count($expired_products),
            'deleted_count' => $deleted_count
        ]);
    }
    
    /**
     * Проверяет, является ли товар временным
     *
     * @param int $product_id ID товара
     * @return bool
     */
    public function is_temp_product($product_id) {
        return get_post_meta($product_id, '_temp_product', true) == '1';
    }
    
    /**
     * Получает временные товары по ID заказа
     *
     * @param int $order_id ID заказа
     * @return array Массив ID товаров
     */
    private function get_temp_products_by_order($order_id) {
        global $wpdb;
        
        $query = $wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_temp_product' AND pm1.meta_value = '1'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_temp_product_order_id' AND pm2.meta_value = %d
            WHERE p.post_type = 'product'
        ", $order_id);
        
        $results = $wpdb->get_col($query);
        
        return array_map('intval', $results);
    }
    
    /**
     * Очищает запланированные события очистки для товара
     *
     * @param int $product_id ID товара
     * @return void
     */
    private function clear_scheduled_cleanup($product_id) {
        $timestamp = wp_next_scheduled(self::CRON_ACTION, [$product_id]);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_ACTION, [$product_id]);
        }
    }
    
    /**
     * Очищает мета-данные товара
     *
     * @param int $product_id ID товара
     * @return void
     */
    private function cleanup_product_meta($product_id) {
        delete_post_meta($product_id, '_temp_product');
        delete_post_meta($product_id, '_temp_product_order_id');
        delete_post_meta($product_id, '_temp_product_created_at');
        delete_post_meta($product_id, '_temp_product_ttl_minutes');
    }
    
    /**
     * Проверяет, включена ли функциональность временных товаров
     *
     * @return bool
     */
    private function is_enabled() {
        return get_option(self::OPTIONS_PREFIX . 'enabled', true);
    }
    
    /**
     * Получает TTL из настроек
     *
     * @return int TTL в минутах
     */
    public function get_option_ttl() {
        return intval(get_option(self::OPTIONS_PREFIX . 'ttl_minutes', 60));
    }
    
    /**
     * Получает режим удаления из настроек
     *
     * @return string 'trash' или 'force_delete'
     */
    public function get_option_delete_mode() {
        return get_option(self::OPTIONS_PREFIX . 'delete_mode', 'trash');
    }
    
    /**
     * Логирование событий
     *
     * @param string $level Уровень лога (error, warning, info, debug)
     * @param string $message Сообщение
     * @param array $context Контекст
     * @return void
     */
    public function log($level, $message, $context = []) {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        
        $logger = wc_get_logger();
        
        $formatted_message = $message;
        if (!empty($context)) {
            $formatted_message .= ' ' . wc_print_r($context, true);
        }
        
        $logger->log($level, $formatted_message, [
            'source' => self::LOG_CHANNEL
        ]);
    }
    
    /**
     * Получает последние записи лога
     *
     * @param int $limit Количество записей
     * @return array
     */
    public function get_recent_logs($limit = 20) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'woocommerce_log';
        
        // Проверяем, существует ли таблица логов
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return [];
        }
        
        $query = $wpdb->prepare("
            SELECT timestamp, level, message
            FROM $table_name
            WHERE source = %s
            ORDER BY timestamp DESC
            LIMIT %d
        ", self::LOG_CHANNEL, $limit);
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Обновляет связь товара с заказом
     *
     * @param int $product_id ID товара
     * @param int $order_id ID заказа
     * @return void
     */
    public function update_product_order_link($product_id, $order_id) {
        if ($this->is_temp_product($product_id)) {
            update_post_meta($product_id, '_temp_product_order_id', intval($order_id));
            
            $this->log('info', 'Обновлена связь временного товара с заказом', [
                'product_id' => $product_id,
                'order_id' => $order_id
            ]);
        }
    }
    
    /**
     * Очистка при деактивации плагина
     *
     * @return void
     */
    public static function cleanup_on_deactivation() {
        // Отменяем все запланированные события
        wp_clear_scheduled_hook(self::CRON_ACTION);
    }
    
    /**
     * Получает статистику временных товаров
     *
     * @return array
     */
    public function get_temp_products_stats() {
        global $wpdb;
        
        // Общее количество временных товаров
        $total_temp = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_temp_product' AND pm.meta_value = '1'
            AND p.post_type = 'product'
        ");
        
        // Количество просроченных товаров
        $current_time = time();
        $expired_temp = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_temp_product' AND pm.meta_value = '1'
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_temp_product_created_at'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_temp_product_ttl_minutes'
            WHERE p.post_type = 'product'
            AND (pm1.meta_value + (pm2.meta_value * 60)) <= %d
        ", $current_time));
        
        return [
            'total_temp_products' => intval($total_temp),
            'expired_temp_products' => intval($expired_temp),
            'enabled' => $this->is_enabled(),
            'ttl_minutes' => $this->get_option_ttl(),
            'delete_mode' => $this->get_option_delete_mode()
        ];
    }
    
    /**
     * Принудительная очистка каждые 30 минут (если WP-Cron не работает)
     */
    public function maybe_force_cleanup() {
        if (!$this->is_enabled()) {
            return;
        }
        
        // Проверяем, прошло ли 30 минут с последней принудительной очистки
        $last_force_cleanup = get_transient('mc_last_force_cleanup_time');
        $current_time = time();
        
        if (!$last_force_cleanup || ($current_time - $last_force_cleanup) >= 1800) { // 30 минут = 1800 секунд
            $this->log('info', 'Запуск принудительной очистки (WP-Cron резерв)');
            $this->cron_worker();
            set_transient('mc_last_force_cleanup_time', $current_time, 3600); // Сохраняем на час
        }
    }
}
