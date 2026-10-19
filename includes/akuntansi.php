<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================
 * MODUL AKUNTANSI
 * Menu: Tagihan Siswa, Gaji Guru, Pembelian / Pengeluaran
 * =========================================================
 */

define( 'QRG_AKUNTANSI_SLUG', 'qrg-akuntansi' );
define( 'QRG_AKUNTANSI_PARENT', 'master-data-plugin' );

// -------------------------------------------------------------------------
// 1. Submenu Akuntansi di dalam menu Sekolah
// -------------------------------------------------------------------------
add_action( 'admin_menu', 'qrg_akuntansi_register_menu', 20 );
function qrg_akuntansi_register_menu() {
    add_submenu_page(
        QRG_AKUNTANSI_PARENT,
        'Keuangan',
        'Keuangan',
        'manage_options',
        QRG_AKUNTANSI_SLUG,
        'qrg_akuntansi_dashboard_callback'
    );
}

// Sembunyikan CPT akuntansi dari sidebar (akses via halaman Akuntansi)
add_action( 'admin_menu', 'qrg_akuntansi_hide_cpt_submenus', 999 );
function qrg_akuntansi_hide_cpt_submenus() {
    $parent = QRG_AKUNTANSI_PARENT;
    $hide   = [
        'edit.php?post_type=master_tagihan',
        'post-new.php?post_type=master_tagihan',
        'edit.php?post_type=master_gaji_guru',
        'post-new.php?post_type=master_gaji_guru',
        'edit.php?post_type=master_pembelian',
        'post-new.php?post_type=master_pembelian',
        'edit.php?post_type=master_rkas',
        'post-new.php?post_type=master_rkas',
        'edit.php?post_type=master_biaya',
        'post-new.php?post_type=master_biaya',
    ];
    foreach ( $hide as $slug ) {
        remove_submenu_page( $parent, $slug );
        remove_submenu_page( QRG_AKUNTANSI_SLUG, $slug );
    }
}

/**
 * Cek apakah screen termasuk modul akuntansi.
 */
function qrg_is_akuntansi_screen() {
    $akun_types = [ 'master_tagihan', 'master_gaji_guru', 'master_pembelian', 'master_biaya', 'master_rkas' ];
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && in_array( $screen->post_type, $akun_types, true ) ) {
        return true;
    }
    if ( isset( $_GET['page'] ) ) {
        $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
        if ( in_array( $page, [ QRG_AKUNTANSI_SLUG, 'qrg-generate-kelas', 'qrg-rumus-gaji', 'qrg-generate-gaji', 'qrg-detail-gaji' ], true ) ) {
            return true;
        }
    }
    if ( isset( $_GET['post'] ) ) {
        $pt = get_post_type( absint( $_GET['post'] ) );
        if ( in_array( $pt, $akun_types, true ) ) {
            return true;
        }
    }
    return false;
}

add_filter( 'parent_file', 'qrg_akuntansi_parent_file', 20 );
function qrg_akuntansi_parent_file( $parent_file ) {
    if ( qrg_is_akuntansi_screen() ) {
        return QRG_AKUNTANSI_PARENT;
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_akuntansi_submenu_file', 20 );
function qrg_akuntansi_submenu_file( $submenu_file ) {
    if ( qrg_is_akuntansi_screen() ) {
        return QRG_AKUNTANSI_SLUG;
    }
    return $submenu_file;
}

// -------------------------------------------------------------------------
// 2. Dashboard Akuntansi
// -------------------------------------------------------------------------
function qrg_akuntansi_sum_meta( $post_type, $meta_key, $status_meta = '', $status_value = '' ) {
    $ids = get_posts( [
        'post_type'      => $post_type,
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );
    $sum = 0;
    $n   = 0;
    foreach ( $ids as $id ) {
        if ( $status_meta !== '' ) {
            $st = get_post_meta( $id, $status_meta, true );
            if ( $st !== $status_value ) {
                continue;
            }
        }
        $sum += (int) get_post_meta( $id, $meta_key, true );
        $n++;
    }
    return [ 'sum' => $sum, 'n' => $n, 'total_posts' => count( $ids ) ];
}

function qrg_akuntansi_get_tagihan_nominal( $tagihan_id ) {
    $nominal = (int) get_post_meta( $tagihan_id, '_tagihan_nominal', true );
    if ( ! $nominal ) {
        $nominal = (int) get_post_meta( $tagihan_id, '_tagihan_jumlah', true );
    }
    $biaya_id = get_post_meta( $tagihan_id, '_tagihan_biaya_tambahan_id', true );
    if ( $biaya_id ) {
        $nominal += (int) get_post_meta( $biaya_id, '_biaya_nominal', true );
    }
    return max( 0, $nominal );
}

/**
 * Ambil entri jurnal pembukuan dari transaksi realisasi.
 *
 * @param int|null $filter_tahun Tahun atau null = semua.
 * @return array{entries:array,total_debit:int,total_kredit:int,saldo_akhir:int}
 */
function qrg_akuntansi_get_jurnal_pembukuan( $filter_tahun = null ) {
    $entries = [];

    // 1. Pemasukan: Tagihan Lunas — Debit Kas / Kredit Pendapatan Siswa
    $tagihan_ids = get_posts( [
        'post_type'      => 'master_tagihan',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );
    foreach ( $tagihan_ids as $tid ) {
        if ( get_post_meta( $tid, '_tagihan_status', true ) !== 'Lunas' ) {
            continue;
        }
        $nominal = qrg_akuntansi_get_tagihan_nominal( $tid );
        if ( $nominal <= 0 ) {
            continue;
        }
        $post = get_post( $tid );
        $tgl  = $post ? substr( $post->post_modified, 0, 10 ) : current_time( 'Y-m-d' );
        if ( $filter_tahun && (int) substr( $tgl, 0, 4 ) !== (int) $filter_tahun ) {
            continue;
        }
        $siswa_id = (int) get_post_meta( $tid, '_tagihan_siswa_id', true );
        $siswa    = $siswa_id ? get_the_title( $siswa_id ) : '';
        $ket      = get_post_meta( $tid, '_tagihan_keterangan', true );
        $uraian   = trim( ( $ket ? $ket : get_the_title( $tid ) ) . ( $siswa ? ' — ' . $siswa : '' ) );

        $entries[] = [
            'tanggal'    => $tgl,
            'no_bukti'   => 'TG-' . $tid,
            'keterangan' => $uraian,
            'akun_debit' => 'Kas',
            'akun_kredit'=> 'Pendapatan Siswa',
            'ref'        => '4-100',
            'jenis'      => 'pemasukan',
            'debit'      => $nominal, // masuk kas
            'kredit'     => 0,
            'link'       => get_edit_post_link( $tid, 'raw' ),
            'sort'       => $tgl . '-A-' . $tid,
        ];
    }

    // 2. Pengeluaran: Gaji Dibayar — Debit Beban Gaji / Kredit Kas
    $gaji_ids = get_posts( [
        'post_type'      => 'master_gaji_guru',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );
    $bulan_labels = function_exists( 'qrg_gaji_guru_bulan_options' ) ? qrg_gaji_guru_bulan_options() : [];
    foreach ( $gaji_ids as $gid ) {
        if ( get_post_meta( $gid, '_gaji_status', true ) !== 'Dibayar' ) {
            continue;
        }
        $nominal = (int) get_post_meta( $gid, '_gaji_total', true );
        if ( $nominal <= 0 ) {
            continue;
        }
        $tahun = (int) get_post_meta( $gid, '_gaji_tahun', true );
        $bulan = (string) get_post_meta( $gid, '_gaji_bulan', true );
        if ( strlen( $bulan ) === 1 ) {
            $bulan = str_pad( $bulan, 2, '0', STR_PAD_LEFT );
        }
        if ( $filter_tahun && $tahun && (int) $filter_tahun !== $tahun ) {
            continue;
        }
        $tgl = ( $tahun && $bulan && $bulan !== '00' )
            ? sprintf( '%04d-%s-28', $tahun, $bulan )
            : ( get_post( $gid ) ? substr( get_post( $gid )->post_date, 0, 10 ) : current_time( 'Y-m-d' ) );
        $guru_id = (int) get_post_meta( $gid, '_gaji_guru_id', true );
        $guru    = $guru_id ? get_the_title( $guru_id ) : 'Guru';
        $periode = ( isset( $bulan_labels[ $bulan ] ) ? $bulan_labels[ $bulan ] : $bulan ) . ' ' . $tahun;

        $entries[] = [
            'tanggal'    => $tgl,
            'no_bukti'   => 'GJ-' . $gid,
            'keterangan' => 'Gaji ' . $guru . ' — ' . $periode,
            'akun_debit' => 'Beban Gaji Guru',
            'akun_kredit'=> 'Kas',
            'ref'        => '5-100',
            'jenis'      => 'pengeluaran',
            'debit'      => 0,
            'kredit'     => $nominal, // keluar kas
            'link'       => get_edit_post_link( $gid, 'raw' ),
            'sort'       => $tgl . '-B-' . $gid,
        ];
    }

    // 3. Pengeluaran: Pembelanjaan Lunas — Debit Beban Pembelanjaan / Kredit Kas
    $beli_ids = get_posts( [
        'post_type'      => 'master_pembelian',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );
    foreach ( $beli_ids as $bid ) {
        if ( get_post_meta( $bid, '_pembelian_status', true ) !== 'Lunas' ) {
            continue;
        }
        $nominal = (int) get_post_meta( $bid, '_pembelian_total', true );
        if ( $nominal <= 0 ) {
            continue;
        }
        $tgl = get_post_meta( $bid, '_pembelian_tanggal', true );
        if ( ! $tgl ) {
            $tgl = get_post( $bid ) ? substr( get_post( $bid )->post_date, 0, 10 ) : current_time( 'Y-m-d' );
        }
        if ( $filter_tahun && (int) substr( $tgl, 0, 4 ) !== (int) $filter_tahun ) {
            continue;
        }
        $kat = get_post_meta( $bid, '_pembelian_kategori', true );
        $entries[] = [
            'tanggal'    => $tgl,
            'no_bukti'   => 'PB-' . $bid,
            'keterangan' => get_the_title( $bid ) . ( $kat ? ' (' . $kat . ')' : '' ),
            'akun_debit' => 'Beban Pembelanjaan',
            'akun_kredit'=> 'Kas',
            'ref'        => '5-200',
            'jenis'      => 'pengeluaran',
            'debit'      => 0,
            'kredit'     => $nominal, // keluar kas
            'link'       => get_edit_post_link( $bid, 'raw' ),
            'sort'       => $tgl . '-C-' . $bid,
        ];
    }

    usort( $entries, function ( $a, $b ) {
        return strcmp( $a['sort'], $b['sort'] );
    } );

    $saldo        = 0;
    $total_debit  = 0;
    $total_kredit = 0;
    foreach ( $entries as $i => $row ) {
        $d = (int) $row['debit'];
        $k = (int) $row['kredit'];
        $total_debit  += $d;
        $total_kredit += $k;
        $saldo        += $d - $k;
        $entries[ $i ]['saldo'] = $saldo;
    }

    return [
        'entries'      => $entries,
        'total_debit'  => $total_debit,
        'total_kredit' => $total_kredit,
        'saldo_akhir'  => $saldo,
    ];
}

/**
 * Bangun data Decision Support System (DSS) keuangan sekolah.
 *
 * @param array $ctx Konteks angka dari dashboard.
 * @return array{score:int,level:string,level_label:string,indicators:array,alerts:array,recommendations:array}
 */
function qrg_akuntansi_build_dss( $ctx ) {
    $pemasukan           = (int) ( $ctx['pemasukan'] ?? 0 );
    $pengeluaran         = (int) ( $ctx['pengeluaran'] ?? 0 );
    $saldo               = (int) ( $ctx['saldo'] ?? 0 );
    $tagihan_lunas       = (int) ( $ctx['tagihan_lunas'] ?? 0 );
    $tagihan_belum       = (int) ( $ctx['tagihan_belum'] ?? 0 );
    $tagihan_lunas_n     = (int) ( $ctx['tagihan_lunas_n'] ?? 0 );
    $tagihan_belum_n     = (int) ( $ctx['tagihan_belum_n'] ?? 0 );
    $gaji_dibayar        = (int) ( $ctx['gaji_dibayar'] ?? 0 );
    $gaji_belum          = (int) ( $ctx['gaji_belum'] ?? 0 );
    $beli_lunas          = (int) ( $ctx['beli_lunas'] ?? 0 );
    $beli_belum          = (int) ( $ctx['beli_belum'] ?? 0 );
    $rkas_anggaran       = (int) ( $ctx['rkas_anggaran'] ?? 0 );
    $rkas_realisasi      = (int) ( $ctx['rkas_realisasi'] ?? 0 );
    $pengeluaran_pending = (int) ( $ctx['pengeluaran_pending'] ?? 0 );

    $total_tagihan = $tagihan_lunas + $tagihan_belum;
    $kolektibilitas = $total_tagihan > 0
        ? round( ( $tagihan_lunas / $total_tagihan ) * 100, 1 )
        : 0.0;

    $rasio_beban = $pemasukan > 0
        ? round( ( $pengeluaran / $pemasukan ) * 100, 1 )
        : ( $pengeluaran > 0 ? 100.0 : 0.0 );

    $saldo_proyeksi = $saldo + $tagihan_belum - $pengeluaran_pending;

    $serapan_rkas = $rkas_anggaran > 0
        ? round( ( $rkas_realisasi / $rkas_anggaran ) * 100, 1 )
        : 0.0;

    $share_gaji = $pengeluaran > 0 ? round( ( $gaji_dibayar / $pengeluaran ) * 100, 1 ) : 0.0;
    $share_beli = $pengeluaran > 0 ? round( ( $beli_lunas / $pengeluaran ) * 100, 1 ) : 0.0;

    // Skor kesehatan 0–100
    $score = 50;
    if ( $saldo > 0 ) {
        $score += 15;
    } elseif ( $saldo < 0 ) {
        $score -= 25;
    }
    if ( $kolektibilitas >= 80 ) {
        $score += 15;
    } elseif ( $kolektibilitas >= 50 ) {
        $score += 5;
    } elseif ( $total_tagihan > 0 ) {
        $score -= 15;
    }
    if ( $rasio_beban <= 70 ) {
        $score += 10;
    } elseif ( $rasio_beban <= 90 ) {
        $score += 3;
    } elseif ( $rasio_beban > 100 ) {
        $score -= 20;
    } else {
        $score -= 8;
    }
    if ( $saldo_proyeksi < 0 ) {
        $score -= 10;
    } elseif ( $saldo_proyeksi > 0 && $pengeluaran_pending > 0 ) {
        $score += 5;
    }
    if ( $rkas_anggaran > 0 ) {
        if ( $serapan_rkas > 110 ) {
            $score -= 10;
        } elseif ( $serapan_rkas >= 60 && $serapan_rkas <= 100 ) {
            $score += 5;
        } elseif ( $serapan_rkas < 30 && $rkas_anggaran > 0 ) {
            $score -= 3;
        }
    }
    $score = max( 0, min( 100, $score ) );

    if ( $score >= 75 ) {
        $level       = 'baik';
        $level_label = 'Baik';
    } elseif ( $score >= 50 ) {
        $level       = 'waspada';
        $level_label = 'Waspada';
    } else {
        $level       = 'kritis';
        $level_label = 'Kritis';
    }

    $links = [
        'tagihan'   => admin_url( 'edit.php?post_type=master_tagihan' ),
        'gaji'      => admin_url( 'edit.php?post_type=master_gaji_guru' ),
        'pembelian' => admin_url( 'edit.php?post_type=master_pembelian' ),
        'rkas'      => admin_url( 'edit.php?post_type=master_rkas' ),
        'gen_tag'   => admin_url( 'admin.php?page=qrg-generate-kelas' ),
        'gen_gaji'  => admin_url( 'admin.php?page=qrg-generate-gaji' ),
    ];

    $alerts = [];
    $recs   = [];

    if ( $saldo < 0 ) {
        $alerts[] = [
            'level'   => 'critical',
            'title'   => 'Saldo kas negatif',
            'message' => 'Pengeluaran realisasi melebihi pemasukan tagihan lunas. Prioritaskan penagihan dan tahan belanja non-mendesak.',
        ];
        $recs[] = [
            'priority' => 1,
            'title'    => 'Percepat penagihan siswa',
            'reason'   => 'Saldo defisit; piutang siswa masih Rp ' . number_format( $tagihan_belum, 0, ',', '.' ),
            'action'   => 'Buka Tagihan',
            'url'      => $links['tagihan'],
        ];
    }

    if ( $saldo_proyeksi < 0 ) {
        $alerts[] = [
            'level'   => 'warning',
            'title'   => 'Proyeksi kas tetap defisit',
            'message' => 'Setelah memperhitungkan piutang dan kewajiban pending, proyeksi saldo masih negatif.',
        ];
    }

    if ( $kolektibilitas < 50 && $total_tagihan > 0 ) {
        $alerts[] = [
            'level'   => 'warning',
            'title'   => 'Kolektibilitas tagihan rendah',
            'message' => 'Hanya ' . $kolektibilitas . '% tagihan yang lunas (' . $tagihan_lunas_n . ' dari ' . ( $tagihan_lunas_n + $tagihan_belum_n ) . ').',
        ];
        $recs[] = [
            'priority' => 2,
            'title'    => 'Tindak lanjut tagihan belum lunas',
            'reason'   => $tagihan_belum_n . ' tagihan belum dibayar senilai Rp ' . number_format( $tagihan_belum, 0, ',', '.' ),
            'action'   => 'Kelola Tagihan',
            'url'      => $links['tagihan'],
        ];
    } elseif ( $kolektibilitas < 80 && $tagihan_belum > 0 ) {
        $recs[] = [
            'priority' => 3,
            'title'    => 'Tingkatkan kolektibilitas ke ≥80%',
            'reason'   => 'Saat ini ' . $kolektibilitas . '%. Sisa piutang Rp ' . number_format( $tagihan_belum, 0, ',', '.' ),
            'action'   => 'Lihat Tagihan',
            'url'      => $links['tagihan'],
        ];
    }

    if ( $rasio_beban > 100 ) {
        $alerts[] = [
            'level'   => 'critical',
            'title'   => 'Rasio beban > 100%',
            'message' => 'Pengeluaran realisasi (' . $rasio_beban . '%) lebih besar dari pemasukan.',
        ];
    } elseif ( $rasio_beban > 85 ) {
        $alerts[] = [
            'level'   => 'warning',
            'title'   => 'Rasio beban tinggi',
            'message' => 'Pengeluaran mencapai ' . $rasio_beban . '% dari pemasukan. Sisa kas tipis.',
        ];
        $recs[] = [
            'priority' => 2,
            'title'    => 'Tinjau belanja operasional',
            'reason'   => 'Pembelanjaan belum lunas Rp ' . number_format( $beli_belum, 0, ',', '.' ),
            'action'   => 'Daftar Pembelanjaan',
            'url'      => $links['pembelian'],
        ];
    }

    if ( $gaji_belum > 0 ) {
        if ( $saldo >= $gaji_belum ) {
            $recs[] = [
                'priority' => 2,
                'title'    => 'Saldo mencukupi untuk bayar gaji tertunda',
                'reason'   => 'Gaji belum dibayar Rp ' . number_format( $gaji_belum, 0, ',', '.' ) . ' — kas tersedia.',
                'action'   => 'Bayar Gaji',
                'url'      => $links['gaji'],
            ];
        } else {
            $alerts[] = [
                'level'   => 'warning',
                'title'   => 'Gaji tertunda tanpa buffer kas',
                'message' => 'Kewajiban gaji Rp ' . number_format( $gaji_belum, 0, ',', '.' ) . ' belum tertutup penuh oleh saldo kas.',
            ];
            $recs[] = [
                'priority' => 1,
                'title'    => 'Prioritaskan kas untuk gaji guru',
                'reason'   => 'Tahan pembelanjaan non-mendesak hingga gaji tertutup.',
                'action'   => 'Lihat Gaji',
                'url'      => $links['gaji'],
            ];
        }
    }

    if ( $rkas_anggaran > 0 ) {
        if ( $serapan_rkas > 100 ) {
            $alerts[] = [
                'level'   => 'critical',
                'title'   => 'Realisasi RKAS melebihi anggaran',
                'message' => 'Serapan ' . $serapan_rkas . '%. Perlu evaluasi kegiatan atau revisi anggaran.',
            ];
            $recs[] = [
                'priority' => 1,
                'title'    => 'Review kegiatan RKAS over-budget',
                'reason'   => 'Realisasi Rp ' . number_format( $rkas_realisasi, 0, ',', '.' ) . ' vs anggaran Rp ' . number_format( $rkas_anggaran, 0, ',', '.' ),
                'action'   => 'Buka RKAS',
                'url'      => $links['rkas'],
            ];
        } elseif ( $serapan_rkas < 40 ) {
            $recs[] = [
                'priority' => 4,
                'title'    => 'Percepat realisasi kegiatan RKAS',
                'reason'   => 'Serapan baru ' . $serapan_rkas . '%. Banyak anggaran belum terealisasi.',
                'action'   => 'Kelola RKAS',
                'url'      => $links['rkas'],
            ];
        }
    } else {
        $recs[] = [
            'priority' => 5,
            'title'    => 'Susun / lengkapi RKAS',
            'reason'   => 'Belum ada data anggaran RKAS untuk perencanaan keputusan.',
            'action'   => 'Tambah RKAS',
            'url'      => $links['rkas'],
        ];
    }

    if ( $total_tagihan === 0 ) {
        $recs[] = [
            'priority' => 4,
            'title'    => 'Generate tagihan siswa',
            'reason'   => 'Belum ada data tagihan sebagai sumber pemasukan.',
            'action'   => 'Generate Tagihan',
            'url'      => $links['gen_tag'],
        ];
    }

    if ( empty( $alerts ) && $score >= 75 ) {
        $alerts[] = [
            'level'   => 'info',
            'title'   => 'Kondisi keuangan relatif sehat',
            'message' => 'Pertahankan kolektibilitas dan pantau rasio beban agar tetap di bawah 85%.',
        ];
    }

    if ( empty( $recs ) ) {
        $recs[] = [
            'priority' => 5,
            'title'    => 'Pantau jurnal pembukuan berkala',
            'reason'   => 'Tidak ada aksi kritis saat ini. Lanjutkan monitoring saldo dan RKAS.',
            'action'   => 'Lihat RKAS',
            'url'      => $links['rkas'],
        ];
    }

    usort( $recs, function ( $a, $b ) {
        return (int) $a['priority'] - (int) $b['priority'];
    } );
    $recs = array_slice( $recs, 0, 5 );

    $indicators = [
        [
            'key'   => 'kolektibilitas',
            'label' => 'Kolektibilitas Tagihan',
            'value' => $kolektibilitas . '%',
            'hint'  => 'Lunas vs total tagihan',
            'tone'  => $kolektibilitas >= 80 ? 'good' : ( $kolektibilitas >= 50 ? 'warn' : 'bad' ),
            'pct'   => min( 100, $kolektibilitas ),
        ],
        [
            'key'   => 'rasio_beban',
            'label' => 'Rasio Beban',
            'value' => $rasio_beban . '%',
            'hint'  => 'Pengeluaran / pemasukan',
            'tone'  => $rasio_beban <= 70 ? 'good' : ( $rasio_beban <= 90 ? 'warn' : 'bad' ),
            'pct'   => min( 100, $rasio_beban ),
        ],
        [
            'key'   => 'saldo_proyeksi',
            'label' => 'Saldo Proyeksi',
            'value' => ( $saldo_proyeksi < 0 ? '-Rp ' : 'Rp ' ) . number_format( abs( $saldo_proyeksi ), 0, ',', '.' ),
            'hint'  => 'Saldo + piutang − kewajiban',
            'tone'  => $saldo_proyeksi >= 0 ? 'good' : 'bad',
            'pct'   => null,
        ],
        [
            'key'   => 'serapan_rkas',
            'label' => 'Serapan RKAS',
            'value' => $rkas_anggaran > 0 ? ( $serapan_rkas . '%' ) : '—',
            'hint'  => $rkas_anggaran > 0 ? 'Realisasi / anggaran' : 'Belum ada anggaran',
            'tone'  => $rkas_anggaran <= 0 ? 'warn' : ( $serapan_rkas > 100 ? 'bad' : ( $serapan_rkas >= 40 ? 'good' : 'warn' ) ),
            'pct'   => $rkas_anggaran > 0 ? min( 100, $serapan_rkas ) : 0,
        ],
        [
            'key'   => 'komposisi_gaji',
            'label' => 'Porsi Gaji',
            'value' => $share_gaji . '%',
            'hint'  => 'Dari total pengeluaran realisasi',
            'tone'  => $share_gaji <= 80 ? 'good' : 'warn',
            'pct'   => min( 100, $share_gaji ),
        ],
        [
            'key'   => 'komposisi_beli',
            'label' => 'Porsi Pembelanjaan',
            'value' => $share_beli . '%',
            'hint'  => 'Dari total pengeluaran realisasi',
            'tone'  => 'info',
            'pct'   => min( 100, $share_beli ),
        ],
    ];

    return [
        'score'           => $score,
        'level'           => $level,
        'level_label'     => $level_label,
        'indicators'      => $indicators,
        'alerts'          => $alerts,
        'recommendations' => $recs,
        'meta'            => [
            'kolektibilitas'  => $kolektibilitas,
            'rasio_beban'     => $rasio_beban,
            'saldo_proyeksi'  => $saldo_proyeksi,
            'serapan_rkas'    => $serapan_rkas,
        ],
    ];
}

function qrg_akuntansi_dashboard_callback() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $tagihan_lunas = [ 'sum' => 0, 'n' => 0 ];
    $tagihan_belum = [ 'sum' => 0, 'n' => 0 ];
    $tagihan_ids   = get_posts( [
        'post_type'      => 'master_tagihan',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );
    foreach ( $tagihan_ids as $tid ) {
        $nominal = qrg_akuntansi_get_tagihan_nominal( $tid );
        $status  = get_post_meta( $tid, '_tagihan_status', true );
        if ( $status === 'Lunas' ) {
            $tagihan_lunas['sum'] += $nominal;
            $tagihan_lunas['n']++;
        } else {
            $tagihan_belum['sum'] += $nominal;
            $tagihan_belum['n']++;
        }
    }

    // Semua tahun agar saldo menyeluruh
    $gaji_stats = function_exists( 'qrg_gaji_get_dashboard_stats' )
        ? qrg_gaji_get_dashboard_stats( null )
        : [ 'total_gaji' => 0, 'dibayar_sum' => 0, 'belum_sum' => 0, 'total_slip' => 0 ];

    $pembelian_all   = qrg_akuntansi_sum_meta( 'master_pembelian', '_pembelian_total' );
    $pembelian_lunas = qrg_akuntansi_sum_meta( 'master_pembelian', '_pembelian_total', '_pembelian_status', 'Lunas' );
    $pembelian_belum = qrg_akuntansi_sum_meta( 'master_pembelian', '_pembelian_total', '_pembelian_status', 'Belum Lunas' );

    // Realisasi kas
    $pemasukan    = (int) $tagihan_lunas['sum'];
    $pengeluaran  = (int) $gaji_stats['dibayar_sum'] + (int) $pembelian_lunas['sum'];
    $saldo        = $pemasukan - $pengeluaran;
    $pengeluaran_pending = (int) $gaji_stats['belum_sum'] + (int) $pembelian_belum['sum'];

    $fmt = function ( $n ) {
        $n = (int) $n;
        $abs = number_format( abs( $n ), 0, ',', '.' );
        return ( $n < 0 ? '-Rp ' : 'Rp ' ) . $abs;
    };

    $links = [
        'tagihan'   => admin_url( 'edit.php?post_type=master_tagihan' ),
        'gaji'      => admin_url( 'edit.php?post_type=master_gaji_guru' ),
        'pembelian' => admin_url( 'edit.php?post_type=master_pembelian' ),
        'rkas'      => admin_url( 'edit.php?post_type=master_rkas' ),
        'generate'  => admin_url( 'admin.php?page=qrg-generate-gaji' ),
        'gen_tag'   => admin_url( 'admin.php?page=qrg-generate-kelas' ),
    ];

    $rkas_stats = qrg_rkas_get_summary_stats();
    $saldo_class = $saldo >= 0 ? 'is-positif' : 'is-negatif';

    $jurnal_tahun = isset( $_GET['jurnal_tahun'] ) ? sanitize_text_field( wp_unslash( $_GET['jurnal_tahun'] ) ) : (string) current_time( 'Y' );
    if ( $jurnal_tahun === 'all' ) {
        $jurnal_filter = null;
    } else {
        $jurnal_filter = absint( $jurnal_tahun ) ?: (int) current_time( 'Y' );
        $jurnal_tahun  = (string) $jurnal_filter;
    }
    $jurnal = qrg_akuntansi_get_jurnal_pembukuan( $jurnal_filter );

    $dss = qrg_akuntansi_build_dss( [
        'pemasukan'           => $pemasukan,
        'pengeluaran'         => $pengeluaran,
        'saldo'               => $saldo,
        'tagihan_lunas'       => $tagihan_lunas['sum'],
        'tagihan_belum'       => $tagihan_belum['sum'],
        'tagihan_lunas_n'     => $tagihan_lunas['n'],
        'tagihan_belum_n'     => $tagihan_belum['n'],
        'gaji_dibayar'        => $gaji_stats['dibayar_sum'],
        'gaji_belum'          => $gaji_stats['belum_sum'],
        'beli_lunas'          => $pembelian_lunas['sum'],
        'beli_belum'          => $pembelian_belum['sum'],
        'rkas_anggaran'       => $rkas_stats['anggaran'],
        'rkas_realisasi'      => $rkas_stats['realisasi'],
        'pengeluaran_pending' => $pengeluaran_pending,
    ] );
    ?>
    <div class="wrap qrg-akuntansi-dash">
        <h1>
            Keuangan
            <a href="<?php echo esc_url( $links['tagihan'] ); ?>" class="page-title-action">Tagihan</a>
            <a href="<?php echo esc_url( $links['rkas'] ); ?>" class="page-title-action">RKAS</a>
        </h1>
        <p class="description">Saldo dihitung dari pemasukan tagihan siswa yang <strong>Lunas</strong>, dikurangi pengeluaran gaji yang <strong>Dibayar</strong> dan pembelian/utilitas yang <strong>Lunas</strong>.</p>

        <div class="qrg-dss qrg-dss--<?php echo esc_attr( $dss['level'] ); ?>">
            <div class="qrg-dss__head">
                <div>
                    <h2>DSS — Decision Support System</h2>
                    <p>Analisis otomatis kondisi kas sekolah untuk membantu keputusan keuangan.</p>
                </div>
                <div class="qrg-dss__score">
                    <span class="qrg-dss__score-label">Skor Kesehatan</span>
                    <strong class="qrg-dss__score-value"><?php echo (int) $dss['score']; ?></strong>
                    <span class="qrg-dss__score-level"><?php echo esc_html( $dss['level_label'] ); ?></span>
                </div>
            </div>

            <div class="qrg-dss__indicators">
                <?php foreach ( $dss['indicators'] as $ind ) : ?>
                    <div class="qrg-dss-ind qrg-dss-ind--<?php echo esc_attr( $ind['tone'] ); ?>">
                        <span class="qrg-dss-ind__label"><?php echo esc_html( $ind['label'] ); ?></span>
                        <strong class="qrg-dss-ind__value"><?php echo esc_html( $ind['value'] ); ?></strong>
                        <span class="qrg-dss-ind__hint"><?php echo esc_html( $ind['hint'] ); ?></span>
                        <?php if ( $ind['pct'] !== null ) : ?>
                            <div class="qrg-dss-ind__bar" aria-hidden="true">
                                <span style="width:<?php echo esc_attr( (string) min( 100, max( 0, (float) $ind['pct'] ) ) ); ?>%;"></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $dss['alerts'] ) ) : ?>
            <div class="qrg-dss__section">
                <h3>Peringatan</h3>
                <ul class="qrg-dss-alerts">
                    <?php foreach ( $dss['alerts'] as $alert ) : ?>
                        <li class="qrg-dss-alert qrg-dss-alert--<?php echo esc_attr( $alert['level'] ); ?>">
                            <strong><?php echo esc_html( $alert['title'] ); ?></strong>
                            <span><?php echo esc_html( $alert['message'] ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="qrg-dss__section">
                <h3>Rekomendasi Keputusan</h3>
                <ol class="qrg-dss-recs">
                    <?php foreach ( $dss['recommendations'] as $i => $rec ) : ?>
                        <li>
                            <div class="qrg-dss-rec">
                                <span class="qrg-dss-rec__num"><?php echo (int) ( $i + 1 ); ?></span>
                                <div class="qrg-dss-rec__body">
                                    <strong><?php echo esc_html( $rec['title'] ); ?></strong>
                                    <span><?php echo esc_html( $rec['reason'] ); ?></span>
                                </div>
                                <?php if ( ! empty( $rec['url'] ) ) : ?>
                                    <a class="button button-small" href="<?php echo esc_url( $rec['url'] ); ?>"><?php echo esc_html( $rec['action'] ); ?></a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>

        <div class="qrg-akun-rkas-banner">
            <div>
                <strong>RKAS</strong> — Rencana Kegiatan Anggaran Sekolah
                <div class="qrg-akun-rkas-banner__meta">
                    Anggaran: <strong><?php echo esc_html( $fmt( $rkas_stats['anggaran'] ) ); ?></strong>
                    · Realisasi: <strong><?php echo esc_html( $fmt( $rkas_stats['realisasi'] ) ); ?></strong>
                    · <?php echo (int) $rkas_stats['n']; ?> kegiatan
                </div>
            </div>
            <div class="qrg-akun-rkas-banner__actions">
                <a class="button button-primary button-hero" href="<?php echo esc_url( $links['rkas'] ); ?>">Buka RKAS</a>
            </div>
        </div>

        <div class="qrg-akun-saldo <?php echo esc_attr( $saldo_class ); ?>">
            <div class="qrg-akun-saldo__main">
                <span class="qrg-akun-saldo__label">Saldo Kas</span>
                <strong class="qrg-akun-saldo__value"><?php echo esc_html( $fmt( $saldo ) ); ?></strong>
                <span class="qrg-akun-saldo__hint">
                    Pemasukan <?php echo esc_html( $fmt( $pemasukan ) ); ?>
                    − Pengeluaran <?php echo esc_html( $fmt( $pengeluaran ) ); ?>
                </span>
            </div>
            <div class="qrg-akun-saldo__cols">
                <div>
                    <span class="lab">Pemasukan (siswa)</span>
                    <strong class="val val--in"><?php echo esc_html( $fmt( $pemasukan ) ); ?></strong>
                    <span class="sub">Tagihan lunas · <?php echo (int) $tagihan_lunas['n']; ?> transaksi</span>
                </div>
                <div>
                    <span class="lab">Pengeluaran</span>
                    <strong class="val val--out"><?php echo esc_html( $fmt( $pengeluaran ) ); ?></strong>
                    <span class="sub">
                        Gaji dibayar <?php echo esc_html( $fmt( $gaji_stats['dibayar_sum'] ) ); ?>
                        + Pembelian/listrik <?php echo esc_html( $fmt( $pembelian_lunas['sum'] ) ); ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ( $tagihan_belum['sum'] > 0 || $pengeluaran_pending > 0 ) : ?>
        <div class="qrg-akun-pending">
            <strong>Belum terealisasi:</strong>
            piutang siswa <?php echo esc_html( $fmt( $tagihan_belum['sum'] ) ); ?>
            · kewajiban pengeluaran <?php echo esc_html( $fmt( $pengeluaran_pending ) ); ?>
            (gaji belum dibayar + pembelian belum lunas)
        </div>
        <?php endif; ?>

        <div class="qrg-akun-grid">
            <a class="qrg-akun-card qrg-akun-card--in" href="<?php echo esc_url( $links['tagihan'] ); ?>">
                <span class="qrg-akun-card__eyebrow">Pemasukan</span>
                <h2>Tagihan Siswa</h2>
                <strong><?php echo esc_html( $fmt( $pemasukan ) ); ?></strong>
                <div class="qrg-akun-card__meta">
                    <span>Lunas: <?php echo esc_html( $fmt( $tagihan_lunas['sum'] ) ); ?> (<?php echo (int) $tagihan_lunas['n']; ?>)</span>
                    <span>Belum: <?php echo esc_html( $fmt( $tagihan_belum['sum'] ) ); ?> (<?php echo (int) $tagihan_belum['n']; ?>)</span>
                </div>
            </a>

            <a class="qrg-akun-card qrg-akun-card--out" href="<?php echo esc_url( $links['gaji'] ); ?>">
                <span class="qrg-akun-card__eyebrow">Pengeluaran</span>
                <h2>Gaji Guru</h2>
                <strong><?php echo esc_html( $fmt( $gaji_stats['dibayar_sum'] ) ); ?></strong>
                <div class="qrg-akun-card__meta">
                    <span>Dibayar: <?php echo esc_html( $fmt( $gaji_stats['dibayar_sum'] ) ); ?></span>
                    <span>Belum: <?php echo esc_html( $fmt( $gaji_stats['belum_sum'] ) ); ?> · <?php echo (int) $gaji_stats['total_slip']; ?> slip</span>
                </div>
            </a>

            <a class="qrg-akun-card qrg-akun-card--buy" href="<?php echo esc_url( $links['pembelian'] ); ?>">
                <span class="qrg-akun-card__eyebrow">Pengeluaran</span>
                <h2>Pembelanjaan</h2>
                <strong><?php echo esc_html( $fmt( $pembelian_lunas['sum'] ) ); ?></strong>
                <div class="qrg-akun-card__meta">
                    <span>Lunas: <?php echo esc_html( $fmt( $pembelian_lunas['sum'] ) ); ?> (<?php echo (int) $pembelian_lunas['n']; ?>)</span>
                    <span>Belum: <?php echo esc_html( $fmt( $pembelian_belum['sum'] ) ); ?> (<?php echo (int) $pembelian_belum['n']; ?>)</span>
                    <span>Total tercatat: <?php echo esc_html( $fmt( $pembelian_all['sum'] ) ); ?></span>
                </div>
            </a>
        </div>

        <div class="qrg-akun-actions">
            <div class="qrg-akun-actions__head">
                <div>
                    <h2>Aksi Cepat</h2>
                    <p>Pintasan menu keuangan yang paling sering digunakan</p>
                </div>
            </div>

            <div class="qrg-akun-actions__group">
                <span class="qrg-akun-actions__label">Perencanaan</span>
                <div class="qrg-akun-actions__grid">
                    <a class="qrg-akun-act qrg-akun-act--plan" href="<?php echo esc_url( $links['rkas'] ); ?>">
                        <span class="qrg-akun-act__icon" aria-hidden="true"><span class="dashicons dashicons-chart-area"></span></span>
                        <span class="qrg-akun-act__body">
                            <strong>RKAS</strong>
                            <span>Rencana kegiatan &amp; anggaran sekolah</span>
                        </span>
                        <span class="qrg-akun-act__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </a>
                </div>
            </div>

            <div class="qrg-akun-actions__group">
                <span class="qrg-akun-actions__label">Pemasukan</span>
                <div class="qrg-akun-actions__grid">
                    <a class="qrg-akun-act qrg-akun-act--in" href="<?php echo esc_url( $links['tagihan'] ); ?>">
                        <span class="qrg-akun-act__icon" aria-hidden="true"><span class="dashicons dashicons-money-alt"></span></span>
                        <span class="qrg-akun-act__body">
                            <strong>Tagihan Siswa</strong>
                            <span>Kelola tagihan &amp; pembayaran siswa</span>
                        </span>
                        <span class="qrg-akun-act__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </a>
                    <a class="qrg-akun-act qrg-akun-act--in qrg-akun-act--soft" href="<?php echo esc_url( $links['gen_tag'] ); ?>">
                        <span class="qrg-akun-act__icon" aria-hidden="true"><span class="dashicons dashicons-update"></span></span>
                        <span class="qrg-akun-act__body">
                            <strong>Generate Tagihan</strong>
                            <span>Buat tagihan massal per kelas</span>
                        </span>
                        <span class="qrg-akun-act__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </a>
                </div>
            </div>

            <div class="qrg-akun-actions__group">
                <span class="qrg-akun-actions__label">Pengeluaran</span>
                <div class="qrg-akun-actions__grid">
                    <a class="qrg-akun-act qrg-akun-act--out" href="<?php echo esc_url( $links['gaji'] ); ?>">
                        <span class="qrg-akun-act__icon" aria-hidden="true"><span class="dashicons dashicons-groups"></span></span>
                        <span class="qrg-akun-act__body">
                            <strong>Gaji Guru</strong>
                            <span>Slip gaji &amp; status pembayaran</span>
                        </span>
                        <span class="qrg-akun-act__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </a>
                    <a class="qrg-akun-act qrg-akun-act--out qrg-akun-act--soft" href="<?php echo esc_url( $links['generate'] ); ?>">
                        <span class="qrg-akun-act__icon" aria-hidden="true"><span class="dashicons dashicons-calculator"></span></span>
                        <span class="qrg-akun-act__body">
                            <strong>Generate Gaji</strong>
                            <span>Hitung &amp; buat slip gaji bulanan</span>
                        </span>
                        <span class="qrg-akun-act__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </a>
                    <a class="qrg-akun-act qrg-akun-act--buy" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=master_pembelian' ) ); ?>">
                        <span class="qrg-akun-act__icon" aria-hidden="true"><span class="dashicons dashicons-cart"></span></span>
                        <span class="qrg-akun-act__body">
                            <strong>Tambah Pembelanjaan</strong>
                            <span>Catat pengeluaran baru</span>
                        </span>
                        <span class="qrg-akun-act__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </a>
                    <a class="qrg-akun-act qrg-akun-act--buy qrg-akun-act--soft" href="<?php echo esc_url( $links['pembelian'] ); ?>">
                        <span class="qrg-akun-act__icon" aria-hidden="true"><span class="dashicons dashicons-list-view"></span></span>
                        <span class="qrg-akun-act__body">
                            <strong>Daftar Pembelanjaan</strong>
                            <span>Lihat semua transaksi pengeluaran</span>
                        </span>
                        <span class="qrg-akun-act__arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                    </a>
                </div>
            </div>
        </div>

        <div class="qrg-akun-jurnal">
            <div class="qrg-akun-jurnal__head">
                <div>
                    <h2>Tabel Akuntansi Sekolah (Pembukuan)</h2>
                    <p class="description" style="margin:4px 0 0;">Buku kas / jurnal realisasi — Debit (masuk), Kredit (keluar), saldo kas berjalan.</p>
                </div>
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                    <input type="hidden" name="page" value="<?php echo esc_attr( QRG_AKUNTANSI_SLUG ); ?>">
                    <label for="jurnal_tahun"><strong>Tahun</strong></label>
                    <select name="jurnal_tahun" id="jurnal_tahun" onchange="this.form.submit()">
                        <option value="all" <?php selected( $jurnal_filter, null ); ?>>Semua</option>
                        <?php
                        $tahun_now = (int) current_time( 'Y' );
                        for ( $y = $tahun_now; $y >= $tahun_now - 5; $y-- ) :
                            ?>
                            <option value="<?php echo (int) $y; ?>" <?php selected( (string) $jurnal_tahun, (string) $y ); ?>><?php echo (int) $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>

            <table class="widefat striped qrg-jurnal-table">
                <thead>
                    <tr>
                        <th style="width:100px;">Tanggal</th>
                        <th style="width:90px;">No. Bukti</th>
                        <th>Keterangan / Akun</th>
                        <th style="width:70px;">Ref</th>
                        <th style="width:130px;text-align:right;">Debit (Masuk)</th>
                        <th style="width:130px;text-align:right;">Kredit (Keluar)</th>
                        <th style="width:140px;text-align:right;">Saldo Kas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $jurnal['entries'] ) ) : ?>
                        <tr>
                            <td colspan="7" style="color:#64748b;">Belum ada transaksi realisasi untuk periode ini. Tagihan harus berstatus <strong>Lunas</strong>, gaji <strong>Dibayar</strong>, atau pembelanjaan <strong>Lunas</strong>.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $jurnal['entries'] as $row ) :
                            $tgl_label = date_i18n( 'd M Y', strtotime( $row['tanggal'] ) );
                            ?>
                        <tr class="qrg-jurnal-row qrg-jurnal-row--<?php echo esc_attr( $row['jenis'] ); ?>">
                            <td><?php echo esc_html( $tgl_label ); ?></td>
                            <td>
                                <?php if ( ! empty( $row['link'] ) ) : ?>
                                    <a href="<?php echo esc_url( $row['link'] ); ?>"><?php echo esc_html( $row['no_bukti'] ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $row['no_bukti'] ); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="qrg-jurnal-akun">
                                    <div class="qrg-jurnal-akun__debit"><?php echo esc_html( $row['akun_debit'] ); ?></div>
                                    <div class="qrg-jurnal-akun__kredit"><?php echo esc_html( $row['akun_kredit'] ); ?></div>
                                    <div class="qrg-jurnal-akun__ket"><?php echo esc_html( $row['keterangan'] ); ?></div>
                                </div>
                            </td>
                            <td><?php echo esc_html( $row['ref'] ); ?></td>
                            <td style="text-align:right;"><?php echo $row['debit'] > 0 ? esc_html( $fmt( $row['debit'] ) ) : '—'; ?></td>
                            <td style="text-align:right;"><?php echo $row['kredit'] > 0 ? esc_html( $fmt( $row['kredit'] ) ) : '—'; ?></td>
                            <td style="text-align:right;"><strong><?php echo esc_html( $fmt( $row['saldo'] ) ); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if ( ! empty( $jurnal['entries'] ) ) : ?>
                <tfoot>
                    <tr>
                        <th colspan="4" style="text-align:right;">Total</th>
                        <th style="text-align:right;"><?php echo esc_html( $fmt( $jurnal['total_debit'] ) ); ?></th>
                        <th style="text-align:right;"><?php echo esc_html( $fmt( $jurnal['total_kredit'] ) ); ?></th>
                        <th style="text-align:right;"><?php echo esc_html( $fmt( $jurnal['saldo_akhir'] ) ); ?></th>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
            <p class="description" style="margin-top:10px;">
                Ref akun: <code>4-100</code> Pendapatan Siswa · <code>5-100</code> Beban Gaji Guru · <code>5-200</code> Beban Pembelanjaan.
                Debit = uang masuk (tagihan lunas); Kredit = uang keluar (gaji dibayar / pembelanjaan lunas).
            </p>
        </div>
    </div>
    <style>
        .qrg-akuntansi-dash { max-width: 1100px; }
        .qrg-dss {
            background: #fff;
            border: 1px solid #d0d7de;
            border-radius: 14px;
            padding: 18px 20px;
            margin: 14px 0 8px;
            border-left: 5px solid #64748b;
        }
        .qrg-dss--baik { border-left-color: #16a34a; }
        .qrg-dss--waspada { border-left-color: #d97706; }
        .qrg-dss--kritis { border-left-color: #dc2626; }
        .qrg-dss__head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .qrg-dss__head h2 { margin: 0 0 4px; font-size: 16px; }
        .qrg-dss__head p { margin: 0; color: #64748b; font-size: 13px; max-width: 520px; }
        .qrg-dss__score {
            text-align: center;
            min-width: 110px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 14px;
        }
        .qrg-dss--baik .qrg-dss__score { background: #f0fdf4; border-color: #bbf7d0; }
        .qrg-dss--waspada .qrg-dss__score { background: #fffbeb; border-color: #fde68a; }
        .qrg-dss--kritis .qrg-dss__score { background: #fef2f2; border-color: #fecaca; }
        .qrg-dss__score-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
        .qrg-dss__score-value { display: block; font-size: 32px; line-height: 1.1; margin: 2px 0; }
        .qrg-dss__score-level { display: block; font-size: 12px; font-weight: 700; }
        .qrg-dss--baik .qrg-dss__score-level { color: #15803d; }
        .qrg-dss--waspada .qrg-dss__score-level { color: #b45309; }
        .qrg-dss--kritis .qrg-dss__score-level { color: #b91c1c; }
        .qrg-dss__indicators {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }
        .qrg-dss-ind {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
        }
        .qrg-dss-ind__label { display: block; font-size: 12px; color: #64748b; margin-bottom: 4px; }
        .qrg-dss-ind__value { display: block; font-size: 18px; color: #0f172a; }
        .qrg-dss-ind__hint { display: block; font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .qrg-dss-ind__bar {
            margin-top: 8px;
            height: 6px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
        }
        .qrg-dss-ind__bar span { display: block; height: 100%; background: #64748b; border-radius: 999px; }
        .qrg-dss-ind--good .qrg-dss-ind__bar span { background: #16a34a; }
        .qrg-dss-ind--warn .qrg-dss-ind__bar span { background: #d97706; }
        .qrg-dss-ind--bad .qrg-dss-ind__bar span { background: #dc2626; }
        .qrg-dss-ind--info .qrg-dss-ind__bar span { background: #0284c7; }
        .qrg-dss__section { margin-top: 14px; }
        .qrg-dss__section h3 { margin: 0 0 8px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #475569; }
        .qrg-dss-alerts { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
        .qrg-dss-alert {
            display: grid;
            gap: 2px;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .qrg-dss-alert strong { font-size: 13px; }
        .qrg-dss-alert span { font-size: 13px; color: #475569; }
        .qrg-dss-alert--critical { background: #fef2f2; border-color: #fecaca; }
        .qrg-dss-alert--critical strong { color: #b91c1c; }
        .qrg-dss-alert--warning { background: #fffbeb; border-color: #fde68a; }
        .qrg-dss-alert--warning strong { color: #b45309; }
        .qrg-dss-alert--info { background: #eff6ff; border-color: #bfdbfe; }
        .qrg-dss-alert--info strong { color: #1d4ed8; }
        .qrg-dss-recs { margin: 0; padding: 0; list-style: none; display: grid; gap: 8px; }
        .qrg-dss-rec {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #fff;
        }
        .qrg-dss-rec__num {
            flex: 0 0 28px;
            width: 28px; height: 28px;
            border-radius: 50%;
            background: #0f172a; color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700;
        }
        .qrg-dss-rec__body { flex: 1; min-width: 0; display: grid; gap: 2px; }
        .qrg-dss-rec__body strong { font-size: 13px; }
        .qrg-dss-rec__body span { font-size: 12px; color: #64748b; }
        .qrg-akun-jurnal {
            background: #fff;
            border: 1px solid #d0d7de;
            border-radius: 12px;
            padding: 16px 18px;
            margin-top: 16px;
        }
        .qrg-akun-jurnal__head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .qrg-akun-jurnal__head h2 { margin: 0; font-size: 15px; }
        .qrg-akun-jurnal__head form { display: flex; align-items: center; gap: 8px; }
        .qrg-jurnal-table { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        .qrg-jurnal-table th, .qrg-jurnal-table td { padding: 8px 10px; vertical-align: top; }
        .qrg-jurnal-table tfoot th { border-top: 2px solid #cbd5e1; background: #f8fafc; }
        .qrg-jurnal-akun__debit { font-weight: 600; color: #0f172a; }
        .qrg-jurnal-akun__kredit { padding-left: 18px; color: #334155; }
        .qrg-jurnal-akun__ket { margin-top: 3px; font-size: 12px; color: #64748b; }
        .qrg-jurnal-row--pemasukan { background: #f0fdf4; }
        .qrg-jurnal-row--pengeluaran { background: #fff7ed; }
        .qrg-akun-rkas-banner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 14px 18px;
            margin: 14px 0 8px;
        }
        .qrg-akun-rkas-banner__meta {
            margin-top: 4px;
            font-size: 13px;
            color: #475569;
        }
        .qrg-akun-rkas-banner__actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .qrg-akun-saldo {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 18px;
            align-items: stretch;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 14px;
            padding: 20px 22px;
            margin: 16px 0 12px;
        }
        .qrg-akun-saldo.is-negatif { background: #7f1d1d; }
        .qrg-akun-saldo.is-positif { background: linear-gradient(135deg, #0f172a 0%, #14532d 100%); }
        .qrg-akun-saldo__label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        .qrg-akun-saldo.is-positif .qrg-akun-saldo__label,
        .qrg-akun-saldo.is-negatif .qrg-akun-saldo__label { color: rgba(255,255,255,.7); }
        .qrg-akun-saldo__value {
            display: block;
            font-size: 34px;
            line-height: 1.15;
            color: #fff;
        }
        .qrg-akun-saldo__hint {
            display: block;
            margin-top: 8px;
            font-size: 13px;
            color: rgba(255,255,255,.75);
        }
        .qrg-akun-saldo__cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            align-content: center;
        }
        .qrg-akun-saldo__cols .lab {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: rgba(255,255,255,.65);
            margin-bottom: 4px;
        }
        .qrg-akun-saldo__cols .val {
            display: block;
            font-size: 18px;
            color: #fff;
        }
        .qrg-akun-saldo__cols .sub {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: rgba(255,255,255,.7);
            line-height: 1.35;
        }
        .qrg-akun-pending {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 10px 14px;
            color: #92400e;
            font-size: 13px;
            margin-bottom: 14px;
        }
        .qrg-akun-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
            margin: 0 0 16px;
        }
        .qrg-akun-card {
            display: block;
            text-decoration: none;
            color: inherit;
            background: #fff;
            border: 1px solid #d0d7de;
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: 0 1px 2px rgba(15,23,42,.04);
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .qrg-akun-card:hover {
            border-color: #94a3b8;
            box-shadow: 0 8px 24px rgba(15,23,42,.08);
            color: inherit;
        }
        .qrg-akun-card__eyebrow {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #64748b;
            margin-bottom: 6px;
        }
        .qrg-akun-card h2 { margin: 0 0 8px; font-size: 15px; }
        .qrg-akun-card strong { font-size: 22px; color: #0f172a; }
        .qrg-akun-card__meta {
            margin-top: 10px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            font-size: 12px;
            color: #64748b;
        }
        .qrg-akun-card--in { border-left: 4px solid #16a34a; }
        .qrg-akun-card--out { border-left: 4px solid #2563eb; }
        .qrg-akun-card--buy { border-left: 4px solid #c2410c; }
        .qrg-akun-actions {
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
            border: 1px solid #d0d7de;
            border-radius: 16px;
            padding: 18px 20px 20px;
            margin-top: 4px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, .04);
        }
        .qrg-akun-actions__head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid #e2e8f0;
        }
        .qrg-akun-actions__head h2 {
            margin: 0 0 4px;
            font-size: 16px;
            color: #0f172a;
        }
        .qrg-akun-actions__head p {
            margin: 0;
            font-size: 13px;
            color: #64748b;
        }
        .qrg-akun-actions__group + .qrg-akun-actions__group {
            margin-top: 16px;
        }
        .qrg-akun-actions__label {
            display: inline-block;
            margin-bottom: 8px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #475569;
            background: #e2e8f0;
        }
        .qrg-akun-actions__grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 10px;
        }
        .qrg-akun-act {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 14px 14px 12px;
            border-radius: 12px;
            text-decoration: none;
            color: inherit;
            border: 1px solid #e2e8f0;
            background: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }
        .qrg-akun-act:hover,
        .qrg-akun-act:focus {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, .08);
            color: inherit;
            outline: none;
        }
        .qrg-akun-act__icon {
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .qrg-akun-act__icon .dashicons {
            width: 22px;
            height: 22px;
            font-size: 22px;
        }
        .qrg-akun-act__body {
            flex: 1;
            min-width: 0;
            display: grid;
            gap: 2px;
        }
        .qrg-akun-act__body strong {
            display: block;
            font-size: 14px;
            color: #0f172a;
            line-height: 1.3;
        }
        .qrg-akun-act__body span {
            display: block;
            font-size: 12px;
            color: #64748b;
            line-height: 1.35;
        }
        .qrg-akun-act__arrow {
            flex: 0 0 auto;
            width: 18px;
            height: 18px;
            font-size: 18px;
            color: #94a3b8;
            transition: transform .15s ease, color .15s ease;
        }
        .qrg-akun-act:hover .qrg-akun-act__arrow,
        .qrg-akun-act:focus .qrg-akun-act__arrow {
            transform: translateX(3px);
            color: #475569;
        }
        .qrg-akun-act--plan { border-left: 4px solid #6366f1; }
        .qrg-akun-act--plan .qrg-akun-act__icon { background: #eef2ff; color: #4f46e5; }
        .qrg-akun-act--plan:hover { border-color: #c7d2fe; }
        .qrg-akun-act--in { border-left: 4px solid #16a34a; }
        .qrg-akun-act--in .qrg-akun-act__icon { background: #f0fdf4; color: #15803d; }
        .qrg-akun-act--in:hover { border-color: #bbf7d0; }
        .qrg-akun-act--out { border-left: 4px solid #2563eb; }
        .qrg-akun-act--out .qrg-akun-act__icon { background: #eff6ff; color: #1d4ed8; }
        .qrg-akun-act--out:hover { border-color: #bfdbfe; }
        .qrg-akun-act--buy { border-left: 4px solid #ea580c; }
        .qrg-akun-act--buy .qrg-akun-act__icon { background: #fff7ed; color: #c2410c; }
        .qrg-akun-act--buy:hover { border-color: #fed7aa; }
        .qrg-akun-act--soft {
            background: #fafafa;
            border-style: dashed;
        }
        .qrg-akun-act--soft .qrg-akun-act__body strong { font-weight: 600; }
        @media (max-width: 782px) {
            .qrg-akun-saldo,
            .qrg-akun-saldo__cols { grid-template-columns: 1fr; }
            .qrg-dss__indicators { grid-template-columns: 1fr; }
            .qrg-dss-rec { flex-wrap: wrap; }
            .qrg-akun-actions__grid { grid-template-columns: 1fr; }
        }
    </style>
    <?php
}

// -------------------------------------------------------------------------
// 3. CPT Pembelian / Pengeluaran Lain-lain
// -------------------------------------------------------------------------
add_action( 'init', 'qrg_register_pembelian_cpt' );
function qrg_register_pembelian_cpt() {
    register_post_type( 'master_pembelian', [
        'labels' => [
            'name'               => 'Pembelian',
            'singular_name'      => 'Pembelian',
            'menu_name'          => 'Pembelian',
            'add_new'            => 'Tambah Pembelian',
            'add_new_item'       => 'Tambah Pembelian / Pengeluaran',
            'edit_item'          => 'Edit Pembelian',
            'all_items'          => 'Pembelian',
            'search_items'       => 'Cari Pembelian',
            'not_found'          => 'Belum ada data pembelian',
            'not_found_in_trash' => 'Tidak ada pembelian di tong sampah',
        ],
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'show_in_menu'        => false, // Akses via Sekolah → Akuntansi
        'supports'            => [ 'title' ],
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
    ] );
}

function qrg_pembelian_kategori_options() {
    return [
        'Pembelian Barang' => 'Pembelian Barang',
        'Operasional'      => 'Operasional',
        'Pemeliharaan'     => 'Pemeliharaan',
        'ATK'              => 'ATK / Administrasi',
        'Utilitas'         => 'Utilitas (listrik, air, dll)',
        'Lainnya'          => 'Lainnya',
    ];
}

function qrg_pembelian_status_options() {
    return [
        'Lunas'       => 'Lunas',
        'Belum Lunas' => 'Belum Lunas',
        'Draft'       => 'Draft',
    ];
}

add_filter( 'enter_title_here', 'qrg_pembelian_title_placeholder', 10, 2 );
function qrg_pembelian_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_pembelian' ) {
        return 'Contoh: Pembelian LCD Projector / Bayar listrik Maret';
    }
    return $title;
}

add_filter( 'post_row_actions', 'qrg_pembelian_row_actions', 10, 2 );
function qrg_pembelian_row_actions( $actions, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_pembelian' ) {
        unset( $actions['view'] );
        unset( $actions['inline hide-if-no-js'] );
    }
    return $actions;
}

add_action( 'admin_footer-edit.php', 'qrg_pembelian_header_buttons' );
add_action( 'admin_footer-post.php', 'qrg_pembelian_header_buttons' );
add_action( 'admin_footer-post-new.php', 'qrg_pembelian_header_buttons' );
function qrg_pembelian_header_buttons() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_pembelian' ) {
        return;
    }
    $dash_url = admin_url( 'admin.php?page=' . QRG_AKUNTANSI_SLUG );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) return;
        var $existing = $wrap.siblings('.page-title-action').first();
        var $back = $('<a href="<?php echo esc_url( $dash_url ); ?>" class="page-title-action">← Keuangan</a>');
        if ($existing.length) {
            $back.insertBefore($existing);
        } else {
            $back.insertAfter($wrap);
        }
    });
    </script>
    <?php
}

add_action( 'add_meta_boxes', 'qrg_pembelian_meta_boxes' );
function qrg_pembelian_meta_boxes() {
    add_meta_box(
        'pembelian_detail',
        'Detail Pembelian / Pengeluaran',
        'qrg_pembelian_fields_callback',
        'master_pembelian',
        'normal',
        'high'
    );
}

function qrg_pembelian_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_pembelian_meta', 'qrg_pembelian_nonce' );

    $tanggal   = get_post_meta( $post->ID, '_pembelian_tanggal', true );
    $kategori  = get_post_meta( $post->ID, '_pembelian_kategori', true );
    $jumlah    = get_post_meta( $post->ID, '_pembelian_jumlah', true );
    $harga     = get_post_meta( $post->ID, '_pembelian_harga', true );
    $total     = get_post_meta( $post->ID, '_pembelian_total', true );
    $vendor    = get_post_meta( $post->ID, '_pembelian_vendor', true );
    $status    = get_post_meta( $post->ID, '_pembelian_status', true );
    $ket       = get_post_meta( $post->ID, '_pembelian_keterangan', true );

    if ( ! $tanggal ) {
        $tanggal = current_time( 'Y-m-d' );
    }
    if ( ! $status ) {
        $status = 'Lunas';
    }
    if ( $jumlah === '' ) {
        $jumlah = 1;
    }
    ?>
    <p style="color:#64748b;margin-top:0;">Catat pembelian barang, biaya operasional, atau pengeluaran lain. Total dihitung otomatis dari jumlah × harga satuan (bisa juga diisi manual).</p>
    <table class="form-table">
        <tr>
            <th><label for="pembelian_tanggal">Tanggal</label></th>
            <td><input type="date" id="pembelian_tanggal" name="pembelian_tanggal" value="<?php echo esc_attr( $tanggal ); ?>" required></td>
        </tr>
        <tr>
            <th><label for="pembelian_kategori">Kategori</label></th>
            <td>
                <select id="pembelian_kategori" name="pembelian_kategori" style="min-width:280px;">
                    <?php foreach ( qrg_pembelian_kategori_options() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $kategori, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="pembelian_jumlah">Jumlah</label></th>
            <td><input type="number" id="pembelian_jumlah" name="pembelian_jumlah" value="<?php echo esc_attr( $jumlah ); ?>" min="1" class="small-text"></td>
        </tr>
        <tr>
            <th><label for="pembelian_harga">Harga Satuan (Rp)</label></th>
            <td><input type="number" id="pembelian_harga" name="pembelian_harga" value="<?php echo esc_attr( $harga ); ?>" min="0" step="1000" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="pembelian_total">Total (Rp)</label></th>
            <td>
                <input type="number" id="pembelian_total" name="pembelian_total" value="<?php echo esc_attr( $total ); ?>" min="0" step="1000" class="regular-text">
                <p class="description">Kosongkan agar dihitung otomatis: Jumlah × Harga Satuan.</p>
            </td>
        </tr>
        <tr>
            <th><label for="pembelian_vendor">Vendor / Toko</label></th>
            <td><input type="text" id="pembelian_vendor" name="pembelian_vendor" value="<?php echo esc_attr( $vendor ); ?>" class="regular-text" placeholder="Opsional"></td>
        </tr>
        <tr>
            <th><label for="pembelian_status">Status</label></th>
            <td>
                <select id="pembelian_status" name="pembelian_status">
                    <?php foreach ( qrg_pembelian_status_options() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="pembelian_keterangan">Keterangan</label></th>
            <td><textarea id="pembelian_keterangan" name="pembelian_keterangan" rows="3" class="large-text"><?php echo esc_textarea( $ket ); ?></textarea></td>
        </tr>
    </table>
    <script>
    (function(){
        var j=document.getElementById('pembelian_jumlah');
        var h=document.getElementById('pembelian_harga');
        var t=document.getElementById('pembelian_total');
        function sync(){
            var jj=parseInt(j.value,10)||0;
            var hh=parseInt(h.value,10)||0;
            if(!t.dataset.manual){ t.value = jj*hh; }
        }
        t.addEventListener('input', function(){ t.dataset.manual='1'; });
        j.addEventListener('input', sync);
        h.addEventListener('input', sync);
    })();
    </script>
    <?php
}

add_action( 'save_post_master_pembelian', 'qrg_save_pembelian_details' );
function qrg_save_pembelian_details( $post_id ) {
    if ( ! isset( $_POST['qrg_pembelian_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_pembelian_nonce'], 'qrg_save_pembelian_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) {
        return;
    }

    $tanggal  = isset( $_POST['pembelian_tanggal'] ) ? sanitize_text_field( wp_unslash( $_POST['pembelian_tanggal'] ) ) : '';
    $kategori = isset( $_POST['pembelian_kategori'] ) ? sanitize_text_field( wp_unslash( $_POST['pembelian_kategori'] ) ) : '';
    $jumlah   = isset( $_POST['pembelian_jumlah'] ) ? max( 1, absint( $_POST['pembelian_jumlah'] ) ) : 1;
    $harga    = isset( $_POST['pembelian_harga'] ) ? absint( $_POST['pembelian_harga'] ) : 0;
    $total    = isset( $_POST['pembelian_total'] ) ? absint( $_POST['pembelian_total'] ) : 0;
    $vendor   = isset( $_POST['pembelian_vendor'] ) ? sanitize_text_field( wp_unslash( $_POST['pembelian_vendor'] ) ) : '';
    $status   = isset( $_POST['pembelian_status'] ) ? sanitize_text_field( wp_unslash( $_POST['pembelian_status'] ) ) : 'Lunas';
    $ket      = isset( $_POST['pembelian_keterangan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pembelian_keterangan'] ) ) : '';

    $kat_ok = array_keys( qrg_pembelian_kategori_options() );
    if ( ! in_array( $kategori, $kat_ok, true ) ) {
        $kategori = 'Lainnya';
    }
    $st_ok = array_keys( qrg_pembelian_status_options() );
    if ( ! in_array( $status, $st_ok, true ) ) {
        $status = 'Lunas';
    }
    if ( ! $total ) {
        $total = $jumlah * $harga;
    }

    update_post_meta( $post_id, '_pembelian_tanggal', $tanggal );
    update_post_meta( $post_id, '_pembelian_kategori', $kategori );
    update_post_meta( $post_id, '_pembelian_jumlah', $jumlah );
    update_post_meta( $post_id, '_pembelian_harga', $harga );
    update_post_meta( $post_id, '_pembelian_total', $total );
    update_post_meta( $post_id, '_pembelian_vendor', $vendor );
    update_post_meta( $post_id, '_pembelian_status', $status );
    update_post_meta( $post_id, '_pembelian_keterangan', $ket );
}

add_filter( 'manage_master_pembelian_posts_columns', 'qrg_set_pembelian_columns' );
function qrg_set_pembelian_columns( $columns ) {
    return [
        'cb'        => $columns['cb'],
        'title'     => 'Keterangan',
        'tanggal'   => 'Tanggal',
        'kategori'  => 'Kategori',
        'total'     => 'Total',
        'status'    => 'Status',
        'vendor'    => 'Vendor',
    ];
}

add_action( 'manage_master_pembelian_posts_custom_column', 'qrg_fill_pembelian_columns', 10, 2 );
function qrg_fill_pembelian_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'tanggal':
            $t = get_post_meta( $post_id, '_pembelian_tanggal', true );
            echo $t ? esc_html( date_i18n( 'd M Y', strtotime( $t ) ) ) : '—';
            break;
        case 'kategori':
            echo esc_html( get_post_meta( $post_id, '_pembelian_kategori', true ) ?: '—' );
            break;
        case 'total':
            $total = (int) get_post_meta( $post_id, '_pembelian_total', true );
            echo '<strong>Rp ' . esc_html( number_format( $total, 0, ',', '.' ) ) . '</strong>';
            break;
        case 'status':
            $status = get_post_meta( $post_id, '_pembelian_status', true );
            if ( $status === 'Lunas' ) {
                echo '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:600;font-size:12px;">Lunas</span>';
            } elseif ( $status === 'Belum Lunas' ) {
                echo '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#ffedd5;color:#9a3412;font-weight:600;font-size:12px;">Belum Lunas</span>';
            } else {
                echo '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#e2e8f0;color:#475569;font-weight:600;font-size:12px;">' . esc_html( $status ?: 'Draft' ) . '</span>';
            }
            break;
        case 'vendor':
            echo esc_html( get_post_meta( $post_id, '_pembelian_vendor', true ) ?: '—' );
            break;
    }
}

// Banner total di daftar pembelian
add_action( 'admin_notices', 'qrg_pembelian_list_notice' );
function qrg_pembelian_list_notice() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_pembelian' ) {
        return;
    }
    $all = qrg_akuntansi_sum_meta( 'master_pembelian', '_pembelian_total' );
    ?>
    <div class="notice notice-info" style="padding:10px 14px;border-left-color:#c2410c;">
        <p style="margin:0;">
            <strong>Total Pembelian / Pengeluaran:</strong>
            <span style="font-size:16px;font-weight:700;color:#c2410c;margin-left:4px;">
                Rp <?php echo esc_html( number_format( $all['sum'], 0, ',', '.' ) ); ?>
            </span>
            <span style="color:#64748b;margin-left:8px;">dari <?php echo (int) $all['total_posts']; ?> transaksi</span>
        </p>
    </div>
    <?php
}

// -------------------------------------------------------------------------
// 4. CPT RKAS — Rencana Kegiatan Anggaran Sekolah
// -------------------------------------------------------------------------
add_action( 'init', 'qrg_register_rkas_cpt' );
function qrg_register_rkas_cpt() {
    register_post_type( 'master_rkas', [
        'labels' => [
            'name'               => 'RKAS',
            'singular_name'      => 'Kegiatan RKAS',
            'menu_name'          => 'RKAS',
            'add_new'            => 'Tambah Kegiatan',
            'add_new_item'       => 'Tambah Kegiatan RKAS',
            'edit_item'          => 'Edit Kegiatan RKAS',
            'all_items'          => 'RKAS',
            'search_items'       => 'Cari Kegiatan RKAS',
            'not_found'          => 'Belum ada kegiatan RKAS',
            'not_found_in_trash' => 'Tidak ada kegiatan di tong sampah',
        ],
        'public'              => false,
        'publicly_queryable'  => false,
        'exclude_from_search' => true,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'supports'            => [ 'title' ],
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
    ] );
}

function qrg_rkas_program_options() {
    return [
        'Kurikulum'      => 'Kurikulum',
        'Kesiswaan'      => 'Kesiswaan',
        'Sarpras'        => 'Sarana & Prasarana',
        'SDM'            => 'SDM / Guru & Tendik',
        'Administrasi'   => 'Administrasi',
        'Humas'          => 'Humas / Kerja sama',
        'Lainnya'        => 'Lainnya',
    ];
}

function qrg_rkas_sumber_options() {
    return [
        'BOS'        => 'BOS',
        'BOP'        => 'BOP',
        'Komite'     => 'Komite Sekolah',
        'SPP'        => 'SPP / Iuran Siswa',
        'Hibah'      => 'Hibah',
        'Lainnya'    => 'Lainnya',
    ];
}

function qrg_rkas_status_options() {
    return [
        'Draft'     => 'Draft',
        'Disetujui' => 'Disetujui',
        'Berjalan'  => 'Berjalan',
        'Selesai'   => 'Selesai',
    ];
}

/**
 * Ringkasan anggaran & realisasi RKAS.
 *
 * @return array{n:int,anggaran:int,realisasi:int}
 */
function qrg_rkas_get_summary_stats() {
    $ids = get_posts( [
        'post_type'      => 'master_rkas',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );
    $anggaran  = 0;
    $realisasi = 0;
    foreach ( $ids as $id ) {
        $anggaran  += (int) get_post_meta( $id, '_rkas_anggaran', true );
        $realisasi += (int) get_post_meta( $id, '_rkas_realisasi', true );
    }
    return [
        'n'         => count( $ids ),
        'anggaran'  => $anggaran,
        'realisasi' => $realisasi,
    ];
}

add_filter( 'enter_title_here', 'qrg_rkas_title_placeholder', 10, 2 );
function qrg_rkas_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_rkas' ) {
        return 'Contoh: Pengadaan buku pelajaran / Renovasi ruang kelas';
    }
    return $title;
}

add_filter( 'post_row_actions', 'qrg_rkas_row_actions', 10, 2 );
function qrg_rkas_row_actions( $actions, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_rkas' ) {
        unset( $actions['view'] );
        unset( $actions['inline hide-if-no-js'] );
    }
    return $actions;
}

add_action( 'admin_footer-edit.php', 'qrg_rkas_header_buttons' );
add_action( 'admin_footer-post.php', 'qrg_rkas_header_buttons' );
add_action( 'admin_footer-post-new.php', 'qrg_rkas_header_buttons' );
function qrg_rkas_header_buttons() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_rkas' ) {
        return;
    }
    $dash_url = admin_url( 'admin.php?page=' . QRG_AKUNTANSI_SLUG );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) return;
        var $existing = $wrap.siblings('.page-title-action').first();
        var $back = $('<a href="<?php echo esc_url( $dash_url ); ?>" class="page-title-action">← Keuangan</a>');
        if ($existing.length) {
            $back.insertBefore($existing);
        } else {
            $back.insertAfter($wrap);
        }
    });
    </script>
    <?php
}

add_action( 'add_meta_boxes', 'qrg_rkas_meta_boxes' );
function qrg_rkas_meta_boxes() {
    add_meta_box(
        'rkas_detail',
        'Detail Rencana Kegiatan Anggaran Sekolah',
        'qrg_rkas_fields_callback',
        'master_rkas',
        'normal',
        'high'
    );
}

function qrg_rkas_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_rkas_meta', 'qrg_rkas_nonce' );

    $tahun     = get_post_meta( $post->ID, '_rkas_tahun', true );
    $program   = get_post_meta( $post->ID, '_rkas_program', true );
    $sumber    = get_post_meta( $post->ID, '_rkas_sumber', true );
    $anggaran  = get_post_meta( $post->ID, '_rkas_anggaran', true );
    $realisasi = get_post_meta( $post->ID, '_rkas_realisasi', true );
    $triwulan  = get_post_meta( $post->ID, '_rkas_triwulan', true );
    $status    = get_post_meta( $post->ID, '_rkas_status', true );
    $ket       = get_post_meta( $post->ID, '_rkas_keterangan', true );

    if ( ! $tahun ) {
        $tahun = current_time( 'Y' );
    }
    if ( ! $status ) {
        $status = 'Draft';
    }
    ?>
    <p style="color:#64748b;margin-top:0;">Isi rencana kegiatan dan anggaran. Judul di atas = nama kegiatan.</p>
    <table class="form-table">
        <tr>
            <th><label for="rkas_tahun">Tahun Anggaran</label></th>
            <td><input type="number" id="rkas_tahun" name="rkas_tahun" value="<?php echo esc_attr( $tahun ); ?>" min="2000" max="2100" class="small-text"></td>
        </tr>
        <tr>
            <th><label for="rkas_triwulan">Triwulan</label></th>
            <td>
                <select id="rkas_triwulan" name="rkas_triwulan">
                    <option value="">— Semua / Tahunan —</option>
                    <?php foreach ( [ '1' => 'Triwulan I', '2' => 'Triwulan II', '3' => 'Triwulan III', '4' => 'Triwulan IV' ] as $k => $lab ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $triwulan, $k ); ?>><?php echo esc_html( $lab ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="rkas_program">Program / Bidang</label></th>
            <td>
                <select id="rkas_program" name="rkas_program" style="min-width:280px;">
                    <?php foreach ( qrg_rkas_program_options() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $program, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="rkas_sumber">Sumber Dana</label></th>
            <td>
                <select id="rkas_sumber" name="rkas_sumber" style="min-width:280px;">
                    <?php foreach ( qrg_rkas_sumber_options() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $sumber, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="rkas_anggaran">Anggaran (Rp)</label></th>
            <td><input type="number" id="rkas_anggaran" name="rkas_anggaran" value="<?php echo esc_attr( $anggaran ); ?>" min="0" step="1000" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="rkas_realisasi">Realisasi (Rp)</label></th>
            <td>
                <input type="number" id="rkas_realisasi" name="rkas_realisasi" value="<?php echo esc_attr( $realisasi ); ?>" min="0" step="1000" class="regular-text">
                <p class="description">Isi saat kegiatan sudah / sedang dibiayai.</p>
            </td>
        </tr>
        <tr>
            <th><label for="rkas_status">Status</label></th>
            <td>
                <select id="rkas_status" name="rkas_status">
                    <?php foreach ( qrg_rkas_status_options() as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="rkas_keterangan">Keterangan</label></th>
            <td><textarea id="rkas_keterangan" name="rkas_keterangan" rows="3" class="large-text"><?php echo esc_textarea( $ket ); ?></textarea></td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_master_rkas', 'qrg_save_rkas_details' );
function qrg_save_rkas_details( $post_id ) {
    if ( ! isset( $_POST['qrg_rkas_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_rkas_nonce'], 'qrg_save_rkas_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) {
        return;
    }

    $tahun     = isset( $_POST['rkas_tahun'] ) ? absint( $_POST['rkas_tahun'] ) : (int) current_time( 'Y' );
    $program   = isset( $_POST['rkas_program'] ) ? sanitize_text_field( wp_unslash( $_POST['rkas_program'] ) ) : '';
    $sumber    = isset( $_POST['rkas_sumber'] ) ? sanitize_text_field( wp_unslash( $_POST['rkas_sumber'] ) ) : '';
    $anggaran  = isset( $_POST['rkas_anggaran'] ) ? absint( $_POST['rkas_anggaran'] ) : 0;
    $realisasi = isset( $_POST['rkas_realisasi'] ) ? absint( $_POST['rkas_realisasi'] ) : 0;
    $triwulan  = isset( $_POST['rkas_triwulan'] ) ? sanitize_text_field( wp_unslash( $_POST['rkas_triwulan'] ) ) : '';
    $status    = isset( $_POST['rkas_status'] ) ? sanitize_text_field( wp_unslash( $_POST['rkas_status'] ) ) : 'Draft';
    $ket       = isset( $_POST['rkas_keterangan'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rkas_keterangan'] ) ) : '';

    if ( ! in_array( $program, array_keys( qrg_rkas_program_options() ), true ) ) {
        $program = 'Lainnya';
    }
    if ( ! in_array( $sumber, array_keys( qrg_rkas_sumber_options() ), true ) ) {
        $sumber = 'Lainnya';
    }
    if ( ! in_array( $status, array_keys( qrg_rkas_status_options() ), true ) ) {
        $status = 'Draft';
    }
    if ( $triwulan !== '' && ! in_array( $triwulan, [ '1', '2', '3', '4' ], true ) ) {
        $triwulan = '';
    }

    update_post_meta( $post_id, '_rkas_tahun', $tahun );
    update_post_meta( $post_id, '_rkas_program', $program );
    update_post_meta( $post_id, '_rkas_sumber', $sumber );
    update_post_meta( $post_id, '_rkas_anggaran', $anggaran );
    update_post_meta( $post_id, '_rkas_realisasi', $realisasi );
    update_post_meta( $post_id, '_rkas_triwulan', $triwulan );
    update_post_meta( $post_id, '_rkas_status', $status );
    update_post_meta( $post_id, '_rkas_keterangan', $ket );
}

add_filter( 'manage_master_rkas_posts_columns', 'qrg_set_rkas_columns' );
function qrg_set_rkas_columns( $columns ) {
    return [
        'cb'         => $columns['cb'],
        'title'      => 'Kegiatan',
        'tahun'      => 'Tahun',
        'program'    => 'Program',
        'sumber'     => 'Sumber Dana',
        'anggaran'   => 'Anggaran',
        'realisasi'  => 'Realisasi',
        'status'     => 'Status',
    ];
}

add_action( 'manage_master_rkas_posts_custom_column', 'qrg_fill_rkas_columns', 10, 2 );
function qrg_fill_rkas_columns( $column, $post_id ) {
    switch ( $column ) {
        case 'tahun':
            $t = get_post_meta( $post_id, '_rkas_tahun', true );
            $tw = get_post_meta( $post_id, '_rkas_triwulan', true );
            echo esc_html( $t ?: '—' );
            if ( $tw ) {
                echo '<div style="font-size:11px;color:#64748b;">TW ' . esc_html( $tw ) . '</div>';
            }
            break;
        case 'program':
            echo esc_html( get_post_meta( $post_id, '_rkas_program', true ) ?: '—' );
            break;
        case 'sumber':
            echo esc_html( get_post_meta( $post_id, '_rkas_sumber', true ) ?: '—' );
            break;
        case 'anggaran':
            $n = (int) get_post_meta( $post_id, '_rkas_anggaran', true );
            echo '<strong>Rp ' . esc_html( number_format( $n, 0, ',', '.' ) ) . '</strong>';
            break;
        case 'realisasi':
            $n = (int) get_post_meta( $post_id, '_rkas_realisasi', true );
            $a = (int) get_post_meta( $post_id, '_rkas_anggaran', true );
            echo 'Rp ' . esc_html( number_format( $n, 0, ',', '.' ) );
            if ( $a > 0 ) {
                $pct = (int) round( ( $n / $a ) * 100 );
                echo '<div style="font-size:11px;color:#64748b;">' . $pct . '%</div>';
            }
            break;
        case 'status':
            $status = get_post_meta( $post_id, '_rkas_status', true ) ?: 'Draft';
            $colors = [
                'Draft'     => [ '#e2e8f0', '#475569' ],
                'Disetujui' => [ '#dbeafe', '#1d4ed8' ],
                'Berjalan'  => [ '#ffedd5', '#c2410c' ],
                'Selesai'   => [ '#dcfce7', '#166534' ],
            ];
            $c = isset( $colors[ $status ] ) ? $colors[ $status ] : [ '#e2e8f0', '#475569' ];
            echo '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:' . esc_attr( $c[0] ) . ';color:' . esc_attr( $c[1] ) . ';font-weight:600;font-size:12px;">' . esc_html( $status ) . '</span>';
            break;
    }
}

add_action( 'admin_notices', 'qrg_rkas_list_notice' );
function qrg_rkas_list_notice() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_rkas' ) {
        return;
    }
    $stats = qrg_rkas_get_summary_stats();
    $sisa  = max( 0, $stats['anggaran'] - $stats['realisasi'] );
    ?>
    <div class="notice notice-info" style="padding:10px 14px;border-left-color:#2563eb;">
        <p style="margin:0 0 4px;">
            <strong>RKAS — Rencana Kegiatan Anggaran Sekolah</strong>
        </p>
        <p style="margin:0;">
            Anggaran: <strong>Rp <?php echo esc_html( number_format( $stats['anggaran'], 0, ',', '.' ) ); ?></strong>
            · Realisasi: <strong>Rp <?php echo esc_html( number_format( $stats['realisasi'], 0, ',', '.' ) ); ?></strong>
            · Sisa: <strong>Rp <?php echo esc_html( number_format( $sisa, 0, ',', '.' ) ); ?></strong>
            <span style="color:#64748b;margin-left:8px;"><?php echo (int) $stats['n']; ?> kegiatan</span>
        </p>
    </div>
    <?php
}
