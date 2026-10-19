<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Registrasi CPT Penerimaan Siswa Baru (PSB)
add_action( 'init', 'qrg_register_psb_cpt' );
function qrg_register_psb_cpt() {
    register_post_type( 'master_psb', [
        'labels' => [
            'name'          => 'Penerimaan Siswa Baru',
            'singular_name' => 'Pendaftar Baru',
            'menu_name'     => 'Penerimaan Siswa',
            'add_new_item'  => 'Tambah Pendaftar Baru',
            'edit_item'     => 'Edit Data Pendaftar',
            'all_items'     => 'Penerimaan Siswa',
        ],
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'show_in_menu'        => 'master-data-plugin',
        'supports'            => [ 'title', 'thumbnail' ], // 'title' untuk NAMA PENDAFTAR, 'thumbnail' untuk FOTO/DOKUMEN
        'has_archive'         => false,
        'menu_position'       => 26,
    ] );
}

// 2. Tambah Meta Box Data Pendaftar Baru
add_action( 'add_meta_boxes', 'qrg_psb_meta_boxes' );
function qrg_psb_meta_boxes() {
    add_meta_box( 'psb_detail', 'Informasi Lengkap Calon Siswa Baru', 'qrg_psb_fields_callback', 'master_psb', 'normal', 'high' );
    add_meta_box( 'psb_tagihan', 'Tagihan Jalur Penerimaan', 'qrg_psb_tagihan_metabox_callback', 'master_psb', 'normal', 'default' );
}

/**
 * Ringkasan tagihan pendaftar PSB.
 *
 * @param int $psb_id ID master_psb.
 * @return array{count:int,total:int,lunas:int,belum:int,ditinjau:int,items:array}
 */
function qrg_get_psb_tagihan_summary( $psb_id ) {
    $summary = [
        'count'    => 0,
        'total'    => 0,
        'lunas'    => 0,
        'belum'    => 0,
        'ditinjau' => 0,
        'items'    => [],
    ];

    if ( ! function_exists( 'qrg_get_psb_tagihan_ids' ) ) {
        return $summary;
    }

    foreach ( qrg_get_psb_tagihan_ids( $psb_id ) as $tagihan_id ) {
        $nominal  = (int) get_post_meta( $tagihan_id, '_tagihan_nominal', true );
        $biaya_id = get_post_meta( $tagihan_id, '_tagihan_biaya_tambahan_id', true );
        if ( $biaya_id ) {
            $nominal += (int) get_post_meta( $biaya_id, '_biaya_nominal', true );
        }
        $status = get_post_meta( $tagihan_id, '_tagihan_status', true );
        if ( ! $status ) {
            $status = 'Belum Dibayar';
        }

        $summary['count']++;
        $summary['total'] += $nominal;
        if ( $status === 'Lunas' ) {
            $summary['lunas'] += $nominal;
        } elseif ( $status === 'Sedang Ditinjau' ) {
            $summary['ditinjau'] += $nominal;
        } else {
            $summary['belum'] += $nominal;
        }

        $summary['items'][] = [
            'id'      => (int) $tagihan_id,
            'title'   => get_the_title( $tagihan_id ),
            'nominal' => $nominal,
            'status'  => $status,
            'pay_url' => function_exists( 'qrg_get_tagihan_pay_url' )
                ? qrg_get_tagihan_pay_url( $tagihan_id )
                : get_permalink( $tagihan_id ),
        ];
    }

    return $summary;
}

/**
 * Meta box tagihan jalur pada halaman edit pendaftar.
 */
function qrg_psb_tagihan_metabox_callback( $post ) {
    $jalur_id = (int) get_post_meta( $post->ID, '_psb_jalur_id', true );
    $summary  = qrg_get_psb_tagihan_summary( $post->ID );
    $jalur_items = ( $jalur_id && function_exists( 'qrg_get_jalur_biaya_items' ) )
        ? qrg_get_jalur_biaya_items( $jalur_id )
        : [];

    if ( ! $jalur_id ) {
        echo '<p style="margin:0;color:#646970;">Belum memilih jalur penerimaan. Pilih jalur di atas lalu simpan agar tagihan muncul otomatis.</p>';
        return;
    }
    ?>
    <table class="widefat striped" style="margin-top:4px;">
        <thead>
            <tr>
                <th>Jalur</th>
                <th>Jumlah Invoice</th>
                <th>Total</th>
                <th>Lunas</th>
                <th>Belum Dibayar</th>
                <th>Ditinjau</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong><?php echo esc_html( qrg_get_jalur_label( $jalur_id ) ); ?></strong></td>
                <td><?php echo (int) $summary['count']; ?></td>
                <td><strong>Rp <?php echo esc_html( number_format( (int) $summary['total'], 0, ',', '.' ) ); ?></strong></td>
                <td style="color:#16a34a;">Rp <?php echo esc_html( number_format( (int) $summary['lunas'], 0, ',', '.' ) ); ?></td>
                <td style="color:#ea580c;">Rp <?php echo esc_html( number_format( (int) $summary['belum'], 0, ',', '.' ) ); ?></td>
                <td style="color:#2563eb;">Rp <?php echo esc_html( number_format( (int) $summary['ditinjau'], 0, ',', '.' ) ); ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ( ! empty( $summary['items'] ) ) : ?>
        <h4 style="margin:16px 0 8px;">Daftar Tagihan</h4>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Nominal</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $summary['items'] as $item ) :
                    $status = $item['status'];
                    if ( $status === 'Lunas' ) {
                        $color = '#16a34a';
                    } elseif ( $status === 'Sedang Ditinjau' ) {
                        $color = '#2563eb';
                    } else {
                        $color = '#ea580c';
                    }
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>">
                                <?php echo esc_html( $item['title'] ? $item['title'] : 'Tagihan #' . $item['id'] ); ?>
                            </a>
                        </td>
                        <td>Rp <?php echo esc_html( number_format( (int) $item['nominal'], 0, ',', '.' ) ); ?></td>
                        <td><strong style="color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $status ); ?></strong></td>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>">Edit</a>
                            <?php if ( ! empty( $item['pay_url'] ) ) : ?>
                                · <a href="<?php echo esc_url( $item['pay_url'] ); ?>" target="_blank" rel="noopener">Link bayar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ( ! empty( $jalur_items ) ) : ?>
        <p style="margin:14px 0 8px;color:#b45309;">
            Tagihan jalur belum dibuat. Biaya yang terpasang pada jalur ini:
        </p>
        <ul style="margin:0 0 10px;padding-left:18px;">
            <?php foreach ( $jalur_items as $item ) : ?>
                <li>
                    <?php echo esc_html( $item['title'] ); ?>
                    — Rp <?php echo esc_html( number_format( (int) $item['nominal'], 0, ',', '.' ) ); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="description" style="margin:0;">Simpan pendaftar ini untuk membuat tagihan otomatis sesuai jalur.</p>
    <?php else : ?>
        <p style="margin:14px 0 0;color:#646970;">
            Jalur ini belum memiliki Jenis Biaya Tambahan.
            Atur di <a href="<?php echo esc_url( get_edit_post_link( $jalur_id ) ); ?>">edit jalur</a>.
        </p>
    <?php endif;
}

// 3. Form Input Data Pendaftar Baru di Dashboard Admin
function qrg_psb_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_psb_meta', 'qrg_psb_nonce' );
    
    // Ambil data dari database
    $nisn          = get_post_meta( $post->ID, '_psb_nisn', true );
    $asal_sekolah  = get_post_meta( $post->ID, '_psb_asal_sekolah', true );
    $nilai_rata    = get_post_meta( $post->ID, '_psb_nilai_rata', true );
    $alamat        = get_post_meta( $post->ID, '_psb_alamat', true );
    $tempat_lahir  = get_post_meta( $post->ID, '_psb_tempat_lahir', true );
    $tgl_lahir     = get_post_meta( $post->ID, '_psb_tgl_lahir', true );
    $nama_ortu     = get_post_meta( $post->ID, '_psb_nama_ortu', true );
    $no_telp       = get_post_meta( $post->ID, '_psb_no_telp', true );
    $jalur_id      = get_post_meta( $post->ID, '_psb_jalur_id', true );
    $token         = get_post_meta( $post->ID, '_psb_token', true );
    $status_daftar = get_post_meta( $post->ID, '_psb_status', true );
    if ( empty( $status_daftar ) ) {
        $status_daftar = 'Menunggu';
    }
    
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Kolom <strong>NAMA</strong> diambil dari kolom judul paling atas.
    </p>
    
    <table class="form-table">
        <tr>
            <th><label for="psb_token">Nomor Registrasi / Token</label></th>
            <td><input type="text" id="psb_token" name="psb_token" value="<?php echo esc_attr($token); ?>" class="regular-text" style="font-weight:bold; color:#0073aa;" readonly placeholder="Dibuat otomatis jika kosong"></td>
        </tr>
        <tr>
            <th><label for="psb_status">Status Pendaftaran</label></th>
            <td>
                <select id="psb_status" name="psb_status" required style="min-width: 200px; font-weight: bold;">
                    <option value="Menunggu" <?php selected($status_daftar, 'Menunggu'); ?>>Menunggu Review</option>
                    <option value="Diterima" <?php selected($status_daftar, 'Diterima'); ?>>Diterima</option>
                    <option value="Ditolak" <?php selected($status_daftar, 'Ditolak'); ?>>Ditolak</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="psb_jalur_id">Jalur Penerimaan</label></th>
            <td>
                <select id="psb_jalur_id" name="psb_jalur_id" style="min-width: 260px;">
                    <option value="">— Pilih Jalur —</option>
                    <?php foreach ( qrg_get_daftar_jalur() as $jalur ) : ?>
                        <option value="<?php echo esc_attr( $jalur->ID ); ?>" <?php selected( (int) $jalur_id, (int) $jalur->ID ); ?>>
                            <?php echo esc_html( $jalur->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Kelola daftar jalur di menu <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=master_jalur' ) ); ?>">Jalur Penerimaan</a>.</p>
            </td>
        </tr>
        <tr>
            <th><label for="psb_nisn">NISN</label></th>
            <td><input type="text" id="psb_nisn" name="psb_nisn" value="<?php echo esc_attr($nisn); ?>" class="regular-text" required></td>
        </tr>
        <tr>
            <th><label for="psb_asal_sekolah">Asal Sekolah</label></th>
            <td><input type="text" id="psb_asal_sekolah" name="psb_asal_sekolah" value="<?php echo esc_attr($asal_sekolah); ?>" class="regular-text" required></td>
        </tr>
        <tr>
            <th><label for="psb_nilai_rata">Nilai Rata-rata Rapor</label></th>
            <td><input type="number" step="0.01" id="psb_nilai_rata" name="psb_nilai_rata" value="<?php echo esc_attr($nilai_rata); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="psb_alamat">Alamat Tinggal</label></th>
            <td><textarea id="psb_alamat" name="psb_alamat" rows="3" class="large-text"><?php echo esc_textarea($alamat); ?></textarea></td>
        </tr>
        <tr>
            <th><label for="psb_tempat_lahir">Tempat Lahir</label></th>
            <td><input type="text" id="psb_tempat_lahir" name="psb_tempat_lahir" value="<?php echo esc_attr($tempat_lahir); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="psb_tgl_lahir">Tanggal Lahir</label></th>
            <td><input type="date" id="psb_tgl_lahir" name="psb_tgl_lahir" value="<?php echo esc_attr($tgl_lahir); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="psb_nama_ortu">Nama Orang Tua/Wali</label></th>
            <td><input type="text" id="psb_nama_ortu" name="psb_nama_ortu" value="<?php echo esc_attr($nama_ortu); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="psb_no_telp">No. Telepon / WhatsApp</label></th>
            <td><input type="text" id="psb_no_telp" name="psb_no_telp" value="<?php echo esc_attr($no_telp); ?>" class="regular-text"></td>
        </tr>
    </table>
    
    <?php
    $custom_fields = get_option( 'qrg_psb_custom_fields', [] );
    if ( ! empty( $custom_fields ) ) {
        echo '<table class="form-table">';
        echo '<tr><td colspan="2"><hr style="margin: 15px 0;"><h3>Kolom Tambahan Kustom</h3></td></tr>';
        foreach ( $custom_fields as $cf ) {
            $meta_key = '_psb_cf_' . $cf['key'];
            $meta_val = get_post_meta( $post->ID, $meta_key, true );
            echo '<tr>';
            echo '<th><label>' . esc_html( $cf['label'] ) . '</label></th>';
            echo '<td>';
            if ( $cf['type'] === 'textarea' ) {
                echo '<textarea name="psb_cf_' . esc_attr($cf['key']) . '" rows="3" class="large-text">' . esc_textarea($meta_val) . '</textarea>';
            } elseif ( $cf['type'] === 'number' ) {
                echo '<input type="number" name="psb_cf_' . esc_attr($cf['key']) . '" value="' . esc_attr($meta_val) . '" class="regular-text">';
            } elseif ( $cf['type'] === 'date' ) {
                echo '<input type="date" name="psb_cf_' . esc_attr($cf['key']) . '" value="' . esc_attr($meta_val) . '" class="regular-text">';
            } elseif ( $cf['type'] === 'select' ) {
                $opts = ! empty( $cf['options'] ) && is_array( $cf['options'] ) ? $cf['options'] : [];
                echo '<select name="psb_cf_' . esc_attr( $cf['key'] ) . '" class="regular-text">';
                echo '<option value="">— Pilih —</option>';
                foreach ( $opts as $opt ) {
                    echo '<option value="' . esc_attr( $opt ) . '" ' . selected( $meta_val, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
                }
                echo '</select>';
            } else {
                echo '<input type="text" name="psb_cf_' . esc_attr($cf['key']) . '" value="' . esc_attr($meta_val) . '" class="regular-text">';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
}

// 4. Simpan Data Pendaftar Baru
add_action( 'save_post', 'qrg_save_psb_meta_data' );
function qrg_save_psb_meta_data( $post_id ) {
    if ( ! isset( $_POST['qrg_psb_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_psb_nonce'], 'qrg_save_psb_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $fields = [
        '_psb_nisn'          => 'psb_nisn',
        '_psb_asal_sekolah'  => 'psb_asal_sekolah',
        '_psb_nilai_rata'    => 'psb_nilai_rata',
        '_psb_alamat'        => 'psb_alamat',
        '_psb_tempat_lahir'  => 'psb_tempat_lahir',
        '_psb_tgl_lahir'     => 'psb_tgl_lahir',
        '_psb_nama_ortu'     => 'psb_nama_ortu',
        '_psb_no_telp'       => 'psb_no_telp',
        '_psb_jalur_id'      => 'psb_jalur_id',
        '_psb_token'         => 'psb_token',
        '_psb_status'        => 'psb_status',
    ];

    foreach ( $fields as $meta_key => $post_key ) {
        if ( isset( $_POST[ $post_key ] ) ) {
            $value = sanitize_text_field( $_POST[ $post_key ] );
            if ( $post_key === 'psb_alamat' ) {
                $value = sanitize_textarea_field( $_POST[ $post_key ] );
            }
            if ( $post_key === 'psb_jalur_id' ) {
                $value = absint( $value );
            }
            update_post_meta( $post_id, $meta_key, $value );
        }
    }
    
    // Simpan Custom Fields
    $custom_fields = get_option( 'qrg_psb_custom_fields', [] );
    if ( ! empty( $custom_fields ) ) {
        foreach ( $custom_fields as $cf ) {
            $post_key = 'psb_cf_' . $cf['key'];
            $meta_key = '_psb_cf_' . $cf['key'];
            if ( isset( $_POST[ $post_key ] ) ) {
                $value = qrg_psb_sanitize_custom_field_value( $cf, wp_unslash( $_POST[ $post_key ] ) );
                update_post_meta( $post_id, $meta_key, $value );
            }
        }
    }

    // Buat tagihan otomatis sesuai jalur yang dipilih
    $jalur_id = (int) get_post_meta( $post_id, '_psb_jalur_id', true );
    if ( $jalur_id && function_exists( 'qrg_create_tagihan_for_psb_jalur' ) ) {
        qrg_create_tagihan_for_psb_jalur( $post_id, $jalur_id );
    }
}

// 5. Custom Columns untuk Tabel Pendaftar
add_filter( 'manage_master_psb_posts_columns', 'qrg_set_custom_edit_psb_columns' );
function qrg_set_custom_edit_psb_columns( $columns ) {
    $new_columns = [];
    foreach ( $columns as $key => $value ) {
        if ( $key == 'title' ) {
            $new_columns['title'] = 'Nama Pendaftar';
            $new_columns['psb_jalur'] = 'Jalur';
            $new_columns['psb_nisn'] = 'NISN';
            $new_columns['psb_asal_sekolah'] = 'Asal Sekolah';
            $new_columns['psb_token'] = 'Token';
            $new_columns['psb_tagihan'] = 'Tagihan';
            $new_columns['psb_status'] = 'Status';
        } else {
            $new_columns[ $key ] = $value;
        }
    }
    return $new_columns;
}

add_action( 'manage_master_psb_posts_custom_column', 'qrg_custom_psb_column', 10, 2 );
function qrg_custom_psb_column( $column, $post_id ) {
    switch ( $column ) {
        case 'psb_jalur':
            $jalur_id = get_post_meta( $post_id, '_psb_jalur_id', true );
            echo esc_html( $jalur_id ? qrg_get_jalur_label( $jalur_id ) : '—' );
            break;
        case 'psb_nisn':
            echo esc_html( get_post_meta( $post_id, '_psb_nisn', true ) );
            break;
        case 'psb_asal_sekolah':
            echo esc_html( get_post_meta( $post_id, '_psb_asal_sekolah', true ) );
            break;
        case 'psb_token':
            echo '<strong>' . esc_html( get_post_meta( $post_id, '_psb_token', true ) ) . '</strong>';
            break;
        case 'psb_tagihan':
            $summary  = qrg_get_psb_tagihan_summary( $post_id );
            $jalur_id = (int) get_post_meta( $post_id, '_psb_jalur_id', true );
            $buat_url = wp_nonce_url(
                admin_url( 'admin.php?action=qrg_buat_tagihan_psb&psb_id=' . (int) $post_id ),
                'qrg_buat_tagihan_psb_' . (int) $post_id
            );

            if ( empty( $summary['count'] ) ) {
                if ( ! $jalur_id ) {
                    echo '<span style="color:#646970;">—</span>';
                    echo '<div style="font-size:11px;color:#666;">Pilih jalur dulu</div>';
                    break;
                }
                if ( ! function_exists( 'qrg_get_jalur_biaya_total' ) || qrg_get_jalur_biaya_total( $jalur_id ) <= 0 ) {
                    echo '<span style="color:#646970;">—</span>';
                    echo '<div style="font-size:11px;color:#666;">Jalur belum punya biaya</div>';
                    break;
                }
                $estimasi = qrg_get_jalur_biaya_total( $jalur_id );
                echo '<a href="' . esc_url( $buat_url ) . '" class="button button-small" style="margin-bottom:4px;" title="Buat tagihan sesuai jalur">Buat Tagihan</a>';
                echo '<div style="font-size:11px;color:#666;">Estimasi: Rp ' . esc_html( number_format( $estimasi, 0, ',', '.' ) ) . '</div>';
                break;
            }

            $list_url = admin_url( 'edit.php?post_type=master_tagihan&psb_id=' . (int) $post_id );
            echo '<a href="' . esc_url( $list_url ) . '" title="Lihat tagihan pendaftar ini">';
            echo '<strong>Rp ' . esc_html( number_format( (int) $summary['total'], 0, ',', '.' ) ) . '</strong>';
            echo '</a>';
            echo '<div style="font-size:11px;margin-top:2px;">';
            if ( $summary['lunas'] > 0 ) {
                echo '<span style="color:#16a34a;">Lunas Rp ' . esc_html( number_format( (int) $summary['lunas'], 0, ',', '.' ) ) . '</span> ';
            }
            if ( $summary['belum'] > 0 ) {
                echo '<span style="color:#ea580c;">Belum Rp ' . esc_html( number_format( (int) $summary['belum'], 0, ',', '.' ) ) . '</span> ';
            }
            if ( $summary['ditinjau'] > 0 ) {
                echo '<span style="color:#2563eb;">Ditinjau Rp ' . esc_html( number_format( (int) $summary['ditinjau'], 0, ',', '.' ) ) . '</span>';
            }
            echo '</div>';
            echo '<div style="font-size:11px;color:#666;">';
            echo '<a href="' . esc_url( $list_url ) . '">' . (int) $summary['count'] . ' invoice</a>';
            echo ' · <a href="' . esc_url( $buat_url ) . '" title="Sinkronkan ulang tagihan jalur">Sinkron</a>';
            echo '</div>';
            break;
        case 'psb_status':
            $status = get_post_meta( $post_id, '_psb_status', true );
            if ( $status == 'Diterima' ) {
                echo '<span style="color: green; font-weight: bold;">Diterima</span>';
            } elseif ( $status == 'Ditolak' ) {
                echo '<span style="color: red; font-weight: bold;">Ditolak</span>';
            } else {
                echo '<span style="color: orange; font-weight: bold;">Menunggu</span>';
            }
            break;
    }
}

// Aksi admin: buat tagihan dari kolom Tagihan di daftar PSB
add_action( 'admin_action_qrg_buat_tagihan_psb', 'qrg_admin_buat_tagihan_psb' );
function qrg_admin_buat_tagihan_psb() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Anda tidak memiliki izin untuk membuat tagihan.' );
    }

    $psb_id = isset( $_GET['psb_id'] ) ? absint( $_GET['psb_id'] ) : 0;
    if ( ! $psb_id || get_post_type( $psb_id ) !== 'master_psb' ) {
        wp_die( 'Pendaftar tidak ditemukan.' );
    }

    check_admin_referer( 'qrg_buat_tagihan_psb_' . $psb_id );

    $jalur_id = (int) get_post_meta( $psb_id, '_psb_jalur_id', true );
    $redirect = admin_url( 'edit.php?post_type=master_psb' );

    if ( ! $jalur_id ) {
        $redirect = add_query_arg( 'qrg_tagihan_psb', 'no_jalur', $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    if ( ! function_exists( 'qrg_create_tagihan_for_psb_jalur' ) ) {
        $redirect = add_query_arg( 'qrg_tagihan_psb', 'error', $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    $biaya_items = function_exists( 'qrg_get_jalur_biaya_items' ) ? qrg_get_jalur_biaya_items( $jalur_id ) : [];
    if ( empty( $biaya_items ) ) {
        $redirect = add_query_arg( 'qrg_tagihan_psb', 'no_biaya', $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    $before = function_exists( 'qrg_get_psb_tagihan_ids' ) ? qrg_get_psb_tagihan_ids( $psb_id ) : [];
    $ids    = qrg_create_tagihan_for_psb_jalur( $psb_id, $jalur_id );
    $created = count( array_diff( $ids, $before ) );

    $redirect = add_query_arg(
        [
            'qrg_tagihan_psb' => $created > 0 ? 'created' : 'exists',
            'qrg_tagihan_n'   => max( count( $ids ), $created ),
        ],
        $redirect
    );
    wp_safe_redirect( $redirect );
    exit;
}

add_action( 'admin_notices', 'qrg_psb_tagihan_admin_notice' );
function qrg_psb_tagihan_admin_notice() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_psb' ) {
        return;
    }
    if ( empty( $_GET['qrg_tagihan_psb'] ) ) {
        return;
    }

    $status = sanitize_text_field( wp_unslash( $_GET['qrg_tagihan_psb'] ) );
    $n      = isset( $_GET['qrg_tagihan_n'] ) ? absint( $_GET['qrg_tagihan_n'] ) : 0;

    $messages = [
        'created'  => [ 'success', sprintf( 'Berhasil membuat %d tagihan untuk pendaftar sesuai jalur.', max( 1, $n ) ) ],
        'exists'   => [ 'info', sprintf( 'Tagihan jalur sudah ada (%d invoice). Tidak ada duplikat yang dibuat.', max( 1, $n ) ) ],
        'no_jalur' => [ 'error', 'Pendaftar belum memilih jalur penerimaan.' ],
        'no_biaya' => [ 'error', 'Jalur belum memiliki Jenis Biaya Tambahan. Atur dulu di Jalur Penerimaan.' ],
        'error'    => [ 'error', 'Gagal membuat tagihan. Silakan coba lagi.' ],
    ];

    if ( ! isset( $messages[ $status ] ) ) {
        return;
    }

    list( $type, $text ) = $messages[ $status ];
    printf(
        '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
        esc_attr( $type ),
        esc_html( $text )
    );
}

// Filter daftar tagihan berdasarkan pendaftar PSB
add_action( 'pre_get_posts', 'qrg_filter_tagihan_by_psb' );
function qrg_filter_tagihan_by_psb( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_tagihan' ) {
        return;
    }
    $psb_id = isset( $_GET['psb_id'] ) ? absint( $_GET['psb_id'] ) : 0;
    if ( ! $psb_id ) {
        return;
    }
    $meta_query   = (array) $query->get( 'meta_query' );
    $meta_query[] = [
        'key'   => '_tagihan_psb_id',
        'value' => (string) $psb_id,
    ];
    $query->set( 'meta_query', $meta_query );
}

// Helper: daftar label kolom default PSB
function qrg_psb_default_field_labels() {
    return [
        'nama'         => 'Nama Lengkap',
        'jalur'        => 'Jalur Penerimaan',
        'nisn'         => 'NISN',
        'asal_sekolah' => 'Asal Sekolah',
        'nilai_rata'   => 'Nilai Rata-rata Rapor',
        'tempat_lahir' => 'Tempat Lahir',
        'tgl_lahir'    => 'Tanggal Lahir',
        'alamat'       => 'Alamat Tinggal',
        'nama_ortu'    => 'Nama Orang Tua / Wali',
        'no_telp'      => 'No. Telepon / WhatsApp',
    ];
}

// Helper: pengaturan form default
function qrg_psb_default_form_settings() {
    return [
        'nama'         => [ 'show' => true, 'req' => true ],
        'jalur'        => [ 'show' => true, 'req' => true ],
        'nisn'         => [ 'show' => true, 'req' => true ],
        'asal_sekolah' => [ 'show' => true, 'req' => true ],
        'nilai_rata'   => [ 'show' => true, 'req' => false ],
        'tempat_lahir' => [ 'show' => true, 'req' => false ],
        'tgl_lahir'    => [ 'show' => true, 'req' => false ],
        'alamat'       => [ 'show' => true, 'req' => true ],
        'nama_ortu'    => [ 'show' => true, 'req' => true ],
        'no_telp'      => [ 'show' => true, 'req' => true ],
    ];
}

// Helper: daftar kolom gabungan (default + custom) sesuai urutan tersimpan
function qrg_psb_get_unified_fields() {
    // Migrasi: pastikan kolom Nama & Jalur ada di urutan form lama
    if ( ! get_option( 'qrg_psb_nama_field_migrated' ) ) {
        $labels = qrg_psb_default_field_labels();
        $order  = get_option( 'qrg_psb_field_order', array_keys( $labels ) );
        if ( ! is_array( $order ) ) {
            $order = array_keys( $labels );
        }
        if ( ! in_array( 'nama', $order, true ) ) {
            array_unshift( $order, 'nama' );
        }
        if ( ! in_array( 'jalur', $order, true ) ) {
            // Sisipkan jalur setelah nama jika ada, atau di depan
            $nama_pos = array_search( 'nama', $order, true );
            if ( $nama_pos === false ) {
                array_unshift( $order, 'jalur' );
            } else {
                array_splice( $order, $nama_pos + 1, 0, [ 'jalur' ] );
            }
        }
        update_option( 'qrg_psb_field_order', $order );

        $settings = get_option( 'qrg_psb_form_settings', qrg_psb_default_form_settings() );
        if ( is_array( $settings ) ) {
            if ( ! isset( $settings['nama'] ) ) {
                $settings['nama'] = [ 'show' => true, 'req' => true ];
            }
            if ( ! isset( $settings['jalur'] ) ) {
                $settings['jalur'] = [ 'show' => true, 'req' => true ];
            }
            update_option( 'qrg_psb_form_settings', $settings );
        }
        update_option( 'qrg_psb_nama_field_migrated', 1 );
        update_option( 'qrg_psb_jalur_field_migrated', 1 );
    } elseif ( ! get_option( 'qrg_psb_jalur_field_migrated' ) ) {
        $labels = qrg_psb_default_field_labels();
        $order  = get_option( 'qrg_psb_field_order', array_keys( $labels ) );
        if ( ! is_array( $order ) ) {
            $order = array_keys( $labels );
        }
        if ( ! in_array( 'jalur', $order, true ) ) {
            array_unshift( $order, 'jalur' );
            update_option( 'qrg_psb_field_order', $order );
        }
        $settings = get_option( 'qrg_psb_form_settings', qrg_psb_default_form_settings() );
        if ( is_array( $settings ) && ! isset( $settings['jalur'] ) ) {
            $settings['jalur'] = [ 'show' => true, 'req' => true ];
            update_option( 'qrg_psb_form_settings', $settings );
        }
        update_option( 'qrg_psb_jalur_field_migrated', 1 );
    }

    $labels   = qrg_psb_default_field_labels();
    $settings = get_option( 'qrg_psb_form_settings', qrg_psb_default_form_settings() );
    $customs  = get_option( 'qrg_psb_custom_fields', [] );
    if ( ! is_array( $customs ) ) {
        $customs = [];
    }

    $custom_map = [];
    foreach ( $customs as $cf ) {
        if ( empty( $cf['key'] ) ) {
            continue;
        }
        $opts = [];
        if ( ! empty( $cf['options'] ) && is_array( $cf['options'] ) ) {
            $opts = array_values( array_filter( array_map( 'strval', $cf['options'] ) ) );
        }
        $custom_map[ $cf['key'] ] = [
            'kind'       => 'custom',
            'key'        => $cf['key'],
            'label'      => isset( $cf['label'] ) ? $cf['label'] : $cf['key'],
            'field_type' => isset( $cf['type'] ) ? $cf['type'] : 'text',
            'options'    => $opts,
            'show'       => isset( $cf['show'] ) ? (bool) $cf['show'] : true,
            'req'        => ! empty( $cf['req'] ),
        ];
    }

    $default_keys = array_keys( $labels );
    $legacy_order = get_option( 'qrg_psb_field_order', $default_keys );
    if ( ! is_array( $legacy_order ) ) {
        $legacy_order = $default_keys;
    }

    // Migrasi: urutan lama hanya default → tambahkan custom di belakang
    $has_custom_in_order = false;
    foreach ( $legacy_order as $item ) {
        if ( is_string( $item ) && strpos( $item, 'cf:' ) === 0 ) {
            $has_custom_in_order = true;
            break;
        }
    }
    if ( ! $has_custom_in_order ) {
        foreach ( array_keys( $custom_map ) as $ck ) {
            $legacy_order[] = 'cf:' . $ck;
        }
    }

    $result   = [];
    $seen_def = [];
    $seen_cf  = [];

    foreach ( $legacy_order as $item ) {
        if ( ! is_string( $item ) || $item === '' ) {
            continue;
        }
        if ( strpos( $item, 'cf:' ) === 0 ) {
            $ck = substr( $item, 3 );
            if ( isset( $custom_map[ $ck ] ) && ! isset( $seen_cf[ $ck ] ) ) {
                $result[]       = $custom_map[ $ck ];
                $seen_cf[ $ck ] = true;
            }
            continue;
        }
        if ( isset( $labels[ $item ] ) && ! isset( $seen_def[ $item ] ) ) {
            $custom_label = ! empty( $settings[ $item ]['label'] ) ? $settings[ $item ]['label'] : $labels[ $item ];
            $result[] = [
                'kind'       => 'default',
                'key'        => $item,
                'label'      => $custom_label,
                'field_type' => qrg_psb_get_field_type( $item, $settings ),
                'options'    => qrg_psb_get_field_options( $item, $settings ),
                'show'       => ! empty( $settings[ $item ]['show'] ),
                'req'        => ! empty( $settings[ $item ]['req'] ),
            ];
            $seen_def[ $item ] = true;
        }
    }

    // Custom yang belum ada di urutan (migrasi) ditambahkan di belakang
    foreach ( $custom_map as $ck => $cf ) {
        if ( ! isset( $seen_cf[ $ck ] ) ) {
            $result[] = $cf;
        }
    }

    return $result;
}

// Helper: tipe kolom default (fallback bawaan)
function qrg_psb_default_field_type( $key ) {
    if ( $key === 'alamat' ) {
        return 'textarea';
    }
    if ( $key === 'nilai_rata' ) {
        return 'number';
    }
    if ( $key === 'tgl_lahir' ) {
        return 'date';
    }
    if ( $key === 'jalur' ) {
        return 'select';
    }
    return 'text';
}

/**
 * Tipe field yang diizinkan.
 *
 * @return string[]
 */
function qrg_psb_allowed_field_types() {
    return [ 'text', 'textarea', 'number', 'date', 'select' ];
}

/**
 * Ambil tipe field tersimpan (atau fallback default).
 *
 * @param string     $key      Kunci field.
 * @param array|null $settings Pengaturan form.
 * @return string
 */
function qrg_psb_get_field_type( $key, $settings = null ) {
    if ( $key === 'jalur' ) {
        return 'select';
    }
    if ( $settings === null ) {
        $settings = get_option( 'qrg_psb_form_settings', qrg_psb_default_form_settings() );
    }
    $allowed = qrg_psb_allowed_field_types();
    if ( ! empty( $settings[ $key ]['type'] ) && in_array( $settings[ $key ]['type'], $allowed, true ) ) {
        return $settings[ $key ]['type'];
    }
    return qrg_psb_default_field_type( $key );
}

/**
 * Ambil opsi pilihan tersimpan untuk field default.
 *
 * @param string     $key      Kunci field.
 * @param array|null $settings Pengaturan form.
 * @return string[]
 */
function qrg_psb_get_field_options( $key, $settings = null ) {
    if ( $settings === null ) {
        $settings = get_option( 'qrg_psb_form_settings', qrg_psb_default_form_settings() );
    }
    if ( empty( $settings[ $key ]['options'] ) || ! is_array( $settings[ $key ]['options'] ) ) {
        return [];
    }
    return array_values( array_filter( array_map( 'strval', $settings[ $key ]['options'] ) ) );
}

/**
 * Ambil jalur yang dipilih untuk form PSB (hanya yang aktif & dicentang di pengaturan).
 */
function qrg_psb_get_jalur_pilihan() {
    if ( ! function_exists( 'qrg_get_daftar_jalur' ) ) {
        return [];
    }

    $semua   = qrg_get_daftar_jalur( true );
    $enabled = get_option( 'qrg_psb_jalur_pilihan', null );

    // Belum pernah diset → tampilkan semua jalur aktif
    if ( ! is_array( $enabled ) ) {
        return $semua;
    }

    $enabled = array_map( 'absint', $enabled );
    $enabled = array_filter( $enabled );

    return array_values( array_filter( $semua, function( $jalur ) use ( $enabled ) {
        return in_array( (int) $jalur->ID, $enabled, true );
    } ) );
}

// Helper: render satu field default di form publik
function qrg_psb_render_public_field( $key, $settings ) {
    if ( empty( $settings[ $key ]['show'] ) ) {
        return;
    }
    $labels   = qrg_psb_default_field_labels();
    $label    = ! empty( $settings[ $key ]['label'] ) ? $settings[ $key ]['label'] : ( isset( $labels[ $key ] ) ? $labels[ $key ] : $key );
    $required = ! empty( $settings[ $key ]['req'] );
    $req_mark = $required ? ' *' : '';
    $req_attr = $required ? 'required' : '';
    $style    = 'width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;';
    $type     = qrg_psb_get_field_type( $key, $settings );
    $name     = ( $key === 'nama' ) ? 'psb_nama' : ( 'psb_' . $key );

    echo '<div style="margin-bottom: 15px;">';
    echo '<label style="display: block; font-weight: bold; margin-bottom: 5px; color:#444;">' . esc_html( $label ) . esc_html( $req_mark ) . '</label>';

    if ( $key === 'jalur' ) {
        $daftar_jalur = qrg_psb_get_jalur_pilihan();
        echo '<select name="psb_jalur_id" id="qrg_psb_jalur_id" ' . $req_attr . ' style="' . esc_attr( $style ) . '">';
        echo '<option value="">— Pilih Jalur Penerimaan —</option>';
        foreach ( $daftar_jalur as $jalur ) {
            echo '<option value="' . esc_attr( $jalur->ID ) . '">' . esc_html( $jalur->post_title ) . '</option>';
        }
        echo '</select>';
    } elseif ( $type === 'textarea' ) {
        echo '<textarea name="' . esc_attr( $name ) . '" rows="3" ' . $req_attr . ' style="' . esc_attr( $style ) . '"></textarea>';
    } elseif ( $type === 'number' ) {
        $step = ( $key === 'nilai_rata' ) ? ' step="0.01"' : '';
        echo '<input type="number"' . $step . ' name="' . esc_attr( $name ) . '" ' . $req_attr . ' style="' . esc_attr( $style ) . '">';
    } elseif ( $type === 'date' ) {
        echo '<input type="date" name="' . esc_attr( $name ) . '" ' . $req_attr . ' style="' . esc_attr( $style ) . '">';
    } elseif ( $type === 'select' ) {
        $options = qrg_psb_get_field_options( $key, $settings );
        echo '<select name="' . esc_attr( $name ) . '" ' . $req_attr . ' style="' . esc_attr( $style ) . '">';
        echo '<option value="">— Pilih —</option>';
        foreach ( $options as $opt ) {
            echo '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input type="text" name="' . esc_attr( $name ) . '" ' . $req_attr . ' style="' . esc_attr( $style ) . '">';
    }

    echo '</div>';
}

/**
 * Parse daftar opsi pilihan dari teks (satu baris = satu opsi).
 *
 * @param string $raw Teks mentah.
 * @return string[]
 */
function qrg_psb_parse_options_text( $raw ) {
    $raw = (string) $raw;
    $lines = preg_split( '/\r\n|\r|\n/', $raw );
    $out   = [];
    foreach ( $lines as $line ) {
        $line = sanitize_text_field( trim( $line ) );
        if ( $line !== '' ) {
            $out[] = $line;
        }
    }
    return array_values( array_unique( $out ) );
}

/**
 * Sanitasi nilai custom field PSB sesuai tipenya.
 *
 * @param array  $cf    Definisi field.
 * @param string $raw   Nilai mentah.
 * @return string
 */
function qrg_psb_sanitize_custom_field_value( $cf, $raw ) {
    $type = isset( $cf['type'] ) ? $cf['type'] : ( isset( $cf['field_type'] ) ? $cf['field_type'] : 'text' );
    if ( $type === 'textarea' ) {
        return sanitize_textarea_field( $raw );
    }
    $value = sanitize_text_field( $raw );
    if ( $type === 'select' ) {
        $options = ! empty( $cf['options'] ) && is_array( $cf['options'] ) ? $cf['options'] : [];
        if ( $value !== '' && ! in_array( $value, $options, true ) ) {
            return '';
        }
    }
    return $value;
}

// Helper: render custom field di form publik
function qrg_psb_render_public_custom_field( $cf ) {
    if ( isset( $cf['show'] ) && ! $cf['show'] ) {
        return;
    }
    $req_mark = ! empty( $cf['req'] ) ? ' *' : '';
    $req_attr = ! empty( $cf['req'] ) ? 'required' : '';
    $style    = 'width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;';
    $name     = 'psb_cf_' . $cf['key'];

    echo '<div style="margin-bottom: 15px;">';
    echo '<label style="display: block; font-weight: bold; margin-bottom: 5px; color:#444;">' . esc_html( $cf['label'] ) . esc_html( $req_mark ) . '</label>';

    if ( $cf['field_type'] === 'textarea' ) {
        echo '<textarea name="' . esc_attr( $name ) . '" rows="3" ' . $req_attr . ' style="' . esc_attr( $style ) . '"></textarea>';
    } elseif ( $cf['field_type'] === 'number' ) {
        echo '<input type="number" name="' . esc_attr( $name ) . '" ' . $req_attr . ' style="' . esc_attr( $style ) . '">';
    } elseif ( $cf['field_type'] === 'date' ) {
        echo '<input type="date" name="' . esc_attr( $name ) . '" ' . $req_attr . ' style="' . esc_attr( $style ) . '">';
    } elseif ( $cf['field_type'] === 'select' ) {
        $options = ! empty( $cf['options'] ) && is_array( $cf['options'] ) ? $cf['options'] : [];
        echo '<select name="' . esc_attr( $name ) . '" ' . $req_attr . ' style="' . esc_attr( $style ) . '">';
        echo '<option value="">— Pilih —</option>';
        foreach ( $options as $opt ) {
            echo '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input type="text" name="' . esc_attr( $name ) . '" ' . $req_attr . ' style="' . esc_attr( $style ) . '">';
    }
    echo '</div>';
}

// Enqueue sortable di halaman pengaturan form PSB
add_action( 'admin_enqueue_scripts', 'qrg_psb_settings_enqueue_scripts' );
function qrg_psb_settings_enqueue_scripts( $hook ) {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'psb-form-settings' ) {
        return;
    }
    wp_enqueue_script( 'jquery-ui-sortable' );
}

// 6. Shortcode Form Publik [form_pendaftaran_psb]
add_shortcode( 'form_pendaftaran_psb', 'qrg_psb_registration_form_shortcode' );
function qrg_psb_registration_form_shortcode() {
    ob_start();
    $success_message = '';
    
    $settings = get_option( 'qrg_psb_form_settings', qrg_psb_default_form_settings() );
    
    // Proses form submit
    if ( isset( $_POST['submit_psb_public'] ) && wp_verify_nonce( $_POST['qrg_psb_public_nonce'], 'submit_psb_form' ) ) {

        // Honeypot: bot biasanya mengisi field tersembunyi
        $honeypot = isset( $_POST['psb_website'] ) ? trim( (string) wp_unslash( $_POST['psb_website'] ) ) : '';
        if ( $honeypot !== '' ) {
            $success_message = '<div style="background-color: #d4edda; color: #155724; padding: 20px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb;"><p style="margin:0;">Pendaftaran diterima.</p></div>';
            echo $success_message;
            return ob_get_clean();
        }

        if ( function_exists( 'qrg_rate_limit_check' ) ) {
            $rl = qrg_rate_limit_check( 'psb_form', 5, 900 );
            if ( is_wp_error( $rl ) ) {
                $success_message = '<div style="color:#721c24;background:#f8d7da;padding:15px;border:1px solid #f5c6cb;border-radius:5px;margin-bottom:20px;">' . esc_html( $rl->get_error_message() ) . '</div>';
                echo $success_message;
                return ob_get_clean();
            }
            qrg_rate_limit_hit( 'psb_form', 900 );
        }
        
        $nama          = sanitize_text_field( isset( $_POST['psb_nama'] ) ? $_POST['psb_nama'] : '' );
        if ( $nama === '' ) {
            $nama = 'Pendaftar Baru';
        }
        $nisn          = sanitize_text_field( isset( $_POST['psb_nisn'] ) ? $_POST['psb_nisn'] : '' );
        $asal_sekolah  = sanitize_text_field( isset( $_POST['psb_asal_sekolah'] ) ? $_POST['psb_asal_sekolah'] : '' );
        $nilai_rata    = sanitize_text_field( isset( $_POST['psb_nilai_rata'] ) ? $_POST['psb_nilai_rata'] : '' );
        $alamat        = sanitize_textarea_field( isset( $_POST['psb_alamat'] ) ? $_POST['psb_alamat'] : '' );
        $tempat_lahir  = sanitize_text_field( isset( $_POST['psb_tempat_lahir'] ) ? $_POST['psb_tempat_lahir'] : '' );
        $tgl_lahir     = sanitize_text_field( isset( $_POST['psb_tgl_lahir'] ) ? $_POST['psb_tgl_lahir'] : '' );
        $nama_ortu     = sanitize_text_field( isset( $_POST['psb_nama_ortu'] ) ? $_POST['psb_nama_ortu'] : '' );
        $no_telp       = sanitize_text_field( isset( $_POST['psb_no_telp'] ) ? $_POST['psb_no_telp'] : '' );
        $jalur_id      = isset( $_POST['psb_jalur_id'] ) ? absint( $_POST['psb_jalur_id'] ) : 0;
        
        // Generate Token registrasi (bukan token invoice)
        $token = 'PSB-' . date( 'Y' ) . '-' . wp_generate_password( 5, false, false );
        
        $post_data = [
            'post_title'  => $nama,
            'post_type'   => 'master_psb',
            'post_status' => 'publish',
        ];
        
        $post_id = wp_insert_post( $post_data );
        
        if ( ! is_wp_error( $post_id ) ) {
            update_post_meta( $post_id, '_psb_nisn', $nisn );
            update_post_meta( $post_id, '_psb_asal_sekolah', $asal_sekolah );
            update_post_meta( $post_id, '_psb_nilai_rata', $nilai_rata );
            update_post_meta( $post_id, '_psb_alamat', $alamat );
            update_post_meta( $post_id, '_psb_tempat_lahir', $tempat_lahir );
            update_post_meta( $post_id, '_psb_tgl_lahir', $tgl_lahir );
            update_post_meta( $post_id, '_psb_nama_ortu', $nama_ortu );
            update_post_meta( $post_id, '_psb_no_telp', $no_telp );
            update_post_meta( $post_id, '_psb_jalur_id', $jalur_id );
            update_post_meta( $post_id, '_psb_status', 'Menunggu' );
            update_post_meta( $post_id, '_psb_token', $token );
            
            // Simpan Custom Fields dari form publik
            $custom_fields = get_option( 'qrg_psb_custom_fields', [] );
            if ( ! empty( $custom_fields ) ) {
                foreach ( $custom_fields as $cf ) {
                    $post_key = 'psb_cf_' . $cf['key'];
                    $meta_key = '_psb_cf_' . $cf['key'];
                    if ( isset( $_POST[ $post_key ] ) ) {
                        $value = qrg_psb_sanitize_custom_field_value( $cf, wp_unslash( $_POST[ $post_key ] ) );
                        update_post_meta( $post_id, $meta_key, $value );
                    }
                }
            }

            // Buat tagihan sesuai jalur yang dipilih
            $tagihan_ids = [];
            if ( $jalur_id && function_exists( 'qrg_create_tagihan_for_psb_jalur' ) ) {
                $tagihan_ids = qrg_create_tagihan_for_psb_jalur( $post_id, $jalur_id );
            }
            
            $success_message = '<div style="background-color: #d4edda; color: #155724; padding: 20px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb;">';
            $success_message .= '<h3 style="margin-top: 0;">Pendaftaran Berhasil!</h3>';
            $success_message .= '<p>Terima kasih telah mendaftar. Berikut adalah Nomor Registrasi (Token) Anda:</p>';
            $success_message .= '<h2 style="font-size: 24px; font-weight: bold; margin: 10px 0; color: #000;">' . esc_html( $token ) . '</h2>';
            $success_message .= '<p style="margin-bottom: 0;">Mohon simpan nomor ini dengan baik untuk keperluan pengecekan status.</p>';

            if ( ! empty( $tagihan_ids ) ) {
                $success_message .= '<hr style="border:0;border-top:1px solid #c3e6cb;margin:16px 0;">';
                $success_message .= '<h4 style="margin:0 0 10px;">Tagihan Jalur Penerimaan</h4>';
                $success_message .= '<p style="margin:0 0 10px;">Silakan selesaikan pembayaran berikut sesuai jalur yang Anda pilih:</p>';
                $success_message .= '<ul style="margin:0;padding-left:18px;">';
                $total_tagihan = 0;
                foreach ( $tagihan_ids as $tid ) {
                    $nominal = (int) get_post_meta( $tid, '_tagihan_nominal', true );
                    $total_tagihan += $nominal;
                    $pay_url = function_exists( 'qrg_get_tagihan_pay_url' )
                        ? qrg_get_tagihan_pay_url( $tid )
                        : get_permalink( $tid );
                    $success_message .= '<li style="margin-bottom:8px;">';
                    $success_message .= '<strong>' . esc_html( get_the_title( $tid ) ) . '</strong> — Rp ' . esc_html( number_format( $nominal, 0, ',', '.' ) );
                    if ( $pay_url ) {
                        $success_message .= '<br><a href="' . esc_url( $pay_url ) . '" style="color:#0b5;font-weight:bold;" target="_blank" rel="noopener">Bayar sekarang →</a>';
                    }
                    $success_message .= '</li>';
                }
                $success_message .= '</ul>';
                $success_message .= '<p style="margin:12px 0 0;"><strong>Total: Rp ' . esc_html( number_format( $total_tagihan, 0, ',', '.' ) ) . '</strong></p>';
            }

            $success_message .= '</div>';
        } else {
            $success_message = '<div style="color: red; padding: 10px; border: 1px solid red; margin-bottom: 20px;">Terjadi kesalahan saat menyimpan data. Silakan coba lagi.</div>';
        }
    }
    
    // Tampilkan pesan sukses jika ada
    if ( ! empty( $success_message ) ) {
        echo $success_message;
        return ob_get_clean();
    }
    
    // Tampilkan Form
    ?>
    <div class="qrg-psb-form-wrapper" style="max-width: 600px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); font-family: sans-serif;">
        <h2 style="margin-top: 0; margin-bottom: 20px; text-align: center; color: #333;">Formulir Penerimaan Siswa Baru</h2>
        <form method="post" action="">
            <?php wp_nonce_field( 'submit_psb_form', 'qrg_psb_public_nonce' ); ?>
            <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                <label for="psb_website">Website</label>
                <input type="text" name="psb_website" id="psb_website" value="" tabindex="-1" autocomplete="off">
            </div>
            
            <?php
            foreach ( qrg_psb_get_unified_fields() as $field ) {
                if ( $field['kind'] === 'default' ) {
                    qrg_psb_render_public_field( $field['key'], $settings );
                } else {
                    qrg_psb_render_public_custom_field( $field );
                }
            }
            ?>
            
            <button type="submit" name="submit_psb_public" style="width: 100%; background: #0073aa; color: #fff; padding: 12px; border: none; border-radius: 4px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.3s;" onmouseover="this.style.background='#005a87';" onmouseout="this.style.background='#0073aa';">Kirim Pendaftaran</button>
        </form>
    </div>
    <?php
    
    return ob_get_clean();
}

// 7. Halaman Pengaturan Form PSB (halaman tersembunyi, akses via tombol)
add_action( 'admin_menu', 'qrg_psb_settings_menu', 20 );
function qrg_psb_settings_menu() {
    add_submenu_page(
        null,
        'Pengaturan Form PSB',
        'Pengaturan Form',
        'manage_options',
        'psb-form-settings',
        'qrg_psb_settings_page_callback'
    );
}

// Saat di Pengaturan Form, tandai menu Penerimaan Siswa sebagai aktif
add_filter( 'parent_file', 'qrg_psb_settings_parent_file' );
function qrg_psb_settings_parent_file( $parent_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'psb-form-settings' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_psb_settings_submenu_file' );
function qrg_psb_settings_submenu_file( $submenu_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'psb-form-settings' ) {
        return 'edit.php?post_type=master_psb';
    }
    return $submenu_file;
}

// Tombol kembali di halaman Pengaturan Form
add_action( 'admin_footer', 'qrg_psb_settings_back_button' );
function qrg_psb_settings_back_button() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'psb-form-settings' ) {
        return;
    }
    $url = admin_url( 'edit.php?post_type=master_psb' );
    ?>
    <script>
    jQuery(function($) {
        var $h1 = $('.wrap h1').first();
        if (!$h1.length) {
            return;
        }
        $('<a href="<?php echo esc_url( $url ); ?>" class="page-title-action">← Penerimaan Siswa</a>')
            .insertAfter($h1);
    });
    </script>
    <?php
}

function qrg_psb_settings_page_callback() {
    $default_labels = qrg_psb_default_field_labels();

    if ( isset( $_POST['qrg_save_psb_settings'] ) && wp_verify_nonce( $_POST['qrg_psb_settings_nonce'], 'qrg_psb_settings_action' ) ) {
        $kinds  = isset( $_POST['psb_row_kind'] ) && is_array( $_POST['psb_row_kind'] ) ? $_POST['psb_row_kind'] : [];
        $keys   = isset( $_POST['psb_row_key'] ) && is_array( $_POST['psb_row_key'] ) ? $_POST['psb_row_key'] : [];
        $labels = isset( $_POST['psb_row_label'] ) && is_array( $_POST['psb_row_label'] ) ? $_POST['psb_row_label'] : [];
        $types  = isset( $_POST['psb_row_type'] ) && is_array( $_POST['psb_row_type'] ) ? $_POST['psb_row_type'] : [];
        $shows  = isset( $_POST['psb_row_show'] ) && is_array( $_POST['psb_row_show'] ) ? $_POST['psb_row_show'] : [];
        $reqs   = isset( $_POST['psb_row_req'] ) && is_array( $_POST['psb_row_req'] ) ? $_POST['psb_row_req'] : [];
        $opts_raw = isset( $_POST['psb_row_options'] ) && is_array( $_POST['psb_row_options'] ) ? $_POST['psb_row_options'] : [];

        $old_settings  = get_option( 'qrg_psb_form_settings', qrg_psb_default_form_settings() );
        if ( ! is_array( $old_settings ) ) {
            $old_settings = [];
        }
        $settings      = qrg_psb_default_form_settings();
        $custom_fields = [];
        $order         = [];
        $used_cf_keys  = [];
        $allowed_types = qrg_psb_allowed_field_types();

        foreach ( $kinds as $i => $kind ) {
            $kind = sanitize_text_field( $kind );
            $key  = isset( $keys[ $i ] ) ? sanitize_text_field( $keys[ $i ] ) : '';
            $show = ! empty( $shows[ $i ] );
            $req  = ! empty( $reqs[ $i ] );

            if ( $kind === 'default' ) {
                if ( ! isset( $default_labels[ $key ] ) ) {
                    continue;
                }
                $label = isset( $labels[ $i ] ) ? sanitize_text_field( $labels[ $i ] ) : '';
                if ( $label === '' ) {
                    $label = $default_labels[ $key ];
                }
                if ( $key === 'jalur' ) {
                    $type = 'select';
                } else {
                    $type = isset( $types[ $i ] ) ? sanitize_text_field( $types[ $i ] ) : qrg_psb_default_field_type( $key );
                    if ( ! in_array( $type, $allowed_types, true ) ) {
                        $type = qrg_psb_default_field_type( $key );
                    }
                }
                $settings[ $key ] = [
                    'show'  => $show,
                    'req'   => $req,
                    'label' => $label,
                    'type'  => $type,
                ];
                if ( $type === 'select' && $key !== 'jalur' ) {
                    $opt_text = isset( $opts_raw[ $i ] ) ? wp_unslash( $opts_raw[ $i ] ) : '';
                    $settings[ $key ]['options'] = qrg_psb_parse_options_text( $opt_text );
                }
                $order[] = $key;
                continue;
            }

            // Custom field
            $label = isset( $labels[ $i ] ) ? sanitize_text_field( $labels[ $i ] ) : '';
            if ( $label === '' ) {
                continue;
            }
            $type = isset( $types[ $i ] ) ? sanitize_text_field( $types[ $i ] ) : 'text';
            if ( ! in_array( $type, $allowed_types, true ) ) {
                $type = 'text';
            }

            $cf_key = ( $key !== '' ) ? sanitize_title( $key ) : sanitize_title( $label );
            if ( $cf_key === '' ) {
                $cf_key = 'field_' . ( $i + 1 );
            }
            // Pastikan unik
            $base = $cf_key;
            $n    = 2;
            while ( isset( $used_cf_keys[ $cf_key ] ) ) {
                $cf_key = $base . '-' . $n;
                $n++;
            }
            $used_cf_keys[ $cf_key ] = true;

            $cf_item = [
                'key'   => $cf_key,
                'label' => $label,
                'type'  => $type,
                'show'  => $show,
                'req'   => $req,
            ];
            if ( $type === 'select' ) {
                $opt_text = isset( $opts_raw[ $i ] ) ? wp_unslash( $opts_raw[ $i ] ) : '';
                $cf_item['options'] = qrg_psb_parse_options_text( $opt_text );
            }
            $custom_fields[] = $cf_item;
            $order[] = 'cf:' . $cf_key;
        }

        // Kolom default yang dihapus dari daftar → sembunyikan (pertahankan label/tipe jika ada)
        foreach ( array_keys( $default_labels ) as $dk ) {
            if ( ! in_array( $dk, $order, true ) ) {
                $old = isset( $old_settings[ $dk ] ) && is_array( $old_settings[ $dk ] ) ? $old_settings[ $dk ] : [];
                $old_label = ! empty( $old['label'] ) ? $old['label'] : $default_labels[ $dk ];
                $old_type  = ! empty( $old['type'] ) ? $old['type'] : qrg_psb_default_field_type( $dk );
                $settings[ $dk ] = [
                    'show'  => false,
                    'req'   => false,
                    'label' => $old_label,
                    'type'  => $old_type,
                ];
                if ( ! empty( $old['options'] ) && is_array( $old['options'] ) ) {
                    $settings[ $dk ]['options'] = $old['options'];
                }
            }
        }

        update_option( 'qrg_psb_form_settings', $settings );
        update_option( 'qrg_psb_custom_fields', $custom_fields );
        update_option( 'qrg_psb_field_order', $order );

        // Simpan pilihan jalur yang ditampilkan di form
        $jalur_pilihan = [];
        if ( isset( $_POST['psb_jalur_pilihan'] ) && is_array( $_POST['psb_jalur_pilihan'] ) ) {
            foreach ( $_POST['psb_jalur_pilihan'] as $jid ) {
                $jid = absint( $jid );
                if ( $jid > 0 ) {
                    $jalur_pilihan[] = $jid;
                }
            }
        }
        update_option( 'qrg_psb_jalur_pilihan', array_values( array_unique( $jalur_pilihan ) ) );

        echo '<div class="notice notice-success is-dismissible"><p>Pengaturan form berhasil disimpan.</p></div>';
    }

    $unified         = qrg_psb_get_unified_fields();
    $daftar_jalur_all = function_exists( 'qrg_get_daftar_jalur' ) ? qrg_get_daftar_jalur( true ) : [];
    $jalur_enabled   = get_option( 'qrg_psb_jalur_pilihan', null );
    if ( ! is_array( $jalur_enabled ) ) {
        // Default: semua jalur aktif tercentang
        $jalur_enabled = array_map( function( $j ) {
            return (int) $j->ID;
        }, $daftar_jalur_all );
    } else {
        $jalur_enabled = array_map( 'absint', $jalur_enabled );
    }

    ?>
    <div class="wrap qrg-psb-settings-wrap">
        <h1>Pengaturan Form Penerimaan Siswa Baru</h1>
        <p class="qrg-psb-settings-desc">Atur nama kolom, urutan, dan pilihan jalur yang tampil di formulir publik. Geser ikon <span class="dashicons dashicons-menu" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span> untuk mengubah urutan.</p>
        <form method="post" action="">
            <?php wp_nonce_field( 'qrg_psb_settings_action', 'qrg_psb_settings_nonce' ); ?>
            <style>
                .qrg-psb-settings-wrap { max-width: 1100px; }
                .qrg-psb-settings-desc { color: #50575e; margin: 8px 0 18px; }
                #psb-fields-table {
                    border: 1px solid #c3c4c7;
                    border-radius: 6px;
                    overflow: hidden;
                    border-spacing: 0;
                    background: #fff;
                }
                #psb-fields-table thead th {
                    background: #f0f0f1;
                    font-weight: 600;
                    padding: 12px 14px;
                    border-bottom: 1px solid #c3c4c7;
                    vertical-align: middle;
                }
                #psb-fields-table td {
                    padding: 14px;
                    vertical-align: middle;
                    border-bottom: 1px solid #e2e4e7;
                }
                #psb-fields-table tbody tr:last-child td { border-bottom: none; }
                #psb-fields-sortable tr { cursor: default; }
                #psb-fields-sortable tr.ui-sortable-helper {
                    background: #fff;
                    box-shadow: 0 4px 14px rgba(0,0,0,0.12);
                    display: table;
                }
                #psb-fields-sortable tr.ui-sortable-placeholder {
                    visibility: visible !important;
                    background: #eef5fb;
                    height: 56px;
                }
                .psb-drag-handle {
                    width: 36px;
                    cursor: move;
                    color: #8c8f94;
                    text-align: center;
                }
                .psb-drag-handle:hover { color: #2271b1; }
                .psb-drag-handle .dashicons { margin-top: 2px; }
                tr.psb-field-fixed td { background: #f8f9fa; }
                .psb-col-name strong { font-size: 13px; color: #1d2327; }
                .psb-type-badge {
                    display: inline-block;
                    padding: 3px 10px;
                    border-radius: 999px;
                    background: #eef2f7;
                    color: #3c434a;
                    font-size: 12px;
                    font-weight: 500;
                    line-height: 1.4;
                }
                .psb-type-badge.is-select { background: #e7f3ff; color: #0a4b78; }
                .psb-jalur-pilihan {
                    margin-top: 10px;
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                }
                .psb-jalur-pilihan label {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    margin: 0;
                    padding: 6px 12px;
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-radius: 999px;
                    font-size: 12px;
                    font-weight: 500;
                    color: #1d2327;
                    line-height: 1.3;
                    cursor: pointer;
                    transition: border-color .15s, background .15s;
                }
                .psb-jalur-pilihan label:hover {
                    border-color: #2271b1;
                    background: #f0f6fc;
                }
                .psb-jalur-pilihan input[type="checkbox"] {
                    margin: 0;
                }
                .psb-jalur-empty {
                    margin: 8px 0 0;
                    color: #646970;
                    font-size: 12px;
                    font-style: italic;
                }
                .psb-col-check,
                .psb-col-action {
                    text-align: center;
                    width: 110px;
                }
                .psb-col-check .psb-check-wrap {
                    display: inline-flex;
                    flex-direction: column;
                    align-items: center;
                    gap: 4px;
                }
                .psb-col-check .psb-check-note {
                    font-size: 11px;
                    color: #646970;
                }
                .psb-col-action .button {
                    min-width: 72px;
                }
                .qrg-psb-settings-actions {
                    margin-top: 16px;
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                    align-items: center;
                }
                #psb-restore-wrap {
                    display: none;
                    align-items: center;
                    gap: 8px;
                }
                #psb-fields-table select,
                #psb-fields-table input[type="text"],
                #psb-fields-table textarea {
                    max-width: 100%;
                }
                .psb-options-wrap {
                    margin-top: 8px;
                }
                .psb-options-wrap textarea {
                    width: 100%;
                    min-height: 72px;
                    font-size: 12px;
                    line-height: 1.4;
                }
                .psb-options-wrap .description {
                    margin: 4px 0 0;
                    font-size: 11px;
                    color: #646970;
                }
                tr.psb-field-row[data-key="jalur"] td {
                    vertical-align: top;
                    padding-top: 16px;
                    padding-bottom: 16px;
                }
                tr.psb-field-row[data-key="jalur"] .psb-drag-handle,
                tr.psb-field-row[data-key="jalur"] .psb-col-check,
                tr.psb-field-row[data-key="jalur"] .psb-col-action {
                    padding-top: 18px;
                }
            </style>
            <table class="wp-list-table widefat fixed" id="psb-fields-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"></th>
                        <th style="width: 22%;">Nama Kolom</th>
                        <th>Tipe / Pilihan</th>
                        <th class="psb-col-check">Tampilkan</th>
                        <th class="psb-col-check">Wajib</th>
                        <th class="psb-col-action">Aksi</th>
                    </tr>
                </thead>
                <tbody id="psb-fields-sortable">
                    <?php foreach ( $unified as $field ) :
                        $is_custom = ( $field['kind'] === 'custom' );
                        $is_jalur  = ( ! $is_custom && $field['key'] === 'jalur' );
                        ?>
                    <tr class="psb-field-row" data-kind="<?php echo esc_attr( $field['kind'] ); ?>" data-key="<?php echo esc_attr( $field['key'] ); ?>">
                        <td class="psb-drag-handle" title="Geser untuk mengubah urutan">
                            <span class="dashicons dashicons-menu"></span>
                        </td>
                        <td class="psb-col-name">
                            <input type="hidden" name="psb_row_kind[]" value="<?php echo esc_attr( $field['kind'] ); ?>">
                            <input type="hidden" name="psb_row_key[]" value="<?php echo esc_attr( $field['key'] ); ?>">
                            <input type="text" name="psb_row_label[]" value="<?php echo esc_attr( $field['label'] ); ?>" required style="width: 100%;" placeholder="Nama kolom">
                        </td>
                        <td>
                            <?php if ( $is_jalur ) : ?>
                                <input type="hidden" name="psb_row_type[]" value="select">
                                <input type="hidden" name="psb_row_options[]" value="">
                                <span class="psb-type-badge is-select">Pilihan</span>
                                <?php if ( empty( $daftar_jalur_all ) ) : ?>
                                    <p class="psb-jalur-empty">Belum ada jalur aktif. <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=master_jalur' ) ); ?>">Tambah jalur</a></p>
                                <?php else : ?>
                                    <div class="psb-jalur-pilihan">
                                        <?php foreach ( $daftar_jalur_all as $jalur ) : ?>
                                            <label>
                                                <input type="checkbox" name="psb_jalur_pilihan[]" value="<?php echo esc_attr( $jalur->ID ); ?>" <?php checked( in_array( (int) $jalur->ID, $jalur_enabled, true ) ); ?>>
                                                <?php echo esc_html( $jalur->post_title ); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else :
                                $row_options = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
                                $row_options_text = implode( "\n", $row_options );
                                $is_select_type = ( $field['field_type'] === 'select' );
                                ?>
                                <select name="psb_row_type[]" class="psb-row-type-select" style="width: 100%; max-width: 220px;">
                                    <option value="text" <?php selected( $field['field_type'], 'text' ); ?>>Teks Singkat</option>
                                    <option value="textarea" <?php selected( $field['field_type'], 'textarea' ); ?>>Paragraf</option>
                                    <option value="number" <?php selected( $field['field_type'], 'number' ); ?>>Angka</option>
                                    <option value="date" <?php selected( $field['field_type'], 'date' ); ?>>Tanggal</option>
                                    <option value="select" <?php selected( $field['field_type'], 'select' ); ?>>Pilihan</option>
                                </select>
                                <div class="psb-options-wrap" style="<?php echo $is_select_type ? '' : 'display:none;'; ?>">
                                    <textarea name="psb_row_options[]" rows="3" placeholder="Satu opsi per baris"><?php echo esc_textarea( $row_options_text ); ?></textarea>
                                    <p class="description">Isi daftar pilihan, satu opsi per baris.</p>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="psb-col-check">
                            <span class="psb-check-wrap">
                                <input type="hidden" name="psb_row_show[]" value="<?php echo $field['show'] ? '1' : '0'; ?>">
                                <input type="checkbox" value="1" <?php checked( $field['show'] ); ?> onchange="this.previousElementSibling.value=this.checked?'1':'0';">
                            </span>
                        </td>
                        <td class="psb-col-check">
                            <span class="psb-check-wrap">
                                <input type="hidden" name="psb_row_req[]" value="<?php echo $field['req'] ? '1' : '0'; ?>">
                                <input type="checkbox" value="1" <?php checked( $field['req'] ); ?> onchange="this.previousElementSibling.value=this.checked?'1':'0';">
                            </span>
                        </td>
                        <td class="psb-col-action">
                            <button type="button" class="button remove-field-row">Hapus</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="qrg-psb-settings-actions">
                <button type="button" id="add-custom-field" class="button">Tambah Kolom Baru</button>
                <span id="psb-restore-wrap">
                    <select id="psb-restore-default"></select>
                    <button type="button" id="psb-restore-btn" class="button">Pulihkan Kolom</button>
                </span>
            </div>
            <script>
                jQuery(function($) {
                    var defaultFields = <?php
                        $defaults_js = [];
                        $type_labels = [
                            'text'     => 'Teks Singkat',
                            'textarea' => 'Paragraf',
                            'number'   => 'Angka',
                            'date'     => 'Tanggal',
                            'select'   => 'Pilihan',
                        ];
                        $saved_settings = get_option( 'qrg_psb_form_settings', qrg_psb_default_form_settings() );
                        foreach ( $default_labels as $dk => $dl ) {
                            $ft = qrg_psb_get_field_type( $dk, $saved_settings );
                            $saved_label = ( ! empty( $saved_settings[ $dk ]['label'] ) ) ? $saved_settings[ $dk ]['label'] : $dl;
                            $defaults_js[] = [
                                'key'        => $dk,
                                'label'      => $saved_label,
                                'field_type' => $ft,
                                'options'    => qrg_psb_get_field_options( $dk, $saved_settings ),
                                'type_label' => isset( $type_labels[ $ft ] ) ? $type_labels[ $ft ] : $ft,
                            ];
                        }
                        echo wp_json_encode( $defaults_js );
                    ?>;
                    var jalurOptions = <?php
                        $jalur_js = [];
                        foreach ( $daftar_jalur_all as $jalur ) {
                            $jalur_js[] = [
                                'id'    => (int) $jalur->ID,
                                'label' => $jalur->post_title,
                            ];
                        }
                        echo wp_json_encode( $jalur_js );
                    ?>;
                    var jalurManageUrl = <?php echo wp_json_encode( admin_url( 'edit.php?post_type=master_jalur' ) ); ?>;

                    function syncCheckboxHiddens($row) {
                        $row.find('.psb-col-check input[type="checkbox"]').each(function() {
                            var $cb = $(this);
                            var $hidden = $cb.prev('input[type="hidden"]');
                            if ($hidden.length) {
                                $hidden.val($cb.is(':checked') ? '1' : '0');
                            }
                        });
                    }

                    function refreshRestoreOptions() {
                        var used = {};
                        $('#psb-fields-sortable tr').each(function() {
                            if ($(this).data('kind') === 'default') {
                                used[$(this).find('input[name="psb_row_key[]"]').val()] = true;
                            }
                        });
                        var $select = $('#psb-restore-default').empty();
                        var missing = 0;
                        $.each(defaultFields, function(_, f) {
                            if (!used[f.key]) {
                                $select.append($('<option>').val(f.key).text(f.label));
                                missing++;
                            }
                        });
                        $('#psb-restore-wrap').css('display', missing > 0 ? 'inline-flex' : 'none');
                    }

                    function buildJalurPilihanHtml() {
                        if (!jalurOptions.length) {
                            return '<p class="psb-jalur-empty">Belum ada jalur aktif. <a href="' + jalurManageUrl + '">Tambah jalur</a></p>';
                        }
                        var html = '<div class="psb-jalur-pilihan">';
                        $.each(jalurOptions, function(_, j) {
                            html += '<label><input type="checkbox" name="psb_jalur_pilihan[]" value="' + j.id + '" checked> ' +
                                $('<div>').text(j.label).html() + '</label>';
                        });
                        html += '</div>';
                        return html;
                    }

                    function buildCustomTypeCell(selected, optionsText) {
                        selected = selected || 'text';
                        optionsText = optionsText || '';
                        var types = [
                            { v: 'text', t: 'Teks Singkat' },
                            { v: 'textarea', t: 'Paragraf' },
                            { v: 'number', t: 'Angka' },
                            { v: 'date', t: 'Tanggal' },
                            { v: 'select', t: 'Pilihan' }
                        ];
                        var html = '<select name="psb_row_type[]" class="psb-row-type-select" style="width: 100%; max-width: 220px;">';
                        $.each(types, function(_, t) {
                            html += '<option value="' + t.v + '"' + (t.v === selected ? ' selected' : '') + '>' + t.t + '</option>';
                        });
                        html += '</select>';
                        html += '<div class="psb-options-wrap"' + (selected === 'select' ? '' : ' style="display:none;"') + '>' +
                            '<textarea name="psb_row_options[]" rows="3" placeholder="Satu opsi per baris">' +
                            $('<div>').text(optionsText).html() +
                            '</textarea>' +
                            '<p class="description">Isi daftar pilihan, satu opsi per baris.</p>' +
                            '</div>';
                        return html;
                    }

                    function toggleOptionsWrap($row) {
                        var type = $row.find('.psb-row-type-select').val();
                        var $wrap = $row.find('.psb-options-wrap');
                        if (!$wrap.length) return;
                        if (type === 'select') {
                            $wrap.show();
                        } else {
                            $wrap.hide();
                        }
                    }

                    function buildDefaultRow(f) {
                        var typeCell;
                        if (f.key === 'jalur') {
                            typeCell =
                                '<input type="hidden" name="psb_row_type[]" value="select">' +
                                '<input type="hidden" name="psb_row_options[]" value="">' +
                                '<span class="psb-type-badge is-select">Pilihan</span>' +
                                buildJalurPilihanHtml();
                        } else {
                            var optsText = (f.options && f.options.length) ? f.options.join('\n') : '';
                            typeCell = buildCustomTypeCell(f.field_type || 'text', optsText);
                        }

                        return $('<tr class="psb-field-row" data-kind="default" data-key="' + f.key + '">' +
                            '<td class="psb-drag-handle" title="Geser untuk mengubah urutan"><span class="dashicons dashicons-menu"></span></td>' +
                            '<td class="psb-col-name">' +
                                '<input type="hidden" name="psb_row_kind[]" value="default">' +
                                '<input type="hidden" name="psb_row_key[]" value="' + f.key + '">' +
                                '<input type="text" name="psb_row_label[]" value="' + $('<div>').text(f.label).html() + '" required style="width: 100%;" placeholder="Nama kolom">' +
                            '</td>' +
                            '<td>' + typeCell + '</td>' +
                            '<td class="psb-col-check"><span class="psb-check-wrap">' +
                                '<input type="hidden" name="psb_row_show[]" value="1">' +
                                '<input type="checkbox" value="1" checked onchange="this.previousElementSibling.value=this.checked?\'1\':\'0\';">' +
                            '</span></td>' +
                            '<td class="psb-col-check"><span class="psb-check-wrap">' +
                                '<input type="hidden" name="psb_row_req[]" value="0">' +
                                '<input type="checkbox" value="1" onchange="this.previousElementSibling.value=this.checked?\'1\':\'0\';">' +
                            '</span></td>' +
                            '<td class="psb-col-action"><button type="button" class="button remove-field-row">Hapus</button></td>' +
                        '</tr>');
                    }

                    // Sinkronkan nilai awal checkbox → hidden
                    $('#psb-fields-sortable tr').each(function() {
                        syncCheckboxHiddens($(this));
                    });
                    refreshRestoreOptions();

                    $('#psb-fields-sortable').sortable({
                        handle: '.psb-drag-handle',
                        axis: 'y',
                        placeholder: 'ui-sortable-placeholder',
                        helper: function(e, tr) {
                            var $originals = tr.children();
                            var $helper = tr.clone();
                            $helper.children().each(function(index) {
                                $(this).width($originals.eq(index).width());
                            });
                            return $helper;
                        }
                    });

                    $('#add-custom-field').on('click', function() {
                        var tr = $('<tr class="psb-field-row" data-kind="custom">' +
                            '<td class="psb-drag-handle" title="Geser untuk mengubah urutan"><span class="dashicons dashicons-menu"></span></td>' +
                            '<td class="psb-col-name">' +
                                '<input type="hidden" name="psb_row_kind[]" value="custom">' +
                                '<input type="hidden" name="psb_row_key[]" value="">' +
                                '<input type="text" name="psb_row_label[]" value="" required style="width: 100%;" placeholder="Label kolom">' +
                            '</td>' +
                            '<td>' + buildCustomTypeCell() + '</td>' +
                            '<td class="psb-col-check"><span class="psb-check-wrap">' +
                                '<input type="hidden" name="psb_row_show[]" value="1">' +
                                '<input type="checkbox" value="1" checked onchange="this.previousElementSibling.value=this.checked?\'1\':\'0\';">' +
                            '</span></td>' +
                            '<td class="psb-col-check"><span class="psb-check-wrap">' +
                                '<input type="hidden" name="psb_row_req[]" value="0">' +
                                '<input type="checkbox" value="1" onchange="this.previousElementSibling.value=this.checked?\'1\':\'0\';">' +
                            '</span></td>' +
                            '<td class="psb-col-action"><button type="button" class="button remove-field-row">Hapus</button></td>' +
                        '</tr>');
                        $('#psb-fields-sortable').append(tr);
                    });

                    $('#psb-fields-table').on('change', '.psb-row-type-select', function() {
                        toggleOptionsWrap($(this).closest('tr'));
                    });

                    $('#psb-restore-btn').on('click', function() {
                        var key = $('#psb-restore-default').val();
                        if (!key) return;
                        var field = null;
                        $.each(defaultFields, function(_, f) {
                            if (f.key === key) field = f;
                        });
                        if (!field) return;
                        $('#psb-fields-sortable').append(buildDefaultRow(field));
                        refreshRestoreOptions();
                    });

                    $('#psb-fields-table').on('click', '.remove-field-row', function() {
                        $(this).closest('tr').remove();
                        refreshRestoreOptions();
                    });
                });
            </script>
            
            <p class="submit" style="margin-top: 20px;">
                <button type="submit" name="qrg_save_psb_settings" class="button button-primary">Simpan Pengaturan</button>
            </p>
        </form>
    </div>
    <?php
}
