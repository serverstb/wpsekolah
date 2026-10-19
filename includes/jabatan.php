<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// CPT Master Jabatan (tersembunyi dari sidebar, akses via tombol Data Guru)
add_action( 'init', 'qrg_register_jabatan_cpt' );
function qrg_register_jabatan_cpt() {
    register_post_type( 'master_jabatan', [
        'labels' => [
            'name'               => 'Master Jabatan',
            'singular_name'      => 'Jabatan',
            'menu_name'          => 'Jabatan',
            'add_new'            => 'Tambah Jabatan',
            'add_new_item'       => 'Tambah Jabatan Baru',
            'edit_item'          => 'Edit Jabatan',
            'all_items'          => 'Master Jabatan',
            'search_items'       => 'Cari Jabatan',
            'not_found'          => 'Jabatan tidak ditemukan',
            'not_found_in_trash' => 'Tidak ada jabatan di tong sampah',
        ],
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'supports'            => [ 'title' ],
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
    ] );
}

add_filter( 'enter_title_here', 'qrg_jabatan_title_placeholder', 10, 2 );
function qrg_jabatan_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_jabatan' ) {
        return 'Contoh: Guru Mapel / Wali Kelas / Kepala Lab';
    }
    return $title;
}

add_filter( 'parent_file', 'qrg_jabatan_parent_file' );
function qrg_jabatan_parent_file( $parent_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_jabatan' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_jabatan_submenu_file' );
function qrg_jabatan_submenu_file( $submenu_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_jabatan' ) {
        return 'edit.php?post_type=master_guru';
    }
    return $submenu_file;
}

add_action( 'admin_footer-edit.php', 'qrg_jabatan_header_buttons' );
add_action( 'admin_footer-post.php', 'qrg_jabatan_header_buttons' );
add_action( 'admin_footer-post-new.php', 'qrg_jabatan_header_buttons' );
function qrg_jabatan_header_buttons() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_jabatan' ) {
        return;
    }
    $guru_url = admin_url( 'edit.php?post_type=master_guru' );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) return;
        var $existing = $wrap.siblings('.page-title-action').first();
        var $back = $('<a href="<?php echo esc_url( $guru_url ); ?>" class="page-title-action">← Data Guru</a>');
        if ($existing.length) {
            $back.insertBefore($existing);
        } else {
            $back.insertAfter($wrap);
        }
    });
    </script>
    <?php
}

add_filter( 'post_row_actions', 'qrg_jabatan_row_actions', 10, 2 );
function qrg_jabatan_row_actions( $actions, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_jabatan' ) {
        unset( $actions['view'] );
        unset( $actions['inline hide-if-no-js'] );
    }
    return $actions;
}

add_filter( 'get_sample_permalink_html', 'qrg_jabatan_hide_permalink_box', 10, 2 );
function qrg_jabatan_hide_permalink_box( $html, $post_id ) {
    if ( get_post_type( $post_id ) === 'master_jabatan' ) {
        return '';
    }
    return $html;
}

add_action( 'add_meta_boxes', 'qrg_jabatan_meta_boxes' );
function qrg_jabatan_meta_boxes() {
    add_meta_box(
        'jabatan_detail',
        'Detail Jabatan',
        'qrg_jabatan_fields_callback',
        'master_jabatan',
        'normal',
        'high'
    );
}

function qrg_jabatan_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_jabatan_meta', 'qrg_jabatan_nonce' );
    $keterangan = get_post_meta( $post->ID, '_jabatan_keterangan', true );
    $tunjangan  = (int) get_post_meta( $post->ID, '_jabatan_tunjangan', true );
    ?>
    <table class="form-table">
        <tr>
            <th><label for="jabatan_keterangan">Keterangan</label></th>
            <td>
                <input type="text" id="jabatan_keterangan" name="jabatan_keterangan" value="<?php echo esc_attr( $keterangan ); ?>" class="regular-text" placeholder="Opsional, contoh: Struktural / Non-struktural">
                <p class="description">Nama jabatan diisi di kolom judul di atas.</p>
            </td>
        </tr>
        <tr>
            <th><label for="jabatan_tunjangan">Tunjangan Jabatan</label></th>
            <td>
                <input type="number" id="jabatan_tunjangan" name="jabatan_tunjangan" value="<?php echo esc_attr( $tunjangan ); ?>" min="0" step="1000" class="regular-text" placeholder="0">
                <p class="description">Dipakai otomatis di <strong>Rumus Gaji Guru</strong> untuk komponen Tunjangan Jabatan.</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_master_jabatan', 'qrg_save_jabatan_details' );
function qrg_save_jabatan_details( $post_id ) {
    if ( ! isset( $_POST['qrg_jabatan_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_jabatan_nonce'], 'qrg_save_jabatan_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) {
        return;
    }

    if ( isset( $_POST['jabatan_keterangan'] ) ) {
        update_post_meta( $post_id, '_jabatan_keterangan', sanitize_text_field( wp_unslash( $_POST['jabatan_keterangan'] ) ) );
    }
    if ( isset( $_POST['jabatan_tunjangan'] ) ) {
        update_post_meta( $post_id, '_jabatan_tunjangan', absint( $_POST['jabatan_tunjangan'] ) );
    }
}

/**
 * Ambil semua jabatan terdaftar.
 *
 * @return WP_Post[]
 */
function qrg_get_daftar_jabatan() {
    return get_posts( [
        'post_type'      => 'master_jabatan',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
}

/**
 * Ambil nominal tunjangan dari master jabatan.
 *
 * @param int $jabatan_id
 * @return int
 */
function qrg_get_jabatan_tunjangan( $jabatan_id ) {
    $jabatan_id = (int) $jabatan_id;
    if ( ! $jabatan_id || get_post_type( $jabatan_id ) !== 'master_jabatan' ) {
        return 0;
    }
    return (int) get_post_meta( $jabatan_id, '_jabatan_tunjangan', true );
}

/**
 * Ambil tunjangan jabatan untuk seorang guru.
 *
 * @param int $guru_id
 * @param int $fallback Nilai cadangan jika guru belum punya jabatan.
 * @return int
 */
function qrg_get_tunjangan_jabatan_for_guru( $guru_id, $fallback = 0 ) {
    $guru_id = (int) $guru_id;
    if ( ! $guru_id ) {
        return (int) $fallback;
    }

    $jabatan_id = (int) get_post_meta( $guru_id, '_guru_jabatan_id', true );
    if ( $jabatan_id ) {
        return qrg_get_jabatan_tunjangan( $jabatan_id );
    }

    return (int) $fallback;
}

/**
 * Label jabatan dari ID atau teks.
 *
 * @param int|string $jabatan_value ID atau nama jabatan.
 * @return string
 */
function qrg_get_jabatan_label( $jabatan_value ) {
    if ( $jabatan_value === '' || $jabatan_value === null ) {
        return '';
    }

    if ( is_numeric( $jabatan_value ) ) {
        $post = get_post( (int) $jabatan_value );
        if ( $post && $post->post_type === 'master_jabatan' ) {
            return $post->post_title;
        }
    }

    return (string) $jabatan_value;
}

/**
 * Ambil label jabatan guru (prioritas ID master, fallback teks lama).
 *
 * @param int $guru_id
 * @return string
 */
function qrg_get_guru_jabatan_label( $guru_id ) {
    $jabatan_id = (int) get_post_meta( $guru_id, '_guru_jabatan_id', true );
    if ( $jabatan_id ) {
        $label = qrg_get_jabatan_label( $jabatan_id );
        if ( $label !== '' ) {
            return $label;
        }
    }

    return (string) get_post_meta( $guru_id, '_guru_jabatan', true );
}

/**
 * Hitung jumlah guru yang memakai jabatan tertentu.
 *
 * @param int $jabatan_id
 * @return int
 */
function qrg_count_guru_by_jabatan( $jabatan_id ) {
    $jabatan_id = (int) $jabatan_id;
    if ( ! $jabatan_id ) {
        return 0;
    }

    $query = new WP_Query( [
        'post_type'      => 'master_guru',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_guru_jabatan_id',
                'value' => $jabatan_id,
                'type'  => 'NUMERIC',
            ],
        ],
        'no_found_rows'  => false,
    ] );

    return (int) $query->found_posts;
}

add_filter( 'manage_master_jabatan_posts_columns', 'qrg_set_jabatan_columns' );
function qrg_set_jabatan_columns( $columns ) {
    $new = [];
    $new['cb']           = $columns['cb'];
    $new['title']        = 'Nama Jabatan';
    $new['keterangan']   = 'Keterangan';
    $new['tunjangan']    = 'Tunjangan';
    $new['jumlah_guru']  = 'Jumlah Guru';
    $new['date']         = 'Dibuat';
    return $new;
}

add_action( 'manage_master_jabatan_posts_custom_column', 'qrg_fill_jabatan_columns', 10, 2 );
function qrg_fill_jabatan_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'keterangan':
            $ket = get_post_meta( $post_id, '_jabatan_keterangan', true );
            echo esc_html( $ket ? $ket : '—' );
            break;
        case 'tunjangan':
            $tj = qrg_get_jabatan_tunjangan( $post_id );
            if ( $tj > 0 ) {
                echo '<strong>Rp ' . esc_html( number_format( $tj, 0, ',', '.' ) ) . '</strong>';
            } else {
                echo '<span style="color:#94a3b8;">Rp 0</span>';
            }
            break;
        case 'jumlah_guru':
            $count = qrg_count_guru_by_jabatan( $post_id );
            if ( $count > 0 ) {
                $url = add_query_arg(
                    [
                        'post_type'       => 'master_guru',
                        'guru_jabatan_id' => (int) $post_id,
                    ],
                    admin_url( 'edit.php' )
                );
                echo '<a href="' . esc_url( $url ) . '"><strong>' . (int) $count . '</strong></a>';
            } else {
                echo '<span style="color:#94a3b8;">0</span>';
            }
            break;
    }
}

// Filter daftar guru berdasarkan jabatan (dari kolom Jumlah Guru)
add_action( 'pre_get_posts', 'qrg_filter_guru_by_jabatan_id' );
function qrg_filter_guru_by_jabatan_id( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( $query->get( 'post_type' ) !== 'master_guru' ) {
        return;
    }
    if ( empty( $_GET['guru_jabatan_id'] ) ) {
        return;
    }
    $jabatan_id = absint( $_GET['guru_jabatan_id'] );
    if ( ! $jabatan_id ) {
        return;
    }
    $meta_query = (array) $query->get( 'meta_query' );
    $meta_query[] = [
        'key'   => '_guru_jabatan_id',
        'value' => $jabatan_id,
        'type'  => 'NUMERIC',
    ];
    $query->set( 'meta_query', $meta_query );
}
