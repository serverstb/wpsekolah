<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. CPT Gaji Guru (menu Akuntansi; juga bisa diakses dari tombol Data Guru)
add_action( 'init', 'qrg_register_gaji_guru_cpt' );
function qrg_register_gaji_guru_cpt() {
    register_post_type( 'master_gaji_guru', [
        'labels' => [
            'name'          => 'Gaji Guru',
            'singular_name' => 'Gaji Guru',
            'menu_name'     => 'Gaji Guru',
            'add_new_item'  => 'Tambah Gaji Guru',
            'edit_item'     => 'Edit Gaji Guru',
            'all_items'     => 'Gaji Guru',
            'add_new'       => 'Tambah Gaji',
        ],
        'public'             => false,
        'publicly_queryable' => false,
        'exclude_from_search'=> true,
        'show_ui'            => true,
        'show_in_menu'       => false, // Akses via Sekolah → Akuntansi / tombol Data Guru
        'supports'           => [ 'title' ],
        'has_archive'        => false,
    ] );
}

add_filter( 'parent_file', 'qrg_gaji_guru_parent_file' );
function qrg_gaji_guru_parent_file( $parent_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_gaji_guru' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_gaji_guru_submenu_file' );
function qrg_gaji_guru_submenu_file( $submenu_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->post_type === 'master_gaji_guru' ) {
        return 'edit.php?post_type=master_guru';
    }
    return $submenu_file;
}

add_action( 'admin_footer-edit.php', 'qrg_gaji_guru_header_buttons' );
add_action( 'admin_footer-post.php', 'qrg_gaji_guru_header_buttons' );
add_action( 'admin_footer-post-new.php', 'qrg_gaji_guru_header_buttons' );
function qrg_gaji_guru_header_buttons() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_gaji_guru' ) {
        return;
    }
    $guru_url     = admin_url( 'edit.php?post_type=master_guru' );
    $akun_url     = admin_url( 'admin.php?page=qrg-akuntansi' );
    $rumus_url    = admin_url( 'admin.php?page=qrg-rumus-gaji' );
    $generate_url = admin_url( 'admin.php?page=qrg-generate-gaji' );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) return;
        var $existing = $wrap.siblings('.page-title-action').first();
        var $akun = $('<a href="<?php echo esc_url( $akun_url ); ?>" class="page-title-action">← Keuangan</a>');
        var $back = $('<a href="<?php echo esc_url( $guru_url ); ?>" class="page-title-action">← Data Guru</a>');
        var $rumus = $('<a href="<?php echo esc_url( $rumus_url ); ?>" class="page-title-action">Rumus Gaji</a>');
        var $generate = $('<a href="<?php echo esc_url( $generate_url ); ?>" class="page-title-action">Generate Gaji</a>');
        if ($existing.length) {
            $akun.insertBefore($existing);
            $back.insertAfter($akun);
            $rumus.insertAfter($existing);
            $generate.insertAfter($rumus);
        } else {
            $akun.insertAfter($wrap);
            $back.insertAfter($akun);
            $rumus.insertAfter($back);
            $generate.insertAfter($rumus);
        }
    });
    </script>
    <style>
        body.post-type-master_gaji_guru #titlediv { display: none; }
    </style>
    <?php
}

add_filter( 'post_row_actions', 'qrg_gaji_guru_row_actions', 10, 2 );
function qrg_gaji_guru_row_actions( $actions, $post ) {
    if ( $post->post_type === 'master_gaji_guru' ) {
        unset( $actions['view'] );
        unset( $actions['inline hide-if-no-js'] );
    }
    return $actions;
}

/**
 * Agregasi statistik gaji untuk dashboard daftar.
 *
 * @param int|null $filter_tahun Tahun tertentu, atau null = semua.
 * @return array
 */
function qrg_gaji_get_dashboard_stats( $filter_tahun = null ) {
    $ids = get_posts( [
        'post_type'      => 'master_gaji_guru',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );

    $bulan_labels = qrg_gaji_guru_bulan_options();
    $stats        = [
        'total_slip'      => 0,
        'total_gaji'      => 0,
        'dibayar_n'       => 0,
        'dibayar_sum'     => 0,
        'belum_n'         => 0,
        'belum_sum'       => 0,
        'guru_ids'        => [],
        'per_bulan'       => [],
        'tahun_tersedia'  => [],
        'filter_tahun'    => $filter_tahun ? (int) $filter_tahun : null,
        'rata_rata'       => 0,
    ];

    foreach ( $ids as $post_id ) {
        $tahun  = (int) get_post_meta( $post_id, '_gaji_tahun', true );
        $bulan  = (string) get_post_meta( $post_id, '_gaji_bulan', true );
        $status = get_post_meta( $post_id, '_gaji_status', true );
        $total  = (int) get_post_meta( $post_id, '_gaji_total', true );
        $guru   = (int) get_post_meta( $post_id, '_gaji_guru_id', true );

        if ( $tahun ) {
            $stats['tahun_tersedia'][ $tahun ] = true;
        }

        if ( $filter_tahun && (int) $filter_tahun !== $tahun ) {
            continue;
        }

        if ( strlen( $bulan ) === 1 ) {
            $bulan = str_pad( $bulan, 2, '0', STR_PAD_LEFT );
        }
        if ( $bulan === '' ) {
            $bulan = '00';
        }

        $stats['total_slip']++;
        $stats['total_gaji'] += max( 0, $total );
        if ( $guru ) {
            $stats['guru_ids'][ $guru ] = true;
        }

        if ( $status === 'Dibayar' ) {
            $stats['dibayar_n']++;
            $stats['dibayar_sum'] += max( 0, $total );
        } else {
            $stats['belum_n']++;
            $stats['belum_sum'] += max( 0, $total );
        }

        $key = sprintf( '%04d-%s', $tahun ?: 0, $bulan );
        if ( ! isset( $stats['per_bulan'][ $key ] ) ) {
            $stats['per_bulan'][ $key ] = [
                'tahun'  => $tahun,
                'bulan'  => $bulan,
                'label'  => ( isset( $bulan_labels[ $bulan ] ) ? $bulan_labels[ $bulan ] : $bulan ) . ( $tahun ? ' ' . $tahun : '' ),
                'n'      => 0,
                'sum'    => 0,
                'dibayar'=> 0,
                'belum'  => 0,
            ];
        }
        $stats['per_bulan'][ $key ]['n']++;
        $stats['per_bulan'][ $key ]['sum'] += max( 0, $total );
        if ( $status === 'Dibayar' ) {
            $stats['per_bulan'][ $key ]['dibayar'] += max( 0, $total );
        } else {
            $stats['per_bulan'][ $key ]['belum'] += max( 0, $total );
        }
    }

    krsort( $stats['per_bulan'] );
    krsort( $stats['tahun_tersedia'] );
    $stats['tahun_tersedia'] = array_map( 'intval', array_keys( $stats['tahun_tersedia'] ) );
    $stats['jumlah_guru']    = count( $stats['guru_ids'] );
    unset( $stats['guru_ids'] );
    $stats['rata_rata'] = $stats['total_slip'] > 0
        ? (int) round( $stats['total_gaji'] / $stats['total_slip'] )
        : 0;

    return $stats;
}

function qrg_gaji_format_rp( $n ) {
    return 'Rp ' . number_format( max( 0, (int) $n ), 0, ',', '.' );
}

// Dashboard statistik di halaman daftar Gaji Guru
add_action( 'admin_notices', 'qrg_gaji_guru_dashboard_notice' );
function qrg_gaji_guru_dashboard_notice() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_gaji_guru' ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $tahun_now = (int) current_time( 'Y' );
    $filter_tahun = isset( $_GET['gaji_dash_tahun'] ) ? absint( $_GET['gaji_dash_tahun'] ) : $tahun_now;
    if ( isset( $_GET['gaji_dash_tahun'] ) && $_GET['gaji_dash_tahun'] === 'all' ) {
        $filter_tahun = null;
    }

    $stats  = qrg_gaji_get_dashboard_stats( $filter_tahun );
    $years  = $stats['tahun_tersedia'];
    if ( empty( $years ) ) {
        $years = [ $tahun_now ];
    }
    if ( $filter_tahun && ! in_array( $filter_tahun, $years, true ) ) {
        $years[] = $filter_tahun;
        rsort( $years );
    }

    $max_bar  = 0;
    foreach ( $stats['per_bulan'] as $row ) {
        $max_bar = max( $max_bar, (int) $row['sum'] );
    }
    if ( $max_bar < 1 ) {
        $max_bar = 1;
    }
    ?>
    <div class="qrg-gaji-dash wrap" style="margin:12px 0 8px;">
        <div class="qrg-gaji-dash__head">
            <div>
                <h2 class="qrg-gaji-dash__title">Dashboard Gaji Guru</h2>
                <p class="qrg-gaji-dash__sub">Ringkasan slip &amp; total gaji<?php echo $filter_tahun ? ' tahun ' . (int) $filter_tahun : ' (semua tahun)'; ?>.</p>
            </div>
            <form method="get" class="qrg-gaji-dash__filter" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
                <input type="hidden" name="post_type" value="master_gaji_guru">
                <label for="gaji_dash_tahun">Tahun</label>
                <select name="gaji_dash_tahun" id="gaji_dash_tahun" onchange="this.form.submit()">
                    <option value="all" <?php selected( $filter_tahun === null ); ?>>Semua</option>
                    <?php foreach ( $years as $y ) : ?>
                        <option value="<?php echo (int) $y; ?>" <?php selected( $filter_tahun, (int) $y ); ?>><?php echo (int) $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="qrg-gaji-dash__cards">
            <div class="qrg-gaji-dash__card">
                <span class="qrg-gaji-dash__label">Total Slip</span>
                <strong class="qrg-gaji-dash__value"><?php echo (int) $stats['total_slip']; ?></strong>
                <span class="qrg-gaji-dash__hint"><?php echo (int) $stats['jumlah_guru']; ?> guru</span>
            </div>
            <div class="qrg-gaji-dash__card qrg-gaji-dash__card--main">
                <span class="qrg-gaji-dash__label">Total Gaji</span>
                <strong class="qrg-gaji-dash__value"><?php echo esc_html( qrg_gaji_format_rp( $stats['total_gaji'] ) ); ?></strong>
                <span class="qrg-gaji-dash__hint">Rata-rata <?php echo esc_html( qrg_gaji_format_rp( $stats['rata_rata'] ) ); ?>/slip</span>
            </div>
            <div class="qrg-gaji-dash__card qrg-gaji-dash__card--ok">
                <span class="qrg-gaji-dash__label">Sudah Dibayar</span>
                <strong class="qrg-gaji-dash__value"><?php echo esc_html( qrg_gaji_format_rp( $stats['dibayar_sum'] ) ); ?></strong>
                <span class="qrg-gaji-dash__hint"><?php echo (int) $stats['dibayar_n']; ?> slip</span>
            </div>
            <div class="qrg-gaji-dash__card qrg-gaji-dash__card--warn">
                <span class="qrg-gaji-dash__label">Belum Dibayar</span>
                <strong class="qrg-gaji-dash__value"><?php echo esc_html( qrg_gaji_format_rp( $stats['belum_sum'] ) ); ?></strong>
                <span class="qrg-gaji-dash__hint"><?php echo (int) $stats['belum_n']; ?> slip</span>
            </div>
        </div>

        <div class="qrg-gaji-dash__month">
            <h3>Total Gaji per Bulan</h3>
            <?php if ( empty( $stats['per_bulan'] ) ) : ?>
                <p class="qrg-gaji-dash__empty">Belum ada data gaji untuk ditampilkan.</p>
            <?php else : ?>
                <table class="widefat striped qrg-gaji-dash__table">
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <th style="width:70px;">Slip</th>
                            <th style="width:38%;">Proporsi</th>
                            <th style="text-align:right;">Total</th>
                            <th style="text-align:right;">Dibayar</th>
                            <th style="text-align:right;">Belum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $stats['per_bulan'] as $row ) :
                            $pct = (int) round( ( (int) $row['sum'] / $max_bar ) * 100 );
                            $list_bulan_url = add_query_arg(
                                [
                                    'post_type'   => 'master_gaji_guru',
                                    'gaji_bulan'  => $row['bulan'],
                                    'gaji_tahun'  => $row['tahun'],
                                ],
                                admin_url( 'edit.php' )
                            );
                            ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( $list_bulan_url ); ?>"><strong><?php echo esc_html( $row['label'] ); ?></strong></a>
                            </td>
                            <td><?php echo (int) $row['n']; ?></td>
                            <td>
                                <div class="qrg-gaji-dash__bar-wrap" title="<?php echo esc_attr( qrg_gaji_format_rp( $row['sum'] ) ); ?>">
                                    <div class="qrg-gaji-dash__bar" style="width:<?php echo max( 4, $pct ); ?>%;"></div>
                                </div>
                            </td>
                            <td style="text-align:right;"><strong><?php echo esc_html( qrg_gaji_format_rp( $row['sum'] ) ); ?></strong></td>
                            <td style="text-align:right;color:#166534;"><?php echo esc_html( qrg_gaji_format_rp( $row['dibayar'] ) ); ?></td>
                            <td style="text-align:right;color:#9a3412;"><?php echo esc_html( qrg_gaji_format_rp( $row['belum'] ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <style>
        .qrg-gaji-dash { max-width: 1100px; }
        .qrg-gaji-dash__head {
            display: flex; justify-content: space-between; align-items: flex-end;
            gap: 12px; flex-wrap: wrap; margin-bottom: 12px;
        }
        .qrg-gaji-dash__title { margin: 0; font-size: 16px; }
        .qrg-gaji-dash__sub { margin: 4px 0 0; color: #64748b; }
        .qrg-gaji-dash__filter { display: flex; align-items: center; gap: 8px; }
        .qrg-gaji-dash__filter label { font-weight: 600; color: #475569; }
        .qrg-gaji-dash__cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }
        .qrg-gaji-dash__card {
            background: #fff;
            border: 1px solid #d0d7de;
            border-radius: 10px;
            padding: 12px 14px;
            box-shadow: 0 1px 2px rgba(15,23,42,.04);
        }
        .qrg-gaji-dash__card--main { border-color: #93c5fd; background: #f8fbff; }
        .qrg-gaji-dash__card--ok { border-color: #86efac; background: #f0fdf4; }
        .qrg-gaji-dash__card--warn { border-color: #fdba74; background: #fff7ed; }
        .qrg-gaji-dash__label {
            display: block; font-size: 12px; color: #64748b; margin-bottom: 4px;
        }
        .qrg-gaji-dash__value {
            display: block; font-size: 18px; line-height: 1.25; color: #0f172a;
        }
        .qrg-gaji-dash__hint {
            display: block; margin-top: 4px; font-size: 12px; color: #64748b;
        }
        .qrg-gaji-dash__month {
            background: #fff;
            border: 1px solid #d0d7de;
            border-radius: 10px;
            padding: 14px 16px;
        }
        .qrg-gaji-dash__month h3 { margin: 0 0 10px; font-size: 14px; }
        .qrg-gaji-dash__empty { color: #64748b; margin: 0; }
        .qrg-gaji-dash__table { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        .qrg-gaji-dash__table th, .qrg-gaji-dash__table td { padding: 8px 10px; vertical-align: middle; }
        .qrg-gaji-dash__bar-wrap {
            height: 10px; background: #eef2f7; border-radius: 999px; overflow: hidden;
        }
        .qrg-gaji-dash__bar {
            height: 100%; background: linear-gradient(90deg, #2563eb, #38bdf8);
            border-radius: 999px;
        }
    </style>
    <?php
}

// Filter daftar gaji dari link periode dashboard
add_action( 'pre_get_posts', 'qrg_gaji_filter_list_by_periode' );
function qrg_gaji_filter_list_by_periode( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( $query->get( 'post_type' ) !== 'master_gaji_guru' ) {
        return;
    }
    $bulan = isset( $_GET['gaji_bulan'] ) ? sanitize_text_field( wp_unslash( $_GET['gaji_bulan'] ) ) : '';
    $tahun = isset( $_GET['gaji_tahun'] ) ? absint( $_GET['gaji_tahun'] ) : 0;
    if ( $bulan === '' && ! $tahun ) {
        return;
    }
    $meta_query = (array) $query->get( 'meta_query' );
    if ( $bulan !== '' ) {
        $bulan_ok = array_keys( qrg_gaji_guru_bulan_options() );
        if ( in_array( $bulan, $bulan_ok, true ) ) {
            $meta_query[] = [
                'key'   => '_gaji_bulan',
                'value' => $bulan,
            ];
        }
    }
    if ( $tahun ) {
        $meta_query[] = [
            'key'   => '_gaji_tahun',
            'value' => (string) $tahun,
        ];
    }
    $query->set( 'meta_query', $meta_query );
}

function qrg_gaji_guru_status_options() {
    return [
        'Belum Dibayar' => 'Belum Dibayar',
        'Dibayar'       => 'Dibayar',
    ];
}

function qrg_gaji_guru_bulan_options() {
    return [
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember',
    ];
}

/**
 * Basis perhitungan kolom per hari.
 */
function qrg_gaji_basis_options() {
    return [
        'hadir' => 'Hari Hadir',
        'izin'  => 'Hari Izin',
        'sakit' => 'Hari Sakit',
        'alpha' => 'Hari Alpha',
    ];
}

/**
 * Kolom rumus default (dinamis).
 */
function qrg_gaji_default_columns() {
    return [
        [
            'id'       => 'gaji_pokok',
            'label'    => 'Gaji Pokok',
            'tipe'     => 'tetap',
            'operator' => 'plus',
            'nilai'    => 3000000,
            'basis'    => '',
        ],
        [
            'id'       => 'tunjangan_jabatan',
            'label'    => 'Tunjangan Jabatan',
            'tipe'     => 'dari_jabatan',
            'operator' => 'plus',
            'nilai'    => 0,
            'basis'    => '',
        ],
        [
            'id'       => 'tunjangan_hadir',
            'label'    => 'Tunjangan Kehadiran',
            'tipe'     => 'per_hari',
            'operator' => 'plus',
            'nilai'    => 25000,
            'basis'    => 'hadir',
        ],
        [
            'id'       => 'potongan_alpha',
            'label'    => 'Potongan Alpha',
            'tipe'     => 'per_hari',
            'operator' => 'minus',
            'nilai'    => 50000,
            'basis'    => 'alpha',
        ],
        [
            'id'       => 'potongan_izin',
            'label'    => 'Potongan Izin',
            'tipe'     => 'per_hari',
            'operator' => 'minus',
            'nilai'    => 0,
            'basis'    => 'izin',
        ],
        [
            'id'       => 'potongan_sakit',
            'label'    => 'Potongan Sakit',
            'tipe'     => 'per_hari',
            'operator' => 'minus',
            'nilai'    => 0,
            'basis'    => 'sakit',
        ],
    ];
}

/**
 * Migrasi rumus lama (field tetap) ke format kolom dinamis.
 */
function qrg_gaji_migrate_rumus_columns( $saved ) {
    if ( ! empty( $saved['columns'] ) && is_array( $saved['columns'] ) ) {
        return $saved;
    }

    $columns = [
        [
            'id'       => 'gaji_pokok',
            'label'    => 'Gaji Pokok',
            'tipe'     => 'tetap',
            'operator' => 'plus',
            'nilai'    => isset( $saved['gaji_pokok'] ) ? (int) $saved['gaji_pokok'] : 3000000,
            'basis'    => '',
        ],
        [
            'id'       => 'tunjangan_jabatan',
            'label'    => 'Tunjangan Jabatan',
            'tipe'     => 'dari_jabatan',
            'operator' => 'plus',
            'nilai'    => isset( $saved['tunjangan_jabatan'] ) ? (int) $saved['tunjangan_jabatan'] : 0,
            'basis'    => '',
        ],
        [
            'id'       => 'tunjangan_hadir',
            'label'    => 'Tunjangan Kehadiran',
            'tipe'     => 'per_hari',
            'operator' => 'plus',
            'nilai'    => isset( $saved['tunjangan_per_hadir'] ) ? (int) $saved['tunjangan_per_hadir'] : 25000,
            'basis'    => 'hadir',
        ],
        [
            'id'       => 'potongan_alpha',
            'label'    => 'Potongan Alpha',
            'tipe'     => 'per_hari',
            'operator' => 'minus',
            'nilai'    => isset( $saved['potongan_per_alpha'] ) ? (int) $saved['potongan_per_alpha'] : 50000,
            'basis'    => 'alpha',
        ],
        [
            'id'       => 'potongan_izin',
            'label'    => 'Potongan Izin',
            'tipe'     => 'per_hari',
            'operator' => 'minus',
            'nilai'    => isset( $saved['potongan_per_izin'] ) ? (int) $saved['potongan_per_izin'] : 0,
            'basis'    => 'izin',
        ],
        [
            'id'       => 'potongan_sakit',
            'label'    => 'Potongan Sakit',
            'tipe'     => 'per_hari',
            'operator' => 'minus',
            'nilai'    => isset( $saved['potongan_per_sakit'] ) ? (int) $saved['potongan_per_sakit'] : 0,
            'basis'    => 'sakit',
        ],
    ];

    return [
        'hari_kerja_standar' => isset( $saved['hari_kerja_standar'] ) ? (int) $saved['hari_kerja_standar'] : 22,
        'columns'            => $columns,
    ];
}

/**
 * Ambil rumus gaji (format kolom dinamis).
 */
function qrg_get_gaji_rumus() {
    $saved = get_option( 'qrg_gaji_rumus', [] );
    if ( ! is_array( $saved ) ) {
        $saved = [];
    }

    if ( empty( $saved['columns'] ) || ! is_array( $saved['columns'] ) ) {
        if ( ! empty( $saved ) ) {
            $saved = qrg_gaji_migrate_rumus_columns( $saved );
            update_option( 'qrg_gaji_rumus', $saved );
        } else {
            $saved = [
                'hari_kerja_standar' => 22,
                'columns'            => qrg_gaji_default_columns(),
            ];
        }
    }

    // Pastikan kolom Tunjangan Jabatan terintegrasi Master Jabatan
    foreach ( $saved['columns'] as $i => $col ) {
        if ( isset( $col['id'] ) && $col['id'] === 'tunjangan_jabatan' ) {
            $saved['columns'][ $i ]['tipe'] = 'dari_jabatan';
        }
    }

    // Kompatibilitas akses lama
    $saved['gaji_pokok']          = 0;
    $saved['tunjangan_jabatan']   = 0;
    $saved['tunjangan_per_hadir'] = 0;
    $saved['potongan_per_alpha']  = 0;
    $saved['potongan_per_izin']   = 0;
    $saved['potongan_per_sakit']  = 0;
    foreach ( $saved['columns'] as $col ) {
        $id = isset( $col['id'] ) ? $col['id'] : '';
        if ( $id === 'gaji_pokok' ) {
            $saved['gaji_pokok'] = (int) $col['nilai'];
        } elseif ( $id === 'tunjangan_jabatan' ) {
            $saved['tunjangan_jabatan'] = (int) $col['nilai'];
        } elseif ( ! empty( $col['basis'] ) && $col['basis'] === 'hadir' && $col['operator'] === 'plus' ) {
            $saved['tunjangan_per_hadir'] = (int) $col['nilai'];
        } elseif ( ! empty( $col['basis'] ) && $col['basis'] === 'alpha' && $col['operator'] === 'minus' ) {
            $saved['potongan_per_alpha'] = (int) $col['nilai'];
        } elseif ( ! empty( $col['basis'] ) && $col['basis'] === 'izin' && $col['operator'] === 'minus' ) {
            $saved['potongan_per_izin'] = (int) $col['nilai'];
        } elseif ( ! empty( $col['basis'] ) && $col['basis'] === 'sakit' && $col['operator'] === 'minus' ) {
            $saved['potongan_per_sakit'] = (int) $col['nilai'];
        }
    }
    if ( empty( $saved['hari_kerja_standar'] ) ) {
        $saved['hari_kerja_standar'] = 22;
    }

    return $saved;
}

/**
 * Sanitasi daftar kolom dari POST.
 */
function qrg_gaji_sanitize_columns_from_post() {
    $labels    = isset( $_POST['col_label'] ) && is_array( $_POST['col_label'] ) ? $_POST['col_label'] : [];
    $tipes     = isset( $_POST['col_tipe'] ) && is_array( $_POST['col_tipe'] ) ? $_POST['col_tipe'] : [];
    $operators = isset( $_POST['col_operator'] ) && is_array( $_POST['col_operator'] ) ? $_POST['col_operator'] : [];
    $nilais    = isset( $_POST['col_nilai'] ) && is_array( $_POST['col_nilai'] ) ? $_POST['col_nilai'] : [];
    $basises   = isset( $_POST['col_basis'] ) && is_array( $_POST['col_basis'] ) ? $_POST['col_basis'] : [];
    $ids       = isset( $_POST['col_id'] ) && is_array( $_POST['col_id'] ) ? $_POST['col_id'] : [];

    $basis_ok = array_keys( qrg_gaji_basis_options() );
    $used     = [];
    $columns  = [];

    foreach ( $labels as $i => $label_raw ) {
        $label = sanitize_text_field( wp_unslash( $label_raw ) );
        if ( $label === '' ) {
            continue;
        }

        $tipe = isset( $tipes[ $i ] ) ? sanitize_text_field( wp_unslash( $tipes[ $i ] ) ) : 'tetap';
        if ( ! in_array( $tipe, [ 'tetap', 'per_hari', 'dari_jabatan' ], true ) ) {
            $tipe = 'tetap';
        }

        $operator = isset( $operators[ $i ] ) ? sanitize_text_field( wp_unslash( $operators[ $i ] ) ) : 'plus';
        if ( ! in_array( $operator, [ 'plus', 'minus' ], true ) ) {
            $operator = 'plus';
        }

        $nilai = isset( $nilais[ $i ] ) ? absint( $nilais[ $i ] ) : 0;
        $basis = '';
        if ( $tipe === 'per_hari' ) {
            $basis = isset( $basises[ $i ] ) ? sanitize_text_field( wp_unslash( $basises[ $i ] ) ) : 'hadir';
            if ( ! in_array( $basis, $basis_ok, true ) ) {
                $basis = 'hadir';
            }
        }

        $id = isset( $ids[ $i ] ) ? sanitize_title( wp_unslash( $ids[ $i ] ) ) : '';
        if ( $id === '' ) {
            $id = sanitize_title( $label );
        }
        if ( $id === '' ) {
            $id = 'kolom_' . ( $i + 1 );
        }

        // Kolom khusus Tunjangan Jabatan selalu dari Master Jabatan
        if ( $id === 'tunjangan_jabatan' ) {
            $tipe  = 'dari_jabatan';
            $basis = '';
        }
        $base = $id;
        $n    = 2;
        while ( isset( $used[ $id ] ) ) {
            $id = $base . '_' . $n;
            $n++;
        }
        $used[ $id ] = true;

        $columns[] = [
            'id'       => $id,
            'label'    => $label,
            'tipe'     => $tipe,
            'operator' => $operator,
            'nilai'    => $nilai,
            'basis'    => $basis,
        ];
    }

    return $columns;
}

/**
 * Hitung ringkasan kehadiran guru per bulan.
 */
function qrg_gaji_hitung_kehadiran( $guru_id, $bulan, $tahun ) {
    $result = [
        'hadir' => 0,
        'izin'  => 0,
        'sakit' => 0,
        'alpha' => 0,
        'total' => 0,
    ];
    $guru_id = (int) $guru_id;
    $bulan   = str_pad( (string) $bulan, 2, '0', STR_PAD_LEFT );
    $tahun   = (int) $tahun;
    if ( $guru_id <= 0 || $tahun <= 0 ) {
        return $result;
    }

    $start = sprintf( '%04d-%02d-01', $tahun, (int) $bulan );
    $end   = date( 'Y-m-t', strtotime( $start ) );

    $ids = get_posts( [
        'post_type'      => 'master_presensi_guru',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => '_presensi_guru_id',
                'value' => (string) $guru_id,
            ],
            [
                'key'     => '_presensi_tanggal',
                'value'   => [ $start, $end ],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ],
        ],
    ] );

    foreach ( $ids as $pid ) {
        $status = get_post_meta( $pid, '_presensi_status', true );
        $key    = strtolower( (string) $status );
        if ( isset( $result[ $key ] ) ) {
            $result[ $key ]++;
        }
        $result['total']++;
    }

    return $result;
}

/**
 * Hitung gaji dari kolom dinamis.
 *
 * @param array $args overrides[id=>nilai], hadir/izin/sakit/alpha, rumus, guru_id.
 * @return array{items:array,total:int,tunjangan_total:int,potongan_total:int,hadir:int,...}
 */
function qrg_gaji_hitung_komponen( $args ) {
    $rumus     = isset( $args['rumus'] ) && is_array( $args['rumus'] ) ? $args['rumus'] : qrg_get_gaji_rumus();
    $columns   = ! empty( $rumus['columns'] ) && is_array( $rumus['columns'] ) ? $rumus['columns'] : qrg_gaji_default_columns();
    $overrides = isset( $args['overrides'] ) && is_array( $args['overrides'] ) ? $args['overrides'] : [];
    $guru_id   = isset( $args['guru_id'] ) ? (int) $args['guru_id'] : 0;

    $hadir = isset( $args['hadir'] ) ? (int) $args['hadir'] : 0;
    $izin  = isset( $args['izin'] ) ? (int) $args['izin'] : 0;
    $sakit = isset( $args['sakit'] ) ? (int) $args['sakit'] : 0;
    $alpha = isset( $args['alpha'] ) ? (int) $args['alpha'] : 0;
    $hari  = [
        'hadir' => $hadir,
        'izin'  => $izin,
        'sakit' => $sakit,
        'alpha' => $alpha,
    ];

    // Kompatibilitas pemanggilan lama
    if ( isset( $args['gaji_pokok'] ) ) {
        $overrides['gaji_pokok'] = (int) $args['gaji_pokok'];
    }
    if ( isset( $args['tunjangan_jabatan'] ) ) {
        $overrides['tunjangan_jabatan'] = (int) $args['tunjangan_jabatan'];
    }

    $items             = [];
    $total             = 0;
    $tunjangan_total   = 0;
    $potongan_total    = 0;
    $gaji_pokok        = 0;
    $tunjangan_jabatan = 0;
    $tunjangan_hadir   = 0;
    $potongan_absen    = 0;

    $guru_jabatan_id    = $guru_id ? (int) get_post_meta( $guru_id, '_guru_jabatan_id', true ) : 0;
    $guru_jabatan_label = $guru_jabatan_id && function_exists( 'qrg_get_jabatan_label' )
        ? qrg_get_jabatan_label( $guru_jabatan_id )
        : '';

    foreach ( $columns as $col ) {
        $id       = isset( $col['id'] ) ? $col['id'] : '';
        $label    = isset( $col['label'] ) ? $col['label'] : $id;
        $tipe     = isset( $col['tipe'] ) ? $col['tipe'] : 'tetap';
        $operator = isset( $col['operator'] ) ? $col['operator'] : 'plus';
        $nilai    = isset( $col['nilai'] ) ? (int) $col['nilai'] : 0;
        $basis    = isset( $col['basis'] ) ? $col['basis'] : '';

        if ( $id === 'tunjangan_jabatan' ) {
            $tipe = 'dari_jabatan';
        }

        if ( $tipe === 'dari_jabatan' ) {
            if ( array_key_exists( $id, $overrides ) ) {
                $nilai = (int) $overrides[ $id ];
            } elseif ( $guru_id && function_exists( 'qrg_get_tunjangan_jabatan_for_guru' ) ) {
                $nilai = qrg_get_tunjangan_jabatan_for_guru( $guru_id, $nilai );
            }
            if ( $guru_jabatan_label ) {
                $label = $label . ' (' . $guru_jabatan_label . ')';
            }
        } elseif ( $tipe === 'tetap' && array_key_exists( $id, $overrides ) ) {
            $nilai = (int) $overrides[ $id ];
        }

        $qty    = 1;
        $jumlah = $nilai;
        if ( $tipe === 'per_hari' ) {
            $qty    = isset( $hari[ $basis ] ) ? (int) $hari[ $basis ] : 0;
            $jumlah = $qty * $nilai;
        }

        $signed = ( $operator === 'minus' ) ? -$jumlah : $jumlah;
        $total += $signed;

        if ( $operator === 'plus' ) {
            $tunjangan_total += $jumlah;
        } else {
            $potongan_total += $jumlah;
        }

        if ( $id === 'gaji_pokok' ) {
            $gaji_pokok = $jumlah;
            $tunjangan_total -= $jumlah;
        } elseif ( $id === 'tunjangan_jabatan' || $tipe === 'dari_jabatan' ) {
            $tunjangan_jabatan = $jumlah;
        } elseif ( $tipe === 'per_hari' && $basis === 'hadir' && $operator === 'plus' ) {
            $tunjangan_hadir += $jumlah;
        } elseif ( $tipe === 'per_hari' && $operator === 'minus' ) {
            $potongan_absen += $jumlah;
        }

        $items[] = [
            'id'       => $id,
            'label'    => $label,
            'tipe'     => $tipe === 'dari_jabatan' ? 'dari_jabatan' : $tipe,
            'operator' => $operator,
            'nilai'    => $nilai,
            'basis'    => $basis,
            'qty'      => $qty,
            'jumlah'   => $jumlah,
            'signed'   => $signed,
        ];
    }

    // Kompatibilitas field lama tunjangan_lain / potongan_lain
    $tunjangan_lain = isset( $args['tunjangan_lain'] ) ? (int) $args['tunjangan_lain'] : 0;
    $potongan_lain  = isset( $args['potongan_lain'] ) ? (int) $args['potongan_lain'] : 0;
    if ( $tunjangan_lain > 0 ) {
        $items[] = [
            'id' => 'tunjangan_lain', 'label' => 'Tunjangan Lain', 'tipe' => 'tetap',
            'operator' => 'plus', 'nilai' => $tunjangan_lain, 'basis' => '', 'qty' => 1,
            'jumlah' => $tunjangan_lain, 'signed' => $tunjangan_lain,
        ];
        $total += $tunjangan_lain;
        $tunjangan_total += $tunjangan_lain;
    }
    if ( $potongan_lain > 0 ) {
        $items[] = [
            'id' => 'potongan_lain', 'label' => 'Potongan Lain', 'tipe' => 'tetap',
            'operator' => 'minus', 'nilai' => $potongan_lain, 'basis' => '', 'qty' => 1,
            'jumlah' => $potongan_lain, 'signed' => -$potongan_lain,
        ];
        $total -= $potongan_lain;
        $potongan_total += $potongan_lain;
    }

    return [
        'items'             => $items,
        'total'             => max( 0, $total ),
        'tunjangan_total'   => max( 0, $tunjangan_total ),
        'potongan_total'    => max( 0, $potongan_total ),
        'gaji_pokok'        => $gaji_pokok,
        'tunjangan_jabatan' => $tunjangan_jabatan,
        'tunjangan_hadir'   => $tunjangan_hadir,
        'tunjangan_lain'    => $tunjangan_lain,
        'potongan_absen'    => $potongan_absen,
        'potongan_lain'     => $potongan_lain,
        'hadir'             => $hadir,
        'izin'              => $izin,
        'sakit'             => $sakit,
        'alpha'             => $alpha,
        'rumus'             => $rumus,
    ];
}

// Halaman pengaturan Rumus Gaji
add_action( 'admin_menu', 'qrg_gaji_rumus_menu', 20 );
function qrg_gaji_rumus_menu() {
    add_submenu_page(
        null,
        'Rumus Gaji Guru',
        'Rumus Gaji',
        'manage_options',
        'qrg-rumus-gaji',
        'qrg_gaji_rumus_page_callback'
    );
}

add_filter( 'parent_file', 'qrg_gaji_rumus_parent_file' );
function qrg_gaji_rumus_parent_file( $parent_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-rumus-gaji' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_gaji_rumus_submenu_file' );
function qrg_gaji_rumus_submenu_file( $submenu_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-rumus-gaji' ) {
        return 'edit.php?post_type=master_guru';
    }
    return $submenu_file;
}

/**
 * Generate slip gaji untuk semua guru pada periode tertentu.
 *
 * @param string $bulan MM.
 * @param int    $tahun YYYY.
 * @param array  $args  skip_existing (default true), status (default Belum Dibayar).
 * @return array{created:int,skipped:int,errors:int,total_guru:int,periode:string}
 */
function qrg_gaji_generate_massal( $bulan, $tahun, $args = [] ) {
    $bulan = str_pad( (string) $bulan, 2, '0', STR_PAD_LEFT );
    $tahun = (int) $tahun;
    $skip_existing = ! isset( $args['skip_existing'] ) || ! empty( $args['skip_existing'] );
    $status        = isset( $args['status'] ) ? $args['status'] : 'Belum Dibayar';
    $status_ok     = array_keys( qrg_gaji_guru_status_options() );
    if ( ! in_array( $status, $status_ok, true ) ) {
        $status = 'Belum Dibayar';
    }

    $bulan_ok = array_keys( qrg_gaji_guru_bulan_options() );
    if ( ! in_array( $bulan, $bulan_ok, true ) ) {
        $bulan = current_time( 'm' );
    }
    if ( $tahun < 2000 || $tahun > 2100 ) {
        $tahun = (int) current_time( 'Y' );
    }

    $guru_ids = get_posts( [
        'post_type'      => 'master_guru',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $rumus       = qrg_get_gaji_rumus();
    $columns     = ! empty( $rumus['columns'] ) ? $rumus['columns'] : qrg_gaji_default_columns();
    $bulan_label = qrg_gaji_guru_bulan_options();
    $periode     = ( isset( $bulan_label[ $bulan ] ) ? $bulan_label[ $bulan ] : $bulan ) . ' ' . $tahun;

    $created = 0;
    $skipped = 0;
    $errors  = 0;

    foreach ( $guru_ids as $guru_id ) {
        $guru_id = (int) $guru_id;

        $existing = get_posts( [
            'post_type'      => 'master_gaji_guru',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => '_gaji_guru_id',
                    'value' => (string) $guru_id,
                ],
                [
                    'key'   => '_gaji_bulan',
                    'value' => $bulan,
                ],
                [
                    'key'   => '_gaji_tahun',
                    'value' => (string) $tahun,
                ],
            ],
        ] );

        if ( ! empty( $existing ) ) {
            if ( $skip_existing ) {
                $skipped++;
                continue;
            }
        }

        $kehadiran = qrg_gaji_hitung_kehadiran( $guru_id, $bulan, $tahun );
        $overrides = [];
        foreach ( $columns as $col ) {
            $col_id   = isset( $col['id'] ) ? $col['id'] : '';
            $col_tipe = isset( $col['tipe'] ) ? $col['tipe'] : 'tetap';
            if ( $col_id === 'tunjangan_jabatan' || $col_tipe === 'dari_jabatan' ) {
                $overrides[ $col_id ] = function_exists( 'qrg_get_tunjangan_jabatan_for_guru' )
                    ? qrg_get_tunjangan_jabatan_for_guru( $guru_id, isset( $col['nilai'] ) ? (int) $col['nilai'] : 0 )
                    : (int) ( isset( $col['nilai'] ) ? $col['nilai'] : 0 );
                continue;
            }
            if ( $col_tipe !== 'tetap' ) {
                continue;
            }
            $overrides[ $col_id ] = (int) $col['nilai'];
        }

        $hitung = qrg_gaji_hitung_komponen( [
            'overrides' => $overrides,
            'hadir'     => $kehadiran['hadir'],
            'izin'      => $kehadiran['izin'],
            'sakit'     => $kehadiran['sakit'],
            'alpha'     => $kehadiran['alpha'],
            'rumus'     => $rumus,
            'guru_id'   => $guru_id,
        ] );

        $nama_guru = get_the_title( $guru_id );
        $title     = sprintf( 'Gaji %s — %s', $nama_guru, $periode );

        $post_id = wp_insert_post( [
            'post_title'  => $title,
            'post_type'   => 'master_gaji_guru',
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            $errors++;
            continue;
        }

        update_post_meta( $post_id, '_gaji_guru_id', $guru_id );
        update_post_meta( $post_id, '_gaji_bulan', $bulan );
        update_post_meta( $post_id, '_gaji_tahun', $tahun );
        update_post_meta( $post_id, '_gaji_kolom_nilai', $overrides );
        update_post_meta( $post_id, '_gaji_komponen', $hitung['items'] );
        update_post_meta( $post_id, '_gaji_nominal', $hitung['gaji_pokok'] );
        update_post_meta( $post_id, '_gaji_tunjangan_jabatan', $hitung['tunjangan_jabatan'] );
        update_post_meta( $post_id, '_gaji_tunjangan_hadir', $hitung['tunjangan_hadir'] );
        update_post_meta( $post_id, '_gaji_tunjangan', $hitung['tunjangan_total'] );
        update_post_meta( $post_id, '_gaji_potongan_absen', $hitung['potongan_absen'] );
        update_post_meta( $post_id, '_gaji_potongan', $hitung['potongan_total'] );
        update_post_meta( $post_id, '_gaji_total', $hitung['total'] );
        update_post_meta( $post_id, '_gaji_hari_hadir', $kehadiran['hadir'] );
        update_post_meta( $post_id, '_gaji_hari_izin', $kehadiran['izin'] );
        update_post_meta( $post_id, '_gaji_hari_sakit', $kehadiran['sakit'] );
        update_post_meta( $post_id, '_gaji_hari_alpha', $kehadiran['alpha'] );
        update_post_meta( $post_id, '_gaji_status', $status );
        update_post_meta( $post_id, '_gaji_keterangan', 'Generate massal periode ' . $periode );

        $created++;
    }

    return [
        'created'    => $created,
        'skipped'    => $skipped,
        'errors'     => $errors,
        'total_guru' => count( $guru_ids ),
        'periode'    => $periode,
    ];
}

// Halaman Generate Gaji Massal
add_action( 'admin_menu', 'qrg_gaji_generate_menu', 21 );
function qrg_gaji_generate_menu() {
    add_submenu_page(
        null,
        'Generate Gaji Guru',
        'Generate Gaji',
        'manage_options',
        'qrg-generate-gaji',
        'qrg_gaji_generate_page_callback'
    );
}

add_filter( 'parent_file', 'qrg_gaji_generate_parent_file' );
function qrg_gaji_generate_parent_file( $parent_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-generate-gaji' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_gaji_generate_submenu_file' );
function qrg_gaji_generate_submenu_file( $submenu_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-generate-gaji' ) {
        return 'edit.php?post_type=master_guru';
    }
    return $submenu_file;
}

function qrg_gaji_generate_page_callback() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $gaji_url  = admin_url( 'edit.php?post_type=master_gaji_guru' );
    $rumus_url = admin_url( 'admin.php?page=qrg-rumus-gaji' );
    $result    = null;

    if ( isset( $_POST['qrg_do_generate_gaji'] ) && check_admin_referer( 'qrg_generate_gaji_action', 'qrg_generate_gaji_nonce' ) ) {
        $bulan  = isset( $_POST['gaji_bulan'] ) ? sanitize_text_field( wp_unslash( $_POST['gaji_bulan'] ) ) : current_time( 'm' );
        $tahun  = isset( $_POST['gaji_tahun'] ) ? absint( $_POST['gaji_tahun'] ) : (int) current_time( 'Y' );
        $status = isset( $_POST['gaji_status'] ) ? sanitize_text_field( wp_unslash( $_POST['gaji_status'] ) ) : 'Belum Dibayar';

        $result = qrg_gaji_generate_massal( $bulan, $tahun, [
            'skip_existing' => true,
            'status'        => $status,
        ] );

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo 'Generate selesai untuk <strong>' . esc_html( $result['periode'] ) . '</strong>. ';
        echo 'Dibuat: <strong>' . (int) $result['created'] . '</strong>, ';
        echo 'dilewati (sudah ada): <strong>' . (int) $result['skipped'] . '</strong>, ';
        echo 'error: <strong>' . (int) $result['errors'] . '</strong> ';
        echo 'dari <strong>' . (int) $result['total_guru'] . '</strong> guru.';
        echo '</p></div>';
    }

    $count_guru = (int) wp_count_posts( 'master_guru' )->publish;
    $bulan_now  = current_time( 'm' );
    $tahun_now  = (int) current_time( 'Y' );
    ?>
    <div class="wrap">
        <h1>
            Generate Gaji Guru
            <a href="<?php echo esc_url( $gaji_url ); ?>" class="page-title-action">← Gaji Guru</a>
            <a href="<?php echo esc_url( $rumus_url ); ?>" class="page-title-action">Rumus Gaji</a>
        </h1>
        <p>Buat slip gaji sekaligus untuk seluruh guru berdasarkan <strong>Rumus Gaji</strong> dan data <strong>Presensi</strong> pada periode yang dipilih. Guru yang sudah punya slip di periode yang sama akan dilewati.</p>

        <div class="card" style="max-width:560px;margin-top:20px;padding:20px;">
            <p style="margin-top:0;">
                Total guru aktif: <strong><?php echo esc_html( number_format_i18n( $count_guru ) ); ?></strong>
            </p>
            <form method="post" action="" id="qrg-generate-gaji-form">
                <?php wp_nonce_field( 'qrg_generate_gaji_action', 'qrg_generate_gaji_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="gaji_bulan">Periode</label></th>
                        <td>
                            <select name="gaji_bulan" id="gaji_bulan" required>
                                <?php foreach ( qrg_gaji_guru_bulan_options() as $num => $label ) : ?>
                                    <option value="<?php echo esc_attr( $num ); ?>" <?php selected( $bulan_now, $num ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="gaji_tahun" id="gaji_tahun" value="<?php echo esc_attr( $tahun_now ); ?>" min="2000" max="2100" class="small-text" style="margin-left:8px;" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gaji_status">Status Awal</label></th>
                        <td>
                            <select name="gaji_status" id="gaji_status">
                                <?php foreach ( qrg_gaji_guru_status_options() as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="qrg_do_generate_gaji" id="qrg-generate-gaji-btn" value="1" class="button button-primary button-large">
                        Mulai Generate Gaji ⚡
                    </button>
                </p>
            </form>
        </div>
    </div>

    <div id="qrg-gaji-gen-modal" class="qrg-gaji-gen-modal" aria-hidden="true" role="dialog">
        <div class="qrg-gaji-gen-modal__backdrop"></div>
        <div class="qrg-gaji-gen-modal__panel">
            <div id="qrg-gaji-gen-confirm">
                <div class="qrg-gaji-gen-modal__icon">⚡</div>
                <h2>Konfirmasi Generate Gaji</h2>
                <p>Apakah Anda yakin ingin membuat slip gaji untuk <strong>seluruh guru</strong> pada periode terpilih?</p>
                <ul class="qrg-gaji-gen-summary">
                    <li><span>Periode</span><strong id="qrg-gaji-gen-sum-periode">—</strong></li>
                    <li><span>Jumlah Guru</span><strong><?php echo esc_html( number_format_i18n( $count_guru ) ); ?></strong></li>
                    <li><span>Status Awal</span><strong id="qrg-gaji-gen-sum-status">—</strong></li>
                </ul>
                <div class="qrg-gaji-gen-actions">
                    <button type="button" class="button button-large" id="qrg-gaji-gen-cancel">Batal</button>
                    <button type="button" class="button button-primary button-large" id="qrg-gaji-gen-confirm-btn">Ya, Generate Sekarang</button>
                </div>
            </div>
            <div id="qrg-gaji-gen-loading" hidden>
                <div class="qrg-gaji-gen-loader" aria-hidden="true">
                    <div class="qrg-gaji-gen-loader__ring"></div>
                    <div class="qrg-gaji-gen-loader__ring"></div>
                    <div class="qrg-gaji-gen-loader__bolt">⚡</div>
                </div>
                <h2>Sedang generate gaji…</h2>
                <p>Mohon tunggu, sistem sedang membuat slip gaji untuk seluruh guru.</p>
                <div class="qrg-gaji-gen-progress"><div class="qrg-gaji-gen-progress__bar"></div></div>
                <p class="qrg-gaji-gen-hint">Jangan tutup atau refresh halaman ini.</p>
            </div>
        </div>
    </div>

    <style>
        .qrg-gaji-gen-modal {
            position: fixed; inset: 0; z-index: 100100;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden; pointer-events: none;
            transition: opacity .22s ease, visibility .22s ease;
        }
        .qrg-gaji-gen-modal.is-open { opacity: 1; visibility: visible; pointer-events: auto; }
        .qrg-gaji-gen-modal__backdrop {
            position: absolute; inset: 0; background: rgba(15,23,42,.55); backdrop-filter: blur(3px);
        }
        .qrg-gaji-gen-modal__panel {
            position: relative; width: min(440px, calc(100vw - 32px));
            background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(15,23,42,.28);
            padding: 28px 28px 24px; text-align: center;
            transform: translateY(12px) scale(.98); transition: transform .22s ease;
        }
        .qrg-gaji-gen-modal.is-open .qrg-gaji-gen-modal__panel { transform: translateY(0) scale(1); }
        .qrg-gaji-gen-modal__icon {
            width: 64px; height: 64px; margin: 0 auto 14px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(145deg,#eff6ff,#dbeafe); font-size: 28px;
        }
        .qrg-gaji-gen-modal h2 { margin: 0 0 8px; font-size: 20px; color: #0f172a; }
        .qrg-gaji-gen-modal p { margin: 0 0 16px; color: #475569; font-size: 14px; line-height: 1.55; }
        .qrg-gaji-gen-summary {
            list-style: none; margin: 0 0 20px; padding: 0; text-align: left;
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;
        }
        .qrg-gaji-gen-summary li {
            display: flex; justify-content: space-between; gap: 12px;
            padding: 10px 14px; border-bottom: 1px solid #e2e8f0; font-size: 13px;
        }
        .qrg-gaji-gen-summary li:last-child { border-bottom: 0; }
        .qrg-gaji-gen-summary span { color: #64748b; }
        .qrg-gaji-gen-summary strong { color: #0f172a; text-align: right; }
        .qrg-gaji-gen-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
        .qrg-gaji-gen-actions .button { min-width: 120px; }
        #qrg-gaji-gen-loading[hidden], #qrg-gaji-gen-confirm[hidden] { display: none !important; }
        .qrg-gaji-gen-loader { position: relative; width: 88px; height: 88px; margin: 8px auto 18px; }
        .qrg-gaji-gen-loader__ring {
            position: absolute; inset: 0; border-radius: 50%; border: 3px solid transparent;
            animation: qrg-gaji-spin 1.2s linear infinite;
        }
        .qrg-gaji-gen-loader__ring:nth-child(1) { border-top-color: #2271b1; border-right-color: #2271b1; }
        .qrg-gaji-gen-loader__ring:nth-child(2) {
            inset: 10px; border-bottom-color: #3b82f6; border-left-color: #3b82f6;
            animation-duration: .9s; animation-direction: reverse;
        }
        .qrg-gaji-gen-loader__bolt {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            font-size: 28px; animation: qrg-gaji-pulse 1.1s ease-in-out infinite;
        }
        .qrg-gaji-gen-progress {
            width: 100%; height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin: 0 0 12px;
        }
        .qrg-gaji-gen-progress__bar {
            width: 35%; height: 100%; border-radius: 999px;
            background: linear-gradient(90deg,#2271b1,#60a5fa,#2271b1); background-size: 200% 100%;
            animation: qrg-gaji-progress 1.4s ease-in-out infinite;
        }
        .qrg-gaji-gen-hint { margin: 0; font-size: 12px; color: #94a3b8; }
        @keyframes qrg-gaji-spin { to { transform: rotate(360deg); } }
        @keyframes qrg-gaji-pulse {
            0%, 100% { transform: scale(1); opacity: .85; }
            50% { transform: scale(1.12); opacity: 1; }
        }
        @keyframes qrg-gaji-progress {
            0% { transform: translateX(-120%); }
            100% { transform: translateX(320%); }
        }
        body.qrg-gaji-gen-modal-open { overflow: hidden; }
    </style>
    <script>
    (function() {
        var form = document.getElementById('qrg-generate-gaji-form');
        var modal = document.getElementById('qrg-gaji-gen-modal');
        if (!form || !modal) return;

        var confirmView = document.getElementById('qrg-gaji-gen-confirm');
        var loadingView = document.getElementById('qrg-gaji-gen-loading');
        var btnCancel = document.getElementById('qrg-gaji-gen-cancel');
        var btnConfirm = document.getElementById('qrg-gaji-gen-confirm-btn');
        var allowSubmit = false;

        function selectedText(sel) {
            if (!sel || sel.selectedIndex < 0) return '—';
            var opt = sel.options[sel.selectedIndex];
            return opt ? opt.text : '—';
        }

        function openModal() {
            var bulan = selectedText(document.getElementById('gaji_bulan'));
            var tahun = document.getElementById('gaji_tahun').value || '—';
            document.getElementById('qrg-gaji-gen-sum-periode').textContent = bulan + ' ' + tahun;
            document.getElementById('qrg-gaji-gen-sum-status').textContent = selectedText(document.getElementById('gaji_status'));
            confirmView.hidden = false;
            loadingView.hidden = true;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('qrg-gaji-gen-modal-open');
            btnConfirm.focus();
        }

        function closeModal() {
            if (allowSubmit) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('qrg-gaji-gen-modal-open');
        }

        function showLoadingAndSubmit() {
            allowSubmit = true;
            confirmView.hidden = true;
            loadingView.hidden = false;
            btnConfirm.disabled = true;
            btnCancel.disabled = true;

            var flag = form.querySelector('input[name="qrg_do_generate_gaji"][type="hidden"]');
            if (!flag) {
                flag = document.createElement('input');
                flag.type = 'hidden';
                flag.name = 'qrg_do_generate_gaji';
                flag.value = '1';
                form.appendChild(flag);
            }

            var submitBtn = document.getElementById('qrg-generate-gaji-btn');
            if (submitBtn) submitBtn.disabled = true;

            setTimeout(function() {
                if (typeof form.requestSubmit === 'function' && submitBtn) {
                    form.requestSubmit(submitBtn);
                } else {
                    form.submit();
                }
            }, 350);
        }

        form.addEventListener('submit', function(e) {
            if (allowSubmit) return;
            e.preventDefault();
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            openModal();
        });

        btnCancel.addEventListener('click', closeModal);
        modal.querySelector('.qrg-gaji-gen-modal__backdrop').addEventListener('click', closeModal);
        btnConfirm.addEventListener('click', showLoadingAndSubmit);
        document.addEventListener('keydown', function(e) {
            if (!modal.classList.contains('is-open') || allowSubmit) return;
            if (e.key === 'Escape') closeModal();
        });
    })();
    </script>
    <?php
}

add_action( 'admin_enqueue_scripts', 'qrg_gaji_rumus_enqueue' );
function qrg_gaji_rumus_enqueue( $hook ) {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'qrg-rumus-gaji' ) {
        return;
    }
    wp_enqueue_script( 'jquery-ui-sortable' );
}

function qrg_gaji_rumus_page_callback() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_POST['qrg_save_rumus_gaji'] ) && check_admin_referer( 'qrg_rumus_gaji_action', 'qrg_rumus_gaji_nonce' ) ) {
        $columns = qrg_gaji_sanitize_columns_from_post();
        if ( empty( $columns ) ) {
            $columns = qrg_gaji_default_columns();
        }
        $rumus = [
            'hari_kerja_standar' => isset( $_POST['hari_kerja_standar'] ) ? max( 1, absint( $_POST['hari_kerja_standar'] ) ) : 22,
            'columns'            => $columns,
        ];
        update_option( 'qrg_gaji_rumus', $rumus );

        // Simpan tunjangan per jabatan ke Master Jabatan
        if ( isset( $_POST['jabatan_tunjangan'] ) && is_array( $_POST['jabatan_tunjangan'] ) ) {
            foreach ( $_POST['jabatan_tunjangan'] as $jid => $nilai ) {
                $jid = absint( $jid );
                if ( $jid && get_post_type( $jid ) === 'master_jabatan' ) {
                    update_post_meta( $jid, '_jabatan_tunjangan', absint( $nilai ) );
                }
            }
        }

        echo '<div class="notice notice-success is-dismissible"><p>Rumus gaji &amp; tunjangan jabatan berhasil disimpan.</p></div>';
    }

    $rumus      = qrg_get_gaji_rumus();
    $columns    = ! empty( $rumus['columns'] ) ? $rumus['columns'] : qrg_gaji_default_columns();
    $gaji_url   = admin_url( 'edit.php?post_type=master_gaji_guru' );
    $jabatan_url = admin_url( 'edit.php?post_type=master_jabatan' );
    $basis      = qrg_gaji_basis_options();
    $daftar_jabatan = function_exists( 'qrg_get_daftar_jabatan' ) ? qrg_get_daftar_jabatan() : [];
    $sample_tj  = 0;
    if ( ! empty( $daftar_jabatan ) && function_exists( 'qrg_get_jabatan_tunjangan' ) ) {
        $sample_tj = qrg_get_jabatan_tunjangan( $daftar_jabatan[0]->ID );
    }
    $contoh = qrg_gaji_hitung_komponen( [
        'hadir'              => (int) $rumus['hari_kerja_standar'],
        'izin'               => 1,
        'sakit'              => 0,
        'alpha'              => 1,
        'rumus'              => $rumus,
        'tunjangan_jabatan'  => $sample_tj,
    ] );
    ?>
    <div class="wrap qrg-rumus-gaji-wrap">
        <h1>
            Rumus Gaji Guru
            <a href="<?php echo esc_url( $gaji_url ); ?>" class="page-title-action">← Gaji Guru</a>
            <a href="<?php echo esc_url( $jabatan_url ); ?>" class="page-title-action">Master Jabatan</a>
        </h1>
        <p>Susun kolom komponen gaji secara dinamis. Kolom <strong>Tetap</strong> bisa diubah di slip gaji; kolom <strong>Per Hari</strong> dihitung otomatis dari Presensi; <strong>Tunjangan Jabatan</strong> diambil dari Master Jabatan sesuai jabatan guru.</p>

        <form method="post" id="qrg-rumus-gaji-form">
            <?php wp_nonce_field( 'qrg_rumus_gaji_action', 'qrg_rumus_gaji_nonce' ); ?>

            <div class="card" style="padding:20px;margin-top:16px;max-width:980px;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
                    <h2 style="margin:0;">Kolom Komponen</h2>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" class="button" id="qrg-preview-rumus-btn">Preview Rumus</button>
                        <button type="button" class="button" id="qrg-add-col">+ Tambah Kolom</button>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped" id="qrg-rumus-cols-table">
                    <thead>
                        <tr>
                            <th style="width:36px;"></th>
                            <th style="width:22%;">Nama Kolom</th>
                            <th style="width:14%;">Tipe</th>
                            <th style="width:12%;">Operasi</th>
                            <th style="width:16%;">Nilai (Rp)</th>
                            <th style="width:18%;">Basis (jika Per Hari)</th>
                            <th style="width:8%;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="qrg-rumus-cols-body">
                        <?php foreach ( $columns as $col ) :
                            $tipe     = isset( $col['tipe'] ) ? $col['tipe'] : 'tetap';
                            $op       = isset( $col['operator'] ) ? $col['operator'] : 'plus';
                            $bas      = isset( $col['basis'] ) ? $col['basis'] : 'hadir';
                            $is_tj    = ( isset( $col['id'] ) && $col['id'] === 'tunjangan_jabatan' ) || $tipe === 'dari_jabatan';
                            if ( $is_tj ) {
                                $tipe = 'dari_jabatan';
                            }
                            ?>
                        <tr class="qrg-rumus-col-row<?php echo $is_tj ? ' qrg-rumus-col-jabatan' : ''; ?>">
                            <td class="qrg-drag" title="Geser urutan"><span class="dashicons dashicons-menu"></span></td>
                            <td>
                                <input type="hidden" name="col_id[]" value="<?php echo esc_attr( $col['id'] ); ?>">
                                <input type="text" name="col_label[]" value="<?php echo esc_attr( $col['label'] ); ?>" class="regular-text" style="width:100%;" required placeholder="Nama kolom">
                            </td>
                            <td>
                                <?php if ( $is_tj ) : ?>
                                    <input type="hidden" name="col_tipe[]" value="dari_jabatan">
                                    <span class="qrg-tipe-badge">Dari Jabatan</span>
                                <?php else : ?>
                                    <select name="col_tipe[]" class="qrg-col-tipe" style="width:100%;">
                                        <option value="tetap" <?php selected( $tipe, 'tetap' ); ?>>Tetap</option>
                                        <option value="per_hari" <?php selected( $tipe, 'per_hari' ); ?>>Per Hari</option>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="col_operator[]" style="width:100%;">
                                    <option value="plus" <?php selected( $op, 'plus' ); ?>>+ Tambah</option>
                                    <option value="minus" <?php selected( $op, 'minus' ); ?>>− Kurangi</option>
                                </select>
                            </td>
                            <td>
                                <?php if ( $is_tj ) : ?>
                                    <input type="hidden" name="col_nilai[]" value="0">
                                    <span class="description">Lihat tabel di bawah</span>
                                <?php else : ?>
                                    <input type="number" name="col_nilai[]" value="<?php echo esc_attr( (int) $col['nilai'] ); ?>" min="0" step="1000" style="width:100%;">
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="col_basis[]" class="qrg-col-basis" style="width:100%;" <?php disabled( $tipe !== 'per_hari' ); ?>>
                                    <?php foreach ( $basis as $bk => $bl ) : ?>
                                        <option value="<?php echo esc_attr( $bk ); ?>" <?php selected( $bas, $bk ); ?>><?php echo esc_html( $bl ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ( $is_tj ) : ?>
                                    <span class="description">Wajib</span>
                                <?php else : ?>
                                    <button type="button" class="button qrg-remove-col">Hapus</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="qrg-tj-jabatan-box" style="margin-top:20px;padding:16px;border:1px solid #c3d9f0;border-radius:10px;background:#f8fbff;">
                    <h3 style="margin:0 0 6px;">Tunjangan per Master Jabatan</h3>
                    <p class="description" style="margin-top:0;">Nilai ini tersimpan di Master Jabatan dan dipakai otomatis saat generate / hitung gaji guru sesuai jabatannya.</p>
                    <?php if ( empty( $daftar_jabatan ) ) : ?>
                        <p style="color:#b45309;">Belum ada data Master Jabatan. Tambahkan dulu di <a href="<?php echo esc_url( $jabatan_url ); ?>">Master Jabatan</a>.</p>
                    <?php else : ?>
                        <table class="widefat striped" id="qrg-tj-jabatan-table" style="max-width:640px;">
                            <thead>
                                <tr>
                                    <th>Jabatan</th>
                                    <th style="width:180px;">Tunjangan (Rp)</th>
                                    <th style="width:100px;">Jumlah Guru</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $daftar_jabatan as $j_post ) :
                                    $tj_val = function_exists( 'qrg_get_jabatan_tunjangan' ) ? qrg_get_jabatan_tunjangan( $j_post->ID ) : 0;
                                    $j_count = function_exists( 'qrg_count_guru_by_jabatan' ) ? qrg_count_guru_by_jabatan( $j_post->ID ) : 0;
                                    ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $j_post->post_title ); ?></strong>
                                        <a href="<?php echo esc_url( get_edit_post_link( $j_post->ID ) ); ?>" style="font-size:12px;margin-left:6px;">edit</a>
                                    </td>
                                    <td>
                                        <input type="number" class="qrg-tj-jabatan-input" name="jabatan_tunjangan[<?php echo (int) $j_post->ID; ?>]" value="<?php echo esc_attr( $tj_val ); ?>" min="0" step="1000" style="width:100%;">
                                    </td>
                                    <td><?php echo (int) $j_count; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <table class="form-table" style="margin-top:12px;">
                    <tr>
                        <th><label for="hari_kerja_standar">Hari Kerja Standar (contoh)</label></th>
                        <td>
                            <input type="number" name="hari_kerja_standar" id="hari_kerja_standar" value="<?php echo esc_attr( (int) $rumus['hari_kerja_standar'] ); ?>" class="small-text" min="1" max="31">
                            <p class="description">Dipakai untuk preview perhitungan (asumsi hadir penuh). Preview Tunjangan Jabatan memakai nilai jabatan pertama di tabel.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top:8px;">
                    <button type="submit" name="qrg_save_rumus_gaji" class="button button-primary button-large">Simpan Rumus Gaji</button>
                </p>
            </div>
        </form>
    </div>

    <div id="qrg-preview-rumus-modal" class="qrg-preview-modal" aria-hidden="true" role="dialog" aria-labelledby="qrg-preview-rumus-title">
        <div class="qrg-preview-modal__backdrop"></div>
        <div class="qrg-preview-modal__panel">
            <div class="qrg-preview-modal__head">
                <h2 id="qrg-preview-rumus-title">Preview Rumus</h2>
                <button type="button" class="button-link qrg-preview-modal__close" id="qrg-preview-rumus-close" aria-label="Tutup">&times;</button>
            </div>
            <div class="qrg-preview-modal__body">
                <div class="qrg-preview-modal__info">
                    <strong>Total Diterima</strong> = jumlah semua kolom<br>
                    <span style="color:#166534;">(+ Tambah)</span> menambah total<br>
                    <span style="color:#b91c1c;">(− Kurangi)</span> mengurangi total<br>
                    <em>Per Hari</em> = Nilai × jumlah hari basis dari Presensi
                </div>
                <h3 style="margin:0 0 6px;">Contoh hitung</h3>
                <p class="description" id="qrg-preview-asumsi" style="margin-top:0;">Hadir <?php echo (int) $rumus['hari_kerja_standar']; ?>, Izin 1, Alpha 1</p>
                <table class="widefat striped" style="border:1px solid #e2e8f0;">
                    <tbody id="qrg-preview-rumus-body">
                        <?php foreach ( $contoh['items'] as $item ) : ?>
                            <tr>
                                <td>
                                    <?php echo $item['operator'] === 'minus' ? '−' : '+'; ?>
                                    <?php echo esc_html( $item['label'] ); ?>
                                    <?php if ( $item['tipe'] === 'per_hari' ) : ?>
                                        <div style="font-size:11px;color:#64748b;"><?php echo (int) $item['qty']; ?> × Rp <?php echo esc_html( number_format( $item['nilai'], 0, ',', '.' ) ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    Rp <?php echo esc_html( number_format( $item['jumlah'], 0, ',', '.' ) ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>Total</strong></td>
                            <td style="text-align:right;"><strong id="qrg-preview-rumus-total">Rp <?php echo esc_html( number_format( $contoh['total'], 0, ',', '.' ) ); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="qrg-preview-modal__foot">
                <button type="button" class="button button-primary" id="qrg-preview-rumus-ok">Tutup</button>
            </div>
        </div>
    </div>

    <style>
        .qrg-rumus-gaji-wrap .qrg-drag { cursor: move; color: #8c8f94; text-align: center; vertical-align: middle; }
        .qrg-rumus-gaji-wrap .qrg-drag:hover { color: #2271b1; }
        #qrg-rumus-cols-body tr.ui-sortable-helper { background: #fff; box-shadow: 0 4px 16px rgba(0,0,0,.12); }
        #qrg-rumus-cols-body tr.ui-sortable-placeholder { visibility: visible !important; background: #eef5fb; height: 52px; }
        .qrg-tipe-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 12px;
            font-weight: 600;
        }
        .qrg-rumus-col-jabatan { background: #f8fbff; }

        .qrg-preview-modal {
            position: fixed; inset: 0; z-index: 100100;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden; pointer-events: none;
            transition: opacity .2s ease, visibility .2s ease;
        }
        .qrg-preview-modal.is-open {
            opacity: 1; visibility: visible; pointer-events: auto;
        }
        .qrg-preview-modal__backdrop {
            position: absolute; inset: 0;
            background: rgba(15, 23, 42, .55);
            backdrop-filter: blur(2px);
        }
        .qrg-preview-modal__panel {
            position: relative;
            width: min(520px, calc(100vw - 32px));
            max-height: calc(100vh - 48px);
            overflow: auto;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
            transform: translateY(10px) scale(.98);
            transition: transform .2s ease;
        }
        .qrg-preview-modal.is-open .qrg-preview-modal__panel {
            transform: translateY(0) scale(1);
        }
        .qrg-preview-modal__head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; padding: 16px 20px 0;
        }
        .qrg-preview-modal__head h2 { margin: 0; font-size: 18px; }
        .qrg-preview-modal__close {
            font-size: 28px; line-height: 1; text-decoration: none;
            color: #64748b; border: 0; background: transparent; cursor: pointer; padding: 0 4px;
        }
        .qrg-preview-modal__close:hover { color: #0f172a; }
        .qrg-preview-modal__body { padding: 14px 20px 8px; }
        .qrg-preview-modal__info {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 12px 14px; font-size: 13px; line-height: 1.55; color: #334155; margin-bottom: 14px;
        }
        .qrg-preview-modal__foot {
            padding: 8px 20px 18px; text-align: right;
        }
        body.qrg-preview-modal-open { overflow: hidden; }
    </style>
    <script>
    jQuery(function($) {
        var basisOptions = <?php echo wp_json_encode( $basis ); ?>;

        function basisSelectHtml(selected) {
            var html = '';
            $.each(basisOptions, function(k, v) {
                html += '<option value="' + k + '"' + (k === selected ? ' selected' : '') + '>' + $('<div>').text(v).html() + '</option>';
            });
            return html;
        }

        function syncBasis($row) {
            var tipe = $row.find('.qrg-col-tipe').val();
            var $basis = $row.find('.qrg-col-basis');
            $basis.prop('disabled', tipe !== 'per_hari');
        }

        function buildRow(data) {
            data = data || {};
            var label = data.label || '';
            var tipe = data.tipe || 'tetap';
            var op = data.operator || 'plus';
            var nilai = typeof data.nilai !== 'undefined' ? data.nilai : 0;
            var basis = data.basis || 'hadir';
            var id = data.id || '';

            return $('<tr class="qrg-rumus-col-row">' +
                '<td class="qrg-drag" title="Geser urutan"><span class="dashicons dashicons-menu"></span></td>' +
                '<td>' +
                    '<input type="hidden" name="col_id[]" value="' + $('<div>').text(id).html() + '">' +
                    '<input type="text" name="col_label[]" value="' + $('<div>').text(label).html() + '" class="regular-text" style="width:100%;" required placeholder="Nama kolom">' +
                '</td>' +
                '<td><select name="col_tipe[]" class="qrg-col-tipe" style="width:100%;">' +
                    '<option value="tetap"' + (tipe === 'tetap' ? ' selected' : '') + '>Tetap</option>' +
                    '<option value="per_hari"' + (tipe === 'per_hari' ? ' selected' : '') + '>Per Hari</option>' +
                '</select></td>' +
                '<td><select name="col_operator[]" style="width:100%;">' +
                    '<option value="plus"' + (op === 'plus' ? ' selected' : '') + '>+ Tambah</option>' +
                    '<option value="minus"' + (op === 'minus' ? ' selected' : '') + '>− Kurangi</option>' +
                '</select></td>' +
                '<td><input type="number" name="col_nilai[]" value="' + nilai + '" min="0" step="1000" style="width:100%;"></td>' +
                '<td><select name="col_basis[]" class="qrg-col-basis" style="width:100%;"' + (tipe !== 'per_hari' ? ' disabled' : '') + '>' +
                    basisSelectHtml(basis) +
                '</select></td>' +
                '<td><button type="button" class="button qrg-remove-col">Hapus</button></td>' +
            '</tr>');
        }

        function fmt(n) {
            n = Math.max(0, parseInt(n, 10) || 0);
            return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function collectColumnsFromForm() {
            var cols = [];
            $('#qrg-rumus-cols-body tr').each(function() {
                var $row = $(this);
                var label = $.trim($row.find('input[name="col_label[]"]').val() || '');
                if (!label) return;
                var id = $row.find('input[name="col_id[]"]').val() || '';
                var tipe = $row.find('select[name="col_tipe[]"]').val() || $row.find('input[name="col_tipe[]"]').val() || 'tetap';
                var nilai = parseInt($row.find('input[name="col_nilai[]"]').val(), 10) || 0;
                if (id === 'tunjangan_jabatan' || tipe === 'dari_jabatan') {
                    tipe = 'dari_jabatan';
                    // Ambil contoh dari input tunjangan jabatan pertama
                    var $firstTj = $('#qrg-tj-jabatan-table .qrg-tj-jabatan-input').first();
                    if ($firstTj.length) {
                        nilai = parseInt($firstTj.val(), 10) || 0;
                    }
                }
                cols.push({
                    id: id,
                    label: label,
                    tipe: tipe,
                    operator: $row.find('select[name="col_operator[]"]').val() || 'plus',
                    nilai: nilai,
                    basis: $row.find('select[name="col_basis[]"]').val() || 'hadir'
                });
            });
            return cols;
        }

        function renderPreview() {
            var hariStandar = parseInt($('#hari_kerja_standar').val(), 10) || 22;
            var hari = { hadir: hariStandar, izin: 1, sakit: 0, alpha: 1 };
            var cols = collectColumnsFromForm();
            var total = 0;
            var html = '';

            $('#qrg-preview-asumsi').text('Hadir ' + hari.hadir + ', Izin ' + hari.izin + ', Alpha ' + hari.alpha + ' · Tunjangan Jabatan = contoh jabatan pertama');

            if (!cols.length) {
                html = '<tr><td colspan="2">Belum ada kolom. Tambahkan kolom terlebih dahulu.</td></tr>';
                $('#qrg-preview-rumus-body').html(html);
                $('#qrg-preview-rumus-total').text(fmt(0));
                return;
            }

            $.each(cols, function(_, col) {
                var qty = 1;
                var jumlah = col.nilai;
                if (col.tipe === 'per_hari') {
                    qty = hari[col.basis] || 0;
                    jumlah = qty * col.nilai;
                }
                if (col.operator === 'minus') {
                    total -= jumlah;
                } else {
                    total += jumlah;
                }
                html += '<tr><td>' +
                    (col.operator === 'minus' ? '− ' : '+ ') + $('<div>').text(col.label).html();
                if (col.tipe === 'per_hari') {
                    html += '<div style="font-size:11px;color:#64748b;">' + qty + ' × ' + fmt(col.nilai) + '</div>';
                } else if (col.tipe === 'dari_jabatan') {
                    html += '<div style="font-size:11px;color:#64748b;">Dari Master Jabatan (contoh)</div>';
                }
                html += '</td><td style="text-align:right;white-space:nowrap;">' + fmt(jumlah) + '</td></tr>';
            });

            $('#qrg-preview-rumus-body').html(html);
            $('#qrg-preview-rumus-total').text(fmt(Math.max(0, total)));
        }

        function openPreviewModal() {
            renderPreview();
            $('#qrg-preview-rumus-modal').addClass('is-open').attr('aria-hidden', 'false');
            $('body').addClass('qrg-preview-modal-open');
        }

        function closePreviewModal() {
            $('#qrg-preview-rumus-modal').removeClass('is-open').attr('aria-hidden', 'true');
            $('body').removeClass('qrg-preview-modal-open');
        }

        $('#qrg-rumus-cols-body').sortable({
            handle: '.qrg-drag',
            axis: 'y',
            placeholder: 'ui-sortable-placeholder',
            helper: function(e, tr) {
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function(i) {
                    $(this).width($originals.eq(i).width());
                });
                return $helper;
            }
        });

        $('#qrg-add-col').on('click', function() {
            var $row = buildRow({ label: '', tipe: 'tetap', operator: 'plus', nilai: 0, basis: 'hadir' });
            $('#qrg-rumus-cols-body').append($row);
            $row.find('input[name="col_label[]"]').focus();
        });

        $('#qrg-rumus-cols-table').on('click', '.qrg-remove-col', function() {
            var $rows = $('#qrg-rumus-cols-body tr');
            if ($rows.length <= 1) {
                alert('Minimal harus ada 1 kolom.');
                return;
            }
            $(this).closest('tr').remove();
        });

        $('#qrg-rumus-cols-table').on('change', '.qrg-col-tipe', function() {
            syncBasis($(this).closest('tr'));
        });

        $('#qrg-rumus-cols-body tr').each(function() {
            syncBasis($(this));
        });

        $('#qrg-preview-rumus-btn').on('click', openPreviewModal);
        $('#qrg-preview-rumus-close, #qrg-preview-rumus-ok').on('click', closePreviewModal);
        $('#qrg-preview-rumus-modal .qrg-preview-modal__backdrop').on('click', closePreviewModal);
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#qrg-preview-rumus-modal').hasClass('is-open')) {
                closePreviewModal();
            }
        });
    });
    </script>
    <?php
}

// 2. Meta box
add_action( 'add_meta_boxes', 'qrg_gaji_guru_meta_boxes' );
function qrg_gaji_guru_meta_boxes() {
    add_meta_box(
        'gaji_guru_detail',
        'Detail Gaji Guru',
        'qrg_gaji_guru_fields_callback',
        'master_gaji_guru',
        'normal',
        'high'
    );
}

function qrg_gaji_guru_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_gaji_guru_meta', 'qrg_gaji_guru_nonce' );

    $rumus   = qrg_get_gaji_rumus();
    $columns = ! empty( $rumus['columns'] ) ? $rumus['columns'] : qrg_gaji_default_columns();

    $guru_id    = (int) get_post_meta( $post->ID, '_gaji_guru_id', true );
    $bulan      = get_post_meta( $post->ID, '_gaji_bulan', true );
    $tahun      = get_post_meta( $post->ID, '_gaji_tahun', true );
    $status     = get_post_meta( $post->ID, '_gaji_status', true );
    $keterangan = get_post_meta( $post->ID, '_gaji_keterangan', true );
    $saved_vals = get_post_meta( $post->ID, '_gaji_kolom_nilai', true );
    if ( ! is_array( $saved_vals ) ) {
        $saved_vals = [];
    }

    // Kompatibilitas data lama
    if ( empty( $saved_vals ) ) {
        $old_pokok = get_post_meta( $post->ID, '_gaji_nominal', true );
        $old_tj    = get_post_meta( $post->ID, '_gaji_tunjangan_jabatan', true );
        if ( $old_tj === '' ) {
            $old_tj = get_post_meta( $post->ID, '_gaji_tunjangan', true );
        }
        if ( $old_pokok !== '' ) {
            $saved_vals['gaji_pokok'] = (int) $old_pokok;
        }
        if ( $old_tj !== '' ) {
            $saved_vals['tunjangan_jabatan'] = (int) $old_tj;
        }
    }

    if ( ! $bulan ) {
        $bulan = current_time( 'm' );
    }
    if ( ! $tahun ) {
        $tahun = current_time( 'Y' );
    }
    if ( ! $status ) {
        $status = 'Belum Dibayar';
    }

    $is_new = ( $post->post_status === 'auto-draft' );
    if ( $is_new && empty( $saved_vals ) ) {
        foreach ( $columns as $col ) {
            $col_id   = isset( $col['id'] ) ? $col['id'] : '';
            $col_tipe = isset( $col['tipe'] ) ? $col['tipe'] : 'tetap';
            if ( $col_id === 'tunjangan_jabatan' || $col_tipe === 'dari_jabatan' ) {
                $saved_vals[ $col_id ] = function_exists( 'qrg_get_tunjangan_jabatan_for_guru' )
                    ? qrg_get_tunjangan_jabatan_for_guru( $guru_id, (int) $col['nilai'] )
                    : (int) $col['nilai'];
            } elseif ( $col_tipe === 'tetap' ) {
                $saved_vals[ $col_id ] = (int) $col['nilai'];
            }
        }
    }

    // Sinkron tunjangan jabatan dari master jika belum di-override manual
    if ( $guru_id && function_exists( 'qrg_get_tunjangan_jabatan_for_guru' ) ) {
        foreach ( $columns as $col ) {
            $col_id   = isset( $col['id'] ) ? $col['id'] : '';
            $col_tipe = isset( $col['tipe'] ) ? $col['tipe'] : '';
            if ( $col_id === 'tunjangan_jabatan' || $col_tipe === 'dari_jabatan' ) {
                if ( ! isset( $saved_vals[ $col_id ] ) || $is_new ) {
                    $saved_vals[ $col_id ] = qrg_get_tunjangan_jabatan_for_guru( $guru_id, (int) $col['nilai'] );
                }
            }
        }
    }

    $kehadiran = qrg_gaji_hitung_kehadiran( $guru_id, $bulan, $tahun );
    $hitung    = qrg_gaji_hitung_komponen( [
        'overrides' => $saved_vals,
        'hadir'     => $kehadiran['hadir'],
        'izin'      => $kehadiran['izin'],
        'sakit'     => $kehadiran['sakit'],
        'alpha'     => $kehadiran['alpha'],
        'rumus'     => $rumus,
        'guru_id'   => $guru_id,
    ] );

    $daftar_guru = get_posts( [
        'post_type'      => 'master_guru',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $rumus_url = admin_url( 'admin.php?page=qrg-rumus-gaji' );
    $basis_map = qrg_gaji_basis_options();
    $jabatan_url = admin_url( 'edit.php?post_type=master_jabatan' );
    $guru_jabatan_label = $guru_id && function_exists( 'qrg_get_guru_jabatan_label' )
        ? qrg_get_guru_jabatan_label( $guru_id )
        : '';
    ?>
    <div class="qrg-gaji-formula-box" style="background:#f0f7ff;border:1px solid #c3d9f0;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
        <strong>Rumus aktif:</strong> Total dari <?php echo count( $columns ); ?> kolom dinamis
        · <a href="<?php echo esc_url( $rumus_url ); ?>">Ubah / Tambah Kolom Rumus</a>
        · <a href="<?php echo esc_url( $jabatan_url ); ?>">Master Jabatan</a>
        <?php if ( $guru_jabatan_label ) : ?>
            <div style="margin-top:6px;font-size:13px;color:#334155;">Jabatan guru: <strong><?php echo esc_html( $guru_jabatan_label ); ?></strong></div>
        <?php endif; ?>
    </div>

    <table class="form-table">
        <tr>
            <th><label for="gaji_guru_id">Guru</label></th>
            <td>
                <select name="gaji_guru_id" id="gaji_guru_id" required style="min-width:320px;">
                    <option value="">— Pilih Guru —</option>
                    <?php foreach ( $daftar_guru as $guru ) : ?>
                        <option value="<?php echo esc_attr( $guru->ID ); ?>" <?php selected( $guru_id, $guru->ID ); ?>>
                            <?php echo esc_html( $guru->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="gaji_bulan">Periode</label></th>
            <td>
                <select name="gaji_bulan" id="gaji_bulan">
                    <?php foreach ( qrg_gaji_guru_bulan_options() as $num => $label ) : ?>
                        <option value="<?php echo esc_attr( $num ); ?>" <?php selected( $bulan, $num ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="gaji_tahun" id="gaji_tahun" value="<?php echo esc_attr( $tahun ); ?>" min="2000" max="2100" class="small-text" style="margin-left:8px;">
                <button type="button" class="button" id="qrg-gaji-hitung-ulang" style="margin-left:8px;">Hitung dari Presensi</button>
            </td>
        </tr>
        <tr>
            <th>Ringkasan Kehadiran</th>
            <td>
                <div id="qrg-gaji-kehadiran" style="display:flex;flex-wrap:wrap;gap:8px;">
                    <span class="qrg-gaji-chip" data-key="hadir">Hadir: <strong><?php echo (int) $kehadiran['hadir']; ?></strong></span>
                    <span class="qrg-gaji-chip" data-key="izin">Izin: <strong><?php echo (int) $kehadiran['izin']; ?></strong></span>
                    <span class="qrg-gaji-chip" data-key="sakit">Sakit: <strong><?php echo (int) $kehadiran['sakit']; ?></strong></span>
                    <span class="qrg-gaji-chip" data-key="alpha">Alpha: <strong><?php echo (int) $kehadiran['alpha']; ?></strong></span>
                </div>
                <input type="hidden" name="gaji_hari_hadir" id="gaji_hari_hadir" value="<?php echo (int) $kehadiran['hadir']; ?>">
                <input type="hidden" name="gaji_hari_izin" id="gaji_hari_izin" value="<?php echo (int) $kehadiran['izin']; ?>">
                <input type="hidden" name="gaji_hari_sakit" id="gaji_hari_sakit" value="<?php echo (int) $kehadiran['sakit']; ?>">
                <input type="hidden" name="gaji_hari_alpha" id="gaji_hari_alpha" value="<?php echo (int) $kehadiran['alpha']; ?>">
            </td>
        </tr>
    </table>

    <h3 style="margin:8px 0 10px;">Komponen Gaji</h3>
    <table class="widefat striped" id="qrg-gaji-komponen-table" style="max-width:760px;">
        <thead>
            <tr>
                <th>Kolom</th>
                <th style="width:140px;">Operasi</th>
                <th style="width:220px;">Nilai</th>
                <th style="width:160px;text-align:right;">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $hitung['items'] as $item ) :
                $is_tetap   = ( $item['tipe'] === 'tetap' || $item['tipe'] === 'dari_jabatan' );
                $is_jabatan = ( $item['tipe'] === 'dari_jabatan' || $item['id'] === 'tunjangan_jabatan' );
                $val        = $item['nilai'];
                ?>
            <tr class="qrg-gaji-item" data-id="<?php echo esc_attr( $item['id'] ); ?>" data-tipe="<?php echo esc_attr( $item['tipe'] ); ?>" data-operator="<?php echo esc_attr( $item['operator'] ); ?>" data-basis="<?php echo esc_attr( $item['basis'] ); ?>" data-default="<?php echo (int) $item['nilai']; ?>">
                <td>
                    <strong><?php echo esc_html( $item['label'] ); ?></strong>
                    <?php if ( $item['tipe'] === 'per_hari' ) : ?>
                        <div style="font-size:12px;color:#64748b;">
                            Per <?php echo esc_html( isset( $basis_map[ $item['basis'] ] ) ? $basis_map[ $item['basis'] ] : $item['basis'] ); ?>
                            · Tarif Rp <?php echo esc_html( number_format( $item['nilai'], 0, ',', '.' ) ); ?>
                        </div>
                    <?php elseif ( $is_jabatan ) : ?>
                        <div style="font-size:12px;color:#64748b;">Otomatis dari Master Jabatan (bisa diubah manual)</div>
                    <?php endif; ?>
                </td>
                <td><?php echo $item['operator'] === 'minus' ? '− Kurangi' : '+ Tambah'; ?></td>
                <td>
                    <?php if ( $is_tetap ) : ?>
                        <input type="number" class="qrg-gaji-col-input" name="gaji_kolom[<?php echo esc_attr( $item['id'] ); ?>]" value="<?php echo esc_attr( $val ); ?>" min="0" step="1000" style="width:100%;">
                    <?php else : ?>
                        <span class="qrg-gaji-qty"><?php echo (int) $item['qty']; ?></span> hari × Rp <?php echo esc_html( number_format( $item['nilai'], 0, ',', '.' ) ); ?>
                        <input type="hidden" name="gaji_kolom[<?php echo esc_attr( $item['id'] ); ?>]" value="<?php echo esc_attr( $item['nilai'] ); ?>">
                    <?php endif; ?>
                </td>
                <td style="text-align:right;">
                    <strong class="qrg-gaji-item-jumlah" data-signed="<?php echo (int) $item['signed']; ?>">
                        <?php echo $item['operator'] === 'minus' ? '−' : ''; ?>
                        Rp <?php echo esc_html( number_format( $item['jumlah'], 0, ',', '.' ) ); ?>
                    </strong>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" style="text-align:right;">Total Diterima</th>
                <th style="text-align:right;"><span id="qrg-gaji-total" style="font-size:16px;">Rp <?php echo esc_html( number_format( $hitung['total'], 0, ',', '.' ) ); ?></span></th>
            </tr>
        </tfoot>
    </table>

    <table class="form-table" style="margin-top:16px;">
        <tr>
            <th><label for="gaji_status">Status</label></th>
            <td>
                <select name="gaji_status" id="gaji_status">
                    <?php foreach ( qrg_gaji_guru_status_options() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="gaji_keterangan">Keterangan</label></th>
            <td>
                <textarea name="gaji_keterangan" id="gaji_keterangan" rows="3" class="large-text"><?php echo esc_textarea( $keterangan ); ?></textarea>
            </td>
        </tr>
    </table>

    <style>
        .qrg-gaji-chip {
            display: inline-flex; gap: 4px; align-items: center;
            padding: 5px 10px; border-radius: 999px; background: #fff;
            border: 1px solid #d0d7de; font-size: 12px; color: #334155;
        }
    </style>
    <script>
    (function() {
        var columns = <?php echo wp_json_encode( array_values( $columns ) ); ?>;
        var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var nonce = <?php echo wp_json_encode( wp_create_nonce( 'qrg_gaji_hitung' ) ); ?>;

        function fmt(n) {
            n = Math.max(0, parseInt(n, 10) || 0);
            return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        function hari(key) {
            var el = document.getElementById('gaji_hari_' + key);
            return el ? (parseInt(el.value, 10) || 0) : 0;
        }
        function setChip(key, n) {
            var el = document.querySelector('.qrg-gaji-chip[data-key="' + key + '"] strong');
            if (el) el.textContent = n;
            var hidden = document.getElementById('gaji_hari_' + key);
            if (hidden) hidden.value = n;
        }
        function recalc() {
            var total = 0;
            document.querySelectorAll('#qrg-gaji-komponen-table .qrg-gaji-item').forEach(function(row) {
                var tipe = row.getAttribute('data-tipe');
                var op = row.getAttribute('data-operator');
                var basis = row.getAttribute('data-basis');
                var def = parseInt(row.getAttribute('data-default'), 10) || 0;
                var jumlah = 0;
                if (tipe === 'tetap' || tipe === 'dari_jabatan') {
                    var input = row.querySelector('.qrg-gaji-col-input');
                    jumlah = input ? (parseInt(input.value, 10) || 0) : def;
                } else {
                    jumlah = hari(basis) * def;
                    var qty = row.querySelector('.qrg-gaji-qty');
                    if (qty) qty.textContent = hari(basis);
                }
                var signed = op === 'minus' ? -jumlah : jumlah;
                total += signed;
                var out = row.querySelector('.qrg-gaji-item-jumlah');
                if (out) {
                    out.textContent = (op === 'minus' ? '− ' : '') + fmt(jumlah);
                }
            });
            document.getElementById('qrg-gaji-total').textContent = fmt(Math.max(0, total));
        }

        function fetchKehadiran() {
            var guru = document.getElementById('gaji_guru_id').value;
            var bulan = document.getElementById('gaji_bulan').value;
            var tahun = document.getElementById('gaji_tahun').value;
            if (!guru) return;
            var body = new FormData();
            body.append('action', 'qrg_gaji_hitung_kehadiran');
            body.append('nonce', nonce);
            body.append('guru_id', guru);
            body.append('bulan', bulan);
            body.append('tahun', tahun);
            fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res || !res.success) return;
                    var d = res.data;
                    setChip('hadir', d.hadir);
                    setChip('izin', d.izin);
                    setChip('sakit', d.sakit);
                    setChip('alpha', d.alpha);
                    recalc();
                });
        }

        document.querySelectorAll('.qrg-gaji-col-input').forEach(function(el) {
            el.addEventListener('input', recalc);
        });
        document.getElementById('qrg-gaji-hitung-ulang').addEventListener('click', fetchKehadiran);
        document.getElementById('gaji_guru_id').addEventListener('change', fetchKehadiran);
        document.getElementById('gaji_bulan').addEventListener('change', fetchKehadiran);
        document.getElementById('gaji_tahun').addEventListener('change', fetchKehadiran);
        recalc();
    })();
    </script>
    <?php
}

add_action( 'wp_ajax_qrg_gaji_hitung_kehadiran', 'qrg_ajax_gaji_hitung_kehadiran' );
function qrg_ajax_gaji_hitung_kehadiran() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Akses ditolak' ], 403 );
    }
    check_ajax_referer( 'qrg_gaji_hitung', 'nonce' );

    $guru_id = isset( $_POST['guru_id'] ) ? absint( $_POST['guru_id'] ) : 0;
    $bulan   = isset( $_POST['bulan'] ) ? sanitize_text_field( wp_unslash( $_POST['bulan'] ) ) : '';
    $tahun   = isset( $_POST['tahun'] ) ? absint( $_POST['tahun'] ) : 0;

    wp_send_json_success( qrg_gaji_hitung_kehadiran( $guru_id, $bulan, $tahun ) );
}

// 3. Simpan
add_action( 'save_post_master_gaji_guru', 'qrg_save_gaji_guru_details' );
function qrg_save_gaji_guru_details( $post_id ) {
    if ( ! isset( $_POST['qrg_gaji_guru_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_gaji_guru_nonce'], 'qrg_save_gaji_guru_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) {
        return;
    }

    $guru_id    = isset( $_POST['gaji_guru_id'] ) ? absint( $_POST['gaji_guru_id'] ) : 0;
    $bulan      = isset( $_POST['gaji_bulan'] ) ? sanitize_text_field( wp_unslash( $_POST['gaji_bulan'] ) ) : '';
    $tahun      = isset( $_POST['gaji_tahun'] ) ? absint( $_POST['gaji_tahun'] ) : (int) current_time( 'Y' );
    $status     = isset( $_POST['gaji_status'] ) ? sanitize_text_field( wp_unslash( $_POST['gaji_status'] ) ) : 'Belum Dibayar';
    $keterangan = isset( $_POST['gaji_keterangan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['gaji_keterangan'] ) ) : '';
    $posted_kol = isset( $_POST['gaji_kolom'] ) && is_array( $_POST['gaji_kolom'] ) ? wp_unslash( $_POST['gaji_kolom'] ) : [];

    $bulan_ok = array_keys( qrg_gaji_guru_bulan_options() );
    if ( ! in_array( $bulan, $bulan_ok, true ) ) {
        $bulan = current_time( 'm' );
    }
    $status_ok = array_keys( qrg_gaji_guru_status_options() );
    if ( ! in_array( $status, $status_ok, true ) ) {
        $status = 'Belum Dibayar';
    }

    $rumus     = qrg_get_gaji_rumus();
    $columns   = ! empty( $rumus['columns'] ) ? $rumus['columns'] : qrg_gaji_default_columns();
    $overrides = [];
    foreach ( $columns as $col ) {
        $col_id   = isset( $col['id'] ) ? $col['id'] : '';
        $col_tipe = isset( $col['tipe'] ) ? $col['tipe'] : 'tetap';
        if ( $col_id === 'tunjangan_jabatan' || $col_tipe === 'dari_jabatan' ) {
            if ( isset( $posted_kol[ $col_id ] ) ) {
                $overrides[ $col_id ] = absint( $posted_kol[ $col_id ] );
            } elseif ( function_exists( 'qrg_get_tunjangan_jabatan_for_guru' ) ) {
                $overrides[ $col_id ] = qrg_get_tunjangan_jabatan_for_guru( $guru_id, (int) $col['nilai'] );
            } else {
                $overrides[ $col_id ] = (int) $col['nilai'];
            }
            continue;
        }
        if ( $col_tipe !== 'tetap' ) {
            continue;
        }
        $overrides[ $col_id ] = isset( $posted_kol[ $col_id ] ) ? absint( $posted_kol[ $col_id ] ) : (int) $col['nilai'];
    }

    $kehadiran = qrg_gaji_hitung_kehadiran( $guru_id, $bulan, $tahun );
    $hitung    = qrg_gaji_hitung_komponen( [
        'overrides' => $overrides,
        'hadir'     => $kehadiran['hadir'],
        'izin'      => $kehadiran['izin'],
        'sakit'     => $kehadiran['sakit'],
        'alpha'     => $kehadiran['alpha'],
        'rumus'     => $rumus,
        'guru_id'   => $guru_id,
    ] );

    update_post_meta( $post_id, '_gaji_guru_id', $guru_id );
    update_post_meta( $post_id, '_gaji_bulan', $bulan );
    update_post_meta( $post_id, '_gaji_tahun', $tahun );
    update_post_meta( $post_id, '_gaji_kolom_nilai', $overrides );
    update_post_meta( $post_id, '_gaji_komponen', $hitung['items'] );
    update_post_meta( $post_id, '_gaji_nominal', $hitung['gaji_pokok'] );
    update_post_meta( $post_id, '_gaji_tunjangan_jabatan', $hitung['tunjangan_jabatan'] );
    update_post_meta( $post_id, '_gaji_tunjangan_hadir', $hitung['tunjangan_hadir'] );
    update_post_meta( $post_id, '_gaji_tunjangan', $hitung['tunjangan_total'] );
    update_post_meta( $post_id, '_gaji_potongan_absen', $hitung['potongan_absen'] );
    update_post_meta( $post_id, '_gaji_potongan', $hitung['potongan_total'] );
    update_post_meta( $post_id, '_gaji_total', $hitung['total'] );
    update_post_meta( $post_id, '_gaji_hari_hadir', $kehadiran['hadir'] );
    update_post_meta( $post_id, '_gaji_hari_izin', $kehadiran['izin'] );
    update_post_meta( $post_id, '_gaji_hari_sakit', $kehadiran['sakit'] );
    update_post_meta( $post_id, '_gaji_hari_alpha', $kehadiran['alpha'] );
    update_post_meta( $post_id, '_gaji_status', $status );
    update_post_meta( $post_id, '_gaji_keterangan', $keterangan );

    $nama_guru   = $guru_id ? get_the_title( $guru_id ) : 'Guru';
    $bulan_label = qrg_gaji_guru_bulan_options();
    $periode     = ( isset( $bulan_label[ $bulan ] ) ? $bulan_label[ $bulan ] : $bulan ) . ' ' . $tahun;
    $title       = sprintf( 'Gaji %s — %s', $nama_guru, $periode );

    remove_action( 'save_post_master_gaji_guru', 'qrg_save_gaji_guru_details' );
    wp_update_post( [
        'ID'         => $post_id,
        'post_title' => $title,
    ] );
    add_action( 'save_post_master_gaji_guru', 'qrg_save_gaji_guru_details' );
}

// 4. Kolom daftar
add_filter( 'manage_master_gaji_guru_posts_columns', 'qrg_set_gaji_guru_columns' );
function qrg_set_gaji_guru_columns( $columns ) {
    return [
        'cb'      => $columns['cb'],
        'title'   => 'Gaji',
        'guru'    => 'Guru',
        'periode' => 'Periode',
        'total'   => 'Total',
        'status'  => 'Status',
        'date'    => 'Dibuat',
    ];
}

add_action( 'manage_master_gaji_guru_posts_custom_column', 'qrg_fill_gaji_guru_columns', 10, 2 );
function qrg_fill_gaji_guru_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'guru':
            $guru_id = (int) get_post_meta( $post_id, '_gaji_guru_id', true );
            if ( $guru_id ) {
                echo '<a href="' . esc_url( get_edit_post_link( $guru_id ) ) . '"><strong>' . esc_html( get_the_title( $guru_id ) ) . '</strong></a>';
            } else {
                echo '<span style="color:#aaa;">—</span>';
            }
            break;
        case 'periode':
            $bulan  = get_post_meta( $post_id, '_gaji_bulan', true );
            $tahun  = get_post_meta( $post_id, '_gaji_tahun', true );
            $labels = qrg_gaji_guru_bulan_options();
            $label  = isset( $labels[ $bulan ] ) ? $labels[ $bulan ] : $bulan;
            echo esc_html( trim( $label . ' ' . $tahun ) );
            break;
        case 'total':
            $detail     = qrg_gaji_get_total_detail( $post_id );
            $total      = (int) $detail['total'];
            $detail_url = admin_url( 'admin.php?page=qrg-detail-gaji&id=' . (int) $post_id );
            ?>
            <div class="qrg-gaji-total-wrap">
                <strong>Rp <?php echo esc_html( number_format( max( 0, $total ), 0, ',', '.' ) ); ?></strong>
                <div>
                    <a href="<?php echo esc_url( $detail_url ); ?>" class="qrg-gaji-total-hint">lihat detail →</a>
                </div>
            </div>
            <?php
            break;
        case 'status':
            $status = get_post_meta( $post_id, '_gaji_status', true );
            if ( $status === 'Dibayar' ) {
                echo '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:600;font-size:12px;">Dibayar</span>';
            } else {
                echo '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#ffedd5;color:#9a3412;font-weight:600;font-size:12px;">' . esc_html( $status ? $status : 'Belum Dibayar' ) . '</span>';
            }
            break;
    }
}

/**
 * Ambil detail total gaji untuk tampilan daftar.
 *
 * @param int $post_id ID master_gaji_guru.
 * @return array{total:int,hadir:int,izin:int,sakit:int,alpha:int,items:array}
 */
function qrg_gaji_get_total_detail( $post_id ) {
    $post_id = (int) $post_id;
    $items   = get_post_meta( $post_id, '_gaji_komponen', true );
    $hadir   = (int) get_post_meta( $post_id, '_gaji_hari_hadir', true );
    $izin    = (int) get_post_meta( $post_id, '_gaji_hari_izin', true );
    $sakit   = (int) get_post_meta( $post_id, '_gaji_hari_sakit', true );
    $alpha   = (int) get_post_meta( $post_id, '_gaji_hari_alpha', true );
    $total   = (int) get_post_meta( $post_id, '_gaji_total', true );

    if ( ! is_array( $items ) || empty( $items ) ) {
        $guru_id   = (int) get_post_meta( $post_id, '_gaji_guru_id', true );
        $bulan     = get_post_meta( $post_id, '_gaji_bulan', true );
        $tahun     = (int) get_post_meta( $post_id, '_gaji_tahun', true );
        $overrides = get_post_meta( $post_id, '_gaji_kolom_nilai', true );
        if ( ! is_array( $overrides ) ) {
            $overrides = [];
            $pokok = get_post_meta( $post_id, '_gaji_nominal', true );
            $tj    = get_post_meta( $post_id, '_gaji_tunjangan_jabatan', true );
            if ( $pokok !== '' ) {
                $overrides['gaji_pokok'] = (int) $pokok;
            }
            if ( $tj !== '' ) {
                $overrides['tunjangan_jabatan'] = (int) $tj;
            }
        }

        if ( $guru_id && ( ! $hadir && ! $izin && ! $sakit && ! $alpha ) ) {
            $kehadiran = qrg_gaji_hitung_kehadiran( $guru_id, $bulan, $tahun );
            $hadir     = $kehadiran['hadir'];
            $izin      = $kehadiran['izin'];
            $sakit     = $kehadiran['sakit'];
            $alpha     = $kehadiran['alpha'];
        }

        $hitung = qrg_gaji_hitung_komponen( [
            'overrides' => $overrides,
            'hadir'     => $hadir,
            'izin'      => $izin,
            'sakit'     => $sakit,
            'alpha'     => $alpha,
            'guru_id'   => $guru_id,
        ] );
        $items = $hitung['items'];
        if ( ! $total ) {
            $total = (int) $hitung['total'];
        }
    }

    if ( ! $total ) {
        $total = (int) get_post_meta( $post_id, '_gaji_nominal', true )
            + (int) get_post_meta( $post_id, '_gaji_tunjangan', true )
            - (int) get_post_meta( $post_id, '_gaji_potongan', true );
    }

    return [
        'total' => max( 0, $total ),
        'hadir' => $hadir,
        'izin'  => $izin,
        'sakit' => $sakit,
        'alpha' => $alpha,
        'items' => is_array( $items ) ? $items : [],
    ];
}

// Style link "lihat detail" di daftar Gaji Guru
add_action( 'admin_footer-edit.php', 'qrg_gaji_total_detail_assets' );
function qrg_gaji_total_detail_assets() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_gaji_guru' ) {
        return;
    }
    ?>
    <style>
        .column-total { min-width: 140px; }
        .qrg-gaji-total-wrap strong { color: #0f172a; }
        .qrg-gaji-total-hint {
            font-size: 11px;
            color: #2271b1;
            font-weight: 500;
            text-decoration: none;
        }
        .qrg-gaji-total-hint:hover { text-decoration: underline; }
    </style>
    <?php
}

// Halaman Detail Perhitungan Gaji
add_action( 'admin_menu', 'qrg_gaji_detail_menu', 22 );
function qrg_gaji_detail_menu() {
    add_submenu_page(
        null,
        'Detail Gaji Guru',
        'Detail Gaji',
        'manage_options',
        'qrg-detail-gaji',
        'qrg_gaji_detail_page_callback'
    );
}

add_filter( 'parent_file', 'qrg_gaji_detail_parent_file' );
function qrg_gaji_detail_parent_file( $parent_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-detail-gaji' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_gaji_detail_submenu_file' );
function qrg_gaji_detail_submenu_file( $submenu_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-detail-gaji' ) {
        return 'edit.php?post_type=master_guru';
    }
    return $submenu_file;
}

function qrg_gaji_detail_page_callback() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $gaji_id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $list_url  = admin_url( 'edit.php?post_type=master_gaji_guru' );
    $post      = $gaji_id ? get_post( $gaji_id ) : null;

    if ( ! $post || $post->post_type !== 'master_gaji_guru' ) {
        echo '<div class="wrap"><h1>Detail Gaji Guru</h1>';
        echo '<div class="notice notice-error"><p>Data gaji tidak ditemukan.</p></div>';
        echo '<p><a href="' . esc_url( $list_url ) . '" class="button">← Kembali ke Gaji Guru</a></p></div>';
        return;
    }

    $guru_id  = (int) get_post_meta( $gaji_id, '_gaji_guru_id', true );
    $bulan    = get_post_meta( $gaji_id, '_gaji_bulan', true );
    $tahun    = get_post_meta( $gaji_id, '_gaji_tahun', true );
    $status   = get_post_meta( $gaji_id, '_gaji_status', true );
    $labels   = qrg_gaji_guru_bulan_options();
    $periode  = trim( ( isset( $labels[ $bulan ] ) ? $labels[ $bulan ] : $bulan ) . ' ' . $tahun );
    $detail   = qrg_gaji_get_total_detail( $gaji_id );
    $total    = (int) $detail['total'];
    $edit_url = get_edit_post_link( $gaji_id );
    $guru_url = $guru_id ? get_edit_post_link( $guru_id ) : '';
    $guru_nama = $guru_id ? get_the_title( $guru_id ) : '—';
    ?>
    <div class="wrap qrg-gaji-detail-page">
        <h1>
            Detail Perhitungan Gaji
            <a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">← Gaji Guru</a>
            <?php if ( $edit_url ) : ?>
                <a href="<?php echo esc_url( $edit_url ); ?>" class="page-title-action">Edit Slip</a>
            <?php endif; ?>
        </h1>

        <div class="qrg-gaji-detail-grid">
            <div class="qrg-gaji-detail-card">
                <h2>Informasi</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Guru</th>
                        <td>
                            <?php if ( $guru_url ) : ?>
                                <a href="<?php echo esc_url( $guru_url ); ?>"><strong><?php echo esc_html( $guru_nama ); ?></strong></a>
                            <?php else : ?>
                                <strong><?php echo esc_html( $guru_nama ); ?></strong>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Periode</th>
                        <td><?php echo esc_html( $periode ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Status</th>
                        <td>
                            <?php if ( $status === 'Dibayar' ) : ?>
                                <span class="qrg-gaji-badge qrg-gaji-badge--ok">Dibayar</span>
                            <?php else : ?>
                                <span class="qrg-gaji-badge qrg-gaji-badge--warn"><?php echo esc_html( $status ? $status : 'Belum Dibayar' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Total</th>
                        <td><strong class="qrg-gaji-total-big">Rp <?php echo esc_html( number_format( max( 0, $total ), 0, ',', '.' ) ); ?></strong></td>
                    </tr>
                </table>
            </div>

            <div class="qrg-gaji-detail-card">
                <h2>Absensi Periode</h2>
                <div class="qrg-gaji-absen-cards">
                    <div class="qrg-gaji-absen-item qrg-gaji-absen-item--hadir">
                        <span class="label">Hadir</span>
                        <strong><?php echo (int) $detail['hadir']; ?></strong>
                    </div>
                    <div class="qrg-gaji-absen-item qrg-gaji-absen-item--izin">
                        <span class="label">Izin</span>
                        <strong><?php echo (int) $detail['izin']; ?></strong>
                    </div>
                    <div class="qrg-gaji-absen-item qrg-gaji-absen-item--sakit">
                        <span class="label">Sakit</span>
                        <strong><?php echo (int) $detail['sakit']; ?></strong>
                    </div>
                    <div class="qrg-gaji-absen-item qrg-gaji-absen-item--alpha">
                        <span class="label">Alpha</span>
                        <strong><?php echo (int) $detail['alpha']; ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="qrg-gaji-detail-card" style="margin-top:16px;max-width:720px;">
            <h2>Rincian Perhitungan</h2>
            <?php if ( empty( $detail['items'] ) ) : ?>
                <p style="color:#64748b;">Belum ada komponen perhitungan. Pastikan Rumus Gaji sudah diatur, lalu generate ulang atau edit slip.</p>
            <?php else : ?>
                <table class="widefat striped qrg-gaji-detail-table">
                    <thead>
                        <tr>
                            <th>Komponen</th>
                            <th style="width:160px;text-align:right;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $detail['items'] as $item ) :
                            $op     = ( isset( $item['operator'] ) && $item['operator'] === 'minus' ) ? 'minus' : 'plus';
                            $jumlah = isset( $item['jumlah'] ) ? (int) $item['jumlah'] : 0;
                            ?>
                        <tr>
                            <td>
                                <span class="qrg-gaji-op qrg-gaji-op--<?php echo esc_attr( $op ); ?>"><?php echo $op === 'minus' ? '−' : '+'; ?></span>
                                <?php echo esc_html( isset( $item['label'] ) ? $item['label'] : '' ); ?>
                                <?php if ( ! empty( $item['tipe'] ) && $item['tipe'] === 'per_hari' ) : ?>
                                    <div class="qrg-gaji-sub">
                                        <?php echo (int) ( isset( $item['qty'] ) ? $item['qty'] : 0 ); ?> hari
                                        × Rp <?php echo esc_html( number_format( (int) ( isset( $item['nilai'] ) ? $item['nilai'] : 0 ), 0, ',', '.' ) ); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="qrg-gaji-amt">
                                <?php echo $op === 'minus' ? '−' : ''; ?>
                                Rp <?php echo esc_html( number_format( $jumlah, 0, ',', '.' ) ); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total Gaji</th>
                            <th class="qrg-gaji-amt">Rp <?php echo esc_html( number_format( max( 0, $total ), 0, ',', '.' ) ); ?></th>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <style>
        .qrg-gaji-detail-page .qrg-gaji-detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-top: 16px;
            max-width: 720px;
        }
        .qrg-gaji-detail-card {
            background: #fff;
            border: 1px solid #d0d7de;
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: 0 1px 2px rgba(15,23,42,.04);
        }
        .qrg-gaji-detail-card h2 {
            margin: 0 0 12px;
            font-size: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #eef2f7;
        }
        .qrg-gaji-detail-card .form-table { margin: 0; }
        .qrg-gaji-detail-card .form-table th {
            width: 100px;
            padding: 8px 10px 8px 0;
            font-weight: 500;
            color: #64748b;
        }
        .qrg-gaji-detail-card .form-table td { padding: 8px 0; }
        .qrg-gaji-total-big { font-size: 18px; color: #0f172a; }
        .qrg-gaji-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 12px;
        }
        .qrg-gaji-badge--ok { background: #dcfce7; color: #166534; }
        .qrg-gaji-badge--warn { background: #ffedd5; color: #9a3412; }
        .qrg-gaji-absen-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .qrg-gaji-absen-item {
            border-radius: 8px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .qrg-gaji-absen-item .label { font-size: 12px; color: #64748b; }
        .qrg-gaji-absen-item strong { font-size: 22px; line-height: 1.1; }
        .qrg-gaji-absen-item--hadir { background: #ecfdf5; color: #166534; }
        .qrg-gaji-absen-item--izin { background: #eff6ff; color: #1d4ed8; }
        .qrg-gaji-absen-item--sakit { background: #fff7ed; color: #c2410c; }
        .qrg-gaji-absen-item--alpha { background: #fef2f2; color: #b91c1c; }
        .qrg-gaji-detail-table { border-radius: 8px; overflow: hidden; }
        .qrg-gaji-detail-table th,
        .qrg-gaji-detail-table td { padding: 10px 12px; vertical-align: top; }
        .qrg-gaji-detail-table tfoot th {
            border-top: 2px solid #d0d7de;
            font-size: 14px;
        }
        .qrg-gaji-amt { text-align: right; white-space: nowrap; }
        .qrg-gaji-sub { font-size: 12px; color: #64748b; margin-top: 3px; }
        .qrg-gaji-op {
            display: inline-block;
            width: 16px;
            font-weight: 700;
        }
        .qrg-gaji-op--plus { color: #166534; }
        .qrg-gaji-op--minus { color: #b91c1c; }
    </style>
    <?php
}
