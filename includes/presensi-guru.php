<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================
 * MODUL PRESENSI
 * Menu Presensi = kelola mesin Solution saja.
 * CPT master_presensi_guru tetap ada (gaji/portal), bukan bagian menu ini.
 * =========================================================
 */

define( 'QRG_PRESENSI_SLUG', 'qrg-presensi' );
define( 'QRG_PRESENSI_PARENT', 'master-data-plugin' );

// -------------------------------------------------------------------------
// Submenu Presensi di dalam menu Sekolah
// -------------------------------------------------------------------------
add_action( 'admin_menu', 'qrg_presensi_register_menu', 20 );
function qrg_presensi_register_menu() {
    add_submenu_page(
        QRG_PRESENSI_PARENT,
        'Presensi',
        'Presensi',
        'manage_options',
        QRG_PRESENSI_SLUG,
        'qrg_presensi_dashboard_callback'
    );
}

add_action( 'admin_menu', 'qrg_presensi_hide_cpt_submenus', 999 );
function qrg_presensi_hide_cpt_submenus() {
    $hide = [
        'edit.php?post_type=master_presensi_guru',
        'post-new.php?post_type=master_presensi_guru',
    ];
    foreach ( $hide as $slug ) {
        remove_submenu_page( QRG_PRESENSI_PARENT, $slug );
        remove_submenu_page( QRG_PRESENSI_SLUG, $slug );
    }
}

function qrg_is_presensi_screen() {
    if ( isset( $_GET['page'] ) ) {
        $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
        if ( $page === QRG_PRESENSI_SLUG ) {
            return true;
        }
    }
    return false;
}

// 1. CPT Presensi Guru (tersembunyi dari sidebar, akses via menu Presensi)
add_action( 'init', 'qrg_register_presensi_guru_cpt' );
function qrg_register_presensi_guru_cpt() {
    register_post_type( 'master_presensi_guru', [
        'labels' => [
            'name'          => 'Presensi Guru',
            'singular_name' => 'Presensi Guru',
            'menu_name'     => 'Presensi Guru',
            'add_new_item'  => 'Tambah Presensi Guru',
            'edit_item'     => 'Edit Presensi Guru',
            'all_items'     => 'Presensi Guru',
            'add_new'       => 'Tambah Presensi',
        ],
        'public'             => false,
        'publicly_queryable' => false,
        'exclude_from_search'=> true,
        'show_ui'            => true,
        'show_in_menu'       => false,
        'supports'           => [ 'title' ],
        'has_archive'        => false,
    ] );
}

add_filter( 'parent_file', 'qrg_presensi_guru_parent_file', 20 );
function qrg_presensi_guru_parent_file( $parent_file ) {
    if ( qrg_is_presensi_screen() ) {
        return QRG_PRESENSI_PARENT;
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_presensi_guru_submenu_file', 20 );
function qrg_presensi_guru_submenu_file( $submenu_file ) {
    if ( qrg_is_presensi_screen() ) {
        return QRG_PRESENSI_SLUG;
    }
    return $submenu_file;
}

// Tombol kembali ke halaman Presensi
add_action( 'admin_footer-edit.php', 'qrg_presensi_guru_header_buttons' );
add_action( 'admin_footer-post.php', 'qrg_presensi_guru_header_buttons' );
add_action( 'admin_footer-post-new.php', 'qrg_presensi_guru_header_buttons' );
function qrg_presensi_guru_header_buttons() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_presensi_guru' ) {
        return;
    }
    $hub_url = admin_url( 'edit.php?post_type=master_guru' );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) return;
        var $existing = $wrap.siblings('.page-title-action').first();
        var $back = $('<a href="<?php echo esc_url( $hub_url ); ?>" class="page-title-action">← Data Guru</a>');
        if ($existing.length) {
            $back.insertBefore($existing);
        } else {
            $back.insertAfter($wrap);
        }
    });
    </script>
    <style>
        body.post-type-master_presensi_guru #titlediv { display: none; }
    </style>
    <?php
}

add_filter( 'post_row_actions', 'qrg_presensi_guru_row_actions', 10, 2 );
function qrg_presensi_guru_row_actions( $actions, $post ) {
    if ( $post->post_type === 'master_presensi_guru' ) {
        unset( $actions['view'] );
        unset( $actions['inline hide-if-no-js'] );
    }
    return $actions;
}

/**
 * Opsi status presensi.
 *
 * @return array
 */
function qrg_presensi_guru_status_options() {
    return [
        'Hadir' => 'Hadir',
        'Izin'  => 'Izin',
        'Sakit' => 'Sakit',
        'Alpha' => 'Alpha',
    ];
}

// 2. Meta box
add_action( 'add_meta_boxes', 'qrg_presensi_guru_meta_boxes' );
function qrg_presensi_guru_meta_boxes() {
    add_meta_box(
        'presensi_guru_detail',
        'Detail Presensi Guru',
        'qrg_presensi_guru_fields_callback',
        'master_presensi_guru',
        'normal',
        'high'
    );
}

function qrg_presensi_guru_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_presensi_guru_meta', 'qrg_presensi_guru_nonce' );

    $guru_id     = (int) get_post_meta( $post->ID, '_presensi_guru_id', true );
    $tanggal     = get_post_meta( $post->ID, '_presensi_tanggal', true );
    $status      = get_post_meta( $post->ID, '_presensi_status', true );
    $jam_masuk   = get_post_meta( $post->ID, '_presensi_jam_masuk', true );
    $jam_pulang  = get_post_meta( $post->ID, '_presensi_jam_pulang', true );
    $keterangan  = get_post_meta( $post->ID, '_presensi_keterangan', true );

    if ( ! $tanggal ) {
        $tanggal = current_time( 'Y-m-d' );
    }
    if ( ! $status ) {
        $status = 'Hadir';
    }

    $daftar_guru = get_posts( [
        'post_type'      => 'master_guru',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
    ?>
    <table class="form-table">
        <tr>
            <th><label for="presensi_guru_id">Guru</label></th>
            <td>
                <select name="presensi_guru_id" id="presensi_guru_id" required style="min-width:320px;">
                    <option value="">— Pilih Guru —</option>
                    <?php foreach ( $daftar_guru as $guru ) : ?>
                        <option value="<?php echo esc_attr( $guru->ID ); ?>" <?php selected( $guru_id, $guru->ID ); ?>>
                            <?php echo esc_html( $guru->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="presensi_tanggal">Tanggal</label></th>
            <td><input type="date" name="presensi_tanggal" id="presensi_tanggal" value="<?php echo esc_attr( $tanggal ); ?>" required></td>
        </tr>
        <tr>
            <th><label for="presensi_status">Status</label></th>
            <td>
                <select name="presensi_status" id="presensi_status">
                    <?php foreach ( qrg_presensi_guru_status_options() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="presensi_jam_masuk">Jam Masuk</label></th>
            <td><input type="time" name="presensi_jam_masuk" id="presensi_jam_masuk" value="<?php echo esc_attr( $jam_masuk ); ?>"></td>
        </tr>
        <tr>
            <th><label for="presensi_jam_pulang">Jam Pulang</label></th>
            <td><input type="time" name="presensi_jam_pulang" id="presensi_jam_pulang" value="<?php echo esc_attr( $jam_pulang ); ?>"></td>
        </tr>
        <tr>
            <th><label for="presensi_keterangan">Keterangan</label></th>
            <td>
                <textarea name="presensi_keterangan" id="presensi_keterangan" rows="3" class="large-text"><?php echo esc_textarea( $keterangan ); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

// 3. Simpan
add_action( 'save_post_master_presensi_guru', 'qrg_save_presensi_guru_details' );
function qrg_save_presensi_guru_details( $post_id ) {
    if ( ! isset( $_POST['qrg_presensi_guru_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_presensi_guru_nonce'], 'qrg_save_presensi_guru_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) {
        return;
    }

    $guru_id    = isset( $_POST['presensi_guru_id'] ) ? absint( $_POST['presensi_guru_id'] ) : 0;
    $tanggal    = isset( $_POST['presensi_tanggal'] ) ? sanitize_text_field( wp_unslash( $_POST['presensi_tanggal'] ) ) : '';
    $status     = isset( $_POST['presensi_status'] ) ? sanitize_text_field( wp_unslash( $_POST['presensi_status'] ) ) : 'Hadir';
    $jam_masuk  = isset( $_POST['presensi_jam_masuk'] ) ? sanitize_text_field( wp_unslash( $_POST['presensi_jam_masuk'] ) ) : '';
    $jam_pulang = isset( $_POST['presensi_jam_pulang'] ) ? sanitize_text_field( wp_unslash( $_POST['presensi_jam_pulang'] ) ) : '';
    $keterangan = isset( $_POST['presensi_keterangan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['presensi_keterangan'] ) ) : '';

    $allowed = array_keys( qrg_presensi_guru_status_options() );
    if ( ! in_array( $status, $allowed, true ) ) {
        $status = 'Hadir';
    }

    update_post_meta( $post_id, '_presensi_guru_id', $guru_id );
    update_post_meta( $post_id, '_presensi_tanggal', $tanggal );
    update_post_meta( $post_id, '_presensi_status', $status );
    update_post_meta( $post_id, '_presensi_jam_masuk', $jam_masuk );
    update_post_meta( $post_id, '_presensi_jam_pulang', $jam_pulang );
    update_post_meta( $post_id, '_presensi_keterangan', $keterangan );

    $nama_guru = $guru_id ? get_the_title( $guru_id ) : 'Guru';
    $tgl_label = $tanggal ? date_i18n( 'd M Y', strtotime( $tanggal ) ) : '';
    $title     = trim( sprintf( 'Presensi %s — %s (%s)', $nama_guru, $tgl_label, $status ) );

    remove_action( 'save_post_master_presensi_guru', 'qrg_save_presensi_guru_details' );
    wp_update_post( [
        'ID'         => $post_id,
        'post_title' => $title,
    ] );
    add_action( 'save_post_master_presensi_guru', 'qrg_save_presensi_guru_details' );
}

// 4. Kolom daftar
add_filter( 'manage_master_presensi_guru_posts_columns', 'qrg_set_presensi_guru_columns' );
function qrg_set_presensi_guru_columns( $columns ) {
    return [
        'cb'        => $columns['cb'],
        'title'     => 'Presensi',
        'guru'      => 'Guru',
        'tanggal'   => 'Tanggal',
        'status'    => 'Status',
        'jam'       => 'Jam',
        'date'      => 'Dibuat',
    ];
}

add_action( 'manage_master_presensi_guru_posts_custom_column', 'qrg_fill_presensi_guru_columns', 10, 2 );
function qrg_fill_presensi_guru_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'guru':
            $guru_id = (int) get_post_meta( $post_id, '_presensi_guru_id', true );
            if ( $guru_id ) {
                echo '<a href="' . esc_url( get_edit_post_link( $guru_id ) ) . '"><strong>' . esc_html( get_the_title( $guru_id ) ) . '</strong></a>';
            } else {
                echo '<span style="color:#aaa;">—</span>';
            }
            break;
        case 'tanggal':
            $tanggal = get_post_meta( $post_id, '_presensi_tanggal', true );
            echo $tanggal ? esc_html( date_i18n( 'd M Y', strtotime( $tanggal ) ) ) : '—';
            break;
        case 'status':
            $status = get_post_meta( $post_id, '_presensi_status', true );
            $colors = [
                'Hadir' => '#166534',
                'Izin'  => '#9a3412',
                'Sakit' => '#1d4ed8',
                'Alpha' => '#b91c1c',
            ];
            $bg = [
                'Hadir' => '#dcfce7',
                'Izin'  => '#ffedd5',
                'Sakit' => '#dbeafe',
                'Alpha' => '#fee2e2',
            ];
            $c = isset( $colors[ $status ] ) ? $colors[ $status ] : '#334155';
            $b = isset( $bg[ $status ] ) ? $bg[ $status ] : '#f1f5f9';
            echo '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:' . esc_attr( $b ) . ';color:' . esc_attr( $c ) . ';font-weight:600;font-size:12px;">' . esc_html( $status ? $status : '—' ) . '</span>';
            break;
        case 'jam':
            $masuk  = get_post_meta( $post_id, '_presensi_jam_masuk', true );
            $pulang = get_post_meta( $post_id, '_presensi_jam_pulang', true );
            if ( ! $masuk && ! $pulang ) {
                echo '<span style="color:#aaa;">—</span>';
            } else {
                echo esc_html( ( $masuk ? $masuk : '—' ) . ' – ' . ( $pulang ? $pulang : '—' ) );
            }
            break;
    }
}

/**
 * Statistik presensi guru per periode bulan.
 *
 * @param int $bulan 1-12
 * @param int $tahun
 * @return array{hadir:int,izin:int,sakit:int,alpha:int,total:int,guru_ids:array}
 */
function qrg_presensi_get_month_stats( $bulan, $tahun ) {
    $result = [
        'hadir'     => 0,
        'izin'      => 0,
        'sakit'     => 0,
        'alpha'     => 0,
        'total'     => 0,
        'guru_ids'  => [],
    ];
    $bulan = max( 1, min( 12, (int) $bulan ) );
    $tahun = (int) $tahun;
    if ( $tahun <= 0 ) {
        return $result;
    }

    $start = sprintf( '%04d-%02d-01', $tahun, $bulan );
    $end   = date( 'Y-m-t', strtotime( $start ) );

    $ids = get_posts( [
        'post_type'      => 'master_presensi_guru',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'     => '_presensi_tanggal',
                'value'   => [ $start, $end ],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ],
        ],
    ] );

    foreach ( $ids as $pid ) {
        $status = get_post_meta( $pid, '_presensi_status', true );
        $key    = strtolower( (string) $status );
        if ( isset( $result[ $key ] ) ) {
            $result[ $key ]++;
        }
        $result['total']++;
        $gid = (int) get_post_meta( $pid, '_presensi_guru_id', true );
        if ( $gid > 0 ) {
            $result['guru_ids'][ $gid ] = true;
        }
    }

    return $result;
}

/**
 * Presensi terbaru untuk tabel ringkas di dashboard.
 *
 * @param int $limit
 * @return WP_Post[]
 */
function qrg_presensi_get_recent( $limit = 8 ) {
    return get_posts( [
        'post_type'      => 'master_presensi_guru',
        'posts_per_page' => max( 1, (int) $limit ),
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ] );
}

function qrg_presensi_dashboard_callback() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( function_exists( 'qrg_solution_handle_admin_post' ) ) {
        qrg_solution_handle_admin_post();
    }
    ?>
    <div class="wrap">
        <h1>Presensi — Mesin Solution</h1>
        <p class="description">Kelola mesin absensi Solution: daftar mesin, user, sidik jari, dan log punch. Menu ini tidak terkait Data Guru.</p>
        <?php
        if ( function_exists( 'qrg_solution_render_device_panel' ) ) {
            qrg_solution_render_device_panel();
        } else {
            echo '<div class="notice notice-error"><p>Modul mesin Solution belum dimuat.</p></div>';
        }
        ?>
    </div>
    <?php
}
