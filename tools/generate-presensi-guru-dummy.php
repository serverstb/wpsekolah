<?php
/**
 * Generate 200 data dummy Presensi Guru.
 * Jalankan:
 *   /usr/local/lsws/lsphp74/bin/php /usr/bin/wp eval-file wp-content/plugins/sekolah/tools/generate-presensi-guru-dummy.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Jalankan melalui WordPress (wp eval-file).\n" );
}

$target = 200;

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

$status_pool = [
	'Hadir', 'Hadir', 'Hadir', 'Hadir', 'Hadir', 'Hadir', 'Hadir',
	'Izin', 'Izin',
	'Sakit', 'Sakit',
	'Alpha',
];

$jam_masuk_pool  = [ '06:45', '07:00', '07:05', '07:10', '07:15', '07:30' ];
$jam_pulang_pool = [ '13:00', '14:00', '14:30', '15:00', '15:30', '16:00' ];

$ket_izin  = [ 'Urusan keluarga', 'Mengurus administrasi', 'Izin keperluan pribadi', 'Mengantar keluarga' ];
$ket_sakit = [ 'Demam', 'Flu', 'Sakit kepala', 'Istirahat atas saran dokter' ];
$ket_alpha = [ 'Tanpa keterangan', 'Tidak hadir tanpa izin' ];

$created = 0;
$errors  = 0;
$seen    = []; // hindari duplikat guru+tanggal

// Rentang tanggal: 90 hari terakhir (hari kerja saja)
$dates = [];
$ts    = strtotime( current_time( 'Y-m-d' ) );
for ( $d = 0; $d < 120 && count( $dates ) < 90; $d++ ) {
	$day = date( 'Y-m-d', strtotime( "-{$d} days", $ts ) );
	$w   = (int) date( 'N', strtotime( $day ) ); // 1=Sen … 7=Min
	if ( $w <= 5 ) {
		$dates[] = $day;
	}
}

$max_attempts = $target * 20;
$attempts     = 0;

while ( $created < $target && $attempts < $max_attempts ) {
	$attempts++;

	$guru_id = (int) $guru_ids[ array_rand( $guru_ids ) ];
	$tanggal = $dates[ array_rand( $dates ) ];
	$key     = $guru_id . '|' . $tanggal;

	if ( isset( $seen[ $key ] ) ) {
		continue;
	}
	$seen[ $key ] = true;

	$status = $status_pool[ array_rand( $status_pool ) ];

	$jam_masuk  = '';
	$jam_pulang = '';
	$keterangan = '';

	if ( $status === 'Hadir' ) {
		$jam_masuk  = $jam_masuk_pool[ array_rand( $jam_masuk_pool ) ];
		$jam_pulang = $jam_pulang_pool[ array_rand( $jam_pulang_pool ) ];
	} elseif ( $status === 'Izin' ) {
		$keterangan = $ket_izin[ array_rand( $ket_izin ) ];
	} elseif ( $status === 'Sakit' ) {
		$keterangan = $ket_sakit[ array_rand( $ket_sakit ) ];
	} else {
		$keterangan = $ket_alpha[ array_rand( $ket_alpha ) ];
	}

	$nama_guru = get_the_title( $guru_id );
	$tgl_label = date_i18n( 'd M Y', strtotime( $tanggal ) );
	$title     = sprintf( 'Presensi %s — %s (%s)', $nama_guru, $tgl_label, $status );

	$post_id = wp_insert_post( [
		'post_title'  => $title,
		'post_type'   => 'master_presensi_guru',
		'post_status' => 'publish',
	], true );

	if ( is_wp_error( $post_id ) ) {
		$errors++;
		unset( $seen[ $key ] );
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::warning( 'Gagal: ' . $post_id->get_error_message() );
		}
		continue;
	}

	update_post_meta( $post_id, '_presensi_guru_id', $guru_id );
	update_post_meta( $post_id, '_presensi_tanggal', $tanggal );
	update_post_meta( $post_id, '_presensi_status', $status );
	update_post_meta( $post_id, '_presensi_jam_masuk', $jam_masuk );
	update_post_meta( $post_id, '_presensi_jam_pulang', $jam_pulang );
	update_post_meta( $post_id, '_presensi_keterangan', $keterangan );
	update_post_meta( $post_id, '_presensi_is_dummy', 1 );

	$created++;
	if ( class_exists( 'WP_CLI' ) && ( $created % 25 === 0 || $created === $target ) ) {
		WP_CLI::log( sprintf( '[%d/%d] Dibuat: %s', $created, $target, $title ) );
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::success( sprintf(
		'Selesai. Dibuat: %d presensi dummy | Error: %d | Guru sumber: %d',
		$created,
		$errors,
		count( $guru_ids )
	) );
} else {
	echo "Selesai. Dibuat: {$created} | Error: {$errors}\n";
}
