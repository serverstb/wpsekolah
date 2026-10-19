<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Registrasi CPT Guru dengan Dukungan Foto (Thumbnail)
add_action( 'init', 'qrg_register_guru_cpt' );
function qrg_register_guru_cpt() {
    register_post_type( 'master_guru', [
        'labels' => [
            'name'          => 'Data Guru',
            'singular_name' => 'Guru',
            'menu_name'     => 'Data Guru',
            'add_new_item'  => 'Tambah Guru Baru',
            'edit_item'     => 'Edit Data Guru',
            'all_items'     => 'Data Guru',
        ],
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'show_in_menu'        => 'master-data-plugin',
        'supports'            => [ 'title', 'thumbnail' ], // 'title' untuk NAMA, 'thumbnail' untuk FOTO
        'has_archive'         => false,
    ] );
}

// Tombol Jabatan, Presensi & Gaji di samping "Tambah Guru Baru"
add_action( 'admin_footer-edit.php', 'qrg_guru_header_action_buttons' );
function qrg_guru_header_action_buttons() {
    global $typenow;
    if ( $typenow !== 'master_guru' || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $jabatan_url  = admin_url( 'edit.php?post_type=master_jabatan' );
    $presensi_url = admin_url( 'edit.php?post_type=master_presensi_guru' );
    $gaji_url     = admin_url( 'edit.php?post_type=master_gaji_guru' );
    ?>
    <script>
    jQuery(function($) {
        var $btn = $('.wrap .page-title-action').first();
        if (!$btn.length) {
            return;
        }
        var $jabatan = $('<a href="<?php echo esc_url( $jabatan_url ); ?>" class="page-title-action">Jabatan</a>')
            .insertAfter($btn);
        var $presensi = $('<a href="<?php echo esc_url( $presensi_url ); ?>" class="page-title-action">Presensi</a>')
            .insertAfter($jabatan);
        $('<a href="<?php echo esc_url( $gaji_url ); ?>" class="page-title-action">Gaji</a>')
            .insertAfter($presensi);
    });
    </script>
    <?php
}

// 2. Tambah Meta Box Informasi Lengkap Guru
add_action( 'add_meta_boxes', 'qrg_guru_meta_boxes' );
function qrg_guru_meta_boxes() {
    add_meta_box( 'guru_detail', 'Informasi Lengkap Detail Guru', 'qrg_guru_fields_callback', 'master_guru', 'normal', 'high' );
}

// 3. Form Input Kamar Data Guru di Dashboard Admin
function qrg_guru_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_guru_meta', 'qrg_guru_nonce' );
    
    // Ambil data yang sudah tersimpan di database
    $nuptk      = get_post_meta( $post->ID, '_guru_nuptk', true );
    $nip        = get_post_meta( $post->ID, '_guru_nip', true );
    $pin_absensi = get_post_meta( $post->ID, '_guru_pin_absensi', true );
    $alamat     = get_post_meta( $post->ID, '_guru_alamat', true );
    $tgl_lahir  = get_post_meta( $post->ID, '_guru_tgl_lahir', true );
    $sejak      = get_post_meta( $post->ID, '_guru_sejak', true );
    $pendidikan = get_post_meta( $post->ID, '_guru_pendidikan', true );
    $jabatan_id   = (int) get_post_meta( $post->ID, '_guru_jabatan_id', true );
    $jabatan_teks = get_post_meta( $post->ID, '_guru_jabatan', true );
    $no_telp      = get_post_meta( $post->ID, '_guru_no_telp', true );
    $nama_bank    = get_post_meta( $post->ID, '_guru_nama_bank', true );
    $no_rek       = get_post_meta( $post->ID, '_guru_no_rek', true );
    $wali_kelas   = (int) get_post_meta( $post->ID, '_guru_wali_kelas', true );
    $kelas_ids    = qrg_get_guru_kelas_ids( $post->ID );
    $daftar_kelas = qrg_get_daftar_kelas();
    $daftar_jabatan = function_exists( 'qrg_get_daftar_jabatan' ) ? qrg_get_daftar_jabatan() : [];

    // Cocokkan teks lama ke master jabatan jika ID belum ada
    if ( ! $jabatan_id && $jabatan_teks && ! empty( $daftar_jabatan ) ) {
        foreach ( $daftar_jabatan as $j_post ) {
            if ( strcasecmp( $j_post->post_title, $jabatan_teks ) === 0 ) {
                $jabatan_id = (int) $j_post->ID;
                break;
            }
        }
    }
    
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Untuk mengisi <strong>NAMA</strong> silakan isi di kolom judul paling atas, dan untuk <strong>FOTO</strong> silakan unggah di bagian panel "Featured Image" di sebelah kanan bawah.
    </p>
    
    <table class="form-table">
        <tr>
            <th><label for="guru_nuptk">NUPTK</label></th>
            <td><input type="text" id="guru_nuptk" name="guru_nuptk" value="<?php echo esc_attr($nuptk); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="guru_nip">NIP (Nomor Induk Pegawai)</label></th>
            <td>
                <input type="text" id="guru_nip" name="guru_nip" value="<?php echo esc_attr($nip); ?>" class="regular-text">
                <p class="description">Dipakai sebagai username login portal <code>/sekolah/guru/</code>.</p>
            </td>
        </tr>
        <tr>
            <th><label for="guru_pin_absensi">PIN Mesin Absensi</label></th>
            <td>
                <input type="text" id="guru_pin_absensi" name="guru_pin_absensi" value="<?php echo esc_attr( $pin_absensi ); ?>" class="regular-text" placeholder="Contoh: 1001">
                <p class="description">Opsional: User ID / PIN di mesin Solution (jika dipakai sinkron absensi ke gaji).</p>
            </td>
        </tr>
        <tr>
            <th><label for="guru_alamat">Alamat Tinggal</label></th>
            <td><textarea id="guru_alamat" name="guru_alamat" rows="3" class="large-text"><?php echo esc_textarea($alamat); ?></textarea></td>
        </tr>
        <tr>
            <th><label for="guru_tgl_lahir">Tanggal Lahir</label></th>
            <td><input type="date" id="guru_tgl_lahir" name="guru_tgl_lahir" value="<?php echo esc_attr($tgl_lahir); ?>"></td>
        </tr>
        <tr>
            <th><label for="guru_sejak">Sebagai Guru Sejak (Tahun)</label></th>
            <td><input type="number" id="guru_sejak" name="guru_sejak" value="<?php echo esc_attr($sejak); ?>" placeholder="Contoh: 2018" class="small-text"></td>
        </tr>
        <tr>
            <th><label for="guru_pendidikan">Pendidikan Terakhir</label></th>
            <td>
                <select id="guru_pendidikan" name="guru_pendidikan">
                    <option value="">-- Pilih Pendidikan --</option>
                    <option value="D3" <?php selected($pendidikan, 'D3'); ?>>D3</option>
                    <option value="S1 / D4" <?php selected($pendidikan, 'S1 / D4'); ?>>S1 / D4</option>
                    <option value="S2" <?php selected($pendidikan, 'S2'); ?>>S2</option>
                    <option value="S3" <?php selected($pendidikan, 'S3'); ?>>S3</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="guru_jabatan_id">Jabatan</label></th>
            <td>
                <select id="guru_jabatan_id" name="guru_jabatan_id" style="min-width: 320px;">
                    <option value="">-- Pilih Jabatan --</option>
                    <?php foreach ( $daftar_jabatan as $j_post ) : ?>
                        <option value="<?php echo esc_attr( $j_post->ID ); ?>" <?php selected( $jabatan_id, (int) $j_post->ID ); ?>>
                            <?php echo esc_html( $j_post->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( empty( $daftar_jabatan ) ) : ?>
                    <p class="description" style="color:#b45309;">Belum ada master jabatan. Tambahkan dulu lewat tombol <strong>Jabatan</strong> di halaman Data Guru.</p>
                <?php else : ?>
                    <p class="description">Daftar dari <strong>Master Jabatan</strong>. Kelola lewat tombol Jabatan di Data Guru.</p>
                <?php endif; ?>
                <?php if ( ! $jabatan_id && $jabatan_teks ) : ?>
                    <p class="description" style="color:#64748b;">Data lama: <em><?php echo esc_html( $jabatan_teks ); ?></em> — pilih jabatan dari master agar tersinkron.</p>
                <?php endif; ?>
            </td>
        </tr>
        <tr style="border-top: 1px solid #eee;">
            <th><label for="guru_wali_kelas">Wali Kelas Dari</label></th>
            <td>
                <select id="guru_wali_kelas" name="guru_wali_kelas" style="min-width: 320px;">
                    <option value="">-- Bukan Wali Kelas --</option>
                    <?php foreach ( $daftar_kelas as $kelas_post ) : ?>
                        <option value="<?php echo esc_attr( $kelas_post->ID ); ?>" <?php selected( $wali_kelas, $kelas_post->ID ); ?>>
                            <?php echo esc_html( qrg_get_kelas_label( $kelas_post->ID ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Terintegrasi dengan <strong>Data Kelas</strong>. Satu guru hanya bisa menjadi wali satu kelas.</p>
            </td>
        </tr>
        <tr>
            <th><label>Kelas yang Diampu</label></th>
            <td>
                <?php if ( empty( $daftar_kelas ) ) : ?>
                    <span style="color:#aaa; font-style:italic;">Belum ada data kelas. Tambahkan dulu di Data Kelas.</span>
                <?php else : ?>
                    <?php foreach ( $daftar_kelas as $kelas_post ) : ?>
                        <label style="display:block; margin-bottom:5px;">
                            <input type="checkbox" name="guru_kelas_ids[]" value="<?php echo esc_attr( $kelas_post->ID ); ?>" <?php checked( in_array( (int) $kelas_post->ID, $kelas_ids, true ) ); ?>>
                            <?php echo esc_html( qrg_get_kelas_label( $kelas_post->ID ) ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">Centang kelas yang diajar / diampu oleh guru ini.</p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="guru_no_telp">No. Telepon / HP</label></th>
            <td>
                <input type="text" id="guru_no_telp" name="guru_no_telp" value="<?php echo esc_attr($no_telp); ?>" class="regular-text" placeholder="08xxxxxxxxxx">
                <p class="description">Dipakai sebagai <strong>password</strong> login portal <code>/sekolah/guru/</code> (bersama NIP).</p>
            </td>
        </tr>
        <tr style="border-top: 1px solid #eee;">
            <th><label for="guru_nama_bank">Nama Bank</label></th>
            <td><input type="text" id="guru_nama_bank" name="guru_nama_bank" value="<?php echo esc_attr($nama_bank); ?>" placeholder="Contoh: Bank Mandiri / BCA" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="guru_no_rek">No. Rekening</label></th>
            <td><input type="text" id="guru_no_rek" name="guru_no_rek" value="<?php echo esc_attr($no_rek); ?>" class="regular-text"></td>
        </tr>
    </table>
    <?php
}

// 4. Proses Simpan Data Guru ke Basis Data WordPress
add_action( 'save_post_master_guru', 'qrg_save_guru_details' );
function qrg_save_guru_details( $post_id ) {
    if ( ! isset( $_POST['qrg_guru_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_guru_nonce'], 'qrg_save_guru_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    if ( isset( $_POST['guru_nuptk'] ) ) update_post_meta( $post_id, '_guru_nuptk', sanitize_text_field( $_POST['guru_nuptk'] ) );
    if ( isset( $_POST['guru_nip'] ) ) update_post_meta( $post_id, '_guru_nip', sanitize_text_field( $_POST['guru_nip'] ) );
    if ( isset( $_POST['guru_pin_absensi'] ) ) update_post_meta( $post_id, '_guru_pin_absensi', sanitize_text_field( $_POST['guru_pin_absensi'] ) );

    if ( isset( $_POST['guru_alamat'] ) ) update_post_meta( $post_id, '_guru_alamat', sanitize_textarea_field( $_POST['guru_alamat'] ) );
    if ( isset( $_POST['guru_tgl_lahir'] ) ) update_post_meta( $post_id, '_guru_tgl_lahir', sanitize_text_field( $_POST['guru_tgl_lahir'] ) );
    if ( isset( $_POST['guru_sejak'] ) ) update_post_meta( $post_id, '_guru_sejak', sanitize_text_field( $_POST['guru_sejak'] ) );
    if ( isset( $_POST['guru_pendidikan'] ) ) update_post_meta( $post_id, '_guru_pendidikan', sanitize_text_field( $_POST['guru_pendidikan'] ) );
    if ( isset( $_POST['guru_jabatan_id'] ) ) {
        $jabatan_id = absint( $_POST['guru_jabatan_id'] );
        if ( $jabatan_id && get_post_type( $jabatan_id ) === 'master_jabatan' ) {
            update_post_meta( $post_id, '_guru_jabatan_id', $jabatan_id );
            update_post_meta( $post_id, '_guru_jabatan', get_the_title( $jabatan_id ) );
        } else {
            delete_post_meta( $post_id, '_guru_jabatan_id' );
            update_post_meta( $post_id, '_guru_jabatan', '' );
        }
    }
    if ( isset( $_POST['guru_no_telp'] ) ) update_post_meta( $post_id, '_guru_no_telp', sanitize_text_field( $_POST['guru_no_telp'] ) );
    if ( isset( $_POST['guru_nama_bank'] ) ) update_post_meta( $post_id, '_guru_nama_bank', sanitize_text_field( $_POST['guru_nama_bank'] ) );
    if ( isset( $_POST['guru_no_rek'] ) ) update_post_meta( $post_id, '_guru_no_rek', sanitize_text_field( $_POST['guru_no_rek'] ) );

    // Kelas yang diampu
    $kelas_ids = isset( $_POST['guru_kelas_ids'] ) ? array_map( 'intval', (array) $_POST['guru_kelas_ids'] ) : array();
    $kelas_ids = array_values( array_filter( $kelas_ids ) );
    update_post_meta( $post_id, '_guru_kelas_ids', $kelas_ids );

    // Sinkron wali kelas (guru → kelas)
    $wali_kelas = isset( $_POST['guru_wali_kelas'] ) ? (int) $_POST['guru_wali_kelas'] : 0;
    $prev_wali  = (int) get_post_meta( $post_id, '_guru_wali_kelas', true );

    if ( $prev_wali && $prev_wali !== $wali_kelas ) {
        $prev_guru = (int) get_post_meta( $prev_wali, '_kelas_wali_id', true );
        if ( $prev_guru === (int) $post_id ) {
            delete_post_meta( $prev_wali, '_kelas_wali_id' );
        }
        delete_post_meta( $post_id, '_guru_wali_kelas' );
    }

    if ( $wali_kelas && get_post_type( $wali_kelas ) === 'master_kelas' ) {
        qrg_sync_kelas_wali( $wali_kelas, $post_id );
        if ( ! in_array( $wali_kelas, $kelas_ids, true ) ) {
            $kelas_ids[] = $wali_kelas;
            update_post_meta( $post_id, '_guru_kelas_ids', $kelas_ids );
        }
    } else {
        delete_post_meta( $post_id, '_guru_wali_kelas' );
    }
}

// 5. Kustomisasi Kolom Tabel Daftar Guru di Dashboard
add_filter( 'manage_master_guru_posts_columns', 'qrg_set_guru_columns' );
function qrg_set_guru_columns($columns) {
    $new_columns = array();
    $new_columns['cb'] = $columns['cb'];
    $new_columns['foto_guru'] = 'Foto';
    $new_columns['title'] = 'Nama Guru';
    $new_columns['nuptk'] = 'NUPTK';
    $new_columns['nip'] = 'NIP';
    $new_columns['jabatan'] = 'Jabatan';
    $new_columns['wali_kelas'] = 'Wali Kelas';
    $new_columns['kelas_diampu'] = 'Kelas Diampu';
    $new_columns['no_telp'] = 'No. Telepon';
    return $new_columns;
}

add_action( 'manage_master_guru_posts_custom_column' , 'qrg_fill_guru_columns', 10, 2 );
function qrg_fill_guru_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'foto_guru' :
            if ( has_post_thumbnail( $post_id ) ) {
                echo get_the_post_thumbnail( $post_id, array( 50, 50 ), array( 'style' => 'border-radius:4px;' ) );
            } else {
                echo '<span style="color:#aaa; font-size:12px;">No Photo</span>';
            }
            break;
        case 'nuptk' :
            $nuptk = get_post_meta( $post_id, '_guru_nuptk', true );
            echo esc_html( $nuptk ? $nuptk : '-' );
            break;
        case 'nip' :
            $nip = get_post_meta( $post_id, '_guru_nip', true );
            echo esc_html( $nip ? $nip : '-' );
            break;
        case 'jabatan' :
            $jabatan = function_exists( 'qrg_get_guru_jabatan_label' )
                ? qrg_get_guru_jabatan_label( $post_id )
                : get_post_meta( $post_id, '_guru_jabatan', true );
            echo esc_html( $jabatan ? $jabatan : '-' );
            break;
        case 'wali_kelas' :
            $wali = (int) get_post_meta( $post_id, '_guru_wali_kelas', true );
            if ( $wali ) {
                echo '<a href="' . esc_url( get_edit_post_link( $wali ) ) . '"><strong>' . esc_html( qrg_get_kelas_label( $wali ) ) . '</strong></a>';
            } else {
                echo '<span style="color:#aaa;">-</span>';
            }
            break;
        case 'kelas_diampu' :
            $ids = qrg_get_guru_kelas_ids( $post_id );
            if ( empty( $ids ) ) {
                echo '<span style="color:#aaa;">-</span>';
            } else {
                $labels = array();
                foreach ( $ids as $kid ) {
                    $labels[] = qrg_get_kelas_label( $kid );
                }
                echo esc_html( implode( ', ', $labels ) );
            }
            break;
        case 'no_telp' :
            $no_telp = get_post_meta( $post_id, '_guru_no_telp', true );
            echo esc_html( $no_telp ? $no_telp : '-' );
            break;
    }
}