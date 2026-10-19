<?php
/**
 * Generate data dummy Mata Pelajaran.
 * Jalankan: wp eval-file wp-content/plugins/sekolah/tools/generate-mapel-dummy.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Jalankan melalui WordPress (wp eval-file).\n" );
}

$mapel_list = [
	[ 'nama' => 'Pendidikan Agama', 'kode' => 'PAI', 'deskripsi' => 'Mata pelajaran pendidikan agama dan budi pekerti.' ],
	[ 'nama' => 'Pendidikan Pancasila dan Kewarganegaraan', 'kode' => 'PPKn', 'deskripsi' => 'Mata pelajaran PPKn / kewarganegaraan.' ],
	[ 'nama' => 'Bahasa Indonesia', 'kode' => 'BIN', 'deskripsi' => 'Mata pelajaran bahasa dan sastra Indonesia.' ],
	[ 'nama' => 'Matematika', 'kode' => 'MTK', 'deskripsi' => 'Mata pelajaran matematika wajib.' ],
	[ 'nama' => 'Ilmu Pengetahuan Alam', 'kode' => 'IPA', 'deskripsi' => 'Mata pelajaran IPA terpadu.' ],
	[ 'nama' => 'Ilmu Pengetahuan Sosial', 'kode' => 'IPS', 'deskripsi' => 'Mata pelajaran IPS terpadu.' ],
	[ 'nama' => 'Bahasa Inggris', 'kode' => 'BIG', 'deskripsi' => 'Mata pelajaran bahasa Inggris.' ],
	[ 'nama' => 'Seni Budaya', 'kode' => 'SBK', 'deskripsi' => 'Mata pelajaran seni budaya dan prakarya.' ],
	[ 'nama' => 'Pendidikan Jasmani, Olahraga dan Kesehatan', 'kode' => 'PJOK', 'deskripsi' => 'Mata pelajaran olahraga dan kesehatan.' ],
	[ 'nama' => 'Prakarya / Informatika', 'kode' => 'TIK', 'deskripsi' => 'Mata pelajaran prakarya dan informatika.' ],
	[ 'nama' => 'Bahasa Jawa', 'kode' => 'BJW', 'deskripsi' => 'Muatan lokal bahasa daerah.' ],
	[ 'nama' => 'Fisika', 'kode' => 'FIS', 'deskripsi' => 'Mata pelajaran fisika (peminatan).' ],
	[ 'nama' => 'Kimia', 'kode' => 'KIM', 'deskripsi' => 'Mata pelajaran kimia (peminatan).' ],
	[ 'nama' => 'Biologi', 'kode' => 'BIO', 'deskripsi' => 'Mata pelajaran biologi (peminatan).' ],
	[ 'nama' => 'Sejarah', 'kode' => 'SEJ', 'deskripsi' => 'Mata pelajaran sejarah Indonesia.' ],
	[ 'nama' => 'Geografi', 'kode' => 'GEO', 'deskripsi' => 'Mata pelajaran geografi.' ],
	[ 'nama' => 'Ekonomi', 'kode' => 'EKO', 'deskripsi' => 'Mata pelajaran ekonomi.' ],
	[ 'nama' => 'Sosiologi', 'kode' => 'SOS', 'deskripsi' => 'Mata pelajaran sosiologi.' ],
	[ 'nama' => 'Bahasa Arab', 'kode' => 'BAR', 'deskripsi' => 'Mata pelajaran bahasa Arab.' ],
	[ 'nama' => 'Bimbingan Konseling', 'kode' => 'BK', 'deskripsi' => 'Bimbingan dan konseling siswa.' ],
];

$created = 0;
$skipped = 0;
$errors  = 0;

foreach ( $mapel_list as $item ) {
	// Hindari duplikat berdasarkan judul yang sama
	$existing = get_posts( [
		'post_type'      => 'master_mapel',
		'posts_per_page' => 1,
		'post_status'    => 'any',
		'title'          => $item['nama'],
		'fields'         => 'ids',
	] );

	// Fallback cek manual jika filter title tidak andal
	if ( empty( $existing ) ) {
		$all = get_posts( [
			'post_type'      => 'master_mapel',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		] );
		foreach ( $all as $p ) {
			if ( strcasecmp( $p->post_title, $item['nama'] ) === 0 ) {
				$existing = [ $p->ID ];
				break;
			}
		}
	}

	if ( ! empty( $existing ) ) {
		$skipped++;
		WP_CLI::log( 'Lewati (sudah ada): ' . $item['nama'] );
		continue;
	}

	$post_id = wp_insert_post( [
		'post_title'  => $item['nama'],
		'post_type'   => 'master_mapel',
		'post_status' => 'publish',
	], true );

	if ( is_wp_error( $post_id ) ) {
		$errors++;
		WP_CLI::warning( 'Gagal: ' . $item['nama'] . ' — ' . $post_id->get_error_message() );
		continue;
	}

	update_post_meta( $post_id, '_mapel_kode', $item['kode'] );
	update_post_meta( $post_id, '_mapel_deskripsi', $item['deskripsi'] );
	update_post_meta( $post_id, '_mapel_status', 'Aktif' );
	update_post_meta( $post_id, '_mapel_is_dummy', 1 );

	$created++;
	WP_CLI::log( 'Dibuat: ' . $item['nama'] . ' (' . $item['kode'] . ') #' . $post_id );
}

WP_CLI::success( sprintf(
	'Selesai. Dibuat: %d | Dilewati: %d | Error: %d',
	$created,
	$skipped,
	$errors
) );
