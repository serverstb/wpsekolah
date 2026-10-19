<?php
/**
 * Modul Perpustakaan — upload PDF di admin, tampil di /sekolah/buku
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -------------------------------------------------------------------------
// 1. CPT
// -------------------------------------------------------------------------
add_action( 'init', 'qrg_register_perpustakaan_cpt' );
function qrg_register_perpustakaan_cpt() {
    register_post_type( 'master_perpustakaan', [
        'labels' => [
            'name'               => 'Perpustakaan',
            'singular_name'      => 'Buku / Dokumen',
            'menu_name'          => 'Perpustakaan',
            'add_new'            => 'Tambah PDF',
            'add_new_item'       => 'Tambah Buku / Dokumen PDF',
            'edit_item'          => 'Edit Buku / Dokumen',
            'all_items'          => 'Perpustakaan',
            'search_items'       => 'Cari Buku',
            'not_found'          => 'Belum ada dokumen di perpustakaan',
            'not_found_in_trash' => 'Tidak ada di tong sampah',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => 'master-data-plugin',
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'supports'            => [ 'title', 'thumbnail' ],
        'menu_icon'           => 'dashicons-book-alt',
    ] );
}

add_filter( 'enter_title_here', 'qrg_perpustakaan_title_placeholder', 10, 2 );
function qrg_perpustakaan_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_perpustakaan' ) {
        return 'Contoh: Buku Matematika Kelas 7 / Modul PKN';
    }
    return $title;
}

add_filter( 'post_row_actions', 'qrg_perpustakaan_remove_view_link', 10, 2 );
function qrg_perpustakaan_remove_view_link( $actions, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_perpustakaan' ) {
        unset( $actions['view'] );
    }
    return $actions;
}

add_filter( 'get_sample_permalink_html', 'qrg_perpustakaan_hide_permalink_box', 10, 2 );
function qrg_perpustakaan_hide_permalink_box( $html, $post_id ) {
    if ( get_post_type( $post_id ) === 'master_perpustakaan' ) {
        return '';
    }
    return $html;
}

/**
 * MIME whitelist khusus PDF.
 *
 * @return array
 */
function qrg_upload_mimes_pdf() {
    return [
        'pdf' => 'application/pdf',
    ];
}

/**
 * Opsi kategori perpustakaan.
 *
 * @return array
 */
function qrg_perpustakaan_kategori_options() {
    return [
        'buku'       => 'Buku Pelajaran',
        'modul'      => 'Modul / LKS',
        'referensi'  => 'Referensi',
        'peraturan'  => 'Peraturan / Panduan',
        'lainnya'    => 'Lainnya',
    ];
}

// -------------------------------------------------------------------------
// 2. Meta boxes
// -------------------------------------------------------------------------
add_action( 'add_meta_boxes', 'qrg_perpustakaan_meta_boxes' );
function qrg_perpustakaan_meta_boxes() {
    add_meta_box(
        'qrg_perpustakaan_detail',
        'Detail & File PDF',
        'qrg_perpustakaan_fields_callback',
        'master_perpustakaan',
        'normal',
        'high'
    );
}

function qrg_perpustakaan_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_perpustakaan_meta', 'qrg_perpustakaan_nonce' );

    $penulis   = (string) get_post_meta( $post->ID, '_perpus_penulis', true );
    $kategori  = (string) get_post_meta( $post->ID, '_perpus_kategori', true );
    $deskripsi = (string) get_post_meta( $post->ID, '_perpus_deskripsi', true );
    $pdf_url   = (string) get_post_meta( $post->ID, '_perpus_pdf_url', true );
    $pdf_name  = (string) get_post_meta( $post->ID, '_perpus_pdf_name', true );
    $pdf_size  = (int) get_post_meta( $post->ID, '_perpus_pdf_size', true );
    $status    = (string) get_post_meta( $post->ID, '_perpus_status', true );
    if ( $status === '' ) {
        $status = 'Aktif';
    }
    $kategori_opts = qrg_perpustakaan_kategori_options();
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="perpus_penulis">Penulis / Sumber</label></th>
            <td>
                <input type="text" class="regular-text" id="perpus_penulis" name="perpus_penulis"
                       value="<?php echo esc_attr( $penulis ); ?>" placeholder="Opsional">
            </td>
        </tr>
        <tr>
            <th><label for="perpus_kategori">Kategori</label></th>
            <td>
                <select id="perpus_kategori" name="perpus_kategori">
                    <?php foreach ( $kategori_opts as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $kategori, $key ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="perpus_deskripsi">Deskripsi Singkat</label></th>
            <td>
                <textarea id="perpus_deskripsi" name="perpus_deskripsi" rows="3" class="large-text"
                          placeholder="Ringkasan isi dokumen"><?php echo esc_textarea( $deskripsi ); ?></textarea>
            </td>
        </tr>
        <tr>
            <th><label for="perpus_status">Status</label></th>
            <td>
                <select id="perpus_status" name="perpus_status">
                    <option value="Aktif" <?php selected( $status, 'Aktif' ); ?>>Aktif (tampil di /sekolah/buku)</option>
                    <option value="Nonaktif" <?php selected( $status, 'Nonaktif' ); ?>>Nonaktif</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="perpus_pdf">File PDF</label></th>
            <td>
                <?php if ( $pdf_url ) : ?>
                    <p style="margin:0 0 10px;">
                        <strong>File saat ini:</strong>
                        <a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( $pdf_name ? $pdf_name : 'Lihat PDF' ); ?>
                        </a>
                        <?php if ( $pdf_size > 0 ) : ?>
                            <span style="color:#646970;">(<?php echo esc_html( size_format( $pdf_size ) ); ?>)</span>
                        <?php endif; ?>
                    </p>
                    <label style="display:inline-flex;align-items:center;gap:6px;margin-bottom:10px;">
                        <input type="checkbox" name="perpus_pdf_hapus" value="1"> Hapus file PDF
                    </label>
                <?php endif; ?>
                <input type="file" id="perpus_pdf" name="perpus_pdf" accept="application/pdf,.pdf">
                <p class="description">Hanya file PDF. Maksimal sesuai batas upload server. Unggah ulang untuk mengganti file.</p>
            </td>
        </tr>
    </table>
    <p class="description">
        Dokumen aktif akan tampil di halaman publik
        <a href="<?php echo esc_url( function_exists( 'qrg_get_perpustakaan_url' ) ? qrg_get_perpustakaan_url() : home_url( '/sekolah/buku/' ) ); ?>" target="_blank" rel="noopener"><code>/sekolah/buku/</code></a>
    </p>
    <?php
}

// Pastikan form edit mendukung enctype multipart
add_action( 'post_edit_form_tag', 'qrg_perpustakaan_form_enctype' );
function qrg_perpustakaan_form_enctype() {
    global $post;
    if ( $post && $post->post_type === 'master_perpustakaan' ) {
        echo ' enctype="multipart/form-data"';
    }
}

add_action( 'save_post_master_perpustakaan', 'qrg_save_perpustakaan_details' );
function qrg_save_perpustakaan_details( $post_id ) {
    if ( ! isset( $_POST['qrg_perpustakaan_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_perpustakaan_nonce'] ) ), 'qrg_save_perpustakaan_meta' ) ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) {
        return;
    }

    $penulis   = isset( $_POST['perpus_penulis'] ) ? sanitize_text_field( wp_unslash( $_POST['perpus_penulis'] ) ) : '';
    $kategori  = isset( $_POST['perpus_kategori'] ) ? sanitize_key( wp_unslash( $_POST['perpus_kategori'] ) ) : 'lainnya';
    $deskripsi = isset( $_POST['perpus_deskripsi'] ) ? sanitize_textarea_field( wp_unslash( $_POST['perpus_deskripsi'] ) ) : '';
    $status    = isset( $_POST['perpus_status'] ) ? sanitize_text_field( wp_unslash( $_POST['perpus_status'] ) ) : 'Aktif';

    $opts = qrg_perpustakaan_kategori_options();
    if ( ! isset( $opts[ $kategori ] ) ) {
        $kategori = 'lainnya';
    }
    if ( ! in_array( $status, [ 'Aktif', 'Nonaktif' ], true ) ) {
        $status = 'Aktif';
    }

    update_post_meta( $post_id, '_perpus_penulis', $penulis );
    update_post_meta( $post_id, '_perpus_kategori', $kategori );
    update_post_meta( $post_id, '_perpus_deskripsi', $deskripsi );
    update_post_meta( $post_id, '_perpus_status', $status );

    if ( ! empty( $_POST['perpus_pdf_hapus'] ) ) {
        delete_post_meta( $post_id, '_perpus_pdf_url' );
        delete_post_meta( $post_id, '_perpus_pdf_path' );
        delete_post_meta( $post_id, '_perpus_pdf_name' );
        delete_post_meta( $post_id, '_perpus_pdf_size' );
    }

    if ( ! empty( $_FILES['perpus_pdf']['name'] ) ) {
        $upload = function_exists( 'qrg_handle_upload_safe' )
            ? qrg_handle_upload_safe( $_FILES['perpus_pdf'], qrg_upload_mimes_pdf() )
            : null;

        if ( is_array( $upload ) && ! empty( $upload['url'] ) && empty( $upload['error'] ) ) {
            update_post_meta( $post_id, '_perpus_pdf_url', esc_url_raw( $upload['url'] ) );
            update_post_meta( $post_id, '_perpus_pdf_path', sanitize_text_field( $upload['file'] ) );
            update_post_meta( $post_id, '_perpus_pdf_name', sanitize_file_name( $_FILES['perpus_pdf']['name'] ) );
            update_post_meta( $post_id, '_perpus_pdf_size', isset( $_FILES['perpus_pdf']['size'] ) ? absint( $_FILES['perpus_pdf']['size'] ) : 0 );
        } elseif ( is_array( $upload ) && ! empty( $upload['error'] ) ) {
            set_transient( 'qrg_perpus_upload_error_' . get_current_user_id(), $upload['error'], 45 );
        }
    }
}

add_action( 'admin_notices', 'qrg_perpustakaan_upload_notice' );
function qrg_perpustakaan_upload_notice() {
    $key = 'qrg_perpus_upload_error_' . get_current_user_id();
    $err = get_transient( $key );
    if ( ! $err ) {
        return;
    }
    delete_transient( $key );
    echo '<div class="notice notice-error is-dismissible"><p>Upload PDF gagal: ' . esc_html( $err ) . '</p></div>';
}

// -------------------------------------------------------------------------
// 3. Kolom list admin
// -------------------------------------------------------------------------
add_filter( 'manage_master_perpustakaan_posts_columns', 'qrg_perpustakaan_columns' );
function qrg_perpustakaan_columns( $columns ) {
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'title' ) {
            $new['perpus_kategori'] = 'Kategori';
            $new['perpus_penulis']  = 'Penulis';
            $new['perpus_pdf']      = 'PDF';
            $new['perpus_status']   = 'Status';
        }
    }
    return $new;
}

add_action( 'manage_master_perpustakaan_posts_custom_column', 'qrg_perpustakaan_column_content', 10, 2 );
function qrg_perpustakaan_column_content( $column, $post_id ) {
    if ( $column === 'perpus_kategori' ) {
        $opts = qrg_perpustakaan_kategori_options();
        $key  = (string) get_post_meta( $post_id, '_perpus_kategori', true );
        echo esc_html( isset( $opts[ $key ] ) ? $opts[ $key ] : '—' );
        return;
    }
    if ( $column === 'perpus_penulis' ) {
        $p = (string) get_post_meta( $post_id, '_perpus_penulis', true );
        echo esc_html( $p !== '' ? $p : '—' );
        return;
    }
    if ( $column === 'perpus_pdf' ) {
        $url  = (string) get_post_meta( $post_id, '_perpus_pdf_url', true );
        $size = (int) get_post_meta( $post_id, '_perpus_pdf_size', true );
        if ( $url ) {
            echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">PDF</a>';
            if ( $size > 0 ) {
                echo ' <span style="color:#646970;">(' . esc_html( size_format( $size ) ) . ')</span>';
            }
        } else {
            echo '<span style="color:#b32d2e;">Belum ada</span>';
        }
        return;
    }
    if ( $column === 'perpus_status' ) {
        $s = (string) get_post_meta( $post_id, '_perpus_status', true );
        if ( $s === '' ) {
            $s = 'Aktif';
        }
        $color = $s === 'Aktif' ? '#00a32a' : '#646970';
        echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600;">' . esc_html( $s ) . '</span>';
    }
}

add_action( 'admin_footer-edit.php', 'qrg_perpustakaan_header_link' );
function qrg_perpustakaan_header_link() {
    global $typenow;
    if ( $typenow !== 'master_perpustakaan' || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $url = function_exists( 'qrg_get_perpustakaan_url' ) ? qrg_get_perpustakaan_url() : home_url( '/sekolah/buku/' );
    ?>
    <script>
    jQuery(function($){
        var $btn = $('.wrap .page-title-action').first();
        if ($btn.length) {
            $('<a href="<?php echo esc_url( $url ); ?>" class="page-title-action" target="_blank" rel="noopener">Lihat Halaman Publik</a>').insertAfter($btn);
        }
    });
    </script>
    <?php
}

// -------------------------------------------------------------------------
// 4. Data helpers
// -------------------------------------------------------------------------
/**
 * Ambil daftar dokumen perpustakaan aktif.
 *
 * @param array $args Opsional: kategori, search, limit.
 * @return array[]
 */
function qrg_get_perpustakaan_items( $args = [] ) {
    $args = wp_parse_args( $args, [
        'kategori' => '',
        'search'   => '',
        'limit'    => -1,
    ] );

    $query_args = [
        'post_type'      => 'master_perpustakaan',
        'posts_per_page' => (int) $args['limit'],
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ];

    if ( $args['search'] !== '' ) {
        $query_args['s'] = sanitize_text_field( $args['search'] );
    }

    $meta_query = [
        'relation' => 'AND',
        [
            'relation' => 'OR',
            [
                'key'   => '_perpus_status',
                'value' => 'Aktif',
            ],
            [
                'key'     => '_perpus_status',
                'compare' => 'NOT EXISTS',
            ],
        ],
        [
            'key'     => '_perpus_pdf_url',
            'compare' => 'EXISTS',
        ],
    ];

    if ( $args['kategori'] !== '' ) {
        $meta_query[] = [
            'key'   => '_perpus_kategori',
            'value' => sanitize_key( $args['kategori'] ),
        ];
    }

    $query_args['meta_query'] = $meta_query;

    $posts = get_posts( $query_args );
    $opts  = qrg_perpustakaan_kategori_options();
    $items = [];

    foreach ( $posts as $post ) {
        $kat = (string) get_post_meta( $post->ID, '_perpus_kategori', true );
        $url = (string) get_post_meta( $post->ID, '_perpus_pdf_url', true );
        if ( $url === '' ) {
            continue;
        }
        $items[] = [
            'id'         => (int) $post->ID,
            'title'      => get_the_title( $post ),
            'penulis'    => (string) get_post_meta( $post->ID, '_perpus_penulis', true ),
            'kategori'   => $kat,
            'kategori_label' => isset( $opts[ $kat ] ) ? $opts[ $kat ] : 'Lainnya',
            'deskripsi'  => (string) get_post_meta( $post->ID, '_perpus_deskripsi', true ),
            'pdf_url'    => $url,
            'pdf_name'   => (string) get_post_meta( $post->ID, '_perpus_pdf_name', true ),
            'pdf_size'   => (int) get_post_meta( $post->ID, '_perpus_pdf_size', true ),
            'thumb'      => get_the_post_thumbnail_url( $post->ID, 'medium' ),
        ];
    }

    return $items;
}

function qrg_get_perpustakaan_url( $args = [] ) {
    $baca = isset( $args['baca'] ) ? absint( $args['baca'] ) : 0;
    if ( $baca > 0 ) {
        $url = function_exists( 'qrg_get_sekolah_portal_url' )
            ? trailingslashit( qrg_get_sekolah_portal_url( 'buku' ) ) . 'baca/' . $baca . '/'
            : home_url( '/sekolah/buku/baca/' . $baca . '/' );
        unset( $args['baca'] );
    } else {
        $url = function_exists( 'qrg_get_sekolah_portal_url' )
            ? qrg_get_sekolah_portal_url( 'buku' )
            : home_url( '/sekolah/buku/' );
    }
    if ( ! empty( $args ) && is_array( $args ) ) {
        $url = add_query_arg( $args, $url );
    }
    return $url;
}

// -------------------------------------------------------------------------
// 5. Rewrite /sekolah/buku (+ redirect /perpustakaan)
// -------------------------------------------------------------------------
add_action( 'init', 'qrg_perpustakaan_rewrite_rules' );
function qrg_perpustakaan_rewrite_rules() {
    add_rewrite_rule( '^sekolah/buku/?$', 'index.php?qrg_sekolah_portal=buku', 'top' );
    add_rewrite_rule( '^sekolah/buku/baca/([0-9]+)/?$', 'index.php?qrg_sekolah_portal=buku&qrg_perpus_baca=$matches[1]', 'top' );
    add_rewrite_rule( '^sekolah/buku/pdf/([0-9]+)/?$', 'index.php?qrg_sekolah_portal=buku&qrg_perpus_pdf=$matches[1]', 'top' );
    add_rewrite_rule( '^perpustakaan/?$', 'index.php?qrg_perpus_legacy=1', 'top' );
    add_rewrite_rule( '^perpustakaan/baca/([0-9]+)/?$', 'index.php?qrg_perpus_legacy=1&qrg_perpus_baca=$matches[1]', 'top' );
}

add_filter( 'query_vars', 'qrg_perpustakaan_query_vars' );
function qrg_perpustakaan_query_vars( $vars ) {
    $vars[] = 'qrg_perpus_legacy';
    $vars[] = 'qrg_perpus_baca';
    $vars[] = 'qrg_perpus_pdf';
    return $vars;
}

add_action( 'admin_init', 'qrg_perpustakaan_flush_rewrite_once' );
function qrg_perpustakaan_flush_rewrite_once() {
    if ( get_option( 'qrg_perpustakaan_rewrite_flushed_v4' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'qrg_perpustakaan_rewrite_flushed_v4', 1 );
}

add_action( 'template_redirect', 'qrg_perpustakaan_template_redirect', 5 );
function qrg_perpustakaan_template_redirect() {
    if ( (int) get_query_var( 'qrg_perpus_legacy' ) !== 1 ) {
        return;
    }

    $target = function_exists( 'qrg_get_perpustakaan_url' )
        ? qrg_get_perpustakaan_url()
        : home_url( '/sekolah/buku/' );
    $baca_id = (int) get_query_var( 'qrg_perpus_baca' );
    if ( ! $baca_id && isset( $_GET['baca'] ) ) {
        $baca_id = absint( $_GET['baca'] );
    }
    if ( $baca_id > 0 ) {
        $target = function_exists( 'qrg_get_perpustakaan_url' )
            ? qrg_get_perpustakaan_url( [ 'baca' => $baca_id ] )
            : home_url( '/sekolah/buku/baca/' . $baca_id . '/' );
    } elseif ( ! empty( $_GET ) && is_array( $_GET ) ) {
        $args = wp_unslash( $_GET );
        unset( $args['qrg_perpus_legacy'], $args['qrg_perpus_baca'] );
        if ( ! empty( $args ) ) {
            $target = add_query_arg( $args, $target );
        }
    }

    wp_safe_redirect( $target, 301 );
    exit;
}

/**
 * Ambil satu item perpustakaan aktif by ID.
 *
 * @param int $id Post ID.
 * @return array|null
 */
function qrg_get_perpustakaan_item( $id ) {
    $id = (int) $id;
    if ( $id <= 0 || get_post_type( $id ) !== 'master_perpustakaan' || get_post_status( $id ) !== 'publish' ) {
        return null;
    }
    $status = (string) get_post_meta( $id, '_perpus_status', true );
    if ( $status === 'Nonaktif' ) {
        return null;
    }
    $url = (string) get_post_meta( $id, '_perpus_pdf_url', true );
    if ( $url === '' ) {
        return null;
    }
    $opts = qrg_perpustakaan_kategori_options();
    $kat  = (string) get_post_meta( $id, '_perpus_kategori', true );
    return [
        'id'             => $id,
        'title'          => get_the_title( $id ),
        'penulis'        => (string) get_post_meta( $id, '_perpus_penulis', true ),
        'kategori'       => $kat,
        'kategori_label' => isset( $opts[ $kat ] ) ? $opts[ $kat ] : 'Lainnya',
        'deskripsi'      => (string) get_post_meta( $id, '_perpus_deskripsi', true ),
        'pdf_url'        => $url,
        'pdf_stream_url' => qrg_get_perpustakaan_pdf_stream_url( $id ),
        'pdf_name'       => (string) get_post_meta( $id, '_perpus_pdf_name', true ),
        'pdf_size'       => (int) get_post_meta( $id, '_perpus_pdf_size', true ),
        'thumb'          => get_the_post_thumbnail_url( $id, 'medium' ),
    ];
}

/**
 * URL stream PDF same-origin untuk PDF.js flipbook.
 *
 * @param int $id Post ID.
 * @return string
 */
function qrg_get_perpustakaan_pdf_stream_url( $id ) {
    $id = (int) $id;
    if ( $id <= 0 ) {
        return '';
    }
    return function_exists( 'qrg_get_sekolah_portal_url' )
        ? trailingslashit( qrg_get_sekolah_portal_url( 'buku' ) ) . 'pdf/' . $id . '/'
        : home_url( '/sekolah/buku/pdf/' . $id . '/' );
}

/**
 * Path lokal dari URL upload WordPress.
 *
 * @param string $url URL file.
 * @return string|null
 */
function qrg_perpustakaan_pdf_local_path( $url ) {
    $url = (string) $url;
    if ( $url === '' ) {
        return null;
    }
    $upload = wp_upload_dir();
    if ( ! empty( $upload['baseurl'] ) && ! empty( $upload['basedir'] ) && strpos( $url, $upload['baseurl'] ) === 0 ) {
        $path = $upload['basedir'] . substr( $url, strlen( $upload['baseurl'] ) );
        return file_exists( $path ) ? $path : null;
    }
    $home = home_url( '/' );
    if ( strpos( $url, $home ) === 0 ) {
        $rel  = ltrim( substr( $url, strlen( $home ) ), '/' );
        $path = ABSPATH . $rel;
        return file_exists( $path ) ? $path : null;
    }
    return null;
}

/**
 * Stream PDF untuk flipbook (same-origin, auth siswa).
 */
add_action( 'template_redirect', 'qrg_perpustakaan_pdf_stream_handler', 4 );
function qrg_perpustakaan_pdf_stream_handler() {
    $id = (int) get_query_var( 'qrg_perpus_pdf' );
    if ( $id <= 0 && isset( $_SERVER['REQUEST_URI'] ) ) {
        $path = wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        if ( is_string( $path ) && preg_match( '#/sekolah/buku/pdf/([0-9]+)#', $path, $m ) ) {
            $id = (int) $m[1];
        }
    }
    if ( $id <= 0 ) {
        return;
    }

    $auth_id = function_exists( 'qrg_pembayaran_get_auth_siswa_id' ) ? qrg_pembayaran_get_auth_siswa_id() : 0;
    if ( $auth_id <= 0 ) {
        status_header( 403 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo 'Login diperlukan.';
        exit;
    }

    $item = qrg_get_perpustakaan_item( $id );
    if ( ! $item ) {
        status_header( 404 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo 'Dokumen tidak ditemukan.';
        exit;
    }

    $path = qrg_perpustakaan_pdf_local_path( $item['pdf_url'] );
    if ( ! $path ) {
        status_header( 404 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo 'File PDF tidak ditemukan di server.';
        exit;
    }

    $filename = $item['pdf_name'] !== '' ? $item['pdf_name'] : sanitize_file_name( $item['title'] . '.pdf' );
    $size     = filesize( $path );

    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: inline; filename="' . rawurlencode( $filename ) . '"' );
    header( 'Accept-Ranges: bytes' );
    header( 'Access-Control-Allow-Origin: ' . home_url() );
    if ( $size ) {
        header( 'Content-Length: ' . (int) $size );
    }

    $start = 0;
    $end   = $size ? $size - 1 : 0;
    if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d+)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m ) ) {
        $start = (int) $m[1];
        $end   = $m[2] !== '' ? (int) $m[2] : $end;
        if ( $start > $end || $start >= $size ) {
            status_header( 416 );
            exit;
        }
        status_header( 206 );
        header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
        header( 'Content-Length: ' . ( $end - $start + 1 ) );
    }

    $fp = fopen( $path, 'rb' );
    if ( ! $fp ) {
        status_header( 500 );
        exit;
    }
    if ( $start > 0 ) {
        fseek( $fp, $start );
    }
    $left = $end - $start + 1;
    while ( $left > 0 && ! feof( $fp ) ) {
        $read = min( 8192, $left );
        echo fread( $fp, $read );
        $left -= $read;
        if ( connection_aborted() ) {
            break;
        }
    }
    fclose( $fp );
    exit;
}


// -------------------------------------------------------------------------
// 6. Halaman publik — Mobile Digital Library UI
// -------------------------------------------------------------------------
function qrg_render_perpustakaan_page( $siswa_id = 0 ) {
    $siswa_id = (int) $siswa_id;
    $kategori = isset( $_GET['kategori'] ) ? sanitize_key( wp_unslash( $_GET['kategori'] ) ) : '';
    $search   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
    $tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home';
    $opts     = qrg_perpustakaan_kategori_options();
    if ( $kategori !== '' && ! isset( $opts[ $kategori ] ) ) {
        $kategori = '';
    }
    if ( ! in_array( $tab, [ 'home', 'search', 'shelf', 'profile' ], true ) ) {
        $tab = 'home';
    }
    if ( $tab === 'profile' ) {
        $tab = 'shelf';
    }

    $baca_id = (int) get_query_var( 'qrg_perpus_baca' );
    if ( ! $baca_id && isset( $_GET['baca'] ) ) {
        $baca_id = absint( $_GET['baca'] );
    }
    $baca_item = $baca_id ? qrg_get_perpustakaan_item( $baca_id ) : null;

    $all_items = qrg_get_perpustakaan_items( [
        'kategori' => $kategori,
        'search'   => $search,
    ] );
    $all_unfiltered = ( $kategori || $search !== '' )
        ? qrg_get_perpustakaan_items()
        : $all_items;

    $recommended = array_slice( $all_unfiltered, 0, 5 );
    // Prefer newest by ID for arrivals
    $by_id = $all_unfiltered;
    usort( $by_id, static function ( $a, $b ) {
        return (int) $b['id'] - (int) $a['id'];
    } );
    $arrivals = array_slice( $by_id, 0, 8 );

    $site_name   = get_bloginfo( 'name' );
    $siswa_nama  = $siswa_id ? get_the_title( $siswa_id ) : '';
    $hub_url     = function_exists( 'qrg_get_sekolah_portal_url' ) ? qrg_get_sekolah_portal_url() : home_url( '/sekolah/' );
    $logout_url  = add_query_arg( 'qrg_logout', '1', $hub_url );
    $base_url    = qrg_get_perpustakaan_url();
    $page_title  = $baca_item
        ? $baca_item['title'] . ' — Perpustakaan'
        : 'Perpustakaan — ' . $site_name;

    $back_args = array_filter( [
        'kategori' => $kategori !== '' ? $kategori : null,
        'q'        => $search !== '' ? $search : null,
        'tab'      => $tab !== 'home' ? $tab : null,
    ] );
    $back_url = $back_args ? qrg_get_perpustakaan_url( $back_args ) : $base_url;

    $items_json = wp_json_encode( array_map( static function ( $it ) {
        return [
            'id'         => $it['id'],
            'title'      => $it['title'],
            'meta'       => trim( ( $it['penulis'] ? $it['penulis'] . ' · ' : '' ) . $it['kategori_label'] ),
            'url'        => $it['pdf_url'],
            'stream_url' => qrg_get_perpustakaan_pdf_stream_url( $it['id'] ),
            'thumb'      => $it['thumb'] ? $it['thumb'] : '',
            'kat'        => $it['kategori'],
            'size'       => $it['pdf_size'] > 0 ? size_format( $it['pdf_size'] ) : '',
        ];
    }, $all_unfiltered ) );

    $extra_css = '';
    ob_start();
    ?>
    <style>
        /* Align perpustakaan dengan portal /sekolah/ */
        .qrg-sk-perpus {
            --bg: var(--ios-bg);
            --bg-elev: var(--ios-card);
            --bg-soft: var(--ios-fill);
            --ink: var(--ios-ink);
            --muted: var(--ios-secondary);
            --line: var(--ios-sep);
            --accent: var(--ios-blue);
            --accent-2: var(--ios-teal);
            --sand: var(--ios-orange);
            --sand-soft: #FFF3E0;
            --danger: var(--ios-orange);
            --success: var(--ios-green);
            --shadow: var(--ios-shadow);
            --radius: var(--ios-radius);
            --nav-h: 0px;
            --safe-t: 0px;
            --safe-b: 0px;
            --font: var(--ios-font);
            background: var(--ios-bg) !important;
        }
        .qrg-sk-perpus button, .qrg-sk-perpus input { font: inherit; }
        .qrg-sk-perpus a { color: inherit; text-decoration: none; }
        .qrg-sk-perpus .app {
            max-width: none;
            margin: 0;
            min-height: 0;
            min-width: 0;
            position: relative;
            background: transparent;
            box-shadow: none;
            border-radius: 0;
            overflow: visible;
        }
        .qrg-sk-perpus .main { padding: 0 0 8px; min-width: 0; max-width: 100%; }
        .qrg-sk-perpus .ios-hero {
            margin-bottom: 14px;
            padding: 16px 16px 18px;
            border-radius: 18px;
            color: #fff;
            background: linear-gradient(145deg, #007AFF 0%, #5856D6 55%, #30B0C7 100%);
            box-shadow: 0 12px 28px rgba(0,122,255,.22);
            position: relative;
            overflow: hidden;
        }
        .qrg-sk-perpus .ios-hero::after {
            content: "";
            position: absolute;
            right: -28px;
            top: -36px;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 20px solid rgba(255,255,255,.12);
            pointer-events: none;
        }
        .qrg-sk-perpus .ios-hero .brand {
            color: rgba(255,255,255,.85);
            position: relative;
            z-index: 1;
        }
        .qrg-sk-perpus .ios-hero h1 {
            color: #fff;
            font-size: 1.75rem;
            position: relative;
            z-index: 1;
        }
        .qrg-sk-perpus .ios-hero .sub {
            color: rgba(255,255,255,.9);
            position: relative;
            z-index: 1;
        }
        .qrg-sk-perpus .perpus-tabs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            background: #E8F1FF;
            border: 0;
            border-radius: 12px;
            padding: 3px;
            margin: 0 0 14px;
            box-shadow: none;
            backdrop-filter: none;
        }
        .qrg-sk-perpus .perpus-tabs button {
            border: 0;
            background: transparent;
            color: #5B708B;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            padding: 8px 6px;
            border-radius: 10px;
            cursor: pointer;
            min-height: 36px;
            transition: .12s ease;
        }
        .qrg-sk-perpus .perpus-tabs button.is-active {
            background: var(--ios-blue);
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,122,255,.28);
        }
        .qrg-sk-perpus .bottom-nav { display: none !important; }
        body.reader-open .ios-tabbar { display: none !important; }
        body.reader-open .qrg-sk-app.has-tabbar .qrg-sk { padding-bottom: 16px !important; }
        .qrg-sk-perpus .reader {
            position: fixed;
            inset: 0;
            z-index: 10050;
            max-width: none;
            margin: 0;
            left: 0;
            right: 0;
            width: 100%;
        }
        .qrg-sk-perpus .reader__stage {
            padding: 8px;
        }

        /* Top bar */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 14px;
        }
        .greet {
            min-width: 0;
            flex: 1;
        }
        .greet small {
            display: block;
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .02em;
        }
        .greet strong {
            display: block;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -.02em;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .icon-btn {
            width: 40px; height: 40px;
            min-width: 40px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: var(--bg-elev);
            color: var(--ink);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform .15s ease, background .15s;
            text-decoration: none;
            font-size: 15px;
            font-weight: 700;
        }
        .icon-btn:active { transform: scale(.96); background: var(--bg-soft); }
        .topbar__actions { display: flex; gap: 6px; flex-shrink: 0; }

        /* Internal tab bar (fixed bottom on mobile) */
        .tabbar {
            display: none;
        }

        /* Search */
        .search {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--ios-card);
            border: 1px solid var(--ios-sep);
            border-radius: 14px;
            padding: 4px 6px 4px 12px;
            margin-bottom: 12px;
            box-shadow: var(--ios-shadow);
        }
        .search svg { flex: 0 0 auto; color: var(--ios-secondary); }
        .search input {
            flex: 1;
            min-width: 0;
            border: 0;
            outline: none;
            background: transparent;
            padding: 12px 0;
            font-size: 15px;
            color: var(--ios-ink);
        }
        .search__filters { display: flex; gap: 4px; }
        .search__chip {
            width: 36px; height: 36px;
            border-radius: 12px;
            border: 0;
            background: var(--ios-fill);
            color: var(--ios-secondary);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .search__chip[data-kat="buku"],
        .search__chip[data-kat="modul"],
        .search__chip[data-kat="referensi"] {
            background: var(--ios-fill);
            color: var(--ios-secondary);
        }
        .search__chip.is-on {
            background: var(--ios-blue) !important;
            color: #fff !important;
            box-shadow: none;
        }

        .catalog-tools {
            margin-bottom: 2px;
            min-width: 0;
            max-width: 100%;
            overflow: visible;
        }
        .main:not(.show-search) .catalog-tools .search { display: none; }
        .main:not(.show-chips) .catalog-tools .chips { display: none; }

        /* Category chips — scroll horizontal geser kiri/kanan */
        .qrg-sk-perpus {
            overflow: visible !important;
        }
        .chips {
            display: block;
            width: auto;
            max-width: none;
            min-width: 0;
            margin: 0 -16px 14px;
            padding: 0;
            overflow-x: auto;
            overflow-y: hidden;
            overscroll-behavior-x: contain;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x;
            scrollbar-width: none;
            cursor: grab;
            -webkit-mask-image: linear-gradient(90deg, #000 0%, #000 calc(100% - 18px), transparent 100%);
            mask-image: linear-gradient(90deg, #000 0%, #000 calc(100% - 18px), transparent 100%);
        }
        .chips.is-dragging { cursor: grabbing; scroll-snap-type: none; }
        .chips::-webkit-scrollbar { display: none; height: 0; }
        .chips__track {
            display: inline-flex;
            flex-wrap: nowrap;
            align-items: center;
            gap: 8px;
            padding: 2px 16px 10px;
            min-width: max-content;
            box-sizing: border-box;
        }
        .chip {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            padding: 9px 14px;
            border-radius: 999px;
            border: 1px solid var(--ios-sep);
            background: var(--ios-card);
            color: var(--ios-secondary);
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            line-height: 1.2;
            transition: .15s ease;
            -webkit-user-select: none;
            user-select: none;
            -webkit-user-drag: none;
            box-shadow: none;
        }
        .chip.chip--all,
        .chip.chip--buku,
        .chip.chip--modul,
        .chip.chip--referensi,
        .chip.chip--lainnya {
            background: var(--ios-card);
            color: var(--ios-ink);
        }
        .chip.is-active {
            background: var(--ios-blue) !important;
            border-color: var(--ios-blue);
            color: #fff !important;
            box-shadow: none;
        }

        .section {
            margin-bottom: 22px;
        }
        .section__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .section__head h2 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: -.02em;
            color: var(--ios-ink);
        }
        .section__head h2::before { display: none; }
        .section__head a, .section__head button.linkish {
            border: 0;
            background: none;
            color: var(--ios-blue);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            border-radius: 0;
        }

        /* Hero recommended */
        .hero-card {
            position: relative;
            overflow: hidden;
            border-radius: 20px;
            padding: 20px;
            min-height: 168px;
            color: #fff;
            background:
                linear-gradient(135deg, rgba(0,0,0,.12), transparent 50%),
                linear-gradient(135deg, var(--ios-blue) 0%, var(--ios-teal) 100%);
            box-shadow: var(--ios-shadow);
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: end;
        }
        .hero-card::before { display: none; }
        html[data-theme="dark"] .hero-card {
            background:
                linear-gradient(135deg, rgba(0,0,0,.35), transparent 45%),
                linear-gradient(120deg, #064E3B 0%, #065F46 40%, #B45309 130%);
        }
        .hero-card__eyebrow {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            opacity: .85;
            margin-bottom: 6px;
        }
        .hero-card h3 {
            margin: 0 0 6px;
            font-size: 1.35rem;
            line-height: 1.25;
            letter-spacing: -.02em;
            max-width: 14ch;
        }
        .hero-card p {
            margin: 0 0 14px;
            font-size: 13px;
            opacity: .9;
            max-width: 28ch;
            line-height: 1.4;
        }
        .hero-card__cta {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 0;
            background: rgba(255,255,255,.95);
            color: #0F172A;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
        }
        html[data-theme="dark"] .hero-card__cta {
            background: var(--accent);
            color: #062016;
        }
        .hero-card__cover {
            width: 92px;
            height: 128px;
            border-radius: 12px;
            background: rgba(255,255,255,.18);
            box-shadow: 0 12px 24px rgba(0,0,0,.22);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 12px;
            letter-spacing: .06em;
        }
        .hero-card__cover img {
            width: 100%; height: 100%; object-fit: cover;
        }
        @media (max-width: 400px) {
            .hero-card {
                grid-template-columns: 1fr;
                padding: 16px;
                min-height: auto;
                border-radius: 18px;
            }
            .hero-card__cover {
                width: 68px;
                height: 94px;
                margin-bottom: 4px;
            }
            .hero-card h3 {
                max-width: none;
                font-size: 1.12rem;
            }
            .hero-card p {
                max-width: none;
                font-size: 12px;
                margin-bottom: 10px;
            }
            .hero-card__cta {
                padding: 9px 12px;
                font-size: 12px;
            }
        }

        /* Progress / continue */
        .progress-card {
            background: var(--ios-card);
            border: 1px solid var(--ios-sep);
            border-radius: 18px;
            padding: 14px;
            display: grid;
            grid-template-columns: 56px 1fr auto;
            gap: 12px;
            align-items: center;
            box-shadow: var(--ios-shadow);
            cursor: pointer;
            transition: transform .15s;
        }
        .progress-card:active { transform: scale(.99); }
        .progress-card__thumb {
            width: 56px; height: 72px;
            border-radius: 10px;
            background: var(--ios-fill);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
            color: var(--ios-blue);
        }
        .progress-card__thumb img { width: 100%; height: 100%; object-fit: cover; }
        .progress-card strong {
            display: block;
            font-size: 14px;
            margin-bottom: 2px;
            line-height: 1.3;
            color: var(--ios-ink);
        }
        .progress-card span {
            display: block;
            font-size: 12px;
            color: var(--ios-secondary);
            margin-bottom: 8px;
        }
        .bar {
            height: 6px;
            border-radius: 999px;
            background: var(--ios-fill);
            overflow: hidden;
        }
        .bar > i {
            display: block;
            height: 100%;
            width: 0%;
            border-radius: inherit;
            background: var(--ios-blue);
            transition: width .4s ease;
        }
        .badge-due {
            min-width: 44px;
            height: 44px;
            border-radius: 14px;
            background: rgba(255,149,0,.12);
            color: #C93400;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.1;
            box-shadow: none;
        }
        html[data-theme="dark"] .badge-due {
            background: rgba(251,191,36,.15);
            color: var(--accent-2);
        }
        .badge-due b { font-size: 14px; }

        /* Slider */
        .slider {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding: 2px 2px 8px;
            scroll-snap-type: x mandatory;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }
        .slider::-webkit-scrollbar { display: none; }
        .book-slide {
            flex: 0 0 118px;
            scroll-snap-align: start;
            cursor: pointer;
            transition: transform .15s;
        }
        .book-slide:active { transform: scale(.97); }
        .book-slide__cover {
            height: 158px;
            border-radius: 14px;
            background: linear-gradient(165deg, #D1D1D6, #AEAEB2);
            overflow: hidden;
            margin-bottom: 8px;
            box-shadow: var(--ios-shadow);
            position: relative;
            display: flex;
            align-items: flex-end;
            padding: 8px;
        }
        .book-slide:nth-child(3n+1) .book-slide__cover {
            background: linear-gradient(165deg, #64D2FF, #007AFF);
        }
        .book-slide:nth-child(3n+2) .book-slide__cover {
            background: linear-gradient(165deg, #63E6BE, #30B0C7);
        }
        .book-slide:nth-child(3n) .book-slide__cover {
            background: linear-gradient(165deg, #FFD60A, #FF9500);
        }
        html[data-theme="dark"] .book-slide__cover {
            background: linear-gradient(165deg, #1F2937, #064E3B);
        }
        .book-slide__cover img {
            position: absolute; inset: 0;
            width: 100%; height: 100%; object-fit: cover;
        }
        .book-slide__tag {
            position: relative;
            z-index: 1;
            font-size: 9px;
            font-weight: 700;
            padding: 3px 7px;
            border-radius: 999px;
            background: rgba(255,255,255,.95);
            color: var(--ios-ink);
            box-shadow: none;
        }
        .book-slide strong {
            display: block;
            font-size: 12px;
            line-height: 1.3;
            font-weight: 700;
            color: var(--ios-ink);
        }
        .book-slide span {
            display: block;
            font-size: 11px;
            color: var(--ios-secondary);
            margin-top: 2px;
        }

        /* List / grid */
        .list {
            display: grid;
            gap: 10px;
        }
        .row-book {
            display: grid;
            grid-template-columns: 54px 1fr auto;
            gap: 12px;
            align-items: center;
            padding: 12px;
            border-radius: 16px;
            background: var(--ios-card);
            border: 1px solid transparent;
            cursor: pointer;
            transition: transform .12s, background .12s;
            box-shadow: var(--ios-shadow);
        }
        .row-book:active { transform: scale(.99); background: #E5E5EA; }
        .row-book__cover {
            width: 54px; height: 70px;
            border-radius: 10px;
            background: var(--ios-fill);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
            color: var(--ios-blue);
        }
        .row-book:nth-child(3n+2) .row-book__cover,
        .row-book:nth-child(3n) .row-book__cover {
            background: var(--ios-fill);
            color: var(--ios-blue);
        }
        .row-book__cover img { width: 100%; height: 100%; object-fit: cover; }
        .row-book strong {
            display: block;
            font-size: 14px;
            line-height: 1.3;
            margin-bottom: 2px;
            color: var(--ios-ink);
        }
        .row-book span {
            display: block;
            font-size: 12px;
            color: var(--ios-secondary);
        }
        .pill {
            padding: 10px 14px;
            min-height: 40px;
            border-radius: 999px;
            background: rgba(0,122,255,.12);
            color: var(--ios-blue);
            font-size: 12px;
            font-weight: 700;
            border: 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            box-shadow: none;
        }

        .empty {
            text-align: center;
            padding: 36px 18px;
            border-radius: 16px;
            border: 1px dashed var(--ios-sep);
            color: var(--ios-secondary);
            background: var(--ios-card);
        }
        .empty strong {
            display: block;
            color: var(--ios-ink);
            margin-bottom: 6px;
            font-size: 15px;
        }

        /* Bottom nav — tab internal perpustakaan */
        .bottom-nav {
            position: fixed;
            left: 0;
            right: 0;
            margin: 0 auto;
            bottom: 0;
            width: 100%;
            max-width: 430px;
            z-index: 50;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2px;
            padding: 6px 8px calc(6px + var(--safe-b));
            background: color-mix(in srgb, var(--bg-elev) 92%, transparent);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-top: 1px solid var(--line);
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            padding: 6px 4px;
            border: 0;
            background: transparent;
            color: var(--muted);
            font-size: 10px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            border-radius: 12px;
            text-decoration: none;
            transition: color .15s, background .15s;
            min-height: 48px;
            justify-content: center;
        }
        .nav-item svg { width: 22px; height: 22px; flex-shrink: 0; }
        .nav-item.is-active {
            color: var(--accent);
            background: color-mix(in srgb, var(--accent) 12%, transparent);
        }
        .nav-item:active { transform: scale(.97); }
        @media (max-width: 380px) {
            .nav-item { font-size: 9px; padding: 5px 2px; }
            .nav-item svg { width: 20px; height: 20px; }
        }

        .panel { display: none; }
        .panel.is-active { display: block; animation: fadeUp .28s ease; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: none; }
        }

        .profile-card {
            background: var(--bg-elev);
            border: 1px solid var(--line);
            border-radius: 22px;
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
        }
        .avatar {
            width: 72px; height: 72px;
            border-radius: 24px;
            margin: 0 auto 12px;
            background: linear-gradient(135deg, var(--accent), var(--sand));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 24px;
            overflow: hidden;
        }
        .avatar.has-photo { padding: 0; background: var(--bg-soft); }
        .avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 16px;
        }
        .stat {
            background: var(--bg-soft);
            border-radius: 14px;
            padding: 12px 8px;
        }
        .stat b { display: block; font-size: 1.1rem; }
        .stat span { font-size: 11px; color: var(--muted); }

        /* Reader */
        .reader {
            position: fixed;
            inset: 0;
            z-index: 200;
            display: none;
            flex-direction: column;
            background: #0B1220;
            color: #F8FAFC;
        }
        .reader.is-open { display: flex; }
        body.reader-open { overflow: hidden; }
        body.reader-open .bottom-nav { display: none; }

        .reader__top {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            padding-top: max(10px, var(--safe-t));
            background: rgba(11,18,32,.96);
            border-bottom: 1px solid rgba(255,255,255,.08);
            position: relative;
            z-index: 2;
        }
        .reader__back, .reader__tool {
            appearance: none;
            border: 1px solid rgba(255,255,255,.12);
            background: rgba(255,255,255,.06);
            color: #fff;
            border-radius: 12px;
            min-height: 40px;
            min-width: 40px;
            padding: 0 12px;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .reader__info { flex: 1; min-width: 0; }
        .reader__info strong, .reader__info span {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .reader__info strong { font-size: 14px; }
        .reader__info span { font-size: 12px; color: #94A3B8; }
        .reader__tools { display: flex; gap: 6px; }
        .reader__stage {
            flex: 1;
            min-height: 0;
            position: relative;
            background:
                radial-gradient(ellipse at 50% 80%, rgba(30,41,59,.6) 0%, #020617 70%);
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .reader__loading {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #94A3B8;
            transition: opacity .25s;
            z-index: 10;
        }
        .reader__loading.is-hide { opacity: 0; visibility: hidden; pointer-events: none; }

        .flipbook {
            position: relative;
            perspective: 2200px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            user-select: none;
            -webkit-user-select: none;
        }
        .flipbook__viewport {
            position: relative;
            transform-style: preserve-3d;
            display: flex;
        }
        .flipbook__page {
            position: relative;
            background: #fdfbf7;
            box-shadow:
                0 2px 12px rgba(0,0,0,.18),
                inset 0 0 40px rgba(0,0,0,.03);
            overflow: hidden;
            flex-shrink: 0;
        }
        .flipbook__page canvas {
            display: block;
            width: 100%;
            height: 100%;
        }
        .flipbook__page--left {
            border-right: 1px solid rgba(0,0,0,.08);
            border-radius: 4px 0 0 4px;
        }
        .flipbook__page--left::after {
            content: '';
            position: absolute;
            right: 0; top: 0; bottom: 0;
            width: 20px;
            background: linear-gradient(to left, rgba(0,0,0,.08), transparent);
            pointer-events: none;
        }
        .flipbook__page--right {
            border-radius: 0 4px 4px 0;
        }
        .flipbook__page--right::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 20px;
            background: linear-gradient(to right, rgba(0,0,0,.10), transparent);
            pointer-events: none;
            z-index: 2;
        }
        .flipbook__page--right::after {
            content: '';
            position: absolute;
            right: 0; top: 0; bottom: 0;
            width: 8px;
            background: linear-gradient(to left, rgba(0,0,0,.04), transparent);
            pointer-events: none;
        }
        .flipbook__spine {
            width: 6px;
            flex-shrink: 0;
            background: linear-gradient(to right,
                rgba(0,0,0,.15) 0%,
                rgba(0,0,0,.06) 30%,
                rgba(255,255,255,.05) 50%,
                rgba(0,0,0,.06) 70%,
                rgba(0,0,0,.15) 100%
            );
            border-radius: 1px;
            box-shadow: 0 0 8px rgba(0,0,0,.12);
        }
        .flipbook__shadow {
            position: absolute;
            bottom: -12px;
            left: 10%;
            width: 80%;
            height: 20px;
            background: radial-gradient(ellipse, rgba(0,0,0,.25) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .flipbook__page-num {
            position: absolute;
            bottom: 6px;
            font-size: 10px;
            color: #94A3B8;
            pointer-events: none;
            z-index: 3;
        }
        .flipbook__page--left .flipbook__page-num { left: 10px; }
        .flipbook__page--right .flipbook__page-num { right: 10px; }

        .flipbook__empty-page {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #CBD5E1;
            font-size: 13px;
            font-weight: 600;
            width: 100%;
            height: 100%;
        }

        .flipbook__nav {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 40%;
            cursor: pointer;
            z-index: 5;
            display: flex;
            align-items: center;
            opacity: 0;
            transition: opacity .2s;
        }
        .flipbook__nav:hover { opacity: 1; }
        .flipbook__nav--prev {
            left: 0;
            justify-content: flex-start;
            padding-left: 8px;
            background: linear-gradient(to right, rgba(0,0,0,.06), transparent);
        }
        .flipbook__nav--next {
            right: 0;
            justify-content: flex-end;
            padding-right: 8px;
            background: linear-gradient(to left, rgba(0,0,0,.06), transparent);
        }
        .flipbook__nav-arrow {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: rgba(255,255,255,.9);
            color: #1E293B;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            box-shadow: 0 2px 10px rgba(0,0,0,.15);
        }

        .flipbook__flipper {
            position: absolute;
            top: 50%;
            width: 50%;
            height: 92%;
            max-height: calc(100% - 24px);
            transform-style: preserve-3d;
            transition: transform .65s cubic-bezier(.45,.05,.25,.95);
            pointer-events: none;
            z-index: 12;
            opacity: 0;
            visibility: hidden;
        }
        .flipbook__flipper.is-active {
            opacity: 1;
            visibility: visible;
        }
        .flipbook__flipper--next {
            right: 0;
            transform: translateY(-50%) rotateY(0deg);
            transform-origin: left center;
        }
        .flipbook__flipper--prev {
            left: 0;
            transform: translateY(-50%) rotateY(0deg);
            transform-origin: right center;
        }
        .flipbook__flipper--next.is-turning {
            transform: translateY(-50%) rotateY(-180deg);
        }
        .flipbook__flipper--prev.is-turning {
            transform: translateY(-50%) rotateY(180deg);
        }
        .flipbook__flipper-face {
            position: absolute;
            inset: 0;
            backface-visibility: hidden;
            background: #fdfbf7;
            box-shadow: 0 4px 20px rgba(0,0,0,.2);
            overflow: hidden;
            border-radius: 2px;
        }
        .flipbook__flipper-back {
            transform: rotateY(180deg);
            background: #f5f0e8;
        }
        .flipbook__flipper-face canvas {
            width: 100%;
            height: 100%;
            display: block;
        }
        .flipbook__error {
            position: absolute;
            inset: 0;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 24px;
            text-align: center;
            color: #94A3B8;
            z-index: 15;
        }
        .flipbook__error.is-show { display: flex; }
        .flipbook__error button {
            appearance: none;
            border: 1px solid rgba(255,255,255,.2);
            background: rgba(255,255,255,.08);
            color: #fff;
            border-radius: 10px;
            padding: 10px 16px;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .reader__pager {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 6px 12px calc(6px + var(--safe-b));
            background: rgba(11,18,32,.96);
            border-top: 1px solid rgba(255,255,255,.08);
            font-size: 12px;
            color: #94A3B8;
        }
        .reader__pager button {
            appearance: none;
            border: 1px solid rgba(255,255,255,.12);
            background: rgba(255,255,255,.06);
            color: #fff;
            border-radius: 10px;
            min-width: 40px; min-height: 36px;
            padding: 0 12px;
            font: inherit; font-size: 13px; font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .reader__pager button:disabled {
            opacity: .3;
            cursor: default;
        }
        .reader__pager input {
            width: 48px;
            text-align: center;
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 8px;
            background: rgba(255,255,255,.06);
            color: #fff;
            padding: 6px 4px;
            font: inherit; font-size: 13px;
        }

        .flipbook.is-single .flipbook__page--left {
            display: none !important;
        }
        .flipbook.is-single .flipbook__spine {
            display: none !important;
        }
        .flipbook.is-single .flipbook__viewport {
            justify-content: center;
            max-width: 100%;
        }
        .flipbook.is-single .flipbook__page--right {
            border-radius: 10px;
            max-width: min(100%, calc(100vw - 24px));
            box-shadow: 0 8px 28px rgba(0,0,0,.35);
        }
        .flipbook.is-single .flipbook__page--right canvas {
            max-width: 100%;
            height: auto !important;
        }
        .flipbook.is-single .flipbook__page--right::before,
        .flipbook.is-single .flipbook__page--right::after {
            display: none;
        }
        .flipbook.is-single .flipbook__nav { width: 28%; }
        .flipbook.is-single .flipbook__flipper {
            width: min(92%, 420px);
            left: 50% !important;
            right: auto !important;
            transform: translate(-50%, -50%) rotateY(0deg);
            transform-origin: left center;
        }
        .flipbook.is-single .flipbook__flipper.is-turning {
            transform: translate(-50%, -50%) rotateY(-180deg);
        }

        @media (max-width: 900px) {
            .flipbook__nav { width: 30%; }
        }
        .spin {
            width: 34px; height: 34px;
            border-radius: 50%;
            border: 3px solid rgba(255,255,255,.12);
            border-top-color: #34D399;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .reader__hint {
            padding: 8px 12px calc(8px + var(--safe-b));
            text-align: center;
            font-size: 11px;
            color: #64748B;
            border-top: 1px solid rgba(255,255,255,.06);
        }

        @media (max-width: 480px) {
            .reader__top {
                flex-wrap: wrap;
                gap: 8px;
                padding: 8px 10px;
                padding-top: max(8px, var(--safe-t));
            }
            .reader__back {
                order: 1;
                min-height: 44px;
                padding: 0 14px;
            }
            .reader__tools {
                order: 1;
                margin-left: auto;
            }
            .reader__info {
                order: 2;
                width: 100%;
                flex: none;
            }
            .reader__tool {
                min-width: 44px;
                min-height: 44px;
            }
        }
        @media (min-width: 860px) {
            .bottom-nav { display: none !important; }
            .main { padding-bottom: 28px; }
        }

        .toast {
            position: fixed;
            left: 50%;
            bottom: calc(var(--nav-h) + 24px + var(--safe-b));
            transform: translateX(-50%) translateY(20px);
            background: var(--ink);
            color: var(--bg);
            padding: 10px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            opacity: 0;
            pointer-events: none;
            transition: .25s ease;
            z-index: 80;
            white-space: nowrap;
        }
        .toast.is-on { opacity: 1; transform: translateX(-50%) translateY(0); }

        .micro-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--accent);
            display: inline-block;
            margin-right: 6px;
            box-shadow: 0 0 0 0 color-mix(in srgb, var(--accent) 50%, transparent);
            animation: pulse 1.8s infinite;
        }
        @media (max-width: 380px) {
            .book-slide { flex-basis: 108px; }
            .book-slide__cover { height: 144px; }
            .row-book {
                grid-template-columns: 48px 1fr;
                gap: 10px;
            }
            .row-book .pill {
                grid-column: 1 / -1;
                justify-self: start;
                margin-top: 2px;
            }
            .progress-card {
                grid-template-columns: 48px 1fr;
                gap: 10px;
            }
            .progress-card .badge-due {
                grid-column: 1 / -1;
                flex-direction: row;
                width: auto;
                height: auto;
                min-height: 36px;
                padding: 6px 12px;
                gap: 4px;
            }
        }
    </style>
    <?php
    $extra_css = ob_get_clean();

    if ( function_exists( 'qrg_sekolah_portal_shell_start' ) ) {
        qrg_sekolah_portal_shell_start( $baca_item ? $baca_item['title'] : 'Perpustakaan', $extra_css, 'beranda' );
    } else {
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $page_title ) . '</title>' . $extra_css . '</head><body>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    ?>
<div class="qrg-sk qrg-sk-perpus">
<div class="app" id="app">
        <div class="main<?php echo $tab !== 'profile' ? ' show-search' : ''; ?><?php echo ( $tab === 'home' || $tab === 'search' ) ? ' show-chips' : ''; ?>" id="perpus-catalog"<?php echo $baca_item ? ' hidden' : ''; ?>>
            <header class="ios-hero">
                <p class="brand">Portal Siswa</p>
                <h1>Perpustakaan</h1>
                <p class="sub"><?php echo esc_html( $siswa_nama !== '' ? $siswa_nama : $site_name ); ?> · e-book &amp; modul</p>
            </header>

            <div class="perpus-tabs" role="tablist" aria-label="Menu perpustakaan">
                <button type="button" class="js-tab-nav<?php echo $tab === 'home' ? ' is-active' : ''; ?>" data-tab="home" aria-current="<?php echo $tab === 'home' ? 'page' : 'false'; ?>">Beranda</button>
                <button type="button" class="js-tab-nav<?php echo $tab === 'search' ? ' is-active' : ''; ?>" data-tab="search">Cari</button>
                <button type="button" class="js-tab-nav<?php echo ( $tab === 'shelf' || $tab === 'profile' ) ? ' is-active' : ''; ?>" data-tab="shelf">Rak</button>
            </div>

            <div class="catalog-tools" id="catalog-tools">
            <form class="search" id="search-form" method="get" action="<?php echo esc_url( $base_url ); ?>" data-panel-focus="search">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3-3"/></svg>
                <input type="search" name="q" id="search-input" value="<?php echo esc_attr( $search ); ?>" placeholder="Cari buku, modul, penulis…" autocomplete="off">
                <?php if ( $kategori ) : ?><input type="hidden" name="kategori" value="<?php echo esc_attr( $kategori ); ?>"><?php endif; ?>
                <input type="hidden" name="tab" value="search">
                <div class="search__filters" aria-label="Filter cepat">
                    <button type="button" class="search__chip js-quick-kat<?php echo $kategori === 'buku' ? ' is-on' : ''; ?>" data-kat="buku" title="Buku">📚</button>
                    <button type="button" class="search__chip js-quick-kat<?php echo $kategori === 'modul' ? ' is-on' : ''; ?>" data-kat="modul" title="Modul">📝</button>
                    <button type="button" class="search__chip js-quick-kat<?php echo $kategori === 'referensi' ? ' is-on' : ''; ?>" data-kat="referensi" title="Referensi">🔬</button>
                </div>
            </form>

            <nav class="chips" id="perpus-chips" aria-label="Kategori">
                <div class="chips__track">
                <a class="chip chip--all<?php echo $kategori === '' ? ' is-active' : ''; ?>" href="<?php echo esc_url( qrg_get_perpustakaan_url( array_filter( [ 'q' => $search ?: null, 'tab' => $tab !== 'home' ? $tab : null ] ) ) ); ?>">Semua</a>
                <?php foreach ( $opts as $key => $label ) :
                    $args = [ 'kategori' => $key ];
                    if ( $search !== '' ) { $args['q'] = $search; }
                    if ( $tab !== 'home' ) { $args['tab'] = $tab; }
                    $chip_mod = in_array( $key, [ 'buku', 'modul', 'referensi' ], true ) ? $key : 'lainnya';
                    ?>
                    <a class="chip chip--<?php echo esc_attr( $chip_mod ); ?><?php echo $kategori === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( qrg_get_perpustakaan_url( $args ) ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
                </div>
            </nav>
            </div>

            <!-- HOME -->
            <div class="panel<?php echo $tab === 'home' ? ' is-active' : ''; ?>" id="panel-home" data-panel="home">
                <?php if ( ! empty( $recommended ) ) :
                    $hero = $recommended[0];
                    $hero_read = qrg_get_perpustakaan_url( [ 'baca' => $hero['id'] ] );
                    ?>
                    <section class="section">
                        <div class="section__head">
                            <h2>Rekomendasi</h2>
                            <button type="button" class="linkish js-goto-tab" data-tab="search">Lihat semua</button>
                        </div>
                        <article class="hero-card js-open-reader"
                            data-id="<?php echo (int) $hero['id']; ?>"
                            data-title="<?php echo esc_attr( $hero['title'] ); ?>"
                            data-meta="<?php echo esc_attr( ( $hero['penulis'] ? $hero['penulis'] . ' · ' : '' ) . $hero['kategori_label'] ); ?>"
                            data-url="<?php echo esc_url( $hero['pdf_url'] ); ?>"
                            data-href="<?php echo esc_url( $hero_read ); ?>"
                            data-thumb="<?php echo esc_attr( $hero['thumb'] ? $hero['thumb'] : '' ); ?>">
                            <div>
                                <div class="hero-card__eyebrow">Pilihan minggu ini</div>
                                <h3><?php echo esc_html( $hero['title'] ); ?></h3>
                                <p><?php echo esc_html( $hero['deskripsi'] !== '' ? wp_trim_words( $hero['deskripsi'], 14, '…' ) : ( $hero['penulis'] ? $hero['penulis'] : $hero['kategori_label'] ) ); ?></p>
                                <span class="hero-card__cta">Mulai baca →</span>
                            </div>
                            <div class="hero-card__cover">
                                <?php if ( $hero['thumb'] ) : ?>
                                    <img src="<?php echo esc_url( $hero['thumb'] ); ?>" alt="">
                                <?php else : ?>PDF<?php endif; ?>
                            </div>
                        </article>
                    </section>
                <?php endif; ?>

                <section class="section" id="continue-section" hidden>
                    <div class="section__head">
                        <h2>Sedang dibaca</h2>
                        <button type="button" class="linkish js-goto-tab" data-tab="shelf">Rak saya</button>
                    </div>
                    <div id="continue-slot"></div>
                </section>

                <?php if ( ! empty( $arrivals ) ) : ?>
                <section class="section">
                    <div class="section__head">
                        <h2>Terbaru</h2>
                        <span style="font-size:12px;color:var(--muted);"><?php echo count( $arrivals ); ?> baru</span>
                    </div>
                    <div class="slider">
                        <?php foreach ( $arrivals as $item ) :
                            $read_url = qrg_get_perpustakaan_url( [ 'baca' => $item['id'] ] );
                            ?>
                            <article class="book-slide js-open-reader"
                                data-id="<?php echo (int) $item['id']; ?>"
                                data-title="<?php echo esc_attr( $item['title'] ); ?>"
                                data-meta="<?php echo esc_attr( ( $item['penulis'] ? $item['penulis'] . ' · ' : '' ) . $item['kategori_label'] ); ?>"
                                data-url="<?php echo esc_url( $item['pdf_url'] ); ?>"
                                data-href="<?php echo esc_url( $read_url ); ?>"
                                data-thumb="<?php echo esc_attr( $item['thumb'] ? $item['thumb'] : '' ); ?>">
                                <div class="book-slide__cover">
                                    <?php if ( $item['thumb'] ) : ?>
                                        <img src="<?php echo esc_url( $item['thumb'] ); ?>" alt="" loading="lazy">
                                    <?php endif; ?>
                                    <span class="book-slide__tag"><?php echo esc_html( $item['kategori_label'] ); ?></span>
                                </div>
                                <strong><?php echo esc_html( wp_trim_words( $item['title'], 6, '…' ) ); ?></strong>
                                <span><?php echo esc_html( $item['penulis'] ? $item['penulis'] : 'PDF' ); ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <section class="section">
                    <div class="section__head">
                        <h2>Koleksi</h2>
                        <span style="font-size:12px;color:var(--muted);"><?php echo (int) count( $all_items ); ?> dokumen</span>
                    </div>
                    <?php if ( empty( $all_items ) ) : ?>
                        <div class="empty"><strong>Belum ada dokumen</strong>Unggah PDF lewat Sekolah → Perpustakaan.</div>
                    <?php else : ?>
                        <div class="list">
                            <?php foreach ( $all_items as $item ) :
                                $read_url = qrg_get_perpustakaan_url( [ 'baca' => $item['id'] ] );
                                ?>
                                <article class="row-book js-open-reader"
                                    data-id="<?php echo (int) $item['id']; ?>"
                                    data-title="<?php echo esc_attr( $item['title'] ); ?>"
                                    data-meta="<?php echo esc_attr( ( $item['penulis'] ? $item['penulis'] . ' · ' : '' ) . $item['kategori_label'] ); ?>"
                                    data-url="<?php echo esc_url( $item['pdf_url'] ); ?>"
                                    data-href="<?php echo esc_url( $read_url ); ?>"
                                    data-thumb="<?php echo esc_attr( $item['thumb'] ? $item['thumb'] : '' ); ?>">
                                    <div class="row-book__cover">
                                        <?php if ( $item['thumb'] ) : ?>
                                            <img src="<?php echo esc_url( $item['thumb'] ); ?>" alt="" loading="lazy">
                                        <?php else : ?>PDF<?php endif; ?>
                                    </div>
                                    <div>
                                        <strong><?php echo esc_html( $item['title'] ); ?></strong>
                                        <span><?php echo esc_html( implode( ' · ', array_filter( [ $item['penulis'], $item['kategori_label'], $item['pdf_size'] ? size_format( $item['pdf_size'] ) : '' ] ) ) ); ?></span>
                                    </div>
                                    <button type="button" class="pill" tabindex="-1">Baca</button>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- SEARCH -->
            <div class="panel<?php echo $tab === 'search' ? ' is-active' : ''; ?>" id="panel-search" data-panel="search">
                <section class="section">
                    <div class="section__head"><h2>Hasil pencarian</h2></div>
                    <div class="list" id="search-results">
                        <?php if ( empty( $all_items ) ) : ?>
                            <div class="empty"><strong>Tidak ditemukan</strong>Coba kata kunci atau kategori lain.</div>
                        <?php else : foreach ( $all_items as $item ) :
                            $read_url = qrg_get_perpustakaan_url( [ 'baca' => $item['id'], 'tab' => 'search' ] );
                            ?>
                            <article class="row-book js-open-reader"
                                data-id="<?php echo (int) $item['id']; ?>"
                                data-title="<?php echo esc_attr( $item['title'] ); ?>"
                                data-meta="<?php echo esc_attr( ( $item['penulis'] ? $item['penulis'] . ' · ' : '' ) . $item['kategori_label'] ); ?>"
                                data-url="<?php echo esc_url( $item['pdf_url'] ); ?>"
                                data-href="<?php echo esc_url( $read_url ); ?>"
                                data-thumb="<?php echo esc_attr( $item['thumb'] ? $item['thumb'] : '' ); ?>">
                                <div class="row-book__cover"><?php echo $item['thumb'] ? '<img src="' . esc_url( $item['thumb'] ) . '" alt="">' : 'PDF'; ?></div>
                                <div>
                                    <strong><?php echo esc_html( $item['title'] ); ?></strong>
                                    <span><?php echo esc_html( $item['kategori_label'] ); ?></span>
                                </div>
                                <button type="button" class="pill" tabindex="-1">Buka</button>
                            </article>
                        <?php endforeach; endif; ?>
                    </div>
                </section>
            </div>

            <!-- SHELF -->
            <div class="panel<?php echo $tab === 'shelf' ? ' is-active' : ''; ?>" id="panel-shelf" data-panel="shelf">
                <section class="section">
                    <div class="section__head">
                        <h2>Rak Saya</h2>
                        <span style="font-size:12px;color:var(--muted);">Progress baca</span>
                    </div>
                    <div class="list" id="shelf-list">
                        <div class="empty"><strong>Rak masih kosong</strong>Mulai baca buku — progress tersimpan otomatis di perangkat ini.</div>
                    </div>
                </section>
                <section class="section">
                    <div class="section__head"><h2>Pengaturan</h2></div>
                    <div class="list">
                        <button type="button" class="row-book" id="btn-clear-shelf" style="cursor:pointer;width:100%;text-align:left;">
                            <div class="row-book__cover">⌫</div>
                            <div><strong>Reset progress baca</strong><span>Hapus riwayat di perangkat ini</span></div>
                            <span class="pill">Reset</span>
                        </button>
                    </div>
                </section>
            </div>

            <!-- PROFILE (legacy, hidden) -->
            <div class="panel" id="panel-profile" data-panel="profile" hidden></div>
        </div><!-- /main catalog -->

<div class="toast" id="toast" role="status"></div>

<section class="reader<?php echo $baca_item ? ' is-open' : ''; ?>" id="perpus-reader"<?php echo $baca_item ? '' : ' hidden'; ?>>
    <div class="reader__top">
        <button type="button" class="reader__back" id="reader-close">&larr; Kembali</button>
        <div class="reader__info">
            <strong id="reader-title"><?php echo $baca_item ? esc_html( $baca_item['title'] ) : 'PDF'; ?></strong>
            <span id="reader-meta"><?php echo $baca_item ? esc_html( ( $baca_item['penulis'] ? $baca_item['penulis'] . ' · ' : '' ) . $baca_item['kategori_label'] ) : ''; ?></span>
        </div>
        <div class="reader__tools">
            <a class="reader__tool" id="reader-download" href="<?php echo $baca_item ? esc_url( $baca_item['pdf_url'] ) : '#'; ?>" download title="Unduh">↓</a>
        </div>
    </div>
    <div class="reader__stage">
        <div class="reader__loading<?php echo $baca_item ? '' : ' is-hide'; ?>" id="reader-loading"><div class="spin"></div><span>Memuat dokumen…</span></div>
        <div class="flipbook" id="flipbook">
            <div class="flipbook__shadow"></div>
            <div class="flipbook__viewport" id="flipbook-viewport">
                <div class="flipbook__page flipbook__page--left" id="fb-page-left">
                    <canvas id="fb-canvas-left"></canvas>
                    <span class="flipbook__page-num" id="fb-num-left"></span>
                </div>
                <div class="flipbook__spine"></div>
                <div class="flipbook__page flipbook__page--right" id="fb-page-right">
                    <canvas id="fb-canvas-right"></canvas>
                    <span class="flipbook__page-num" id="fb-num-right"></span>
                </div>
            </div>
            <div class="flipbook__nav flipbook__nav--prev" id="fb-prev"><div class="flipbook__nav-arrow">‹</div></div>
            <div class="flipbook__nav flipbook__nav--next" id="fb-next"><div class="flipbook__nav-arrow">›</div></div>
            <div class="flipbook__flipper flipbook__flipper--next" id="fb-flipper" aria-hidden="true">
                <div class="flipbook__flipper-face flipbook__flipper-front"><canvas id="fb-flip-front"></canvas></div>
                <div class="flipbook__flipper-face flipbook__flipper-back"><canvas id="fb-flip-back"></canvas></div>
            </div>
            <div class="flipbook__error" id="fb-error">
                <strong>Gagal memuat flipbook</strong>
                <span id="fb-error-msg">PDF tidak dapat dibaca.</span>
                <button type="button" id="fb-retry">Coba lagi</button>
            </div>
        </div>
    </div>
    <div class="reader__pager">
        <button type="button" id="pager-prev">‹ Prev</button>
        <span>Hal. <input type="number" id="pager-input" min="1" value="1"> / <span id="pager-total">0</span></span>
        <button type="button" id="pager-next">Next ›</button>
    </div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
(function(){
    var STORAGE_KEY = 'qrg_perpus_shelf_v1';
    var THEME_KEY = 'qrg_perpus_theme';
    var catalog = document.getElementById('perpus-catalog');
    var reader = document.getElementById('perpus-reader');
    var loading = document.getElementById('reader-loading');
    var titleEl = document.getElementById('reader-title');
    var metaEl = document.getElementById('reader-meta');
    var dl = document.getElementById('reader-download');
    var toast = document.getElementById('toast');
    var books = <?php echo $items_json ? $items_json : '[]'; ?>;
    var backUrl = <?php echo wp_json_encode( $back_url ); ?>;
    var baseUrl = <?php echo wp_json_encode( $base_url ); ?>;

    var openUrl = '';
    var openStreamUrl = '';
    var openId = 0;
    var openMeta = null;
    var readTimer = null;

    // Flipbook state
    var pdfDoc = null;
    var fbCurrentSpread = 0;
    var fbTotalPages = 0;
    var fbRendering = false;
    var fbAnimating = false;
    var fbPageCache = {};
    var fbIsMobile = function(){
        var stage = reader ? reader.querySelector('.reader__stage') : null;
        var w = stage ? stage.clientWidth : window.innerWidth;
        // Satu halaman jika layar sempit / tidak muat spread 2 halaman
        return w < 860 || window.innerWidth < 900;
    };
    function syncFlipbookMode(){
        var fb = document.getElementById('flipbook');
        if (!fb) return;
        fb.classList.toggle('is-single', fbIsMobile());
    }

    var canvasL = document.getElementById('fb-canvas-left');
    var canvasR = document.getElementById('fb-canvas-right');
    var pageL = document.getElementById('fb-page-left');
    var pageR = document.getElementById('fb-page-right');
    var numL = document.getElementById('fb-num-left');
    var numR = document.getElementById('fb-num-right');
    var flipper = document.getElementById('fb-flipper');
    var flipFront = document.getElementById('fb-flip-front');
    var flipBack = document.getElementById('fb-flip-back');
    var fbError = document.getElementById('fb-error');
    var fbErrorMsg = document.getElementById('fb-error-msg');
    var pagerInput = document.getElementById('pager-input');
    var pagerTotal = document.getElementById('pager-total');

    function getStreamUrl(id){
        id = parseInt(id || 0, 10);
        if(!id) return '';
        return baseUrl.replace(/\/?$/, '/') + 'pdf/' + id + '/';
    }
    function hideFlipError(){
        if(fbError) fbError.classList.remove('is-show');
    }
    function showFlipError(msg){
        hideLoading();
        if(fbErrorMsg) fbErrorMsg.textContent = msg || 'PDF tidak dapat dibaca.';
        if(fbError) fbError.classList.add('is-show');
    }

    function toastMsg(msg){
        toast.textContent = msg;
        toast.classList.add('is-on');
        setTimeout(function(){ toast.classList.remove('is-on'); }, 1800);
    }
    function getShelf(){
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e){ return {}; }
    }
    function saveShelf(data){
        localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        renderShelf();
    }
    function showLoading(){ loading.classList.remove('is-hide'); }
    function hideLoading(){ loading.classList.add('is-hide'); }

    function getSpreadPages(spread){
        if(fbIsMobile()){
            // Mode satu halaman: render di panel kanan (yang terlihat)
            var pg = spread + 1;
            return [0, pg <= fbTotalPages ? pg : 0];
        }
        var left = spread * 2 + 1;
        var right = spread * 2 + 2;
        return [
            left <= fbTotalPages ? left : 0,
            right <= fbTotalPages ? right : 0
        ];
    }
    function totalSpreads(){
        if(fbIsMobile()) return fbTotalPages;
        return Math.ceil(fbTotalPages / 2);
    }

    function renderPage(pageNum, canvas, container){
        return new Promise(function(resolve, reject){
            if(!pageNum || pageNum < 1 || pageNum > fbTotalPages){
                if(canvas) canvas.style.display = 'none';
                if(container) container.style.display = 'none';
                resolve(null);
                return;
            }
            if(container) container.style.display = '';
            if(canvas) canvas.style.display = '';

            var stage = reader.querySelector('.reader__stage');
            var pad = fbIsMobile() ? 20 : 32;
            var stageW = Math.max(120, (stage ? stage.clientWidth : window.innerWidth) - pad);
            var stageH = Math.max(120, (stage ? stage.clientHeight : window.innerHeight) - pad);
            if(stageH < 100) stageH = Math.min(window.innerHeight - 140, 800);
            var cacheKey = pageNum + '_' + stageW + 'x' + stageH + '_' + (fbIsMobile() ? 'm' : 'd');

            if(fbPageCache[cacheKey]){
                applyCachedPage(fbPageCache[cacheKey], canvas, container);
                resolve(fbPageCache[cacheKey]);
                return;
            }

            pdfDoc.getPage(pageNum).then(function(page){
                var vp0 = page.getViewport({scale: 1});
                // Muatkan ke lebar & tinggi layar (hindari halaman terpotong)
                var scale = Math.min(stageW / vp0.width, stageH / vp0.height);
                if(scale < 0.05) scale = 0.05;
                if(scale > 2.5) scale = 2.5;
                var vp = page.getViewport({scale: scale});
                var dpr = Math.min(window.devicePixelRatio || 1, 2);

                canvas.width = Math.floor(vp.width * dpr);
                canvas.height = Math.floor(vp.height * dpr);
                canvas.style.width = Math.floor(vp.width) + 'px';
                canvas.style.height = Math.floor(vp.height) + 'px';
                if(container){
                    container.style.width = Math.floor(vp.width) + 'px';
                    container.style.height = Math.floor(vp.height) + 'px';
                }

                var ctx = canvas.getContext('2d');
                return page.render({
                    canvasContext: ctx,
                    viewport: vp,
                    transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null
                }).promise.then(function(){
                    var cached = {
                        width: canvas.width,
                        height: canvas.height,
                        cssW: Math.floor(vp.width),
                        cssH: Math.floor(vp.height),
                        dataUrl: canvas.toDataURL('image/jpeg', 0.92)
                    };
                    fbPageCache[cacheKey] = cached;
                    resolve(cached);
                });
            }).catch(function(err){
                reject(err);
            });
        });
    }

    function applyCachedPage(cached, canvas, container){
        if(!cached || !canvas) return;
        canvas.width = cached.width;
        canvas.height = cached.height;
        canvas.style.width = cached.cssW + 'px';
        canvas.style.height = cached.cssH + 'px';
        if(container){
            container.style.width = cached.cssW + 'px';
            container.style.height = cached.cssH + 'px';
        }
        var img = new Image();
        img.onload = function(){
            canvas.getContext('2d').drawImage(img, 0, 0);
        };
        img.src = cached.dataUrl;
    }

    function renderSpread(spread){
        syncFlipbookMode();
        fbRendering = true;
        hideFlipError();
        var pages = getSpreadPages(spread);
        var leftNum = pages[0];
        var rightNum = pages[1];

        numL.textContent = leftNum ? leftNum : '';
        numR.textContent = rightNum ? rightNum : '';

        var visiblePage = fbIsMobile() ? rightNum : (leftNum || rightNum);
        if(pagerInput) pagerInput.value = visiblePage || 1;

        return Promise.all([
            renderPage(leftNum, canvasL, pageL),
            renderPage(rightNum, canvasR, pageR)
        ]).then(function(){
            fbRendering = false;
            hideLoading();
        }).catch(function(err){
            fbRendering = false;
            showFlipError(err && err.message ? err.message : 'Gagal merender halaman.');
        });
    }

    function resetFlipper(){
        if(!flipper) return;
        flipper.classList.remove('is-active', 'is-turning', 'flipbook__flipper--next', 'flipbook__flipper--prev');
        flipper.setAttribute('aria-hidden', 'true');
    }

    function animateFlip(direction, targetSpread){
        if(fbAnimating || fbRendering) return Promise.resolve();
        if(!flipper || !flipFront || !flipBack) {
            fbCurrentSpread = targetSpread;
            return renderSpread(targetSpread);
        }

        var pages = getSpreadPages(fbCurrentSpread);
        var pageNum = direction === 'next'
            ? (fbIsMobile() ? pages[1] : (pages[1] || pages[0]))
            : (fbIsMobile() ? pages[1] : pages[0]);
        if(!pageNum) {
            fbCurrentSpread = targetSpread;
            return renderSpread(targetSpread);
        }

        fbAnimating = true;
        flipper.classList.remove('flipbook__flipper--next', 'flipbook__flipper--prev');
        flipper.classList.add(direction === 'next' ? 'flipbook__flipper--next' : 'flipbook__flipper--prev', 'is-active');
        flipper.setAttribute('aria-hidden', 'false');

        return renderPage(pageNum, flipFront, null).then(function(frontCached){
            var targetPages = getSpreadPages(targetSpread);
            var backNum = direction === 'next'
                ? (fbIsMobile() ? targetPages[1] : (targetPages[0] || targetPages[1]))
                : (fbIsMobile() ? pages[1] : (pages[1] || pages[0]));
            return renderPage(backNum, flipBack, null).then(function(){
                if(frontCached) applyCachedPage(frontCached, flipFront, null);
                flipper.classList.add('is-turning');
                return new Promise(function(resolve){
                    setTimeout(function(){
                        fbCurrentSpread = targetSpread;
                        renderSpread(targetSpread).then(function(){
                            resetFlipper();
                            fbAnimating = false;
                            resolve();
                        });
                    }, 660);
                });
            });
        }).catch(function(){
            resetFlipper();
            fbAnimating = false;
            fbCurrentSpread = targetSpread;
            return renderSpread(targetSpread);
        });
    }

    function goToSpread(s, direction){
        var max = totalSpreads() - 1;
        s = Math.max(0, Math.min(max, s));
        if(s === fbCurrentSpread) return;
        if(direction && pdfDoc){
            animateFlip(direction, s).then(updatePagerButtons);
        } else {
            fbCurrentSpread = s;
            renderSpread(s);
            updatePagerButtons();
        }
    }
    function nextSpread(){
        if(fbCurrentSpread >= totalSpreads() - 1) return;
        goToSpread(fbCurrentSpread + 1, 'next');
    }
    function prevSpread(){
        if(fbCurrentSpread <= 0) return;
        goToSpread(fbCurrentSpread - 1, 'prev');
    }
    function goToPage(pg){
        pg = Math.max(1, Math.min(fbTotalPages, pg));
        var target = fbIsMobile() ? pg - 1 : Math.floor((pg - 1) / 2);
        if(target === fbCurrentSpread) return;
        var dir = target > fbCurrentSpread ? 'next' : 'prev';
        goToSpread(target, Math.abs(target - fbCurrentSpread) === 1 ? dir : null);
    }
    function updatePagerButtons(){
        var prevBtn = document.getElementById('pager-prev');
        var nextBtn = document.getElementById('pager-next');
        if(prevBtn) prevBtn.disabled = fbCurrentSpread <= 0;
        if(nextBtn) nextBtn.disabled = fbCurrentSpread >= totalSpreads() - 1;
    }

    function loadPdf(streamUrl){
        syncFlipbookMode();
        fbPageCache = {};
        fbCurrentSpread = 0;
        fbAnimating = false;
        resetFlipper();
        hideFlipError();
        showLoading();
        pdfDoc = null;
        fbTotalPages = 0;

        if(canvasL) canvasL.getContext('2d').clearRect(0,0,canvasL.width,canvasL.height);
        if(canvasR) canvasR.getContext('2d').clearRect(0,0,canvasR.width,canvasR.height);

        if(typeof pdfjsLib === 'undefined'){
            showFlipError('Pustaka PDF.js belum dimuat. Muat ulang halaman.');
            return;
        }

        pdfjsLib.getDocument({
            url: streamUrl,
            withCredentials: true,
            cMapUrl: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/cmaps/',
            cMapPacked: true
        }).promise.then(function(pdf){
            pdfDoc = pdf;
            fbTotalPages = pdf.numPages;
            if(pagerTotal) pagerTotal.textContent = fbTotalPages;
            if(pagerInput) pagerInput.max = fbTotalPages;
            fbCurrentSpread = 0;
            renderSpread(0);
            updatePagerButtons();
            // Preload halaman berikutnya
            if(fbTotalPages > 1){
                renderPage(2, document.createElement('canvas'), null).catch(function(){});
            }
        }).catch(function(err){
            console.error('Flipbook load error:', err);
            showFlipError('Tidak dapat membuka PDF. Pastikan sudah login.');
        });
    }

    function openReader(data){
        if(!data || !data.url) return;
        openUrl = data.url;
        openStreamUrl = data.stream_url || getStreamUrl(data.id);
        openId = parseInt(data.id||0,10);
        openMeta = data;
        titleEl.textContent = data.title || 'Dokumen';
        metaEl.textContent = data.meta || '';
        dl.href = data.url;
        reader.hidden = false;
        reader.classList.add('is-open');
        document.body.classList.add('reader-open');
        if(catalog) catalog.hidden = true;
        if(data.href && history.replaceState) history.replaceState({perpus:1}, '', data.href);
        bumpProgress(openId, data, 5);
        clearInterval(readTimer);
        readTimer = setInterval(function(){ bumpProgress(openId, openMeta, 3); }, 20000);
        loadPdf(openStreamUrl);
    }
    function closeReader(){
        clearInterval(readTimer);
        if(openId) bumpProgress(openId, openMeta, 8);
        reader.classList.remove('is-open');
        reader.hidden = true;
        document.body.classList.remove('reader-open');
        if(catalog) catalog.hidden = false;
        pdfDoc = null;
        fbPageCache = {};
        resetFlipper();
        hideFlipError();
        openUrl='';
        openStreamUrl='';
        openId=0;
        if(history.replaceState) history.replaceState({}, '', backUrl);
    }
    function bumpProgress(id, data, add){
        if(!id || !data) return;
        var shelf = getShelf();
        var cur = shelf[id] || { id:id, title:data.title, meta:data.meta, url:data.url, thumb:data.thumb||'', progress:0, updated:0 };
        cur.progress = Math.min(100, (cur.progress||0) + (add||1));
        cur.updated = Date.now();
        cur.title = data.title || cur.title;
        cur.meta = data.meta || cur.meta;
        cur.url = data.url || cur.url;
        cur.thumb = data.thumb || cur.thumb;
        shelf[id] = cur;
        saveShelf(shelf);
    }

    function coverHtml(thumb){
        return thumb ? '<img src="'+thumb+'" alt="">' : 'PDF';
    }
    function renderShelf(){
        var shelf = getShelf();
        var list = Object.keys(shelf).map(function(k){ return shelf[k]; })
            .sort(function(a,b){ return (b.updated||0)-(a.updated||0); });
        var continueSec = document.getElementById('continue-section');
        var continueSlot = document.getElementById('continue-slot');
        var shelfList = document.getElementById('shelf-list');
        var reading = list.filter(function(x){ return (x.progress||0) < 100; });
        var done = list.filter(function(x){ return (x.progress||0) >= 80; });
        var statReading = document.getElementById('stat-reading');
        var statDone = document.getElementById('stat-done');
        if(statReading) statReading.textContent = String(list.length);
        if(statDone) statDone.textContent = String(done.length);

        if(continueSec && continueSlot){
            if(!reading.length){ continueSec.hidden = true; }
            else {
                continueSec.hidden = false;
                var top = reading[0];
                var daysLeft = Math.max(1, 14 - Math.floor(((Date.now()-(top.updated||Date.now()))/86400000)));
                continueSlot.innerHTML =
                    '<article class="progress-card js-resume" data-id="'+top.id+'" data-title="'+esc(top.title)+'" data-meta="'+esc(top.meta||'')+'" data-url="'+esc(top.url)+'" data-thumb="'+esc(top.thumb||'')+'">' +
                    '<div class="progress-card__thumb">'+coverHtml(top.thumb)+'</div>' +
                    '<div><strong>'+esc(top.title)+'</strong><span>'+esc(top.meta||'Sedang dibaca')+' · '+Math.round(top.progress)+'%</span>' +
                    '<div class="bar"><i style="width:'+Math.round(top.progress)+'%"></i></div></div>' +
                    '<div class="badge-due"><b>'+daysLeft+'</b>hari</div></article>';
            }
        }
        if(shelfList){
            if(!list.length){
                shelfList.innerHTML = '<div class="empty"><strong>Rak masih kosong</strong>Mulai baca buku — progress tersimpan otomatis di perangkat ini.</div>';
            } else {
                shelfList.innerHTML = list.map(function(top){
                    return '<article class="progress-card js-resume" data-id="'+top.id+'" data-title="'+esc(top.title)+'" data-meta="'+esc(top.meta||'')+'" data-url="'+esc(top.url)+'" data-thumb="'+esc(top.thumb||'')+'">' +
                        '<div class="progress-card__thumb">'+coverHtml(top.thumb)+'</div>' +
                        '<div><strong>'+esc(top.title)+'</strong><span>'+Math.round(top.progress)+'% selesai</span>' +
                        '<div class="bar"><i style="width:'+Math.round(top.progress)+'%"></i></div></div>' +
                        '<div class="badge-due"><b>'+Math.round(top.progress)+'</b>%</div></article>';
                }).join('');
            }
        }
        bindOpeners();
    }
    function esc(s){
        return String(s||'').replace(/[&<>"']/g, function(c){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
        });
    }

    function bindOpeners(){
        document.querySelectorAll('.js-open-reader, .js-resume').forEach(function(el){
            if(el._bound) return;
            el._bound = true;
            el.addEventListener('click', function(e){
                if(e.target.closest && e.target.closest('a') && !el.classList.contains('js-open-reader') && !el.classList.contains('js-resume') && !el.classList.contains('hero-card') && !el.classList.contains('book-slide') && !el.classList.contains('row-book') && !el.classList.contains('progress-card')) return;
                openReader({
                    id: el.getAttribute('data-id'),
                    title: el.getAttribute('data-title'),
                    meta: el.getAttribute('data-meta'),
                    url: el.getAttribute('data-url'),
                    stream_url: el.getAttribute('data-stream-url') || getStreamUrl(el.getAttribute('data-id')),
                    thumb: el.getAttribute('data-thumb'),
                    href: el.getAttribute('data-href') || (baseUrl + '?baca=' + el.getAttribute('data-id'))
                });
            });
        });
    }

    function setTab(tab){
        if(tab === 'profile') tab = 'shelf';
        document.querySelectorAll('.panel').forEach(function(p){
            p.classList.toggle('is-active', p.getAttribute('data-panel')===tab);
        });
        document.querySelectorAll('.js-tab-nav, .js-goto-tab').forEach(function(b){
            b.classList.toggle('is-active', b.getAttribute('data-tab')===tab);
        });
        if(catalog){
            catalog.classList.toggle('show-search', true);
            catalog.classList.toggle('show-chips', tab === 'home' || tab === 'search');
        }
        if(tab==='search'){
            var inp = document.getElementById('search-input');
            if(inp) setTimeout(function(){ inp.focus(); }, 50);
        }
        if(history.replaceState){
            var u = new URL(window.location.href);
            if(tab==='home') u.searchParams.delete('tab'); else u.searchParams.set('tab', tab);
            history.replaceState({}, '', u.toString());
            backUrl = u.toString().replace(/[?&]baca=\d+/, '').replace(/\?$/, '');
        }
    }

    document.querySelectorAll('.js-tab-nav, .js-goto-tab').forEach(function(btn){
        btn.addEventListener('click', function(){ setTab(btn.getAttribute('data-tab')); });
    });

    document.querySelectorAll('.js-quick-kat').forEach(function(btn){
        btn.addEventListener('click', function(){
            var kat = btn.getAttribute('data-kat');
            var u = new URL(baseUrl, window.location.origin);
            var q = document.getElementById('search-input').value;
            if(q) u.searchParams.set('q', q);
            u.searchParams.set('kategori', kat);
            u.searchParams.set('tab', 'search');
            window.location.href = u.toString();
        });
    });

    var btnClear = document.getElementById('btn-clear-shelf');
    if(btnClear){
        btnClear.addEventListener('click', function(){
            if(confirm('Hapus semua progress baca di perangkat ini?')){
                localStorage.removeItem(STORAGE_KEY);
                renderShelf();
                toastMsg('Progress direset');
            }
        });
    }

    document.getElementById('reader-close').addEventListener('click', closeReader);
    document.getElementById('fb-retry').addEventListener('click', function(){
        if(openStreamUrl) loadPdf(openStreamUrl);
    });

    document.getElementById('fb-prev').addEventListener('click', prevSpread);
    document.getElementById('fb-next').addEventListener('click', nextSpread);
    document.getElementById('pager-prev').addEventListener('click', prevSpread);
    document.getElementById('pager-next').addEventListener('click', nextSpread);
    pagerInput.addEventListener('change', function(){ goToPage(parseInt(pagerInput.value,10)||1); });

    document.addEventListener('keydown', function(e){
        if(!reader.classList.contains('is-open')) return;
        if(e.key==='Escape'){ e.preventDefault(); closeReader(); }
        if(e.key==='ArrowLeft' || e.key==='ArrowUp'){ e.preventDefault(); prevSpread(); }
        if(e.key==='ArrowRight' || e.key==='ArrowDown'){ e.preventDefault(); nextSpread(); }
    });

    // Swipe support
    (function(){
        var startX = 0, startY = 0, swiping = false;
        var fb = document.getElementById('flipbook');
        if(!fb) return;
        fb.addEventListener('touchstart', function(e){
            if(e.touches.length !== 1) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            swiping = true;
        }, {passive: true});
        fb.addEventListener('touchend', function(e){
            if(!swiping) return;
            swiping = false;
            var dx = e.changedTouches[0].clientX - startX;
            var dy = e.changedTouches[0].clientY - startY;
            if(Math.abs(dx) < 40 || Math.abs(dy) > Math.abs(dx)) return;
            if(dx < 0) nextSpread(); else prevSpread();
        }, {passive: true});
    })();

    // Resize handler
    var resizeTimer;
    window.addEventListener('resize', function(){
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function(){
            syncFlipbookMode();
            if(pdfDoc){
                // Sesuaikan spread saat beralih mode 1/2 halaman
                var pageNow = parseInt(pagerInput && pagerInput.value ? pagerInput.value : '1', 10) || 1;
                fbPageCache = {};
                fbCurrentSpread = fbIsMobile() ? Math.max(0, pageNow - 1) : Math.floor((pageNow - 1) / 2);
                renderSpread(fbCurrentSpread);
                updatePagerButtons();
            }
        }, 200);
    });

    // Theme init removed — mengikuti desain portal /sekolah/

    bindOpeners();
    renderShelf();

    // Scroll chip: native di touch; drag mouse hanya setelah geser nyata (klik tetap jalan)
    (function enableChipsScroll(){
        var scroller = document.getElementById('perpus-chips');
        if (!scroller) return;

        var active = false;
        var dragging = false;
        var startX = 0;
        var startLeft = 0;
        var pointerId = null;
        var THRESHOLD = 10;

        scroller.addEventListener('pointerdown', function(e){
            // Touch/pen: biarkan native scroll + klik link
            if (e.pointerType !== 'mouse' || e.button !== 0) return;
            active = true;
            dragging = false;
            startX = e.clientX;
            startLeft = scroller.scrollLeft;
            pointerId = e.pointerId;
        });

        scroller.addEventListener('pointermove', function(e){
            if (!active || e.pointerType !== 'mouse') return;
            var dx = e.clientX - startX;
            if (!dragging) {
                if (Math.abs(dx) < THRESHOLD) return;
                dragging = true;
                scroller.classList.add('is-dragging');
                try { scroller.setPointerCapture(pointerId); } catch (err) {}
            }
            scroller.scrollLeft = startLeft - dx;
            e.preventDefault();
        });

        function endDrag(){
            if (!active) return;
            var wasDragging = dragging;
            active = false;
            dragging = false;
            scroller.classList.remove('is-dragging');
            if (pointerId != null) {
                try { scroller.releasePointerCapture(pointerId); } catch (err) {}
                pointerId = null;
            }
            if (wasDragging) {
                var block = function(ev){
                    ev.preventDefault();
                    ev.stopImmediatePropagation();
                    scroller.removeEventListener('click', block, true);
                };
                scroller.addEventListener('click', block, true);
                setTimeout(function(){ scroller.removeEventListener('click', block, true); }, 50);
            }
        }

        scroller.addEventListener('pointerup', endDrag);
        scroller.addEventListener('pointercancel', endDrag);
        scroller.addEventListener('pointerleave', function(e){
            if (e.pointerType === 'mouse' && active && !dragging) endDrag();
        });

        scroller.addEventListener('wheel', function(e){
            if (scroller.scrollWidth <= scroller.clientWidth) return;
            if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
            scroller.scrollLeft += e.deltaY;
            e.preventDefault();
        }, { passive: false });
    })();

    <?php if ( $baca_item ) : ?>
    openUrl = <?php echo wp_json_encode( $baca_item['pdf_url'] ); ?>;
    openStreamUrl = <?php echo wp_json_encode( qrg_get_perpustakaan_pdf_stream_url( $baca_item['id'] ) ); ?>;
    openId = <?php echo (int) $baca_item['id']; ?>;
    openMeta = {
        id: openId,
        title: <?php echo wp_json_encode( $baca_item['title'] ); ?>,
        meta: <?php echo wp_json_encode( ( $baca_item['penulis'] ? $baca_item['penulis'] . ' · ' : '' ) . $baca_item['kategori_label'] ); ?>,
        url: openUrl,
        stream_url: openStreamUrl,
        thumb: <?php echo wp_json_encode( $baca_item['thumb'] ? $baca_item['thumb'] : '' ); ?>
    };
    titleEl.textContent = openMeta.title;
    metaEl.textContent = openMeta.meta;
    dl.href = openUrl;
    reader.hidden = false;
    reader.classList.add('is-open');
    document.body.classList.add('reader-open');
    if(catalog) catalog.hidden = true;
    bumpProgress(openId, openMeta, 5);
    readTimer = setInterval(function(){ bumpProgress(openId, openMeta, 3); }, 20000);
    setTimeout(function(){ loadPdf(openStreamUrl); }, 150);
    <?php endif; ?>
})();
</script>
</div><!-- /app -->
</div><!-- /qrg-sk-perpus -->
<?php
if ( function_exists( 'qrg_sekolah_portal_shell_end' ) ) {
    qrg_sekolah_portal_shell_end();
} else {
    if ( function_exists( 'qrg_pembayaran_render_idle_script' ) ) {
        qrg_pembayaran_render_idle_script();
    }
    echo '</body></html>';
}
}

add_shortcode( 'perpustakaan', 'qrg_perpustakaan_shortcode' );
function qrg_perpustakaan_shortcode( $atts ) {
    $atts = shortcode_atts( [
        'kategori' => '',
        'limit'    => 12,
    ], $atts, 'perpustakaan' );

    $items = qrg_get_perpustakaan_items( [
        'kategori' => sanitize_key( $atts['kategori'] ),
        'limit'    => absint( $atts['limit'] ) ?: 12,
    ] );

    $url = qrg_get_perpustakaan_url();
    ob_start();
    ?>
    <div class="qrg-perpus-embed" style="display:grid;gap:10px;font-family:Inter,system-ui,sans-serif;">
        <?php if ( empty( $items ) ) : ?>
            <p>Belum ada dokumen di perpustakaan.</p>
        <?php else : ?>
            <ul style="margin:0;padding-left:18px;">
                <?php foreach ( $items as $item ) : ?>
                    <li style="margin-bottom:6px;">
                        <a href="<?php echo esc_url( qrg_get_perpustakaan_url( [ 'baca' => $item['id'] ] ) ); ?>">
                            <?php echo esc_html( $item['title'] ); ?>
                        </a>
                        <span style="color:#64748b;font-size:12px;"> — <?php echo esc_html( $item['kategori_label'] ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><a href="<?php echo esc_url( $url ); ?>">Buka aplikasi Perpustakaan →</a></p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
