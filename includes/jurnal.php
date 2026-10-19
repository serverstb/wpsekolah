<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Jurnal Mengajar — akses via tombol di halaman Mata Pelajaran.
 */

add_action( 'init', 'qrg_register_jurnal_cpt' );
function qrg_register_jurnal_cpt() {
    register_post_type( 'master_jurnal', [
        'labels' => [
            'name'               => 'Jurnal Mengajar',
            'singular_name'      => 'Jurnal',
            'menu_name'          => 'Jurnal Mengajar',
            'add_new'            => 'Tambah Jurnal',
            'add_new_item'       => 'Tambah Jurnal Baru',
            'edit_item'          => 'Edit Jurnal',
            'all_items'          => 'Jurnal Mengajar',
            'search_items'       => 'Cari Jurnal',
            'not_found'          => 'Jurnal tidak ditemukan',
            'not_found_in_trash' => 'Tidak ada jurnal di tong sampah',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'supports'            => [ 'title' ],
    ] );
}

function qrg_jurnal_build_title( $mapel_id, $kelas_id, $tanggal ) {
    $mapel = function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mapel_id ) : get_the_title( $mapel_id );
    $kelas = function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas_id ) : get_the_title( $kelas_id );
    $parts = array_filter( [
        $mapel ?: 'Jurnal',
        $kelas ? 'Kelas ' . $kelas : '',
        $tanggal ? date_i18n( 'd M Y', strtotime( $tanggal ) ) : '',
    ] );
    return implode( ' · ', $parts );
}

add_filter( 'parent_file', 'qrg_jurnal_parent_file' );
function qrg_jurnal_parent_file( $parent_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_jurnal' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_jurnal_submenu_file' );
function qrg_jurnal_submenu_file( $submenu_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_jurnal' ) {
        return 'edit.php?post_type=master_mapel';
    }
    return $submenu_file;
}

add_action( 'admin_footer-edit.php', 'qrg_jurnal_header_buttons' );
add_action( 'admin_footer-post.php', 'qrg_jurnal_header_buttons' );
add_action( 'admin_footer-post-new.php', 'qrg_jurnal_header_buttons' );
function qrg_jurnal_header_buttons() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_jurnal' || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $mapel_url = admin_url( 'edit.php?post_type=master_mapel' );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) return;
        var $existing = $wrap.siblings('.page-title-action').first();
        var $back = $('<a href="<?php echo esc_url( $mapel_url ); ?>" class="page-title-action">← Mata Pelajaran</a>');
        if ($existing.length) {
            $back.insertBefore($existing);
        } else {
            $back.insertAfter($wrap);
        }
    });
    </script>
    <style>
        body.post-type-master_jurnal #titlediv { display: none; }
    </style>
    <?php
}

add_filter( 'post_row_actions', 'qrg_jurnal_remove_view_link', 10, 2 );
function qrg_jurnal_remove_view_link( $actions, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_jurnal' ) {
        unset( $actions['view'] );
    }
    return $actions;
}

add_action( 'add_meta_boxes', 'qrg_jurnal_meta_boxes' );
function qrg_jurnal_meta_boxes() {
    add_meta_box( 'jurnal_detail', 'Detail Jurnal Mengajar', 'qrg_jurnal_fields_callback', 'master_jurnal', 'normal', 'high' );
}

function qrg_jurnal_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_jurnal_meta', 'qrg_jurnal_nonce' );

    $mapel_id  = (int) get_post_meta( $post->ID, '_jurnal_mapel_id', true );
    $guru_id   = (int) get_post_meta( $post->ID, '_jurnal_guru_id', true );
    $kelas_id  = (int) get_post_meta( $post->ID, '_jurnal_kelas_id', true );
    $tanggal   = get_post_meta( $post->ID, '_jurnal_tanggal', true );
    $materi    = get_post_meta( $post->ID, '_jurnal_materi', true );
    $kegiatan  = get_post_meta( $post->ID, '_jurnal_kegiatan', true );
    $catatan   = get_post_meta( $post->ID, '_jurnal_catatan', true );

    if ( ! $mapel_id && isset( $_GET['mapel_id'] ) ) {
        $mapel_id = absint( $_GET['mapel_id'] );
    }
    if ( ! $tanggal ) {
        $tanggal = current_time( 'Y-m-d' );
    }

    $daftar_mapel = function_exists( 'qrg_get_daftar_mapel' ) ? qrg_get_daftar_mapel( true ) : [];
    $daftar_guru  = function_exists( 'qrg_get_daftar_guru' ) ? qrg_get_daftar_guru() : [];
    $daftar_kelas = function_exists( 'qrg_get_daftar_kelas' ) ? qrg_get_daftar_kelas() : [];
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        Catat kegiatan pembelajaran per pertemuan. Judul jurnal dibuat otomatis dari <strong>Mapel + Kelas + Tanggal</strong>.
    </p>
    <table class="form-table">
        <tr>
            <th><label for="jurnal_tanggal">Tanggal</label></th>
            <td><input type="date" name="jurnal_tanggal" id="jurnal_tanggal" value="<?php echo esc_attr( $tanggal ); ?>" required></td>
        </tr>
        <tr>
            <th><label for="jurnal_mapel_id">Mata Pelajaran</label></th>
            <td>
                <select name="jurnal_mapel_id" id="jurnal_mapel_id" style="min-width:320px;" required>
                    <option value="">— Pilih Mapel —</option>
                    <?php foreach ( $daftar_mapel as $m ) : ?>
                        <option value="<?php echo (int) $m->ID; ?>" <?php selected( $mapel_id, (int) $m->ID ); ?>>
                            <?php echo esc_html( function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $m->ID ) : $m->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="jurnal_guru_id">Guru</label></th>
            <td>
                <select name="jurnal_guru_id" id="jurnal_guru_id" style="min-width:320px;" required>
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
            <th><label for="jurnal_kelas_id">Kelas</label></th>
            <td>
                <select name="jurnal_kelas_id" id="jurnal_kelas_id" style="min-width:320px;" required>
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
            <th><label for="jurnal_materi">Materi / Topik</label></th>
            <td>
                <input type="text" name="jurnal_materi" id="jurnal_materi" value="<?php echo esc_attr( $materi ); ?>" class="large-text" placeholder="Contoh: Bab 3 — Persamaan Linear">
            </td>
        </tr>
        <tr>
            <th><label for="jurnal_kegiatan">Kegiatan Pembelajaran</label></th>
            <td>
                <textarea name="jurnal_kegiatan" id="jurnal_kegiatan" rows="5" class="large-text" placeholder="Ringkas kegiatan: pembukaan, inti, penutupan, metode, dll."><?php echo esc_textarea( $kegiatan ); ?></textarea>
            </td>
        </tr>
        <tr>
            <th><label for="jurnal_catatan">Catatan / Kendala</label></th>
            <td>
                <textarea name="jurnal_catatan" id="jurnal_catatan" rows="3" class="large-text" placeholder="Opsional"><?php echo esc_textarea( $catatan ); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_master_jurnal', 'qrg_save_jurnal_details' );
function qrg_save_jurnal_details( $post_id ) {
    if ( ! isset( $_POST['qrg_jurnal_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_jurnal_nonce'], 'qrg_save_jurnal_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) {
        return;
    }

    $mapel_id = isset( $_POST['jurnal_mapel_id'] ) ? absint( $_POST['jurnal_mapel_id'] ) : 0;
    $guru_id  = isset( $_POST['jurnal_guru_id'] ) ? absint( $_POST['jurnal_guru_id'] ) : 0;
    $kelas_id = isset( $_POST['jurnal_kelas_id'] ) ? absint( $_POST['jurnal_kelas_id'] ) : 0;
    $tanggal  = isset( $_POST['jurnal_tanggal'] ) ? sanitize_text_field( wp_unslash( $_POST['jurnal_tanggal'] ) ) : '';
    $materi   = isset( $_POST['jurnal_materi'] ) ? sanitize_text_field( wp_unslash( $_POST['jurnal_materi'] ) ) : '';
    $kegiatan = isset( $_POST['jurnal_kegiatan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['jurnal_kegiatan'] ) ) : '';
    $catatan  = isset( $_POST['jurnal_catatan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['jurnal_catatan'] ) ) : '';

    update_post_meta( $post_id, '_jurnal_mapel_id', $mapel_id );
    update_post_meta( $post_id, '_jurnal_guru_id', $guru_id );
    update_post_meta( $post_id, '_jurnal_kelas_id', $kelas_id );
    update_post_meta( $post_id, '_jurnal_tanggal', $tanggal );
    update_post_meta( $post_id, '_jurnal_materi', $materi );
    update_post_meta( $post_id, '_jurnal_kegiatan', $kegiatan );
    update_post_meta( $post_id, '_jurnal_catatan', $catatan );

    remove_action( 'save_post_master_jurnal', 'qrg_save_jurnal_details' );
    wp_update_post( [
        'ID'         => $post_id,
        'post_title' => qrg_jurnal_build_title( $mapel_id, $kelas_id, $tanggal ),
    ] );
    add_action( 'save_post_master_jurnal', 'qrg_save_jurnal_details' );
}

add_filter( 'manage_master_jurnal_posts_columns', 'qrg_set_jurnal_columns' );
function qrg_set_jurnal_columns( $columns ) {
    return [
        'cb'      => $columns['cb'],
        'title'   => 'Jurnal',
        'tanggal' => 'Tanggal',
        'mapel'   => 'Mapel',
        'kelas'   => 'Kelas',
        'guru'    => 'Guru',
        'materi'  => 'Materi',
        'date'    => 'Dibuat',
    ];
}

add_action( 'manage_master_jurnal_posts_custom_column', 'qrg_fill_jurnal_columns', 10, 2 );
function qrg_fill_jurnal_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'tanggal':
            $tgl = get_post_meta( $post_id, '_jurnal_tanggal', true );
            echo $tgl ? esc_html( date_i18n( 'd M Y', strtotime( $tgl ) ) ) : '—';
            break;
        case 'mapel':
            $mapel_id = (int) get_post_meta( $post_id, '_jurnal_mapel_id', true );
            echo $mapel_id
                ? esc_html( function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $mapel_id ) : get_the_title( $mapel_id ) )
                : '—';
            break;
        case 'kelas':
            $kelas_id = (int) get_post_meta( $post_id, '_jurnal_kelas_id', true );
            echo $kelas_id
                ? esc_html( function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas_id ) : get_the_title( $kelas_id ) )
                : '—';
            break;
        case 'guru':
            $guru_id = (int) get_post_meta( $post_id, '_jurnal_guru_id', true );
            echo $guru_id ? esc_html( get_the_title( $guru_id ) ) : '—';
            break;
        case 'materi':
            $materi = get_post_meta( $post_id, '_jurnal_materi', true );
            echo $materi ? esc_html( wp_trim_words( $materi, 10, '…' ) ) : '—';
            break;
    }
}

add_action( 'restrict_manage_posts', 'qrg_jurnal_mapel_filter_dropdown' );
function qrg_jurnal_mapel_filter_dropdown() {
    global $typenow;
    if ( $typenow !== 'master_jurnal' ) {
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

add_action( 'pre_get_posts', 'qrg_filter_jurnal_by_mapel' );
function qrg_filter_jurnal_by_mapel( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_jurnal' ) {
        return;
    }
    $mapel_id = isset( $_GET['mapel_id'] ) ? absint( $_GET['mapel_id'] ) : 0;
    if ( ! $mapel_id ) {
        return;
    }
    $meta_query   = (array) $query->get( 'meta_query' );
    $meta_query[] = [
        'key'   => '_jurnal_mapel_id',
        'value' => (string) $mapel_id,
    ];
    $query->set( 'meta_query', $meta_query );
}
