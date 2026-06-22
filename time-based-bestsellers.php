<?php
/**
 * Plugin Name: Time-Based Bestsellers for WooCommerce
 * Description: Adds time-based filtering to WooCommerce best selling products shortcode
 * Version: 1.0.2
 * Author: Your Name
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class TimeBasedBestsellers {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('time_bestsellers', array($this, 'time_bestsellers_shortcode'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Time-Based Bestsellers',
            'Time Bestsellers',
            'manage_options',
            'time-bestsellers-settings',
            array($this, 'settings_page')
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Time-Based Bestsellers</h1>
            <div class="card">
                <h2>Usage Instructions</h2>
                <p>Use the shortcode <code>[time_bestsellers]</code> to display best-selling products within a specific time period.</p>

                <h3>Available Parameters:</h3>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li><code>time_period</code>: Number of days to look back (default: 30)</li>
                    <li><code>limit</code>: Number of products to display (default: 8) - Uses WooCommerce native parameter</li>
                    <li><code>columns</code>: Number of columns (default: 4) - Uses WooCommerce native parameter</li>
                </ul>

                <h3>Example Usage:</h3>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li>Last 30 days: <code>[time_bestsellers time_period="30"]</code></li>
                    <li>Last 90 days: <code>[time_bestsellers time_period="90"]</code></li>
                    <li>Custom layout: <code>[time_bestsellers time_period="30" limit="6" columns="3"]</code></li>
                </ul>

                <h3>Performance Notes:</h3>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li>The plugin uses optimized database queries to calculate bestsellers</li>
                    <li>Results are cached for 1 hour to minimize database load</li>
                    <li>Compatible with WooCommerce product display settings</li>
                </ul>
            </div>
        </div>
        <?php
    }

    public function time_bestsellers_shortcode($atts) {
        // Return a placeholder in Elementor editor to avoid JS/rendering conflicts
        if ($this->is_elementor_editor()) {
            return '<div style="padding:20px;border:1px dashed #ccc;text-align:center;">'
                . '<p><strong>[Time Bestsellers]</strong></p>'
                . '<p>Ce bloc sera affiché correctement sur le site publié.</p>'
                . '</div>';
        }

        $atts = shortcode_atts(array(
            'limit'       => '8',
            'columns'     => '4',
            'time_period' => '30'
        ), $atts);

        $limit       = absint($atts['limit']);
        $columns     = absint($atts['columns']);
        $time_period = absint($atts['time_period']);

        // Fetch 3x more IDs than needed to compensate for hidden/deleted/variation products
        // that WooCommerce will silently exclude when rendering the shortcode.
        $fetch_limit = $limit * 3;

        $cache_key   = 'bestsellers_' . $time_period . '_' . $limit;
        $product_ids = wp_cache_get($cache_key);

        if ($product_ids === false) {
            global $wpdb;

            $end_date   = current_time('Y-m-d H:i:s');
            $start_date = date('Y-m-d H:i:s', strtotime("-{$time_period} days"));

            // Resolve variation IDs to their parent product ID so we never pass
            // a variation to [products ids="..."] (WooCommerce ignores them).
            $results = $wpdb->get_results($wpdb->prepare("
                SELECT
                    COALESCE(p_parent.ID, p.ID) AS product_id,
                    SUM(lookup.product_qty)      AS total_qty
                FROM {$wpdb->prefix}wc_order_product_lookup AS lookup
                INNER JOIN {$wpdb->posts} AS p
                    ON p.ID = lookup.product_id
                LEFT JOIN {$wpdb->posts} AS p_parent
                    ON p_parent.ID = p.post_parent
                    AND p.post_type = 'product_variation'
                WHERE lookup.date_created BETWEEN %s AND %s
                    AND (
                        p.post_status = 'publish'
                        OR (p.post_type = 'product_variation' AND p_parent.post_status = 'publish')
                    )
                GROUP BY COALESCE(p_parent.ID, p.ID)
                ORDER BY total_qty DESC
                LIMIT %d",
                $start_date,
                $end_date,
                $fetch_limit
            ));

            $product_ids = array_values(array_unique(wp_list_pluck($results, 'product_id')));

            wp_cache_set($cache_key, $product_ids, '', HOUR_IN_SECONDS);
        }

        if (empty($product_ids)) {
            return '';
        }

        // Pass all candidate IDs to WooCommerce; it will apply visibility filters
        // and respect the limit parameter to show exactly $limit products.
        return do_shortcode(sprintf(
            '[products limit="%d" columns="%d" ids="%s"]',
            intval($limit),
            intval($columns),
            implode(',', array_map('intval', $product_ids))
        ));
    }

    /**
     * Detect Elementor's editor context (iframe preview or AJAX render).
     */
    private function is_elementor_editor() {
        if (isset($_GET['elementor-preview'])) {
            return true;
        }

        if (
            defined('DOING_AJAX') && DOING_AJAX &&
            isset($_POST['action']) &&
            strpos(sanitize_text_field(wp_unslash($_POST['action'])), 'elementor') !== false
        ) {
            return true;
        }

        if (
            class_exists('\Elementor\Plugin') &&
            \Elementor\Plugin::instance()->editor->is_edit_mode()
        ) {
            return true;
        }

        return false;
    }
}

// Only initialize when WooCommerce is active
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        TimeBasedBestsellers::get_instance();
    }
});
