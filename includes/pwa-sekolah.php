<?php
/**
 * Progressive Web App untuk Portal Siswa (/sekolah/).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * URL aset PWA di bawah /sekolah/.
 *
 * @param string $path Path relatif (manifest.webmanifest, sw.js, offline/, icons/192.png).
 * @return string
 */
function qrg_sekolah_pwa_url( $path = '' ) {
    $path = ltrim( (string) $path, '/' );
    return home_url( '/sekolah/' . $path );
}

/**
 * Path file ikon di plugin.
 *
 * @param string $size 192|512|180|32
 * @return string
 */
function qrg_sekolah_pwa_icon_file( $size ) {
    $map = [
        '192' => 'icon-192.png',
        '512' => 'icon-512.png',
        '180' => 'apple-touch-icon.png',
        '32'  => 'favicon-32.png',
    ];
    $size = (string) $size;
    $file = isset( $map[ $size ] ) ? $map[ $size ] : '';
    if ( $file === '' ) {
        return '';
    }
    return QRG_PLUGIN_DIR . 'assets/pwa/' . $file;
}

add_action( 'init', 'qrg_sekolah_pwa_rewrite_rules' );
function qrg_sekolah_pwa_rewrite_rules() {
    add_rewrite_rule( '^sekolah/manifest\.webmanifest$', 'index.php?qrg_sekolah_pwa=manifest', 'top' );
    add_rewrite_rule( '^sekolah/sw\.js$', 'index.php?qrg_sekolah_pwa=sw', 'top' );
    add_rewrite_rule( '^sekolah/offline/?$', 'index.php?qrg_sekolah_pwa=offline', 'top' );
    add_rewrite_rule( '^sekolah/icons/(192|512|180|32)\.png$', 'index.php?qrg_sekolah_pwa=icon&qrg_pwa_icon=$matches[1]', 'top' );
}

add_filter( 'query_vars', 'qrg_sekolah_pwa_query_vars' );
function qrg_sekolah_pwa_query_vars( $vars ) {
    $vars[] = 'qrg_sekolah_pwa';
    $vars[] = 'qrg_pwa_icon';
    return $vars;
}

add_action( 'admin_init', 'qrg_sekolah_pwa_flush_rewrite_once' );
function qrg_sekolah_pwa_flush_rewrite_once() {
    if ( get_option( 'qrg_sekolah_pwa_rewrite_flushed_v1' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'qrg_sekolah_pwa_rewrite_flushed_v1', 1 );
}

add_action( 'template_redirect', 'qrg_sekolah_pwa_template_redirect', 4 );
function qrg_sekolah_pwa_template_redirect() {
    $kind = get_query_var( 'qrg_sekolah_pwa' );
    $icon = get_query_var( 'qrg_pwa_icon' );

    // Fallback jika rewrite belum di-flush (mis. setelah update plugin).
    if ( ! $kind && isset( $_SERVER['REQUEST_URI'] ) ) {
        $path = wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        $path = is_string( $path ) ? untrailingslashit( $path ) : '';
        $base = untrailingslashit( (string) wp_parse_url( home_url( '/sekolah' ), PHP_URL_PATH ) );
        if ( $path === $base . '/manifest.webmanifest' ) {
            $kind = 'manifest';
        } elseif ( $path === $base . '/sw.js' ) {
            $kind = 'sw';
        } elseif ( $path === $base . '/offline' ) {
            $kind = 'offline';
        } elseif ( preg_match( '#^' . preg_quote( $base, '#' ) . '/icons/(192|512|180|32)\.png$#', $path, $m ) ) {
            $kind = 'icon';
            $icon = $m[1];
        }
    }

    if ( ! $kind ) {
        return;
    }
    $kind = sanitize_key( (string) $kind );

    if ( $kind === 'manifest' ) {
        qrg_sekolah_pwa_serve_manifest();
        exit;
    }
    if ( $kind === 'sw' ) {
        qrg_sekolah_pwa_serve_sw();
        exit;
    }
    if ( $kind === 'offline' ) {
        qrg_sekolah_pwa_serve_offline();
        exit;
    }
    if ( $kind === 'icon' ) {
        qrg_sekolah_pwa_serve_icon( $icon );
        exit;
    }
}

/**
 * Manifest Web App dinamis.
 */
function qrg_sekolah_pwa_serve_manifest() {
    global $wp_query;
    if ( $wp_query ) {
        $wp_query->is_404 = false;
    }
    status_header( 200 );
    $site = wp_strip_all_tags( get_bloginfo( 'name' ) );
    $manifest = [
        'id'               => home_url( '/sekolah/' ),
        'name'             => 'Portal Sekolah — ' . $site,
        'short_name'       => 'Sekolah',
        'description'      => 'Portal siswa: pembayaran, jadwal, dan layanan sekolah.',
        'start_url'        => home_url( '/sekolah/?source=pwa' ),
        'scope'            => home_url( '/sekolah/' ),
        'display'          => 'standalone',
        'display_override' => [ 'standalone', 'browser' ],
        'orientation'      => 'portrait-primary',
        'background_color' => '#F2F2F7',
        'theme_color'      => '#F2F2F7',
        'lang'             => 'id',
        'dir'              => 'ltr',
        'categories'       => [ 'education', 'utilities' ],
        'icons'            => [
            [
                'src'     => qrg_sekolah_pwa_url( 'icons/192.png' ),
                'sizes'   => '192x192',
                'type'    => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src'     => qrg_sekolah_pwa_url( 'icons/512.png' ),
                'sizes'   => '512x512',
                'type'    => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src'     => qrg_sekolah_pwa_url( 'icons/512.png' ),
                'sizes'   => '512x512',
                'type'    => 'image/png',
                'purpose' => 'maskable',
            ],
        ],
        'shortcuts'        => [
            [
                'name'        => 'Pembayaran',
                'short_name'  => 'Bayar',
                'description' => 'Lihat tagihan sekolah',
                'url'         => home_url( '/sekolah/pembayaran/?source=pwa' ),
                'icons'       => [
                    [
                        'src'   => qrg_sekolah_pwa_url( 'icons/192.png' ),
                        'sizes' => '192x192',
                    ],
                ],
            ],
        ],
    ];

    nocache_headers();
    header( 'Content-Type: application/manifest+json; charset=utf-8' );
    header( 'X-Content-Type-Options: nosniff' );
    echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
}

/**
 * Service Worker.
 */
function qrg_sekolah_pwa_serve_sw() {
    global $wp_query;
    $file = QRG_PLUGIN_DIR . 'assets/pwa/sw.js';
    if ( ! is_readable( $file ) ) {
        status_header( 404 );
        exit;
    }
    if ( $wp_query ) {
        $wp_query->is_404 = false;
    }
    status_header( 200 );
    header( 'Content-Type: application/javascript; charset=utf-8' );
    header( 'Service-Worker-Allowed: /sekolah/' );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );
    header( 'X-Content-Type-Options: nosniff' );
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo file_get_contents( $file );
}

/**
 * Ikon PNG.
 *
 * @param string $size Ukuran ikon.
 */
function qrg_sekolah_pwa_serve_icon( $size ) {
    global $wp_query;
    $file = qrg_sekolah_pwa_icon_file( $size );
    if ( $file === '' || ! is_readable( $file ) ) {
        status_header( 404 );
        exit;
    }
    if ( $wp_query ) {
        $wp_query->is_404 = false;
    }
    status_header( 200 );
    header( 'Content-Type: image/png' );
    header( 'Cache-Control: public, max-age=604800, immutable' );
    header( 'X-Content-Type-Options: nosniff' );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    readfile( $file );
}

/**
 * Halaman offline (fallback PWA).
 */
function qrg_sekolah_pwa_serve_offline() {
    global $wp_query;
    if ( $wp_query ) {
        $wp_query->is_404 = false;
    }
    $site = get_bloginfo( 'name' );
    $hub  = home_url( '/sekolah/' );
    status_header( 200 );
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'Cache-Control: public, max-age=3600' );
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#F2F2F7">
    <title>Offline — <?php echo esc_html( $site ); ?></title>
    <style>
        :root {
            --ios-bg: #F2F2F7; --ios-card: #fff; --ios-ink: #1C1C1E;
            --ios-secondary: #8E8E93; --ios-blue: #007AFF;
            --ios-font: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100dvh; display: flex; align-items: center; justify-content: center;
            font-family: var(--ios-font); background: var(--ios-bg); color: var(--ios-ink);
            padding: 24px; -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%; max-width: 360px; background: var(--ios-card); border-radius: 20px;
            padding: 28px 22px; text-align: center; box-shadow: 0 8px 28px rgba(0,0,0,.06);
        }
        .ico {
            width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 16px;
            background: linear-gradient(180deg, #64D2FF, #007AFF); color: #fff;
            display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 700;
        }
        h1 { margin: 0 0 8px; font-size: 1.35rem; letter-spacing: -.02em; }
        p { margin: 0 0 20px; color: var(--ios-secondary); font-size: 15px; line-height: 1.45; }
        a {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 44px; padding: 12px 22px; border-radius: 980px;
            background: var(--ios-blue); color: #fff; text-decoration: none; font-weight: 600; font-size: 15px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="ico" aria-hidden="true">S</div>
        <h1>Anda sedang offline</h1>
        <p>Periksa koneksi internet, lalu coba buka Portal Sekolah lagi.</p>
        <a href="<?php echo esc_url( $hub ); ?>">Coba lagi</a>
    </div>
</body>
</html>
    <?php
}

/**
 * Meta + link PWA untuk head shell portal.
 *
 * @return string HTML
 */
function qrg_sekolah_pwa_head_tags() {
    $manifest = esc_url( qrg_sekolah_pwa_url( 'manifest.webmanifest' ) );
    $icon180  = esc_url( qrg_sekolah_pwa_url( 'icons/180.png' ) );
    $icon32   = esc_url( qrg_sekolah_pwa_url( 'icons/32.png' ) );
    $site     = esc_attr( wp_strip_all_tags( get_bloginfo( 'name' ) ) );

    return ''
        . '<link rel="manifest" href="' . $manifest . '">'
        . '<link rel="apple-touch-icon" href="' . $icon180 . '">'
        . '<link rel="icon" type="image/png" sizes="32x32" href="' . $icon32 . '">'
        . '<meta name="apple-mobile-web-app-title" content="Sekolah">'
        . '<meta name="application-name" content="Portal Sekolah">'
        . '<meta name="apple-mobile-web-app-capable" content="yes">'
        . '<meta name="mobile-web-app-capable" content="yes">'
        . '<meta name="apple-mobile-web-app-status-bar-style" content="default">'
        . '<meta name="format-detection" content="telephone=no">'
        . '<!-- ' . $site . ' PWA -->';
}

/**
 * Banner instal + registrasi service worker (HTML/JS di akhir shell).
 */
function qrg_sekolah_pwa_boot_script() {
    $sw_url = esc_url( qrg_sekolah_pwa_url( 'sw.js' ) );
    $scope  = trailingslashit( (string) wp_parse_url( home_url( '/sekolah/' ), PHP_URL_PATH ) );
    if ( $scope === '' || $scope === '/' ) {
        $scope = '/sekolah/';
    }
    ?>
    <div class="qrg-pwa-banner" id="qrg-pwa-banner" hidden>
        <div class="qrg-pwa-banner__icon" aria-hidden="true">S</div>
        <div class="qrg-pwa-banner__body">
            <strong>Pasang Portal Sekolah</strong>
            <span id="qrg-pwa-banner-text">Akses cepat seperti aplikasi di layar utama.</span>
        </div>
        <button type="button" class="qrg-pwa-banner__btn" id="qrg-pwa-install">Pasang</button>
        <button type="button" class="qrg-pwa-banner__close" id="qrg-pwa-dismiss" aria-label="Tutup">&times;</button>
    </div>
    <style>
        .qrg-pwa-banner {
            position: fixed; left: 12px; right: 12px; bottom: calc(12px + env(safe-area-inset-bottom, 0px));
            z-index: 10050; max-width: 406px; margin: 0 auto;
            display: flex; align-items: center; gap: 10px;
            padding: 12px 12px 12px 14px;
            background: rgba(28,28,30,.92); color: #fff;
            border-radius: 16px; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 12px 40px rgba(0,0,0,.28);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif;
        }
        body:has(.has-tabbar) .qrg-pwa-banner {
            bottom: calc(12px + 52px + env(safe-area-inset-bottom, 0px));
        }
        .qrg-pwa-banner[hidden] { display: none !important; }
        .qrg-pwa-banner__icon {
            width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
            background: linear-gradient(180deg, #64D2FF, #007AFF);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 18px;
        }
        .qrg-pwa-banner__body { flex: 1; min-width: 0; }
        .qrg-pwa-banner__body strong { display: block; font-size: 14px; font-weight: 600; }
        .qrg-pwa-banner__body span { display: block; font-size: 12px; color: rgba(255,255,255,.7); margin-top: 2px; line-height: 1.3; }
        .qrg-pwa-banner__btn {
            appearance: none; border: 0; border-radius: 980px;
            background: #007AFF; color: #fff; font-weight: 600; font-size: 13px;
            padding: 8px 14px; min-height: 32px; cursor: pointer; flex-shrink: 0;
        }
        .qrg-pwa-banner__close {
            appearance: none; border: 0; background: transparent; color: rgba(255,255,255,.55);
            font-size: 22px; line-height: 1; padding: 4px 2px; cursor: pointer; flex-shrink: 0;
        }
        @media (display-mode: standalone), (display-mode: fullscreen) {
            .qrg-pwa-banner { display: none !important; }
        }
    </style>
    <script>
    (function () {
        var swUrl = <?php echo wp_json_encode( $sw_url ); ?>;
        var swScope = <?php echo wp_json_encode( $scope ); ?>;
        var banner = document.getElementById('qrg-pwa-banner');
        var btnInstall = document.getElementById('qrg-pwa-install');
        var btnDismiss = document.getElementById('qrg-pwa-dismiss');
        var textEl = document.getElementById('qrg-pwa-banner-text');
        var deferred = null;
        var DISMISS_KEY = 'qrg_pwa_dismiss_v1';

        function isStandalone() {
            return window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;
        }

        function dismissed() {
            try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
        }

        function showBanner(iosHint) {
            if (!banner || isStandalone() || dismissed()) return;
            if (iosHint && textEl) {
                textEl.textContent = 'Di Safari: Bagikan → Tambah ke Layar Utama';
                if (btnInstall) btnInstall.textContent = 'Cara';
            }
            banner.hidden = false;
        }

        function hideBanner(persist) {
            if (banner) banner.hidden = true;
            if (persist) {
                try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) {}
            }
        }

        if (btnDismiss) {
            btnDismiss.addEventListener('click', function () { hideBanner(true); });
        }

        if (btnInstall) {
            btnInstall.addEventListener('click', function () {
                if (deferred) {
                    deferred.prompt();
                    deferred.userChoice.finally(function () {
                        deferred = null;
                        hideBanner(true);
                    });
                    return;
                }
                if (textEl) {
                    textEl.textContent = 'Safari: tombol Bagikan, lalu “Tambah ke Layar Utama”.';
                }
                if (btnInstall) btnInstall.style.display = 'none';
            });
        }

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            deferred = e;
            showBanner(false);
        });

        window.addEventListener('appinstalled', function () {
            hideBanner(true);
        });

        var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent || '');
        if (isIOS && !isStandalone() && !dismissed()) {
            setTimeout(function () { showBanner(true); }, 1800);
        }

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register(swUrl, { scope: swScope }).catch(function () {});
            });
        }
    })();
    </script>
    <?php
}
