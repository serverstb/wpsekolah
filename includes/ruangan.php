<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Registrasi CPT Data Ruangan
add_action( 'init', 'qrg_register_ruangan_cpt' );
function qrg_register_ruangan_cpt() {
    register_post_type( 'master_ruangan', [
        'labels' => [
            'name'               => 'Data Ruangan',
            'singular_name'      => 'Ruangan',
            'menu_name'          => 'Data Ruangan',
            'add_new'            => 'Tambah Baru',
            'add_new_item'       => 'Tambah Ruangan Baru',
            'edit_item'          => 'Edit Ruangan',
            'new_item'           => 'Ruangan Baru',
            'search_items'       => 'Cari Ruangan',
            'not_found'          => 'Data ruangan tidak ditemukan',
            'not_found_in_trash' => 'Tidak ada ruangan di tong sampah',
            'all_items'          => 'Data Ruangan',
        ],
        'public'             => true,
        'show_ui'            => true,
        'show_in_menu'       => 'master-data-plugin',
        'supports'           => [ 'title' ],
        'has_archive'        => false,
        'rewrite'            => false,
        'publicly_queryable' => false,
    ] );
}

add_filter( 'enter_title_here', 'qrg_ruangan_title_placeholder', 10, 2 );
function qrg_ruangan_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_ruangan' ) {
        return 'Contoh: Lab Komputer / Ruang Guru / Gudang';
    }
    return $title;
}

// Tombol Inventaris di samping "Tambah Baru" pada Data Ruangan
add_action( 'admin_footer-edit.php', 'qrg_ruangan_header_action_button' );
function qrg_ruangan_header_action_button() {
    global $typenow;
    if ( $typenow !== 'master_ruangan' || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $inventaris_url = admin_url( 'edit.php?post_type=master_inventaris' );
    ?>
    <script>
    jQuery(function($) {
        var $btn = $('.wrap .page-title-action').first();
        if (!$btn.length) {
            return;
        }
        $('<a href="<?php echo esc_url( $inventaris_url ); ?>" class="page-title-action">Inventaris</a>')
            .insertAfter($btn);
    });
    </script>
    <?php
}

function qrg_ruangan_jenis_options() {
    return [
        'Kelas'     => 'Ruang Kelas',
        'Lab'       => 'Laboratorium',
        'Kantor'    => 'Kantor / Administrasi',
        'Perpus'    => 'Perpustakaan',
        'Olahraga'  => 'Ruang / Lapangan Olahraga',
        'Gudang'    => 'Gudang',
        'Aula'      => 'Aula / Serbaguna',
        'Lainnya'   => 'Lainnya',
    ];
}

/**
 * Ambil semua ruangan terdaftar.
 */
function qrg_get_daftar_ruangan() {
    return get_posts( [
        'post_type'      => 'master_ruangan',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
}

/**
 * Label ruangan: "Lab Komputer (LAB-01)" atau dengan kelas terkait.
 */
function qrg_get_ruangan_label( $ruangan_id ) {
    $ruangan_id = (int) $ruangan_id;
    if ( ! $ruangan_id || get_post_type( $ruangan_id ) !== 'master_ruangan' ) {
        return '';
    }

    $label = get_the_title( $ruangan_id );
    $kode  = get_post_meta( $ruangan_id, '_ruangan_kode', true );
    if ( $kode ) {
        $label .= ' (' . $kode . ')';
    }

    $kelas_id = (int) get_post_meta( $ruangan_id, '_ruangan_kelas_id', true );
    if ( $kelas_id && function_exists( 'qrg_get_kelas_label' ) ) {
        $kelas_label = qrg_get_kelas_label( $kelas_id );
        if ( $kelas_label ) {
            $label .= ' — ' . $kelas_label;
        }
    }

    return $label;
}

/**
 * Inventaris yang ada di ruangan tertentu.
 */
function qrg_get_inventaris_by_ruangan( $ruangan_id ) {
    $ruangan_id = (int) $ruangan_id;
    if ( ! $ruangan_id ) {
        return [];
    }

    return get_posts( [
        'post_type'      => 'master_inventaris',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => [
            [
                'key'   => '_inv_lokasi_ruangan_id',
                'value' => (string) $ruangan_id,
            ],
        ],
    ] );
}

// 2. Meta Box Detail Ruangan
add_action( 'add_meta_boxes', 'qrg_ruangan_meta_boxes' );
function qrg_ruangan_meta_boxes() {
    add_meta_box( 'ruangan_detail', 'Detail Data Ruangan', 'qrg_ruangan_fields_callback', 'master_ruangan', 'normal', 'high' );
    add_meta_box( 'ruangan_inventaris', 'Inventaris di Ruangan Ini', 'qrg_ruangan_inventaris_callback', 'master_ruangan', 'normal', 'default' );
}

function qrg_ruangan_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_ruangan_meta', 'qrg_ruangan_nonce' );

    $kode      = get_post_meta( $post->ID, '_ruangan_kode', true );
    $jenis     = get_post_meta( $post->ID, '_ruangan_jenis', true );
    $gedung    = get_post_meta( $post->ID, '_ruangan_gedung', true );
    $lantai    = get_post_meta( $post->ID, '_ruangan_lantai', true );
    $kapasitas = get_post_meta( $post->ID, '_ruangan_kapasitas', true );
    $kelas_id  = (int) get_post_meta( $post->ID, '_ruangan_kelas_id', true );
    $ket       = get_post_meta( $post->ID, '_ruangan_keterangan', true );
    $daftar_kelas = function_exists( 'qrg_get_daftar_kelas' ) ? qrg_get_daftar_kelas() : [];
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Isi <strong>NAMA RUANGAN</strong> di judul atas (contoh: Lab Komputer, Gudang, Ruang Guru).
        Data ini dipakai sebagai lokasi di <strong>Inventaris</strong>.
    </p>

    <table class="form-table">
        <tr>
            <th><label for="ruangan_kode">Kode Ruangan</label></th>
            <td>
                <input type="text" id="ruangan_kode" name="ruangan_kode" value="<?php echo esc_attr( $kode ); ?>" class="regular-text" placeholder="Contoh: R-LAB-01">
            </td>
        </tr>
        <tr>
            <th><label for="ruangan_jenis">Jenis Ruangan</label></th>
            <td>
                <select id="ruangan_jenis" name="ruangan_jenis" style="min-width:280px;">
                    <option value="">-- Pilih Jenis --</option>
                    <?php foreach ( qrg_ruangan_jenis_options() as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $jenis, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="ruangan_kelas_id">Terkait Data Kelas</label></th>
            <td>
                <select id="ruangan_kelas_id" name="ruangan_kelas_id" style="min-width:320px;">
                    <option value="">-- Tidak terkait kelas --</option>
                    <?php foreach ( $daftar_kelas as $kelas_post ) : ?>
                        <option value="<?php echo esc_attr( $kelas_post->ID ); ?>" <?php selected( $kelas_id, $kelas_post->ID ); ?>>
                            <?php echo esc_html( qrg_get_kelas_label( $kelas_post->ID ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Opsional. Isi jika ruangan ini adalah ruang kelas tertentu.</p>
            </td>
        </tr>
        <tr>
            <th><label for="ruangan_gedung">Gedung / Blok</label></th>
            <td>
                <input type="text" id="ruangan_gedung" name="ruangan_gedung" value="<?php echo esc_attr( $gedung ); ?>" class="regular-text" placeholder="Contoh: Gedung A">
            </td>
        </tr>
        <tr>
            <th><label for="ruangan_lantai">Lantai</label></th>
            <td>
                <input type="text" id="ruangan_lantai" name="ruangan_lantai" value="<?php echo esc_attr( $lantai ); ?>" class="small-text" placeholder="1">
            </td>
        </tr>
        <tr>
            <th><label for="ruangan_kapasitas">Kapasitas</label></th>
            <td>
                <input type="number" id="ruangan_kapasitas" name="ruangan_kapasitas" value="<?php echo esc_attr( $kapasitas ); ?>" class="small-text" min="0" placeholder="40">
                <span class="description">orang / kursi</span>
            </td>
        </tr>
        <tr>
            <th><label for="ruangan_keterangan">Keterangan</label></th>
            <td>
                <textarea id="ruangan_keterangan" name="ruangan_keterangan" rows="3" class="large-text" placeholder="Catatan lokasi atau fasilitas ruangan"><?php echo esc_textarea( $ket ); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

function qrg_ruangan_inventaris_callback( $post ) {
    if ( ! $post->ID || $post->post_status === 'auto-draft' ) {
        echo '<p style="color:#666;">Simpan ruangan terlebih dahulu untuk melihat inventaris.</p>';
        return;
    }

    $items = qrg_get_inventaris_by_ruangan( $post->ID );
    $add_url = admin_url( 'post-new.php?post_type=master_inventaris' );
    ?>
    <p style="margin-top:0;">
        Total: <strong><?php echo count( $items ); ?> item</strong>
        · <a href="<?php echo esc_url( $add_url ); ?>">Tambah Inventaris</a>
        · <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=master_inventaris' ) ); ?>">Lihat Semua Inventaris</a>
    </p>
    <?php if ( empty( $items ) ) : ?>
        <p style="color:#888;">Belum ada inventaris di ruangan ini. Pilih ruangan ini pada form Inventaris → Lokasi / Ruangan.</p>
    <?php else : ?>
        <table class="widefat striped" style="margin-top:10px;">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>Nama Barang</th>
                    <th>Kode</th>
                    <th>Jumlah</th>
                    <th>Kondisi</th>
                    <th style="width:80px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $i => $item ) : ?>
                    <?php
                    $jumlah  = get_post_meta( $item->ID, '_inv_jumlah', true );
                    $satuan  = get_post_meta( $item->ID, '_inv_satuan', true );
                    $kondisi = get_post_meta( $item->ID, '_inv_kondisi', true );
                    ?>
                    <tr>
                        <td><?php echo (int) $i + 1; ?></td>
                        <td><strong><?php echo esc_html( $item->post_title ); ?></strong></td>
                        <td><?php echo esc_html( get_post_meta( $item->ID, '_inv_kode', true ) ?: '-' ); ?></td>
                        <td><?php echo esc_html( ( $jumlah !== '' ? $jumlah : '0' ) . ' ' . $satuan ); ?></td>
                        <td><?php echo esc_html( $kondisi ?: '-' ); ?></td>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif;
}

add_action( 'save_post_master_ruangan', 'qrg_save_ruangan_details' );
function qrg_save_ruangan_details( $post_id ) {
    if ( ! isset( $_POST['qrg_ruangan_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_ruangan_nonce'], 'qrg_save_ruangan_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    $map = [
        'ruangan_kode'        => '_ruangan_kode',
        'ruangan_jenis'       => '_ruangan_jenis',
        'ruangan_gedung'      => '_ruangan_gedung',
        'ruangan_lantai'      => '_ruangan_lantai',
        'ruangan_kapasitas'   => '_ruangan_kapasitas',
    ];
    foreach ( $map as $post_key => $meta_key ) {
        if ( isset( $_POST[ $post_key ] ) ) {
            update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
        }
    }

    if ( isset( $_POST['ruangan_keterangan'] ) ) {
        update_post_meta( $post_id, '_ruangan_keterangan', sanitize_textarea_field( wp_unslash( $_POST['ruangan_keterangan'] ) ) );
    }

    $kelas_id = isset( $_POST['ruangan_kelas_id'] ) ? (int) $_POST['ruangan_kelas_id'] : 0;
    if ( $kelas_id && get_post_type( $kelas_id ) === 'master_kelas' ) {
        update_post_meta( $post_id, '_ruangan_kelas_id', $kelas_id );
    } else {
        delete_post_meta( $post_id, '_ruangan_kelas_id' );
    }
}

// 3. Kolom daftar admin
add_filter( 'manage_master_ruangan_posts_columns', 'qrg_set_ruangan_columns' );
function qrg_set_ruangan_columns( $columns ) {
    return [
        'cb'         => $columns['cb'],
        'title'      => 'Nama Ruangan',
        'kode'       => 'Kode',
        'jenis'      => 'Jenis',
        'kelas'      => 'Data Kelas',
        'gedung'     => 'Gedung / Lantai',
        'jml_inv'    => 'Inventaris',
        'date'       => $columns['date'],
    ];
}

add_action( 'manage_master_ruangan_posts_custom_column', 'qrg_fill_ruangan_columns', 10, 2 );
function qrg_fill_ruangan_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'kode':
            echo esc_html( get_post_meta( $post_id, '_ruangan_kode', true ) ?: '-' );
            break;
        case 'jenis':
            $jenis = get_post_meta( $post_id, '_ruangan_jenis', true );
            $opts  = qrg_ruangan_jenis_options();
            echo esc_html( isset( $opts[ $jenis ] ) ? $opts[ $jenis ] : ( $jenis ?: '-' ) );
            break;
        case 'kelas':
            $kelas_id = (int) get_post_meta( $post_id, '_ruangan_kelas_id', true );
            if ( $kelas_id ) {
                echo '<a href="' . esc_url( get_edit_post_link( $kelas_id ) ) . '">' . esc_html( qrg_get_kelas_label( $kelas_id ) ) . '</a>';
            } else {
                echo '<span style="color:#aaa;">-</span>';
            }
            break;
        case 'gedung':
            $gedung = get_post_meta( $post_id, '_ruangan_gedung', true );
            $lantai = get_post_meta( $post_id, '_ruangan_lantai', true );
            $parts  = array_filter( [ $gedung, $lantai !== '' ? 'Lt. ' . $lantai : '' ] );
            echo esc_html( $parts ? implode( ' / ', $parts ) : '-' );
            break;
        case 'jml_inv':
            echo '<strong>' . count( qrg_get_inventaris_by_ruangan( $post_id ) ) . '</strong>';
            break;
    }
}
