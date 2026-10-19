<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Registrasi CPT Jalur Penerimaan
add_action( 'init', 'qrg_register_jalur_cpt' );
function qrg_register_jalur_cpt() {
    register_post_type( 'master_jalur', [
        'labels' => [
            'name'          => 'Jalur Penerimaan',
            'singular_name' => 'Jalur Penerimaan',
            'menu_name'     => 'Jalur Penerimaan',
            'add_new_item'  => 'Tambah Jalur Baru',
            'edit_item'     => 'Edit Jalur Penerimaan',
            'all_items'     => 'Jalur Penerimaan',
            'add_new'       => 'Tambah Jalur',
            'search_items'  => 'Cari Jalur',
            'not_found'     => 'Jalur tidak ditemukan',
        ],
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => false, // Akses via tombol di halaman Penerimaan Siswa
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'supports'            => [ 'title' ],
    ] );
}

// Tombol Jalur Penerimaan & Pengaturan Form di samping "Tambah Pendaftar Baru"
add_action( 'admin_footer-edit.php', 'qrg_psb_header_action_buttons' );
function qrg_psb_header_action_buttons() {
    global $typenow;
    if ( $typenow !== 'master_psb' ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $jalur_url    = admin_url( 'edit.php?post_type=master_jalur' );
    $settings_url = admin_url( 'admin.php?page=psb-form-settings' );
    ?>
    <script>
    jQuery(function($) {
        var $btn = $('.wrap .page-title-action').first();
        if (!$btn.length) {
            return;
        }
        var $jalur = $('<a href="<?php echo esc_url( $jalur_url ); ?>" class="page-title-action">Jalur Penerimaan</a>');
        var $settings = $('<a href="<?php echo esc_url( $settings_url ); ?>" class="page-title-action">Pengaturan Form</a>');
        $jalur.insertAfter($btn);
        $settings.insertAfter($jalur);
    });
    </script>
    <?php
}

// Tombol kembali ke Penerimaan Siswa di halaman Jalur
add_action( 'admin_footer-edit.php', 'qrg_jalur_back_to_psb_button' );
add_action( 'admin_footer-post.php', 'qrg_jalur_back_to_psb_button' );
add_action( 'admin_footer-post-new.php', 'qrg_jalur_back_to_psb_button' );
function qrg_jalur_back_to_psb_button() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_jalur' ) {
        return;
    }
    $url = admin_url( 'edit.php?post_type=master_psb' );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) {
            return;
        }
        var $existing = $wrap.siblings('.page-title-action').first();
        var $back = $('<a href="<?php echo esc_url( $url ); ?>" class="page-title-action">← Penerimaan Siswa</a>');
        if ($existing.length) {
            $back.insertBefore($existing);
        } else {
            $back.insertAfter($wrap);
        }
    });
    </script>
    <?php
}

// Saat di halaman Jalur, tandai menu Penerimaan Siswa sebagai aktif
add_filter( 'parent_file', 'qrg_jalur_parent_file' );
function qrg_jalur_parent_file( $parent_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_jalur' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_jalur_submenu_file' );
function qrg_jalur_submenu_file( $submenu_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_jalur' ) {
        return 'edit.php?post_type=master_psb';
    }
    return $submenu_file;
}

add_filter( 'enter_title_here', 'qrg_jalur_title_placeholder', 10, 2 );
function qrg_jalur_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_jalur' ) {
        return 'Contoh: Jalur Prestasi / Jalur Reguler';
    }
    return $title;
}

add_filter( 'post_row_actions', 'qrg_jalur_remove_view_link', 10, 2 );
function qrg_jalur_remove_view_link( $actions, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_jalur' ) {
        unset( $actions['view'] );
    }
    return $actions;
}

add_filter( 'get_sample_permalink_html', 'qrg_jalur_hide_permalink_box', 10, 2 );
function qrg_jalur_hide_permalink_box( $html, $post_id ) {
    if ( get_post_type( $post_id ) === 'master_jalur' ) {
        return '';
    }
    return $html;
}

/**
 * Ambil daftar jalur penerimaan.
 *
 * @param bool $aktif_saja Hanya jalur berstatus Aktif.
 */
function qrg_get_daftar_jalur( $aktif_saja = false ) {
    $posts = get_posts( [
        'post_type'      => 'master_jalur',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    if ( ! $aktif_saja ) {
        return $posts;
    }

    return array_values( array_filter( $posts, function( $post ) {
        $status = get_post_meta( $post->ID, '_jalur_status', true );
        return $status !== 'Nonaktif';
    } ) );
}

/**
 * Label jalur dari ID.
 */
function qrg_get_jalur_label( $jalur_id ) {
    if ( empty( $jalur_id ) ) {
        return '';
    }
    $post = get_post( (int) $jalur_id );
    if ( ! $post || $post->post_type !== 'master_jalur' ) {
        return (string) $jalur_id;
    }
    return $post->post_title;
}

/**
 * Ambil ID jenis biaya yang terpasang pada jalur.
 *
 * @param int $jalur_id ID master_jalur.
 * @return int[]
 */
function qrg_get_jalur_biaya_ids( $jalur_id ) {
    $jalur_id = (int) $jalur_id;
    if ( $jalur_id <= 0 ) {
        return [];
    }
    $ids = get_post_meta( $jalur_id, '_jalur_biaya_ids', true );
    if ( ! is_array( $ids ) ) {
        return [];
    }
    return array_values( array_filter( array_map( 'absint', $ids ) ) );
}

/**
 * Ambil detail biaya (nama + nominal) untuk sebuah jalur.
 *
 * @param int $jalur_id ID master_jalur.
 * @return array<int,array{id:int,title:string,nominal:int}>
 */
function qrg_get_jalur_biaya_items( $jalur_id ) {
    $items = [];
    foreach ( qrg_get_jalur_biaya_ids( $jalur_id ) as $biaya_id ) {
        $post = get_post( $biaya_id );
        if ( ! $post || $post->post_type !== 'master_biaya' || $post->post_status !== 'publish' ) {
            continue;
        }
        $items[] = [
            'id'      => $biaya_id,
            'title'   => $post->post_title,
            'nominal' => (int) get_post_meta( $biaya_id, '_biaya_nominal', true ),
        ];
    }
    return $items;
}

/**
 * Total nominal tagihan yang terpasang pada jalur.
 *
 * @param int $jalur_id ID master_jalur.
 * @return int
 */
function qrg_get_jalur_biaya_total( $jalur_id ) {
    $total = 0;
    foreach ( qrg_get_jalur_biaya_items( $jalur_id ) as $item ) {
        $total += (int) $item['nominal'];
    }
    return $total;
}

/**
 * Buat tagihan otomatis untuk pendaftar PSB sesuai biaya jalur.
 * Idempotent: tidak membuat ulang jika tagihan jalur+biaya sudah ada.
 *
 * @param int $psb_id   ID master_psb.
 * @param int $jalur_id ID master_jalur (opsional; diambil dari meta jika kosong).
 * @return int[] ID tagihan yang dibuat / sudah ada.
 */
function qrg_create_tagihan_for_psb_jalur( $psb_id, $jalur_id = 0 ) {
    $psb_id = (int) $psb_id;
    if ( $psb_id <= 0 || get_post_type( $psb_id ) !== 'master_psb' ) {
        return [];
    }

    if ( ! $jalur_id ) {
        $jalur_id = (int) get_post_meta( $psb_id, '_psb_jalur_id', true );
    }
    $jalur_id = (int) $jalur_id;
    if ( $jalur_id <= 0 ) {
        return [];
    }

    $biaya_items = qrg_get_jalur_biaya_items( $jalur_id );
    if ( empty( $biaya_items ) ) {
        return [];
    }

    $nama_pendaftar = get_the_title( $psb_id );
    $nama_jalur     = qrg_get_jalur_label( $jalur_id );
    $created_ids    = [];

    foreach ( $biaya_items as $item ) {
        $biaya_id = (int) $item['id'];

        // Cegah duplikat: cek tagihan yang sudah terhubung ke PSB + jalur + biaya ini
        $existing = get_posts( [
            'post_type'      => 'master_tagihan',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => '_tagihan_psb_id',
                    'value' => (string) $psb_id,
                ],
                [
                    'key'   => '_tagihan_jalur_id',
                    'value' => (string) $jalur_id,
                ],
                [
                    'key'   => '_tagihan_biaya_sumber_id',
                    'value' => (string) $biaya_id,
                ],
            ],
        ] );

        if ( ! empty( $existing ) ) {
            $created_ids[] = (int) $existing[0];
            continue;
        }

        $title = sprintf(
            '%s - %s (%s)',
            $item['title'],
            $nama_pendaftar,
            $nama_jalur ? $nama_jalur : 'PSB'
        );

        $tagihan_id = wp_insert_post( [
            'post_title'  => $title,
            'post_type'   => 'master_tagihan',
            'post_status' => 'publish',
        ] );

        if ( ! $tagihan_id || is_wp_error( $tagihan_id ) ) {
            continue;
        }

        // Nominal utama = nominal jenis biaya (tanpa biaya tambahan agar tidak dobel)
        update_post_meta( $tagihan_id, '_tagihan_psb_id', $psb_id );
        update_post_meta( $tagihan_id, '_tagihan_jalur_id', $jalur_id );
        update_post_meta( $tagihan_id, '_tagihan_biaya_sumber_id', $biaya_id );
        update_post_meta( $tagihan_id, '_tagihan_nominal', (int) $item['nominal'] );
        if ( function_exists( 'qrg_tagihan_set_status' ) ) {
            qrg_tagihan_set_status( $tagihan_id, 'Belum Dibayar', [
                'via'     => 'sistem',
                'catatan' => 'Tagihan jalur PSB',
            ] );
        } else {
            update_post_meta( $tagihan_id, '_tagihan_status', 'Belum Dibayar' );
        }
        update_post_meta(
            $tagihan_id,
            '_tagihan_keterangan',
            sprintf( 'Tagihan jalur penerimaan: %s', $nama_jalur ? $nama_jalur : '-' )
        );
        if ( function_exists( 'qrg_get_or_create_tagihan_access_token' ) ) {
            qrg_get_or_create_tagihan_access_token( $tagihan_id );
        }

        $created_ids[] = (int) $tagihan_id;
    }

    return $created_ids;
}

/**
 * Ambil daftar tagihan milik pendaftar PSB.
 *
 * @param int $psb_id ID master_psb.
 * @return int[]
 */
function qrg_get_psb_tagihan_ids( $psb_id ) {
    $psb_id = (int) $psb_id;
    if ( $psb_id <= 0 ) {
        return [];
    }
    return array_map( 'intval', get_posts( [
        'post_type'      => 'master_tagihan',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => [
            [
                'key'   => '_tagihan_psb_id',
                'value' => (string) $psb_id,
            ],
        ],
    ] ) );
}

// 2. Meta Box Detail Jalur
add_action( 'add_meta_boxes', 'qrg_jalur_meta_boxes' );
function qrg_jalur_meta_boxes() {
    add_meta_box( 'jalur_detail', 'Detail Jalur Penerimaan', 'qrg_jalur_fields_callback', 'master_jalur', 'normal', 'high' );
}

function qrg_jalur_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_jalur_meta', 'qrg_jalur_nonce' );

    $kode      = get_post_meta( $post->ID, '_jalur_kode', true );
    $kuota     = get_post_meta( $post->ID, '_jalur_kuota', true );
    $deskripsi = get_post_meta( $post->ID, '_jalur_deskripsi', true );
    $status    = get_post_meta( $post->ID, '_jalur_status', true );
    if ( empty( $status ) ) {
        $status = 'Aktif';
    }
    ?>
    <p style="color:#666; font-style:italic; margin-bottom:20px;">
        💡 Isi <strong>NAMA JALUR</strong> di kolom judul paling atas (contoh: Jalur Prestasi, Jalur Affirmasi, Jalur Reguler).
    </p>
    <table class="form-table">
        <tr>
            <th><label for="jalur_kode">Kode Jalur</label></th>
            <td>
                <input type="text" id="jalur_kode" name="jalur_kode" value="<?php echo esc_attr( $kode ); ?>" class="regular-text" placeholder="Contoh: PRESTASI">
                <p class="description">Opsional. Digunakan untuk identifikasi singkat.</p>
            </td>
        </tr>
        <tr>
            <th><label for="jalur_kuota">Kuota</label></th>
            <td>
                <input type="number" id="jalur_kuota" name="jalur_kuota" value="<?php echo esc_attr( $kuota ); ?>" class="small-text" min="0" placeholder="0">
                <p class="description">Jumlah kuota penerimaan untuk jalur ini. Kosongkan jika tidak dibatasi.</p>
            </td>
        </tr>
        <tr>
            <th><label for="jalur_status">Status</label></th>
            <td>
                <select id="jalur_status" name="jalur_status" style="min-width: 180px;">
                    <option value="Aktif" <?php selected( $status, 'Aktif' ); ?>>Aktif</option>
                    <option value="Nonaktif" <?php selected( $status, 'Nonaktif' ); ?>>Nonaktif</option>
                </select>
                <p class="description">Hanya jalur berstatus Aktif yang muncul di form pendaftaran.</p>
            </td>
        </tr>
        <tr>
            <th><label for="jalur_deskripsi">Deskripsi / Syarat</label></th>
            <td>
                <textarea id="jalur_deskripsi" name="jalur_deskripsi" rows="4" class="large-text" placeholder="Contoh: Untuk calon siswa berprestasi akademik/non-akademik"><?php echo esc_textarea( $deskripsi ); ?></textarea>
            </td>
        </tr>
        <tr>
            <th><label>Jenis Biaya Tambahan</label></th>
            <td>
                <p class="description" style="margin-top:0;">
                    Ambil dari data <strong>Jenis Biaya Tambahan</strong>. Centang biaya yang otomatis menjadi tagihan jika calon siswa memilih jalur ini.
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=master_biaya' ) ); ?>">Kelola Jenis Biaya Tambahan</a>
                </p>
                <?php
                // Ambil semua data CPT master_biaya (Jenis Biaya Tambahan)
                $biaya_query = new WP_Query( [
                    'post_type'      => 'master_biaya',
                    'posts_per_page' => -1,
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                ] );
                $selected_biaya = get_post_meta( $post->ID, '_jalur_biaya_ids', true );
                if ( ! is_array( $selected_biaya ) ) {
                    $selected_biaya = [];
                }
                // Normalisasi ke string agar cocok dengan value checkbox (sama seperti di Data Siswa)
                $selected_biaya = array_map( 'strval', $selected_biaya );

                if ( $biaya_query->have_posts() ) {
                    $total_preview = 0;
                    echo '<div style="max-width:520px;">';
                    while ( $biaya_query->have_posts() ) {
                        $biaya_query->the_post();
                        $biaya_id = get_the_ID();
                        $nominal  = get_post_meta( $biaya_id, '_biaya_nominal', true );
                        $checked  = in_array( (string) $biaya_id, $selected_biaya, true );
                        if ( $checked ) {
                            $total_preview += (int) $nominal;
                        }
                        echo '<label style="display:block; margin-bottom:5px;">';
                        echo '<input type="checkbox" name="jalur_biaya_ids[]" value="' . esc_attr( $biaya_id ) . '" ' . checked( $checked, true, false ) . '> ';
                        echo esc_html( get_the_title() ) . ' (Rp ' . number_format( intval( $nominal ), 0, ',', '.' ) . ')';
                        echo '</label>';
                    }
                    wp_reset_postdata();
                    echo '<p style="margin:10px 0 0; padding-top:8px; border-top:1px solid #e2e4e7;">';
                    echo 'Total tagihan jalur: <strong id="qrg-jalur-biaya-total">Rp ' . esc_html( number_format( $total_preview, 0, ',', '.' ) ) . '</strong>';
                    echo '</p>';
                    echo '</div>';
                    ?>
                    <script>
                    jQuery(function($) {
                        function formatRp(n) {
                            return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                        }
                        function updateTotal() {
                            var total = 0;
                            $('input[name="jalur_biaya_ids[]"]:checked').each(function() {
                                var label = $(this).closest('label').text();
                                var m = label.match(/Rp\s*([\d.]+)/);
                                if (m) {
                                    total += parseInt(m[1].replace(/\./g, ''), 10) || 0;
                                }
                            });
                            $('#qrg-jalur-biaya-total').text(formatRp(total));
                        }
                        $(document).on('change', 'input[name="jalur_biaya_ids[]"]', updateTotal);
                    });
                    </script>
                    <?php
                } else {
                    echo '<span style="color:#aaa; font-style:italic;">Belum ada data Jenis Biaya Tambahan. Silakan tambahkan terlebih dahulu di menu <a href="' . esc_url( admin_url( 'edit.php?post_type=master_biaya' ) ) . '">Jenis Biaya Tambahan</a>.</span>';
                }
                ?>
            </td>
        </tr>
    </table>
    <?php
}

// 3. Simpan meta jalur
add_action( 'save_post_master_jalur', 'qrg_save_jalur_details' );
function qrg_save_jalur_details( $post_id ) {
    if ( ! isset( $_POST['qrg_jalur_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_jalur_nonce'], 'qrg_save_jalur_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    if ( isset( $_POST['jalur_kode'] ) ) {
        update_post_meta( $post_id, '_jalur_kode', sanitize_text_field( $_POST['jalur_kode'] ) );
    }
    if ( isset( $_POST['jalur_kuota'] ) ) {
        update_post_meta( $post_id, '_jalur_kuota', max( 0, (int) $_POST['jalur_kuota'] ) );
    }
    if ( isset( $_POST['jalur_status'] ) ) {
        $status = sanitize_text_field( $_POST['jalur_status'] );
        if ( ! in_array( $status, [ 'Aktif', 'Nonaktif' ], true ) ) {
            $status = 'Aktif';
        }
        update_post_meta( $post_id, '_jalur_status', $status );
    }
    if ( isset( $_POST['jalur_deskripsi'] ) ) {
        update_post_meta( $post_id, '_jalur_deskripsi', sanitize_textarea_field( $_POST['jalur_deskripsi'] ) );
    }

    $biaya_ids = [];
    if ( isset( $_POST['jalur_biaya_ids'] ) && is_array( $_POST['jalur_biaya_ids'] ) ) {
        foreach ( $_POST['jalur_biaya_ids'] as $bid ) {
            $bid = absint( $bid );
            if ( $bid && get_post_type( $bid ) === 'master_biaya' ) {
                $biaya_ids[] = $bid;
            }
        }
    }
    update_post_meta( $post_id, '_jalur_biaya_ids', array_values( array_unique( $biaya_ids ) ) );
}

// 4. Kolom tabel daftar jalur
add_filter( 'manage_master_jalur_posts_columns', 'qrg_set_jalur_columns' );
function qrg_set_jalur_columns( $columns ) {
    return [
        'cb'        => $columns['cb'],
        'title'     => 'Nama Jalur',
        'kode'      => 'Kode',
        'kuota'     => 'Kuota',
        'tagihan'   => 'Jenis Biaya Tambahan',
        'status'    => 'Status',
        'deskripsi' => 'Deskripsi',
        'date'      => 'Tanggal',
    ];
}

add_action( 'manage_master_jalur_posts_custom_column', 'qrg_fill_jalur_columns', 10, 2 );
function qrg_fill_jalur_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'kode':
            $kode = get_post_meta( $post_id, '_jalur_kode', true );
            echo esc_html( $kode ? $kode : '—' );
            break;
        case 'kuota':
            $kuota = get_post_meta( $post_id, '_jalur_kuota', true );
            echo ( $kuota !== '' && $kuota !== null ) ? esc_html( (int) $kuota ) : '<span style="color:#646970;">Tidak dibatasi</span>';
            break;
        case 'tagihan':
            $items = qrg_get_jalur_biaya_items( $post_id );
            if ( empty( $items ) ) {
                echo '<span style="color:#646970;">—</span>';
                break;
            }
            $names = [];
            foreach ( $items as $item ) {
                $names[] = $item['title'];
            }
            echo esc_html( implode( ', ', $names ) );
            echo '<div style="font-size:11px;color:#666;margin-top:2px;">Total: <strong>Rp ' . esc_html( number_format( qrg_get_jalur_biaya_total( $post_id ), 0, ',', '.' ) ) . '</strong></div>';
            break;
        case 'status':
            $status = get_post_meta( $post_id, '_jalur_status', true );
            if ( empty( $status ) ) {
                $status = 'Aktif';
            }
            $color = ( $status === 'Aktif' ) ? '#16a34a' : '#dc2626';
            echo '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $status ) . '</strong>';
            break;
        case 'deskripsi':
            $deskripsi = get_post_meta( $post_id, '_jalur_deskripsi', true );
            if ( ! $deskripsi ) {
                echo '—';
                break;
            }
            echo esc_html( wp_trim_words( $deskripsi, 12, '…' ) );
            break;
    }
}
