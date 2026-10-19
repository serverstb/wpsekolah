<?php
/**
 * Generate data dummy Jadwal Mata Pelajaran.
 * Jalankan: wp eval-file wp-content/plugins/sekolah/tools/generate-jadwal-dummy.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Jalankan melalui WordPress (wp eval-file).\n" );
}

$mapel_ids = [];
if ( function_exists( 'qrg_get_daftar_mapel' ) ) {
	foreach ( qrg_get_daftar_mapel( true ) as $m ) {
		$mapel_ids[] = (int) $m->ID;
	}
}

$guru_ids = [];
if ( function_exists( 'qrg_get_daftar_guru' ) ) {
	foreach ( qrg_get_daftar_guru() as $g ) {
		$guru_ids[] = (int) $g->ID;
	}
}

$kelas_ids = [];
if ( function_exists( 'qrg_get_daftar_kelas' ) ) {
	foreach ( qrg_get_daftar_kelas() as $k ) {
		$kelas_ids[] = (int) $k->ID;
	}
}

$ruangan_kelas_ids = get_posts( [
	'post_type'      => 'master_ruangan',
	'posts_per_page' => -1,
	'post_status'    => 'publish',
	'fields'         => 'ids',
	'meta_query'     => [
		[
			'key'   => '_ruangan_jenis',
			'value' => 'Kelas',
		],
	],
] );
$ruangan_kelas_ids = array_map( 'intval', $ruangan_kelas_ids );

$ruangan_lain_ids = get_posts( [
	'post_type'      => 'master_ruangan',
	'posts_per_page' => -1,
	'post_status'    => 'publish',
	'fields'         => 'ids',
	'meta_query'     => [
		[
			'key'     => '_ruangan_jenis',
			'value'   => 'Kelas',
			'compare' => '!=',
		],
	],
] );
$ruangan_lain_ids = array_map( 'intval', $ruangan_lain_ids );

if ( empty( $mapel_ids ) ) {
	WP_CLI::error( 'Belum ada Mata Pelajaran. Generate mapel dulu.' );
}
if ( empty( $kelas_ids ) ) {
	WP_CLI::error( 'Belum ada Data Kelas.' );
}

$hari_list = [ 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat' ];

$jam_slots = [
	[ '07:00', '07:45' ],
	[ '07:45', '08:30' ],
	[ '08:30', '09:15' ],
	[ '09:30', '10:15' ],
	[ '10:15', '11:00' ],
	[ '11:00', '11:45' ],
];

// Target ~100 jadwal: isi slot untuk tiap kelas di hari kerja
$target   = 100;
$created  = 0;
$errors   = 0;
$mapel_i  = 0;
$guru_i   = 0;

WP_CLI::log( sprintf(
	'Membuat jadwal dummy (mapel=%d, guru=%d, kelas=%d, ruangan kelas=%d)...',
	count( $mapel_ids ),
	count( $guru_ids ),
	count( $kelas_ids ),
	count( $ruangan_kelas_ids )
) );

foreach ( $kelas_ids as $kelas_idx => $kelas_id ) {
	if ( $created >= $target ) {
		break;
	}

	// Prefer ruangan kelas yang terkait kelas ini, fallback round-robin
	$ruangan_prefer = 0;
	foreach ( $ruangan_kelas_ids as $rid ) {
		$rel = (int) get_post_meta( $rid, '_ruangan_kelas_id', true );
		if ( $rel === (int) $kelas_id ) {
			$ruangan_prefer = $rid;
			break;
		}
	}
	if ( ! $ruangan_prefer && ! empty( $ruangan_kelas_ids ) ) {
		$ruangan_prefer = $ruangan_kelas_ids[ $kelas_idx % count( $ruangan_kelas_ids ) ];
	}

	foreach ( $hari_list as $hari ) {
		if ( $created >= $target ) {
			break 2;
		}

		// 3–4 slot per hari per kelas agar total mendekati 100
		$slot_count = ( $kelas_idx % 2 === 0 ) ? 4 : 3;
		$slots      = array_slice( $jam_slots, 0, $slot_count );

		foreach ( $slots as $slot_idx => $slot ) {
			if ( $created >= $target ) {
				break 3;
			}

			$mapel_id = $mapel_ids[ $mapel_i % count( $mapel_ids ) ];
			$mapel_i++;

			$guru_id = ! empty( $guru_ids ) ? $guru_ids[ $guru_i % count( $guru_ids ) ] : 0;
			$guru_i++;

			// Sesekali pakai lab/aula untuk variasi
			$ruangan_id = $ruangan_prefer;
			if ( $slot_idx === 0 && ! empty( $ruangan_lain_ids ) && mt_rand( 0, 4 ) === 0 ) {
				$ruangan_id = $ruangan_lain_ids[ array_rand( $ruangan_lain_ids ) ];
			}

			$jam_mulai   = $slot[0];
			$jam_selesai = $slot[1];

			$title = function_exists( 'qrg_jadwal_build_title' )
				? qrg_jadwal_build_title( $mapel_id, $hari, $jam_mulai, $jam_selesai )
				: ( get_the_title( $mapel_id ) . ' — ' . $hari . ' (' . $jam_mulai . '–' . $jam_selesai . ')' );

			$post_id = wp_insert_post( [
				'post_title'  => $title,
				'post_type'   => 'master_jadwal',
				'post_status' => 'publish',
			], true );

			if ( is_wp_error( $post_id ) ) {
				$errors++;
				continue;
			}

			update_post_meta( $post_id, '_jadwal_mapel_id', $mapel_id );
			update_post_meta( $post_id, '_jadwal_guru_id', $guru_id );
			update_post_meta( $post_id, '_jadwal_kelas_id', $kelas_id );
			update_post_meta( $post_id, '_jadwal_ruangan_id', $ruangan_id );
			update_post_meta( $post_id, '_jadwal_hari', $hari );
			update_post_meta( $post_id, '_jadwal_jam_mulai', $jam_mulai );
			update_post_meta( $post_id, '_jadwal_jam_selesai', $jam_selesai );
			update_post_meta( $post_id, '_jadwal_keterangan', 'Data dummy jadwal pelajaran.' );
			update_post_meta( $post_id, '_jadwal_is_dummy', 1 );

			$created++;
		}
	}
}

WP_CLI::success( sprintf(
	'Selesai. Dibuat: %d jadwal | Error: %d',
	$created,
	$errors
) );
