<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Portal Guru: /sekolah/guru/
 * Login NIP + No. Telepon → jadwal & tugas, gaji, presensi.
 * URL lama /guru/ dialihkan ke /sekolah/guru/.
 */

function qrg_guru_portal_ensure_session() {
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

function qrg_guru_portal_get_auth_id() {
    qrg_guru_portal_ensure_session();
    return isset( $_SESSION['qrg_guru_portal_id'] ) ? (int) $_SESSION['qrg_guru_portal_id'] : 0;
}

function qrg_guru_portal_set_auth_id( $guru_id ) {
    qrg_guru_portal_ensure_session();
    $_SESSION['qrg_guru_portal_id'] = (int) $guru_id;
}

function qrg_guru_portal_logout() {
    qrg_guru_portal_ensure_session();
    unset( $_SESSION['qrg_guru_portal_id'] );
}

/**
 * URL portal guru (di bawah /sekolah/guru/).
 *
 * @param string $section ''|jadwal|gaji|presensi|tugas|izin
 * @param array  $args    Query args.
 */
function qrg_get_guru_portal_url( $section = '', $args = [] ) {
    $section = sanitize_key( (string) $section );
    $path    = 'sekolah/guru/';
    if ( in_array( $section, [ 'jadwal', 'gaji', 'presensi', 'tugas', 'izin' ], true ) ) {
        $path .= $section . '/';
    }
    $url = home_url( '/' . $path );
    if ( ! empty( $args ) && is_array( $args ) ) {
        $url = add_query_arg( $args, $url );
    }
    return $url;
}

function qrg_find_guru_by_nip( $nip ) {
    $nip = trim( (string) $nip );
    if ( $nip === '' ) {
        return 0;
    }
    $posts = get_posts( [
        'post_type'      => 'master_guru',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_guru_nip',
                'value' => $nip,
            ],
        ],
    ] );
    return ! empty( $posts ) ? (int) $posts[0] : 0;
}

/**
 * Normalisasi nomor telepon untuk perbandingan login.
 */
function qrg_guru_normalize_telp( $telp ) {
    $telp = trim( (string) $telp );
    // Hapus spasi, strip, titik, kurung
    $telp = preg_replace( '/[\s\-\.\(\)]+/', '', $telp );
    return (string) $telp;
}

function qrg_guru_portal_verify( $guru_id, $password ) {
    $guru_id  = (int) $guru_id;
    $password = qrg_guru_normalize_telp( $password );
    if ( ! $guru_id || $password === '' || get_post_type( $guru_id ) !== 'master_guru' ) {
        return false;
    }

    $telp = qrg_guru_normalize_telp( get_post_meta( $guru_id, '_guru_no_telp', true ) );
    if ( $telp === '' ) {
        return false;
    }

    return hash_equals( $telp, $password );
}

add_action( 'init', 'qrg_guru_portal_rewrite_rules' );
function qrg_guru_portal_rewrite_rules() {
    // Portal baru di bawah /sekolah/guru/
    add_rewrite_rule( '^sekolah/guru/?$', 'index.php?qrg_guru_portal=hub', 'top' );
    add_rewrite_rule(
        '^sekolah/guru/(jadwal|gaji|presensi|tugas|izin)/?$',
        'index.php?qrg_guru_portal=$matches[1]',
        'top'
    );
    // Legacy /guru/ → tetap di-resolve agar bisa diarahkan 301.
    add_rewrite_rule( '^guru/?$', 'index.php?qrg_guru_portal=hub&qrg_guru_legacy=1', 'top' );
    add_rewrite_rule(
        '^guru/(jadwal|gaji|presensi|tugas|izin)/?$',
        'index.php?qrg_guru_portal=$matches[1]&qrg_guru_legacy=1',
        'top'
    );
}

add_filter( 'query_vars', 'qrg_guru_portal_query_vars' );
function qrg_guru_portal_query_vars( $vars ) {
    $vars[] = 'qrg_guru_portal';
    $vars[] = 'qrg_guru_legacy';
    return $vars;
}

add_action( 'admin_init', 'qrg_guru_portal_flush_rewrite_once' );
function qrg_guru_portal_flush_rewrite_once() {
    if ( get_option( 'qrg_guru_portal_rewrite_flushed_v3' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'qrg_guru_portal_rewrite_flushed_v3', 1 );
}

add_action( 'init', 'qrg_guru_portal_ensure_session', 1 );

/**
 * Deteksi section portal guru dari REQUEST_URI (fallback + legacy).
 *
 * @return array{section:string,legacy:bool}|null
 */
function qrg_guru_portal_detect_from_uri() {
    if ( empty( $_SERVER['REQUEST_URI'] ) ) {
        return null;
    }
    $path = wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
    $path = is_string( $path ) ? untrailingslashit( $path ) : '';
    if ( $path === '' ) {
        return null;
    }

    $base_new = untrailingslashit( (string) wp_parse_url( home_url( '/sekolah/guru' ), PHP_URL_PATH ) );
    $base_old = untrailingslashit( (string) wp_parse_url( home_url( '/guru' ), PHP_URL_PATH ) );

    if ( $base_new && ( $path === $base_new || strpos( $path, $base_new . '/' ) === 0 ) ) {
        if ( $path === $base_new ) {
            return [ 'section' => 'hub', 'legacy' => false ];
        }
        if ( preg_match( '#^' . preg_quote( $base_new, '#' ) . '/(jadwal|gaji|presensi|tugas|izin)$#', $path, $m ) ) {
            return [ 'section' => $m[1], 'legacy' => false ];
        }
    }

    if ( $base_old && ( $path === $base_old || strpos( $path, $base_old . '/' ) === 0 ) ) {
        if ( $path === $base_old ) {
            return [ 'section' => 'hub', 'legacy' => true ];
        }
        if ( preg_match( '#^' . preg_quote( $base_old, '#' ) . '/(jadwal|gaji|presensi|tugas|izin)$#', $path, $m ) ) {
            return [ 'section' => $m[1], 'legacy' => true ];
        }
    }

    return null;
}

add_action( 'template_redirect', 'qrg_guru_portal_template_redirect', 5 );
function qrg_guru_portal_template_redirect() {
    $section = get_query_var( 'qrg_guru_portal' );
    $legacy  = (int) get_query_var( 'qrg_guru_legacy' ) === 1;

    if ( ! $section ) {
        $detected = qrg_guru_portal_detect_from_uri();
        if ( $detected ) {
            $section = $detected['section'];
            $legacy  = ! empty( $detected['legacy'] );
        }
    }

    if ( ! $section ) {
        return;
    }
    $section = sanitize_key( (string) $section );
    if ( ! in_array( $section, [ 'hub', 'jadwal', 'gaji', 'presensi', 'tugas', 'izin' ], true ) ) {
        return;
    }

    // Redirect permanen dari /guru/* → /sekolah/guru/*
    if ( $legacy ) {
        $target = ( $section === 'hub' ) ? qrg_get_guru_portal_url() : qrg_get_guru_portal_url( $section );
        if ( ! empty( $_GET ) && is_array( $_GET ) ) {
            $keep = [];
            foreach ( $_GET as $k => $v ) {
                $key = sanitize_key( (string) $k );
                if ( $key === '' || strpos( $key, 'qrg_' ) === 0 ) {
                    continue;
                }
                $keep[ $key ] = sanitize_text_field( wp_unslash( is_array( $v ) ? '' : $v ) );
            }
            if ( $keep ) {
                $target = add_query_arg( $keep, $target );
            }
        }
        wp_safe_redirect( $target, 301 );
        exit;
    }

    qrg_render_guru_portal( $section );
    exit;
}

function qrg_guru_portal_handle_auth() {
    if ( isset( $_GET['qrg_logout'] ) ) {
        qrg_guru_portal_logout();
        wp_safe_redirect( qrg_get_guru_portal_url() );
        exit;
    }

    if ( ! isset( $_POST['qrg_guru_login'] ) ) {
        return '';
    }
    if ( ! isset( $_POST['qrg_guru_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_guru_nonce'] ) ), 'qrg_guru_login' ) ) {
        return 'Sesi tidak valid. Muat ulang halaman.';
    }

    $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'login_guru', 8, 900 ) : true;
    if ( is_wp_error( $rl ) ) {
        return $rl->get_error_message();
    }

    $identitas = isset( $_POST['qrg_guru_identitas'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_guru_identitas'] ) ) : '';
    $password  = isset( $_POST['qrg_guru_password'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_guru_password'] ) ) : '';

    $guru_id = qrg_find_guru_by_nip( $identitas );
    if ( $guru_id && qrg_guru_portal_verify( $guru_id, $password ) ) {
        if ( function_exists( 'qrg_rate_limit_clear' ) ) {
            qrg_rate_limit_clear( 'login_guru' );
        }
        qrg_guru_portal_set_auth_id( $guru_id );
        $goto = isset( $_POST['qrg_guru_goto'] ) ? sanitize_key( wp_unslash( $_POST['qrg_guru_goto'] ) ) : '';
        if ( $goto === 'tugas' ) {
            $goto = 'jadwal';
        }
        if ( ! in_array( $goto, [ 'jadwal', 'gaji', 'presensi', 'izin' ], true ) ) {
            $goto = '';
        }
        wp_safe_redirect( qrg_get_guru_portal_url( $goto ) );
        exit;
    }

    if ( function_exists( 'qrg_rate_limit_hit' ) ) {
        qrg_rate_limit_hit( 'login_guru', 900 );
    }
    if ( ! $guru_id ) {
        return 'NIP tidak ditemukan. Periksa kembali NIP Anda.';
    }
    return 'NIP atau No. Telepon salah. Pastikan No. Telepon di Data Guru sudah diisi.';
}

/**
 * AJAX: cek apakah NIP guru terdaftar (untuk langkah login).
 */
add_action( 'wp_ajax_qrg_guru_check_nip', 'qrg_ajax_guru_check_nip' );
add_action( 'wp_ajax_nopriv_qrg_guru_check_nip', 'qrg_ajax_guru_check_nip' );
function qrg_ajax_guru_check_nip() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'qrg_guru_check_nip' ) ) {
        wp_send_json_error( [ 'message' => 'Sesi tidak valid. Muat ulang halaman.' ], 403 );
    }

    $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'check_nip_guru', 20, 900 ) : true;
    if ( is_wp_error( $rl ) ) {
        wp_send_json_error( [ 'message' => $rl->get_error_message() ], 429 );
    }

    $nip = isset( $_POST['nip'] ) ? sanitize_text_field( wp_unslash( $_POST['nip'] ) ) : '';
    $nip = preg_replace( '/\D+/', '', $nip );
    if ( $nip === '' ) {
        wp_send_json_error( [ 'message' => 'Masukkan NIP terlebih dahulu.' ], 400 );
    }

    if ( function_exists( 'qrg_rate_limit_hit' ) ) {
        qrg_rate_limit_hit( 'check_nip_guru', 900 );
    }

    $guru_id = qrg_find_guru_by_nip( $nip );
    if ( ! $guru_id ) {
        wp_send_json_error( [ 'message' => 'NIP tidak ditemukan. Periksa kembali NIP Anda.' ], 404 );
    }

    wp_send_json_success( [ 'ok' => true ] );
}

/**
 * URL foto profil guru (featured image).
 *
 * @param int          $guru_id ID guru.
 * @param string|array $size    Ukuran.
 * @return string
 */
function qrg_get_guru_avatar_url( $guru_id, $size = 'thumbnail' ) {
    $guru_id = (int) $guru_id;
    if ( $guru_id <= 0 || get_post_type( $guru_id ) !== 'master_guru' ) {
        return '';
    }
    if ( ! has_post_thumbnail( $guru_id ) ) {
        return '';
    }
    $url = get_the_post_thumbnail_url( $guru_id, $size );
    return $url ? (string) $url : '';
}

/**
 * Unggah / hapus foto profil guru.
 *
 * @param int $guru_id ID guru.
 * @return array{error:string,success:string}
 */
function qrg_process_guru_profile_photo( $guru_id ) {
    $guru_id = (int) $guru_id;
    $out     = [ 'error' => '', 'success' => '' ];
    if ( $guru_id <= 0 || get_post_type( $guru_id ) !== 'master_guru' ) {
        return $out;
    }

    $doing_upload = isset( $_POST['qrg_gp_avatar_upload'] );
    $doing_remove = isset( $_POST['qrg_gp_avatar_remove'] );
    if ( ! $doing_upload && ! $doing_remove ) {
        return $out;
    }

    if ( ! isset( $_POST['qrg_gp_avatar_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_gp_avatar_nonce'] ) ), 'qrg_gp_avatar_' . $guru_id ) ) {
        $out['error'] = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
        return $out;
    }

    if ( $doing_remove ) {
        $old = (int) get_post_thumbnail_id( $guru_id );
        delete_post_thumbnail( $guru_id );
        if ( $old > 0 && (int) wp_get_post_parent_id( $old ) === $guru_id ) {
            wp_delete_attachment( $old, true );
        }
        $out['success'] = 'Foto profil dihapus.';
        return $out;
    }

    if ( empty( $_FILES['qrg_gp_avatar']['name'] ) ) {
        $out['error'] = 'Pilih foto terlebih dahulu.';
        return $out;
    }

    $file = $_FILES['qrg_gp_avatar'];
    if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
        $out['error'] = 'Unggah gagal. Coba foto lain.';
        return $out;
    }
    if ( ! empty( $file['size'] ) && (int) $file['size'] > ( 3 * 1024 * 1024 ) ) {
        $out['error'] = 'Ukuran foto maksimal 3 MB.';
        return $out;
    }
    if ( ! function_exists( 'qrg_handle_upload_safe' ) || ! function_exists( 'qrg_upload_mimes_bukti' ) ) {
        $out['error'] = 'Fitur unggah tidak tersedia.';
        return $out;
    }

    $movefile = qrg_handle_upload_safe( $file, qrg_upload_mimes_bukti() );
    if ( ! is_array( $movefile ) || ! empty( $movefile['error'] ) || empty( $movefile['file'] ) ) {
        $out['error'] = is_array( $movefile ) && ! empty( $movefile['error'] )
            ? (string) $movefile['error']
            : 'Gagal mengunggah foto.';
        return $out;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attachment = [
        'post_mime_type' => isset( $movefile['type'] ) ? $movefile['type'] : 'image/jpeg',
        'post_title'     => sanitize_file_name( pathinfo( $movefile['file'], PATHINFO_FILENAME ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_parent'    => $guru_id,
    ];
    $attach_id = wp_insert_attachment( $attachment, $movefile['file'], $guru_id );
    if ( is_wp_error( $attach_id ) || ! $attach_id ) {
        if ( ! empty( $movefile['file'] ) && file_exists( $movefile['file'] ) ) {
            @unlink( $movefile['file'] );
        }
        $out['error'] = 'Gagal menyimpan foto.';
        return $out;
    }

    $meta = wp_generate_attachment_metadata( $attach_id, $movefile['file'] );
    if ( is_array( $meta ) ) {
        wp_update_attachment_metadata( $attach_id, $meta );
    }

    $old = (int) get_post_thumbnail_id( $guru_id );
    set_post_thumbnail( $guru_id, $attach_id );
    if ( $old > 0 && $old !== (int) $attach_id && (int) wp_get_post_parent_id( $old ) === $guru_id ) {
        wp_delete_attachment( $old, true );
    }

    $out['success'] = 'Foto profil berhasil diperbarui.';
    return $out;
}

/**
 * Ganti password login guru (= No. Telepon di Data Guru).
 *
 * @param int $guru_id ID guru.
 * @return array{error:string,success:string}
 */
function qrg_process_guru_change_password( $guru_id ) {
    $guru_id = (int) $guru_id;
    $out     = [ 'error' => '', 'success' => '' ];
    if ( $guru_id <= 0 || get_post_type( $guru_id ) !== 'master_guru' ) {
        return $out;
    }
    if ( ! isset( $_POST['qrg_gp_change_pass'] ) ) {
        return $out;
    }
    if ( ! isset( $_POST['qrg_gp_pass_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_gp_pass_nonce'] ) ), 'qrg_gp_change_pass_' . $guru_id ) ) {
        $out['error'] = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
        return $out;
    }

    $current = isset( $_POST['qrg_gp_pass_current'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_gp_pass_current'] ) ) : '';
    $new     = isset( $_POST['qrg_gp_pass_new'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_gp_pass_new'] ) ) : '';
    $confirm = isset( $_POST['qrg_gp_pass_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_gp_pass_confirm'] ) ) : '';
    $current = qrg_guru_normalize_telp( $current );
    $new     = qrg_guru_normalize_telp( $new );
    $confirm = qrg_guru_normalize_telp( $confirm );

    if ( $current === '' || $new === '' || $confirm === '' ) {
        $out['error'] = 'Lengkapi semua kolom password.';
        return $out;
    }

    $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'change_pass_guru_' . $guru_id, 6, 900 ) : true;
    if ( is_wp_error( $rl ) ) {
        $out['error'] = $rl->get_error_message();
        return $out;
    }

    if ( ! qrg_guru_portal_verify( $guru_id, $current ) ) {
        if ( function_exists( 'qrg_rate_limit_hit' ) ) {
            qrg_rate_limit_hit( 'change_pass_guru_' . $guru_id, 900 );
        }
        $out['error'] = 'Password lama salah.';
        return $out;
    }

    if ( ! preg_match( '/^[0-9]{8,20}$/', $new ) ) {
        $out['error'] = 'Password baru harus 8–20 digit nomor telepon.';
        return $out;
    }
    if ( ! hash_equals( $new, $confirm ) ) {
        $out['error'] = 'Konfirmasi password tidak cocok.';
        return $out;
    }
    if ( hash_equals( $current, $new ) ) {
        $out['error'] = 'Password baru harus berbeda dari password lama.';
        return $out;
    }

    update_post_meta( $guru_id, '_guru_no_telp', $new );
    if ( function_exists( 'qrg_rate_limit_clear' ) ) {
        qrg_rate_limit_clear( 'change_pass_guru_' . $guru_id );
    }
    $out['success'] = 'Password berhasil diganti. Gunakan nomor baru saat login berikutnya.';
    return $out;
}

function qrg_render_guru_portal( $section = 'hub' ) {
    $error   = qrg_guru_portal_handle_auth();
    $auth_id = qrg_guru_portal_get_auth_id();

    if ( isset( $_GET['qrg_err'] ) ) {
        $error = sanitize_text_field( wp_unslash( $_GET['qrg_err'] ) );
    }

    if ( in_array( $section, [ 'jadwal', 'gaji', 'presensi', 'tugas', 'izin' ], true ) && ! $auth_id ) {
        wp_safe_redirect( add_query_arg( 'goto', $section, qrg_get_guru_portal_url() ) );
        exit;
    }

    if ( $section === 'tugas' && $auth_id ) {
        // URL lama /guru/tugas/ digabung ke halaman jadwal.
        $args = [];
        foreach ( [ 'baru', 'created', 'kelas_id', 'mapel_id', 'tugas', 'siswa', 'jurnal', 'jurnal_baru', 'jurnal_created' ] as $k ) {
            if ( isset( $_GET[ $k ] ) ) {
                $args[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
            }
        }
        wp_safe_redirect( qrg_get_guru_portal_url( 'jadwal', $args ) );
        exit;
    }
    if ( $section === 'jadwal' && $auth_id ) {
        qrg_render_guru_portal_jadwal( $auth_id );
        return;
    }
    if ( $section === 'gaji' && $auth_id ) {
        qrg_render_guru_portal_gaji( $auth_id );
        return;
    }
    if ( $section === 'presensi' && $auth_id ) {
        qrg_render_guru_portal_presensi( $auth_id );
        return;
    }
    if ( $section === 'izin' && $auth_id ) {
        if ( function_exists( 'qrg_render_guru_portal_izin' ) ) {
            qrg_render_guru_portal_izin( $auth_id );
        }
        return;
    }

    if ( $auth_id ) {
        qrg_render_guru_portal_hub( $auth_id );
        return;
    }

    $goto = isset( $_GET['goto'] ) ? sanitize_key( wp_unslash( $_GET['goto'] ) ) : '';
    if ( ! in_array( $goto, [ 'jadwal', 'gaji', 'presensi', 'tugas', 'izin' ], true ) ) {
        $goto = '';
    }
    qrg_render_guru_portal_login( $error, $goto );
}

function qrg_guru_portal_shell_start( $title, $nav_active = '' ) {
    $site = get_bloginfo( 'name' );
    $nav_active = sanitize_key( (string) $nav_active );
    $GLOBALS['qrg_gp_nav_active'] = $nav_active;
    $has_nav = $nav_active !== '';
    status_header( 200 );
    nocache_headers();
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <meta name="theme-color" content="#0086F0">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <title><?php echo esc_html( $title ); ?> — <?php echo esc_html( $site ); ?></title>
        <style>
            :root {
                --ios-bg: #F2F2F7;
                --ios-card: #FFFFFF;
                --ios-ink: #1C1C1E;
                --ios-secondary: #8E8E93;
                --ios-blue: #007AFF;
                --ios-green: #34C759;
                --ios-teal: #30B0C7;
                --ios-orange: #FF9500;
                --ios-red: #FF3B30;
                --ios-fill: #E5E5EA;
                --ios-sep: rgba(60,60,67,.12);
                --ios-shadow: 0 8px 28px rgba(0,0,0,.06);
                --ios-radius: 16px;
                --ios-safe-b: env(safe-area-inset-bottom, 0px);
                --ios-safe-t: env(safe-area-inset-top, 0px);
                --ios-tabbar-h: 52px;
                --ios-font: -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Helvetica Neue", Helvetica, Arial, sans-serif;
                --gp-accent: var(--ios-blue);
            }
            *, *::before, *::after { box-sizing: border-box; }
            html { -webkit-text-size-adjust: 100%; }
            body {
                margin: 0; padding: 0; min-height: 100dvh;
                font-family: var(--ios-font); background: var(--ios-bg); color: var(--ios-ink);
                line-height: 1.4; -webkit-font-smoothing: antialiased;
            }
            .qrg-gp-app {
                min-height: 100dvh; display: flex; flex-direction: column; padding: 0;
                padding-bottom: var(--ios-safe-b);
            }
            .qrg-gp-app.has-tabbar { padding-bottom: 0; }
            .qrg-gp {
                width: 100%; max-width: 430px; margin: 0 auto; background: var(--ios-bg);
                border-radius: 0; box-shadow: none;
                padding: calc(12px + var(--ios-safe-t)) 16px 28px; flex: 1;
            }
            .qrg-gp-app.has-tabbar .qrg-gp {
                padding-bottom: calc(28px + var(--ios-tabbar-h) + var(--ios-safe-b));
            }
            .qrg-gp.login {
                max-width: 430px; margin: 0 auto; display: flex; flex-direction: column;
                justify-content: center; min-height: 100dvh;
                padding-top: calc(24px + var(--ios-safe-t));
                padding-bottom: calc(28px + var(--ios-safe-b));
            }
            .qrg-gp h1 {
                margin: 0; font-size: 1.75rem; font-weight: 800;
                letter-spacing: -.03em; line-height: 1.15; word-break: break-word;
            }
            .brand {
                font-size: 12px; font-weight: 600; color: var(--ios-secondary); margin: 0 0 4px;
            }
            .sub { color: var(--ios-secondary); margin: 6px 0 0; font-size: 15px; font-weight: 400; }
            .err {
                background: rgba(255,59,48,.1); color: var(--ios-red); border: 0;
                padding: 12px 14px; border-radius: 12px; margin-bottom: 14px; font-size: 14px; font-weight: 500;
            }
            .ok {
                background: rgba(52,199,89,.12); color: #248A3D; border: 0;
                padding: 12px 14px; border-radius: 12px; margin-bottom: 14px; font-size: 14px;
            }
            .btn {
                display: inline-flex; align-items: center; justify-content: center;
                background: var(--ios-blue); color: #fff !important; border: none;
                padding: 12px 18px; border-radius: 980px; font-size: 15px; font-weight: 600;
                cursor: pointer; text-decoration: none; min-height: 44px;
                -webkit-tap-highlight-color: transparent;
            }
            .btn:active { opacity: .85; transform: scale(.98); }
            .btn-muted { background: var(--ios-fill); color: var(--ios-ink) !important; }
            .btn-block { display: flex; width: 100%; border-radius: 14px; }
            .btn-green { background: var(--ios-green); }
            .topbar {
                display: flex; justify-content: space-between; align-items: flex-start;
                gap: 12px; margin-bottom: 18px;
            }
            .topbar .btn { flex-shrink: 0; padding: 8px 14px; font-size: 14px; min-height: 36px; }
            .back {
                display: inline-flex; align-items: center; gap: 4px;
                margin: 0 0 14px; padding: 8px 14px 8px 10px; border-radius: 980px;
                background: var(--ios-fill); color: var(--ios-blue); text-decoration: none;
                font-size: 14px; font-weight: 600; min-height: 36px;
            }
            .ios-hero { margin: 0 0 18px; padding: 4px 2px 2px; }
            .ios-hero h1 { font-size: 2rem; margin-top: 2px; }
            .ios-login-card {
                background: var(--ios-card); border-radius: 20px; padding: 22px 18px;
                box-shadow: var(--ios-shadow); overflow: hidden;
            }
            .qrg-login-stage { position: relative; min-height: 420px; overflow: hidden; }
            .qrg-login-step {
                display: none;
                opacity: 0;
                transform: translateX(28px) scale(.98);
                pointer-events: none;
                will-change: transform, opacity;
            }
            .qrg-login-step.is-active {
                display: block;
                animation: qrgGpLoginIn .42s cubic-bezier(.2,.8,.2,1) forwards;
                pointer-events: auto;
            }
            .qrg-login-step.is-leave-left,
            .qrg-login-step.is-leave-right {
                display: block;
                opacity: 1;
                transform: none;
                pointer-events: none;
                position: absolute;
                left: 0; right: 0; top: 0;
            }
            .qrg-login-step.is-leave-left {
                animation: qrgGpLoginOutLeft .32s ease forwards;
            }
            .qrg-login-step.is-leave-right {
                animation: qrgGpLoginOutRight .32s ease forwards;
            }
            .qrg-login-step.is-active.from-left { animation-name: qrgGpLoginInFromLeft; }
            @keyframes qrgGpLoginIn {
                from { opacity: 0; transform: translateX(28px) scale(.98); }
                to { opacity: 1; transform: translateX(0) scale(1); }
            }
            @keyframes qrgGpLoginInFromLeft {
                from { opacity: 0; transform: translateX(-28px) scale(.98); }
                to { opacity: 1; transform: translateX(0) scale(1); }
            }
            @keyframes qrgGpLoginOutLeft {
                from { opacity: 1; transform: translateX(0) scale(1); }
                to { opacity: 0; transform: translateX(-28px) scale(.98); }
            }
            @keyframes qrgGpLoginOutRight {
                from { opacity: 1; transform: translateX(0) scale(1); }
                to { opacity: 0; transform: translateX(28px) scale(.98); }
            }
            .qrg-login-step__title {
                margin: 0 0 6px; font-size: 20px; font-weight: 700;
                letter-spacing: -.02em; text-align: center;
            }
            .qrg-login-step__sub {
                margin: 0 0 18px; font-size: 14px; color: var(--ios-secondary);
                text-align: center; line-height: 1.4;
            }
            .qrg-login-back {
                display: inline-flex; align-items: center; gap: 6px; margin: 0 0 14px;
                border: 0; background: var(--ios-fill); padding: 8px 14px 8px 10px;
                border-radius: 980px; font-size: 15px; color: var(--ios-ink); font-weight: 600;
                cursor: pointer; min-height: 36px; line-height: 1;
                -webkit-tap-highlight-color: transparent;
                transition: transform .14s cubic-bezier(.2,.8,.2,1), background .15s;
            }
            .qrg-login-back:active { transform: scale(.96); background: #D1D1D6; }
            .qrg-login-back svg {
                width: 18px; height: 18px; flex-shrink: 0; stroke: var(--ios-blue); fill: none;
                stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round;
                transition: transform .14s ease;
            }
            .qrg-login-back:active svg { transform: translateX(-2px); }
            .calc-screen.is-pulse { animation: qrgGpPulse .45s ease; }
            @keyframes qrgGpPulse {
                0%, 100% { transform: scale(1); box-shadow: none; }
                40% { transform: scale(1.02); box-shadow: 0 0 0 3px rgba(0,122,255,.35); }
            }
            .calc-pad.is-enter button {
                animation: qrgGpKeyIn .45s cubic-bezier(.2,.8,.2,1) both;
            }
            .calc-pad.is-enter button:nth-child(1) { animation-delay: .02s; }
            .calc-pad.is-enter button:nth-child(2) { animation-delay: .05s; }
            .calc-pad.is-enter button:nth-child(3) { animation-delay: .08s; }
            .calc-pad.is-enter button:nth-child(4) { animation-delay: .11s; }
            .calc-pad.is-enter button:nth-child(5) { animation-delay: .14s; }
            .calc-pad.is-enter button:nth-child(6) { animation-delay: .17s; }
            .calc-pad.is-enter button:nth-child(7) { animation-delay: .2s; }
            .calc-pad.is-enter button:nth-child(8) { animation-delay: .23s; }
            .calc-pad.is-enter button:nth-child(9) { animation-delay: .26s; }
            .calc-pad.is-enter button:nth-child(10) { animation-delay: .29s; }
            .calc-pad.is-enter button:nth-child(11) { animation-delay: .32s; }
            .calc-pad.is-enter button:nth-child(12) { animation-delay: .35s; }
            .calc-pad.is-enter button:nth-child(13) { animation-delay: .38s; }
            @keyframes qrgGpKeyIn {
                from { opacity: 0; transform: translateY(12px) scale(.9); }
                to { opacity: 1; transform: none; }
            }
            @media (prefers-reduced-motion: reduce) {
                .qrg-login-step.is-active,
                .qrg-login-step.is-leave-left,
                .qrg-login-step.is-leave-right,
                .calc-pad.is-enter button,
                .calc-screen.is-pulse { animation: none !important; opacity: 1; transform: none; }
            }
            .ios-section { margin: 0 0 22px; }
            .ios-section__label {
                margin: 0 0 8px 12px; font-size: 13px; font-weight: 400;
                color: var(--ios-secondary); text-transform: uppercase; letter-spacing: .02em;
            }
            .ios-list, .menu {
                display: grid; gap: 0; background: var(--ios-card);
                border-radius: var(--ios-radius); overflow: hidden; box-shadow: var(--ios-shadow);
            }
            .card-link, .ios-row {
                display: grid; grid-template-columns: 44px 1fr auto; align-items: center;
                gap: 12px; text-decoration: none; color: inherit; padding: 14px;
                background: var(--ios-card); border: 0; border-bottom: 1px solid var(--ios-sep);
                -webkit-tap-highlight-color: transparent;
            }
            button.card-link {
                width: 100%; font: inherit; text-align: left; cursor: pointer;
            }
            .card-link:last-child, .ios-row:last-child { border-bottom: 0; }
            .card-link:active, .ios-row:active { background: #E5E5EA; }
            .card-link::after, .ios-row::after {
                content: ""; width: 8px; height: 8px;
                border-right: 2px solid #C7C7CC; border-top: 2px solid #C7C7CC;
                transform: rotate(45deg); margin-left: 4px;
            }
            .ios-icon {
                width: 40px; height: 40px; border-radius: 11px;
                display: inline-flex; align-items: center; justify-content: center;
                color: #fff; flex-shrink: 0;
            }
            .ios-icon svg { display: block; width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; }
            .ios-icon--jadwal { background: linear-gradient(180deg, #64D2FF, #007AFF); }
            .ios-icon--gaji { background: linear-gradient(180deg, #63E6BE, #30B0C7); }
            .ios-icon--presensi { background: linear-gradient(180deg, #FFD60A, #FF9500); }
            .ios-icon--photo { background: linear-gradient(180deg, #5AC8FA, #007AFF); }
            .ios-icon--lock { background: linear-gradient(180deg, #FF9F0A, #FF375F); }
            .ios-avatar {
                width: 88px; height: 88px; border-radius: 50%; margin: 0 auto 12px;
                background: linear-gradient(180deg, #64D2FF, #007AFF); color: #fff;
                display: flex; align-items: center; justify-content: center;
                font-size: 34px; font-weight: 800; overflow: hidden;
            }
            .ios-avatar.has-photo { background: #E5E5EA; }
            .ios-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
            .qrg-gp-profile-card {
                padding: 22px 16px; text-align: center;
            }
            .qrg-gp-avatar-toggle,
            .qrg-gp-security-toggle {
                width: 100%; text-align: left; cursor: pointer; font: inherit;
            }
            .qrg-gp-avatar-toggle::after,
            .qrg-gp-security-toggle::after {
                content: ""; width: 8px; height: 8px;
                border-right: 2px solid #C7C7CC; border-top: 2px solid #C7C7CC;
                transform: rotate(45deg); margin-left: 4px; transition: transform .2s;
            }
            .qrg-gp-avatar-toggle.is-open::after,
            .qrg-gp-security-toggle.is-open::after { transform: rotate(135deg); }
            .qrg-gp-avatar-panel,
            .qrg-gp-security-panel {
                display: grid; grid-template-rows: 0fr; transition: grid-template-rows .35s ease;
                margin-top: 0;
            }
            .qrg-gp-avatar-panel.is-open,
            .qrg-gp-security-panel.is-open { grid-template-rows: 1fr; margin-top: 10px; }
            .qrg-gp-avatar-panel__inner,
            .qrg-gp-security-panel__inner { overflow: hidden; min-height: 0; }
            .qrg-gp-avatar-card,
            .qrg-gp-pin-card {
                background: var(--ios-card); border-radius: var(--ios-radius);
                padding: 16px; box-shadow: var(--ios-shadow);
            }
            .qrg-gp-avatar-edit {
                position: relative; width: 88px; margin: 0 auto 14px;
            }
            .qrg-gp-avatar-edit .ios-avatar { margin-bottom: 0; }
            .qrg-gp-avatar-edit__cam {
                position: absolute; right: -2px; bottom: -2px;
                width: 32px; height: 32px; border-radius: 50%;
                background: var(--ios-blue); color: #fff;
                display: inline-flex; align-items: center; justify-content: center;
                box-shadow: 0 2px 8px rgba(0,122,255,.35); cursor: pointer;
            }
            .qrg-gp-avatar-edit__cam:active { transform: scale(.94); }
            .qrg-gp-avatar-edit__cam svg { width: 16px; height: 16px; display: block; }
            .qrg-gp-avatar-edit input[type=file] {
                position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; clip: rect(0,0,0,0);
            }
            .qrg-gp-avatar-actions { display: flex; gap: 8px; justify-content: center; margin-bottom: 10px; }
            .qrg-gp-avatar-status { text-align: center; font-size: 13px; color: var(--ios-secondary); margin: 0 0 8px; }
            .qrg-gp-avatar-rules {
                background: #F2F2F7; border-radius: 12px; padding: 12px 14px; margin-top: 4px;
            }
            .qrg-gp-avatar-rules__title { margin: 0 0 8px; font-size: 13px; font-weight: 700; }
            .qrg-gp-avatar-rules ul { margin: 0; padding-left: 18px; font-size: 12px; color: var(--ios-secondary); line-height: 1.45; }
            .qrg-gp-avatar-rules li { margin-bottom: 4px; }
            .qrg-gp-pin-steps {
                display: flex; gap: 6px; justify-content: center; margin: 0 0 12px; flex-wrap: wrap;
            }
            .qrg-gp-pin-step {
                font-size: 11px; font-weight: 600; color: var(--ios-secondary);
                background: var(--ios-fill); padding: 6px 10px; border-radius: 980px;
            }
            .qrg-gp-pin-step.is-on { background: rgba(0,122,255,.12); color: var(--ios-blue); }
            .qrg-gp-pin-step.is-done { background: rgba(52,199,89,.14); color: #248A3D; }
            .qrg-gp-pin-nav { margin: 0 0 8px; }
            .qrg-gp-pin-nav button {
                appearance: none; border: 0; background: none; color: var(--ios-blue);
                font-size: 14px; font-weight: 600; cursor: pointer; padding: 4px 0;
            }
            .qrg-gp-pin-err {
                display: none; background: rgba(255,59,48,.1); color: var(--ios-red);
                border-radius: 10px; padding: 10px 12px; font-size: 13px; margin: 0 0 10px;
            }
            .qrg-gp-pin-err.is-on { display: block; }
            .qrg-gp-pin-hint {
                margin: 12px 0 0; font-size: 12px; color: var(--ios-secondary); text-align: center; line-height: 1.4;
            }
            .qrg-gp-alert {
                position: fixed; inset: 0; z-index: 10050; display: none;
                align-items: center; justify-content: center; padding: 24px;
                background: rgba(0,0,0,.35); backdrop-filter: blur(4px);
            }
            .qrg-gp-alert.is-open { display: flex; }
            .qrg-gp-alert__card {
                width: min(320px, 100%); background: rgba(255,255,255,.96);
                border-radius: 16px; overflow: hidden; box-shadow: 0 16px 40px rgba(0,0,0,.18);
            }
            .qrg-gp-alert__body { padding: 22px 18px 12px; text-align: center; }
            .qrg-gp-alert__title { margin: 0 0 6px; font-size: 17px; font-weight: 700; }
            .qrg-gp-alert__text { margin: 0; font-size: 13px; color: var(--ios-secondary); line-height: 1.4; }
            .qrg-gp-alert__actions {
                display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid var(--ios-sep);
            }
            .qrg-gp-alert__actions button {
                appearance: none; border: 0; background: transparent; min-height: 48px;
                font-size: 16px; font-weight: 600; cursor: pointer;
            }
            .qrg-gp-alert__cancel { color: var(--ios-blue); border-right: 1px solid var(--ios-sep); }
            .qrg-gp-alert__danger { color: var(--ios-red); font-weight: 700; }
            .dana-grid__ico--akun { background: linear-gradient(145deg, #5F27CD, #341F97); }
            .card-link strong, .ios-row strong {
                display: block; font-size: 16px; font-weight: 600; margin: 0 0 2px; color: var(--ios-ink);
            }
            .card-link span, .ios-row span {
                font-size: 13px; color: var(--ios-secondary); line-height: 1.35;
            }
            .field { margin-bottom: 12px; }
            .field label {
                display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px; color: var(--ios-secondary);
            }
            .field input, .field select, .field textarea {
                width: 100%; padding: 14px 16px; border: 0; border-radius: 12px;
                font-size: 16px; background: var(--ios-fill); font-family: inherit; color: var(--ios-ink);
            }
            .field input:focus, .field select:focus, .field textarea:focus {
                outline: none; background: #fff; box-shadow: inset 0 0 0 2px rgba(0,122,255,.35);
            }
            .calc { max-width: 320px; width: 100%; margin: 0 auto; }
            .calc-screen {
                background: #1C1C1E; color: #F2F2F7; border-radius: 16px; padding: 18px 16px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 28px; letter-spacing: 6px; text-align: right; min-height: 44px; margin-bottom: 14px;
                word-break: break-all; line-height: 1.2;
                transition: box-shadow .25s, transform .2s;
            }
            .calc-screen.empty {
                color: #8E8E93; letter-spacing: 1px; font-size: 15px; text-align: center; font-family: var(--ios-font);
            }
            .calc-screen--nip:not(.empty) {
                letter-spacing: 2px; font-size: 22px; text-align: center; font-family: var(--ios-font); font-weight: 700;
            }
            .calc-hint { text-align: center; font-size: 13px; color: var(--ios-secondary); margin: 0 0 12px; }
            .calc-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .calc-pad button {
                appearance: none; border: none; border-radius: 50%; aspect-ratio: 1;
                width: 100%; max-width: 84px; margin: 0 auto; font-size: 24px; font-weight: 400;
                background: var(--ios-fill); color: var(--ios-ink); cursor: pointer;
                touch-action: manipulation; -webkit-tap-highlight-color: transparent;
                transition: transform .1s, background .12s;
            }
            .calc-pad button:active { background: #D1D1D6; transform: scale(.92); }
            .calc-pad button.key-fn { background: #D1D1D6; font-size: 15px; font-weight: 600; }
            .calc-pad button.key-ok {
                background: var(--ios-blue); color: #fff; border-radius: 18px; aspect-ratio: auto;
                max-width: none; grid-column: 1 / -1; font-size: 16px; font-weight: 600; min-height: 48px;
            }
            .calc-pad button.key-ok:disabled { opacity: .55; cursor: not-allowed; }
            .day { margin-bottom: 12px; border-radius: var(--ios-radius); overflow: hidden; background: var(--ios-card); box-shadow: var(--ios-shadow); }
            .day__title {
                margin: 0; padding: 12px 14px; background: rgba(0,122,255,.08); color: var(--ios-blue);
                font-size: 13px; font-weight: 700;
            }
            .day__title.is-today { background: var(--ios-blue); color: #fff; }
            .qrg-gp--jadwal { padding-bottom: 28px; }
            .qrg-gp--dana { padding: 0; background: #F5F6F8; max-width: 430px; }
            .qrg-gp-app.has-tabbar .qrg-gp--dana {
                padding-bottom: calc(var(--ios-tabbar-h) + var(--ios-safe-b));
            }
            .dana-top {
                background: linear-gradient(165deg, #1AA3FF 0%, #0086F0 48%, #0070D6 100%);
                color: #fff; padding: calc(14px + var(--ios-safe-t)) 16px 48px; position: relative;
            }
            .dana-top__row { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
            .dana-top__brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
            .dana-top__logo {
                width: 36px; height: 36px; border-radius: 50%;
                background: rgba(255,255,255,.2); border: 1.5px solid rgba(255,255,255,.55);
                display: inline-flex; align-items: center; justify-content: center;
                font-weight: 800; font-size: 15px; flex-shrink: 0; color: #fff;
                text-decoration: none; overflow: hidden; padding: 0;
            }
            .dana-top__logo.has-photo { background: rgba(255,255,255,.35); }
            .dana-top__logo img { width: 100%; height: 100%; object-fit: cover; display: block; }
            .dana-top__brand strong { display: block; font-size: 15px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .dana-top__brand span { display: block; font-size: 12px; opacity: .85; margin-top: 1px; }
            .dana-top__bell {
                width: 40px; height: 40px; border-radius: 12px;
                border: 1.5px solid rgba(255,255,255,.45);
                background: transparent; color: #fff;
                display: inline-flex; align-items: center; justify-content: center;
                text-decoration: none; flex-shrink: 0;
            }
            .dana-top__bell svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
            .dana-balance { margin-bottom: 18px; }
            .dana-balance__label { margin: 0 0 4px; font-size: 12px; opacity: .9; font-weight: 500; }
            .dana-balance__row { display: flex; align-items: center; gap: 10px; }
            .dana-balance__amount {
                margin: 0; font-size: 1.65rem; font-weight: 800;
                letter-spacing: -.03em; line-height: 1.1;
            }
            .dana-balance__meta { margin: 6px 0 0; font-size: 12px; opacity: .88; }
            .dana-actions {
                display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
            }
            .dana-actions a {
                display: flex; flex-direction: column; align-items: center; gap: 8px;
                text-decoration: none; color: #fff; font-size: 11px; font-weight: 600;
                -webkit-tap-highlight-color: transparent;
            }
            .dana-actions a:active { opacity: .85; }
            .dana-actions__ico {
                width: 48px; height: 48px; border-radius: 14px;
                border: 1.5px solid rgba(255,255,255,.5);
                background: rgba(255,255,255,.12);
                display: flex; align-items: center; justify-content: center;
            }
            .dana-actions__ico svg {
                width: 22px; height: 22px; stroke: #fff; fill: none; stroke-width: 1.8;
                stroke-linecap: round; stroke-linejoin: round;
            }
            .dana-sheet {
                margin-top: -28px; background: #fff; border-radius: 24px 24px 0 0;
                padding: 16px 16px 24px; position: relative; z-index: 1;
                min-height: 50vh; box-shadow: 0 -8px 24px rgba(0,70,140,.08);
            }
            .dana-sheet__label {
                margin: 0 0 12px 4px; font-size: 13px; font-weight: 600;
                color: #8E8E93; text-transform: uppercase; letter-spacing: .03em;
            }
            .dana-banner {
                position: relative; margin: 0 0 18px; border-radius: 18px;
                overflow: hidden; touch-action: pan-y;
            }
            .dana-banner__track {
                display: flex; transition: transform .45s cubic-bezier(.2,.8,.2,1); will-change: transform;
            }
            .dana-banner__slide {
                flex: 0 0 100%; min-width: 100%; position: relative; display: block;
                text-decoration: none; color: #fff; min-height: 132px;
                padding: 18px 18px 28px; box-sizing: border-box;
            }
            .dana-banner__slide--1 {
                background:
                    radial-gradient(120px 80px at 90% 20%, rgba(255,255,255,.28), transparent 60%),
                    linear-gradient(135deg, #0EA5E9 0%, #0369A1 100%);
            }
            .dana-banner__slide--2 {
                background:
                    radial-gradient(120px 80px at 90% 20%, rgba(255,255,255,.25), transparent 60%),
                    linear-gradient(135deg, #10B981 0%, #047857 100%);
            }
            .dana-banner__slide--3 {
                background:
                    radial-gradient(120px 80px at 90% 20%, rgba(255,255,255,.25), transparent 60%),
                    linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
            }
            .dana-banner__kicker {
                display: inline-flex; margin: 0 0 8px; padding: 3px 8px; border-radius: 980px;
                background: rgba(255,255,255,.2); font-size: 10px; font-weight: 700;
                letter-spacing: .04em; text-transform: uppercase;
            }
            .dana-banner__title {
                margin: 0 0 6px; font-size: 18px; font-weight: 800;
                letter-spacing: -.02em; line-height: 1.2; max-width: 78%;
            }
            .dana-banner__sub {
                margin: 0; font-size: 12px; opacity: .92; line-height: 1.35; max-width: 78%;
            }
            .dana-banner__cta {
                position: absolute; right: 16px; bottom: 16px; display: inline-flex; align-items: center;
                min-height: 30px; padding: 0 12px; border-radius: 980px; background: #fff;
                color: #0F172A; font-size: 11px; font-weight: 700;
            }
            .dana-banner__dots {
                position: absolute; left: 0; right: 0; bottom: 8px;
                display: flex; justify-content: center; gap: 5px; pointer-events: none; z-index: 2;
            }
            .dana-banner__dots span {
                width: 6px; height: 6px; border-radius: 50%; background: rgba(255,255,255,.4);
                transition: width .25s, background .25s;
            }
            .dana-banner__dots span.is-on { width: 16px; border-radius: 980px; background: #fff; }
            .dana-promo {
                display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 16px;
                background: linear-gradient(90deg, #FFF7ED, #FFF1E0); text-decoration: none; color: inherit;
                margin-bottom: 20px;
            }
            .dana-promo__ico {
                width: 44px; height: 44px; border-radius: 12px; background: #FF8A3D; color: #fff;
                display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex-shrink: 0;
            }
            .dana-promo__body { flex: 1; min-width: 0; }
            .dana-promo__body strong { display: block; font-size: 14px; font-weight: 700; color: #1C1C1E; }
            .dana-promo__body span { display: block; font-size: 12px; color: #E67E22; margin-top: 2px; }
            .dana-promo__btn {
                flex-shrink: 0; background: #0086F0; color: #fff; border: 0; border-radius: 980px;
                padding: 8px 14px; font-size: 12px; font-weight: 700; letter-spacing: .02em;
            }
            .dana-grid {
                display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px 8px; margin-bottom: 18px;
            }
            .dana-grid a {
                display: flex; flex-direction: column; align-items: center; gap: 8px;
                text-decoration: none; color: #1C1C1E; font-size: 11px; font-weight: 600;
                text-align: center; line-height: 1.25; -webkit-tap-highlight-color: transparent;
            }
            .dana-grid a:active { opacity: .8; }
            .dana-grid__ico {
                width: 48px; height: 48px; border-radius: 16px;
                display: flex; align-items: center; justify-content: center; color: #fff;
            }
            .dana-grid__ico svg {
                width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 1.8;
                stroke-linecap: round; stroke-linejoin: round;
            }
            .dana-grid__ico--jadwal { background: linear-gradient(145deg, #1DD1A1, #10AC84); }
            .dana-grid__ico--gaji { background: linear-gradient(145deg, #54A0FF, #2E86DE); }
            .dana-grid__ico--presensi { background: linear-gradient(145deg, #F368E0, #FF9FF3); }
            .dana-grid__ico--izin { background: linear-gradient(145deg, #FF9F43, #EE5A24); }
            .dana-logout {
                display: flex; width: 100%; align-items: center; justify-content: center;
                min-height: 48px; border-radius: 14px; background: #F2F2F7; color: var(--ios-red) !important;
                text-decoration: none; font-size: 15px; font-weight: 600; margin-top: 4px;
            }
            .dana-logout:active { background: #E5E5EA; }
            @media (prefers-reduced-motion: reduce) {
                .dana-banner__track { transition: none; }
            }
            .jadwal-hero {
                margin-bottom: 14px; padding: 16px; border-radius: 16px;
                background: linear-gradient(135deg, #1AA3FF 0%, #007AFF 55%, #5AC8FA 100%);
                color: #fff; box-shadow: 0 8px 24px rgba(0,122,255,.22);
            }
            .jadwal-hero h1 { color: #fff; font-size: 1.35rem; margin: 0 0 4px; }
            .jadwal-hero .sub { color: rgba(255,255,255,.88); margin: 0; }
            .jadwal-nav-wrap {
                position: sticky; top: 0; z-index: 20; margin: 0 -4px 14px; padding: 8px 4px;
                background: linear-gradient(180deg, var(--ios-bg) 75%, rgba(242,242,247,0));
            }
            .jadwal-nav {
                display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px;
                scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; scrollbar-width: none;
            }
            .jadwal-nav::-webkit-scrollbar { display: none; }
            .jadwal-nav a {
                flex: 0 0 auto; scroll-snap-align: start;
                padding: 8px 14px; border-radius: 999px; font-size: 12px; font-weight: 700;
                text-decoration: none; color: var(--ios-blue); background: var(--ios-fill); border: 0;
            }
            .jadwal-nav a.is-active { background: var(--ios-blue); color: #fff; box-shadow: 0 4px 12px rgba(0,122,255,.25); }
            .jadwal-nav a.is-today:not(.is-active) { box-shadow: inset 0 0 0 1.5px rgba(0,122,255,.45); color: var(--ios-blue); background: #fff; }
            .jadwal-feed { display: flex; flex-direction: column; gap: 14px; }
            .day--reveal {
                opacity: 0; transform: translateY(16px);
                transition: opacity .45s ease, transform .45s ease;
                border: none; border-radius: 16px; overflow: hidden;
                box-shadow: var(--ios-shadow); background: var(--ios-card);
                scroll-margin-top: 58px;
            }
            .day--reveal.is-visible { opacity: 1; transform: none; }
            .jadwal-empty {
                text-align: center; padding: 28px 16px; border-radius: 16px;
                background: var(--ios-card); box-shadow: var(--ios-shadow); color: var(--ios-secondary);
            }
            .jadwal-empty__icon {
                width: 52px; height: 52px; margin: 0 auto 12px; border-radius: 50%;
                background: rgba(0,122,255,.12); color: var(--ios-blue); font-size: 24px; line-height: 52px;
            }
            .jadwal-empty strong { display: block; color: var(--ios-ink); font-size: 15px; margin-bottom: 6px; }
            .day--reveal .day__title {
                padding: 12px 14px; font-size: 12px; letter-spacing: .04em;
                text-transform: uppercase; background: transparent; color: var(--ios-secondary);
                border-bottom: 1px solid var(--ios-sep);
            }
            .day--reveal .day__title.is-today {
                background: rgba(0,122,255,.08); color: var(--ios-blue); border-bottom-color: rgba(0,122,255,.12);
            }
            .day--reveal .day__count { float: right; font-weight: 600; opacity: .85; font-size: 11px; }
            .slot-card {
                display: grid; grid-template-columns: 64px 1fr; gap: 12px;
                padding: 14px; border-top: 1px solid var(--ios-sep); background: var(--ios-card);
            }
            .slot-card:first-of-type { border-top: none; }
            .slot-card__time {
                display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
                padding-top: 2px; font-size: 11px; font-weight: 800; color: var(--ios-blue);
                line-height: 1.35; text-align: center;
            }
            .slot-card__time::before {
                content: ''; width: 8px; height: 8px; border-radius: 50%;
                background: var(--ios-blue); margin-bottom: 6px; box-shadow: 0 0 0 3px rgba(0,122,255,.15);
            }
            .slot-card__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
            .slot-card__mapel { margin: 0; font-size: 15px; font-weight: 700; color: var(--ios-ink); line-height: 1.3; flex: 1; min-width: 0; }
            .slot-card__meta { margin: 4px 0 0; font-size: 12px; color: var(--ios-secondary); line-height: 1.4; }
            .slot-card__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 10px; }
            .chip {
                display: inline-flex; align-items: center; justify-content: center;
                min-height: 30px; padding: 4px 11px; border-radius: 999px;
                font-size: 11px; font-weight: 600; line-height: 1.2; text-decoration: none; border: 0;
            }
            .chip--jurnal { background: rgba(255,149,0,.14); color: #C93400; }
            .chip--tugas { background: rgba(0,122,255,.12); color: var(--ios-blue); }
            .chip--tugas-exist { background: rgba(0,122,255,.1); color: var(--ios-blue); max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .chip--jurnal-exist { background: rgba(255,149,0,.14); color: #C93400; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .jadwal-scroll-hint { text-align: center; font-size: 11px; color: var(--ios-secondary); padding: 8px 0 0; }
            html.qrg-jadwal-smooth { scroll-behavior: smooth; }
            .mon-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
            .mon-stat { background: var(--ios-card); box-shadow: var(--ios-shadow); border-radius: 12px; padding: 10px 8px; text-align: center; }
            .mon-stat__n { display: block; font-size: 1.15rem; font-weight: 800; color: var(--ios-blue); }
            .mon-stat__l { font-size: 11px; color: var(--ios-secondary); }
            .jawab-box { background: var(--ios-bg); border-radius: 12px; padding: 12px 14px; margin: 10px 0; font-size: 14px; white-space: pre-wrap; word-break: break-word; }
            .jawab-file { display: inline-flex; margin-top: 8px; font-size: 13px; font-weight: 600; color: var(--ios-blue); }
            .section-label { margin: 22px 0 8px 4px; font-size: 13px; font-weight: 400; color: var(--ios-secondary); text-transform: uppercase; letter-spacing: .02em; }
            .slip-card { border-radius: var(--ios-radius); overflow: hidden; background: var(--ios-card); box-shadow: var(--ios-shadow); }
            .slip-head { padding: 14px 16px; background: rgba(0,122,255,.06); border-bottom: 1px solid var(--ios-sep); }
            .slip-head strong { display: block; font-size: 1.05rem; margin-bottom: 4px; }
            .slip-hadir { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 12px 14px; background: var(--ios-bg); border-bottom: 1px solid var(--ios-sep); }
            .slip-hadir__item { text-align: center; }
            .slip-hadir__num { display: block; font-size: 1.1rem; font-weight: 800; color: var(--ios-blue); }
            .slip-hadir__lbl { font-size: 11px; color: var(--ios-secondary); }
            .slip-lines { padding: 4px 0; }
            .slip-line {
                display: grid; grid-template-columns: 1fr auto; gap: 8px 12px;
                padding: 12px 16px; border-bottom: 1px solid var(--ios-sep); align-items: start;
            }
            .slip-line:last-child { border-bottom: none; }
            .slip-line__label { font-size: 14px; font-weight: 600; color: var(--ios-ink); }
            .slip-line__desc { font-size: 12px; color: var(--ios-secondary); margin-top: 3px; line-height: 1.4; }
            .slip-line__amt { font-size: 14px; font-weight: 700; white-space: nowrap; text-align: right; }
            .slip-line__amt.is-plus { color: #248A3D; }
            .slip-line__amt.is-minus { color: var(--ios-red); }
            .slip-line__amt.is-zero { color: var(--ios-secondary); font-weight: 600; }
            .slip-total {
                display: flex; justify-content: space-between; align-items: center; gap: 12px;
                padding: 14px 16px; background: var(--ios-blue); color: #fff;
            }
            .slip-total span { font-size: 13px; opacity: .9; }
            .slip-total strong { font-size: 1.15rem; }
            .slip-note { padding: 12px 16px; font-size: 12px; color: var(--ios-secondary); background: var(--ios-bg); border-top: 1px solid var(--ios-sep); }
            .list { display: grid; gap: 10px; }
            .row-card {
                border: 0; border-radius: var(--ios-radius); padding: 14px; background: var(--ios-card);
                box-shadow: var(--ios-shadow);
            }
            .row-card strong { display: block; font-size: 15px; margin-bottom: 4px; }
            .row-card .meta { font-size: 13px; color: var(--ios-secondary); }
            .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
            .badge-ok { background: rgba(52,199,89,.14); color: #1B7A31; }
            .badge-wait { background: rgba(255,149,0,.14); color: #C93400; }
            .badge-info { background: rgba(0,122,255,.12); color: var(--ios-blue); }
            .empty { color: var(--ios-secondary); font-size: 14px; padding: 18px; text-align: center; background: var(--ios-card); border-radius: var(--ios-radius); box-shadow: var(--ios-shadow); }
            table.data { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; background: var(--ios-card); border-radius: var(--ios-radius); overflow: hidden; box-shadow: var(--ios-shadow); }
            table.data th { background: var(--ios-blue); color: #fff; text-align: left; padding: 10px 12px; }
            table.data td { padding: 10px 12px; border-bottom: 1px solid var(--ios-sep); }
            .ios-tabbar {
                position: fixed; left: 50%; transform: translateX(-50%); bottom: 0;
                width: min(430px, 100%); z-index: 10040;
                display: grid; grid-template-columns: repeat(4, 1fr); gap: 0;
                padding: 4px 6px calc(4px + var(--ios-safe-b));
                background: rgba(249,249,249,.92);
                backdrop-filter: saturate(180%) blur(20px);
                -webkit-backdrop-filter: saturate(180%) blur(20px);
                border-top: 0.5px solid rgba(60,60,67,.18);
                min-height: calc(var(--ios-tabbar-h) + var(--ios-safe-b));
            }
            .ios-tabbar a {
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                gap: 2px; text-decoration: none; color: #8E8E93;
                font-size: 10px; font-weight: 500; min-height: var(--ios-tabbar-h);
                -webkit-tap-highlight-color: transparent;
            }
            .ios-tabbar a:active { transform: scale(.96); }
            .ios-tabbar a.is-active { color: var(--ios-blue); }
            .ios-tabbar a svg {
                width: 24px; height: 24px; stroke: currentColor; fill: none; stroke-width: 1.6;
                stroke-linecap: round; stroke-linejoin: round;
            }
            .ios-tabbar a.is-active svg { stroke-width: 2; }
            .loader {
                position: fixed; inset: 0; z-index: 99999; display: flex; align-items: center; justify-content: center;
                background: rgba(242,242,247,.92); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
                opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .2s ease;
            }
            .loader.is-on { opacity: 1; visibility: visible; pointer-events: auto; }
            .loader__card {
                width: min(260px, calc(100vw - 48px)); background: var(--ios-card); border-radius: 18px;
                padding: 24px; text-align: center; box-shadow: var(--ios-shadow);
            }
            .loader__spin {
                width: 42px; height: 42px; margin: 0 auto 12px; border-radius: 50%;
                border: 3px solid var(--ios-fill); border-top-color: var(--ios-blue); animation: gpSpin .7s linear infinite;
            }
            @keyframes gpSpin { to { transform: rotate(360deg); } }
            @media (max-width: 640px) {
                .topbar { flex-direction: row; align-items: flex-start; }
                .slot-card { grid-template-columns: 1fr; gap: 8px; }
                .slot-card__time {
                    flex-direction: row; justify-content: flex-start; gap: 8px;
                    text-align: left; font-size: 12px; padding-bottom: 4px;
                    border-bottom: 1px dashed var(--ios-sep); margin-bottom: 2px;
                }
                .slot-card__time::before { margin-bottom: 0; }
                .slip-hadir { grid-template-columns: repeat(2, 1fr); }
                .mon-stats { grid-template-columns: repeat(2, 1fr); }
                table.data thead { display: none; }
                table.data, table.data tbody, table.data tr, table.data td { display: block; width: 100%; }
                table.data tr { border: 0; border-radius: var(--ios-radius); padding: 10px; margin-bottom: 8px; background: var(--ios-card); box-shadow: var(--ios-shadow); }
                table.data td { border: none; padding: 4px 0; display: flex; justify-content: space-between; gap: 8px; }
                table.data td::before { content: attr(data-label); font-size: 12px; color: var(--ios-secondary); font-weight: 600; }
            }
        </style>
    </head>
    <body>
    <div class="qrg-gp-app<?php echo $has_nav ? ' has-tabbar' : ''; ?>">
    <?php
}

/**
 * @param string $active beranda|jadwal|gaji|akun
 */
function qrg_guru_portal_render_tabbar( $active = 'beranda' ) {
    $active = sanitize_key( (string) $active );
    $akun_url = add_query_arg( 'tab', 'akun', qrg_get_guru_portal_url() );
    $items  = [
        'beranda'  => [
            'label' => 'Beranda',
            'url'   => qrg_get_guru_portal_url(),
            'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10.5L12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5z"/></svg>',
        ],
        'jadwal'   => [
            'label' => 'Jadwal',
            'url'   => qrg_get_guru_portal_url( 'jadwal' ),
            'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
        ],
        'gaji'     => [
            'label' => 'Gaji',
            'url'   => qrg_get_guru_portal_url( 'gaji' ),
            'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg>',
        ],
        'akun' => [
            'label' => 'Akun',
            'url'   => $akun_url,
            'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        ],
    ];
    echo '<nav class="ios-tabbar" aria-label="Navigasi portal guru">';
    foreach ( $items as $key => $item ) {
        $attrs = ' href="' . esc_url( $item['url'] ) . '"';
        if ( $key === $active ) {
            $attrs = ' class="is-active" aria-current="page"' . $attrs;
        }
        echo '<a' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<span>' . esc_html( $item['label'] ) . '</span>';
        echo '</a>';
    }
    echo '</nav>';
}

function qrg_guru_portal_shell_end() {
    $site = get_bloginfo( 'name' );
    $nav_active = isset( $GLOBALS['qrg_gp_nav_active'] ) ? sanitize_key( (string) $GLOBALS['qrg_gp_nav_active'] ) : '';
    if ( $nav_active !== '' ) {
        qrg_guru_portal_render_tabbar( $nav_active );
    }
    ?>
    </div>
    <div class="loader" id="qrg-gp-loader" aria-hidden="true">
        <div class="loader__card">
            <div class="loader__spin"></div>
            <p style="margin:0;font-weight:700;font-size:14px;" id="qrg-gp-loader-title">Memuat…</p>
            <p style="margin:6px 0 0;font-size:12px;color:var(--ios-secondary);"><?php echo esc_html( $site ); ?></p>
        </div>
    </div>
    <script>
    (function(){
        var el=document.getElementById('qrg-gp-loader');
        var title=document.getElementById('qrg-gp-loader-title');
        function show(msg){ if(title&&msg) title.textContent=msg; if(el){ el.classList.add('is-on'); el.setAttribute('aria-hidden','false'); } }
        function hide(){ if(el){ el.classList.remove('is-on'); el.setAttribute('aria-hidden','true'); } }
        document.addEventListener('click',function(e){
            var a=e.target.closest&&e.target.closest('a');
            if(!a||e.metaKey||e.ctrlKey||e.shiftKey||a.target==='_blank') return;
            var href=a.getAttribute('href'); if(!href||href.charAt(0)==='#') return;
            try{ var u=new URL(href,location.href); if(u.origin!==location.origin) return;
                if(!/\/sekolah\/guru(\/|$)/i.test(u.pathname) && !/\/guru(\/|$)/i.test(u.pathname)) return;
                show(/logout/i.test(u.search)?'Keluar…':'Memuat halaman…');
            }catch(err){}
        },true);
        document.addEventListener('submit',function(){ show('Memproses…'); },true);
        window.addEventListener('pageshow', hide);
        window.addEventListener('load', hide);
    })();
    </script>
    </body></html>
    <?php
    unset( $GLOBALS['qrg_gp_nav_active'] );
}

function qrg_render_guru_portal_login( $error = '', $goto = '' ) {
    $site       = get_bloginfo( 'name' );
    $posted_nip = isset( $_POST['qrg_guru_identitas'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_guru_identitas'] ) ) : '';
    $posted_nip = preg_replace( '/\D+/', '', $posted_nip );
    $start_step = ( $error && $posted_nip !== '' ) ? 2 : 1;
    // Jika error karena NIP tidak ada, tetap di langkah NIP.
    if ( $error && ( false !== stripos( $error, 'NIP tidak ditemukan' ) || false !== stripos( $error, 'NIP tidak ada' ) ) ) {
        $start_step = 1;
    }
    $ajax_url   = admin_url( 'admin-ajax.php' );
    $check_nonce = wp_create_nonce( 'qrg_guru_check_nip' );

    qrg_guru_portal_shell_start( 'Login Portal Guru' );
    ?>
    <div class="qrg-gp login">
        <div class="ios-login-card">
            <p class="brand" style="text-align:center;"><?php echo esc_html( $site ); ?></p>
            <h1 style="text-align:center;">Portal Guru</h1>
            <p class="sub" style="text-align:center;margin-bottom:14px;">Masuk dengan NIP dan No. Telepon</p>
            <?php if ( $error ) : ?>
                <div class="err" id="qrg-gp-login-err"><?php echo esc_html( $error ); ?></div>
            <?php else : ?>
                <div class="err" id="qrg-gp-login-err" style="display:none;"></div>
            <?php endif; ?>

            <form method="post" id="qrg-gp-form" autocomplete="off" data-start-step="<?php echo (int) $start_step; ?>" data-ajax-url="<?php echo esc_url( $ajax_url ); ?>" data-check-nonce="<?php echo esc_attr( $check_nonce ); ?>">
                <?php wp_nonce_field( 'qrg_guru_login', 'qrg_guru_nonce' ); ?>
                <input type="hidden" name="qrg_guru_login" value="1">
                <?php if ( $goto ) : ?><input type="hidden" name="qrg_guru_goto" value="<?php echo esc_attr( $goto ); ?>"><?php endif; ?>
                <input type="hidden" name="qrg_guru_identitas" id="qrg_guru_identitas" value="<?php echo esc_attr( $posted_nip ); ?>" required autocomplete="username">
                <input type="hidden" name="qrg_guru_password" id="qrg_guru_password" value="">

                <div class="qrg-login-stage">
                    <div class="qrg-login-step<?php echo $start_step === 1 ? ' is-active' : ''; ?>" data-step="1" id="qrg-gp-step-nip">
                        <h2 class="qrg-login-step__title">Masukkan NIP</h2>
                        <p class="qrg-login-step__sub">Nomor Induk Pegawai yang terdaftar di Data Guru.</p>
                        <div class="calc">
                            <div class="calc-screen calc-screen--nip<?php echo $posted_nip === '' ? ' empty' : ''; ?>" id="qrg-gp-nip-screen" aria-live="polite"><?php echo $posted_nip !== '' ? esc_html( $posted_nip ) : 'NIP'; ?></div>
                            <div class="calc-pad<?php echo $start_step === 1 ? ' is-enter' : ''; ?>" role="group" aria-label="Keypad NIP" id="qrg-gp-nip-pad">
                                <?php foreach ( [ '1','2','3','4','5','6','7','8','9','clear','0','back' ] as $k ) :
                                    $cls = ( $k === 'clear' || $k === 'back' ) ? 'key-fn' : '';
                                    $label = $k === 'clear' ? 'C' : ( $k === 'back' ? '⌫' : $k );
                                    ?>
                                    <button type="button" class="<?php echo esc_attr( $cls ); ?>" data-key="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></button>
                                <?php endforeach; ?>
                                <button type="button" class="key-ok" id="qrg-gp-btn-to-pass">Lanjut</button>
                            </div>
                        </div>
                    </div>

                    <div class="qrg-login-step<?php echo $start_step === 2 ? ' is-active' : ''; ?>" data-step="2" id="qrg-gp-step-pass">
                        <button type="button" class="qrg-login-back" id="qrg-gp-btn-back-nip" aria-label="Kembali ke NIP">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                            <span>Kembali</span>
                        </button>
                        <h2 class="qrg-login-step__title">Masukkan No. Telepon</h2>
                        <p class="qrg-login-step__sub">Sesuai nomor telepon di Data Guru.</p>
                        <div class="calc">
                            <div class="calc-screen empty" id="qrg-gp-screen" aria-live="polite">No. Telepon</div>
                            <div class="calc-pad<?php echo $start_step === 2 ? ' is-enter' : ''; ?>" role="group" aria-label="Keypad telepon" id="qrg-gp-pass-pad">
                                <?php foreach ( [ '1','2','3','4','5','6','7','8','9','clear','0','back' ] as $k ) :
                                    $cls = ( $k === 'clear' || $k === 'back' ) ? 'key-fn' : '';
                                    $label = $k === 'clear' ? 'C' : ( $k === 'back' ? '⌫' : $k );
                                    ?>
                                    <button type="button" class="<?php echo esc_attr( $cls ); ?>" data-key="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></button>
                                <?php endforeach; ?>
                                <button type="submit" class="key-ok" id="qrg-gp-btn-login">Masuk</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <p class="sub" style="text-align:center;margin-top:18px;">
                <a href="<?php echo esc_url( function_exists( 'qrg_get_sekolah_portal_url' ) ? qrg_get_sekolah_portal_url() : home_url( '/sekolah/' ) ); ?>" style="color:var(--ios-blue);font-weight:600;text-decoration:none;">Portal Siswa →</a>
            </p>
        </div>
    </div>
    <script>
    (function(){
        var form=document.getElementById('qrg-gp-form');
        var stepNip=document.getElementById('qrg-gp-step-nip');
        var stepPass=document.getElementById('qrg-gp-step-pass');
        var nipInput=document.getElementById('qrg_guru_identitas');
        var passInput=document.getElementById('qrg_guru_password');
        var nipScreen=document.getElementById('qrg-gp-nip-screen');
        var passScreen=document.getElementById('qrg-gp-screen');
        var nipPad=document.getElementById('qrg-gp-nip-pad');
        var passPad=document.getElementById('qrg-gp-pass-pad');
        var btnNext=document.getElementById('qrg-gp-btn-to-pass');
        var btnBack=document.getElementById('qrg-gp-btn-back-nip');
        var errBox=document.getElementById('qrg-gp-login-err');
        if(!form||!stepNip||!stepPass||!nipInput||!passInput||!nipScreen||!passScreen) return;

        var step=parseInt(form.getAttribute('data-start-step')||'1',10)||1;
        var nipVal=(nipInput.value||'').replace(/\D/g,'');
        var passVal='';
        var animating=false;
        var leaveTimer=null;
        var checking=false;
        var ajaxUrl=form.getAttribute('data-ajax-url')||'';
        var checkNonce=form.getAttribute('data-check-nonce')||'';
        var nextLabel=btnNext?btnNext.textContent:'Lanjut';

        function setNextBusy(on){
            if(!btnNext) return;
            btnNext.disabled=!!on;
            btnNext.textContent=on?'Memeriksa…':nextLabel;
            btnNext.style.opacity=on?'0.7':'';
        }
        function pulse(el){
            if(!el) return;
            el.classList.remove('is-pulse');
            void el.offsetWidth;
            el.classList.add('is-pulse');
        }
        function showErr(msg){
            if(!errBox) return;
            if(!msg){
                if(!errBox.getAttribute('data-server')){
                    errBox.style.display='none';
                    errBox.textContent='';
                }
                return;
            }
            errBox.removeAttribute('data-server');
            errBox.textContent=msg;
            errBox.style.display='block';
        }
        function renderNip(doPulse){
            nipInput.value=nipVal;
            if(!nipVal){ nipScreen.textContent='NIP'; nipScreen.classList.add('empty'); }
            else { nipScreen.textContent=nipVal; nipScreen.classList.remove('empty'); }
            if(doPulse!==false) pulse(nipScreen);
        }
        function renderPass(doPulse){
            passInput.value=passVal;
            if(!passVal){ passScreen.textContent='No. Telepon'; passScreen.classList.add('empty'); }
            else { passScreen.textContent=passVal.replace(/./g,'•'); passScreen.classList.remove('empty'); }
            if(doPulse!==false) pulse(passScreen);
        }
        function clearPadAnim(pad){
            if(!pad) return;
            pad.classList.remove('is-enter');
            pad.querySelectorAll('button').forEach(function(btn){
                btn.style.animation='none';
                void btn.offsetWidth;
                btn.style.animation='';
            });
        }
        function replayPad(pad){
            if(!pad) return;
            clearPadAnim(pad);
            requestAnimationFrame(function(){
                pad.classList.add('is-enter');
                window.setTimeout(function(){ clearPadAnim(pad); }, 900);
            });
        }
        function goStep(n,dir){
            if(n===step||(n!==1&&n!==2)) return;
            if(animating){
                if(!(dir==='back'&&n===1)) return;
            }
            animating=true;
            if(leaveTimer){ clearTimeout(leaveTimer); leaveTimer=null; }

            var from=step===1?stepNip:stepPass;
            var to=n===1?stepNip:stepPass;
            var leave=dir==='back'?'is-leave-right':'is-leave-left';

            from.classList.remove('is-active','from-left','is-leave-left','is-leave-right');
            to.classList.remove('is-leave-left','is-leave-right','from-left','is-active');

            void from.offsetWidth;
            from.classList.add(leave);

            requestAnimationFrame(function(){
                to.classList.add('is-active');
                if(dir==='back') to.classList.add('from-left');
            });

            step=n;
            if(n===2){
                passVal='';
                renderPass(false);
                replayPad(passPad);
            } else {
                passVal='';
                renderPass(false);
                replayPad(nipPad);
            }

            leaveTimer=window.setTimeout(function(){
                from.classList.remove(leave);
                animating=false;
                leaveTimer=null;
            }, 360);
        }
        function pressNip(key){
            if(step!==1||checking) return;
            if(key==='clear') nipVal='';
            else if(key==='back') nipVal=nipVal.slice(0,-1);
            else if(/^[0-9]$/.test(key)&&nipVal.length<30) nipVal+=key;
            else return;
            renderNip(true);
            showErr('');
        }
        function pressPass(key){
            if(step!==2) return;
            if(key==='clear') passVal='';
            else if(key==='back') passVal=passVal.slice(0,-1);
            else if(/^[0-9]$/.test(key)&&passVal.length<20) passVal+=key;
            else return;
            renderPass(true);
            showErr('');
        }
        function validateNipLocal(){
            if(!nipVal){
                showErr('Masukkan NIP terlebih dahulu.');
                pulse(nipScreen);
                return false;
            }
            return true;
        }
        function advanceAfterNip(){
            if(checking||animating) return;
            if(!validateNipLocal()) return;
            if(!ajaxUrl||!checkNonce){
                showErr('');
                goStep(2,'next');
                return;
            }
            checking=true;
            setNextBusy(true);
            showErr('');
            var body=new FormData();
            body.append('action','qrg_guru_check_nip');
            body.append('nonce',checkNonce);
            body.append('nip',nipVal);
            fetch(ajaxUrl,{
                method:'POST',
                credentials:'same-origin',
                body:body
            }).then(function(res){
                return res.json().then(function(data){
                    return { ok:res.ok, status:res.status, data:data };
                });
            }).then(function(result){
                var data=result.data||{};
                if(result.ok && data.success){
                    showErr('');
                    goStep(2,'next');
                    return;
                }
                var msg=(data.data&&data.data.message)||(data.message)||'NIP tidak ditemukan. Periksa kembali NIP Anda.';
                showErr(msg);
                pulse(nipScreen);
            }).catch(function(){
                showErr('Gagal memeriksa NIP. Coba lagi.');
                pulse(nipScreen);
            }).finally(function(){
                checking=false;
                setNextBusy(false);
            });
        }

        if(nipPad){
            nipPad.querySelectorAll('button[data-key]').forEach(function(btn){
                btn.addEventListener('click',function(){ pressNip(btn.getAttribute('data-key')); });
            });
        }
        if(passPad){
            passPad.querySelectorAll('button[data-key]').forEach(function(btn){
                btn.addEventListener('click',function(){ pressPass(btn.getAttribute('data-key')); });
            });
        }
        if(btnNext){
            btnNext.addEventListener('click',function(){ advanceAfterNip(); });
        }
        if(btnBack){
            btnBack.addEventListener('click',function(e){
                e.preventDefault();
                goStep(1,'back');
            });
        }

        document.addEventListener('keydown',function(e){
            if(!document.body.contains(form)) return;
            if(e.key>='0'&&e.key<='9'){
                e.preventDefault();
                if(step===1) pressNip(e.key); else pressPass(e.key);
            } else if(e.key==='Backspace'){
                e.preventDefault();
                if(step===1) pressNip('back'); else pressPass('back');
            } else if(e.key==='Escape'){
                e.preventDefault();
                if(step===1) pressNip('clear'); else pressPass('clear');
            } else if(e.key==='Enter'){
                e.preventDefault();
                if(step===1){
                    advanceAfterNip();
                } else if(form.requestSubmit){
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        });

        form.addEventListener('submit',function(e){
            if(step!==2){
                e.preventDefault();
                advanceAfterNip();
                return;
            }
            if(!nipVal){
                e.preventDefault();
                goStep(1,'back');
                showErr('Masukkan NIP terlebih dahulu.');
                return;
            }
            if(!passVal){
                e.preventDefault();
                showErr('Masukkan No. Telepon.');
                pulse(passScreen);
                return;
            }
            nipInput.value=nipVal;
            passInput.value=passVal;
        });

        if(errBox&&errBox.textContent&&errBox.style.display!=='none'){
            errBox.setAttribute('data-server','1');
        }
        renderNip(false);
        renderPass(false);
        if(step===1) replayPad(nipPad);
        else replayPad(passPad);
    })();
    </script>
    <?php
    qrg_guru_portal_shell_end();
}

function qrg_render_guru_portal_hub( $guru_id ) {
    $guru_id = (int) $guru_id;
    $nama    = get_the_title( $guru_id );
    $jabatan = get_post_meta( $guru_id, '_guru_jabatan', true );
    if ( ! $jabatan ) {
        $jid = (int) get_post_meta( $guru_id, '_guru_jabatan_id', true );
        if ( $jid && function_exists( 'qrg_get_jabatan_label' ) ) {
            $jabatan = qrg_get_jabatan_label( $jid );
        }
    }
    $logout   = add_query_arg( 'qrg_logout', '1', qrg_get_guru_portal_url() );
    $site     = get_bloginfo( 'name' );
    $akun_url = add_query_arg( 'tab', 'akun', qrg_get_guru_portal_url() );
    $tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    $nav      = ( $tab === 'akun' ) ? 'akun' : 'beranda';
    $initial  = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'G';
    }

    $photo_msg = [ 'error' => '', 'success' => '' ];
    $pass_msg  = [ 'error' => '', 'success' => '' ];
    if ( $nav === 'akun' ) {
        $photo_msg = qrg_process_guru_profile_photo( $guru_id );
        if ( $photo_msg['success'] !== '' || $photo_msg['error'] !== '' ) {
            $redir = $akun_url;
            if ( $photo_msg['success'] !== '' ) {
                $flag  = isset( $_POST['qrg_gp_avatar_remove'] ) ? 'removed' : 'ok';
                $redir = add_query_arg( 'avatar', $flag, $redir );
            } else {
                $redir = add_query_arg( 'avatar_err', rawurlencode( $photo_msg['error'] ), $redir );
            }
            wp_safe_redirect( $redir );
            exit;
        }

        $pass_msg = qrg_process_guru_change_password( $guru_id );
        if ( $pass_msg['success'] !== '' || $pass_msg['error'] !== '' ) {
            $redir = $akun_url;
            if ( $pass_msg['success'] !== '' ) {
                $redir = add_query_arg( 'pass', 'ok', $redir );
            } else {
                $redir = add_query_arg( 'pass_err', rawurlencode( $pass_msg['error'] ), $redir );
            }
            wp_safe_redirect( $redir );
            exit;
        }

        if ( isset( $_GET['avatar'] ) ) {
            $avatar_flag = sanitize_key( wp_unslash( $_GET['avatar'] ) );
            if ( $avatar_flag === 'ok' ) {
                $photo_msg['success'] = 'Foto profil berhasil diperbarui.';
            } elseif ( $avatar_flag === 'removed' ) {
                $photo_msg['success'] = 'Foto profil dihapus.';
            }
        }
        if ( isset( $_GET['avatar_err'] ) ) {
            $photo_msg['error'] = sanitize_text_field( wp_unslash( $_GET['avatar_err'] ) );
        }
        if ( isset( $_GET['pass'] ) && sanitize_key( wp_unslash( $_GET['pass'] ) ) === 'ok' ) {
            $pass_msg['success'] = 'Password berhasil diganti. Gunakan nomor baru saat login berikutnya.';
        }
        if ( isset( $_GET['pass_err'] ) ) {
            $pass_msg['error'] = sanitize_text_field( wp_unslash( $_GET['pass_err'] ) );
        }
    }

    $avatar_url = qrg_get_guru_avatar_url( $guru_id, 'medium' );
    $avatar_sm  = $avatar_url ? qrg_get_guru_avatar_url( $guru_id, 'thumbnail' ) : '';
    $nip        = (string) get_post_meta( $guru_id, '_guru_nip', true );

    $hari_today = function_exists( 'qrg_guru_portal_today_hari' ) ? qrg_guru_portal_today_hari() : '';
    $jadwal_by  = function_exists( 'qrg_get_jadwal_by_guru' ) ? qrg_get_jadwal_by_guru( $guru_id ) : [];
    $today_rows = ( $hari_today && isset( $jadwal_by[ $hari_today ] ) && is_array( $jadwal_by[ $hari_today ] ) )
        ? $jadwal_by[ $hari_today ]
        : [];
    $today_count = count( $today_rows );

    $gaji_list    = function_exists( 'qrg_guru_portal_get_gaji_list' ) ? qrg_guru_portal_get_gaji_list( $guru_id ) : [];
    $gaji_pending = 0;
    $gaji_latest  = null;
    foreach ( $gaji_list as $row ) {
        if ( empty( $gaji_latest ) ) {
            $gaji_latest = $row;
        }
        if ( isset( $row['status'] ) && $row['status'] !== 'Dibayar' ) {
            $gaji_pending++;
        }
    }

    $jadwal_url   = qrg_get_guru_portal_url( 'jadwal' );
    $gaji_url     = qrg_get_guru_portal_url( 'gaji' );
    $presensi_url = qrg_get_guru_portal_url( 'presensi' );
    $izin_url     = qrg_get_guru_portal_url( 'izin' );
    $izin_pending = 0;
    if ( function_exists( 'qrg_izin_get_for_guru' ) ) {
        $izin_pending = count( qrg_izin_get_for_guru( $guru_id, [ 'status' => 'Menunggu', 'limit' => 200 ] ) );
    }
    $today_label  = $hari_today ? $hari_today : 'Hari ini';
    $hero_main    = $today_count > 0 ? (string) $today_count . ' jadwal' : 'Libur mengajar';
    $hero_meta    = $today_count > 0
        ? ( $today_label . ' · ' . $today_count . ' sesi mengajar' )
        : ( $today_label . ' · tidak ada jadwal' );

    qrg_guru_portal_shell_start( $nav === 'akun' ? 'Akun' : 'Portal Guru', $nav );
    ?>
    <div class="qrg-gp<?php echo $nav === 'beranda' ? ' qrg-gp--dana' : ''; ?>">
        <?php if ( $nav === 'akun' ) : ?>
            <header class="ios-hero">
                <p class="brand">Pengaturan Profil</p>
                <h1>Akun</h1>
            </header>
            <?php if ( $photo_msg['error'] ) : ?><div class="err"><?php echo esc_html( $photo_msg['error'] ); ?></div><?php endif; ?>
            <?php if ( $photo_msg['success'] ) : ?><div class="ok"><?php echo esc_html( $photo_msg['success'] ); ?></div><?php endif; ?>
            <?php if ( $pass_msg['error'] ) : ?><div class="err"><?php echo esc_html( $pass_msg['error'] ); ?></div><?php endif; ?>
            <?php if ( $pass_msg['success'] ) : ?><div class="ok"><?php echo esc_html( $pass_msg['success'] ); ?></div><?php endif; ?>

            <section class="ios-section">
                <p class="ios-section__label">Profil</p>
                <div class="ios-list qrg-gp-profile-card">
                    <div class="ios-avatar<?php echo $avatar_url ? ' has-photo' : ''; ?>" aria-hidden="true">
                        <?php if ( $avatar_url ) : ?>
                            <img src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="88" height="88">
                        <?php else : ?>
                            <?php echo esc_html( $initial ); ?>
                        <?php endif; ?>
                    </div>
                    <strong style="display:block;font-size:18px;"><?php echo esc_html( $nama ); ?></strong>
                    <span style="display:block;margin-top:4px;font-size:14px;color:var(--ios-secondary);">
                        <?php echo $jabatan ? esc_html( $jabatan ) : 'Guru'; ?>
                        <?php echo $nip ? ' · NIP ' . esc_html( $nip ) : ''; ?>
                        · <?php echo esc_html( $site ); ?>
                    </span>
                </div>
                <?php $avatar_panel_open = ( $photo_msg['error'] !== '' || $photo_msg['success'] !== '' ); ?>
                <div class="menu" style="margin-top:10px;">
                    <button type="button" class="card-link qrg-gp-avatar-toggle<?php echo $avatar_panel_open ? ' is-open' : ''; ?>" id="qrg-gp-avatar-toggle" aria-expanded="<?php echo $avatar_panel_open ? 'true' : 'false'; ?>" aria-controls="qrg-gp-avatar-panel">
                        <span class="ios-icon ios-icon--photo" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        </span>
                        <div>
                            <strong>Ubah foto</strong>
                            <span>Ganti foto profil dari galeri atau kamera</span>
                        </div>
                    </button>
                </div>
                <div class="qrg-gp-avatar-panel<?php echo $avatar_panel_open ? ' is-open' : ''; ?>" id="qrg-gp-avatar-panel">
                    <div class="qrg-gp-avatar-panel__inner">
                        <div class="qrg-gp-avatar-card">
                            <form method="post" enctype="multipart/form-data" id="qrg-gp-avatar-form" action="<?php echo esc_url( $akun_url ); ?>">
                                <?php wp_nonce_field( 'qrg_gp_avatar_' . $guru_id, 'qrg_gp_avatar_nonce' ); ?>
                                <input type="hidden" name="qrg_gp_avatar_upload" value="1">
                                <div class="qrg-gp-avatar-edit">
                                    <div class="ios-avatar<?php echo $avatar_url ? ' has-photo' : ''; ?>" aria-hidden="true">
                                        <?php if ( $avatar_url ) : ?>
                                            <img src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="88" height="88">
                                        <?php else : ?>
                                            <?php echo esc_html( $initial ); ?>
                                        <?php endif; ?>
                                    </div>
                                    <label class="qrg-gp-avatar-edit__cam" for="qrg-gp-avatar-file" title="Ganti foto profil">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                    </label>
                                    <input type="file" id="qrg-gp-avatar-file" name="qrg_gp_avatar" accept="image/jpeg,image/png,image/webp,image/gif" capture="user">
                                </div>
                                <div class="qrg-gp-avatar-actions">
                                    <?php if ( $avatar_url ) : ?>
                                        <button type="button" class="btn btn-muted" id="qrg-gp-avatar-remove-btn">Hapus foto</button>
                                        <button type="submit" name="qrg_gp_avatar_remove" value="1" id="qrg-gp-avatar-remove-submit" hidden tabindex="-1" aria-hidden="true">Hapus</button>
                                    <?php endif; ?>
                                </div>
                                <p class="qrg-gp-avatar-status" id="qrg-gp-avatar-status" hidden></p>
                                <div class="qrg-gp-avatar-rules" role="note">
                                    <p class="qrg-gp-avatar-rules__title">Syarat upload foto</p>
                                    <ul>
                                        <li>Ketuk ikon kamera untuk memilih atau mengambil foto</li>
                                        <li>Format: <strong>JPG, PNG, WebP,</strong> atau <strong>GIF</strong></li>
                                        <li>Ukuran maksimal <strong>3 MB</strong></li>
                                    </ul>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ios-section">
                <p class="ios-section__label">Keamanan</p>
                <?php $pass_panel_open = ( $pass_msg['error'] !== '' || $pass_msg['success'] !== '' ); ?>
                <div class="menu">
                    <button type="button" class="card-link qrg-gp-security-toggle<?php echo $pass_panel_open ? ' is-open' : ''; ?>" id="qrg-gp-pass-toggle" aria-expanded="<?php echo $pass_panel_open ? 'true' : 'false'; ?>" aria-controls="qrg-gp-pass-panel">
                        <span class="ios-icon ios-icon--lock" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <div>
                            <strong>Ganti password</strong>
                            <span>Ubah No. Telepon login dengan keypad angka</span>
                        </div>
                    </button>
                </div>
                <div class="qrg-gp-security-panel<?php echo $pass_panel_open ? ' is-open' : ''; ?>" id="qrg-gp-pass-panel">
                    <div class="qrg-gp-security-panel__inner">
                        <div class="qrg-gp-pin-card">
                            <form method="post" action="<?php echo esc_url( $akun_url ); ?>" id="qrg-gp-pass-form" autocomplete="off">
                                <?php wp_nonce_field( 'qrg_gp_change_pass_' . $guru_id, 'qrg_gp_pass_nonce' ); ?>
                                <input type="hidden" name="qrg_gp_change_pass" value="1">
                                <input type="hidden" name="qrg_gp_pass_current" id="qrg-gp-pass-current" value="">
                                <input type="hidden" name="qrg_gp_pass_new" id="qrg-gp-pass-new" value="">
                                <input type="hidden" name="qrg_gp_pass_confirm" id="qrg-gp-pass-confirm" value="">
                                <div class="qrg-gp-pin-steps" aria-hidden="true">
                                    <span class="qrg-gp-pin-step is-on" data-step="0">1. Lama</span>
                                    <span class="qrg-gp-pin-step" data-step="1">2. Baru</span>
                                    <span class="qrg-gp-pin-step" data-step="2">3. Ulangi</span>
                                </div>
                                <div class="qrg-gp-pin-nav">
                                    <button type="button" id="qrg-gp-pass-back" hidden>← Kembali</button>
                                </div>
                                <p class="calc-hint" id="qrg-gp-pass-hint">Masukkan password lama (No. Telepon)</p>
                                <div class="qrg-gp-pin-err" id="qrg-gp-pass-local-err" role="alert"></div>
                                <div class="calc">
                                    <div class="calc-screen empty" id="qrg-gp-pass-screen" aria-live="polite">Password lama</div>
                                    <div class="calc-pad" role="group" aria-label="Keypad password" id="qrg-gp-pass-pad">
                                        <?php foreach ( [ '1','2','3','4','5','6','7','8','9','clear','0','back' ] as $k ) :
                                            $cls = ( $k === 'clear' || $k === 'back' ) ? 'key-fn' : '';
                                            $label = $k === 'clear' ? 'C' : ( $k === 'back' ? '⌫' : $k );
                                            ?>
                                            <button type="button" class="<?php echo esc_attr( $cls ); ?>" data-key="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></button>
                                        <?php endforeach; ?>
                                        <button type="button" class="key-ok" id="qrg-gp-pass-next" disabled>Lanjut</button>
                                    </div>
                                </div>
                                <p class="qrg-gp-pin-hint">Password login adalah No. Telepon di Data Guru. Password baru 8–20 digit.</p>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <div style="margin-top:8px;">
                <a class="btn btn-muted btn-block" href="<?php echo esc_url( $logout ); ?>" style="color:var(--ios-red) !important;">Keluar</a>
            </div>

            <?php if ( $avatar_url ) : ?>
            <div class="qrg-gp-alert" id="qrg-gp-avatar-alert" role="dialog" aria-modal="true" hidden>
                <div class="qrg-gp-alert__card">
                    <div class="qrg-gp-alert__body">
                        <h2 class="qrg-gp-alert__title">Hapus foto profil?</h2>
                        <p class="qrg-gp-alert__text">Foto akan dihapus dari akun Anda. Anda bisa mengunggah foto baru kapan saja.</p>
                    </div>
                    <div class="qrg-gp-alert__actions">
                        <button type="button" class="qrg-gp-alert__cancel" id="qrg-gp-avatar-alert-cancel">Batal</button>
                        <button type="button" class="qrg-gp-alert__danger" id="qrg-gp-avatar-alert-confirm">Hapus</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <script>
            (function(){
                var avatarToggle=document.getElementById('qrg-gp-avatar-toggle');
                var avatarPanel=document.getElementById('qrg-gp-avatar-panel');
                var avatarOpen=avatarPanel&&avatarPanel.classList.contains('is-open');
                function setAvatarOpen(open){
                    avatarOpen=!!open;
                    if(avatarPanel) avatarPanel.classList.toggle('is-open',avatarOpen);
                    if(avatarToggle){
                        avatarToggle.classList.toggle('is-open',avatarOpen);
                        avatarToggle.setAttribute('aria-expanded',avatarOpen?'true':'false');
                    }
                }
                if(avatarToggle&&avatarPanel){
                    avatarToggle.addEventListener('click',function(){ setAvatarOpen(!avatarOpen); });
                }
                var fileInput=document.getElementById('qrg-gp-avatar-file');
                var avatarForm=document.getElementById('qrg-gp-avatar-form');
                var status=document.getElementById('qrg-gp-avatar-status');
                if(fileInput&&avatarForm){
                    fileInput.addEventListener('change',function(){
                        if(!fileInput.files||!fileInput.files.length) return;
                        if(status){ status.hidden=false; status.textContent='Mengunggah foto…'; }
                        avatarForm.submit();
                    });
                }
                var removeBtn=document.getElementById('qrg-gp-avatar-remove-btn');
                var removeSubmit=document.getElementById('qrg-gp-avatar-remove-submit');
                var alertBox=document.getElementById('qrg-gp-avatar-alert');
                var alertCancel=document.getElementById('qrg-gp-avatar-alert-cancel');
                var alertConfirm=document.getElementById('qrg-gp-avatar-alert-confirm');
                function openAlert(){ if(alertBox){ alertBox.hidden=false; alertBox.classList.add('is-open'); } }
                function closeAlert(){ if(alertBox){ alertBox.classList.remove('is-open'); alertBox.hidden=true; } }
                if(removeBtn){ removeBtn.addEventListener('click',function(){ openAlert(); }); }
                if(alertCancel){ alertCancel.addEventListener('click',closeAlert); }
                if(alertConfirm){
                    alertConfirm.addEventListener('click',function(){
                        closeAlert();
                        if(removeSubmit&&avatarForm){
                            if(avatarForm.requestSubmit) avatarForm.requestSubmit(removeSubmit);
                            else { removeSubmit.hidden=false; removeSubmit.click(); }
                        }
                    });
                }

                var passForm=document.getElementById('qrg-gp-pass-form');
                if(!passForm) return;
                var passToggle=document.getElementById('qrg-gp-pass-toggle');
                var passPanel=document.getElementById('qrg-gp-pass-panel');
                var screen=document.getElementById('qrg-gp-pass-screen');
                var pad=document.getElementById('qrg-gp-pass-pad');
                var nextBtn=document.getElementById('qrg-gp-pass-next');
                var backBtn=document.getElementById('qrg-gp-pass-back');
                var hint=document.getElementById('qrg-gp-pass-hint');
                var localErr=document.getElementById('qrg-gp-pass-local-err');
                var stepEls=passForm.querySelectorAll('.qrg-gp-pin-step');
                var fields=[
                    document.getElementById('qrg-gp-pass-current'),
                    document.getElementById('qrg-gp-pass-new'),
                    document.getElementById('qrg-gp-pass-confirm')
                ];
                var labels=['Password lama','Password baru','Ulangi password'];
                var hints=[
                    'Masukkan password lama (No. Telepon)',
                    'Masukkan password baru (8–20 digit)',
                    'Ulangi password baru untuk konfirmasi'
                ];
                var values=['','',''];
                var step=0;
                var panelOpen=passPanel&&passPanel.classList.contains('is-open');
                function setPassOpen(open){
                    panelOpen=!!open;
                    if(passPanel) passPanel.classList.toggle('is-open',panelOpen);
                    if(passToggle){
                        passToggle.classList.toggle('is-open',panelOpen);
                        passToggle.setAttribute('aria-expanded',panelOpen?'true':'false');
                    }
                }
                if(passToggle&&passPanel){
                    passToggle.addEventListener('click',function(){ setPassOpen(!panelOpen); });
                }
                function showLocalErr(msg){
                    if(!localErr) return;
                    if(!msg){ localErr.textContent=''; localErr.classList.remove('is-on'); return; }
                    localErr.textContent=msg; localErr.classList.add('is-on');
                }
                function render(){
                    fields.forEach(function(f,i){ if(f) f.value=values[i]; });
                    if(screen){
                        if(!values[step]){ screen.textContent=labels[step]; screen.classList.add('empty'); }
                        else { screen.textContent=values[step].replace(/./g,'•'); screen.classList.remove('empty'); }
                    }
                    if(hint) hint.textContent=hints[step];
                    if(nextBtn){
                        nextBtn.disabled=!values[step];
                        nextBtn.textContent=step===2?'Simpan':'Lanjut';
                    }
                    if(backBtn) backBtn.hidden=step===0;
                    stepEls.forEach(function(el){
                        var s=parseInt(el.getAttribute('data-step'),10);
                        el.classList.toggle('is-on',s===step);
                        el.classList.toggle('is-done',s<step);
                    });
                }
                function press(key){
                    if(key==='clear') values[step]='';
                    else if(key==='back') values[step]=values[step].slice(0,-1);
                    else if(/^[0-9]$/.test(key)&&values[step].length<20) values[step]+=key;
                    showLocalErr('');
                    render();
                }
                if(pad){
                    pad.querySelectorAll('button[data-key]').forEach(function(btn){
                        btn.addEventListener('click',function(){ press(btn.getAttribute('data-key')); });
                    });
                }
                if(nextBtn){
                    nextBtn.addEventListener('click',function(){
                        if(!values[step]){ showLocalErr('Masukkan angka terlebih dahulu.'); return; }
                        if(step<2){ step+=1; showLocalErr(''); render(); return; }
                        if(values[1].length<8){ showLocalErr('Password baru minimal 8 digit.'); step=1; render(); return; }
                        if(values[1]!==values[2]){ showLocalErr('Konfirmasi password tidak cocok.'); step=2; render(); return; }
                        if(values[0]===values[1]){ showLocalErr('Password baru harus berbeda.'); step=1; render(); return; }
                        fields.forEach(function(f,i){ if(f) f.value=values[i]; });
                        if(passForm.requestSubmit) passForm.requestSubmit();
                        else passForm.submit();
                    });
                }
                if(backBtn){
                    backBtn.addEventListener('click',function(){
                        if(step>0){ step-=1; showLocalErr(''); render(); }
                    });
                }
                render();
            })();
            </script>
        <?php else : ?>
            <div class="dana-top">
                <div class="dana-top__row">
                    <div class="dana-top__brand">
                        <a class="dana-top__logo<?php echo $avatar_sm ? ' has-photo' : ''; ?>" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Pengaturan profil">
                            <?php if ( $avatar_sm ) : ?>
                                <img src="<?php echo esc_url( $avatar_sm ); ?>" alt="" width="36" height="36">
                            <?php else : ?>
                                <?php echo esc_html( $initial ); ?>
                            <?php endif; ?>
                        </a>
                        <div>
                            <strong><?php echo esc_html( $nama ); ?></strong>
                            <span><?php echo $jabatan ? esc_html( $jabatan ) : 'Guru'; ?> · <?php echo esc_html( $site ); ?></span>
                        </div>
                    </div>
                    <a class="dana-top__bell" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Pengaturan profil">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </a>
                </div>

                <div class="dana-balance">
                    <p class="dana-balance__label">Jadwal mengajar hari ini</p>
                    <div class="dana-balance__row">
                        <p class="dana-balance__amount"><?php echo esc_html( $hero_main ); ?></p>
                    </div>
                    <p class="dana-balance__meta"><?php echo esc_html( $hero_meta ); ?> · <?php echo esc_html( $site ); ?></p>
                </div>

                <div class="dana-actions">
                    <a href="<?php echo esc_url( $jadwal_url ); ?>">
                        <span class="dana-actions__ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        </span>
                        Jadwal
                    </a>
                    <a href="<?php echo esc_url( $gaji_url ); ?>">
                        <span class="dana-actions__ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg>
                        </span>
                        Gaji
                    </a>
                    <a href="<?php echo esc_url( $presensi_url ); ?>">
                        <span class="dana-actions__ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        </span>
                        Presensi
                    </a>
                    <a href="<?php echo esc_url( $akun_url ); ?>">
                        <span class="dana-actions__ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        Akun
                    </a>
                </div>
            </div>

            <div class="dana-sheet">
                <div class="dana-banner" id="dana-gp-banner" aria-roledescription="carousel" aria-label="Banner portal guru">
                    <div class="dana-banner__track" id="dana-gp-banner-track">
                        <a class="dana-banner__slide dana-banner__slide--1" href="<?php echo esc_url( $jadwal_url ); ?>">
                            <span class="dana-banner__kicker">Jadwal</span>
                            <p class="dana-banner__title">Kelola jadwal &amp; tugas</p>
                            <p class="dana-banner__sub">Lihat mapel hari ini, buat tugas, dan pantau pengumpulan siswa.</p>
                            <span class="dana-banner__cta">Buka jadwal</span>
                        </a>
                        <a class="dana-banner__slide dana-banner__slide--2" href="<?php echo esc_url( $gaji_url ); ?>">
                            <span class="dana-banner__kicker">Slip Gaji</span>
                            <p class="dana-banner__title">Cek slip gaji kapan saja</p>
                            <p class="dana-banner__sub">Rincian komponen, kehadiran, dan status pembayaran.</p>
                            <span class="dana-banner__cta">Lihat slip</span>
                        </a>
                        <a class="dana-banner__slide dana-banner__slide--3" href="<?php echo esc_url( $presensi_url ); ?>">
                            <span class="dana-banner__kicker">Presensi</span>
                            <p class="dana-banner__title">Riwayat kehadiran Anda</p>
                            <p class="dana-banner__sub">Hadir, izin, sakit, dan jam masuk–pulang tersimpan rapi.</p>
                            <span class="dana-banner__cta">Buka presensi</span>
                        </a>
                    </div>
                    <div class="dana-banner__dots" id="dana-gp-banner-dots" aria-hidden="true">
                        <span class="is-on"></span><span></span><span></span>
                    </div>
                </div>

                <?php if ( $gaji_pending > 0 && $gaji_latest ) : ?>
                    <a class="dana-promo" href="<?php echo esc_url( $gaji_url ); ?>">
                        <div class="dana-promo__ico" aria-hidden="true">Rp</div>
                        <div class="dana-promo__body">
                            <strong>Ada slip menunggu</strong>
                            <span><?php echo esc_html( $gaji_pending ); ?> slip belum dibayar<?php echo ! empty( $gaji_latest['periode'] ) ? ' · ' . esc_html( $gaji_latest['periode'] ) : ''; ?></span>
                        </div>
                        <span class="dana-promo__btn">LIHAT</span>
                    </a>
                <?php endif; ?>

                <p class="dana-sheet__label">Layanan utama</p>
                <div class="dana-grid">
                    <a href="<?php echo esc_url( $jadwal_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--jadwal" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        </span>
                        Jadwal &amp; Tugas
                    </a>
                    <a href="<?php echo esc_url( $gaji_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--gaji" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg>
                        </span>
                        Slip Gaji
                    </a>
                    <a href="<?php echo esc_url( $presensi_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--presensi" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        </span>
                        Presensi
                    </a>
                    <a href="<?php echo esc_url( $izin_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--izin" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6M9 11h6"/></svg>
                        </span>
                        Izin Siswa<?php echo $izin_pending ? ' (' . (int) $izin_pending . ')' : ''; ?>
                    </a>
                </div>

                <p class="dana-sheet__label">Akses cepat</p>
                <div class="menu" style="margin-bottom:8px;">
                    <a class="card-link" href="<?php echo esc_url( $izin_url ); ?>">
                        <span class="ios-icon ios-icon--jadwal" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6M9 11h6"/></svg>
                        </span>
                        <div>
                            <strong>Izin Siswa</strong>
                            <span><?php echo $izin_pending > 0 ? esc_html( $izin_pending . ' pengajuan menunggu' ) : 'Setujui atau tolak izin siswa'; ?></span>
                        </div>
                    </a>
                    <a class="card-link" href="<?php echo esc_url( $jadwal_url ); ?>">
                        <span class="ios-icon ios-icon--jadwal" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        </span>
                        <div>
                            <strong>Jadwal &amp; Tugas</strong>
                            <span><?php echo $today_count > 0 ? esc_html( $today_count . ' sesi hari ini' ) : 'Buat tugas &amp; jurnal mengajar'; ?></span>
                        </div>
                    </a>
                    <a class="card-link" href="<?php echo esc_url( $akun_url ); ?>">
                        <span class="ios-icon ios-icon--photo" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <div>
                            <strong>Pengaturan profil</strong>
                            <span>Ubah foto, ganti password, dan keluar</span>
                        </div>
                    </a>
                </div>
            </div>
            <script>
            (function () {
                var root = document.getElementById('dana-gp-banner');
                var track = document.getElementById('dana-gp-banner-track');
                var dotsWrap = document.getElementById('dana-gp-banner-dots');
                if (!root || !track || !dotsWrap) return;
                var slides = track.children;
                var dots = dotsWrap.children;
                var total = slides.length;
                var index = 0;
                var timer = null;
                var startX = 0;
                var deltaX = 0;
                var dragging = false;
                function go(i) {
                    index = (i + total) % total;
                    track.style.transform = 'translateX(' + (-index * 100) + '%)';
                    for (var d = 0; d < dots.length; d++) dots[d].classList.toggle('is-on', d === index);
                }
                function next() { go(index + 1); }
                function startAuto() {
                    stopAuto();
                    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                    timer = setInterval(next, 4200);
                }
                function stopAuto() { if (timer) { clearInterval(timer); timer = null; } }
                root.addEventListener('pointerdown', function (e) {
                    dragging = true; startX = e.clientX; deltaX = 0; stopAuto(); track.style.transition = 'none';
                });
                root.addEventListener('pointermove', function (e) {
                    if (!dragging) return;
                    deltaX = e.clientX - startX;
                    track.style.transform = 'translateX(' + ((-index * 100) + (deltaX / root.offsetWidth) * 100) + '%)';
                });
                function endDrag() {
                    if (!dragging) return;
                    dragging = false; track.style.transition = '';
                    if (Math.abs(deltaX) > 40) go(index + (deltaX < 0 ? 1 : -1)); else go(index);
                    startAuto();
                }
                root.addEventListener('pointerup', endDrag);
                root.addEventListener('pointercancel', endDrag);
                root.addEventListener('pointerleave', function () { if (dragging) endDrag(); });
                go(0); startAuto();
            })();
            </script>
        <?php endif; ?>
    </div>
    <?php
    qrg_guru_portal_shell_end();
}

function qrg_guru_portal_today_hari() {
    $map = [
        'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
    ];
    $en = wp_date( 'l' );
    return isset( $map[ $en ] ) ? $map[ $en ] : '';
}

function qrg_guru_portal_match_tugas_for_slot( $index, $mapel_id, $kelas_id ) {
    $mapel_id = (int) $mapel_id;
    $kelas_id = (int) $kelas_id;
    $found    = [];
    $seen     = [];

    if ( $mapel_id > 0 && $kelas_id > 0 && ! empty( $index['by_mapel_kelas'][ $mapel_id ][ $kelas_id ] ) ) {
        foreach ( $index['by_mapel_kelas'][ $mapel_id ][ $kelas_id ] as $item ) {
            $seen[ $item['id'] ] = true;
            $found[]             = $item;
        }
    }
    if ( $mapel_id > 0 && ! empty( $index['by_mapel'][ $mapel_id ] ) ) {
        foreach ( $index['by_mapel'][ $mapel_id ] as $item ) {
            if ( isset( $seen[ $item['id'] ] ) ) {
                continue;
            }
            // Jika kelas slot ada, hanya tampilkan tugas tanpa kelas atau kelas sama.
            if ( $kelas_id > 0 && ! empty( $item['kelas_id'] ) && (int) $item['kelas_id'] !== $kelas_id ) {
                continue;
            }
            $seen[ $item['id'] ] = true;
            $found[]             = $item;
        }
    }
    return $found;
}

function qrg_guru_portal_tugas_index( $guru_id ) {
    $list = qrg_guru_portal_get_tugas_list( $guru_id );
    $out  = [
        'by_mapel_kelas' => [],
        'by_mapel'       => [],
        'all'            => [],
    ];
    foreach ( $list as $t ) {
        $tid      = (int) $t->ID;
        $mapel_id = (int) get_post_meta( $tid, '_tugas_mapel_id', true );
        $kelas_id = (int) get_post_meta( $tid, '_tugas_kelas_id', true );
        $meta     = function_exists( 'qrg_get_tugas_meta' ) ? qrg_get_tugas_meta( $tid ) : [];
        $summary  = function_exists( 'qrg_tugas_monitoring_summary' ) ? qrg_tugas_monitoring_summary( $tid ) : null;
        $item     = [
            'id'       => $tid,
            'title'    => get_the_title( $tid ),
            'mapel_id' => $mapel_id,
            'kelas_id' => $kelas_id,
            'mapel'    => ! empty( $meta['mapel'] ) ? $meta['mapel'] : '',
            'kelas'    => $kelas_id && function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas_id ) : '',
            'deadline' => ! empty( $meta['deadline'] ) ? $meta['deadline'] : '',
            'tipe'     => ! empty( $meta['tipe'] ) ? $meta['tipe'] : '',
            'summary'  => $summary,
        ];
        $out['all'][] = $item;
        if ( $mapel_id > 0 ) {
            if ( ! isset( $out['by_mapel'][ $mapel_id ] ) ) {
                $out['by_mapel'][ $mapel_id ] = [];
            }
            $out['by_mapel'][ $mapel_id ][] = $item;
        }
        if ( $mapel_id > 0 && $kelas_id > 0 ) {
            if ( ! isset( $out['by_mapel_kelas'][ $mapel_id ][ $kelas_id ] ) ) {
                $out['by_mapel_kelas'][ $mapel_id ][ $kelas_id ] = [];
            }
            $out['by_mapel_kelas'][ $mapel_id ][ $kelas_id ][] = $item;
        }
    }
    return $out;
}

function qrg_guru_portal_tugas_owned( $guru_id, $tugas_id ) {
    $guru_id  = (int) $guru_id;
    $tugas_id = (int) $tugas_id;
    if ( ! $guru_id || ! $tugas_id || get_post_type( $tugas_id ) !== 'master_tugas' ) {
        return false;
    }
    return (int) get_post_meta( $tugas_id, '_tugas_guru_id', true ) === $guru_id;
}

function qrg_guru_portal_nilai_jawaban( $guru_id ) {
    if ( ! isset( $_POST['qrg_guru_nilai_jawaban'] ) ) {
        return [ 'error' => '', 'success' => '' ];
    }
    $tugas_id = isset( $_POST['tugas_id'] ) ? absint( $_POST['tugas_id'] ) : 0;
    $siswa_id = isset( $_POST['siswa_id'] ) ? absint( $_POST['siswa_id'] ) : 0;
    if ( ! isset( $_POST['qrg_guru_nilai_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_guru_nilai_nonce'] ) ), 'qrg_guru_nilai_' . $guru_id . '_' . $tugas_id ) ) {
        return [ 'error' => 'Sesi tidak valid.', 'success' => '' ];
    }
    if ( ! qrg_guru_portal_tugas_owned( $guru_id, $tugas_id ) ) {
        return [ 'error' => 'Tugas tidak ditemukan.', 'success' => '' ];
    }
    $jid = function_exists( 'qrg_find_jawaban' ) ? qrg_find_jawaban( $tugas_id, $siswa_id ) : 0;
    if ( ! $jid ) {
        return [ 'error' => 'Jawaban siswa belum ada.', 'success' => '' ];
    }
    $nilai   = isset( $_POST['jawaban_nilai'] ) ? sanitize_text_field( wp_unslash( $_POST['jawaban_nilai'] ) ) : '';
    $catatan = isset( $_POST['jawaban_catatan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['jawaban_catatan'] ) ) : '';
    update_post_meta( $jid, '_jawaban_nilai', $nilai );
    update_post_meta( $jid, '_jawaban_catatan_guru', $catatan );
    update_post_meta( $jid, '_jawaban_status', 'Dinilai' );
    wp_safe_redirect( qrg_get_guru_portal_url( 'jadwal', [ 'tugas' => $tugas_id, 'siswa' => $siswa_id, 'graded' => 1 ] ) );
    exit;
}

function qrg_render_guru_portal_tugas_monitor( $guru_id, $tugas_id ) {
    $tugas_id = (int) $tugas_id;
    $back     = qrg_get_guru_portal_url( 'jadwal' );
    $grade_msg = qrg_guru_portal_nilai_jawaban( $guru_id );

    if ( ! qrg_guru_portal_tugas_owned( $guru_id, $tugas_id ) ) {
        qrg_guru_portal_shell_start( 'Tugas', 'jadwal' );
        echo '<div class="qrg-gp"><a class="back" href="' . esc_url( $back ) . '">&larr; Kembali</a><p class="empty">Tugas tidak ditemukan atau bukan milik Anda.</p></div>';
        qrg_guru_portal_shell_end();
        return;
    }

    $meta       = function_exists( 'qrg_get_tugas_meta' ) ? qrg_get_tugas_meta( $tugas_id ) : [];
    $summary    = function_exists( 'qrg_tugas_monitoring_summary' ) ? qrg_tugas_monitoring_summary( $tugas_id ) : [ 'total' => 0, 'submitted' => 0, 'pending' => 0, 'graded' => 0 ];
    $siswa_list = ! empty( $meta['kelas_id'] ) && function_exists( 'qrg_get_siswa_by_kelas' ) ? qrg_get_siswa_by_kelas( $meta['kelas_id'] ) : [];
    $kelas_lbl  = ! empty( $meta['kelas_id'] ) && function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $meta['kelas_id'] ) : '';
    $mapel_lbl  = ! empty( $meta['mapel'] ) ? $meta['mapel'] : '';
    $view_siswa = isset( $_GET['siswa'] ) ? absint( $_GET['siswa'] ) : 0;
    $success    = ! empty( $_GET['graded'] ) ? 'Nilai berhasil disimpan.' : $grade_msg['success'];
    $error      = $grade_msg['error'];

    // Detail satu siswa
    if ( $view_siswa ) {
        $siswa_ok = false;
        foreach ( $siswa_list as $s ) {
            if ( (int) $s->ID === $view_siswa ) {
                $siswa_ok = true;
                break;
            }
        }
        $jid      = function_exists( 'qrg_find_jawaban' ) ? qrg_find_jawaban( $tugas_id, $view_siswa ) : 0;
        $isi      = $jid ? get_post_meta( $jid, '_jawaban_isi', true ) : '';
        $file_url = $jid ? get_post_meta( $jid, '_jawaban_file_url', true ) : '';
        $nilai    = $jid ? get_post_meta( $jid, '_jawaban_nilai', true ) : '';
        $catatan  = $jid ? get_post_meta( $jid, '_jawaban_catatan_guru', true ) : '';
        $status   = $jid ? ( get_post_meta( $jid, '_jawaban_status', true ) ?: 'Dikirim' ) : '';
        $dikirim  = $jid ? get_the_date( 'd M Y H:i', $jid ) : '';
        $list_url = qrg_get_guru_portal_url( 'jadwal', [ 'tugas' => $tugas_id ] );

        qrg_guru_portal_shell_start( 'Jawaban Siswa', 'jadwal' );
        ?>
        <div class="qrg-gp">
            <a class="back" href="<?php echo esc_url( $list_url ); ?>">&larr; Daftar pengumpulan</a>
            <header class="ios-hero">
                <p class="brand">Penilaian</p>
                <h1><?php echo esc_html( get_the_title( $view_siswa ) ?: 'Siswa' ); ?></h1>
                <p class="sub"><?php echo esc_html( get_the_title( $tugas_id ) ); ?><?php echo $kelas_lbl ? ' · Kelas ' . esc_html( $kelas_lbl ) : ''; ?></p>
            </header>
            <?php if ( $error ) : ?><div class="err"><?php echo esc_html( $error ); ?></div><?php endif; ?>
            <?php if ( $success ) : ?><div class="ok"><?php echo esc_html( $success ); ?></div><?php endif; ?>

            <?php if ( ! $siswa_ok ) : ?>
                <p class="empty">Siswa tidak termasuk kelas tugas ini.</p>
            <?php elseif ( ! $jid ) : ?>
                <p class="empty">Siswa belum mengumpulkan tugas.</p>
            <?php else : ?>
                <div class="meta" style="margin-bottom:10px;">
                    Status: <span class="badge <?php echo $status === 'Dinilai' ? 'badge-ok' : 'badge-info'; ?>"><?php echo esc_html( $status ); ?></span>
                    <?php if ( $dikirim ) : ?> · Dikirim <?php echo esc_html( $dikirim ); ?><?php endif; ?>
                </div>
                <?php if ( $isi !== '' && $isi !== null ) : ?>
                    <div class="section-label" style="margin-top:0;">Jawaban teks</div>
                    <div class="jawab-box"><?php echo esc_html( $isi ); ?></div>
                <?php endif; ?>
                <?php if ( $file_url ) : ?>
                    <a class="jawab-file" href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener">Unduh / lihat lampiran</a>
                <?php endif; ?>
                <?php if ( $isi === '' && ! $file_url ) : ?>
                    <p class="empty">Tidak ada isi jawaban teks atau lampiran.</p>
                <?php endif; ?>

                <div class="section-label">Penilaian</div>
                <form method="post" class="row-card">
                    <?php wp_nonce_field( 'qrg_guru_nilai_' . $guru_id . '_' . $tugas_id, 'qrg_guru_nilai_nonce' ); ?>
                    <input type="hidden" name="qrg_guru_nilai_jawaban" value="1">
                    <input type="hidden" name="tugas_id" value="<?php echo (int) $tugas_id; ?>">
                    <input type="hidden" name="siswa_id" value="<?php echo (int) $view_siswa; ?>">
                    <div class="field">
                        <label for="jawaban_nilai">Nilai<?php echo ! empty( $meta['poin_max'] ) ? ' (maks ' . (int) $meta['poin_max'] . ')' : ''; ?></label>
                        <input type="number" name="jawaban_nilai" id="jawaban_nilai" value="<?php echo esc_attr( $nilai ); ?>" min="0" max="<?php echo ! empty( $meta['poin_max'] ) ? (int) $meta['poin_max'] : 1000; ?>" step="0.01" required>
                    </div>
                    <div class="field">
                        <label for="jawaban_catatan">Catatan untuk siswa</label>
                        <textarea name="jawaban_catatan" id="jawaban_catatan" rows="3" placeholder="Opsional"><?php echo esc_textarea( $catatan ); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-block">Simpan Nilai</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
        qrg_guru_portal_shell_end();
        return;
    }

    qrg_guru_portal_shell_start( 'Pengumpulan Tugas', 'jadwal' );
    ?>
    <div class="qrg-gp">
        <a class="back" href="<?php echo esc_url( $back ); ?>">&larr; Jadwal &amp; Tugas</a>
        <header class="ios-hero">
            <p class="brand">Pengumpulan</p>
            <h1><?php echo esc_html( get_the_title( $tugas_id ) ); ?></h1>
            <p class="sub">
                <?php
                echo esc_html( implode( ' · ', array_filter( [
                    $kelas_lbl ? 'Kelas ' . $kelas_lbl : '',
                    $mapel_lbl,
                    ! empty( $meta['tipe'] ) ? strtoupper( $meta['tipe'] ) : '',
                ] ) ) );
                ?>
            </p>
        </header>

        <div class="mon-stats">
            <div class="mon-stat"><span class="mon-stat__n"><?php echo (int) $summary['total']; ?></span><span class="mon-stat__l">Total</span></div>
            <div class="mon-stat"><span class="mon-stat__n"><?php echo (int) $summary['submitted']; ?></span><span class="mon-stat__l">Kumpul</span></div>
            <div class="mon-stat"><span class="mon-stat__n"><?php echo (int) $summary['pending']; ?></span><span class="mon-stat__l">Belum</span></div>
            <div class="mon-stat"><span class="mon-stat__n"><?php echo (int) $summary['graded']; ?></span><span class="mon-stat__l">Dinilai</span></div>
        </div>

        <?php if ( empty( $siswa_list ) ) : ?>
            <p class="empty">Belum ada siswa di kelas tujuan tugas ini.</p>
        <?php else : ?>
            <div class="list">
                <?php foreach ( $siswa_list as $siswa ) :
                    $sid     = (int) $siswa->ID;
                    $jid     = function_exists( 'qrg_find_jawaban' ) ? qrg_find_jawaban( $tugas_id, $sid ) : 0;
                    $status  = $jid ? get_post_meta( $jid, '_jawaban_status', true ) : '';
                    $nilai   = $jid ? get_post_meta( $jid, '_jawaban_nilai', true ) : '';
                    $dikirim = $jid ? get_the_date( 'd M Y H:i', $jid ) : '';
                    $url     = qrg_get_guru_portal_url( 'jadwal', [ 'tugas' => $tugas_id, 'siswa' => $sid ] );
                    if ( ! $jid ) {
                        $badge = 'badge-wait';
                        $stxt  = 'Belum mengumpulkan';
                    } elseif ( $status === 'Dinilai' ) {
                        $badge = 'badge-ok';
                        $stxt  = 'Dinilai' . ( $nilai !== '' ? ' · ' . $nilai : '' );
                    } else {
                        $badge = 'badge-info';
                        $stxt  = 'Sudah dikirim' . ( $dikirim ? ' · ' . $dikirim : '' );
                    }
                    ?>
                    <?php if ( $jid ) : ?>
                        <a class="row-card" href="<?php echo esc_url( $url ); ?>" style="text-decoration:none;color:inherit;">
                            <strong><?php echo esc_html( $siswa->post_title ); ?></strong>
                            <div class="meta"><span class="badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $stxt ); ?></span> · Ketuk untuk lihat jawaban</div>
                        </a>
                    <?php else : ?>
                        <div class="row-card">
                            <strong><?php echo esc_html( $siswa->post_title ); ?></strong>
                            <div class="meta"><span class="badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $stxt ); ?></span></div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    qrg_guru_portal_shell_end();
}

function qrg_guru_portal_get_jurnal_list( $guru_id ) {
    return get_posts( [
        'post_type'      => 'master_jurnal',
        'posts_per_page' => 60,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            [
                'key'   => '_jurnal_guru_id',
                'value' => (string) (int) $guru_id,
            ],
        ],
    ] );
}

function qrg_guru_portal_jurnal_index( $guru_id ) {
    $list = qrg_guru_portal_get_jurnal_list( $guru_id );
    $out  = [
        'by_mapel_kelas' => [],
        'by_mapel'       => [],
        'all'            => [],
    ];
    foreach ( $list as $j ) {
        $jid      = (int) $j->ID;
        $mapel_id = (int) get_post_meta( $jid, '_jurnal_mapel_id', true );
        $kelas_id = (int) get_post_meta( $jid, '_jurnal_kelas_id', true );
        $tanggal  = (string) get_post_meta( $jid, '_jurnal_tanggal', true );
        $materi   = (string) get_post_meta( $jid, '_jurnal_materi', true );
        $item     = [
            'id'       => $jid,
            'title'    => get_the_title( $jid ),
            'mapel_id' => $mapel_id,
            'kelas_id' => $kelas_id,
            'mapel'    => $mapel_id && function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mapel_id ) : '',
            'kelas'    => $kelas_id && function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas_id ) : '',
            'tanggal'  => $tanggal,
            'materi'   => $materi,
            'label'    => $tanggal ? date_i18n( 'd M Y', strtotime( $tanggal ) ) : get_the_title( $jid ),
        ];
        $out['all'][] = $item;
        if ( $mapel_id > 0 ) {
            if ( ! isset( $out['by_mapel'][ $mapel_id ] ) ) {
                $out['by_mapel'][ $mapel_id ] = [];
            }
            $out['by_mapel'][ $mapel_id ][] = $item;
        }
        if ( $mapel_id > 0 && $kelas_id > 0 ) {
            if ( ! isset( $out['by_mapel_kelas'][ $mapel_id ][ $kelas_id ] ) ) {
                $out['by_mapel_kelas'][ $mapel_id ][ $kelas_id ] = [];
            }
            $out['by_mapel_kelas'][ $mapel_id ][ $kelas_id ][] = $item;
        }
    }
    return $out;
}

function qrg_guru_portal_match_jurnal_for_slot( $index, $mapel_id, $kelas_id ) {
    return qrg_guru_portal_match_tugas_for_slot( $index, $mapel_id, $kelas_id );
}

function qrg_guru_portal_jurnal_owned( $guru_id, $jurnal_id ) {
    $guru_id   = (int) $guru_id;
    $jurnal_id = (int) $jurnal_id;
    if ( ! $guru_id || ! $jurnal_id || get_post_type( $jurnal_id ) !== 'master_jurnal' ) {
        return false;
    }
    return (int) get_post_meta( $jurnal_id, '_jurnal_guru_id', true ) === $guru_id;
}

function qrg_guru_portal_create_jurnal( $guru_id ) {
    if ( ! isset( $_POST['qrg_guru_buat_jurnal'] ) ) {
        return [ 'error' => '', 'success' => '' ];
    }
    if ( ! isset( $_POST['qrg_guru_jurnal_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_guru_jurnal_nonce'] ) ), 'qrg_guru_buat_jurnal_' . $guru_id ) ) {
        return [ 'error' => 'Sesi tidak valid.', 'success' => '' ];
    }

    $tanggal  = isset( $_POST['jurnal_tanggal'] ) ? sanitize_text_field( wp_unslash( $_POST['jurnal_tanggal'] ) ) : '';
    $kelas_id = isset( $_POST['jurnal_kelas_id'] ) ? absint( $_POST['jurnal_kelas_id'] ) : 0;
    $mapel_id = isset( $_POST['jurnal_mapel_id'] ) ? absint( $_POST['jurnal_mapel_id'] ) : 0;
    $materi   = isset( $_POST['jurnal_materi'] ) ? sanitize_text_field( wp_unslash( $_POST['jurnal_materi'] ) ) : '';
    $kegiatan = isset( $_POST['jurnal_kegiatan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['jurnal_kegiatan'] ) ) : '';
    $catatan  = isset( $_POST['jurnal_catatan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['jurnal_catatan'] ) ) : '';

    if ( ! $tanggal || ! $kelas_id || ! $mapel_id ) {
        return [ 'error' => 'Tanggal, mapel, dan kelas wajib diisi.', 'success' => '' ];
    }
    if ( $materi === '' && $kegiatan === '' ) {
        return [ 'error' => 'Isi materi atau kegiatan pembelajaran.', 'success' => '' ];
    }

    $title = function_exists( 'qrg_jurnal_build_title' )
        ? qrg_jurnal_build_title( $mapel_id, $kelas_id, $tanggal )
        : 'Jurnal';

    $jid = wp_insert_post( [
        'post_type'   => 'master_jurnal',
        'post_status' => 'publish',
        'post_title'  => $title,
    ], true );

    if ( is_wp_error( $jid ) || ! $jid ) {
        return [ 'error' => 'Gagal menyimpan jurnal.', 'success' => '' ];
    }

    update_post_meta( $jid, '_jurnal_guru_id', (int) $guru_id );
    update_post_meta( $jid, '_jurnal_mapel_id', $mapel_id );
    update_post_meta( $jid, '_jurnal_kelas_id', $kelas_id );
    update_post_meta( $jid, '_jurnal_tanggal', $tanggal );
    update_post_meta( $jid, '_jurnal_materi', $materi );
    update_post_meta( $jid, '_jurnal_kegiatan', $kegiatan );
    update_post_meta( $jid, '_jurnal_catatan', $catatan );

    wp_safe_redirect( qrg_get_guru_portal_url( 'jadwal', [ 'jurnal' => $jid, 'jurnal_created' => 1 ] ) );
    exit;
}

function qrg_render_guru_portal_jurnal_detail( $guru_id, $jurnal_id ) {
    $back = qrg_get_guru_portal_url( 'jadwal' );
    if ( ! qrg_guru_portal_jurnal_owned( $guru_id, $jurnal_id ) ) {
        qrg_guru_portal_shell_start( 'Jurnal', 'jadwal' );
        echo '<div class="qrg-gp"><a class="back" href="' . esc_url( $back ) . '">&larr; Kembali</a><p class="empty">Jurnal tidak ditemukan.</p></div>';
        qrg_guru_portal_shell_end();
        return;
    }

    $mapel_id = (int) get_post_meta( $jurnal_id, '_jurnal_mapel_id', true );
    $kelas_id = (int) get_post_meta( $jurnal_id, '_jurnal_kelas_id', true );
    $tanggal  = get_post_meta( $jurnal_id, '_jurnal_tanggal', true );
    $materi   = get_post_meta( $jurnal_id, '_jurnal_materi', true );
    $kegiatan = get_post_meta( $jurnal_id, '_jurnal_kegiatan', true );
    $catatan  = get_post_meta( $jurnal_id, '_jurnal_catatan', true );
    $mapel_lbl = $mapel_id && function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mapel_id ) : '';
    $kelas_lbl = $kelas_id && function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas_id ) : '';
    $success   = ! empty( $_GET['jurnal_created'] ) ? 'Jurnal berhasil disimpan.' : '';

    qrg_guru_portal_shell_start( 'Jurnal Mengajar', 'jadwal' );
    ?>
    <div class="qrg-gp">
        <a class="back" href="<?php echo esc_url( $back ); ?>">&larr; Jadwal &amp; Tugas</a>
        <header class="ios-hero">
            <p class="brand">Jurnal</p>
            <h1>Jurnal Mengajar</h1>
            <p class="sub">
                <?php
                echo esc_html( implode( ' · ', array_filter( [
                    $tanggal ? date_i18n( 'd M Y', strtotime( $tanggal ) ) : '',
                    $mapel_lbl,
                    $kelas_lbl ? 'Kelas ' . $kelas_lbl : '',
                ] ) ) );
                ?>
            </p>
        </header>
        <?php if ( $success ) : ?><div class="ok"><?php echo esc_html( $success ); ?></div><?php endif; ?>
        <div class="row-card">
            <?php if ( $materi ) : ?>
                <div class="section-label" style="margin-top:0;">Materi / Topik</div>
                <p style="margin:0 0 12px;font-size:14px;"><?php echo esc_html( $materi ); ?></p>
            <?php endif; ?>
            <?php if ( $kegiatan ) : ?>
                <div class="section-label" style="margin-top:0;">Kegiatan Pembelajaran</div>
                <div class="jawab-box"><?php echo esc_html( $kegiatan ); ?></div>
            <?php endif; ?>
            <?php if ( $catatan ) : ?>
                <div class="section-label">Catatan / Kendala</div>
                <p style="margin:0;font-size:14px;color:#64748b;"><?php echo esc_html( $catatan ); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    qrg_guru_portal_shell_end();
}

function qrg_render_guru_portal_jadwal( $guru_id ) {
    $view_jurnal = isset( $_GET['jurnal'] ) ? absint( $_GET['jurnal'] ) : 0;
    if ( $view_jurnal && ! isset( $_GET['jurnal_baru'] ) && ! isset( $_GET['baru'] ) ) {
        qrg_render_guru_portal_jurnal_detail( $guru_id, $view_jurnal );
        return;
    }

    $view_tugas = isset( $_GET['tugas'] ) ? absint( $_GET['tugas'] ) : 0;
    if ( $view_tugas && ! isset( $_GET['baru'] ) && ! isset( $_GET['jurnal_baru'] ) ) {
        qrg_render_guru_portal_tugas_monitor( $guru_id, $view_tugas );
        return;
    }

    $nama           = get_the_title( $guru_id );
    $hub            = qrg_get_guru_portal_url();
    $msg_tugas      = qrg_guru_portal_create_tugas( $guru_id );
    $msg_jurnal     = qrg_guru_portal_create_jurnal( $guru_id );
    $by             = function_exists( 'qrg_get_jadwal_by_guru' ) ? qrg_get_jadwal_by_guru( $guru_id ) : [];
    $hari_opts      = function_exists( 'qrg_jadwal_hari_options' ) ? qrg_jadwal_hari_options() : [];
    $today          = qrg_guru_portal_today_hari();
    $index_tugas    = qrg_guru_portal_tugas_index( $guru_id );
    $index_jurnal   = qrg_guru_portal_jurnal_index( $guru_id );
    $show_tugas_form   = isset( $_GET['baru'] );
    $show_jurnal_form  = isset( $_GET['jurnal_baru'] );
    $pre_kelas      = isset( $_GET['kelas_id'] ) ? absint( $_GET['kelas_id'] ) : 0;
    $pre_mapel      = isset( $_GET['mapel_id'] ) ? absint( $_GET['mapel_id'] ) : 0;
    if ( ! empty( $_GET['created'] ) ) {
        $success = 'Tugas berhasil diterbitkan.';
    } elseif ( ! empty( $_GET['jurnal_created'] ) ) {
        $success = 'Jurnal berhasil disimpan.';
    } else {
        $success = $msg_tugas['success'] ? $msg_tugas['success'] : $msg_jurnal['success'];
    }
    $error          = $msg_tugas['error'] ?: $msg_jurnal['error'];
    $kelas          = function_exists( 'qrg_get_daftar_kelas' ) ? qrg_get_daftar_kelas() : [];
    $mapel          = function_exists( 'qrg_get_daftar_mapel' ) ? qrg_get_daftar_mapel( true ) : [];
    $today_date     = current_time( 'Y-m-d' );

    $total = 0;
    $days_with_rows = [];
    foreach ( $by as $hari_key => $rows ) {
        $cnt = count( $rows );
        $total += $cnt;
        if ( $cnt > 0 && isset( $hari_opts[ $hari_key ] ) ) {
            $days_with_rows[] = [
                'hari'  => $hari_key,
                'label' => $hari_opts[ $hari_key ],
                'count' => $cnt,
            ];
        }
    }

    $is_jadwal_list = ! $show_jurnal_form && ! $show_tugas_form;

    qrg_guru_portal_shell_start( 'Jadwal & Tugas', 'jadwal' );
    ?>
    <div class="qrg-gp<?php echo $is_jadwal_list ? ' qrg-gp--jadwal' : ''; ?>">
        <header class="ios-hero">
            <p class="brand">Portal Guru</p>
            <h1><?php echo $is_jadwal_list ? 'Jadwal &amp; Tugas' : ( $show_jurnal_form ? 'Tulis Jurnal' : 'Buat Tugas' ); ?></h1>
            <p class="sub"><?php echo esc_html( $nama ); ?></p>
        </header>

        <?php if ( $error ) : ?><div class="err"><?php echo esc_html( $error ); ?></div><?php endif; ?>
        <?php if ( $success ) : ?><div class="ok"><?php echo esc_html( $success ); ?></div><?php endif; ?>

        <?php if ( $show_jurnal_form ) : ?>
            <a class="back" href="<?php echo esc_url( qrg_get_guru_portal_url( 'jadwal' ) ); ?>">&larr; Kembali ke jadwal</a>
            <form method="post" class="row-card">
                <?php wp_nonce_field( 'qrg_guru_buat_jurnal_' . $guru_id, 'qrg_guru_jurnal_nonce' ); ?>
                <input type="hidden" name="qrg_guru_buat_jurnal" value="1">
                <input type="hidden" name="jurnal_kelas_id" value="<?php echo (int) $pre_kelas; ?>">
                <input type="hidden" name="jurnal_mapel_id" value="<?php echo (int) $pre_mapel; ?>">
                <div class="field">
                    <label for="jurnal_tanggal">Tanggal</label>
                    <input type="date" name="jurnal_tanggal" id="jurnal_tanggal" value="<?php echo esc_attr( $today_date ); ?>" required>
                </div>
                <?php if ( $pre_mapel && function_exists( 'qrg_get_mapel_label' ) ) : ?>
                    <p class="sub" style="margin:0 0 12px;">Mapel: <strong><?php echo esc_html( qrg_get_mapel_label( $pre_mapel ) ); ?></strong><?php echo $pre_kelas && function_exists( 'qrg_get_kelas_label' ) ? ' · Kelas ' . esc_html( qrg_get_kelas_label( $pre_kelas ) ) : ''; ?></p>
                <?php endif; ?>
                <div class="field">
                    <label for="jurnal_materi">Materi / Topik</label>
                    <input type="text" name="jurnal_materi" id="jurnal_materi" placeholder="Contoh: Bab 3 — Persamaan Linear">
                </div>
                <div class="field">
                    <label for="jurnal_kegiatan">Kegiatan Pembelajaran</label>
                    <textarea name="jurnal_kegiatan" id="jurnal_kegiatan" rows="5" placeholder="Pembukaan, inti, penutupan, metode…"></textarea>
                </div>
                <div class="field">
                    <label for="jurnal_catatan">Catatan / Kendala</label>
                    <textarea name="jurnal_catatan" id="jurnal_catatan" rows="3" placeholder="Opsional"></textarea>
                </div>
                <button type="submit" class="btn btn-block">Simpan Jurnal</button>
            </form>
        <?php elseif ( $show_tugas_form ) : ?>
            <a class="back" href="<?php echo esc_url( qrg_get_guru_portal_url( 'jadwal' ) ); ?>">&larr; Kembali ke jadwal</a>
            <form method="post" class="row-card">
                <?php wp_nonce_field( 'qrg_guru_buat_tugas_' . $guru_id, 'qrg_guru_tugas_nonce' ); ?>
                <input type="hidden" name="qrg_guru_buat_tugas" value="1">
                <div class="field">
                    <label for="tugas_judul">Judul Tugas</label>
                    <input type="text" name="tugas_judul" id="tugas_judul" required placeholder="Contoh: Latihan Bab 3">
                </div>
                <div class="field">
                    <label for="tugas_isi">Petunjuk / Deskripsi</label>
                    <textarea name="tugas_isi" id="tugas_isi" rows="4" placeholder="Instruksi untuk siswa"></textarea>
                </div>
                <div class="field">
                    <label for="tugas_kelas_id">Kelas</label>
                    <select name="tugas_kelas_id" id="tugas_kelas_id" required>
                        <option value="">-- Pilih Kelas --</option>
                        <?php foreach ( $kelas as $k ) :
                            $kid   = (int) $k->ID;
                            $label = function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kid ) : $k->post_title;
                            ?>
                            <option value="<?php echo $kid; ?>" <?php selected( $pre_kelas, $kid ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="tugas_mapel_id">Mata Pelajaran</label>
                    <select name="tugas_mapel_id" id="tugas_mapel_id">
                        <option value="">-- Opsional --</option>
                        <?php foreach ( $mapel as $m ) : ?>
                            <option value="<?php echo (int) $m->ID; ?>" <?php selected( $pre_mapel, (int) $m->ID ); ?>><?php echo esc_html( function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $m->ID ) : $m->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="tugas_deadline">Batas Pengumpulan</label>
                    <input type="datetime-local" name="tugas_deadline" id="tugas_deadline">
                </div>
                <div class="field">
                    <label for="tugas_poin_max">Nilai Maksimal</label>
                    <input type="number" name="tugas_poin_max" id="tugas_poin_max" value="100" min="1" max="1000">
                </div>
                <div class="field">
                    <label>Tipe</label>
                    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:12px;font-weight:500;">
                        <input type="radio" name="tugas_tipe" value="esai" checked> Esai
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:500;">
                        <input type="radio" name="tugas_tipe" value="cbt"> CBT
                    </label>
                    <p class="sub" style="margin:6px 0 0;">Untuk CBT dengan banyak soal, lanjutkan edit di admin setelah dibuat.</p>
                </div>
                <button type="submit" class="btn btn-block">Terbitkan Tugas</button>
            </form>
        <?php else : ?>

            <?php if ( $total === 0 ) : ?>
                <div class="jadwal-empty" role="status">
                    <div class="jadwal-empty__icon" aria-hidden="true">📅</div>
                    <strong>Belum ada jadwal mengajar</strong>
                    <p style="margin:0;font-size:13px;line-height:1.5;">Jadwal belum ditautkan ke akun Anda. Hubungi admin sekolah untuk mengatur jadwal mengajar.</p>
                </div>
            <?php else : ?>
                <?php if ( count( $days_with_rows ) > 1 ) : ?>
                    <div class="jadwal-nav-wrap">
                        <nav class="jadwal-nav" id="qrg-jadwal-nav" aria-label="Navigasi hari">
                            <?php foreach ( $days_with_rows as $dn ) :
                                $anchor = 'jadwal-' . sanitize_title( $dn['hari'] );
                                ?>
                                <a href="#<?php echo esc_attr( $anchor ); ?>"
                                   class="<?php echo $dn['hari'] === $today ? 'is-today' : ''; ?>"
                                   data-hari="<?php echo esc_attr( $dn['hari'] ); ?>">
                                    <?php echo esc_html( $dn['label'] ); ?>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                <?php endif; ?>
                <div class="jadwal-feed" id="qrg-jadwal-feed">
                <?php foreach ( $hari_opts as $hari => $label ) :
                    $rows = isset( $by[ $hari ] ) ? $by[ $hari ] : [];
                    if ( empty( $rows ) ) {
                        continue;
                    }
                    $anchor = 'jadwal-' . sanitize_title( $hari );
                    ?>
                    <section class="day day--reveal" id="<?php echo esc_attr( $anchor ); ?>" data-hari="<?php echo esc_attr( $hari ); ?>">
                        <div class="day__title<?php echo $hari === $today ? ' is-today' : ''; ?>">
                            <?php echo esc_html( $label ); ?><?php echo $hari === $today ? ' · Hari ini' : ''; ?>
                            <span class="day__count"><?php echo count( $rows ); ?> mapel</span>
                        </div>
                        <?php foreach ( $rows as $row ) :
                            $jam_raw = trim( ( $row['jam_mulai'] ? $row['jam_mulai'] : '' ) . ( $row['jam_selesai'] ? '–' . $row['jam_selesai'] : '' ) );
                            $jam_parts = preg_split( '/[–\-]/', $jam_raw );
                            $jam_display = $jam_raw;
                            if ( count( $jam_parts ) === 2 ) {
                                $jam_display = trim( $jam_parts[0] ) . '<br>' . trim( $jam_parts[1] );
                            }
                            $meta = array_filter( [ $row['kelas'] ? 'Kelas ' . $row['kelas'] : '', $row['ruangan'] ? 'Ruang ' . $row['ruangan'] : '' ] );
                            $mid  = isset( $row['mapel_id'] ) ? (int) $row['mapel_id'] : 0;
                            $kid  = isset( $row['kelas_id'] ) ? (int) $row['kelas_id'] : 0;
                            $slot_tugas   = qrg_guru_portal_match_tugas_for_slot( $index_tugas, $mid, $kid );
                            $slot_jurnal  = qrg_guru_portal_match_jurnal_for_slot( $index_jurnal, $mid, $kid );
                            $beri_args    = [ 'baru' => 1 ];
                            $jurnal_args  = [ 'jurnal_baru' => 1 ];
                            if ( $kid ) {
                                $beri_args['kelas_id'] = $kid;
                                $jurnal_args['kelas_id'] = $kid;
                            }
                            if ( $mid ) {
                                $beri_args['mapel_id'] = $mid;
                                $jurnal_args['mapel_id'] = $mid;
                            }
                            $beri_url   = qrg_get_guru_portal_url( 'jadwal', $beri_args );
                            $jurnal_url = qrg_get_guru_portal_url( 'jadwal', $jurnal_args );
                            ?>
                            <article class="slot-card">
                                <div class="slot-card__time"><?php echo $jam_display ? wp_kses( $jam_display, [ 'br' => [] ] ) : '—'; ?></div>
                                <div class="slot-card__body">
                                    <div class="slot-card__head">
                                        <h3 class="slot-card__mapel"><?php echo esc_html( $row['mapel'] ?: 'Mapel' ); ?></h3>
                                        <a class="chip chip--jurnal" href="<?php echo esc_url( $jurnal_url ); ?>">Jurnal</a>
                                    </div>
                                    <?php if ( $meta ) : ?>
                                        <p class="slot-card__meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></p>
                                    <?php endif; ?>
                                    <div class="slot-card__actions">
                                        <?php foreach ( $slot_jurnal as $sj ) :
                                            $jurnal_view = qrg_get_guru_portal_url( 'jadwal', [ 'jurnal' => (int) $sj['id'] ] );
                                            $chip_j = ! empty( $sj['materi'] ) ? wp_trim_words( $sj['materi'], 4, '…' ) : $sj['label'];
                                            ?>
                                            <a class="chip chip--jurnal-exist" href="<?php echo esc_url( $jurnal_view ); ?>"><?php echo esc_html( $chip_j ); ?></a>
                                        <?php endforeach; ?>
                                        <?php foreach ( $slot_tugas as $st ) :
                                            $chip = $st['title'];
                                            if ( ! empty( $st['summary'] ) ) {
                                                $chip .= ' (' . (int) $st['summary']['submitted'] . '/' . (int) $st['summary']['total'] . ')';
                                            }
                                            $tugas_url = qrg_get_guru_portal_url( 'jadwal', [ 'tugas' => (int) $st['id'] ] );
                                            ?>
                                            <a class="chip chip--tugas-exist" href="<?php echo esc_url( $tugas_url ); ?>"><?php echo esc_html( $chip ); ?></a>
                                        <?php endforeach; ?>
                                        <a class="chip chip--tugas" href="<?php echo esc_url( $beri_url ); ?>">+ Tugas</a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
                </div>
                <p class="jadwal-scroll-hint" id="qrg-jadwal-hint">Gulir untuk melihat jadwal lainnya</p>
                <script>
                (function(){
                    document.documentElement.classList.add('qrg-jadwal-smooth');
                    var feed=document.getElementById('qrg-jadwal-feed');
                    var nav=document.getElementById('qrg-jadwal-nav');
                    var hint=document.getElementById('qrg-jadwal-hint');
                    if(!feed) return;
                    var days=feed.querySelectorAll('.day--reveal');
                    var todayHari=<?php echo wp_json_encode( $today ); ?>;
                    var todayEl=todayHari ? feed.querySelector('.day--reveal[data-hari="'+todayHari+'"]') : null;
                    var revealDay=function(el,delay){
                        if(!el||el.classList.contains('is-visible')) return;
                        if(delay) el.style.transitionDelay=delay+'s';
                        el.classList.add('is-visible');
                    };
                    if(todayEl) revealDay(todayEl,0);
                    if(days[0]&&days[0]!==todayEl) revealDay(days[0],0.04);
                    if('IntersectionObserver' in window){
                        var io=new IntersectionObserver(function(entries){
                            entries.forEach(function(en){
                                if(en.isIntersecting){
                                    revealDay(en.target);
                                    io.unobserve(en.target);
                                }
                            });
                        },{root:null,rootMargin:'0px 0px -6% 0px',threshold:0.06});
                        days.forEach(function(d,i){
                            if(d.classList.contains('is-visible')) return;
                            d.style.transitionDelay=(i*0.05)+'s';
                            io.observe(d);
                        });
                    } else {
                        days.forEach(function(d){ revealDay(d); });
                    }
                    function scrollNavToActive(link){
                        if(!nav||!link) return;
                        var left=link.offsetLeft-(nav.clientWidth/2)+(link.offsetWidth/2);
                        nav.scrollTo({left:Math.max(0,left),behavior:'smooth'});
                    }
                    if(todayEl){
                        setTimeout(function(){
                            todayEl.scrollIntoView({behavior:'smooth',block:'start'});
                        },350);
                        if(nav){
                            var todayLink=nav.querySelector('a[data-hari="'+todayHari+'"]');
                            if(todayLink){
                                todayLink.classList.add('is-active');
                                scrollNavToActive(todayLink);
                            }
                        }
                    }
                    if(nav){
                        var links=nav.querySelectorAll('a');
                        links.forEach(function(a){
                            a.addEventListener('click',function(e){
                                var id=(a.getAttribute('href')||'').replace('#','');
                                var el=document.getElementById(id);
                                if(el){
                                    e.preventDefault();
                                    el.scrollIntoView({behavior:'smooth',block:'start'});
                                    links.forEach(function(x){ x.classList.remove('is-active'); });
                                    a.classList.add('is-active');
                                    scrollNavToActive(a);
                                }
                            });
                        });
                        if(window.IntersectionObserver){
                            var navIo=new IntersectionObserver(function(entries){
                                entries.forEach(function(en){
                                    if(en.isIntersecting){
                                        var h=en.target.getAttribute('data-hari');
                                        links.forEach(function(x){
                                            x.classList.toggle('is-active', x.getAttribute('data-hari')===h);
                                        });
                                    }
                                });
                            },{root:null,rootMargin:'-30% 0px -55% 0px',threshold:0});
                            days.forEach(function(d){ navIo.observe(d); });
                        }
                    }
                    var hideHint=function(){
                        if(!hint) return;
                        if(window.scrollY>80) hint.style.opacity='0';
                    };
                    window.addEventListener('scroll',hideHint,{passive:true});
                    setTimeout(function(){ if(hint) hint.style.display='none'; },8000);
                })();
                </script>
            <?php endif; ?>

        <?php endif; ?>
    </div>
    <?php
    qrg_guru_portal_shell_end();
}

function qrg_guru_portal_get_gaji_list( $guru_id ) {
    $ids = get_posts( [
        'post_type'      => 'master_gaji_guru',
        'posts_per_page' => 24,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            [
                'key'   => '_gaji_guru_id',
                'value' => (string) (int) $guru_id,
            ],
        ],
    ] );
    $bulan = function_exists( 'qrg_gaji_guru_bulan_options' ) ? qrg_gaji_guru_bulan_options() : [];
    $out   = [];
    foreach ( $ids as $gid ) {
        $b = (string) get_post_meta( $gid, '_gaji_bulan', true );
        if ( strlen( $b ) === 1 ) {
            $b = str_pad( $b, 2, '0', STR_PAD_LEFT );
        }
        $t = (int) get_post_meta( $gid, '_gaji_tahun', true );
        $out[] = [
            'id'               => (int) $gid,
            'bulan'            => $b,
            'tahun'            => $t,
            'periode'          => ( isset( $bulan[ $b ] ) ? $bulan[ $b ] : $b ) . ' ' . $t,
            'total'            => (int) get_post_meta( $gid, '_gaji_total', true ),
            'status'           => get_post_meta( $gid, '_gaji_status', true ) ?: 'Belum Dibayar',
            'pokok'            => (int) get_post_meta( $gid, '_gaji_nominal', true ),
            'tunjangan'        => (int) get_post_meta( $gid, '_gaji_tunjangan', true ),
            'tunjangan_jabatan'=> (int) get_post_meta( $gid, '_gaji_tunjangan_jabatan', true ),
            'tunjangan_hadir'  => (int) get_post_meta( $gid, '_gaji_tunjangan_hadir', true ),
            'potongan'         => (int) get_post_meta( $gid, '_gaji_potongan', true ),
            'komponen'         => get_post_meta( $gid, '_gaji_komponen', true ),
            'hari_hadir'       => (int) get_post_meta( $gid, '_gaji_hari_hadir', true ),
            'hari_izin'        => (int) get_post_meta( $gid, '_gaji_hari_izin', true ),
            'hari_sakit'       => (int) get_post_meta( $gid, '_gaji_hari_sakit', true ),
            'hari_alpha'       => (int) get_post_meta( $gid, '_gaji_hari_alpha', true ),
            'keterangan'       => (string) get_post_meta( $gid, '_gaji_keterangan', true ),
        ];
    }
    return $out;
}

/**
 * Deskripsi manusiawi untuk satu baris komponen slip gaji.
 *
 * @param array $item Item dari _gaji_komponen.
 * @return string
 */
function qrg_guru_portal_gaji_komponen_deskripsi( $item ) {
    $id       = isset( $item['id'] ) ? (string) $item['id'] : '';
    $tipe     = isset( $item['tipe'] ) ? $item['tipe'] : 'tetap';
    $operator = isset( $item['operator'] ) ? $item['operator'] : 'plus';
    $nilai    = isset( $item['nilai'] ) ? (int) $item['nilai'] : 0;
    $qty      = isset( $item['qty'] ) ? (int) $item['qty'] : 1;
    $basis    = isset( $item['basis'] ) ? $item['basis'] : '';
    $rp       = function ( $n ) {
        return 'Rp ' . number_format( (int) $n, 0, ',', '.' );
    };

    // Infer basis dari id jika data lama tidak akurat.
    if ( $tipe === 'per_hari' ) {
        if ( strpos( $id, 'alpha' ) !== false ) {
            $basis = 'alpha';
        } elseif ( strpos( $id, 'izin' ) !== false ) {
            $basis = 'izin';
        } elseif ( strpos( $id, 'sakit' ) !== false ) {
            $basis = 'sakit';
        } elseif ( strpos( $id, 'hadir' ) !== false || $basis === '' ) {
            $basis = 'hadir';
        }
    }

    $basis_label = [
        'hadir' => 'hari hadir',
        'izin'  => 'hari izin',
        'sakit' => 'hari sakit',
        'alpha' => 'hari alpha',
    ];
    $basis_unit = [
        'hadir' => 'hadir',
        'izin'  => 'izin',
        'sakit' => 'sakit',
        'alpha' => 'alpha',
    ];
    $basis_txt  = isset( $basis_label[ $basis ] ) ? $basis_label[ $basis ] : ( $basis ? 'hari ' . $basis : 'hari' );
    $basis_short = isset( $basis_unit[ $basis ] ) ? $basis_unit[ $basis ] : $basis;

    if ( $tipe === 'per_hari' ) {
        $aksi = ( $operator === 'minus' ) ? 'Potongan' : 'Tunjangan';
        if ( $qty <= 0 ) {
            return sprintf(
                '%s %s per %s. Tidak tercatat %s pada periode ini, sehingga nominalnya Rp 0.',
                $aksi,
                $rp( $nilai ),
                $basis_short,
                $basis_txt
            );
        }
        return sprintf(
            '%s dihitung %s × %d %s = %s.',
            $aksi,
            $rp( $nilai ),
            $qty,
            $basis_txt,
            $rp( $qty * $nilai )
        );
    }
    if ( $tipe === 'dari_jabatan' ) {
        return 'Tunjangan berdasarkan jabatan yang diampu guru pada periode ini. Nominal: ' . $rp( $nilai ) . '.';
    }
    if ( $operator === 'minus' ) {
        return 'Potongan tetap bulanan sebesar ' . $rp( $nilai ) . '.';
    }
    if ( $id === 'gaji_pokok' ) {
        return 'Gaji pokok tetap bulanan sebesar ' . $rp( $nilai ) . '.';
    }
    return 'Komponen tetap bulanan sebesar ' . $rp( $nilai ) . '.';
}

function qrg_render_guru_portal_gaji( $guru_id ) {
    $hub   = qrg_get_guru_portal_url();
    $list  = qrg_guru_portal_get_gaji_list( $guru_id );
    $view  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $fmt   = function ( $n ) {
        return 'Rp ' . number_format( (int) $n, 0, ',', '.' );
    };
    $nama  = get_the_title( $guru_id );
    $jabatan = get_post_meta( $guru_id, '_guru_jabatan', true );
    if ( ! $jabatan ) {
        $jid = (int) get_post_meta( $guru_id, '_guru_jabatan_id', true );
        if ( $jid && function_exists( 'qrg_get_jabatan_label' ) ) {
            $jabatan = qrg_get_jabatan_label( $jid );
        }
    }

    qrg_guru_portal_shell_start( 'Slip Gaji', 'gaji' );
    ?>
    <div class="qrg-gp">
        <header class="ios-hero">
            <p class="brand">Portal Guru</p>
            <h1>Slip Gaji</h1>
            <p class="sub"><?php echo esc_html( $nama ); ?><?php echo $jabatan ? ' · ' . esc_html( $jabatan ) : ''; ?></p>
        </header>

        <?php
        if ( $view ) {
            $item = null;
            foreach ( $list as $row ) {
                if ( (int) $row['id'] === $view ) {
                    $item = $row;
                    break;
                }
            }
            if ( ! $item ) {
                echo '<p class="empty">Slip tidak ditemukan.</p>';
            } else {
                $badge     = ( $item['status'] === 'Dibayar' ) ? 'badge-ok' : 'badge-wait';
                $komponen  = is_array( $item['komponen'] ) ? $item['komponen'] : [];
                $status_desc = ( $item['status'] === 'Dibayar' )
                    ? 'Slip ini sudah ditandai dibayar oleh sekolah.'
                    : 'Slip ini masih menunggu pembayaran.';
                ?>
                <a class="back" href="<?php echo esc_url( qrg_get_guru_portal_url( 'gaji' ) ); ?>">&larr; Daftar slip</a>
                <div class="slip-card">
                    <div class="slip-head">
                        <strong>Periode <?php echo esc_html( $item['periode'] ); ?></strong>
                        <div class="meta" style="margin:0 0 6px;">
                            <span class="badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $item['status'] ); ?></span>
                        </div>
                        <p class="sub" style="margin:0;"><?php echo esc_html( $status_desc ); ?> Rincian di bawah mengikuti rumus gaji sekolah untuk periode ini.</p>
                    </div>

                    <div class="slip-hadir">
                        <div class="slip-hadir__item">
                            <span class="slip-hadir__num"><?php echo (int) $item['hari_hadir']; ?></span>
                            <span class="slip-hadir__lbl">Hadir</span>
                        </div>
                        <div class="slip-hadir__item">
                            <span class="slip-hadir__num"><?php echo (int) $item['hari_izin']; ?></span>
                            <span class="slip-hadir__lbl">Izin</span>
                        </div>
                        <div class="slip-hadir__item">
                            <span class="slip-hadir__num"><?php echo (int) $item['hari_sakit']; ?></span>
                            <span class="slip-hadir__lbl">Sakit</span>
                        </div>
                        <div class="slip-hadir__item">
                            <span class="slip-hadir__num"><?php echo (int) $item['hari_alpha']; ?></span>
                            <span class="slip-hadir__lbl">Alpha</span>
                        </div>
                    </div>

                    <div class="slip-lines">
                        <?php
                        if ( ! empty( $komponen ) ) {
                            foreach ( $komponen as $kom ) {
                                $label  = isset( $kom['label'] ) ? $kom['label'] : 'Komponen';
                                $jumlah = isset( $kom['jumlah'] ) ? (int) $kom['jumlah'] : 0;
                                $op     = isset( $kom['operator'] ) ? $kom['operator'] : 'plus';
                                $desc   = qrg_guru_portal_gaji_komponen_deskripsi( $kom );
                                $amt_cls = 'is-zero';
                                $prefix  = '';
                                if ( $jumlah > 0 ) {
                                    $amt_cls = ( $op === 'minus' ) ? 'is-minus' : 'is-plus';
                                    $prefix  = ( $op === 'minus' ) ? '− ' : '+ ';
                                }
                                ?>
                                <div class="slip-line">
                                    <div>
                                        <div class="slip-line__label"><?php echo esc_html( $label ); ?></div>
                                        <div class="slip-line__desc"><?php echo esc_html( $desc ); ?></div>
                                    </div>
                                    <div class="slip-line__amt <?php echo esc_attr( $amt_cls ); ?>">
                                        <?php echo esc_html( $prefix . $fmt( $jumlah ) ); ?>
                                    </div>
                                </div>
                                <?php
                            }
                        } else {
                            // Fallback jika komponen belum tersimpan.
                            $fallback = [
                                [ 'label' => 'Gaji Pokok', 'desc' => 'Gaji pokok tetap bulanan.', 'jumlah' => $item['pokok'], 'op' => 'plus' ],
                                [ 'label' => 'Tunjangan Jabatan', 'desc' => 'Tunjangan berdasarkan jabatan.', 'jumlah' => $item['tunjangan_jabatan'], 'op' => 'plus' ],
                                [ 'label' => 'Tunjangan Kehadiran', 'desc' => 'Tunjangan dari jumlah hari hadir.', 'jumlah' => $item['tunjangan_hadir'], 'op' => 'plus' ],
                                [ 'label' => 'Potongan', 'desc' => 'Total potongan pada periode ini.', 'jumlah' => $item['potongan'], 'op' => 'minus' ],
                            ];
                            foreach ( $fallback as $fb ) {
                                $amt_cls = $fb['jumlah'] > 0 ? ( $fb['op'] === 'minus' ? 'is-minus' : 'is-plus' ) : 'is-zero';
                                $prefix  = $fb['jumlah'] > 0 ? ( $fb['op'] === 'minus' ? '− ' : '+ ' ) : '';
                                ?>
                                <div class="slip-line">
                                    <div>
                                        <div class="slip-line__label"><?php echo esc_html( $fb['label'] ); ?></div>
                                        <div class="slip-line__desc"><?php echo esc_html( $fb['desc'] ); ?></div>
                                    </div>
                                    <div class="slip-line__amt <?php echo esc_attr( $amt_cls ); ?>">
                                        <?php echo esc_html( $prefix . $fmt( $fb['jumlah'] ) ); ?>
                                    </div>
                                </div>
                                <?php
                            }
                        }
                        ?>
                    </div>

                    <div class="slip-total">
                        <span>Total diterima</span>
                        <strong><?php echo esc_html( $fmt( $item['total'] ) ); ?></strong>
                    </div>
                    <?php if ( $item['keterangan'] ) : ?>
                        <div class="slip-note">Catatan: <?php echo esc_html( $item['keterangan'] ); ?></div>
                    <?php endif; ?>
                </div>
                <?php
            }
        } elseif ( empty( $list ) ) {
            echo '<p class="empty">Belum ada slip gaji.</p>';
        } else {
            echo '<div class="list">';
            foreach ( $list as $row ) {
                $badge = ( $row['status'] === 'Dibayar' ) ? 'badge-ok' : 'badge-wait';
                $url   = qrg_get_guru_portal_url( 'gaji', [ 'id' => $row['id'] ] );
                echo '<a class="row-card" href="' . esc_url( $url ) . '" style="text-decoration:none;color:inherit;">';
                echo '<strong>' . esc_html( $row['periode'] ) . '</strong>';
                echo '<div class="meta">' . esc_html( $fmt( $row['total'] ) ) . ' · <span class="badge ' . esc_attr( $badge ) . '">' . esc_html( $row['status'] ) . '</span></div>';
                echo '</a>';
            }
            echo '</div>';
        }
        ?>
    </div>
    <?php
    qrg_guru_portal_shell_end();
}

function qrg_guru_portal_get_presensi_list( $guru_id ) {
    $ids = get_posts( [
        'post_type'      => 'master_presensi_guru',
        'posts_per_page' => 60,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_key'       => '_presensi_tanggal',
        'orderby'        => 'meta_value',
        'order'          => 'DESC',
        'meta_query'     => [
            [
                'key'   => '_presensi_guru_id',
                'value' => (string) (int) $guru_id,
            ],
        ],
    ] );
    $out = [];
    foreach ( $ids as $pid ) {
        $out[] = [
            'id'         => (int) $pid,
            'tanggal'    => get_post_meta( $pid, '_presensi_tanggal', true ),
            'status'     => get_post_meta( $pid, '_presensi_status', true ) ?: '—',
            'jam_masuk'  => get_post_meta( $pid, '_presensi_jam_masuk', true ),
            'jam_pulang' => get_post_meta( $pid, '_presensi_jam_pulang', true ),
            'ket'        => get_post_meta( $pid, '_presensi_keterangan', true ),
        ];
    }
    return $out;
}

function qrg_render_guru_portal_presensi( $guru_id ) {
    $hub  = qrg_get_guru_portal_url();
    $list = qrg_guru_portal_get_presensi_list( $guru_id );

    qrg_guru_portal_shell_start( 'Presensi', 'presensi' );
    ?>
    <div class="qrg-gp">
        <header class="ios-hero">
            <p class="brand">Portal Guru</p>
            <h1>Presensi</h1>
            <p class="sub"><?php echo esc_html( get_the_title( $guru_id ) ); ?></p>
        </header>
        <?php if ( empty( $list ) ) : ?>
            <p class="empty">Belum ada data presensi.</p>
        <?php else : ?>
            <div class="list">
                <?php foreach ( $list as $row ) :
                    $tgl = $row['tanggal'] ? date_i18n( 'd M Y', strtotime( $row['tanggal'] ) ) : '—';
                    $st  = $row['status'];
                    $badge = ( $st === 'Hadir' ) ? 'badge-ok' : ( in_array( $st, [ 'Izin', 'Sakit' ], true ) ? 'badge-info' : 'badge-wait' );
                    $jam = trim( ( $row['jam_masuk'] ? $row['jam_masuk'] : '—' ) . ' – ' . ( $row['jam_pulang'] ? $row['jam_pulang'] : '—' ) );
                    ?>
                    <div class="row-card">
                        <strong><?php echo esc_html( $tgl ); ?> · <span class="badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $st ); ?></span></strong>
                        <div class="meta">Jam: <?php echo esc_html( $jam ); ?><?php echo $row['ket'] ? ' · ' . esc_html( $row['ket'] ) : ''; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    qrg_guru_portal_shell_end();
}

function qrg_guru_portal_get_tugas_list( $guru_id ) {
    return get_posts( [
        'post_type'      => 'master_tugas',
        'posts_per_page' => 40,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            [
                'key'   => '_tugas_guru_id',
                'value' => (string) (int) $guru_id,
            ],
        ],
    ] );
}

function qrg_guru_portal_create_tugas( $guru_id ) {
    if ( ! isset( $_POST['qrg_guru_buat_tugas'] ) ) {
        return [ 'error' => '', 'success' => '' ];
    }
    if ( ! isset( $_POST['qrg_guru_tugas_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_guru_tugas_nonce'] ) ), 'qrg_guru_buat_tugas_' . $guru_id ) ) {
        return [ 'error' => 'Sesi tidak valid.', 'success' => '' ];
    }

    $judul    = isset( $_POST['tugas_judul'] ) ? sanitize_text_field( wp_unslash( $_POST['tugas_judul'] ) ) : '';
    $isi      = isset( $_POST['tugas_isi'] ) ? wp_kses_post( wp_unslash( $_POST['tugas_isi'] ) ) : '';
    $kelas_id = isset( $_POST['tugas_kelas_id'] ) ? absint( $_POST['tugas_kelas_id'] ) : 0;
    $mapel_id = isset( $_POST['tugas_mapel_id'] ) ? absint( $_POST['tugas_mapel_id'] ) : 0;
    $deadline = isset( $_POST['tugas_deadline'] ) ? sanitize_text_field( wp_unslash( $_POST['tugas_deadline'] ) ) : '';
    $poin     = isset( $_POST['tugas_poin_max'] ) ? absint( $_POST['tugas_poin_max'] ) : 100;
    $tipe     = isset( $_POST['tugas_tipe'] ) ? sanitize_text_field( wp_unslash( $_POST['tugas_tipe'] ) ) : 'esai';

    if ( $judul === '' || ! $kelas_id ) {
        return [ 'error' => 'Judul dan kelas wajib diisi.', 'success' => '' ];
    }
    if ( ! in_array( $tipe, [ 'esai', 'cbt' ], true ) ) {
        $tipe = 'esai';
    }
    if ( $poin <= 0 ) {
        $poin = 100;
    }

    $tid = wp_insert_post( [
        'post_type'    => 'master_tugas',
        'post_status'  => 'publish',
        'post_title'   => $judul,
        'post_content' => $isi,
    ], true );

    if ( is_wp_error( $tid ) || ! $tid ) {
        return [ 'error' => 'Gagal menyimpan tugas.', 'success' => '' ];
    }

    update_post_meta( $tid, '_tugas_guru_id', (int) $guru_id );
    update_post_meta( $tid, '_tugas_kelas_id', $kelas_id );
    update_post_meta( $tid, '_tugas_mapel_id', $mapel_id );
    if ( $mapel_id ) {
        update_post_meta( $tid, '_tugas_mapel', get_the_title( $mapel_id ) );
    }
    update_post_meta( $tid, '_tugas_poin_max', $poin );
    update_post_meta( $tid, '_tugas_tipe', $tipe );
    if ( $deadline ) {
        update_post_meta( $tid, '_tugas_deadline', str_replace( 'T', ' ', $deadline ) );
    }

    wp_safe_redirect( qrg_get_guru_portal_url( 'jadwal', [ 'created' => 1 ] ) );
    exit;
}

/**
 * @deprecated Digabung ke qrg_render_guru_portal_jadwal. URL /sekolah/guru/tugas/ dialihkan.
 */
function qrg_render_guru_portal_tugas( $guru_id ) {
    wp_safe_redirect( qrg_get_guru_portal_url( 'jadwal' ) );
    exit;
}
