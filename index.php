<?php
/**
 * Plugin Name: Sekolah
 * Description: Sistem Informasi Sekolah
 * Version: 1.7.1
 * Author: Ryan Dewantoro
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Definisikan Path konstanta menggunakan file index.php saat ini
define( 'QRG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QRG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load semua file modular menu dari folder includes
require_once QRG_PLUGIN_DIR . 'includes/security.php';
require_once QRG_PLUGIN_DIR . 'includes/kelas.php';
require_once QRG_PLUGIN_DIR . 'includes/guru.php';
require_once QRG_PLUGIN_DIR . 'includes/jabatan.php';
require_once QRG_PLUGIN_DIR . 'includes/presensi-guru.php';
require_once QRG_PLUGIN_DIR . 'includes/solution-absensi.php';
require_once QRG_PLUGIN_DIR . 'includes/gaji-guru.php';
require_once QRG_PLUGIN_DIR . 'includes/siswa.php';
require_once QRG_PLUGIN_DIR . 'includes/mapel.php';
require_once QRG_PLUGIN_DIR . 'includes/jadwal.php';
require_once QRG_PLUGIN_DIR . 'includes/jurnal.php';
require_once QRG_PLUGIN_DIR . 'includes/biaya.php';
require_once QRG_PLUGIN_DIR . 'includes/ruangan.php';
require_once QRG_PLUGIN_DIR . 'includes/inventaris.php';
require_once QRG_PLUGIN_DIR . 'includes/tagihan.php';
require_once QRG_PLUGIN_DIR . 'includes/akuntansi.php';
require_once QRG_PLUGIN_DIR . 'includes/frontend.php';
require_once QRG_PLUGIN_DIR . 'includes/kts-digital.php';
require_once QRG_PLUGIN_DIR . 'includes/izin-siswa.php';
require_once QRG_PLUGIN_DIR . 'includes/saran-siswa.php';
require_once QRG_PLUGIN_DIR . 'includes/pengumuman.php';
require_once QRG_PLUGIN_DIR . 'includes/antar-jemput.php';
require_once QRG_PLUGIN_DIR . 'includes/kesehatan-konseling.php';
require_once QRG_PLUGIN_DIR . 'includes/portal-sekolah.php';
require_once QRG_PLUGIN_DIR . 'includes/pwa-sekolah.php';
require_once QRG_PLUGIN_DIR . 'includes/portal-guru.php';
require_once QRG_PLUGIN_DIR . 'includes/export-import.php';
require_once QRG_PLUGIN_DIR . 'includes/jalur.php';
require_once QRG_PLUGIN_DIR . 'includes/psb.php';
require_once QRG_PLUGIN_DIR . 'includes/bantuan.php';
require_once QRG_PLUGIN_DIR . 'includes/google-auth.php';
require_once QRG_PLUGIN_DIR . 'includes/payment-api.php';
require_once QRG_PLUGIN_DIR . 'includes/tampung-api.php';
require_once QRG_PLUGIN_DIR . 'includes/pembelajaran.php';
require_once QRG_PLUGIN_DIR . 'includes/perpustakaan.php';

// Setelah update keamanan CPT: flush rewrite sekali
add_action( 'admin_init', 'qrg_security_flush_rewrite_once' );
function qrg_security_flush_rewrite_once() {
    if ( get_option( 'qrg_security_rewrite_flushed_v1' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'qrg_security_rewrite_flushed_v1', 1 );
}

// Tautan cepat Dashboard & Setting di halaman Plugins
add_filter( 'plugin_action_links_' . QRG_PLUGIN_BASENAME, 'qrg_plugin_action_links' );
function qrg_plugin_action_links( $links ) {
    $extra_links = [
        '<a href="' . esc_url( admin_url( 'admin.php?page=master-data-plugin' ) ) . '">Dashboard</a>',
        '<a href="' . esc_url( admin_url( 'admin.php?page=qrg-setting' ) ) . '">Setting</a>',
    ];
    return array_merge( $extra_links, $links );
}

// Layout default Dashboard (bisa digeser, disembunyikan, & diubah ukurannya)
function qrg_dashboard_allowed_sizes() {
    return [ '25', '33', '50', '75', '100' ];
}

function qrg_dashboard_size_labels() {
    return [
        '25'  => '1/4',
        '33'  => '1/3',
        '50'  => '1/2',
        '75'  => '3/4',
        '100' => 'Penuh',
    ];
}

function qrg_dashboard_default_layout() {
    return [
        'summary'         => [ 'civitas', 'psb', 'kelas', 'keuangan' ],
        'widgets'         => [ 'inventaris', 'keuangan', 'siswa_kelas', 'psb_widget' ],
        'widget_cols'     => [
            '1' => [ 'inventaris', 'siswa_kelas' ],
            '2' => [ 'keuangan', 'psb_widget' ],
        ],
        'hidden_summary'  => [],
        'hidden_widgets'  => [],
        'sizes_summary'   => [
            'civitas'  => '25',
            'psb'      => '25',
            'kelas'    => '25',
            'keuangan' => '25',
        ],
        'sizes_widgets'   => [
            'inventaris'  => '50',
            'keuangan'    => '50',
            'siswa_kelas' => '50',
            'psb_widget'  => '50',
        ],
    ];
}

function qrg_sanitize_dashboard_sizes( $sizes, $allowed_keys, $defaults ) {
    $allowed_sizes = qrg_dashboard_allowed_sizes();
    $out           = $defaults;
    if ( ! is_array( $sizes ) ) {
        return $out;
    }
    foreach ( $allowed_keys as $key ) {
        if ( ! isset( $sizes[ $key ] ) ) {
            continue;
        }
        $size = sanitize_text_field( (string) $sizes[ $key ] );
        if ( in_array( $size, $allowed_sizes, true ) ) {
            $out[ $key ] = $size;
        }
    }
    return $out;
}

function qrg_get_dashboard_layout() {
    $defaults = qrg_dashboard_default_layout();
    $saved    = get_user_meta( get_current_user_id(), 'qrg_dashboard_layout', true );
    if ( ! is_array( $saved ) ) {
        return $defaults;
    }

    // Migrasi kartu ringkasan lama "status" → "keuangan".
    if ( ! empty( $saved['summary'] ) && is_array( $saved['summary'] ) ) {
        $saved['summary'] = array_map(
            static function ( $key ) {
                return $key === 'status' ? 'keuangan' : $key;
            },
            $saved['summary']
        );
    }
    if ( ! empty( $saved['hidden_summary'] ) && is_array( $saved['hidden_summary'] ) ) {
        $saved['hidden_summary'] = array_map(
            static function ( $key ) {
                return $key === 'status' ? 'keuangan' : $key;
            },
            $saved['hidden_summary']
        );
    }
    if ( isset( $saved['sizes_summary']['status'] ) && ! isset( $saved['sizes_summary']['keuangan'] ) ) {
        $saved['sizes_summary']['keuangan'] = $saved['sizes_summary']['status'];
        unset( $saved['sizes_summary']['status'] );
    }

    $layout = $defaults;
    foreach ( [ 'summary', 'widgets' ] as $group ) {
        if ( empty( $saved[ $group ] ) || ! is_array( $saved[ $group ] ) ) {
            continue;
        }
        $valid = array_values( array_intersect( $saved[ $group ], $defaults[ $group ] ) );
        foreach ( $defaults[ $group ] as $key ) {
            if ( ! in_array( $key, $valid, true ) ) {
                $valid[] = $key;
            }
        }
        $layout[ $group ] = $valid;
    }
    foreach ( [ 'hidden_summary', 'hidden_widgets' ] as $group ) {
        if ( isset( $saved[ $group ] ) && is_array( $saved[ $group ] ) ) {
            $base_key = ( $group === 'hidden_summary' ) ? 'summary' : 'widgets';
            $layout[ $group ] = array_values( array_intersect( $saved[ $group ], $defaults[ $base_key ] ) );
        }
    }
    $layout['sizes_summary'] = qrg_sanitize_dashboard_sizes(
        $saved['sizes_summary'] ?? [],
        $defaults['summary'],
        $defaults['sizes_summary']
    );
    $layout['sizes_widgets'] = qrg_sanitize_dashboard_sizes(
        $saved['sizes_widgets'] ?? [],
        $defaults['widgets'],
        $defaults['sizes_widgets']
    );

    // Kolom widget ala Dashboard WordPress (2 kolom).
    $layout['widget_cols'] = $defaults['widget_cols'];
    if ( ! empty( $saved['widget_cols'] ) && is_array( $saved['widget_cols'] ) ) {
        $cols = [ '1' => [], '2' => [] ];
        foreach ( [ '1', '2' ] as $col ) {
            if ( empty( $saved['widget_cols'][ $col ] ) || ! is_array( $saved['widget_cols'][ $col ] ) ) {
                continue;
            }
            $cols[ $col ] = array_values( array_intersect( $saved['widget_cols'][ $col ], $defaults['widgets'] ) );
        }
        $placed = array_merge( $cols['1'], $cols['2'] );
        foreach ( $defaults['widgets'] as $wid ) {
            if ( ! in_array( $wid, $placed, true ) ) {
                $cols['1'][] = $wid;
            }
        }
        $layout['widget_cols'] = $cols;
        $layout['widgets']     = array_merge( $cols['1'], $cols['2'] );
    } else {
        // Migrasi layout lama: bagi rata ke 2 kolom.
        $all  = $layout['widgets'];
        $half = (int) ceil( count( $all ) / 2 );
        $layout['widget_cols'] = [
            '1' => array_slice( $all, 0, $half ),
            '2' => array_slice( $all, $half ),
        ];
    }

    $user_id = get_current_user_id();
    if ( get_user_meta( $user_id, 'qrg_dashboard_widgets_row_migrated', true ) !== 'wp2col' ) {
        $layout['widget_cols']   = $defaults['widget_cols'];
        $layout['widgets']       = array_merge( $defaults['widget_cols']['1'], $defaults['widget_cols']['2'] );
        $layout['sizes_widgets'] = $defaults['sizes_widgets'];
        update_user_meta( $user_id, 'qrg_dashboard_layout', $layout );
        update_user_meta( $user_id, 'qrg_dashboard_widgets_row_migrated', 'wp2col' );
    }

    return $layout;
}

add_action( 'admin_enqueue_scripts', 'qrg_dashboard_enqueue_assets' );
function qrg_dashboard_enqueue_assets( $hook ) {
    if ( $hook !== 'toplevel_page_master-data-plugin' ) {
        return;
    }
    wp_enqueue_style( 'dashboard' );
    wp_enqueue_script( 'jquery-ui-sortable' );
    wp_enqueue_script( 'postbox' );
    wp_enqueue_script( 'dashboard' );
    wp_enqueue_script(
        'chart-js',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
        [],
        '4.4.1',
        true
    );
}

add_filter( 'admin_body_class', 'qrg_dashboard_admin_body_class' );
function qrg_dashboard_admin_body_class( $classes ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->id === 'toplevel_page_master-data-plugin' ) {
        $classes .= ' index-php columns-2';
    }
    return $classes;
}

add_action( 'wp_ajax_qrg_save_dashboard_layout', 'qrg_ajax_save_dashboard_layout' );
function qrg_ajax_save_dashboard_layout() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Akses ditolak.' ], 403 );
    }
    check_ajax_referer( 'qrg_dashboard_layout', 'nonce' );

    $defaults = qrg_dashboard_default_layout();
    $summary  = isset( $_POST['summary'] ) ? (array) wp_unslash( $_POST['summary'] ) : [];
    $widgets  = isset( $_POST['widgets'] ) ? (array) wp_unslash( $_POST['widgets'] ) : [];
    $hidden_s = isset( $_POST['hidden_summary'] ) ? (array) wp_unslash( $_POST['hidden_summary'] ) : [];
    $hidden_w = isset( $_POST['hidden_widgets'] ) ? (array) wp_unslash( $_POST['hidden_widgets'] ) : [];
    $sizes_s  = isset( $_POST['sizes_summary'] ) ? (array) wp_unslash( $_POST['sizes_summary'] ) : [];
    $sizes_w  = isset( $_POST['sizes_widgets'] ) ? (array) wp_unslash( $_POST['sizes_widgets'] ) : [];
    $col1     = isset( $_POST['widget_col1'] ) ? (array) wp_unslash( $_POST['widget_col1'] ) : [];
    $col2     = isset( $_POST['widget_col2'] ) ? (array) wp_unslash( $_POST['widget_col2'] ) : [];

    $summary = array_values( array_intersect( array_map( 'sanitize_key', $summary ), $defaults['summary'] ) );
    $widgets = array_values( array_intersect( array_map( 'sanitize_key', $widgets ), $defaults['widgets'] ) );
    foreach ( $defaults['summary'] as $key ) {
        if ( ! in_array( $key, $summary, true ) ) {
            $summary[] = $key;
        }
    }

    $widget_cols = [
        '1' => array_values( array_intersect( array_map( 'sanitize_key', $col1 ), $defaults['widgets'] ) ),
        '2' => array_values( array_intersect( array_map( 'sanitize_key', $col2 ), $defaults['widgets'] ) ),
    ];
    $placed = array_merge( $widget_cols['1'], $widget_cols['2'] );
    if ( empty( $placed ) && ! empty( $widgets ) ) {
        $half = (int) ceil( count( $widgets ) / 2 );
        $widget_cols = [
            '1' => array_slice( $widgets, 0, $half ),
            '2' => array_slice( $widgets, $half ),
        ];
        $placed = array_merge( $widget_cols['1'], $widget_cols['2'] );
    }
    foreach ( $defaults['widgets'] as $key ) {
        if ( ! in_array( $key, $placed, true ) ) {
            $widget_cols['1'][] = $key;
            $placed[]           = $key;
        }
    }
    $widgets = $placed;

    $layout = [
        'summary'        => $summary,
        'widgets'        => $widgets,
        'widget_cols'    => $widget_cols,
        'hidden_summary' => array_values( array_intersect( array_map( 'sanitize_key', $hidden_s ), $defaults['summary'] ) ),
        'hidden_widgets' => array_values( array_intersect( array_map( 'sanitize_key', $hidden_w ), $defaults['widgets'] ) ),
        'sizes_summary'  => qrg_sanitize_dashboard_sizes( $sizes_s, $defaults['summary'], $defaults['sizes_summary'] ),
        'sizes_widgets'  => qrg_sanitize_dashboard_sizes( $sizes_w, $defaults['widgets'], $defaults['sizes_widgets'] ),
    ];

    update_user_meta( get_current_user_id(), 'qrg_dashboard_layout', $layout );
    wp_send_json_success( [ 'message' => 'Layout dashboard disimpan.' ] );
}

// Membuat Menu Induk "Sekolah" di Sidebar Dashboard
add_action( 'admin_menu', 'qrg_add_master_menu' );
function qrg_add_master_menu() {
    add_menu_page(
        'Data Sekolah',          
        'Sekolah',               
        'manage_options',       
        'master-data-plugin',   
        'qrg_master_dashboard_callback', 
        'dashicons-welcome-learn-more',   
        25                      
    );

    // Daftarkan secara eksplisit agar menu Dashboard terjamin terdaftar di submenu
    add_submenu_page(
        'master-data-plugin',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'master-data-plugin',
        'qrg_master_dashboard_callback'
    );
}

// Mengurutkan Submenu agar Dashboard berada di urutan pertama dan bernama "Dashboard"
add_action( 'admin_menu', 'qrg_reorder_master_submenus', 999 );
function qrg_reorder_master_submenus() {
    global $submenu;
    if ( ! isset( $submenu['master-data-plugin'] ) ) {
        return;
    }
    
    $master_submenus = $submenu['master-data-plugin'];
    $new_submenus = array();
    $dashboard_item = null;
    
    // Cari item dengan slug 'master-data-plugin', simpan satu dan buang duplikatnya
    foreach ( $master_submenus as $key => $item ) {
        if ( isset( $item[2] ) && $item[2] === 'master-data-plugin' ) {
            if ( ! $dashboard_item ) {
                $dashboard_item = $item;
                $dashboard_item[0] = 'Dashboard'; // Ubah label nama menjadi Dashboard
            }
            unset( $master_submenus[$key] );
        }
        // Hapus menu yang diakses via tombol di halaman terkait
        if ( isset( $item[2] ) && (
            $item[2] === 'edit.php?post_type=master_jalur' ||
            $item[2] === 'post-new.php?post_type=master_jalur' ||
            $item[2] === 'edit.php?post_type=master_biaya' ||
            $item[2] === 'edit.php?post_type=master_jadwal' ||
            $item[2] === 'post-new.php?post_type=master_jadwal' ||
            $item[2] === 'edit.php?post_type=master_tugas' ||
            $item[2] === 'post-new.php?post_type=master_tugas' ||
            $item[2] === 'edit.php?post_type=master_inventaris' ||
            $item[2] === 'post-new.php?post_type=master_inventaris' ||
            $item[2] === 'edit.php?post_type=master_tagihan' ||
            $item[2] === 'post-new.php?post_type=master_tagihan' ||
            $item[2] === 'edit.php?post_type=master_gaji_guru' ||
            $item[2] === 'post-new.php?post_type=master_gaji_guru' ||
            $item[2] === 'edit.php?post_type=master_pembelian' ||
            $item[2] === 'post-new.php?post_type=master_pembelian' ||
            $item[2] === 'edit.php?post_type=master_rkas' ||
            $item[2] === 'post-new.php?post_type=master_rkas' ||
            $item[2] === 'edit.php?post_type=master_presensi_guru' ||
            $item[2] === 'post-new.php?post_type=master_presensi_guru' ||
            $item[2] === 'psb-form-settings' ||
            $item[2] === 'qrg-generate-kelas'
        ) ) {
            unset( $master_submenus[$key] );
        }
    }
    
    // Masukkan dashboard ke urutan paling pertama
    if ( $dashboard_item ) {
        $new_submenus[] = $dashboard_item;
    }
    
    // Gabungkan sisanya
    foreach ( $master_submenus as $item ) {
        $new_submenus[] = $item;
    }
    
    $submenu['master-data-plugin'] = $new_submenus;
}

// Tampilan Halaman Utama Dashboard Master
function qrg_master_dashboard_callback() {
    // Helper hitung publish aman
    $count_publish = function( $post_type ) {
        $counts = wp_count_posts( $post_type );
        return isset( $counts->publish ) ? (int) $counts->publish : 0;
    };

    $count_guru       = $count_publish( 'master_guru' );
    $count_siswa      = $count_publish( 'master_siswa' );
    $count_kelas      = $count_publish( 'master_kelas' );
    $count_ruangan    = $count_publish( 'master_ruangan' );
    $count_inventaris = $count_publish( 'master_inventaris' );
    $count_biaya      = $count_publish( 'master_biaya' );
    $count_psb        = $count_publish( 'master_psb' );

    $psb_menunggu = 0;
    $psb_diterima = 0;
    $psb_ditolak  = 0;
    $psb_per_jalur = []; // jalur_id => ['total' => n, 'diterima' => n, 'menunggu' => n]
    $psb_asal_sekolah = []; // asal => count

    $psb_posts = get_posts( [
        'post_type'      => 'master_psb',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );
    foreach ( $psb_posts as $psb_id ) {
        $status   = get_post_meta( $psb_id, '_psb_status', true );
        $jalur_id = (int) get_post_meta( $psb_id, '_psb_jalur_id', true );
        $asal     = trim( (string) get_post_meta( $psb_id, '_psb_asal_sekolah', true ) );

        if ( $status == 'Diterima' ) {
            $psb_diterima++;
        } elseif ( $status == 'Ditolak' ) {
            $psb_ditolak++;
        } else {
            $psb_menunggu++;
        }

        if ( $jalur_id > 0 ) {
            if ( ! isset( $psb_per_jalur[ $jalur_id ] ) ) {
                $psb_per_jalur[ $jalur_id ] = [ 'total' => 0, 'diterima' => 0, 'menunggu' => 0 ];
            }
            $psb_per_jalur[ $jalur_id ]['total']++;
            if ( $status === 'Diterima' ) {
                $psb_per_jalur[ $jalur_id ]['diterima']++;
            } elseif ( $status !== 'Ditolak' ) {
                $psb_per_jalur[ $jalur_id ]['menunggu']++;
            }
        }

        if ( $asal === '' ) {
            $asal = '(Tidak diisi)';
        }
        if ( ! isset( $psb_asal_sekolah[ $asal ] ) ) {
            $psb_asal_sekolah[ $asal ] = 0;
        }
        $psb_asal_sekolah[ $asal ]++;
    }
    arsort( $psb_asal_sekolah );

    // Ringkasan kuota per jalur
    $psb_kuota_rows = [];
    if ( function_exists( 'qrg_get_daftar_jalur' ) ) {
        foreach ( qrg_get_daftar_jalur( true ) as $jalur ) {
            $kuota_raw = get_post_meta( $jalur->ID, '_jalur_kuota', true );
            $kuota     = ( $kuota_raw === '' || $kuota_raw === null ) ? null : max( 0, (int) $kuota_raw );
            $terisi    = isset( $psb_per_jalur[ $jalur->ID ] ) ? (int) $psb_per_jalur[ $jalur->ID ]['diterima'] : 0;
            $menunggu  = isset( $psb_per_jalur[ $jalur->ID ] ) ? (int) $psb_per_jalur[ $jalur->ID ]['menunggu'] : 0;
            $sisa      = ( $kuota === null ) ? null : max( 0, $kuota - $terisi );

            $psb_kuota_rows[] = [
                'nama'     => $jalur->post_title,
                'kuota'    => $kuota,
                'terisi'   => $terisi,
                'menunggu' => $menunggu,
                'sisa'     => $sisa,
            ];
        }
    }

    // Ringkasan inventaris (jumlah unit, kondisi, nilai aset)
    $inv_unit_total   = 0;
    $inv_baik         = 0;
    $inv_rusak        = 0;
    $inv_harga_total  = 0;
    $inv_nilai_aset   = 0;
    $inv_posts = get_posts( [
        'post_type'      => 'master_inventaris',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );
    foreach ( $inv_posts as $inv_id ) {
        $jumlah  = (int) get_post_meta( $inv_id, '_inv_jumlah', true );
        $kondisi = get_post_meta( $inv_id, '_inv_kondisi', true );
        $inv_unit_total += max( 0, $jumlah );
        if ( $kondisi === 'Baik' ) {
            $inv_baik++;
        } elseif ( in_array( $kondisi, [ 'Rusak Ringan', 'Rusak Berat', 'Dalam Perbaikan', 'Hilang' ], true ) ) {
            $inv_rusak++;
        }
        if ( function_exists( 'qrg_get_inventaris_total_harga' ) ) {
            $inv_harga_total += qrg_get_inventaris_total_harga( $inv_id );
        }
        if ( function_exists( 'qrg_get_inventaris_nilai_aset' ) ) {
            $inv_nilai_aset += qrg_get_inventaris_nilai_aset( $inv_id );
        }
    }

    // Ringkasan billing/tagihan
    $tagihan_query = new WP_Query( [
        'post_type'      => 'master_tagihan',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ] );

    $total_nominal    = 0;
    $total_count      = 0;
    $terbayar_nominal = 0;
    $terbayar_count   = 0;
    $ditinjau_nominal = 0;
    $ditinjau_count   = 0;
    $pending_nominal  = 0;
    $pending_count    = 0;

    if ( $tagihan_query->have_posts() ) {
        while ( $tagihan_query->have_posts() ) {
            $tagihan_query->the_post();
            $t_id = get_the_ID();

            $nominal  = (int) get_post_meta( $t_id, '_tagihan_nominal', true );
            $biaya_id = get_post_meta( $t_id, '_tagihan_biaya_tambahan_id', true );
            if ( $biaya_id ) {
                $nominal += (int) get_post_meta( $biaya_id, '_biaya_nominal', true );
            }

            $status = get_post_meta( $t_id, '_tagihan_status', true );
            $total_nominal += $nominal;
            $total_count++;

            if ( $status === 'Lunas' ) {
                $terbayar_nominal += $nominal;
                $terbayar_count++;
            } elseif ( $status === 'Sedang Ditinjau' ) {
                $ditinjau_nominal += $nominal;
                $ditinjau_count++;
            } else {
                $pending_nominal += $nominal;
                $pending_count++;
            }
        }
        wp_reset_postdata();
    }

    $persen_lunas = $total_nominal > 0
        ? min( 100, round( ( $terbayar_nominal / $total_nominal ) * 100 ) )
        : 0;
    $persen_nilai_aset = $inv_harga_total > 0
        ? min( 100, round( ( $inv_nilai_aset / $inv_harga_total ) * 100 ) )
        : 0;
    $total_belum = $pending_nominal + $ditinjau_nominal;

    // Rekap Siswa per Kelas
    $siswa_per_kelas = [];
    $all_siswa = get_posts( [
        'post_type'      => 'master_siswa',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );
    foreach ( $all_siswa as $s_id ) {
        $k_id = get_post_meta( $s_id, '_siswa_kelas', true );
        if ( $k_id ) {
            if ( ! isset( $siswa_per_kelas[ $k_id ] ) ) {
                $siswa_per_kelas[ $k_id ] = 0;
            }
            $siswa_per_kelas[ $k_id ]++;
        }
    }
    arsort( $siswa_per_kelas ); // Urutkan dari yang terbanyak

    $layout = qrg_get_dashboard_layout();

    // Konten kartu ringkasan — gaya AdminLTE small-box (versi WP)
    ob_start();
    ?>
    <div class="qrg-small-box qrg-bg-primary">
        <div class="qrg-inner">
            <h3><?php echo (int) ( $count_siswa + $count_guru ); ?></h3>
            <p>Civitas Akademika</p>
            <div class="qrg-small-meta">Siswa <?php echo (int) $count_siswa; ?> · Guru <?php echo (int) $count_guru; ?></div>
        </div>
        <div class="qrg-icon"><span class="dashicons dashicons-groups"></span></div>
    </div>
    <?php
    $summary_cards = [ 'civitas' => ob_get_clean() ];

    ob_start();
    ?>
    <div class="qrg-small-box qrg-bg-warning">
        <div class="qrg-inner">
            <h3><?php echo (int) $count_psb; ?></h3>
            <p>Penerimaan Siswa</p>
            <div class="qrg-small-meta">
                Menunggu <?php echo (int) $psb_menunggu; ?> ·
                <span class="qrg-text-ok">OK <?php echo (int) $psb_diterima; ?></span> ·
                <span class="qrg-text-bad">Tolak <?php echo (int) $psb_ditolak; ?></span>
            </div>
        </div>
        <div class="qrg-icon"><span class="dashicons dashicons-id"></span></div>
    </div>
    <?php
    $summary_cards['psb'] = ob_get_clean();

    ob_start();
    ?>
    <div class="qrg-small-box qrg-bg-success">
        <div class="qrg-inner">
            <h3><?php echo (int) ( $count_kelas + $count_ruangan ); ?></h3>
            <p>Kelas &amp; Ruangan</p>
            <div class="qrg-small-meta">Kelas <?php echo (int) $count_kelas; ?> · Ruangan <?php echo (int) $count_ruangan; ?></div>
        </div>
        <div class="qrg-icon"><span class="dashicons dashicons-building"></span></div>
    </div>
    <?php
    $summary_cards['kelas'] = ob_get_clean();

    ob_start();
    ?>
    <div class="qrg-small-box qrg-bg-danger">
        <div class="qrg-inner">
            <h3>Rp <?php echo esc_html( number_format( (int) $total_nominal, 0, ',', '.' ) ); ?></h3>
            <p>Keuangan</p>
            <div class="qrg-small-meta">
                <span class="qrg-text-ok">Lunas <?php echo (int) $persen_lunas; ?>%</span> ·
                Belum Rp <?php echo esc_html( number_format( (int) $total_belum, 0, ',', '.' ) ); ?>
            </div>
        </div>
        <div class="qrg-icon"><span class="dashicons dashicons-money-alt"></span></div>
    </div>
    <?php
    $summary_cards['keuangan'] = ob_get_clean();

    $summary_labels = [
        'civitas'  => 'Civitas Akademika',
        'psb'      => 'Penerimaan Siswa',
        'kelas'    => 'Kelas & Ruangan',
        'keuangan' => 'Keuangan',
    ];

    // Konten widget — gaya AdminLTE card body
    ob_start();
    ?>
    <div class="qrg-info-grid">
        <div class="qrg-info-item">
            <span class="qrg-info-label">Total Item</span>
            <span class="qrg-info-value"><?php echo (int) $count_inventaris; ?> unit</span>
        </div>
        <div class="qrg-info-item">
            <span class="qrg-info-label">Total Fisik</span>
            <span class="qrg-info-value"><?php echo (int) $inv_unit_total; ?></span>
        </div>
        <div class="qrg-info-item">
            <span class="qrg-info-label">Kondisi Baik</span>
            <span class="qrg-info-value qrg-text-ok"><?php echo (int) $inv_baik; ?></span>
        </div>
        <div class="qrg-info-item">
            <span class="qrg-info-label">Perlu Perhatian</span>
            <span class="qrg-info-value qrg-text-bad"><?php echo (int) $inv_rusak; ?></span>
        </div>
    </div>
    <div class="qrg-progress-wrap">
        <div class="qrg-progress-label">
            <span>Nilai Aset Tersisa</span>
            <strong><?php echo (int) $persen_nilai_aset; ?>%</strong>
        </div>
        <div class="qrg-progress"><div class="qrg-progress-bar qrg-bg-primary" style="width:<?php echo (int) $persen_nilai_aset; ?>%"></div></div>
        <div class="qrg-progress-meta">
            Rp <?php echo esc_html( number_format( (int) $inv_nilai_aset, 0, ',', '.' ) ); ?>
            dari Rp <?php echo esc_html( number_format( (int) $inv_harga_total, 0, ',', '.' ) ); ?>
        </div>
    </div>
    <?php
    $widget_bodies = [ 'inventaris' => ob_get_clean() ];
    $widget_titles = [ 'inventaris' => [ 'icon' => 'dashicons-archive', 'label' => 'Inventaris Aset', 'theme' => 'primary' ] ];

    ob_start();
    ?>
    <div class="qrg-big-number">Rp <?php echo esc_html( number_format( $total_nominal, 0, ',', '.' ) ); ?></div>
    <p class="qrg-subtext">Dari <?php echo (int) $total_count; ?> tagihan · <?php echo (int) $count_biaya; ?> jenis biaya</p>
    <div class="qrg-progress-wrap">
        <div class="qrg-progress-label">
            <span>Tingkat Pelunasan</span>
            <strong><?php echo (int) $persen_lunas; ?>%</strong>
        </div>
        <div class="qrg-progress"><div class="qrg-progress-bar qrg-bg-success" style="width:<?php echo (int) $persen_lunas; ?>%"></div></div>
    </div>
    <div class="qrg-info-grid">
        <div class="qrg-info-item">
            <span class="qrg-info-label">Lunas</span>
            <span class="qrg-info-value qrg-text-ok">Rp <?php echo esc_html( number_format( $terbayar_nominal, 0, ',', '.' ) ); ?></span>
        </div>
        <div class="qrg-info-item">
            <span class="qrg-info-label">Belum Dibayar</span>
            <span class="qrg-info-value qrg-text-warn">Rp <?php echo esc_html( number_format( $total_belum, 0, ',', '.' ) ); ?></span>
        </div>
        <div class="qrg-info-item">
            <span class="qrg-info-label">Pending</span>
            <span class="qrg-info-value"><?php echo (int) $pending_count; ?></span>
        </div>
        <div class="qrg-info-item">
            <span class="qrg-info-label">Ditinjau</span>
            <span class="qrg-info-value"><?php echo (int) $ditinjau_count; ?></span>
        </div>
    </div>
    <?php
    $widget_bodies['keuangan'] = ob_get_clean();
    $widget_titles['keuangan'] = [ 'icon' => 'dashicons-money-alt', 'label' => 'Keuangan & Tagihan', 'theme' => 'success' ];

    ob_start();
    if ( ! empty( $siswa_per_kelas ) ) :
        ?>
        <ul class="qrg-dash-list">
            <?php foreach ( $siswa_per_kelas as $kelas_id => $jumlah_siswa ) : ?>
                <li>
                    <span><?php echo esc_html( qrg_get_kelas_label( $kelas_id ) ); ?></span>
                    <strong><span class="qrg-badge qrg-badge-primary"><?php echo (int) $jumlah_siswa; ?></span></strong>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="description">Belum ada data siswa yang masuk ke kelas.</p>
    <?php
    endif;
    $widget_bodies['siswa_kelas'] = ob_get_clean();
    $widget_titles['siswa_kelas'] = [ 'icon' => 'dashicons-groups', 'label' => 'Siswa per Kelas', 'theme' => 'info' ];

    ob_start();
    ?>
    <div class="qrg-info-grid qrg-info-grid-3">
        <div class="qrg-info-item qrg-info-center">
            <span class="qrg-info-value qrg-text-warn"><?php echo (int) $psb_menunggu; ?></span>
            <span class="qrg-info-label">Menunggu</span>
        </div>
        <div class="qrg-info-item qrg-info-center">
            <span class="qrg-info-value qrg-text-ok"><?php echo (int) $psb_diterima; ?></span>
            <span class="qrg-info-label">Diterima</span>
        </div>
        <div class="qrg-info-item qrg-info-center">
            <span class="qrg-info-value qrg-text-bad"><?php echo (int) $psb_ditolak; ?></span>
            <span class="qrg-info-label">Ditolak</span>
        </div>
    </div>
    <p class="qrg-section-title">Sisa Kuota per Jalur</p>
    <?php if ( ! empty( $psb_kuota_rows ) ) : ?>
        <ul class="qrg-dash-list">
            <?php foreach ( $psb_kuota_rows as $row ) : ?>
                <li>
                    <span>
                        <?php echo esc_html( $row['nama'] ); ?>
                        <span class="description">Terisi <?php echo (int) $row['terisi']; ?> · Menunggu <?php echo (int) $row['menunggu']; ?></span>
                    </span>
                    <?php if ( $row['kuota'] === null ) : ?>
                        <span class="qrg-badge">∞</span>
                    <?php else : ?>
                        <span class="qrg-badge <?php echo ( (int) $row['sisa'] > 0 ) ? 'qrg-badge-success' : 'qrg-badge-danger'; ?>">
                            <?php echo (int) $row['sisa']; ?>/<?php echo (int) $row['kuota']; ?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="description">Belum ada jalur aktif.</p>
    <?php endif; ?>
    <p class="qrg-section-title">Asal Sekolah</p>
    <?php if ( ! empty( $psb_asal_sekolah ) ) :
        // Siapkan data grafik: top 8 + grup "Lainnya"
        $asal_chart_labels = [];
        $asal_chart_values = [];
        $asal_i            = 0;
        $asal_lainnya      = 0;
        foreach ( $psb_asal_sekolah as $asal_nama => $asal_jumlah ) {
            if ( $asal_i < 8 ) {
                $asal_chart_labels[] = $asal_nama;
                $asal_chart_values[] = (int) $asal_jumlah;
            } else {
                $asal_lainnya += (int) $asal_jumlah;
            }
            $asal_i++;
        }
        if ( $asal_lainnya > 0 ) {
            $asal_chart_labels[] = 'Lainnya';
            $asal_chart_values[] = $asal_lainnya;
        }
        ?>
        <div class="qrg-pie-wrap">
            <div class="qrg-pie-canvas-box">
                <canvas id="qrg-asal-sekolah-chart" aria-label="Grafik lingkaran asal sekolah" role="img"></canvas>
            </div>
        </div>
        <script type="application/json" id="qrg-asal-sekolah-data"><?php echo wp_json_encode( [
            'labels' => $asal_chart_labels,
            'values' => $asal_chart_values,
        ], JSON_UNESCAPED_UNICODE ); ?></script>
    <?php else : ?>
        <p class="description">Belum ada data asal sekolah.</p>
    <?php endif;
    $widget_bodies['psb_widget'] = ob_get_clean();
    $widget_titles['psb_widget'] = [ 'icon' => 'dashicons-id', 'label' => 'Penerimaan Siswa Baru', 'theme' => 'warning' ];

    $ajax_nonce     = wp_create_nonce( 'qrg_dashboard_layout' );
    $size_labels    = qrg_dashboard_size_labels();
    $default_layout = qrg_dashboard_default_layout();
    ?>
    <div class="wrap qrg-dashboard qrg-adminlte">
        <div class="qrg-content-header">
            <div>
                <h1 class="wp-heading-inline">Dashboard Sistem Informasi Sekolah</h1>
                <button type="button" id="qrg-dash-open-settings" class="page-title-action">Setting Dashboard</button>
                <hr class="wp-header-end">
                <p class="qrg-content-subtitle">Ringkasan akademik, aset, dan keuangan sekolah</p>
            </div>
            <p id="qrg-dash-save-status" class="qrg-dash-save-status" hidden>Tersimpan</p>
        </div>

        <div id="qrg-dash-summary" class="qrg-dash-summary-grid">
            <?php foreach ( $layout['summary'] as $sid ) :
                if ( ! isset( $summary_cards[ $sid ] ) ) {
                    continue;
                }
                $is_hidden = in_array( $sid, $layout['hidden_summary'], true );
                $size      = $layout['sizes_summary'][ $sid ] ?? '25';
                ?>
                <div class="qrg-dash-summary-card qrg-size-<?php echo esc_attr( $size ); ?><?php echo $is_hidden ? ' hidden' : ''; ?>" data-id="<?php echo esc_attr( $sid ); ?>" data-size="<?php echo esc_attr( $size ); ?>"<?php echo $is_hidden ? ' hidden' : ''; ?>>
                    <?php echo $summary_cards[ $sid ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="dashboard-widgets-wrap">
            <div id="dashboard-widgets" class="metabox-holder columns-2">
                <?php
                $widget_cols = $layout['widget_cols'] ?? [
                    '1' => [ 'inventaris', 'siswa_kelas' ],
                    '2' => [ 'keuangan', 'psb_widget' ],
                ];
                foreach ( [ '1', '2' ] as $col_num ) :
                    $col_widgets = $widget_cols[ $col_num ] ?? [];
                    $sort_id     = ( $col_num === '1' ) ? 'normal-sortables' : 'side-sortables';
                    ?>
                    <div id="postbox-container-<?php echo esc_attr( $col_num ); ?>" class="postbox-container">
                        <div id="<?php echo esc_attr( $sort_id ); ?>" class="meta-box-sortables qrg-dash-col" data-col="<?php echo esc_attr( $col_num ); ?>">
                            <?php foreach ( $col_widgets as $wid ) :
                                if ( ! isset( $widget_bodies[ $wid ] ) ) {
                                    continue;
                                }
                                $is_hidden = in_array( $wid, $layout['hidden_widgets'], true );
                                $title     = $widget_titles[ $wid ];
                                $box_id    = 'dashboard_widget_' . $wid;
                                ?>
                                <div id="<?php echo esc_attr( $box_id ); ?>" class="postbox qrg-dash-widget<?php echo $is_hidden ? ' hidden' : ''; ?>" data-id="<?php echo esc_attr( $wid ); ?>"<?php echo $is_hidden ? ' hidden' : ''; ?>>
                                    <div class="postbox-header">
                                        <h2 class="hndle ui-sortable-handle">
                                            <span class="dashicons <?php echo esc_attr( $title['icon'] ); ?>" style="margin-right:4px;"></span>
                                            <?php echo esc_html( $title['label'] ); ?>
                                        </h2>
                                        <div class="handle-actions hide-if-no-js">
                                            <button type="button" class="handlediv" aria-expanded="true">
                                                <span class="screen-reader-text"><?php echo esc_html( sprintf( 'Tampilkan/Sembunyikan Panel: %s', $title['label'] ) ); ?></span>
                                                <span class="toggle-indicator" aria-hidden="true"></span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="inside">
                                        <?php echo $widget_bodies[ $wid ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <br class="clear">
            </div>
        </div>

        <div id="qrg-dash-drawer-backdrop" class="qrg-dash-drawer-backdrop" hidden></div>
        <aside id="qrg-dash-drawer" class="qrg-dash-drawer" aria-hidden="true" aria-labelledby="qrg-dash-drawer-title">
            <div class="qrg-dash-drawer-header">
                <h2 id="qrg-dash-drawer-title">Setting Dashboard</h2>
                <button type="button" id="qrg-dash-close-settings" class="button-link qrg-dash-drawer-close" aria-label="Tutup">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </div>
            <div class="qrg-dash-drawer-body">
                <p class="description">Centang untuk menampilkan. Geser widget antar kolom seperti Dashboard WordPress. Ukuran berlaku untuk kartu Ringkasan.</p>
                <h3>Ringkasan</h3>
                <ul class="qrg-dash-toggles" id="qrg-toggle-summary">
                    <?php foreach ( $summary_labels as $sid => $label ) :
                        $size = $layout['sizes_summary'][ $sid ] ?? '25';
                        ?>
                        <li>
                            <label>
                                <input type="checkbox" data-group="summary" value="<?php echo esc_attr( $sid ); ?>" <?php checked( ! in_array( $sid, $layout['hidden_summary'], true ) ); ?>>
                                <span class="qrg-toggle-label"><?php echo esc_html( $label ); ?></span>
                            </label>
                            <select class="qrg-size-select" data-group="summary" data-id="<?php echo esc_attr( $sid ); ?>" title="Ukuran">
                                <?php foreach ( $size_labels as $val => $lab ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $size, $val ); ?>><?php echo esc_html( $lab ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <h3>Widget</h3>
                <ul class="qrg-dash-toggles" id="qrg-toggle-widgets">
                    <?php foreach ( $widget_titles as $wid => $meta ) : ?>
                        <li>
                            <label>
                                <input type="checkbox" data-group="widgets" value="<?php echo esc_attr( $wid ); ?>" <?php checked( ! in_array( $wid, $layout['hidden_widgets'], true ) ); ?>>
                                <span class="qrg-toggle-label"><?php echo esc_html( $meta['label'] ); ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="qrg-dash-drawer-footer">
                <button type="button" id="qrg-dash-reset" class="button">Reset Layout</button>
                <button type="button" id="qrg-dash-done-settings" class="button button-primary">Selesai</button>
            </div>
        </aside>
    </div>

    <style>
        /* AdminLTE-inspired dashboard (scoped WordPress admin) */
        .qrg-adminlte {
            --qrg-primary: #2271b1;
            --qrg-success: #00a32a;
            --qrg-warning: #dba617;
            --qrg-danger: #d63638;
            --qrg-info: #72aee6;
            --qrg-dark: #1d2327;
            --qrg-muted: #646970;
            --qrg-border: #c3c4c7;
            --qrg-soft: #f0f0f1;
        }
        .qrg-content-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }
        .qrg-content-subtitle {
            margin: 6px 0 0;
            color: var(--qrg-muted);
            font-size: 13px;
        }
        .qrg-dash-save-status {
            margin: 8px 0 0;
            color: var(--qrg-success);
            font-weight: 600;
        }
        .qrg-dash-summary-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 16px;
            align-items: stretch;
            margin: 0 0 20px;
        }
        .qrg-dash-summary-card { min-width: 0; cursor: move; height: 100%; }
        .qrg-dash-summary-card .qrg-small-box { height: 100%; }
        .qrg-dash-summary-grid .qrg-size-25 { grid-column: span 3; }
        .qrg-dash-summary-grid .qrg-size-33 { grid-column: span 4; }
        .qrg-dash-summary-grid .qrg-size-50 { grid-column: span 6; }
        .qrg-dash-summary-grid .qrg-size-75 { grid-column: span 9; }
        .qrg-dash-summary-grid .qrg-size-100 { grid-column: span 12; }
        .qrg-size-25 .qrg-small-box .qrg-inner h3,
        .qrg-size-33 .qrg-small-box .qrg-inner h3 { font-size: 24px !important; }

        /* Widget ala Dashboard WordPress (2 kolom penuh, tanpa celah tengah) */
        .qrg-dashboard #dashboard-widgets-wrap {
            margin: 0 -8px;
            overflow: hidden;
        }
        .qrg-dashboard #wpbody-content #dashboard-widgets.columns-2 .postbox-container,
        .qrg-dashboard #dashboard-widgets.columns-2 .postbox-container,
        #wpbody-content .qrg-dashboard #dashboard-widgets .postbox-container {
            width: 50% !important;
            float: left;
            box-sizing: border-box;
        }
        .qrg-dashboard #dashboard-widgets.columns-2 #postbox-container-1 {
            float: left !important;
        }
        .qrg-dashboard #dashboard-widgets.columns-2 #postbox-container-2 {
            float: right !important;
        }
        .qrg-dashboard #dashboard-widgets .meta-box-sortables {
            min-height: 80px;
            margin: 0 8px 20px;
        }
        .qrg-dashboard #dashboard-widgets .postbox {
            margin-bottom: 20px;
        }
        .qrg-dashboard #dashboard-widgets .postbox .hndle .dashicons {
            color: #787c82;
            vertical-align: text-bottom;
        }
        .qrg-dashboard #dashboard-widgets .postbox .inside {
            margin: 0;
            padding: 12px;
        }
        .qrg-dashboard #dashboard-widgets .qrg-dash-col .ui-sortable-placeholder {
            visibility: visible !important;
            border: 1px dashed #c3c4c7;
            background: #f6f7f7;
            min-height: 60px;
            margin-bottom: 20px;
            box-sizing: border-box;
        }

        /* Small boxes ala AdminLTE */
        .qrg-small-box {
            position: relative;
            display: block;
            min-height: 98px;
            background: #fff;
            border: 1px solid var(--qrg-border);
            border-radius: 4px;
            box-shadow: 0 1px 1px rgba(0,0,0,.05);
            overflow: hidden;
            box-sizing: border-box;
        }
        .qrg-small-box .qrg-inner {
            padding: 14px 14px 12px;
            margin-right: 70px;
        }
        .qrg-small-box .qrg-inner h3 {
            margin: 0 0 4px !important;
            font-size: 28px !important;
            font-weight: 700;
            line-height: 1.1;
            color: var(--qrg-dark);
            letter-spacing: -0.02em;
        }
        .qrg-small-box .qrg-inner p {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--qrg-dark);
        }
        .qrg-small-meta {
            font-size: 12px;
            color: var(--qrg-muted);
            line-height: 1.35;
        }
        .qrg-small-box .qrg-icon {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,.92);
        }
        .qrg-small-box .qrg-icon .dashicons {
            font-size: 42px;
            width: 42px;
            height: 42px;
            opacity: .9;
        }
        .qrg-bg-primary .qrg-icon { background: var(--qrg-primary); }
        .qrg-bg-success .qrg-icon { background: var(--qrg-success); }
        .qrg-bg-warning .qrg-icon { background: var(--qrg-warning); }
        .qrg-bg-danger .qrg-icon { background: var(--qrg-danger); }
        .qrg-bg-info .qrg-icon { background: var(--qrg-info); }
        .qrg-bg-primary { border-top: 3px solid var(--qrg-primary); }
        .qrg-bg-success { border-top: 3px solid var(--qrg-success); }
        .qrg-bg-warning { border-top: 3px solid var(--qrg-warning); }
        .qrg-bg-danger { border-top: 3px solid var(--qrg-danger); }
        .qrg-bg-info { border-top: 3px solid var(--qrg-info); }

        .qrg-big-number {
            font-size: 22px;
            font-weight: 700;
            color: var(--qrg-dark);
            line-height: 1.2;
            margin: 0 0 4px;
        }
        .qrg-subtext {
            margin: 0 0 12px;
            color: var(--qrg-muted);
            font-size: 12px;
        }
        .qrg-section-title {
            margin: 14px 0 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: var(--qrg-muted);
        }
        .qrg-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }
        .qrg-info-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .qrg-info-item {
            background: var(--qrg-soft);
            border: 1px solid #e2e4e7;
            border-radius: 4px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .qrg-info-center { align-items: center; text-align: center; }
        .qrg-info-label {
            font-size: 11px;
            color: var(--qrg-muted);
            text-transform: uppercase;
            letter-spacing: .02em;
        }
        .qrg-info-value {
            font-size: 15px;
            font-weight: 700;
            color: var(--qrg-dark);
            word-break: break-word;
        }
        .qrg-info-center .qrg-info-value { font-size: 22px; }
        .qrg-progress-wrap { margin: 4px 0 12px; }
        .qrg-progress-label {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
            font-size: 12px;
            color: var(--qrg-muted);
        }
        .qrg-progress {
            height: 8px;
            background: #e2e4e7;
            border-radius: 999px;
            overflow: hidden;
        }
        .qrg-progress-bar {
            height: 100%;
            border-radius: 999px;
            min-width: 2px;
        }
        .qrg-progress-bar.qrg-bg-primary { background: var(--qrg-primary); }
        .qrg-progress-bar.qrg-bg-success { background: var(--qrg-success); }
        .qrg-progress-bar.qrg-bg-warning { background: var(--qrg-warning); }
        .qrg-progress-meta {
            margin-top: 6px;
            font-size: 12px;
            color: var(--qrg-muted);
        }
        .qrg-pie-wrap {
            margin: 4px 0 0;
        }
        .qrg-pie-canvas-box {
            position: relative;
            width: 100%;
            max-width: 320px;
            margin: 0 auto;
            height: 260px;
        }
        .qrg-pie-canvas-box canvas {
            width: 100% !important;
            height: 100% !important;
        }
        .qrg-badge {
            display: inline-block;
            min-width: 1.6em;
            padding: 2px 8px;
            border-radius: 999px;
            background: #dcdcde;
            color: var(--qrg-dark);
            font-size: 12px;
            font-weight: 700;
            text-align: center;
            white-space: nowrap;
        }
        .qrg-badge-primary { background: #dbeafe; color: #1e40af; }
        .qrg-badge-success { background: #dcfce7; color: #166534; }
        .qrg-badge-danger { background: #fee2e2; color: #991b1b; }
        .qrg-badge-info { background: #e0f2fe; color: #075985; }
        .qrg-text-ok { color: var(--qrg-success) !important; }
        .qrg-text-bad { color: var(--qrg-danger) !important; }
        .qrg-text-warn { color: #b45309 !important; }
        .qrg-text-muted { color: var(--qrg-muted) !important; }
        .qrg-dash-list { margin: 0; padding: 0; list-style: none; }
        .qrg-dash-list li {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f1;
            font-size: 13px;
            color: #50575e;
        }
        .qrg-dash-list li:last-child { border-bottom: 0; }
        .qrg-dash-list .description {
            display: block;
            margin-top: 2px;
            color: #8c8f94;
        }
        .qrg-dash-scroll {
            max-height: 180px;
            overflow-y: auto;
            padding-right: 4px;
        }

        /* Drawer */
        .qrg-dash-drawer-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,.35);
            z-index: 100100; opacity: 0; transition: opacity .25s ease;
        }
        .qrg-dash-drawer-backdrop.is-open { opacity: 1; }
        .qrg-dash-drawer {
            position: fixed; top: 0; right: 0; width: min(360px, 92vw); height: 100vh;
            background: #fff; border-left: 1px solid var(--qrg-border);
            box-shadow: -4px 0 16px rgba(0,0,0,.12); z-index: 100101;
            display: flex; flex-direction: column;
            transform: translateX(105%); transition: transform .28s ease;
        }
        .qrg-dash-drawer.is-open { transform: translateX(0); }
        .qrg-dash-drawer-header {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 16px; border-bottom: 1px solid #dcdcde; background: var(--qrg-soft);
        }
        .qrg-dash-drawer-header h2 { margin: 0; font-size: 14px; font-weight: 600; }
        .qrg-dash-drawer-close .dashicons { font-size: 20px; width: 20px; height: 20px; }
        .qrg-dash-drawer-body { flex: 1; overflow-y: auto; padding: 16px; }
        .qrg-dash-drawer-body h3 { margin: 12px 0 8px; font-size: 13px; }
        .qrg-dash-drawer-footer {
            display: flex; justify-content: space-between; gap: 10px;
            padding: 12px 16px; border-top: 1px solid #dcdcde; background: var(--qrg-soft);
        }
        body.qrg-dash-drawer-open { overflow: hidden; }
        .qrg-dash-toggles { margin: 0 0 16px; padding: 0; list-style: none; }
        .qrg-dash-toggles li {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            margin: 0 0 8px; padding: 8px 10px; background: #fff;
            border: 1px solid #dcdcde; border-radius: 4px;
        }
        .qrg-dash-toggles label {
            display: flex; align-items: center; gap: 6px; cursor: pointer; min-width: 0; flex: 1;
        }
        .qrg-toggle-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .qrg-dash-toggles .qrg-size-select { width: 88px; flex-shrink: 0; }
        .qrg-dash-summary-grid .ui-sortable-placeholder {
            visibility: visible !important;
            background: #f0f6fc;
            border: 1px dashed var(--qrg-primary);
            border-radius: 4px;
            min-height: 98px;
            margin: 0;
            box-sizing: border-box;
        }
        @media (max-width: 960px) {
            .qrg-dash-summary-grid .qrg-size-25,
            .qrg-dash-summary-grid .qrg-size-33 { grid-column: span 6; }
            .qrg-dash-summary-grid .qrg-size-50,
            .qrg-dash-summary-grid .qrg-size-75 { grid-column: span 12; }
            .qrg-dashboard #dashboard-widgets.columns-2 .postbox-container,
            #wpbody-content .qrg-dashboard #dashboard-widgets .postbox-container {
                width: 100% !important;
                float: none !important;
            }
        }
        @media (max-width: 782px) {
            .qrg-content-header { flex-direction: column; }
            .qrg-dash-summary-grid { grid-template-columns: 1fr; }
            .qrg-dash-summary-grid .qrg-size-25,
            .qrg-dash-summary-grid .qrg-size-33,
            .qrg-dash-summary-grid .qrg-size-50,
            .qrg-dash-summary-grid .qrg-size-75,
            .qrg-dash-summary-grid .qrg-size-100 { grid-column: span 1; }
            .qrg-info-grid, .qrg-info-grid-3 { grid-template-columns: 1fr; }
        }
    </style>
    <script>
    jQuery(function($) {
        var nonce = <?php echo wp_json_encode( $ajax_nonce ); ?>;
        var defaultSizesSummary = <?php echo wp_json_encode( $default_layout['sizes_summary'] ); ?>;
        var defaultSizesWidgets = <?php echo wp_json_encode( $default_layout['sizes_widgets'] ); ?>;
        var saveTimer = null;
        var sizeClasses = 'qrg-size-25 qrg-size-33 qrg-size-50 qrg-size-75 qrg-size-100';
        var $drawer = $('#qrg-dash-drawer');
        var $backdrop = $('#qrg-dash-drawer-backdrop');

        // Grafik lingkaran Asal Sekolah
        (function initAsalSekolahChart() {
            var canvas = document.getElementById('qrg-asal-sekolah-chart');
            var dataEl = document.getElementById('qrg-asal-sekolah-data');
            if (!canvas || !dataEl || typeof Chart === 'undefined') {
                return;
            }
            var raw;
            try {
                raw = JSON.parse(dataEl.textContent || '{}');
            } catch (e) {
                return;
            }
            if (!raw.labels || !raw.values || !raw.labels.length) {
                return;
            }
            var palette = [
                '#0d6efd', '#198754', '#fd7e14', '#0dcaf0',
                '#dc3545', '#6c757d', '#20c997', '#ffc107', '#6610f2'
            ];
            var colors = raw.labels.map(function(_, i) {
                return palette[i % palette.length];
            });
            new Chart(canvas.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: raw.labels,
                    datasets: [{
                        data: raw.values,
                        backgroundColor: colors,
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 10,
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                    var val = ctx.raw || 0;
                                    var pct = total ? Math.round((val / total) * 100) : 0;
                                    return ' ' + ctx.label + ': ' + val + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        })();

        function openSettings() {
            $backdrop.prop('hidden', false);
            requestAnimationFrame(function() {
                $backdrop.addClass('is-open');
                $drawer.addClass('is-open').attr('aria-hidden', 'false');
            });
            $('body').addClass('qrg-dash-drawer-open');
        }

        function closeSettings() {
            $drawer.removeClass('is-open').attr('aria-hidden', 'true');
            $backdrop.removeClass('is-open');
            $('body').removeClass('qrg-dash-drawer-open');
            setTimeout(function() {
                if (!$drawer.hasClass('is-open')) {
                    $backdrop.prop('hidden', true);
                }
            }, 280);
        }

        function collectLayout() {
            var summary = [];
            var widgets = [];
            var hiddenSummary = [];
            var hiddenWidgets = [];
            var sizesSummary = {};
            var sizesWidgets = {};
            var col1 = [];
            var col2 = [];

            $('#qrg-dash-summary .qrg-dash-summary-card').each(function() {
                summary.push(String($(this).data('id')));
            });

            $('#normal-sortables .qrg-dash-widget').each(function() {
                col1.push(String($(this).data('id')));
            });
            $('#side-sortables .qrg-dash-widget').each(function() {
                col2.push(String($(this).data('id')));
            });
            widgets = col1.concat(col2);

            $('#qrg-toggle-summary input[type="checkbox"]').each(function() {
                if (!this.checked) hiddenSummary.push(this.value);
            });
            $('#qrg-toggle-widgets input[type="checkbox"]').each(function() {
                if (!this.checked) hiddenWidgets.push(this.value);
            });
            $('#qrg-toggle-summary .qrg-size-select').each(function() {
                sizesSummary[String($(this).data('id'))] = String($(this).val());
            });

            return {
                summary: summary,
                widgets: widgets,
                widget_col1: col1,
                widget_col2: col2,
                hidden_summary: hiddenSummary,
                hidden_widgets: hiddenWidgets,
                sizes_summary: sizesSummary,
                sizes_widgets: sizesWidgets
            };
        }

        function saveLayout() {
            var data = collectLayout();
            data.action = 'qrg_save_dashboard_layout';
            data.nonce = nonce;
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data
            }).done(function() {
                var $status = $('#qrg-dash-save-status').prop('hidden', false).show();
                clearTimeout(saveTimer);
                saveTimer = setTimeout(function() {
                    $status.fadeOut(200, function() { $(this).prop('hidden', true); });
                }, 1500);
            });
        }

        function applyVisibility() {
            $('#qrg-toggle-summary input[type="checkbox"]').each(function() {
                var $card = $('#qrg-dash-summary .qrg-dash-summary-card[data-id="' + this.value + '"]');
                if (this.checked) {
                    $card.removeClass('hidden').prop('hidden', false).show();
                } else {
                    $card.addClass('hidden').prop('hidden', true).hide();
                }
            });
            $('#qrg-toggle-widgets input[type="checkbox"]').each(function() {
                var $card = $('.qrg-dash-widget[data-id="' + this.value + '"]');
                if (this.checked) {
                    $card.removeClass('hidden').prop('hidden', false).show();
                } else {
                    $card.addClass('hidden').prop('hidden', true).hide();
                }
            });
        }

        function applySize(group, id, size) {
            if (group !== 'summary') {
                return;
            }
            size = String(size);
            $('#qrg-dash-summary .qrg-dash-summary-card[data-id="' + id + '"]')
                .removeClass(sizeClasses)
                .addClass('qrg-size-' + size)
                .attr('data-size', size);
            $('.qrg-size-select[data-group="summary"][data-id="' + id + '"]').val(size);
        }

        function applyAllSettings() {
            applyVisibility();
            $('#qrg-toggle-summary .qrg-size-select').each(function() {
                applySize('summary', $(this).data('id'), $(this).val());
            });
        }

        $('#qrg-dash-open-settings').on('click', openSettings);
        $('#qrg-dash-close-settings, #qrg-dash-done-settings').on('click', function() {
            applyAllSettings();
            saveLayout();
            closeSettings();
        });
        $backdrop.on('click', closeSettings);
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $drawer.hasClass('is-open')) {
                closeSettings();
            }
        });

        $('#qrg-dash-summary').sortable({
            items: '.qrg-dash-summary-card:not(.hidden)',
            placeholder: 'ui-sortable-placeholder',
            tolerance: 'pointer',
            cursor: 'move',
            start: function(e, ui) {
                ui.placeholder.addClass(ui.item.attr('class')).removeClass('ui-sortable-helper hidden');
            },
            update: saveLayout
        });

        $('.qrg-dash-col').sortable({
            connectWith: '.qrg-dash-col',
            items: '.qrg-dash-widget:not(.hidden)',
            handle: '.hndle, .postbox-header',
            cancel: '.handlediv, .toggle-indicator',
            placeholder: 'ui-sortable-placeholder',
            tolerance: 'pointer',
            cursor: 'move',
            forcePlaceholderSize: true,
            update: saveLayout
        });

        $(document).on('click', '#dashboard-widgets .handlediv', function(e) {
            e.preventDefault();
            var $box = $(this).closest('.postbox');
            var expanded = $(this).attr('aria-expanded') === 'true';
            $box.toggleClass('closed', expanded);
            $(this).attr('aria-expanded', expanded ? 'false' : 'true');
        });

        $('.qrg-dash-toggles').on('change', 'input[type="checkbox"]', function() {
            applyVisibility();
            saveLayout();
        });

        $(document).on('change', '.qrg-size-select', function() {
            applySize($(this).data('group'), $(this).data('id'), String($(this).val()));
            saveLayout();
        });

        applyAllSettings();

        $('#qrg-dash-reset').on('click', function() {
            if (!confirm('Kembalikan layout dashboard ke pengaturan awal?')) {
                return;
            }
            $.post(ajaxurl, {
                action: 'qrg_save_dashboard_layout',
                nonce: nonce,
                summary: ['civitas', 'psb', 'kelas', 'keuangan'],
                widgets: ['inventaris', 'siswa_kelas', 'keuangan', 'psb_widget'],
                widget_col1: ['inventaris', 'siswa_kelas'],
                widget_col2: ['keuangan', 'psb_widget'],
                hidden_summary: [],
                hidden_widgets: [],
                sizes_summary: defaultSizesSummary,
                sizes_widgets: defaultSizesWidgets
            }).done(function() {
                window.location.reload();
            });
        });
    });
    </script>
    <?php
}