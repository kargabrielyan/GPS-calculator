<?php
/**
 * Vehicles Data Source Class
 * 
 * Provides vehicle makes, models, and years data from CarQuery API
 * with local cache and JSON fallback support.
 * 
 * @package Machine Calculator
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MC_Vehicles_Source {
    
    /**
     * Cache TTL in seconds (30 days)
     */
    const CACHE_TTL = 30 * DAY_IN_SECONDS;
    
    /**
     * Vehicle types
     */
    const VEHICLE_TYPES = [
        'mardatar' => 'Մարդատար ավտոմեքենաներ',
        'bernatar' => 'Բեռնատար ավտոմեքենաներ'
    ];
    
    /**
     * CarQuery API base URL
     */
    const API_BASE_URL = 'https://www.carqueryapi.com/api/0.3/';
    
    /**
     * NHTSA API base URL
     */
    const NHTSA_API_URL = 'https://vpic.nhtsa.dot.gov/api/';
    
    
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
     * Get vehicle types
     *
     * @return array Array of vehicle types [{id, name}]
     */
    public function get_vehicle_types() {
        $data = $this->load_fallback_data();
        return $data['vehicle_types'] ?? [];
    }
    
    /**
     * Get all vehicle makes for specific type
     *
     * @param string $vehicle_type
     * @return array Array of makes [{id, name}]
     */
    public function get_makes($vehicle_type = 'mardatar') {
        // First try local catalog database
        $catalog = new MC_Vehicles_Catalog();
        $catalog_makes = $catalog->get_makes($vehicle_type);
        
        if (!empty($catalog_makes)) {
            $makes = [];
            foreach ($catalog_makes as $make) {
                $makes[] = [
                    'id' => $make->name,
                    'name' => $make->name
                ];
            }
            $this->log("Получено " . count($makes) . " марок из локального каталога для типа {$vehicle_type}");
            return $makes;
        }
        
        // If no local data, try API
        $cache_key = 'mc_makes_' . $vehicle_type;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->log("Возвращаем марки для типа {$vehicle_type} из кэша");
            return $cached;
        }
        
        // Try API first
        $makes = $this->fetch_makes_from_api($vehicle_type);
        
        // If API failed, try local fallback
        if (empty($makes)) {
            $data = $this->load_fallback_data();
            $makes = $data['makes'][$vehicle_type] ?? [];
        }
        
        // If still empty, use default
        if (empty($makes)) {
            $makes = $this->get_default_makes($vehicle_type);
        }
        
        // Cache the result
        if (!empty($makes)) {
            set_transient($cache_key, $makes, self::CACHE_TTL);
            $this->log("Кэшировано " . count($makes) . " марок для типа {$vehicle_type}");
        }
        
        return $makes;
    }
    
    /**
     * Get models for specific make
     *
     * @param string $make Make name/slug
     * @return array Array of models [{id, name}]
     */
    public function get_models($make) {
        if (empty($make)) {
            return [];
        }
        
        // First try local catalog database
        $catalog = new MC_Vehicles_Catalog();
        
        // Find make by name
        global $wpdb;
        $make_obj = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mc_makes WHERE name = %s",
            $make
        ));
        
        if ($make_obj) {
            $catalog_models = $catalog->get_models($make_obj->id);
            
            if (!empty($catalog_models)) {
                $models = [];
                foreach ($catalog_models as $model) {
                    $models[] = [
                        'id' => $model->name,
                        'name' => $model->name
                    ];
                }
                $this->log("Получено " . count($models) . " моделей из локального каталога для марки {$make}");
                return $models;
            }
        }
        
        // If no local data, try API
        $cache_key = 'mc_cq_models_' . sanitize_key($make);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->log("Возвращаем модели для {$make} из кэша");
            return $cached;
        }
        
        // Try CarQuery API first
        $models = $this->fetch_models_from_api($make);
        
        // If API failed, try local fallback
        if (empty($models)) {
            $models = $this->get_models_from_fallback($make);
        }
        
        // Cache the result
        if (!empty($models)) {
            set_transient($cache_key, $models, self::CACHE_TTL);
            $this->log("Кэшировано " . count($models) . " моделей для {$make}");
        }
        
        return $models;
    }
    
    /**
     * Get years for specific make and model
     *
     * @param string $make Make name/slug
     * @param string $model Model name/slug
     * @return array Array of years [YYYY, ...]
     */
    public function get_years($make, $model) {
        if (empty($make) || empty($model)) {
            return [];
        }
        
        $cache_key = 'mc_cq_years_' . sanitize_key($make) . '_' . sanitize_key($model);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            $this->log("Возвращаем годы для {$make} {$model} из кэша");
            return $cached;
        }
        
        // Try CarQuery API first
        $years = $this->fetch_years_from_api($make, $model);
        
        // If API failed, try local fallback
        if (empty($years)) {
            $years = $this->get_years_from_fallback($make, $model);
        }
        
        // Cache the result
        if (!empty($years)) {
            set_transient($cache_key, $years, self::CACHE_TTL);
            $this->log("Кэшировано " . count($years) . " годов для {$make} {$model}");
        }
        
        return $years;
    }
    
    /**
     * Fetch makes from API
     *
     * @param string $vehicle_type
     * @return array
     */
    private function fetch_makes_from_api($vehicle_type = 'mardatar') {
        // Try NHTSA API first (more reliable)
        $makes = $this->fetch_makes_from_nhtsa($vehicle_type);
        
        // If NHTSA fails, try CarQuery API
        if (empty($makes)) {
            $makes = $this->fetch_makes_from_carquery();
        }
        
        return $makes;
    }
    
    /**
     * Fetch makes from CarQuery API
     *
     * @return array
     */
    private function fetch_makes_from_carquery() {
        $url = self::API_BASE_URL . '?cmd=getMakes';
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Machine Calculator WordPress Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Ошибка CarQuery API при получении марок: " . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['Makes'])) {
            $this->log("Неверный формат ответа CarQuery API для марок");
            return [];
        }
        
        $makes = [];
        foreach ($data['Makes'] as $make) {
            $makes[] = [
                'id' => sanitize_text_field($make['make_id'] ?? ''),
                'name' => sanitize_text_field($make['make_display'] ?? '')
            ];
        }
        
        $this->log("Получено " . count($makes) . " марок из CarQuery API");
        return $makes;
    }
    
    /**
     * Fetch makes from NHTSA API
     *
     * @param string $vehicle_type
     * @return array
     */
    private function fetch_makes_from_nhtsa($vehicle_type = 'mardatar') {
        $url = self::NHTSA_API_URL . 'vehicles/GetAllMakes?format=json';
        
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'Machine Calculator WordPress Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Ошибка NHTSA API при получении марок: " . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['Results'])) {
            $this->log("Неверный формат ответа NHTSA API для марок");
            return [];
        }
        
        $makes = [];
        $filtered_makes = $this->filter_makes_by_type($data['Results'], $vehicle_type);
        
        foreach ($filtered_makes as $make) {
            if (!empty($make['Make_Name'])) {
                $makes[] = [
                    'id' => sanitize_text_field($make['Make_Name']),
                    'name' => sanitize_text_field($make['Make_Name'])
                ];
            }
        }
        
        // Remove duplicates
        $makes = array_unique($makes, SORT_REGULAR);
        $makes = array_values($makes);
        
        $this->log("Получено " . count($makes) . " марок из NHTSA API для типа {$vehicle_type}");
        return $makes;
    }
    
    /**
     * Filter makes by vehicle type
     *
     * @param array $makes
     * @param string $vehicle_type
     * @return array
     */
    private function filter_makes_by_type($makes, $vehicle_type) {
        // Определяем марки для каждого типа
        $mardatar_makes = [
            'Toyota', 'Honda', 'Nissan', 'Mazda', 'Subaru', 'Mitsubishi',
            'Mercedes-Benz', 'BMW', 'Audi', 'Volkswagen', 'Porsche',
            'Ford', 'Chevrolet', 'Cadillac', 'Buick', 'GMC',
            'Hyundai', 'Kia', 'Genesis',
            'Lexus', 'Infiniti', 'Acura',
            'Jeep', 'Chrysler', 'Dodge', 'Ram',
            'Volvo', 'Saab',
            'Tesla', 'Rivian', 'Lucid',
            'Ferrari', 'Lamborghini', 'Maserati', 'Bentley', 'Rolls-Royce',
            'Aston Martin', 'McLaren', 'Bugatti', 'Koenigsegg'
        ];
        
        $bernatar_makes = [
            'Freightliner', 'Peterbilt', 'Kenworth', 'Mack', 'Volvo Trucks',
            'International', 'Western Star', 'Hino', 'Isuzu', 'Mitsubishi Fuso',
            'Ford Commercial', 'Chevrolet Commercial', 'GMC Commercial',
            'Mercedes-Benz Trucks', 'MAN', 'Scania', 'Iveco', 'DAF',
            'Renault Trucks', 'Volvo Construction Equipment',
            'Caterpillar', 'John Deere', 'Case', 'New Holland'
        ];
        
        $target_makes = ($vehicle_type === 'bernatar') ? $bernatar_makes : $mardatar_makes;
        
        return array_filter($makes, function($make) use ($target_makes) {
            $make_name = $make['Make_Name'] ?? '';
            return in_array($make_name, $target_makes);
        });
    }
    
    /**
     * Fetch models from CarQuery API
     *
     * @param string $make
     * @return array
     */
    private function fetch_models_from_api($make) {
        // Try NHTSA API first (more reliable)
        $models = $this->fetch_models_from_nhtsa($make);
        
        // If NHTSA fails, try CarQuery API
        if (empty($models)) {
            $models = $this->fetch_models_from_carquery($make);
        }
        
        return $models;
    }
    
    /**
     * Fetch models from CarQuery API
     *
     * @param string $make
     * @return array
     */
    private function fetch_models_from_carquery($make) {
        $url = self::API_BASE_URL . '?cmd=getModels&make=' . urlencode($make);
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Machine Calculator WordPress Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Ошибка CarQuery API при получении моделей для {$make}: " . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['Models'])) {
            $this->log("Неверный формат ответа CarQuery API для моделей {$make}");
            return [];
        }
        
        $models = [];
        foreach ($data['Models'] as $model) {
            $models[] = [
                'id' => sanitize_text_field($model['model_name'] ?? ''),
                'name' => sanitize_text_field($model['model_name'] ?? '')
            ];
        }
        
        $this->log("Получено " . count($models) . " моделей для {$make} из CarQuery API");
        return $models;
    }
    
    /**
     * Fetch models from NHTSA API
     *
     * @param string $make
     * @return array
     */
    private function fetch_models_from_nhtsa($make) {
        $url = self::NHTSA_API_URL . 'getmodelsformake/' . urlencode($make) . '?format=json';
        
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'Machine Calculator WordPress Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Ошибка NHTSA API при получении моделей для {$make}: " . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['Results'])) {
            $this->log("Неверный формат ответа NHTSA API для моделей {$make}");
            return [];
        }
        
        $models = [];
        foreach ($data['Results'] as $model) {
            if (!empty($model['Model_Name'])) {
                $models[] = [
                    'id' => sanitize_text_field($model['Model_Name']),
                    'name' => sanitize_text_field($model['Model_Name'])
                ];
            }
        }
        
        // Remove duplicates
        $models = array_unique($models, SORT_REGULAR);
        $models = array_values($models);
        
        $this->log("Получено " . count($models) . " моделей для {$make} из NHTSA API");
        return $models;
    }
    
    /**
     * Fetch years from CarQuery API
     *
     * @param string $make
     * @param string $model
     * @return array
     */
    private function fetch_years_from_api($make, $model) {
        // Try CarQuery API first
        $years = $this->fetch_years_from_carquery($make, $model);
        
        // If CarQuery fails, try NHTSA API
        if (empty($years)) {
            $years = $this->fetch_years_from_nhtsa($make, $model);
        }
        
        // If still empty, generate default years
        if (empty($years)) {
            $years = $this->generate_default_years();
        }
        
        return $years;
    }
    
    /**
     * Fetch years from CarQuery API
     *
     * @param string $make
     * @param string $model
     * @return array
     */
    private function fetch_years_from_carquery($make, $model) {
        $url = self::API_BASE_URL . '?cmd=getTrims&make=' . urlencode($make) . '&model=' . urlencode($model);
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'Machine Calculator WordPress Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Ошибка CarQuery API при получении годов для {$make} {$model}: " . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['Trims'])) {
            $this->log("Неверный формат ответа CarQuery API для годов {$make} {$model}");
            return [];
        }
        
        $years = [];
        foreach ($data['Trims'] as $trim) {
            if (isset($trim['model_year'])) {
                $year = intval($trim['model_year']);
                if ($year >= 1900 && $year <= 2100) {
                    $years[] = $year;
                }
            }
        }
        
        // Remove duplicates and sort
        $years = array_unique($years);
        rsort($years); // Newest first
        
        $this->log("Получено " . count($years) . " годов для {$make} {$model} из CarQuery API");
        return $years;
    }
    
    /**
     * Fetch years from NHTSA API
     *
     * @param string $make
     * @param string $model
     * @return array
     */
    private function fetch_years_from_nhtsa($make, $model) {
        $url = self::NHTSA_API_URL . 'getmodelyearsformakeyear/make/' . urlencode($make) . '/modelyear/2024?format=json';
        
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'Machine Calculator WordPress Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->log("Ошибка NHTSA API при получении годов для {$make} {$model}: " . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['Results'])) {
            $this->log("Неверный формат ответа NHTSA API для годов {$make} {$model}");
            return [];
        }
        
        $years = [];
        foreach ($data['Results'] as $result) {
            if (isset($result['Model_Year'])) {
                $year = intval($result['Model_Year']);
                if ($year >= 1900 && $year <= 2100) {
                    $years[] = $year;
                }
            }
        }
        
        // Remove duplicates and sort
        $years = array_unique($years);
        rsort($years); // Newest first
        
        $this->log("Получено " . count($years) . " годов для {$make} {$model} из NHTSA API");
        return $years;
    }
    
    /**
     * Generate default years (last 30 years)
     *
     * @return array
     */
    private function generate_default_years() {
        $current_year = date('Y');
        $years = [];
        
        // Last 30 years
        for ($i = 0; $i < 30; $i++) {
            $years[] = $current_year - $i;
        }
        
        $this->log("Сгенерировано " . count($years) . " годов по умолчанию");
        return $years;
    }
    
    /**
     * Get makes from local fallback JSON
     *
     * @return array
     */
    private function get_makes_from_fallback() {
        $fallback_data = $this->load_fallback_data();
        
        if (!$fallback_data || !isset($fallback_data['makes'])) {
            $this->log("Fallback данные недоступны для марок");
            return $this->get_default_makes('mardatar');
        }
        
        $this->log("Используем fallback данные для марок");
        return $fallback_data['makes'];
    }
    
    /**
     * Get models from local fallback JSON
     *
     * @param string $make
     * @return array
     */
    private function get_models_from_fallback($make) {
        $fallback_data = $this->load_fallback_data();
        
        if (!$fallback_data || !isset($fallback_data['models'][$make])) {
            $this->log("Fallback данные недоступны для моделей {$make}");
            return $this->get_default_models($make);
        }
        
        $this->log("Используем fallback данные для моделей {$make}");
        return $fallback_data['models'][$make];
    }
    
    /**
     * Get years from local fallback JSON
     *
     * @param string $make
     * @param string $model
     * @return array
     */
    private function get_years_from_fallback($make, $model) {
        $fallback_data = $this->load_fallback_data();
        
        if (!$fallback_data || !isset($fallback_data['years'][$make][$model])) {
            $this->log("Fallback данные недоступны для годов {$make} {$model}");
            return $this->get_default_years();
        }
        
        $this->log("Используем fallback данные для годов {$make} {$model}");
        return $fallback_data['years'][$make][$model];
    }
    
    /**
     * Load fallback data from JSON file
     *
     * @return array|null
     */
    private function load_fallback_data() {
        $file_path = MACHINE_CALC_PLUGIN_DIR . 'assets/data/vehicles.json';
        
        if (!file_exists($file_path)) {
            $this->log("Файл fallback данных не найден: " . $file_path);
            return null;
        }
        
        $json_content = file_get_contents($file_path);
        if ($json_content === false) {
            $this->log("Ошибка чтения файла fallback данных: " . $file_path);
            return null;
        }
        
        $data = json_decode($json_content, true);
        if (!$data) {
            $this->log("Ошибка парсинга JSON fallback данных");
            return null;
        }
        
        $this->log("Загружены fallback данные из " . $file_path);
        return $data;
    }
    
    /**
     * Get default makes when everything fails
     *
     * @param string $vehicle_type
     * @return array
     */
    private function get_default_makes($vehicle_type = 'mardatar') {
        if ($vehicle_type === 'bernatar') {
            return [
                ['id' => 'Volvo', 'name' => 'Volvo'],
                ['id' => 'Scania', 'name' => 'Scania'],
                ['id' => 'MAN', 'name' => 'MAN'],
                ['id' => 'Iveco', 'name' => 'Iveco'],
                ['id' => 'DAF', 'name' => 'DAF']
            ];
        }
        
        return [
            ['id' => 'Toyota', 'name' => 'Toyota'],
            ['id' => 'Mercedes', 'name' => 'Mercedes'],
            ['id' => 'BMW', 'name' => 'BMW'],
            ['id' => 'Audi', 'name' => 'Audi'],
            ['id' => 'Volkswagen', 'name' => 'Volkswagen'],
            ['id' => 'Hyundai', 'name' => 'Hyundai'],
            ['id' => 'Kia', 'name' => 'Kia'],
            ['id' => 'Nissan', 'name' => 'Nissan'],
            ['id' => 'Ford', 'name' => 'Ford'],
            ['id' => 'Lexus', 'name' => 'Lexus']
        ];
    }
    
    /**
     * Get default models when everything fails
     *
     * @param string $make
     * @return array
     */
    private function get_default_models($make) {
        $default_models = [
            'Toyota' => ['Corolla', 'Camry', 'RAV4'],
            'Mercedes' => ['C-Class', 'E-Class', 'GLC'],
            'BMW' => ['3 Series', '5 Series', 'X5'],
            'Audi' => ['A4', 'Q5', 'A6'],
            'Volkswagen' => ['Golf', 'Passat', 'Tiguan'],
            'Hyundai' => ['Elantra', 'Tucson', 'Santa Fe'],
            'Kia' => ['Sportage', 'Rio', 'Sorento'],
            'Nissan' => ['Qashqai', 'X-Trail', 'Altima'],
            'Ford' => ['Focus', 'Mondeo', 'Explorer'],
            'Lexus' => ['RX', 'NX', 'ES']
        ];
        
        if (!isset($default_models[$make])) {
            return [];
        }
        
        $models = [];
        foreach ($default_models[$make] as $model) {
            $models[] = ['id' => $model, 'name' => $model];
        }
        
        return $models;
    }
    
    /**
     * Get default years when everything fails
     *
     * @return array
     */
    private function get_default_years() {
        $current_year = date('Y');
        $years = [];
        
        // Last 20 years
        for ($i = 0; $i < 20; $i++) {
            $years[] = $current_year - $i;
        }
        
        return $years;
    }
    
    /**
     * Clear all vehicle data cache
     *
     * @return void
     */
    public function clear_cache() {
        // Delete makes cache
        delete_transient('mc_cq_makes');
        
        // We need to delete all models and years cache too
        // Since we can't enumerate all possible cache keys, 
        // we'll use a different approach in the future if needed
        $this->log("Кэш марок очищен");
    }
    
    /**
     * Force refresh data from API (bypass cache)
     *
     * @return void
     */
    public function refresh_data() {
        $this->clear_cache();
        
        // Preload makes to warm up cache
        $makes = $this->get_makes();
        $this->log("Данные обновлены из API, загружено " . count($makes) . " марок");
        
        return $makes;
    }
    
    /**
     * Get API status and statistics
     *
     * @return array
     */
    public function get_api_status() {
        $status = [
            'carquery' => $this->test_carquery_api(),
            'nhtsa' => $this->test_nhtsa_api(),
            'cache_status' => $this->get_cache_status()
        ];
        
        return $status;
    }
    
    /**
     * Test CarQuery API availability
     *
     * @return array
     */
    private function test_carquery_api() {
        $url = self::API_BASE_URL . '?cmd=getMakes';
        
        $response = wp_remote_get($url, [
            'timeout' => 5,
            'headers' => [
                'User-Agent' => 'Machine Calculator WordPress Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => $response->get_error_message()
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($data && isset($data['Makes'])) {
            return [
                'status' => 'success',
                'message' => 'API доступен',
                'makes_count' => count($data['Makes'])
            ];
        }
        
        return [
            'status' => 'error',
            'message' => 'Неверный формат ответа'
        ];
    }
    
    /**
     * Test NHTSA API availability
     *
     * @return array
     */
    private function test_nhtsa_api() {
        $url = self::NHTSA_API_URL . 'getallmakes?format=json';
        
        $response = wp_remote_get($url, [
            'timeout' => 5,
            'headers' => [
                'User-Agent' => 'Machine Calculator WordPress Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => $response->get_error_message()
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($data && isset($data['Results'])) {
            return [
                'status' => 'success',
                'message' => 'API доступен',
                'makes_count' => count($data['Results'])
            ];
        }
        
        return [
            'status' => 'error',
            'message' => 'Неверный формат ответа'
        ];
    }
    
    /**
     * Get cache status
     *
     * @return array
     */
    private function get_cache_status() {
        $cache_keys = [
            'mc_cq_makes' => 'Марки автомобилей',
            'mc_cq_models_' => 'Модели автомобилей',
            'mc_cq_years_' => 'Годы выпуска'
        ];
        
        $status = [];
        foreach ($cache_keys as $key => $description) {
            $cached = get_transient($key);
            $status[$key] = [
                'description' => $description,
                'cached' => $cached !== false,
                'expires' => $cached !== false ? 'Кэшировано' : 'Не кэшировано'
            ];
        }
        
        return $status;
    }
    
    /**
     * Log debug message
     *
     * @param string $message
     * @return void
     */
    private function log($message) {
        if ($this->debug) {
            error_log('[Machine Calculator Vehicles] ' . $message);
        }
    }
}
