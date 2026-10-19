<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================
 * MODUL JADWAL MATA PELAJARAN
 * Akses via tombol di halaman Mata Pelajaran
 * =========================================================
 */

// 1. Registrasi CPT Jadwal (tersembunyi dari sidebar)
add_action( 'init', 'qrg_register_jadwal_cpt' );
function qrg_register_jadwal_cpt() {
    register_post_type( 'master_jadwal', [
        'labels' => [
            'name'               => 'Jadwal Mata Pelajaran',
            'singular_name'      => 'Jadwal',
            'menu_name'          => 'Jadwal Mata Pelajaran',
            'add_new'            => 'Tambah Jadwal',
            'add_new_item'       => 'Tambah Jadwal Baru',
            'edit_item'          => 'Edit Jadwal',
            'all_items'          => 'Jadwal Mata Pelajaran',
            'search_items'       => 'Cari Jadwal',
            'not_found'          => 'Jadwal tidak ditemukan',
            'not_found_in_trash' => 'Tidak ada jadwal di tong sampah',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => false, // Akses via tombol di Mata Pelajaran
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'supports'            => [ 'title' ],
    ] );
}

/**
 * Daftar hari sekolah.
 *
 * @return array<string,string>
 */
function qrg_jadwal_hari_options() {
    return [
        'Senin'  => 'Senin',
        'Selasa' => 'Selasa',
        'Rabu'   => 'Rabu',
        'Kamis'  => 'Kamis',
        'Jumat'  => 'Jumat',
        'Sabtu'  => 'Sabtu',
        'Minggu' => 'Minggu',
    ];
}

/**
 * Ambil daftar jadwal.
 *
 * @param array $args Argumen get_posts tambahan.
 * @return WP_Post[]
 */
function qrg_get_daftar_jadwal( $args = [] ) {
    $defaults = [
        'post_type'      => 'master_jadwal',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'meta_value',
        'meta_key'       => '_jadwal_hari',
        'order'          => 'ASC',
    ];
    return get_posts( array_merge( $defaults, $args ) );
}

/**
 * Label singkat jadwal.
 *
 * @param int $jadwal_id ID master_jadwal.
 * @return string
 */
function qrg_get_jadwal_label( $jadwal_id ) {
    $jadwal_id = (int) $jadwal_id;
    if ( ! $jadwal_id || get_post_type( $jadwal_id ) !== 'master_jadwal' ) {
        return '';
    }
    $mapel_id = (int) get_post_meta( $jadwal_id, '_jadwal_mapel_id', true );
    $hari     = get_post_meta( $jadwal_id, '_jadwal_hari', true );
    $mulai    = get_post_meta( $jadwal_id, '_jadwal_jam_mulai', true );
    $selesai  = get_post_meta( $jadwal_id, '_jadwal_jam_selesai', true );
    $mapel    = function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mapel_id ) : get_the_title( $mapel_id );

    $parts = array_filter( [
        $mapel,
        $hari,
        ( $mulai && $selesai ) ? ( $mulai . '–' . $selesai ) : '',
    ] );
    return implode( ' · ', $parts );
}

/**
 * Bangun judul otomatis untuk post jadwal.
 *
 * @param int    $mapel_id Mapel.
 * @param string $hari     Hari.
 * @param string $mulai    Jam mulai.
 * @param string $selesai  Jam selesai.
 * @return string
 */
function qrg_jadwal_build_title( $mapel_id, $hari, $mulai, $selesai ) {
    $mapel = function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mapel_id ) : get_the_title( $mapel_id );
    if ( ! $mapel ) {
        $mapel = 'Jadwal';
    }
    $jam = ( $mulai && $selesai ) ? ( $mulai . '–' . $selesai ) : '';
    return trim( $mapel . ( $hari ? ' — ' . $hari : '' ) . ( $jam ? ' (' . $jam . ')' : '' ) );
}

add_filter( 'enter_title_here', 'qrg_jadwal_title_placeholder', 10, 2 );
function qrg_jadwal_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_jadwal' ) {
        return 'Judul otomatis dari mapel, hari, dan jam';
    }
    return $title;
}

add_filter( 'post_row_actions', 'qrg_jadwal_remove_view_link', 10, 2 );
function qrg_jadwal_remove_view_link( $actions, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_jadwal' ) {
        unset( $actions['view'] );
    }
    return $actions;
}

add_filter( 'get_sample_permalink_html', 'qrg_jadwal_hide_permalink_box', 10, 2 );
function qrg_jadwal_hide_permalink_box( $html, $post_id ) {
    if ( get_post_type( $post_id ) === 'master_jadwal' ) {
        return '';
    }
    return $html;
}

// Tombol kembali + Tampilan Mingguan di halaman Jadwal
add_action( 'admin_footer-edit.php', 'qrg_jadwal_back_to_mapel_button' );
add_action( 'admin_footer-post.php', 'qrg_jadwal_back_to_mapel_button' );
add_action( 'admin_footer-post-new.php', 'qrg_jadwal_back_to_mapel_button' );
function qrg_jadwal_back_to_mapel_button() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_jadwal' ) {
        return;
    }
    $mapel_url  = admin_url( 'edit.php?post_type=master_mapel' );
    $mingguan_url = admin_url( 'admin.php?page=qrg-jadwal-mingguan' );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) {
            return;
        }
        var $existing = $wrap.siblings('.page-title-action').first();
        var $back = $('<a href="<?php echo esc_url( $mapel_url ); ?>" class="page-title-action">← Mata Pelajaran</a>');
        var $mingguan = $('<a href="<?php echo esc_url( $mingguan_url ); ?>" class="page-title-action">Tampilan Mingguan</a>');
        if ($existing.length) {
            $back.insertBefore($existing);
            $mingguan.insertAfter($existing);
        } else {
            $back.insertAfter($wrap);
            $mingguan.insertAfter($back);
        }
    });
    </script>
    <style>
        /* Sembunyikan judul manual — diisi otomatis saat simpan */
        body.post-type-master_jadwal #titlediv { display: none; }
    </style>
    <?php
}

// Tandai menu Mata Pelajaran aktif saat di halaman Jadwal / Mingguan
add_filter( 'parent_file', 'qrg_jadwal_parent_file' );
function qrg_jadwal_parent_file( $parent_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_jadwal' ) {
        return 'master-data-plugin';
    }
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-jadwal-mingguan' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_jadwal_submenu_file' );
function qrg_jadwal_submenu_file( $submenu_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_jadwal' ) {
        return 'edit.php?post_type=master_mapel';
    }
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-jadwal-mingguan' ) {
        return 'edit.php?post_type=master_mapel';
    }
    return $submenu_file;
}

// Prefill mapel dari daftar mapel (?mapel_id=)
add_action( 'add_meta_boxes', 'qrg_jadwal_meta_boxes' );
function qrg_jadwal_meta_boxes() {
    add_meta_box( 'jadwal_detail', 'Detail Jadwal Mata Pelajaran', 'qrg_jadwal_fields_callback', 'master_jadwal', 'normal', 'high' );
}

function qrg_jadwal_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_jadwal_meta', 'qrg_jadwal_nonce' );

    $mapel_id   = (int) get_post_meta( $post->ID, '_jadwal_mapel_id', true );
    $guru_id    = (int) get_post_meta( $post->ID, '_jadwal_guru_id', true );
    $kelas_id   = (int) get_post_meta( $post->ID, '_jadwal_kelas_id', true );
    $ruangan_id = (int) get_post_meta( $post->ID, '_jadwal_ruangan_id', true );
    $hari       = get_post_meta( $post->ID, '_jadwal_hari', true );
    $jam_mulai  = get_post_meta( $post->ID, '_jadwal_jam_mulai', true );
    $jam_selesai = get_post_meta( $post->ID, '_jadwal_jam_selesai', true );
    $keterangan = get_post_meta( $post->ID, '_jadwal_keterangan', true );

    if ( ! $mapel_id && isset( $_GET['mapel_id'] ) ) {
        $mapel_id = absint( $_GET['mapel_id'] );
    }

    $daftar_mapel   = function_exists( 'qrg_get_daftar_mapel' ) ? qrg_get_daftar_mapel( true ) : [];
    $daftar_guru    = function_exists( 'qrg_get_daftar_guru' ) ? qrg_get_daftar_guru() : [];
    $daftar_kelas   = function_exists( 'qrg_get_daftar_kelas' ) ? qrg_get_daftar_kelas() : [];
    $daftar_ruangan = function_exists( 'qrg_get_daftar_ruangan' ) ? qrg_get_daftar_ruangan() : [];
    $hari_options   = qrg_jadwal_hari_options();
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Judul jadwal dibuat otomatis dari <strong>Mapel + Hari + Jam</strong>. Isi detail di bawah ini lalu simpan.
    </p>
    <table class="form-table">
        <tr>
            <th><label for="jadwal_mapel_id">Mata Pelajaran</label></th>
            <td>
                <select name="jadwal_mapel_id" id="jadwal_mapel_id" style="min-width:320px;" required>
                    <option value="">— Pilih Mapel —</option>
                    <?php foreach ( $daftar_mapel as $m ) : ?>
                        <option value="<?php echo (int) $m->ID; ?>" <?php selected( $mapel_id, (int) $m->ID ); ?>>
                            <?php echo esc_html( function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $m->ID ) : $m->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( empty( $daftar_mapel ) ) : ?>
                    <p class="description" style="color:#b32d2e;">Belum ada mapel aktif. <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=master_mapel' ) ); ?>">Tambah Mapel</a>.</p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="jadwal_guru_id">Guru Pengajar</label></th>
            <td>
                <select name="jadwal_guru_id" id="jadwal_guru_id" style="min-width:320px;">
                    <option value="">— Pilih Guru —</option>
                    <?php foreach ( $daftar_guru as $g ) : ?>
                        <option value="<?php echo (int) $g->ID; ?>" <?php selected( $guru_id, (int) $g->ID ); ?>>
                            <?php echo esc_html( $g->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="jadwal_kelas_id">Kelas</label></th>
            <td>
                <select name="jadwal_kelas_id" id="jadwal_kelas_id" style="min-width:320px;">
                    <option value="">— Pilih Kelas —</option>
                    <?php foreach ( $daftar_kelas as $k ) : ?>
                        <option value="<?php echo (int) $k->ID; ?>" <?php selected( $kelas_id, (int) $k->ID ); ?>>
                            <?php echo esc_html( function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $k->ID ) : $k->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="jadwal_hari">Hari</label></th>
            <td>
                <select name="jadwal_hari" id="jadwal_hari" style="min-width:200px;" required>
                    <option value="">— Pilih Hari —</option>
                    <?php foreach ( $hari_options as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $hari, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="jadwal_jam_mulai">Jam Mulai</label></th>
            <td>
                <input type="time" name="jadwal_jam_mulai" id="jadwal_jam_mulai" value="<?php echo esc_attr( $jam_mulai ); ?>" required>
            </td>
        </tr>
        <tr>
            <th><label for="jadwal_jam_selesai">Jam Selesai</label></th>
            <td>
                <input type="time" name="jadwal_jam_selesai" id="jadwal_jam_selesai" value="<?php echo esc_attr( $jam_selesai ); ?>" required>
            </td>
        </tr>
        <tr>
            <th><label for="jadwal_ruangan_id">Ruangan</label></th>
            <td>
                <select name="jadwal_ruangan_id" id="jadwal_ruangan_id" style="min-width:320px;">
                    <option value="">— Pilih Ruangan (opsional) —</option>
                    <?php foreach ( $daftar_ruangan as $r ) : ?>
                        <option value="<?php echo (int) $r->ID; ?>" <?php selected( $ruangan_id, (int) $r->ID ); ?>>
                            <?php echo esc_html( function_exists( 'qrg_get_ruangan_label' ) ? qrg_get_ruangan_label( $r->ID ) : $r->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="jadwal_keterangan">Keterangan</label></th>
            <td>
                <textarea name="jadwal_keterangan" id="jadwal_keterangan" rows="3" class="large-text" placeholder="Opsional"><?php echo esc_textarea( $keterangan ); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_master_jadwal', 'qrg_save_jadwal_details' );
function qrg_save_jadwal_details( $post_id ) {
    if ( ! isset( $_POST['qrg_jadwal_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_jadwal_nonce'], 'qrg_save_jadwal_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    $mapel_id    = isset( $_POST['jadwal_mapel_id'] ) ? absint( $_POST['jadwal_mapel_id'] ) : 0;
    $guru_id     = isset( $_POST['jadwal_guru_id'] ) ? absint( $_POST['jadwal_guru_id'] ) : 0;
    $kelas_id    = isset( $_POST['jadwal_kelas_id'] ) ? absint( $_POST['jadwal_kelas_id'] ) : 0;
    $ruangan_id  = isset( $_POST['jadwal_ruangan_id'] ) ? absint( $_POST['jadwal_ruangan_id'] ) : 0;
    $hari        = isset( $_POST['jadwal_hari'] ) ? sanitize_text_field( wp_unslash( $_POST['jadwal_hari'] ) ) : '';
    $jam_mulai   = isset( $_POST['jadwal_jam_mulai'] ) ? sanitize_text_field( wp_unslash( $_POST['jadwal_jam_mulai'] ) ) : '';
    $jam_selesai = isset( $_POST['jadwal_jam_selesai'] ) ? sanitize_text_field( wp_unslash( $_POST['jadwal_jam_selesai'] ) ) : '';
    $keterangan  = isset( $_POST['jadwal_keterangan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['jadwal_keterangan'] ) ) : '';

    $hari_ok = array_keys( qrg_jadwal_hari_options() );
    if ( ! in_array( $hari, $hari_ok, true ) ) {
        $hari = '';
    }

    update_post_meta( $post_id, '_jadwal_mapel_id', $mapel_id );
    update_post_meta( $post_id, '_jadwal_guru_id', $guru_id );
    update_post_meta( $post_id, '_jadwal_kelas_id', $kelas_id );
    update_post_meta( $post_id, '_jadwal_ruangan_id', $ruangan_id );
    update_post_meta( $post_id, '_jadwal_hari', $hari );
    update_post_meta( $post_id, '_jadwal_jam_mulai', $jam_mulai );
    update_post_meta( $post_id, '_jadwal_jam_selesai', $jam_selesai );
    update_post_meta( $post_id, '_jadwal_keterangan', $keterangan );

    // Update judul otomatis tanpa memicu loop save
    $auto_title = qrg_jadwal_build_title( $mapel_id, $hari, $jam_mulai, $jam_selesai );
    remove_action( 'save_post_master_jadwal', 'qrg_save_jadwal_details' );
    wp_update_post( [
        'ID'         => $post_id,
        'post_title' => $auto_title,
    ] );
    add_action( 'save_post_master_jadwal', 'qrg_save_jadwal_details' );
}

// Kolom tabel jadwal
add_filter( 'manage_master_jadwal_posts_columns', 'qrg_set_jadwal_columns' );
function qrg_set_jadwal_columns( $columns ) {
    return [
        'cb'       => $columns['cb'],
        'title'    => 'Jadwal',
        'mapel'    => 'Mapel',
        'kelas'    => 'Kelas',
        'guru'     => 'Guru',
        'hari'     => 'Hari',
        'jam'      => 'Jam',
        'ruangan'  => 'Ruangan',
        'date'     => 'Tanggal',
    ];
}

add_action( 'manage_master_jadwal_posts_custom_column', 'qrg_fill_jadwal_columns', 10, 2 );
function qrg_fill_jadwal_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'mapel':
            $mapel_id = (int) get_post_meta( $post_id, '_jadwal_mapel_id', true );
            echo $mapel_id
                ? esc_html( function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mapel_id ) : get_the_title( $mapel_id ) )
                : '<span style="color:#646970;">—</span>';
            break;
        case 'kelas':
            $kelas_id = (int) get_post_meta( $post_id, '_jadwal_kelas_id', true );
            echo $kelas_id
                ? esc_html( function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas_id ) : get_the_title( $kelas_id ) )
                : '<span style="color:#646970;">—</span>';
            break;
        case 'guru':
            $guru_id = (int) get_post_meta( $post_id, '_jadwal_guru_id', true );
            echo $guru_id ? esc_html( get_the_title( $guru_id ) ) : '<span style="color:#646970;">—</span>';
            break;
        case 'hari':
            $hari = get_post_meta( $post_id, '_jadwal_hari', true );
            echo $hari ? '<strong>' . esc_html( $hari ) . '</strong>' : '—';
            break;
        case 'jam':
            $mulai   = get_post_meta( $post_id, '_jadwal_jam_mulai', true );
            $selesai = get_post_meta( $post_id, '_jadwal_jam_selesai', true );
            if ( $mulai || $selesai ) {
                echo esc_html( trim( $mulai . ' – ' . $selesai, ' –' ) );
            } else {
                echo '—';
            }
            break;
        case 'ruangan':
            $ruangan_id = (int) get_post_meta( $post_id, '_jadwal_ruangan_id', true );
            echo $ruangan_id
                ? esc_html( function_exists( 'qrg_get_ruangan_label' ) ? qrg_get_ruangan_label( $ruangan_id ) : get_the_title( $ruangan_id ) )
                : '<span style="color:#646970;">—</span>';
            break;
    }
}

// Filter cepat berdasarkan mapel
add_action( 'restrict_manage_posts', 'qrg_jadwal_mapel_filter_dropdown' );
function qrg_jadwal_mapel_filter_dropdown() {
    global $typenow;
    if ( $typenow !== 'master_jadwal' ) {
        return;
    }
    $selected = isset( $_GET['mapel_id'] ) ? absint( $_GET['mapel_id'] ) : 0;
    $daftar   = function_exists( 'qrg_get_daftar_mapel' ) ? qrg_get_daftar_mapel() : [];
    echo '<select name="mapel_id">';
    echo '<option value="">Semua Mapel</option>';
    foreach ( $daftar as $m ) {
        printf(
            '<option value="%d" %s>%s</option>',
            (int) $m->ID,
            selected( $selected, (int) $m->ID, false ),
            esc_html( $m->post_title )
        );
    }
    echo '</select>';
}

add_action( 'pre_get_posts', 'qrg_filter_jadwal_by_mapel' );
function qrg_filter_jadwal_by_mapel( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_jadwal' ) {
        return;
    }
    $mapel_id = isset( $_GET['mapel_id'] ) ? absint( $_GET['mapel_id'] ) : 0;
    if ( ! $mapel_id ) {
        return;
    }
    $meta_query   = (array) $query->get( 'meta_query' );
    $meta_query[] = [
        'key'   => '_jadwal_mapel_id',
        'value' => (string) $mapel_id,
    ];
    $query->set( 'meta_query', $meta_query );
}

// -------------------------------------------------------------------------
// Visualisasi jadwal mingguan per kelas
// -------------------------------------------------------------------------
add_action( 'admin_menu', 'qrg_add_jadwal_mingguan_page', 20 );
function qrg_add_jadwal_mingguan_page() {
    add_submenu_page(
        null,
        'Jadwal Mingguan',
        'Jadwal Mingguan',
        'manage_options',
        'qrg-jadwal-mingguan',
        'qrg_jadwal_mingguan_page_callback'
    );
}

/**
 * Palet warna untuk sel mapel.
 *
 * @return string[]
 */
function qrg_jadwal_color_palette() {
    return [
        '#dbeafe', '#dcfce7', '#ffedd5', '#fce7f3',
        '#e0e7ff', '#fef9c3', '#ccfbf1', '#fee2e2',
        '#ede9fe', '#e0f2fe', '#f3e8ff', '#ecfccb',
    ];
}

/**
 * Ambil semua jadwal milik satu kelas, dikelompokkan per hari.
 *
 * @param int $kelas_id ID master_kelas.
 * @return array<string,array<int,array>>
 */
function qrg_get_jadwal_by_kelas( $kelas_id ) {
    $kelas_id = (int) $kelas_id;
    $by_hari  = [];
    foreach ( array_keys( qrg_jadwal_hari_options() ) as $hari ) {
        $by_hari[ $hari ] = [];
    }

    if ( $kelas_id <= 0 ) {
        return $by_hari;
    }

    $ids = get_posts( [
        'post_type'      => 'master_jadwal',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_jadwal_kelas_id',
                'value' => (string) $kelas_id,
            ],
        ],
    ] );

    foreach ( $ids as $jid ) {
        $hari = get_post_meta( $jid, '_jadwal_hari', true );
        if ( ! $hari || ! isset( $by_hari[ $hari ] ) ) {
            continue;
        }
        $mapel_id   = (int) get_post_meta( $jid, '_jadwal_mapel_id', true );
        $guru_id    = (int) get_post_meta( $jid, '_jadwal_guru_id', true );
        $ruangan_id = (int) get_post_meta( $jid, '_jadwal_ruangan_id', true );
        $mulai      = (string) get_post_meta( $jid, '_jadwal_jam_mulai', true );
        $selesai    = (string) get_post_meta( $jid, '_jadwal_jam_selesai', true );

        $by_hari[ $hari ][] = [
            'id'         => (int) $jid,
            'mapel_id'   => $mapel_id,
            'mapel'      => function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mapel_id ) : get_the_title( $mapel_id ),
            'guru'       => $guru_id ? get_the_title( $guru_id ) : '',
            'ruangan'    => $ruangan_id
                ? ( function_exists( 'qrg_get_ruangan_label' ) ? qrg_get_ruangan_label( $ruangan_id ) : get_the_title( $ruangan_id ) )
                : '',
            'jam_mulai'  => $mulai,
            'jam_selesai'=> $selesai,
            'edit_url'   => get_edit_post_link( $jid, 'raw' ),
        ];
    }

    // Urutkan tiap hari berdasarkan jam mulai
    foreach ( $by_hari as $hari => $rows ) {
        usort( $rows, function( $a, $b ) {
            return strcmp( $a['jam_mulai'], $b['jam_mulai'] );
        } );
        $by_hari[ $hari ] = $rows;
    }

    return $by_hari;
}

/**
 * Jadwal mengajar dikelompokkan per hari untuk satu guru.
 *
 * @param int $guru_id ID master_guru.
 * @return array<string,array<int,array>>
 */
function qrg_get_jadwal_by_guru( $guru_id ) {
    $guru_id = (int) $guru_id;
    $by_hari = [];
    foreach ( array_keys( qrg_jadwal_hari_options() ) as $hari ) {
        $by_hari[ $hari ] = [];
    }
    if ( $guru_id <= 0 ) {
        return $by_hari;
    }

    $ids = get_posts( [
        'post_type'      => 'master_jadwal',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_jadwal_guru_id',
                'value' => (string) $guru_id,
            ],
        ],
    ] );

    foreach ( $ids as $jid ) {
        $hari = get_post_meta( $jid, '_jadwal_hari', true );
        if ( ! $hari || ! isset( $by_hari[ $hari ] ) ) {
            continue;
        }
        $mapel_id   = (int) get_post_meta( $jid, '_jadwal_mapel_id', true );
        $kelas_id   = (int) get_post_meta( $jid, '_jadwal_kelas_id', true );
        $ruangan_id = (int) get_post_meta( $jid, '_jadwal_ruangan_id', true );
        $mulai      = (string) get_post_meta( $jid, '_jadwal_jam_mulai', true );
        $selesai    = (string) get_post_meta( $jid, '_jadwal_jam_selesai', true );

        $by_hari[ $hari ][] = [
            'id'          => (int) $jid,
            'mapel_id'    => $mapel_id,
            'mapel'       => function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mapel_id ) : get_the_title( $mapel_id ),
            'kelas_id'    => $kelas_id,
            'kelas'       => function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas_id ) : get_the_title( $kelas_id ),
            'ruangan'     => $ruangan_id
                ? ( function_exists( 'qrg_get_ruangan_label' ) ? qrg_get_ruangan_label( $ruangan_id ) : get_the_title( $ruangan_id ) )
                : '',
            'jam_mulai'   => $mulai,
            'jam_selesai' => $selesai,
        ];
    }

    foreach ( $by_hari as $hari => $rows ) {
        usort( $rows, function ( $a, $b ) {
            return strcmp( $a['jam_mulai'], $b['jam_mulai'] );
        } );
        $by_hari[ $hari ] = $rows;
    }

    return $by_hari;
}

/**
 * Kumpulkan slot jam unik dari jadwal (untuk baris grid).
 *
 * @param array $by_hari Hasil qrg_get_jadwal_by_kelas.
 * @return string[] Format "HH:MM–HH:MM"
 */
function qrg_jadwal_collect_time_slots( $by_hari ) {
    $slots = [];
    foreach ( $by_hari as $rows ) {
        foreach ( $rows as $row ) {
            if ( ! $row['jam_mulai'] ) {
                continue;
            }
            $key = $row['jam_mulai'] . '–' . $row['jam_selesai'];
            $slots[ $key ] = [
                'mulai'   => $row['jam_mulai'],
                'selesai' => $row['jam_selesai'],
                'label'   => $key,
            ];
        }
    }
    uasort( $slots, function( $a, $b ) {
        return strcmp( $a['mulai'], $b['mulai'] );
    } );
    return array_values( $slots );
}

function qrg_jadwal_mingguan_page_callback() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Akses ditolak.' );
    }

    $daftar_kelas = function_exists( 'qrg_get_daftar_kelas' ) ? qrg_get_daftar_kelas() : [];
    $kelas_id     = isset( $_GET['kelas_id'] ) ? absint( $_GET['kelas_id'] ) : 0;
    if ( ! $kelas_id && ! empty( $daftar_kelas ) ) {
        $kelas_id = (int) $daftar_kelas[0]->ID;
    }

    $hari_kerja = [ 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu' ];
    $by_hari    = $kelas_id ? qrg_get_jadwal_by_kelas( $kelas_id ) : [];
    $slots      = qrg_jadwal_collect_time_slots( $by_hari );
    $palette    = qrg_jadwal_color_palette();
    $mapel_color = [];
    $color_i     = 0;

    // Index cepat: hari + jam_mulai + jam_selesai => item(s)
    $grid = [];
    foreach ( $by_hari as $hari => $rows ) {
        foreach ( $rows as $row ) {
            $key = $hari . '|' . $row['jam_mulai'] . '|' . $row['jam_selesai'];
            if ( ! isset( $grid[ $key ] ) ) {
                $grid[ $key ] = [];
            }
            $grid[ $key ][] = $row;
            if ( $row['mapel_id'] && ! isset( $mapel_color[ $row['mapel_id'] ] ) ) {
                $mapel_color[ $row['mapel_id'] ] = $palette[ $color_i % count( $palette ) ];
                $color_i++;
            }
        }
    }

    $list_url = admin_url( 'edit.php?post_type=master_jadwal' );
    $add_url  = admin_url( 'post-new.php?post_type=master_jadwal' );
    $base_url = admin_url( 'admin.php?page=qrg-jadwal-mingguan' );
    ?>
    <div class="wrap qrg-jadwal-mingguan">
        <h1>
            Jadwal Mingguan per Kelas
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">← Daftar Jadwal</a>
            <a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action">Tambah Jadwal</a>
        </h1>
        <p class="description">Tampilan grid mingguan agar jadwal tiap kelas mudah dibaca. Pilih kelas di bawah.</p>

        <?php if ( empty( $daftar_kelas ) ) : ?>
            <div class="notice notice-warning"><p>Belum ada Data Kelas. Buat kelas terlebih dahulu.</p></div>
        <?php else : ?>
            <div class="qrg-jadwal-tabs">
                <?php foreach ( $daftar_kelas as $kelas ) :
                    $url = add_query_arg( 'kelas_id', (int) $kelas->ID, $base_url );
                    $active = ( (int) $kelas->ID === (int) $kelas_id );
                    $label = function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas->ID ) : $kelas->post_title;
                    ?>
                    <a class="qrg-jadwal-tab<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="qrg-jadwal-toolbar">
                <strong><?php echo esc_html( function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas_id ) : get_the_title( $kelas_id ) ); ?></strong>
                <span class="description">
                    <?php
                    $total = 0;
                    foreach ( $by_hari as $rows ) {
                        $total += count( $rows );
                    }
                    echo esc_html( sprintf( '%d jadwal minggu ini', $total ) );
                    ?>
                </span>
            </div>

            <?php if ( empty( $slots ) ) : ?>
                <div class="notice notice-info inline"><p>Belum ada jadwal untuk kelas ini. <a href="<?php echo esc_url( $add_url ); ?>">Tambah jadwal</a>.</p></div>
            <?php else : ?>
                <div class="qrg-jadwal-scroll">
                    <table class="qrg-jadwal-grid">
                        <thead>
                            <tr>
                                <th class="qrg-jadwal-time">Jam</th>
                                <?php foreach ( $hari_kerja as $hari ) : ?>
                                    <th><?php echo esc_html( $hari ); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $slots as $slot ) : ?>
                                <tr>
                                    <th class="qrg-jadwal-time">
                                        <span class="qrg-jam-mulai"><?php echo esc_html( $slot['mulai'] ); ?></span>
                                        <span class="qrg-jam-sep">–</span>
                                        <span class="qrg-jam-selesai"><?php echo esc_html( $slot['selesai'] ); ?></span>
                                    </th>
                                    <?php foreach ( $hari_kerja as $hari ) :
                                        $key  = $hari . '|' . $slot['mulai'] . '|' . $slot['selesai'];
                                        $cell = isset( $grid[ $key ] ) ? $grid[ $key ] : [];
                                        ?>
                                        <td>
                                            <?php if ( empty( $cell ) ) : ?>
                                                <div class="qrg-jadwal-empty">—</div>
                                            <?php else : ?>
                                                <?php foreach ( $cell as $item ) :
                                                    $bg = isset( $mapel_color[ $item['mapel_id'] ] )
                                                        ? $mapel_color[ $item['mapel_id'] ]
                                                        : '#f1f5f9';
                                                    ?>
                                                    <a class="qrg-jadwal-card" href="<?php echo esc_url( $item['edit_url'] ); ?>" style="background:<?php echo esc_attr( $bg ); ?>;" title="Edit jadwal">
                                                        <strong class="qrg-card-mapel"><?php echo esc_html( $item['mapel'] ? $item['mapel'] : 'Mapel' ); ?></strong>
                                                        <?php if ( $item['guru'] ) : ?>
                                                            <span class="qrg-card-meta"><?php echo esc_html( $item['guru'] ); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ( $item['ruangan'] ) : ?>
                                                            <span class="qrg-card-meta qrg-card-room"><?php echo esc_html( $item['ruangan'] ); ?></span>
                                                        <?php endif; ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( ! empty( $mapel_color ) ) : ?>
                    <div class="qrg-jadwal-legend">
                        <strong>Legenda Mapel:</strong>
                        <?php foreach ( $mapel_color as $mid => $bg ) : ?>
                            <span class="qrg-legend-item">
                                <span class="qrg-legend-swatch" style="background:<?php echo esc_attr( $bg ); ?>"></span>
                                <?php echo esc_html( function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mid ) : get_the_title( $mid ) ); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <style>
        .qrg-jadwal-mingguan { max-width: 100%; }
        .qrg-jadwal-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 16px 0 12px;
        }
        .qrg-jadwal-tab {
            display: inline-block;
            padding: 8px 14px;
            border: 1px solid #c3c4c7;
            border-radius: 6px;
            background: #fff;
            text-decoration: none;
            color: #1d2327;
            font-weight: 600;
            font-size: 13px;
        }
        .qrg-jadwal-tab:hover { border-color: #2271b1; color: #2271b1; }
        .qrg-jadwal-tab.is-active {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
        }
        .qrg-jadwal-toolbar {
            display: flex;
            align-items: baseline;
            gap: 12px;
            margin: 0 0 14px;
            font-size: 14px;
        }
        .qrg-jadwal-scroll {
            overflow-x: auto;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
        }
        .qrg-jadwal-grid {
            width: 100%;
            border-collapse: collapse;
            min-width: 920px;
            table-layout: fixed;
        }
        .qrg-jadwal-grid th,
        .qrg-jadwal-grid td {
            border: 1px solid #e2e4e7;
            vertical-align: top;
            padding: 8px;
        }
        .qrg-jadwal-grid thead th {
            background: #f6f7f7;
            text-align: center;
            font-size: 13px;
            padding: 10px 8px;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .qrg-jadwal-time {
            width: 100px;
            background: #f8fafc !important;
            text-align: center !important;
            font-size: 12px;
            color: #475569;
            white-space: nowrap;
        }
        .qrg-jam-mulai { display: block; font-weight: 700; color: #0f172a; font-size: 13px; }
        .qrg-jam-sep { display: none; }
        .qrg-jam-selesai { display: block; font-size: 11px; }
        .qrg-jadwal-empty {
            color: #cbd5e1;
            text-align: center;
            padding: 18px 0;
            font-size: 12px;
        }
        .qrg-jadwal-card {
            display: block;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 6px;
            text-decoration: none;
            color: #0f172a;
            border: 1px solid rgba(15,23,42,.08);
            min-height: 64px;
            transition: transform .12s ease, box-shadow .12s ease;
        }
        .qrg-jadwal-card:last-child { margin-bottom: 0; }
        .qrg-jadwal-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(15,23,42,.08);
            color: #0f172a;
        }
        .qrg-card-mapel {
            display: block;
            font-size: 12px;
            line-height: 1.35;
            margin-bottom: 4px;
        }
        .qrg-card-meta {
            display: block;
            font-size: 11px;
            color: #475569;
            line-height: 1.3;
        }
        .qrg-card-room { opacity: .85; }
        .qrg-card-jam {
            display: block;
            margin-top: 4px;
            font-size: 10px;
            color: #64748b;
            font-weight: 600;
        }
        .qrg-jadwal-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            align-items: center;
            margin-top: 16px;
            padding: 12px 14px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            font-size: 12px;
        }
        .qrg-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .qrg-legend-swatch {
            width: 14px;
            height: 14px;
            border-radius: 4px;
            border: 1px solid rgba(0,0,0,.08);
            display: inline-block;
        }
        @media (max-width: 782px) {
            .qrg-jadwal-tab { padding: 6px 10px; font-size: 12px; }
        }
    </style>
    <?php
}

