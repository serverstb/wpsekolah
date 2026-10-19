<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Portal siswa terpadu: /sekolah
 * Login sekali → menu Pembayaran, Pembelajaran & Perpustakaan.
 */

/**
 * URL portal sekolah.
 *
 * @param string $section '' | 'pembayaran' | 'pembelajaran' | 'jadwal' | 'buku' | 'izin' | 'saran' | 'informasi' | 'antarjemput' | 'kesehatan' | 'konseling' | 'pelajaran'
 */
function qrg_get_sekolah_portal_url( $section = '', $args = [] ) {
    $section = sanitize_key( (string) $section );
    $path    = 'sekolah/';
    if ( in_array( $section, [ 'pembayaran', 'pembelajaran', 'jadwal', 'buku', 'izin', 'saran', 'informasi', 'antarjemput', 'kesehatan', 'konseling' ], true ) ) {
        $path .= $section . '/';
    } elseif ( $section === 'pelajaran' ) {
        $jid = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
        if ( $jid > 0 ) {
            $path .= 'pelajaran/' . $jid . '/';
            unset( $args['id'] );
        } else {
            $path .= 'jadwal/';
        }
    }
    $url = home_url( '/' . $path );
    if ( ! empty( $args ) && is_array( $args ) ) {
        $url = add_query_arg( $args, $url );
    }
    return $url;
}

/**
 * URL halaman materi & quiz dari slot jadwal.
 *
 * @param int $jadwal_id ID master_jadwal.
 * @return string
 */
function qrg_get_pelajaran_url( $jadwal_id ) {
    $jadwal_id = (int) $jadwal_id;
    if ( $jadwal_id <= 0 ) {
        return qrg_get_sekolah_portal_url( 'jadwal' );
    }
    return qrg_get_sekolah_portal_url( 'pelajaran', [ 'id' => $jadwal_id ] );
}

/**
 * Nilai goto yang diizinkan setelah login portal siswa.
 *
 * @return string[]
 */
function qrg_sekolah_allowed_goto() {
    return [ 'pembayaran', 'pembelajaran', 'perpustakaan', 'buku', 'jadwal', 'izin', 'saran', 'informasi', 'antarjemput', 'kesehatan', 'konseling' ];
}

/**
 * Resolve URL tujuan setelah login (termasuk Perpustakaan).
 *
 * @param string $goto Kunci section.
 * @return string
 */
function qrg_sekolah_goto_url( $goto = '' ) {
    $goto = sanitize_key( (string) $goto );
    if ( in_array( $goto, [ 'perpustakaan', 'buku' ], true ) && function_exists( 'qrg_get_perpustakaan_url' ) ) {
        return qrg_get_perpustakaan_url();
    }
    // Pembelajaran digabung ke jadwal.
    if ( $goto === 'pembelajaran' ) {
        $goto = 'jadwal';
    }
    if ( in_array( $goto, [ 'pembayaran', 'jadwal', 'izin', 'saran', 'informasi', 'antarjemput', 'kesehatan', 'konseling' ], true ) ) {
        return qrg_get_sekolah_portal_url( $goto );
    }
    return qrg_get_sekolah_portal_url();
}

add_action( 'init', 'qrg_sekolah_portal_rewrite_rules' );
function qrg_sekolah_portal_rewrite_rules() {
    add_rewrite_rule(
        '^sekolah/?$',
        'index.php?qrg_sekolah_portal=hub',
        'top'
    );
    add_rewrite_rule(
        '^sekolah/(pembayaran|pembelajaran|jadwal|buku|izin|saran|informasi|antarjemput|kesehatan|konseling)/?$',
        'index.php?qrg_sekolah_portal=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^sekolah/bayar/([0-9]+)/?$',
        'index.php?qrg_sekolah_portal=bayar&qrg_tagihan_id=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^sekolah/pelajaran/([0-9]+)/?$',
        'index.php?qrg_sekolah_portal=pelajaran&qrg_jadwal_id=$matches[1]',
        'top'
    );
}

add_filter( 'query_vars', 'qrg_sekolah_portal_query_vars' );
function qrg_sekolah_portal_query_vars( $vars ) {
    $vars[] = 'qrg_sekolah_portal';
    $vars[] = 'qrg_tagihan_id';
    $vars[] = 'qrg_jadwal_id';
    $vars[] = 'qrg_perpus_baca';
    $vars[] = 'qrg_perpus_pdf';
    return $vars;
}

add_action( 'admin_init', 'qrg_sekolah_portal_flush_rewrite_once' );
function qrg_sekolah_portal_flush_rewrite_once() {
    if ( get_option( 'qrg_sekolah_portal_rewrite_flushed_v13' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'qrg_sekolah_portal_rewrite_flushed_v13', 1 );
}

add_action( 'template_redirect', 'qrg_sekolah_portal_template_redirect', 5 );
function qrg_sekolah_portal_template_redirect() {
    $section = get_query_var( 'qrg_sekolah_portal' );

    // Fallback jika rewrite belum di-flush.
    if ( ! $section && isset( $_SERVER['REQUEST_URI'] ) ) {
        $path = wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        $path = is_string( $path ) ? untrailingslashit( $path ) : '';
        $base = untrailingslashit( (string) wp_parse_url( home_url( '/sekolah' ), PHP_URL_PATH ) );
        if ( $path === $base ) {
            $section = 'hub';
        } elseif ( preg_match( '#^' . preg_quote( $base, '#' ) . '/buku/baca/([0-9]+)$#', $path, $m ) ) {
            $section = 'buku';
            set_query_var( 'qrg_perpus_baca', (int) $m[1] );
        } elseif ( preg_match( '#^' . preg_quote( $base, '#' ) . '/(pembayaran|pembelajaran|jadwal|buku|izin|saran|informasi|antarjemput|kesehatan|konseling)$#', $path, $m ) ) {
            $section = $m[1];
        } elseif ( preg_match( '#^' . preg_quote( $base, '#' ) . '/bayar/([0-9]+)$#', $path, $m ) ) {
            $section = 'bayar';
            set_query_var( 'qrg_tagihan_id', (int) $m[1] );
        } elseif ( preg_match( '#^' . preg_quote( $base, '#' ) . '/pelajaran/([0-9]+)$#', $path, $m ) ) {
            $section = 'pelajaran';
            set_query_var( 'qrg_jadwal_id', (int) $m[1] );
        }
    }

    if ( ! $section ) {
        return;
    }
    $section = sanitize_key( (string) $section );
    if ( ! in_array( $section, [ 'hub', 'pembayaran', 'pembelajaran', 'jadwal', 'bayar', 'buku', 'izin', 'saran', 'informasi', 'antarjemput', 'kesehatan', 'konseling', 'pelajaran' ], true ) ) {
        return;
    }
    qrg_render_sekolah_portal( $section );
    exit;
}

/**
 * Proses login / logout portal sekolah. Mengembalikan pesan error atau string kosong.
 */
function qrg_sekolah_portal_handle_auth() {
    $error = '';

    if ( isset( $_GET['qrg_logout'] ) ) {
        if ( function_exists( 'qrg_pembayaran_logout' ) ) {
            qrg_pembayaran_logout();
        }
        $url = qrg_get_sekolah_portal_url();
        if ( isset( $_GET['qrg_idle'] ) ) {
            $url = add_query_arg(
                'qrg_err',
                rawurlencode( 'Sesi berakhir karena tidak aktif. Silakan masuk kembali.' ),
                $url
            );
        }
        wp_safe_redirect( $url );
        exit;
    }

    if ( ! isset( $_POST['qrg_sekolah_login'] ) ) {
        return $error;
    }

    if ( ! isset( $_POST['qrg_sekolah_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_sekolah_nonce'] ) ), 'qrg_sekolah_login' ) ) {
        return 'Sesi tidak valid. Muat ulang halaman.';
    }

    $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'login_nis', 8, 900 ) : true;
    if ( is_wp_error( $rl ) ) {
        return $rl->get_error_message();
    }

    $nis      = isset( $_POST['qrg_sekolah_nis'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_sekolah_nis'] ) ) : '';
    $password = isset( $_POST['qrg_sekolah_password'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_sekolah_password'] ) ) : '';
    if ( $password === '' ) {
        $password = $nis;
    }

    $siswa_id = function_exists( 'qrg_find_siswa_by_nis' ) ? qrg_find_siswa_by_nis( $nis ) : 0;
    $ok       = $siswa_id
        && function_exists( 'qrg_pembayaran_verify_nis' )
        && qrg_pembayaran_verify_nis( $siswa_id, $password );

    if ( $ok ) {
        if ( function_exists( 'qrg_rate_limit_clear' ) ) {
            qrg_rate_limit_clear( 'login_nis' );
        }
        $remember = ! empty( $_POST['qrg_sekolah_remember'] );
        qrg_pembayaran_set_auth_siswa_id( $siswa_id, $remember );
        $goto = isset( $_POST['qrg_sekolah_goto'] ) ? sanitize_key( wp_unslash( $_POST['qrg_sekolah_goto'] ) ) : '';
        if ( ! in_array( $goto, qrg_sekolah_allowed_goto(), true ) ) {
            $goto = '';
        }
        wp_safe_redirect( qrg_sekolah_goto_url( $goto ) );
        exit;
    }

    if ( function_exists( 'qrg_rate_limit_hit' ) ) {
        qrg_rate_limit_hit( 'login_nis', 900 );
    }

    return 'NIS atau password/PIN salah. Silakan coba lagi.';
}

function qrg_render_sekolah_portal( $section = 'hub' ) {
    $error   = qrg_sekolah_portal_handle_auth();
    $auth_id = function_exists( 'qrg_pembayaran_get_auth_siswa_id' ) ? qrg_pembayaran_get_auth_siswa_id() : 0;

    if ( isset( $_GET['qrg_err'] ) ) {
        $error = sanitize_text_field( wp_unslash( $_GET['qrg_err'] ) );
    }

    // Sub-halaman wajib login
    if ( in_array( $section, [ 'pembayaran', 'pembelajaran', 'jadwal', 'bayar', 'buku', 'izin', 'saran', 'informasi', 'antarjemput', 'kesehatan', 'konseling', 'pelajaran' ], true ) && ! $auth_id ) {
        if ( $section === 'bayar' ) {
            $goto = 'pembayaran';
        } elseif ( $section === 'pelajaran' ) {
            $goto = 'jadwal';
        } elseif ( $section === 'buku' ) {
            $goto = 'buku';
        } else {
            $goto = $section;
        }
        wp_safe_redirect( add_query_arg( 'goto', $goto, qrg_get_sekolah_portal_url() ) );
        exit;
    }

    if ( $section === 'pelajaran' && $auth_id ) {
        $jadwal_id = absint( get_query_var( 'qrg_jadwal_id' ) );
        if ( ! $jadwal_id && isset( $_GET['id'] ) ) {
            $jadwal_id = absint( $_GET['id'] );
        }
        if ( ! $jadwal_id || get_post_type( $jadwal_id ) !== 'master_jadwal' ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'jadwal' ) );
            exit;
        }
        // Pastikan jadwal milik kelas siswa.
        $siswa_kelas = (int) get_post_meta( $auth_id, '_siswa_kelas', true );
        $jadwal_kelas = (int) get_post_meta( $jadwal_id, '_jadwal_kelas_id', true );
        if ( ! $siswa_kelas || $siswa_kelas !== $jadwal_kelas ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'jadwal' ) );
            exit;
        }
        qrg_render_sekolah_portal_pelajaran( $jadwal_id, $auth_id );
        return;
    }

    if ( $section === 'bayar' && $auth_id ) {
        $tagihan_id = absint( get_query_var( 'qrg_tagihan_id' ) );
        if ( ! $tagihan_id && isset( $_GET['id'] ) ) {
            $tagihan_id = absint( $_GET['id'] );
        }
        if ( ! $tagihan_id || get_post_type( $tagihan_id ) !== 'master_tagihan' ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'pembayaran' ) );
            exit;
        }
        $siswa_id = (int) get_post_meta( $tagihan_id, '_tagihan_siswa_id', true );
        if ( ! $siswa_id || $siswa_id !== (int) $auth_id ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'pembayaran' ) );
            exit;
        }

        // Upload bukti dari halaman bayar portal
        $is_bukti_post = (
            ( isset( $_POST['qrg_upload_bukti'] ) || ( ! empty( $_FILES['bukti_transfer']['name'] ) && isset( $_POST['qrg_bukti_nonce'] ) ) )
            && isset( $_POST['qrg_bukti_nonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_bukti_nonce'] ) ), 'qrg_upload_action' )
        );

        if ( $is_bukti_post ) {
            $bayar_url = function_exists( 'qrg_get_tagihan_bayar_url' )
                ? qrg_get_tagihan_bayar_url( $tagihan_id )
                : qrg_get_sekolah_portal_url( 'pembayaran' );

            if ( empty( $_FILES['bukti_transfer']['name'] ) || empty( $_FILES['bukti_transfer']['tmp_name'] ) ) {
                wp_safe_redirect( add_query_arg( 'bukti_err', rawurlencode( 'Pilih foto bukti pembayaran terlebih dahulu.' ), $bayar_url ) );
                exit;
            }

            // Cek error upload PHP (ukuran/file corrupt).
            $file_err = isset( $_FILES['bukti_transfer']['error'] ) ? (int) $_FILES['bukti_transfer']['error'] : UPLOAD_ERR_NO_FILE;
            if ( $file_err !== UPLOAD_ERR_OK ) {
                $msg = 'Gagal mengunggah bukti. Coba lagi.';
                if ( $file_err === UPLOAD_ERR_INI_SIZE || $file_err === UPLOAD_ERR_FORM_SIZE ) {
                    $msg = 'Ukuran file terlalu besar. Kompres foto lalu coba lagi.';
                }
                wp_safe_redirect( add_query_arg( 'bukti_err', rawurlencode( $msg ), $bayar_url ) );
                exit;
            }

            $movefile = function_exists( 'qrg_handle_upload_safe' )
                ? qrg_handle_upload_safe( $_FILES['bukti_transfer'], qrg_upload_mimes_bukti() )
                : null;

            if ( is_array( $movefile ) && ! empty( $movefile['url'] ) && empty( $movefile['error'] ) ) {
                update_post_meta( $tagihan_id, '_tagihan_bukti_url', esc_url_raw( $movefile['url'] ) );
                // Ubah Belum Dibayar → Proses (nilai DB: Sedang Ditinjau).
                if ( function_exists( 'qrg_tagihan_set_status' ) ) {
                    qrg_tagihan_set_status( $tagihan_id, 'Sedang Ditinjau', [
                        'via'     => 'siswa',
                        'force'   => true,
                        'catatan' => 'Unggah bukti transfer — status Proses',
                    ] );
                }
                update_post_meta( $tagihan_id, '_tagihan_status', 'Sedang Ditinjau' );

                $redir = add_query_arg(
                    [
                        'bukti'  => 'ok',
                        'status' => 'proses',
                    ],
                    $bayar_url
                );
                wp_safe_redirect( $redir . '#status' );
                exit;
            }

            $err = ( is_array( $movefile ) && ! empty( $movefile['error'] ) )
                ? (string) $movefile['error']
                : 'Gagal mengunggah bukti. Gunakan foto JPG/PNG.';
            wp_safe_redirect( add_query_arg( 'bukti_err', rawurlencode( $err ), $bayar_url ) );
            exit;
        }

        if ( function_exists( 'qrg_render_sekolah_portal_bayar' ) ) {
            qrg_render_sekolah_portal_bayar( $tagihan_id );
        } elseif ( function_exists( 'qrg_render_invoice_page' ) ) {
            qrg_render_invoice_page( $tagihan_id );
        } else {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'pembayaran' ) );
        }
        exit;
    }

    if ( $section === 'pembayaran' && $auth_id ) {
        qrg_render_sekolah_portal_pembayaran( $auth_id );
        return;
    }
    // Pembelajaran digabung ke jadwal (materi & quiz per mapel).
    if ( $section === 'pembelajaran' && $auth_id ) {
        $tugas_redir = isset( $_GET['tugas'] ) ? absint( $_GET['tugas'] ) : 0;
        $target      = qrg_get_sekolah_portal_url( 'jadwal' );
        if ( $tugas_redir > 0 ) {
            $target = add_query_arg( 'tugas', $tugas_redir, $target );
        }
        wp_safe_redirect( $target );
        exit;
    }
    if ( $section === 'jadwal' && $auth_id ) {
        $view_tugas = isset( $_GET['tugas'] ) ? absint( $_GET['tugas'] ) : 0;
        if ( $view_tugas > 0 ) {
            qrg_render_sekolah_portal_pembelajaran( $auth_id );
        } else {
            qrg_render_sekolah_portal_jadwal_page( $auth_id );
        }
        return;
    }
    if ( $section === 'izin' && $auth_id ) {
        if ( function_exists( 'qrg_render_sekolah_portal_izin' ) ) {
            qrg_render_sekolah_portal_izin( $auth_id );
        }
        return;
    }
    if ( $section === 'saran' && $auth_id ) {
        if ( function_exists( 'qrg_render_sekolah_portal_saran' ) ) {
            qrg_render_sekolah_portal_saran( $auth_id );
        }
        return;
    }
    if ( $section === 'informasi' && $auth_id ) {
        if ( function_exists( 'qrg_render_sekolah_portal_informasi' ) ) {
            qrg_render_sekolah_portal_informasi( $auth_id );
        }
        return;
    }
    if ( $section === 'antarjemput' && $auth_id ) {
        if ( function_exists( 'qrg_render_sekolah_portal_antarjemput' ) ) {
            qrg_render_sekolah_portal_antarjemput( $auth_id );
        }
        return;
    }
    if ( $section === 'kesehatan' && $auth_id ) {
        if ( function_exists( 'qrg_render_sekolah_portal_kesehatan' ) ) {
            qrg_render_sekolah_portal_kesehatan( $auth_id, 'kesehatan' );
        }
        return;
    }
    if ( $section === 'konseling' && $auth_id ) {
        if ( function_exists( 'qrg_render_sekolah_portal_konseling' ) ) {
            qrg_render_sekolah_portal_konseling( $auth_id );
        } elseif ( function_exists( 'qrg_render_sekolah_portal_kesehatan' ) ) {
            qrg_render_sekolah_portal_kesehatan( $auth_id, 'konseling' );
        }
        return;
    }
    if ( $section === 'buku' && $auth_id ) {
        if ( function_exists( 'qrg_render_perpustakaan_page' ) ) {
            qrg_render_perpustakaan_page( $auth_id );
        }
        return;
    }

    // Hub / login
    if ( $auth_id ) {
        $goto_logged = isset( $_GET['goto'] ) ? sanitize_key( wp_unslash( $_GET['goto'] ) ) : '';
        if ( in_array( $goto_logged, [ 'perpustakaan', 'buku' ], true ) ) {
            wp_safe_redirect( qrg_sekolah_goto_url( 'buku' ) );
            exit;
        }
        if ( $goto_logged === 'jadwal' || $goto_logged === 'pembelajaran' ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'jadwal' ) );
            exit;
        }
        if ( $goto_logged === 'izin' ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'izin' ) );
            exit;
        }
        if ( $goto_logged === 'saran' ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'saran' ) );
            exit;
        }
        if ( $goto_logged === 'informasi' ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'informasi' ) );
            exit;
        }
        if ( $goto_logged === 'antarjemput' ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'antarjemput' ) );
            exit;
        }
        if ( $goto_logged === 'kesehatan' ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'kesehatan' ) );
            exit;
        }
        if ( $goto_logged === 'konseling' ) {
            wp_safe_redirect( qrg_get_sekolah_portal_url( 'konseling' ) );
            exit;
        }
        qrg_render_sekolah_portal_hub( $auth_id );
        return;
    }

    $goto = isset( $_GET['goto'] ) ? sanitize_key( wp_unslash( $_GET['goto'] ) ) : '';
    if ( ! in_array( $goto, qrg_sekolah_allowed_goto(), true ) ) {
        $goto = '';
    }
    qrg_render_sekolah_portal_login( $error, $goto );
}

function qrg_sekolah_portal_shell_start( $title, $extra_head = '', $nav_active = '' ) {
    $site = get_bloginfo( 'name' );
    $nav_active = sanitize_key( (string) $nav_active );
    $GLOBALS['qrg_sk_nav_active'] = $nav_active;
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
        <meta name="mobile-web-app-capable" content="yes">
        <title><?php echo esc_html( $title ); ?> — <?php echo esc_html( $site ); ?></title>
        <?php
        if ( function_exists( 'qrg_sekolah_pwa_head_tags' ) ) {
            echo qrg_sekolah_pwa_head_tags(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo $extra_head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
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
            }
            *, *::before, *::after { box-sizing: border-box; }
            html { -webkit-text-size-adjust: 100%; }
            body {
                margin: 0;
                padding: 0;
                min-height: 100dvh;
                font-family: var(--ios-font);
                background: var(--ios-bg);
                color: var(--ios-ink);
                line-height: 1.4;
                -webkit-font-smoothing: antialiased;
            }
            .qrg-sk-app {
                min-height: 100dvh;
                display: flex;
                flex-direction: column;
                padding: 0;
                padding-bottom: var(--ios-safe-b);
            }
            .qrg-sk-app.has-tabbar {
                padding-bottom: 0;
            }
            .qrg-sk {
                width: 100%;
                max-width: 430px;
                margin: 0 auto;
                background: var(--ios-bg);
                border-radius: 0;
                box-shadow: none;
                padding: calc(12px + var(--ios-safe-t)) 16px 28px;
                flex: 1;
            }
            .qrg-sk-app.has-tabbar .qrg-sk {
                padding-bottom: calc(28px + var(--ios-tabbar-h) + var(--ios-safe-b));
            }
            .qrg-sk.login {
                max-width: 430px;
                margin: 0 auto;
                display: flex;
                flex-direction: column;
                justify-content: center;
                min-height: 100dvh;
                padding-top: calc(24px + var(--ios-safe-t));
                padding-bottom: calc(28px + var(--ios-safe-b));
            }
            .qrg-sk h1 {
                margin: 0;
                font-size: 1.75rem;
                font-weight: 800;
                letter-spacing: -.03em;
                line-height: 1.15;
                word-break: break-word;
            }
            .qrg-sk .brand {
                font-size: 12px;
                font-weight: 600;
                letter-spacing: .02em;
                text-transform: none;
                color: var(--ios-secondary);
                margin: 0 0 4px;
            }
            .qrg-sk .sub {
                color: var(--ios-secondary);
                margin: 6px 0 0;
                font-size: 15px;
                font-weight: 400;
            }
            .qrg-sk .err {
                background: rgba(255,59,48,.1);
                color: var(--ios-red);
                border: 0;
                padding: 12px 14px;
                border-radius: 12px;
                margin-bottom: 14px;
                font-size: 14px;
                font-weight: 500;
            }
            .qrg-sk .ok {
                background: rgba(52,199,89,.12);
                color: #248A3D;
                border: 0;
                padding: 12px 14px;
                border-radius: 12px;
                margin-bottom: 14px;
                font-size: 14px;
            }
            .qrg-sk .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: var(--ios-blue);
                color: #fff !important;
                border: none;
                padding: 12px 18px;
                border-radius: 980px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                min-height: 44px;
                -webkit-tap-highlight-color: transparent;
                transition: opacity .15s, transform .12s;
            }
            .qrg-sk .btn:active { opacity: .85; transform: scale(.98); }
            .qrg-sk .btn-muted {
                background: var(--ios-fill);
                color: var(--ios-ink) !important;
            }
            .qrg-sk .btn-green { background: var(--ios-green); }
            .qrg-sk .btn-block { display: flex; width: 100%; border-radius: 14px; }
            .qrg-sk .topbar {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 12px;
                margin-bottom: 20px;
            }
            .qrg-sk .topbar .btn {
                flex-shrink: 0;
                padding: 8px 14px;
                font-size: 14px;
                min-height: 36px;
                font-weight: 600;
            }

            /* iOS large title header */
            .ios-hero {
                margin: 0 0 18px;
                padding: 4px 2px 2px;
            }
            .ios-hero__row {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
            }
            .ios-hero h1 { font-size: 2rem; margin-top: 2px; }
            .ios-avatar {
                width: 44px; height: 44px;
                border-radius: 50%;
                background: linear-gradient(145deg, #5AC8FA, #007AFF);
                color: #fff;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 17px;
                flex-shrink: 0;
                box-shadow: 0 4px 12px rgba(0,122,255,.25);
                overflow: hidden;
            }
            .ios-avatar.has-photo {
                background: var(--ios-fill);
                box-shadow: 0 4px 12px rgba(0,0,0,.08);
                padding: 0;
            }
            .ios-avatar img {
                width: 100%; height: 100%;
                object-fit: cover; display: block;
            }
            .qrg-sk-avatar-edit {
                position: relative;
                display: inline-block;
                margin: 0 auto 12px;
            }
            .qrg-sk-avatar-edit .ios-avatar {
                width: 88px; height: 88px; font-size: 32px;
                margin: 0;
            }
            .qrg-sk-avatar-edit__cam {
                position: absolute; right: 0; bottom: 0;
                width: 32px; height: 32px; border-radius: 50%;
                background: var(--ios-blue); color: #fff;
                border: 2.5px solid #fff;
                display: inline-flex; align-items: center; justify-content: center;
                cursor: pointer;
                box-shadow: 0 2px 8px rgba(0,122,255,.35);
                -webkit-tap-highlight-color: transparent;
            }
            .qrg-sk-avatar-edit__cam:active { transform: scale(.94); }
            .qrg-sk-avatar-edit__cam svg { width: 16px; height: 16px; display: block; }
            .qrg-sk-avatar-edit input[type=file] {
                position: absolute; width: 1px; height: 1px;
                opacity: 0; overflow: hidden; clip: rect(0,0,0,0);
            }
            .qrg-sk-avatar-actions {
                display: flex; flex-direction: column; gap: 8px;
                align-items: center; margin-top: 4px;
            }
            .qrg-sk-avatar-actions .btn {
                min-height: 36px; padding: 8px 16px; font-size: 14px;
            }
            .qrg-sk-avatar-hint {
                margin: 8px 0 0; font-size: 12px; color: var(--ios-secondary);
            }
            .qrg-sk-avatar-status {
                margin: 10px 0 0; font-size: 13px; font-weight: 600;
                color: var(--ios-blue); min-height: 0;
            }
            .qrg-sk-avatar-rules {
                margin: 14px 0 0;
                padding: 12px 14px;
                text-align: left;
                background: var(--ios-bg);
                border-radius: 12px;
                font-size: 13px;
                color: var(--ios-ink);
                line-height: 1.45;
            }
            .qrg-sk-avatar-rules__title {
                margin: 0 0 8px;
                font-size: 12px;
                font-weight: 600;
                color: var(--ios-secondary);
                text-transform: uppercase;
                letter-spacing: .02em;
            }
            .qrg-sk-avatar-rules ul {
                margin: 0;
                padding: 0 0 0 18px;
            }
            .qrg-sk-avatar-rules li {
                margin: 0 0 6px;
            }
            .qrg-sk-avatar-rules li:last-child { margin-bottom: 0; }
            .qrg-sk-pin-card {
                background: var(--ios-card);
                border-radius: var(--ios-radius);
                padding: 16px;
                box-shadow: var(--ios-shadow);
            }
            .qrg-sk-pin-card .qrg-calc { max-width: 100%; }
            .qrg-sk-pin-card .qrg-calc-hint { margin: 0 0 12px; text-align: center; }
            .qrg-sk-pin-steps {
                display: flex; gap: 6px; justify-content: center; flex-wrap: wrap;
                margin: 0 0 12px;
            }
            .qrg-sk-pin-step {
                font-size: 11px; font-weight: 600;
                color: var(--ios-secondary);
                padding: 5px 10px; border-radius: 980px;
                background: var(--ios-fill);
            }
            .qrg-sk-pin-step.is-on {
                background: rgba(0,122,255,.12);
                color: var(--ios-blue);
            }
            .qrg-sk-pin-step.is-done {
                background: rgba(52,199,89,.12);
                color: #1B7A31;
            }
            .qrg-sk-pin-nav {
                display: flex; justify-content: center; margin: 0 0 8px;
            }
            .qrg-sk-pin-nav button {
                appearance: none; border: 0; background: transparent;
                color: var(--ios-blue); font-size: 14px; font-weight: 600;
                font-family: inherit; cursor: pointer; padding: 6px 10px;
                min-height: 36px;
            }
            .qrg-sk-pin-nav button[hidden] { display: none; }
            .qrg-sk-pin-hint {
                margin: 12px 0 0;
                font-size: 12px;
                color: var(--ios-secondary);
                text-align: left;
                line-height: 1.4;
            }
            .qrg-sk-pin-err {
                display: none;
                margin: 0 0 10px;
                padding: 10px 12px;
                border-radius: 12px;
                background: rgba(255,59,48,.1);
                color: var(--ios-red);
                font-size: 13px; font-weight: 500;
                text-align: left;
            }
            .qrg-sk-pin-err.is-on { display: block; }
            .ios-icon--lock { background: linear-gradient(180deg, #FF9F0A, #FF375F); }
            .qrg-sk-security { margin-bottom: 0; }
            .qrg-sk-security-toggle {
                width: 100%; cursor: pointer; text-align: left;
                font-family: inherit; color: inherit;
                border: 0;
            }
            .qrg-sk-security-toggle:active { background: #E5E5EA; }
            .qrg-sk-security-toggle::after {
                transition: transform .28s cubic-bezier(.2,.8,.2,1);
            }
            .qrg-sk-security-toggle.is-open::after {
                transform: rotate(135deg);
            }
            .qrg-sk-security-panel {
                display: grid;
                grid-template-rows: 0fr;
                opacity: 0;
                transform: translateY(-6px);
                transition:
                    grid-template-rows .38s cubic-bezier(.2,.8,.2,1),
                    opacity .28s ease,
                    transform .32s cubic-bezier(.2,.8,.2,1);
            }
            .qrg-sk-security-panel.is-open {
                grid-template-rows: 1fr;
                opacity: 1;
                transform: translateY(0);
            }
            .qrg-sk-security-panel__inner {
                overflow: hidden;
                min-height: 0;
            }
            .qrg-sk-security-panel .qrg-sk-pin-card {
                margin-top: 10px;
                transform: translateY(8px) scale(.98);
                opacity: 0;
                transition: transform .34s cubic-bezier(.2,.8,.2,1), opacity .28s ease;
            }
            .qrg-sk-security-panel.is-open .qrg-sk-pin-card {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
            .ios-icon--photo { background: linear-gradient(180deg, #5AC8FA, #007AFF); }
            .qrg-sk-profile-card {
                padding: 18px 16px;
                text-align: center;
                margin-bottom: 16px;
            }
            .qrg-sk-profile-card .ios-avatar {
                width: 88px; height: 88px; font-size: 32px;
                margin: 0 auto 12px;
            }
            .qrg-sk-avatar-toggle {
                width: 100%; cursor: pointer; text-align: left;
                font-family: inherit; color: inherit; border: 0;
            }
            .qrg-sk-avatar-toggle:active { background: #E5E5EA; }
            .qrg-sk-avatar-toggle::after {
                transition: transform .28s cubic-bezier(.2,.8,.2,1);
            }
            .qrg-sk-avatar-toggle.is-open::after {
                transform: rotate(135deg);
            }
            .qrg-sk-avatar-panel {
                display: grid;
                grid-template-rows: 0fr;
                opacity: 0;
                transform: translateY(-6px);
                transition:
                    grid-template-rows .38s cubic-bezier(.2,.8,.2,1),
                    opacity .28s ease,
                    transform .32s cubic-bezier(.2,.8,.2,1);
            }
            .qrg-sk-avatar-panel.is-open {
                grid-template-rows: 1fr;
                opacity: 1;
                transform: translateY(0);
            }
            .qrg-sk-avatar-panel__inner {
                overflow: hidden;
                min-height: 0;
            }
            .qrg-sk-avatar-card {
                background: var(--ios-card);
                border-radius: var(--ios-radius);
                padding: 16px;
                box-shadow: var(--ios-shadow);
                margin-top: 10px;
                text-align: center;
                transform: translateY(8px) scale(.98);
                opacity: 0;
                transition: transform .34s cubic-bezier(.2,.8,.2,1), opacity .28s ease;
            }
            .qrg-sk-avatar-panel.is-open .qrg-sk-avatar-card {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
            .qrg-sk-avatar-panel.is-opening .qrg-sk-avatar-edit .ios-avatar {
                animation: qrgAvatarPop .42s cubic-bezier(.2,.8,.2,1);
            }
            .qrg-sk-avatar-panel.is-opening .qrg-sk-avatar-edit__cam {
                animation: qrgCamPop .48s cubic-bezier(.2,.8,.2,1) .08s both;
            }
            .qrg-sk-avatar-panel.is-opening .qrg-sk-avatar-rules {
                animation: qrgRulesIn .36s ease .12s both;
            }
            @keyframes qrgAvatarPop {
                from { transform: scale(.88); opacity: .5; }
                to { transform: scale(1); opacity: 1; }
            }
            @keyframes qrgCamPop {
                from { transform: scale(.6); opacity: 0; }
                to { transform: scale(1); opacity: 1; }
            }
            @keyframes qrgRulesIn {
                from { opacity: 0; transform: translateY(8px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .qrg-sk-pin-step {
                transition: background .22s ease, color .22s ease, transform .22s ease;
            }
            .qrg-sk-pin-step.is-on {
                transform: scale(1.04);
            }
            .qrg-sk-pin-body.is-switching .qrg-calc-screen {
                animation: qrgPinPulse .45s ease;
            }
            .qrg-sk-pin-body.is-switching .qrg-calc-pad {
                animation: qrgPinPadIn .32s cubic-bezier(.2,.8,.2,1);
            }
            @keyframes qrgPinPadIn {
                from { opacity: .35; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .qrg-sk-security-panel,
                .qrg-sk-security-panel .qrg-sk-pin-card,
                .qrg-sk-security-toggle::after,
                .qrg-sk-pin-step,
                .qrg-sk-pin-body.is-switching .qrg-calc-screen,
                .qrg-sk-pin-body.is-switching .qrg-calc-pad,
                .qrg-sk-avatar-panel,
                .qrg-sk-avatar-panel .qrg-sk-avatar-card,
                .qrg-sk-avatar-toggle::after,
                .qrg-sk-avatar-panel.is-opening .qrg-sk-avatar-edit .ios-avatar,
                .qrg-sk-avatar-panel.is-opening .qrg-sk-avatar-edit__cam,
                .qrg-sk-avatar-panel.is-opening .qrg-sk-avatar-rules {
                    transition: none !important;
                    animation: none !important;
                    transform: none !important;
                    opacity: 1 !important;
                }
            }
                position: fixed; inset: 0; z-index: 1200;
                display: flex; align-items: center; justify-content: center;
                padding: 24px 20px calc(24px + var(--ios-safe-b));
                background: rgba(0,0,0,.4);
                -webkit-backdrop-filter: blur(8px);
                backdrop-filter: blur(8px);
                opacity: 0; visibility: hidden; pointer-events: none;
                transition: opacity .2s ease, visibility .2s ease;
            }
            .qrg-sk-alert.is-open {
                opacity: 1; visibility: visible; pointer-events: auto;
            }
            .qrg-sk-alert__card {
                width: 100%; max-width: 280px;
                background: rgba(255,255,255,.94);
                border-radius: 14px;
                overflow: hidden;
                box-shadow: 0 16px 48px rgba(0,0,0,.22);
                text-align: center;
                transform: scale(.92); opacity: 0;
                transition: transform .22s cubic-bezier(.2,.8,.2,1), opacity .2s ease;
            }
            .qrg-sk-alert.is-open .qrg-sk-alert__card {
                transform: scale(1); opacity: 1;
            }
            .qrg-sk-alert__body {
                padding: 20px 16px 16px;
            }
            .qrg-sk-alert__icon {
                width: 44px; height: 44px; margin: 0 auto 10px;
                border-radius: 50%;
                background: rgba(255,59,48,.12);
                color: var(--ios-red);
                display: flex; align-items: center; justify-content: center;
            }
            .qrg-sk-alert__icon svg { width: 22px; height: 22px; display: block; }
            .qrg-sk-alert__title {
                margin: 0 0 6px;
                font-size: 17px; font-weight: 700;
                letter-spacing: -.02em; color: var(--ios-ink);
            }
            .qrg-sk-alert__text {
                margin: 0;
                font-size: 13px; line-height: 1.4;
                color: var(--ios-secondary);
            }
            .qrg-sk-alert__actions {
                display: grid;
                grid-template-columns: 1fr 1fr;
                border-top: 1px solid var(--ios-sep);
            }
            .qrg-sk-alert__actions button {
                appearance: none; border: 0; background: transparent;
                margin: 0; padding: 12px 8px;
                min-height: 48px; font-size: 17px; font-weight: 600;
                font-family: inherit; cursor: pointer;
                -webkit-tap-highlight-color: transparent;
            }
            .qrg-sk-alert__actions button:active { background: rgba(0,0,0,.05); }
            .qrg-sk-alert__cancel { color: var(--ios-blue); border-right: 1px solid var(--ios-sep); }
            .qrg-sk-alert__danger { color: var(--ios-red); font-weight: 700; }
            @media (prefers-reduced-motion: reduce) {
                .qrg-sk-alert, .qrg-sk-alert__card { transition: none; }
            }
            .ios-chip {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                margin-top: 10px;
                padding: 6px 12px;
                border-radius: 980px;
                background: var(--ios-card);
                color: var(--ios-secondary);
                font-size: 13px;
                font-weight: 500;
                box-shadow: 0 1px 2px rgba(0,0,0,.04);
            }
            .ios-chip i {
                width: 8px; height: 8px; border-radius: 50%;
                background: var(--ios-green);
                display: inline-block;
            }

            /* iOS grouped list */
            .ios-section { margin: 0 0 22px; }
            .ios-section__label {
                margin: 0 0 8px 12px;
                font-size: 13px;
                font-weight: 400;
                color: var(--ios-secondary);
                text-transform: uppercase;
                letter-spacing: .02em;
            }
            .qrg-sk-menu,
            .ios-list {
                display: grid;
                gap: 0;
                background: var(--ios-card);
                border-radius: var(--ios-radius);
                overflow: hidden;
                box-shadow: var(--ios-shadow);
            }
            .qrg-sk-card,
            .ios-row {
                display: grid;
                grid-template-columns: 44px 1fr auto;
                align-items: center;
                gap: 12px;
                text-decoration: none;
                color: inherit;
                padding: 14px 14px;
                background: var(--ios-card);
                border: 0;
                border-bottom: 1px solid var(--ios-sep);
                -webkit-tap-highlight-color: transparent;
                position: relative;
                transition: background .12s;
            }
            .qrg-sk-card:last-child,
            .ios-row:last-child { border-bottom: 0; }
            .qrg-sk-card:active,
            .ios-row:active { background: #E5E5EA; }
            .qrg-sk-card::after,
            .ios-row::after {
                content: "";
                width: 8px; height: 8px;
                border-right: 2px solid #C7C7CC;
                border-top: 2px solid #C7C7CC;
                transform: rotate(45deg);
                margin-left: 4px;
            }
            .ios-icon {
                width: 40px; height: 40px;
                border-radius: 11px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                color: #fff !important;
                font-size: 15px;
                font-weight: 700;
                flex-shrink: 0;
                line-height: 1;
            }
            .ios-icon svg { display: block; }
            .ios-icon--pay { background: linear-gradient(180deg, #64D2FF, #007AFF); }
            .ios-icon--book { background: linear-gradient(180deg, #63E6BE, #30B0C7); }
            .ios-icon--learn { background: linear-gradient(180deg, #BF5AF2, #AF52DE); }
            .qrg-sk-card strong,
            .ios-row strong {
                display: block;
                font-size: 16px;
                font-weight: 600;
                margin: 0 0 2px;
                color: var(--ios-ink);
            }
            .qrg-sk-card > div > span,
            .ios-row > div > span {
                font-size: 13px;
                color: var(--ios-secondary);
                line-height: 1.35;
            }
            .qrg-sk-card--perpus { background: var(--ios-card); border-color: transparent; }
            .qrg-sk-card--perpus strong { color: var(--ios-ink); }
            .qrg-sk-card--perpus:active { background: #E5E5EA; }

            /* Login */
            .qrg-google-wrap { text-align: center; margin-bottom: 16px; }
            .qrg-google-wrap .g_id_signin { display: inline-flex !important; justify-content: center; max-width: 100%; }
            .qrg-google-status { font-size: 13px; color: var(--ios-secondary); margin-top: 10px; min-height: 18px; }
            .qrg-divider {
                display: flex; align-items: center; gap: 10px;
                color: var(--ios-secondary); font-size: 13px; margin: 18px 0;
            }
            .qrg-divider::before, .qrg-divider::after {
                content: ""; flex: 1; height: 1px; background: var(--ios-sep);
            }
            .qrg-nis-toggle {
                display: block; width: 100%; text-align: center;
                font-size: 15px; color: var(--ios-blue);
                cursor: pointer; margin-bottom: 12px;
                background: none; border: none;
                text-decoration: none; padding: 12px; min-height: 44px;
                font-weight: 600;
            }
            .qrg-nis-panel { display: none; }
            .qrg-nis-panel.open { display: block; }
            .qrg-field { margin-bottom: 14px; }
            .qrg-field label {
                display: block; font-size: 13px; font-weight: 500;
                margin-bottom: 6px; color: var(--ios-secondary);
            }
            .qrg-field input[type=text], .qrg-field input[type=password] {
                width: 100%; padding: 14px 16px;
                border: 0; border-radius: 12px;
                font-size: 16px;
                background: var(--ios-fill);
                box-shadow: inset 0 0 0 1px transparent;
            }
            .qrg-field input:focus {
                outline: none;
                background: #fff;
                box-shadow: inset 0 0 0 2px rgba(0,122,255,.35);
            }
            .ios-login-card {
                background: var(--ios-card);
                border-radius: 20px;
                padding: 22px 18px;
                box-shadow: var(--ios-shadow);
                overflow: hidden;
            }

            /* Login steps */
            .qrg-login-stage {
                position: relative;
                min-height: 420px;
            }
            .qrg-login-step {
                display: none;
                opacity: 0;
                transform: translateX(28px) scale(.98);
                pointer-events: none;
            }
            .qrg-login-step.is-active {
                display: block;
                animation: qrgLoginIn .42s cubic-bezier(.2,.8,.2,1) forwards;
                pointer-events: auto;
            }
            .qrg-login-step.is-leave-left {
                display: block;
                animation: qrgLoginOutLeft .32s ease forwards;
                pointer-events: none;
                position: absolute; left: 0; right: 0; top: 0;
            }
            .qrg-login-step.is-leave-right {
                display: block;
                animation: qrgLoginOutRight .32s ease forwards;
                pointer-events: none;
                position: absolute; left: 0; right: 0; top: 0;
            }
            .qrg-login-step.is-active.from-left {
                animation-name: qrgLoginInFromLeft;
            }
            @keyframes qrgLoginIn {
                from { opacity: 0; transform: translateX(28px) scale(.98); }
                to { opacity: 1; transform: translateX(0) scale(1); }
            }
            @keyframes qrgLoginInFromLeft {
                from { opacity: 0; transform: translateX(-28px) scale(.98); }
                to { opacity: 1; transform: translateX(0) scale(1); }
            }
            @keyframes qrgLoginOutLeft {
                from { opacity: 1; transform: translateX(0) scale(1); }
                to { opacity: 0; transform: translateX(-28px) scale(.98); }
            }
            @keyframes qrgLoginOutRight {
                from { opacity: 1; transform: translateX(0) scale(1); }
                to { opacity: 0; transform: translateX(28px) scale(.98); }
            }
            .qrg-login-step__title {
                margin: 0 0 6px;
                font-size: 20px; font-weight: 700;
                letter-spacing: -.02em; text-align: center;
            }
            .qrg-login-step__sub {
                margin: 0 0 18px;
                font-size: 14px; color: var(--ios-secondary);
                text-align: center; line-height: 1.4;
            }
            .qrg-login-remember {
                display: flex;
                align-items: center;
                gap: 12px;
                margin: 0 0 14px;
                padding: 14px 16px;
                background: var(--ios-card);
                border: 1px solid var(--ios-sep);
                border-radius: 16px;
                cursor: pointer;
                user-select: none;
                -webkit-tap-highlight-color: transparent;
                perspective: 800px;
                transition: transform .2s ease, box-shadow .2s ease;
            }
            .qrg-login-remember:active {
                transform: scale(.98);
            }
            .qrg-login-remember.is-checked {
                background: linear-gradient(135deg, rgba(0,122,255,.08), rgba(52,199,89,.06));
                border-color: rgba(52,199,89,.3);
                box-shadow: 0 4px 16px rgba(52,199,89,.12);
            }
            .qrg-login-remember input {
                display: none;
            }
            .qrg-remember-toggle {
                position: relative;
                width: 52px;
                height: 32px;
                flex-shrink: 0;
                perspective: 600px;
            }
            .qrg-remember-toggle__track {
                position: absolute;
                inset: 0;
                border-radius: 16px;
                background: #E5E5EA;
                transition: background .3s ease;
                box-shadow:
                    inset 0 2px 4px rgba(0,0,0,.1),
                    inset 0 0 0 1px rgba(0,0,0,.04);
            }
            .qrg-login-remember.is-checked .qrg-remember-toggle__track {
                background: #34C759;
                box-shadow:
                    inset 0 2px 4px rgba(0,0,0,.08),
                    0 0 12px rgba(52,199,89,.3);
            }
            .qrg-remember-toggle__knob {
                position: absolute;
                top: 2px;
                left: 2px;
                width: 28px;
                height: 28px;
                border-radius: 14px;
                background: linear-gradient(180deg, #FFFFFF 0%, #F0F0F0 100%);
                box-shadow:
                    0 3px 8px rgba(0,0,0,.15),
                    0 1px 3px rgba(0,0,0,.1),
                    inset 0 1px 0 rgba(255,255,255,.8);
                transition: transform .3s cubic-bezier(.4,.0,.2,1), box-shadow .3s ease;
                transform-style: preserve-3d;
                transform: translateX(0) translateZ(0) rotateY(0);
            }
            .qrg-login-remember.is-checked .qrg-remember-toggle__knob {
                transform: translateX(20px) translateZ(4px) rotateY(-12deg);
                box-shadow:
                    0 4px 12px rgba(0,0,0,.18),
                    0 1px 4px rgba(0,0,0,.1),
                    inset 0 1px 0 rgba(255,255,255,.9),
                    0 0 8px rgba(52,199,89,.2);
            }
            .qrg-remember-toggle__knob::before {
                content: '';
                position: absolute;
                inset: 3px;
                border-radius: 50%;
                background: radial-gradient(circle at 40% 35%, rgba(255,255,255,.6), transparent 60%);
            }
            .qrg-remember-icon {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%,-50%) scale(0) rotateY(90deg);
                font-size: 13px;
                transition: transform .35s cubic-bezier(.4,.0,.2,1) .05s, opacity .2s;
                opacity: 0;
                transform-style: preserve-3d;
            }
            .qrg-login-remember.is-checked .qrg-remember-icon {
                transform: translate(-50%,-50%) scale(1) rotateY(0);
                opacity: 1;
            }
            .qrg-remember-content {
                flex: 1;
                min-width: 0;
            }
            .qrg-remember-content__title {
                font-size: 14px;
                font-weight: 600;
                color: var(--ios-ink);
                line-height: 1.3;
                transition: color .2s;
            }
            .qrg-login-remember.is-checked .qrg-remember-content__title {
                color: #34C759;
            }
            .qrg-login-remember span {
                font-size: 13px;
                color: var(--ios-ink);
                line-height: 1.35;
                text-align: left;
            }
            .qrg-login-remember small {
                display: block;
                font-size: 11px;
                color: var(--ios-secondary);
                margin-top: 2px;
                font-weight: 400;
            }
            .qrg-remember-shield {
                width: 36px;
                height: 36px;
                flex-shrink: 0;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                background: rgba(0,122,255,.08);
                transition: background .3s, transform .3s;
                transform-style: preserve-3d;
                transform: rotateY(0);
            }
            .qrg-login-remember.is-checked .qrg-remember-shield {
                background: rgba(52,199,89,.12);
                transform: rotateY(360deg);
            }
            .qrg-login-back {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                margin: 0 0 14px;
                border: 0;
                background: var(--ios-fill);
                padding: 8px 14px 8px 10px;
                border-radius: 980px;
                font-size: 15px;
                color: var(--ios-ink);
                font-weight: 600;
                cursor: pointer;
                min-height: 36px;
                line-height: 1;
                box-shadow: 0 1px 0 rgba(255,255,255,.7) inset;
                -webkit-tap-highlight-color: transparent;
                transition: transform .14s cubic-bezier(.2,.8,.2,1), background .15s, box-shadow .15s;
            }
            .qrg-login-back svg {
                width: 18px;
                height: 18px;
                flex-shrink: 0;
                stroke: var(--ios-blue);
                fill: none;
                stroke-width: 2.2;
                stroke-linecap: round;
                stroke-linejoin: round;
                transition: transform .18s cubic-bezier(.2,.8,.2,1);
            }
            .qrg-login-back span { letter-spacing: -.01em; }
            .qrg-login-back:hover {
                background: #E8E8ED;
            }
            .qrg-login-back:active {
                transform: scale(.96);
                background: #DCDCE2;
            }
            .qrg-login-back:active svg {
                transform: translateX(-2px);
            }
            .qrg-calc { max-width: 320px; width: 100%; margin: 0 auto; }
            .qrg-calc-screen {
                background: #1C1C1E; color: #F2F2F7;
                border-radius: 16px; padding: 18px 16px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 28px; letter-spacing: 6px; text-align: right; min-height: 44px;
                margin-bottom: 14px;
                word-break: break-all; line-height: 1.2;
                transition: box-shadow .25s, transform .2s;
            }
            .qrg-calc-screen.is-pulse {
                animation: qrgPinPulse .45s ease;
            }
            @keyframes qrgPinPulse {
                0%, 100% { transform: scale(1); box-shadow: none; }
                40% { transform: scale(1.02); box-shadow: 0 0 0 3px rgba(0,122,255,.35); }
            }
            .qrg-calc-screen.empty {
                color: #8E8E93; letter-spacing: 1px; font-size: 15px; text-align: center;
                font-family: var(--ios-font);
            }
            .qrg-calc-screen--nis:not(.empty) {
                text-align: center;
                letter-spacing: 2px;
                font-size: 22px;
                font-family: var(--ios-font);
                font-weight: 600;
            }
            .qrg-calc-hint {
                text-align: center; font-size: 13px;
                color: var(--ios-secondary); margin: 0 0 12px;
            }
            .qrg-calc-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .qrg-calc-pad button {
                appearance: none; border: none; border-radius: 50%;
                aspect-ratio: 1; width: 100%; max-width: 84px; margin: 0 auto;
                min-height: 0; font-size: 24px; font-weight: 400; cursor: pointer;
                background: var(--ios-fill); color: var(--ios-ink);
                box-shadow: none;
                user-select: none; -webkit-tap-highlight-color: transparent;
                touch-action: manipulation;
                transition: transform .1s, background .12s;
            }
            .qrg-calc-pad button:active { background: #D1D1D6; transform: scale(.92); }
            .qrg-calc-pad button.key-fn {
                background: #D1D1D6; color: var(--ios-ink); font-size: 15px; font-weight: 600;
            }
            .qrg-calc-pad button.key-ok {
                background: var(--ios-blue); color: #fff;
                border-radius: 18px; aspect-ratio: auto; max-width: none;
                grid-column: 1 / -1; font-size: 17px; font-weight: 600;
                min-height: 52px;
            }
            .qrg-calc-pad button.key-ok:disabled { opacity: .4; cursor: not-allowed; }
            .qrg-calc-pad.is-enter button {
                animation: qrgKeyIn .45s cubic-bezier(.2,.8,.2,1) both;
            }
            .qrg-calc-pad.is-enter button:nth-child(1) { animation-delay: .02s; }
            .qrg-calc-pad.is-enter button:nth-child(2) { animation-delay: .05s; }
            .qrg-calc-pad.is-enter button:nth-child(3) { animation-delay: .08s; }
            .qrg-calc-pad.is-enter button:nth-child(4) { animation-delay: .11s; }
            .qrg-calc-pad.is-enter button:nth-child(5) { animation-delay: .14s; }
            .qrg-calc-pad.is-enter button:nth-child(6) { animation-delay: .17s; }
            .qrg-calc-pad.is-enter button:nth-child(7) { animation-delay: .2s; }
            .qrg-calc-pad.is-enter button:nth-child(8) { animation-delay: .23s; }
            .qrg-calc-pad.is-enter button:nth-child(9) { animation-delay: .26s; }
            .qrg-calc-pad.is-enter button:nth-child(10) { animation-delay: .29s; }
            .qrg-calc-pad.is-enter button:nth-child(11) { animation-delay: .32s; }
            .qrg-calc-pad.is-enter button:nth-child(12) { animation-delay: .35s; }
            .qrg-calc-pad.is-enter button:nth-child(13) { animation-delay: .38s; }
            @keyframes qrgKeyIn {
                from { opacity: 0; transform: translateY(12px) scale(.9); }
                to { opacity: 1; transform: none; }
            }
            @media (prefers-reduced-motion: reduce) {
                .qrg-login-step.is-active,
                .qrg-login-step.is-leave-left,
                .qrg-login-step.is-leave-right,
                .qrg-calc-pad.is-enter button,
                .qrg-calc-screen.is-pulse { animation: none !important; opacity: 1; transform: none; }
            }
            .qrg-back {
                display: inline-flex; align-items: center; gap: 2px;
                margin: 0 0 12px -4px;
                font-size: 17px; color: var(--ios-blue);
                text-decoration: none; font-weight: 400;
                min-height: 40px; padding: 4px 8px 4px 0;
                -webkit-tap-highlight-color: transparent;
            }

            /* Tagihan */
            .qrg-tagihan-wrap { margin-top: 8px; }
            .qrg-sk table.qrg-tagihan-table {
                width: 100%; border-collapse: separate; border-spacing: 0;
                font-size: 14px; margin-top: 4px;
                background: var(--ios-card); border-radius: var(--ios-radius);
                overflow: hidden; box-shadow: var(--ios-shadow);
            }
            .qrg-sk table.qrg-tagihan-table th {
                background: transparent; color: var(--ios-secondary);
                text-align: left; padding: 12px 14px;
                font-size: 12px; font-weight: 500; text-transform: uppercase;
                border-bottom: 1px solid var(--ios-sep);
            }
            .qrg-sk table.qrg-tagihan-table td {
                padding: 14px; border-bottom: 1px solid var(--ios-sep);
                vertical-align: middle; background: var(--ios-card);
            }
            .qrg-sk table.qrg-tagihan-table tr:last-child td { border-bottom: 0; }
            .qrg-sk .total { color: var(--ios-red); font-weight: 700; }

            /* Jadwal iOS */
            .qrg-sk-jadwal { margin-top: 8px; padding-top: 0; border-top: 0; }
            .qrg-sk-jadwal h2 {
                margin: 0 0 8px 4px;
                font-size: 13px; font-weight: 400;
                color: var(--ios-secondary);
                text-transform: uppercase; letter-spacing: .02em;
            }
            .qrg-sk-jadwal .hint {
                margin: -2px 0 12px 4px;
                font-size: 13px; color: var(--ios-secondary); line-height: 1.4;
                text-transform: none; letter-spacing: 0;
            }
            .qrg-jadwal-slider {
                margin-top: 4px;
            }
            .qrg-jadwal-tabs {
                display: flex;
                gap: 8px;
                overflow-x: auto;
                padding: 2px 2px 12px;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
            }
            .qrg-jadwal-tabs::-webkit-scrollbar { display: none; }
            .qrg-jadwal-tab {
                appearance: none; border: 0;
                flex: 0 0 auto;
                padding: 8px 14px;
                border-radius: 980px;
                background: var(--ios-fill);
                color: var(--ios-ink);
                font-size: 13px; font-weight: 600;
                cursor: pointer;
                min-height: 36px;
                white-space: nowrap;
                -webkit-tap-highlight-color: transparent;
                transition: background .15s, color .15s, transform .12s;
            }
            .qrg-jadwal-tab.is-on {
                background: var(--ios-blue);
                color: #fff;
            }
            .qrg-jadwal-tab.is-today:not(.is-on) {
                box-shadow: inset 0 0 0 1.5px rgba(0,122,255,.45);
                color: var(--ios-blue);
            }
            .qrg-jadwal-tab:active { transform: scale(.96); }
            .qrg-jadwal-viewport {
                overflow: hidden;
                border-radius: var(--ios-radius);
                touch-action: pan-y;
                background: var(--ios-card);
                box-shadow: var(--ios-shadow);
            }
            .qrg-jadwal-track {
                display: flex;
                transition: transform .4s cubic-bezier(.2,.8,.2,1);
                will-change: transform;
            }
            .qrg-jadwal-slide {
                flex: 0 0 100%;
                min-width: 100%;
                box-sizing: border-box;
            }
            .qrg-sk-jadwal-day {
                margin-bottom: 0;
                background: transparent;
                border: 0;
                border-radius: 0;
                overflow: hidden;
                box-shadow: none;
            }
            .qrg-sk-jadwal-day__title {
                font-size: 15px; font-weight: 600; color: var(--ios-ink);
                margin: 0; padding: 14px 14px 10px;
                background: transparent;
                border-bottom: 1px solid var(--ios-sep);
            }
            .qrg-sk-jadwal-day__title.is-today {
                background: rgba(0,122,255,.08);
                color: var(--ios-blue);
                border-bottom-color: rgba(0,122,255,.12);
            }
            .qrg-sk-jadwal-item {
                display: grid;
                grid-template-columns: 70px 1fr;
                gap: 8px 12px;
                padding: 14px;
                border-top: 1px solid var(--ios-sep);
                font-size: 14px;
                background: var(--ios-card);
            }
            a.qrg-sk-jadwal-item.is-link {
                text-decoration: none; color: inherit;
                -webkit-tap-highlight-color: transparent;
                transition: background .15s;
            }
            a.qrg-sk-jadwal-item.is-link:active { background: #F2F2F7; }
            .qrg-sk-jadwal-item:first-of-type { border-top: 0; }
            .qrg-sk-jadwal-item .jam {
                font-weight: 600; color: var(--ios-blue);
                font-variant-numeric: tabular-nums;
                font-size: 13px; line-height: 1.35;
            }
            .qrg-sk-jadwal-item .mapel {
                font-weight: 600; color: var(--ios-ink); font-size: 15px;
            }
            .qrg-sk-jadwal-item .meta {
                font-size: 13px; color: var(--ios-secondary); margin-top: 3px;
            }
            .qrg-sk-jadwal-mapel-link {
                display: block; text-decoration: none; color: inherit;
                -webkit-tap-highlight-color: transparent;
            }
            .qrg-sk-jadwal-mapel-link:active .mapel { color: #0086F0; }
            .qrg-sk-jadwal-chips {
                display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;
            }
            .qrg-sk-jadwal-chip {
                display: inline-flex; align-items: center; padding: 4px 10px;
                border-radius: 999px; font-size: 11px; font-weight: 700;
            }
            .qrg-sk-jadwal-chip.is-materi {
                background: rgba(0,134,240,.12); color: #0086F0;
            }
            .qrg-sk-jadwal-chip.is-quiz {
                background: rgba(255,149,0,.14); color: #C93400;
            }
            .qrg-sk-jadwal-go {
                margin-top: 6px; font-size: 12px; font-weight: 700; color: #0086F0;
            }
            .qrg-sk-jadwal-tugas {
                margin-top: 10px; display: flex; flex-direction: column; gap: 8px;
            }
            .qrg-sk-jadwal-tugas a {
                display: flex; align-items: center; justify-content: center;
                font-size: 14px; font-weight: 600; text-decoration: none; text-align: center;
                padding: 11px 14px; border-radius: 12px; min-height: 44px;
                background: rgba(52,199,89,.12); color: #248A3D; border: 0;
                -webkit-tap-highlight-color: transparent;
            }
            .qrg-sk-jadwal-tugas a:active { opacity: .8; }
            .qrg-sk-jadwal-tugas a.is-done {
                background: rgba(0,122,255,.1); color: var(--ios-blue);
            }
            .qrg-sk-jadwal-tugas a.is-graded {
                background: rgba(52,199,89,.14); color: #1B7A31;
            }
            .qrg-sk-jadwal-empty {
                color: var(--ios-secondary); font-size: 14px;
                padding: 18px; text-align: center;
                background: var(--ios-card); border-radius: var(--ios-radius);
            }
            .qrg-jadwal-dots {
                display: flex; justify-content: center; gap: 5px;
                margin-top: 12px;
            }
            .qrg-jadwal-dots span {
                width: 6px; height: 6px; border-radius: 50%;
                background: #D1D1D6;
                transition: width .25s, background .25s;
            }
            .qrg-jadwal-dots span.is-on {
                width: 16px; border-radius: 980px;
                background: var(--ios-blue);
            }
            @media (prefers-reduced-motion: reduce) {
                .qrg-jadwal-track { transition: none; }
            }

            /* Pembelajaran / daftar tugas */
            .qrg-sk-ajar .qrg-ajar-back {
                display: inline-flex; align-items: center; gap: 4px;
                margin: 0 0 14px; padding: 8px 14px 8px 10px;
                border-radius: 980px; background: var(--ios-fill);
                color: var(--ios-blue); text-decoration: none;
                font-size: 14px; font-weight: 600; min-height: 36px;
                -webkit-tap-highlight-color: transparent;
            }
            .qrg-sk-ajar .qrg-ajar-back:active { opacity: .75; }
            .qrg-sk-ajar .qrg-ajar-list {
                background: var(--ios-card);
                border-radius: var(--ios-radius);
                overflow: hidden;
                box-shadow: var(--ios-shadow);
            }
            .qrg-sk-ajar .qrg-ajar-item {
                display: flex; align-items: center; gap: 12px;
                padding: 14px; text-decoration: none; color: inherit;
                border-bottom: 1px solid var(--ios-sep);
                -webkit-tap-highlight-color: transparent;
                transition: background .12s;
            }
            .qrg-sk-ajar .qrg-ajar-item:last-child { border-bottom: 0; }
            .qrg-sk-ajar .qrg-ajar-item:active { background: #E5E5EA; }
            .qrg-sk-ajar .qrg-ajar-item__body { flex: 1; min-width: 0; }
            .qrg-sk-ajar .qrg-ajar-item__title {
                display: block; font-size: 16px; font-weight: 600;
                color: var(--ios-ink); line-height: 1.25;
                margin: 0 0 4px;
            }
            .qrg-sk-ajar .qrg-ajar-item__meta {
                display: block; font-size: 13px; color: var(--ios-secondary);
                line-height: 1.35; margin: 0 0 8px;
            }
            .qrg-sk-ajar .qrg-ajar-item__chev {
                width: 8px; height: 8px; flex-shrink: 0;
                border-right: 2px solid #C7C7CC; border-top: 2px solid #C7C7CC;
                transform: rotate(45deg); margin-right: 2px;
            }
            .qrg-sk-ajar .qrg-ajar-badge {
                display: inline-flex; align-items: center;
                padding: 3px 10px; border-radius: 980px;
                font-size: 12px; font-weight: 600; line-height: 1.3;
            }
            .qrg-sk-ajar .qrg-ajar-badge--wait {
                background: rgba(255,149,0,.14); color: #C93400;
            }
            .qrg-sk-ajar .qrg-ajar-badge--sent {
                background: rgba(0,122,255,.12); color: var(--ios-blue);
            }
            .qrg-sk-ajar .qrg-ajar-badge--ok {
                background: rgba(52,199,89,.14); color: #1B7A31;
            }
            .qrg-sk-ajar .qrg-ajar-badge--type {
                background: var(--ios-fill); color: var(--ios-ink);
            }
            .qrg-sk-ajar .qrg-ajar-badge--late {
                background: rgba(255,59,48,.12); color: var(--ios-red);
            }
            .qrg-sk-ajar .qrg-ajar-empty {
                text-align: center; padding: 28px 18px;
                color: var(--ios-secondary); font-size: 15px;
                background: var(--ios-card); border-radius: var(--ios-radius);
                box-shadow: var(--ios-shadow);
            }
            .qrg-sk-ajar .qrg-ajar-card {
                background: var(--ios-card);
                border-radius: var(--ios-radius);
                padding: 16px;
                box-shadow: var(--ios-shadow);
                margin-bottom: 12px;
            }
            .qrg-sk-ajar .qrg-ajar-card h3 {
                margin: 0 0 8px; font-size: 20px; font-weight: 700;
                letter-spacing: -.02em; line-height: 1.25;
            }
            .qrg-sk-ajar .qrg-ajar-card h4 {
                margin: 18px 0 10px; font-size: 15px; font-weight: 600;
            }
            .qrg-sk-ajar .qrg-ajar-meta-row {
                display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
                margin: 0 0 12px; font-size: 13px; color: var(--ios-secondary);
            }
            .qrg-sk-ajar .qrg-ajar-body {
                font-size: 15px; line-height: 1.55; color: var(--ios-ink);
                margin: 0 0 14px;
            }
            .qrg-sk-ajar .qrg-ajar-body p:first-child { margin-top: 0; }
            .qrg-sk-ajar .qrg-ajar-body p:last-child { margin-bottom: 0; }
            .qrg-sk-ajar .qrg-ajar-attach {
                background: var(--ios-bg); border-radius: 12px;
                padding: 12px 14px; margin-bottom: 14px;
            }
            .qrg-sk-ajar .qrg-ajar-attach strong {
                display: block; font-size: 13px; font-weight: 600;
                margin-bottom: 8px; color: var(--ios-secondary);
            }
            .qrg-sk-ajar .qrg-ajar-attach ul {
                margin: 0; padding: 0; list-style: none;
            }
            .qrg-sk-ajar .qrg-ajar-attach li { margin: 0 0 6px; }
            .qrg-sk-ajar .qrg-ajar-attach li:last-child { margin: 0; }
            .qrg-sk-ajar .qrg-ajar-attach a {
                color: var(--ios-blue); text-decoration: none;
                font-size: 14px; font-weight: 500;
            }
            .qrg-sk-ajar .qrg-ajar-soal {
                background: var(--ios-bg); border-radius: 12px;
                padding: 14px; margin-bottom: 10px;
            }
            .qrg-sk-ajar .qrg-ajar-soal__q {
                margin: 0 0 10px; font-weight: 600; font-size: 15px;
            }
            .qrg-sk-ajar .qrg-ajar-opt {
                display: flex; align-items: flex-start; gap: 10px;
                padding: 10px 12px; margin-bottom: 6px;
                background: var(--ios-card); border-radius: 10px;
                border: 1.5px solid transparent;
                cursor: pointer; font-size: 14px;
                -webkit-tap-highlight-color: transparent;
            }
            .qrg-sk-ajar .qrg-ajar-opt:has(input:checked) {
                border-color: var(--ios-blue);
                background: rgba(0,122,255,.06);
            }
            .qrg-sk-ajar .qrg-ajar-opt input { margin-top: 2px; accent-color: var(--ios-blue); }
            .qrg-sk-ajar textarea,
            .qrg-sk-ajar input[type=file] {
                width: 100%; box-sizing: border-box;
                font-family: inherit; font-size: 15px;
            }
            .qrg-sk-ajar textarea {
                padding: 12px 14px; border: 0; border-radius: 12px;
                background: var(--ios-bg); color: var(--ios-ink);
                resize: vertical; min-height: 120px;
            }
            .qrg-sk-ajar textarea:focus {
                outline: 2px solid rgba(0,122,255,.35); outline-offset: 0;
            }
            .qrg-sk-ajar .qrg-ajar-label {
                display: block; font-size: 13px; font-weight: 600;
                color: var(--ios-secondary); margin: 0 0 6px;
            }
            .qrg-sk-ajar .qrg-ajar-file {
                margin: 12px 0 0;
            }
            .qrg-sk-ajar .qrg-ajar-file input[type=file] {
                font-size: 14px; color: var(--ios-secondary);
            }
            .qrg-sk-ajar .qrg-ajar-actions {
                margin-top: 16px;
            }
            .qrg-sk-ajar .qrg-ajar-actions .btn {
                width: 100%; border-radius: 14px;
            }
            .qrg-sk-ajar .qrg-ajar-filters {
                display: flex; gap: 8px; overflow-x: auto;
                padding: 0 0 12px; margin: 0;
                scrollbar-width: none;
            }
            .qrg-sk-ajar .qrg-ajar-filters::-webkit-scrollbar { display: none; }
            .qrg-sk-ajar .qrg-ajar-filter {
                appearance: none; border: 0; flex: 0 0 auto;
                padding: 8px 14px; border-radius: 980px;
                background: var(--ios-fill); color: var(--ios-ink);
                font-size: 13px; font-weight: 600; cursor: pointer;
                min-height: 36px; white-space: nowrap;
            }
            .qrg-sk-ajar .qrg-ajar-filter.is-on {
                background: var(--ios-blue); color: #fff;
            }

            @media (max-width: 640px) {
                .qrg-sk .topbar { flex-direction: row; align-items: flex-start; }
                .qrg-sk .topbar .btn { width: auto; }
                .qrg-sk-jadwal-item { grid-template-columns: 1fr; }
                .qrg-sk-jadwal-item .jam {
                    display: inline-flex; width: fit-content;
                    background: rgba(0,122,255,.1); color: var(--ios-blue);
                    padding: 4px 10px; border-radius: 8px; font-size: 12px;
                }
                .qrg-sk table.qrg-tagihan-table thead { display: none; }
                .qrg-sk table.qrg-tagihan-table,
                .qrg-sk table.qrg-tagihan-table tbody,
                .qrg-sk table.qrg-tagihan-table tr,
                .qrg-sk table.qrg-tagihan-table td { display: block; width: 100%; }
                .qrg-sk table.qrg-tagihan-table {
                    background: transparent; box-shadow: none; border-radius: 0;
                }
                .qrg-sk table.qrg-tagihan-table tr {
                    border: 0; border-radius: var(--ios-radius);
                    padding: 14px; margin-bottom: 10px;
                    background: var(--ios-card); box-shadow: var(--ios-shadow);
                }
                .qrg-sk table.qrg-tagihan-table td {
                    border: none; padding: 6px 0;
                    display: flex; justify-content: space-between; align-items: center; gap: 10px;
                }
                .qrg-sk table.qrg-tagihan-table td::before {
                    content: attr(data-label);
                    font-size: 13px; font-weight: 400; color: var(--ios-secondary); flex-shrink: 0;
                }
                .qrg-sk table.qrg-tagihan-table td[data-label=""] {
                    display: block; padding-top: 10px;
                }
                .qrg-sk table.qrg-tagihan-table td[data-label=""]::before { display: none; }
                .qrg-sk table.qrg-tagihan-table td[data-label=""] .btn {
                    width: 100%; display: flex; border-radius: 12px;
                }
                .qrg-sk table.qrg-tagihan-table td.qrg-empty {
                    text-align: center; color: var(--ios-secondary); justify-content: center;
                }
                .qrg-sk table.qrg-tagihan-table td.qrg-empty::before { display: none; }
            }

            @media (min-width: 641px) {
                body {
                    background:
                        radial-gradient(700px 360px at 15% 0%, rgba(0,134,240,.12), transparent 55%),
                        #E8EEF5;
                }
                .qrg-sk-app { padding: 28px 20px; }
                .qrg-sk {
                    max-width: 430px;
                    margin: 0 auto;
                    border-radius: 28px;
                    box-shadow: 0 20px 50px rgba(0,0,0,.1);
                    min-height: calc(100dvh - 56px);
                    overflow: hidden;
                }
                .qrg-sk--dana {
                    overflow: hidden;
                    border-radius: 28px;
                    min-height: calc(100dvh - 56px);
                }
                .qrg-sk.login {
                    margin: 0 auto;
                    min-height: auto;
                    padding: 28px 22px;
                }
                .qrg-sk-menu { max-width: none; }
                .qrg-sk-jadwal-tugas { flex-direction: row; flex-wrap: wrap; }
                .qrg-sk-jadwal-tugas a {
                    display: inline-flex; width: auto; min-height: 34px;
                    padding: 7px 12px; font-size: 13px; border-radius: 980px;
                }
                .qrg-sk-card:hover { background: #F2F2F7; }
            }

            .qrg-sk-loader {
                position: fixed; inset: 0; z-index: 99999;
                display: flex; align-items: center; justify-content: center;
                background: rgba(242,242,247,.88);
                backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
                opacity: 0; visibility: hidden; pointer-events: none;
                transition: opacity .22s ease, visibility .22s ease;
            }
            .qrg-sk-loader.is-on {
                opacity: 1; visibility: visible; pointer-events: auto;
            }
            .qrg-sk-loader__card {
                width: min(260px, calc(100vw - 48px));
                background: rgba(255,255,255,.92);
                border-radius: 20px;
                box-shadow: 0 16px 40px rgba(0,0,0,.12);
                padding: 28px 24px 22px;
                text-align: center;
                transform: translateY(10px) scale(.97);
                transition: transform .28s cubic-bezier(.22, 1, .36, 1);
            }
            .qrg-sk-loader.is-on .qrg-sk-loader__card { transform: translateY(0) scale(1); }
            .qrg-sk-loader__orb {
                width: 48px; height: 48px; margin: 0 auto 14px; position: relative;
            }
            .qrg-sk-loader__orb::before,
            .qrg-sk-loader__orb::after {
                content: ""; position: absolute; inset: 0;
                border-radius: 50%; border: 3px solid transparent;
            }
            .qrg-sk-loader__orb::before {
                border-top-color: var(--ios-blue);
                border-right-color: var(--ios-blue);
                animation: qrgSkSpin .75s linear infinite;
            }
            .qrg-sk-loader__orb::after {
                inset: 8px;
                border-bottom-color: #64D2FF;
                border-left-color: #64D2FF;
                animation: qrgSkSpin .55s linear infinite reverse;
            }
            .qrg-sk-loader__pulse {
                position: absolute; inset: 14px; border-radius: 50%;
                background: var(--ios-blue);
                animation: qrgSkPulse 1.1s ease-in-out infinite;
            }
            .qrg-sk-loader__title {
                margin: 0 0 4px; font-size: 15px; font-weight: 600; color: var(--ios-ink);
            }
            .qrg-sk-loader__sub { margin: 0; font-size: 12px; color: var(--ios-secondary); }
            .qrg-sk-loader__dots {
                display: inline-flex; gap: 5px; margin-top: 12px; justify-content: center;
            }
            .qrg-sk-loader__dots span {
                width: 6px; height: 6px; border-radius: 50%; background: var(--ios-blue);
                animation: qrgSkDot 1s ease-in-out infinite;
            }
            .qrg-sk-loader__dots span:nth-child(2) { animation-delay: .15s; }
            .qrg-sk-loader__dots span:nth-child(3) { animation-delay: .3s; }
            .qrg-sk-loader__bar {
                position: absolute; top: 0; left: 0; height: 3px; width: 0;
                background: linear-gradient(90deg, var(--ios-blue), #64D2FF, var(--ios-blue));
                background-size: 200% 100%;
                animation: qrgSkBar 1.2s ease-in-out infinite;
            }
            @keyframes qrgSkSpin { to { transform: rotate(360deg); } }
            @keyframes qrgSkPulse {
                0%, 100% { transform: scale(.7); opacity: .4; }
                50% { transform: scale(1); opacity: 1; }
            }
            @keyframes qrgSkDot {
                0%, 80%, 100% { transform: translateY(0); opacity: .35; }
                40% { transform: translateY(-5px); opacity: 1; }
            }
            @keyframes qrgSkBar {
                0% { width: 0; left: 0; }
                50% { width: 70%; left: 15%; }
                100% { width: 0; left: 100%; }
            }
            @media (prefers-reduced-motion: reduce) {
                .qrg-sk-loader__orb::before,
                .qrg-sk-loader__orb::after,
                .qrg-sk-loader__pulse,
                .qrg-sk-loader__dots span,
                .qrg-sk-loader__bar { animation: none; }
            }

            /* ===== DANA-style home ===== */
            .qrg-sk--dana {
                padding: 0;
                background: #F5F6F8;
                max-width: 430px;
            }
            .qrg-sk-app.has-tabbar .qrg-sk--dana {
                padding-bottom: calc(var(--ios-tabbar-h) + var(--ios-safe-b));
            }
            .dana-top {
                background: linear-gradient(165deg, #1AA3FF 0%, #0086F0 48%, #0070D6 100%);
                color: #fff;
                padding: calc(14px + var(--ios-safe-t)) 16px 48px;
                position: relative;
            }
            .dana-top__row {
                display: flex; align-items: center; justify-content: space-between;
                gap: 12px; margin-bottom: 18px;
            }
            .dana-top__brand {
                display: flex; align-items: center; gap: 10px; min-width: 0;
            }
            .dana-top__logo {
                width: 36px; height: 36px; border-radius: 50%;
                background: rgba(255,255,255,.2);
                border: 1.5px solid rgba(255,255,255,.55);
                display: inline-flex; align-items: center; justify-content: center;
                font-weight: 800; font-size: 15px; flex-shrink: 0;
                overflow: hidden; padding: 0; text-decoration: none; color: #fff;
            }
            .dana-top__logo.has-photo { background: rgba(255,255,255,.35); }
            .dana-top__logo img {
                width: 100%; height: 100%; object-fit: cover; display: block;
            }
            .dana-top__brand strong {
                display: block; font-size: 15px; font-weight: 700;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .dana-top__brand span {
                display: block; font-size: 12px; opacity: .85; margin-top: 1px;
            }
            .dana-top__bell {
                width: 40px; height: 40px; border-radius: 12px;
                border: 1.5px solid rgba(255,255,255,.45);
                background: transparent; color: #fff;
                display: inline-flex; align-items: center; justify-content: center;
                text-decoration: none; flex-shrink: 0;
            }
            .dana-top__bell svg { width: 20px; height: 20px; }
            .dana-balance {
                margin-bottom: 18px;
            }
            .dana-balance__label {
                margin: 0 0 4px; font-size: 12px; opacity: .9; font-weight: 500;
            }
            .dana-balance__row {
                display: flex; align-items: center; gap: 10px;
            }
            .dana-balance__amount {
                margin: 0; font-size: 1.65rem; font-weight: 800;
                letter-spacing: -.03em; line-height: 1.1;
            }
            .dana-balance__eye {
                appearance: none; border: 0; background: rgba(255,255,255,.18);
                width: 32px; height: 32px; border-radius: 50%;
                color: #fff; cursor: pointer;
                display: inline-flex; align-items: center; justify-content: center;
            }
            .dana-balance__eye svg { width: 18px; height: 18px; }
            .dana-balance__meta {
                margin: 6px 0 0; font-size: 12px; opacity: .88;
            }
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
            .dana-actions__ico svg { width: 22px; height: 22px; stroke: #fff; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
            .dana-sheet {
                margin-top: -28px;
                background: #fff;
                border-radius: 24px 24px 0 0;
                padding: 16px 16px 24px;
                position: relative; z-index: 1;
                min-height: 50vh;
                box-shadow: 0 -8px 24px rgba(0,70,140,.08);
            }
            .dana-banner {
                position: relative;
                margin: 0 0 18px;
                border-radius: 18px;
                overflow: hidden;
                touch-action: pan-y;
            }
            .dana-banner__track {
                display: flex;
                transition: transform .45s cubic-bezier(.2,.8,.2,1);
                will-change: transform;
            }
            .dana-banner__slide {
                flex: 0 0 100%;
                min-width: 100%;
                position: relative;
                display: block;
                text-decoration: none;
                color: #fff;
                min-height: 132px;
                padding: 18px 18px 28px;
                box-sizing: border-box;
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
                    linear-gradient(135deg, #8B5CF6 0%, #6D28D9 100%);
            }
            .dana-banner__kicker {
                display: inline-flex;
                margin: 0 0 8px;
                padding: 3px 8px;
                border-radius: 980px;
                background: rgba(255,255,255,.2);
                font-size: 10px;
                font-weight: 700;
                letter-spacing: .04em;
                text-transform: uppercase;
            }
            .dana-banner__title {
                margin: 0 0 6px;
                font-size: 18px;
                font-weight: 800;
                letter-spacing: -.02em;
                line-height: 1.2;
                max-width: 78%;
            }
            .dana-banner__sub {
                margin: 0;
                font-size: 12px;
                opacity: .92;
                line-height: 1.35;
                max-width: 78%;
            }
            .dana-banner__cta {
                position: absolute;
                right: 16px;
                bottom: 16px;
                display: inline-flex;
                align-items: center;
                min-height: 30px;
                padding: 0 12px;
                border-radius: 980px;
                background: #fff;
                color: #0F172A;
                font-size: 11px;
                font-weight: 700;
            }
            .dana-banner__dots {
                position: absolute;
                left: 0; right: 0; bottom: 8px;
                display: flex; justify-content: center; gap: 5px;
                pointer-events: none;
                z-index: 2;
            }
            .dana-banner__dots span {
                width: 6px; height: 6px; border-radius: 50%;
                background: rgba(255,255,255,.4);
                transition: width .25s, background .25s;
            }
            .dana-banner__dots span.is-on {
                width: 16px; border-radius: 980px;
                background: #fff;
            }
            @media (prefers-reduced-motion: reduce) {
                .dana-banner__track { transition: none; }
            }
            .dana-sheet__label {
                margin: 0 0 12px 4px;
                font-size: 13px;
                font-weight: 600;
                color: #8E8E93;
                text-transform: uppercase;
                letter-spacing: .03em;
            }
            .dana-promo {
                display: flex; align-items: center; gap: 12px;
                padding: 12px;
                border-radius: 16px;
                background: linear-gradient(90deg, #FFF7ED, #FFF1E0);
                text-decoration: none; color: inherit;
                margin-bottom: 20px;
            }
            .dana-promo__ico {
                width: 44px; height: 44px; border-radius: 12px;
                background: #FF8A3D; color: #fff;
                display: flex; align-items: center; justify-content: center;
                font-weight: 800; font-size: 14px; flex-shrink: 0;
            }
            .dana-promo__body { flex: 1; min-width: 0; }
            .dana-promo__body strong { display: block; font-size: 14px; font-weight: 700; color: #1C1C1E; }
            .dana-promo__body span { display: block; font-size: 12px; color: #E67E22; margin-top: 2px; }
            .dana-promo__btn {
                flex-shrink: 0;
                background: #0086F0; color: #fff;
                border: 0; border-radius: 980px;
                padding: 8px 14px; font-size: 12px; font-weight: 700;
                letter-spacing: .02em;
            }
            .dana-grid {
                display: grid; grid-template-columns: repeat(4, 1fr);
                gap: 14px 8px; margin-bottom: 8px;
            }
            .dana-grid--compact {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .dana-grid--compact a {
                flex-direction: row;
                justify-content: flex-start;
                gap: 12px;
                padding: 14px;
                background: #F7F8FA;
                border-radius: 16px;
                text-align: left;
                font-size: 14px;
            }
            .dana-grid--compact .dana-grid__ico {
                width: 44px; height: 44px; border-radius: 14px; flex-shrink: 0;
            }
            .dana-grid a {
                display: flex; flex-direction: column; align-items: center; gap: 8px;
                text-decoration: none; color: #1C1C1E;
                font-size: 11px; font-weight: 600; text-align: center;
                line-height: 1.25; -webkit-tap-highlight-color: transparent;
            }
            .dana-grid a:active { opacity: .8; }
            .dana-grid__ico {
                width: 48px; height: 48px; border-radius: 16px;
                display: flex; align-items: center; justify-content: center;
                color: #fff;
            }
            .dana-grid__ico svg { width: 22px; height: 22px; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
            .dana-grid__ico--pay { background: linear-gradient(145deg, #FF6B6B, #EE5A24); }
            .dana-grid__ico--book { background: linear-gradient(145deg, #54A0FF, #2E86DE); }
            .dana-grid__ico--learn { background: linear-gradient(145deg, #A55EEA, #8854D0); }
            .dana-grid__ico--jadwal { background: linear-gradient(145deg, #1DD1A1, #10AC84); }
            .dana-grid__ico--izin { background: linear-gradient(145deg, #FF9F43, #EE5A24); }
            .dana-grid__ico--saran { background: linear-gradient(145deg, #00CEC9, #0984E3); }
            .dana-grid__ico--aj { background: linear-gradient(145deg, #FD79A8, #E84393); }
            .dana-grid__ico--kes { background: linear-gradient(145deg, #00B894, #00CEC9); }
            .dana-grid__ico--kon { background: linear-gradient(145deg, #A29BFE, #6C5CE7); }
            .dana-grid__ico--akun { background: linear-gradient(145deg, #5F27CD, #341F97); }
            .dana-grid__ico--info { background: linear-gradient(145deg, #F368E0, #FF9FF3); color: #fff; }
            .dana-grid__ico--more { background: #EEF0F4; color: #636E72; }
            .dana-feed__title {
                margin: 0 0 10px;
                font-size: 15px; font-weight: 700; color: #1C1C1E;
            }
            .dana-feed {
                background: #fff;
            }
            .dana-feed .qrg-sk-jadwal { margin-top: 0; }
            .dana-feed .qrg-sk-jadwal h2,
            .dana-feed .qrg-sk-jadwal .hint { display: none; }
            .dana-feed .qrg-sk-jadwal-day {
                box-shadow: none;
                border: 1px solid rgba(60,60,67,.08);
            }

            /* iOS tab bar */
            .ios-tabbar {
                position: fixed;
                left: 50%;
                transform: translateX(-50%);
                bottom: 0;
                width: min(430px, 100%);
                z-index: 10040;
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 0;
                padding: 4px 6px calc(4px + var(--ios-safe-b));
                background: rgba(249,249,249,.92);
                backdrop-filter: saturate(180%) blur(20px);
                -webkit-backdrop-filter: saturate(180%) blur(20px);
                border-top: 0.5px solid rgba(60,60,67,.29);
                box-shadow: 0 -1px 0 rgba(255,255,255,.6) inset;
            }
            .ios-tabbar a {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 1px;
                min-height: var(--ios-tabbar-h);
                padding: 4px 2px;
                text-decoration: none;
                color: var(--ios-secondary);
                font-size: 10px;
                font-weight: 500;
                letter-spacing: -.01em;
                border-radius: 10px;
                -webkit-tap-highlight-color: transparent;
                transition: color .12s, transform .12s;
            }
            .ios-tabbar a:active { transform: scale(.96); }
            .ios-tabbar a.is-active { color: var(--ios-blue); }
            .ios-tabbar a svg {
                width: 26px;
                height: 26px;
                stroke: currentColor;
                fill: none;
                stroke-width: 1.7;
                stroke-linecap: round;
                stroke-linejoin: round;
            }
            .ios-tabbar a.is-active svg { stroke-width: 2; }
            .qrg-sk-app.has-tabbar ~ .qrg-pwa-banner,
            .qrg-sk-app.has-tabbar .qrg-pwa-banner {
                bottom: calc(12px + var(--ios-tabbar-h) + var(--ios-safe-b));
            }
        </style>
    </head>
    <body>
    <div class="qrg-sk-app<?php echo $has_nav ? ' has-tabbar' : ''; ?>">
    <?php
}

function qrg_sekolah_portal_shell_end() {
    $site       = get_bloginfo( 'name' );
    $nav_active = isset( $GLOBALS['qrg_sk_nav_active'] ) ? sanitize_key( (string) $GLOBALS['qrg_sk_nav_active'] ) : '';
    if ( $nav_active !== '' ) {
        qrg_sekolah_portal_render_tabbar( $nav_active );
    }
    ?>
    </div>
    <div class="qrg-sk-loader" id="qrg-sk-loader" aria-hidden="true" role="status">
        <div class="qrg-sk-loader__bar" aria-hidden="true"></div>
        <div class="qrg-sk-loader__card">
            <div class="qrg-sk-loader__orb" aria-hidden="true">
                <span class="qrg-sk-loader__pulse"></span>
            </div>
            <p class="qrg-sk-loader__title" id="qrg-sk-loader-title">Memuat…</p>
            <p class="qrg-sk-loader__sub"><?php echo esc_html( $site ); ?></p>
            <div class="qrg-sk-loader__dots" aria-hidden="true">
                <span></span><span></span><span></span>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var loader = document.getElementById('qrg-sk-loader');
        var titleEl = document.getElementById('qrg-sk-loader-title');
        if (!loader) return;

        var messages = {
            pembayaran: 'Membuka pembayaran…',
            pembelajaran: 'Membuka pembelajaran…',
            jadwal: 'Membuka jadwal…',
            izin: 'Membuka pengajuan izin…',
            saran: 'Membuka kritik & saran…',
            informasi: 'Membuka informasi…',
            antarjemput: 'Membuka antar jemput…',
            kesehatan: 'Membuka kunjungan UKS…',
            konseling: 'Membuka konseling…',
            perpustakaan: 'Membuka Perpustakaan…',
            hub: 'Kembali ke portal…',
            akun: 'Membuka akun…',
            logout: 'Keluar…',
            default: 'Memuat halaman…'
        };

        function guessMessage(href) {
            try {
                var u = new URL(href, window.location.href);
                var path = (u.pathname || '').replace(/\/+$/, '');
                if (u.searchParams.get('qrg_logout')) return messages.logout;
                if (u.searchParams.get('tab') === 'akun') return messages.akun;
                if (/\/sekolah\/bayar\//i.test(path)) return 'Membuka invoice…';
                if (/\/sekolah\/pelajaran\//i.test(path)) return 'Membuka pelajaran…';
                if (/\/sekolah\/jadwal$/i.test(path) && u.searchParams.get('tugas')) return 'Membuka tugas…';
                if (/\/sekolah\/pembelajaran/i.test(path)) return messages.jadwal;
                if (/\/sekolah\/pembayaran$/i.test(path)) return messages.pembayaran;
                if (/\/sekolah\/jadwal$/i.test(path)) return messages.jadwal;
                if (/\/sekolah\/izin$/i.test(path)) return messages.izin;
                if (/\/sekolah\/saran$/i.test(path)) return messages.saran;
                if (/\/sekolah\/informasi$/i.test(path)) return messages.informasi;
                if (/\/sekolah\/antarjemput$/i.test(path)) return messages.antarjemput;
                if (/\/sekolah\/kesehatan$/i.test(path)) return messages.kesehatan;
                if (/\/sekolah\/konseling$/i.test(path)) return messages.konseling;
                if (/\/sekolah\/buku/i.test(path) || /\/perpustakaan/i.test(path)) return messages.perpustakaan;
                if (/\/sekolah$/i.test(path)) return messages.hub;
            } catch (e) {}
            return messages.default;
        }

        function show(msg) {
            if (titleEl) titleEl.textContent = msg || messages.default;
            loader.classList.add('is-on');
            loader.setAttribute('aria-hidden', 'false');
            document.documentElement.style.overflow = 'hidden';
        }

        function hide() {
            loader.classList.remove('is-on');
            loader.setAttribute('aria-hidden', 'true');
            document.documentElement.style.overflow = '';
        }

        function isInternalNav(a) {
            if (!a || a.target === '_blank' || a.hasAttribute('download')) return false;
            var href = a.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0 || href.indexOf('mailto:') === 0) return false;
            try {
                var u = new URL(href, window.location.href);
                if (u.origin !== window.location.origin) return false;
                return /\/sekolah(\/|$)/i.test(u.pathname) || /\/perpustakaan(\/|$)/i.test(u.pathname) || /\/spp\//i.test(u.pathname) || u.pathname.indexOf('/master_') !== -1;
            } catch (e) {
                return false;
            }
        }

        document.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a') : null;
            if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            if (!isInternalNav(a)) return;
            show(guessMessage(a.href));
        }, true);

        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            if (form.getAttribute('target') === '_blank') return;
            show('Memproses…');
        }, true);

        window.addEventListener('pageshow', function (e) {
            hide();
            if (e.persisted) hide();
        });

        window.addEventListener('pagehide', function () {
            /* biarkan loader tetap jika navigasi keluar */
        });

        // Sembunyikan jika halaman sudah siap (back/forward)
        if (document.readyState === 'complete') hide();
        else window.addEventListener('load', hide);
    })();
    </script>
    <?php
    if ( function_exists( 'qrg_sekolah_pwa_boot_script' ) ) {
        qrg_sekolah_pwa_boot_script();
    }

    if ( isset( $GLOBALS['qrg_sk_nav_active'] ) && sanitize_key( (string) $GLOBALS['qrg_sk_nav_active'] ) !== '' ) {
        if ( function_exists( 'qrg_pembayaran_render_idle_script' ) ) {
            qrg_pembayaran_render_idle_script();
        }
    }
    ?>
    </body>
    </html>
    <?php
    unset( $GLOBALS['qrg_sk_nav_active'] );
}

/**
 * Bottom tab bar iOS untuk portal siswa (setelah login).
 *
 * @param string $active beranda|jadwal|bayar|akun
 */
function qrg_sekolah_portal_render_tabbar( $active = 'beranda' ) {
    $active = sanitize_key( (string) $active );
    $items  = [
        'beranda' => [
            'label' => 'Beranda',
            'url'   => qrg_get_sekolah_portal_url(),
            'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10.5L12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5z"/></svg>',
        ],
        'jadwal'  => [
            'label' => 'Jadwal',
            'url'   => qrg_get_sekolah_portal_url( 'jadwal' ),
            'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
        ],
        'bayar'   => [
            'label' => 'Bayar',
            'url'   => qrg_get_sekolah_portal_url( 'pembayaran' ),
            'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg>',
        ],
        'akun'    => [
            'label' => 'Akun',
            'url'   => add_query_arg( 'tab', 'akun', qrg_get_sekolah_portal_url() ),
            'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-3.5 4-5 8-5s6.5 1.5 8 5"/></svg>',
        ],
    ];
    echo '<nav class="ios-tabbar" aria-label="Navigasi portal">';
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

function qrg_render_sekolah_portal_login( $error = '', $goto = '' ) {
    $google_on    = function_exists( 'qrg_google_auth_enabled' ) && qrg_google_auth_enabled();
    $google_cid   = $google_on ? qrg_get_google_client_id() : '';
    $portal_nonce = wp_create_nonce( 'qrg_google_portal_login' );
    $ajax_url     = admin_url( 'admin-ajax.php' );
    $redirect     = qrg_sekolah_goto_url( $goto );
    $site         = get_bloginfo( 'name' );
    $posted_nis   = isset( $_POST['qrg_sekolah_nis'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_sekolah_nis'] ) ) : '';
    $start_step   = ( $error && $posted_nis !== '' ) ? 2 : 1;

    $extra = '';
    if ( $google_on ) {
        $extra = '<script src="https://accounts.google.com/gsi/client" async defer></script>';
    }

    qrg_sekolah_portal_shell_start( 'Login Portal Siswa', $extra );
    ?>
    <div class="qrg-sk login">
        <div class="ios-login-card">
            <p class="brand" style="text-align:center;"><?php echo esc_html( $site ); ?></p>
            <h1 style="text-align:center;">Portal Sekolah</h1>
            <p class="sub" style="text-align:center;margin-bottom:14px;"><?php
                echo $goto === 'perpustakaan' || $goto === 'buku'
                    ? 'Masuk untuk membuka Perpustakaan Digital'
                    : 'Masuk untuk pembayaran, pembelajaran &amp; perpustakaan';
            ?></p>

            <?php if ( $error ) : ?>
                <div class="err" id="qrg-login-err"><?php echo esc_html( $error ); ?></div>
            <?php else : ?>
                <div class="err" id="qrg-login-err" style="display:none;"></div>
            <?php endif; ?>

            <div class="qrg-nis-panel open" id="qrg-nis-panel" data-start-step="<?php echo (int) $start_step; ?>">
                <form method="post" id="qrg-calc-form" autocomplete="off">
                    <?php wp_nonce_field( 'qrg_sekolah_login', 'qrg_sekolah_nonce' ); ?>
                    <input type="hidden" name="qrg_sekolah_login" value="1">
                    <?php if ( $goto ) : ?>
                        <input type="hidden" name="qrg_sekolah_goto" value="<?php echo esc_attr( $goto ); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="qrg_sekolah_password" id="qrg_sekolah_password" value="">

                    <div class="qrg-login-stage" id="qrg-login-stage">
                        <div class="qrg-login-step<?php echo $start_step === 1 ? ' is-active' : ''; ?>" data-step="1" id="qrg-step-nis">
                            <h2 class="qrg-login-step__title">Masukkan NIS</h2>
                            <p class="qrg-login-step__sub">Nomor Induk Siswa yang terdaftar di sekolah.</p>
                            <input type="hidden" name="qrg_sekolah_nis" id="qrg_sekolah_nis" value="<?php echo esc_attr( $posted_nis ); ?>" required autocomplete="username">
                            <div class="qrg-calc">
                                <div class="qrg-calc-screen qrg-calc-screen--nis<?php echo $posted_nis === '' ? ' empty' : ''; ?>" id="qrg-nis-screen" aria-live="polite"><?php echo $posted_nis !== '' ? esc_html( $posted_nis ) : 'NIS'; ?></div>
                                <div class="qrg-calc-pad<?php echo $start_step === 1 ? ' is-enter' : ''; ?>" role="group" aria-label="Keypad NIS" id="qrg-nis-pad">
                                    <button type="button" data-key="1">1</button>
                                    <button type="button" data-key="2">2</button>
                                    <button type="button" data-key="3">3</button>
                                    <button type="button" data-key="4">4</button>
                                    <button type="button" data-key="5">5</button>
                                    <button type="button" data-key="6">6</button>
                                    <button type="button" data-key="7">7</button>
                                    <button type="button" data-key="8">8</button>
                                    <button type="button" data-key="9">9</button>
                                    <button type="button" class="key-fn" data-key="clear">C</button>
                                    <button type="button" data-key="0">0</button>
                                    <button type="button" class="key-fn" data-key="back">⌫</button>
                                    <button type="button" class="key-ok" id="qrg-btn-to-pin">Lanjut</button>
                                </div>
                            </div>
                        </div>

                        <div class="qrg-login-step<?php echo $start_step === 2 ? ' is-active' : ''; ?>" data-step="2" id="qrg-step-pin">
                            <button type="button" class="qrg-login-back" id="qrg-btn-back-nis" aria-label="Kembali ke NIS">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                                <span>Kembali</span>
                            </button>
                            <h2 class="qrg-login-step__title">Masukkan PIN</h2>
                            <p class="qrg-login-step__sub">Jika belum diganti, PIN sama dengan NIS.</p>
                            <label class="qrg-login-remember" for="qrg_sekolah_remember" id="qrg-remember-label-pin">
                                <input type="checkbox" name="qrg_sekolah_remember" id="qrg_sekolah_remember" value="1">
                                <div class="qrg-remember-shield">🔒</div>
                                <div class="qrg-remember-content">
                                    <div class="qrg-remember-content__title">Ingat saya</div>
                                    <small>Tetap masuk meski lama tidak dipakai</small>
                                </div>
                                <div class="qrg-remember-toggle">
                                    <div class="qrg-remember-toggle__track"></div>
                                    <div class="qrg-remember-toggle__knob">
                                        <span class="qrg-remember-icon">✓</span>
                                    </div>
                                </div>
                            </label>
                            <div class="qrg-calc">
                                <div class="qrg-calc-screen empty" id="qrg-calc-screen" aria-live="polite">PIN</div>
                                <div class="qrg-calc-pad<?php echo $start_step === 2 ? ' is-enter' : ''; ?>" role="group" aria-label="Keypad password" id="qrg-calc-pad">
                                    <button type="button" data-key="1">1</button>
                                    <button type="button" data-key="2">2</button>
                                    <button type="button" data-key="3">3</button>
                                    <button type="button" data-key="4">4</button>
                                    <button type="button" data-key="5">5</button>
                                    <button type="button" data-key="6">6</button>
                                    <button type="button" data-key="7">7</button>
                                    <button type="button" data-key="8">8</button>
                                    <button type="button" data-key="9">9</button>
                                    <button type="button" class="key-fn" data-key="clear">C</button>
                                    <button type="button" data-key="0">0</button>
                                    <button type="button" class="key-fn" data-key="back">⌫</button>
                                    <button type="submit" class="key-ok" id="qrg-calc-ok">Masuk</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ( $google_on ) : ?>
                <div class="qrg-divider">atau</div>
                <label class="qrg-login-remember" for="qrg_google_remember" id="qrg-remember-label-google" style="margin-bottom:14px;">
                    <input type="checkbox" id="qrg_google_remember" value="1">
                    <div class="qrg-remember-shield">🔒</div>
                    <div class="qrg-remember-content">
                        <div class="qrg-remember-content__title">Ingat saya</div>
                        <small>Tetap masuk meski lama tidak dipakai</small>
                    </div>
                    <div class="qrg-remember-toggle">
                        <div class="qrg-remember-toggle__track"></div>
                        <div class="qrg-remember-toggle__knob">
                            <span class="qrg-remember-icon">✓</span>
                        </div>
                    </div>
                </label>
                <div class="qrg-google-wrap">
                    <p style="font-size:13px;color:var(--ios-secondary);margin:0 0 12px;">Masuk dengan akun Google yang terdaftar di Data Siswa.</p>
                    <div id="qrg-google-btn"></div>
                    <div class="qrg-google-status" id="qrg-google-status"></div>
                </div>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            // 3D remember-me toggles
            document.querySelectorAll('.qrg-login-remember').forEach(function(label){
                var cb = label.querySelector('input[type="checkbox"]');
                var shield = label.querySelector('.qrg-remember-shield');
                function sync(){
                    label.classList.toggle('is-checked', cb.checked);
                    if(shield) shield.textContent = cb.checked ? '🔓' : '🔒';
                }
                cb.addEventListener('change', sync);
                sync();
            });

            var panel = document.getElementById('qrg-nis-panel');
            var stepNis = document.getElementById('qrg-step-nis');
            var stepPin = document.getElementById('qrg-step-pin');
            var btnNext = document.getElementById('qrg-btn-to-pin');
            var btnBack = document.getElementById('qrg-btn-back-nis');
            var pinPad = document.getElementById('qrg-calc-pad');
            var nisPad = document.getElementById('qrg-nis-pad');
            var pinInput = document.getElementById('qrg_sekolah_password');
            var nisInput = document.getElementById('qrg_sekolah_nis');
            var pinScreen = document.getElementById('qrg-calc-screen');
            var nisScreen = document.getElementById('qrg-nis-screen');
            var form = document.getElementById('qrg-calc-form');
            var errBox = document.getElementById('qrg-login-err');
            if (!form || !stepNis || !stepPin || !nisInput || !pinInput || !pinScreen || !nisScreen) return;

            var step = parseInt((panel && panel.getAttribute('data-start-step')) || '1', 10) || 1;
            var nisVal = (nisInput.value || '').replace(/\D/g, '');
            var pinVal = '';
            var animating = false;

            function pulse(el) {
                if (!el) return;
                el.classList.remove('is-pulse');
                void el.offsetWidth;
                el.classList.add('is-pulse');
            }

            function renderNis() {
                nisInput.value = nisVal;
                if (!nisVal) {
                    nisScreen.textContent = 'NIS';
                    nisScreen.classList.add('empty');
                } else {
                    nisScreen.textContent = nisVal;
                    nisScreen.classList.remove('empty');
                }
                pulse(nisScreen);
            }

            function renderPin() {
                pinInput.value = pinVal;
                if (!pinVal) {
                    pinScreen.textContent = 'PIN';
                    pinScreen.classList.add('empty');
                } else {
                    pinScreen.textContent = pinVal.replace(/./g, '•');
                    pinScreen.classList.remove('empty');
                }
                pulse(pinScreen);
            }

            function replayPad(pad) {
                if (!pad) return;
                pad.classList.remove('is-enter');
                void pad.offsetWidth;
                pad.classList.add('is-enter');
            }

            function goStep(n, dir) {
                if (n === step) return;
                if (n !== 1 && n !== 2) return;
                if (animating) {
                    if (!(dir === 'back' && n === 1)) return;
                }
                animating = true;
                var from = step === 1 ? stepNis : stepPin;
                var to = n === 1 ? stepNis : stepPin;
                var leave = dir === 'back' ? 'is-leave-right' : 'is-leave-left';
                from.classList.remove('is-active', 'from-left', 'is-leave-left', 'is-leave-right');
                from.classList.add(leave);
                to.classList.remove('is-leave-left', 'is-leave-right', 'from-left');
                to.classList.add('is-active');
                if (dir === 'back') to.classList.add('from-left');
                step = n;
                if (n === 2) {
                    replayPad(pinPad);
                } else {
                    pinVal = '';
                    renderPin();
                    replayPad(nisPad);
                }
                setTimeout(function () {
                    from.classList.remove(leave);
                    animating = false;
                }, 340);
            }

            function pressNis(key) {
                if (step !== 1) return;
                if (key === 'clear') nisVal = '';
                else if (key === 'back') nisVal = nisVal.slice(0, -1);
                else if (/^[0-9]$/.test(key) && nisVal.length < 20) nisVal += key;
                renderNis();
            }

            function pressPin(key) {
                if (step !== 2) return;
                if (key === 'clear') pinVal = '';
                else if (key === 'back') pinVal = pinVal.slice(0, -1);
                else if (/^[0-9]$/.test(key) && pinVal.length < 20) pinVal += key;
                renderPin();
            }

            function validateNis() {
                if (!nisVal) {
                    if (errBox) {
                        errBox.textContent = 'Masukkan NIS terlebih dahulu.';
                        errBox.style.display = 'block';
                        errBox.removeAttribute('data-server');
                    }
                    pulse(nisScreen);
                    return false;
                }
                if (errBox && !errBox.getAttribute('data-server')) {
                    errBox.style.display = 'none';
                    errBox.textContent = '';
                }
                return true;
            }

            if (btnNext) {
                btnNext.addEventListener('click', function () {
                    if (!validateNis()) return;
                    goStep(2, 'next');
                });
            }
            if (btnBack) {
                btnBack.addEventListener('click', function (e) {
                    e.preventDefault();
                    goStep(1, 'back');
                });
            }

            if (nisPad) {
                nisPad.querySelectorAll('button[data-key]').forEach(function (btn) {
                    btn.addEventListener('click', function () { pressNis(btn.getAttribute('data-key')); });
                });
            }
            if (pinPad) {
                pinPad.querySelectorAll('button[data-key]').forEach(function (btn) {
                    btn.addEventListener('click', function () { pressPin(btn.getAttribute('data-key')); });
                });
            }

            document.addEventListener('keydown', function (e) {
                if (e.key >= '0' && e.key <= '9') {
                    e.preventDefault();
                    if (step === 1) pressNis(e.key);
                    else pressPin(e.key);
                } else if (e.key === 'Backspace') {
                    e.preventDefault();
                    if (step === 1) pressNis('back');
                    else pressPin('back');
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    if (step === 1) pressNis('clear');
                    else pressPin('clear');
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (step === 1) {
                        if (validateNis()) goStep(2, 'next');
                    } else if (form.requestSubmit) {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            });

            form.addEventListener('submit', function (e) {
                if (step !== 2) {
                    e.preventDefault();
                    if (validateNis()) goStep(2, 'next');
                    return;
                }
                nisInput.value = nisVal;
                if (!pinInput.value && nisVal) {
                    pinInput.value = nisVal;
                }
            });

            if (errBox && errBox.textContent && errBox.style.display !== 'none') {
                errBox.setAttribute('data-server', '1');
            }
            renderNis();
            renderPin();
            if (step === 1) replayPad(nisPad);
        })();
        </script>

        <?php if ( $google_on ) : ?>
        <script>
        window.qrgHandlePortalGoogle = function (response) {
            var errBox = document.getElementById('qrg-login-err');
            var status = document.getElementById('qrg-google-status');
            if (status) status.textContent = 'Memverifikasi akun Google...';
            if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }

            var body = new FormData();
            body.append('action', 'qrg_google_portal_login');
            body.append('credential', response.credential || '');
            body.append('nonce', '<?php echo esc_js( $portal_nonce ); ?>');
            body.append('redirect', '<?php echo esc_js( $redirect ); ?>');
            var rememberGoogle = document.getElementById('qrg_google_remember');
            if (rememberGoogle && rememberGoogle.checked) {
                body.append('remember', '1');
            }

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

        function qrgInitPortalGoogle() {
            if (!window.google || !google.accounts || !google.accounts.id) {
                setTimeout(qrgInitPortalGoogle, 200);
                return;
            }
            google.accounts.id.initialize({
                client_id: '<?php echo esc_js( $google_cid ); ?>',
                callback: window.qrgHandlePortalGoogle,
                auto_select: false,
                cancel_on_tap_outside: true
            });
            var btn = document.getElementById('qrg-google-btn');
            if (btn) {
                google.accounts.id.renderButton(btn, {
                    theme: 'outline', size: 'large', shape: 'rectangular', text: 'signin_with', width: Math.min(320, (window.innerWidth || 320) - 48)
                });
            }
        }
        qrgInitPortalGoogle();
        </script>
        <?php endif; ?>
        <p style="text-align:center;margin:18px 0 0;font-size:14px;">
            <a href="<?php echo esc_url( function_exists( 'qrg_get_guru_portal_url' ) ? qrg_get_guru_portal_url() : home_url( '/sekolah/guru/' ) ); ?>" style="color:var(--ios-blue);font-weight:600;text-decoration:none;">Portal Guru →</a>
        </p>
    </div>
    <?php
    qrg_sekolah_portal_shell_end();
}

/**
 * URL foto profil siswa (featured image CPT master_siswa).
 *
 * @param int          $siswa_id ID siswa.
 * @param string|array $size     Ukuran gambar WP.
 * @return string
 */
function qrg_get_siswa_avatar_url( $siswa_id, $size = 'thumbnail' ) {
    $siswa_id = (int) $siswa_id;
    if ( $siswa_id <= 0 || get_post_type( $siswa_id ) !== 'master_siswa' ) {
        return '';
    }
    if ( ! has_post_thumbnail( $siswa_id ) ) {
        return '';
    }
    $url = get_the_post_thumbnail_url( $siswa_id, $size );
    return $url ? (string) $url : '';
}

/**
 * Proses unggah / hapus foto profil siswa dari portal.
 *
 * @param int $siswa_id ID siswa yang sedang login.
 * @return array{error:string,success:string}
 */
function qrg_process_siswa_profile_photo( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $out      = [ 'error' => '', 'success' => '' ];

    if ( $siswa_id <= 0 || get_post_type( $siswa_id ) !== 'master_siswa' ) {
        return $out;
    }

    $doing_upload = isset( $_POST['qrg_avatar_upload'] );
    $doing_remove = isset( $_POST['qrg_avatar_remove'] );
    if ( ! $doing_upload && ! $doing_remove ) {
        return $out;
    }

    if ( ! isset( $_POST['qrg_avatar_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_avatar_nonce'] ) ), 'qrg_avatar_' . $siswa_id ) ) {
        $out['error'] = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
        return $out;
    }

    if ( $doing_remove ) {
        $old = (int) get_post_thumbnail_id( $siswa_id );
        delete_post_thumbnail( $siswa_id );
        if ( $old > 0 && (int) wp_get_post_parent_id( $old ) === $siswa_id ) {
            wp_delete_attachment( $old, true );
        }
        $out['success'] = 'Foto profil dihapus.';
        return $out;
    }

    if ( empty( $_FILES['qrg_avatar']['name'] ) ) {
        $out['error'] = 'Pilih foto terlebih dahulu.';
        return $out;
    }

    $file = $_FILES['qrg_avatar'];
    if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
        $out['error'] = 'Unggah gagal. Coba foto lain.';
        return $out;
    }

    $max_bytes = 3 * 1024 * 1024; // 3 MB
    if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
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
        'post_parent'    => $siswa_id,
    ];
    $attach_id = wp_insert_attachment( $attachment, $movefile['file'], $siswa_id );
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

    $old = (int) get_post_thumbnail_id( $siswa_id );
    set_post_thumbnail( $siswa_id, $attach_id );
    if ( $old > 0 && $old !== (int) $attach_id && (int) wp_get_post_parent_id( $old ) === $siswa_id ) {
        wp_delete_attachment( $old, true );
    }

    $out['success'] = 'Foto profil berhasil diperbarui.';
    return $out;
}

/**
 * Ganti PIN/password portal siswa (meta _siswa_portal_pin).
 *
 * @param int $siswa_id ID siswa yang sedang login.
 * @return array{error:string,success:string}
 */
function qrg_process_siswa_change_password( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $out      = [ 'error' => '', 'success' => '' ];

    if ( $siswa_id <= 0 || get_post_type( $siswa_id ) !== 'master_siswa' ) {
        return $out;
    }
    if ( ! isset( $_POST['qrg_change_pin'] ) ) {
        return $out;
    }

    if ( ! isset( $_POST['qrg_pin_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_pin_nonce'] ) ), 'qrg_change_pin_' . $siswa_id ) ) {
        $out['error'] = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
        return $out;
    }

    $current = isset( $_POST['qrg_pin_current'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_pin_current'] ) ) : '';
    $new     = isset( $_POST['qrg_pin_new'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_pin_new'] ) ) : '';
    $confirm = isset( $_POST['qrg_pin_confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_pin_confirm'] ) ) : '';

    // Hanya angka (abaikan huruf/simbol jika dikirim).
    $current = preg_replace( '/\D+/', '', $current );
    $new     = preg_replace( '/\D+/', '', $new );
    $confirm = preg_replace( '/\D+/', '', $confirm );

    if ( $current === '' || $new === '' || $confirm === '' ) {
        $out['error'] = 'Lengkapi semua kolom password.';
        return $out;
    }

    $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'change_pin_' . $siswa_id, 6, 900 ) : true;
    if ( is_wp_error( $rl ) ) {
        $out['error'] = $rl->get_error_message();
        return $out;
    }

    if ( ! function_exists( 'qrg_pembayaran_verify_nis' ) || ! qrg_pembayaran_verify_nis( $siswa_id, $current ) ) {
        if ( function_exists( 'qrg_rate_limit_hit' ) ) {
            qrg_rate_limit_hit( 'change_pin_' . $siswa_id, 900 );
        }
        $out['error'] = 'Password lama salah.';
        return $out;
    }

    if ( ! preg_match( '/^[0-9]{4,20}$/', $new ) ) {
        $out['error'] = 'Password baru harus 4–20 digit angka (sesuai keypad login).';
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

    update_post_meta( $siswa_id, '_siswa_portal_pin', wp_hash_password( $new ) );
    if ( function_exists( 'qrg_rate_limit_clear' ) ) {
        qrg_rate_limit_clear( 'change_pin_' . $siswa_id );
    }

    $out['success'] = 'Password berhasil diganti. Gunakan password baru saat login berikutnya.';
    return $out;
}

function qrg_render_sekolah_portal_hub( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $nama     = get_the_title( $siswa_id );
    $kelas_raw = get_post_meta( $siswa_id, '_siswa_kelas', true );
    $kelas_id  = is_numeric( $kelas_raw ) ? (int) $kelas_raw : 0;
    $kelas     = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( $kelas_raw )
        : '';
    $logout   = add_query_arg( 'qrg_logout', '1', qrg_get_sekolah_portal_url() );
    $site     = get_bloginfo( 'name' );
    $initial  = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }
    $perpus_url = function_exists( 'qrg_get_perpustakaan_url' )
        ? qrg_get_perpustakaan_url()
        : qrg_get_sekolah_portal_url( 'buku' );
    $bayar_url  = qrg_get_sekolah_portal_url( 'pembayaran' );
    $ajar_url   = qrg_get_sekolah_portal_url( 'jadwal' );
    $jadwal_url = $ajar_url;
    $izin_url   = qrg_get_sekolah_portal_url( 'izin' );
    $saran_url  = qrg_get_sekolah_portal_url( 'saran' );
    $info_url   = qrg_get_sekolah_portal_url( 'informasi' );
    $aj_url     = qrg_get_sekolah_portal_url( 'antarjemput' );
    $kes_url    = qrg_get_sekolah_portal_url( 'kesehatan' );
    $kon_url    = qrg_get_sekolah_portal_url( 'konseling' );
    $akun_url   = add_query_arg( 'tab', 'akun', qrg_get_sekolah_portal_url() );
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    $nav = ( $tab === 'akun' ) ? 'akun' : 'beranda';

    $photo_msg = [ 'error' => '', 'success' => '' ];
    $pin_msg   = [ 'error' => '', 'success' => '' ];
    if ( $nav === 'akun' ) {
        if ( function_exists( 'qrg_kts_maybe_handle_request' ) && qrg_kts_maybe_handle_request( $siswa_id ) ) {
            return;
        }
        $photo_msg = qrg_process_siswa_profile_photo( $siswa_id );
        // Setelah unggah/hapus, arahkan ke GET agar refresh tidak mengulang POST.
        if ( $photo_msg['success'] !== '' || $photo_msg['error'] !== '' ) {
            $redir = $akun_url;
            if ( $photo_msg['success'] !== '' ) {
                $flag = isset( $_POST['qrg_avatar_remove'] ) ? 'removed' : 'ok';
                $redir = add_query_arg( 'avatar', $flag, $redir );
            } elseif ( $photo_msg['error'] !== '' ) {
                $redir = add_query_arg( 'avatar_err', rawurlencode( $photo_msg['error'] ), $redir );
            }
            wp_safe_redirect( $redir );
            exit;
        }

        $pin_msg = qrg_process_siswa_change_password( $siswa_id );
        if ( $pin_msg['success'] !== '' || $pin_msg['error'] !== '' ) {
            $redir = $akun_url;
            if ( $pin_msg['success'] !== '' ) {
                $redir = add_query_arg( 'pin', 'ok', $redir );
            } else {
                $redir = add_query_arg( 'pin_err', rawurlencode( $pin_msg['error'] ), $redir );
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
        if ( isset( $_GET['pin'] ) && sanitize_key( wp_unslash( $_GET['pin'] ) ) === 'ok' ) {
            $pin_msg['success'] = 'Password berhasil diganti. Gunakan password baru saat login berikutnya.';
        }
        if ( isset( $_GET['pin_err'] ) ) {
            $pin_msg['error'] = sanitize_text_field( wp_unslash( $_GET['pin_err'] ) );
        }
    }

    $avatar_url = qrg_get_siswa_avatar_url( $siswa_id, 'medium' );
    $avatar_sm  = $avatar_url ? qrg_get_siswa_avatar_url( $siswa_id, 'thumbnail' ) : '';

    $summary = function_exists( 'qrg_get_siswa_tagihan_summary' )
        ? qrg_get_siswa_tagihan_summary( $siswa_id )
        : [ 'belum' => 0, 'ditinjau' => 0, 'count' => 0 ];
    $tagihan_open = (int) ( $summary['belum'] ?? 0 ) + (int) ( $summary['ditinjau'] ?? 0 );
    $tagihan_fmt  = 'Rp ' . number_format( $tagihan_open, 0, ',', '.' );
    $open_count   = 0;
    if ( ! empty( $summary['items'] ) && is_array( $summary['items'] ) ) {
        foreach ( $summary['items'] as $it ) {
            if ( ( $it['status'] ?? '' ) !== 'Lunas' ) {
                $open_count++;
            }
        }
    }

    qrg_sekolah_portal_shell_start( $nav === 'akun' ? 'Akun' : 'Portal Sekolah', '', $nav );
    ?>
    <div class="qrg-sk<?php echo $nav === 'beranda' ? ' qrg-sk--dana' : ''; ?>">
        <?php if ( $nav === 'akun' ) : ?>
            <header class="ios-hero">
                <p class="brand">Akun Siswa</p>
                <h1>Profil</h1>
            </header>
            <?php if ( $photo_msg['error'] ) : ?>
                <div class="err"><?php echo esc_html( $photo_msg['error'] ); ?></div>
            <?php endif; ?>
            <?php if ( $photo_msg['success'] ) : ?>
                <div class="ok"><?php echo esc_html( $photo_msg['success'] ); ?></div>
            <?php endif; ?>
            <?php if ( $pin_msg['error'] ) : ?>
                <div class="err"><?php echo esc_html( $pin_msg['error'] ); ?></div>
            <?php endif; ?>
            <?php if ( $pin_msg['success'] ) : ?>
                <div class="ok"><?php echo esc_html( $pin_msg['success'] ); ?></div>
            <?php endif; ?>
            <section class="ios-section">
                <p class="ios-section__label">Profil</p>
                <div class="ios-list qrg-sk-profile-card">
                    <div class="ios-avatar<?php echo $avatar_url ? ' has-photo' : ''; ?>" aria-hidden="true">
                        <?php if ( $avatar_url ) : ?>
                            <img src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="88" height="88">
                        <?php else : ?>
                            <?php echo esc_html( $initial ); ?>
                        <?php endif; ?>
                    </div>
                    <strong style="display:block;font-size:18px;"><?php echo esc_html( $nama ); ?></strong>
                    <span style="display:block;margin-top:4px;font-size:14px;color:var(--ios-secondary);">
                        <?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : 'Portal siswa'; ?>
                        · <?php echo esc_html( $site ); ?>
                    </span>
                </div>
                <?php
                $avatar_panel_open = ( $photo_msg['error'] !== '' || $photo_msg['success'] !== '' );
                ?>
                <div class="qrg-sk-menu">
                    <button type="button"
                        class="qrg-sk-card qrg-sk-avatar-toggle<?php echo $avatar_panel_open ? ' is-open' : ''; ?>"
                        id="qrg-avatar-toggle"
                        aria-expanded="<?php echo $avatar_panel_open ? 'true' : 'false'; ?>"
                        aria-controls="qrg-avatar-panel">
                        <span class="ios-icon ios-icon--photo" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        </span>
                        <div>
                            <strong>Ubah foto</strong>
                            <span>Ganti foto profil dari galeri atau kamera</span>
                        </div>
                    </button>
                </div>
                <div class="qrg-sk-avatar-panel<?php echo $avatar_panel_open ? ' is-open' : ''; ?>" id="qrg-avatar-panel">
                    <div class="qrg-sk-avatar-panel__inner">
                        <div class="qrg-sk-avatar-card">
                            <form method="post" enctype="multipart/form-data" id="qrg-avatar-form" action="<?php echo esc_url( $akun_url ); ?>">
                                <?php wp_nonce_field( 'qrg_avatar_' . $siswa_id, 'qrg_avatar_nonce' ); ?>
                                <input type="hidden" name="qrg_avatar_upload" value="1">
                                <div class="qrg-sk-avatar-edit">
                                    <div class="ios-avatar<?php echo $avatar_url ? ' has-photo' : ''; ?>" aria-hidden="true">
                                        <?php if ( $avatar_url ) : ?>
                                            <img src="<?php echo esc_url( $avatar_url ); ?>" alt="" width="88" height="88">
                                        <?php else : ?>
                                            <?php echo esc_html( $initial ); ?>
                                        <?php endif; ?>
                                    </div>
                                    <label class="qrg-sk-avatar-edit__cam" for="qrg-avatar-file" title="Ganti foto profil">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                        <span class="screen-reader-text" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Pilih foto</span>
                                    </label>
                                    <input type="file" id="qrg-avatar-file" name="qrg_avatar" accept="image/jpeg,image/png,image/webp,image/gif" capture="user">
                                </div>
                                <div class="qrg-sk-avatar-actions">
                                    <?php if ( $avatar_url ) : ?>
                                        <button type="button" class="btn btn-muted" id="qrg-avatar-remove-btn">Hapus foto</button>
                                        <button type="submit" name="qrg_avatar_remove" value="1" id="qrg-avatar-remove-submit" hidden tabindex="-1" aria-hidden="true">Hapus</button>
                                    <?php endif; ?>
                                </div>
                                <p class="qrg-sk-avatar-status" id="qrg-avatar-status" hidden></p>
                                <div class="qrg-sk-avatar-rules" role="note" aria-label="Syarat upload foto profil">
                                    <p class="qrg-sk-avatar-rules__title">Syarat upload foto</p>
                                    <ul>
                                        <li>Ketuk ikon kamera untuk memilih atau mengambil foto</li>
                                        <li>Format yang diizinkan: <strong>JPG, JPEG, PNG, WebP,</strong> atau <strong>GIF</strong></li>
                                        <li>Ukuran file maksimal <strong>3 MB</strong></li>
                                        <li>Gunakan foto wajah yang jelas (disarankan rasio persegi)</li>
                                        <li>File selain gambar atau melebihi 3 MB akan ditolak</li>
                                    </ul>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
            <?php
            if ( function_exists( 'qrg_kts_render_akun_section' ) ) {
                qrg_kts_render_akun_section( $siswa_id );
            }
            ?>
            <section class="ios-section qrg-sk-security">
                <p class="ios-section__label">Keamanan</p>
                <?php
                $pin_panel_open = ( $pin_msg['error'] !== '' || $pin_msg['success'] !== '' );
                ?>
                <div class="qrg-sk-menu">
                    <button type="button"
                        class="qrg-sk-card qrg-sk-security-toggle<?php echo $pin_panel_open ? ' is-open' : ''; ?>"
                        id="qrg-pin-toggle"
                        aria-expanded="<?php echo $pin_panel_open ? 'true' : 'false'; ?>"
                        aria-controls="qrg-pin-panel">
                        <span class="ios-icon ios-icon--lock" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <div>
                            <strong>Ganti password</strong>
                            <span>Ubah PIN login portal dengan keypad angka</span>
                        </div>
                    </button>
                </div>
                <div class="qrg-sk-security-panel<?php echo $pin_panel_open ? ' is-open' : ''; ?>" id="qrg-pin-panel">
                    <div class="qrg-sk-security-panel__inner">
                <div class="qrg-sk-pin-card">
                    <form method="post" action="<?php echo esc_url( $akun_url ); ?>" id="qrg-pin-form" autocomplete="off">
                        <?php wp_nonce_field( 'qrg_change_pin_' . $siswa_id, 'qrg_pin_nonce' ); ?>
                        <input type="hidden" name="qrg_change_pin" value="1">
                        <input type="hidden" name="qrg_pin_current" id="qrg-pin-current" value="">
                        <input type="hidden" name="qrg_pin_new" id="qrg-pin-new" value="">
                        <input type="hidden" name="qrg_pin_confirm" id="qrg-pin-confirm" value="">

                        <div class="qrg-sk-pin-steps" aria-hidden="true">
                            <span class="qrg-sk-pin-step is-on" data-step="0">1. Lama</span>
                            <span class="qrg-sk-pin-step" data-step="1">2. Baru</span>
                            <span class="qrg-sk-pin-step" data-step="2">3. Ulangi</span>
                        </div>
                        <div class="qrg-sk-pin-nav">
                            <button type="button" id="qrg-pin-back" hidden>← Kembali</button>
                        </div>
                        <p class="qrg-calc-hint" id="qrg-pin-hint">Masukkan password lama (NIS/PIN)</p>
                        <div class="qrg-sk-pin-err" id="qrg-pin-local-err" role="alert"></div>
                        <div class="qrg-sk-pin-body" id="qrg-pin-body">
                        <div class="qrg-calc">
                            <div class="qrg-calc-screen empty" id="qrg-pin-screen" aria-live="polite">Password lama</div>
                            <div class="qrg-calc-pad" role="group" aria-label="Keypad password" id="qrg-pin-pad">
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
                                <button type="button" class="key-ok" id="qrg-pin-next" disabled>Lanjut</button>
                            </div>
                        </div>
                        </div>
                        <p class="qrg-sk-pin-hint">Gunakan keypad angka. Password baru 4–20 digit. Jika belum pernah mengatur PIN, password lama adalah NIS Anda.</p>
                    </form>
                </div>
                    </div>
                </div>
            </section>
            <div style="margin-top:8px;">
                <a class="btn btn-muted btn-block" href="<?php echo esc_url( $logout ); ?>">Keluar</a>
            </div>
            <?php if ( $avatar_url ) : ?>
            <div class="qrg-sk-alert" id="qrg-avatar-alert" role="dialog" aria-modal="true" aria-labelledby="qrg-avatar-alert-title" aria-describedby="qrg-avatar-alert-text" hidden>
                <div class="qrg-sk-alert__card">
                    <div class="qrg-sk-alert__body">
                        <div class="qrg-sk-alert__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                        </div>
                        <h2 class="qrg-sk-alert__title" id="qrg-avatar-alert-title">Hapus foto profil?</h2>
                        <p class="qrg-sk-alert__text" id="qrg-avatar-alert-text">Foto akan dihapus dari akun Anda. Anda bisa mengunggah foto baru kapan saja.</p>
                    </div>
                    <div class="qrg-sk-alert__actions">
                        <button type="button" class="qrg-sk-alert__cancel" id="qrg-avatar-alert-cancel">Batal</button>
                        <button type="button" class="qrg-sk-alert__danger" id="qrg-avatar-alert-confirm">Hapus</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <script>
            (function () {
                var input = document.getElementById('qrg-avatar-file');
                var form = document.getElementById('qrg-avatar-form');
                var status = document.getElementById('qrg-avatar-status');
                var avatarToggle = document.getElementById('qrg-avatar-toggle');
                var avatarPanel = document.getElementById('qrg-avatar-panel');
                var avatarPanelOpen = avatarPanel && avatarPanel.classList.contains('is-open');

                function setAvatarPanelOpen(open) {
                    avatarPanelOpen = !!open;
                    if (avatarPanel) {
                        avatarPanel.classList.toggle('is-open', avatarPanelOpen);
                        if (avatarPanelOpen) {
                            avatarPanel.classList.add('is-opening');
                            setTimeout(function () {
                                avatarPanel.classList.remove('is-opening');
                            }, 520);
                        }
                    }
                    if (avatarToggle) {
                        avatarToggle.classList.toggle('is-open', avatarPanelOpen);
                        avatarToggle.setAttribute('aria-expanded', avatarPanelOpen ? 'true' : 'false');
                    }
                }

                if (avatarToggle && avatarPanel) {
                    avatarToggle.addEventListener('click', function () {
                        setAvatarPanelOpen(!avatarPanelOpen);
                    });
                    if (avatarPanelOpen) {
                        avatarPanel.classList.add('is-opening');
                        setTimeout(function () {
                            avatarPanel.classList.remove('is-opening');
                        }, 520);
                    }
                }

                if (input && form) {
                    input.addEventListener('change', function () {
                        if (!input.files || !input.files.length) return;
                        if (status) {
                            status.hidden = false;
                            status.textContent = 'Mengunggah foto…';
                        }
                        form.submit();
                    });
                }

                var pinForm = document.getElementById('qrg-pin-form');
                if (pinForm) {
                    var pinToggle = document.getElementById('qrg-pin-toggle');
                    var pinPanel = document.getElementById('qrg-pin-panel');
                    var pinBody = document.getElementById('qrg-pin-body');
                    var screen = document.getElementById('qrg-pin-screen');
                    var pad = document.getElementById('qrg-pin-pad');
                    var nextBtn = document.getElementById('qrg-pin-next');
                    var backBtn = document.getElementById('qrg-pin-back');
                    var hint = document.getElementById('qrg-pin-hint');
                    var localErr = document.getElementById('qrg-pin-local-err');
                    var stepEls = pinForm.querySelectorAll('.qrg-sk-pin-step');
                    var fields = [
                        document.getElementById('qrg-pin-current'),
                        document.getElementById('qrg-pin-new'),
                        document.getElementById('qrg-pin-confirm')
                    ];
                    var labels = ['Password lama', 'Password baru', 'Ulangi password'];
                    var hints = [
                        'Masukkan password lama (NIS/PIN)',
                        'Masukkan password baru (4–20 digit)',
                        'Ulangi password baru untuk konfirmasi'
                    ];
                    var values = ['', '', ''];
                    var step = 0;
                    var panelOpen = pinPanel && pinPanel.classList.contains('is-open');

                    function pulseScreen() {
                        if (!screen) return;
                        screen.classList.remove('is-pulse');
                        void screen.offsetWidth;
                        screen.classList.add('is-pulse');
                    }

                    function replayPad() {
                        if (!pad) return;
                        pad.classList.remove('is-enter');
                        void pad.offsetWidth;
                        pad.classList.add('is-enter');
                    }

                    function animateStepChange() {
                        if (!pinBody) return;
                        pinBody.classList.remove('is-switching');
                        void pinBody.offsetWidth;
                        pinBody.classList.add('is-switching');
                        pulseScreen();
                        replayPad();
                        setTimeout(function () {
                            pinBody.classList.remove('is-switching');
                        }, 360);
                    }

                    function setPanelOpen(open) {
                        panelOpen = !!open;
                        if (pinPanel) pinPanel.classList.toggle('is-open', panelOpen);
                        if (pinToggle) {
                            pinToggle.classList.toggle('is-open', panelOpen);
                            pinToggle.setAttribute('aria-expanded', panelOpen ? 'true' : 'false');
                        }
                        if (panelOpen) {
                            setTimeout(function () {
                                replayPad();
                                pulseScreen();
                            }, 120);
                        }
                    }

                    if (pinToggle && pinPanel) {
                        pinToggle.addEventListener('click', function () {
                            setPanelOpen(!panelOpen);
                        });
                    }

                    function showErr(msg) {
                        if (!localErr) return;
                        if (!msg) {
                            localErr.textContent = '';
                            localErr.classList.remove('is-on');
                            return;
                        }
                        localErr.textContent = msg;
                        localErr.classList.add('is-on');
                    }

                    function render() {
                        var val = values[step] || '';
                        if (fields[step]) fields[step].value = val;
                        if (screen) {
                            if (!val) {
                                screen.textContent = labels[step];
                                screen.classList.add('empty');
                            } else {
                                screen.textContent = val.replace(/./g, '•');
                                screen.classList.remove('empty');
                            }
                        }
                        if (hint) hint.textContent = hints[step];
                        if (nextBtn) {
                            if (step === 0) nextBtn.disabled = val.length < 1;
                            else nextBtn.disabled = val.length < 4;
                            nextBtn.textContent = step === 2 ? 'Simpan password' : 'Lanjut';
                        }
                        if (backBtn) backBtn.hidden = step === 0;
                        for (var s = 0; s < stepEls.length; s++) {
                            stepEls[s].classList.toggle('is-on', s === step);
                            stepEls[s].classList.toggle('is-done', s < step);
                        }
                    }

                    function press(key) {
                        if (!panelOpen) return;
                        var val = values[step] || '';
                        if (key === 'clear') val = '';
                        else if (key === 'back') val = val.slice(0, -1);
                        else if (/^[0-9]$/.test(key) && val.length < 20) val += key;
                        values[step] = val;
                        showErr('');
                        render();
                        pulseScreen();
                    }

                    function goNext() {
                        if (!panelOpen) return;
                        var val = values[step] || '';
                        if (step === 0 && val.length < 1) return;
                        if (step > 0 && val.length < 4) {
                            showErr('Minimal 4 digit.');
                            pulseScreen();
                            return;
                        }
                        if (step === 1 && values[0] && val === values[0]) {
                            showErr('Password baru harus berbeda dari password lama.');
                            pulseScreen();
                            return;
                        }
                        if (step === 2) {
                            if (val !== values[1]) {
                                showErr('Konfirmasi password tidak cocok.');
                                values[2] = '';
                                render();
                                pulseScreen();
                                return;
                            }
                            fields[0].value = values[0];
                            fields[1].value = values[1];
                            fields[2].value = values[2];
                            if (nextBtn) {
                                nextBtn.disabled = true;
                                nextBtn.textContent = 'Menyimpan…';
                            }
                            pinForm.submit();
                            return;
                        }
                        step += 1;
                        values[step] = values[step] || '';
                        showErr('');
                        render();
                        animateStepChange();
                    }

                    function goBack() {
                        if (!panelOpen || step <= 0) return;
                        step -= 1;
                        showErr('');
                        render();
                        animateStepChange();
                    }

                    if (pad) {
                        pad.querySelectorAll('button[data-key]').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                press(btn.getAttribute('data-key'));
                            });
                        });
                    }
                    if (nextBtn) nextBtn.addEventListener('click', goNext);
                    if (backBtn) backBtn.addEventListener('click', goBack);

                    document.addEventListener('keydown', function (e) {
                        if (!panelOpen) return;
                        var active = document.activeElement;
                        if (active && active.closest && !active.closest('#qrg-pin-form') && active.tagName === 'INPUT' && active.type === 'file') return;
                        if (e.key >= '0' && e.key <= '9') {
                            e.preventDefault();
                            press(e.key);
                        } else if (e.key === 'Backspace') {
                            e.preventDefault();
                            press('back');
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            press('clear');
                        } else if (e.key === 'Enter') {
                            e.preventDefault();
                            if (nextBtn && !nextBtn.disabled) goNext();
                        }
                    });

                    render();
                    if (panelOpen) replayPad();
                }

                var openBtn = document.getElementById('qrg-avatar-remove-btn');
                var alertEl = document.getElementById('qrg-avatar-alert');
                var cancelBtn = document.getElementById('qrg-avatar-alert-cancel');
                var confirmBtn = document.getElementById('qrg-avatar-alert-confirm');
                var removeSubmit = document.getElementById('qrg-avatar-remove-submit');
                if (!openBtn || !alertEl || !form) return;

                var lastFocus = null;
                function openAlert() {
                    lastFocus = document.activeElement;
                    alertEl.hidden = false;
                    requestAnimationFrame(function () {
                        alertEl.classList.add('is-open');
                    });
                    document.body.style.overflow = 'hidden';
                    if (cancelBtn) cancelBtn.focus();
                }
                function closeAlert() {
                    alertEl.classList.remove('is-open');
                    document.body.style.overflow = '';
                    setTimeout(function () {
                        if (!alertEl.classList.contains('is-open')) alertEl.hidden = true;
                    }, 220);
                    if (lastFocus && lastFocus.focus) lastFocus.focus();
                }

                openBtn.addEventListener('click', openAlert);
                if (cancelBtn) cancelBtn.addEventListener('click', closeAlert);
                alertEl.addEventListener('click', function (e) {
                    if (e.target === alertEl) closeAlert();
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && alertEl.classList.contains('is-open')) {
                        e.preventDefault();
                        closeAlert();
                    }
                });
                if (confirmBtn) {
                    confirmBtn.addEventListener('click', function () {
                        confirmBtn.disabled = true;
                        confirmBtn.textContent = 'Menghapus…';
                        if (status) {
                            status.hidden = false;
                            status.textContent = 'Menghapus foto…';
                        }
                        closeAlert();
                        if (removeSubmit && form.requestSubmit) form.requestSubmit(removeSubmit);
                        else if (removeSubmit) {
                            removeSubmit.hidden = false;
                            removeSubmit.click();
                        } else {
                            form.submit();
                        }
                    });
                }
            })();
            </script>
        <?php else : ?>
            <div class="dana-top">
                <div class="dana-top__row">
                    <div class="dana-top__brand">
                        <a class="dana-top__logo<?php echo $avatar_sm ? ' has-photo' : ''; ?>" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Profil">
                            <?php if ( $avatar_sm ) : ?>
                                <img src="<?php echo esc_url( $avatar_sm ); ?>" alt="" width="36" height="36">
                            <?php else : ?>
                                <?php echo esc_html( $initial ); ?>
                            <?php endif; ?>
                        </a>
                        <div>
                            <strong><?php echo esc_html( $nama ); ?></strong>
                            <span><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : esc_html( $site ); ?></span>
                        </div>
                    </div>
                    <a class="dana-top__bell" href="<?php echo esc_url( $bayar_url ); ?>" aria-label="Tagihan">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    </a>
                </div>

                <div class="dana-balance">
                    <p class="dana-balance__label">Tagihan belum dibayar</p>
                    <div class="dana-balance__row">
                        <p class="dana-balance__amount" id="dana-balance-amount" data-amount="<?php echo esc_attr( $tagihan_fmt ); ?>"><?php echo esc_html( $tagihan_fmt ); ?></p>
                        <button type="button" class="dana-balance__eye" id="dana-balance-toggle" aria-label="Sembunyikan nominal" aria-pressed="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <p class="dana-balance__meta"><?php echo $open_count > 0 ? esc_html( $open_count . ' tagihan aktif' ) : 'Tidak ada tagihan terbuka'; ?> · <?php echo esc_html( $site ); ?></p>
                </div>
            </div>

            <div class="dana-sheet">
                <div class="dana-banner" id="dana-banner" aria-roledescription="carousel" aria-label="Banner portal">
                    <div class="dana-banner__track" id="dana-banner-track">
                        <a class="dana-banner__slide dana-banner__slide--1" href="<?php echo esc_url( $bayar_url ); ?>">
                            <span class="dana-banner__kicker">Pembayaran</span>
                            <p class="dana-banner__title">Bayar tagihan sekolah lebih mudah</p>
                            <p class="dana-banner__sub">Cek SPP &amp; biaya lain, unggah bukti dalam sekali ketuk.</p>
                            <span class="dana-banner__cta">Bayar sekarang</span>
                        </a>
                        <a class="dana-banner__slide dana-banner__slide--2" href="<?php echo esc_url( $jadwal_url ); ?>">
                            <span class="dana-banner__kicker">Jadwal</span>
                            <p class="dana-banner__title">Jadwal pelajaran hari ini</p>
                            <p class="dana-banner__sub">Lihat mapel, jam, dan tugas yang terkait.</p>
                            <span class="dana-banner__cta">Buka jadwal</span>
                        </a>
                        <a class="dana-banner__slide dana-banner__slide--3" href="<?php echo esc_url( $perpus_url ); ?>">
                            <span class="dana-banner__kicker">Perpustakaan</span>
                            <p class="dana-banner__title">Baca e-book kapan saja</p>
                            <p class="dana-banner__sub">Koleksi PDF sekolah siap dibuka di HP.</p>
                            <span class="dana-banner__cta">Jelajahi buku</span>
                        </a>
                    </div>
                    <div class="dana-banner__dots" id="dana-banner-dots" aria-hidden="true">
                        <span class="is-on"></span><span></span><span></span>
                    </div>
                </div>

                <?php if ( $tagihan_open > 0 ) : ?>
                <a class="dana-promo" href="<?php echo esc_url( $bayar_url ); ?>">
                    <div class="dana-promo__ico" aria-hidden="true">Rp</div>
                    <div class="dana-promo__body">
                        <strong>Ada tagihan menunggu</strong>
                        <span><?php echo esc_html( $tagihan_fmt ); ?> belum dibayar</span>
                    </div>
                    <span class="dana-promo__btn">BAYAR</span>
                </a>
                <?php endif; ?>

                <p class="dana-sheet__label">Layanan lain</p>
                <div class="dana-grid dana-grid--compact">
                    <a href="<?php echo esc_url( $izin_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--izin" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg></span>
                        Izin
                    </a>
                    <a href="<?php echo esc_url( $saran_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--saran" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 13h5"/></svg></span>
                        Saran
                    </a>
                    <a href="<?php echo esc_url( $info_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--info" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg></span>
                        Informasi
                    </a>
                    <a href="<?php echo esc_url( $aj_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--aj" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 17h14v-5H5z"/><path d="M5 12V8l3-4h8l3 4v4"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/></svg></span>
                        Antar Jemput
                    </a>
                    <a href="<?php echo esc_url( $kes_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--kes" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></span>
                        Kunjungan UKS
                    </a>
                    <a href="<?php echo esc_url( $kon_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--kon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 10h8M8 14h5"/></svg></span>
                        Konseling
                    </a>
                    <a href="<?php echo esc_url( $perpus_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--book" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>
                        Perpustakaan
                    </a>
                    <a href="<?php echo esc_url( $jadwal_url ); ?>">
                        <span class="dana-grid__ico dana-grid__ico--learn" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span>
                        Jadwal
                    </a>
                </div>
            </div>

            <script>
            (function () {
                var btn = document.getElementById('dana-balance-toggle');
                var el = document.getElementById('dana-balance-amount');
                if (btn && el) {
                    var hidden = false;
                    var real = el.getAttribute('data-amount') || el.textContent;
                    btn.addEventListener('click', function () {
                        hidden = !hidden;
                        el.textContent = hidden ? 'Rp ••••••••' : real;
                        btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
                        btn.setAttribute('aria-label', hidden ? 'Tampilkan nominal' : 'Sembunyikan nominal');
                    });
                }

                var root = document.getElementById('dana-banner');
                var track = document.getElementById('dana-banner-track');
                var dotsWrap = document.getElementById('dana-banner-dots');
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
                    for (var d = 0; d < dots.length; d++) {
                        dots[d].classList.toggle('is-on', d === index);
                    }
                }

                function next() { go(index + 1); }

                function startAuto() {
                    stopAuto();
                    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                    timer = setInterval(next, 4200);
                }
                function stopAuto() {
                    if (timer) { clearInterval(timer); timer = null; }
                }

                root.addEventListener('pointerdown', function (e) {
                    dragging = true;
                    startX = e.clientX;
                    deltaX = 0;
                    stopAuto();
                    track.style.transition = 'none';
                });
                root.addEventListener('pointermove', function (e) {
                    if (!dragging) return;
                    deltaX = e.clientX - startX;
                    var pct = (deltaX / root.offsetWidth) * 100;
                    track.style.transform = 'translateX(' + ((-index * 100) + pct) + '%)';
                });
                function endDrag() {
                    if (!dragging) return;
                    dragging = false;
                    track.style.transition = '';
                    if (Math.abs(deltaX) > 40) go(index + (deltaX < 0 ? 1 : -1));
                    else go(index);
                    startAuto();
                }
                root.addEventListener('pointerup', endDrag);
                root.addEventListener('pointercancel', endDrag);
                root.addEventListener('pointerleave', function () { if (dragging) endDrag(); });

                go(0);
                startAuto();
            })();
            </script>
        <?php endif; ?>
    </div>
    <?php
    qrg_sekolah_portal_shell_end();
}

/**
 * Index tugas siswa per mapel_id (dan label) untuk ditampilkan di jadwal.
 *
 * @param int $siswa_id ID siswa.
 * @return array{by_id:array<int,array>,by_label:array<string,array>}
 */
function qrg_sekolah_portal_tugas_by_mapel( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $by_id    = [];
    $by_label = [];
    if ( $siswa_id <= 0 || ! function_exists( 'qrg_get_tugas_for_siswa' ) ) {
        return [ 'by_id' => $by_id, 'by_label' => $by_label ];
    }

    foreach ( qrg_get_tugas_for_siswa( $siswa_id ) as $tugas ) {
        $tid      = (int) $tugas->ID;
        $mapel_id = (int) get_post_meta( $tid, '_tugas_mapel_id', true );
        $label    = function_exists( 'qrg_tugas_resolve_mapel_label' )
            ? qrg_tugas_resolve_mapel_label( $tid )
            : (string) get_post_meta( $tid, '_tugas_mapel', true );
        $label_key = $label !== '' ? mb_strtolower( trim( $label ) ) : '';

        $status = 'baru';
        if ( function_exists( 'qrg_find_jawaban' ) ) {
            $jid = qrg_find_jawaban( $tid, $siswa_id );
            if ( $jid ) {
                $st = get_post_meta( $jid, '_jawaban_status', true );
                $status = ( $st === 'Dinilai' ) ? 'dinilai' : 'dikirim';
            }
        }

        $url = function_exists( 'qrg_get_sekolah_portal_url' )
            ? qrg_get_sekolah_portal_url( 'jadwal', [ 'tugas' => $tid ] )
            : home_url( '/sekolah/jadwal/?tugas=' . $tid );

        $item = [
            'id'     => $tid,
            'title'  => get_the_title( $tid ),
            'status' => $status,
            'url'    => $url,
            'tipe'   => (string) get_post_meta( $tid, '_tugas_tipe', true ),
        ];
        if ( $item['tipe'] !== 'cbt' ) {
            $item['tipe'] = 'esai';
        }

        if ( $mapel_id > 0 ) {
            $by_id[ $mapel_id ][] = $item;
        }
        if ( $label_key !== '' ) {
            $by_label[ $label_key ][] = $item;
        }
    }

    return [ 'by_id' => $by_id, 'by_label' => $by_label ];
}

/**
 * Ambil daftar tugas yang cocok dengan slot jadwal (mapel).
 *
 * @param array $tugas_index Hasil qrg_sekolah_portal_tugas_by_mapel.
 * @param int   $mapel_id    ID mapel jadwal.
 * @param string $mapel_label Label mapel jadwal.
 * @return array
 */
function qrg_sekolah_portal_match_tugas_for_mapel( $tugas_index, $mapel_id, $mapel_label = '' ) {
    $mapel_id = (int) $mapel_id;
    $found    = [];
    $seen     = [];

    if ( $mapel_id > 0 && ! empty( $tugas_index['by_id'][ $mapel_id ] ) ) {
        foreach ( $tugas_index['by_id'][ $mapel_id ] as $item ) {
            $seen[ $item['id'] ] = true;
            $found[] = $item;
        }
    }

    $key = $mapel_label !== '' ? mb_strtolower( trim( $mapel_label ) ) : '';
    if ( $key !== '' && ! empty( $tugas_index['by_label'][ $key ] ) ) {
        foreach ( $tugas_index['by_label'][ $key ] as $item ) {
            if ( isset( $seen[ $item['id'] ] ) ) {
                continue;
            }
            $seen[ $item['id'] ] = true;
            $found[] = $item;
        }
    }

    return $found;
}

/**
 * Tampilkan jadwal pelajaran kelas siswa di portal.
 *
 * @param int    $kelas_id ID kelas.
 * @param string $kelas_label Label kelas.
 * @param int    $siswa_id ID siswa (untuk mencocokkan tugas).
 */
function qrg_render_sekolah_portal_jadwal( $kelas_id, $kelas_label = '', $siswa_id = 0 ) {
    $kelas_id  = (int) $kelas_id;
    $siswa_id  = (int) $siswa_id;
    $tugas_map = qrg_sekolah_portal_tugas_by_mapel( $siswa_id );
    $uid       = 'qj' . ( function_exists( 'wp_unique_id' ) ? wp_unique_id() : uniqid( '', false ) );
    ?>
    <div class="qrg-sk-jadwal">
        <h2>Jadwal Pelajaran</h2>
        <?php if ( $kelas_label ) : ?>
            <p class="hint">Jadwal kelas <?php echo esc_html( $kelas_label ); ?>. Geser untuk ganti hari.</p>
        <?php else : ?>
            <p class="hint">Geser untuk melihat jadwal hari lain</p>
        <?php endif; ?>

        <?php if ( $kelas_id <= 0 || ! function_exists( 'qrg_get_jadwal_by_kelas' ) ) : ?>
            <p class="qrg-sk-jadwal-empty">Kelas siswa belum diatur, jadwal tidak tersedia.</p>
            <?php
            return;
        endif;

        $by_hari   = qrg_get_jadwal_by_kelas( $kelas_id );
        $hari_opts = function_exists( 'qrg_jadwal_hari_options' ) ? qrg_jadwal_hari_options() : [];
        $days      = [];
        foreach ( $hari_opts as $hari => $label ) {
            $rows = isset( $by_hari[ $hari ] ) ? $by_hari[ $hari ] : [];
            if ( empty( $rows ) ) {
                continue;
            }
            $days[] = [
                'key'   => $hari,
                'label' => $label,
                'rows'  => $rows,
            ];
        }

        if ( empty( $days ) ) :
            ?>
            <p class="qrg-sk-jadwal-empty">Belum ada jadwal pelajaran untuk kelas ini.</p>
            <?php
            return;
        endif;

        $hari_map = [
            'Monday'    => 'Senin',
            'Tuesday'   => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday'  => 'Kamis',
            'Friday'    => 'Jumat',
            'Saturday'  => 'Sabtu',
            'Sunday'    => 'Minggu',
        ];
        $today_en   = wp_date( 'l' );
        $today      = isset( $hari_map[ $today_en ] ) ? $hari_map[ $today_en ] : '';
        $start_idx  = 0;
        foreach ( $days as $i => $day ) {
            if ( $day['key'] === $today ) {
                $start_idx = $i;
                break;
            }
        }
        ?>
        <div class="qrg-jadwal-slider" id="<?php echo esc_attr( $uid ); ?>" data-start="<?php echo (int) $start_idx; ?>">
            <div class="qrg-jadwal-tabs" role="tablist" aria-label="Pilih hari">
                <?php foreach ( $days as $i => $day ) :
                    $is_today = ( $day['key'] === $today );
                    ?>
                    <button type="button"
                        class="qrg-jadwal-tab<?php echo $i === $start_idx ? ' is-on' : ''; ?><?php echo $is_today ? ' is-today' : ''; ?>"
                        data-index="<?php echo (int) $i; ?>"
                        role="tab"
                        aria-selected="<?php echo $i === $start_idx ? 'true' : 'false'; ?>">
                        <?php echo esc_html( $day['label'] ); ?><?php echo $is_today ? ' · Hari ini' : ''; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="qrg-jadwal-viewport">
                <div class="qrg-jadwal-track">
                    <?php foreach ( $days as $i => $day ) :
                        $is_today = ( $day['key'] === $today );
                        ?>
                        <div class="qrg-jadwal-slide" role="tabpanel" aria-hidden="<?php echo $i === $start_idx ? 'false' : 'true'; ?>">
                            <div class="qrg-sk-jadwal-day">
                                <div class="qrg-sk-jadwal-day__title<?php echo $is_today ? ' is-today' : ''; ?>">
                                    <?php echo esc_html( $day['label'] ); ?><?php echo $is_today ? ' · Hari ini' : ''; ?>
                                    <span style="float:right;font-weight:500;font-size:12px;opacity:.75;"><?php echo count( $day['rows'] ); ?> mapel</span>
                                </div>
                                <?php foreach ( $day['rows'] as $row ) :
                                    $jam = trim( ( $row['jam_mulai'] ? $row['jam_mulai'] : '' ) . ( $row['jam_selesai'] ? '–' . $row['jam_selesai'] : '' ) );
                                    $meta_parts = array_filter( [ $row['guru'] ? 'Guru: ' . $row['guru'] : '', $row['ruangan'] ? 'Ruang: ' . $row['ruangan'] : '' ] );
                                    $mapel_id   = isset( $row['mapel_id'] ) ? (int) $row['mapel_id'] : 0;
                                    $jadwal_id  = isset( $row['id'] ) ? (int) $row['id'] : 0;
                                    $tugas_list = qrg_sekolah_portal_match_tugas_for_mapel(
                                        $tugas_map,
                                        $mapel_id,
                                        isset( $row['mapel'] ) ? (string) $row['mapel'] : ''
                                    );
                                    $pelajaran_url = ( $jadwal_id > 0 && function_exists( 'qrg_get_pelajaran_url' ) )
                                        ? qrg_get_pelajaran_url( $jadwal_id )
                                        : '';
                                    $mapel_label = $row['mapel'] ? $row['mapel'] : 'Mata pelajaran';
                                    ?>
                                    <div class="qrg-sk-jadwal-item">
                                        <div class="jam"><?php echo esc_html( $jam ? $jam : '—' ); ?></div>
                                        <div>
                                            <?php if ( $pelajaran_url !== '' ) : ?>
                                                <a class="qrg-sk-jadwal-mapel-link" href="<?php echo esc_url( $pelajaran_url ); ?>">
                                                    <div class="mapel"><?php echo esc_html( $mapel_label ); ?></div>
                                                    <?php if ( $meta_parts ) : ?>
                                                        <div class="meta"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ( empty( $tugas_list ) ) : ?>
                                                        <div class="qrg-sk-jadwal-go">Materi →</div>
                                                    <?php endif; ?>
                                                </a>
                                            <?php else : ?>
                                                <div class="mapel"><?php echo esc_html( $mapel_label ); ?></div>
                                                <?php if ( $meta_parts ) : ?>
                                                    <div class="meta"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $tugas_list ) ) : ?>
                                                <div class="qrg-sk-jadwal-tugas">
                                                    <?php foreach ( $tugas_list as $tugas ) :
                                                        $cls = '';
                                                        $label_tugas = 'Tugas';
                                                        if ( ! empty( $tugas['tipe'] ) && $tugas['tipe'] === 'cbt' ) {
                                                            $label_tugas = 'Quiz';
                                                        }
                                                        if ( $tugas['status'] === 'dinilai' ) {
                                                            $cls = 'is-graded';
                                                            $label_tugas = ( $label_tugas === 'Quiz' ? 'Quiz' : 'Tugas' ) . ' · Dinilai';
                                                        } elseif ( $tugas['status'] === 'dikirim' ) {
                                                            $cls = 'is-done';
                                                            $label_tugas = ( $label_tugas === 'Quiz' ? 'Quiz' : 'Tugas' ) . ' · Dikirim';
                                                        } else {
                                                            $label_tugas = ( $label_tugas === 'Quiz' ? 'Quiz' : 'Tugas' );
                                                        }
                                                        $title_attr = $tugas['title'] ? $tugas['title'] : $label_tugas;
                                                        ?>
                                                        <a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $tugas['url'] ); ?>" title="<?php echo esc_attr( $title_attr ); ?>">
                                                            <?php echo esc_html( $label_tugas ); ?>: <?php echo esc_html( wp_html_excerpt( $title_attr, 28, '…' ) ); ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="qrg-jadwal-dots" aria-hidden="true">
                <?php foreach ( $days as $i => $day ) : ?>
                    <span class="<?php echo $i === $start_idx ? 'is-on' : ''; ?>"></span>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        (function () {
            var root = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
            if (!root || root.getAttribute('data-ready')) return;
            root.setAttribute('data-ready', '1');
            var track = root.querySelector('.qrg-jadwal-track');
            var viewport = root.querySelector('.qrg-jadwal-viewport');
            var tabs = root.querySelectorAll('.qrg-jadwal-tab');
            var slides = root.querySelectorAll('.qrg-jadwal-slide');
            var dots = root.querySelectorAll('.qrg-jadwal-dots span');
            var total = slides.length;
            var index = parseInt(root.getAttribute('data-start') || '0', 10) || 0;
            var startX = 0, deltaX = 0, dragging = false;

            function go(i) {
                index = Math.max(0, Math.min(total - 1, i));
                track.style.transform = 'translateX(' + (-index * 100) + '%)';
                for (var t = 0; t < tabs.length; t++) {
                    var on = t === index;
                    tabs[t].classList.toggle('is-on', on);
                    tabs[t].setAttribute('aria-selected', on ? 'true' : 'false');
                    if (slides[t]) slides[t].setAttribute('aria-hidden', on ? 'false' : 'true');
                    if (dots[t]) dots[t].classList.toggle('is-on', on);
                }
                var activeTab = tabs[index];
                if (activeTab && activeTab.scrollIntoView) {
                    activeTab.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
                }
            }

            for (var n = 0; n < tabs.length; n++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        go(parseInt(btn.getAttribute('data-index'), 10) || 0);
                    });
                })(tabs[n]);
            }

            if (viewport) {
                viewport.addEventListener('pointerdown', function (e) {
                    dragging = true;
                    startX = e.clientX;
                    deltaX = 0;
                    track.style.transition = 'none';
                });
                viewport.addEventListener('pointermove', function (e) {
                    if (!dragging) return;
                    deltaX = e.clientX - startX;
                    var pct = (deltaX / viewport.offsetWidth) * 100;
                    track.style.transform = 'translateX(' + ((-index * 100) + pct) + '%)';
                });
                function endDrag() {
                    if (!dragging) return;
                    dragging = false;
                    track.style.transition = '';
                    if (Math.abs(deltaX) > 45) go(index + (deltaX < 0 ? 1 : -1));
                    else go(index);
                }
                viewport.addEventListener('pointerup', endDrag);
                viewport.addEventListener('pointercancel', endDrag);
                viewport.addEventListener('pointerleave', function () { if (dragging) endDrag(); });
            }

            go(index);
        })();
        </script>
    </div>
    <?php
}

function qrg_render_sekolah_portal_jadwal_page( $siswa_id ) {
    $siswa_id  = (int) $siswa_id;
    $nama      = get_the_title( $siswa_id );
    $kelas_raw = get_post_meta( $siswa_id, '_siswa_kelas', true );
    $kelas_id  = is_numeric( $kelas_raw ) ? (int) $kelas_raw : 0;
    $kelas     = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( $kelas_raw )
        : '';
    $site      = get_bloginfo( 'name' );
    $akun_url  = add_query_arg( 'tab', 'akun', qrg_get_sekolah_portal_url() );
    $hub_url   = qrg_get_sekolah_portal_url();
    $avatar_sm = function_exists( 'qrg_get_siswa_avatar_url' ) ? qrg_get_siswa_avatar_url( $siswa_id, 'thumbnail' ) : '';
    $initial   = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }

    $hari_map = [
        'Monday'    => 'Senin',
        'Tuesday'   => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday'  => 'Kamis',
        'Friday'    => 'Jumat',
        'Saturday'  => 'Sabtu',
        'Sunday'    => 'Minggu',
    ];
    $today_en  = wp_date( 'l' );
    $today     = isset( $hari_map[ $today_en ] ) ? $hari_map[ $today_en ] : '';
    $today_count = 0;
    if ( $kelas_id > 0 && function_exists( 'qrg_get_jadwal_by_kelas' ) ) {
        $by_hari = qrg_get_jadwal_by_kelas( $kelas_id );
        if ( $today && ! empty( $by_hari[ $today ] ) && is_array( $by_hari[ $today ] ) ) {
            $today_count = count( $by_hari[ $today ] );
        }
    }
    $hero_main = $today_count > 0 ? (string) $today_count . ' mapel' : 'Libur / kosong';
    $hero_meta = $today
        ? ( $today_count > 0
            ? ( $today . ' · ' . $today_count . ' jadwal hari ini' )
            : ( $today . ' · tidak ada jadwal' ) )
        : 'Jadwal pelajaran kelas';

    $extra = '<style>
        .qrg-sk--jadwal-page .dana-sheet { padding-top: 18px; }
        .qrg-sk--jadwal-page .qrg-sk-jadwal { margin-top: 0; }
        .qrg-sk--jadwal-page .qrg-sk-jadwal > h2,
        .qrg-sk--jadwal-page .qrg-sk-jadwal > .hint { display: none; }
        .qrg-sk--jadwal-page .qrg-jadwal-tabs {
            margin: 0 -4px 12px;
            padding: 2px 4px 10px;
        }
        .qrg-sk--jadwal-page .qrg-jadwal-tab {
            background: #F2F2F7;
            box-shadow: none;
        }
        .qrg-sk--jadwal-page .qrg-jadwal-tab.is-on {
            background: #0086F0;
            box-shadow: 0 4px 12px rgba(0,134,240,.28);
        }
        .qrg-sk--jadwal-page .qrg-jadwal-viewport {
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(15,23,42,.06);
            border: 1px solid rgba(60,60,67,.06);
        }
        .qrg-sk--jadwal-page .qrg-sk-jadwal-day__title {
            padding: 14px 16px 12px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .02em;
            text-transform: uppercase;
            color: #8E8E93;
            background: #FAFBFC;
        }
        .qrg-sk--jadwal-page .qrg-sk-jadwal-day__title.is-today {
            background: rgba(0,134,240,.08);
            color: #0086F0;
        }
        .qrg-sk--jadwal-page .qrg-sk-jadwal-item {
            grid-template-columns: 72px 1fr;
            padding: 14px 16px;
            gap: 10px 14px;
            position: relative;
        }
        .qrg-sk--jadwal-page .qrg-sk-jadwal-item .jam {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            font-size: 12px;
            font-weight: 800;
            color: #0086F0;
            line-height: 1.35;
            padding-top: 2px;
        }
        .qrg-sk--jadwal-page .qrg-sk-jadwal-item .jam::before {
            content: "";
            width: 8px; height: 8px; border-radius: 50%;
            background: #0086F0;
            margin-bottom: 6px;
            box-shadow: 0 0 0 3px rgba(0,134,240,.16);
        }
        .qrg-sk--jadwal-page .qrg-sk-jadwal-item .mapel {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -.01em;
        }
        .qrg-sk--jadwal-page .qrg-sk-jadwal-empty {
            border: 1px dashed rgba(60,60,67,.18);
            background: #F7F8FA;
            border-radius: 16px;
            padding: 28px 16px;
        }
        .qrg-sk--jadwal-page .dana-sheet__label {
            margin-top: 0;
        }
        .qrg-jadwal-back {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            opacity: .92;
            margin-bottom: 10px;
        }
        .qrg-jadwal-back:active { opacity: .75; }
    </style>';

    qrg_sekolah_portal_shell_start( 'Jadwal Pelajaran', $extra, 'jadwal' );
    ?>
    <div class="qrg-sk qrg-sk--dana qrg-sk--jadwal-page">
        <div class="dana-top">
            <a class="qrg-jadwal-back" href="<?php echo esc_url( $hub_url ); ?>">← Beranda</a>
            <div class="dana-top__row">
                <div class="dana-top__brand">
                    <a class="dana-top__logo<?php echo $avatar_sm ? ' has-photo' : ''; ?>" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Profil">
                        <?php if ( $avatar_sm ) : ?>
                            <img src="<?php echo esc_url( $avatar_sm ); ?>" alt="" width="36" height="36">
                        <?php else : ?>
                            <?php echo esc_html( $initial ); ?>
                        <?php endif; ?>
                    </a>
                    <div>
                        <strong><?php echo esc_html( $nama ); ?></strong>
                        <span><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : esc_html( $site ); ?></span>
                    </div>
                </div>
                <a class="dana-top__bell" href="<?php echo esc_url( $hub_url ); ?>" aria-label="Beranda">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
                </a>
            </div>

            <div class="dana-balance">
                <p class="dana-balance__label">Jadwal hari ini</p>
                <div class="dana-balance__row">
                    <p class="dana-balance__amount"><?php echo esc_html( $hero_main ); ?></p>
                </div>
                <p class="dana-balance__meta"><?php echo esc_html( $hero_meta ); ?> · <?php echo esc_html( $site ); ?></p>
            </div>
        </div>

        <div class="dana-sheet">
            <p class="dana-sheet__label">Jadwal pelajaran<?php echo $kelas ? ' · Kelas ' . esc_html( $kelas ) : ''; ?></p>
            <?php qrg_render_sekolah_portal_jadwal( $kelas_id, $kelas, $siswa_id ); ?>
        </div>
    </div>
    <?php
    qrg_sekolah_portal_shell_end();
}

/**
 * Halaman materi & quiz dari slot jadwal (/sekolah/pelajaran/{id}/).
 *
 * @param int $jadwal_id ID master_jadwal.
 * @param int $siswa_id  ID siswa.
 */
function qrg_render_sekolah_portal_pelajaran( $jadwal_id, $siswa_id ) {
    $jadwal_id = (int) $jadwal_id;
    $siswa_id  = (int) $siswa_id;

    $mapel_id   = (int) get_post_meta( $jadwal_id, '_jadwal_mapel_id', true );
    $guru_id    = (int) get_post_meta( $jadwal_id, '_jadwal_guru_id', true );
    $ruangan_id = (int) get_post_meta( $jadwal_id, '_jadwal_ruangan_id', true );
    $hari       = (string) get_post_meta( $jadwal_id, '_jadwal_hari', true );
    $mulai      = (string) get_post_meta( $jadwal_id, '_jadwal_jam_mulai', true );
    $selesai    = (string) get_post_meta( $jadwal_id, '_jadwal_jam_selesai', true );

    $mapel = $mapel_id && function_exists( 'qrg_get_mapel_label' )
        ? qrg_get_mapel_label( $mapel_id )
        : ( $mapel_id ? get_the_title( $mapel_id ) : 'Mata pelajaran' );
    if ( $mapel === '' ) {
        $mapel = 'Mata pelajaran';
    }
    $guru = $guru_id ? get_the_title( $guru_id ) : '';
    $ruang = $ruangan_id
        ? ( function_exists( 'qrg_get_ruangan_label' ) ? qrg_get_ruangan_label( $ruangan_id ) : get_the_title( $ruangan_id ) )
        : '';
    $hari_opts = function_exists( 'qrg_jadwal_hari_options' ) ? qrg_jadwal_hari_options() : [];
    $hari_label = isset( $hari_opts[ $hari ] ) ? $hari_opts[ $hari ] : $hari;
    $jam = trim( ( $mulai !== '' ? $mulai : '' ) . ( $selesai !== '' ? '–' . $selesai : '' ) );

    $nama      = get_the_title( $siswa_id );
    $kelas     = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $site      = get_bloginfo( 'name' );
    $akun_url  = add_query_arg( 'tab', 'akun', qrg_get_sekolah_portal_url() );
    $hub_url   = qrg_get_sekolah_portal_url();
    $back_url  = qrg_get_sekolah_portal_url( 'jadwal' );
    $avatar_sm = function_exists( 'qrg_get_siswa_avatar_url' ) ? qrg_get_siswa_avatar_url( $siswa_id, 'thumbnail' ) : '';
    $initial   = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }

    $tugas_map  = qrg_sekolah_portal_tugas_by_mapel( $siswa_id );
    $tugas_list = qrg_sekolah_portal_match_tugas_for_mapel( $tugas_map, $mapel_id, $mapel );
    $materi     = [];
    $quiz       = [];
    foreach ( $tugas_list as $item ) {
        if ( ! empty( $item['tipe'] ) && $item['tipe'] === 'cbt' ) {
            $quiz[] = $item;
        } else {
            $materi[] = $item;
        }
    }

    $extra = '<style>
        .qrg-sk--pelajaran-page .dana-sheet { padding-top: 18px; }
        .qrg-sk--pelajaran-page .dana-sheet__label { margin-top: 0; }
        .qrg-pelajaran-back {
            display: inline-flex; align-items: center; gap: 4px;
            color: #fff; text-decoration: none; font-size: 13px; font-weight: 600;
            opacity: .92; margin-bottom: 10px;
        }
        .qrg-pelajaran-back:active { opacity: .75; }
        .qrg-pelajaran-info {
            background: #fff; border-radius: 16px; padding: 14px 16px; margin-bottom: 14px;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .qrg-pelajaran-info__row {
            display: flex; justify-content: space-between; gap: 12px;
            padding: 8px 0; border-top: 1px solid #F2F2F7; font-size: 14px;
        }
        .qrg-pelajaran-info__row:first-child { border-top: 0; padding-top: 0; }
        .qrg-pelajaran-info__row:last-child { padding-bottom: 0; }
        .qrg-pelajaran-info__k { color: #8E8E93; font-weight: 600; }
        .qrg-pelajaran-info__v { font-weight: 700; text-align: right; color: #1C1C1E; }
        .qrg-pelajaran-sec { margin-bottom: 18px; }
        .qrg-pelajaran-sec__head {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            margin: 0 0 10px 2px;
        }
        .qrg-pelajaran-sec__title {
            margin: 0; font-size: 13px; font-weight: 700; color: #8E8E93;
            text-transform: uppercase; letter-spacing: .02em;
        }
        .qrg-pelajaran-sec__count {
            font-size: 12px; font-weight: 700; color: #8E8E93;
            background: #F2F2F7; padding: 3px 8px; border-radius: 999px;
        }
        .qrg-pelajaran-list {
            background: #fff; border-radius: 16px; overflow: hidden;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .qrg-pelajaran-item {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 16px; text-decoration: none; color: inherit;
            border-top: 1px solid #F2F2F7; -webkit-tap-highlight-color: transparent;
        }
        .qrg-pelajaran-item:first-child { border-top: 0; }
        .qrg-pelajaran-item:active { background: #F7F8FA; }
        .qrg-pelajaran-item__ico {
            flex: 0 0 auto; width: 42px; height: 42px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 800;
        }
        .qrg-pelajaran-item__ico.is-materi {
            background: rgba(0,134,240,.12); color: #0086F0;
        }
        .qrg-pelajaran-item__ico.is-quiz {
            background: rgba(255,149,0,.14); color: #C93400;
        }
        .qrg-pelajaran-item__body { flex: 1; min-width: 0; }
        .qrg-pelajaran-item__title {
            margin: 0; font-size: 15px; font-weight: 700; letter-spacing: -.01em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .qrg-pelajaran-item__meta {
            margin: 3px 0 0; font-size: 12px; color: #8E8E93; font-weight: 600;
        }
        .qrg-pelajaran-item__badge {
            flex: 0 0 auto; font-size: 11px; font-weight: 700;
            padding: 4px 8px; border-radius: 999px;
            background: rgba(255,149,0,.14); color: #C93400;
        }
        .qrg-pelajaran-item__badge.is-sent {
            background: rgba(0,122,255,.12); color: #007AFF;
        }
        .qrg-pelajaran-item__badge.is-ok {
            background: rgba(52,199,89,.14); color: #248A3D;
        }
        .qrg-pelajaran-item__chev {
            flex: 0 0 auto; color: #C7C7CC; font-size: 18px; font-weight: 700;
        }
        .qrg-pelajaran-empty {
            text-align: center; padding: 28px 16px; color: #8E8E93; font-size: 14px;
            background: #F7F8FA; border: 1px dashed rgba(60,60,67,.18); border-radius: 16px;
        }
    </style>';

    qrg_sekolah_portal_shell_start( $mapel, $extra, 'jadwal' );
    ?>
    <div class="qrg-sk qrg-sk--dana qrg-sk--pelajaran-page">
        <div class="dana-top">
            <a class="qrg-pelajaran-back" href="<?php echo esc_url( $back_url ); ?>">← Jadwal</a>
            <div class="dana-top__row">
                <div class="dana-top__brand">
                    <a class="dana-top__logo<?php echo $avatar_sm ? ' has-photo' : ''; ?>" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Profil">
                        <?php if ( $avatar_sm ) : ?>
                            <img src="<?php echo esc_url( $avatar_sm ); ?>" alt="" width="36" height="36">
                        <?php else : ?>
                            <?php echo esc_html( $initial ); ?>
                        <?php endif; ?>
                    </a>
                    <div>
                        <strong><?php echo esc_html( $nama ); ?></strong>
                        <span><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : esc_html( $site ); ?></span>
                    </div>
                </div>
                <a class="dana-top__bell" href="<?php echo esc_url( $hub_url ); ?>" aria-label="Beranda">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
                </a>
            </div>
            <div class="dana-balance">
                <p class="dana-balance__label">Pelajaran</p>
                <div class="dana-balance__row">
                    <p class="dana-balance__amount" style="font-size:1.55rem;"><?php echo esc_html( $mapel ); ?></p>
                </div>
                <p class="dana-balance__meta">
                    <?php
                    $meta_bits = array_filter( [
                        $hari_label !== '' ? $hari_label : '',
                        $jam !== '' ? $jam : '',
                        $guru !== '' ? $guru : '',
                    ] );
                    echo esc_html( $meta_bits ? implode( ' · ', $meta_bits ) : $site );
                    ?>
                </p>
            </div>
        </div>

        <div class="dana-sheet">
            <p class="dana-sheet__label">Materi &amp; quiz</p>

            <div class="qrg-pelajaran-info">
                <?php if ( $hari_label !== '' || $jam !== '' ) : ?>
                <div class="qrg-pelajaran-info__row">
                    <span class="qrg-pelajaran-info__k">Jadwal</span>
                    <span class="qrg-pelajaran-info__v"><?php echo esc_html( trim( $hari_label . ( $jam !== '' ? ' · ' . $jam : '' ) ) ); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $guru !== '' ) : ?>
                <div class="qrg-pelajaran-info__row">
                    <span class="qrg-pelajaran-info__k">Guru</span>
                    <span class="qrg-pelajaran-info__v"><?php echo esc_html( $guru ); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $ruang !== '' ) : ?>
                <div class="qrg-pelajaran-info__row">
                    <span class="qrg-pelajaran-info__k">Ruangan</span>
                    <span class="qrg-pelajaran-info__v"><?php echo esc_html( $ruang ); ?></span>
                </div>
                <?php endif; ?>
                <div class="qrg-pelajaran-info__row">
                    <span class="qrg-pelajaran-info__k">Konten</span>
                    <span class="qrg-pelajaran-info__v"><?php echo (int) count( $materi ); ?> materi · <?php echo (int) count( $quiz ); ?> quiz</span>
                </div>
            </div>

            <div class="qrg-pelajaran-sec" id="materi">
                <div class="qrg-pelajaran-sec__head">
                    <h2 class="qrg-pelajaran-sec__title">Materi</h2>
                    <span class="qrg-pelajaran-sec__count"><?php echo (int) count( $materi ); ?></span>
                </div>
                <?php if ( empty( $materi ) ) : ?>
                    <div class="qrg-pelajaran-empty">Belum ada materi untuk mapel ini.</div>
                <?php else : ?>
                    <div class="qrg-pelajaran-list">
                        <?php foreach ( $materi as $item ) :
                            $badge = 'Belum';
                            $bcls  = '';
                            if ( $item['status'] === 'dinilai' ) {
                                $badge = 'Dinilai';
                                $bcls  = 'is-ok';
                            } elseif ( $item['status'] === 'dikirim' ) {
                                $badge = 'Dikirim';
                                $bcls  = 'is-sent';
                            }
                            ?>
                            <a class="qrg-pelajaran-item" href="<?php echo esc_url( $item['url'] ); ?>">
                                <span class="qrg-pelajaran-item__ico is-materi" aria-hidden="true">M</span>
                                <div class="qrg-pelajaran-item__body">
                                    <p class="qrg-pelajaran-item__title"><?php echo esc_html( $item['title'] ? $item['title'] : 'Materi' ); ?></p>
                                    <p class="qrg-pelajaran-item__meta">Tugas / lampiran</p>
                                </div>
                                <span class="qrg-pelajaran-item__badge <?php echo esc_attr( $bcls ); ?>"><?php echo esc_html( $badge ); ?></span>
                                <span class="qrg-pelajaran-item__chev" aria-hidden="true">›</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="qrg-pelajaran-sec" id="quiz">
                <div class="qrg-pelajaran-sec__head">
                    <h2 class="qrg-pelajaran-sec__title">Quiz</h2>
                    <span class="qrg-pelajaran-sec__count"><?php echo (int) count( $quiz ); ?></span>
                </div>
                <?php if ( empty( $quiz ) ) : ?>
                    <div class="qrg-pelajaran-empty">Belum ada quiz untuk mapel ini.</div>
                <?php else : ?>
                    <div class="qrg-pelajaran-list">
                        <?php foreach ( $quiz as $item ) :
                            $badge = 'Belum';
                            $bcls  = '';
                            if ( $item['status'] === 'dinilai' ) {
                                $badge = 'Dinilai';
                                $bcls  = 'is-ok';
                            } elseif ( $item['status'] === 'dikirim' ) {
                                $badge = 'Dikirim';
                                $bcls  = 'is-sent';
                            }
                            ?>
                            <a class="qrg-pelajaran-item" href="<?php echo esc_url( $item['url'] ); ?>">
                                <span class="qrg-pelajaran-item__ico is-quiz" aria-hidden="true">Q</span>
                                <div class="qrg-pelajaran-item__body">
                                    <p class="qrg-pelajaran-item__title"><?php echo esc_html( $item['title'] ? $item['title'] : 'Quiz' ); ?></p>
                                    <p class="qrg-pelajaran-item__meta">CBT pilihan ganda</p>
                                </div>
                                <span class="qrg-pelajaran-item__badge <?php echo esc_attr( $bcls ); ?>"><?php echo esc_html( $badge ); ?></span>
                                <span class="qrg-pelajaran-item__chev" aria-hidden="true">›</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    qrg_sekolah_portal_shell_end();
}

function qrg_render_sekolah_portal_pembayaran( $siswa_id ) {
    $siswa_id  = (int) $siswa_id;
    $nama      = get_the_title( $siswa_id );
    $kelas     = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $site      = get_bloginfo( 'name' );
    $akun_url  = add_query_arg( 'tab', 'akun', qrg_get_sekolah_portal_url() );
    $hub_url   = qrg_get_sekolah_portal_url();
    $avatar_sm = function_exists( 'qrg_get_siswa_avatar_url' ) ? qrg_get_siswa_avatar_url( $siswa_id, 'thumbnail' ) : '';
    $initial   = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }

    $summary = function_exists( 'qrg_get_siswa_tagihan_summary' )
        ? qrg_get_siswa_tagihan_summary( $siswa_id )
        : [ 'belum' => 0, 'ditinjau' => 0, 'lunas' => 0, 'count' => 0, 'items' => [] ];

    $open_amount = (int) ( $summary['belum'] ?? 0 ) + (int) ( $summary['ditinjau'] ?? 0 );
    $open_count  = 0;
    $count_belum = 0;
    $count_tinjau = 0;
    $count_lunas = 0;
    if ( ! empty( $summary['items'] ) && is_array( $summary['items'] ) ) {
        foreach ( $summary['items'] as $it ) {
            $st = $it['status'] ?? '';
            if ( $st === 'Lunas' ) {
                $count_lunas++;
            } elseif ( $st === 'Sedang Ditinjau' ) {
                $count_tinjau++;
                $open_count++;
            } else {
                $count_belum++;
                $open_count++;
            }
        }
    }

    $hero_main = 'Rp ' . number_format( $open_amount, 0, ',', '.' );
    $hero_meta = $open_count > 0
        ? ( $open_count . ' tagihan aktif menunggu pembayaran' )
        : 'Tidak ada tagihan terbuka';

    $extra = '<style>
        .qrg-sk--bayar-page .dana-sheet { padding-top: 18px; }
        .qrg-sk--bayar-page .dana-sheet__label { margin-top: 0; }
        .qrg-sk--bayar-page .qrg-tagihan-wrap { margin-top: 0; }
        .qrg-sk--bayar-page table.qrg-tagihan-table {
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(15,23,42,.06);
            border: 1px solid rgba(60,60,67,.06);
            background: #fff;
        }
        .qrg-sk--bayar-page table.qrg-tagihan-table th {
            background: #FAFBFC;
            color: #8E8E93;
            font-size: 11px;
            letter-spacing: .04em;
        }
        .qrg-sk--bayar-page table.qrg-tagihan-table td {
            background: #fff;
        }
        .qrg-sk--bayar-page table.qrg-tagihan-table .total {
            color: #FF3B30;
            font-weight: 800;
            letter-spacing: -.02em;
        }
        .qrg-sk--bayar-page table.qrg-tagihan-table .btn {
            width: 100%;
            border-radius: 12px;
            min-height: 42px;
            justify-content: center;
        }
        .qrg-sk--bayar-page table.qrg-tagihan-table td.qrg-empty {
            text-align: center;
            color: #8E8E93;
            padding: 28px 16px;
            background: #F7F8FA;
        }
        .qrg-bayar-back {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            opacity: .92;
            margin-bottom: 10px;
        }
        .qrg-bayar-back:active { opacity: .75; }
        .qrg-sk--bayar-page .dana-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 14px;
        }
        .qrg-sk--bayar-page .dana-stats__item {
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 14px;
            padding: 10px 8px;
            text-align: center;
        }
        .qrg-sk--bayar-page .dana-stats__n {
            display: block;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -.02em;
            line-height: 1.1;
        }
        .qrg-sk--bayar-page .dana-stats__l {
            display: block;
            margin-top: 3px;
            font-size: 11px;
            opacity: .88;
            font-weight: 600;
        }
        @media (max-width: 640px) {
            .qrg-sk--bayar-page table.qrg-tagihan-table {
                border: 0;
                box-shadow: none;
                background: transparent;
                border-radius: 0;
            }
            .qrg-sk--bayar-page table.qrg-tagihan-table tr {
                border-radius: 16px;
                box-shadow: 0 8px 24px rgba(15,23,42,.06);
                border: 1px solid rgba(60,60,67,.06);
                margin-bottom: 10px;
                overflow: hidden;
                background: #fff;
            }
            .qrg-sk--bayar-page table.qrg-tagihan-table td {
                padding: 12px 14px;
            }
            .qrg-sk--bayar-page table.qrg-tagihan-table td[data-label="Tagihan"] {
                font-weight: 700;
                font-size: 15px;
                padding-bottom: 6px;
            }
            .qrg-sk--bayar-page table.qrg-tagihan-table td[data-label="Tagihan"]::before {
                display: none;
            }
        }
    </style>';

    qrg_sekolah_portal_shell_start( 'Pembayaran', $extra, 'bayar' );
    ?>
    <div class="qrg-sk qrg-sk--dana qrg-sk--bayar-page">
        <div class="dana-top">
            <a class="qrg-bayar-back" href="<?php echo esc_url( $hub_url ); ?>">← Beranda</a>
            <div class="dana-top__row">
                <div class="dana-top__brand">
                    <a class="dana-top__logo<?php echo $avatar_sm ? ' has-photo' : ''; ?>" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Profil">
                        <?php if ( $avatar_sm ) : ?>
                            <img src="<?php echo esc_url( $avatar_sm ); ?>" alt="" width="36" height="36">
                        <?php else : ?>
                            <?php echo esc_html( $initial ); ?>
                        <?php endif; ?>
                    </a>
                    <div>
                        <strong><?php echo esc_html( $nama ); ?></strong>
                        <span><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : esc_html( $site ); ?></span>
                    </div>
                </div>
                <a class="dana-top__bell" href="<?php echo esc_url( $hub_url ); ?>" aria-label="Beranda">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
                </a>
            </div>

            <div class="dana-balance">
                <p class="dana-balance__label">Tagihan belum dibayar</p>
                <div class="dana-balance__row">
                    <p class="dana-balance__amount"><?php echo esc_html( $hero_main ); ?></p>
                </div>
                <p class="dana-balance__meta"><?php echo esc_html( $hero_meta ); ?> · <?php echo esc_html( $site ); ?></p>
            </div>

            <?php if ( (int) ( $summary['count'] ?? 0 ) > 0 ) : ?>
                <div class="dana-stats" aria-label="Statistik tagihan">
                    <div class="dana-stats__item">
                        <span class="dana-stats__n"><?php echo (int) $count_belum; ?></span>
                        <span class="dana-stats__l">Belum</span>
                    </div>
                    <div class="dana-stats__item">
                        <span class="dana-stats__n"><?php echo (int) $count_tinjau; ?></span>
                        <span class="dana-stats__l">Proses</span>
                    </div>
                    <div class="dana-stats__item">
                        <span class="dana-stats__n"><?php echo (int) $count_lunas; ?></span>
                        <span class="dana-stats__l">Lunas</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="dana-sheet">
            <p class="dana-sheet__label">Daftar tagihan<?php echo $kelas ? ' · Kelas ' . esc_html( $kelas ) : ''; ?></p>
            <?php
            if ( function_exists( 'qrg_render_tagihan_table_for_siswa' ) ) {
                echo qrg_render_tagihan_table_for_siswa( $siswa_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
        </div>
    </div>
    <?php
    qrg_sekolah_portal_shell_end();
}

function qrg_render_sekolah_portal_pembelajaran( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $error    = '';
    $success  = '';
    $base_url = qrg_get_sekolah_portal_url( 'jadwal' );
    $nama     = get_the_title( $siswa_id );
    $kelas    = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $site     = get_bloginfo( 'name' );
    $akun_url = add_query_arg( 'tab', 'akun', qrg_get_sekolah_portal_url() );
    $hub_url  = qrg_get_sekolah_portal_url();
    $avatar_sm = function_exists( 'qrg_get_siswa_avatar_url' ) ? qrg_get_siswa_avatar_url( $siswa_id, 'thumbnail' ) : '';
    $initial  = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }

    if ( function_exists( 'qrg_process_submit_jawaban' ) ) {
        $submit  = qrg_process_submit_jawaban( $siswa_id );
        $error   = $submit['error'];
        $success = $submit['success'];
    }

    $view_tugas = isset( $_GET['tugas'] ) ? absint( $_GET['tugas'] ) : 0;
    // Daftar pembelajaran digabung ke jadwal — tanpa ?tugas= kembali ke jadwal.
    if ( ! $view_tugas ) {
        wp_safe_redirect( $base_url );
        exit;
    }

    $page_title  = 'Detail tugas';
    $sheet_label = 'Tugas';
    $tugas_post  = get_post( $view_tugas );
    if ( $tugas_post ) {
        $sheet_label = $tugas_post->post_title;
    }

    $back_url = $base_url;
    $mapel_id = (int) get_post_meta( $view_tugas, '_tugas_mapel_id', true );
    $kelas_id = (int) get_post_meta( $siswa_id, '_siswa_kelas', true );
    if ( $mapel_id > 0 && $kelas_id > 0 ) {
        $jadwal_ids = get_posts( [
            'post_type'      => 'master_jadwal',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => '_jadwal_kelas_id',
                    'value' => (string) $kelas_id,
                ],
                [
                    'key'   => '_jadwal_mapel_id',
                    'value' => (string) $mapel_id,
                ],
            ],
        ] );
        if ( ! empty( $jadwal_ids[0] ) && function_exists( 'qrg_get_pelajaran_url' ) ) {
            $back_url = qrg_get_pelajaran_url( (int) $jadwal_ids[0] );
        }
    }

    $hero_main = 'Kerjakan tugas';
    $hero_meta = $sheet_label;
    $counts    = [ 'semua' => 0, 'belum' => 0, 'dikirim' => 0, 'dinilai' => 0 ];

    $extra = '<style>
        .qrg-sk--ajar-page .dana-sheet { padding-top: 18px; }
        .qrg-sk--ajar-page .qrg-sk-ajar .ios-section__label { display: none; }
        .qrg-sk--ajar-page .qrg-ajar-back { display: none; }
        .qrg-sk--ajar-page .qrg-ajar-filters {
            margin: 0 -4px 12px;
            padding: 2px 4px 10px;
        }
        .qrg-sk--ajar-page .qrg-ajar-filter {
            background: #F2F2F7;
        }
        .qrg-sk--ajar-page .qrg-ajar-filter.is-on {
            background: #0086F0;
            box-shadow: 0 4px 12px rgba(0,134,240,.28);
        }
        .qrg-sk--ajar-page .qrg-ajar-list {
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(15,23,42,.06);
            border: 1px solid rgba(60,60,67,.06);
        }
        .qrg-sk--ajar-page .qrg-ajar-item {
            padding: 14px 16px;
        }
        .qrg-sk--ajar-page .qrg-ajar-item__title {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -.01em;
        }
        .qrg-sk--ajar-page .qrg-ajar-empty {
            border: 1px dashed rgba(60,60,67,.18);
            background: #F7F8FA;
            border-radius: 16px;
            box-shadow: none;
            padding: 28px 16px;
        }
        .qrg-sk--ajar-page .qrg-ajar-card {
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(15,23,42,.06);
            border: 1px solid rgba(60,60,67,.06);
            margin-bottom: 0;
        }
        .qrg-sk--ajar-page .qrg-ajar-card h3 {
            font-size: 1.15rem;
            letter-spacing: -.02em;
        }
        .qrg-sk--ajar-page .err,
        .qrg-sk--ajar-page .ok {
            border-radius: 14px;
            margin-bottom: 12px;
        }
        .qrg-sk--ajar-page .dana-sheet__label {
            margin-top: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .qrg-ajar-page-back {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            opacity: .92;
            margin-bottom: 10px;
        }
        .qrg-ajar-page-back:active { opacity: .75; }
        .qrg-sk--ajar-page .dana-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 14px;
        }
        .qrg-sk--ajar-page .dana-stats__item {
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 14px;
            padding: 10px 8px;
            text-align: center;
        }
        .qrg-sk--ajar-page .dana-stats__n {
            display: block;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -.02em;
            line-height: 1.1;
        }
        .qrg-sk--ajar-page .dana-stats__l {
            display: block;
            margin-top: 3px;
            font-size: 11px;
            opacity: .88;
            font-weight: 600;
        }
    </style>';

    qrg_sekolah_portal_shell_start( $page_title, $extra, 'jadwal' );
    ?>
    <div class="qrg-sk qrg-sk--dana qrg-sk--ajar-page">
        <div class="dana-top">
            <a class="qrg-ajar-page-back" href="<?php echo esc_url( $back_url ); ?>">
                ← <?php echo ( $back_url !== $base_url ) ? 'Materi' : 'Jadwal'; ?>
            </a>
            <div class="dana-top__row">
                <div class="dana-top__brand">
                    <a class="dana-top__logo<?php echo $avatar_sm ? ' has-photo' : ''; ?>" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Profil">
                        <?php if ( $avatar_sm ) : ?>
                            <img src="<?php echo esc_url( $avatar_sm ); ?>" alt="" width="36" height="36">
                        <?php else : ?>
                            <?php echo esc_html( $initial ); ?>
                        <?php endif; ?>
                    </a>
                    <div>
                        <strong><?php echo esc_html( $nama ); ?></strong>
                        <span><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : esc_html( $site ); ?></span>
                    </div>
                </div>
                <a class="dana-top__bell" href="<?php echo esc_url( $hub_url ); ?>" aria-label="Beranda">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
                </a>
            </div>

            <div class="dana-balance">
                <p class="dana-balance__label">Pembelajaran</p>
                <div class="dana-balance__row">
                    <p class="dana-balance__amount" style="font-size:1.35rem;"><?php echo esc_html( $hero_main ); ?></p>
                </div>
                <p class="dana-balance__meta"><?php echo esc_html( $hero_meta ); ?> · <?php echo esc_html( $site ); ?></p>
            </div>
        </div>

        <div class="dana-sheet">
            <p class="dana-sheet__label"><?php echo esc_html( $sheet_label ); ?></p>
            <?php
            if ( function_exists( 'qrg_render_siswa_tugas_panel' ) ) {
                qrg_render_siswa_tugas_panel( $siswa_id, $base_url, $view_tugas, $error, $success, [ 'embedded' => true ] );
            }
            ?>
        </div>
    </div>
    <?php
    qrg_sekolah_portal_shell_end();
}

/**
 * Halaman bayar tagihan (/sekolah/bayar/{id}/) — desain DANA seperti beranda.
 *
 * @param int $tagihan_id Post ID master_tagihan.
 */
function qrg_render_sekolah_portal_bayar( $tagihan_id ) {
    $tagihan_id = (int) $tagihan_id;
    $siswa_id   = (int) get_post_meta( $tagihan_id, '_tagihan_siswa_id', true );
    $nominal    = (int) get_post_meta( $tagihan_id, '_tagihan_nominal', true );
    $biaya_id   = get_post_meta( $tagihan_id, '_tagihan_biaya_tambahan_id', true );
    $keterangan = (string) get_post_meta( $tagihan_id, '_tagihan_keterangan', true );
    $status     = (string) get_post_meta( $tagihan_id, '_tagihan_status', true );
    if ( $status === '' ) {
        $status = 'Belum Dibayar';
    }
    $bukti_url = (string) get_post_meta( $tagihan_id, '_tagihan_bukti_url', true );
    // Jika bukti sudah ada tapi status masih Belum Dibayar, naikkan ke Proses.
    if ( $bukti_url !== '' && $status === 'Belum Dibayar' ) {
        if ( function_exists( 'qrg_tagihan_set_status' ) ) {
            qrg_tagihan_set_status( $tagihan_id, 'Sedang Ditinjau', [
                'via'     => 'sistem',
                'force'   => true,
                'catatan' => 'Sinkron status setelah bukti terunggah',
            ] );
        } else {
            update_post_meta( $tagihan_id, '_tagihan_status', 'Sedang Ditinjau' );
        }
        $status = 'Sedang Ditinjau';
    }

    $nama  = get_the_title( $siswa_id );
    $kelas = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $site  = get_bloginfo( 'name' );
    $invoice_title = get_the_title( $tagihan_id );
    $back_url = qrg_get_sekolah_portal_url( 'pembayaran' );
    $akun_url = add_query_arg( 'tab', 'akun', qrg_get_sekolah_portal_url() );
    $hub_url  = qrg_get_sekolah_portal_url();
    $avatar_sm = function_exists( 'qrg_get_siswa_avatar_url' ) ? qrg_get_siswa_avatar_url( $siswa_id, 'thumbnail' ) : '';
    $initial = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }

    $biaya_nama    = '';
    $biaya_nominal = 0;
    $total         = $nominal;
    if ( $biaya_id ) {
        $biaya_nama    = get_the_title( $biaya_id );
        $biaya_nominal = (int) get_post_meta( $biaya_id, '_biaya_nominal', true );
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
        $qr_code_url   = (string) $qr_meta['url'];
        $qr_fallback   = (string) ( $qr_meta['fallback'] ?: $qr_meta['url'] );
        $qr_is_dynamic = ! empty( $qr_meta['dynamic'] );
        $qr_merchant   = (string) ( $qr_meta['merchant'] ?? '' );
    } else {
        $uploaded_qr = function_exists( 'qrg_get_pembayaran_qr_url' ) ? qrg_get_pembayaran_qr_url( 'full' ) : '';
        if ( $uploaded_qr ) {
            $qr_code_url   = $uploaded_qr;
            $qr_fallback   = $uploaded_qr;
            $qr_is_dynamic = false;
            $qr_merchant   = '';
        } else {
            $qris_raw = function_exists( 'qrg_get_default_pembayaran_qr_payload' )
                ? qrg_get_default_pembayaran_qr_payload()
                : '';
            $qr_payload    = rawurlencode( (string) $qris_raw );
            $qr_code_url   = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . $qr_payload;
            $qr_fallback   = 'https://quickchart.io/qr?size=300&text=' . $qr_payload;
            $qr_is_dynamic = false;
            $qr_merchant   = '';
        }
    }

    $status_tone = 'wait';
    $status_label = 'Belum Dibayar';
    if ( $status === 'Lunas' ) {
        $status_tone  = 'ok';
        $status_label = 'Lunas';
    } elseif ( $status === 'Sedang Ditinjau' ) {
        $status_tone  = 'info';
        $status_label = 'Proses';
    }

    $bukti_ok  = isset( $_GET['bukti'] ) && sanitize_key( wp_unslash( $_GET['bukti'] ) ) === 'ok';
    $bukti_err = isset( $_GET['bukti_err'] ) ? sanitize_text_field( wp_unslash( $_GET['bukti_err'] ) ) : '';

    $extra = '<style>
        .qrg-sk--invoice-page .dana-sheet { padding-top: 18px; }
        .qrg-sk--invoice-page .dana-sheet__label { margin-top: 0; margin-bottom: 12px; }
        .qrg-inv-back {
            display: inline-flex; align-items: center; gap: 4px;
            color: #fff; text-decoration: none; font-size: 13px; font-weight: 600;
            opacity: .92; margin-bottom: 10px;
        }
        .qrg-inv-back:active { opacity: .75; }
        .qrg-inv-status {
            display: inline-flex; margin-top: 10px; padding: 6px 12px;
            border-radius: 999px; font-size: 12px; font-weight: 700;
            background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.28); color: #fff;
        }
        .qrg-inv-status.is-ok { background: rgba(52,199,89,.25); }
        .qrg-inv-status.is-info { background: rgba(90,200,250,.25); }
        .qrg-inv-flash {
            margin: 0 0 12px; padding: 12px 14px; border-radius: 14px; font-size: 14px; font-weight: 600;
        }
        .qrg-inv-flash.is-ok { background: rgba(52,199,89,.12); color: #248A3D; }
        .qrg-inv-flash.is-err { background: rgba(255,59,48,.1); color: #FF3B30; }
        .qrg-inv-card {
            background: #fff; border-radius: 18px; padding: 16px; margin-bottom: 12px;
            box-shadow: 0 8px 24px rgba(15,23,42,.06); border: 1px solid rgba(60,60,67,.06);
        }
        .qrg-inv-card h2 { margin: 0 0 4px; font-size: 1.05rem; font-weight: 800; letter-spacing: -.02em; }
        .qrg-inv-card .inv-id { margin: 0 0 12px; font-size: 13px; color: var(--ios-secondary); }
        .qrg-inv-table { width: 100%; border-collapse: collapse; }
        .qrg-inv-table td { padding: 7px 0; font-size: 13px; vertical-align: top; }
        .qrg-inv-table .text-right { text-align: right; }
        .qrg-inv-table .total-row td { font-size: 16px; font-weight: 800; border-top: 1px solid var(--ios-sep); padding-top: 12px; }
        .qrg-inv-table .total-row .text-right { color: #FF3B30; }
        .qrg-pay-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 0 0 12px; }
        .qrg-pay-tab {
            appearance: none; border: 0; background: #F2F2F7; color: #1C1C1E;
            border-radius: 980px; padding: 12px 10px; min-height: 44px; font: inherit;
            font-size: 13px; font-weight: 700; cursor: pointer;
        }
        .qrg-pay-tab.is-on { background: #0086F0; color: #fff; box-shadow: 0 4px 12px rgba(0,134,240,.28); }
        .qrg-pay-panel { display: none; }
        .qrg-pay-panel.is-on { display: block; }
        .qrg-inv-qr, .qrg-inv-bank, .qrg-inv-up, .qrg-inv-note {
            background: #fff; border-radius: 16px; padding: 16px;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
            margin-bottom: 12px;
        }
        .qrg-inv-qr { text-align: center; }
        .qrg-inv-qr img {
            max-width: 220px; width: 100%; height: auto; margin: 10px auto;
            border: 1px solid var(--ios-sep); padding: 10px; background: #fff; border-radius: 12px; display: block;
        }
        .qrg-inv-hint {
            text-align: left; margin-top: 10px; padding: 12px; border-radius: 12px;
            background: rgba(255,204,0,.12); border-left: 4px solid #FFCC00;
            font-size: 13px; line-height: 1.5; color: #1C1C1E;
        }
        .qrg-inv-bank__row {
            display: flex; justify-content: space-between; gap: 10px;
            padding: 10px 0; border-bottom: 1px solid var(--ios-sep); font-size: 13px;
        }
        .qrg-inv-bank__row:last-child { border-bottom: 0; }
        .qrg-inv-bank__label { color: var(--ios-secondary); }
        .qrg-inv-bank__val {
            font-weight: 700; text-align: right; word-break: break-all;
            display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 6px;
        }
        .qrg-inv-bank__code { font-size: 18px; letter-spacing: .06em; color: #0086F0; }
        .qrg-inv-bank__amt { font-size: 20px; color: #FF3B30; }
        .qrg-inv-copy {
            appearance: none; border: 0; background: rgba(0,134,240,.12); color: #0086F0;
            font-size: 11px; font-weight: 700; border-radius: 8px; padding: 6px 10px; cursor: pointer;
        }
        .qrg-inv-up__title { margin: 0 0 4px; font-size: 15px; font-weight: 700; }
        .qrg-inv-up__hint { margin: 0 0 12px; font-size: 12px; color: var(--ios-secondary); }
        .qrg-inv-up__file { display: none; }
        .qrg-inv-up__drop {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 8px; min-height: 130px; padding: 16px; cursor: pointer; text-align: center;
            border: 2px dashed #C7C7CC; border-radius: 14px; background: #F7F8FA;
        }
        .qrg-inv-up__drop.is-drag, .qrg-inv-up__drop:active { border-color: #0086F0; background: rgba(0,134,240,.06); }
        .qrg-inv-up__drop.has-file { border-style: solid; border-color: #34C759; background: rgba(52,199,89,.08); }
        .qrg-inv-up__ico {
            width: 48px; height: 48px; border-radius: 14px; background: rgba(0,134,240,.12); color: #0086F0;
            display: flex; align-items: center; justify-content: center; font-size: 22px;
        }
        .qrg-inv-up__drop-title { font-size: 14px; font-weight: 700; }
        .qrg-inv-up__drop-sub { font-size: 12px; color: var(--ios-secondary); }
        .qrg-inv-up__preview { display: none; margin-top: 12px; border-radius: 12px; overflow: hidden; border: 1px solid var(--ios-sep); }
        .qrg-inv-up__preview.is-on { display: block; }
        .qrg-inv-up__preview img { display: block; width: 100%; max-height: 220px; object-fit: contain; background: #1C1C1E; }
        .qrg-inv-up__meta { display: flex; justify-content: space-between; gap: 8px; padding: 10px 12px; font-size: 12px; }
        .qrg-inv-up__change { appearance: none; border: 0; background: none; color: #0086F0; font-weight: 700; cursor: pointer; }
        .qrg-inv-up__submit {
            appearance: none; border: 0; width: 100%; margin-top: 14px; min-height: 48px;
            background: #0086F0; color: #fff; border-radius: 14px; font-size: 15px; font-weight: 700; cursor: pointer;
        }
        .qrg-inv-up__submit:disabled { opacity: .55; cursor: not-allowed; }
        .qrg-inv-up__submit.is-ready { background: #34C759; }
        .qrg-inv-note { text-align: center; font-weight: 700; }
        .qrg-inv-note.is-info { background: rgba(0,122,255,.08); color: #007AFF; }
        .qrg-inv-note.is-ok { background: rgba(52,199,89,.12); color: #248A3D; }
        .qrg-inv-status-card {
            background: #fff; border-radius: 16px; padding: 16px; margin-bottom: 12px;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .qrg-inv-status-card__head {
            display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px;
        }
        .qrg-inv-status-card__title {
            margin: 0; font-size: 13px; font-weight: 600; color: var(--ios-secondary);
            text-transform: uppercase; letter-spacing: .02em;
        }
        .qrg-inv-status-card__badge {
            display: inline-flex; align-items: center; padding: 6px 12px; border-radius: 999px;
            font-size: 12px; font-weight: 700;
        }
        .qrg-inv-status-card__badge.is-wait {
            background: rgba(255,149,0,.14); color: #C93400;
        }
        .qrg-inv-status-card__badge.is-info {
            background: rgba(0,122,255,.12); color: #007AFF;
        }
        .qrg-inv-status-card__badge.is-ok {
            background: rgba(52,199,89,.14); color: #248A3D;
        }
        .qrg-inv-status-card__desc {
            margin: 0; font-size: 14px; color: var(--ios-ink); line-height: 1.45;
        }
        .qrg-inv-bukti {
            background: #fff; border-radius: 16px; padding: 16px; margin-bottom: 12px;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .qrg-inv-bukti__title {
            margin: 0 0 6px; font-size: 15px; font-weight: 700;
        }
        .qrg-inv-bukti__hint {
            margin: 0 0 12px; font-size: 13px; color: var(--ios-secondary); line-height: 1.4;
        }
        .qrg-inv-bukti__actions {
            display: grid; grid-template-columns: 1fr; gap: 8px;
        }
        .qrg-inv-bukti__actions button {
            appearance: none; border: 0; cursor: pointer;
            display: flex; align-items: center; justify-content: center; min-height: 44px;
            border-radius: 12px; font-size: 14px; font-weight: 700;
            background: rgba(0,134,240,.1); color: #0086F0; width: 100%;
        }
        .qrg-bukti-pop {
            position: fixed; inset: 0; z-index: 100010; display: flex; align-items: center; justify-content: center;
            background: rgba(15,23,42,.5); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .2s, visibility .2s;
            padding: 20px; padding-bottom: max(20px, env(safe-area-inset-bottom));
        }
        .qrg-bukti-pop.is-on { opacity: 1; visibility: visible; pointer-events: auto; }
        .qrg-bukti-pop__card {
            width: min(340px, 100%); background: #fff; border-radius: 18px; overflow: hidden;
            box-shadow: 0 16px 48px rgba(15,23,42,.28); transform: translateY(10px) scale(.97);
            transition: transform .22s ease;
        }
        .qrg-bukti-pop.is-on .qrg-bukti-pop__card { transform: none; }
        .qrg-bukti-pop__head {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 12px 14px; border-bottom: 1px solid var(--ios-sep);
        }
        .qrg-bukti-pop__head strong { font-size: 14px; }
        .qrg-bukti-pop__close {
            appearance: none; border: 0; background: #F2F2F7; color: var(--ios-ink);
            width: 30px; height: 30px; border-radius: 50%; font-size: 18px; line-height: 1;
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
        }
        .qrg-bukti-pop__body { padding: 12px; background: #F2F2F7; }
        .qrg-bukti-pop__body img {
            display: block; width: 100%; max-height: 280px; object-fit: contain;
            border-radius: 12px; background: #1C1C1E;
        }
        .qrg-up-load {
            position: fixed; inset: 0; z-index: 100000; display: flex; align-items: flex-end; justify-content: center;
            background: rgba(15,23,42,.45); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .22s, visibility .22s;
            padding: 16px; padding-bottom: max(16px, env(safe-area-inset-bottom));
        }
        .qrg-up-load.is-on { opacity: 1; visibility: visible; pointer-events: auto; }
        .qrg-up-load__sheet {
            width: min(420px, 100%); background: #fff; border-radius: 20px; padding: 22px 20px; text-align: center;
            box-shadow: 0 -8px 40px rgba(15,23,42,.2);
        }
        .qrg-up-load__orb { width: 64px; height: 64px; margin: 0 auto 14px; position: relative; }
        .qrg-up-load__orb::before {
            content: ""; position: absolute; inset: 0; border-radius: 50%;
            border: 3px solid #E5E5EA; border-top-color: #0086F0; animation: qrgInvSpin .75s linear infinite;
        }
        .qrg-up-load__title { margin: 0 0 4px; font-size: 16px; font-weight: 700; }
        .qrg-up-load__tip { margin: 0; font-size: 13px; color: var(--ios-secondary); }
        @keyframes qrgInvSpin { to { transform: rotate(360deg); } }
    </style>';

    qrg_sekolah_portal_shell_start( 'Bayar Tagihan', $extra, 'bayar' );
    ?>
    <div class="qrg-sk qrg-sk--dana qrg-sk--invoice-page">
        <div class="dana-top">
            <a class="qrg-inv-back" href="<?php echo esc_url( $back_url ); ?>">← Daftar pembayaran</a>
            <div class="dana-top__row">
                <div class="dana-top__brand">
                    <a class="dana-top__logo<?php echo $avatar_sm ? ' has-photo' : ''; ?>" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Profil">
                        <?php if ( $avatar_sm ) : ?>
                            <img src="<?php echo esc_url( $avatar_sm ); ?>" alt="" width="36" height="36">
                        <?php else : ?>
                            <?php echo esc_html( $initial ); ?>
                        <?php endif; ?>
                    </a>
                    <div>
                        <strong><?php echo esc_html( $nama ); ?></strong>
                        <span><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : esc_html( $site ); ?></span>
                    </div>
                </div>
                <a class="dana-top__bell" href="<?php echo esc_url( $hub_url ); ?>" aria-label="Beranda">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
                </a>
            </div>
            <div class="dana-balance">
                <p class="dana-balance__label">Total tagihan</p>
                <div class="dana-balance__row">
                    <p class="dana-balance__amount"><?php echo esc_html( $formatted_amount ); ?></p>
                </div>
                <p class="dana-balance__meta"><?php echo esc_html( $invoice_title ); ?> · <?php echo esc_html( $site ); ?></p>
                <span class="qrg-inv-status is-<?php echo esc_attr( $status_tone ); ?>"><?php echo esc_html( $status_label ); ?></span>
            </div>
        </div>

        <div class="dana-sheet">
            <p class="dana-sheet__label">Detail &amp; pembayaran</p>

            <?php if ( $bukti_ok ) : ?>
                <div class="qrg-inv-flash is-ok">Bukti berhasil dikirim. Status diubah menjadi <strong>Proses</strong>.</div>
            <?php endif; ?>
            <?php if ( $bukti_err !== '' ) : ?>
                <div class="qrg-inv-flash is-err"><?php echo esc_html( $bukti_err ); ?></div>
            <?php endif; ?>

            <div class="qrg-inv-status-card" id="status">
                <div class="qrg-inv-status-card__head">
                    <p class="qrg-inv-status-card__title">Status pembayaran</p>
                    <span class="qrg-inv-status-card__badge is-<?php echo esc_attr( $status_tone ); ?>"><?php echo esc_html( $status_label ); ?></span>
                </div>
                <p class="qrg-inv-status-card__desc">
                    <?php if ( $status === 'Belum Dibayar' ) : ?>
                        Belum ada bukti yang dikirim. Silakan bayar lalu unggah bukti transfer.
                    <?php elseif ( $status === 'Sedang Ditinjau' ) : ?>
                        Pembayaran sedang diproses. Admin akan memeriksa bukti yang Anda unggah.
                    <?php else : ?>
                        Pembayaran sudah dikonfirmasi dan dinyatakan lunas.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ( $bukti_url !== '' ) : ?>
                <div class="qrg-inv-bukti" id="bukti">
                    <p class="qrg-inv-bukti__title">Hasil upload bukti</p>
                    <p class="qrg-inv-bukti__hint">Bukti sudah terkirim. Ketuk tombol di bawah untuk melihatnya.</p>
                    <div class="qrg-inv-bukti__actions">
                        <button type="button" id="qrg-bukti-open" aria-haspopup="dialog" aria-controls="qrg-bukti-pop">
                            Cek / lihat bukti
                        </button>
                    </div>
                </div>
                <div class="qrg-bukti-pop" id="qrg-bukti-pop" role="dialog" aria-modal="true" aria-labelledby="qrg-bukti-pop-title" aria-hidden="true">
                    <div class="qrg-bukti-pop__card">
                        <div class="qrg-bukti-pop__head">
                            <strong id="qrg-bukti-pop-title">Bukti pembayaran</strong>
                            <button type="button" class="qrg-bukti-pop__close" id="qrg-bukti-close" aria-label="Tutup">&times;</button>
                        </div>
                        <div class="qrg-bukti-pop__body">
                            <img src="<?php echo esc_url( $bukti_url ); ?>" alt="Bukti pembayaran" loading="lazy">
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    var pop = document.getElementById('qrg-bukti-pop');
                    var openBtn = document.getElementById('qrg-bukti-open');
                    var closeBtn = document.getElementById('qrg-bukti-close');
                    if (!pop || !openBtn) return;
                    function openPop() {
                        pop.classList.add('is-on');
                        pop.setAttribute('aria-hidden', 'false');
                    }
                    function closePop() {
                        pop.classList.remove('is-on');
                        pop.setAttribute('aria-hidden', 'true');
                    }
                    openBtn.addEventListener('click', openPop);
                    if (closeBtn) closeBtn.addEventListener('click', closePop);
                    pop.addEventListener('click', function (e) {
                        if (e.target === pop) closePop();
                    });
                    document.addEventListener('keydown', function (e) {
                        if (e.key === 'Escape' && pop.classList.contains('is-on')) closePop();
                    });
                })();
                </script>
            <?php endif; ?>

            <div class="qrg-inv-card">
                <h2>Invoice pembayaran</h2>
                <p class="inv-id">ID: <strong><?php echo esc_html( $invoice_title ); ?></strong></p>
                <table class="qrg-inv-table">
                    <tr>
                        <td>Nama Siswa</td>
                        <td class="text-right"><strong><?php echo esc_html( $nama ); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Kelas</td>
                        <td class="text-right"><?php echo esc_html( $kelas !== '' ? $kelas : '—' ); ?></td>
                    </tr>
                    <tr>
                        <td>Status</td>
                        <td class="text-right"><strong><?php echo esc_html( $status_label ); ?></strong></td>
                    </tr>
                    <?php if ( $keterangan !== '' ) : ?>
                    <tr>
                        <td>Keterangan</td>
                        <td class="text-right"><?php echo nl2br( esc_html( $keterangan ) ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td style="color:var(--ios-secondary);">Tagihan utama</td>
                        <td class="text-right" style="color:var(--ios-secondary);">Rp <?php echo esc_html( number_format( $nominal, 0, ',', '.' ) ); ?></td>
                    </tr>
                    <?php if ( $biaya_id ) : ?>
                    <tr>
                        <td style="color:var(--ios-secondary);">Biaya tambahan (<?php echo esc_html( $biaya_nama ); ?>)</td>
                        <td class="text-right" style="color:var(--ios-secondary);">Rp <?php echo esc_html( number_format( $biaya_nominal, 0, ',', '.' ) ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td>Total</td>
                        <td class="text-right"><?php echo esc_html( $formatted_amount ); ?></td>
                    </tr>
                </table>
            </div>

            <?php if ( $status === 'Belum Dibayar' ) : ?>
                <?php if ( $bank_on ) : ?>
                <div class="qrg-pay-tabs" role="tablist">
                    <button type="button" class="qrg-pay-tab is-on" data-pay="qris">QRIS</button>
                    <button type="button" class="qrg-pay-tab" data-pay="bank">Transfer</button>
                </div>
                <?php endif; ?>

                <div class="qrg-pay-panel is-on" data-pay-panel="qris">
                    <div class="qrg-inv-qr">
                        <strong>Pindai QRIS pembayaran</strong>
                        <?php if ( ! empty( $qr_is_dynamic ) ) : ?>
                            <p style="margin:8px 0 0;font-size:12px;color:#248A3D;font-weight:600;">
                                QRIS dinamis · nominal <?php echo esc_html( $formatted_amount ); ?>
                                <?php echo $qr_merchant !== '' ? ' · ' . esc_html( $qr_merchant ) : ''; ?>
                            </p>
                        <?php endif; ?>
                        <img src="<?php echo esc_url( $qr_code_url ); ?>"
                             data-fallback="<?php echo esc_url( $qr_fallback ); ?>"
                             alt="QRIS"
                             onerror="if(this.dataset.fallback&&this.src!==this.dataset.fallback){this.src=this.dataset.fallback;}">
                        <div class="qrg-inv-hint">
                            1. Scan QR dengan e-wallet / m-banking<br>
                            2. Pastikan nominal <strong><?php echo esc_html( $formatted_amount ); ?></strong><br>
                            3. Screenshot bukti, lalu unggah di bawah
                        </div>
                    </div>
                </div>

                <?php if ( $bank_on ) : ?>
                <div class="qrg-pay-panel" data-pay-panel="bank">
                    <div class="qrg-inv-bank">
                        <div class="qrg-inv-bank__row">
                            <span class="qrg-inv-bank__label">Bank</span>
                            <span class="qrg-inv-bank__val"><?php echo esc_html( $bank_name !== '' ? $bank_name : '—' ); ?></span>
                        </div>
                        <div class="qrg-inv-bank__row">
                            <span class="qrg-inv-bank__label">No. rekening</span>
                            <span class="qrg-inv-bank__val">
                                <span id="qrg-bank-acc"><?php echo esc_html( $bank_acc ); ?></span>
                                <button type="button" class="qrg-inv-copy" data-copy="qrg-bank-acc">Salin</button>
                            </span>
                        </div>
                        <?php if ( $bank_holder !== '' ) : ?>
                        <div class="qrg-inv-bank__row">
                            <span class="qrg-inv-bank__label">Atas nama</span>
                            <span class="qrg-inv-bank__val"><?php echo esc_html( $bank_holder ); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="qrg-inv-bank__row">
                            <span class="qrg-inv-bank__label">Kode unik</span>
                            <span class="qrg-inv-bank__val">
                                <span class="qrg-inv-bank__code" id="qrg-bank-unik"><?php echo esc_html( $tf_info['kode'] ); ?></span>
                                <button type="button" class="qrg-inv-copy" data-copy="qrg-bank-unik">Salin</button>
                            </span>
                        </div>
                        <div class="qrg-inv-bank__row">
                            <span class="qrg-inv-bank__label">Kode bayar</span>
                            <span class="qrg-inv-bank__val">
                                <span id="qrg-bank-ref"><?php echo esc_html( $tf_info['referensi'] ); ?></span>
                                <button type="button" class="qrg-inv-copy" data-copy="qrg-bank-ref">Salin</button>
                            </span>
                        </div>
                        <div class="qrg-inv-bank__row">
                            <span class="qrg-inv-bank__label">Jumlah transfer</span>
                            <span class="qrg-inv-bank__val">
                                <span class="qrg-inv-bank__amt" id="qrg-bank-amt"><?php echo esc_html( $tf_amount ); ?></span>
                                <button type="button" class="qrg-inv-copy" data-copy-text="<?php echo esc_attr( (string) (int) $tf_info['jumlah'] ); ?>">Salin</button>
                            </span>
                        </div>
                    </div>
                    <div class="qrg-inv-hint">
                        Transfer tepat <strong><?php echo esc_html( $tf_amount ); ?></strong>, lalu unggah bukti di bawah.
                    </div>
                </div>
                <?php endif; ?>

                <div class="qrg-inv-up">
                    <p class="qrg-inv-up__title">Unggah bukti pembayaran</p>
                    <p class="qrg-inv-up__hint">Screenshot transfer · JPG/PNG</p>
                    <form method="post" enctype="multipart/form-data" id="qrg-up-form" action="<?php echo esc_url( function_exists( 'qrg_get_tagihan_bayar_url' ) ? qrg_get_tagihan_bayar_url( $tagihan_id ) : '' ); ?>" novalidate>
                        <?php wp_nonce_field( 'qrg_upload_action', 'qrg_bukti_nonce' ); ?>
                        <input type="hidden" name="qrg_upload_bukti" value="1">
                        <input type="file" class="qrg-inv-up__file" name="bukti_transfer" id="qrg-bukti-file" accept="image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif" required>
                        <label class="qrg-inv-up__drop" id="qrg-up-drop" for="qrg-bukti-file">
                            <span class="qrg-inv-up__ico" aria-hidden="true">📷</span>
                            <span class="qrg-inv-up__drop-title" id="qrg-up-drop-title">Ketuk untuk pilih foto</span>
                            <span class="qrg-inv-up__drop-sub">dari galeri atau kamera</span>
                        </label>
                        <div class="qrg-inv-up__preview" id="qrg-up-preview">
                            <img src="" alt="Pratinjau" id="qrg-up-preview-img">
                            <div class="qrg-inv-up__meta">
                                <strong id="qrg-up-filename">—</strong>
                                <button type="button" class="qrg-inv-up__change" id="qrg-up-change">Ganti</button>
                            </div>
                        </div>
                        <button type="submit" class="qrg-inv-up__submit" id="qrg-up-submit" disabled>
                            Kirim bukti pembayaran
                        </button>
                    </form>
                </div>

                <div class="qrg-up-load" id="qrg-up-load" aria-hidden="true" role="status">
                    <div class="qrg-up-load__sheet">
                        <div class="qrg-up-load__orb" aria-hidden="true"></div>
                        <p class="qrg-up-load__title">Mengunggah bukti…</p>
                        <p class="qrg-up-load__tip">Mohon tunggu, jangan tutup halaman.</p>
                    </div>
                </div>

                <script>
                (function () {
                    document.querySelectorAll('.qrg-pay-tab').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var id = btn.getAttribute('data-pay');
                            document.querySelectorAll('.qrg-pay-tab').forEach(function (b) { b.classList.toggle('is-on', b === btn); });
                            document.querySelectorAll('.qrg-pay-panel').forEach(function (p) {
                                p.classList.toggle('is-on', p.getAttribute('data-pay-panel') === id);
                            });
                        });
                    });
                    document.querySelectorAll('.qrg-inv-copy').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var text = btn.getAttribute('data-copy-text') || '';
                            if (!text) {
                                var el = document.getElementById(btn.getAttribute('data-copy') || '');
                                text = el ? (el.textContent || '').trim() : '';
                            }
                            if (!text) return;
                            var old = btn.textContent;
                            function done() { btn.textContent = 'Tersalin'; setTimeout(function () { btn.textContent = old; }, 1400); }
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(text).then(done).catch(function () { window.prompt('Salin:', text); });
                            } else { window.prompt('Salin:', text); }
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
                    if (!form || !input) return;
                    function formatSize(bytes) {
                        if (bytes < 1024) return bytes + ' B';
                        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                        return (bytes / 1048576).toFixed(1) + ' MB';
                    }
                    function setFile(file) {
                        if (!file) {
                            preview.classList.remove('is-on'); drop.classList.remove('has-file'); drop.style.display = '';
                            dropTitle.textContent = 'Ketuk untuk pilih foto'; submitBtn.disabled = true; submitBtn.classList.remove('is-ready');
                            previewImg.removeAttribute('src'); return;
                        }
                        if (!/^image\//.test(file.type)) { alert('Pilih file gambar (JPG/PNG).'); input.value = ''; setFile(null); return; }
                        if (file.size > 8 * 1024 * 1024) { alert('File terlalu besar (maks. ~8 MB).'); input.value = ''; setFile(null); return; }
                        var url = URL.createObjectURL(file);
                        previewImg.onload = function () { URL.revokeObjectURL(url); };
                        previewImg.src = url;
                        fileNameEl.textContent = file.name + ' · ' + formatSize(file.size);
                        preview.classList.add('is-on'); drop.classList.add('has-file'); drop.style.display = 'none';
                        dropTitle.textContent = 'Foto siap diunggah'; submitBtn.disabled = false; submitBtn.classList.add('is-ready');
                    }
                    input.addEventListener('change', function () { setFile(input.files && input.files[0] ? input.files[0] : null); });
                    if (changeBtn) changeBtn.addEventListener('click', function () { input.click(); });
                    ['dragenter','dragover'].forEach(function (ev) {
                        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-drag'); });
                    });
                    ['dragleave','drop'].forEach(function (ev) {
                        drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-drag'); });
                    });
                    drop.addEventListener('drop', function (e) {
                        var files = e.dataTransfer && e.dataTransfer.files;
                        if (!files || !files.length) return;
                        input.files = files; setFile(files[0]);
                    });
                    form.addEventListener('submit', function (e) {
                        if (submitBtn.disabled) {
                            e.preventDefault();
                            return false;
                        }
                        // Jangan disable sebelum request terkirim — hidden field sudah menandai aksi.
                        submitBtn.textContent = 'Mengirim…';
                        if (load) { load.classList.add('is-on'); load.setAttribute('aria-hidden', 'false'); }
                    });
                })();
                </script>

            <?php elseif ( $status === 'Sedang Ditinjau' ) : ?>
                <div class="qrg-inv-note is-info">⏳ Status: Proses — admin sedang memeriksa bukti Anda</div>
            <?php else : ?>
                <div class="qrg-inv-note is-ok">✓ Pembayaran sukses &amp; lunas</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    qrg_sekolah_portal_shell_end();
}
