<?php
/**
 * Setting API pembayaran — kredensial & REST untuk integrasi gateway/aplikasi eksternal.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Menu Setting di antara Dashboard dan Bantuan.
 */
add_action( 'admin_menu', 'qrg_payment_api_settings_menu', 27 );
function qrg_payment_api_settings_menu() {
    add_submenu_page(
        'master-data-plugin',
        'Setting API Pembayaran',
        'Setting',
        'manage_options',
        'qrg-setting',
        'qrg_payment_api_settings_page'
    );
}

function qrg_payment_api_is_enabled() {
    return get_option( 'qrg_payment_api_enabled', '' ) === '1';
}

function qrg_payment_api_get_key() {
    return trim( (string) get_option( 'qrg_payment_api_key', '' ) );
}

function qrg_payment_api_get_secret() {
    return trim( (string) get_option( 'qrg_payment_api_secret', '' ) );
}

function qrg_payment_api_get_ip_whitelist() {
    $raw = trim( (string) get_option( 'qrg_payment_api_ip_whitelist', '' ) );
    if ( $raw === '' ) {
        return [];
    }
    $parts = preg_split( '/[\s,]+/', $raw );
    return array_values( array_filter( array_map( 'trim', $parts ) ) );
}

function qrg_payment_api_generate_token( $length = 40 ) {
    return wp_generate_password( (int) $length, false, false );
}

function qrg_payment_api_rest_base() {
    return rest_url( 'sekolah/v1/pembayaran' );
}

function qrg_bank_transfer_enabled() {
    $account = qrg_bank_transfer_account();
    return get_option( 'qrg_bank_enabled', '' ) === '1' && $account !== '';
}

function qrg_bank_transfer_name() {
    return trim( (string) get_option( 'qrg_bank_name', '' ) );
}

function qrg_bank_transfer_account() {
    return preg_replace( '/\s+/', '', (string) get_option( 'qrg_bank_account', '' ) );
}

function qrg_bank_transfer_holder() {
    return trim( (string) get_option( 'qrg_bank_holder', '' ) );
}

function qrg_bank_transfer_use_unik() {
    $opt = get_option( 'qrg_bank_use_unik', '1' );
    return $opt !== '0' && $opt !== '';
}

/**
 * Kode unik 3 digit per tagihan (untuk membedakan transfer).
 *
 * @param int $tagihan_id
 * @return string
 */
function qrg_get_tagihan_kode_unik( $tagihan_id ) {
    $tagihan_id = (int) $tagihan_id;
    if ( $tagihan_id <= 0 ) {
        return '000';
    }
    $existing = (string) get_post_meta( $tagihan_id, '_tagihan_kode_unik', true );
    if ( $existing !== '' && preg_match( '/^\d{1,3}$/', $existing ) ) {
        return str_pad( (string) (int) $existing, 3, '0', STR_PAD_LEFT );
    }

    $used = [];
    $posts = get_posts( [
        'post_type'      => 'master_tagihan',
        'post_status'    => 'publish',
        'posts_per_page' => 400,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_tagihan_status',
                'value'   => 'Lunas',
                'compare' => '!=',
            ],
        ],
    ] );
    foreach ( $posts as $pid ) {
        if ( (int) $pid === $tagihan_id ) {
            continue;
        }
        $k = (string) get_post_meta( $pid, '_tagihan_kode_unik', true );
        if ( $k !== '' && preg_match( '/^\d{1,3}$/', $k ) ) {
            $used[ str_pad( (string) (int) $k, 3, '0', STR_PAD_LEFT ) ] = true;
        }
    }

    $unik = str_pad( (string) ( $tagihan_id % 900 + 100 ), 3, '0', STR_PAD_LEFT );
    if ( isset( $used[ $unik ] ) ) {
        for ( $i = 1; $i <= 899; $i++ ) {
            $candidate = str_pad( (string) $i, 3, '0', STR_PAD_LEFT );
            if ( $candidate === '000' ) {
                continue;
            }
            if ( ! isset( $used[ $candidate ] ) ) {
                $unik = $candidate;
                break;
            }
        }
    }

    update_post_meta( $tagihan_id, '_tagihan_kode_unik', $unik );
    return $unik;
}

/**
 * Jumlah yang harus ditransfer (total + kode unik bila aktif).
 *
 * @param int $tagihan_id
 * @param int $total
 * @return array{kode:string,jumlah:int,referensi:string}
 */
function qrg_get_tagihan_transfer_info( $tagihan_id, $total ) {
    $kode = qrg_get_tagihan_kode_unik( $tagihan_id );
    $jumlah = (int) $total;
    if ( qrg_bank_transfer_use_unik() ) {
        $jumlah += (int) $kode;
    }
    $referensi = 'SKL-' . (int) $tagihan_id . '-' . $kode;
    update_post_meta( $tagihan_id, '_tagihan_kode_bayar', $referensi );
    return [
        'kode'      => $kode,
        'jumlah'    => $jumlah,
        'referensi' => $referensi,
    ];
}

function qrg_payment_api_extract_key( WP_REST_Request $request ) {
    $auth = (string) $request->get_header( 'authorization' );
    if ( preg_match( '/Bearer\s+(.+)/i', $auth, $m ) ) {
        return trim( $m[1] );
    }
    $header_key = (string) $request->get_header( 'x-api-key' );
    if ( $header_key !== '' ) {
        return trim( $header_key );
    }
    $param = $request->get_param( 'api_key' );
    return is_string( $param ) ? trim( $param ) : '';
}

function qrg_payment_api_client_ip() {
    return function_exists( 'qrg_get_client_ip' ) ? qrg_get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
}

function qrg_payment_api_log_max() {
    return 100;
}

/**
 * @return array<int,array<string,mixed>>
 */
function qrg_payment_api_get_logs() {
    $logs = get_option( 'qrg_payment_api_logs', [] );
    return is_array( $logs ) ? $logs : [];
}

/**
 * @param array<string,mixed> $entry
 */
function qrg_payment_api_write_log( $entry ) {
    $logs = qrg_payment_api_get_logs();
    $row  = [
        'time'    => time(),
        'method'  => strtoupper( (string) ( $entry['method'] ?? 'GET' ) ),
        'path'    => (string) ( $entry['path'] ?? '' ),
        'status'  => (int) ( $entry['status'] ?? 0 ),
        'ok'      => ! empty( $entry['ok'] ),
        'message' => sanitize_text_field( (string) ( $entry['message'] ?? '' ) ),
        'ip'      => sanitize_text_field( (string) ( $entry['ip'] ?? qrg_payment_api_client_ip() ) ),
        'detail'  => sanitize_text_field( (string) ( $entry['detail'] ?? '' ) ),
    ];
    array_unshift( $logs, $row );
    $logs = array_slice( $logs, 0, qrg_payment_api_log_max() );
    update_option( 'qrg_payment_api_logs', $logs, false );
}

function qrg_payment_api_clear_logs() {
    update_option( 'qrg_payment_api_logs', [], false );
}

function qrg_payment_api_request_path( WP_REST_Request $request ) {
    $route = (string) $request->get_route();
    $query = $request->get_query_params();
    unset( $query['api_key'] );
    if ( empty( $query ) ) {
        return $route;
    }
    return $route . '?' . http_build_query( $query );
}

function qrg_payment_api_log_error( WP_REST_Request $request, WP_Error $error ) {
    $data   = $error->get_error_data();
    $status = 400;
    if ( is_array( $data ) && isset( $data['status'] ) ) {
        $status = (int) $data['status'];
    }
    qrg_payment_api_write_log( [
        'method'  => $request->get_method(),
        'path'    => qrg_payment_api_request_path( $request ),
        'status'  => $status,
        'ok'      => false,
        'message' => $error->get_error_message(),
        'detail'  => $error->get_error_code(),
    ] );
}

function qrg_payment_api_permission( WP_REST_Request $request ) {
    if ( ! qrg_payment_api_is_enabled() ) {
        $err = new WP_Error( 'qrg_api_disabled', 'API pembayaran sedang nonaktif.', [ 'status' => 403 ] );
        qrg_payment_api_log_error( $request, $err );
        return $err;
    }

    $stored = qrg_payment_api_get_key();
    $given  = qrg_payment_api_extract_key( $request );
    if ( $stored === '' || $given === '' || ! hash_equals( $stored, $given ) ) {
        $err = new WP_Error( 'qrg_api_unauthorized', 'API key tidak valid.', [ 'status' => 401 ] );
        qrg_payment_api_log_error( $request, $err );
        return $err;
    }

    $allow = qrg_payment_api_get_ip_whitelist();
    if ( ! empty( $allow ) ) {
        $ip = qrg_payment_api_client_ip();
        if ( ! in_array( $ip, $allow, true ) ) {
            $err = new WP_Error( 'qrg_api_forbidden_ip', 'IP tidak diizinkan.', [ 'status' => 403 ] );
            qrg_payment_api_log_error( $request, $err );
            return $err;
        }
    }

    if ( function_exists( 'qrg_rate_limit_check' ) ) {
        $rl = qrg_rate_limit_check( 'payment_api', 120, 60 );
        if ( is_wp_error( $rl ) ) {
            $err = new WP_Error( 'qrg_api_rate', $rl->get_error_message(), [ 'status' => 429 ] );
            qrg_payment_api_log_error( $request, $err );
            return $err;
        }
        qrg_rate_limit_hit( 'payment_api', 60 );
    }

    return true;
}

/**
 * Payload tagihan untuk API.
 *
 * @param int $tagihan_id
 * @return array|null
 */
function qrg_payment_api_format_tagihan( $tagihan_id ) {
    $tagihan_id = (int) $tagihan_id;
    if ( $tagihan_id <= 0 || get_post_type( $tagihan_id ) !== 'master_tagihan' ) {
        return null;
    }

    $siswa_id   = (int) get_post_meta( $tagihan_id, '_tagihan_siswa_id', true );
    $nominal    = (int) get_post_meta( $tagihan_id, '_tagihan_nominal', true );
    if ( $nominal <= 0 && function_exists( 'qrg_akuntansi_get_tagihan_nominal' ) ) {
        $nominal = (int) qrg_akuntansi_get_tagihan_nominal( $tagihan_id );
    }
    $biaya_id   = (int) get_post_meta( $tagihan_id, '_tagihan_biaya_tambahan_id', true );
    $biaya_nom  = $biaya_id ? (int) get_post_meta( $biaya_id, '_biaya_nominal', true ) : 0;
    $total      = $nominal + $biaya_nom;
    $status     = (string) get_post_meta( $tagihan_id, '_tagihan_status', true );
    if ( $status === '' ) {
        $status = 'Belum Dibayar';
    }

    $nis   = $siswa_id ? (string) get_post_meta( $siswa_id, '_siswa_nis', true ) : '';
    $kelas = '';
    if ( $siswa_id && function_exists( 'qrg_get_kelas_label' ) ) {
        $kelas_id = get_post_meta( $siswa_id, '_siswa_kelas', true );
        if ( ! $kelas_id ) {
            $kelas_id = get_post_meta( $siswa_id, '_siswa_kelas_id', true );
        }
        $kelas = qrg_get_kelas_label( $kelas_id );
    }

    $bayar_url = function_exists( 'qrg_get_tagihan_bayar_url' )
        ? qrg_get_tagihan_bayar_url( $tagihan_id )
        : '';

    $tf = function_exists( 'qrg_get_tagihan_transfer_info' )
        ? qrg_get_tagihan_transfer_info( $tagihan_id, $total )
        : [ 'kode' => '', 'jumlah' => $total, 'referensi' => (string) get_post_meta( $tagihan_id, '_tagihan_kode_bayar', true ) ];

    return [
        'id'           => $tagihan_id,
        'judul'        => get_the_title( $tagihan_id ),
        'keterangan'   => (string) get_post_meta( $tagihan_id, '_tagihan_keterangan', true ),
        'status'       => $status,
        'nominal'      => $nominal,
        'biaya_tambahan' => $biaya_nom,
        'total'        => $total,
        'mata_uang'    => 'IDR',
        'siswa'        => [
            'id'    => $siswa_id,
            'nama'  => $siswa_id ? get_the_title( $siswa_id ) : '',
            'nis'   => $nis,
            'kelas' => $kelas,
            'email' => $siswa_id ? (string) get_post_meta( $siswa_id, '_siswa_email', true ) : '',
        ],
        'url_bayar'    => $bayar_url,
        'referensi_api'=> (string) get_post_meta( $tagihan_id, '_tagihan_api_ref', true ),
        'kode_unik'    => $tf['kode'],
        'kode_bayar'   => $tf['referensi'],
        'jumlah_transfer' => (int) $tf['jumlah'],
        'rekening'     => [
            'aktif'     => function_exists( 'qrg_bank_transfer_enabled' ) && qrg_bank_transfer_enabled(),
            'bank'      => function_exists( 'qrg_bank_transfer_name' ) ? qrg_bank_transfer_name() : '',
            'nomor'     => function_exists( 'qrg_bank_transfer_account' ) ? qrg_bank_transfer_account() : '',
            'atas_nama' => function_exists( 'qrg_bank_transfer_holder' ) ? qrg_bank_transfer_holder() : '',
        ],
        'dibayar_pada' => (string) get_post_meta( $tagihan_id, '_tagihan_api_paid_at', true ),
        'diperbarui'   => get_post_modified_time( 'c', true, $tagihan_id ),
    ];
}

function qrg_payment_api_allowed_status() {
    return [ 'Belum Dibayar', 'Sedang Ditinjau', 'Lunas' ];
}

add_action( 'rest_api_init', 'qrg_payment_api_register_routes' );
function qrg_payment_api_register_routes() {
    $ns = 'sekolah/v1';

    register_rest_route( $ns, '/pembayaran/status', [
        'methods'             => 'GET',
        'callback'            => 'qrg_payment_api_rest_status',
        'permission_callback' => 'qrg_payment_api_permission',
    ] );

    register_rest_route( $ns, '/pembayaran/tagihan', [
        'methods'             => 'GET',
        'callback'            => 'qrg_payment_api_rest_list_tagihan',
        'permission_callback' => 'qrg_payment_api_permission',
        'args'                => [
            'status'   => [ 'type' => 'string', 'required' => false ],
            'nis'      => [ 'type' => 'string', 'required' => false ],
            'page'     => [ 'type' => 'integer', 'default' => 1 ],
            'per_page' => [ 'type' => 'integer', 'default' => 20 ],
        ],
    ] );

    register_rest_route( $ns, '/pembayaran/tagihan/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'qrg_payment_api_rest_get_tagihan',
        'permission_callback' => 'qrg_payment_api_permission',
    ] );

    register_rest_route( $ns, '/pembayaran/webhook', [
        'methods'             => 'POST',
        'callback'            => 'qrg_payment_api_rest_webhook',
        'permission_callback' => 'qrg_payment_api_permission',
    ] );
}

add_filter( 'rest_request_after_callbacks', 'qrg_payment_api_log_after_callback', 10, 3 );
function qrg_payment_api_log_after_callback( $response, $handler, $request ) {
    if ( ! $request instanceof WP_REST_Request ) {
        return $response;
    }
    $route = (string) $request->get_route();
    if ( strpos( $route, '/sekolah/v1/pembayaran' ) !== 0 ) {
        return $response;
    }

    if ( is_wp_error( $response ) ) {
        qrg_payment_api_log_error( $request, $response );
        return $response;
    }

    $rest = rest_ensure_response( $response );
    $code = (int) $rest->get_status();
    $data = $rest->get_data();
    $msg  = 'OK';
    $detail = '';
    if ( is_array( $data ) ) {
        if ( isset( $data['total'] ) ) {
            $msg    = 'Daftar tagihan';
            $detail = (int) $data['total'] . ' item';
        } elseif ( isset( $data['tagihan']['id'] ) ) {
            $msg    = 'Webhook diproses';
            $detail = 'tagihan #' . (int) $data['tagihan']['id'] . ' → ' . (string) ( $data['tagihan']['status'] ?? '' );
        } elseif ( isset( $data['id'] ) ) {
            $msg    = 'Detail tagihan';
            $detail = '#' . (int) $data['id'];
        } elseif ( ! empty( $data['ok'] ) ) {
            $msg = 'Status API';
        }
    }

    qrg_payment_api_write_log( [
        'method'  => $request->get_method(),
        'path'    => qrg_payment_api_request_path( $request ),
        'status'  => $code ?: 200,
        'ok'      => $code < 400,
        'message' => $msg,
        'detail'  => $detail,
    ] );

    return $response;
}

function qrg_payment_api_rest_status() {
    return rest_ensure_response( [
        'ok'      => true,
        'plugin'  => 'sekolah',
        'modul'   => 'pembayaran',
        'versi'   => defined( 'QRG_PLUGIN_BASENAME' ) ? '1.7.1' : '',
        'waktu'   => gmdate( 'c' ),
    ] );
}

function qrg_payment_api_rest_list_tagihan( WP_REST_Request $request ) {
    $page     = max( 1, (int) $request->get_param( 'page' ) );
    $per_page = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) );
    $status   = sanitize_text_field( (string) $request->get_param( 'status' ) );
    $nis      = sanitize_text_field( (string) $request->get_param( 'nis' ) );

    $meta_query = [ 'relation' => 'AND' ];
    if ( $status !== '' && in_array( $status, qrg_payment_api_allowed_status(), true ) ) {
        $meta_query[] = [
            'key'   => '_tagihan_status',
            'value' => $status,
        ];
    }
    if ( $nis !== '' && function_exists( 'qrg_find_siswa_by_nis' ) ) {
        $siswa_id = qrg_find_siswa_by_nis( $nis );
        if ( ! $siswa_id ) {
            return rest_ensure_response( [
                'data'       => [],
                'page'       => $page,
                'per_page'   => $per_page,
                'total'      => 0,
                'total_page' => 0,
            ] );
        }
        $meta_query[] = [
            'key'   => '_tagihan_siswa_id',
            'value' => (string) $siswa_id,
        ];
    }

    $args = [
        'post_type'      => 'master_tagihan',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ];
    if ( count( $meta_query ) > 1 ) {
        $args['meta_query'] = $meta_query;
    }

    $q = new WP_Query( $args );
    $items = [];
    foreach ( $q->posts as $id ) {
        $row = qrg_payment_api_format_tagihan( (int) $id );
        if ( $row ) {
            $items[] = $row;
        }
    }

    return rest_ensure_response( [
        'data'       => $items,
        'page'       => $page,
        'per_page'   => $per_page,
        'total'      => (int) $q->found_posts,
        'total_page' => (int) $q->max_num_pages,
    ] );
}

function qrg_payment_api_rest_get_tagihan( WP_REST_Request $request ) {
    $id  = (int) $request['id'];
    $row = qrg_payment_api_format_tagihan( $id );
    if ( ! $row ) {
        return new WP_Error( 'qrg_not_found', 'Tagihan tidak ditemukan.', [ 'status' => 404 ] );
    }
    return rest_ensure_response( $row );
}

function qrg_payment_api_rest_webhook( WP_REST_Request $request ) {
    $secret = qrg_payment_api_get_secret();
    $sig    = (string) $request->get_header( 'x-sekolah-signature' );
    $raw    = $request->get_body();
    if ( $secret !== '' ) {
        $expect = hash_hmac( 'sha256', $raw, $secret );
        if ( $sig === '' || ! hash_equals( $expect, $sig ) ) {
            return new WP_Error( 'qrg_bad_signature', 'Tanda tangan webhook tidak valid.', [ 'status' => 401 ] );
        }
    }

    $params     = $request->get_json_params();
    if ( ! is_array( $params ) ) {
        $params = $request->get_params();
    }
    $tagihan_id = isset( $params['tagihan_id'] ) ? absint( $params['tagihan_id'] ) : 0;
    $status     = isset( $params['status'] ) ? sanitize_text_field( (string) $params['status'] ) : '';
    $referensi  = isset( $params['referensi'] ) ? sanitize_text_field( (string) $params['referensi'] ) : '';
    $catatan    = isset( $params['catatan'] ) ? sanitize_textarea_field( (string) $params['catatan'] ) : '';

    if ( $tagihan_id <= 0 || get_post_type( $tagihan_id ) !== 'master_tagihan' ) {
        return new WP_Error( 'qrg_not_found', 'tagihan_id tidak valid.', [ 'status' => 404 ] );
    }
    if ( ! in_array( $status, qrg_payment_api_allowed_status(), true ) ) {
        return new WP_Error(
            'qrg_bad_status',
            'status harus salah satu: ' . implode( ', ', qrg_payment_api_allowed_status() ),
            [ 'status' => 400 ]
        );
    }

    $jumlah = isset( $params['jumlah'] ) ? (int) $params['jumlah'] : 0;
    if ( $status === 'Lunas' && $jumlah > 0 ) {
        $row = qrg_payment_api_format_tagihan( $tagihan_id );
        if ( $row && abs( $jumlah - (int) $row['total'] ) > 1 ) {
            return new WP_Error(
                'qrg_amount_mismatch',
                'Jumlah tidak sesuai total tagihan (' . (int) $row['total'] . ').',
                [ 'status' => 422 ]
            );
        }
    }

    $note_parts = [];
    if ( $referensi !== '' ) {
        update_post_meta( $tagihan_id, '_tagihan_api_ref', $referensi );
        $note_parts[] = 'ref: ' . $referensi;
    }
    if ( $catatan !== '' ) {
        update_post_meta( $tagihan_id, '_tagihan_api_note', $catatan );
        $note_parts[] = $catatan;
    }
    if ( $jumlah > 0 ) {
        $note_parts[] = 'jumlah ' . $jumlah;
    }

    if ( function_exists( 'qrg_tagihan_set_status' ) ) {
        qrg_tagihan_set_status( $tagihan_id, $status, [
            'via'     => 'api',
            'force'   => true,
            'catatan' => implode( ' · ', $note_parts ),
            'oleh'    => 'API pembayaran',
        ] );
    } else {
        update_post_meta( $tagihan_id, '_tagihan_status', $status );
    }
    if ( $status === 'Lunas' ) {
        update_post_meta( $tagihan_id, '_tagihan_api_paid_at', gmdate( 'c' ) );
    }

    return rest_ensure_response( [
        'ok'      => true,
        'tagihan' => qrg_payment_api_format_tagihan( $tagihan_id ),
    ] );
}

function qrg_payment_api_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( function_exists( 'qrg_handle_pembayaran_qr_admin_post' ) ) {
        qrg_handle_pembayaran_qr_admin_post();
    }

    $saved   = false;
    $regen   = false;
    $message = '';

    if ( isset( $_POST['qrg_save_payment_api'] ) && check_admin_referer( 'qrg_payment_api_save', 'qrg_payment_api_nonce' ) ) {
        $enabled = ! empty( $_POST['qrg_payment_api_enabled'] ) ? '1' : '';
        update_option( 'qrg_payment_api_enabled', $enabled );

        $whitelist = isset( $_POST['qrg_payment_api_ip_whitelist'] )
            ? sanitize_textarea_field( wp_unslash( $_POST['qrg_payment_api_ip_whitelist'] ) )
            : '';
        update_option( 'qrg_payment_api_ip_whitelist', $whitelist );

        if ( qrg_payment_api_get_key() === '' ) {
            update_option( 'qrg_payment_api_key', qrg_payment_api_generate_token( 40 ) );
            $regen = true;
        }
        if ( qrg_payment_api_get_secret() === '' ) {
            update_option( 'qrg_payment_api_secret', qrg_payment_api_generate_token( 48 ) );
            $regen = true;
        }

        $saved   = true;
        $message = 'Pengaturan API pembayaran disimpan.';
    }

    if ( isset( $_POST['qrg_save_bank_transfer'] ) && check_admin_referer( 'qrg_bank_transfer_save', 'qrg_bank_transfer_nonce' ) ) {
        update_option( 'qrg_bank_enabled', ! empty( $_POST['qrg_bank_enabled'] ) ? '1' : '' );
        update_option( 'qrg_bank_name', isset( $_POST['qrg_bank_name'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_bank_name'] ) ) : '' );
        update_option( 'qrg_bank_account', isset( $_POST['qrg_bank_account'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_bank_account'] ) ) : '' );
        update_option( 'qrg_bank_holder', isset( $_POST['qrg_bank_holder'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_bank_holder'] ) ) : '' );
        update_option( 'qrg_bank_use_unik', ! empty( $_POST['qrg_bank_use_unik'] ) ? '1' : '0' );
        $saved   = true;
        $message = 'Pengaturan rekening pembayaran disimpan.';
    }

    if ( isset( $_POST['qrg_regen_payment_api_key'] ) && check_admin_referer( 'qrg_payment_api_save', 'qrg_payment_api_nonce' ) ) {
        update_option( 'qrg_payment_api_key', qrg_payment_api_generate_token( 40 ) );
        $saved   = true;
        $regen   = true;
        $message = 'API key baru dibuat. Simpan di aplikasi pembayaran Anda — key lama tidak berlaku lagi.';
    }

    if ( isset( $_POST['qrg_regen_payment_api_secret'] ) && check_admin_referer( 'qrg_payment_api_save', 'qrg_payment_api_nonce' ) ) {
        update_option( 'qrg_payment_api_secret', qrg_payment_api_generate_token( 48 ) );
        $saved   = true;
        $regen   = true;
        $message = 'API secret baru dibuat. Perbarui tanda tangan HMAC di gateway.';
    }

    $log_cleared = false;
    if ( isset( $_POST['qrg_clear_payment_api_logs'] ) && check_admin_referer( 'qrg_payment_api_logs', 'qrg_payment_api_logs_nonce' ) ) {
        qrg_payment_api_clear_logs();
        $log_cleared = true;
    }

    $tampung_cleared = function_exists( 'qrg_tampung_handle_admin_post' ) && qrg_tampung_handle_admin_post();
    $google_saved    = function_exists( 'qrg_google_auth_handle_admin_post' ) && qrg_google_auth_handle_admin_post();

    $enabled   = qrg_payment_api_is_enabled();
    $api_key   = qrg_payment_api_get_key();
    $secret    = qrg_payment_api_get_secret();
    $whitelist = (string) get_option( 'qrg_payment_api_ip_whitelist', '' );
    $base      = untrailingslashit( qrg_payment_api_rest_base() );
    $logs        = qrg_payment_api_get_logs();
    $tampung_n   = function_exists( 'qrg_tampung_get_logs' ) ? count( qrg_tampung_get_logs() ) : 0;
    $log_tab     = isset( $_GET['log'] ) ? sanitize_key( (string) wp_unslash( $_GET['log'] ) ) : '';
    if ( $log_tab === '1' ) {
        $log_tab = 'tampung';
    }
    $view_tampung = ( $log_tab === 'tampung' );
    $view_api     = ( $log_tab === 'api' );
    $view_google  = isset( $_GET['google'] );
    $view_bank    = isset( $_GET['rekening'] );
    $view_qr      = isset( $_GET['qris'] );
    $view_endpoint = isset( $_GET['endpoint'] );
    $setting_url  = admin_url( 'admin.php?page=qrg-setting' );
    $tampung_url  = admin_url( 'admin.php?page=qrg-setting&log=tampung' );
    $api_log_url  = admin_url( 'admin.php?page=qrg-setting&log=api' );
    $google_url   = function_exists( 'qrg_google_auth_settings_url' )
        ? qrg_google_auth_settings_url()
        : admin_url( 'admin.php?page=qrg-setting&google=1' );
    $bank_url     = admin_url( 'admin.php?page=qrg-setting&rekening=1' );
    $qr_url       = admin_url( 'admin.php?page=qrg-setting&qris=1' );
    $endpoint_url = admin_url( 'admin.php?page=qrg-setting&endpoint=1' );
    $sub_view     = ( $view_api || $view_tampung || $view_google || $view_bank || $view_qr || $view_endpoint );
    ?>
    <div class="wrap">
        <h1>
            Setting — Pembayaran
            <?php if ( $sub_view ) : ?>
                <a href="<?php echo esc_url( $setting_url ); ?>" class="page-title-action">Pengaturan</a>
            <?php endif; ?>
            <?php if ( ! $view_google ) : ?>
                <a href="<?php echo esc_url( $google_url ); ?>" class="page-title-action">Login Google</a>
            <?php endif; ?>
            <?php if ( ! $view_bank ) : ?>
                <a href="<?php echo esc_url( $bank_url ); ?>" class="page-title-action">Transfer Rekening</a>
            <?php endif; ?>
            <?php if ( ! $view_qr ) : ?>
                <a href="<?php echo esc_url( $qr_url ); ?>" class="page-title-action">QR Code Pembayaran</a>
            <?php endif; ?>
            <?php if ( ! $view_endpoint ) : ?>
                <a href="<?php echo esc_url( $endpoint_url ); ?>" class="page-title-action">Endpoint</a>
            <?php endif; ?>
            <?php if ( ! $view_tampung ) : ?>
                <a href="<?php echo esc_url( $tampung_url ); ?>" class="page-title-action">
                    Log Tampung<?php echo $tampung_n > 0 ? ' (' . (int) $tampung_n . ')' : ''; ?>
                </a>
            <?php endif; ?>
            <?php if ( ! $view_api ) : ?>
                <a href="<?php echo esc_url( $api_log_url ); ?>" class="page-title-action">
                    Log API Pembayaran<?php echo count( $logs ) > 0 ? ' (' . count( $logs ) . ')' : ''; ?>
                </a>
            <?php endif; ?>
        </h1>
        <nav class="nav-tab-wrapper" style="margin:12px 0 16px;">
            <a href="<?php echo esc_url( $setting_url ); ?>" class="nav-tab<?php echo $sub_view ? '' : ' nav-tab-active'; ?>">Pengaturan</a>
            <a href="<?php echo esc_url( $google_url ); ?>" class="nav-tab<?php echo $view_google ? ' nav-tab-active' : ''; ?>">Login Google</a>
            <a href="<?php echo esc_url( $bank_url ); ?>" class="nav-tab<?php echo $view_bank ? ' nav-tab-active' : ''; ?>">Transfer Rekening</a>
            <a href="<?php echo esc_url( $qr_url ); ?>" class="nav-tab<?php echo $view_qr ? ' nav-tab-active' : ''; ?>">QR Code Pembayaran</a>
            <a href="<?php echo esc_url( $endpoint_url ); ?>" class="nav-tab<?php echo $view_endpoint ? ' nav-tab-active' : ''; ?>">Endpoint</a>
            <a href="<?php echo esc_url( $tampung_url ); ?>" class="nav-tab<?php echo $view_tampung ? ' nav-tab-active' : ''; ?>">
                Log Tampung
                <?php if ( $tampung_n > 0 ) : ?>
                    <span class="awaiting-mod" style="margin-left:4px;"><?php echo (int) $tampung_n; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo esc_url( $api_log_url ); ?>" class="nav-tab<?php echo $view_api ? ' nav-tab-active' : ''; ?>">
                Log API Pembayaran
                <?php if ( count( $logs ) > 0 ) : ?>
                    <span class="awaiting-mod" style="margin-left:4px;"><?php echo count( $logs ); ?></span>
                <?php endif; ?>
            </a>
        </nav>

        <?php if ( $view_google ) : ?>
            <?php if ( $google_saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Pengaturan Login Google disimpan.</p></div>
            <?php endif; ?>
            <?php
            if ( function_exists( 'qrg_google_auth_render_settings_panel' ) ) {
                qrg_google_auth_render_settings_panel();
            }
            ?>
        </div>
        <?php
            return;
        endif;

        if ( $view_bank ) :
            ?>
            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message !== '' ? $message : 'Pengaturan rekening pembayaran disimpan.' ); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( $bank_url ); ?>" style="max-width:820px;">
            <?php wp_nonce_field( 'qrg_bank_transfer_save', 'qrg_bank_transfer_nonce' ); ?>
            <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-top:8px;">
                <h2 style="margin-top:0;">Transfer Rekening</h2>
                <p style="color:#646970;margin-top:0;">Tampil di invoice <code>/sekolah/bayar/{id}/</code> sebagai opsi pembayaran selain QRIS. Setiap tagihan mendapat kode unik 3 digit.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Status</th>
                        <td>
                            <label>
                                <input type="checkbox" name="qrg_bank_enabled" value="1" <?php checked( get_option( 'qrg_bank_enabled', '' ), '1' ); ?>>
                                Tampilkan opsi transfer rekening di invoice
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qrg_bank_name">Nama bank</label></th>
                        <td>
                            <input type="text" class="regular-text" id="qrg_bank_name" name="qrg_bank_name"
                                   value="<?php echo esc_attr( qrg_bank_transfer_name() ); ?>" placeholder="Contoh: BCA / BRI / Mandiri">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qrg_bank_account">Nomor rekening</label></th>
                        <td>
                            <input type="text" class="regular-text" id="qrg_bank_account" name="qrg_bank_account"
                                   value="<?php echo esc_attr( qrg_bank_transfer_account() ); ?>" placeholder="Contoh: 1234567890" inputmode="numeric">
                            <p class="description">Wajib diisi agar opsi transfer tampil di invoice.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qrg_bank_holder">Nama pemilik rekening</label></th>
                        <td>
                            <input type="text" class="regular-text" id="qrg_bank_holder" name="qrg_bank_holder"
                                   value="<?php echo esc_attr( qrg_bank_transfer_holder() ); ?>" placeholder="Atas nama sekolah / yayasan">
                        </td>
                    </tr>
                    <tr>
                        <th>Kode unik</th>
                        <td>
                            <label>
                                <input type="checkbox" name="qrg_bank_use_unik" value="1" <?php checked( qrg_bank_transfer_use_unik() ); ?>>
                                Tambahkan 3 digit kode unik ke nominal transfer
                            </label>
                            <p class="description">Contoh tagihan Rp 150.000 dengan kode 123 dibayar <strong>Rp 150.123</strong> agar mudah dicocokkan. Siswa juga mendapat kode referensi <code>SKL-{id}-{kode}</code>.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" name="qrg_save_bank_transfer" class="button button-primary">Simpan rekening</button>
                </p>
            </div>
            </form>
        </div>
        <?php
            return;
        endif;

        if ( $view_qr ) :
            ?>
            <?php
            if ( function_exists( 'qrg_render_pembayaran_qr_admin_panel' ) ) {
                qrg_render_pembayaran_qr_admin_panel();
            }
            ?>
        </div>
        <?php
            return;
        endif;

        if ( $view_tampung ) :
            ?>
            <p>JSON yang masuk ke endpoint <code>/wp-json/tampung/</code>.</p>
            <?php if ( ! empty( $tampung_cleared ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Log pool tampung dikosongkan.</p></div>
            <?php endif; ?>
            <?php
            if ( function_exists( 'qrg_tampung_render_settings_panel' ) ) {
                qrg_tampung_render_settings_panel();
            }
            ?>
        </div>
        <?php
            return;
        endif;

        if ( $view_api ) :
            ?>
            <p>Catatan panggilan REST API pembayaran (sukses dan gagal).</p>
            <?php if ( $log_cleared ) : ?>
                <div class="notice notice-success is-dismissible"><p>Log API pembayaran dikosongkan.</p></div>
            <?php endif; ?>
            <div id="qrg-api-log" style="max-width:820px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-top:8px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0 0 4px;">Log API Pembayaran</h2>
                        <p style="margin:0;color:#646970;">Menampilkan <?php echo count( $logs ); ?> dari maksimal <?php echo (int) qrg_payment_api_log_max(); ?> panggilan terakhir.</p>
                    </div>
                    <form method="post" action="<?php echo esc_url( $api_log_url ); ?>" style="margin:0;" onsubmit="return confirm('Hapus semua log API pembayaran?');">
                        <?php wp_nonce_field( 'qrg_payment_api_logs', 'qrg_payment_api_logs_nonce' ); ?>
                        <button type="submit" name="qrg_clear_payment_api_logs" class="button" <?php disabled( empty( $logs ) ); ?>>Kosongkan log</button>
                    </form>
                </div>
                <?php if ( empty( $logs ) ) : ?>
                    <p style="margin:16px 0 0;color:#646970;">Belum ada panggilan API. Log akan muncul setelah gateway atau aplikasi eksternal mengakses endpoint pembayaran.</p>
                <?php else : ?>
                    <table class="widefat striped" style="margin-top:14px;">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Metode</th>
                                <th>Endpoint</th>
                                <th>Status</th>
                                <th>IP</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $logs as $row ) :
                                $ok      = ! empty( $row['ok'] );
                                $status  = (int) ( $row['status'] ?? 0 );
                                $color   = $ok ? '#00a32a' : '#d63638';
                                $waktu   = ! empty( $row['time'] ) ? wp_date( 'd M Y H:i:s', (int) $row['time'] ) : '—';
                                $detail  = trim( (string) ( $row['detail'] ?? '' ) );
                                $message = (string) ( $row['message'] ?? '' );
                                ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo esc_html( $waktu ); ?></td>
                                    <td><code><?php echo esc_html( (string) ( $row['method'] ?? '' ) ); ?></code></td>
                                    <td style="word-break:break-all;"><code><?php echo esc_html( (string) ( $row['path'] ?? '' ) ); ?></code></td>
                                    <td>
                                        <span style="display:inline-block;padding:2px 8px;border-radius:999px;font-weight:600;font-size:12px;background:<?php echo $ok ? '#edfaef' : '#fcf0f1'; ?>;color:<?php echo esc_attr( $color ); ?>;">
                                            <?php echo esc_html( (string) $status ); ?>
                                        </span>
                                    </td>
                                    <td><code><?php echo esc_html( (string) ( $row['ip'] ?? '' ) ); ?></code></td>
                                    <td>
                                        <?php echo esc_html( $message ); ?>
                                        <?php if ( $detail !== '' ) : ?>
                                            <div style="color:#646970;font-size:12px;"><?php echo esc_html( $detail ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
            return;
        endif;

        if ( $view_endpoint ) :
            $ex_key = $api_key !== '' ? $api_key : 'API_KEY';
            ?>
            <p>Dokumentasi REST API pembayaran. Setiap endpoint punya contoh permintaan sendiri. Header wajib: <code>Authorization: Bearer {api_key}</code> atau <code>X-API-Key</code>.</p>
            <p style="color:#646970;margin-top:0;">Base URL: <code><?php echo esc_html( $base ); ?></code></p>

            <div id="ep-status" style="max-width:820px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-top:12px;">
                <h2 style="margin:0 0 6px;">1. Cek status API</h2>
                <p style="margin:0 0 8px;color:#646970;"><code>GET</code> <code>/status</code> — pastikan API aktif dan kredensial benar.</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0 0 10px;">curl -H "Authorization: Bearer <?php echo esc_html( $ex_key ); ?>" \
  "<?php echo esc_html( $base ); ?>/status"</pre>
                <p class="description" style="margin:0 0 6px;">Contoh respons:</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0;">{
  "ok": true,
  "plugin": "sekolah",
  "modul": "pembayaran",
  "versi": "1.7.1",
  "waktu": "2026-08-19T09:00:00+00:00"
}</pre>
            </div>

            <div id="ep-tagihan" style="max-width:820px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-top:16px;">
                <h2 style="margin:0 0 6px;">2. Daftar tagihan</h2>
                <p style="margin:0 0 8px;color:#646970;"><code>GET</code> <code>/tagihan</code> — query opsional: <code>status</code>, <code>nis</code>, <code>page</code>, <code>per_page</code> (maks. 50).</p>
                <p class="description">Status yang valid: <strong>Belum Dibayar</strong>, <strong>Sedang Ditinjau</strong>, <strong>Lunas</strong>.</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0 0 10px;">curl -H "Authorization: Bearer <?php echo esc_html( $ex_key ); ?>" \
  "<?php echo esc_html( $base ); ?>/tagihan?status=Belum%20Dibayar&amp;page=1&amp;per_page=20"</pre>
                <p class="description" style="margin:0 0 6px;">Filter per siswa (NIS):</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0 0 10px;">curl -H "Authorization: Bearer <?php echo esc_html( $ex_key ); ?>" \
  "<?php echo esc_html( $base ); ?>/tagihan?nis=1234567890"</pre>
                <p class="description" style="margin:0 0 6px;">Contoh respons:</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0;">{
  "total": 1,
  "page": 1,
  "per_page": 20,
  "total_page": 1,
  "items": [
    {
      "id": 3153,
      "judul": "SPP Agustus - Nama Siswa",
      "status": "Belum Dibayar",
      "total": 150000,
      "kode_unik": "123",
      "kode_bayar": "SKL-3153-123",
      "url_bayar": "https://contoh.sch.id/sekolah/bayar/3153/"
    }
  ]
}</pre>
            </div>

            <div id="ep-tagihan-id" style="max-width:820px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-top:16px;">
                <h2 style="margin:0 0 6px;">3. Detail satu tagihan</h2>
                <p style="margin:0 0 8px;color:#646970;"><code>GET</code> <code>/tagihan/{id}</code> — ganti <code>{id}</code> dengan ID post tagihan (contoh: 3153).</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0 0 10px;">curl -H "Authorization: Bearer <?php echo esc_html( $ex_key ); ?>" \
  "<?php echo esc_html( $base ); ?>/tagihan/3153"</pre>
                <p class="description" style="margin:0 0 6px;">Contoh respons:</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0;">{
  "id": 3153,
  "judul": "SPP Agustus - Nama Siswa",
  "status": "Belum Dibayar",
  "nominal": 150000,
  "biaya_tambahan": 0,
  "total": 150000,
  "mata_uang": "IDR",
  "siswa": {
    "id": 88,
    "nama": "Nama Siswa",
    "nis": "1234567890",
    "kelas": "7A",
    "email": "siswa@contoh.sch.id"
  },
  "url_bayar": "https://contoh.sch.id/sekolah/bayar/3153/",
  "kode_unik": "123",
  "kode_bayar": "SKL-3153-123",
  "jumlah_transfer": 150123
}</pre>
            </div>

            <div id="ep-webhook" style="max-width:820px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-top:16px;">
                <h2 style="margin:0 0 6px;">4. Webhook update status</h2>
                <p style="margin:0 0 8px;color:#646970;"><code>POST</code> <code>/webhook</code> — kirim konfirmasi pembayaran. Body JSON: <code>tagihan_id</code>, <code>status</code>, opsional <code>referensi</code>, <code>catatan</code>, <code>jumlah</code>.</p>
                <p class="description">Jika API Secret diisi, header <code>X-Sekolah-Signature</code> wajib. Signature = <code>hash_hmac('sha256', raw_json_body, api_secret)</code>.</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0 0 10px;">curl -X POST "<?php echo esc_html( $base ); ?>/webhook" \
  -H "Authorization: Bearer <?php echo esc_html( $ex_key ); ?>" \
  -H "Content-Type: application/json" \
  -H "X-Sekolah-Signature: HMAC_SHA256_BODY" \
  -d '{"tagihan_id":3153,"status":"Lunas","referensi":"PAY-001","jumlah":150000}'</pre>
                <p class="description" style="margin:0 0 6px;">Contoh lain: sedang ditinjau</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0 0 10px;">curl -X POST "<?php echo esc_html( $base ); ?>/webhook" \
  -H "Authorization: Bearer <?php echo esc_html( $ex_key ); ?>" \
  -H "Content-Type: application/json" \
  -d '{"tagihan_id":3153,"status":"Sedang Ditinjau","catatan":"Menunggu cek bukti"}'</pre>
                <p class="description" style="margin:0 0 6px;">Contoh respons:</p>
<pre style="background:#f6f7f7;padding:12px 14px;overflow:auto;border-radius:4px;margin:0;">{
  "ok": true,
  "tagihan": {
    "id": 3153,
    "status": "Lunas"
  }
}</pre>
            </div>
        </div>
        <?php
            return;
        endif;
        ?>

        <p>Atur REST API untuk integrasi gateway pembayaran eksternal.</p>

        <?php if ( $saved ) : ?>
            <div class="notice notice-<?php echo $regen ? 'warning' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <form method="post" style="max-width:820px;">
            <?php wp_nonce_field( 'qrg_payment_api_save', 'qrg_payment_api_nonce' ); ?>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-top:16px;">
                <h2 style="margin-top:0;">API Pembayaran</h2>
                <p style="color:#646970;margin-top:0;">Aktifkan REST API agar aplikasi atau gateway eksternal dapat membaca tagihan dan mengirim konfirmasi lunas ke portal <code>/sekolah/</code>.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Status API</th>
                        <td>
                            <label>
                                <input type="checkbox" name="qrg_payment_api_enabled" value="1" <?php checked( $enabled ); ?>>
                                Aktifkan API pembayaran
                            </label>
                            <p class="description">Nonaktifkan jika tidak dipakai agar endpoint menolak semua permintaan.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qrg_payment_api_key">API Key</label></th>
                        <td>
                            <input type="text" class="large-text code" id="qrg_payment_api_key" readonly
                                   value="<?php echo esc_attr( $api_key ); ?>"
                                   placeholder="Akan dibuat otomatis saat disimpan">
                            <p class="description">Kirim di header <code>Authorization: Bearer {api_key}</code> atau <code>X-API-Key</code>.</p>
                            <p>
                                <button type="submit" name="qrg_regen_payment_api_key" class="button" onclick="return confirm('Buat API key baru? Key lama langsung tidak berlaku.');">Buat ulang API Key</button>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qrg_payment_api_secret">API Secret</label></th>
                        <td>
                            <input type="text" class="large-text code" id="qrg_payment_api_secret" readonly
                                   value="<?php echo esc_attr( $secret ); ?>"
                                   placeholder="Akan dibuat otomatis saat disimpan">
                            <p class="description">Untuk webhook: header <code>X-Sekolah-Signature</code> = HMAC-SHA256 body JSON dengan secret ini.</p>
                            <p>
                                <button type="submit" name="qrg_regen_payment_api_secret" class="button" onclick="return confirm('Buat API secret baru? Signature webhook lama akan ditolak.');">Buat ulang API Secret</button>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="qrg_payment_api_ip_whitelist">IP yang diizinkan</label></th>
                        <td>
                            <textarea class="large-text code" rows="3" id="qrg_payment_api_ip_whitelist" name="qrg_payment_api_ip_whitelist" placeholder="Kosongkan = semua IP"><?php echo esc_textarea( $whitelist ); ?></textarea>
                            <p class="description">Opsional. Pisahkan dengan koma atau baris baru. Contoh: <code>103.10.10.5, 103.10.10.6</code></p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" name="qrg_save_payment_api" class="button button-primary">Simpan pengaturan</button>
                    <a href="<?php echo esc_url( $endpoint_url ); ?>" class="button">Endpoint</a>
                    <a href="<?php echo esc_url( $api_log_url ); ?>" class="button">Log API Pembayaran<?php echo count( $logs ) > 0 ? ' (' . count( $logs ) . ')' : ''; ?></a>
                </p>
            </div>
        </form>
    </div>
    <?php
}
