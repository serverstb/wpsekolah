<?php
/**
 * Kritik & saran — portal siswa /sekolah/saran/
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'qrg_register_saran_siswa_cpt' );
function qrg_register_saran_siswa_cpt() {
    register_post_type( 'master_saran_siswa', [
        'labels' => [
            'name'               => 'Kritik & Saran',
            'singular_name'      => 'Kritik & Saran',
            'add_new'            => 'Tambah',
            'add_new_item'       => 'Tambah Kritik/Saran',
            'edit_item'          => 'Lihat Kritik/Saran',
            'new_item'           => 'Kritik/Saran Baru',
            'view_item'          => 'Lihat',
            'search_items'       => 'Cari Kritik & Saran',
            'not_found'          => 'Belum ada kritik/saran',
            'not_found_in_trash' => 'Tidak ada di tong sampah',
            'all_items'          => 'Kritik & Saran',
            'menu_name'          => 'Kritik & Saran',
        ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => 'master-data-plugin',
        'supports'        => [ 'title' ],
        'capability_type' => 'post',
        'map_meta_cap'    => true,
        'menu_position'   => 29,
    ] );
}

/**
 * @return string[]
 */
function qrg_saran_jenis_list() {
    return [ 'kritik', 'saran' ];
}

/**
 * @param string $jenis Jenis.
 * @return string
 */
function qrg_saran_jenis_label( $jenis ) {
    $jenis = sanitize_key( (string) $jenis );
    if ( $jenis === 'kritik' ) {
        return 'Kritik';
    }
    return 'Saran';
}

add_action( 'add_meta_boxes', 'qrg_saran_siswa_metaboxes' );
function qrg_saran_siswa_metaboxes() {
    add_meta_box(
        'qrg_saran_siswa_detail',
        'Detail Kritik & Saran',
        'qrg_saran_siswa_metabox_render',
        'master_saran_siswa',
        'normal',
        'high'
    );
}

/**
 * @param WP_Post $post Post.
 */
function qrg_saran_siswa_metabox_render( $post ) {
    wp_nonce_field( 'qrg_saran_admin_save', 'qrg_saran_admin_nonce' );
    $siswa_id = (int) get_post_meta( $post->ID, '_saran_siswa_id', true );
    $jenis    = (string) get_post_meta( $post->ID, '_saran_jenis', true );
    $isi      = (string) get_post_meta( $post->ID, '_saran_isi', true );
    $status   = (string) get_post_meta( $post->ID, '_saran_status', true );
    $balasan  = (string) get_post_meta( $post->ID, '_saran_balasan', true );
    if ( $status === '' ) {
        $status = 'Baru';
    }
    $siswa_list = get_posts( [
        'post_type'      => 'master_siswa',
        'posts_per_page' => 500,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="saran_siswa_id">Siswa</label></th>
            <td>
                <select name="saran_siswa_id" id="saran_siswa_id" style="min-width:280px;">
                    <option value="0">— Pilih —</option>
                    <?php foreach ( $siswa_list as $s ) : ?>
                        <option value="<?php echo (int) $s->ID; ?>" <?php selected( $siswa_id, (int) $s->ID ); ?>>
                            <?php echo esc_html( $s->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="saran_jenis">Jenis</label></th>
            <td>
                <select name="saran_jenis" id="saran_jenis">
                    <?php foreach ( qrg_saran_jenis_list() as $j ) : ?>
                        <option value="<?php echo esc_attr( $j ); ?>" <?php selected( $jenis, $j ); ?>>
                            <?php echo esc_html( qrg_saran_jenis_label( $j ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="saran_isi">Isi</label></th>
            <td>
                <textarea name="saran_isi" id="saran_isi" rows="6" class="large-text"><?php echo esc_textarea( $isi ); ?></textarea>
            </td>
        </tr>
        <tr>
            <th><label for="saran_status">Status</label></th>
            <td>
                <select name="saran_status" id="saran_status">
                    <?php foreach ( [ 'Baru', 'Dibaca', 'Ditindaklanjuti' ] as $st ) : ?>
                        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>>
                            <?php echo esc_html( $st ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="saran_balasan">Balasan admin</label></th>
            <td>
                <textarea name="saran_balasan" id="saran_balasan" rows="3" class="large-text" placeholder="Opsional"><?php echo esc_textarea( $balasan ); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_master_saran_siswa', 'qrg_saran_siswa_save_meta' );
function qrg_saran_siswa_save_meta( $post_id ) {
    if ( ! isset( $_POST['qrg_saran_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_saran_admin_nonce'] ) ), 'qrg_saran_admin_save' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    if ( isset( $_POST['saran_siswa_id'] ) ) {
        update_post_meta( $post_id, '_saran_siswa_id', absint( $_POST['saran_siswa_id'] ) );
    }
    if ( isset( $_POST['saran_jenis'] ) ) {
        $jenis = sanitize_key( wp_unslash( $_POST['saran_jenis'] ) );
        if ( ! in_array( $jenis, qrg_saran_jenis_list(), true ) ) {
            $jenis = 'saran';
        }
        update_post_meta( $post_id, '_saran_jenis', $jenis );
    }
    if ( isset( $_POST['saran_isi'] ) ) {
        update_post_meta( $post_id, '_saran_isi', sanitize_textarea_field( wp_unslash( $_POST['saran_isi'] ) ) );
    }
    if ( isset( $_POST['saran_status'] ) ) {
        $status = sanitize_text_field( wp_unslash( $_POST['saran_status'] ) );
        if ( ! in_array( $status, [ 'Baru', 'Dibaca', 'Ditindaklanjuti' ], true ) ) {
            $status = 'Baru';
        }
        update_post_meta( $post_id, '_saran_status', $status );
    }
    if ( isset( $_POST['saran_balasan'] ) ) {
        update_post_meta( $post_id, '_saran_balasan', sanitize_textarea_field( wp_unslash( $_POST['saran_balasan'] ) ) );
    }
}

add_filter( 'manage_master_saran_siswa_posts_columns', 'qrg_saran_siswa_columns' );
function qrg_saran_siswa_columns( $columns ) {
    return [
        'cb'     => $columns['cb'],
        'title'  => 'Judul',
        'siswa'  => 'Siswa',
        'jenis'  => 'Jenis',
        'status' => 'Status',
        'date'   => 'Tanggal',
    ];
}

add_action( 'manage_master_saran_siswa_posts_custom_column', 'qrg_saran_siswa_column_content', 10, 2 );
function qrg_saran_siswa_column_content( $column, $post_id ) {
    switch ( $column ) {
        case 'siswa':
            $sid = (int) get_post_meta( $post_id, '_saran_siswa_id', true );
            echo $sid ? esc_html( get_the_title( $sid ) ) : '—';
            break;
        case 'jenis':
            echo esc_html( qrg_saran_jenis_label( get_post_meta( $post_id, '_saran_jenis', true ) ) );
            break;
        case 'status':
            $st = (string) get_post_meta( $post_id, '_saran_status', true );
            echo esc_html( $st !== '' ? $st : 'Baru' );
            break;
    }
}

/**
 * @param int $siswa_id ID siswa.
 * @return WP_Post[]
 */
function qrg_saran_get_for_siswa( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    if ( $siswa_id <= 0 ) {
        return [];
    }
    return get_posts( [
        'post_type'      => 'master_saran_siswa',
        'posts_per_page' => 30,
        'post_status'    => 'publish',
        'meta_key'       => '_saran_siswa_id',
        'meta_value'     => $siswa_id,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
}

/**
 * @param int $siswa_id ID siswa.
 * @return array{error:string,success:string}
 */
function qrg_saran_process_submit( $siswa_id ) {
    $out      = [ 'error' => '', 'success' => '' ];
    $siswa_id = (int) $siswa_id;
    if ( $siswa_id <= 0 || empty( $_POST['qrg_saran_submit'] ) ) {
        return $out;
    }
    if ( ! isset( $_POST['qrg_saran_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_saran_nonce'] ) ), 'qrg_saran_' . $siswa_id ) ) {
        $out['error'] = 'Token tidak valid. Muat ulang halaman.';
        return $out;
    }

    $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'saran_' . $siswa_id, 6, 3600 ) : true;
    if ( is_wp_error( $rl ) ) {
        $out['error'] = $rl->get_error_message();
        return $out;
    }

    $jenis = isset( $_POST['saran_jenis'] ) ? sanitize_key( wp_unslash( $_POST['saran_jenis'] ) ) : 'saran';
    $isi   = isset( $_POST['saran_isi'] ) ? sanitize_textarea_field( wp_unslash( $_POST['saran_isi'] ) ) : '';
    if ( ! in_array( $jenis, qrg_saran_jenis_list(), true ) ) {
        $jenis = 'saran';
    }
    $isi = trim( $isi );
    if ( mb_strlen( $isi ) < 10 ) {
        $out['error'] = 'Isi kritik/saran minimal 10 karakter.';
        return $out;
    }
    if ( mb_strlen( $isi ) > 2000 ) {
        $out['error'] = 'Isi terlalu panjang (maks. 2000 karakter).';
        return $out;
    }

    $nama  = get_the_title( $siswa_id );
    $label = qrg_saran_jenis_label( $jenis );
    $title = $label . ' — ' . ( $nama !== '' ? $nama : 'Siswa' ) . ' · ' . wp_date( 'd/m/Y H:i' );

    $post_id = wp_insert_post( [
        'post_type'   => 'master_saran_siswa',
        'post_status' => 'publish',
        'post_title'  => $title,
    ], true );

    if ( is_wp_error( $post_id ) || ! $post_id ) {
        $out['error'] = 'Gagal mengirim. Coba lagi.';
        return $out;
    }

    update_post_meta( $post_id, '_saran_siswa_id', $siswa_id );
    update_post_meta( $post_id, '_saran_jenis', $jenis );
    update_post_meta( $post_id, '_saran_isi', $isi );
    update_post_meta( $post_id, '_saran_status', 'Baru' );

    if ( function_exists( 'qrg_rate_limit_hit' ) ) {
        qrg_rate_limit_hit( 'saran_' . $siswa_id, 3600 );
    }

    $out['success'] = 'Kritik/saran berhasil dikirim. Terima kasih.';
    return $out;
}

/**
 * Halaman portal siswa: form + riwayat kritik & saran.
 *
 * @param int $siswa_id ID siswa.
 */
function qrg_render_sekolah_portal_saran( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $nama     = get_the_title( $siswa_id );
    $kelas    = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $site     = get_bloginfo( 'name' );
    $saran_url = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url( 'saran' )
        : home_url( '/sekolah/saran/' );
    $hub_url  = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url()
        : home_url( '/sekolah/' );
    $akun_url = add_query_arg( 'tab', 'akun', $hub_url );
    $avatar_sm = function_exists( 'qrg_get_siswa_avatar_url' ) ? qrg_get_siswa_avatar_url( $siswa_id, 'thumbnail' ) : '';
    $initial  = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }

    $msg = qrg_saran_process_submit( $siswa_id );
    if ( $msg['success'] !== '' || $msg['error'] !== '' ) {
        $redir = $saran_url;
        if ( $msg['success'] !== '' ) {
            $redir = add_query_arg( 'saran', 'ok', $redir );
        } else {
            $redir = add_query_arg( 'saran_err', rawurlencode( $msg['error'] ), $redir );
        }
        wp_safe_redirect( $redir );
        exit;
    }

    $ok  = '';
    $err = '';
    if ( isset( $_GET['saran'] ) && sanitize_key( wp_unslash( $_GET['saran'] ) ) === 'ok' ) {
        $ok = 'Kritik/saran berhasil dikirim. Terima kasih atas masukan Anda.';
    }
    if ( isset( $_GET['saran_err'] ) ) {
        $err = sanitize_text_field( wp_unslash( $_GET['saran_err'] ) );
    }

    $items = qrg_saran_get_for_siswa( $siswa_id );

    $extra = '<style>
        .qrg-sk--saran-page .dana-sheet { padding-top: 18px; }
        .qrg-sk--saran-page .dana-sheet__label { margin-top: 0; }
        .qrg-saran-back {
            display: inline-flex; align-items: center; gap: 4px;
            color: #fff; text-decoration: none; font-size: 13px; font-weight: 600;
            opacity: .92; margin-bottom: 10px;
        }
        .qrg-saran-back:active { opacity: .75; }
        .qrg-saran-flash {
            border-radius: 14px; padding: 12px 14px; margin-bottom: 12px;
            font-size: 14px; font-weight: 600; line-height: 1.4;
        }
        .qrg-saran-flash.is-ok { background: rgba(52,199,89,.14); color: #248A3D; }
        .qrg-saran-flash.is-err { background: rgba(255,59,48,.12); color: #C93400; }
        .qrg-saran-form {
            background: #fff; border-radius: 16px; padding: 16px;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
            margin-bottom: 16px;
        }
        .qrg-saran-form .qrg-field { margin-bottom: 14px; }
        .qrg-saran-form .qrg-field > span {
            display: block; font-size: 12px; font-weight: 700; color: #8E8E93;
            text-transform: uppercase; letter-spacing: .02em; margin-bottom: 8px;
        }
        .qrg-saran-jenis { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .qrg-saran-jenis label {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 48px; padding: 12px; border-radius: 14px; background: #F2F2F7;
            border: 2px solid transparent; font-size: 15px; font-weight: 700; cursor: pointer;
            color: #1C1C1E; margin: 0;
        }
        .qrg-saran-jenis label:has(input:checked) {
            background: rgba(0,134,240,.1); border-color: #0086F0; color: #0086F0;
        }
        .qrg-saran-jenis label[data-jenis="kritik"]:has(input:checked) {
            background: rgba(255,149,0,.12); border-color: #FF9500; color: #C93400;
        }
        .qrg-saran-jenis input { position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; }
        .qrg-saran-form textarea {
            width: 100%; box-sizing: border-box; min-height: 130px; resize: vertical;
            padding: 12px 14px; border: 0; border-radius: 12px; background: #F2F2F7;
            font: inherit; font-size: 15px; color: #1C1C1E;
        }
        .qrg-saran-form textarea:focus { outline: 2px solid rgba(0,134,240,.28); }
        .qrg-saran-form .hint { margin: 6px 0 0; font-size: 12px; color: #8E8E93; }
        .qrg-saran-form .btn {
            appearance: none; border: 0; width: 100%; margin-top: 4px; min-height: 48px;
            border-radius: 14px; background: #0086F0; color: #fff; font-size: 15px;
            font-weight: 700; cursor: pointer; font-family: inherit;
        }
        .qrg-saran-form .btn:active { opacity: .88; }
        .qrg-saran-list {
            background: #fff; border-radius: 16px; overflow: hidden;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .qrg-saran-item {
            padding: 14px 16px; border-top: 1px solid #F2F2F7;
        }
        .qrg-saran-item:first-child { border-top: 0; }
        .qrg-saran-item__head {
            display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px;
        }
        .qrg-saran-item__jenis {
            font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px;
            background: rgba(0,134,240,.12); color: #0086F0;
        }
        .qrg-saran-item__jenis.is-kritik {
            background: rgba(255,149,0,.14); color: #C93400;
        }
        .qrg-saran-item__status {
            font-size: 11px; font-weight: 700; color: #8E8E93;
        }
        .qrg-saran-item__status.is-ok { color: #248A3D; }
        .qrg-saran-item__isi {
            margin: 0; font-size: 14px; line-height: 1.45; color: #1C1C1E; white-space: pre-wrap;
        }
        .qrg-saran-item__meta {
            margin: 8px 0 0; font-size: 12px; color: #8E8E93; font-weight: 600;
        }
        .qrg-saran-item__balasan {
            margin-top: 10px; padding: 10px 12px; border-radius: 12px; background: #F7F8FA;
            font-size: 13px; line-height: 1.4; color: #3A3A3C;
        }
        .qrg-saran-item__balasan strong { display: block; font-size: 11px; color: #8E8E93; margin-bottom: 4px; }
        .qrg-saran-empty {
            text-align: center; padding: 28px 16px; color: #8E8E93; font-size: 14px;
            background: #F7F8FA; border: 1px dashed rgba(60,60,67,.18); border-radius: 16px;
        }
    </style>';

    if ( function_exists( 'qrg_sekolah_portal_shell_start' ) ) {
        qrg_sekolah_portal_shell_start( 'Kritik & Saran', $extra, 'beranda' );
    }
    ?>
    <div class="qrg-sk qrg-sk--dana qrg-sk--saran-page">
        <div class="dana-top">
            <a class="qrg-saran-back" href="<?php echo esc_url( $hub_url ); ?>">← Beranda</a>
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
                <p class="dana-balance__label">Kritik &amp; saran</p>
                <div class="dana-balance__row">
                    <p class="dana-balance__amount" style="font-size:1.45rem;">Sampaikan masukan</p>
                </div>
                <p class="dana-balance__meta">Bantu sekolah menjadi lebih baik · <?php echo esc_html( $site ); ?></p>
            </div>
        </div>

        <div class="dana-sheet">
            <p class="dana-sheet__label">Kirim kritik / saran</p>

            <?php if ( $err !== '' ) : ?>
                <div class="qrg-saran-flash is-err"><?php echo esc_html( $err ); ?></div>
            <?php endif; ?>
            <?php if ( $ok !== '' ) : ?>
                <div class="qrg-saran-flash is-ok"><?php echo esc_html( $ok ); ?></div>
            <?php endif; ?>

            <form class="qrg-saran-form" method="post" action="<?php echo esc_url( $saran_url ); ?>">
                <?php wp_nonce_field( 'qrg_saran_' . $siswa_id, 'qrg_saran_nonce' ); ?>
                <input type="hidden" name="qrg_saran_submit" value="1">

                <div class="qrg-field">
                    <span>Jenis</span>
                    <div class="qrg-saran-jenis">
                        <label data-jenis="saran">
                            <input type="radio" name="saran_jenis" value="saran" checked>
                            Saran
                        </label>
                        <label data-jenis="kritik">
                            <input type="radio" name="saran_jenis" value="kritik">
                            Kritik
                        </label>
                    </div>
                </div>
                <div class="qrg-field">
                    <span for="saran_isi">Pesan</span>
                    <textarea id="saran_isi" name="saran_isi" required minlength="10" maxlength="2000" placeholder="Tulis kritik atau saran Anda di sini…"></textarea>
                    <p class="hint">Minimal 10 karakter. Pesan akan dibaca admin sekolah.</p>
                </div>
                <button type="submit" class="btn">Kirim</button>
            </form>

            <p class="dana-sheet__label" style="margin-top:8px;">Riwayat Anda</p>
            <?php if ( empty( $items ) ) : ?>
                <div class="qrg-saran-empty">Belum ada kritik/saran yang dikirim.</div>
            <?php else : ?>
                <div class="qrg-saran-list">
                    <?php foreach ( $items as $item ) :
                        $jenis   = (string) get_post_meta( $item->ID, '_saran_jenis', true );
                        $isi     = (string) get_post_meta( $item->ID, '_saran_isi', true );
                        $status  = (string) get_post_meta( $item->ID, '_saran_status', true );
                        $balasan = (string) get_post_meta( $item->ID, '_saran_balasan', true );
                        if ( $status === '' ) {
                            $status = 'Baru';
                        }
                        $tgl = get_the_date( 'd M Y · H:i', $item );
                        ?>
                        <div class="qrg-saran-item">
                            <div class="qrg-saran-item__head">
                                <span class="qrg-saran-item__jenis<?php echo $jenis === 'kritik' ? ' is-kritik' : ''; ?>">
                                    <?php echo esc_html( qrg_saran_jenis_label( $jenis ) ); ?>
                                </span>
                                <span class="qrg-saran-item__status<?php echo $status === 'Ditindaklanjuti' ? ' is-ok' : ''; ?>">
                                    <?php echo esc_html( $status ); ?>
                                </span>
                            </div>
                            <p class="qrg-saran-item__isi"><?php echo esc_html( $isi ); ?></p>
                            <p class="qrg-saran-item__meta"><?php echo esc_html( $tgl ); ?></p>
                            <?php if ( $balasan !== '' ) : ?>
                                <div class="qrg-saran-item__balasan">
                                    <strong>Balasan sekolah</strong>
                                    <?php echo esc_html( $balasan ); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    if ( function_exists( 'qrg_sekolah_portal_shell_end' ) ) {
        qrg_sekolah_portal_shell_end();
    }
}
