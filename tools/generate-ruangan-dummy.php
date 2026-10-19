<?php
/**
 * Generate 100 data dummy Ruangan (kelas maupun non-kelas).
 * Jalankan: wp eval-file wp-content/plugins/sekolah/tools/generate-ruangan-dummy.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Jalankan melalui WordPress (wp eval-file).\n" );
}

$jumlah = 100;

$gedung_list = [ 'Gedung A', 'Gedung B', 'Gedung C', 'Gedung D', 'Blok Timur', 'Blok Barat' ];

// Template non-kelas (akan digandakan dengan nomor)
$non_kelas_templates = [
	[ 'jenis' => 'Lab', 'nama' => 'Lab Komputer', 'kode' => 'LAB-KOM', 'kap' => [ 20, 40 ], 'ket' => 'Laboratorium komputer untuk praktik TIK.' ],
	[ 'jenis' => 'Lab', 'nama' => 'Lab IPA', 'kode' => 'LAB-IPA', 'kap' => [ 24, 36 ], 'ket' => 'Laboratorium IPA / sains.' ],
	[ 'jenis' => 'Lab', 'nama' => 'Lab Bahasa', 'kode' => 'LAB-BHS', 'kap' => [ 20, 32 ], 'ket' => 'Laboratorium bahasa.' ],
	[ 'jenis' => 'Lab', 'nama' => 'Lab Multimedia', 'kode' => 'LAB-MM', 'kap' => [ 16, 30 ], 'ket' => 'Studio multimedia.' ],
	[ 'jenis' => 'Kantor', 'nama' => 'Ruang Guru', 'kode' => 'KTR-GRU', 'kap' => [ 10, 25 ], 'ket' => 'Ruang kerja guru.' ],
	[ 'jenis' => 'Kantor', 'nama' => 'Ruang Kepala Sekolah', 'kode' => 'KTR-KS', 'kap' => [ 4, 10 ], 'ket' => 'Kantor kepala sekolah.' ],
	[ 'jenis' => 'Kantor', 'nama' => 'Ruang Tata Usaha', 'kode' => 'KTR-TU', 'kap' => [ 6, 15 ], 'ket' => 'Administrasi / tata usaha.' ],
	[ 'jenis' => 'Kantor', 'nama' => 'Ruang BK', 'kode' => 'KTR-BK', 'kap' => [ 4, 8 ], 'ket' => 'Bimbingan konseling.' ],
	[ 'jenis' => 'Perpus', 'nama' => 'Perpustakaan', 'kode' => 'PERPUS', 'kap' => [ 30, 80 ], 'ket' => 'Ruang perpustakaan.' ],
	[ 'jenis' => 'Perpus', 'nama' => 'Ruang Baca', 'kode' => 'BACA', 'kap' => [ 20, 40 ], 'ket' => 'Area baca siswa.' ],
	[ 'jenis' => 'Olahraga', 'nama' => 'Lapangan Basket', 'kode' => 'OLA-BSK', 'kap' => [ 50, 100 ], 'ket' => 'Lapangan basket outdoor.' ],
	[ 'jenis' => 'Olahraga', 'nama' => 'Lapangan Futsal', 'kode' => 'OLA-FTS', 'kap' => [ 40, 80 ], 'ket' => 'Lapangan futsal.' ],
	[ 'jenis' => 'Olahraga', 'nama' => 'Ruang Olahraga', 'kode' => 'OLA-INR', 'kap' => [ 30, 60 ], 'ket' => 'Ruang olahraga indoor.' ],
	[ 'jenis' => 'Gudang', 'nama' => 'Gudang Alat', 'kode' => 'GDG-ALT', 'kap' => [ 0, 5 ], 'ket' => 'Penyimpanan alat sekolah.' ],
	[ 'jenis' => 'Gudang', 'nama' => 'Gudang Arsip', 'kode' => 'GDG-ARS', 'kap' => [ 0, 5 ], 'ket' => 'Penyimpanan arsip.' ],
	[ 'jenis' => 'Aula', 'nama' => 'Aula Serbaguna', 'kode' => 'AULA', 'kap' => [ 100, 300 ], 'ket' => 'Aula kegiatan sekolah.' ],
	[ 'jenis' => 'Aula', 'nama' => 'Ruang Serbaguna', 'kode' => 'SERBA', 'kap' => [ 60, 150 ], 'ket' => 'Ruang pertemuan serbaguna.' ],
	[ 'jenis' => 'Lainnya', 'nama' => 'Musholla', 'kode' => 'MSH', 'kap' => [ 40, 100 ], 'ket' => 'Tempat ibadah.' ],
	[ 'jenis' => 'Lainnya', 'nama' => 'Kantin', 'kode' => 'KNT', 'kap' => [ 30, 80 ], 'ket' => 'Kantin sekolah.' ],
	[ 'jenis' => 'Lainnya', 'nama' => 'UKS', 'kode' => 'UKS', 'kap' => [ 4, 10 ], 'ket' => 'Unit kesehatan sekolah.' ],
	[ 'jenis' => 'Lainnya', 'nama' => 'Toilet Toilet', 'kode' => 'WC', 'kap' => [ 0, 0 ], 'ket' => 'Fasilitas toilet.' ],
	[ 'jenis' => 'Lainnya', 'nama' => 'Lobby', 'kode' => 'LBY', 'kap' => [ 10, 30 ], 'ket' => 'Lobby / area depan.' ],
];

$kelas_ids = [];
if ( function_exists( 'qrg_get_daftar_kelas' ) ) {
	foreach ( qrg_get_daftar_kelas() as $k ) {
		$kelas_ids[] = (int) $k->ID;
	}
}

$created = 0;
$errors  = 0;

// Target: ~60 ruang kelas + ~40 non-kelas = 100
$jumlah_kelas_ruangan = 60;
$jumlah_non_kelas     = $jumlah - $jumlah_kelas_ruangan;

WP_CLI::log( "Membuat {$jumlah} ruangan dummy ({$jumlah_kelas_ruangan} kelas + {$jumlah_non_kelas} lainnya)..." );

// --- Ruang Kelas ---
for ( $i = 1; $i <= $jumlah_kelas_ruangan; $i++ ) {
	$nomor   = str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
	$nama    = 'Ruang Kelas ' . $nomor;
	$kode    = 'RK-' . $nomor;
	$gedung  = $gedung_list[ ( $i - 1 ) % count( $gedung_list ) ];
	$lantai  = (string) ( ( ( $i - 1 ) % 3 ) + 1 );
	$kap     = mt_rand( 30, 40 );
	$kelas_id = ! empty( $kelas_ids ) ? $kelas_ids[ ( $i - 1 ) % count( $kelas_ids ) ] : 0;

	$post_id = wp_insert_post( [
		'post_title'  => $nama,
		'post_type'   => 'master_ruangan',
		'post_status' => 'publish',
	], true );

	if ( is_wp_error( $post_id ) ) {
		$errors++;
		continue;
	}

	update_post_meta( $post_id, '_ruangan_kode', $kode );
	update_post_meta( $post_id, '_ruangan_jenis', 'Kelas' );
	update_post_meta( $post_id, '_ruangan_gedung', $gedung );
	update_post_meta( $post_id, '_ruangan_lantai', $lantai );
	update_post_meta( $post_id, '_ruangan_kapasitas', $kap );
	update_post_meta( $post_id, '_ruangan_kelas_id', $kelas_id );
	update_post_meta( $post_id, '_ruangan_keterangan', 'Ruang kelas reguler untuk kegiatan belajar mengajar.' );
	update_post_meta( $post_id, '_ruangan_is_dummy', 1 );

	$created++;
}

// --- Ruangan non-kelas ---
for ( $i = 1; $i <= $jumlah_non_kelas; $i++ ) {
	$tpl     = $non_kelas_templates[ ( $i - 1 ) % count( $non_kelas_templates ) ];
	$batch   = (int) floor( ( $i - 1 ) / count( $non_kelas_templates ) ) + 1;
	$suffix  = $batch > 1 ? ' ' . str_pad( (string) $batch, 2, '0', STR_PAD_LEFT ) : ( $i <= count( $non_kelas_templates ) ? '' : ' ' . $batch );
	// Pastikan unik: nomor urut global non-kelas
	$nomor   = str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
	$nama    = $tpl['nama'] . ' ' . $nomor;
	$kode    = $tpl['kode'] . '-' . $nomor;
	$gedung  = $gedung_list[ array_rand( $gedung_list ) ];
	$lantai  = (string) mt_rand( 1, 3 );
	$min_kap = (int) $tpl['kap'][0];
	$max_kap = (int) $tpl['kap'][1];
	$kap     = ( $max_kap > $min_kap ) ? mt_rand( $min_kap, $max_kap ) : $min_kap;

	$post_id = wp_insert_post( [
		'post_title'  => $nama,
		'post_type'   => 'master_ruangan',
		'post_status' => 'publish',
	], true );

	if ( is_wp_error( $post_id ) ) {
		$errors++;
		continue;
	}

	update_post_meta( $post_id, '_ruangan_kode', $kode );
	update_post_meta( $post_id, '_ruangan_jenis', $tpl['jenis'] );
	update_post_meta( $post_id, '_ruangan_gedung', $gedung );
	update_post_meta( $post_id, '_ruangan_lantai', $lantai );
	update_post_meta( $post_id, '_ruangan_kapasitas', $kap );
	update_post_meta( $post_id, '_ruangan_kelas_id', 0 );
	update_post_meta( $post_id, '_ruangan_keterangan', $tpl['ket'] );
	update_post_meta( $post_id, '_ruangan_is_dummy', 1 );

	$created++;
}

WP_CLI::success( sprintf(
	'Selesai. Dibuat: %d ruangan | Error: %d',
	$created,
	$errors
) );
