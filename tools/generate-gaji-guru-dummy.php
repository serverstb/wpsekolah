<?php
/**
 * Generate Gaji Guru dummy sesuai jumlah guru (1 slip per guru untuk periode berjalan).
 * Jalankan:
 *   /usr/local/lsws/lsphp74/bin/php /usr/bin/wp eval-file wp-content/plugins/sekolah/tools/generate-gaji-guru-dummy.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Jalankan melalui WordPress (wp eval-file).\n" );
}

$guru_ids = get_posts( [
	'post_type'      => 'master_guru',
	'posts_per_page' => -1,
	'post_status'    => 'publish',
	'fields'         => 'ids',
	'orderby'        => 'title',
	'order'          => 'ASC',
] );

if ( empty( $guru_ids ) ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( 'Tidak ada data guru. Generate Data Guru dulu.' );
	}
	exit( "Tidak ada data guru.\n" );
}

$bulan = current_time( 'm' );
$tahun = (int) current_time( 'Y' );
$rumus = function_exists( 'qrg_get_gaji_rumus' ) ? qrg_get_gaji_rumus() : [];
$columns = ! empty( $rumus['columns'] ) ? $rumus['columns'] : ( function_exists( 'qrg_gaji_default_columns' ) ? qrg_gaji_default_columns() : [] );
$bulan_labels = function_exists( 'qrg_gaji_guru_bulan_options' ) ? qrg_gaji_guru_bulan_options() : [ $bulan => $bulan ];
$periode_label = ( isset( $bulan_labels[ $bulan ] ) ? $bulan_labels[ $bulan ] : $bulan ) . ' ' . $tahun;

$created = 0;
$skipped = 0;
$errors  = 0;
$status_pool = [ 'Belum Dibayar', 'Belum Dibayar', 'Belum Dibayar', 'Dibayar' ];

foreach ( $guru_ids as $guru_id ) {
	$guru_id = (int) $guru_id;

	// Hindari duplikat untuk periode yang sama
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
		$skipped++;
		continue;
	}

	$kehadiran = function_exists( 'qrg_gaji_hitung_kehadiran' )
		? qrg_gaji_hitung_kehadiran( $guru_id, $bulan, $tahun )
		: [ 'hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpha' => 0 ];

	// Override nilai tetap dari rumus (sedikit variasi agar tidak identik)
	$overrides = [];
	foreach ( $columns as $col ) {
		if ( empty( $col['tipe'] ) || $col['tipe'] !== 'tetap' ) {
			continue;
		}
		$base = (int) $col['nilai'];
		// Variasi ±5% untuk gaji pokok / tunjangan tetap
		$factor = 1 + ( ( rand( -5, 5 ) ) / 100 );
		$overrides[ $col['id'] ] = max( 0, (int) round( $base * $factor / 1000 ) * 1000 );
	}

	$hitung = function_exists( 'qrg_gaji_hitung_komponen' )
		? qrg_gaji_hitung_komponen( [
			'overrides' => $overrides,
			'hadir'     => $kehadiran['hadir'],
			'izin'      => $kehadiran['izin'],
			'sakit'     => $kehadiran['sakit'],
			'alpha'     => $kehadiran['alpha'],
			'rumus'     => $rumus,
		] )
		: [
			'items'             => [],
			'total'             => 0,
			'tunjangan_total'   => 0,
			'potongan_total'    => 0,
			'gaji_pokok'        => isset( $overrides['gaji_pokok'] ) ? $overrides['gaji_pokok'] : 0,
			'tunjangan_jabatan' => isset( $overrides['tunjangan_jabatan'] ) ? $overrides['tunjangan_jabatan'] : 0,
			'tunjangan_hadir'   => 0,
			'potongan_absen'    => 0,
		];

	$nama_guru = get_the_title( $guru_id );
	$title     = sprintf( 'Gaji %s — %s', $nama_guru, $periode_label );
	$status    = $status_pool[ array_rand( $status_pool ) ];

	$post_id = wp_insert_post( [
		'post_title'  => $title,
		'post_type'   => 'master_gaji_guru',
		'post_status' => 'publish',
	], true );

	if ( is_wp_error( $post_id ) ) {
		$errors++;
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::warning( 'Gagal: ' . $nama_guru . ' — ' . $post_id->get_error_message() );
		}
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
	update_post_meta( $post_id, '_gaji_keterangan', 'Data dummy periode ' . $periode_label );
	update_post_meta( $post_id, '_gaji_is_dummy', 1 );

	$created++;
	if ( class_exists( 'WP_CLI' ) && ( $created % 20 === 0 || $created === count( $guru_ids ) ) ) {
		WP_CLI::log( sprintf(
			'[%d/%d] %s — Total Rp %s (%s)',
			$created,
			count( $guru_ids ),
			$nama_guru,
			number_format( $hitung['total'], 0, ',', '.' ),
			$status
		) );
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::success( sprintf(
		'Selesai. Periode: %s | Dibuat: %d | Dilewati: %d | Error: %d | Total guru: %d',
		$periode_label,
		$created,
		$skipped,
		$errors,
		count( $guru_ids )
	) );
} else {
	echo "Selesai. Dibuat: {$created} | Dilewati: {$skipped} | Error: {$errors}\n";
}
