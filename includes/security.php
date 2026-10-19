<?php
/**
 * Helper keamanan bersama plugin Sekolah.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Mulai session PHP dengan cookie flag yang lebih aman.
 */
function qrg_secure_session_start() {
    if ( headers_sent() ) {
        return;
    }
    if ( session_status() !== PHP_SESSION_NONE ) {
        return;
    }

    $secure = is_ssl();
    if ( PHP_VERSION_ID >= 70300 ) {
        session_set_cookie_params( [
            'lifetime' => 0,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
    } else {
        session_set_cookie_params( 0, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN ? COOKIE_DOMAIN : '', $secure, true );
    }
    session_start();
}

/**
 * Rate limit sederhana berbasis transient + IP.
 *
 * @param string $action  Kunci aksi (mis. login_nis, psb_form).
 * @param int    $max     Maks percobaan.
 * @param int    $window  Jendela waktu (detik).
 * @return true|WP_Error True jika diizinkan, WP_Error jika diblokir.
 */
function qrg_rate_limit_check( $action, $max = 8, $window = 900 ) {
    $ip = qrg_get_client_ip();
    $key = 'qrg_rl_' . md5( $action . '|' . $ip );
    $data = get_transient( $key );
    if ( ! is_array( $data ) ) {
        $data = [ 'count' => 0, 'start' => time() ];
    }

    if ( (int) $data['count'] >= (int) $max ) {
        $remaining = max( 0, (int) $window - ( time() - (int) $data['start'] ) );
        $mins = max( 1, (int) ceil( $remaining / 60 ) );
        return new WP_Error(
            'qrg_rate_limited',
            sprintf( 'Terlalu banyak percobaan. Coba lagi dalam sekitar %d menit.', $mins )
        );
    }

    return true;
}

/**
 * Catat satu percobaan rate limit (panggil setelah gagal / submit).
 */
function qrg_rate_limit_hit( $action, $window = 900 ) {
    $ip  = qrg_get_client_ip();
    $key = 'qrg_rl_' . md5( $action . '|' . $ip );
    $data = get_transient( $key );
    if ( ! is_array( $data ) ) {
        $data = [ 'count' => 0, 'start' => time() ];
    }
    $data['count'] = (int) $data['count'] + 1;
    set_transient( $key, $data, (int) $window );
}

/**
 * Reset rate limit setelah sukses.
 */
function qrg_rate_limit_clear( $action ) {
    $ip  = qrg_get_client_ip();
    $key = 'qrg_rl_' . md5( $action . '|' . $ip );
    delete_transient( $key );
}

/**
 * IP klien (menghormati proxy umum secara terbatas).
 */
function qrg_get_client_ip() {
    $ip = '';
    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
        $ip    = trim( $parts[0] );
    } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip = (string) $_SERVER['REMOTE_ADDR'];
    }
    $ip = sanitize_text_field( $ip );
    return $ip !== '' ? $ip : '0.0.0.0';
}

/**
 * Validasi redirect hanya ke URL internal situs.
 *
 * @param string $url     URL calon redirect.
 * @param string $default Fallback.
 * @return string
 */
function qrg_safe_redirect_url( $url, $default = '' ) {
    if ( $default === '' ) {
        $default = home_url( '/' );
    }
    $url = is_string( $url ) ? $url : '';
    return wp_validate_redirect( $url, $default );
}

/**
 * MIME whitelist untuk bukti transfer (gambar saja).
 *
 * @return array
 */
function qrg_upload_mimes_bukti() {
    return [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'gif'          => 'image/gif',
        'webp'         => 'image/webp',
    ];
}

/**
 * MIME whitelist lampiran tugas / jawaban siswa.
 *
 * @return array
 */
function qrg_upload_mimes_dokumen() {
    return [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'gif'          => 'image/gif',
        'webp'         => 'image/webp',
        'pdf'          => 'application/pdf',
        'doc'          => 'application/msword',
        'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'          => 'application/vnd.ms-excel',
        'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'          => 'application/vnd.ms-powerpoint',
        'pptx'         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip'          => 'application/zip',
    ];
}

/**
 * Wrapper aman wp_handle_upload dengan whitelist MIME.
 *
 * @param array $file  Satu elemen $_FILES.
 * @param array $mimes Whitelist.
 * @return array|false
 */
function qrg_handle_upload_safe( $file, $mimes ) {
    if ( empty( $file['name'] ) ) {
        return false;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $upload = wp_handle_upload( $file, [
        'test_form' => false,
        'mimes'     => $mimes,
    ] );

    if ( isset( $upload['error'] ) ) {
        return $upload;
    }

    // Tolak jika ekstensi berbahaya meskipun lolos filter
    $path = isset( $upload['file'] ) ? $upload['file'] : '';
    $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
    $blocked = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'exe', 'sh', 'bat', 'cmd', 'js', 'html', 'htm', 'svg' ];
    if ( $ext && in_array( $ext, $blocked, true ) ) {
        if ( $path && file_exists( $path ) ) {
            @unlink( $path );
        }
        return [ 'error' => 'Tipe file tidak diizinkan.' ];
    }

    return $upload;
}

/**
 * Pastikan capability edit sebelum simpan meta CPT.
 *
 * @param int $post_id ID post.
 * @return bool
 */
function qrg_can_save_post_meta( $post_id ) {
    $post_id = (int) $post_id;
    if ( $post_id <= 0 ) {
        return false;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return false;
    }
    return current_user_can( 'edit_post', $post_id );
}

/**
 * Generate / ambil token akses invoice PSB.
 *
 * @param int $tagihan_id ID master_tagihan.
 * @return string
 */
function qrg_get_or_create_tagihan_access_token( $tagihan_id ) {
    $tagihan_id = (int) $tagihan_id;
    $token = (string) get_post_meta( $tagihan_id, '_tagihan_akses_token', true );
    if ( $token !== '' ) {
        return $token;
    }
    $token = bin2hex( random_bytes( 16 ) );
    update_post_meta( $tagihan_id, '_tagihan_akses_token', $token );
    return $token;
}

/**
 * URL invoice dengan token (untuk PSB).
 *
 * @param int $tagihan_id ID tagihan.
 * @return string
 */
function qrg_get_tagihan_pay_url( $tagihan_id ) {
    $url = get_permalink( $tagihan_id );
    if ( ! $url ) {
        return '';
    }
    $psb_id = (int) get_post_meta( $tagihan_id, '_tagihan_psb_id', true );
    if ( $psb_id ) {
        $token = qrg_get_or_create_tagihan_access_token( $tagihan_id );
        $url   = add_query_arg( 'token', $token, $url );
    }
    return $url;
}

/**
 * Verifikasi token akses tagihan PSB.
 *
 * @param int $tagihan_id ID tagihan.
 * @return bool
 */
function qrg_verify_tagihan_access_token( $tagihan_id ) {
    $tagihan_id = (int) $tagihan_id;
    $expected   = (string) get_post_meta( $tagihan_id, '_tagihan_akses_token', true );
    if ( $expected === '' ) {
        // Tagihan lama tanpa token: buat token tapi tolak akses sampai URL baru dipakai
        qrg_get_or_create_tagihan_access_token( $tagihan_id );
        return false;
    }
    $given = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
    if ( $given === '' ) {
        return false;
    }
    return hash_equals( $expected, $given );
}
