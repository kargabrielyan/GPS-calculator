<?php
/**
 * Vehicles REST API Class
 * 
 * Registers REST endpoints for vehicle makes, models, and years data.
 * Uses MC_Vehicles_Source for data retrieval with caching.
 * 
 * @package Machine Calculator
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MC_Vehicles_REST {
    
    /**
     * REST namespace
     */
    const NAMESPACE = 'mc/v1';
    
    /**
     * Vehicles source instance
     *
     * @var MC_Vehicles_Source
     */
    private $vehicles_source;
    
    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug = false;
    
    /**
     * Constructor
     *
     * @param MC_Vehicles_Source $vehicles_source
     * @param bool $debug
     */
    public function __construct($vehicles_source, $debug = false) {
        $this->vehicles_source = $vehicles_source;
        $this->debug = $debug;
        
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_routes() {
        // GET /wp-json/mc/v1/makes?vehicle_type=passenger
        register_rest_route(self::NAMESPACE, '/makes', [
            'methods' => 'GET',
            'callback' => [$this, 'get_makes'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => [
                'vehicle_type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'passenger',
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);
        
        // GET /wp-json/mc/v1/models?make=Toyota
        register_rest_route(self::NAMESPACE, '/models', [
            'methods' => 'GET',
            'callback' => [$this, 'get_models'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => [
                'make' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [$this, 'validate_make_param']
                ]
            ]
        ]);
        
        // GET /wp-json/mc/v1/years?make=Toyota&model=Camry
        register_rest_route(self::NAMESPACE, '/years', [
            'methods' => 'GET',
            'callback' => [$this, 'get_years'],
            'permission_callback' => '__return_true', // Public endpoint
            'args' => [
                'make' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [$this, 'validate_make_param']
                ],
                'model' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [$this, 'validate_model_param']
                ]
            ]
        ]);
        
        $this->log("REST маршруты зарегистрированы");
    }
    
    /**
     * Get makes endpoint
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_makes($request) {
        try {
            $vehicle_type = $request->get_param('vehicle_type') ?: 'passenger';
            $makes = $this->vehicles_source->get_makes($vehicle_type);
            
            $this->log("REST: Возвращаем " . count($makes) . " марок для типа " . $vehicle_type);
            
            return new WP_REST_Response([
                'success' => true,
                'data' => $makes,
                'count' => count($makes),
                'vehicle_type' => $vehicle_type
            ], 200);
            
        } catch (Exception $e) {
            $this->log("Ошибка в get_makes: " . $e->getMessage());
            
            return new WP_Error(
                'mc_makes_error',
                'Ошибка при получении марок автомобилей',
                ['status' => 500]
            );
        }
    }
    
    /**
     * Get models endpoint
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_models($request) {
        $make = $request->get_param('make');
        
        try {
            $models = $this->vehicles_source->get_models($make);
            
            $this->log("REST: Возвращаем " . count($models) . " моделей для {$make}");
            
            return new WP_REST_Response([
                'success' => true,
                'data' => $models,
                'count' => count($models),
                'make' => $make
            ], 200);
            
        } catch (Exception $e) {
            $this->log("Ошибка в get_models для {$make}: " . $e->getMessage());
            
            return new WP_Error(
                'mc_models_error',
                'Ошибка при получении моделей автомобилей',
                ['status' => 500]
            );
        }
    }
    
    /**
     * Get years endpoint
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_years($request) {
        $make = $request->get_param('make');
        $model = $request->get_param('model');
        
        try {
            $years = $this->vehicles_source->get_years($make, $model);
            
            $this->log("REST: Возвращаем " . count($years) . " годов для {$make} {$model}");
            
            return new WP_REST_Response([
                'success' => true,
                'data' => $years,
                'count' => count($years),
                'make' => $make,
                'model' => $model
            ], 200);
            
        } catch (Exception $e) {
            $this->log("Ошибка в get_years для {$make} {$model}: " . $e->getMessage());
            
            return new WP_Error(
                'mc_years_error',
                'Ошибка при получении годов выпуска',
                ['status' => 500]
            );
        }
    }
    
    /**
     * Validate make parameter
     *
     * @param string $param
     * @param WP_REST_Request $request
     * @param string $key
     * @return bool|WP_Error
     */
    public function validate_make_param($param, $request, $key) {
        if (empty($param) || strlen($param) > 100) {
            return new WP_Error(
                'invalid_make',
                'Параметр make должен быть не пустым и не длиннее 100 символов'
            );
        }
        
        return true;
    }
    
    /**
     * Validate model parameter
     *
     * @param string $param
     * @param WP_REST_Request $request
     * @param string $key
     * @return bool|WP_Error
     */
    public function validate_model_param($param, $request, $key) {
        if (empty($param) || strlen($param) > 100) {
            return new WP_Error(
                'invalid_model',
                'Параметр model должен быть не пустым и не длиннее 100 символов'
            );
        }
        
        return true;
    }
    
    /**
     * Get REST base URL for frontend
     *
     * @return string
     */
    public static function get_rest_base_url() {
        return rest_url(self::NAMESPACE);
    }
    
    /**
     * Log debug message
     *
     * @param string $message
     * @return void
     */
    private function log($message) {
        if ($this->debug) {
            error_log('[Machine Calculator REST] ' . $message);
        }
    }
}
