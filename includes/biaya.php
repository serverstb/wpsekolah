<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Registrasi CPT Biaya Tambahan (hanya admin, tanpa URL publik)
add_action( 'init', 'qrg_register_biaya_cpt' );
function qrg_register_biaya_cpt() {
    register_post_type( 'master_biaya', [
        'labels' => [
            'name'          => 'Jenis Biaya Tambahan',
            'singular_name' => 'Jenis Biaya',
            'menu_name'     => 'Biaya Tambahan',
            'add_new_item'  => 'Tambah Jenis Biaya Baru',
            'edit_item'     => 'Edit Jenis Biaya',
            'all_items'     => 'Jenis Biaya',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => false, // Akses via tombol di Tagihan Siswa (menu Akuntansi)
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'supports'            => [ 'title' ],
    ]);
}

// Hapus permalink/slug lama /master_biaya/... sekali setelah update
add_action( 'admin_init', 'qrg_biaya_flush_rewrite_once' );
function qrg_biaya_flush_rewrite_once() {
    if ( get_option( 'qrg_biaya_rewrite_flushed_v2' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'qrg_biaya_rewrite_flushed_v2', 1 );
}

// Sembunyikan link "Lihat" di admin Jenis Biaya
add_filter( 'post_row_actions', 'qrg_biaya_remove_view_link', 10, 2 );
function qrg_biaya_remove_view_link( $actions, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_biaya' ) {
        unset( $actions['view'] );
    }
    return $actions;
}

add_filter( 'get_sample_permalink_html', 'qrg_biaya_hide_permalink_box', 10, 2 );
function qrg_biaya_hide_permalink_box( $html, $post_id ) {
    if ( get_post_type( $post_id ) === 'master_biaya' ) {
        return '';
    }
    return $html;
}

// 2. Tambah Meta Box Informasi Detail Biaya Tambahan
add_action( 'add_meta_boxes', 'qrg_biaya_meta_boxes' );
function qrg_biaya_meta_boxes() {
    add_meta_box( 'biaya_detail', 'Detail Biaya Tambahan', 'qrg_biaya_fields_callback', 'master_biaya', 'normal', 'high' );
}

// 3. Form Input Data Biaya Tambahan di Dashboard Admin
function qrg_biaya_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_biaya_meta', 'qrg_biaya_nonce' );
    
    // Ambil data yang tersimpan di database
    $nominal   = get_post_meta( $post->ID, '_biaya_nominal', true );
    $deskripsi = get_post_meta( $post->ID, '_biaya_deskripsi', true );
    
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Silakan isi <strong>NAMA BIAYA TAMBAHAN</strong> (contoh: Biaya Administrasi, Biaya Pendaftaran) di kolom judul paling atas.
    </p>
    
    <table class="form-table">
        <tr>
            <th><label for="biaya_nominal">Nominal Biaya (Rp)</label></th>
            <td>
                <input type="number" id="biaya_nominal" name="biaya_nominal" value="<?php echo esc_attr($nominal); ?>" class="regular-text" required placeholder="Contoh: 10000">
                <p class="description">Masukkan nilai angka saja tanpa tanda titik atau koma.</p>
            </td>
        </tr>
        <tr>
            <th><label for="biaya_deskripsi">Deskripsi / Keterangan</label></th>
            <td>
                <textarea id="biaya_deskripsi" name="biaya_deskripsi" rows="4" class="large-text" placeholder="Contoh: Biaya admin untuk penanganan gerbang pembayaran QRIS"><?php echo esc_textarea($deskripsi); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

// 4. Proses Simpan Data Biaya Tambahan ke Basis Data WordPress
add_action( 'save_post_master_biaya', 'qrg_save_biaya_details' );
function qrg_save_biaya_details( $post_id ) {
    if ( ! isset( $_POST['qrg_biaya_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_biaya_nonce'], 'qrg_save_biaya_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    if ( isset( $_POST['biaya_nominal'] ) ) {
        update_post_meta( $post_id, '_biaya_nominal', sanitize_text_field( $_POST['biaya_nominal'] ) );
    }
    if ( isset( $_POST['biaya_deskripsi'] ) ) {
        update_post_meta( $post_id, '_biaya_deskripsi', sanitize_textarea_field( $_POST['biaya_deskripsi'] ) );
    }
}

// 5. Kustomisasi Kolom Tabel Daftar Biaya Tambahan di Dashboard
add_filter( 'manage_master_biaya_posts_columns', 'qrg_set_biaya_columns' );
function qrg_set_biaya_columns($columns) {
    $new_columns = array();
    $new_columns['cb'] = $columns['cb'];
    $new_columns['title'] = 'Nama Biaya';
    $new_columns['nominal'] = 'Nominal (Rp)';
    $new_columns['deskripsi'] = 'Keterangan';
    return $new_columns;
}

add_action( 'manage_master_biaya_posts_custom_column' , 'qrg_fill_biaya_columns', 10, 2 );
function qrg_fill_biaya_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'nominal' :
            $nominal = get_post_meta( $post_id, '_biaya_nominal', true );
            echo '<strong>Rp ' . number_format( intval($nominal), 0, ',', '.' ) . '</strong>';
            break;
        case 'deskripsi' :
            $deskripsi = get_post_meta( $post_id, '_biaya_deskripsi', true );
            echo esc_html( $deskripsi ? $deskripsi : '-' );
            break;
    }
}
