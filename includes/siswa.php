<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Registrasi CPT Siswa dengan Dukungan Foto (Thumbnail)
add_action( 'init', 'qrg_register_siswa_cpt' );
function qrg_register_siswa_cpt() {
    register_post_type( 'master_siswa', [
        'labels' => [
            'name'          => 'Data Siswa',
            'singular_name' => 'Siswa',
            'menu_name'     => 'Data Siswa',
            'add_new_item'  => 'Tambah Siswa Baru',
            'edit_item'     => 'Edit Data Siswa',
            'all_items'     => 'Data Siswa',
        ],
        'public'              => false,
        'publicly_queryable'  => true, // Dibutuhkan rewrite /pembayaran/ & /pembelajaran/
        'exclude_from_search' => true,
        'show_ui'             => true,
        'show_in_menu'        => 'master-data-plugin',
        'supports'            => [ 'title', 'thumbnail' ], // 'title' untuk NAMA SISWA, 'thumbnail' untuk FOTO SISWA
        'has_archive'         => false,
    ] );
}

// 2. Tambah Meta Box Informasi Lengkap Siswa
add_action( 'add_meta_boxes', 'qrg_siswa_meta_boxes' );
function qrg_siswa_meta_boxes() {
    add_meta_box( 'siswa_detail', 'Informasi Lengkap Detail Siswa', 'qrg_siswa_fields_callback', 'master_siswa', 'normal', 'high' );
    add_meta_box( 'siswa_tagihan_ringkas', 'Ringkasan Tagihan Siswa', 'qrg_siswa_tagihan_metabox_callback', 'master_siswa', 'side', 'high' );
}

function qrg_siswa_tagihan_metabox_callback( $post ) {
    if ( ! function_exists( 'qrg_get_siswa_tagihan_summary' ) ) {
        echo '<p class="description">Modul tagihan belum tersedia.</p>';
        return;
    }

    $summary = qrg_get_siswa_tagihan_summary( $post->ID );
    $list_url = admin_url( 'edit.php?post_type=master_tagihan&siswa_id=' . (int) $post->ID );
    ?>
    <p style="margin:0 0 10px;">
        <strong>Total Tagihan:</strong><br>
        <span style="font-size:18px;">Rp <?php echo esc_html( number_format( (int) $summary['total'], 0, ',', '.' ) ); ?></span>
    </p>
    <ul style="margin:0 0 12px; padding-left:18px;">
        <li>Jumlah invoice: <strong><?php echo (int) $summary['count']; ?></strong></li>
        <li style="color:#16a34a;">Lunas: <strong>Rp <?php echo esc_html( number_format( (int) $summary['lunas'], 0, ',', '.' ) ); ?></strong></li>
        <li style="color:#ea580c;">Belum dibayar: <strong>Rp <?php echo esc_html( number_format( (int) $summary['belum'], 0, ',', '.' ) ); ?></strong></li>
        <li style="color:#2563eb;">Ditinjau: <strong>Rp <?php echo esc_html( number_format( (int) $summary['ditinjau'], 0, ',', '.' ) ); ?></strong></li>
    </ul>
    <?php if ( ! empty( $summary['items'] ) ) : ?>
        <p style="margin:0 0 6px;"><strong>Daftar Tagihan</strong></p>
        <ul style="margin:0 0 12px; max-height:180px; overflow:auto; padding-left:0; list-style:none;">
            <?php foreach ( $summary['items'] as $item ) : ?>
                <li style="padding:6px 0; border-bottom:1px solid #f0f0f1;">
                    <a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>">
                        <?php echo esc_html( $item['title'] ? $item['title'] : 'Tagihan #' . $item['id'] ); ?>
                    </a>
                    <div style="font-size:12px; color:#50575e;">
                        Rp <?php echo esc_html( number_format( (int) $item['nominal'], 0, ',', '.' ) ); ?>
                        · <?php echo esc_html( $item['status'] ); ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="description">Belum ada tagihan untuk siswa ini.</p>
    <?php endif; ?>
    <p style="margin:0;">
        <a class="button button-secondary" href="<?php echo esc_url( $list_url ); ?>">Lihat Semua Tagihan</a>
        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=master_tagihan&siswa_id=' . (int) $post->ID ) ); ?>">Buat Tagihan</a>
    </p>
    <?php
}

// 3. Form Input Data Siswa di Dashboard Admin
function qrg_siswa_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_siswa_meta', 'qrg_siswa_nonce' );
    
    // Ambil data dari database
    $nisn         = get_post_meta( $post->ID, '_siswa_nisn', true );
    $nis          = get_post_meta( $post->ID, '_siswa_nis', true );
    $kelas        = get_post_meta( $post->ID, '_siswa_kelas', true ); // Data Kelas Siswa
    $asal_sekolah = get_post_meta( $post->ID, '_siswa_asal_sekolah', true );
    $alamat       = get_post_meta( $post->ID, '_siswa_alamat', true );
    $tempat_lahir = get_post_meta( $post->ID, '_siswa_tempat_lahir', true );
    $tgl_lahir    = get_post_meta( $post->ID, '_siswa_tgl_lahir', true );
    $nama_ortu    = get_post_meta( $post->ID, '_siswa_nama_ortu', true );
    $pend_ortu    = get_post_meta( $post->ID, '_siswa_pend_ortu', true );
    $no_telp      = get_post_meta( $post->ID, '_siswa_no_telp', true );
    $email        = get_post_meta( $post->ID, '_siswa_email', true );
    
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Kolom <strong>NAMA</strong> diambil dari kolom judul paling atas. Unggah <strong>FOTO</strong> melalui opsi panel "Featured Image" di sisi kanan bawah halaman.
    </p>
    
    <table class="form-table">
        <tr>
            <th><label for="siswa_nis">NIS</label></th>
            <td><input type="text" id="siswa_nis" name="siswa_nis" value="<?php echo esc_attr($nis); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="siswa_nisn">NISN</label></th>
            <td><input type="text" id="siswa_nisn" name="siswa_nisn" value="<?php echo esc_attr($nisn); ?>" class="regular-text" required></td>
        </tr>
        <tr>
            <th><label for="siswa_kelas">Kelas</label></th>
            <td>
                <select id="siswa_kelas" name="siswa_kelas" required style="min-width: 320px;">
                    <option value="">-- Pilih Kelas --</option>
                    <?php
                    $daftar_kelas = qrg_get_daftar_kelas();
                    if ( ! empty( $daftar_kelas ) ) {
                        foreach ( $daftar_kelas as $kelas_post ) {
                            echo '<option value="' . esc_attr( $kelas_post->ID ) . '" ' . selected( (string) $kelas, (string) $kelas_post->ID, false ) . '>' . esc_html( qrg_get_kelas_label( $kelas_post->ID ) ) . '</option>';
                        }
                    }
                    // Pertahankan nilai lama (teks bebas) agar tidak hilang sebelum dimigrasi
                    if ( $kelas && ! is_numeric( $kelas ) ) {
                        echo '<option value="' . esc_attr( $kelas ) . '" selected>' . esc_html( $kelas ) . ' (data lama)</option>';
                    }
                    ?>
                </select>
                <p class="description">Daftar kelas dikelola di menu <strong>Sekolah → Data Kelas</strong> (contoh: Kelas 1 — Abu Bakar). <?php if ( empty( $daftar_kelas ) ) : ?><span style="color:#b32d2e;">Belum ada data kelas. Tambahkan dulu di Data Kelas.</span><?php endif; ?></p>
                <?php
                if ( is_numeric( $kelas ) && (int) $kelas > 0 ) {
                    $wali_nama = qrg_get_kelas_wali_nama( (int) $kelas );
                    if ( $wali_nama ) {
                        $wali_id = qrg_get_kelas_wali_id( (int) $kelas );
                        echo '<p style="margin:8px 0 0;"><strong>Wali Kelas:</strong> ';
                        if ( $wali_id ) {
                            echo '<a href="' . esc_url( get_edit_post_link( $wali_id ) ) . '">' . esc_html( $wali_nama ) . '</a>';
                        } else {
                            echo esc_html( $wali_nama );
                        }
                        echo '</p>';
                    }
                }
                ?>
            </td>
        </tr>
        <tr>
            <th><label for="siswa_asal_sekolah">Asal Sekolah (Lulusan)</label></th>
            <td><input type="text" id="siswa_asal_sekolah" name="siswa_asal_sekolah" value="<?php echo esc_attr($asal_sekolah); ?>" placeholder="Contoh: SMP Negeri 1 Sidoarjo" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="siswa_alamat">Alamat Tinggal</label></th>
            <td><textarea id="siswa_alamat" name="siswa_alamat" rows="3" class="large-text"><?php echo esc_textarea($alamat); ?></textarea></td>
        </tr>
        <tr>
            <th><label for="siswa_tempat_lahir">Tempat Lahir</label></th>
            <td><input type="text" id="siswa_tempat_lahir" name="siswa_tempat_lahir" value="<?php echo esc_attr($tempat_lahir); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="siswa_tgl_lahir">Tanggal Lahir</label></th>
            <td><input type="date" id="siswa_tgl_lahir" name="siswa_tgl_lahir" value="<?php echo esc_attr($tgl_lahir); ?>"></td>
        </tr>
        <tr style="border-top: 1px solid #eee;">
            <th><label for="siswa_nama_ortu">Nama Orang Tua / Wali</label></th>
            <td><input type="text" id="siswa_nama_ortu" name="siswa_nama_ortu" value="<?php echo esc_attr($nama_ortu); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="siswa_pend_ortu">Pendidikan Terakhir Orang Tua</label></th>
            <td>
                <select id="siswa_pend_ortu" name="siswa_pend_ortu">
                    <option value="">-- Pilih Pendidikan --</option>
                    <option value="SD" <?php selected($pend_ortu, 'SD'); ?>>SD</option>
                    <option value="SMP" <?php selected($pend_ortu, 'SMP'); ?>>SMP</option>
                    <option value="SMA / SMK" <?php selected($pend_ortu, 'SMA / SMK'); ?>>SMA / SMK</option>
                    <option value="D3" <?php selected($pend_ortu, 'D3'); ?>>D3</option>
                    <option value="S1" <?php selected($pend_ortu, 'S1'); ?>>S1</option>
                    <option value="S2" <?php selected($pend_ortu, 'S2'); ?>>S2</option>
                    <option value="S3" <?php selected($pend_ortu, 'S3'); ?>>S3</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="siswa_no_telp">No. Telepon (HP Orang Tua/Siswa)</label></th>
            <td><input type="text" id="siswa_no_telp" name="siswa_no_telp" value="<?php echo esc_attr($no_telp); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="siswa_email">Email</label></th>
            <td><input type="email" id="siswa_email" name="siswa_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="contoh@email.com"></td>
        </tr>
        <tr>
            <th><label for="siswa_portal_pin">PIN Portal</label></th>
            <td>
                <input type="password" id="siswa_portal_pin" name="siswa_portal_pin" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo get_post_meta( $post->ID, '_siswa_portal_pin', true ) ? '•••••••• (biarkan kosong jika tidak diubah)' : 'Opsional — ganti NIS sebagai password'; ?>">
                <p class="description">
                    Disarankan diisi. Jika diisi, login pembayaran/pembelajaran memakai PIN ini (bukan NIS).
                    Kosongkan untuk tetap memakai NIS (legacy). Centang di bawah untuk menghapus PIN.
                </p>
                <?php if ( get_post_meta( $post->ID, '_siswa_portal_pin', true ) ) : ?>
                    <label><input type="checkbox" name="siswa_portal_pin_hapus" value="1"> Hapus PIN (kembali ke login NIS)</label>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label>Jenis Tagihan / Biaya Tambahan</label></th>
            <td>
                <?php
                $biaya_query = new WP_Query([
                    'post_type'      => 'master_biaya',
                    'posts_per_page' => -1,
                    'orderby'        => 'title',
                    'order'          => 'ASC'
                ]);
                $selected_biaya = get_post_meta( $post->ID, '_siswa_biaya_tambahan', true );
                if ( ! is_array( $selected_biaya ) ) {
                    $selected_biaya = array();
                }
                
                if ( $biaya_query->have_posts() ) {
                    while ( $biaya_query->have_posts() ) {
                        $biaya_query->the_post();
                        $biaya_id = get_the_ID();
                        $nominal = get_post_meta( $biaya_id, '_biaya_nominal', true );
                        $checked = in_array( $biaya_id, $selected_biaya ) ? 'checked' : '';
                        echo '<label style="display:block; margin-bottom:5px;">';
                        echo '<input type="checkbox" name="siswa_biaya_tambahan[]" value="' . esc_attr($biaya_id) . '" ' . $checked . '> ';
                        echo esc_html( get_the_title() ) . ' (Rp ' . number_format(intval($nominal), 0, ',', '.') . ')';
                        echo '</label>';
                    }
                    wp_reset_postdata();
                } else {
                    echo '<span style="color:#aaa; font-style:italic;">Belum ada data Jenis Biaya Tambahan. Silakan tambahkan terlebih dahulu.</span>';
                }
                ?>
            </td>
        </tr>
    </table>
    <?php
}

// 4. Proses Simpan Data Siswa ke Basis Data WordPress
add_action( 'save_post_master_siswa', 'qrg_save_siswa_details' );
function qrg_save_siswa_details( $post_id ) {
    if ( ! isset( $_POST['qrg_siswa_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_siswa_nonce'], 'qrg_save_siswa_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    if ( isset( $_POST['siswa_nis'] ) ) update_post_meta( $post_id, '_siswa_nis', sanitize_text_field( $_POST['siswa_nis'] ) );
    if ( isset( $_POST['siswa_nisn'] ) ) update_post_meta( $post_id, '_siswa_nisn', sanitize_text_field( $_POST['siswa_nisn'] ) );
    if ( isset( $_POST['siswa_kelas'] ) ) update_post_meta( $post_id, '_siswa_kelas', sanitize_text_field( $_POST['siswa_kelas'] ) ); // Simpan Kelas
    if ( isset( $_POST['siswa_asal_sekolah'] ) ) update_post_meta( $post_id, '_siswa_asal_sekolah', sanitize_text_field( $_POST['siswa_asal_sekolah'] ) );
    if ( isset( $_POST['siswa_alamat'] ) ) update_post_meta( $post_id, '_siswa_alamat', sanitize_textarea_field( $_POST['siswa_alamat'] ) );
    if ( isset( $_POST['siswa_tempat_lahir'] ) ) update_post_meta( $post_id, '_siswa_tempat_lahir', sanitize_text_field( $_POST['siswa_tempat_lahir'] ) );
    if ( isset( $_POST['siswa_tgl_lahir'] ) ) update_post_meta( $post_id, '_siswa_tgl_lahir', sanitize_text_field( $_POST['siswa_tgl_lahir'] ) );
    if ( isset( $_POST['siswa_nama_ortu'] ) ) update_post_meta( $post_id, '_siswa_nama_ortu', sanitize_text_field( $_POST['siswa_nama_ortu'] ) );
    if ( isset( $_POST['siswa_pend_ortu'] ) ) update_post_meta( $post_id, '_siswa_pend_ortu', sanitize_text_field( $_POST['siswa_pend_ortu'] ) );
    if ( isset( $_POST['siswa_no_telp'] ) ) update_post_meta( $post_id, '_siswa_no_telp', sanitize_text_field( $_POST['siswa_no_telp'] ) );
    if ( isset( $_POST['siswa_email'] ) ) update_post_meta( $post_id, '_siswa_email', sanitize_email( wp_unslash( $_POST['siswa_email'] ) ) );

    if ( ! empty( $_POST['siswa_portal_pin_hapus'] ) ) {
        delete_post_meta( $post_id, '_siswa_portal_pin' );
    } elseif ( isset( $_POST['siswa_portal_pin'] ) && $_POST['siswa_portal_pin'] !== '' ) {
        $pin = sanitize_text_field( wp_unslash( $_POST['siswa_portal_pin'] ) );
        if ( strlen( $pin ) >= 4 ) {
            update_post_meta( $post_id, '_siswa_portal_pin', wp_hash_password( $pin ) );
        }
    }
    
    $biaya_tambahan = isset( $_POST['siswa_biaya_tambahan'] ) ? array_map( 'sanitize_text_field', $_POST['siswa_biaya_tambahan'] ) : array();
    update_post_meta( $post_id, '_siswa_biaya_tambahan', $biaya_tambahan );
}

// 5. Kustomisasi Susunan Kolom Tabel Admin Master Siswa
add_filter( 'manage_master_siswa_posts_columns', 'qrg_set_siswa_columns' );
function qrg_set_siswa_columns($columns) {
    $new_columns = array();
    $new_columns['cb'] = $columns['cb'];
    $new_columns['foto_siswa'] = 'Foto';
    $new_columns['title'] = 'Nama Siswa';
    $new_columns['nis'] = 'NIS';
    $new_columns['nisn'] = 'NISN';
    $new_columns['kelas'] = 'Kelas'; // Kolom baru di tabel utama admin
    $new_columns['wali_kelas'] = 'Wali Kelas';
    $new_columns['asal_sekolah'] = 'Asal Sekolah';
    $new_columns['tagihan_pilihan'] = 'Jenis Tagihan';
    $new_columns['total_tagihan'] = 'Total Tagihan';
    $new_columns['portal_pembayaran'] = 'Pembayaran';
    $new_columns['portal_pembelajaran'] = 'Pembelajaran';
    $new_columns['portal_sekolah'] = 'Portal';
    $new_columns['nama_ortu'] = 'Nama Orang Tua';
    $new_columns['no_telp'] = 'No. Telepon';
    $new_columns['email'] = 'Email';
    return $new_columns;
}

add_action( 'manage_master_siswa_posts_custom_column' , 'qrg_fill_siswa_columns', 10, 2 );
function qrg_fill_siswa_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'foto_siswa' :
            if ( has_post_thumbnail( $post_id ) ) {
                echo get_the_post_thumbnail( $post_id, array( 50, 50 ), array( 'style' => 'border-radius:4px;' ) );
            } else {
                echo '<span style="color:#aaa; font-size:12px;">No Photo</span>';
            }
            break;
        case 'nis' :
            $nis = get_post_meta( $post_id, '_siswa_nis', true );
            echo esc_html( $nis ? $nis : '-' );
            break;
        case 'nisn' :
            $nisn = get_post_meta( $post_id, '_siswa_nisn', true );
            echo esc_html( $nisn ? $nisn : '-' );
            break;
        case 'email' :
            $email = get_post_meta( $post_id, '_siswa_email', true );
            echo $email
                ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>'
                : '<span style="color:#aaa;">-</span>';
            break;
        case 'kelas' : // Isi data kolom Kelas
            $kelas = get_post_meta( $post_id, '_siswa_kelas', true );
            $label = qrg_get_kelas_label( $kelas );
            if ( $label && is_numeric( $kelas ) ) {
                echo '<a href="' . esc_url( get_edit_post_link( (int) $kelas ) ) . '"><strong>' . esc_html( $label ) . '</strong></a>';
            } else {
                echo '<strong>' . esc_html( $label ? $label : '-' ) . '</strong>';
            }
            break;
        case 'wali_kelas' :
            $kelas = get_post_meta( $post_id, '_siswa_kelas', true );
            if ( is_numeric( $kelas ) ) {
                $wali_nama = qrg_get_kelas_wali_nama( (int) $kelas );
                $wali_id   = qrg_get_kelas_wali_id( (int) $kelas );
                if ( $wali_nama && $wali_id ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $wali_id ) ) . '">' . esc_html( $wali_nama ) . '</a>';
                } elseif ( $wali_nama ) {
                    echo esc_html( $wali_nama );
                } else {
                    echo '<span style="color:#aaa;">-</span>';
                }
            } else {
                echo '<span style="color:#aaa;">-</span>';
            }
            break;
        case 'asal_sekolah' :
            $asal_sekolah = get_post_meta( $post_id, '_siswa_asal_sekolah', true );
            echo esc_html( $asal_sekolah ? $asal_sekolah : '-' );
            break;
        case 'tagihan_pilihan' :
            $selected_biaya = get_post_meta( $post_id, '_siswa_biaya_tambahan', true );
            if ( ! empty( $selected_biaya ) && is_array( $selected_biaya ) ) {
                $names = array();
                foreach ( $selected_biaya as $biaya_id ) {
                    if ( get_post_status( $biaya_id ) ) {
                        $names[] = get_the_title( $biaya_id );
                    }
                }
                echo esc_html( implode( ', ', $names ) );
            } else {
                echo '<span style="color:#aaa;">-</span>';
            }
            break;
        case 'total_tagihan' :
            if ( ! function_exists( 'qrg_get_siswa_tagihan_summary' ) ) {
                echo '<span style="color:#aaa;">-</span>';
                break;
            }
            $summary  = qrg_get_siswa_tagihan_summary( $post_id );
            $list_url = admin_url( 'edit.php?post_type=master_tagihan&siswa_id=' . (int) $post_id );
            if ( (int) $summary['count'] === 0 ) {
                echo '<span style="color:#aaa;">Rp 0</span>';
                break;
            }
            echo '<a href="' . esc_url( $list_url ) . '"><strong>Rp ' . esc_html( number_format( (int) $summary['total'], 0, ',', '.' ) ) . '</strong></a>';
            echo '<div style="font-size:11px;color:#646970;margin-top:2px;">';
            echo (int) $summary['count'] . ' tagihan';
            if ( (int) $summary['belum'] > 0 || (int) $summary['ditinjau'] > 0 ) {
                $sisa = (int) $summary['belum'] + (int) $summary['ditinjau'];
                echo ' · sisa Rp ' . esc_html( number_format( $sisa, 0, ',', '.' ) );
            }
            echo '</div>';
            break;
        case 'portal_pembayaran' :
            $url = function_exists( 'qrg_get_sekolah_portal_url' )
                ? qrg_get_sekolah_portal_url( 'pembayaran' )
                : ( function_exists( 'qrg_get_siswa_pembayaran_url' ) ? qrg_get_siswa_pembayaran_url( $post_id ) : '#' );
            echo '<a class="button button-small" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">Buka</a>';
            break;
        case 'portal_pembelajaran' :
            $url = function_exists( 'qrg_get_sekolah_portal_url' )
                ? qrg_get_sekolah_portal_url( 'jadwal' )
                : ( function_exists( 'qrg_get_siswa_pembelajaran_url' ) ? qrg_get_siswa_pembelajaran_url( $post_id ) : '#' );
            echo '<a class="button button-small" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">Buka</a>';
            break;
        case 'portal_sekolah' :
            $url = function_exists( 'qrg_get_sekolah_portal_url' )
                ? qrg_get_sekolah_portal_url()
                : home_url( '/sekolah/' );
            echo '<a class="button button-small button-primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">Login</a>';
            break;
        case 'nama_ortu' :
            $nama_ortu = get_post_meta( $post_id, '_siswa_nama_ortu', true );
            echo esc_html( $nama_ortu ? $nama_ortu : '-' );
            break;
        case 'no_telp' :
            $no_telp = get_post_meta( $post_id, '_siswa_no_telp', true );
            echo esc_html( $no_telp ? $no_telp : '-' );
            break;
    }
}