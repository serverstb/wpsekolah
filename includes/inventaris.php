<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Registrasi CPT Inventaris (CRUD native WordPress)
add_action( 'init', 'qrg_register_inventaris_cpt' );
function qrg_register_inventaris_cpt() {
    register_post_type( 'master_inventaris', [
        'labels' => [
            'name'               => 'Inventaris',
            'singular_name'      => 'Inventaris',
            'menu_name'          => 'Inventaris',
            'name_admin_bar'     => 'Inventaris',
            'add_new'            => 'Tambah Baru',
            'add_new_item'       => 'Tambah Inventaris Baru',
            'edit_item'          => 'Edit Inventaris',
            'new_item'           => 'Inventaris Baru',
            'view_item'          => 'Lihat Inventaris',
            'search_items'       => 'Cari Inventaris',
            'not_found'          => 'Data inventaris tidak ditemukan',
            'not_found_in_trash' => 'Tidak ada inventaris di tong sampah',
            'all_items'          => 'Inventaris',
        ],
        'public'       => true,
        'show_ui'      => true,
        'show_in_menu' => false, // Akses via tombol di Data Ruangan
        'supports'     => [ 'title', 'thumbnail' ],
        'has_archive'  => false,
        'rewrite'      => false,
        'publicly_queryable' => false,
    ]);
}

// Highlight menu Data Ruangan saat di Inventaris
add_filter( 'parent_file', 'qrg_inventaris_parent_file' );
function qrg_inventaris_parent_file( $parent_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_inventaris' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_inventaris_submenu_file' );
function qrg_inventaris_submenu_file( $submenu_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_inventaris' ) {
        return 'edit.php?post_type=master_ruangan';
    }
    return $submenu_file;
}

// Tombol kembali ke Data Ruangan
add_action( 'admin_footer-edit.php', 'qrg_inventaris_header_buttons' );
add_action( 'admin_footer-post.php', 'qrg_inventaris_header_buttons' );
add_action( 'admin_footer-post-new.php', 'qrg_inventaris_header_buttons' );
function qrg_inventaris_header_buttons() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_inventaris' ) {
        return;
    }
    $ruangan_url = admin_url( 'edit.php?post_type=master_ruangan' );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) return;
        var $existing = $wrap.siblings('.page-title-action').first();
        var $back = $('<a href="<?php echo esc_url( $ruangan_url ); ?>" class="page-title-action">← Data Ruangan</a>');
        if ($existing.length) {
            $back.insertBefore($existing);
        } else {
            $back.insertAfter($wrap);
        }
    });
    </script>
    <?php
}

// Sembunyikan submenu inventaris jika masih muncul
add_action( 'admin_menu', 'qrg_hide_inventaris_submenu', 999 );
function qrg_hide_inventaris_submenu() {
    remove_submenu_page( 'master-data-plugin', 'edit.php?post_type=master_inventaris' );
    remove_submenu_page( 'master-data-plugin', 'post-new.php?post_type=master_inventaris' );
}

// Redirect URL lama (placeholder) ke daftar inventaris CPT
add_action( 'admin_init', 'qrg_inventaris_redirect_old_page' );
function qrg_inventaris_redirect_old_page() {
    if ( ! is_admin() ) {
        return;
    }
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-inventaris' ) {
        wp_safe_redirect( admin_url( 'edit.php?post_type=master_inventaris' ) );
        exit;
    }
}

/**
 * Label lokasi inventaris (prioritas: Data Ruangan → Data Kelas lama → teks).
 */
function qrg_get_inventaris_lokasi_label( $post_id ) {
    $ruangan_id = (int) get_post_meta( $post_id, '_inv_lokasi_ruangan_id', true );
    if ( $ruangan_id && function_exists( 'qrg_get_ruangan_label' ) ) {
        $label = qrg_get_ruangan_label( $ruangan_id );
        if ( $label ) {
            return $label;
        }
    }

    $kelas_id = (int) get_post_meta( $post_id, '_inv_lokasi_kelas_id', true );
    if ( $kelas_id && function_exists( 'qrg_get_kelas_label' ) ) {
        $label = qrg_get_kelas_label( $kelas_id );
        if ( $label ) {
            return $label;
        }
    }

    $lokasi = get_post_meta( $post_id, '_inv_lokasi', true );
    return $lokasi ? (string) $lokasi : '';
}

/**
 * Total harga perolehan 1 item: harga satuan × jumlah.
 */
function qrg_get_inventaris_total_harga( $post_id ) {
    $harga  = (int) get_post_meta( $post_id, '_inv_harga', true );
    $jumlah = (int) get_post_meta( $post_id, '_inv_jumlah', true );
    if ( $jumlah < 1 ) {
        $jumlah = 1;
    }
    return $harga * $jumlah;
}

/**
 * Umur aset dalam tahun (dari tanggal perolehan, dibulatkan ke bawah).
 * Minimal 0. Jika tidak ada tanggal, pakai 0 (belum disusutkan oleh waktu).
 */
function qrg_get_inventaris_umur_tahun( $post_id ) {
    $tgl = get_post_meta( $post_id, '_inv_tgl_perolehan', true );
    if ( ! $tgl ) {
        return 0;
    }
    try {
        $start = new DateTime( $tgl );
        $now   = new DateTime( 'today' );
        if ( $start > $now ) {
            return 0;
        }
        $diff = $start->diff( $now );
        return max( 0, (int) $diff->y );
    } catch ( Exception $e ) {
        return 0;
    }
}

/**
 * % penyusutan per tahun (0–100).
 */
function qrg_get_inventaris_penyusutan_persen( $post_id ) {
    $persen = get_post_meta( $post_id, '_inv_penyusutan_persen', true );
    if ( $persen === '' || $persen === null ) {
        return 0.0;
    }
    $persen = (float) $persen;
    if ( $persen < 0 ) {
        $persen = 0;
    }
    if ( $persen > 100 ) {
        $persen = 100;
    }
    return $persen;
}

/**
 * Nilai aset setelah penyusutan garis lurus:
 * Nilai = Total Harga × max(0, 1 − (%/100 × umur_tahun))
 */
function qrg_get_inventaris_nilai_aset( $post_id ) {
    $total  = qrg_get_inventaris_total_harga( $post_id );
    $persen = qrg_get_inventaris_penyusutan_persen( $post_id );
    $umur   = qrg_get_inventaris_umur_tahun( $post_id );

    if ( $total <= 0 ) {
        return 0;
    }
    if ( $persen <= 0 || $umur <= 0 ) {
        return $total;
    }

    $faktor = 1 - ( ( $persen / 100 ) * $umur );
    if ( $faktor < 0 ) {
        $faktor = 0;
    }
    return (int) round( $total * $faktor );
}

/**
 * Akumulasi total harga perolehan seluruh inventaris.
 */
function qrg_get_inventaris_grand_total_harga() {
    $ids   = get_posts( [
        'post_type'      => 'master_inventaris',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );
    $total = 0;
    foreach ( $ids as $id ) {
        $total += qrg_get_inventaris_total_harga( $id );
    }
    return $total;
}

/**
 * Akumulasi total nilai aset setelah penyusutan.
 */
function qrg_get_inventaris_grand_total_nilai_aset() {
    $ids   = get_posts( [
        'post_type'      => 'master_inventaris',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );
    $total = 0;
    foreach ( $ids as $id ) {
        $total += qrg_get_inventaris_nilai_aset( $id );
    }
    return $total;
}

add_filter( 'enter_title_here', 'qrg_inventaris_title_placeholder', 10, 2 );
function qrg_inventaris_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_inventaris' ) {
        return 'Contoh: Laptop Acer Aspire 5';
    }
    return $title;
}

function qrg_inventaris_kategori_options() {
    return [
        'Elektronik'      => 'Elektronik',
        'Furniture'       => 'Furniture',
        'Alat Tulis'      => 'Alat Tulis Kantor',
        'Alat Lab'        => 'Alat Laboratorium',
        'Olahraga'        => 'Peralatan Olahraga',
        'Kendaraan'       => 'Kendaraan',
        'Buku / Perpus'   => 'Buku / Perpustakaan',
        'Lainnya'         => 'Lainnya',
    ];
}

function qrg_inventaris_kondisi_options() {
    return [
        'Baik'          => 'Baik',
        'Rusak Ringan'  => 'Rusak Ringan',
        'Rusak Berat'   => 'Rusak Berat',
        'Dalam Perbaikan' => 'Dalam Perbaikan',
        'Hilang'        => 'Hilang',
    ];
}

/**
 * Kode unik inventaris (untuk label QR).
 */
function qrg_get_inventaris_kode_unik( $post_id ) {
    $kode = trim( (string) get_post_meta( $post_id, '_inv_kode', true ) );
    if ( $kode !== '' ) {
        return $kode;
    }
    return 'INV-' . str_pad( (string) (int) $post_id, 5, '0', STR_PAD_LEFT );
}

/**
 * Teks informasi barang yang disimpan di dalam QR Code.
 */
function qrg_get_inventaris_qr_info_text( $post_id ) {
    $nama     = get_the_title( $post_id );
    $kode     = qrg_get_inventaris_kode_unik( $post_id );
    $kategori = get_post_meta( $post_id, '_inv_kategori', true );
    $jumlah   = get_post_meta( $post_id, '_inv_jumlah', true );
    $satuan   = get_post_meta( $post_id, '_inv_satuan', true );
    $kondisi  = get_post_meta( $post_id, '_inv_kondisi', true );
    $lokasi   = qrg_get_inventaris_lokasi_label( $post_id );
    $tgl      = get_post_meta( $post_id, '_inv_tgl_perolehan', true );
    $pic      = get_post_meta( $post_id, '_inv_pic', true );
    $nilai    = function_exists( 'qrg_get_inventaris_nilai_aset' ) ? qrg_get_inventaris_nilai_aset( $post_id ) : 0;

    $lines = [
        'INVENTARIS SEKOLAH',
        'Kode: ' . $kode,
        'Nama: ' . $nama,
    ];
    if ( $kategori ) {
        $lines[] = 'Kategori: ' . $kategori;
    }
    $lines[] = 'Jumlah: ' . ( $jumlah !== '' ? $jumlah : '0' ) . ( $satuan ? ' ' . $satuan : '' );
    if ( $kondisi ) {
        $lines[] = 'Kondisi: ' . $kondisi;
    }
    if ( $lokasi ) {
        $lines[] = 'Lokasi: ' . $lokasi;
    }
    if ( $tgl ) {
        $lines[] = 'Perolehan: ' . $tgl;
    }
    if ( $pic ) {
        $lines[] = 'PIC: ' . $pic;
    }
    if ( $nilai > 0 ) {
        $lines[] = 'Nilai Aset: Rp ' . number_format( $nilai, 0, ',', '.' );
    }
    $lines[] = 'ID: ' . (int) $post_id;

    return implode( "\n", $lines );
}

/**
 * URL gambar QR berisi info barang.
 */
function qrg_get_inventaris_qr_image_url( $post_id, $size = 220 ) {
    $info = qrg_get_inventaris_qr_info_text( $post_id );
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . (int) $size . 'x' . (int) $size . '&margin=8&data=' . rawurlencode( $info );
}

/**
 * URL lihat / unduh QR.
 */
function qrg_get_inventaris_qr_lihat_url( $post_id ) {
    return wp_nonce_url(
        admin_url( 'admin-post.php?action=qrg_lihat_qrcode&id=' . (int) $post_id ),
        'qrg_qrcode_' . (int) $post_id
    );
}

function qrg_get_inventaris_qr_unduh_url( $post_id ) {
    return wp_nonce_url(
        admin_url( 'admin-post.php?action=qrg_unduh_qrcode&id=' . (int) $post_id ),
        'qrg_qrcode_' . (int) $post_id
    );
}

// Alias lama agar tidak error jika masih terpanggil
function qrg_get_inventaris_barcode_value( $post_id ) {
    return qrg_get_inventaris_kode_unik( $post_id );
}

// 2. Meta Box Detail Inventaris
add_action( 'add_meta_boxes', 'qrg_inventaris_meta_boxes' );
function qrg_inventaris_meta_boxes() {
    add_meta_box( 'inventaris_detail', 'Detail Inventaris Aset', 'qrg_inventaris_fields_callback', 'master_inventaris', 'normal', 'high' );
    add_meta_box( 'inventaris_riwayat', 'Riwayat & Peminjaman', 'qrg_inventaris_riwayat_callback', 'master_inventaris', 'normal', 'high' );
    add_meta_box( 'inventaris_qrcode', 'QR Code Barang', 'qrg_inventaris_qrcode_callback', 'master_inventaris', 'side', 'high' );
}

function qrg_inventaris_riwayat_callback( $post ) {
    $history = get_post_meta( $post->ID, '_inv_history', true );
    if ( ! is_array( $history ) ) {
        $history = [];
    }
    $status = get_post_meta( $post->ID, '_inv_status', true );
    if ( ! $status ) {
        $status = 'Tersedia';
    }
    
    // Reverse array to show newest first
    $history_reversed = array_reverse( $history );
    ?>
    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 300px;">
            <h4>Catat Peminjaman / Pengembalian</h4>
            <p style="margin-bottom: 12px;">Status Saat Ini: 
                <strong>
                    <?php if ( $status === 'Tersedia' ) : ?>
                        <span style="color: #16a34a;">Tersedia</span>
                    <?php else : ?>
                        <span style="color: #dc2626;">Dipinjam</span>
                    <?php endif; ?>
                </strong>
            </p>
            <table class="form-table" style="margin-top: 0;">
                <tr>
                    <th style="width: 120px;"><label for="inv_action_type">Aksi</label></th>
                    <td>
                        <select id="inv_action_type" name="inv_action_type">
                            <option value="">-- Pilih Aksi --</option>
                            <?php if ( $status === 'Tersedia' ) : ?>
                                <option value="Pinjam">Pinjam Barang</option>
                            <?php else : ?>
                                <option value="Kembalikan">Kembalikan Barang</option>
                            <?php endif; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="inv_action_pic">Peminjam / PIC</label></th>
                    <td>
                        <select id="inv_action_pic" name="inv_action_pic" style="min-width:250px;">
                            <option value="">-- Pilih Guru --</option>
                            <?php 
                            if ( function_exists( 'qrg_get_daftar_guru' ) ) {
                                foreach ( qrg_get_daftar_guru() as $guru ) {
                                    echo '<option value="' . esc_attr( $guru->post_title ) . '">' . esc_html( $guru->post_title ) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="inv_action_date">Tanggal</label></th>
                    <td><input type="date" id="inv_action_date" name="inv_action_date" value="<?php echo esc_attr( wp_date('Y-m-d') ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="inv_action_notes">Keterangan</label></th>
                    <td><textarea id="inv_action_notes" name="inv_action_notes" rows="2" class="regular-text" placeholder="Catatan opsional"></textarea></td>
                </tr>
            </table>
            <p class="description">Simpan (Update) barang ini untuk mencatat aksi di atas ke dalam riwayat.</p>
        </div>
        
        <div style="flex: 2; min-width: 400px;">
            <h4>Riwayat Pergerakan Barang</h4>
            <table class="wp-list-table widefat fixed striped" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 120px;">Tanggal</th>
                        <th style="width: 120px;">Aksi</th>
                        <th>Oleh / Ke</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $history_reversed ) ) : ?>
                        <tr><td colspan="4">Belum ada riwayat pergerakan.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $history_reversed as $item ) : ?>
                            <tr>
                                <td><?php echo esc_html( $item['date'] ); ?></td>
                                <td>
                                    <?php 
                                    $act_color = '#64748b';
                                    if ( $item['action'] === 'Pinjam' ) $act_color = '#dc2626';
                                    if ( $item['action'] === 'Kembalikan' ) $act_color = '#16a34a';
                                    if ( $item['action'] === 'Dipindah' ) $act_color = '#2563eb';
                                    ?>
                                    <span style="color: <?php echo esc_attr( $act_color ); ?>; font-weight: 600;"><?php echo esc_html( $item['action'] ); ?></span>
                                </td>
                                <td><?php echo esc_html( $item['pic'] ); ?></td>
                                <td><?php echo esc_html( $item['notes'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function qrg_inventaris_qrcode_callback( $post ) {
    if ( ! $post->ID || $post->post_status === 'auto-draft' ) {
        echo '<p style="color:#666;">Simpan inventaris terlebih dahulu untuk menghasilkan QR Code.</p>';
        return;
    }

    $kode       = qrg_get_inventaris_kode_unik( $post->ID );
    $qr_url     = qrg_get_inventaris_qr_image_url( $post->ID, 200 );
    $lihat_url  = qrg_get_inventaris_qr_lihat_url( $post->ID );
    $unduh_url  = qrg_get_inventaris_qr_unduh_url( $post->ID );
    ?>
    <div style="text-align:center;">
        <img src="<?php echo esc_url( $qr_url ); ?>" alt="QR Code <?php echo esc_attr( $kode ); ?>" style="width:180px;height:180px;background:#fff;padding:8px;border:1px solid #e2e8f0;border-radius:8px;">
        <p style="margin:10px 0 6px;font-family:monospace;font-weight:700;"><?php echo esc_html( $kode ); ?></p>
        <p class="description" style="text-align:left;margin:0 0 12px;">
            QR berisi informasi nama, kode, kategori, jumlah, kondisi, lokasi, PIC, dan nilai aset.
        </p>
        <p style="margin:0 0 8px;">
            <a href="<?php echo esc_url( $lihat_url ); ?>" target="_blank" class="button button-secondary" style="width:100%;text-align:center;">
                Lihat QR Code
            </a>
        </p>
        <p style="margin:0;">
            <a href="<?php echo esc_url( $unduh_url ); ?>" class="button button-primary" style="width:100%;text-align:center;">
                Unduh QR Code
            </a>
        </p>
    </div>
    <?php
}

function qrg_inventaris_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_inventaris_meta', 'qrg_inventaris_nonce' );

    $kode       = get_post_meta( $post->ID, '_inv_kode', true );
    $kategori   = get_post_meta( $post->ID, '_inv_kategori', true );
    $jumlah     = get_post_meta( $post->ID, '_inv_jumlah', true );
    $satuan     = get_post_meta( $post->ID, '_inv_satuan', true );
    $kondisi    = get_post_meta( $post->ID, '_inv_kondisi', true );
    $lokasi_ruangan_id = (int) get_post_meta( $post->ID, '_inv_lokasi_ruangan_id', true );
    $lokasi_kelas_id   = (int) get_post_meta( $post->ID, '_inv_lokasi_kelas_id', true );
    $lokasi     = get_post_meta( $post->ID, '_inv_lokasi', true );
    $tgl_beli   = get_post_meta( $post->ID, '_inv_tgl_perolehan', true );
    $harga      = get_post_meta( $post->ID, '_inv_harga', true );
    $penyusutan = get_post_meta( $post->ID, '_inv_penyusutan_persen', true );
    $sumber     = get_post_meta( $post->ID, '_inv_sumber', true );
    $pic        = get_post_meta( $post->ID, '_inv_pic', true );
    $keterangan = get_post_meta( $post->ID, '_inv_keterangan', true );
    $daftar_ruangan = function_exists( 'qrg_get_daftar_ruangan' ) ? qrg_get_daftar_ruangan() : [];

    if ( $jumlah === '' ) {
        $jumlah = '1';
    }
    if ( $satuan === '' ) {
        $satuan = 'unit';
    }
    if ( $kondisi === '' ) {
        $kondisi = 'Baik';
    }
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Isi <strong>NAMA BARANG</strong> di kolom judul atas. Unggah <strong>FOTO</strong> aset melalui panel Featured Image di sisi kanan.
    </p>

    <table class="form-table">
        <tr>
            <th><label for="inv_kode">Kode Inventaris</label></th>
            <td>
                <input type="text" id="inv_kode" name="inv_kode" value="<?php echo esc_attr( $kode ); ?>" class="regular-text" placeholder="Contoh: INV-ELK-001">
            </td>
        </tr>
        <tr>
            <th><label for="inv_kategori">Kategori</label></th>
            <td>
                <select id="inv_kategori" name="inv_kategori" style="min-width:280px;">
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach ( qrg_inventaris_kategori_options() as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $kategori, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="inv_jumlah">Jumlah</label></th>
            <td>
                <input type="number" id="inv_jumlah" name="inv_jumlah" value="<?php echo esc_attr( $jumlah ); ?>" min="0" class="small-text" required>
                <input type="text" id="inv_satuan" name="inv_satuan" value="<?php echo esc_attr( $satuan ); ?>" class="regular-text" style="max-width:120px;" placeholder="unit / buah / set">
                <p class="description">Jumlah stok beserta satuannya.</p>
            </td>
        </tr>
        <tr>
            <th><label for="inv_kondisi">Kondisi</label></th>
            <td>
                <select id="inv_kondisi" name="inv_kondisi" style="min-width:280px;">
                    <?php foreach ( qrg_inventaris_kondisi_options() as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $kondisi, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="inv_lokasi_ruangan_id">Lokasi / Ruangan</label></th>
            <td>
                <select id="inv_lokasi_ruangan_id" name="inv_lokasi_ruangan_id" style="min-width:320px;">
                    <option value="">-- Pilih dari Data Ruangan --</option>
                    <?php foreach ( $daftar_ruangan as $ruangan ) : ?>
                        <option value="<?php echo esc_attr( $ruangan->ID ); ?>" <?php selected( $lokasi_ruangan_id, $ruangan->ID ); ?>>
                            <?php echo esc_html( qrg_get_ruangan_label( $ruangan->ID ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    Terintegrasi dengan <strong>Sekolah → Data Ruangan</strong>.
                    <?php if ( empty( $daftar_ruangan ) ) : ?>
                        <span style="color:#b32d2e;">Belum ada data ruangan. <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=master_ruangan' ) ); ?>">Tambah Ruangan</a> terlebih dahulu.</span>
                    <?php else : ?>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=master_ruangan' ) ); ?>">Kelola Data Ruangan</a>
                    <?php endif; ?>
                </p>
                <?php if ( ! $lokasi_ruangan_id && ( $lokasi_kelas_id || $lokasi ) ) : ?>
                    <p style="color:#856404; background:#fff3cd; padding:8px 10px; border-radius:4px;">
                        Data lokasi lama:
                        <strong><?php echo esc_html( $lokasi_kelas_id ? qrg_get_kelas_label( $lokasi_kelas_id ) : $lokasi ); ?></strong>
                        — silakan pilih ulang dari Data Ruangan.
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="inv_tgl_perolehan">Tanggal Perolehan</label></th>
            <td>
                <input type="date" id="inv_tgl_perolehan" name="inv_tgl_perolehan" value="<?php echo esc_attr( $tgl_beli ); ?>">
            </td>
        </tr>
        <tr>
            <th><label for="inv_harga">Harga Perolehan (Rp)</label></th>
            <td>
                <input type="number" id="inv_harga" name="inv_harga" value="<?php echo esc_attr( $harga ); ?>" class="regular-text" min="0" step="1" placeholder="Contoh: 7500000">
                <p class="description">Harga satuan per unit. Total harga = harga × jumlah.</p>
            </td>
        </tr>
        <tr>
            <th><label for="inv_penyusutan_persen">Penyusutan (% / tahun)</label></th>
            <td>
                <input type="number" id="inv_penyusutan_persen" name="inv_penyusutan_persen" value="<?php echo esc_attr( $penyusutan !== '' ? $penyusutan : '0' ); ?>" class="small-text" min="0" max="100" step="0.1" placeholder="10">
                <span>% per tahun</span>
                <p class="description">
                    Metode garis lurus: <strong>Nilai Aset = Total Harga × (1 − % × umur tahun)</strong>.
                    Umur dihitung otomatis dari <strong>Tanggal Perolehan</strong>.
                </p>
                <?php
                $jumlah_calc = ( $jumlah !== '' && (int) $jumlah > 0 ) ? (int) $jumlah : 1;
                $harga_calc  = (int) $harga;
                $total_calc  = $harga_calc * $jumlah_calc;
                $umur_calc   = qrg_get_inventaris_umur_tahun( $post->ID );
                $persen_calc = $penyusutan !== '' ? (float) $penyusutan : 0;
                $nilai_calc  = $total_calc;
                if ( $total_calc > 0 && $persen_calc > 0 && $umur_calc > 0 ) {
                    $faktor = 1 - ( ( $persen_calc / 100 ) * $umur_calc );
                    if ( $faktor < 0 ) {
                        $faktor = 0;
                    }
                    $nilai_calc = (int) round( $total_calc * $faktor );
                }
                if ( $harga_calc > 0 ) :
                    ?>
                    <div style="margin-top:12px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                        <p style="margin:0 0 6px;"><strong>Total Harga Perolehan:</strong> Rp <?php echo esc_html( number_format( $total_calc, 0, ',', '.' ) ); ?>
                            <span style="color:#64748b;">(<?php echo esc_html( number_format( $harga_calc, 0, ',', '.' ) ); ?> × <?php echo (int) $jumlah_calc; ?>)</span>
                        </p>
                        <p style="margin:0 0 6px;"><strong>Umur aset:</strong> <?php echo (int) $umur_calc; ?> tahun
                            <?php if ( ! $tgl_beli ) : ?>
                                <span style="color:#b45309;">— isi Tanggal Perolehan agar umur terhitung</span>
                            <?php endif; ?>
                        </p>
                        <p style="margin:0; font-size:15px;">
                            <strong>Nilai Aset saat ini:</strong>
                            <span style="color:#0f766e; font-size:18px; font-weight:700;">Rp <?php echo esc_html( number_format( $nilai_calc, 0, ',', '.' ) ); ?></span>
                            <?php if ( $total_calc > $nilai_calc ) : ?>
                                <span style="color:#64748b;">
                                    (penyusutan Rp <?php echo esc_html( number_format( $total_calc - $nilai_calc, 0, ',', '.' ) ); ?>)
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="inv_sumber">Sumber Dana / Perolehan</label></th>
            <td>
                <input type="text" id="inv_sumber" name="inv_sumber" value="<?php echo esc_attr( $sumber ); ?>" class="regular-text" placeholder="Contoh: BOS, Hibah, Swadaya">
            </td>
        </tr>
        <tr>
            <th><label for="inv_pic">Penanggung Jawab</label></th>
            <td>
                <select id="inv_pic" name="inv_pic" style="min-width:250px;">
                    <option value="">-- Pilih Guru --</option>
                    <?php 
                    if ( function_exists( 'qrg_get_daftar_guru' ) ) {
                        foreach ( qrg_get_daftar_guru() as $guru ) {
                            echo '<option value="' . esc_attr( $guru->post_title ) . '" ' . selected( $pic, $guru->post_title, false ) . '>' . esc_html( $guru->post_title ) . '</option>';
                        }
                    }
                    ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="inv_keterangan">Keterangan</label></th>
            <td>
                <textarea id="inv_keterangan" name="inv_keterangan" rows="4" class="large-text" placeholder="Catatan tambahan kondisi, spesifikasi, atau riwayat perbaikan"><?php echo esc_textarea( $keterangan ); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

// 3. Simpan meta inventaris
add_action( 'save_post_master_inventaris', 'qrg_save_inventaris_details' );
function qrg_save_inventaris_details( $post_id ) {
    if ( ! isset( $_POST['qrg_inventaris_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_inventaris_nonce'], 'qrg_save_inventaris_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    $fields = [
        'inv_kode'          => '_inv_kode',
        'inv_kategori'      => '_inv_kategori',
        'inv_jumlah'        => '_inv_jumlah',
        'inv_satuan'        => '_inv_satuan',
        'inv_kondisi'       => '_inv_kondisi',
        'inv_tgl_perolehan' => '_inv_tgl_perolehan',
        'inv_harga'         => '_inv_harga',
        'inv_sumber'        => '_inv_sumber',
        'inv_pic'           => '_inv_pic',
    ];

    foreach ( $fields as $post_key => $meta_key ) {
        if ( isset( $_POST[ $post_key ] ) ) {
            update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
        }
    }

    // Auto-generate kode inventaris jika kosong (untuk QR Code)
    $kode_saved = isset( $_POST['inv_kode'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_kode'] ) ) : get_post_meta( $post_id, '_inv_kode', true );
    if ( trim( (string) $kode_saved ) === '' ) {
        $auto_kode = 'INV-' . str_pad( (string) (int) $post_id, 5, '0', STR_PAD_LEFT );
        update_post_meta( $post_id, '_inv_kode', $auto_kode );
    }

    if ( isset( $_POST['inv_penyusutan_persen'] ) ) {
        $persen = (float) wp_unslash( $_POST['inv_penyusutan_persen'] );
        if ( $persen < 0 ) {
            $persen = 0;
        }
        if ( $persen > 100 ) {
            $persen = 100;
        }
        update_post_meta( $post_id, '_inv_penyusutan_persen', (string) $persen );
    }

    if ( isset( $_POST['inv_keterangan'] ) ) {
        update_post_meta( $post_id, '_inv_keterangan', sanitize_textarea_field( wp_unslash( $_POST['inv_keterangan'] ) ) );
    }

    // Handle Borrowing History
    if ( ! empty( $_POST['inv_action_type'] ) ) {
        $action = sanitize_text_field( wp_unslash( $_POST['inv_action_type'] ) );
        $pic    = sanitize_text_field( wp_unslash( $_POST['inv_action_pic'] ) );
        $date   = sanitize_text_field( wp_unslash( $_POST['inv_action_date'] ) );
        $notes  = sanitize_textarea_field( wp_unslash( $_POST['inv_action_notes'] ) );
        
        $history = get_post_meta( $post_id, '_inv_history', true );
        if ( ! is_array( $history ) ) $history = [];
        
        $history[] = [
            'date'   => $date . ' ' . wp_date('H:i:s'),
            'action' => $action,
            'pic'    => $pic,
            'notes'  => $notes,
        ];
        update_post_meta( $post_id, '_inv_history', $history );
        
        if ( $action === 'Pinjam' ) {
            update_post_meta( $post_id, '_inv_status', 'Dipinjam' );
        } elseif ( $action === 'Kembalikan' ) {
            update_post_meta( $post_id, '_inv_status', 'Tersedia' );
        }
    }

    // Lokasi: dari Data Ruangan
    $ruangan_id = isset( $_POST['inv_lokasi_ruangan_id'] ) ? (int) $_POST['inv_lokasi_ruangan_id'] : 0;

    // Auto-log location change
    $old_ruangan_id = (int) get_post_meta( $post_id, '_inv_lokasi_ruangan_id', true );
    if ( $ruangan_id && $ruangan_id !== $old_ruangan_id ) {
        $old_label = $old_ruangan_id ? qrg_get_ruangan_label( $old_ruangan_id ) : get_post_meta( $post_id, '_inv_lokasi', true );
        $new_label = qrg_get_ruangan_label( $ruangan_id );
        
        // Only log if the old label is different (avoids initial save logging if it was just created)
        if ( $old_label && $old_label !== $new_label ) {
            $history = get_post_meta( $post_id, '_inv_history', true );
            if ( ! is_array( $history ) ) $history = [];
            
            $history[] = [
                'date'   => wp_date('Y-m-d H:i:s'),
                'action' => 'Dipindah',
                'pic'    => 'Dari ' . $old_label . ' ke ' . $new_label,
                'notes'  => 'Dipindah secara sistem',
            ];
            update_post_meta( $post_id, '_inv_history', $history );
        }
    }
    if ( $ruangan_id && get_post_type( $ruangan_id ) === 'master_ruangan' ) {
        update_post_meta( $post_id, '_inv_lokasi_ruangan_id', $ruangan_id );
        update_post_meta( $post_id, '_inv_lokasi', qrg_get_ruangan_label( $ruangan_id ) );
        // Bersihkan referensi lokasi lama
        delete_post_meta( $post_id, '_inv_lokasi_kelas_id' );
    } else {
        delete_post_meta( $post_id, '_inv_lokasi_ruangan_id' );
        // Pertahankan label lama jika belum memilih ruangan baru
        if ( ! get_post_meta( $post_id, '_inv_lokasi_kelas_id', true ) ) {
            // biarkan _inv_lokasi jika ada data lama
        }
    }
}

// 4. Kolom daftar admin (list table WordPress)
add_filter( 'manage_master_inventaris_posts_columns', 'qrg_set_inventaris_columns' );
function qrg_set_inventaris_columns( $columns ) {
    return [
        'cb'         => $columns['cb'],
        'foto'       => 'Foto',
        'qrcode'     => 'QR Code',
        'title'      => 'Nama Barang',
        'kode'       => 'Kode',
        'status'     => 'Status',
        'kategori'   => 'Kategori',
        'jumlah'     => 'Jumlah',
        'kondisi'    => 'Kondisi',
        'lokasi'     => 'Lokasi',
        'harga'      => 'Harga Satuan',
        'total'      => 'Total Harga',
        'penyusutan' => 'Penyusutan',
        'nilai'      => 'Nilai Aset',
        'date'       => $columns['date'],
    ];
}

add_action( 'manage_master_inventaris_posts_custom_column', 'qrg_fill_inventaris_columns', 10, 2 );
function qrg_fill_inventaris_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'foto':
            if ( has_post_thumbnail( $post_id ) ) {
                echo get_the_post_thumbnail( $post_id, [ 48, 48 ], [ 'style' => 'border-radius:4px;' ] );
            } else {
                echo '<span style="color:#aaa;font-size:12px;">No Photo</span>';
            }
            break;
        case 'qrcode':
            $kode  = qrg_get_inventaris_kode_unik( $post_id );
            $thumb = qrg_get_inventaris_qr_image_url( $post_id, 72 );
            $lihat = qrg_get_inventaris_qr_lihat_url( $post_id );
            $unduh = qrg_get_inventaris_qr_unduh_url( $post_id );
            echo '<a href="' . esc_url( $lihat ) . '" target="_blank" title="Lihat QR Code">';
            echo '<img src="' . esc_url( $thumb ) . '" alt="QR ' . esc_attr( $kode ) . '" style="width:56px;height:56px;display:block;background:#fff;border:1px solid #e2e8f0;border-radius:4px;">';
            echo '</a>';
            echo '<div style="margin-top:4px;font-size:11px;">';
            echo '<a href="' . esc_url( $lihat ) . '" target="_blank">Lihat</a> · ';
            echo '<a href="' . esc_url( $unduh ) . '">Unduh</a>';
            echo '</div>';
            break;
        case 'kode':
            $kode = get_post_meta( $post_id, '_inv_kode', true );
            echo esc_html( $kode ?: '-' );
            break;
        case 'status':
            $status = get_post_meta( $post_id, '_inv_status', true );
            if ( ! $status ) $status = 'Tersedia';
            if ( $status === 'Tersedia' ) {
                echo '<span style="color:#16a34a;font-weight:600;"><span class="dashicons dashicons-yes-alt" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span> Tersedia</span>';
            } else {
                echo '<span style="color:#dc2626;font-weight:600;"><span class="dashicons dashicons-warning" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span> Dipinjam</span>';
            }
            break;
        case 'kategori':
            echo esc_html( get_post_meta( $post_id, '_inv_kategori', true ) ?: '-' );
            break;
        case 'jumlah':
            $jumlah = get_post_meta( $post_id, '_inv_jumlah', true );
            $satuan = get_post_meta( $post_id, '_inv_satuan', true );
            echo '<strong>' . esc_html( $jumlah !== '' ? $jumlah : '0' ) . '</strong> ' . esc_html( $satuan ?: '' );
            break;
        case 'kondisi':
            $kondisi = get_post_meta( $post_id, '_inv_kondisi', true );
            $colors  = [
                'Baik'            => '#16a34a',
                'Rusak Ringan'    => '#ca8a04',
                'Rusak Berat'     => '#dc2626',
                'Dalam Perbaikan' => '#2563eb',
                'Hilang'          => '#64748b',
            ];
            $color = isset( $colors[ $kondisi ] ) ? $colors[ $kondisi ] : '#64748b';
            if ( $kondisi ) {
                echo '<span style="background:' . esc_attr( $color ) . ';color:#fff;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:bold;">' . esc_html( $kondisi ) . '</span>';
            } else {
                echo '-';
            }
            break;
        case 'lokasi':
            $label = qrg_get_inventaris_lokasi_label( $post_id );
            $ruangan_id = (int) get_post_meta( $post_id, '_inv_lokasi_ruangan_id', true );
            $kelas_id   = (int) get_post_meta( $post_id, '_inv_lokasi_kelas_id', true );
            if ( $label && $ruangan_id ) {
                echo '<a href="' . esc_url( get_edit_post_link( $ruangan_id ) ) . '">' . esc_html( $label ) . '</a>';
            } elseif ( $label && $kelas_id ) {
                echo '<a href="' . esc_url( get_edit_post_link( $kelas_id ) ) . '">' . esc_html( $label ) . '</a>';
            } else {
                echo esc_html( $label ?: '-' );
            }
            break;
        case 'harga':
            $harga = get_post_meta( $post_id, '_inv_harga', true );
            echo $harga !== '' ? 'Rp ' . esc_html( number_format( (int) $harga, 0, ',', '.' ) ) : '-';
            break;
        case 'total':
            $total = qrg_get_inventaris_total_harga( $post_id );
            echo $total > 0
                ? '<strong style="color:#0f766e;">Rp ' . esc_html( number_format( $total, 0, ',', '.' ) ) . '</strong>'
                : '-';
            break;
        case 'penyusutan':
            $persen = qrg_get_inventaris_penyusutan_persen( $post_id );
            $umur   = qrg_get_inventaris_umur_tahun( $post_id );
            if ( $persen > 0 ) {
                echo esc_html( rtrim( rtrim( number_format( $persen, 1, ',', '.' ), '0' ), ',' ) ) . '%/thn';
                echo '<div style="font-size:11px;color:#64748b;">Umur: ' . (int) $umur . ' thn</div>';
            } else {
                echo '<span style="color:#aaa;">0%</span>';
            }
            break;
        case 'nilai':
            $nilai = qrg_get_inventaris_nilai_aset( $post_id );
            $total = qrg_get_inventaris_total_harga( $post_id );
            if ( $total > 0 ) {
                echo '<strong style="color:#7c3aed;">Rp ' . esc_html( number_format( $nilai, 0, ',', '.' ) ) . '</strong>';
            } else {
                echo '-';
            }
            break;
    }
}

// Banner total harga perolehan di daftar Inventaris
add_action( 'admin_notices', 'qrg_inventaris_total_harga_notice' );
function qrg_inventaris_total_harga_notice() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_inventaris' ) {
        return;
    }

    $grand_total = qrg_get_inventaris_grand_total_harga();
    $nilai_aset  = qrg_get_inventaris_grand_total_nilai_aset();
    $count       = wp_count_posts( 'master_inventaris' );
    $publish     = isset( $count->publish ) ? (int) $count->publish : 0;
    $selisih     = max( 0, $grand_total - $nilai_aset );
    ?>
    <div class="notice notice-info" style="padding:12px 16px; border-left-color:#0f766e;">
        <p style="margin:0 0 6px; font-size:14px;">
            <strong>Total Harga Perolehan:</strong>
            <span style="font-size:17px; font-weight:700; color:#0f766e; margin-left:4px;">
                Rp <?php echo esc_html( number_format( $grand_total, 0, ',', '.' ) ); ?>
            </span>
            <span style="color:#64748b; margin-left:8px;">dari <?php echo (int) $publish; ?> item</span>
        </p>
        <p style="margin:0; font-size:14px;">
            <strong>Total Nilai Aset (setelah penyusutan):</strong>
            <span style="font-size:17px; font-weight:700; color:#7c3aed; margin-left:4px;">
                Rp <?php echo esc_html( number_format( $nilai_aset, 0, ',', '.' ) ); ?>
            </span>
            <?php if ( $selisih > 0 ) : ?>
                <span style="color:#64748b; margin-left:8px;">akumulasi penyusutan Rp <?php echo esc_html( number_format( $selisih, 0, ',', '.' ) ); ?></span>
            <?php endif; ?>
        </p>
    </div>
    <?php
}

// 5. Filter cepat berdasarkan kategori & kondisi
add_action( 'restrict_manage_posts', 'qrg_inventaris_admin_filters' );
function qrg_inventaris_admin_filters( $post_type ) {
    if ( $post_type !== 'master_inventaris' ) {
        return;
    }

    $selected_kat = isset( $_GET['inv_filter_kategori'] ) ? sanitize_text_field( wp_unslash( $_GET['inv_filter_kategori'] ) ) : '';
    $selected_kon = isset( $_GET['inv_filter_kondisi'] ) ? sanitize_text_field( wp_unslash( $_GET['inv_filter_kondisi'] ) ) : '';

    echo '<select name="inv_filter_kategori"><option value="">Semua Kategori</option>';
    foreach ( qrg_inventaris_kategori_options() as $value => $label ) {
        printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $selected_kat, $value, false ), esc_html( $label ) );
    }
    echo '</select>';

    echo '<select name="inv_filter_kondisi"><option value="">Semua Kondisi</option>';
    foreach ( qrg_inventaris_kondisi_options() as $value => $label ) {
        printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $selected_kon, $value, false ), esc_html( $label ) );
    }
    echo '</select>';
}

add_action( 'pre_get_posts', 'qrg_inventaris_filter_query' );
function qrg_inventaris_filter_query( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( $query->get( 'post_type' ) !== 'master_inventaris' ) {
        return;
    }

    $meta_query = [];

    if ( ! empty( $_GET['inv_filter_kategori'] ) ) {
        $meta_query[] = [
            'key'   => '_inv_kategori',
            'value' => sanitize_text_field( wp_unslash( $_GET['inv_filter_kategori'] ) ),
        ];
    }
    if ( ! empty( $_GET['inv_filter_kondisi'] ) ) {
        $meta_query[] = [
            'key'   => '_inv_kondisi',
            'value' => sanitize_text_field( wp_unslash( $_GET['inv_filter_kondisi'] ) ),
        ];
    }

    if ( ! empty( $meta_query ) ) {
        $query->set( 'meta_query', $meta_query );
    }
}

// 6. Aksi baris: Lihat & Unduh QR Code
add_filter( 'post_row_actions', 'qrg_inventaris_row_actions', 10, 2 );
function qrg_inventaris_row_actions( $actions, $post ) {
    if ( $post->post_type !== 'master_inventaris' ) {
        return $actions;
    }
    $actions['lihat_qr'] = '<a href="' . esc_url( qrg_get_inventaris_qr_lihat_url( $post->ID ) ) . '" target="_blank">Lihat QR Code</a>';
    $actions['unduh_qr'] = '<a href="' . esc_url( qrg_get_inventaris_qr_unduh_url( $post->ID ) ) . '">Unduh QR Code</a>';
    return $actions;
}

// 7. Handler Lihat & Unduh QR Code (via admin-post.php, tanpa registrasi menu)
add_action( 'admin_post_qrg_lihat_qrcode', 'qrg_inventaris_lihat_qrcode_handler' );
add_action( 'admin_post_qrg_unduh_qrcode', 'qrg_inventaris_unduh_qrcode_handler' );

function qrg_inventaris_qrcode_validate_request() {
    if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Akses ditolak.' );
    }

    $post_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
    if ( ! $post_id || get_post_type( $post_id ) !== 'master_inventaris' ) {
        wp_die( 'Data inventaris tidak ditemukan.' );
    }
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qrg_qrcode_' . $post_id ) ) {
        wp_die( 'Token tidak valid. Silakan buka ulang dari halaman Inventaris.' );
    }

    return $post_id;
}

function qrg_inventaris_unduh_qrcode_handler() {
    $post_id  = qrg_inventaris_qrcode_validate_request();
    $kode     = qrg_get_inventaris_kode_unik( $post_id );
    $qr_url   = qrg_get_inventaris_qr_image_url( $post_id, 500 );
    $filename = sanitize_file_name( 'qrcode-' . $kode . '.png' );

    $response = wp_remote_get( $qr_url, [ 'timeout' => 20 ] );
    if ( is_wp_error( $response ) ) {
        wp_die( 'Gagal mengunduh QR Code. Coba lagi.' );
    }
    $body = wp_remote_retrieve_body( $response );
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 || empty( $body ) ) {
        wp_die( 'QR Code tidak tersedia. Coba lagi.' );
    }

    nocache_headers();
    header( 'Content-Type: image/png' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $body ) );
    echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}

function qrg_inventaris_lihat_qrcode_handler() {
    $post_id   = qrg_inventaris_qrcode_validate_request();
    $nama      = get_the_title( $post_id );
    $kode      = qrg_get_inventaris_kode_unik( $post_id );
    $qr_url    = qrg_get_inventaris_qr_image_url( $post_id, 400 );
    $info      = qrg_get_inventaris_qr_info_text( $post_id );
    $unduh_url = qrg_get_inventaris_qr_unduh_url( $post_id );
    $edit_url  = get_edit_post_link( $post_id, 'raw' );
    $kategori  = get_post_meta( $post_id, '_inv_kategori', true );
    $jumlah    = get_post_meta( $post_id, '_inv_jumlah', true );
    $satuan    = get_post_meta( $post_id, '_inv_satuan', true );
    $kondisi   = get_post_meta( $post_id, '_inv_kondisi', true );
    $lokasi    = qrg_get_inventaris_lokasi_label( $post_id );
    $nilai     = qrg_get_inventaris_nilai_aset( $post_id );

    nocache_headers();
    header( 'Content-Type: text/html; charset=utf-8' );
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>QR Code - <?php echo esc_html( $nama ); ?></title>
        <style>
            body { margin:0; padding:28px; font-family:"Segoe UI",Arial,sans-serif; background:#f1f5f9; color:#0f172a; }
            .box { max-width:520px; margin:0 auto; background:#fff; border-radius:14px; padding:24px; box-shadow:0 8px 24px rgba(15,23,42,.08); text-align:center; }
            h1 { margin:0 0 6px; font-size:20px; }
            .kode { font-family:monospace; font-weight:700; color:#0f766e; margin-bottom:16px; }
            img.qr { width:280px; height:280px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; }
            .meta { text-align:left; margin:18px 0; font-size:13px; line-height:1.6; color:#334155; white-space:pre-wrap; background:#f8fafc; border-radius:8px; padding:12px 14px; border:1px solid #e2e8f0; }
            .actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
            .actions a { display:inline-block; padding:10px 16px; border-radius:8px; text-decoration:none; font-weight:600; color:#fff; background:#0f766e; }
            .actions a.secondary { background:#64748b; }
            .chips { display:flex; flex-wrap:wrap; gap:6px; justify-content:center; margin:12px 0 4px; }
            .chips span { background:#ecfeff; color:#0e7490; border-radius:999px; padding:4px 10px; font-size:12px; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1><?php echo esc_html( $nama ); ?></h1>
            <div class="kode"><?php echo esc_html( $kode ); ?></div>
            
            <?php if ( has_post_thumbnail( $post_id ) ) : ?>
                <?php $foto_url = get_the_post_thumbnail_url( $post_id, 'medium' ); ?>
                <img src="<?php echo esc_url( $foto_url ); ?>" alt="Foto <?php echo esc_attr( $nama ); ?>" style="max-width:100%; height:auto; max-height:220px; border-radius:10px; margin-bottom:16px; border:1px solid #e2e8f0; display:block; margin-left:auto; margin-right:auto; object-fit:cover;">
            <?php endif; ?>

            <div class="chips">
                <?php if ( $kategori ) : ?><span><?php echo esc_html( $kategori ); ?></span><?php endif; ?>
                <?php if ( $kondisi ) : ?><span><?php echo esc_html( $kondisi ); ?></span><?php endif; ?>
                <span><?php echo esc_html( ( $jumlah !== '' ? $jumlah : '0' ) . ( $satuan ? ' ' . $satuan : '' ) ); ?></span>
                <?php if ( $lokasi ) : ?><span><?php echo esc_html( $lokasi ); ?></span><?php endif; ?>
                <?php if ( $nilai > 0 ) : ?><span>Rp <?php echo esc_html( number_format( $nilai, 0, ',', '.' ) ); ?></span><?php endif; ?>
            </div>
            <div class="meta"><?php echo esc_html( $info ); ?></div>
            <?php 
            $history = get_post_meta( $post_id, '_inv_history', true );
            if ( is_array( $history ) && ! empty( $history ) ) :
                $history_reversed = array_reverse( $history );
            ?>
            <div style="text-align:left; margin:18px 0; font-size:12px; line-height:1.5;">
                <h3 style="margin:0 0 8px; font-size:14px; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">Riwayat Pergerakan Barang</h3>
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="padding:6px 0; text-align:left; color:#64748b;">Tanggal</th>
                            <th style="padding:6px 0; text-align:left; color:#64748b;">Aksi</th>
                            <th style="padding:6px 0; text-align:left; color:#64748b;">Peminjam / PIC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $history_reversed as $item ) : ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:6px 0; color:#334155;"><?php echo esc_html( wp_date( 'd/m/y', strtotime( $item['date'] ) ) ); ?></td>
                                <td style="padding:6px 0;">
                                    <?php 
                                    $act_color = '#64748b';
                                    if ( $item['action'] === 'Pinjam' ) $act_color = '#dc2626';
                                    if ( $item['action'] === 'Kembalikan' ) $act_color = '#16a34a';
                                    if ( $item['action'] === 'Dipindah' ) $act_color = '#2563eb';
                                    ?>
                                    <span style="color: <?php echo esc_attr( $act_color ); ?>; font-weight: 600;"><?php echo esc_html( $item['action'] ); ?></span>
                                </td>
                                <td style="padding:6px 0; color:#334155;"><?php echo esc_html( $item['pic'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}
