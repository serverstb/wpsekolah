<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Google Client ID untuk login pembayaran siswa.
 */
function qrg_get_google_client_id() {
    return trim( (string) get_option( 'qrg_google_client_id', '' ) );
}

function qrg_google_auth_enabled() {
    return qrg_get_google_client_id() !== '';
}

/**
 * Pengaturan Login Google dipindah ke Setting — Pembayaran (?google=1).
 * Slug lama diarahkan agar tautan lama tetap jalan.
 */
add_action( 'admin_menu', 'qrg_google_auth_settings_menu', 25 );
function qrg_google_auth_settings_menu() {
    add_submenu_page(
        '',
        'Login Google',
        'Login Google',
        'manage_options',
        'qrg-google-auth',
        'qrg_google_auth_settings_page'
    );
}

function qrg_google_auth_settings_url() {
    return admin_url( 'admin.php?page=qrg-setting&google=1' );
}

function qrg_google_auth_settings_page() {
    wp_safe_redirect( qrg_google_auth_settings_url() );
    exit;
}

function qrg_google_auth_handle_admin_post() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return false;
    }
    if ( isset( $_POST['qrg_save_google_auth'] ) && check_admin_referer( 'qrg_google_auth_save', 'qrg_google_auth_nonce' ) ) {
        $client_id = isset( $_POST['qrg_google_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_google_client_id'] ) ) : '';
        update_option( 'qrg_google_client_id', $client_id );
        return true;
    }
    return false;
}

function qrg_google_auth_render_settings_panel() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $client_id   = qrg_get_google_client_id();
    $origin      = home_url( '/' );
    $example_url = home_url( '/sekolah/' );
    ?>
    <p>Atur autentikasi Google agar siswa/wali login di <code>/sekolah/</code> dengan email Google yang sama dengan email siswa di Data Siswa.</p>

    <form method="post" action="<?php echo esc_url( qrg_google_auth_settings_url() ); ?>" style="max-width:820px; background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:20px 24px; margin-top:8px;">
        <?php wp_nonce_field( 'qrg_google_auth_save', 'qrg_google_auth_nonce' ); ?>
        <h2 style="margin-top:0;">Login Google — Portal Siswa</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="qrg_google_client_id">Google Client ID</label></th>
                <td>
                    <input type="text" class="large-text" id="qrg_google_client_id" name="qrg_google_client_id"
                        value="<?php echo esc_attr( $client_id ); ?>"
                        placeholder="xxxxx.apps.googleusercontent.com">
                    <p class="description">
                        Buat OAuth Client ID (tipe <strong>Web application</strong>) di
                        <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>.
                    </p>
                </td>
            </tr>
            <tr>
                <th>Authorized JavaScript origins</th>
                <td>
                    <code style="display:inline-block; padding:6px 10px; background:#f6f7f7;"><?php echo esc_html( untrailingslashit( $origin ) ); ?></code>
                    <p class="description">Tambahkan URL di atas pada pengaturan OAuth Client.</p>
                </td>
            </tr>
            <tr>
                <th>Contoh halaman</th>
                <td>
                    <code style="display:inline-block; padding:6px 10px; background:#f6f7f7;"><?php echo esc_html( $example_url ); ?></code>
                </td>
            </tr>
        </table>
        <p>
            <button type="submit" name="qrg_save_google_auth" class="button button-primary">Simpan</button>
        </p>
    </form>

    <div style="max-width:820px; margin-top:20px; background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:16px 20px;">
        <h2 style="margin-top:0;">Cara kerja</h2>
        <ol style="margin:0; line-height:1.7; color:#50575e;">
            <li>Isi <strong>Email</strong> siswa di Data Siswa (contoh Farza: akun Google yang dipakai login).</li>
            <li>Simpan Google Client ID di halaman ini.</li>
            <li>Di halaman pembayaran, user klik <strong>Login dengan Google</strong>.</li>
            <li>Sistem membandingkan email Google dengan email siswa. Jika cocok, daftar pembayaran ditampilkan.</li>
            <li>Login NIS tetap tersedia sebagai alternatif.</li>
        </ol>
    </div>
    <?php
}

/**
 * Verifikasi Google ID token dan ambil email.
 *
 * @return array{ok:bool,email?:string,error?:string}
 */
function qrg_verify_google_id_token( $id_token ) {
    $id_token  = trim( (string) $id_token );
    $client_id = qrg_get_google_client_id();

    if ( $id_token === '' || $client_id === '' ) {
        return [ 'ok' => false, 'error' => 'Konfigurasi Google belum lengkap.' ];
    }

    $response = wp_remote_get(
        'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ),
        [ 'timeout' => 15 ]
    );

    if ( is_wp_error( $response ) ) {
        return [ 'ok' => false, 'error' => 'Gagal menghubungi Google: ' . $response->get_error_message() ];
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 || ! is_array( $body ) ) {
        return [ 'ok' => false, 'error' => 'Token Google tidak valid.' ];
    }

    $aud = isset( $body['aud'] ) ? (string) $body['aud'] : '';
    if ( ! hash_equals( $client_id, $aud ) ) {
        return [ 'ok' => false, 'error' => 'Client ID tidak cocok.' ];
    }

    $email = isset( $body['email'] ) ? strtolower( trim( (string) $body['email'] ) ) : '';
    if ( $email === '' || ! is_email( $email ) ) {
        return [ 'ok' => false, 'error' => 'Email Google tidak ditemukan.' ];
    }

    $verified = isset( $body['email_verified'] ) ? $body['email_verified'] : false;
    if ( ! ( $verified === true || $verified === 'true' || $verified === 1 || $verified === '1' ) ) {
        return [ 'ok' => false, 'error' => 'Email Google belum terverifikasi.' ];
    }

    return [ 'ok' => true, 'email' => $email ];
}

/**
 * Cocokkan email Google dengan email siswa.
 */
function qrg_pembayaran_verify_google_email( $siswa_id, $google_email ) {
    $siswa_id     = (int) $siswa_id;
    $google_email = strtolower( trim( (string) $google_email ) );
    if ( ! $siswa_id || $google_email === '' ) {
        return false;
    }

    $siswa_email = strtolower( trim( (string) get_post_meta( $siswa_id, '_siswa_email', true ) ) );
    if ( $siswa_email === '' || ! is_email( $siswa_email ) ) {
        return false;
    }

    return hash_equals( $siswa_email, $google_email );
}

/**
 * Cari siswa berdasarkan email (_siswa_email).
 */
function qrg_find_siswa_by_email( $email ) {
    $email = strtolower( trim( (string) $email ) );
    if ( $email === '' || ! is_email( $email ) ) {
        return 0;
    }

    $posts = get_posts( [
        'post_type'      => 'master_siswa',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_siswa_email',
                'value' => $email,
            ],
        ],
    ] );

    // Fallback case-insensitive via loop jika exact miss (email disimpan mixed case)
    if ( empty( $posts ) ) {
        $all = get_posts( [
            'post_type'      => 'master_siswa',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_siswa_email',
        ] );
        foreach ( $all as $sid ) {
            $stored = strtolower( trim( (string) get_post_meta( $sid, '_siswa_email', true ) ) );
            if ( $stored !== '' && hash_equals( $stored, $email ) ) {
                return (int) $sid;
            }
        }
        return 0;
    }

    return (int) $posts[0];
}

/**
 * AJAX login Google untuk halaman pembayaran.
 */
add_action( 'wp_ajax_qrg_google_pembayaran_login', 'qrg_ajax_google_pembayaran_login' );
add_action( 'wp_ajax_nopriv_qrg_google_pembayaran_login', 'qrg_ajax_google_pembayaran_login' );
function qrg_ajax_google_pembayaran_login() {
    $siswa_id  = isset( $_POST['siswa_id'] ) ? absint( $_POST['siswa_id'] ) : 0;
    $credential = isset( $_POST['credential'] ) ? sanitize_text_field( wp_unslash( $_POST['credential'] ) ) : '';

    if ( ! $siswa_id || get_post_type( $siswa_id ) !== 'master_siswa' ) {
        wp_send_json_error( [ 'message' => 'Data siswa tidak valid.' ], 400 );
    }

    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'qrg_google_login_' . $siswa_id ) ) {
        wp_send_json_error( [ 'message' => 'Sesi tidak valid. Muat ulang halaman.' ], 403 );
    }

    $verify = qrg_verify_google_id_token( $credential );
    if ( empty( $verify['ok'] ) ) {
        wp_send_json_error( [ 'message' => isset( $verify['error'] ) ? $verify['error'] : 'Verifikasi gagal.' ], 401 );
    }

    if ( ! qrg_pembayaran_verify_google_email( $siswa_id, $verify['email'] ) ) {
        wp_send_json_error( [
            'message' => 'Email Google tidak cocok dengan data siswa.',
        ], 403 );
    }

    if ( ! function_exists( 'qrg_pembayaran_set_auth_siswa_id' ) ) {
        wp_send_json_error( [ 'message' => 'Modul pembayaran belum siap.' ], 500 );
    }

    qrg_pembayaran_set_auth_siswa_id( $siswa_id, ! empty( $_POST['remember'] ) );

    $default = function_exists( 'qrg_get_siswa_pembayaran_url' )
        ? qrg_get_siswa_pembayaran_url( $siswa_id )
        : home_url( '/' );
    $redirect = isset( $_POST['redirect'] ) ? wp_unslash( $_POST['redirect'] ) : '';
    $redirect = function_exists( 'qrg_safe_redirect_url' )
        ? qrg_safe_redirect_url( $redirect, $default )
        : wp_validate_redirect( $redirect, $default );

    wp_send_json_success( [
        'message'  => 'Login berhasil.',
        'redirect' => $redirect,
    ] );
}

/**
 * AJAX login Google untuk portal pembelajaran (cari siswa by email).
 */
add_action( 'wp_ajax_qrg_google_portal_login', 'qrg_ajax_google_portal_login' );
add_action( 'wp_ajax_nopriv_qrg_google_portal_login', 'qrg_ajax_google_portal_login' );
function qrg_ajax_google_portal_login() {
    $credential = isset( $_POST['credential'] ) ? sanitize_text_field( wp_unslash( $_POST['credential'] ) ) : '';
    $redirect   = isset( $_POST['redirect'] ) ? wp_unslash( $_POST['redirect'] ) : '';

    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'qrg_google_portal_login' ) ) {
        wp_send_json_error( [ 'message' => 'Sesi tidak valid. Muat ulang halaman.' ], 403 );
    }

    $verify = qrg_verify_google_id_token( $credential );
    if ( empty( $verify['ok'] ) ) {
        wp_send_json_error( [ 'message' => isset( $verify['error'] ) ? $verify['error'] : 'Verifikasi gagal.' ], 401 );
    }

    $siswa_id = qrg_find_siswa_by_email( $verify['email'] );
    if ( ! $siswa_id ) {
        wp_send_json_error( [
            'message' => 'Email Google tidak terdaftar di Data Siswa.',
        ], 403 );
    }

    if ( ! function_exists( 'qrg_pembayaran_set_auth_siswa_id' ) ) {
        wp_send_json_error( [ 'message' => 'Modul autentikasi belum siap.' ], 500 );
    }

    qrg_pembayaran_set_auth_siswa_id( $siswa_id, ! empty( $_POST['remember'] ) );

    $default = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url()
        : home_url( '/' );
    $redirect = function_exists( 'qrg_safe_redirect_url' )
        ? qrg_safe_redirect_url( $redirect, $default )
        : wp_validate_redirect( $redirect, $default );

    wp_send_json_success( [
        'message'  => 'Login berhasil.',
        'redirect' => $redirect,
    ] );
}
