<?php
/**
 * Antar jemput siswa — portal /sekolah/antarjemput/
 * Jenis antar/jemput (bisa keduanya), peta lokasi, estimasi biaya per jarak.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'qrg_register_antar_jemput_cpt' );
function qrg_register_antar_jemput_cpt() {
    register_post_type( 'master_antar_jemput', [
        'labels' => [
            'name'               => 'Antar Jemput',
            'singular_name'      => 'Antar Jemput',
            'add_new'            => 'Tambah',
            'add_new_item'       => 'Tambah Antar/Jemput',
            'edit_item'          => 'Edit Antar/Jemput',
            'new_item'           => 'Permintaan Baru',
            'view_item'          => 'Lihat',
            'search_items'       => 'Cari Antar Jemput',
            'not_found'          => 'Belum ada permintaan',
            'not_found_in_trash' => 'Tidak ada di tong sampah',
            'all_items'          => 'Antar Jemput',
            'menu_name'          => 'Antar Jemput',
        ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => 'master-data-plugin',
        'supports'        => [ 'title' ],
        'capability_type' => 'post',
        'map_meta_cap'    => true,
        'menu_position'   => 30,
    ] );
}

/**
 * Pengaturan lokasi sekolah & tarif.
 *
 * @return array{lat:float,lng:float,tarif_km:int,tarif_min:int,tarif_base:int,label:string}
 */
function qrg_antar_jemput_settings() {
    return [
        'lat'        => (float) get_option( 'qrg_aj_school_lat', -6.200000 ),
        'lng'        => (float) get_option( 'qrg_aj_school_lng', 106.816666 ),
        'tarif_km'   => max( 0, (int) get_option( 'qrg_aj_tarif_km', 5000 ) ),
        'tarif_min'  => max( 0, (int) get_option( 'qrg_aj_tarif_min', 10000 ) ),
        'tarif_base' => max( 0, (int) get_option( 'qrg_aj_tarif_base', 5000 ) ),
        'label'      => (string) get_option( 'qrg_aj_school_label', 'Sekolah' ),
    ];
}

/**
 * @return string[]
 */
function qrg_antar_jemput_jenis_list() {
    return [ 'antar', 'jemput', 'keduanya' ];
}

/**
 * @param string $jenis Jenis.
 * @return string
 */
function qrg_antar_jemput_jenis_label( $jenis ) {
    $jenis = sanitize_key( (string) $jenis );
    if ( $jenis === 'antar' ) {
        return 'Antar ke sekolah';
    }
    if ( $jenis === 'keduanya' ) {
        return 'Antar & Jemput';
    }
    return 'Jemput dari sekolah';
}

/**
 * @return string[]
 */
function qrg_antar_jemput_status_list() {
    return [ 'Menunggu', 'Disetujui', 'Ditolak', 'Selesai' ];
}

/**
 * Jarak haversine (km).
 *
 * @param float $lat1 Lat A.
 * @param float $lng1 Lng A.
 * @param float $lat2 Lat B.
 * @param float $lng2 Lng B.
 * @return float
 */
function qrg_antar_jemput_haversine_km( $lat1, $lng1, $lat2, $lng2 ) {
    $lat1 = (float) $lat1;
    $lng1 = (float) $lng1;
    $lat2 = (float) $lat2;
    $lng2 = (float) $lng2;
    if ( ! is_finite( $lat1 ) || ! is_finite( $lng1 ) || ! is_finite( $lat2 ) || ! is_finite( $lng2 ) ) {
        return 0.0;
    }
    $r    = 6371.0;
    $dlat = deg2rad( $lat2 - $lat1 );
    $dlng = deg2rad( $lng2 - $lng1 );
    $a    = sin( $dlat / 2 ) * sin( $dlat / 2 )
        + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) )
        * sin( $dlng / 2 ) * sin( $dlng / 2 );
    $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
    return round( $r * $c, 2 );
}

/**
 * Estimasi biaya dari jarak & jenis.
 *
 * @param float  $jarak_km Jarak satu arah.
 * @param string $jenis    antar|jemput|keduanya.
 * @return int
 */
function qrg_antar_jemput_estimasi_biaya( $jarak_km, $jenis = 'jemput' ) {
    $s     = qrg_antar_jemput_settings();
    $jarak = max( 0, (float) $jarak_km );
    $trips = ( $jenis === 'keduanya' ) ? 2 : 1;
    $raw   = $s['tarif_base'] + ( $jarak * $s['tarif_km'] * $trips );
    $min   = $s['tarif_min'] * $trips;
    $biaya = max( $min, $raw );
    // Bulatkan ke Rp500 terdekat.
    return (int) ( round( $biaya / 500 ) * 500 );
}

/**
 * Format rupiah singkat.
 *
 * @param int $n Nominal.
 * @return string
 */
function qrg_antar_jemput_format_rp( $n ) {
    return 'Rp ' . number_format( (int) $n, 0, ',', '.' );
}

add_action( 'admin_menu', 'qrg_antar_jemput_settings_menu', 60 );
function qrg_antar_jemput_settings_menu() {
    add_submenu_page(
        'master-data-plugin',
        'Setelan Antar Jemput',
        'Setelan Antar Jemput',
        'manage_options',
        'qrg-aj-settings',
        'qrg_antar_jemput_settings_page'
    );
}

function qrg_antar_jemput_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( isset( $_POST['qrg_aj_settings_save'] ) ) {
        check_admin_referer( 'qrg_aj_settings' );
        update_option( 'qrg_aj_school_lat', (float) ( $_POST['aj_school_lat'] ?? -6.2 ) );
        update_option( 'qrg_aj_school_lng', (float) ( $_POST['aj_school_lng'] ?? 106.816666 ) );
        update_option( 'qrg_aj_tarif_km', max( 0, (int) ( $_POST['aj_tarif_km'] ?? 5000 ) ) );
        update_option( 'qrg_aj_tarif_min', max( 0, (int) ( $_POST['aj_tarif_min'] ?? 10000 ) ) );
        update_option( 'qrg_aj_tarif_base', max( 0, (int) ( $_POST['aj_tarif_base'] ?? 5000 ) ) );
        update_option( 'qrg_aj_school_label', sanitize_text_field( wp_unslash( $_POST['aj_school_label'] ?? 'Sekolah' ) ) );
        echo '<div class="updated"><p>Setelan disimpan.</p></div>';
    }
    $s = qrg_antar_jemput_settings();
    ?>
    <div class="wrap">
        <h1>Setelan Antar Jemput</h1>
        <form method="post">
            <?php wp_nonce_field( 'qrg_aj_settings' ); ?>
            <input type="hidden" name="qrg_aj_settings_save" value="1">
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="aj_school_label">Nama titik sekolah</label></th>
                    <td><input type="text" class="regular-text" id="aj_school_label" name="aj_school_label" value="<?php echo esc_attr( $s['label'] ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="aj_school_lat">Latitude sekolah</label></th>
                    <td><input type="text" class="regular-text" id="aj_school_lat" name="aj_school_lat" value="<?php echo esc_attr( (string) $s['lat'] ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="aj_school_lng">Longitude sekolah</label></th>
                    <td><input type="text" class="regular-text" id="aj_school_lng" name="aj_school_lng" value="<?php echo esc_attr( (string) $s['lng'] ); ?>">
                        <p class="description">Salin koordinat dari Google Maps (klik kanan → koordinat).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="aj_tarif_base">Biaya dasar (Rp)</label></th>
                    <td><input type="number" min="0" step="500" id="aj_tarif_base" name="aj_tarif_base" value="<?php echo (int) $s['tarif_base']; ?>"></td>
                </tr>
                <tr>
                    <th><label for="aj_tarif_km">Tarif per km (Rp)</label></th>
                    <td><input type="number" min="0" step="500" id="aj_tarif_km" name="aj_tarif_km" value="<?php echo (int) $s['tarif_km']; ?>"></td>
                </tr>
                <tr>
                    <th><label for="aj_tarif_min">Biaya minimum (Rp)</label></th>
                    <td><input type="number" min="0" step="500" id="aj_tarif_min" name="aj_tarif_min" value="<?php echo (int) $s['tarif_min']; ?>">
                        <p class="description">Jika jenis Antar &amp; Jemput, jarak × 2 (pulang-pergi).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Simpan setelan' ); ?>
        </form>
    </div>
    <?php
}

add_action( 'add_meta_boxes', 'qrg_antar_jemput_metaboxes' );
function qrg_antar_jemput_metaboxes() {
    add_meta_box(
        'qrg_antar_jemput_detail',
        'Detail Antar Jemput',
        'qrg_antar_jemput_metabox_render',
        'master_antar_jemput',
        'normal',
        'high'
    );
}

/**
 * @param WP_Post $post Post.
 */
function qrg_antar_jemput_metabox_render( $post ) {
    wp_nonce_field( 'qrg_antar_jemput_admin_save', 'qrg_antar_jemput_admin_nonce' );
    $siswa_id  = (int) get_post_meta( $post->ID, '_aj_siswa_id', true );
    $jenis     = (string) get_post_meta( $post->ID, '_aj_jenis', true );
    $tanggal   = (string) get_post_meta( $post->ID, '_aj_tanggal', true );
    $jam       = (string) get_post_meta( $post->ID, '_aj_jam', true );
    $penjemput = (string) get_post_meta( $post->ID, '_aj_penjemput', true );
    $catatan   = (string) get_post_meta( $post->ID, '_aj_catatan', true );
    $status    = (string) get_post_meta( $post->ID, '_aj_status', true );
    $admin_cat = (string) get_post_meta( $post->ID, '_aj_catatan_admin', true );
    $lat       = (string) get_post_meta( $post->ID, '_aj_lat', true );
    $lng       = (string) get_post_meta( $post->ID, '_aj_lng', true );
    $alamat    = (string) get_post_meta( $post->ID, '_aj_alamat', true );
    $jarak     = (string) get_post_meta( $post->ID, '_aj_jarak_km', true );
    $biaya     = (int) get_post_meta( $post->ID, '_aj_estimasi_biaya', true );
    if ( $status === '' ) {
        $status = 'Menunggu';
    }
    $siswa_list = get_posts( [
        'post_type'      => 'master_siswa',
        'posts_per_page' => 500,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="aj_siswa_id">Siswa</label></th>
            <td>
                <select name="aj_siswa_id" id="aj_siswa_id" style="min-width:280px;">
                    <option value="0">— Pilih —</option>
                    <?php foreach ( $siswa_list as $s ) : ?>
                        <option value="<?php echo (int) $s->ID; ?>" <?php selected( $siswa_id, (int) $s->ID ); ?>>
                            <?php echo esc_html( $s->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="aj_jenis">Jenis</label></th>
            <td>
                <select name="aj_jenis" id="aj_jenis">
                    <?php foreach ( qrg_antar_jemput_jenis_list() as $j ) : ?>
                        <option value="<?php echo esc_attr( $j ); ?>" <?php selected( $jenis, $j ); ?>>
                            <?php echo esc_html( qrg_antar_jemput_jenis_label( $j ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="aj_tanggal">Tanggal</label></th>
            <td><input type="date" name="aj_tanggal" id="aj_tanggal" value="<?php echo esc_attr( $tanggal ); ?>"></td>
        </tr>
        <tr>
            <th><label for="aj_jam">Jam</label></th>
            <td><input type="time" name="aj_jam" id="aj_jam" value="<?php echo esc_attr( $jam ); ?>"></td>
        </tr>
        <tr>
            <th><label for="aj_penjemput">Nama penjemput / pengantar</label></th>
            <td><input type="text" class="regular-text" name="aj_penjemput" id="aj_penjemput" value="<?php echo esc_attr( $penjemput ); ?>"></td>
        </tr>
        <tr>
            <th>Lokasi</th>
            <td>
                <p style="margin:0 0 6px;"><strong>Alamat:</strong> <?php echo $alamat !== '' ? esc_html( $alamat ) : '—'; ?></p>
                <p style="margin:0 0 6px;"><strong>Koordinat:</strong> <?php echo ( $lat !== '' && $lng !== '' ) ? esc_html( $lat . ', ' . $lng ) : '—'; ?></p>
                <p style="margin:0 0 6px;"><strong>Jarak:</strong> <?php echo $jarak !== '' ? esc_html( $jarak . ' km' ) : '—'; ?></p>
                <p style="margin:0;"><strong>Estimasi biaya:</strong> <?php echo $biaya > 0 ? esc_html( qrg_antar_jemput_format_rp( $biaya ) ) : '—'; ?></p>
                <?php if ( $lat !== '' && $lng !== '' ) : ?>
                    <p style="margin:8px 0 0;">
                        <a href="<?php echo esc_url( 'https://www.google.com/maps?q=' . rawurlencode( $lat . ',' . $lng ) ); ?>" target="_blank" rel="noopener">Buka di Google Maps</a>
                    </p>
                <?php endif; ?>
                <input type="hidden" name="aj_lat" value="<?php echo esc_attr( $lat ); ?>">
                <input type="hidden" name="aj_lng" value="<?php echo esc_attr( $lng ); ?>">
                <input type="hidden" name="aj_alamat" value="<?php echo esc_attr( $alamat ); ?>">
                <input type="hidden" name="aj_jarak_km" value="<?php echo esc_attr( $jarak ); ?>">
                <input type="hidden" name="aj_estimasi_biaya" value="<?php echo esc_attr( (string) $biaya ); ?>">
            </td>
        </tr>
        <tr>
            <th><label for="aj_catatan">Catatan orang tua</label></th>
            <td><textarea name="aj_catatan" id="aj_catatan" rows="3" class="large-text"><?php echo esc_textarea( $catatan ); ?></textarea></td>
        </tr>
        <tr>
            <th><label for="aj_status">Status</label></th>
            <td>
                <select name="aj_status" id="aj_status">
                    <?php foreach ( qrg_antar_jemput_status_list() as $st ) : ?>
                        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>>
                            <?php echo esc_html( $st ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="aj_catatan_admin">Catatan admin</label></th>
            <td><textarea name="aj_catatan_admin" id="aj_catatan_admin" rows="2" class="large-text"><?php echo esc_textarea( $admin_cat ); ?></textarea></td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_master_antar_jemput', 'qrg_antar_jemput_save_meta' );
function qrg_antar_jemput_save_meta( $post_id ) {
    if ( ! isset( $_POST['qrg_antar_jemput_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_antar_jemput_admin_nonce'] ) ), 'qrg_antar_jemput_admin_save' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    if ( isset( $_POST['aj_siswa_id'] ) ) {
        update_post_meta( $post_id, '_aj_siswa_id', absint( $_POST['aj_siswa_id'] ) );
    }
    if ( isset( $_POST['aj_jenis'] ) ) {
        $jenis = sanitize_key( wp_unslash( $_POST['aj_jenis'] ) );
        if ( ! in_array( $jenis, qrg_antar_jemput_jenis_list(), true ) ) {
            $jenis = 'jemput';
        }
        update_post_meta( $post_id, '_aj_jenis', $jenis );
    }
    if ( isset( $_POST['aj_tanggal'] ) ) {
        update_post_meta( $post_id, '_aj_tanggal', sanitize_text_field( wp_unslash( $_POST['aj_tanggal'] ) ) );
    }
    if ( isset( $_POST['aj_jam'] ) ) {
        update_post_meta( $post_id, '_aj_jam', sanitize_text_field( wp_unslash( $_POST['aj_jam'] ) ) );
    }
    if ( isset( $_POST['aj_penjemput'] ) ) {
        update_post_meta( $post_id, '_aj_penjemput', sanitize_text_field( wp_unslash( $_POST['aj_penjemput'] ) ) );
    }
    if ( isset( $_POST['aj_catatan'] ) ) {
        update_post_meta( $post_id, '_aj_catatan', sanitize_textarea_field( wp_unslash( $_POST['aj_catatan'] ) ) );
    }
    if ( isset( $_POST['aj_status'] ) ) {
        $status = sanitize_text_field( wp_unslash( $_POST['aj_status'] ) );
        if ( ! in_array( $status, qrg_antar_jemput_status_list(), true ) ) {
            $status = 'Menunggu';
        }
        update_post_meta( $post_id, '_aj_status', $status );
    }
    if ( isset( $_POST['aj_catatan_admin'] ) ) {
        update_post_meta( $post_id, '_aj_catatan_admin', sanitize_textarea_field( wp_unslash( $_POST['aj_catatan_admin'] ) ) );
    }
    foreach ( [ 'aj_lat' => '_aj_lat', 'aj_lng' => '_aj_lng', 'aj_alamat' => '_aj_alamat', 'aj_jarak_km' => '_aj_jarak_km' ] as $post_key => $meta_key ) {
        if ( isset( $_POST[ $post_key ] ) ) {
            update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
        }
    }
    if ( isset( $_POST['aj_estimasi_biaya'] ) ) {
        update_post_meta( $post_id, '_aj_estimasi_biaya', absint( $_POST['aj_estimasi_biaya'] ) );
    }
}

add_filter( 'manage_master_antar_jemput_posts_columns', 'qrg_antar_jemput_columns' );
function qrg_antar_jemput_columns( $columns ) {
    return [
        'cb'     => $columns['cb'],
        'title'  => 'Judul',
        'siswa'  => 'Siswa',
        'jenis'  => 'Jenis',
        'jadwal' => 'Jadwal',
        'jarak'  => 'Jarak / Biaya',
        'status' => 'Status',
        'date'   => 'Dikirim',
    ];
}

add_action( 'manage_master_antar_jemput_posts_custom_column', 'qrg_antar_jemput_column_content', 10, 2 );
function qrg_antar_jemput_column_content( $column, $post_id ) {
    switch ( $column ) {
        case 'siswa':
            $sid = (int) get_post_meta( $post_id, '_aj_siswa_id', true );
            echo $sid ? esc_html( get_the_title( $sid ) ) : '—';
            break;
        case 'jenis':
            echo esc_html( qrg_antar_jemput_jenis_label( get_post_meta( $post_id, '_aj_jenis', true ) ) );
            break;
        case 'jadwal':
            $tgl = (string) get_post_meta( $post_id, '_aj_tanggal', true );
            $jam = (string) get_post_meta( $post_id, '_aj_jam', true );
            $out = $tgl;
            if ( $tgl && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tgl ) ) {
                $ts  = strtotime( $tgl . ' 12:00:00' );
                $out = $ts ? wp_date( 'd M Y', $ts ) : $tgl;
            }
            if ( $jam !== '' ) {
                $out .= ( $out !== '' ? ' · ' : '' ) . $jam;
            }
            echo esc_html( $out !== '' ? $out : '—' );
            break;
        case 'jarak':
            $jarak = (string) get_post_meta( $post_id, '_aj_jarak_km', true );
            $biaya = (int) get_post_meta( $post_id, '_aj_estimasi_biaya', true );
            $parts = [];
            if ( $jarak !== '' ) {
                $parts[] = $jarak . ' km';
            }
            if ( $biaya > 0 ) {
                $parts[] = qrg_antar_jemput_format_rp( $biaya );
            }
            echo esc_html( $parts ? implode( ' · ', $parts ) : '—' );
            break;
        case 'status':
            $st = (string) get_post_meta( $post_id, '_aj_status', true );
            echo esc_html( $st !== '' ? $st : 'Menunggu' );
            break;
    }
}

/**
 * @param int $siswa_id ID siswa.
 * @return WP_Post[]
 */
function qrg_antar_jemput_get_for_siswa( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    if ( $siswa_id <= 0 ) {
        return [];
    }
    return get_posts( [
        'post_type'      => 'master_antar_jemput',
        'posts_per_page' => 40,
        'post_status'    => 'publish',
        'meta_key'       => '_aj_siswa_id',
        'meta_value'     => $siswa_id,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
}

/**
 * Normalisasi pilihan jenis dari POST (checkbox).
 *
 * @param mixed $raw Raw POST.
 * @return string antar|jemput|keduanya|''
 */
function qrg_antar_jemput_normalize_jenis( $raw ) {
    $picked = [];
    if ( is_array( $raw ) ) {
        foreach ( $raw as $v ) {
            $v = sanitize_key( (string) $v );
            if ( in_array( $v, [ 'antar', 'jemput' ], true ) ) {
                $picked[ $v ] = true;
            }
        }
    } else {
        $v = sanitize_key( (string) $raw );
        if ( in_array( $v, qrg_antar_jemput_jenis_list(), true ) ) {
            return $v;
        }
    }
    $has_a = ! empty( $picked['antar'] );
    $has_j = ! empty( $picked['jemput'] );
    if ( $has_a && $has_j ) {
        return 'keduanya';
    }
    if ( $has_a ) {
        return 'antar';
    }
    if ( $has_j ) {
        return 'jemput';
    }
    return '';
}

/**
 * @param int $siswa_id ID siswa.
 * @return array{error:string,success:string}
 */
function qrg_antar_jemput_process_submit( $siswa_id ) {
    $out      = [ 'error' => '', 'success' => '' ];
    $siswa_id = (int) $siswa_id;
    if ( $siswa_id <= 0 || empty( $_POST['qrg_aj_submit'] ) ) {
        return $out;
    }
    if ( ! isset( $_POST['qrg_aj_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_aj_nonce'] ) ), 'qrg_aj_' . $siswa_id ) ) {
        $out['error'] = 'Token tidak valid. Muat ulang halaman.';
        return $out;
    }

    $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'aj_' . $siswa_id, 8, 3600 ) : true;
    if ( is_wp_error( $rl ) ) {
        $out['error'] = $rl->get_error_message();
        return $out;
    }

    $jenis_raw = isset( $_POST['aj_jenis'] ) ? wp_unslash( $_POST['aj_jenis'] ) : [];
    $jenis     = qrg_antar_jemput_normalize_jenis( $jenis_raw );
    $tanggal   = isset( $_POST['aj_tanggal'] ) ? sanitize_text_field( wp_unslash( $_POST['aj_tanggal'] ) ) : '';
    $jam       = isset( $_POST['aj_jam'] ) ? sanitize_text_field( wp_unslash( $_POST['aj_jam'] ) ) : '';
    $penjemput = isset( $_POST['aj_penjemput'] ) ? sanitize_text_field( wp_unslash( $_POST['aj_penjemput'] ) ) : '';
    $catatan   = isset( $_POST['aj_catatan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['aj_catatan'] ) ) : '';
    $lat       = isset( $_POST['aj_lat'] ) ? (float) wp_unslash( $_POST['aj_lat'] ) : 0;
    $lng       = isset( $_POST['aj_lng'] ) ? (float) wp_unslash( $_POST['aj_lng'] ) : 0;
    $alamat    = isset( $_POST['aj_alamat'] ) ? sanitize_text_field( wp_unslash( $_POST['aj_alamat'] ) ) : '';

    if ( $jenis === '' ) {
        $out['error'] = 'Pilih minimal satu jenis: Antar atau Jemput.';
        return $out;
    }
    if ( $tanggal === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tanggal ) ) {
        $out['error'] = 'Tanggal wajib diisi.';
        return $out;
    }
    if ( $jam === '' || ! preg_match( '/^\d{2}:\d{2}$/', $jam ) ) {
        $out['error'] = 'Jam wajib diisi.';
        return $out;
    }
    $penjemput = trim( $penjemput );
    if ( $penjemput === '' ) {
        $out['error'] = 'Nama penjemput / pengantar wajib diisi.';
        return $out;
    }
    if ( abs( $lat ) < 0.0001 && abs( $lng ) < 0.0001 ) {
        $out['error'] = 'Tentukan lokasi di peta terlebih dahulu.';
        return $out;
    }
    if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
        $out['error'] = 'Koordinat lokasi tidak valid.';
        return $out;
    }

    $school = qrg_antar_jemput_settings();
    $jarak  = qrg_antar_jemput_haversine_km( $school['lat'], $school['lng'], $lat, $lng );
    if ( $jarak <= 0 ) {
        $jarak = 0.1;
    }
    $biaya = qrg_antar_jemput_estimasi_biaya( $jarak, $jenis );

    $nama  = get_the_title( $siswa_id );
    $label = qrg_antar_jemput_jenis_label( $jenis );
    $title = $label . ' — ' . ( $nama !== '' ? $nama : 'Siswa' ) . ' · ' . $tanggal . ' ' . $jam;

    $post_id = wp_insert_post( [
        'post_type'   => 'master_antar_jemput',
        'post_status' => 'publish',
        'post_title'  => $title,
    ], true );

    if ( is_wp_error( $post_id ) || ! $post_id ) {
        $out['error'] = 'Gagal mengirim permintaan. Coba lagi.';
        return $out;
    }

    update_post_meta( $post_id, '_aj_siswa_id', $siswa_id );
    update_post_meta( $post_id, '_aj_jenis', $jenis );
    update_post_meta( $post_id, '_aj_tanggal', $tanggal );
    update_post_meta( $post_id, '_aj_jam', $jam );
    update_post_meta( $post_id, '_aj_penjemput', $penjemput );
    update_post_meta( $post_id, '_aj_catatan', $catatan );
    update_post_meta( $post_id, '_aj_status', 'Menunggu' );
    update_post_meta( $post_id, '_aj_lat', (string) $lat );
    update_post_meta( $post_id, '_aj_lng', (string) $lng );
    update_post_meta( $post_id, '_aj_alamat', $alamat );
    update_post_meta( $post_id, '_aj_jarak_km', (string) $jarak );
    update_post_meta( $post_id, '_aj_estimasi_biaya', $biaya );

    if ( function_exists( 'qrg_rate_limit_hit' ) ) {
        qrg_rate_limit_hit( 'aj_' . $siswa_id, 3600 );
    }

    $out['success'] = 'Permintaan berhasil dikirim. Estimasi ' . qrg_antar_jemput_format_rp( $biaya ) . '.';
    return $out;
}

/**
 * Halaman portal siswa antar jemput.
 *
 * @param int $siswa_id ID siswa.
 */
function qrg_render_sekolah_portal_antarjemput( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $nama     = get_the_title( $siswa_id );
    $kelas    = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $site     = get_bloginfo( 'name' );
    $page_url = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url( 'antarjemput' )
        : home_url( '/sekolah/antarjemput/' );
    $hub_url  = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url()
        : home_url( '/sekolah/' );
    $akun_url = add_query_arg( 'tab', 'akun', $hub_url );
    $avatar_sm = function_exists( 'qrg_get_siswa_avatar_url' ) ? qrg_get_siswa_avatar_url( $siswa_id, 'thumbnail' ) : '';
    $initial  = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }

    $msg = qrg_antar_jemput_process_submit( $siswa_id );
    if ( $msg['success'] !== '' || $msg['error'] !== '' ) {
        $redir = $page_url;
        if ( $msg['success'] !== '' ) {
            $redir = add_query_arg( 'aj', 'ok', $redir );
        } else {
            $redir = add_query_arg( 'aj_err', rawurlencode( $msg['error'] ), $redir );
        }
        wp_safe_redirect( $redir );
        exit;
    }

    $ok  = '';
    $err = '';
    if ( isset( $_GET['aj'] ) && sanitize_key( wp_unslash( $_GET['aj'] ) ) === 'ok' ) {
        $ok = 'Permintaan berhasil dikirim. Menunggu konfirmasi sekolah.';
    }
    if ( isset( $_GET['aj_err'] ) ) {
        $err = sanitize_text_field( wp_unslash( $_GET['aj_err'] ) );
    }

    $items  = qrg_antar_jemput_get_for_siswa( $siswa_id );
    $today  = wp_date( 'Y-m-d' );
    $now_hm = wp_date( 'H:i' );
    $cfg    = qrg_antar_jemput_settings();

    $extra = '
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        .qrg-sk--aj-page .dana-sheet { padding-top: 18px; }
        .qrg-sk--aj-page .dana-sheet__label { margin-top: 0; }
        .qrg-aj-back {
            display: inline-flex; align-items: center; gap: 4px;
            color: #fff; text-decoration: none; font-size: 13px; font-weight: 600;
            opacity: .92; margin-bottom: 10px;
        }
        .qrg-aj-back:active { opacity: .75; }
        .qrg-aj-flash {
            border-radius: 14px; padding: 12px 14px; margin-bottom: 12px;
            font-size: 14px; font-weight: 600; line-height: 1.4;
        }
        .qrg-aj-flash.is-ok { background: rgba(52,199,89,.14); color: #248A3D; }
        .qrg-aj-flash.is-err { background: rgba(255,59,48,.12); color: #C93400; }
        .qrg-aj-form {
            background: #fff; border-radius: 16px; padding: 16px;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
            margin-bottom: 16px;
        }
        .qrg-aj-form .qrg-field { margin-bottom: 14px; }
        .qrg-aj-form .qrg-field > span.label {
            display: block; font-size: 12px; font-weight: 700; color: #8E8E93;
            text-transform: uppercase; letter-spacing: .02em; margin-bottom: 8px;
        }
        .qrg-aj-jenis { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .qrg-aj-jenis label {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            min-height: 52px; padding: 10px 8px; border-radius: 14px; background: #F2F2F7;
            border: 2px solid transparent; font-size: 13px; font-weight: 700; cursor: pointer;
            color: #1C1C1E; margin: 0; text-align: center; line-height: 1.25; user-select: none;
        }
        .qrg-aj-jenis label.is-on {
            background: rgba(0,134,240,.1); border-color: #0086F0; color: #0086F0;
        }
        .qrg-aj-jenis label[data-jenis="antar"].is-on {
            background: rgba(29,209,161,.12); border-color: #10AC84; color: #0A7A5C;
        }
        .qrg-aj-jenis input { position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; }
        .qrg-aj-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .qrg-aj-form input[type=date],
        .qrg-aj-form input[type=time],
        .qrg-aj-form input[type=text],
        .qrg-aj-form textarea {
            width: 100%; box-sizing: border-box; padding: 12px 14px; border: 0;
            border-radius: 12px; background: #F2F2F7; font: inherit; font-size: 15px; color: #1C1C1E;
            -webkit-appearance: none; appearance: none;
        }
        .qrg-aj-form textarea { min-height: 80px; resize: vertical; }
        .qrg-aj-form input:focus, .qrg-aj-form textarea:focus {
            outline: 2px solid rgba(0,134,240,.28);
        }
        .qrg-aj-form .hint { margin: 6px 0 0; font-size: 12px; color: #8E8E93; }
        .qrg-aj-map-wrap {
            border-radius: 14px; overflow: hidden; border: 1px solid #E5E5EA; background: #F2F2F7;
        }
        #qrg-aj-map { height: 220px; width: 100%; }
        .qrg-aj-map-actions {
            display: flex; gap: 8px; padding: 10px; background: #FAFBFC; border-top: 1px solid #E5E5EA;
        }
        .qrg-aj-map-actions button {
            appearance: none; border: 0; flex: 1; min-height: 40px; border-radius: 10px;
            background: rgba(0,134,240,.1); color: #0086F0; font-size: 13px; font-weight: 700;
            cursor: pointer; font-family: inherit;
        }
        .qrg-aj-map-actions button:active { opacity: .85; }
        .qrg-aj-loc-meta {
            margin-top: 8px; font-size: 12px; color: #8E8E93; line-height: 1.4;
        }
        .qrg-aj-estimate {
            margin-top: 4px; padding: 12px 14px; border-radius: 14px;
            background: linear-gradient(135deg, rgba(0,134,240,.1), rgba(29,209,161,.1));
            border: 1px solid rgba(0,134,240,.15);
        }
        .qrg-aj-estimate__row {
            display: flex; justify-content: space-between; gap: 10px;
            font-size: 13px; font-weight: 600; color: #3A3A3C; margin-bottom: 6px;
        }
        .qrg-aj-estimate__row:last-child { margin-bottom: 0; }
        .qrg-aj-estimate__biaya {
            font-size: 18px; font-weight: 800; color: #0086F0; letter-spacing: -.02em;
        }
        .qrg-aj-form .btn {
            appearance: none; border: 0; width: 100%; margin-top: 4px; min-height: 48px;
            border-radius: 14px; background: #0086F0; color: #fff; font-size: 15px;
            font-weight: 700; cursor: pointer; font-family: inherit;
        }
        .qrg-aj-form .btn:active { opacity: .88; }
        .qrg-aj-list {
            background: #fff; border-radius: 16px; overflow: hidden;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .qrg-aj-item { padding: 14px 16px; border-top: 1px solid #F2F2F7; }
        .qrg-aj-item:first-child { border-top: 0; }
        .qrg-aj-item__head {
            display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px;
        }
        .qrg-aj-item__jenis {
            font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px;
            background: rgba(0,134,240,.12); color: #0086F0;
        }
        .qrg-aj-item__jenis.is-antar { background: rgba(29,209,161,.14); color: #0A7A5C; }
        .qrg-aj-item__jenis.is-both { background: rgba(162,155,254,.18); color: #6C5CE7; }
        .qrg-aj-item__status {
            font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 999px;
            background: rgba(255,149,0,.14); color: #C93400;
        }
        .qrg-aj-item__status.is-ok { background: rgba(52,199,89,.14); color: #248A3D; }
        .qrg-aj-item__status.is-no { background: rgba(255,59,48,.12); color: #C93400; }
        .qrg-aj-item__status.is-done { background: rgba(0,122,255,.12); color: #007AFF; }
        .qrg-aj-item__title { margin: 0; font-size: 15px; font-weight: 700; }
        .qrg-aj-item__meta { margin: 4px 0 0; font-size: 13px; color: #8E8E93; line-height: 1.4; }
        .qrg-aj-item__admin {
            margin-top: 10px; padding: 10px 12px; border-radius: 12px; background: #F7F8FA;
            font-size: 13px; line-height: 1.4; color: #3A3A3C;
        }
        .qrg-aj-item__admin strong { display: block; font-size: 11px; color: #8E8E93; margin-bottom: 4px; }
        .qrg-aj-empty {
            text-align: center; padding: 28px 16px; color: #8E8E93; font-size: 14px;
            background: #F7F8FA; border: 1px dashed rgba(60,60,67,.18); border-radius: 16px;
        }
        @media (max-width: 380px) {
            .qrg-aj-row { grid-template-columns: 1fr; }
        }
    </style>';

    if ( function_exists( 'qrg_sekolah_portal_shell_start' ) ) {
        qrg_sekolah_portal_shell_start( 'Antar Jemput', $extra, 'beranda' );
    }
    ?>
    <div class="qrg-sk qrg-sk--dana qrg-sk--aj-page">
        <div class="dana-top">
            <a class="qrg-aj-back" href="<?php echo esc_url( $hub_url ); ?>">← Beranda</a>
            <div class="dana-top__row">
                <div class="dana-top__brand">
                    <a class="dana-top__logo<?php echo $avatar_sm ? ' has-photo' : ''; ?>" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Profil">
                        <?php if ( $avatar_sm ) : ?>
                            <img src="<?php echo esc_url( $avatar_sm ); ?>" alt="" width="36" height="36">
                        <?php else : ?>
                            <?php echo esc_html( $initial ); ?>
                        <?php endif; ?>
                    </a>
                    <div>
                        <strong><?php echo esc_html( $nama ); ?></strong>
                        <span><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : esc_html( $site ); ?></span>
                    </div>
                </div>
                <a class="dana-top__bell" href="<?php echo esc_url( $hub_url ); ?>" aria-label="Beranda">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
                </a>
            </div>
            <div class="dana-balance">
                <p class="dana-balance__label">Layanan antar jemput</p>
                <div class="dana-balance__row">
                    <p class="dana-balance__amount" style="font-size:1.4rem;">Ajukan jadwal</p>
                </div>
                <p class="dana-balance__meta">Pilih lokasi di peta · estimasi dari jarak · <?php echo esc_html( $site ); ?></p>
            </div>
        </div>

        <div class="dana-sheet">
            <p class="dana-sheet__label">Buat permintaan</p>

            <?php if ( $err !== '' ) : ?>
                <div class="qrg-aj-flash is-err"><?php echo esc_html( $err ); ?></div>
            <?php endif; ?>
            <?php if ( $ok !== '' ) : ?>
                <div class="qrg-aj-flash is-ok"><?php echo esc_html( $ok ); ?></div>
            <?php endif; ?>

            <form class="qrg-aj-form" method="post" action="<?php echo esc_url( $page_url ); ?>" id="qrg-aj-form">
                <?php wp_nonce_field( 'qrg_aj_' . $siswa_id, 'qrg_aj_nonce' ); ?>
                <input type="hidden" name="qrg_aj_submit" value="1">
                <input type="hidden" name="aj_lat" id="aj_lat" value="">
                <input type="hidden" name="aj_lng" id="aj_lng" value="">
                <input type="hidden" name="aj_alamat" id="aj_alamat" value="">

                <div class="qrg-field">
                    <span class="label">Jenis (bisa keduanya)</span>
                    <div class="qrg-aj-jenis">
                        <label data-jenis="jemput" class="is-on">
                            <input type="checkbox" name="aj_jenis[]" value="jemput" checked>
                            Jemput dari sekolah
                        </label>
                        <label data-jenis="antar">
                            <input type="checkbox" name="aj_jenis[]" value="antar">
                            Antar ke sekolah
                        </label>
                    </div>
                    <p class="hint">Centang keduanya jika butuh antar pagi &amp; jemput pulang (biaya × 2).</p>
                </div>

                <div class="qrg-field">
                    <div class="qrg-aj-row">
                        <div>
                            <span class="label">Tanggal</span>
                            <input type="date" name="aj_tanggal" value="<?php echo esc_attr( $today ); ?>" required>
                        </div>
                        <div>
                            <span class="label">Jam</span>
                            <input type="time" name="aj_jam" value="<?php echo esc_attr( $now_hm ); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="qrg-field">
                    <span class="label">Nama penjemput / pengantar</span>
                    <input type="text" name="aj_penjemput" required maxlength="120" placeholder="Contoh: Bapak / Ibu">
                </div>

                <div class="qrg-field">
                    <span class="label">Lokasi di peta</span>
                    <div class="qrg-aj-map-wrap">
                        <div id="qrg-aj-map" role="application" aria-label="Peta lokasi antar jemput"></div>
                        <div class="qrg-aj-map-actions">
                            <button type="button" id="qrg-aj-geo">Gunakan lokasi saya</button>
                            <button type="button" id="qrg-aj-school">Ke titik sekolah</button>
                        </div>
                    </div>
                    <p class="qrg-aj-loc-meta" id="qrg-aj-loc-meta">Geser pin atau ketuk peta untuk menentukan lokasi rumah / titik jemput.</p>
                </div>

                <div class="qrg-aj-estimate" id="qrg-aj-estimate" hidden>
                    <div class="qrg-aj-estimate__row">
                        <span>Jarak ke sekolah</span>
                        <span id="qrg-aj-jarak">—</span>
                    </div>
                    <div class="qrg-aj-estimate__row">
                        <span>Estimasi biaya</span>
                        <span class="qrg-aj-estimate__biaya" id="qrg-aj-biaya">—</span>
                    </div>
                    <p class="hint" style="margin-top:8px;" id="qrg-aj-est-hint"></p>
                </div>

                <div class="qrg-field">
                    <span class="label">Catatan (opsional)</span>
                    <textarea name="aj_catatan" maxlength="500" placeholder="Contoh: jemput di gerbang utama"></textarea>
                </div>
                <button type="submit" class="btn">Kirim permintaan</button>
            </form>

            <p class="dana-sheet__label" style="margin-top:8px;">Riwayat</p>
            <?php if ( empty( $items ) ) : ?>
                <div class="qrg-aj-empty">Belum ada permintaan antar/jemput.</div>
            <?php else : ?>
                <div class="qrg-aj-list">
                    <?php foreach ( $items as $item ) :
                        $jenis     = (string) get_post_meta( $item->ID, '_aj_jenis', true );
                        $tanggal   = (string) get_post_meta( $item->ID, '_aj_tanggal', true );
                        $jam       = (string) get_post_meta( $item->ID, '_aj_jam', true );
                        $penjemput = (string) get_post_meta( $item->ID, '_aj_penjemput', true );
                        $catatan   = (string) get_post_meta( $item->ID, '_aj_catatan', true );
                        $status    = (string) get_post_meta( $item->ID, '_aj_status', true );
                        $admin_cat = (string) get_post_meta( $item->ID, '_aj_catatan_admin', true );
                        $alamat    = (string) get_post_meta( $item->ID, '_aj_alamat', true );
                        $jarak     = (string) get_post_meta( $item->ID, '_aj_jarak_km', true );
                        $biaya     = (int) get_post_meta( $item->ID, '_aj_estimasi_biaya', true );
                        $lat_i     = (string) get_post_meta( $item->ID, '_aj_lat', true );
                        $lng_i     = (string) get_post_meta( $item->ID, '_aj_lng', true );
                        if ( $status === '' ) {
                            $status = 'Menunggu';
                        }
                        $tgl_label = $tanggal;
                        if ( $tanggal && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tanggal ) ) {
                            $ts = strtotime( $tanggal . ' 12:00:00' );
                            $tgl_label = $ts ? wp_date( 'd M Y', $ts ) : $tanggal;
                        }
                        $st_cls = '';
                        if ( $status === 'Disetujui' ) {
                            $st_cls = 'is-ok';
                        } elseif ( $status === 'Ditolak' ) {
                            $st_cls = 'is-no';
                        } elseif ( $status === 'Selesai' ) {
                            $st_cls = 'is-done';
                        }
                        $jenis_cls = '';
                        if ( $jenis === 'antar' ) {
                            $jenis_cls = 'is-antar';
                        } elseif ( $jenis === 'keduanya' ) {
                            $jenis_cls = 'is-both';
                        }
                        $jenis_short = 'Jemput';
                        if ( $jenis === 'antar' ) {
                            $jenis_short = 'Antar';
                        } elseif ( $jenis === 'keduanya' ) {
                            $jenis_short = 'Antar & Jemput';
                        }
                        ?>
                        <div class="qrg-aj-item">
                            <div class="qrg-aj-item__head">
                                <span class="qrg-aj-item__jenis <?php echo esc_attr( $jenis_cls ); ?>"><?php echo esc_html( $jenis_short ); ?></span>
                                <span class="qrg-aj-item__status <?php echo esc_attr( $st_cls ); ?>"><?php echo esc_html( $status ); ?></span>
                            </div>
                            <p class="qrg-aj-item__title"><?php echo esc_html( trim( $tgl_label . ( $jam !== '' ? ' · ' . $jam : '' ) ) ); ?></p>
                            <p class="qrg-aj-item__meta">
                                <?php echo esc_html( $penjemput !== '' ? $penjemput : '—' ); ?>
                                <?php if ( $jarak !== '' ) : ?>
                                    · <?php echo esc_html( $jarak ); ?> km
                                <?php endif; ?>
                                <?php if ( $biaya > 0 ) : ?>
                                    · <?php echo esc_html( qrg_antar_jemput_format_rp( $biaya ) ); ?>
                                <?php endif; ?>
                            </p>
                            <?php if ( $alamat !== '' ) : ?>
                                <p class="qrg-aj-item__meta"><?php echo esc_html( $alamat ); ?></p>
                            <?php endif; ?>
                            <?php if ( $lat_i !== '' && $lng_i !== '' ) : ?>
                                <p class="qrg-aj-item__meta">
                                    <a href="<?php echo esc_url( 'https://www.google.com/maps?q=' . rawurlencode( $lat_i . ',' . $lng_i ) ); ?>" target="_blank" rel="noopener" style="color:#0086F0;font-weight:700;text-decoration:none;">Lihat lokasi di peta</a>
                                </p>
                            <?php endif; ?>
                            <?php if ( $catatan !== '' ) : ?>
                                <p class="qrg-aj-item__meta"><?php echo esc_html( $catatan ); ?></p>
                            <?php endif; ?>
                            <?php if ( $admin_cat !== '' ) : ?>
                                <div class="qrg-aj-item__admin">
                                    <strong>Catatan sekolah</strong>
                                    <?php echo esc_html( $admin_cat ); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
    (function () {
        var cfg = <?php echo wp_json_encode( [
            'schoolLat'  => $cfg['lat'],
            'schoolLng'  => $cfg['lng'],
            'schoolLabel'=> $cfg['label'] !== '' ? $cfg['label'] : 'Sekolah',
            'tarifKm'    => $cfg['tarif_km'],
            'tarifMin'   => $cfg['tarif_min'],
            'tarifBase'  => $cfg['tarif_base'],
        ] ); ?>;

        var mapEl = document.getElementById('qrg-aj-map');
        if (!mapEl || typeof L === 'undefined') return;

        var latInput = document.getElementById('aj_lat');
        var lngInput = document.getElementById('aj_lng');
        var alamatInput = document.getElementById('aj_alamat');
        var metaEl = document.getElementById('qrg-aj-loc-meta');
        var estBox = document.getElementById('qrg-aj-estimate');
        var jarakEl = document.getElementById('qrg-aj-jarak');
        var biayaEl = document.getElementById('qrg-aj-biaya');
        var hintEl = document.getElementById('qrg-aj-est-hint');
        var form = document.getElementById('qrg-aj-form');

        var map = L.map('qrg-aj-map', { zoomControl: true }).setView([cfg.schoolLat, cfg.schoolLng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        var schoolIcon = L.divIcon({
            className: '',
            html: '<div style="width:14px;height:14px;border-radius:50%;background:#0086F0;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);"></div>',
            iconSize: [14, 14],
            iconAnchor: [7, 7]
        });
        L.marker([cfg.schoolLat, cfg.schoolLng], { icon: schoolIcon, interactive: false })
            .addTo(map)
            .bindTooltip(cfg.schoolLabel, { permanent: false });

        var marker = null;
        var reverseTimer = null;

        function haversine(lat1, lng1, lat2, lng2) {
            var R = 6371, toRad = Math.PI / 180;
            var dLat = (lat2 - lat1) * toRad;
            var dLng = (lng2 - lng1) * toRad;
            var a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) *
                Math.sin(dLng/2) * Math.sin(dLng/2);
            return Math.round((R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a))) * 100) / 100;
        }

        function selectedJenis() {
            var boxes = form.querySelectorAll('input[name="aj_jenis[]"]:checked');
            var hasA = false, hasJ = false;
            boxes.forEach(function (b) {
                if (b.value === 'antar') hasA = true;
                if (b.value === 'jemput') hasJ = true;
            });
            if (hasA && hasJ) return 'keduanya';
            if (hasA) return 'antar';
            if (hasJ) return 'jemput';
            return '';
        }

        function formatRp(n) {
            return 'Rp ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function estimasi(jarak, jenis) {
            var trips = jenis === 'keduanya' ? 2 : 1;
            var raw = cfg.tarifBase + (jarak * cfg.tarifKm * trips);
            var min = cfg.tarifMin * trips;
            var biaya = Math.max(min, raw);
            return Math.round(biaya / 500) * 500;
        }

        function updateEstimate() {
            if (!marker) {
                estBox.hidden = true;
                return;
            }
            var ll = marker.getLatLng();
            var jenis = selectedJenis();
            var jarak = haversine(cfg.schoolLat, cfg.schoolLng, ll.lat, ll.lng);
            if (jarak <= 0) jarak = 0.1;
            var biaya = estimasi(jarak, jenis || 'jemput');
            estBox.hidden = false;
            jarakEl.textContent = jarak.toFixed(2) + ' km' + (jenis === 'keduanya' ? ' (×2 trip)' : '');
            biayaEl.textContent = formatRp(biaya);
            hintEl.textContent = jenis === 'keduanya'
                ? 'Antar & jemput: biaya dihitung 2 arah.'
                : 'Estimasi berdasarkan jarak ke titik sekolah.';
        }

        function setPoint(lat, lng, fromGeo) {
            latInput.value = String(lat);
            lngInput.value = String(lng);
            if (!marker) {
                marker = L.marker([lat, lng], { draggable: true }).addTo(map);
                marker.on('dragend', function () {
                    var p = marker.getLatLng();
                    setPoint(p.lat, p.lng, false);
                });
            } else {
                marker.setLatLng([lat, lng]);
            }
            map.setView([lat, lng], Math.max(map.getZoom(), 15));
            metaEl.textContent = fromGeo
                ? 'Lokasi perangkat dipakai. Geser pin jika perlu dikoreksi.'
                : 'Lokasi dipilih. Geser pin untuk menyesuaikan.';
            updateEstimate();
            scheduleReverse(lat, lng);
        }

        function scheduleReverse(lat, lng) {
            if (reverseTimer) clearTimeout(reverseTimer);
            reverseTimer = setTimeout(function () {
                fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng), {
                    headers: { 'Accept': 'application/json' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.display_name) {
                        alamatInput.value = data.display_name;
                        metaEl.textContent = data.display_name;
                    }
                }).catch(function () {});
            }, 450);
        }

        map.on('click', function (e) {
            setPoint(e.latlng.lat, e.latlng.lng, false);
        });

        // Mulai di dekat sekolah agar user tinggal geser.
        setTimeout(function () { map.invalidateSize(); }, 200);

        document.getElementById('qrg-aj-geo').addEventListener('click', function () {
            if (!navigator.geolocation) {
                metaEl.textContent = 'Perangkat tidak mendukung lokasi.';
                return;
            }
            metaEl.textContent = 'Mengambil lokasi…';
            navigator.geolocation.getCurrentPosition(function (pos) {
                setPoint(pos.coords.latitude, pos.coords.longitude, true);
            }, function () {
                metaEl.textContent = 'Gagal mengambil lokasi. Izinkan akses lokasi atau ketuk peta.';
            }, { enableHighAccuracy: true, timeout: 12000 });
        });

        document.getElementById('qrg-aj-school').addEventListener('click', function () {
            map.setView([cfg.schoolLat, cfg.schoolLng], 15);
        });

        form.querySelectorAll('.qrg-aj-jenis label').forEach(function (lab) {
            var inp = lab.querySelector('input');
            function sync() {
                lab.classList.toggle('is-on', inp.checked);
                updateEstimate();
            }
            inp.addEventListener('change', sync);
            lab.addEventListener('click', function () {
                setTimeout(sync, 0);
            });
        });

        form.addEventListener('submit', function (e) {
            if (!latInput.value || !lngInput.value) {
                e.preventDefault();
                metaEl.textContent = 'Tentukan lokasi di peta dulu (ketuk peta atau gunakan lokasi saya).';
                return false;
            }
            if (!selectedJenis()) {
                e.preventDefault();
                alert('Pilih minimal satu jenis: Antar atau Jemput.');
                return false;
            }
        });
    })();
    </script>
    <?php
    if ( function_exists( 'qrg_sekolah_portal_shell_end' ) ) {
        qrg_sekolah_portal_shell_end();
    }
}
