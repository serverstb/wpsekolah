<?php
/**
 * Generate 80 data dummy Guru.
 * Jalankan:
 *   /usr/local/lsws/lsphp74/bin/php /usr/bin/wp eval-file wp-content/plugins/sekolah/tools/generate-guru-dummy.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Jalankan melalui WordPress (wp eval-file).\n" );
}

$target = 80;

$nama_depan = [
	'Ahmad', 'Budi', 'Citra', 'Dewi', 'Eko', 'Fitri', 'Gita', 'Hadi', 'Indah', 'Joko',
	'Kartika', 'Lestari', 'Maya', 'Nugroho', 'Oktavia', 'Putri', 'Rizki', 'Sari', 'Taufik', 'Umi',
	'Vina', 'Wawan', 'Yuni', 'Zainal', 'Agus', 'Bayu', 'Candra', 'Dian', 'Endang', 'Fajar',
	'Gunawan', 'Hasan', 'Irfan', 'Jamilah', 'Kurnia', 'Lina', 'Mulyadi', 'Nina', 'Omar', 'Prasetyo',
];

$nama_belakang = [
	'Saputra', 'Wijaya', 'Pratama', 'Santoso', 'Kusuma', 'Hidayat', 'Rahayu', 'Permata', 'Nugraha', 'Suryani',
	'Mahendra', 'Anggraini', 'Setiawan', 'Wulandari', 'Firmansyah', 'Handayani', 'Kurniawan', 'Safitri', 'Hakim', 'Puspitasari',
];

$gelar = [ '', 'S.Pd.', 'S.Pd.', 'S.Pd.', 'M.Pd.', 'S.Si.', 'S.Kom.', 'S.S.', 'M.Si.' ];

$jabatan_list = [
	'Guru Mapel', 'Guru Mapel', 'Guru Mapel', 'Guru Mapel',
	'Wali Kelas', 'Guru BK', 'Kepala Lab', 'Guru Olahraga',
	'Guru Agama', 'Koordinator Kurikulum', 'Guru Bahasa',
];

$pendidikan_list = [ 'D3', 'S1 / D4', 'S1 / D4', 'S1 / D4', 'S2', 'S3' ];

$bank_list = [ 'BCA', 'Bank Mandiri', 'BRI', 'BNI', 'BTN', 'Bank Jatim', 'Bank Syariah Indonesia' ];

$kota_list = [
	'Sidoarjo', 'Surabaya', 'Gresik', 'Mojokerto', 'Pasuruan', 'Malang', 'Jombang', 'Lamongan',
];

$jalan_list = [
	'Jl. Merdeka', 'Jl. Ahmad Yani', 'Jl. Diponegoro', 'Jl. Sudirman', 'Jl. Gatot Subroto',
	'Jl. Pahlawan', 'Jl. Kartini', 'Jl. Imam Bonjol', 'Jl. Raya Waru', 'Jl. Pemuda',
];

$kelas_ids = [];
if ( function_exists( 'qrg_get_daftar_kelas' ) ) {
	foreach ( qrg_get_daftar_kelas() as $kelas ) {
		$kelas_ids[] = (int) $kelas->ID;
	}
}

$created = 0;
$errors  = 0;
$used_names = [];

for ( $i = 1; $i <= $target; $i++ ) {
	// Bangun nama unik
	$attempts = 0;
	do {
		$depan    = $nama_depan[ array_rand( $nama_depan ) ];
		$belakang = $nama_belakang[ array_rand( $nama_belakang ) ];
		$g        = $gelar[ array_rand( $gelar ) ];
		$nama     = trim( $depan . ' ' . $belakang . ( $g ? ' ' . $g : '' ) );
		$key      = strtolower( $nama );
		$attempts++;
		if ( $attempts > 20 ) {
			$nama = $depan . ' ' . $belakang . ' ' . $i;
			$key  = strtolower( $nama );
			break;
		}
	} while ( isset( $used_names[ $key ] ) );
	$used_names[ $key ] = true;

	$post_id = wp_insert_post( [
		'post_title'  => $nama,
		'post_type'   => 'master_guru',
		'post_status' => 'publish',
	], true );

	if ( is_wp_error( $post_id ) ) {
		$errors++;
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::warning( 'Gagal #' . $i . ': ' . $post_id->get_error_message() );
		}
		continue;
	}

	$nuptk = '19' . str_pad( (string) ( 8000000000 + $i ), 10, '0', STR_PAD_LEFT );
	$nip   = '19' . str_pad( (string) ( 197001000000 + $i ), 12, '0', STR_PAD_LEFT );
	$tahun_lahir = rand( 1970, 1998 );
	$bulan       = str_pad( (string) rand( 1, 12 ), 2, '0', STR_PAD_LEFT );
	$hari        = str_pad( (string) rand( 1, 28 ), 2, '0', STR_PAD_LEFT );
	$sejak       = rand( max( 2000, $tahun_lahir + 22 ), 2024 );
	$pendidikan  = $pendidikan_list[ array_rand( $pendidikan_list ) ];
	$jabatan     = $jabatan_list[ array_rand( $jabatan_list ) ];
	$telp        = '08' . str_pad( (string) rand( 100000000, 999999999 ), 9, '0', STR_PAD_LEFT );
	$bank        = $bank_list[ array_rand( $bank_list ) ];
	$rek         = (string) rand( 1000000000, 9999999999 );
	$alamat      = $jalan_list[ array_rand( $jalan_list ) ] . ' No. ' . rand( 1, 200 ) . ', ' . $kota_list[ array_rand( $kota_list ) ];

	update_post_meta( $post_id, '_guru_nuptk', $nuptk );
	update_post_meta( $post_id, '_guru_nip', $nip );
	update_post_meta( $post_id, '_guru_alamat', $alamat );
	update_post_meta( $post_id, '_guru_tgl_lahir', $tahun_lahir . '-' . $bulan . '-' . $hari );
	update_post_meta( $post_id, '_guru_sejak', (string) $sejak );
	update_post_meta( $post_id, '_guru_pendidikan', $pendidikan );
	update_post_meta( $post_id, '_guru_jabatan', $jabatan );
	update_post_meta( $post_id, '_guru_no_telp', $telp );
	update_post_meta( $post_id, '_guru_nama_bank', $bank );
	update_post_meta( $post_id, '_guru_no_rek', $rek );
	update_post_meta( $post_id, '_guru_is_dummy', 1 );

	// Assign 1–3 kelas diampu secara acak jika ada data kelas
	if ( ! empty( $kelas_ids ) ) {
		$shuffled = $kelas_ids;
		shuffle( $shuffled );
		$picked = array_slice( $shuffled, 0, min( count( $shuffled ), rand( 1, 3 ) ) );
		update_post_meta( $post_id, '_guru_kelas_ids', array_values( array_map( 'intval', $picked ) ) );
	}

	$created++;
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( sprintf( '[%d/%d] Dibuat: %s (#%d)', $created, $target, $nama, $post_id ) );
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::success( sprintf( 'Selesai. Dibuat: %d guru dummy | Error: %d', $created, $errors ) );
} else {
	echo "Selesai. Dibuat: {$created} guru dummy | Error: {$errors}\n";
}
