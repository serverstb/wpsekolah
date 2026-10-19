<?php
/**
 * Endpoint penampung JSON publik: /wp-json/tampung/
 * Semua payload disimpan ke log pool di halaman Setting.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function qrg_tampung_rest_url() {
    return rest_url( 'tampung/' );
}

function qrg_tampung_log_max() {
    return 120;
}

function qrg_tampung_body_max() {
    return 16384;
}

/**
 * @return array<int,array<string,mixed>>
 */
function qrg_tampung_get_logs() {
    $logs = get_option( 'qrg_tampung_logs', [] );
    return is_array( $logs ) ? $logs : [];
}

function qrg_tampung_clear_logs() {
    update_option( 'qrg_tampung_logs', [], false );
}

/**
 * @param array<string,mixed> $entry
 */
function qrg_tampung_write_log( $entry ) {
    $logs = qrg_tampung_get_logs();
    array_unshift( $logs, $entry );
    $logs = array_slice( $logs, 0, qrg_tampung_log_max() );
    update_option( 'qrg_tampung_logs', $logs, false );
}

/**
 * @param mixed $data
 * @return string
 */
function qrg_tampung_pretty_json( $data ) {
    if ( is_string( $data ) ) {
        $trim = trim( $data );
        if ( $trim === '' ) {
            return '';
        }
        $decoded = json_decode( $trim, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            $data = $decoded;
        } else {
            return $data;
        }
    }
    $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    return is_string( $json ) ? $json : '';
}

function qrg_tampung_client_ip() {
    return function_exists( 'qrg_get_client_ip' ) ? qrg_get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
}

add_action( 'rest_api_init', 'qrg_tampung_register_rest' );
function qrg_tampung_register_rest() {
    $args = [
        'methods'             => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ],
        'callback'            => 'qrg_tampung_rest_receive',
        'permission_callback' => '__return_true',
    ];
    register_rest_route( 'tampung', '/', $args );
    register_rest_route( 'tampung', '/(?P<subpath>.*)', $args );
}

/**
 * Indeks namespace WP (`/wp-json/tampung`) hanya GET.
 * Ambil alih agar POST/PUT ke URL itu juga tertampung.
 */
add_filter( 'rest_pre_dispatch', 'qrg_tampung_pre_dispatch', 5, 3 );
function qrg_tampung_pre_dispatch( $result, $server, $request ) {
    if ( $result !== null || ! $request instanceof WP_REST_Request ) {
        return $result;
    }
    $route = untrailingslashit( (string) $request->get_route() );
    if ( $route === '/tampung' ) {
        return qrg_tampung_rest_receive( $request );
    }
    return $result;
}

add_filter( 'rest_pre_serve_request', 'qrg_tampung_cors', 15, 4 );
function qrg_tampung_cors( $served, $result, $request, $server ) {
    if ( ! $request instanceof WP_REST_Request ) {
        return $served;
    }
    $route = (string) $request->get_route();
    if ( strpos( $route, '/tampung' ) !== 0 ) {
        return $served;
    }
    if ( ! headers_sent() ) {
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Api-Key' );
        header( 'Access-Control-Max-Age: 600' );
    }
    return $served;
}

/**
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function qrg_tampung_rest_receive( WP_REST_Request $request ) {
    if ( function_exists( 'qrg_rate_limit_check' ) ) {
        $rl = qrg_rate_limit_check( 'tampung_api', 90, 60 );
        if ( is_wp_error( $rl ) ) {
            return new WP_Error( 'qrg_tampung_rate', 'Terlalu banyak permintaan. Coba lagi sebentar.', [ 'status' => 429 ] );
        }
        if ( function_exists( 'qrg_rate_limit_hit' ) ) {
            qrg_rate_limit_hit( 'tampung_api', 60 );
        }
    }

    $raw        = (string) $request->get_body();
    $truncated  = false;
    $max        = qrg_tampung_body_max();
    if ( strlen( $raw ) > $max ) {
        $raw       = substr( $raw, 0, $max );
        $truncated = true;
    }

    $payload = $request->get_json_params();
    if ( $payload === null && $raw !== '' ) {
        $decoded = json_decode( $raw, true );
        if ( json_last_error() === JSON_ERROR_NONE ) {
            $payload = $decoded;
        }
    }

    if ( $payload === null ) {
        $form = $request->get_body_params();
        if ( is_array( $form ) && ! empty( $form ) ) {
            $payload = $form;
        } elseif ( $raw !== '' ) {
            $payload = $raw;
        } else {
            $query = $request->get_query_params();
            unset( $query['subpath'] );
            $payload = ! empty( $query ) ? $query : new stdClass();
        }
    }

    $pretty = qrg_tampung_pretty_json( $payload );
    if ( strlen( $pretty ) > $max ) {
        $pretty    = substr( $pretty, 0, $max ) . "\n… (dipotong)";
        $truncated = true;
    }

    $headers = [];
    foreach ( [ 'content-type', 'user-agent', 'x-request-id', 'x-callback-id' ] as $header ) {
        $val = (string) $request->get_header( $header );
        if ( $val !== '' ) {
            $headers[ $header ] = sanitize_text_field( $val );
        }
    }

    $sub  = (string) $request->get_param( 'subpath' );
    $path = '/wp-json/tampung/' . ( $sub !== '' ? ltrim( $sub, '/' ) : '' );
    $id   = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 't', true );

    qrg_tampung_write_log( [
        'id'        => sanitize_text_field( $id ),
        'time'      => time(),
        'method'    => strtoupper( (string) $request->get_method() ),
        'path'      => sanitize_text_field( $path ),
        'ip'        => sanitize_text_field( qrg_tampung_client_ip() ),
        'headers'   => $headers,
        'body'      => $pretty,
        'truncated' => $truncated,
    ] );

    return rest_ensure_response( [
        'ok'      => true,
        'tampung' => true,
        'id'      => $id,
        'waktu'   => current_time( 'c' ),
    ] );
}

add_action( 'wp_ajax_qrg_tampung_pool', 'qrg_tampung_ajax_pool' );
function qrg_tampung_ajax_pool() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Tidak diizinkan.' ], 403 );
    }
    check_ajax_referer( 'qrg_tampung_pool', 'nonce' );
    wp_send_json_success( [
        'count' => count( qrg_tampung_get_logs() ),
        'html'  => qrg_tampung_render_pool_items(),
    ] );
}

function qrg_tampung_render_pool_items() {
    $logs = qrg_tampung_get_logs();
    ob_start();
    if ( empty( $logs ) ) {
        echo '<tr class="no-items"><td class="colspanchange" colspan="5">Belum ada data. Kirim JSON ke endpoint tampung untuk melihatnya di sini.</td></tr>';
        return ob_get_clean();
    }
    foreach ( $logs as $row ) {
        $waktu  = ! empty( $row['time'] ) ? wp_date( 'd M Y H:i:s', (int) $row['time'] ) : '—';
        $body   = (string) ( $row['body'] ?? '' );
        $method = strtoupper( (string) ( $row['method'] ?? '' ) );
        $hdrs   = isset( $row['headers'] ) && is_array( $row['headers'] ) ? $row['headers'] : [];
        ?>
        <tr>
            <td style="white-space:nowrap;"><?php echo esc_html( $waktu ); ?></td>
            <td><code><?php echo esc_html( $method ); ?></code></td>
            <td style="word-break:break-all;"><code><?php echo esc_html( (string) ( $row['path'] ?? '/wp-json/tampung/' ) ); ?></code></td>
            <td><code><?php echo esc_html( (string) ( $row['ip'] ?? '' ) ); ?></code></td>
            <td>
                <?php if ( ! empty( $row['truncated'] ) ) : ?>
                    <span class="qrg-pool__cut">Dipotong</span>
                <?php endif; ?>
                <?php if ( ! empty( $hdrs ) ) : ?>
                    <div class="qrg-pool__hdrs">
                        <?php foreach ( $hdrs as $hk => $hv ) : ?>
                            <code><?php echo esc_html( $hk ); ?>: <?php echo esc_html( (string) $hv ); ?></code>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <details>
                    <summary>Lihat JSON</summary>
                    <pre class="qrg-pool__json"><?php echo esc_html( $body !== '' ? $body : '{}' ); ?></pre>
                </details>
            </td>
        </tr>
        <?php
    }
    return ob_get_clean();
}

function qrg_tampung_handle_admin_post() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return false;
    }
    if ( isset( $_POST['qrg_clear_tampung_logs'] ) && check_admin_referer( 'qrg_tampung_logs', 'qrg_tampung_logs_nonce' ) ) {
        qrg_tampung_clear_logs();
        return true;
    }
    return false;
}

function qrg_tampung_render_settings_panel() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $url   = qrg_tampung_rest_url();
    $logs  = qrg_tampung_get_logs();
    $nonce = wp_create_nonce( 'qrg_tampung_pool' );
    ?>
    <div id="qrg-tampung-pool" class="qrg-tampung-panel" style="max-width:820px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-top:16px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <h2 style="margin:0 0 4px;">Log Pool Tampung</h2>
                <p style="margin:0;color:#646970;">Endpoint menampung segala bentuk JSON. Terbaru di atas · maks. <?php echo (int) qrg_tampung_log_max(); ?> entri.</p>
            </div>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <label style="margin:0;">
                    <input type="checkbox" id="qrg-pool-live" value="1" checked>
                    Perbarui otomatis
                </label>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=qrg-setting&log=tampung' ) ); ?>" style="margin:0;" onsubmit="return confirm('Kosongkan seluruh log pool tampung?');">
                    <?php wp_nonce_field( 'qrg_tampung_logs', 'qrg_tampung_logs_nonce' ); ?>
                    <button type="submit" name="qrg_clear_tampung_logs" class="button" <?php disabled( empty( $logs ) ); ?>>Kosongkan pool</button>
                </form>
            </div>
        </div>

        <p class="description" style="margin:14px 0 6px;">POST / PUT / PATCH / GET / DELETE</p>
        <input type="text" class="large-text code" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();">
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;font-size:12px;margin:12px 0 0;">curl -X POST "<?php echo esc_html( untrailingslashit( $url ) ); ?>/" \
  -H "Content-Type: application/json" \
  -d '{"contoh":true,"nominal":150000}'</pre>

        <table class="widefat striped" style="margin-top:16px;">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Metode</th>
                    <th>Endpoint</th>
                    <th>IP</th>
                    <th>JSON</th>
                </tr>
            </thead>
            <tbody id="qrg-pool-list">
                <?php echo qrg_tampung_render_pool_items(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </tbody>
        </table>
        <p id="qrg-pool-count" class="description" style="margin:10px 0 0;"><?php echo count( $logs ); ?> entri di pool</p>
    </div>
    <style>
        .qrg-tampung-panel details { margin-top: 4px; }
        .qrg-tampung-panel details summary { cursor: pointer; color: #2271b1; }
        .qrg-tampung-panel details summary:hover { color: #135e96; }
        .qrg-pool__json {
            margin: 8px 0 0;
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 10px 12px;
            max-height: 240px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 12px;
            line-height: 1.45;
            color: #1d2327;
        }
        .qrg-pool__hdrs { margin: 0 0 6px; display: flex; flex-wrap: wrap; gap: 6px; }
        .qrg-pool__cut {
            display: inline-block;
            margin-bottom: 4px;
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            background: #fcf9e8;
            color: #996800;
        }
    </style>
    <script>
    (function () {
        var list = document.getElementById('qrg-pool-list');
        var countEl = document.getElementById('qrg-pool-count');
        var live = document.getElementById('qrg-pool-live');
        if (!list) return;
        var timer = null;
        function tick() {
            if (!live || !live.checked) return;
            var body = new FormData();
            body.append('action', 'qrg_tampung_pool');
            body.append('nonce', '<?php echo esc_js( $nonce ); ?>');
            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success || !res.data) return;
                    list.innerHTML = res.data.html;
                    if (countEl) countEl.textContent = (res.data.count || 0) + ' entri di pool';
                })
                .catch(function () {});
        }
        function start() {
            if (timer) clearInterval(timer);
            timer = setInterval(tick, 4000);
        }
        if (live) {
            live.addEventListener('change', function () {
                if (live.checked) { tick(); start(); }
                else if (timer) clearInterval(timer);
            });
        }
        start();
    })();
    </script>
    <?php
}
