<?php
/**
 * Kartu Tanda Siswa (KTS) Digital & Generator QR — portal /sekolah/?tab=akun
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Data ringkas siswa untuk KTS / QR.
 *
 * @param int $siswa_id Post ID master_siswa.
 * @return array<string,mixed>
 */
function qrg_kts_get_siswa_data( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $nama     = get_the_title( $siswa_id );
    $nisn     = (string) get_post_meta( $siswa_id, '_siswa_nisn', true );
    $nis      = (string) get_post_meta( $siswa_id, '_siswa_nis', true );
    $kelas_raw = get_post_meta( $siswa_id, '_siswa_kelas', true );
    $kelas    = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( $kelas_raw )
        : (string) $kelas_raw;
    $tgl_lahir = (string) get_post_meta( $siswa_id, '_siswa_tgl_lahir', true );
    $tempat    = (string) get_post_meta( $siswa_id, '_siswa_tempat_lahir', true );
    $alamat    = (string) get_post_meta( $siswa_id, '_siswa_alamat', true );
    $foto      = '';
    if ( function_exists( 'qrg_get_siswa_avatar_url' ) ) {
        $foto = (string) qrg_get_siswa_avatar_url( $siswa_id, 'medium' );
    }
    if ( $foto === '' && has_post_thumbnail( $siswa_id ) ) {
        $foto = (string) get_the_post_thumbnail_url( $siswa_id, 'medium' );
    }

    $tahun = (int) wp_date( 'Y' );
    $tahun_ajaran = $tahun . '/' . ( $tahun + 1 );

    return [
        'id'           => $siswa_id,
        'nama'         => $nama,
        'nisn'         => $nisn,
        'nis'          => $nis !== '' ? $nis : $nisn,
        'kelas'        => $kelas,
        'tempat_lahir' => $tempat,
        'tgl_lahir'    => $tgl_lahir,
        'alamat'       => $alamat,
        'foto'         => $foto,
        'sekolah'      => get_bloginfo( 'name' ),
        'tahun_ajaran' => $tahun_ajaran,
        'kode'         => 'KTS-' . str_pad( (string) $siswa_id, 6, '0', STR_PAD_LEFT ),
    ];
}

/**
 * Teks payload QR KTS.
 *
 * @param int $siswa_id Post ID.
 * @return string
 */
function qrg_kts_qr_info_text( $siswa_id ) {
    $d = qrg_kts_get_siswa_data( $siswa_id );
    $lines = [
        'KARTU TANDA SISWA DIGITAL',
        'Sekolah: ' . $d['sekolah'],
        'Kode: ' . $d['kode'],
        'Nama: ' . $d['nama'],
    ];
    if ( $d['nisn'] !== '' ) {
        $lines[] = 'NISN: ' . $d['nisn'];
    }
    if ( $d['nis'] !== '' && $d['nis'] !== $d['nisn'] ) {
        $lines[] = 'NIS: ' . $d['nis'];
    }
    if ( $d['kelas'] !== '' ) {
        $lines[] = 'Kelas: ' . $d['kelas'];
    }
    if ( $d['tempat_lahir'] !== '' || $d['tgl_lahir'] !== '' ) {
        $ttl = trim( $d['tempat_lahir'] . ( $d['tempat_lahir'] && $d['tgl_lahir'] ? ', ' : '' ) . $d['tgl_lahir'] );
        $lines[] = 'TTL: ' . $ttl;
    }
    $lines[] = 'TA: ' . $d['tahun_ajaran'];
    return implode( "\n", $lines );
}

/**
 * URL gambar QR via layanan publik.
 *
 * @param int $siswa_id Post ID.
 * @param int $size     Ukuran px.
 * @return string
 */
function qrg_kts_qr_image_url( $siswa_id, $size = 220 ) {
    $info = qrg_kts_qr_info_text( $siswa_id );
    $size = max( 80, min( 1024, (int) $size ) );
    return add_query_arg(
        [
            'size'   => $size . 'x' . $size,
            'data'   => rawurlencode( $info ),
            'margin' => 1,
        ],
        'https://api.qrserver.com/v1/create-qr-code/'
    );
}

/**
 * URL aksi lihat / unduh KTS di portal akun.
 *
 * @param int    $siswa_id Post ID.
 * @param string $action   lihat|unduh|cetak
 * @return string
 */
function qrg_kts_action_url( $siswa_id, $action = 'lihat' ) {
    $siswa_id = (int) $siswa_id;
    $action   = sanitize_key( $action );
    $base     = add_query_arg( 'tab', 'akun', function_exists( 'qrg_get_sekolah_portal_url' ) ? qrg_get_sekolah_portal_url() : home_url( '/sekolah/' ) );
    $url      = add_query_arg(
        [
            'kts' => $action,
            'id'  => $siswa_id,
        ],
        $base
    );
    return wp_nonce_url( $url, 'qrg_kts_' . $siswa_id );
}

/**
 * Proses request unduh/cetak KTS (dipanggil dari portal hub).
 * Lihat kartu sekarang inline di tab akun (bukan halaman terpisah).
 *
 * @param int $auth_siswa_id Siswa yang sedang login.
 * @return bool True jika request ditangani (exit).
 */
function qrg_kts_maybe_handle_request( $auth_siswa_id ) {
    $auth_siswa_id = (int) $auth_siswa_id;
    if ( $auth_siswa_id <= 0 ) {
        return false;
    }
    if ( empty( $_GET['kts'] ) || empty( $_GET['tab'] ) || sanitize_key( wp_unslash( $_GET['tab'] ) ) !== 'akun' ) {
        return false;
    }

    $action   = sanitize_key( wp_unslash( $_GET['kts'] ) );
    $siswa_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : $auth_siswa_id;

    // "lihat" ditampilkan inline di tab akun — tidak intercept.
    if ( $action === 'lihat' ) {
        return false;
    }

    if ( $siswa_id !== $auth_siswa_id ) {
        wp_die( 'Anda tidak diizinkan melihat kartu siswa ini.', 'Akses ditolak', [ 'response' => 403 ] );
    }
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qrg_kts_' . $siswa_id ) ) {
        wp_die( 'Token tidak valid. Buka ulang dari menu Akun.', 'Token tidak valid', [ 'response' => 403 ] );
    }
    if ( get_post_type( $siswa_id ) !== 'master_siswa' ) {
        wp_die( 'Data siswa tidak ditemukan.' );
    }

    if ( $action === 'unduh' ) {
        qrg_kts_stream_qr_download( $siswa_id );
        return true;
    }
    if ( $action === 'cetak' ) {
        qrg_kts_render_fullscreen( $siswa_id, true );
        return true;
    }

    return false;
}

/**
 * Unduh PNG QR KTS.
 *
 * @param int $siswa_id Post ID.
 */
function qrg_kts_stream_qr_download( $siswa_id ) {
    $d        = qrg_kts_get_siswa_data( $siswa_id );
    $qr_url   = qrg_kts_qr_image_url( $siswa_id, 512 );
    $filename = sanitize_file_name( 'kts-qr-' . $d['kode'] . '.png' );

    $response = wp_remote_get( $qr_url, [ 'timeout' => 25 ] );
    if ( is_wp_error( $response ) ) {
        wp_die( 'Gagal mengunduh QR Code. Coba lagi.' );
    }
    $body = wp_remote_retrieve_body( $response );
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 || $body === '' ) {
        wp_die( 'QR Code tidak tersedia. Coba lagi.' );
    }

    nocache_headers();
    header( 'Content-Type: image/png' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $body ) );
    echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}

/**
 * Halaman lihat / cetak KTS — memakai shell portal /sekolah/.
 *
 * @param int  $siswa_id Post ID.
 * @param bool $auto_print Auto buka dialog print.
 */
function qrg_kts_render_fullscreen( $siswa_id, $auto_print = false ) {
    $d         = qrg_kts_get_siswa_data( $siswa_id );
    $qr_url    = qrg_kts_qr_image_url( $siswa_id, 280 );
    $unduh_url = qrg_kts_action_url( $siswa_id, 'unduh' );
    $cetak_url = qrg_kts_action_url( $siswa_id, 'cetak' );
    $akun_url  = add_query_arg( 'tab', 'akun', function_exists( 'qrg_get_sekolah_portal_url' ) ? qrg_get_sekolah_portal_url() : home_url( '/sekolah/' ) );
    $initial   = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $d['nama'], 0, 1 ) )
        : strtoupper( substr( (string) $d['nama'], 0, 1 ) );

    $extra = '<style>
        .qrg-kts-page .qrg-kts-card {
            position: relative; overflow: hidden; border-radius: 18px;
            background: linear-gradient(145deg, #007AFF 0%, #5856D6 55%, #30B0C7 100%);
            color: #fff; padding: 16px; margin-bottom: 12px;
            box-shadow: 0 12px 28px rgba(0,122,255,.22);
        }
        .qrg-kts-page .qrg-kts-card::after {
            content: ""; position: absolute; right: -36px; top: -46px;
            width: 130px; height: 130px; border-radius: 50%;
            border: 24px solid rgba(255,255,255,.08); pointer-events: none;
        }
        .qrg-kts-page .qrg-kts-card__top {
            position: relative; z-index: 1; display: flex; align-items: center; gap: 10px; margin-bottom: 12px;
        }
        .qrg-kts-page .qrg-kts-card__badge {
            width: 38px; height: 38px; border-radius: 11px; background: rgba(255,255,255,.16);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; letter-spacing: .04em;
        }
        .qrg-kts-page .qrg-kts-card__eyebrow {
            margin: 0; font-size: 10px; font-weight: 800; letter-spacing: .1em;
            text-transform: uppercase; color: #FFE566;
        }
        .qrg-kts-page .qrg-kts-card__school { font-size: 12px; opacity: .92; line-height: 1.25; }
        .qrg-kts-page .qrg-kts-card__grid {
            position: relative; z-index: 1; display: grid; grid-template-columns: 78px 1fr; gap: 12px;
        }
        .qrg-kts-page .qrg-kts-card__photo {
            width: 78px; height: 96px; border-radius: 12px; overflow: hidden;
            border: 2px solid rgba(255,255,255,.35); background: rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 800;
        }
        .qrg-kts-page .qrg-kts-card__photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .qrg-kts-page .qrg-kts-card__name { margin: 0 0 8px; font-size: 16px; line-height: 1.25; font-weight: 800; }
        .qrg-kts-page .qrg-kts-card__row { margin: 0 0 4px; font-size: 12px; }
        .qrg-kts-page .qrg-kts-card__row span { opacity: .78; }
        .qrg-kts-page .qrg-kts-card__foot {
            position: relative; z-index: 1; margin-top: 14px; padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
        }
        .qrg-kts-page .qrg-kts-card__code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px; font-weight: 700; letter-spacing: .03em;
        }
        .qrg-kts-page .qrg-kts-card__hint { font-size: 11px; opacity: .85; margin-top: 3px; }
        .qrg-kts-page .qrg-kts-card__qr {
            width: 72px; height: 72px; border-radius: 10px; background: #fff; padding: 5px; flex: 0 0 auto;
        }
        .qrg-kts-page .qrg-kts-card__qr img { width: 100%; height: 100%; display: block; }
        .qrg-kts-page .qrg-kts-actions {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 4px;
        }
        .qrg-kts-page .qrg-kts-actions a,
        .qrg-kts-page .qrg-kts-actions button {
            display: block; width: 100%; text-align: center; text-decoration: none;
            border-radius: 12px; padding: 11px 10px; font-size: 13px; font-weight: 700;
            background: var(--ios-card); color: var(--ios-ink); border: 0;
            box-shadow: 0 1px 0 rgba(0,0,0,.04); cursor: pointer; font-family: inherit;
        }
        .qrg-kts-page .qrg-kts-actions a:active,
        .qrg-kts-page .qrg-kts-actions button:active { background: #E5E5EA; }
        .qrg-kts-page .qrg-kts-actions .primary {
            background: var(--ios-blue); color: #fff; grid-column: 1 / -1;
        }
        .qrg-kts-page .qrg-kts-qr-panel {
            background: var(--ios-card); border-radius: var(--ios-radius);
            padding: 14px; box-shadow: var(--ios-shadow);
        }
        .qrg-kts-page .qrg-kts-qr-panel h2 {
            margin: 0 0 8px; font-size: 14px; font-weight: 700;
        }
        .qrg-kts-page .qrg-kts-qr-panel pre {
            margin: 0; white-space: pre-wrap; word-break: break-word;
            font-size: 12px; line-height: 1.55; color: var(--ios-ink);
            background: var(--ios-bg); border-radius: 12px; padding: 12px;
        }
        .qrg-kts-page .qrg-kts-back {
            display: inline-flex; align-items: center; gap: 4px;
            color: var(--ios-blue); text-decoration: none; font-size: 15px; font-weight: 600;
            margin-bottom: 8px;
        }
        @media print {
            .ios-tabbar, .qrg-kts-actions, .qrg-kts-qr-panel, .qrg-kts-back, .ios-hero .brand, .ios-hero .sub { display: none !important; }
            .qrg-sk-app.has-tabbar .qrg-sk { padding-bottom: 0 !important; }
            body, .qrg-sk-app { background: #fff !important; }
            .qrg-kts-page .qrg-kts-card { box-shadow: none; border: 1px solid #c7c7cc; }
        }
    </style>';

    if ( function_exists( 'qrg_sekolah_portal_shell_start' ) ) {
        qrg_sekolah_portal_shell_start( 'Kartu Tanda Siswa', $extra, 'akun' );
    } else {
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $extra . '</head><body>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>
    <div class="qrg-sk qrg-kts-page">
        <a class="qrg-kts-back" href="<?php echo esc_url( $akun_url ); ?>">← Kembali ke Akun</a>
        <header class="ios-hero">
            <p class="brand">Portal Siswa</p>
            <h1>Kartu Tanda Siswa</h1>
            <p class="sub">KTS Digital &amp; QR identitas</p>
        </header>

        <section class="ios-section">
            <p class="ios-section__label">Kartu Digital</p>
            <article class="qrg-kts-card" aria-label="Kartu Tanda Siswa Digital">
                <div class="qrg-kts-card__top">
                    <div class="qrg-kts-card__badge">KTS</div>
                    <div>
                        <p class="qrg-kts-card__eyebrow">Kartu Tanda Siswa Digital</p>
                        <div class="qrg-kts-card__school"><?php echo esc_html( $d['sekolah'] ); ?></div>
                    </div>
                </div>
                <div class="qrg-kts-card__grid">
                    <div class="qrg-kts-card__photo">
                        <?php if ( $d['foto'] ) : ?>
                            <img src="<?php echo esc_url( $d['foto'] ); ?>" alt="">
                        <?php else : ?>
                            <?php echo esc_html( $initial !== '' ? $initial : 'S' ); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 class="qrg-kts-card__name"><?php echo esc_html( $d['nama'] ); ?></h2>
                        <?php if ( $d['nisn'] !== '' ) : ?>
                            <p class="qrg-kts-card__row"><span>NISN</span> · <strong><?php echo esc_html( $d['nisn'] ); ?></strong></p>
                        <?php endif; ?>
                        <?php if ( $d['nis'] !== '' && $d['nis'] !== $d['nisn'] ) : ?>
                            <p class="qrg-kts-card__row"><span>NIS</span> · <strong><?php echo esc_html( $d['nis'] ); ?></strong></p>
                        <?php endif; ?>
                        <?php if ( $d['kelas'] !== '' ) : ?>
                            <p class="qrg-kts-card__row"><span>Kelas</span> · <strong><?php echo esc_html( $d['kelas'] ); ?></strong></p>
                        <?php endif; ?>
                        <p class="qrg-kts-card__row"><span>TA</span> · <strong><?php echo esc_html( $d['tahun_ajaran'] ); ?></strong></p>
                    </div>
                </div>
                <div class="qrg-kts-card__foot">
                    <div>
                        <div class="qrg-kts-card__code"><?php echo esc_html( $d['kode'] ); ?></div>
                        <div class="qrg-kts-card__hint">Scan QR untuk verifikasi identitas</div>
                    </div>
                    <div class="qrg-kts-card__qr">
                        <img src="<?php echo esc_url( $qr_url ); ?>" alt="QR KTS <?php echo esc_attr( $d['kode'] ); ?>">
                    </div>
                </div>
            </article>

            <div class="qrg-kts-actions">
                <button type="button" class="primary" onclick="window.print()">Cetak / Bagikan</button>
                <a href="<?php echo esc_url( $unduh_url ); ?>">Unduh QR Code</a>
                <?php if ( ! $auto_print ) : ?>
                    <a href="<?php echo esc_url( $cetak_url ); ?>">Mode Cetak</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="ios-section">
            <p class="ios-section__label">Isi QR Code</p>
            <div class="qrg-kts-qr-panel">
                <h2>Data identitas</h2>
                <pre><?php echo esc_html( qrg_kts_qr_info_text( $siswa_id ) ); ?></pre>
            </div>
        </section>
    </div>
    <?php
    if ( $auto_print ) {
        echo '<script>window.addEventListener("load",function(){window.print();});</script>';
    }
    if ( function_exists( 'qrg_sekolah_portal_shell_end' ) ) {
        qrg_sekolah_portal_shell_end();
    } else {
        echo '</body></html>';
    }
    exit;
}

/**
 * Menu KTS + QR Code di tab akun — expand langsung di bawah.
 *
 * @param int $siswa_id Post ID siswa login.
 */
function qrg_kts_render_akun_section( $siswa_id ) {
    $siswa_id  = (int) $siswa_id;
    $d         = qrg_kts_get_siswa_data( $siswa_id );
    $qr_url    = qrg_kts_qr_image_url( $siswa_id, 280 );
    $qr_sm     = qrg_kts_qr_image_url( $siswa_id, 160 );
    $unduh_url = qrg_kts_action_url( $siswa_id, 'unduh' );
    $cetak_url = qrg_kts_action_url( $siswa_id, 'cetak' );
    $initial   = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $d['nama'], 0, 1 ) )
        : strtoupper( substr( (string) $d['nama'], 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }

    $open = isset( $_GET['kts'] ) ? sanitize_key( wp_unslash( $_GET['kts'] ) ) : '';
    $kts_open = ( $open === 'lihat' || $open === 'kts' );
    $qr_open  = ( $open === 'qr' || $open === 'qrcode' );
    ?>
    <style>
        .ios-icon--kts { background: linear-gradient(180deg, #64D2FF, #5856D6); }
        .ios-icon--qr { background: linear-gradient(180deg, #30D158, #0086F0); }
        .qrg-sk-card--toggle {
            width: 100%; text-align: left; cursor: pointer; font: inherit;
            position: relative;
        }
        .qrg-sk-card--toggle::after {
            content: "";
            width: 8px; height: 8px; margin-left: auto; flex-shrink: 0;
            border-right: 2px solid #C7C7CC; border-bottom: 2px solid #C7C7CC;
            transform: rotate(45deg); transition: transform .22s ease;
            align-self: center;
        }
        .qrg-sk-card--toggle.is-open::after {
            transform: rotate(225deg); margin-top: 4px;
        }
        .qrg-kts-inline-panel,
        .qrg-qr-inline-panel {
            display: grid;
            grid-template-rows: 0fr;
            opacity: 0;
            transform: translateY(-6px);
            transition: grid-template-rows .32s cubic-bezier(.2,.8,.2,1),
                opacity .28s ease, transform .32s cubic-bezier(.2,.8,.2,1);
        }
        .qrg-kts-inline-panel.is-open,
        .qrg-qr-inline-panel.is-open {
            grid-template-rows: 1fr;
            opacity: 1;
            transform: translateY(0);
        }
        .qrg-kts-inline-panel__inner,
        .qrg-qr-inline-panel__inner {
            overflow: hidden; min-height: 0;
        }
        .qrg-kts-inline-card {
            position: relative; overflow: hidden; border-radius: 18px;
            background: linear-gradient(145deg, #007AFF 0%, #5856D6 55%, #30B0C7 100%);
            color: #fff; padding: 16px; margin-top: 10px;
            box-shadow: 0 12px 28px rgba(0,122,255,.22);
        }
        .qrg-kts-inline-card::after {
            content: ""; position: absolute; right: -36px; top: -46px;
            width: 130px; height: 130px; border-radius: 50%;
            border: 24px solid rgba(255,255,255,.08); pointer-events: none;
        }
        .qrg-kts-inline-card__top {
            position: relative; z-index: 1; display: flex; align-items: center; gap: 10px; margin-bottom: 12px;
        }
        .qrg-kts-inline-card__badge {
            width: 38px; height: 38px; border-radius: 11px; background: rgba(255,255,255,.16);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; letter-spacing: .04em;
        }
        .qrg-kts-inline-card__eyebrow {
            margin: 0; font-size: 10px; font-weight: 800; letter-spacing: .1em;
            text-transform: uppercase; color: #FFE566;
        }
        .qrg-kts-inline-card__school { font-size: 12px; opacity: .92; line-height: 1.25; }
        .qrg-kts-inline-card__grid {
            position: relative; z-index: 1; display: grid; grid-template-columns: 78px 1fr; gap: 12px;
        }
        .qrg-kts-inline-card__photo {
            width: 78px; height: 96px; border-radius: 12px; overflow: hidden;
            border: 2px solid rgba(255,255,255,.35); background: rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 800;
        }
        .qrg-kts-inline-card__photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .qrg-kts-inline-card__name { margin: 0 0 8px; font-size: 16px; line-height: 1.25; font-weight: 800; }
        .qrg-kts-inline-card__row { margin: 0 0 4px; font-size: 12px; }
        .qrg-kts-inline-card__row span { opacity: .78; }
        .qrg-kts-inline-card__foot {
            position: relative; z-index: 1; margin-top: 14px; padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
        }
        .qrg-kts-inline-card__code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px; font-weight: 700; letter-spacing: .03em;
        }
        .qrg-kts-inline-card__hint { font-size: 11px; opacity: .85; margin-top: 3px; }
        .qrg-kts-inline-card__qr {
            width: 72px; height: 72px; border-radius: 10px; background: #fff; padding: 5px; flex: 0 0 auto;
        }
        .qrg-kts-inline-card__qr img { width: 100%; height: 100%; display: block; }
        .qrg-kts-inline-actions {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px;
        }
        .qrg-kts-inline-actions a,
        .qrg-kts-inline-actions button {
            display: block; width: 100%; text-align: center; text-decoration: none;
            border-radius: 12px; padding: 11px 10px; font-size: 13px; font-weight: 700;
            background: var(--ios-card); color: var(--ios-ink); border: 0;
            box-shadow: var(--ios-shadow); cursor: pointer; font-family: inherit;
        }
        .qrg-kts-inline-actions a:active,
        .qrg-kts-inline-actions button:active { background: #E5E5EA; }
        .qrg-kts-inline-actions .primary {
            background: var(--ios-blue); color: #fff; grid-column: 1 / -1;
        }
        .qrg-qr-box {
            background: var(--ios-card); border-radius: var(--ios-radius);
            padding: 18px 16px; margin-top: 10px; box-shadow: var(--ios-shadow);
            text-align: center;
        }
        .qrg-qr-box__frame {
            width: 200px; height: 200px; margin: 0 auto 12px;
            border-radius: 16px; background: #fff;
            border: 1px solid var(--ios-sep);
            padding: 12px; box-sizing: border-box;
        }
        .qrg-qr-box__frame img { width: 100%; height: 100%; display: block; }
        .qrg-qr-box__code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px; font-weight: 700; color: var(--ios-ink); margin: 0 0 4px;
        }
        .qrg-qr-box__hint {
            margin: 0 0 14px; font-size: 13px; color: var(--ios-secondary);
        }
        .qrg-qr-box__actions {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;
        }
        .qrg-qr-box__actions a {
            display: flex; align-items: center; justify-content: center;
            text-align: center; text-decoration: none;
            border-radius: 12px; padding: 12px 10px; min-height: 44px;
            font-size: 13px; font-weight: 700; box-sizing: border-box;
            background: rgba(0,122,255,.1); color: var(--ios-blue);
        }
        .qrg-qr-box__actions a.primary {
            background: var(--ios-blue); color: #fff;
        }
        .qrg-qr-box__actions a:active { opacity: .85; }
        @media (prefers-reduced-motion: reduce) {
            .qrg-kts-inline-panel, .qrg-qr-inline-panel, .qrg-sk-card--toggle::after {
                transition: none !important;
            }
        }
        @media print {
            body * { visibility: hidden; }
            .qrg-kts-inline-panel.is-open,
            .qrg-kts-inline-panel.is-open * { visibility: visible; }
            .qrg-kts-inline-panel.is-open {
                position: absolute; left: 0; top: 0; width: 100%;
                display: block !important; opacity: 1 !important;
                transform: none !important;
            }
            .qrg-kts-inline-actions { display: none !important; }
        }
    </style>
    <section class="ios-section" id="qrg-kts-akun">
        <p class="ios-section__label">Kartu &amp; QR</p>
        <div class="qrg-sk-menu">
            <button type="button"
                class="qrg-sk-card qrg-sk-card--toggle<?php echo $kts_open ? ' is-open' : ''; ?>"
                id="qrg-kts-toggle"
                aria-expanded="<?php echo $kts_open ? 'true' : 'false'; ?>"
                aria-controls="qrg-kts-panel">
                <span class="ios-icon ios-icon--kts" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2.5"/><path d="M14 10h5M14 14h3"/></svg>
                </span>
                <div>
                    <strong>KTS</strong>
                    <span>Kartu Tanda Siswa digital</span>
                </div>
            </button>
        </div>
        <div class="qrg-kts-inline-panel<?php echo $kts_open ? ' is-open' : ''; ?>" id="qrg-kts-panel">
            <div class="qrg-kts-inline-panel__inner">
                <article class="qrg-kts-inline-card" aria-label="Kartu Tanda Siswa Digital">
                    <div class="qrg-kts-inline-card__top">
                        <div class="qrg-kts-inline-card__badge">KTS</div>
                        <div>
                            <p class="qrg-kts-inline-card__eyebrow">Kartu Tanda Siswa Digital</p>
                            <div class="qrg-kts-inline-card__school"><?php echo esc_html( $d['sekolah'] ); ?></div>
                        </div>
                    </div>
                    <div class="qrg-kts-inline-card__grid">
                        <div class="qrg-kts-inline-card__photo">
                            <?php if ( $d['foto'] ) : ?>
                                <img src="<?php echo esc_url( $d['foto'] ); ?>" alt="">
                            <?php else : ?>
                                <?php echo esc_html( $initial ); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h2 class="qrg-kts-inline-card__name"><?php echo esc_html( $d['nama'] ); ?></h2>
                            <?php if ( $d['nisn'] !== '' ) : ?>
                                <p class="qrg-kts-inline-card__row"><span>NISN</span> · <strong><?php echo esc_html( $d['nisn'] ); ?></strong></p>
                            <?php endif; ?>
                            <?php if ( $d['nis'] !== '' && $d['nis'] !== $d['nisn'] ) : ?>
                                <p class="qrg-kts-inline-card__row"><span>NIS</span> · <strong><?php echo esc_html( $d['nis'] ); ?></strong></p>
                            <?php endif; ?>
                            <?php if ( $d['kelas'] !== '' ) : ?>
                                <p class="qrg-kts-inline-card__row"><span>Kelas</span> · <strong><?php echo esc_html( $d['kelas'] ); ?></strong></p>
                            <?php endif; ?>
                            <p class="qrg-kts-inline-card__row"><span>TA</span> · <strong><?php echo esc_html( $d['tahun_ajaran'] ); ?></strong></p>
                        </div>
                    </div>
                    <div class="qrg-kts-inline-card__foot">
                        <div>
                            <div class="qrg-kts-inline-card__code"><?php echo esc_html( $d['kode'] ); ?></div>
                            <div class="qrg-kts-inline-card__hint">Scan QR untuk verifikasi identitas</div>
                        </div>
                        <div class="qrg-kts-inline-card__qr">
                            <img src="<?php echo esc_url( $qr_sm ); ?>" alt="QR KTS <?php echo esc_attr( $d['kode'] ); ?>">
                        </div>
                    </div>
                </article>
                <div class="qrg-kts-inline-actions">
                    <button type="button" class="primary" id="qrg-kts-print-btn">Cetak / Bagikan</button>
                    <a href="<?php echo esc_url( $cetak_url ); ?>">Mode Cetak</a>
                    <a href="<?php echo esc_url( $unduh_url ); ?>">Unduh QR</a>
                </div>
            </div>
        </div>

        <div class="qrg-sk-menu" style="margin-top:10px;">
            <button type="button"
                class="qrg-sk-card qrg-sk-card--toggle<?php echo $qr_open ? ' is-open' : ''; ?>"
                id="qrg-qr-toggle"
                aria-expanded="<?php echo $qr_open ? 'true' : 'false'; ?>"
                aria-controls="qrg-qr-panel">
                <span class="ios-icon ios-icon--qr" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v6M14 20h3"/></svg>
                </span>
                <div>
                    <strong>QR Code</strong>
                    <span>Kode QR identitas siswa</span>
                </div>
            </button>
        </div>
        <div class="qrg-qr-inline-panel<?php echo $qr_open ? ' is-open' : ''; ?>" id="qrg-qr-panel">
            <div class="qrg-qr-inline-panel__inner">
                <div class="qrg-qr-box">
                    <div class="qrg-qr-box__frame">
                        <img src="<?php echo esc_url( $qr_url ); ?>" alt="QR Code <?php echo esc_attr( $d['kode'] ); ?>">
                    </div>
                    <p class="qrg-qr-box__code"><?php echo esc_html( $d['kode'] ); ?></p>
                    <p class="qrg-qr-box__hint"><?php echo esc_html( $d['nama'] ); ?><?php echo $d['kelas'] ? ' · Kelas ' . esc_html( $d['kelas'] ) : ''; ?></p>
                    <div class="qrg-qr-box__actions">
                        <a class="primary" href="<?php echo esc_url( $unduh_url ); ?>">Unduh QR</a>
                        <a href="<?php echo esc_url( $cetak_url ); ?>">Cetak</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <script>
    (function () {
        function bindToggle(btnId, panelId) {
            var btn = document.getElementById(btnId);
            var panel = document.getElementById(panelId);
            if (!btn || !panel) return;
            btn.addEventListener('click', function () {
                var open = !panel.classList.contains('is-open');
                panel.classList.toggle('is-open', open);
                btn.classList.toggle('is-open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    try { panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch (e) {}
                }
            });
        }
        bindToggle('qrg-kts-toggle', 'qrg-kts-panel');
        bindToggle('qrg-qr-toggle', 'qrg-qr-panel');

        var printBtn = document.getElementById('qrg-kts-print-btn');
        if (printBtn) {
            printBtn.addEventListener('click', function () {
                var panel = document.getElementById('qrg-kts-panel');
                var btn = document.getElementById('qrg-kts-toggle');
                if (panel && !panel.classList.contains('is-open')) {
                    panel.classList.add('is-open');
                    if (btn) {
                        btn.classList.add('is-open');
                        btn.setAttribute('aria-expanded', 'true');
                    }
                }
                window.print();
            });
        }
    })();
    </script>
    <?php
}
