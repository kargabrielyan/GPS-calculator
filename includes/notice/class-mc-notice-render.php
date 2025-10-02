<?php
/**
 * Notice Render Class
 * 
 * Handles frontend rendering of calculator notices/banners.
 * Displays notices in appropriate positions within calculator tabs.
 * 
 * @package Machine Calculator
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MC_Notice_Render {
    
    /**
     * Settings cache
     *
     * @var array
     */
    private $settings = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hook into calculator display
        add_action('machine_calculator/notice/top', [$this, 'render_top_notice']);
        add_action('machine_calculator/notice/bottom', [$this, 'render_bottom_notice']);
        
        // Enqueue styles when calculator is displayed
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_styles']);
    }
    
    /**
     * Get notice settings
     */
    private function get_settings() {
        if ($this->settings === null) {
            $this->settings = get_option('machine_calc_notice', [
                'enabled' => false,
                'position' => 'top',
                'variant' => 'accent',
                'global' => '',
                'type1' => '',
                'type2' => '',
                'type3' => ''
            ]);
        }
        
        return $this->settings;
    }
    
    /**
     * Check if notices are enabled
     */
    private function is_enabled() {
        $settings = $this->get_settings();
        return !empty($settings['enabled']);
    }
    
    /**
     * Get content for specific calculator type
     */
    private function get_content_for_type($type) {
        $settings = $this->get_settings();
        
        // Try type-specific content first
        if (!empty($settings[$type])) {
            return $settings[$type];
        }
        
        // Fallback to global content
        return $settings['global'] ?? '';
    }
    
    /**
     * Render top notice
     */
    public function render_top_notice($type = 'type1') {
        if (!$this->is_enabled()) {
            return;
        }
        
        $settings = $this->get_settings();
        if ($settings['position'] !== 'top') {
            return;
        }
        
        $this->render_notice($type);
    }
    
    /**
     * Render bottom notice
     */
    public function render_bottom_notice($type = 'type1') {
        if (!$this->is_enabled()) {
            return;
        }
        
        $settings = $this->get_settings();
        if ($settings['position'] !== 'bottom') {
            return;
        }
        
        $this->render_notice($type);
    }
    
    /**
     * Render notice HTML
     */
    private function render_notice($type) {
        $content = $this->get_content_for_type($type);
        
        if (empty($content)) {
            return;
        }
        
        $settings = $this->get_settings();
        $variant = $settings['variant'] ?? 'accent';
        $position = $settings['position'] ?? 'top';
        
        $classes = [
            'mc-notice',
            'mc-notice--' . esc_attr($variant),
            'mc-notice--' . esc_attr($position)
        ];
        
        ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>" role="note">
            <div class="mc-notice__icon" aria-hidden="true">
                <?php echo $this->get_icon_svg($variant); ?>
            </div>
            <div class="mc-notice__content">
                <?php echo wp_kses_post($content); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get SVG icon for variant
     */
    private function get_icon_svg($variant) {
        $icons = [
            'info' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                <path d="m12 16 0-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                <path d="m12 8 .01 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>',
            
            'warning' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="m12 2 10 18H2L12 2z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                <path d="m12 9 0 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                <path d="m12 17 .01 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>',
            
            'success' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                <path d="m9 12 2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>',
            
            'accent' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>'
        ];
        
        return $icons[$variant] ?? $icons['accent'];
    }
    
    /**
     * Maybe enqueue styles when calculator is displayed
     */
    public function maybe_enqueue_styles() {
        // Check if we're on a page that might contain the calculator shortcode
        global $post;
        
        if (!$post || !has_shortcode($post->post_content, 'machine_calculator')) {
            return;
        }
        
        if (!$this->is_enabled()) {
            return;
        }
        
        wp_enqueue_style(
            'machine-calc-notice',
            MACHINE_CALC_PLUGIN_URL . 'assets/css/notice.css',
            [],
            MACHINE_CALC_VERSION
        );
    }
    
    /**
     * Public method to render notice for specific type and position
     */
    public function render_notice_for($type, $position) {
        if (!$this->is_enabled()) {
            return;
        }
        
        $settings = $this->get_settings();
        if ($settings['position'] !== $position) {
            return;
        }
        
        $this->render_notice($type);
    }
}
