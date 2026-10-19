<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Registrasi CPT Data Kelas
add_action( 'init', 'qrg_register_master_kelas_cpt' );
function qrg_register_master_kelas_cpt() {
    register_post_type( 'master_kelas', array(
        'labels' => array(
            'name'          => 'Data Kelas',
            'singular_name' => 'Kelas',
            'menu_name'     => 'Data Kelas',
            'add_new_item'  => 'Tambah Kelas Baru',
            'edit_item'     => 'Edit Kelas',
            'all_items'     => 'Data Kelas',
        ),
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => 'master-data-plugin',
        'supports'           => array( 'title' ),
        'capability_type'    => 'post',
        'show_in_rest'       => false,
    ) );
}

add_filter( 'enter_title_here', 'qrg_kelas_title_placeholder', 10, 2 );
function qrg_kelas_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_kelas' ) {
        return 'Contoh: Kelas 1';
    }
    return $title;
}

/**
 * Format label kelas: "Kelas 1 — Abu Bakar"
 */
function qrg_get_kelas_label( $kelas_value ) {
    if ( $kelas_value === '' || $kelas_value === null ) {
        return '';
    }

    if ( is_numeric( $kelas_value ) ) {
        $post = get_post( (int) $kelas_value );
        if ( $post && $post->post_type === 'master_kelas' ) {
            $keterangan = get_post_meta( $post->ID, '_kelas_keterangan', true );
            $label      = $post->post_title;
            if ( $keterangan ) {
                $label .= ' — ' . $keterangan;
            }
            return $label;
        }
    }

    return (string) $kelas_value;
}

/**
 * Ambil semua kelas terdaftar.
 */
function qrg_get_daftar_kelas() {
    return get_posts( array(
        'post_type'      => 'master_kelas',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
}

/**
 * Ambil semua guru terdaftar.
 */
function qrg_get_daftar_guru() {
    return get_posts( array(
        'post_type'      => 'master_guru',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
}

/**
 * Resolve nilai kelas menjadi ID post (jika ditemukan).
 */
function qrg_resolve_kelas_value( $input ) {
    $input = trim( (string) $input );
    if ( $input === '' ) {
        return '';
    }

    if ( is_numeric( $input ) ) {
        $post = get_post( (int) $input );
        if ( $post && $post->post_type === 'master_kelas' ) {
            return (string) $post->ID;
        }
    }

    foreach ( qrg_get_daftar_kelas() as $kelas_post ) {
        $label = qrg_get_kelas_label( $kelas_post->ID );
        if ( strcasecmp( $kelas_post->post_title, $input ) === 0 || strcasecmp( $label, $input ) === 0 ) {
            return (string) $kelas_post->ID;
        }
        $ket = get_post_meta( $kelas_post->ID, '_kelas_keterangan', true );
        if ( $ket && strcasecmp( $ket, $input ) === 0 ) {
            return (string) $kelas_post->ID;
        }
    }

    return $input;
}

/**
 * ID wali kelas (guru) dari sebuah kelas.
 */
function qrg_get_kelas_wali_id( $kelas_id ) {
    $kelas_id = (int) $kelas_id;
    if ( ! $kelas_id ) {
        return 0;
    }
    return (int) get_post_meta( $kelas_id, '_kelas_wali_id', true );
}

/**
 * Nama wali kelas.
 */
function qrg_get_kelas_wali_nama( $kelas_id ) {
    $guru_id = qrg_get_kelas_wali_id( $kelas_id );
    if ( $guru_id && get_post_type( $guru_id ) === 'master_guru' ) {
        return get_the_title( $guru_id );
    }
    // Fallback data lama (teks bebas)
    $legacy = get_post_meta( (int) $kelas_id, '_kelas_wali', true );
    return $legacy ? (string) $legacy : '';
}

/**
 * Ambil siswa berdasarkan ID kelas.
 */
function qrg_get_siswa_by_kelas( $kelas_id ) {
    $kelas_id = (int) $kelas_id;
    if ( ! $kelas_id ) {
        return array();
    }

    return get_posts( array(
        'post_type'      => 'master_siswa',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => array(
            array(
                'key'     => '_siswa_kelas',
                'value'   => (string) $kelas_id,
                'compare' => '=',
            ),
        ),
    ) );
}

/**
 * Hitung jumlah siswa di kelas.
 */
function qrg_count_siswa_kelas( $kelas_id ) {
    return count( qrg_get_siswa_by_kelas( $kelas_id ) );
}

/**
 * Ambil kelas yang diampu seorang guru (array ID).
 */
function qrg_get_guru_kelas_ids( $guru_id ) {
    $ids = get_post_meta( (int) $guru_id, '_guru_kelas_ids', true );
    if ( ! is_array( $ids ) ) {
        $ids = array();
    }
    return array_map( 'intval', array_filter( $ids ) );
}

/**
 * Sinkronisasi wali kelas dua arah (kelas ↔ guru).
 */
function qrg_sync_kelas_wali( $kelas_id, $guru_id ) {
    $kelas_id = (int) $kelas_id;
    $guru_id  = (int) $guru_id;

    if ( ! $kelas_id || get_post_type( $kelas_id ) !== 'master_kelas' ) {
        return;
    }

    $old_guru_id = (int) get_post_meta( $kelas_id, '_kelas_wali_id', true );

    // Lepas kelas ini dari guru lama
    if ( $old_guru_id && $old_guru_id !== $guru_id ) {
        $old_wali = (int) get_post_meta( $old_guru_id, '_guru_wali_kelas', true );
        if ( $old_wali === $kelas_id ) {
            delete_post_meta( $old_guru_id, '_guru_wali_kelas' );
        }
    }

    if ( $guru_id && get_post_type( $guru_id ) === 'master_guru' ) {
        // Jika guru sudah jadi wali kelas lain, lepaskan dulu
        $prev_kelas = (int) get_post_meta( $guru_id, '_guru_wali_kelas', true );
        if ( $prev_kelas && $prev_kelas !== $kelas_id ) {
            $prev_wali = (int) get_post_meta( $prev_kelas, '_kelas_wali_id', true );
            if ( $prev_wali === $guru_id ) {
                delete_post_meta( $prev_kelas, '_kelas_wali_id' );
            }
        }

        update_post_meta( $kelas_id, '_kelas_wali_id', $guru_id );
        update_post_meta( $guru_id, '_guru_wali_kelas', $kelas_id );

        // Pastikan kelas masuk daftar kelas diampu guru
        $kelas_ids = qrg_get_guru_kelas_ids( $guru_id );
        if ( ! in_array( $kelas_id, $kelas_ids, true ) ) {
            $kelas_ids[] = $kelas_id;
            update_post_meta( $guru_id, '_guru_kelas_ids', $kelas_ids );
        }
    } else {
        delete_post_meta( $kelas_id, '_kelas_wali_id' );
    }
}

// 2. Meta Box Detail Kelas
add_action( 'add_meta_boxes', 'qrg_kelas_meta_boxes' );
function qrg_kelas_meta_boxes() {
    add_meta_box( 'kelas_detail', 'Detail Data Kelas', 'qrg_kelas_fields_callback', 'master_kelas', 'normal', 'high' );
    add_meta_box( 'kelas_siswa_list', 'Daftar Siswa di Kelas Ini', 'qrg_kelas_siswa_list_callback', 'master_kelas', 'normal', 'default' );
}

function qrg_kelas_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_kelas_meta', 'qrg_kelas_nonce' );

    $keterangan   = get_post_meta( $post->ID, '_kelas_keterangan', true );
    $tingkat      = get_post_meta( $post->ID, '_kelas_tingkat', true );
    $wali_id      = (int) get_post_meta( $post->ID, '_kelas_wali_id', true );
    $tahun_ajaran = get_post_meta( $post->ID, '_kelas_tahun_ajaran', true );
    $daftar_guru  = qrg_get_daftar_guru();
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Judul: <strong>Kelas 1</strong> · Keterangan: <strong>Abu Bakar</strong> → tampil sebagai <strong>Kelas 1 — Abu Bakar</strong>.
        Wali kelas dipilih dari <strong>Data Guru</strong>.
    </p>

    <table class="form-table">
        <tr>
            <th><label for="kelas_keterangan">Keterangan / Nama Kelas</label></th>
            <td>
                <input type="text" id="kelas_keterangan" name="kelas_keterangan" value="<?php echo esc_attr( $keterangan ); ?>" class="regular-text" placeholder="Contoh: Abu Bakar" required>
            </td>
        </tr>
        <tr>
            <th><label for="kelas_tingkat">Tingkat (Angka)</label></th>
            <td>
                <input type="number" id="kelas_tingkat" name="kelas_tingkat" value="<?php echo esc_attr( $tingkat ); ?>" min="1" max="12" class="small-text" placeholder="1">
            </td>
        </tr>
        <tr>
            <th><label for="kelas_wali_id">Wali Kelas (dari Data Guru)</label></th>
            <td>
                <select id="kelas_wali_id" name="kelas_wali_id" style="min-width: 320px;">
                    <option value="">-- Pilih Guru --</option>
                    <?php foreach ( $daftar_guru as $guru ) : ?>
                        <option value="<?php echo esc_attr( $guru->ID ); ?>" <?php selected( $wali_id, $guru->ID ); ?>>
                            <?php echo esc_html( $guru->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php if ( empty( $daftar_guru ) ) : ?>
                        <span style="color:#b32d2e;">Belum ada data guru. Tambahkan dulu di Data Guru.</span>
                    <?php else : ?>
                        Terintegrasi dengan menu <strong>Sekolah → Data Guru</strong>.
                    <?php endif; ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="kelas_tahun_ajaran">Tahun Ajaran</label></th>
            <td>
                <input type="text" id="kelas_tahun_ajaran" name="kelas_tahun_ajaran" value="<?php echo esc_attr( $tahun_ajaran ); ?>" class="regular-text" placeholder="Contoh: 2025/2026">
            </td>
        </tr>
    </table>
    <?php
}

function qrg_kelas_siswa_list_callback( $post ) {
    if ( ! $post->ID || $post->post_status === 'auto-draft' ) {
        echo '<p style="color:#666;">Simpan kelas terlebih dahulu untuk melihat daftar siswa.</p>';
        return;
    }

    $siswa_list = qrg_get_siswa_by_kelas( $post->ID );
    $edit_url   = admin_url( 'edit.php?post_type=master_siswa' );
    ?>
    <p style="margin-top:0;">
        Total: <strong><?php echo count( $siswa_list ); ?> siswa</strong>
        · <a href="<?php echo esc_url( $edit_url ); ?>">Kelola Data Siswa</a>
    </p>
    <?php if ( empty( $siswa_list ) ) : ?>
        <p style="color:#888;">Belum ada siswa yang terdaftar di kelas ini. Pilih kelas ini pada form Data Siswa.</p>
    <?php else : ?>
        <table class="widefat striped" style="margin-top:10px;">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>Nama Siswa</th>
                    <th>NISN</th>
                    <th style="width:80px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $siswa_list as $i => $siswa ) : ?>
                    <tr>
                        <td><?php echo (int) $i + 1; ?></td>
                        <td><strong><?php echo esc_html( $siswa->post_title ); ?></strong></td>
                        <td><?php echo esc_html( get_post_meta( $siswa->ID, '_siswa_nisn', true ) ?: '-' ); ?></td>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $siswa->ID ) ); ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif;
}

add_action( 'save_post_master_kelas', 'qrg_save_kelas_details' );
function qrg_save_kelas_details( $post_id ) {
    if ( ! isset( $_POST['qrg_kelas_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_kelas_nonce'], 'qrg_save_kelas_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    if ( isset( $_POST['kelas_keterangan'] ) ) {
        update_post_meta( $post_id, '_kelas_keterangan', sanitize_text_field( $_POST['kelas_keterangan'] ) );
    }
    if ( isset( $_POST['kelas_tingkat'] ) ) {
        update_post_meta( $post_id, '_kelas_tingkat', sanitize_text_field( $_POST['kelas_tingkat'] ) );
    }
    if ( isset( $_POST['kelas_tahun_ajaran'] ) ) {
        update_post_meta( $post_id, '_kelas_tahun_ajaran', sanitize_text_field( $_POST['kelas_tahun_ajaran'] ) );
    }

    $wali_id = isset( $_POST['kelas_wali_id'] ) ? (int) $_POST['kelas_wali_id'] : 0;
    qrg_sync_kelas_wali( $post_id, $wali_id );
}

// 3. Kolom daftar admin
add_filter( 'manage_master_kelas_posts_columns', 'qrg_set_kelas_columns' );
function qrg_set_kelas_columns( $columns ) {
    return array(
        'cb'         => $columns['cb'],
        'title'      => 'Kelas',
        'keterangan' => 'Keterangan',
        'tingkat'    => 'Tingkat',
        'wali'       => 'Wali Kelas',
        'jml_siswa'  => 'Jumlah Siswa',
        'tahun'      => 'Tahun Ajaran',
        'date'       => $columns['date'],
    );
}

add_action( 'manage_master_kelas_posts_custom_column', 'qrg_fill_kelas_columns', 10, 2 );
function qrg_fill_kelas_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'keterangan':
            $ket = get_post_meta( $post_id, '_kelas_keterangan', true );
            echo $ket ? '<strong>' . esc_html( $ket ) . '</strong>' : '<span style="color:#aaa;">-</span>';
            break;
        case 'tingkat':
            $tingkat = get_post_meta( $post_id, '_kelas_tingkat', true );
            echo esc_html( $tingkat !== '' ? $tingkat : '-' );
            break;
        case 'wali':
            $wali_id = qrg_get_kelas_wali_id( $post_id );
            if ( $wali_id ) {
                $link = get_edit_post_link( $wali_id );
                echo '<a href="' . esc_url( $link ) . '"><strong>' . esc_html( get_the_title( $wali_id ) ) . '</strong></a>';
            } else {
                $legacy = get_post_meta( $post_id, '_kelas_wali', true );
                echo $legacy ? esc_html( $legacy ) . ' <em style="color:#888;">(lama)</em>' : '<span style="color:#aaa;">-</span>';
            }
            break;
        case 'jml_siswa':
            $count = qrg_count_siswa_kelas( $post_id );
            echo '<strong>' . (int) $count . '</strong>';
            break;
        case 'tahun':
            $tahun = get_post_meta( $post_id, '_kelas_tahun_ajaran', true );
            echo esc_html( $tahun ? $tahun : '-' );
            break;
    }
}
