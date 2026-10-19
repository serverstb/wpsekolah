<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Registrasi CPT Mata Pelajaran / Mapel
add_action( 'init', 'qrg_register_mapel_cpt' );
function qrg_register_mapel_cpt() {
    register_post_type( 'master_mapel', [
        'labels' => [
            'name'               => 'Mata Pelajaran',
            'singular_name'      => 'Mata Pelajaran',
            'menu_name'          => 'Mata Pelajaran',
            'add_new'            => 'Tambah Mapel',
            'add_new_item'       => 'Tambah Mata Pelajaran Baru',
            'edit_item'          => 'Edit Mata Pelajaran',
            'all_items'          => 'Mata Pelajaran',
            'search_items'       => 'Cari Mapel',
            'not_found'          => 'Mapel tidak ditemukan',
            'not_found_in_trash' => 'Tidak ada mapel di tong sampah',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => 'master-data-plugin',
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'supports'            => [ 'title' ],
    ] );
}

add_filter( 'enter_title_here', 'qrg_mapel_title_placeholder', 10, 2 );
function qrg_mapel_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_mapel' ) {
        return 'Contoh: Matematika / Bahasa Indonesia';
    }
    return $title;
}

add_filter( 'post_row_actions', 'qrg_mapel_remove_view_link', 10, 2 );
function qrg_mapel_remove_view_link( $actions, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_mapel' ) {
        unset( $actions['view'] );
    }
    return $actions;
}

add_filter( 'get_sample_permalink_html', 'qrg_mapel_hide_permalink_box', 10, 2 );
function qrg_mapel_hide_permalink_box( $html, $post_id ) {
    if ( get_post_type( $post_id ) === 'master_mapel' ) {
        return '';
    }
    return $html;
}

// Tombol Jadwal Mata Pelajaran & Daftar Tugas di samping "Tambah Mapel"
add_action( 'admin_footer-edit.php', 'qrg_mapel_header_action_button' );
function qrg_mapel_header_action_button() {
    global $typenow;
    if ( $typenow !== 'master_mapel' ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $jadwal_url = admin_url( 'edit.php?post_type=master_jadwal' );
    $tugas_url  = admin_url( 'edit.php?post_type=master_tugas' );
    $jurnal_url = admin_url( 'edit.php?post_type=master_jurnal' );
    ?>
    <script>
    jQuery(function($) {
        var $btn = $('.wrap .page-title-action').first();
        if (!$btn.length) {
            return;
        }
        var $jadwal = $('<a href="<?php echo esc_url( $jadwal_url ); ?>" class="page-title-action">Jadwal Mata Pelajaran</a>')
            .insertAfter($btn);
        var $tugas = $('<a href="<?php echo esc_url( $tugas_url ); ?>" class="page-title-action">Daftar Tugas</a>')
            .insertAfter($jadwal);
        $('<a href="<?php echo esc_url( $jurnal_url ); ?>" class="page-title-action">Jurnal</a>')
            .insertAfter($tugas);
    });
    </script>
    <?php
}

/**
 * Ambil daftar mata pelajaran.
 *
 * @param bool $aktif_saja Hanya mapel berstatus Aktif.
 * @return WP_Post[]
 */
function qrg_get_daftar_mapel( $aktif_saja = false ) {
    $posts = get_posts( [
        'post_type'      => 'master_mapel',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    if ( ! $aktif_saja ) {
        return $posts;
    }

    return array_values( array_filter( $posts, function( $post ) {
        $status = get_post_meta( $post->ID, '_mapel_status', true );
        return $status !== 'Nonaktif';
    } ) );
}

/**
 * Label mapel dari ID (atau teks lama).
 *
 * @param int|string $mapel_value ID master_mapel atau nama mapel.
 * @return string
 */
function qrg_get_mapel_label( $mapel_value ) {
    if ( $mapel_value === '' || $mapel_value === null ) {
        return '';
    }

    if ( is_numeric( $mapel_value ) ) {
        $post = get_post( (int) $mapel_value );
        if ( $post && $post->post_type === 'master_mapel' ) {
            $kode  = get_post_meta( $post->ID, '_mapel_kode', true );
            $label = $post->post_title;
            if ( $kode ) {
                $label .= ' (' . $kode . ')';
            }
            return $label;
        }
    }

    return (string) $mapel_value;
}

// 2. Meta Box Detail Mapel
add_action( 'add_meta_boxes', 'qrg_mapel_meta_boxes' );
function qrg_mapel_meta_boxes() {
    add_meta_box( 'mapel_detail', 'Detail Mata Pelajaran', 'qrg_mapel_fields_callback', 'master_mapel', 'normal', 'high' );
}

function qrg_mapel_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_mapel_meta', 'qrg_mapel_nonce' );

    $kode      = get_post_meta( $post->ID, '_mapel_kode', true );
    $deskripsi = get_post_meta( $post->ID, '_mapel_deskripsi', true );
    $status    = get_post_meta( $post->ID, '_mapel_status', true );
    if ( empty( $status ) ) {
        $status = 'Aktif';
    }
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Isi <strong>NAMA MATA PELAJARAN</strong> di kolom judul paling atas (contoh: Matematika, Bahasa Indonesia, IPA).
    </p>
    <table class="form-table">
        <tr>
            <th><label for="mapel_kode">Kode Mapel</label></th>
            <td>
                <input type="text" id="mapel_kode" name="mapel_kode" value="<?php echo esc_attr( $kode ); ?>" class="regular-text" placeholder="Contoh: MTK">
                <p class="description">Opsional. Digunakan untuk identifikasi singkat.</p>
            </td>
        </tr>
        <tr>
            <th><label for="mapel_status">Status</label></th>
            <td>
                <select id="mapel_status" name="mapel_status" style="min-width:180px;">
                    <option value="Aktif" <?php selected( $status, 'Aktif' ); ?>>Aktif</option>
                    <option value="Nonaktif" <?php selected( $status, 'Nonaktif' ); ?>>Nonaktif</option>
                </select>
                <p class="description">Hanya mapel berstatus Aktif yang muncul di form tugas pembelajaran.</p>
            </td>
        </tr>
        <tr>
            <th><label for="mapel_deskripsi">Deskripsi / Keterangan</label></th>
            <td>
                <textarea id="mapel_deskripsi" name="mapel_deskripsi" rows="4" class="large-text" placeholder="Contoh: Mata pelajaran wajib untuk seluruh kelas"><?php echo esc_textarea( $deskripsi ); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

// 3. Simpan meta mapel
add_action( 'save_post_master_mapel', 'qrg_save_mapel_details' );
function qrg_save_mapel_details( $post_id ) {
    if ( ! isset( $_POST['qrg_mapel_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_mapel_nonce'], 'qrg_save_mapel_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    if ( isset( $_POST['mapel_kode'] ) ) {
        update_post_meta( $post_id, '_mapel_kode', sanitize_text_field( wp_unslash( $_POST['mapel_kode'] ) ) );
    }
    if ( isset( $_POST['mapel_deskripsi'] ) ) {
        update_post_meta( $post_id, '_mapel_deskripsi', sanitize_textarea_field( wp_unslash( $_POST['mapel_deskripsi'] ) ) );
    }
    if ( isset( $_POST['mapel_status'] ) ) {
        $status = sanitize_text_field( wp_unslash( $_POST['mapel_status'] ) );
        if ( ! in_array( $status, [ 'Aktif', 'Nonaktif' ], true ) ) {
            $status = 'Aktif';
        }
        update_post_meta( $post_id, '_mapel_status', $status );
    }
}

// 4. Kolom tabel daftar mapel
add_filter( 'manage_master_mapel_posts_columns', 'qrg_set_mapel_columns' );
function qrg_set_mapel_columns( $columns ) {
    return [
        'cb'        => $columns['cb'],
        'title'     => 'Nama Mapel',
        'kode'      => 'Kode',
        'status'    => 'Status',
        'deskripsi' => 'Keterangan',
        'date'      => 'Tanggal',
    ];
}

add_action( 'manage_master_mapel_posts_custom_column', 'qrg_fill_mapel_columns', 10, 2 );
function qrg_fill_mapel_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'kode':
            $kode = get_post_meta( $post_id, '_mapel_kode', true );
            echo esc_html( $kode ? $kode : '—' );
            break;
        case 'status':
            $status = get_post_meta( $post_id, '_mapel_status', true );
            if ( empty( $status ) ) {
                $status = 'Aktif';
            }
            $color = ( $status === 'Aktif' ) ? '#16a34a' : '#dc2626';
            echo '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $status ) . '</strong>';
            break;
        case 'deskripsi':
            $deskripsi = get_post_meta( $post_id, '_mapel_deskripsi', true );
            if ( ! $deskripsi ) {
                echo '—';
                break;
            }
            echo esc_html( wp_trim_words( $deskripsi, 12, '…' ) );
            break;
    }
}
