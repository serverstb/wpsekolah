<?php
/**
 * Auto-Generated WordPress Self-Hosted Plugin Updater Client
 * Host Server: https://api.serverstb.com
 * Plugin Slug: wpsekolah
 * 
 * CARA PAKAI:
 * 1. Simpan file ini di dalam folder plugin Anda, misal: 'updater.php'
 * 2. Di file utama plugin Anda (misal 'index.php'), tambahkan:
 *    require_once __DIR__ . '/updater.php';
 *    new Custom_Plugin_Updater(__FILE__, 'wpsekolah', 'https://api.serverstb.com');
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('Custom_Plugin_Updater')) {
    class Custom_Plugin_Updater {
        private $plugin_file;
        private $plugin_slug;
        private $api_base;
        private $api_key;
        private $plugin_data;

        public function __construct($plugin_file, $plugin_slug, $api_base, $api_key = '') {
            $this->plugin_file = $plugin_file;
            $this->plugin_slug = $plugin_slug;
            $this->api_base    = rtrim($api_base, '/');
            $this->api_key     = $api_key;

            add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
            add_filter('plugins_api', [$this, 'plugin_popup_information'], 20, 3);
            add_action('upgrader_process_complete', [$this, 'purge_update_transient'], 10, 2);
        }

        private function get_installed_version() {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (!$this->plugin_data) {
                $this->plugin_data = get_plugin_data($this->plugin_file);
            }
            return isset($this->plugin_data['Version']) ? $this->plugin_data['Version'] : '1.0.0';
        }

        public function check_for_update($transient) {
            if (empty($transient->checked)) {
                return $transient;
            }

            $current_ver = $this->get_installed_version();
            $query_args  = [
                'slug'    => $this->plugin_slug,
                'version' => $current_ver,
                'key'     => $this->api_key,
                'site'    => home_url()
            ];

            $url = $this->api_base . '/plugin/wordpress/api/update?' . http_build_query($query_args);
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json']
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return $transient;
            }

            $payload = json_decode(wp_remote_retrieve_body($response));
            if (!empty($payload) && !empty($payload->new_version) && empty($payload->no_update)) {
                if (version_compare($current_ver, $payload->new_version, '<')) {
                    $plugin_basename = plugin_basename($this->plugin_file);
                    
                    $item = new stdClass();
                    $item->id           = $plugin_basename;
                    $item->slug         = $this->plugin_slug;
                    $item->plugin       = $plugin_basename;
                    $item->new_version  = $payload->new_version;
                    $item->url          = !empty($payload->url) ? $payload->url : $this->api_base . '/plugin/wordpress';
                    $item->package      = $payload->package;
                    $item->tested       = !empty($payload->tested) ? $payload->tested : '';
                    $item->requires_php = !empty($payload->requires_php) ? $payload->requires_php : '';
                    if (!empty($payload->icons)) $item->icons = (array)$payload->icons;
                    if (!empty($payload->banners)) $item->banners = (array)$payload->banners;

                    $transient->response[$plugin_basename] = $item;
                    unset($transient->no_update[$plugin_basename]);
                }
            }

            return $transient;
        }

        public function plugin_popup_information($result, $action, $args) {
            if ($action !== 'plugin_information') return $result;
            if (empty($args->slug) || $args->slug !== $this->plugin_slug) return $result;

            $url = $this->api_base . '/plugin/wordpress/api/info?slug=' . urlencode($this->plugin_slug);
            $response = wp_remote_get($url, [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json']
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return $result;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data) && is_array($data)) {
                $info = (object) $data;
                if (!empty($data['sections'])) {
                    $info->sections = (array) $data['sections'];
                }
                if (!empty($data['banners'])) {
                    $info->banners = (array) $data['banners'];
                }
                return $info;
            }

            return $result;
        }

        public function purge_update_transient($upgrader, $options) {
            if ($options['action'] === 'update' && $options['type'] === 'plugin') {
                delete_site_transient('update_plugins');
            }
        }
    }
}
