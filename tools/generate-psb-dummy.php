<?php
/**
 * Generate 100 data dummy Penerimaan Siswa Baru.
 * Jalankan sekali via WP-CLI / PHP CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Jalankan melalui WordPress (wp eval-file).\n" );
}

$jumlah = 100;

$nama_depan = [
	'Ahmad', 'Budi', 'Citra', 'Dewi', 'Eko', 'Fajar', 'Gita', 'Hadi', 'Indah', 'Joko',
	'Kartika', 'Lestari', 'Made', 'Nina', 'Oscar', 'Putri', 'Rizki', 'Sari', 'Tono', 'Umi',
	'Vina', 'Wawan', 'Yuni', 'Zaki', 'Andi', 'Bayu', 'Chandra', 'Dian', 'Endang', 'Fitri',
];

$nama_belakang = [
	'Saputra', 'Wijaya', 'Nugroho', 'Santoso', 'Pratama', 'Wulandari', 'Rahmawati', 'Kusuma',
	'Hidayat', 'Suryadi', 'Permata', 'Maulana', 'Firmansyah', 'Anggraini', 'Syahputra', 'Lestari',
];

$asal_sekolah = [
	'SDN 1 Surabaya', 'SDN 2 Sidoarjo', 'SD Muhammadiyah 1', 'MI Al-Ikhlas', 'SDN Kebonsari',
	'SDN Wonokromo', 'SDIT Al-Falah', 'SDN Gubeng 3', 'MI Nurul Huda', 'SDN Rungkut 5',
	'SD Aisyiyah', 'SDN Tambaksari 2', 'SDN Kenjeran 1', 'MI Darussalam', 'SDN Jambangan',
];

$kota = [
	'Surabaya', 'Sidoarjo', 'Gresik', 'Mojokerto', 'Lamongan', 'Pasuruan', 'Malang', 'Jombang',
];

$pekerjaan = [
	'PNS', 'Wiraswasta', 'Guru', 'Karyawan Swasta', 'Petani', 'Pedagang', 'Dokter', 'Supir',
	'Buruh', 'Ibu Rumah Tangga',
];

$info_dari = [
	'Media Sosial', 'Teman', 'Keluarga', 'Website Sekolah', 'Brosur', 'Guru SD',
];

$status_list = [ 'Menunggu', 'Menunggu', 'Menunggu', 'Diterima', 'Diterima', 'Ditolak' ];

$alamat_jalan = [
	'Jl. Merdeka', 'Jl. Ahmad Yani', 'Jl. Diponegoro', 'Jl. Sudirman', 'Jl. Pahlawan',
	'Jl. Gatot Subroto', 'Jl. Raya Darmo', 'Jl. Kenjeran', 'Jl. Rungkut Asri', 'Jl. Ngagel Jaya',
];

$jalur_ids = [];
if ( function_exists( 'qrg_get_daftar_jalur' ) ) {
	foreach ( qrg_get_daftar_jalur( true ) as $jalur ) {
		$jalur_ids[] = (int) $jalur->ID;
	}
}
if ( empty( $jalur_ids ) ) {
	$jalur_posts = get_posts( [
		'post_type'      => 'master_jalur',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'fields'         => 'ids',
	] );
	$jalur_ids = array_map( 'intval', $jalur_posts );
}

$custom_fields = get_option( 'qrg_psb_custom_fields', [] );
$tahun         = (int) date( 'Y' );
$created       = 0;
$errors        = 0;

for ( $i = 1; $i <= $jumlah; $i++ ) {
	$nama = $nama_depan[ array_rand( $nama_depan ) ] . ' ' . $nama_belakang[ array_rand( $nama_belakang ) ];
	if ( mt_rand( 0, 1 ) ) {
		$nama .= ' ' . $nama_belakang[ array_rand( $nama_belakang ) ];
	}

	$post_id = wp_insert_post( [
		'post_title'  => $nama,
		'post_type'   => 'master_psb',
		'post_status' => 'publish',
	], true );

	if ( is_wp_error( $post_id ) ) {
		$errors++;
		continue;
	}

	$nisn = sprintf( '00%08d', mt_rand( 10000000, 99999999 ) );
	$tgl  = sprintf(
		'%04d-%02d-%02d',
		mt_rand( 2010, 2015 ),
		mt_rand( 1, 12 ),
		mt_rand( 1, 28 )
	);

	$token  = 'PSB-' . $tahun . '-' . str_pad( (string) mt_rand( 10000, 99999 ), 5, '0', STR_PAD_LEFT ) . $i;
	$status = $status_list[ array_rand( $status_list ) ];
	$jalur  = ! empty( $jalur_ids ) ? $jalur_ids[ array_rand( $jalur_ids ) ] : 0;

	update_post_meta( $post_id, '_psb_nisn', $nisn );
	update_post_meta( $post_id, '_psb_asal_sekolah', $asal_sekolah[ array_rand( $asal_sekolah ) ] );
	update_post_meta( $post_id, '_psb_nilai_rata', number_format( mt_rand( 700, 980 ) / 10, 1, '.', '' ) );
	update_post_meta(
		$post_id,
		'_psb_alamat',
		$alamat_jalan[ array_rand( $alamat_jalan ) ] . ' No. ' . mt_rand( 1, 200 ) . ', ' . $kota[ array_rand( $kota ) ]
	);
	update_post_meta( $post_id, '_psb_tempat_lahir', $kota[ array_rand( $kota ) ] );
	update_post_meta( $post_id, '_psb_tgl_lahir', $tgl );
	update_post_meta(
		$post_id,
		'_psb_nama_ortu',
		$nama_depan[ array_rand( $nama_depan ) ] . ' ' . $nama_belakang[ array_rand( $nama_belakang ) ]
	);
	update_post_meta( $post_id, '_psb_no_telp', '08' . mt_rand( 1000000000, 1999999999 ) );
	update_post_meta( $post_id, '_psb_jalur_id', $jalur );
	update_post_meta( $post_id, '_psb_status', $status );
	update_post_meta( $post_id, '_psb_token', $token );
	update_post_meta( $post_id, '_psb_is_dummy', 1 );

	if ( ! empty( $custom_fields ) && is_array( $custom_fields ) ) {
		foreach ( $custom_fields as $cf ) {
			if ( empty( $cf['key'] ) ) {
				continue;
			}
			$meta_key = '_psb_cf_' . $cf['key'];
			if ( $cf['key'] === 'pekerjaan-orang-tua' ) {
				$value = $pekerjaan[ array_rand( $pekerjaan ) ];
			} elseif ( $cf['key'] === 'informasi-dari' ) {
				$value = $info_dari[ array_rand( $info_dari ) ];
			} elseif ( isset( $cf['type'] ) && $cf['type'] === 'number' ) {
				$value = (string) mt_rand( 1, 100 );
			} else {
				$value = 'Data dummy ' . $i;
			}
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	$created++;
}

WP_CLI::success( "Berhasil membuat {$created} data dummy PSB." . ( $errors ? " Gagal: {$errors}." : '' ) );
