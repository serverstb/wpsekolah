<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Mulai session PHP untuk autentikasi pembayaran siswa (NIS).
 */
function qrg_pembayaran_ensure_session() {
    if ( function_exists( 'qrg_secure_session_start' ) ) {
        qrg_secure_session_start();
        return;
    }
    if ( headers_sent() ) {
        return;
    }
    if ( session_status() === PHP_SESSION_NONE ) {
        session_start();
    }
}

/**
 * ID siswa yang sudah lolos verifikasi NIS di session.
 */
function qrg_pembayaran_idle_timeout_seconds() {
    /**
     * Durasi idle (detik) sebelum auto logout portal siswa.
     * Tidak berlaku jika login dengan centang "Ingat saya".
     *
     * @param int $seconds Default 30 menit.
     */
    return max( 60, (int) apply_filters( 'qrg_portal_idle_timeout', 1800 ) );
}

function qrg_pembayaran_is_remember_me() {
    qrg_pembayaran_ensure_session();
    return ! empty( $_SESSION['qrg_pembayaran_remember'] );
}

function qrg_pembayaran_touch_activity() {
    qrg_pembayaran_ensure_session();
    if ( ! empty( $_SESSION['qrg_pembayaran_siswa_id'] ) ) {
        $_SESSION['qrg_pembayaran_last_activity'] = time();
    }
}

/**
 * Cek idle timeout; logout jika melebihi batas (kecuali "Ingat saya").
 *
 * @return bool True jika sesi baru saja diakhiri karena idle.
 */
function qrg_pembayaran_enforce_idle_timeout() {
    qrg_pembayaran_ensure_session();
    if ( empty( $_SESSION['qrg_pembayaran_siswa_id'] ) ) {
        return false;
    }
    if ( qrg_pembayaran_is_remember_me() ) {
        qrg_pembayaran_touch_activity();
        return false;
    }

    $last    = (int) ( $_SESSION['qrg_pembayaran_last_activity'] ?? 0 );
    $timeout = qrg_pembayaran_idle_timeout_seconds();
    if ( $last > 0 && ( time() - $last ) > $timeout ) {
        qrg_pembayaran_logout();
        return true;
    }

    qrg_pembayaran_touch_activity();
    return false;
}

/**
 * Apakah request ini area portal siswa (session idle dicek).
 *
 * @return bool
 */
function qrg_pembayaran_is_portal_request() {
    $section = get_query_var( 'qrg_sekolah_portal' );
    if ( $section ) {
        return true;
    }
    if ( (int) get_query_var( 'qrg_pembayaran' ) === 1 ) {
        return true;
    }
    if ( get_query_var( 'qrg_perpustakaan' ) || get_query_var( 'qrg_perpus_legacy' ) ) {
        return true;
    }
    $path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    if ( preg_match( '#/(?:sekolah|perpustakaan)(?:/|$)#i', $path ) ) {
        return true;
    }
    return false;
}

/**
 * Redirect ke login jika idle timeout pada halaman portal.
 */
function qrg_pembayaran_idle_timeout_maybe_redirect() {
    if ( ! qrg_pembayaran_is_portal_request() ) {
        return;
    }
    if ( ! qrg_pembayaran_enforce_idle_timeout() ) {
        return;
    }

    $login_url = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url()
        : home_url( '/sekolah/' );
    $login_url = add_query_arg(
        'qrg_err',
        rawurlencode( 'Sesi berakhir karena tidak aktif. Silakan masuk kembali.' ),
        $login_url
    );
    wp_safe_redirect( $login_url );
    exit;
}

add_action( 'template_redirect', 'qrg_pembayaran_idle_timeout_maybe_redirect', 4 );

/**
 * Script auto logout saat idle (client-side). Tidak dipakai jika "Ingat saya" aktif.
 */
function qrg_pembayaran_render_idle_script() {
    if ( qrg_pembayaran_get_auth_siswa_id() <= 0 ) {
        return;
    }
    if ( qrg_pembayaran_is_remember_me() ) {
        return;
    }
    $idle_sec   = qrg_pembayaran_idle_timeout_seconds();
    $logout_url = function_exists( 'qrg_get_sekolah_portal_url' )
        ? add_query_arg( [ 'qrg_logout' => '1', 'qrg_idle' => '1' ], qrg_get_sekolah_portal_url() )
        : home_url( '/sekolah/?qrg_logout=1&qrg_idle=1' );
    ?>
    <script>
    (function () {
        var timeoutMs = <?php echo (int) $idle_sec * 1000; ?>;
        var logoutUrl = <?php echo wp_json_encode( $logout_url ); ?>;
        var timer = null;
        function resetIdle() {
            if (timer) clearTimeout(timer);
            timer = setTimeout(function () { window.location.href = logoutUrl; }, timeoutMs);
        }
        ['mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(function (evt) {
            document.addEventListener(evt, resetIdle, { passive: true });
        });
        resetIdle();
    })();
    </script>
    <?php
}

function qrg_pembayaran_get_auth_siswa_id() {
    qrg_pembayaran_ensure_session();
    return isset( $_SESSION['qrg_pembayaran_siswa_id'] ) ? (int) $_SESSION['qrg_pembayaran_siswa_id'] : 0;
}

function qrg_pembayaran_set_auth_siswa_id( $siswa_id, $remember = false ) {
    qrg_pembayaran_ensure_session();
    $_SESSION['qrg_pembayaran_siswa_id']     = (int) $siswa_id;
    $_SESSION['qrg_pembayaran_remember']       = $remember ? 1 : 0;
    $_SESSION['qrg_pembayaran_last_activity']  = time();
}

function qrg_pembayaran_logout() {
    qrg_pembayaran_ensure_session();
    unset( $_SESSION['qrg_pembayaran_siswa_id'], $_SESSION['qrg_pembayaran_remember'], $_SESSION['qrg_pembayaran_last_activity'] );
}

/**
 * Cari siswa berdasarkan NIS (meta _siswa_nis).
 */
function qrg_find_siswa_by_nis( $nis ) {
    $nis = trim( (string) $nis );
    if ( $nis === '' ) {
        return 0;
    }

    $posts = get_posts( [
        'post_type'      => 'master_siswa',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_siswa_nis',
                'value' => $nis,
            ],
        ],
    ] );

    return ! empty( $posts ) ? (int) $posts[0] : 0;
}

/**
 * Verifikasi kredensial portal siswa.
 * Prioritas: PIN/password terpisah (_siswa_portal_pin hash), fallback NIS (legacy).
 */
function qrg_pembayaran_verify_nis( $siswa_id, $password ) {
    $siswa_id = (int) $siswa_id;
    $password = trim( (string) $password );
    if ( ! $siswa_id || $password === '' ) {
        return false;
    }
    if ( get_post_type( $siswa_id ) !== 'master_siswa' ) {
        return false;
    }

    $pin_hash = (string) get_post_meta( $siswa_id, '_siswa_portal_pin', true );
    if ( $pin_hash !== '' ) {
        return wp_check_password( $password, $pin_hash, $siswa_id );
    }

    // Legacy: NIS sebagai password (tetap dilindungi rate-limit di pemanggil)
    $nis = trim( (string) get_post_meta( $siswa_id, '_siswa_nis', true ) );
    if ( $nis === '' ) {
        return false;
    }

    return hash_equals( $nis, $password );
}

/**
 * URL pembayaran siswa → portal terpadu /sekolah/pembayaran/
 */
function qrg_get_siswa_pembayaran_url( $siswa_id = 0 ) {
    if ( function_exists( 'qrg_get_sekolah_portal_url' ) ) {
        return qrg_get_sekolah_portal_url( 'pembayaran' );
    }
    $post = get_post( $siswa_id );
    if ( ! $post || $post->post_type !== 'master_siswa' ) {
        return home_url( '/' );
    }
    return trailingslashit( get_permalink( $siswa_id ) ) . 'pembayaran/';
}

// Rewrite: /master_siswa/{slug}/pembayaran/
add_action( 'init', 'qrg_pembayaran_rewrite_rules' );
function qrg_pembayaran_rewrite_rules() {
    add_rewrite_rule(
        '^master_siswa/([^/]+)/pembayaran/?$',
        'index.php?master_siswa=$matches[1]&qrg_pembayaran=1',
        'top'
    );
}

add_filter( 'query_vars', 'qrg_pembayaran_query_vars' );
function qrg_pembayaran_query_vars( $vars ) {
    $vars[] = 'qrg_pembayaran';
    return $vars;
}

// Flush rewrite sekali setelah fitur ini diaktifkan
add_action( 'admin_init', 'qrg_pembayaran_flush_rewrite_once' );
function qrg_pembayaran_flush_rewrite_once() {
    if ( get_option( 'qrg_pembayaran_rewrite_flushed_v1' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'qrg_pembayaran_rewrite_flushed_v1', 1 );
}

add_action( 'init', 'qrg_pembayaran_ensure_session', 1 );

// Mencegat URL Single Post CPT Tagihan + halaman pembayaran siswa
add_action( 'template_redirect', 'qrg_intercept_tagihan_url' );
function qrg_intercept_tagihan_url() {
    // Blokir single CPT sensitif (kecuali endpoint khusus)
    if ( is_singular( 'master_guru' ) || is_singular( 'master_psb' ) ) {
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }
    if ( is_singular( 'master_siswa' ) ) {
        $is_bayar = (int) get_query_var( 'qrg_pembayaran' ) === 1;
        $is_belajar = (int) get_query_var( 'qrg_pembelajaran' ) === 1;
        if ( ! $is_bayar && ! $is_belajar ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }
    }

    // URL lama /master_siswa/{slug}/pembayaran/ → portal /sekolah
    if ( (int) get_query_var( 'qrg_pembayaran' ) === 1 && is_singular( 'master_siswa' ) ) {
        $auth_id  = qrg_pembayaran_get_auth_siswa_id();
        $siswa_id = get_queried_object_id();
        if ( $auth_id && (int) $auth_id === (int) $siswa_id && function_exists( 'qrg_get_sekolah_portal_url' ) ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'pembayaran' ) );
        } elseif ( function_exists( 'qrg_get_sekolah_portal_url' ) ) {
            wp_safe_redirect( add_query_arg( 'goto', 'pembayaran', qrg_get_sekolah_portal_url() ) );
        } else {
            qrg_render_siswa_pembayaran_page( $siswa_id );
        }
        exit;
    }

    if ( is_singular( 'master_tagihan' ) ) {
        global $post;

        $siswa_id = (int) get_post_meta( $post->ID, '_tagihan_siswa_id', true );
        $psb_id   = (int) get_post_meta( $post->ID, '_tagihan_psb_id', true );

        // Tagihan siswa: wajib login NIS pemilik.
        if ( $siswa_id && ! $psb_id ) {
            $auth_id = qrg_pembayaran_get_auth_siswa_id();
            if ( ! $auth_id || $auth_id !== $siswa_id ) {
                wp_safe_redirect( qrg_get_siswa_pembayaran_url( $siswa_id ) );
                exit;
            }
        } elseif ( $psb_id ) {
            // Tagihan PSB: wajib token akses di URL
            if ( ! function_exists( 'qrg_verify_tagihan_access_token' ) || ! qrg_verify_tagihan_access_token( $post->ID ) ) {
                status_header( 403 );
                wp_die( 'Akses invoice tidak valid. Gunakan tautan pembayaran yang diberikan saat pendaftaran.', 'Akses Ditolak', [ 'response' => 403 ] );
            }
        } else {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }

        // Proses Form Upload Bukti jika dikirim
        if ( isset( $_POST['qrg_upload_bukti'] ) && wp_verify_nonce( $_POST['qrg_bukti_nonce'], 'qrg_upload_action' ) ) {
            if ( ! empty( $_FILES['bukti_transfer']['name'] ) ) {
                $movefile = function_exists( 'qrg_handle_upload_safe' )
                    ? qrg_handle_upload_safe( $_FILES['bukti_transfer'], qrg_upload_mimes_bukti() )
                    : null;

                if ( is_array( $movefile ) && ! empty( $movefile['url'] ) && empty( $movefile['error'] ) ) {
                    update_post_meta( $post->ID, '_tagihan_bukti_url', esc_url_raw( $movefile['url'] ) );
                    if ( function_exists( 'qrg_tagihan_set_status' ) ) {
                        qrg_tagihan_set_status( $post->ID, 'Sedang Ditinjau', [
                            'via'     => 'siswa',
                            'force'   => true,
                            'catatan' => 'Unggah bukti transfer',
                        ] );
                    } else {
                        update_post_meta( $post->ID, '_tagihan_status', 'Sedang Ditinjau' );
                    }

                    $redirect = get_permalink( $post->ID );
                    if ( $psb_id && function_exists( 'qrg_get_tagihan_pay_url' ) ) {
                        $redirect = qrg_get_tagihan_pay_url( $post->ID );
                    }
                    wp_safe_redirect( $redirect );
                    exit;
                }
            }
        }

        qrg_render_invoice_page( $post->ID );
        exit;
    }
}

/**
 * Halaman pembayaran per siswa (login NIS → daftar tagihan).
 */
function qrg_render_siswa_pembayaran_page( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $error    = '';
    $auth_id  = qrg_pembayaran_get_auth_siswa_id();

    // Logout
    if ( isset( $_GET['qrg_logout'] ) && $auth_id === $siswa_id ) {
        qrg_pembayaran_logout();
        wp_safe_redirect( qrg_get_siswa_pembayaran_url( $siswa_id ) );
        exit;
    }

    // Proses login NIS / PIN portal
    if ( isset( $_POST['qrg_pembayaran_login'] ) && wp_verify_nonce( $_POST['qrg_pembayaran_nonce'], 'qrg_pembayaran_login_' . $siswa_id ) ) {
        $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'login_nis', 8, 900 ) : true;
        if ( is_wp_error( $rl ) ) {
            $error = $rl->get_error_message();
        } else {
            $password = isset( $_POST['qrg_nis_password'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_nis_password'] ) ) : '';
            if ( qrg_pembayaran_verify_nis( $siswa_id, $password ) ) {
                if ( function_exists( 'qrg_rate_limit_clear' ) ) {
                    qrg_rate_limit_clear( 'login_nis' );
                }
                qrg_pembayaran_set_auth_siswa_id( $siswa_id );
                wp_safe_redirect( qrg_get_siswa_pembayaran_url( $siswa_id ) );
                exit;
            }
            if ( function_exists( 'qrg_rate_limit_hit' ) ) {
                qrg_rate_limit_hit( 'login_nis', 900 );
            }
            $error = 'Kredensial salah. Silakan coba lagi.';
        }
    }

    $nama  = get_the_title( $siswa_id );
    $kelas = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $logged_in     = ( $auth_id === $siswa_id );
    $google_on     = function_exists( 'qrg_google_auth_enabled' ) && qrg_google_auth_enabled();
    $google_cid    = $google_on ? qrg_get_google_client_id() : '';
    $siswa_email   = trim( (string) get_post_meta( $siswa_id, '_siswa_email', true ) );
    $google_nonce  = wp_create_nonce( 'qrg_google_login_' . $siswa_id );
    $ajax_url      = admin_url( 'admin-ajax.php' );

    // Error dari redirect Google (opsional)
    if ( isset( $_GET['qrg_err'] ) ) {
        $error = sanitize_text_field( wp_unslash( $_GET['qrg_err'] ) );
    }

    status_header( 200 );
    nocache_headers();
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pembayaran — <?php echo esc_html( $nama ); ?></title>
        <?php if ( $google_on && ! $logged_in ) : ?>
            <script src="https://accounts.google.com/gsi/client" async defer></script>
        <?php endif; ?>
        <style>
            body { background:#f4f6f9; font-family:sans-serif; margin:0; padding:20px; color:#1e293b; }
            .qrg-box { max-width:720px; margin:40px auto; background:#fff; border-radius:10px; box-shadow:0 4px 14px rgba(0,0,0,.08); padding:28px 30px; }
            .qrg-box.login { max-width:380px; }
            h1 { margin:0 0 6px; font-size:22px; }
            .sub { color:#64748b; margin:0 0 22px; font-size:14px; }
            .err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:10px 12px; border-radius:6px; margin-bottom:16px; font-size:14px; }
            .btn { display:inline-block; background:#2271b1; color:#fff; border:none; padding:12px 18px; border-radius:6px; font-size:14px; font-weight:700; cursor:pointer; text-decoration:none; }
            .btn-green { background:#16a34a; }
            .btn-muted { background:#64748b; }
            table { width:100%; border-collapse:collapse; font-size:14px; margin-top:10px; }
            th { background:#0073aa; color:#fff; text-align:left; padding:10px; }
            td { padding:10px; border-bottom:1px solid #e2e8f0; }
            .total { color:#d32f2f; font-weight:700; }
            .topbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
            .qrg-google-wrap { text-align:center; margin-bottom:18px; }
            .qrg-google-wrap .g_id_signin { display:inline-flex !important; justify-content:center; }
            .qrg-google-status { font-size:13px; color:#64748b; margin-top:10px; min-height:18px; }
            .qrg-divider { display:flex; align-items:center; gap:10px; color:#94a3b8; font-size:12px; margin:18px 0; }
            .qrg-divider::before, .qrg-divider::after { content:""; flex:1; height:1px; background:#e2e8f0; }
            .qrg-nis-toggle { display:block; text-align:center; font-size:13px; color:#2271b1; cursor:pointer; margin-bottom:12px; background:none; border:none; text-decoration:underline; }
            .qrg-nis-panel { display:none; }
            .qrg-nis-panel.open { display:block; }
            /* Kalkulator NIS */
            .qrg-calc { max-width:300px; margin:0 auto; }
            .qrg-calc-screen {
                background:#0f172a; color:#e2e8f0; border-radius:12px; padding:18px 16px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size:28px; letter-spacing:6px; text-align:right; min-height:36px;
                margin-bottom:14px; box-shadow:inset 0 2px 8px rgba(0,0,0,.35);
                word-break:break-all; line-height:1.2;
            }
            .qrg-calc-screen.empty { color:#64748b; letter-spacing:2px; font-size:15px; text-align:center; }
            .qrg-calc-hint { text-align:center; font-size:13px; color:#64748b; margin:0 0 14px; }
            .qrg-calc-pad {
                display:grid; grid-template-columns:repeat(3, 1fr); gap:10px;
            }
            .qrg-calc-pad button {
                appearance:none; border:none; border-radius:12px; padding:18px 0;
                font-size:22px; font-weight:700; cursor:pointer;
                background:#e2e8f0; color:#0f172a;
                box-shadow:0 2px 0 #94a3b8; transition:transform .05s, background .15s;
                user-select:none; -webkit-tap-highlight-color:transparent;
            }
            .qrg-calc-pad button:active { transform:translateY(2px); box-shadow:none; }
            .qrg-calc-pad button.key-fn { background:#cbd5e1; color:#334155; font-size:16px; }
            .qrg-calc-pad button.key-ok {
                background:#16a34a; color:#fff; box-shadow:0 2px 0 #15803d;
                grid-column:1 / -1; font-size:16px; letter-spacing:.5px;
            }
            .qrg-calc-pad button.key-ok:disabled { opacity:.45; cursor:not-allowed; }
        </style>
    </head>
    <body>
        <div class="qrg-box<?php echo $logged_in ? '' : ' login'; ?>">
            <?php if ( ! $logged_in ) : ?>
                <h1 style="text-align:center;">Login Pembayaran</h1>
                <p class="sub" style="text-align:center;">Siswa: <strong><?php echo esc_html( $nama ); ?></strong><?php echo $kelas ? ' · Kelas ' . esc_html( $kelas ) : ''; ?></p>
                <?php if ( $error ) : ?>
                    <div class="err" id="qrg-login-err"><?php echo esc_html( $error ); ?></div>
                <?php else : ?>
                    <div class="err" id="qrg-login-err" style="display:none;"></div>
                <?php endif; ?>

                <?php if ( $google_on ) : ?>
                    <div class="qrg-google-wrap">
                        <?php if ( $siswa_email === '' ) : ?>
                            <div class="err">Email siswa belum diisi di Data Siswa. Login Google belum bisa dipakai.</div>
                        <?php else : ?>
                            <p style="font-size:13px; color:#64748b; margin:0 0 12px;">Masuk dengan akun Google yang emailnya sama dengan siswa.</p>
                            <div id="qrg-google-btn"></div>
                            <div class="qrg-google-status" id="qrg-google-status"></div>
                        <?php endif; ?>
                    </div>
                    <div class="qrg-divider">atau</div>
                    <button type="button" class="qrg-nis-toggle" id="qrg-nis-toggle">Login dengan NIS</button>
                <?php endif; ?>

                <div class="qrg-nis-panel<?php echo $google_on ? '' : ' open'; ?>" id="qrg-nis-panel">
                    <form method="post" id="qrg-calc-form" class="qrg-calc" autocomplete="off">
                        <?php wp_nonce_field( 'qrg_pembayaran_login_' . $siswa_id, 'qrg_pembayaran_nonce' ); ?>
                        <input type="hidden" name="qrg_nis_password" id="qrg_nis_password" value="" required>
                        <input type="hidden" name="qrg_pembayaran_login" value="1">
                        <p class="qrg-calc-hint">Masukkan NIS dengan keypad</p>
                        <div class="qrg-calc-screen empty" id="qrg-calc-screen" aria-live="polite">NIS</div>
                        <div class="qrg-calc-pad" role="group" aria-label="Keypad NIS">
                            <button type="button" data-key="1">1</button>
                            <button type="button" data-key="2">2</button>
                            <button type="button" data-key="3">3</button>
                            <button type="button" data-key="4">4</button>
                            <button type="button" data-key="5">5</button>
                            <button type="button" data-key="6">6</button>
                            <button type="button" data-key="7">7</button>
                            <button type="button" data-key="8">8</button>
                            <button type="button" data-key="9">9</button>
                            <button type="button" class="key-fn" data-key="clear" title="Hapus semua">C</button>
                            <button type="button" data-key="0">0</button>
                            <button type="button" class="key-fn" data-key="back" title="Hapus satu">⌫</button>
                            <button type="submit" class="key-ok" id="qrg-calc-ok" disabled>Masuk</button>
                        </div>
                    </form>
                </div>

                <script>
                (function () {
                    var toggle = document.getElementById('qrg-nis-toggle');
                    var panel = document.getElementById('qrg-nis-panel');
                    if (toggle && panel) {
                        toggle.addEventListener('click', function () {
                            panel.classList.toggle('open');
                            toggle.textContent = panel.classList.contains('open')
                                ? 'Sembunyikan login NIS'
                                : 'Login dengan NIS';
                        });
                    }

                    var input = document.getElementById('qrg_nis_password');
                    var screen = document.getElementById('qrg-calc-screen');
                    var okBtn = document.getElementById('qrg-calc-ok');
                    var form = document.getElementById('qrg-calc-form');
                    if (!input || !screen || !okBtn || !form) return;
                    var value = '';

                    function render() {
                        input.value = value;
                        if (!value) {
                            screen.textContent = 'NIS';
                            screen.classList.add('empty');
                        } else {
                            screen.textContent = value.replace(/./g, '•');
                            screen.classList.remove('empty');
                        }
                        okBtn.disabled = value.length === 0;
                    }

                    function press(key) {
                        if (key === 'clear') {
                            value = '';
                        } else if (key === 'back') {
                            value = value.slice(0, -1);
                        } else if (/^[0-9]$/.test(key) && value.length < 20) {
                            value += key;
                        }
                        render();
                    }

                    form.querySelectorAll('.qrg-calc-pad button[data-key]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            press(btn.getAttribute('data-key'));
                        });
                    });

                    document.addEventListener('keydown', function (e) {
                        if (!panel || !panel.classList.contains('open')) return;
                        if (e.key >= '0' && e.key <= '9') {
                            e.preventDefault();
                            press(e.key);
                        } else if (e.key === 'Backspace') {
                            e.preventDefault();
                            press('back');
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            press('clear');
                        } else if (e.key === 'Enter' && value.length) {
                            e.preventDefault();
                            form.submit();
                        }
                    });

                    render();
                })();
                </script>

                <?php if ( $google_on && $siswa_email !== '' ) : ?>
                <script>
                window.qrgHandleGoogleCredential = function (response) {
                    var errBox = document.getElementById('qrg-login-err');
                    var status = document.getElementById('qrg-google-status');
                    if (status) status.textContent = 'Memverifikasi akun Google...';
                    if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }

                    var body = new FormData();
                    body.append('action', 'qrg_google_pembayaran_login');
                    body.append('siswa_id', '<?php echo (int) $siswa_id; ?>');
                    body.append('credential', response.credential || '');
                    body.append('nonce', '<?php echo esc_js( $google_nonce ); ?>');
                    body.append('redirect', '<?php echo esc_js( qrg_get_siswa_pembayaran_url( $siswa_id ) ); ?>');

                    fetch('<?php echo esc_url( $ajax_url ); ?>', {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin'
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        if (data && data.success && data.data && data.data.redirect) {
                            if (status) status.textContent = 'Berhasil. Mengalihkan...';
                            window.location.href = data.data.redirect;
                            return;
                        }
                        var msg = (data && data.data && data.data.message) ? data.data.message : 'Login Google gagal.';
                        if (errBox) { errBox.textContent = msg; errBox.style.display = 'block'; }
                        if (status) status.textContent = '';
                    }).catch(function () {
                        if (errBox) { errBox.textContent = 'Koneksi gagal. Coba lagi.'; errBox.style.display = 'block'; }
                        if (status) status.textContent = '';
                    });
                };

                function qrgInitGoogle() {
                    if (!window.google || !google.accounts || !google.accounts.id) {
                        setTimeout(qrgInitGoogle, 200);
                        return;
                    }
                    google.accounts.id.initialize({
                        client_id: '<?php echo esc_js( $google_cid ); ?>',
                        callback: window.qrgHandleGoogleCredential,
                        auto_select: false,
                        cancel_on_tap_outside: true
                    });
                    google.accounts.id.renderButton(
                        document.getElementById('qrg-google-btn'),
                        { theme: 'outline', size: 'large', shape: 'rectangular', text: 'signin_with', width: 320 }
                    );
                }
                qrgInitGoogle();
                </script>
                <?php endif; ?>
            <?php else : ?>
                <div class="topbar">
                    <div>
                        <h1>Pembayaran <?php echo esc_html( $nama ); ?></h1>
                        <p class="sub" style="margin:0;"><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : ''; ?></p>
                    </div>
                    <a class="btn btn-muted" href="<?php echo esc_url( add_query_arg( 'qrg_logout', '1', qrg_get_siswa_pembayaran_url( $siswa_id ) ) ); ?>">Keluar</a>
                </div>
                <?php echo qrg_render_tagihan_table_for_siswa( $siswa_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

/**
 * Tabel tagihan aktif milik satu siswa.
 */
function qrg_render_tagihan_table_for_siswa( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $tagihan_loop = new WP_Query( [
        'post_type'      => 'master_tagihan',
        'posts_per_page' => -1,
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => '_tagihan_siswa_id',
                'value' => $siswa_id,
            ],
            [
                'key'     => '_tagihan_status',
                'value'   => [ 'Belum Dibayar', 'Sedang Ditinjau', 'Lunas' ],
                'compare' => 'IN',
            ],
        ],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    ob_start();
    ?>
    <div class="qrg-tagihan-wrap">
    <table class="qrg-tagihan-table">
        <thead>
            <tr>
                <th>Keterangan / Inv</th>
                <th>Status</th>
                <th>Total</th>
                <th style="text-align:center;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ( $tagihan_loop->have_posts() ) {
                while ( $tagihan_loop->have_posts() ) {
                    $tagihan_loop->the_post();
                    $t_id     = get_the_ID();
                    $nominal  = (int) get_post_meta( $t_id, '_tagihan_nominal', true );
                    $biaya_id = get_post_meta( $t_id, '_tagihan_biaya_tambahan_id', true );
                    $status   = get_post_meta( $t_id, '_tagihan_status', true );
                    $total    = $nominal;
                    if ( $biaya_id ) {
                        $total += (int) get_post_meta( $biaya_id, '_biaya_nominal', true );
                    }
                    $permalink = function_exists( 'qrg_get_tagihan_bayar_url' )
                        ? qrg_get_tagihan_bayar_url( $t_id )
                        : get_permalink( $t_id );
                    ?>
                    <tr>
                        <td data-label="Tagihan"><?php the_title(); ?></td>
                        <td data-label="Status">
                            <?php if ( $status === 'Lunas' ) : ?>
                                <span style="display:inline-block; background:#dcfce7; color:#166534; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:700;">✓ Lunas</span>
                            <?php elseif ( $status === 'Sedang Ditinjau' ) : ?>
                                <span style="display:inline-block; background:#dbeafe; color:#1d4ed8; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:700;">Proses</span>
                            <?php else : ?>
                                <span style="display:inline-block; background:#fef3c7; color:#92400e; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:700;">Belum Dibayar</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Total" class="total">Rp <?php echo esc_html( number_format( $total, 0, ',', '.' ) ); ?></td>
                        <td data-label="">
                            <?php if ( $status === 'Belum Dibayar' ) : ?>
                                <a class="btn btn-green" href="<?php echo esc_url( $permalink ); ?>" style="padding:10px 14px; font-size:13px;">Bayar Sekarang</a>
                            <?php else : ?>
                                <a class="btn" href="<?php echo esc_url( $permalink ); ?>" style="padding:10px 14px; font-size:13px;">Lihat Status</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                }
                wp_reset_postdata();
            } else {
                echo '<tr><td class="qrg-empty" colspan="4" data-label="">Belum ada data pembayaran untuk siswa ini.</td></tr>';
            }
            ?>
        </tbody>
    </table>
    </div>
    <?php
    return ob_get_clean();
}

// Shortcode Daftar Tagihan [qr_checkout] — login NIS dulu
add_shortcode( 'qr_checkout', 'qrg_render_frontend_billing' );
function qrg_render_frontend_billing() {
    $error   = '';
    $auth_id = qrg_pembayaran_get_auth_siswa_id();

    // Logout dari shortcode
    if ( isset( $_GET['qrg_checkout_logout'] ) ) {
        qrg_pembayaran_logout();
        $auth_id = 0;
    }

    // Login via NIS (cari siswa berdasarkan NIS) + rate limit
    if ( isset( $_POST['qrg_checkout_login'] ) && wp_verify_nonce( $_POST['qrg_checkout_nonce'], 'qrg_checkout_login' ) ) {
        $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'login_nis', 8, 900 ) : true;
        if ( is_wp_error( $rl ) ) {
            $error = $rl->get_error_message();
            $auth_id = 0;
        } else {
            $nis = isset( $_POST['qrg_checkout_nis'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_checkout_nis'] ) ) : '';
            $siswa_id = qrg_find_siswa_by_nis( $nis );
            if ( $siswa_id && qrg_pembayaran_verify_nis( $siswa_id, $nis ) ) {
                if ( function_exists( 'qrg_rate_limit_clear' ) ) {
                    qrg_rate_limit_clear( 'login_nis' );
                }
                qrg_pembayaran_set_auth_siswa_id( $siswa_id );
                wp_safe_redirect( qrg_get_siswa_pembayaran_url( $siswa_id ) );
                exit;
            }
            if ( function_exists( 'qrg_rate_limit_hit' ) ) {
                qrg_rate_limit_hit( 'login_nis', 900 );
            }
            $error = 'Kredensial salah. Silakan coba lagi.';
            $auth_id = 0;
        }
    }

    ob_start();
    ?>
    <div class="qrg-frontend-container" style="max-width:700px; margin:20px auto; font-family:sans-serif;">
        <?php if ( ! $auth_id ) : ?>
            <h3 style="border-bottom:2px solid #0073aa; padding-bottom:8px;">Login Lihat Tagihan</h3>
            <p style="color:#555; font-size:14px;">Masukkan <strong>NIS</strong> siswa untuk melihat tagihan. Tagihan tidak ditampilkan secara publik.</p>
            <?php if ( $error ) : ?>
                <div style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:10px 12px; border-radius:6px; margin-bottom:14px; font-size:14px;">
                    <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>
            <form method="post" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:18px 20px;">
                <?php wp_nonce_field( 'qrg_checkout_login', 'qrg_checkout_nonce' ); ?>
                <label for="qrg_checkout_nis" style="display:block; font-weight:600; margin-bottom:6px;">NIS Siswa</label>
                <input type="password" id="qrg_checkout_nis" name="qrg_checkout_nis" required
                    placeholder="Masukkan NIS"
                    style="width:100%; max-width:320px; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15px;">
                <p style="margin:14px 0 0;">
                    <button type="submit" name="qrg_checkout_login" value="1"
                        style="background:#2271b1; color:#fff; border:none; padding:10px 16px; border-radius:6px; font-weight:700; cursor:pointer;">
                        Masuk
                    </button>
                </p>
            </form>
        <?php else : ?>
            <?php
            $nama  = get_the_title( $auth_id );
            $kelas = function_exists( 'qrg_get_kelas_label' )
                ? qrg_get_kelas_label( get_post_meta( $auth_id, '_siswa_kelas', true ) )
                : '';
            $logout_url = add_query_arg( 'qrg_checkout_logout', '1' );
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; border-bottom:2px solid #0073aa; padding-bottom:8px; margin-bottom:12px;">
                <div>
                    <h3 style="margin:0;">Tagihan <?php echo esc_html( $nama ); ?></h3>
                    <p style="margin:4px 0 0; color:#64748b; font-size:13px;"><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : ''; ?></p>
                </div>
                <a href="<?php echo esc_url( $logout_url ); ?>" style="background:#64748b; color:#fff; text-decoration:none; padding:8px 12px; border-radius:4px; font-size:12px; font-weight:700;">Keluar</a>
            </div>
            <?php echo qrg_render_tagihan_table_for_siswa( $auth_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Render Halaman Invoice Publik (siswa setelah login NIS, atau pendaftar PSB via link)
function qrg_render_invoice_page( $tagihan_id, $opts = [] ) {
    $tagihan_id = (int) $tagihan_id;
    $opts       = is_array( $opts ) ? $opts : [];
    $siswa_id   = get_post_meta( $tagihan_id, '_tagihan_siswa_id', true );
    $psb_id     = (int) get_post_meta( $tagihan_id, '_tagihan_psb_id', true );
    $jalur_id   = (int) get_post_meta( $tagihan_id, '_tagihan_jalur_id', true );
    $nominal    = get_post_meta( $tagihan_id, '_tagihan_nominal', true );
    $biaya_id   = get_post_meta( $tagihan_id, '_tagihan_biaya_tambahan_id', true );
    $keterangan = get_post_meta( $tagihan_id, '_tagihan_keterangan', true );
    $status     = get_post_meta( $tagihan_id, '_tagihan_status', true );

    $is_psb = $psb_id > 0;
    $portal = ! empty( $opts['portal'] ) && ! $is_psb && function_exists( 'qrg_sekolah_portal_shell_start' );

    if ( $is_psb ) {
        $nama_siswa = get_the_title( $psb_id );
        $kelas      = $jalur_id && function_exists( 'qrg_get_jalur_label' )
            ? ( 'Jalur: ' . qrg_get_jalur_label( $jalur_id ) )
            : 'Pendaftaran Siswa Baru';
        $token      = get_post_meta( $psb_id, '_psb_token', true );
        $back_url   = home_url( '/' );
    } else {
        $nama_siswa = get_the_title( $siswa_id );
        $kelas      = qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) );
        $token      = '';
        $back_url   = qrg_get_siswa_pembayaran_url( $siswa_id );
    }

    $invoice_title = get_the_title( $tagihan_id );

    $biaya_nama    = '';
    $biaya_nominal = 0;
    $total         = intval( $nominal );

    if ( $biaya_id ) {
        $biaya_nama    = get_the_title( $biaya_id );
        $biaya_nominal = intval( get_post_meta( $biaya_id, '_biaya_nominal', true ) );
        $total        += $biaya_nominal;
    }

    $formatted_amount = 'Rp ' . number_format( $total, 0, ',', '.' );

    $bank_on     = function_exists( 'qrg_bank_transfer_enabled' ) && qrg_bank_transfer_enabled();
    $bank_name   = $bank_on ? qrg_bank_transfer_name() : '';
    $bank_acc    = $bank_on ? qrg_bank_transfer_account() : '';
    $bank_holder = $bank_on ? qrg_bank_transfer_holder() : '';
    $tf_info     = ( $bank_on && function_exists( 'qrg_get_tagihan_transfer_info' ) )
        ? qrg_get_tagihan_transfer_info( $tagihan_id, $total )
        : [ 'kode' => '', 'jumlah' => $total, 'referensi' => '' ];
    $tf_amount   = 'Rp ' . number_format( (int) $tf_info['jumlah'], 0, ',', '.' );

    $qr_meta = function_exists( 'qrg_build_invoice_pembayaran_qr' )
        ? qrg_build_invoice_pembayaran_qr( $total )
        : null;
    if ( is_array( $qr_meta ) && ! empty( $qr_meta['url'] ) ) {
        $qr_code_url  = (string) $qr_meta['url'];
        $qr_fallback  = (string) ( $qr_meta['fallback'] ?: $qr_meta['url'] );
        $qr_is_dynamic = ! empty( $qr_meta['dynamic'] );
        $qr_merchant  = (string) ( $qr_meta['merchant'] ?? '' );
    } else {
        $uploaded_qr = function_exists( 'qrg_get_pembayaran_qr_url' ) ? qrg_get_pembayaran_qr_url( 'full' ) : '';
        if ( $uploaded_qr ) {
            $qr_code_url   = $uploaded_qr;
            $qr_fallback   = $uploaded_qr;
            $qr_is_dynamic = false;
            $qr_merchant   = '';
        } else {
            $qris_raw_data = function_exists( 'qrg_get_default_pembayaran_qr_payload' )
                ? qrg_get_default_pembayaran_qr_payload()
                : 'hQVDUFYwMWFbTwegAAAGAiAgUAdRUklTQ1BNWgqTYAkVMFmDiUdPXyAOUllBTiBERVdBTlRPUk9fLQJpZF9QB0RBTkEuSURjG590GDI4MTAxMjAyMTMwOTg1OTUwMzQxNjI3MA==';
            $qr_payload    = rawurlencode( $qris_raw_data );
            $qr_code_url   = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . $qr_payload;
            $qr_fallback   = 'https://quickchart.io/qr?size=300&text=' . $qr_payload;
            $qr_is_dynamic = false;
            $qr_merchant   = '';
        }
    }
    if ( ! $is_psb && function_exists( 'qrg_get_sekolah_portal_url' ) ) {
        $back_url = qrg_get_sekolah_portal_url( 'pembayaran' );
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <meta name="theme-color" content="#0369a1">
        <title>Invoice - <?php echo esc_html( $invoice_title ); ?></title>
        <style>
            *, *::before, *::after { box-sizing: border-box; }
            body { background: #e8eef4; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 12px; padding-bottom: max(16px, env(safe-area-inset-bottom)); color: #0f172a; }
            .qrg-invoice-wrapper { max-width: 500px; margin: 12px auto; padding: 20px 18px; border-radius: 16px; background: #fff; box-shadow: 0 4px 24px rgba(15,23,42,.08); }
            table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
            td { padding: 8px 0; font-size: 14px; vertical-align: top; }
            .text-right { text-align: right; }
            .total-row { border-top: 2px solid #eee; font-size: 18px; font-weight: bold; }
            .qr-box { text-align: center; background: #f8fafc; padding: 18px 14px; border-radius: 12px; border: 1px solid #e2e8f0; margin-top: 12px; }
            .qr-box img { max-width: 240px; width: 100%; height: auto; margin: 8px auto 12px; border: 1px solid #e2e8f0; padding: 10px; background: #fff; border-radius: 8px; display: block; }
            .instruksi-box { background: #fffde7; border-left: 4px solid #fdd835; padding: 12px; font-size: 13px; text-align: left; margin-top: 12px; line-height: 1.5; color:#444; border-radius: 0 8px 8px 0; }
            .status-badge { padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: bold; display: inline-block; }
            .back-link { display: inline-flex; align-items: center; min-height: 40px; margin-bottom: 8px; font-size: 14px; color: #0369a1; text-decoration: none; font-weight: 600; }
            input[type=file] { width: 100%; font-size: 14px; margin: 8px 0; }
            input[type=submit] {
                background: #0369a1; color: #fff; border: none; padding: 12px 14px; border-radius: 10px;
                cursor: pointer; font-size: 14px; font-weight: 700; width: 100%; min-height: 44px;
            }
            .qrg-up {
                background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
                border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; text-align: left; margin-top: 16px;
            }
            .qrg-up__title { margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0f172a; }
            .qrg-up__hint { margin: 0 0 14px; font-size: 12px; color: #64748b; line-height: 1.4; }
            .qrg-up__drop {
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                gap: 8px; min-height: 140px; padding: 18px 14px; cursor: pointer;
                border: 2px dashed #94a3b8; border-radius: 14px; background: #fff;
                transition: border-color .15s, background .15s, transform .15s;
                -webkit-tap-highlight-color: transparent; text-align: center;
            }
            .qrg-up__drop:active, .qrg-up__drop.is-drag { border-color: #0369a1; background: #f0f9ff; transform: scale(.99); }
            .qrg-up__drop.has-file { border-style: solid; border-color: #86efac; background: #f0fdf4; }
            .qrg-up__ico {
                width: 48px; height: 48px; border-radius: 14px;
                background: #e0f2fe; color: #0369a1;
                display: flex; align-items: center; justify-content: center; font-size: 22px;
            }
            .qrg-up__drop-title { font-size: 14px; font-weight: 700; color: #0f172a; }
            .qrg-up__drop-sub { font-size: 12px; color: #64748b; }
            .qrg-up__file { display: none; }
            .qrg-up__preview {
                display: none; margin-top: 12px; border-radius: 12px; overflow: hidden;
                border: 1px solid #e2e8f0; background: #fff;
            }
            .qrg-up__preview.is-on { display: block; }
            .qrg-up__preview img { display: block; width: 100%; max-height: 220px; object-fit: contain; background: #0f172a; }
            .qrg-up__meta {
                display: flex; justify-content: space-between; align-items: center; gap: 8px;
                padding: 10px 12px; font-size: 12px; color: #475569;
            }
            .qrg-up__meta strong { color: #0f172a; word-break: break-all; }
            .qrg-up__change {
                appearance: none; border: none; background: none; color: #0369a1;
                font-size: 12px; font-weight: 700; cursor: pointer; padding: 6px 0; min-height: 36px;
            }
            .qrg-up__submit {
                appearance: none; border: none; width: 100%; margin-top: 14px;
                background: #0369a1; color: #fff; border-radius: 12px;
                padding: 14px 16px; font-size: 15px; font-weight: 700; min-height: 48px;
                cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
                -webkit-tap-highlight-color: transparent;
            }
            .qrg-up__submit:disabled { opacity: .55; cursor: not-allowed; }
            .qrg-up__submit.is-ready { background: #15803d; }

            /* Upload loading overlay */
            .qrg-up-load {
                position: fixed; inset: 0; z-index: 100000;
                display: flex; align-items: flex-end; justify-content: center;
                background: rgba(15, 23, 42, .45);
                backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
                opacity: 0; visibility: hidden; pointer-events: none;
                transition: opacity .22s ease, visibility .22s ease;
                padding: 16px; padding-bottom: max(16px, env(safe-area-inset-bottom));
            }
            .qrg-up-load.is-on { opacity: 1; visibility: visible; pointer-events: auto; }
            .qrg-up-load__sheet {
                width: min(420px, 100%); background: #fff; border-radius: 20px 20px 16px 16px;
                padding: 22px 20px 20px; text-align: center;
                box-shadow: 0 -8px 40px rgba(15,23,42,.2);
                transform: translateY(24px);
                transition: transform .28s cubic-bezier(.22,1,.36,1);
            }
            .qrg-up-load.is-on .qrg-up-load__sheet { transform: translateY(0); }
            .qrg-up-load__orb {
                width: 64px; height: 64px; margin: 0 auto 14px; position: relative;
            }
            .qrg-up-load__orb::before {
                content: ""; position: absolute; inset: 0; border-radius: 50%;
                border: 3px solid #e2e8f0; border-top-color: #0369a1;
                animation: qrgUpSpin .75s linear infinite;
            }
            .qrg-up-load__cloud {
                position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
                font-size: 22px; animation: qrgUpBounce 1.1s ease-in-out infinite;
            }
            .qrg-up-load__title { margin: 0 0 4px; font-size: 16px; font-weight: 700; color: #0f172a; }
            .qrg-up-load__tip { margin: 0 0 14px; font-size: 13px; color: #64748b; min-height: 36px; line-height: 1.4; }
            .qrg-up-load__bar {
                height: 8px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-bottom: 10px;
            }
            .qrg-up-load__bar > span {
                display: block; height: 100%; width: 18%; border-radius: 999px;
                background: linear-gradient(90deg, #0369a1, #38bdf8, #0369a1);
                background-size: 200% 100%;
                animation: qrgUpBar 1.4s ease-in-out infinite;
            }
            .qrg-up-load__pct { font-size: 12px; font-weight: 700; color: #0369a1; }
            .qrg-up-load__steps {
                display: flex; justify-content: space-between; gap: 6px; margin-top: 14px; font-size: 10px; color: #94a3b8;
            }
            .qrg-up-load__steps span.is-active { color: #0369a1; font-weight: 700; }
            .qrg-up-load__steps span.is-done { color: #15803d; }
            @keyframes qrgUpSpin { to { transform: rotate(360deg); } }
            @keyframes qrgUpBounce {
                0%, 100% { transform: translateY(0); }
                50% { transform: translateY(-4px); }
            }
            @keyframes qrgUpBar {
                0% { width: 12%; margin-left: 0; background-position: 0% 0; }
                50% { width: 55%; margin-left: 20%; background-position: 100% 0; }
                100% { width: 12%; margin-left: 88%; background-position: 0% 0; }
            }

            .qrg-pay-tabs {
                display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 16px 0 12px;
            }
            .qrg-pay-tab {
                appearance: none; border: 1px solid #e2e8f0; background: #f8fafc; color: #475569;
                border-radius: 12px; padding: 12px 10px; min-height: 48px; font: inherit; font-size: 13px;
                font-weight: 700; cursor: pointer;
            }
            .qrg-pay-tab.is-on { background: #0369a1; border-color: #0369a1; color: #fff; }
            .qrg-pay-panel { display: none; }
            .qrg-pay-panel.is-on { display: block; }
            .qrg-bank {
                background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 14px; text-align: left;
            }
            .qrg-bank__row {
                display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;
                padding: 10px 0; border-bottom: 1px solid #e2e8f0; font-size: 13px;
            }
            .qrg-bank__row:last-child { border-bottom: 0; }
            .qrg-bank__label { color: #64748b; }
            .qrg-bank__val {
                font-weight: 700; text-align: right; word-break: break-all;
                display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 6px;
            }
            .qrg-bank__val--code { font-size: 20px; letter-spacing: .08em; color: #0369a1; font-variant-numeric: tabular-nums; }
            .qrg-bank__copy {
                appearance: none; border: 0; background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 700;
                border-radius: 8px; padding: 6px 10px; cursor: pointer; flex-shrink: 0;
            }
            .qrg-bank__amount { font-size: 22px; color: #d32f2f; }
            @media (prefers-reduced-motion: reduce) {
                .qrg-up-load__orb::before, .qrg-up-load__cloud, .qrg-up-load__bar > span { animation: none; }
            }
            @media (max-width: 640px) {
                body { background: #fff; padding: 0; }
                .qrg-invoice-wrapper { margin: 0; border-radius: 0; box-shadow: none; min-height: 100dvh; padding: 16px 16px calc(20px + env(safe-area-inset-bottom)); }
                .qrg-up-load { align-items: flex-end; }
            }
        </style>
    </head>
    <body>
        <div class="qrg-invoice-wrapper">
            <?php if ( ! $is_psb ) : ?>
            <a class="back-link" href="<?php echo esc_url( $back_url ); ?>">&larr; Kembali ke daftar pembayaran</a>
            <?php endif; ?>
            <div style="text-align: center;">
                <h2 style="margin:8px 0 6px;font-size:1.25rem;">INVOICE PEMBAYARAN</h2>
                <p style="margin:0 0 8px;font-size:13px;color:#64748b;">ID: <strong style="color:#0f172a;"><?php echo esc_html( $invoice_title ); ?></strong></p>
            </div>
            <hr style="border:0; border-top:1px solid #eee;">
            <table>
                <tr>
                    <td><?php echo $is_psb ? 'Nama Pendaftar:' : 'Nama Siswa:'; ?></td>
                    <td class="text-right" style="font-weight:bold;"><?php echo esc_html( $nama_siswa ); ?></td>
                </tr>
                <tr>
                    <td><?php echo $is_psb ? 'Jalur:' : 'Kelas:'; ?></td>
                    <td class="text-right"><?php echo esc_html( $is_psb ? ( $jalur_id && function_exists( 'qrg_get_jalur_label' ) ? qrg_get_jalur_label( $jalur_id ) : '—' ) : $kelas ); ?></td>
                </tr>
                <?php if ( $is_psb && $token ) : ?>
                <tr>
                    <td>Token Registrasi:</td>
                    <td class="text-right" style="font-weight:bold;"><?php echo esc_html( $token ); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ( $keterangan ) : ?>
                <tr>
                    <td>Keterangan:</td>
                    <td class="text-right"><?php echo nl2br( esc_html( $keterangan ) ); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>Status:</td>
                    <td class="text-right">
                        <?php
                        if ( $status === 'Lunas' ) {
                            echo '<span class="status-badge" style="background:#e8f5e9; color:#2e7d32;">Lunas</span>';
                        } elseif ( $status === 'Sedang Ditinjau' ) {
                            echo '<span class="status-badge" style="background:#e3f2fd; color:#1565c0;">Sedang Ditinjau (Proses Cek)</span>';
                        } else {
                            echo '<span class="status-badge" style="background:#fff8e1; color:#b78103;">Belum Dibayar</span>';
                        }
                        ?>
                    </td>
                </tr>
                <tr style="border-top: 1px solid #eee;">
                    <td style="padding-top:10px; color: #555;">Tagihan Utama:</td>
                    <td class="text-right" style="padding-top:10px; color: #555;">Rp <?php echo number_format( $nominal, 0, ',', '.' ); ?></td>
                </tr>
                <?php if ( $biaya_id ) : ?>
                <tr>
                    <td style="color: #555;">Biaya Tambahan (<?php echo esc_html( $biaya_nama ); ?>):</td>
                    <td class="text-right" style="color: #555;">Rp <?php echo number_format( $biaya_nominal, 0, ',', '.' ); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td style="padding-top:15px;">Total:</td>
                    <td class="text-right" style="padding-top:15px; color:#d32f2f; font-weight:bold;"><?php echo esc_html( $formatted_amount ); ?></td>
                </tr>
            </table>

            <?php if ( $status === 'Belum Dibayar' ) : ?>
                <?php if ( $bank_on ) : ?>
                <div class="qrg-pay-tabs" role="tablist">
                    <button type="button" class="qrg-pay-tab is-on" data-pay="qris">QRIS</button>
                    <button type="button" class="qrg-pay-tab" data-pay="bank">Transfer Rekening</button>
                </div>
                <?php endif; ?>

                <div class="qrg-pay-panel is-on" data-pay-panel="qris">
                <div class="qr-box">
                    <p style="margin:0;"><strong>Pindai QRIS Pembayaran Di Bawah Ini:</strong></p>
                    <?php if ( ! empty( $qr_is_dynamic ) ) : ?>
                        <p style="margin:8px 0 0;font-size:12px;color:#166534;font-weight:600;">
                            QRIS dinamis · nominal otomatis <?php echo esc_html( $formatted_amount ); ?>
                            <?php if ( $qr_merchant !== '' ) : ?>
                                · <?php echo esc_html( $qr_merchant ); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <img src="<?php echo esc_url( $qr_code_url ); ?>"
                         data-fallback="<?php echo esc_url( $qr_fallback ); ?>"
                         alt="QR Code Pembayaran"
                         onerror="if(this.dataset.fallback && this.src!==this.dataset.fallback){this.src=this.dataset.fallback;}">
                    <div class="instruksi-box">
                        <strong>⚠️ ALUR PROSES TRANSAKSI:</strong><br>
                        1. Pindai/Scan QR Code di atas menggunakan aplikasi e-wallet atau mobile banking (QRIS).<br>
                        <?php if ( ! empty( $qr_is_dynamic ) ) : ?>
                            2. Pastikan nominal yang muncul otomatis tepat sebesar: <strong><?php echo esc_html( $formatted_amount ); ?></strong> — jangan ubah jumlahnya.<br>
                        <?php else : ?>
                            2. Masukkan jumlah nominal pembayaran manual tepat sebesar: <strong><?php echo esc_html( $formatted_amount ); ?></strong>.<br>
                        <?php endif; ?>
                        3. Ambil tangkapan layar (screenshot) bukti transfer sukses.<br>
                        4. Upload pada form konfirmasi di bawah ini agar status divalidasi oleh sistem.
                    </div>
                </div>
                </div>

                <?php if ( $bank_on ) : ?>
                <div class="qrg-pay-panel" data-pay-panel="bank">
                    <div class="qrg-bank">
                        <div class="qrg-bank__row">
                            <span class="qrg-bank__label">Bank</span>
                            <span class="qrg-bank__val"><?php echo esc_html( $bank_name !== '' ? $bank_name : '—' ); ?></span>
                        </div>
                        <div class="qrg-bank__row">
                            <span class="qrg-bank__label">Nomor rekening</span>
                            <span class="qrg-bank__val">
                                <span id="qrg-bank-acc"><?php echo esc_html( $bank_acc ); ?></span>
                                <button type="button" class="qrg-bank__copy" data-copy="qrg-bank-acc">Salin</button>
                            </span>
                        </div>
                        <?php if ( $bank_holder !== '' ) : ?>
                        <div class="qrg-bank__row">
                            <span class="qrg-bank__label">Atas nama</span>
                            <span class="qrg-bank__val"><?php echo esc_html( $bank_holder ); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="qrg-bank__row">
                            <span class="qrg-bank__label">Kode unik</span>
                            <span class="qrg-bank__val qrg-bank__val--code">
                                <span id="qrg-bank-unik"><?php echo esc_html( $tf_info['kode'] ); ?></span>
                                <button type="button" class="qrg-bank__copy" data-copy="qrg-bank-unik">Salin</button>
                            </span>
                        </div>
                        <div class="qrg-bank__row">
                            <span class="qrg-bank__label">Kode pembayaran</span>
                            <span class="qrg-bank__val">
                                <span id="qrg-bank-ref"><?php echo esc_html( $tf_info['referensi'] ); ?></span>
                                <button type="button" class="qrg-bank__copy" data-copy="qrg-bank-ref">Salin</button>
                            </span>
                        </div>
                        <div class="qrg-bank__row">
                            <span class="qrg-bank__label">Jumlah transfer</span>
                            <span class="qrg-bank__val">
                                <span class="qrg-bank__amount" id="qrg-bank-amt"><?php echo esc_html( $tf_amount ); ?></span>
                                <button type="button" class="qrg-bank__copy" data-copy-text="<?php echo esc_attr( (string) (int) $tf_info['jumlah'] ); ?>">Salin</button>
                            </span>
                        </div>
                    </div>
                    <div class="instruksi-box">
                        <strong>⚠️ TRANSFER REKENING:</strong><br>
                        1. Transfer ke rekening di atas dengan jumlah <strong>tepat</strong> <?php echo esc_html( $tf_amount ); ?>
                        <?php if ( function_exists( 'qrg_bank_transfer_use_unik' ) && qrg_bank_transfer_use_unik() ) : ?>
                            (tagihan <?php echo esc_html( $formatted_amount ); ?> + kode unik <?php echo esc_html( $tf_info['kode'] ); ?>).
                        <?php else : ?>
                            .
                        <?php endif; ?><br>
                        2. Cantumkan kode pembayaran <strong><?php echo esc_html( $tf_info['referensi'] ); ?></strong> pada berita transfer jika tersedia.<br>
                        3. Ambil screenshot bukti transfer, lalu unggah di form konfirmasi.
                    </div>
                </div>
                <?php endif; ?>

                    <div class="qrg-up">
                        <p class="qrg-up__title">Konfirmasi Pembayaran Cepat</p>
                        <p class="qrg-up__hint">Unggah screenshot bukti transfer. Format JPG/PNG, maks. jelas terbaca.</p>
                        <form method="POST" enctype="multipart/form-data" id="qrg-up-form" novalidate>
                            <?php wp_nonce_field( 'qrg_upload_action', 'qrg_bukti_nonce' ); ?>
                            <input type="file" class="qrg-up__file" name="bukti_transfer" id="qrg-bukti-file" accept="image/*" required>

                            <label class="qrg-up__drop" id="qrg-up-drop" for="qrg-bukti-file">
                                <span class="qrg-up__ico" aria-hidden="true">📷</span>
                                <span class="qrg-up__drop-title" id="qrg-up-drop-title">Ketuk untuk pilih foto</span>
                                <span class="qrg-up__drop-sub">atau ambil foto dari galeri / kamera</span>
                            </label>

                            <div class="qrg-up__preview" id="qrg-up-preview">
                                <img src="" alt="Pratinjau bukti" id="qrg-up-preview-img">
                                <div class="qrg-up__meta">
                                    <strong id="qrg-up-filename">—</strong>
                                    <button type="button" class="qrg-up__change" id="qrg-up-change">Ganti</button>
                                </div>
                            </div>

                            <button type="submit" name="qrg_upload_bukti" value="1" class="qrg-up__submit" id="qrg-up-submit" disabled>
                                Kirim Bukti Pembayaran
                            </button>
                        </form>
                    </div>

                    <div class="qrg-up-load" id="qrg-up-load" aria-hidden="true" role="status">
                        <div class="qrg-up-load__sheet">
                            <div class="qrg-up-load__orb" aria-hidden="true">
                                <span class="qrg-up-load__cloud">☁️</span>
                            </div>
                            <p class="qrg-up-load__title" id="qrg-up-load-title">Mengunggah bukti…</p>
                            <p class="qrg-up-load__tip" id="qrg-up-load-tip">Mohon tunggu, jangan tutup halaman ini.</p>
                            <div class="qrg-up-load__bar" aria-hidden="true"><span></span></div>
                            <div class="qrg-up-load__pct" id="qrg-up-load-pct">0%</div>
                            <div class="qrg-up-load__steps">
                                <span id="qrg-step-1" class="is-active">1. Siapkan</span>
                                <span id="qrg-step-2">2. Unggah</span>
                                <span id="qrg-step-3">3. Selesai</span>
                            </div>
                        </div>
                    </div>
                <script>
                (function () {
                    document.querySelectorAll('.qrg-pay-tab').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var id = btn.getAttribute('data-pay');
                            document.querySelectorAll('.qrg-pay-tab').forEach(function (b) {
                                b.classList.toggle('is-on', b === btn);
                            });
                            document.querySelectorAll('.qrg-pay-panel').forEach(function (p) {
                                p.classList.toggle('is-on', p.getAttribute('data-pay-panel') === id);
                            });
                        });
                    });
                    document.querySelectorAll('.qrg-bank__copy').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var text = btn.getAttribute('data-copy-text') || '';
                            if (!text) {
                                var el = document.getElementById(btn.getAttribute('data-copy') || '');
                                text = el ? (el.textContent || '').trim() : '';
                            }
                            if (!text) return;
                            var old = btn.textContent;
                            function done() {
                                btn.textContent = 'Tersalin';
                                setTimeout(function () { btn.textContent = old; }, 1400);
                            }
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(text).then(done).catch(function () {
                                    window.prompt('Salin teks ini:', text);
                                });
                            } else {
                                window.prompt('Salin teks ini:', text);
                            }
                        });
                    });

                    var form = document.getElementById('qrg-up-form');
                    var input = document.getElementById('qrg-bukti-file');
                    var drop = document.getElementById('qrg-up-drop');
                    var dropTitle = document.getElementById('qrg-up-drop-title');
                    var preview = document.getElementById('qrg-up-preview');
                    var previewImg = document.getElementById('qrg-up-preview-img');
                    var fileNameEl = document.getElementById('qrg-up-filename');
                    var changeBtn = document.getElementById('qrg-up-change');
                    var submitBtn = document.getElementById('qrg-up-submit');
                    var load = document.getElementById('qrg-up-load');
                    var loadTip = document.getElementById('qrg-up-load-tip');
                    var loadPct = document.getElementById('qrg-up-load-pct');
                    var step1 = document.getElementById('qrg-step-1');
                    var step2 = document.getElementById('qrg-step-2');
                    var step3 = document.getElementById('qrg-step-3');
                    if (!form || !input) return;

                    var tips = [
                        'Sedang mengamankan file bukti Anda…',
                        'Hampir selesai, mohon tetap di halaman ini…',
                        'Bukti akan langsung masuk antrean validasi…',
                        'Terima kasih atas kesabaran Anda 🙏',
                        'Koneksi sedang memproses unggahan…'
                    ];
                    var tipIdx = 0;
                    var tipTimer = null;
                    var pctTimer = null;
                    var pct = 0;

                    function formatSize(bytes) {
                        if (bytes < 1024) return bytes + ' B';
                        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                        return (bytes / 1048576).toFixed(1) + ' MB';
                    }

                    function setFile(file) {
                        if (!file) {
                            preview.classList.remove('is-on');
                            drop.classList.remove('has-file');
                            drop.style.display = '';
                            dropTitle.textContent = 'Ketuk untuk pilih foto';
                            submitBtn.disabled = true;
                            submitBtn.classList.remove('is-ready');
                            previewImg.removeAttribute('src');
                            return;
                        }
                        if (!/^image\//.test(file.type)) {
                            alert('Pilih file gambar (JPG/PNG).');
                            input.value = '';
                            setFile(null);
                            return;
                        }
                        if (file.size > 8 * 1024 * 1024) {
                            alert('Ukuran file terlalu besar (maks. sekitar 8 MB).');
                            input.value = '';
                            setFile(null);
                            return;
                        }
                        var url = URL.createObjectURL(file);
                        previewImg.onload = function () { URL.revokeObjectURL(url); };
                        previewImg.src = url;
                        fileNameEl.textContent = file.name + ' · ' + formatSize(file.size);
                        preview.classList.add('is-on');
                        drop.classList.add('has-file');
                        drop.style.display = 'none';
                        dropTitle.textContent = 'Foto siap diunggah';
                        submitBtn.disabled = false;
                        submitBtn.classList.add('is-ready');
                    }

                    input.addEventListener('change', function () {
                        setFile(input.files && input.files[0] ? input.files[0] : null);
                    });

                    if (changeBtn) {
                        changeBtn.addEventListener('click', function () {
                            input.click();
                        });
                    }

                    ['dragenter', 'dragover'].forEach(function (ev) {
                        drop.addEventListener(ev, function (e) {
                            e.preventDefault();
                            drop.classList.add('is-drag');
                        });
                    });
                    ['dragleave', 'drop'].forEach(function (ev) {
                        drop.addEventListener(ev, function (e) {
                            e.preventDefault();
                            drop.classList.remove('is-drag');
                        });
                    });
                    drop.addEventListener('drop', function (e) {
                        var files = e.dataTransfer && e.dataTransfer.files;
                        if (!files || !files.length) return;
                        input.files = files;
                        setFile(files[0]);
                    });

                    function setStep(n) {
                        [step1, step2, step3].forEach(function (el, i) {
                            if (!el) return;
                            el.classList.remove('is-active', 'is-done');
                            if (i + 1 < n) el.classList.add('is-done');
                            if (i + 1 === n) el.classList.add('is-active');
                        });
                    }

                    function showLoader() {
                        load.classList.add('is-on');
                        load.setAttribute('aria-hidden', 'false');
                        document.documentElement.style.overflow = 'hidden';
                        tipIdx = 0;
                        pct = 0;
                        setStep(1);
                        if (loadTip) loadTip.textContent = tips[0];
                        if (loadPct) loadPct.textContent = '0%';
                        tipTimer = setInterval(function () {
                            tipIdx = (tipIdx + 1) % tips.length;
                            if (loadTip) loadTip.textContent = tips[tipIdx];
                        }, 2200);
                        pctTimer = setInterval(function () {
                            if (pct < 28) { pct += 4; setStep(1); }
                            else if (pct < 78) { pct += 3; setStep(2); }
                            else if (pct < 92) { pct += 1; setStep(2); }
                            if (loadPct) loadPct.textContent = Math.min(92, pct) + '%';
                        }, 280);
                    }

                    form.addEventListener('submit', function (e) {
                        if (!input.files || !input.files.length) {
                            e.preventDefault();
                            alert('Silakan pilih foto bukti transfer terlebih dahulu.');
                            return;
                        }
                        if (form.dataset.uploading === '1') {
                            e.preventDefault();
                            return;
                        }
                        form.dataset.uploading = '1';
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Mengunggah…';
                        showLoader();
                        setTimeout(function () { setStep(2); }, 400);
                    });
                })();
                </script>
            <?php elseif ( $status === 'Sedang Ditinjau' ) : ?>
                <div class="qr-box" style="background:#e3f2fd; border-color:#bbdefb;">
                    <p style="color:#1565c0; font-weight:bold; margin:0;">Bukti transfer sudah kami terima!</p>
                    <p style="font-size:13px; color:#555; margin:5px 0 0 0;">Sistem sedang meninjau data masuk. Halaman invoice ini akan otomatis berubah menjadi lunas begitu admin menyetujui validasi.</p>
                </div>
            <?php else : ?>
                <div class="qr-box" style="background:#e8f5e9; border-color:#c8e6c9;">
                    <p style="color:#2e7d32; font-weight:bold; margin:0;">✓ Pembayaran Sukses & Lunas</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
        if ( function_exists( 'qrg_pembayaran_render_idle_script' ) ) {
            qrg_pembayaran_render_idle_script();
        }
        ?>
    </body>
    </html>
    <?php
}
