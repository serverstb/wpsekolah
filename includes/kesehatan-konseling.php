<?php
/**
 * Kesehatan (log kunjungan UKS) & Konseling — portal /sekolah/kesehatan/ & /sekolah/konseling/
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'qrg_register_kesehatan_cpt' );
function qrg_register_kesehatan_cpt() {
    register_post_type( 'master_kesehatan', [
        'labels' => [
            'name'               => 'Kesehatan & Konseling',
            'singular_name'      => 'Kesehatan & Konseling',
            'add_new'            => 'Tambah',
            'add_new_item'       => 'Tambah Pengajuan',
            'edit_item'          => 'Edit Pengajuan',
            'new_item'           => 'Pengajuan Baru',
            'view_item'          => 'Lihat',
            'search_items'       => 'Cari Pengajuan',
            'not_found'          => 'Belum ada pengajuan',
            'not_found_in_trash' => 'Tidak ada di tong sampah',
            'all_items'          => 'Kesehatan & Konseling',
            'menu_name'          => 'Kesehatan & Konseling',
        ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => 'master-data-plugin',
        'supports'        => [ 'title' ],
        'capability_type' => 'post',
        'map_meta_cap'    => true,
        'menu_position'   => 31,
    ] );
}

/**
 * @return string[]
 */
function qrg_kesehatan_jenis_list() {
    return [ 'kesehatan', 'konseling' ];
}

/**
 * @param string $jenis Jenis.
 * @return string
 */
function qrg_kesehatan_jenis_label( $jenis ) {
    $jenis = sanitize_key( (string) $jenis );
    if ( $jenis === 'konseling' ) {
        return 'Konseling';
    }
    return 'Kunjungan UKS';
}

/**
 * @return string[]
 */
function qrg_kesehatan_status_list() {
    return [ 'Dicatat', 'Ditindaklanjuti', 'Menunggu', 'Diproses', 'Selesai', 'Ditolak' ];
}

add_action( 'add_meta_boxes', 'qrg_kesehatan_metaboxes' );
function qrg_kesehatan_metaboxes() {
    add_meta_box(
        'qrg_kesehatan_detail',
        'Detail Kesehatan & Konseling',
        'qrg_kesehatan_metabox_render',
        'master_kesehatan',
        'normal',
        'high'
    );
}

/**
 * @param WP_Post $post Post.
 */
function qrg_kesehatan_metabox_render( $post ) {
    wp_nonce_field( 'qrg_kesehatan_admin_save', 'qrg_kesehatan_admin_nonce' );
    $siswa_id  = (int) get_post_meta( $post->ID, '_kes_siswa_id', true );
    $jenis     = (string) get_post_meta( $post->ID, '_kes_jenis', true );
    $tanggal   = (string) get_post_meta( $post->ID, '_kes_tanggal', true );
    $jam       = (string) get_post_meta( $post->ID, '_kes_jam', true );
    $keluhan   = (string) get_post_meta( $post->ID, '_kes_keluhan', true );
    $status    = (string) get_post_meta( $post->ID, '_kes_status', true );
    $admin_cat = (string) get_post_meta( $post->ID, '_kes_catatan_admin', true );
    if ( $status === '' ) {
        $status = ( $jenis === 'kesehatan' || $jenis === '' ) ? 'Dicatat' : 'Menunggu';
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
            <th><label for="kes_siswa_id">Siswa</label></th>
            <td>
                <select name="kes_siswa_id" id="kes_siswa_id" style="min-width:280px;">
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
            <th><label for="kes_jenis">Jenis</label></th>
            <td>
                <select name="kes_jenis" id="kes_jenis">
                    <?php foreach ( qrg_kesehatan_jenis_list() as $j ) : ?>
                        <option value="<?php echo esc_attr( $j ); ?>" <?php selected( $jenis, $j ); ?>>
                            <?php echo esc_html( qrg_kesehatan_jenis_label( $j ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="kes_tanggal">Tanggal kunjungan / preferensi</label></th>
            <td><input type="date" name="kes_tanggal" id="kes_tanggal" value="<?php echo esc_attr( $tanggal ); ?>"></td>
        </tr>
        <tr>
            <th><label for="kes_jam">Jam kunjungan</label></th>
            <td><input type="time" name="kes_jam" id="kes_jam" value="<?php echo esc_attr( $jam ); ?>"></td>
        </tr>
        <tr>
            <th><label for="kes_keluhan">Keluhan / topik</label></th>
            <td><textarea name="kes_keluhan" id="kes_keluhan" rows="5" class="large-text"><?php echo esc_textarea( $keluhan ); ?></textarea></td>
        </tr>
        <tr>
            <th><label for="kes_status">Status</label></th>
            <td>
                <select name="kes_status" id="kes_status">
                    <?php foreach ( qrg_kesehatan_status_list() as $st ) : ?>
                        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>>
                            <?php echo esc_html( $st ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="kes_catatan_admin">Tindakan / catatan UKS-BK</label></th>
            <td><textarea name="kes_catatan_admin" id="kes_catatan_admin" rows="3" class="large-text" placeholder="Obat diberikan, istirahat, rujukan, dll."><?php echo esc_textarea( $admin_cat ); ?></textarea></td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_master_kesehatan', 'qrg_kesehatan_save_meta' );
function qrg_kesehatan_save_meta( $post_id ) {
    if ( ! isset( $_POST['qrg_kesehatan_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_kesehatan_admin_nonce'] ) ), 'qrg_kesehatan_admin_save' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    if ( isset( $_POST['kes_siswa_id'] ) ) {
        update_post_meta( $post_id, '_kes_siswa_id', absint( $_POST['kes_siswa_id'] ) );
    }
    if ( isset( $_POST['kes_jenis'] ) ) {
        $jenis = sanitize_key( wp_unslash( $_POST['kes_jenis'] ) );
        if ( ! in_array( $jenis, qrg_kesehatan_jenis_list(), true ) ) {
            $jenis = 'kesehatan';
        }
        update_post_meta( $post_id, '_kes_jenis', $jenis );
    }
    if ( isset( $_POST['kes_tanggal'] ) ) {
        update_post_meta( $post_id, '_kes_tanggal', sanitize_text_field( wp_unslash( $_POST['kes_tanggal'] ) ) );
    }
    if ( isset( $_POST['kes_jam'] ) ) {
        update_post_meta( $post_id, '_kes_jam', sanitize_text_field( wp_unslash( $_POST['kes_jam'] ) ) );
    }
    if ( isset( $_POST['kes_keluhan'] ) ) {
        update_post_meta( $post_id, '_kes_keluhan', sanitize_textarea_field( wp_unslash( $_POST['kes_keluhan'] ) ) );
    }
    if ( isset( $_POST['kes_status'] ) ) {
        $status = sanitize_text_field( wp_unslash( $_POST['kes_status'] ) );
        if ( ! in_array( $status, qrg_kesehatan_status_list(), true ) ) {
            $status = 'Menunggu';
        }
        update_post_meta( $post_id, '_kes_status', $status );
    }
    if ( isset( $_POST['kes_catatan_admin'] ) ) {
        update_post_meta( $post_id, '_kes_catatan_admin', sanitize_textarea_field( wp_unslash( $_POST['kes_catatan_admin'] ) ) );
    }
}

add_filter( 'manage_master_kesehatan_posts_columns', 'qrg_kesehatan_columns' );
function qrg_kesehatan_columns( $columns ) {
    return [
        'cb'     => $columns['cb'],
        'title'  => 'Judul',
        'siswa'  => 'Siswa',
        'jenis'  => 'Jenis',
        'status' => 'Status',
        'date'   => 'Tanggal',
    ];
}

add_action( 'manage_master_kesehatan_posts_custom_column', 'qrg_kesehatan_column_content', 10, 2 );
function qrg_kesehatan_column_content( $column, $post_id ) {
    switch ( $column ) {
        case 'siswa':
            $sid = (int) get_post_meta( $post_id, '_kes_siswa_id', true );
            echo $sid ? esc_html( get_the_title( $sid ) ) : '—';
            break;
        case 'jenis':
            echo esc_html( qrg_kesehatan_jenis_label( get_post_meta( $post_id, '_kes_jenis', true ) ) );
            break;
        case 'status':
            $st = (string) get_post_meta( $post_id, '_kes_status', true );
            if ( $st === '' ) {
                $j = (string) get_post_meta( $post_id, '_kes_jenis', true );
                $st = ( $j === 'konseling' ) ? 'Menunggu' : 'Dicatat';
            }
            echo esc_html( $st );
            break;
    }
}

/**
 * @param int    $siswa_id ID siswa.
 * @param string $jenis    kesehatan|konseling|'' (semua).
 * @return WP_Post[]
 */
function qrg_kesehatan_get_for_siswa( $siswa_id, $jenis = '' ) {
    $siswa_id = (int) $siswa_id;
    if ( $siswa_id <= 0 ) {
        return [];
    }
    $args = [
        'post_type'      => 'master_kesehatan',
        'posts_per_page' => 40,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            [
                'key'   => '_kes_siswa_id',
                'value' => $siswa_id,
            ],
        ],
    ];
    $jenis = sanitize_key( (string) $jenis );
    if ( in_array( $jenis, qrg_kesehatan_jenis_list(), true ) ) {
        $args['meta_query'][] = [
            'key'   => '_kes_jenis',
            'value' => $jenis,
        ];
    }
    return get_posts( $args );
}

/**
 * @param int    $siswa_id     ID siswa.
 * @param string $force_jenis  kesehatan|konseling — paksa jenis dari halaman.
 * @return array{error:string,success:string}
 */
function qrg_kesehatan_process_submit( $siswa_id, $force_jenis = '' ) {
    $out      = [ 'error' => '', 'success' => '' ];
    $siswa_id = (int) $siswa_id;
    if ( $siswa_id <= 0 || empty( $_POST['qrg_kes_submit'] ) ) {
        return $out;
    }
    if ( ! isset( $_POST['qrg_kes_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_kes_nonce'] ) ), 'qrg_kes_' . $siswa_id ) ) {
        $out['error'] = 'Token tidak valid. Muat ulang halaman.';
        return $out;
    }

    $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'kes_' . $siswa_id, 8, 3600 ) : true;
    if ( is_wp_error( $rl ) ) {
        $out['error'] = $rl->get_error_message();
        return $out;
    }

    $jenis = sanitize_key( (string) $force_jenis );
    if ( ! in_array( $jenis, qrg_kesehatan_jenis_list(), true ) ) {
        $jenis = isset( $_POST['kes_jenis'] ) ? sanitize_key( wp_unslash( $_POST['kes_jenis'] ) ) : 'kesehatan';
    }
    if ( ! in_array( $jenis, qrg_kesehatan_jenis_list(), true ) ) {
        $jenis = 'kesehatan';
    }
    $tanggal = isset( $_POST['kes_tanggal'] ) ? sanitize_text_field( wp_unslash( $_POST['kes_tanggal'] ) ) : '';
    $jam     = isset( $_POST['kes_jam'] ) ? sanitize_text_field( wp_unslash( $_POST['kes_jam'] ) ) : '';
    $keluhan = isset( $_POST['kes_keluhan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['kes_keluhan'] ) ) : '';

    $keluhan = trim( $keluhan );
    $is_uks  = ( $jenis === 'kesehatan' );

    if ( $is_uks ) {
        if ( $tanggal === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tanggal ) ) {
            $out['error'] = 'Tanggal kunjungan wajib diisi.';
            return $out;
        }
        if ( $jam === '' || ! preg_match( '/^\d{2}:\d{2}$/', $jam ) ) {
            $out['error'] = 'Jam kunjungan wajib diisi.';
            return $out;
        }
        if ( mb_strlen( $keluhan ) < 5 ) {
            $out['error'] = 'Isi keluhan minimal 5 karakter.';
            return $out;
        }
    } else {
        if ( mb_strlen( $keluhan ) < 10 ) {
            $out['error'] = 'Jelaskan topik minimal 10 karakter.';
            return $out;
        }
        if ( $tanggal !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tanggal ) ) {
            $out['error'] = 'Tanggal tidak valid.';
            return $out;
        }
    }
    if ( mb_strlen( $keluhan ) > 2000 ) {
        $out['error'] = 'Teks terlalu panjang (maks. 2000 karakter).';
        return $out;
    }

    $nama  = get_the_title( $siswa_id );
    $label = qrg_kesehatan_jenis_label( $jenis );
    if ( $is_uks ) {
        $title = 'Kunjungan UKS — ' . ( $nama !== '' ? $nama : 'Siswa' ) . ' · ' . $tanggal . ' ' . $jam;
    } else {
        $title = $label . ' — ' . ( $nama !== '' ? $nama : 'Siswa' ) . ' · ' . wp_date( 'd/m/Y H:i' );
    }

    $post_id = wp_insert_post( [
        'post_type'   => 'master_kesehatan',
        'post_status' => 'publish',
        'post_title'  => $title,
    ], true );

    if ( is_wp_error( $post_id ) || ! $post_id ) {
        $out['error'] = 'Gagal menyimpan. Coba lagi.';
        return $out;
    }

    update_post_meta( $post_id, '_kes_siswa_id', $siswa_id );
    update_post_meta( $post_id, '_kes_jenis', $jenis );
    update_post_meta( $post_id, '_kes_tanggal', $tanggal );
    update_post_meta( $post_id, '_kes_jam', $jam );
    update_post_meta( $post_id, '_kes_keluhan', $keluhan );
    update_post_meta( $post_id, '_kes_status', $is_uks ? 'Dicatat' : 'Menunggu' );

    if ( function_exists( 'qrg_rate_limit_hit' ) ) {
        qrg_rate_limit_hit( 'kes_' . $siswa_id, 3600 );
    }

    $out['success'] = $is_uks
        ? 'Kunjungan UKS berhasil dicatat.'
        : 'Pengajuan berhasil dikirim. Tim sekolah akan menindaklanjuti.';
    return $out;
}

/**
 * Halaman portal kesehatan atau konseling (terpisah).
 *
 * @param int    $siswa_id ID siswa.
 * @param string $jenis    kesehatan|konseling.
 */
function qrg_render_sekolah_portal_kesehatan( $siswa_id, $jenis = 'kesehatan' ) {
    $siswa_id = (int) $siswa_id;
    $jenis    = sanitize_key( (string) $jenis );
    if ( ! in_array( $jenis, qrg_kesehatan_jenis_list(), true ) ) {
        $jenis = 'kesehatan';
    }
    $is_konseling = ( $jenis === 'konseling' );
    $section_key  = $is_konseling ? 'konseling' : 'kesehatan';
    $page_title   = $is_konseling ? 'Konseling' : 'Kunjungan UKS';
    $hero_label   = $is_konseling ? 'Bimbingan & konseling' : 'Unit Kesehatan Sekolah';
    $hero_meta    = $is_konseling ? 'BK siap mendengarkan' : 'Catat setiap kunjungan ke UKS';
    $form_label   = $is_konseling ? 'Ajukan konseling' : 'Catat kunjungan';
    $keluhan_label = $is_konseling ? 'Topik / keluhan' : 'Keluhan';
    $keluhan_ph   = $is_konseling
        ? 'Ceritakan topik yang ingin dibahas dengan BK…'
        : 'Contoh: pusing, demam, luka ringan…';
    $empty_msg    = $is_konseling
        ? 'Belum ada pengajuan konseling.'
        : 'Belum ada log kunjungan UKS.';
    $btn_label    = $is_konseling ? 'Kirim pengajuan' : 'Catat kunjungan';
    $btn_bg       = $is_konseling ? '#6C5CE7' : '#00B894';
    $focus_ring   = $is_konseling ? 'rgba(108,92,231,.3)' : 'rgba(0,184,148,.3)';
    $ok_default   = $is_konseling
        ? 'Pengajuan berhasil dikirim. Tim sekolah akan menindaklanjuti.'
        : 'Kunjungan UKS berhasil dicatat.';
    $riwayat_label = $is_konseling ? 'Riwayat konseling' : 'Log kunjungan';
    $admin_label   = $is_konseling ? 'Catatan BK' : 'Tindakan UKS';

    $nama     = get_the_title( $siswa_id );
    $kelas    = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $site     = get_bloginfo( 'name' );
    $page_url = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url( $section_key )
        : home_url( '/sekolah/' . $section_key . '/' );
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

    $msg = qrg_kesehatan_process_submit( $siswa_id, $jenis );
    if ( $msg['success'] !== '' || $msg['error'] !== '' ) {
        $redir = $page_url;
        if ( $msg['success'] !== '' ) {
            $redir = add_query_arg( 'kes', 'ok', $redir );
        } else {
            $redir = add_query_arg( 'kes_err', rawurlencode( $msg['error'] ), $redir );
        }
        wp_safe_redirect( $redir );
        exit;
    }

    $ok  = '';
    $err = '';
    if ( isset( $_GET['kes'] ) && sanitize_key( wp_unslash( $_GET['kes'] ) ) === 'ok' ) {
        $ok = $ok_default;
    }
    if ( isset( $_GET['kes_err'] ) ) {
        $err = sanitize_text_field( wp_unslash( $_GET['kes_err'] ) );
    }

    $items = qrg_kesehatan_get_for_siswa( $siswa_id, $jenis );
    $today = wp_date( 'Y-m-d' );
    $now_hm = wp_date( 'H:i' );

    $extra = '<style>
        .qrg-sk--kes-page .dana-sheet { padding-top: 18px; }
        .qrg-sk--kes-page .dana-sheet__label { margin-top: 0; }
        .qrg-kes-back {
            display: inline-flex; align-items: center; gap: 4px;
            color: #fff; text-decoration: none; font-size: 13px; font-weight: 600;
            opacity: .92; margin-bottom: 10px;
        }
        .qrg-kes-back:active { opacity: .75; }
        .qrg-kes-flash {
            border-radius: 14px; padding: 12px 14px; margin-bottom: 12px;
            font-size: 14px; font-weight: 600; line-height: 1.4;
        }
        .qrg-kes-flash.is-ok { background: rgba(52,199,89,.14); color: #248A3D; }
        .qrg-kes-flash.is-err { background: rgba(255,59,48,.12); color: #C93400; }
        .qrg-kes-form {
            background: #fff; border-radius: 16px; padding: 16px;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
            margin-bottom: 16px;
        }
        .qrg-kes-form .qrg-field { margin-bottom: 14px; }
        .qrg-kes-form .qrg-field > span {
            display: block; font-size: 12px; font-weight: 700; color: #8E8E93;
            text-transform: uppercase; letter-spacing: .02em; margin-bottom: 8px;
        }
        .qrg-kes-form .qrg-field-row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
        }
        .qrg-kes-form input[type=date],
        .qrg-kes-form input[type=time],
        .qrg-kes-form textarea {
            width: 100%; box-sizing: border-box; padding: 12px 14px; border: 0;
            border-radius: 12px; background: #F2F2F7; font: inherit; font-size: 15px; color: #1C1C1E;
            -webkit-appearance: none; appearance: none;
        }
        .qrg-kes-form textarea { min-height: 120px; resize: vertical; }
        .qrg-kes-form input:focus, .qrg-kes-form textarea:focus {
            outline: 2px solid ' . esc_attr( $focus_ring ) . ';
        }
        .qrg-kes-form .hint { margin: 6px 0 0; font-size: 12px; color: #8E8E93; }
        .qrg-kes-form .btn {
            appearance: none; border: 0; width: 100%; margin-top: 4px; min-height: 48px;
            border-radius: 14px; background: ' . esc_attr( $btn_bg ) . '; color: #fff; font-size: 15px;
            font-weight: 700; cursor: pointer; font-family: inherit;
        }
        .qrg-kes-form .btn:active { opacity: .88; }
        .qrg-kes-list {
            background: #fff; border-radius: 16px; overflow: hidden;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .qrg-kes-item { padding: 14px 16px; border-top: 1px solid #F2F2F7; }
        .qrg-kes-item:first-child { border-top: 0; }
        .qrg-kes-item__head {
            display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px;
        }
        .qrg-kes-item__jenis {
            font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px;
            background: rgba(0,184,148,.14); color: #00896C;
        }
        .qrg-kes-item__jenis.is-konseling {
            background: rgba(108,92,231,.14); color: #5A4FCF;
        }
        .qrg-kes-item__status {
            font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 999px;
            background: rgba(255,149,0,.14); color: #C93400;
        }
        .qrg-kes-item__status.is-ok { background: rgba(52,199,89,.14); color: #248A3D; }
        .qrg-kes-item__status.is-run { background: rgba(0,122,255,.12); color: #007AFF; }
        .qrg-kes-item__status.is-no { background: rgba(255,59,48,.12); color: #C93400; }
        .qrg-kes-item__isi {
            margin: 0; font-size: 14px; line-height: 1.45; color: #1C1C1E; white-space: pre-wrap;
        }
        .qrg-kes-item__meta { margin: 8px 0 0; font-size: 12px; color: #8E8E93; font-weight: 600; }
        .qrg-kes-item__admin {
            margin-top: 10px; padding: 10px 12px; border-radius: 12px; background: #F7F8FA;
            font-size: 13px; line-height: 1.4; color: #3A3A3C;
        }
        .qrg-kes-item__admin strong { display: block; font-size: 11px; color: #8E8E93; margin-bottom: 4px; }
        .qrg-kes-empty {
            text-align: center; padding: 28px 16px; color: #8E8E93; font-size: 14px;
            background: #F7F8FA; border: 1px dashed rgba(60,60,67,.18); border-radius: 16px;
        }
        @media (max-width: 420px) {
            .qrg-kes-form .qrg-field-row { grid-template-columns: 1fr; }
        }
    </style>';

    if ( function_exists( 'qrg_sekolah_portal_shell_start' ) ) {
        qrg_sekolah_portal_shell_start( $page_title, $extra, 'beranda' );
    }
    ?>
    <div class="qrg-sk qrg-sk--dana qrg-sk--kes-page">
        <div class="dana-top">
            <a class="qrg-kes-back" href="<?php echo esc_url( $hub_url ); ?>">← Beranda</a>
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
                <p class="dana-balance__label"><?php echo esc_html( $hero_label ); ?></p>
                <div class="dana-balance__row">
                    <p class="dana-balance__amount" style="font-size:1.45rem;"><?php echo esc_html( $page_title ); ?></p>
                </div>
                <p class="dana-balance__meta"><?php echo esc_html( $hero_meta ); ?> · <?php echo esc_html( $site ); ?></p>
            </div>
        </div>

        <div class="dana-sheet">
            <p class="dana-sheet__label"><?php echo esc_html( $form_label ); ?></p>

            <?php if ( $err !== '' ) : ?>
                <div class="qrg-kes-flash is-err"><?php echo esc_html( $err ); ?></div>
            <?php endif; ?>
            <?php if ( $ok !== '' ) : ?>
                <div class="qrg-kes-flash is-ok"><?php echo esc_html( $ok ); ?></div>
            <?php endif; ?>

            <form class="qrg-kes-form" method="post" action="<?php echo esc_url( $page_url ); ?>">
                <?php wp_nonce_field( 'qrg_kes_' . $siswa_id, 'qrg_kes_nonce' ); ?>
                <input type="hidden" name="qrg_kes_submit" value="1">
                <input type="hidden" name="kes_jenis" value="<?php echo esc_attr( $jenis ); ?>">

                <?php if ( $is_konseling ) : ?>
                    <div class="qrg-field">
                        <span>Tanggal preferensi (opsional)</span>
                        <input type="date" name="kes_tanggal" value="<?php echo esc_attr( $today ); ?>">
                        <p class="hint">Kosongkan jika tidak ada preferensi tanggal.</p>
                    </div>
                    <div class="qrg-field">
                        <span><?php echo esc_html( $keluhan_label ); ?></span>
                        <textarea name="kes_keluhan" required minlength="10" maxlength="2000" placeholder="<?php echo esc_attr( $keluhan_ph ); ?>"></textarea>
                    </div>
                <?php else : ?>
                    <div class="qrg-field-row">
                        <div class="qrg-field">
                            <span>Tanggal kunjungan</span>
                            <input type="date" name="kes_tanggal" required value="<?php echo esc_attr( $today ); ?>">
                        </div>
                        <div class="qrg-field">
                            <span>Jam kunjungan</span>
                            <input type="time" name="kes_jam" required value="<?php echo esc_attr( $now_hm ); ?>">
                        </div>
                    </div>
                    <div class="qrg-field">
                        <span><?php echo esc_html( $keluhan_label ); ?></span>
                        <textarea name="kes_keluhan" required minlength="5" maxlength="2000" placeholder="<?php echo esc_attr( $keluhan_ph ); ?>"></textarea>
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn"><?php echo esc_html( $btn_label ); ?></button>
            </form>

            <p class="dana-sheet__label" style="margin-top:8px;"><?php echo esc_html( $riwayat_label ); ?></p>
            <?php if ( empty( $items ) ) : ?>
                <div class="qrg-kes-empty"><?php echo esc_html( $empty_msg ); ?></div>
            <?php else : ?>
                <div class="qrg-kes-list">
                    <?php foreach ( $items as $item ) :
                        $item_jenis = (string) get_post_meta( $item->ID, '_kes_jenis', true );
                        $keluhan    = (string) get_post_meta( $item->ID, '_kes_keluhan', true );
                        $tanggal    = (string) get_post_meta( $item->ID, '_kes_tanggal', true );
                        $jam        = (string) get_post_meta( $item->ID, '_kes_jam', true );
                        $status     = (string) get_post_meta( $item->ID, '_kes_status', true );
                        $admin_cat  = (string) get_post_meta( $item->ID, '_kes_catatan_admin', true );
                        if ( $status === '' ) {
                            $status = ( $item_jenis === 'kesehatan' || $item_jenis === '' ) ? 'Dicatat' : 'Menunggu';
                        }
                        $meta_line = get_the_date( 'd M Y · H:i', $item );
                        if ( $item_jenis !== 'konseling' && $tanggal && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tanggal ) ) {
                            $ts = strtotime( $tanggal . ' 12:00:00' );
                            $meta_line = $ts ? wp_date( 'd M Y', $ts ) : $tanggal;
                            if ( $jam !== '' ) {
                                $meta_line .= ' · ' . $jam;
                            }
                        } elseif ( $tanggal && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tanggal ) ) {
                            $ts = strtotime( $tanggal . ' 12:00:00' );
                            $pref = $ts ? wp_date( 'd M Y', $ts ) : $tanggal;
                            $meta_line .= ' · Preferensi: ' . $pref;
                        }
                        $st_cls = '';
                        if ( in_array( $status, [ 'Selesai', 'Dicatat' ], true ) ) {
                            $st_cls = 'is-ok';
                        } elseif ( in_array( $status, [ 'Diproses', 'Ditindaklanjuti' ], true ) ) {
                            $st_cls = 'is-run';
                        } elseif ( $status === 'Ditolak' ) {
                            $st_cls = 'is-no';
                        }
                        ?>
                        <div class="qrg-kes-item">
                            <div class="qrg-kes-item__head">
                                <span class="qrg-kes-item__jenis<?php echo $item_jenis === 'konseling' ? ' is-konseling' : ''; ?>">
                                    <?php echo esc_html( qrg_kesehatan_jenis_label( $item_jenis ) ); ?>
                                </span>
                                <span class="qrg-kes-item__status <?php echo esc_attr( $st_cls ); ?>"><?php echo esc_html( $status ); ?></span>
                            </div>
                            <p class="qrg-kes-item__isi"><?php echo esc_html( $keluhan ); ?></p>
                            <p class="qrg-kes-item__meta"><?php echo esc_html( $meta_line ); ?></p>
                            <?php if ( $admin_cat !== '' ) : ?>
                                <div class="qrg-kes-item__admin">
                                    <strong><?php echo esc_html( $admin_label ); ?></strong>
                                    <?php echo esc_html( $admin_cat ); ?>
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

/**
 * Alias render halaman konseling.
 *
 * @param int $siswa_id ID siswa.
 */
function qrg_render_sekolah_portal_konseling( $siswa_id ) {
    qrg_render_sekolah_portal_kesehatan( $siswa_id, 'konseling' );
}
