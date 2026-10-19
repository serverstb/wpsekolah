<?php
/**
 * Pengajuan izin tidak masuk — portal siswa /sekolah/izin/
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'qrg_register_izin_siswa_cpt' );
function qrg_register_izin_siswa_cpt() {
    register_post_type( 'master_izin_siswa', [
        'labels' => [
            'name'               => 'Pengajuan Izin',
            'singular_name'      => 'Pengajuan Izin',
            'add_new'            => 'Tambah Izin',
            'add_new_item'       => 'Tambah Pengajuan Izin',
            'edit_item'          => 'Edit Pengajuan Izin',
            'new_item'           => 'Pengajuan Izin Baru',
            'view_item'          => 'Lihat Pengajuan',
            'search_items'       => 'Cari Pengajuan Izin',
            'not_found'          => 'Belum ada pengajuan izin',
            'not_found_in_trash' => 'Tidak ada di tong sampah',
            'all_items'          => 'Pengajuan Izin',
            'menu_name'          => 'Pengajuan Izin',
        ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => 'master-data-plugin',
        'supports'        => [ 'title' ],
        'capability_type' => 'post',
        'map_meta_cap'    => true,
        'menu_position'   => 28,
    ] );
}

add_action( 'add_meta_boxes', 'qrg_izin_siswa_metaboxes' );
function qrg_izin_siswa_metaboxes() {
    add_meta_box(
        'qrg_izin_siswa_detail',
        'Detail Izin',
        'qrg_izin_siswa_metabox_render',
        'master_izin_siswa',
        'normal',
        'high'
    );
}

/**
 * Tipe pemohon izin yang didukung di admin.
 *
 * @return string[]
 */
function qrg_izin_tipe_list() {
    return [ 'siswa', 'guru', 'karyawan' ];
}

/**
 * Ambil tipe pemohon izin (default siswa untuk data lama).
 *
 * @param int $post_id Post ID.
 * @return string siswa|guru|karyawan
 */
function qrg_izin_get_tipe( $post_id ) {
    $tipe = (string) get_post_meta( (int) $post_id, '_izin_tipe', true );
    if ( ! in_array( $tipe, qrg_izin_tipe_list(), true ) ) {
        return 'siswa';
    }
    return $tipe;
}

/**
 * Label tipe pemohon.
 *
 * @param string $tipe Tipe.
 * @return string
 */
function qrg_izin_tipe_label( $tipe ) {
    $map = [
        'siswa'    => 'Siswa',
        'guru'     => 'Guru',
        'karyawan' => 'Karyawan',
    ];
    return isset( $map[ $tipe ] ) ? $map[ $tipe ] : 'Siswa';
}

/**
 * Tab aktif di list admin (?izin_tab=).
 *
 * @return string siswa|guru|karyawan
 */
function qrg_izin_admin_current_tab() {
    $tab = isset( $_GET['izin_tab'] ) ? sanitize_key( wp_unslash( $_GET['izin_tab'] ) ) : 'siswa';
    if ( ! in_array( $tab, qrg_izin_tipe_list(), true ) ) {
        $tab = 'siswa';
    }
    return $tab;
}

/**
 * @param WP_Post $post Post.
 */
function qrg_izin_siswa_metabox_render( $post ) {
    wp_nonce_field( 'qrg_izin_admin_save', 'qrg_izin_admin_nonce' );
    $tipe     = qrg_izin_get_tipe( $post->ID );
    if ( isset( $_GET['izin_tab'] ) ) {
        $tab_hint = sanitize_key( wp_unslash( $_GET['izin_tab'] ) );
        if ( in_array( $tab_hint, qrg_izin_tipe_list(), true ) && ! get_post_meta( $post->ID, '_izin_tipe', true ) ) {
            $tipe = $tab_hint;
        }
    }
    $siswa_id = (int) get_post_meta( $post->ID, '_izin_siswa_id', true );
    $guru_id  = (int) get_post_meta( $post->ID, '_izin_guru_id', true );
    $jenis    = (string) get_post_meta( $post->ID, '_izin_jenis', true );
    $tgl      = (string) get_post_meta( $post->ID, '_izin_tanggal', true );
    $tgl2     = (string) get_post_meta( $post->ID, '_izin_tanggal_selesai', true );
    $alasan   = (string) get_post_meta( $post->ID, '_izin_alasan', true );
    $status   = (string) get_post_meta( $post->ID, '_izin_status', true );
    $lampiran = (string) get_post_meta( $post->ID, '_izin_lampiran', true );
    if ( $status === '' ) {
        $status = 'Menunggu';
    }
    $siswa_opts = get_posts( [
        'post_type'      => 'master_siswa',
        'posts_per_page' => 500,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
    $guru_opts = get_posts( [
        'post_type'      => 'master_guru',
        'posts_per_page' => 500,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
    ?>
    <table class="form-table">
        <tr>
            <th><label for="_izin_tipe">Pemohon</label></th>
            <td>
                <select name="_izin_tipe" id="_izin_tipe">
                    <?php foreach ( qrg_izin_tipe_list() as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tipe, $t ); ?>><?php echo esc_html( qrg_izin_tipe_label( $t ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr class="qrg-izin-row-siswa" <?php echo $tipe !== 'siswa' ? 'style="display:none"' : ''; ?>>
            <th><label for="_izin_siswa_id">Siswa</label></th>
            <td>
                <select name="_izin_siswa_id" id="_izin_siswa_id" style="min-width:280px;">
                    <option value="0">— Pilih —</option>
                    <?php foreach ( $siswa_opts as $s ) : ?>
                        <option value="<?php echo (int) $s->ID; ?>" <?php selected( $siswa_id, (int) $s->ID ); ?>>
                            <?php echo esc_html( $s->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr class="qrg-izin-row-guru" <?php echo $tipe === 'siswa' ? 'style="display:none"' : ''; ?>>
            <th><label for="_izin_guru_id">Nama Guru / Karyawan</label></th>
            <td>
                <select name="_izin_guru_id" id="_izin_guru_id" style="min-width:280px;">
                    <option value="0">— Pilih —</option>
                    <?php foreach ( $guru_opts as $g ) : ?>
                        <option value="<?php echo (int) $g->ID; ?>" <?php selected( $guru_id, (int) $g->ID ); ?>>
                            <?php echo esc_html( $g->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Data diambil dari Data Guru (termasuk tenaga kependidikan/karyawan).</p>
            </td>
        </tr>
        <tr>
            <th><label for="_izin_jenis">Jenis</label></th>
            <td>
                <select name="_izin_jenis" id="_izin_jenis">
                    <option value="izin" <?php selected( $jenis, 'izin' ); ?>>Izin</option>
                    <option value="sakit" <?php selected( $jenis, 'sakit' ); ?>>Sakit</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="_izin_tanggal">Tanggal mulai</label></th>
            <td><input type="date" name="_izin_tanggal" id="_izin_tanggal" value="<?php echo esc_attr( $tgl ); ?>"></td>
        </tr>
        <tr>
            <th><label for="_izin_tanggal_selesai">Tanggal selesai</label></th>
            <td><input type="date" name="_izin_tanggal_selesai" id="_izin_tanggal_selesai" value="<?php echo esc_attr( $tgl2 ); ?>"></td>
        </tr>
        <tr>
            <th><label for="_izin_status">Status</label></th>
            <td>
                <select name="_izin_status" id="_izin_status">
                    <?php foreach ( [ 'Menunggu', 'Disetujui', 'Ditolak' ] as $st ) : ?>
                        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( $st ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="_izin_alasan">Alasan</label></th>
            <td><textarea name="_izin_alasan" id="_izin_alasan" rows="4" class="large-text"><?php echo esc_textarea( $alasan ); ?></textarea></td>
        </tr>
        <?php if ( $lampiran ) : ?>
            <tr>
                <th>Lampiran</th>
                <td><a href="<?php echo esc_url( $lampiran ); ?>" target="_blank" rel="noopener">Lihat file</a></td>
            </tr>
        <?php endif; ?>
    </table>
    <script>
    (function () {
        var tipe = document.getElementById('_izin_tipe');
        if (!tipe) return;
        function sync() {
            var v = tipe.value;
            document.querySelectorAll('.qrg-izin-row-siswa').forEach(function (el) {
                el.style.display = v === 'siswa' ? '' : 'none';
            });
            document.querySelectorAll('.qrg-izin-row-guru').forEach(function (el) {
                el.style.display = v === 'siswa' ? 'none' : '';
            });
        }
        tipe.addEventListener('change', sync);
        sync();
    })();
    </script>
    <?php
}

add_action( 'save_post_master_izin_siswa', 'qrg_izin_siswa_admin_save' );
function qrg_izin_siswa_admin_save( $post_id ) {
    if ( ! isset( $_POST['qrg_izin_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_izin_admin_nonce'] ) ), 'qrg_izin_admin_save' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $tipe     = isset( $_POST['_izin_tipe'] ) ? sanitize_key( wp_unslash( $_POST['_izin_tipe'] ) ) : 'siswa';
    $siswa_id = isset( $_POST['_izin_siswa_id'] ) ? (int) $_POST['_izin_siswa_id'] : 0;
    $guru_id  = isset( $_POST['_izin_guru_id'] ) ? (int) $_POST['_izin_guru_id'] : 0;
    $jenis    = isset( $_POST['_izin_jenis'] ) ? sanitize_key( wp_unslash( $_POST['_izin_jenis'] ) ) : 'izin';
    $tgl      = isset( $_POST['_izin_tanggal'] ) ? sanitize_text_field( wp_unslash( $_POST['_izin_tanggal'] ) ) : '';
    $tgl2     = isset( $_POST['_izin_tanggal_selesai'] ) ? sanitize_text_field( wp_unslash( $_POST['_izin_tanggal_selesai'] ) ) : '';
    $status   = isset( $_POST['_izin_status'] ) ? sanitize_text_field( wp_unslash( $_POST['_izin_status'] ) ) : 'Menunggu';
    $alasan   = isset( $_POST['_izin_alasan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_izin_alasan'] ) ) : '';

    if ( ! in_array( $tipe, qrg_izin_tipe_list(), true ) ) {
        $tipe = 'siswa';
    }
    if ( ! in_array( $jenis, [ 'izin', 'sakit' ], true ) ) {
        $jenis = 'izin';
    }
    if ( ! in_array( $status, [ 'Menunggu', 'Disetujui', 'Ditolak' ], true ) ) {
        $status = 'Menunggu';
    }

    update_post_meta( $post_id, '_izin_tipe', $tipe );
    update_post_meta( $post_id, '_izin_jenis', $jenis );
    update_post_meta( $post_id, '_izin_tanggal', $tgl );
    update_post_meta( $post_id, '_izin_tanggal_selesai', $tgl2 !== '' ? $tgl2 : $tgl );
    update_post_meta( $post_id, '_izin_status', $status );
    update_post_meta( $post_id, '_izin_alasan', $alasan );

    $nama = '';
    if ( $tipe === 'siswa' ) {
        update_post_meta( $post_id, '_izin_siswa_id', $siswa_id );
        update_post_meta( $post_id, '_izin_guru_id', 0 );
        if ( $siswa_id > 0 ) {
            $nama = get_the_title( $siswa_id );
        }
    } else {
        update_post_meta( $post_id, '_izin_guru_id', $guru_id );
        update_post_meta( $post_id, '_izin_siswa_id', 0 );
        if ( $guru_id > 0 ) {
            $nama = get_the_title( $guru_id );
        }
    }

    if ( $nama !== '' ) {
        $label = qrg_izin_tipe_label( $tipe ) . ' · ' . ( $jenis === 'sakit' ? 'Sakit' : 'Izin' ) . ' — ' . $nama . ( $tgl ? ' (' . $tgl . ')' : '' );
        remove_action( 'save_post_master_izin_siswa', 'qrg_izin_siswa_admin_save' );
        wp_update_post( [
            'ID'         => $post_id,
            'post_title' => $label,
        ] );
        add_action( 'save_post_master_izin_siswa', 'qrg_izin_siswa_admin_save' );
    }
}

add_filter( 'manage_master_izin_siswa_posts_columns', 'qrg_izin_siswa_columns' );
function qrg_izin_siswa_columns( $cols ) {
    $tab = qrg_izin_admin_current_tab();
    $pemohon_label = $tab === 'siswa' ? 'Siswa' : ( $tab === 'guru' ? 'Guru' : 'Karyawan' );
    $extra_label   = $tab === 'siswa' ? 'Kelas' : 'Jabatan';
    return [
        'cb'       => $cols['cb'],
        'title'    => 'Pengajuan',
        'pemohon'  => $pemohon_label,
        'extra'    => $extra_label,
        'jenis'    => 'Jenis',
        'tgl'      => 'Tanggal',
        'status'   => 'Status',
        'lampiran' => 'Lampiran',
        'date'     => 'Diajukan',
    ];
}

add_action( 'manage_master_izin_siswa_posts_custom_column', 'qrg_izin_siswa_column_fill', 10, 2 );
function qrg_izin_siswa_column_fill( $col, $post_id ) {
    $tipe = qrg_izin_get_tipe( $post_id );
    if ( $col === 'pemohon' ) {
        if ( $tipe === 'siswa' ) {
            $sid = (int) get_post_meta( $post_id, '_izin_siswa_id', true );
            if ( $sid ) {
                $edit = get_edit_post_link( $sid );
                $name = get_the_title( $sid );
                if ( $edit ) {
                    echo '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
                } else {
                    echo esc_html( $name );
                }
            } else {
                echo '—';
            }
        } else {
            $gid = (int) get_post_meta( $post_id, '_izin_guru_id', true );
            if ( $gid ) {
                $edit = get_edit_post_link( $gid );
                $name = get_the_title( $gid );
                if ( $edit ) {
                    echo '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
                } else {
                    echo esc_html( $name );
                }
            } else {
                echo '—';
            }
        }
    } elseif ( $col === 'extra' ) {
        if ( $tipe === 'siswa' ) {
            $sid   = (int) get_post_meta( $post_id, '_izin_siswa_id', true );
            $kelas = $sid ? get_post_meta( $sid, '_siswa_kelas', true ) : '';
            $label = function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas ) : (string) $kelas;
            echo $label !== '' ? esc_html( $label ) : '—';
        } else {
            $gid = (int) get_post_meta( $post_id, '_izin_guru_id', true );
            $jabatan = '';
            if ( $gid ) {
                $jabatan = (string) get_post_meta( $gid, '_guru_jabatan', true );
                if ( $jabatan === '' ) {
                    $jid = (int) get_post_meta( $gid, '_guru_jabatan_id', true );
                    if ( $jid && function_exists( 'qrg_get_jabatan_label' ) ) {
                        $jabatan = (string) qrg_get_jabatan_label( $jid );
                    }
                }
            }
            echo $jabatan !== '' ? esc_html( $jabatan ) : '—';
        }
    } elseif ( $col === 'jenis' ) {
        $j = (string) get_post_meta( $post_id, '_izin_jenis', true );
        echo esc_html( $j === 'sakit' ? 'Sakit' : 'Izin' );
    } elseif ( $col === 'tgl' ) {
        $a = (string) get_post_meta( $post_id, '_izin_tanggal', true );
        $b = (string) get_post_meta( $post_id, '_izin_tanggal_selesai', true );
        echo esc_html( $b && $b !== $a ? $a . ' – ' . $b : $a );
    } elseif ( $col === 'status' ) {
        $st = (string) get_post_meta( $post_id, '_izin_status', true ) ?: 'Menunggu';
        $color = '#b45309';
        $bg = '#fff7ed';
        if ( $st === 'Disetujui' ) {
            $color = '#15803d';
            $bg = '#dcfce7';
        } elseif ( $st === 'Ditolak' ) {
            $color = '#b91c1c';
            $bg = '#fee2e2';
        }
        echo '<span style="display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $color ) . ';">' . esc_html( $st ) . '</span>';
    } elseif ( $col === 'lampiran' ) {
        $url = (string) get_post_meta( $post_id, '_izin_lampiran', true );
        if ( $url ) {
            echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Lihat</a>';
        } else {
            echo '—';
        }
    }
}

add_filter( 'views_edit-master_izin_siswa', 'qrg_izin_admin_tabs' );
function qrg_izin_admin_tabs( $views ) {
    $current = qrg_izin_admin_current_tab();
    $base    = admin_url( 'edit.php?post_type=master_izin_siswa' );
    $tabs    = [
        'siswa'    => 'Siswa',
        'guru'     => 'Guru',
        'karyawan' => 'Karyawan',
    ];
    $out = [];
    foreach ( $tabs as $key => $label ) {
        $count = qrg_izin_count_by_tipe( $key );
        $url   = add_query_arg( 'izin_tab', $key, $base );
        $class = $current === $key ? 'current' : '';
        $out[ 'izin_' . $key ] = '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . ' <span class="count">(' . (int) $count . ')</span></a>';
    }
    if ( ! empty( $views['trash'] ) ) {
        $out['trash'] = $views['trash'];
    }
    return $out;
}

/**
 * Hitung pengajuan per tipe (data lama tanpa meta dihitung sebagai siswa).
 *
 * @param string $tipe siswa|guru|karyawan
 * @return int
 */
function qrg_izin_count_by_tipe( $tipe ) {
    $tipe = sanitize_key( $tipe );
    if ( ! in_array( $tipe, qrg_izin_tipe_list(), true ) ) {
        return 0;
    }
    if ( $tipe === 'siswa' ) {
        $q = new WP_Query( [
            'post_type'      => 'master_izin_siswa',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'   => '_izin_tipe',
                    'value' => 'siswa',
                ],
                [
                    'key'     => '_izin_tipe',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );
        return (int) $q->found_posts;
    }
    $q = new WP_Query( [
        'post_type'      => 'master_izin_siswa',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_izin_tipe',
                'value' => $tipe,
            ],
        ],
    ] );
    return (int) $q->found_posts;
}

add_action( 'restrict_manage_posts', 'qrg_izin_admin_filters' );
function qrg_izin_admin_filters() {
    global $typenow;
    if ( $typenow !== 'master_izin_siswa' ) {
        return;
    }
    $tab = qrg_izin_admin_current_tab();
    echo '<input type="hidden" name="izin_tab" value="' . esc_attr( $tab ) . '">';
    $status = isset( $_GET['izin_status'] ) ? sanitize_text_field( wp_unslash( $_GET['izin_status'] ) ) : '';
    $jenis  = isset( $_GET['izin_jenis'] ) ? sanitize_key( wp_unslash( $_GET['izin_jenis'] ) ) : '';
    echo '<select name="izin_status">';
    echo '<option value="">Semua status</option>';
    foreach ( [ 'Menunggu', 'Disetujui', 'Ditolak' ] as $st ) {
        printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $st ), selected( $status, $st, false ) );
    }
    echo '</select>';
    echo '<select name="izin_jenis">';
    echo '<option value="">Semua jenis</option>';
    echo '<option value="izin" ' . selected( $jenis, 'izin', false ) . '>Izin</option>';
    echo '<option value="sakit" ' . selected( $jenis, 'sakit', false ) . '>Sakit</option>';
    echo '</select>';
}

add_action( 'pre_get_posts', 'qrg_izin_admin_filter_query' );
function qrg_izin_admin_filter_query( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( $query->get( 'post_type' ) !== 'master_izin_siswa' ) {
        return;
    }
    $tab  = qrg_izin_admin_current_tab();
    $meta = [];

    if ( $tab === 'siswa' ) {
        $meta[] = [
            'relation' => 'OR',
            [
                'key'   => '_izin_tipe',
                'value' => 'siswa',
            ],
            [
                'key'     => '_izin_tipe',
                'compare' => 'NOT EXISTS',
            ],
        ];
    } else {
        $meta[] = [
            'key'   => '_izin_tipe',
            'value' => $tab,
        ];
    }

    if ( ! empty( $_GET['izin_status'] ) ) {
        $meta[] = [
            'key'   => '_izin_status',
            'value' => sanitize_text_field( wp_unslash( $_GET['izin_status'] ) ),
        ];
    }
    if ( ! empty( $_GET['izin_jenis'] ) ) {
        $meta[] = [
            'key'   => '_izin_jenis',
            'value' => sanitize_key( wp_unslash( $_GET['izin_jenis'] ) ),
        ];
    }
    if ( count( $meta ) > 1 ) {
        $meta['relation'] = 'AND';
    }
    $query->set( 'meta_query', $meta );
}

add_filter( 'post_row_actions', 'qrg_izin_row_actions', 10, 2 );
function qrg_izin_row_actions( $actions, $post ) {
    if ( ! $post || $post->post_type !== 'master_izin_siswa' || ! current_user_can( 'edit_post', $post->ID ) ) {
        return $actions;
    }
    $status = (string) get_post_meta( $post->ID, '_izin_status', true ) ?: 'Menunggu';
    if ( $status === 'Menunggu' ) {
        $ok_url = wp_nonce_url(
            add_query_arg(
                [
                    'post_type'      => 'master_izin_siswa',
                    'qrg_izin_aksi'  => 'setujui',
                    'izin_id'        => $post->ID,
                ],
                admin_url( 'edit.php' )
            ),
            'qrg_izin_aksi_' . $post->ID
        );
        $no_url = wp_nonce_url(
            add_query_arg(
                [
                    'post_type'     => 'master_izin_siswa',
                    'qrg_izin_aksi' => 'tolak',
                    'izin_id'       => $post->ID,
                ],
                admin_url( 'edit.php' )
            ),
            'qrg_izin_aksi_' . $post->ID
        );
        $actions['qrg_izin_ok'] = '<a href="' . esc_url( $ok_url ) . '" style="color:#15803d;font-weight:600;">Setujui</a>';
        $actions['qrg_izin_no'] = '<a href="' . esc_url( $no_url ) . '" style="color:#b91c1c;font-weight:600;">Tolak</a>';
    }
    return $actions;
}

add_action( 'admin_init', 'qrg_izin_handle_admin_aksi' );
function qrg_izin_handle_admin_aksi() {
    if ( empty( $_GET['qrg_izin_aksi'] ) || empty( $_GET['izin_id'] ) ) {
        return;
    }
    $id = (int) $_GET['izin_id'];
    if ( $id <= 0 || get_post_type( $id ) !== 'master_izin_siswa' ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $id ) ) {
        return;
    }
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qrg_izin_aksi_' . $id ) ) {
        return;
    }
    $aksi = sanitize_key( wp_unslash( $_GET['qrg_izin_aksi'] ) );
    if ( $aksi === 'setujui' ) {
        qrg_izin_set_status( $id, 'Disetujui' );
    } elseif ( $aksi === 'tolak' ) {
        qrg_izin_set_status( $id, 'Ditolak' );
    }
    wp_safe_redirect( admin_url( 'edit.php?post_type=master_izin_siswa&izin_updated=1&izin_tab=' . rawurlencode( qrg_izin_get_tipe( $id ) ) ) );
    exit;
}

add_action( 'admin_notices', 'qrg_izin_admin_notice' );
function qrg_izin_admin_notice() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_izin_siswa' ) {
        return;
    }
    if ( empty( $_GET['izin_updated'] ) ) {
        return;
    }
    echo '<div class="notice notice-success is-dismissible"><p>Status pengajuan izin diperbarui.</p></div>';
}

add_action( 'admin_footer-edit.php', 'qrg_izin_admin_add_new_tab_link' );
add_action( 'admin_footer-post-new.php', 'qrg_izin_admin_add_new_tab_link' );
function qrg_izin_admin_add_new_tab_link() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_izin_siswa' ) {
        return;
    }
    $tab = qrg_izin_admin_current_tab();
    $url = admin_url( 'post-new.php?post_type=master_izin_siswa&izin_tab=' . rawurlencode( $tab ) );
    ?>
    <script>
    jQuery(function ($) {
        var $btn = $('.wrap .page-title-action').first();
        if ($btn.length) {
            $btn.attr('href', <?php echo wp_json_encode( $url ); ?>);
            var labels = { siswa: 'Tambah Izin Siswa', guru: 'Tambah Izin Guru', karyawan: 'Tambah Izin Karyawan' };
            var tab = <?php echo wp_json_encode( $tab ); ?>;
            if (labels[tab]) $btn.text(labels[tab]);
        }
    });
    </script>
    <?php
}

add_action( 'admin_menu', 'qrg_izin_menu_badge', 999 );
function qrg_izin_menu_badge() {
    global $submenu;
    if ( empty( $submenu['master-data-plugin'] ) || ! is_array( $submenu['master-data-plugin'] ) ) {
        return;
    }
    $pending = qrg_izin_count_by_status( 'Menunggu' );
    if ( $pending <= 0 ) {
        return;
    }
    foreach ( $submenu['master-data-plugin'] as $i => $item ) {
        if ( isset( $item[2] ) && $item[2] === 'edit.php?post_type=master_izin_siswa' ) {
            $submenu['master-data-plugin'][ $i ][0] .= ' <span class="awaiting-mod">' . (int) $pending . '</span>';
            break;
        }
    }
}

/**
 * Ubah status pengajuan izin.
 *
 * @param int    $izin_id Post ID.
 * @param string $status  Menunggu|Disetujui|Ditolak.
 * @return bool
 */
function qrg_izin_set_status( $izin_id, $status ) {
    $izin_id = (int) $izin_id;
    $status  = sanitize_text_field( (string) $status );
    if ( $izin_id <= 0 || get_post_type( $izin_id ) !== 'master_izin_siswa' ) {
        return false;
    }
    if ( ! in_array( $status, [ 'Menunggu', 'Disetujui', 'Ditolak' ], true ) ) {
        return false;
    }
    update_post_meta( $izin_id, '_izin_status', $status );
    return true;
}

/**
 * Hitung pengajuan by status.
 *
 * @param string $status Status.
 * @return int
 */
function qrg_izin_count_by_status( $status = 'Menunggu' ) {
    $q = new WP_Query( [
        'post_type'      => 'master_izin_siswa',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_izin_status',
        'meta_value'     => sanitize_text_field( $status ),
        'no_found_rows'  => false,
    ] );
    return (int) $q->found_posts;
}

/**
 * Daftar pengajuan izin milik siswa.
 *
 * @param int $siswa_id Post ID.
 * @return WP_Post[]
 */
function qrg_izin_get_for_siswa( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    if ( $siswa_id <= 0 ) {
        return [];
    }
    return get_posts( [
        'post_type'      => 'master_izin_siswa',
        'posts_per_page' => 50,
        'post_status'    => 'publish',
        'meta_key'       => '_izin_siswa_id',
        'meta_value'     => $siswa_id,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
}

/**
 * Proses form pengajuan dari portal.
 *
 * @param int $siswa_id Post ID.
 * @return array{error:string,success:string}
 */
function qrg_izin_process_submit( $siswa_id ) {
    $out = [ 'error' => '', 'success' => '' ];
    $siswa_id = (int) $siswa_id;
    if ( $siswa_id <= 0 || empty( $_POST['qrg_izin_submit'] ) ) {
        return $out;
    }
    if ( ! isset( $_POST['qrg_izin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_izin_nonce'] ) ), 'qrg_izin_' . $siswa_id ) ) {
        $out['error'] = 'Token tidak valid. Muat ulang halaman.';
        return $out;
    }

    $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'izin_' . $siswa_id, 8, 3600 ) : true;
    if ( is_wp_error( $rl ) ) {
        $out['error'] = $rl->get_error_message();
        return $out;
    }

    $jenis = isset( $_POST['izin_jenis'] ) ? sanitize_key( wp_unslash( $_POST['izin_jenis'] ) ) : 'izin';
    $tgl   = isset( $_POST['izin_tanggal'] ) ? sanitize_text_field( wp_unslash( $_POST['izin_tanggal'] ) ) : '';
    $tgl2  = isset( $_POST['izin_tanggal_selesai'] ) ? sanitize_text_field( wp_unslash( $_POST['izin_tanggal_selesai'] ) ) : '';
    $alasan = isset( $_POST['izin_alasan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['izin_alasan'] ) ) : '';

    if ( ! in_array( $jenis, [ 'izin', 'sakit' ], true ) ) {
        $jenis = 'izin';
    }
    if ( $tgl === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tgl ) ) {
        $out['error'] = 'Tanggal mulai wajib diisi.';
        return $out;
    }
    if ( $tgl2 === '' ) {
        $tgl2 = $tgl;
    }
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tgl2 ) ) {
        $out['error'] = 'Tanggal selesai tidak valid.';
        return $out;
    }
    if ( $tgl2 < $tgl ) {
        $out['error'] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
        return $out;
    }
    if ( strlen( $alasan ) < 5 ) {
        $out['error'] = 'Alasan minimal 5 karakter.';
        return $out;
    }
    if ( strlen( $alasan ) > 1000 ) {
        $out['error'] = 'Alasan terlalu panjang.';
        return $out;
    }

    $lampiran = '';
    if ( ! empty( $_FILES['izin_lampiran']['name'] ) ) {
        $mimes = [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' ];
        $upload = function_exists( 'qrg_handle_upload_safe' )
            ? qrg_handle_upload_safe( $_FILES['izin_lampiran'], $mimes )
            : null;
        if ( is_array( $upload ) && ! empty( $upload['error'] ) ) {
            $out['error'] = is_string( $upload['error'] ) ? $upload['error'] : 'Gagal mengunggah lampiran.';
            return $out;
        }
        if ( ! is_array( $upload ) || empty( $upload['url'] ) ) {
            $out['error'] = 'Lampiran gagal diunggah. Format: JPG, PNG, atau PDF (maks. sesuai aturan upload).';
            return $out;
        }
        $lampiran = esc_url_raw( $upload['url'] );
    }

    $nama  = get_the_title( $siswa_id );
    $label = ( $jenis === 'sakit' ? 'Sakit' : 'Izin' ) . ' — ' . $nama . ' (' . $tgl . ')';
    $post_id = wp_insert_post( [
        'post_type'   => 'master_izin_siswa',
        'post_status' => 'publish',
        'post_title'  => $label,
    ], true );

    if ( is_wp_error( $post_id ) ) {
        $out['error'] = 'Gagal menyimpan pengajuan.';
        return $out;
    }

    update_post_meta( $post_id, '_izin_tipe', 'siswa' );
    update_post_meta( $post_id, '_izin_siswa_id', $siswa_id );
    update_post_meta( $post_id, '_izin_jenis', $jenis );
    update_post_meta( $post_id, '_izin_tanggal', $tgl );
    update_post_meta( $post_id, '_izin_tanggal_selesai', $tgl2 );
    update_post_meta( $post_id, '_izin_alasan', $alasan );
    update_post_meta( $post_id, '_izin_status', 'Menunggu' );
    if ( $lampiran ) {
        update_post_meta( $post_id, '_izin_lampiran', $lampiran );
    }

    if ( function_exists( 'qrg_rate_limit_hit' ) ) {
        qrg_rate_limit_hit( 'izin_' . $siswa_id, 3600 );
    }

    $out['success'] = 'Pengajuan izin berhasil dikirim. Menunggu persetujuan.';
    return $out;
}

/**
 * Halaman portal pengajuan izin.
 *
 * @param int $siswa_id Post ID.
 */
function qrg_render_sekolah_portal_izin( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $nama     = get_the_title( $siswa_id );
    $kelas    = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $izin_url = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url( 'izin' )
        : home_url( '/sekolah/izin/' );

    $msg = qrg_izin_process_submit( $siswa_id );
    if ( $msg['success'] !== '' || $msg['error'] !== '' ) {
        $redir = $izin_url;
        if ( $msg['success'] !== '' ) {
            $redir = add_query_arg( 'izin', 'ok', $redir );
        } else {
            $redir = add_query_arg( 'izin_err', rawurlencode( $msg['error'] ), $redir );
        }
        wp_safe_redirect( $redir );
        exit;
    }

    $ok  = '';
    $err = '';
    if ( isset( $_GET['izin'] ) && sanitize_key( wp_unslash( $_GET['izin'] ) ) === 'ok' ) {
        $ok = 'Pengajuan izin berhasil dikirim. Menunggu persetujuan.';
    }
    if ( isset( $_GET['izin_err'] ) ) {
        $err = sanitize_text_field( wp_unslash( $_GET['izin_err'] ) );
    }

    $items = qrg_izin_get_for_siswa( $siswa_id );
    $today = wp_date( 'Y-m-d' );

    $hub_url = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url()
        : home_url( '/sekolah/' );

    $extra = '<style>
        .qrg-izin .ios-hero {
            margin-bottom: 14px;
            padding: 16px 16px 18px;
            border-radius: 18px;
            color: #fff;
            background: linear-gradient(145deg, #007AFF 0%, #5856D6 55%, #30B0C7 100%);
            box-shadow: 0 12px 28px rgba(0,122,255,.22);
            position: relative;
            overflow: hidden;
        }
        .qrg-izin .ios-hero::after {
            content: "";
            position: absolute;
            right: -28px; top: -36px;
            width: 120px; height: 120px;
            border-radius: 50%;
            border: 20px solid rgba(255,255,255,.12);
            pointer-events: none;
        }
        .qrg-izin .ios-hero .brand,
        .qrg-izin .ios-hero .sub { color: rgba(255,255,255,.88); position: relative; z-index: 1; }
        .qrg-izin .ios-hero h1 { color: #fff; position: relative; z-index: 1; }
        .qrg-izin-back {
            display: inline-flex; align-items: center; gap: 4px;
            color: var(--ios-blue); text-decoration: none;
            font-size: 15px; font-weight: 600; margin-bottom: 10px;
        }
        .qrg-izin-form {
            background: var(--ios-card);
            border-radius: var(--ios-radius);
            padding: 16px;
            box-shadow: var(--ios-shadow);
            margin-bottom: 4px;
        }
        .qrg-izin-form .qrg-field { margin-bottom: 14px; }
        .qrg-izin-form .qrg-field:last-of-type { margin-bottom: 10px; }
        .qrg-izin-form .qrg-field > label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--ios-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: .02em;
        }
        .qrg-izin-form input[type=date],
        .qrg-izin-form textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 12px 14px;
            border: 0;
            border-radius: 12px;
            background: var(--ios-bg);
            font: inherit;
            font-size: 15px;
            color: var(--ios-ink);
            -webkit-appearance: none;
            appearance: none;
        }
        .qrg-izin-form input[type=date]:focus,
        .qrg-izin-form textarea:focus {
            outline: 2px solid rgba(0,122,255,.25);
            outline-offset: 0;
        }
        .qrg-izin-form textarea { min-height: 110px; resize: vertical; }
        .qrg-izin-form .hint {
            font-size: 12px;
            color: var(--ios-secondary);
            margin: 6px 0 0;
            line-height: 1.4;
        }
        .qrg-izin-form .btn {
            width: 100%;
            margin-top: 6px;
            border: 0;
            border-radius: 14px;
            padding: 14px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            background: var(--ios-blue);
            color: #fff !important;
            font-family: inherit;
            min-height: 48px;
        }
        .qrg-izin-form .btn:active { opacity: .88; transform: scale(.99); }
        .qrg-izin-jenis {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .qrg-izin-jenis label {
            display: flex !important;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 12px;
            border-radius: 14px;
            background: var(--ios-bg);
            border: 2px solid transparent;
            font-size: 15px !important;
            font-weight: 700 !important;
            text-transform: none !important;
            letter-spacing: 0 !important;
            color: var(--ios-ink) !important;
            cursor: pointer;
            margin: 0 !important;
            transition: .15s ease;
            min-height: 52px;
        }
        .qrg-izin-jenis label:has(input:checked) {
            background: rgba(0,122,255,.1);
            border-color: var(--ios-blue);
            color: var(--ios-blue) !important;
        }
        .qrg-izin-jenis label[data-jenis="sakit"]:has(input:checked) {
            background: rgba(255,149,0,.12);
            border-color: var(--ios-orange);
            color: #C93400 !important;
        }
        .qrg-izin-jenis input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
            width: 0 !important;
            height: 0;
        }
        .qrg-izin-jenis .ico {
            width: 28px; height: 28px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,122,255,.12);
            color: var(--ios-blue);
            flex-shrink: 0;
        }
        .qrg-izin-jenis label[data-jenis="sakit"] .ico {
            background: rgba(255,149,0,.14);
            color: var(--ios-orange);
        }
        .qrg-izin-jenis .ico svg {
            width: 16px; height: 16px;
            stroke: currentColor; fill: none;
            stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
        }
        .qrg-izin-file {
            position: relative;
            display: block;
            border-radius: 16px;
            background: linear-gradient(180deg, #F7FAFF 0%, #EEF5FF 100%);
            padding: 18px 16px;
            text-align: center;
            cursor: pointer;
            border: 1.5px dashed rgba(0,122,255,.35);
            transition: border-color .15s, background .15s, transform .12s;
            overflow: hidden;
        }
        .qrg-izin-file:active { transform: scale(.99); }
        .qrg-izin-file.is-drag {
            border-color: var(--ios-blue);
            border-style: solid;
            background: rgba(0,122,255,.08);
        }
        .qrg-izin-file.has-file {
            border-style: solid;
            border-color: rgba(0,122,255,.2);
            background: var(--ios-card);
            text-align: left;
            padding: 12px;
        }
        .qrg-izin-file input[type=file] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
            font-size: 0;
            z-index: 2;
        }
        .qrg-izin-file.has-file input[type=file] { display: none; }
        .qrg-izin-file__empty { display: block; }
        .qrg-izin-file.has-file .qrg-izin-file__empty { display: none; }
        .qrg-izin-file__preview { display: none; }
        .qrg-izin-file.has-file .qrg-izin-file__preview {
            display: grid;
            grid-template-columns: 56px 1fr auto;
            gap: 12px;
            align-items: center;
            position: relative;
            z-index: 3;
        }
        .qrg-izin-file__icon {
            width: 52px; height: 52px;
            margin: 0 auto 10px;
            border-radius: 16px;
            background: linear-gradient(145deg, #64D2FF, #007AFF);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 18px rgba(0,122,255,.28);
        }
        .qrg-izin-file__icon svg {
            width: 24px; height: 24px;
            stroke: currentColor; fill: none;
            stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round;
        }
        .qrg-izin-file__title {
            display: block;
            font-size: 15px;
            font-weight: 700;
            color: var(--ios-ink);
            margin-bottom: 4px;
        }
        .qrg-izin-file__sub {
            display: block;
            font-size: 12px;
            color: var(--ios-secondary);
            margin-bottom: 10px;
            line-height: 1.35;
        }
        .qrg-izin-file__formats {
            display: inline-flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .qrg-izin-file__formats span {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(0,122,255,.1);
            color: var(--ios-blue);
        }
        .qrg-izin-file__thumb {
            width: 56px; height: 56px;
            border-radius: 12px;
            overflow: hidden;
            background: var(--ios-fill);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--ios-blue);
            font-size: 11px;
            font-weight: 800;
        }
        .qrg-izin-file__thumb img {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
        }
        .qrg-izin-file__thumb.is-pdf {
            background: linear-gradient(145deg, #FF9F0A, #FF375F);
            color: #fff;
        }
        .qrg-izin-file__meta strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: var(--ios-ink);
            line-height: 1.3;
            word-break: break-word;
        }
        .qrg-izin-file__meta span {
            display: block;
            font-size: 12px;
            color: var(--ios-secondary);
            margin-top: 3px;
        }
        .qrg-izin-file__remove {
            appearance: none;
            border: 0;
            background: var(--ios-fill);
            color: var(--ios-red);
            width: 36px; height: 36px;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            flex-shrink: 0;
            font-family: inherit;
        }
        .qrg-izin-file__remove:active { opacity: .8; }
        .qrg-izin-list {
            display: grid;
            gap: 0;
            background: var(--ios-card);
            border-radius: var(--ios-radius);
            overflow: hidden;
            box-shadow: var(--ios-shadow);
        }
        .qrg-izin-item {
            padding: 14px;
            border-bottom: 1px solid var(--ios-sep);
            display: grid;
            grid-template-columns: 40px 1fr auto;
            gap: 12px;
            align-items: start;
        }
        .qrg-izin-item:last-child { border-bottom: 0; }
        .qrg-izin-item__ico {
            width: 40px; height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #64D2FF, #007AFF);
            color: #fff;
            flex-shrink: 0;
        }
        .qrg-izin-item__ico.is-sakit {
            background: linear-gradient(180deg, #FFD60A, #FF9500);
        }
        .qrg-izin-item__ico svg {
            width: 18px; height: 18px;
            stroke: currentColor; fill: none;
            stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
        }
        .qrg-izin-item strong { display: block; font-size: 15px; font-weight: 700; color: var(--ios-ink); }
        .qrg-izin-item .meta {
            font-size: 13px;
            color: var(--ios-secondary);
            margin-top: 4px;
            line-height: 1.4;
        }
        .qrg-izin-badge {
            flex: 0 0 auto;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 9px;
            border-radius: 999px;
            background: rgba(255,149,0,.14);
            color: #C93400;
            white-space: nowrap;
        }
        .qrg-izin-badge.is-ok {
            background: rgba(52,199,89,.14);
            color: #248A3D;
        }
        .qrg-izin-badge.is-no {
            background: rgba(255,59,48,.12);
            color: var(--ios-red);
        }
        .qrg-izin-empty {
            padding: 28px 16px;
            text-align: center;
            color: var(--ios-secondary);
            font-size: 14px;
            line-height: 1.45;
        }
    </style>';

    qrg_sekolah_portal_shell_start( 'Pengajuan Izin', $extra, 'beranda' );
    ?>
    <div class="qrg-sk qrg-izin">
        <a class="qrg-izin-back" href="<?php echo esc_url( $hub_url ); ?>">← Kembali</a>
        <header class="ios-hero">
            <p class="brand">Portal Siswa</p>
            <h1>Pengajuan Izin</h1>
            <p class="sub"><?php echo esc_html( $nama ); ?><?php echo $kelas ? ' · Kelas ' . esc_html( $kelas ) : ''; ?></p>
        </header>

        <?php if ( $err ) : ?>
            <div class="err"><?php echo esc_html( $err ); ?></div>
        <?php endif; ?>
        <?php if ( $ok ) : ?>
            <div class="ok"><?php echo esc_html( $ok ); ?></div>
        <?php endif; ?>

        <section class="ios-section">
            <p class="ios-section__label">Ajukan izin tidak masuk</p>
            <form class="qrg-izin-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( $izin_url ); ?>">
                <?php wp_nonce_field( 'qrg_izin_' . $siswa_id, 'qrg_izin_nonce' ); ?>
                <input type="hidden" name="qrg_izin_submit" value="1">

                <div class="qrg-field">
                    <label>Jenis</label>
                    <div class="qrg-izin-jenis">
                        <label data-jenis="izin">
                            <input type="radio" name="izin_jenis" value="izin" checked>
                            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg></span>
                            Izin
                        </label>
                        <label data-jenis="sakit">
                            <input type="radio" name="izin_jenis" value="sakit">
                            <span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/><circle cx="12" cy="12" r="3"/></svg></span>
                            Sakit
                        </label>
                    </div>
                </div>
                <div class="qrg-field">
                    <label for="izin_tanggal">Tanggal mulai</label>
                    <input type="date" id="izin_tanggal" name="izin_tanggal" value="<?php echo esc_attr( $today ); ?>" required>
                </div>
                <div class="qrg-field">
                    <label for="izin_tanggal_selesai">Tanggal selesai</label>
                    <input type="date" id="izin_tanggal_selesai" name="izin_tanggal_selesai" value="<?php echo esc_attr( $today ); ?>" required>
                    <p class="hint">Isi sama dengan tanggal mulai jika hanya 1 hari.</p>
                </div>
                <div class="qrg-field">
                    <label for="izin_alasan">Alasan</label>
                    <textarea id="izin_alasan" name="izin_alasan" required maxlength="1000" placeholder="Contoh: keperluan keluarga / sakit demam"></textarea>
                </div>
                <div class="qrg-field">
                    <label>Lampiran (opsional)</label>
                    <div class="qrg-izin-file" id="qrg-izin-drop">
                        <input type="file" id="izin_lampiran" name="izin_lampiran" accept="image/jpeg,image/png,application/pdf" aria-label="Unggah lampiran">
                        <div class="qrg-izin-file__empty">
                            <div class="qrg-izin-file__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            </div>
                            <span class="qrg-izin-file__title">Unggah surat / bukti</span>
                            <span class="qrg-izin-file__sub">Ketuk untuk memilih dari galeri atau file</span>
                            <div class="qrg-izin-file__formats" aria-hidden="true">
                                <span>JPG</span><span>PNG</span><span>PDF</span>
                            </div>
                        </div>
                        <div class="qrg-izin-file__preview" id="qrg-izin-preview" aria-live="polite">
                            <div class="qrg-izin-file__thumb" id="qrg-izin-thumb">PDF</div>
                            <div class="qrg-izin-file__meta">
                                <strong id="qrg-izin-fname">—</strong>
                                <span id="qrg-izin-fmeta">—</span>
                            </div>
                            <button type="button" class="qrg-izin-file__remove" id="qrg-izin-remove" title="Hapus lampiran" aria-label="Hapus lampiran">×</button>
                        </div>
                    </div>
                    <p class="hint">Surat dokter atau bukti pendukung, maksimal sesuai batas unggah server.</p>
                </div>
                <button type="submit" class="btn">Kirim Pengajuan</button>
            </form>
        </section>

        <section class="ios-section">
            <p class="ios-section__label">Riwayat pengajuan</p>
            <div class="qrg-izin-list">
                <?php if ( empty( $items ) ) : ?>
                    <div class="qrg-izin-empty">Belum ada pengajuan izin.<br>Ajukan izin di formulir di atas.</div>
                <?php else : ?>
                    <?php foreach ( $items as $item ) :
                        $jenis  = (string) get_post_meta( $item->ID, '_izin_jenis', true );
                        $tgl    = (string) get_post_meta( $item->ID, '_izin_tanggal', true );
                        $tgl2   = (string) get_post_meta( $item->ID, '_izin_tanggal_selesai', true );
                        $alasan = (string) get_post_meta( $item->ID, '_izin_alasan', true );
                        $status = (string) get_post_meta( $item->ID, '_izin_status', true ) ?: 'Menunggu';
                        $badge  = 'qrg-izin-badge';
                        if ( $status === 'Disetujui' ) {
                            $badge .= ' is-ok';
                        } elseif ( $status === 'Ditolak' ) {
                            $badge .= ' is-no';
                        }
                        $periode = ( $tgl2 && $tgl2 !== $tgl ) ? $tgl . ' – ' . $tgl2 : $tgl;
                        $is_sakit = ( $jenis === 'sakit' );
                        ?>
                        <div class="qrg-izin-item">
                            <div class="qrg-izin-item__ico<?php echo $is_sakit ? ' is-sakit' : ''; ?>" aria-hidden="true">
                                <?php if ( $is_sakit ) : ?>
                                    <svg viewBox="0 0 24 24"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/><circle cx="12" cy="12" r="3"/></svg>
                                <?php else : ?>
                                    <svg viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong><?php echo esc_html( $is_sakit ? 'Sakit' : 'Izin' ); ?></strong>
                                <div class="meta">
                                    <?php echo esc_html( $periode ); ?><br>
                                    <?php echo esc_html( wp_trim_words( $alasan, 18 ) ); ?>
                                </div>
                            </div>
                            <span class="<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $status ); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <script>
    (function () {
        var drop = document.getElementById('qrg-izin-drop');
        var input = document.getElementById('izin_lampiran');
        var thumb = document.getElementById('qrg-izin-thumb');
        var fname = document.getElementById('qrg-izin-fname');
        var fmeta = document.getElementById('qrg-izin-fmeta');
        var removeBtn = document.getElementById('qrg-izin-remove');
        if (!drop || !input) return;

        var previewUrl = '';

        function fmtSize(bytes) {
            if (!bytes && bytes !== 0) return '';
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        function clearPreview() {
            drop.classList.remove('has-file');
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = '';
            }
            thumb.className = 'qrg-izin-file__thumb';
            thumb.innerHTML = 'PDF';
            fname.textContent = '—';
            fmeta.textContent = '—';
            input.value = '';
        }

        function showFile(file) {
            if (!file) {
                clearPreview();
                return;
            }
            var isImg = /^image\/(jpeg|png|jpg)$/i.test(file.type) || /\.(jpe?g|png)$/i.test(file.name);
            var isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
            drop.classList.add('has-file');
            fname.textContent = file.name;
            fmeta.textContent = (isPdf ? 'PDF' : (isImg ? 'Gambar' : 'File')) + ' · ' + fmtSize(file.size);

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = '';
            }
            if (isImg) {
                previewUrl = URL.createObjectURL(file);
                thumb.className = 'qrg-izin-file__thumb';
                thumb.innerHTML = '<img src="' + previewUrl + '" alt="">';
            } else {
                thumb.className = 'qrg-izin-file__thumb is-pdf';
                thumb.textContent = 'PDF';
            }
        }

        input.addEventListener('change', function () {
            showFile(input.files && input.files[0] ? input.files[0] : null);
        });

        if (removeBtn) {
            removeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                clearPreview();
            });
        }

        ;['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                drop.classList.add('is-drag');
            });
        });
        ;['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                drop.classList.remove('is-drag');
            });
        });
        drop.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            if (!files || !files.length) return;
            try {
                var dt = new DataTransfer();
                dt.items.add(files[0]);
                input.files = dt.files;
                showFile(files[0]);
            } catch (err) {
                // Fallback: user must tap to pick if DataTransfer unsupported
            }
        });
    })();
    </script>
    <?php
    qrg_sekolah_portal_shell_end();
}

/**
 * ID kelas yang terkait dengan guru (wali + mengajar + jadwal).
 *
 * @param int $guru_id Post ID guru.
 * @return int[]
 */
function qrg_izin_kelas_ids_for_guru( $guru_id ) {
    $guru_id = (int) $guru_id;
    $ids     = [];
    if ( $guru_id <= 0 ) {
        return $ids;
    }
    $wali = (int) get_post_meta( $guru_id, '_guru_wali_kelas', true );
    if ( $wali > 0 ) {
        $ids[] = $wali;
    }
    if ( function_exists( 'qrg_get_guru_kelas_ids' ) ) {
        foreach ( qrg_get_guru_kelas_ids( $guru_id ) as $kid ) {
            $kid = (int) $kid;
            if ( $kid > 0 ) {
                $ids[] = $kid;
            }
        }
    }
    if ( function_exists( 'qrg_get_jadwal_by_guru' ) ) {
        $by_hari = qrg_get_jadwal_by_guru( $guru_id );
        if ( is_array( $by_hari ) ) {
            foreach ( $by_hari as $rows ) {
                if ( ! is_array( $rows ) ) {
                    continue;
                }
                foreach ( $rows as $row ) {
                    $kid = isset( $row['kelas_id'] ) ? (int) $row['kelas_id'] : 0;
                    if ( ! $kid && isset( $row['ID'] ) ) {
                        $kid = (int) get_post_meta( (int) $row['ID'], '_jadwal_kelas_id', true );
                    }
                    if ( $kid > 0 ) {
                        $ids[] = $kid;
                    }
                }
            }
        }
    }
    $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
    return $ids;
}

/**
 * Apakah guru boleh mengubah status izin siswa.
 *
 * @param int $guru_id  Guru ID.
 * @param int $siswa_id Siswa ID.
 * @return bool
 */
function qrg_izin_guru_can_manage( $guru_id, $siswa_id ) {
    $guru_id  = (int) $guru_id;
    $siswa_id = (int) $siswa_id;
    if ( $guru_id <= 0 || $siswa_id <= 0 ) {
        return false;
    }
    $kelas_siswa = get_post_meta( $siswa_id, '_siswa_kelas', true );
    $kelas_id    = is_numeric( $kelas_siswa ) ? (int) $kelas_siswa : 0;
    if ( $kelas_id <= 0 ) {
        return false;
    }
    $wali = (int) get_post_meta( $guru_id, '_guru_wali_kelas', true );
    if ( $wali && $wali === $kelas_id ) {
        return true;
    }
    return in_array( $kelas_id, qrg_izin_kelas_ids_for_guru( $guru_id ), true );
}

/**
 * Ambil pengajuan izin untuk kelas guru.
 *
 * @param int   $guru_id Guru ID.
 * @param array $args    Optional status/limit.
 * @return WP_Post[]
 */
function qrg_izin_get_for_guru( $guru_id, $args = [] ) {
    $guru_id   = (int) $guru_id;
    $kelas_ids = qrg_izin_kelas_ids_for_guru( $guru_id );
    if ( empty( $kelas_ids ) ) {
        return [];
    }

    $siswa_ids = [];
    foreach ( $kelas_ids as $kid ) {
        if ( function_exists( 'qrg_get_siswa_by_kelas' ) ) {
            foreach ( qrg_get_siswa_by_kelas( $kid ) as $s ) {
                $siswa_ids[] = (int) ( is_object( $s ) ? $s->ID : $s );
            }
        }
    }
    $siswa_ids = array_values( array_unique( array_filter( $siswa_ids ) ) );
    if ( empty( $siswa_ids ) ) {
        return [];
    }

    $meta_query = [
        [
            'key'     => '_izin_siswa_id',
            'value'   => $siswa_ids,
            'compare' => 'IN',
        ],
    ];
    if ( ! empty( $args['status'] ) ) {
        $meta_query[] = [
            'key'   => '_izin_status',
            'value' => sanitize_text_field( $args['status'] ),
        ];
        $meta_query['relation'] = 'AND';
    }

    return get_posts( [
        'post_type'      => 'master_izin_siswa',
        'posts_per_page' => isset( $args['limit'] ) ? (int) $args['limit'] : 80,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => $meta_query,
    ] );
}

/**
 * Proses aksi setujui/tolak dari portal guru.
 *
 * @param int $guru_id Guru login.
 * @return array{error:string,success:string}
 */
function qrg_izin_process_guru_aksi( $guru_id ) {
    $out = [ 'error' => '', 'success' => '' ];
    $guru_id = (int) $guru_id;
    if ( $guru_id <= 0 || empty( $_POST['qrg_izin_guru_aksi'] ) ) {
        return $out;
    }
    if ( ! isset( $_POST['qrg_izin_guru_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_izin_guru_nonce'] ) ), 'qrg_izin_guru_' . $guru_id ) ) {
        $out['error'] = 'Token tidak valid.';
        return $out;
    }
    $izin_id = isset( $_POST['izin_id'] ) ? (int) $_POST['izin_id'] : 0;
    $aksi    = sanitize_key( wp_unslash( $_POST['qrg_izin_guru_aksi'] ) );
    if ( $izin_id <= 0 || get_post_type( $izin_id ) !== 'master_izin_siswa' ) {
        $out['error'] = 'Pengajuan tidak ditemukan.';
        return $out;
    }
    $siswa_id = (int) get_post_meta( $izin_id, '_izin_siswa_id', true );
    if ( ! qrg_izin_guru_can_manage( $guru_id, $siswa_id ) ) {
        $out['error'] = 'Anda tidak berwenang memproses izin siswa ini.';
        return $out;
    }
    if ( $aksi === 'setujui' ) {
        qrg_izin_set_status( $izin_id, 'Disetujui' );
        $out['success'] = 'Pengajuan disetujui.';
    } elseif ( $aksi === 'tolak' ) {
        qrg_izin_set_status( $izin_id, 'Ditolak' );
        $out['success'] = 'Pengajuan ditolak.';
    } else {
        $out['error'] = 'Aksi tidak dikenal.';
    }
    return $out;
}

/**
 * Halaman izin siswa di portal guru.
 *
 * @param int $guru_id Guru ID.
 */
function qrg_render_guru_portal_izin( $guru_id ) {
    $guru_id  = (int) $guru_id;
    $nama     = get_the_title( $guru_id );
    $izin_url = function_exists( 'qrg_get_guru_portal_url' )
        ? qrg_get_guru_portal_url( 'izin' )
        : home_url( '/sekolah/guru/izin/' );
    $hub_url  = function_exists( 'qrg_get_guru_portal_url' )
        ? qrg_get_guru_portal_url()
        : home_url( '/sekolah/guru/' );

    $msg = qrg_izin_process_guru_aksi( $guru_id );
    if ( $msg['success'] !== '' || $msg['error'] !== '' ) {
        $redir = $izin_url;
        if ( $msg['success'] !== '' ) {
            $redir = add_query_arg( 'ok', '1', $redir );
        } else {
            $redir = add_query_arg( 'err', rawurlencode( $msg['error'] ), $redir );
        }
        wp_safe_redirect( $redir );
        exit;
    }

    $ok  = isset( $_GET['ok'] ) ? 'Status pengajuan berhasil diperbarui.' : '';
    $err = isset( $_GET['err'] ) ? sanitize_text_field( wp_unslash( $_GET['err'] ) ) : '';
    $filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
    if ( $filter !== '' && ! in_array( $filter, [ 'Menunggu', 'Disetujui', 'Ditolak' ], true ) ) {
        $filter = '';
    }

    $args  = $filter !== '' ? [ 'status' => $filter ] : [];
    $items = qrg_izin_get_for_guru( $guru_id, $args );
    $pending_count = 0;
    foreach ( qrg_izin_get_for_guru( $guru_id, [ 'status' => 'Menunggu', 'limit' => 200 ] ) as $_p ) {
        $pending_count++;
    }

    if ( ! function_exists( 'qrg_guru_portal_shell_start' ) ) {
        wp_die( 'Portal guru tidak tersedia.' );
    }

    qrg_guru_portal_shell_start( 'Izin Siswa', 'beranda' );
    ?>
    <style>
        .qrg-gp-izin .ios-hero {
            margin-bottom: 14px; padding: 16px 16px 18px; border-radius: 18px; color: #fff;
            background: linear-gradient(145deg, #007AFF 0%, #5856D6 55%, #30B0C7 100%);
            box-shadow: 0 12px 28px rgba(0,122,255,.22); position: relative; overflow: hidden;
        }
        .qrg-gp-izin .ios-hero .brand, .qrg-gp-izin .ios-hero .sub { color: rgba(255,255,255,.88); }
        .qrg-gp-izin .ios-hero h1 { color: #fff; margin: 0; font-size: 1.75rem; }
        .qrg-gp-izin-back { display:inline-flex; color:var(--ios-blue); text-decoration:none; font-weight:600; margin-bottom:10px; }
        .qrg-gp-izin-tabs { display:flex; gap:8px; overflow-x:auto; margin:0 0 14px; padding-bottom:2px; }
        .qrg-gp-izin-tabs a {
            flex:0 0 auto; padding:8px 12px; border-radius:999px; text-decoration:none;
            background:var(--ios-fill); color:var(--ios-ink); font-size:13px; font-weight:600;
        }
        .qrg-gp-izin-tabs a.is-on { background:var(--ios-blue); color:#fff; }
        .qrg-gp-izin-list { background:var(--ios-card); border-radius:16px; overflow:hidden; box-shadow:var(--ios-shadow); }
        .qrg-gp-izin-item { padding:14px; border-bottom:1px solid var(--ios-sep); }
        .qrg-gp-izin-item:last-child { border-bottom:0; }
        .qrg-gp-izin-item__top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
        .qrg-gp-izin-item strong { display:block; font-size:15px; }
        .qrg-gp-izin-item .meta { font-size:13px; color:var(--ios-secondary); margin-top:4px; line-height:1.4; }
        .qrg-gp-izin-badge { font-size:11px; font-weight:700; padding:5px 9px; border-radius:999px; background:rgba(255,149,0,.14); color:#C93400; white-space:nowrap; }
        .qrg-gp-izin-badge.is-ok { background:rgba(52,199,89,.14); color:#248A3D; }
        .qrg-gp-izin-badge.is-no { background:rgba(255,59,48,.12); color:var(--ios-red); }
        .qrg-gp-izin-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:10px; }
        .qrg-gp-izin-actions button {
            border:0; border-radius:12px; padding:11px; font-weight:700; font-size:13px;
            font-family:inherit; cursor:pointer; min-height:42px;
        }
        .qrg-gp-izin-actions .ok { background:rgba(52,199,89,.16); color:#248A3D; }
        .qrg-gp-izin-actions .no { background:rgba(255,59,48,.12); color:var(--ios-red); }
        .qrg-gp-izin-empty { padding:28px 16px; text-align:center; color:var(--ios-secondary); font-size:14px; }
        .qrg-gp-izin-lampiran { display:inline-block; margin-top:6px; font-size:12px; font-weight:600; color:var(--ios-blue); text-decoration:none; }
        .qrg-gp .err { background:rgba(255,59,48,.1); color:var(--ios-red); padding:12px 14px; border-radius:12px; margin-bottom:12px; font-size:14px; }
        .qrg-gp .ok { background:rgba(52,199,89,.12); color:#248A3D; padding:12px 14px; border-radius:12px; margin-bottom:12px; font-size:14px; }
    </style>
    <div class="qrg-gp qrg-gp-izin">
        <a class="qrg-gp-izin-back" href="<?php echo esc_url( $hub_url ); ?>">← Kembali</a>
        <header class="ios-hero">
            <p class="brand">Portal Guru</p>
            <h1>Izin Siswa</h1>
            <p class="sub"><?php echo esc_html( $nama ); ?><?php echo $pending_count ? ' · ' . (int) $pending_count . ' menunggu' : ''; ?></p>
        </header>

        <?php if ( $err ) : ?><div class="err"><?php echo esc_html( $err ); ?></div><?php endif; ?>
        <?php if ( $ok ) : ?><div class="ok"><?php echo esc_html( $ok ); ?></div><?php endif; ?>

        <div class="qrg-gp-izin-tabs" role="tablist">
            <a class="<?php echo $filter === '' ? 'is-on' : ''; ?>" href="<?php echo esc_url( $izin_url ); ?>">Semua</a>
            <a class="<?php echo $filter === 'Menunggu' ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'status', 'Menunggu', $izin_url ) ); ?>">Menunggu</a>
            <a class="<?php echo $filter === 'Disetujui' ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'status', 'Disetujui', $izin_url ) ); ?>">Disetujui</a>
            <a class="<?php echo $filter === 'Ditolak' ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'status', 'Ditolak', $izin_url ) ); ?>">Ditolak</a>
        </div>

        <div class="qrg-gp-izin-list">
            <?php if ( empty( $items ) ) : ?>
                <div class="qrg-gp-izin-empty">Belum ada pengajuan izin dari siswa kelas Anda.</div>
            <?php else : ?>
                <?php foreach ( $items as $item ) :
                    $siswa_id = (int) get_post_meta( $item->ID, '_izin_siswa_id', true );
                    $jenis    = (string) get_post_meta( $item->ID, '_izin_jenis', true );
                    $tgl      = (string) get_post_meta( $item->ID, '_izin_tanggal', true );
                    $tgl2     = (string) get_post_meta( $item->ID, '_izin_tanggal_selesai', true );
                    $alasan   = (string) get_post_meta( $item->ID, '_izin_alasan', true );
                    $status   = (string) get_post_meta( $item->ID, '_izin_status', true ) ?: 'Menunggu';
                    $lampiran = (string) get_post_meta( $item->ID, '_izin_lampiran', true );
                    $kelas_raw = $siswa_id ? get_post_meta( $siswa_id, '_siswa_kelas', true ) : '';
                    $kelas    = function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( $kelas_raw ) : (string) $kelas_raw;
                    $periode  = ( $tgl2 && $tgl2 !== $tgl ) ? $tgl . ' – ' . $tgl2 : $tgl;
                    $badge    = 'qrg-gp-izin-badge';
                    if ( $status === 'Disetujui' ) { $badge .= ' is-ok'; }
                    elseif ( $status === 'Ditolak' ) { $badge .= ' is-no'; }
                    $can = qrg_izin_guru_can_manage( $guru_id, $siswa_id );
                    ?>
                    <div class="qrg-gp-izin-item">
                        <div class="qrg-gp-izin-item__top">
                            <div>
                                <strong><?php echo esc_html( get_the_title( $siswa_id ) ); ?></strong>
                                <div class="meta">
                                    <?php echo esc_html( ( $jenis === 'sakit' ? 'Sakit' : 'Izin' ) . ( $kelas ? ' · Kelas ' . $kelas : '' ) ); ?><br>
                                    <?php echo esc_html( $periode ); ?><br>
                                    <?php echo esc_html( wp_trim_words( $alasan, 20 ) ); ?>
                                    <?php if ( $lampiran ) : ?>
                                        <br><a class="qrg-gp-izin-lampiran" href="<?php echo esc_url( $lampiran ); ?>" target="_blank" rel="noopener">Lihat lampiran</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $status ); ?></span>
                        </div>
                        <?php if ( $can && $status === 'Menunggu' ) : ?>
                            <div class="qrg-gp-izin-actions">
                                <form method="post" action="<?php echo esc_url( $izin_url ); ?>">
                                    <?php wp_nonce_field( 'qrg_izin_guru_' . $guru_id, 'qrg_izin_guru_nonce' ); ?>
                                    <input type="hidden" name="izin_id" value="<?php echo (int) $item->ID; ?>">
                                    <button class="ok" type="submit" name="qrg_izin_guru_aksi" value="setujui">Setujui</button>
                                </form>
                                <form method="post" action="<?php echo esc_url( $izin_url ); ?>">
                                    <?php wp_nonce_field( 'qrg_izin_guru_' . $guru_id, 'qrg_izin_guru_nonce' ); ?>
                                    <input type="hidden" name="izin_id" value="<?php echo (int) $item->ID; ?>">
                                    <button class="no" type="submit" name="qrg_izin_guru_aksi" value="tolak">Tolak</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    qrg_guru_portal_shell_end();
}
