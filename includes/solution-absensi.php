<?php
/**
 * Integrasi mesin absensi Solution (OEM ZKTeco) via protokol ADMS Push.
 * Perangkat push log ke /iclock/* di situs WordPress.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'QRG_SOLUTION_LOG_MAX', 80 );

/**
 * @return array<string,mixed>
 */
function qrg_solution_get_settings() {
    $defaults = [
        'enabled'    => '1',
        'timezone'   => '7',
        'admin_user' => '16020',
        'admin_pass' => '16020',
        'devices'    => [],
    ];
    $saved = get_option( 'qrg_solution_absensi_settings', [] );
    if ( ! is_array( $saved ) ) {
        $saved = [];
    }
    $out = array_merge( $defaults, $saved );

    // Migrasi data lama (satu mesin) ke daftar.
    if ( empty( $out['devices'] ) || ! is_array( $out['devices'] ) ) {
        $out['devices'] = [];
        $legacy_ip = sanitize_text_field( (string) ( $saved['device_ip'] ?? '' ) );
        $legacy_sn = sanitize_text_field( (string) ( $saved['device_sn'] ?? '' ) );
        if ( $legacy_ip !== '' || $legacy_sn !== '' ) {
            $out['devices'][] = qrg_solution_sanitize_device( [
                'nama'   => 'Mesin 1',
                'ip'     => $legacy_ip ?: '192.168.11.111',
                'sn'     => $legacy_sn,
                'lokasi' => '',
            ] );
        }
    }

    $normalized = [];
    foreach ( $out['devices'] as $device ) {
        $normalized[] = qrg_solution_sanitize_device( is_array( $device ) ? $device : [] );
    }
    $out['devices'] = $normalized;

    $first = ! empty( $out['devices'][0] ) ? $out['devices'][0] : [];
    $out['device_ip'] = (string) ( $first['ip'] ?? '' );
    $out['device_sn'] = (string) ( $first['sn'] ?? '' );

    return $out;
}

/**
 * @param array<string,mixed> $raw
 * @return array{id:string,nama:string,ip:string,sn:string,lokasi:string,admin_user:string,admin_pass:string,enabled:string,last_seen:string}
 */
function qrg_solution_sanitize_device( $raw ) {
    $id = sanitize_key( (string) ( $raw['id'] ?? '' ) );
    if ( $id === '' ) {
        $id = substr( md5( uniqid( 'sol', true ) ), 0, 12 );
    }
    $ip = sanitize_text_field( (string) ( $raw['ip'] ?? '' ) );
    if ( $ip !== '' && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        $ip = '';
    }
    $nama = sanitize_text_field( (string) ( $raw['nama'] ?? '' ) );
    if ( $nama === '' ) {
        $nama = $ip !== '' ? $ip : 'Mesin';
    }
    $admin_user = sanitize_text_field( (string) ( $raw['admin_user'] ?? '' ) );
    if ( $admin_user === '' ) {
        $admin_user = '16020';
    }
    $admin_pass = array_key_exists( 'admin_pass', $raw )
        ? sanitize_text_field( (string) $raw['admin_pass'] )
        : '16020';
    if ( $admin_pass === '' ) {
        $admin_pass = '16020';
    }
    return [
        'id'         => $id,
        'nama'       => $nama,
        'ip'         => $ip,
        'sn'         => sanitize_text_field( (string) ( $raw['sn'] ?? '' ) ),
        'lokasi'     => sanitize_text_field( (string) ( $raw['lokasi'] ?? '' ) ),
        'admin_user' => $admin_user,
        'admin_pass' => $admin_pass,
        'enabled'    => empty( $raw['enabled'] ) && isset( $raw['enabled'] ) ? '0' : '1',
        'last_seen'  => sanitize_text_field( (string) ( $raw['last_seen'] ?? '' ) ),
    ];
}

/**
 * @return array<int,array<string,string>>
 */
function qrg_solution_get_devices() {
    $settings = qrg_solution_get_settings();
    return is_array( $settings['devices'] ) ? $settings['devices'] : [];
}

function qrg_solution_get_device( $id ) {
    $id = sanitize_key( (string) $id );
    foreach ( qrg_solution_get_devices() as $device ) {
        if ( $device['id'] === $id ) {
            return $device;
        }
    }
    return null;
}

/**
 * @param array<int,array<string,mixed>> $devices
 */
function qrg_solution_save_devices( $devices ) {
    $settings = qrg_solution_get_settings();
    $clean    = [];
    foreach ( (array) $devices as $device ) {
        if ( ! is_array( $device ) ) {
            continue;
        }
        $clean[] = qrg_solution_sanitize_device( $device );
    }
    $settings['devices'] = $clean;
    qrg_solution_save_settings( $settings );
}

/**
 * @param array<string,mixed> $settings
 */
function qrg_solution_save_settings( $settings ) {
    $current = qrg_solution_get_settings();
    $devices = $current['devices'];
    if ( isset( $settings['devices'] ) && is_array( $settings['devices'] ) ) {
        $devices = [];
        foreach ( $settings['devices'] as $device ) {
            if ( is_array( $device ) ) {
                $devices[] = qrg_solution_sanitize_device( $device );
            }
        }
    }
    $out = [
        'enabled'    => array_key_exists( 'enabled', $settings )
            ? ( ! empty( $settings['enabled'] ) ? '1' : '0' )
            : $current['enabled'],
        'timezone'   => sanitize_text_field( (string) ( $settings['timezone'] ?? $current['timezone'] ) ),
        'admin_user' => sanitize_text_field( (string) ( $settings['admin_user'] ?? ( $current['admin_user'] ?? '16020' ) ) ),
        'admin_pass' => sanitize_text_field( (string) ( $settings['admin_pass'] ?? ( $current['admin_pass'] ?? '16020' ) ) ),
        'devices'    => $devices,
    ];
    if ( $out['admin_user'] === '' ) {
        $out['admin_user'] = '16020';
    }
    if ( $out['admin_pass'] === '' ) {
        $out['admin_pass'] = '16020';
    }
    update_option( 'qrg_solution_absensi_settings', $out, false );
}

/**
 * Kredensial admin untuk login web/SOAP mesin.
 *
 * @param array<string,string>|null $device
 * @return array{user:string,pass:string}
 */
function qrg_solution_get_admin_credentials( $device = null ) {
    $settings = qrg_solution_get_settings();
    $user     = '16020';
    $pass     = '16020';
    if ( ! empty( $settings['admin_user'] ) ) {
        $user = (string) $settings['admin_user'];
    }
    if ( isset( $settings['admin_pass'] ) && $settings['admin_pass'] !== '' ) {
        $pass = (string) $settings['admin_pass'];
    }
    if ( is_array( $device ) ) {
        if ( ! empty( $device['admin_user'] ) ) {
            $user = (string) $device['admin_user'];
        }
        if ( isset( $device['admin_pass'] ) && $device['admin_pass'] !== '' ) {
            $pass = (string) $device['admin_pass'];
        }
    }
    return [
        'user' => $user,
        'pass' => $pass,
    ];
}

function qrg_solution_is_admin_login_pin( $pin, $device = null ) {
    $cred = qrg_solution_get_admin_credentials( $device );
    return (string) $pin !== '' && (string) $pin === (string) $cred['user'];
}

function qrg_solution_find_device_index_by_sn( $sn ) {
    $sn = trim( (string) $sn );
    if ( $sn === '' ) {
        return -1;
    }
    foreach ( qrg_solution_get_devices() as $i => $device ) {
        if ( strcasecmp( (string) $device['sn'], $sn ) === 0 ) {
            return (int) $i;
        }
    }
    return -1;
}

function qrg_solution_find_device_index_by_ip( $ip ) {
    $ip = trim( (string) $ip );
    if ( $ip === '' ) {
        return -1;
    }
    foreach ( qrg_solution_get_devices() as $i => $device ) {
        if ( (string) $device['ip'] === $ip ) {
            return (int) $i;
        }
    }
    return -1;
}

function qrg_solution_device_label( $sn, $ip = '' ) {
    $idx = qrg_solution_find_device_index_by_sn( $sn );
    if ( $idx < 0 && $ip !== '' ) {
        $idx = qrg_solution_find_device_index_by_ip( $ip );
    }
    if ( $idx < 0 ) {
        return $sn !== '' ? $sn : ( $ip !== '' ? $ip : 'Mesin' );
    }
    $device = qrg_solution_get_devices()[ $idx ];
    $name   = (string) $device['nama'];
    if ( ! empty( $device['lokasi'] ) ) {
        $name .= ' (' . $device['lokasi'] . ')';
    }
    return $name;
}

/**
 * Catat SN / last seen saat mesin menghubungi server.
 */
function qrg_solution_touch_device( $sn, $ip = '' ) {
    $sn = sanitize_text_field( (string) $sn );
    $ip = sanitize_text_field( (string) $ip );
    if ( $sn === '' && $ip === '' ) {
        return;
    }

    $devices = qrg_solution_get_devices();
    $idx     = qrg_solution_find_device_index_by_sn( $sn );
    if ( $idx < 0 && $ip !== '' ) {
        $idx = qrg_solution_find_device_index_by_ip( $ip );
    }

    $now = current_time( 'mysql' );
    if ( $idx >= 0 ) {
        $changed = false;
        if ( $sn !== '' && $devices[ $idx ]['sn'] === '' ) {
            $devices[ $idx ]['sn'] = $sn;
            $changed = true;
        }
        if ( $ip !== '' && $devices[ $idx ]['ip'] === '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            $devices[ $idx ]['ip'] = $ip;
            $changed = true;
        }
        $prev = (string) $devices[ $idx ]['last_seen'];
        if ( $prev === '' || ( strtotime( $now ) - (int) strtotime( $prev ) ) >= 60 ) {
            $devices[ $idx ]['last_seen'] = $now;
            $changed = true;
        }
        if ( $changed ) {
            qrg_solution_save_devices( $devices );
        }
        return;
    }

    if ( $sn === '' ) {
        return;
    }

    $n = count( $devices ) + 1;
    $devices[] = qrg_solution_sanitize_device( [
        'nama'      => 'Mesin ' . $n,
        'ip'        => filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
        'sn'        => $sn,
        'lokasi'    => '',
        'last_seen' => current_time( 'mysql' ),
    ] );
    qrg_solution_save_devices( $devices );
}

function qrg_solution_iclock_base_url() {
    return home_url( '/iclock/' );
}

function qrg_solution_server_url_for_device() {
    return home_url( '/iclock/cdata' );
}

/**
 * URL admin Mesin Solution.
 *
 * @param array<string,string|int> $args
 */
function qrg_solution_admin_url( $args = [] ) {
    $base = [
        'page'      => 'qrg-presensi',
        'perangkat' => '1',
    ];
    return add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) );
}

/**
 * @return array<int,array<string,mixed>>
 */
function qrg_solution_get_logs() {
    $logs = get_option( 'qrg_solution_absensi_logs', [] );
    return is_array( $logs ) ? $logs : [];
}

/**
 * @param array<string,mixed> $entry
 */
function qrg_solution_write_log( $entry ) {
    $logs = qrg_solution_get_logs();
    $entry['time'] = current_time( 'mysql' );
    array_unshift( $logs, $entry );
    $logs = array_slice( $logs, 0, QRG_SOLUTION_LOG_MAX );
    update_option( 'qrg_solution_absensi_logs', $logs, false );
}

function qrg_solution_clear_logs() {
    update_option( 'qrg_solution_absensi_logs', [], false );
}

// -------------------------------------------------------------------------
// Routing /iclock/*
// -------------------------------------------------------------------------
add_action( 'init', 'qrg_solution_rewrite_rules' );
function qrg_solution_rewrite_rules() {
    add_rewrite_rule( '^iclock/?$', 'index.php?qrg_solution_iclock=status', 'top' );
    add_rewrite_rule( '^iclock/cdata/?$', 'index.php?qrg_solution_iclock=cdata', 'top' );
    add_rewrite_rule( '^iclock/getrequest/?$', 'index.php?qrg_solution_iclock=getrequest', 'top' );
    add_rewrite_rule( '^iclock/devicecmd/?$', 'index.php?qrg_solution_iclock=devicecmd', 'top' );
    add_rewrite_rule( '^iclock/registry/?$', 'index.php?qrg_solution_iclock=registry', 'top' );
}

add_filter( 'query_vars', 'qrg_solution_query_vars' );
function qrg_solution_query_vars( $vars ) {
    $vars[] = 'qrg_solution_iclock';
    return $vars;
}

add_action( 'admin_init', 'qrg_solution_flush_rewrite_once' );
function qrg_solution_flush_rewrite_once() {
    if ( get_option( 'qrg_solution_rewrite_flushed_v2' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'qrg_solution_rewrite_flushed_v2', 1 );
}

/**
 * Ambil endpoint iclock dari URI request (tanpa mengandalkan rewrite).
 *
 * @return string|null status|cdata|getrequest|devicecmd|registry
 */
function qrg_solution_parse_iclock_endpoint() {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    $uri = strtok( (string) $uri, '?' );
    $home_path = parse_url( home_url( '/' ), PHP_URL_PATH );
    if ( $home_path && $home_path !== '/' && strpos( $uri, $home_path ) === 0 ) {
        $uri = substr( $uri, strlen( rtrim( $home_path, '/' ) ) );
    }
    $uri = '/' . ltrim( (string) $uri, '/' );
    $uri = rtrim( $uri, '/' );
    if ( $uri === '/iclock' ) {
        return 'status';
    }
    if ( preg_match( '#^/iclock/(cdata|getrequest|devicecmd|registry)$#', $uri, $m ) ) {
        return $m[1];
    }
    return null;
}

add_filter( 'redirect_canonical', 'qrg_solution_disable_canonical', 10, 2 );
function qrg_solution_disable_canonical( $redirect_url, $requested_url ) {
    if ( qrg_solution_parse_iclock_endpoint() ) {
        return false;
    }
    return $redirect_url;
}

add_action( 'template_redirect', 'qrg_solution_template_redirect', 0 );
function qrg_solution_template_redirect() {
    $endpoint = get_query_var( 'qrg_solution_iclock' );
    if ( ! $endpoint ) {
        $endpoint = qrg_solution_parse_iclock_endpoint();
    }
    if ( ! $endpoint ) {
        return;
    }
    qrg_solution_dispatch_iclock( sanitize_key( (string) $endpoint ) );
    exit;
}

add_action( 'init', 'qrg_solution_uri_fallback', 0 );
function qrg_solution_uri_fallback() {
    $endpoint = qrg_solution_parse_iclock_endpoint();
    if ( ! $endpoint ) {
        return;
    }
    qrg_solution_dispatch_iclock( $endpoint );
    exit;
}

function qrg_solution_send_plain_headers( $code = 200 ) {
    status_header( (int) $code );
    nocache_headers();
    header( 'Content-Type: text/plain; charset=UTF-8' );
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
}

function qrg_solution_handle_status() {
    qrg_solution_send_plain_headers( 200 );
    echo "OK\r\n";
    echo "ADMS ready\r\n";
    echo 'cdata=' . qrg_solution_server_url_for_device() . "\r\n";
    echo 'getrequest=' . home_url( '/iclock/getrequest' ) . "\r\n";
    echo 'devicecmd=' . home_url( '/iclock/devicecmd' ) . "\r\n";
    echo 'registry=' . home_url( '/iclock/registry' ) . "\r\n";
}

function qrg_solution_dispatch_iclock( $endpoint ) {
    $settings = qrg_solution_get_settings();
    if ( empty( $settings['enabled'] ) ) {
        qrg_solution_send_plain_headers( 503 );
        echo 'DISABLED';
        return;
    }

    $sn = isset( $_GET['SN'] ) ? sanitize_text_field( wp_unslash( $_GET['SN'] ) ) : '';
    if ( $endpoint !== 'status' ) {
        qrg_solution_touch_device( $sn, qrg_solution_client_ip() );
    }

    switch ( $endpoint ) {
        case 'status':
            qrg_solution_handle_status();
            break;
        case 'cdata':
            qrg_solution_handle_cdata( $sn );
            break;
        case 'getrequest':
            qrg_solution_handle_getrequest( $sn );
            break;
        case 'devicecmd':
            qrg_solution_handle_devicecmd( $sn );
            break;
        case 'registry':
            qrg_solution_handle_registry( $sn );
            break;
        default:
            qrg_solution_send_plain_headers( 404 );
            echo 'NOT FOUND';
    }
}

function qrg_solution_read_body() {
    $raw = file_get_contents( 'php://input' );
    return is_string( $raw ) ? $raw : '';
}

function qrg_solution_handle_registry( $sn ) {
    qrg_solution_write_log( [
        'type' => 'registry',
        'sn'   => $sn,
        'ip'   => qrg_solution_client_ip(),
    ] );
    status_header( 200 );
    header( 'Content-Type: text/plain; charset=UTF-8' );
    echo "OK\r\n";
}

function qrg_solution_get_commands() {
    $cmds = get_option( 'qrg_solution_commands', [] );
    return is_array( $cmds ) ? $cmds : [];
}

/**
 * @return int Command ID
 */
function qrg_solution_queue_command( $sn, $command ) {
    $sn      = sanitize_text_field( (string) $sn );
    $command = sanitize_text_field( (string) $command );
    if ( $sn === '' || $command === '' ) {
        return 0;
    }
    $id = (int) get_option( 'qrg_solution_cmd_seq', 1 );
    if ( $id < 1 ) {
        $id = 1;
    }
    update_option( 'qrg_solution_cmd_seq', $id + 1, false );
    $cmds   = qrg_solution_get_commands();
    $cmds[] = [
        'id'      => $id,
        'sn'      => $sn,
        'cmd'     => $command,
        'created' => time(),
    ];
    update_option( 'qrg_solution_commands', $cmds, false );
    return $id;
}

/**
 * @return array<int,array<string,mixed>>
 */
function qrg_solution_take_commands( $sn ) {
    $sn      = sanitize_text_field( (string) $sn );
    $all     = qrg_solution_get_commands();
    $taken   = [];
    $remain  = [];
    foreach ( $all as $row ) {
        if ( $sn !== '' && strcasecmp( (string) ( $row['sn'] ?? '' ), $sn ) === 0 ) {
            $taken[] = $row;
        } else {
            $remain[] = $row;
        }
    }
    if ( ! empty( $taken ) ) {
        update_option( 'qrg_solution_commands', $remain, false );
    }
    return $taken;
}

function qrg_solution_users_store() {
    $data = get_option( 'qrg_solution_device_users', [] );
    return is_array( $data ) ? $data : [];
}

/**
 * @return array{updated:string,source:string,pending:bool,users:array}
 */
function qrg_solution_get_device_users( $device_id ) {
    $empty = [
        'updated' => '',
        'source'  => '',
        'pending' => false,
        'users'   => [],
    ];
    $all = qrg_solution_users_store();
    $id  = sanitize_key( (string) $device_id );
    if ( $id !== '' && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ) {
        return array_merge( $empty, $all[ $id ] );
    }
    $device = $id !== '' ? qrg_solution_get_device( $id ) : null;
    if ( $device && $device['sn'] !== '' ) {
        $key = 'sn_' . $device['sn'];
        if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) {
            return array_merge( $empty, $all[ $key ] );
        }
    }
    return $empty;
}

/**
 * @param array<int,array<string,string>> $users
 */
function qrg_solution_save_device_users( $device_id, $users, $source = 'adms', $sn = '' ) {
    $all = qrg_solution_users_store();
    $id  = sanitize_key( (string) $device_id );
    $payload = [
        'updated' => current_time( 'mysql' ),
        'source'  => sanitize_key( (string) $source ),
        'pending' => false,
        'users'   => array_values( $users ),
    ];
    if ( $id !== '' ) {
        $all[ $id ] = $payload;
    }
    if ( $sn === '' && $id !== '' ) {
        $device = qrg_solution_get_device( $id );
        $sn     = $device ? (string) $device['sn'] : '';
    }
    if ( $sn !== '' ) {
        $all[ 'sn_' . $sn ] = $payload;
        if ( $id === '' ) {
            $idx = qrg_solution_find_device_index_by_sn( $sn );
            if ( $idx >= 0 ) {
                $devices = qrg_solution_get_devices();
                $all[ $devices[ $idx ]['id'] ] = $payload;
            }
        }
    }
    update_option( 'qrg_solution_device_users', $all, false );
}

function qrg_solution_mark_users_pending( $device_id, $pending = true ) {
    $id   = sanitize_key( (string) $device_id );
    $data = qrg_solution_get_device_users( $id );
    $data['pending'] = (bool) $pending;
    $all  = qrg_solution_users_store();
    $all[ $id ] = $data;
    update_option( 'qrg_solution_device_users', $all, false );
}

function qrg_solution_field_from_line( $line, $keys ) {
    foreach ( (array) $keys as $key ) {
        if ( preg_match( '/(?:^|[\s\t])' . preg_quote( $key, '/' ) . '=([^\t]*)/i', $line, $m ) ) {
            return trim( $m[1] );
        }
    }
    return '';
}

/**
 * @return array<int,array{pin:string,nama:string,card:string,privilege:string}>
 */
function qrg_solution_parse_userinfo( $body ) {
    $users = [];
    $seen  = [];
    $lines = preg_split( '/\r\n|\r|\n/', (string) $body );
    foreach ( $lines as $line ) {
        $line = trim( (string) $line );
        if ( $line === '' ) {
            continue;
        }
        $line = preg_replace( '/^USER\s+/i', '', $line );
        $pin  = qrg_solution_field_from_line( $line, [ 'PIN2', 'PIN' ] );
        if ( $pin === '' && preg_match( '/^(\d+)\t/', $line, $m ) ) {
            $pin = $m[1];
        }
        if ( $pin === '' || isset( $seen[ $pin ] ) ) {
            continue;
        }
        $seen[ $pin ] = true;
        $users[]      = [
            'pin'       => $pin,
            'nama'      => qrg_solution_field_from_line( $line, [ 'Name', 'Nama' ] ),
            'card'      => qrg_solution_field_from_line( $line, [ 'Card', 'CardNo' ] ),
            'privilege' => qrg_solution_field_from_line( $line, [ 'Pri', 'Privilege', 'Priv' ] ),
        ];
    }
    return $users;
}

/**
 * @return array<int,array{pin:string,nama:string,card:string,privilege:string}>
 */
function qrg_solution_parse_soap_users( $xml ) {
    $users = [];
    $seen  = [];
    if ( ! preg_match_all( '/<Row\b[^>]*>(.*?)<\/Row>/is', (string) $xml, $rows ) ) {
        return $users;
    }
    foreach ( $rows[1] as $row ) {
        $get = static function ( $tag ) use ( $row ) {
            if ( preg_match( '/<' . $tag . '\b[^>]*>([^<]*)<\/' . $tag . '>/i', $row, $m ) ) {
                return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
            }
            return '';
        };
        $pin = $get( 'PIN2' ) !== '' ? $get( 'PIN2' ) : $get( 'PIN' );
        if ( $pin === '' || isset( $seen[ $pin ] ) ) {
            continue;
        }
        $seen[ $pin ] = true;
        $users[]      = [
            'pin'       => $pin,
            'nama'      => $get( 'Name' ),
            'card'      => $get( 'Card' ),
            'privilege' => $get( 'Privilege' ) !== '' ? $get( 'Privilege' ) : $get( 'Pri' ),
        ];
    }
    return $users;
}

function qrg_solution_privilege_label( $priv ) {
    $map = [
        '0'  => 'User',
        '2'  => 'Enroller',
        '6'  => 'Admin',
        '10' => 'User',
        '14' => 'Super Admin',
    ];
    $priv = (string) $priv;
    return isset( $map[ $priv ] ) ? $map[ $priv ] : ( $priv !== '' ? $priv : 'User' );
}

/**
 * Izinkan wp_remote_* ke IP mesin lokal (WP default memblokir 192.168.x.x).
 */
add_filter( 'http_request_host_is_external', 'qrg_solution_allow_device_http', 10, 3 );
function qrg_solution_allow_device_http( $allow, $host, $url ) {
    $host = (string) $host;
    if ( $host === '' || ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
        return $allow;
    }
    foreach ( qrg_solution_get_devices() as $device ) {
        if ( ! empty( $device['ip'] ) && $device['ip'] === $host ) {
            return true;
        }
    }
    // Izinkan saat tes IP yang sedang diinput di form (belum tersimpan).
    if ( doing_action( 'wp_ajax_qrg_solution_test_device' ) ) {
        return true;
    }
    return $allow;
}

/**
 * POST HTTP ke mesin — wp_remote dulu, fallback cURL jika WP memblokir IP privat.
 *
 * @param array<string,string> $headers
 * @return array{code:int,body:string}|WP_Error
 */
function qrg_solution_device_http_post( $url, $body, $headers = [], $timeout = 15 ) {
    $args = [
        'timeout'   => $timeout,
        'sslverify' => false,
        'headers'   => $headers,
        'body'      => $body,
    ];
    $res = wp_remote_post( $url, $args );
    if ( ! is_wp_error( $res ) ) {
        return [
            'code' => (int) wp_remote_retrieve_response_code( $res ),
            'body' => (string) wp_remote_retrieve_body( $res ),
        ];
    }

    if ( ! function_exists( 'curl_init' ) ) {
        return $res;
    }

    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => array_map(
            static function ( $k, $v ) {
                return $k . ': ' . $v;
            },
            array_keys( $headers ),
            array_values( $headers )
        ),
    ] );
    $raw  = curl_exec( $ch );
    $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $err  = curl_error( $ch );
    curl_close( $ch );

    if ( $raw === false ) {
        return new WP_Error( 'curl_fail', $err !== '' ? $err : 'Gagal menghubungi mesin.' );
    }
    return [
        'code' => $code,
        'body' => (string) $raw,
    ];
}

/**
 * @param array<string,string> $device
 * @return array<int,array{pin:string,nama:string,card:string,privilege:string}>|WP_Error
 */
function qrg_solution_soap_get_users( $ip, $com_key = 0 ) {
    $ip = trim( (string) $ip );
    if ( $ip === '' || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        return new WP_Error( 'invalid_ip', 'Alamat IP tidak valid.' );
    }
    // ComKey 0 biasanya kunci komunikasi default mesin Solution; password admin (16020) sering mengembalikan kosong.
    $keys = [];
    foreach ( [ 0, (int) $com_key ] as $k ) {
        if ( ! in_array( $k, $keys, true ) ) {
            $keys[] = $k;
        }
    }
    $last = new WP_Error( 'soap_fail', 'Mesin tidak mengirim daftar user.' );
    foreach ( $keys as $key ) {
        $xml  = '<GetUserInfo><ArgComKey xsi:type="xsd:integer">' . (int) $key . '</ArgComKey><Arg><PIN xsi:type="xsd:integer">All</PIN></Arg></GetUserInfo>';
        $http = qrg_solution_device_http_post(
            'http://' . $ip . '/iWsService',
            $xml,
            [ 'Content-Type' => 'text/xml' ],
            20
        );
        if ( is_wp_error( $http ) ) {
            $last = $http;
            continue;
        }
        if ( (int) $http['code'] >= 400 || $http['body'] === '' ) {
            $last = new WP_Error( 'soap_fail', 'Mesin tidak mengirim daftar user (HTTP ' . (int) $http['code'] . ').' );
            continue;
        }
        $users = qrg_solution_parse_soap_users( $http['body'] );
        if ( ! empty( $users ) ) {
            return $users;
        }
        $last = new WP_Error( 'soap_empty', 'Respons mesin tidak berisi user (ComKey ' . (int) $key . ').' );
    }
    return $last;
}

/**
 * Login web mesin (user/password admin) lalu coba ambil daftar user.
 *
 * @param array<string,string> $device
 * @return array<int,array{pin:string,nama:string,card:string,privilege:string}>|WP_Error
 */
function qrg_solution_web_get_users( $device ) {
    $ip = trim( (string) ( $device['ip'] ?? '' ) );
    if ( $ip === '' || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        return new WP_Error( 'invalid_ip', 'Alamat IP tidak valid.' );
    }
    $cred = qrg_solution_get_admin_credentials( $device );
    $user = $cred['user'];
    $pass = $cred['pass'];
    $cookie = '';

    $login_paths = [
        '/cgi-bin/login.cgi',
        '/csl/check',
        '/cgi-bin/login',
    ];
    foreach ( $login_paths as $path ) {
        $res = wp_remote_post( 'http://' . $ip . $path, [
            'timeout'   => 8,
            'sslverify' => false,
            'redirection' => 0,
            'body'      => [
                'username' => $user,
                'userpwd'  => $pass,
                'password' => $pass,
                'pwd'      => $pass,
            ],
        ] );
        if ( is_wp_error( $res ) ) {
            continue;
        }
        $cookies = wp_remote_retrieve_cookies( $res );
        if ( ! empty( $cookies ) ) {
            $parts = [];
            foreach ( $cookies as $c ) {
                if ( is_object( $c ) && isset( $c->name, $c->value ) ) {
                    $parts[] = $c->name . '=' . $c->value;
                }
            }
            if ( $parts ) {
                $cookie = implode( '; ', $parts );
                break;
            }
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code >= 200 && $code < 400 ) {
            $cookie = 'ok';
            break;
        }
    }

    // Coba SOAP dengan ComKey dari password admin (banyak mesin Solution memakai ini).
    $com_key = ctype_digit( (string) $pass ) ? (int) $pass : 0;
    $soap    = qrg_solution_soap_get_users( $ip, $com_key );
    if ( ! is_wp_error( $soap ) && ! empty( $soap ) ) {
        return $soap;
    }

    if ( $cookie === '' ) {
        return is_wp_error( $soap ) ? $soap : new WP_Error( 'login_fail', 'Login admin mesin gagal (user ' . $user . ').' );
    }

    return is_wp_error( $soap ) ? $soap : new WP_Error( 'web_empty', 'Login berhasil, tetapi daftar user belum bisa dibaca lewat web.' );
}

/**
 * @param array<string,string> $device
 * @return array{ok:bool,source:string,pending:bool,count:int,message:string}|WP_Error
 */
function qrg_solution_fetch_device_users( $device ) {
    if ( ! is_array( $device ) || empty( $device['id'] ) ) {
        return new WP_Error( 'no_device', 'Mesin tidak ditemukan.' );
    }

    $cred = qrg_solution_get_admin_credentials( $device );

    if ( ! empty( $device['ip'] ) ) {
        $com_key = ctype_digit( (string) $cred['pass'] ) ? (int) $cred['pass'] : 0;
        $soap    = qrg_solution_soap_get_users( $device['ip'], $com_key );
        if ( is_wp_error( $soap ) || empty( $soap ) ) {
            $soap = qrg_solution_web_get_users( $device );
        }
        if ( ! is_wp_error( $soap ) && ! empty( $soap ) ) {
            // Pastikan admin login terlihat sebagai admin di daftar.
            foreach ( $soap as &$u ) {
                if ( qrg_solution_is_admin_login_pin( $u['pin'] ?? '', $device ) ) {
                    $u['privilege'] = '14';
                    if ( empty( $u['nama'] ) ) {
                        $u['nama'] = 'Admin mesin';
                    }
                }
            }
            unset( $u );
            qrg_solution_save_device_users( $device['id'], $soap, 'soap', (string) $device['sn'] );
            return [
                'ok'      => true,
                'source'  => 'soap',
                'pending' => false,
                'count'   => count( $soap ),
                'message' => count( $soap ) . ' user diambil dari mesin (login admin ' . $cred['user'] . ').',
            ];
        }
    }

    $sn = sanitize_text_field( (string) ( $device['sn'] ?? '' ) );
    if ( $sn === '' ) {
        return new WP_Error(
            'no_sn',
            'Tidak bisa mengambil user: IP mesin tidak terjangkau dari server, dan Serial Number belum terisi. Isi SN atau pastikan mesin sudah connect ke ADMS. Admin login mesin: ' . $cred['user'] . '.'
        );
    }

    qrg_solution_queue_command( $sn, 'DATA QUERY USERINFO' );
    qrg_solution_mark_users_pending( $device['id'], true );
    qrg_solution_write_log( [
        'type'  => 'query_user',
        'sn'    => $sn,
        'mesin' => (string) $device['nama'],
        'msg'   => 'Perintah DATA QUERY USERINFO dikirim, menunggu mesin.',
    ] );

    $cached = qrg_solution_get_device_users( $device['id'] );
    return [
        'ok'      => true,
        'source'  => 'adms',
        'pending' => true,
        'count'   => count( $cached['users'] ),
        'message' => 'Perintah dikirim ke mesin. Daftar user muncul setelah mesin polling server (biasanya beberapa detik).',
    ];
}

function qrg_solution_handle_getrequest( $sn ) {
    $cmds = qrg_solution_take_commands( $sn );
    status_header( 200 );
    header( 'Content-Type: text/plain; charset=UTF-8' );
    if ( empty( $cmds ) ) {
        echo "OK\r\n";
        return;
    }
    foreach ( $cmds as $row ) {
        echo 'C:' . (int) $row['id'] . ':' . (string) $row['cmd'] . "\n";
    }
}

function qrg_solution_handle_devicecmd( $sn ) {
    $body = qrg_solution_read_body();
    qrg_solution_write_log( [
        'type' => 'devicecmd',
        'sn'   => $sn,
        'body' => substr( $body, 0, 500 ),
        'ip'   => qrg_solution_client_ip(),
    ] );
    status_header( 200 );
    header( 'Content-Type: text/plain; charset=UTF-8' );
    echo "OK\r\n";
}

function qrg_solution_handle_cdata( $sn ) {
    $table  = isset( $_GET['table'] ) ? sanitize_text_field( wp_unslash( $_GET['table'] ) ) : '';
    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

    status_header( 200 );
    header( 'Content-Type: text/plain; charset=UTF-8' );

    if ( $method === 'GET' && ( $table === '' || isset( $_GET['options'] ) ) ) {
        $settings = qrg_solution_get_settings();
        $tz       = preg_replace( '/[^0-9\-]/', '', (string) $settings['timezone'] );
        if ( $tz === '' ) {
            $tz = '7';
        }
        echo "GET OPTION FROM: " . ( $sn !== '' ? $sn : 'Solution' ) . "\r\n";
        echo "ATTLOGStamp=0\r\n";
        echo "OPERLOGStamp=0\r\n";
        echo "ATTPHOTOStamp=0\r\n";
        echo "ErrorDelay=60\r\n";
        echo "Delay=10\r\n";
        echo "TransTimes=00:00;23:59\r\n";
        echo "TransInterval=1\r\n";
        echo "TransFlag=TransData AttLog OpLog EnrollUser\r\n";
        echo "TimeZone=" . $tz . "\r\n";
        echo "Realtime=1\r\n";
        echo "Encrypt=0\r\n";
        qrg_solution_write_log( [
            'type' => 'options',
            'sn'   => $sn,
            'ip'   => qrg_solution_client_ip(),
        ] );
        return;
    }

    if ( strtoupper( $table ) !== 'ATTLOG' && strtoupper( $table ) !== 'OPERLOG' && strtoupper( $table ) !== 'USER' && strtoupper( $table ) !== 'USERINFO' ) {
        if ( $method === 'POST' || $method === 'GET' ) {
            echo "OK\r\n";
        }
        return;
    }

    $body = qrg_solution_read_body();
    if ( $body === '' && isset( $_GET['content'] ) ) {
        $body = wp_unslash( (string) $_GET['content'] );
    }

    if ( strtoupper( $table ) === 'ATTLOG' && $body !== '' ) {
        $records = qrg_solution_parse_attlog( $body );
        $saved   = qrg_solution_process_attendance_records( $records, $sn );
        qrg_solution_write_log( [
            'type'    => 'attlog',
            'sn'      => $sn,
            'mesin'   => qrg_solution_device_label( $sn, qrg_solution_client_ip() ),
            'count'   => count( $records ),
            'saved'   => $saved,
            'ip'      => qrg_solution_client_ip(),
            'preview' => substr( str_replace( [ "\r", "\n" ], ' ', $body ), 0, 200 ),
        ] );
        echo 'OK:' . (int) $saved . "\r\n";
        return;
    }

    if ( in_array( strtoupper( $table ), [ 'USER', 'USERINFO' ], true ) && $body !== '' ) {
        $users = qrg_solution_parse_userinfo( $body );
        if ( ! empty( $users ) ) {
            $idx     = qrg_solution_find_device_index_by_sn( $sn );
            $devices = qrg_solution_get_devices();
            $device  = $idx >= 0 ? $devices[ $idx ] : null;
            foreach ( $users as &$u ) {
                if ( qrg_solution_is_admin_login_pin( $u['pin'] ?? '', $device ) ) {
                    $u['privilege'] = '14';
                    if ( empty( $u['nama'] ) ) {
                        $u['nama'] = 'Admin mesin';
                    }
                }
            }
            unset( $u );
            qrg_solution_save_device_users( '', $users, 'adms', $sn );
            qrg_solution_write_log( [
                'type'  => 'userinfo',
                'sn'    => $sn,
                'mesin' => qrg_solution_device_label( $sn, qrg_solution_client_ip() ),
                'count' => count( $users ),
                'ip'    => qrg_solution_client_ip(),
            ] );
            echo 'OK:' . count( $users ) . "\r\n";
            return;
        }
    }

    qrg_solution_write_log( [
        'type'  => 'cdata',
        'sn'    => $sn,
        'table' => $table,
        'ip'    => qrg_solution_client_ip(),
    ] );
    echo "OK\r\n";
}

/**
 * @return array<int,array{pin:string,datetime:string,inout:int,status:int}>
 */
function qrg_solution_parse_attlog( $body ) {
    $lines   = preg_split( '/\r\n|\r|\n/', trim( $body ) );
    $records = [];

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line === '' ) {
            continue;
        }

        if ( stripos( $line, 'PIN=' ) !== false ) {
            $pin = '';
            $dt  = '';
            $io  = 0;
            if ( preg_match( '/PIN=([^\s\t]+)/i', $line, $m ) ) {
                $pin = trim( $m[1] );
            }
            if ( preg_match( '/DateTime=([^\s\t]+(?:\s[^\s\t]+)?)/i', $line, $m ) ) {
                $dt = trim( $m[1] );
            } elseif ( preg_match( '/\d{4}[-\/]\d{2}[-\/]\d{2}\s+\d{2}:\d{2}:\d{2}/', $line, $m ) ) {
                $dt = $m[0];
            }
            if ( preg_match( '/InOut=(\d+)/i', $line, $m ) ) {
                $io = (int) $m[1];
            }
            if ( $pin !== '' && $dt !== '' ) {
                $records[] = [
                    'pin'      => $pin,
                    'datetime' => qrg_solution_normalize_datetime( $dt ),
                    'inout'    => $io,
                    'status'   => 0,
                ];
            }
            continue;
        }

        $parts = preg_split( '/\t+/', $line );
        if ( count( $parts ) < 2 ) {
            $parts = preg_split( '/\s+/', $line );
        }
        if ( count( $parts ) < 2 ) {
            continue;
        }

        $pin = trim( (string) $parts[0] );
        $dt  = '';
        if ( preg_match( '/\d{4}[-\/]\d{2}[-\/]\d{2}\s+\d{2}:\d{2}:\d{2}/', $line, $m ) ) {
            $dt = $m[0];
        } elseif ( isset( $parts[1] ) && preg_match( '/\d/', $parts[1] ) ) {
            $dt = trim( $parts[1] );
            if ( isset( $parts[2] ) && preg_match( '/^\d{2}:\d{2}:\d{2}$/', trim( $parts[2] ) ) ) {
                $dt .= ' ' . trim( $parts[2] );
            }
        }

        $io = isset( $parts[4] ) ? (int) $parts[4] : ( isset( $parts[3] ) ? (int) $parts[3] : 0 );

        if ( $pin !== '' && $dt !== '' ) {
            $records[] = [
                'pin'      => $pin,
                'datetime' => qrg_solution_normalize_datetime( $dt ),
                'inout'    => $io,
                'status'   => isset( $parts[2] ) ? (int) $parts[2] : 0,
            ];
        }
    }

    return $records;
}

function qrg_solution_normalize_datetime( $raw ) {
    $raw = str_replace( '/', '-', trim( $raw ) );
    $ts  = strtotime( $raw );
    if ( ! $ts ) {
        return '';
    }
    return date( 'Y-m-d H:i:s', $ts );
}

/**
 * @param array<int,array{pin:string,datetime:string,inout:int,status:int}> $records
 */
function qrg_solution_process_attendance_records( $records, $sn ) {
    $saved = 0;
    foreach ( $records as $row ) {
        if ( qrg_solution_apply_attendance_row( $row, $sn ) ) {
            $saved++;
        }
    }
    return $saved;
}

/**
 * @param array{pin:string,datetime:string,inout:int,status:int} $row
 */
function qrg_solution_apply_attendance_row( $row, $sn ) {
    $pin = trim( (string) $row['pin'] );
    $dt  = (string) $row['datetime'];
    if ( $pin === '' || $dt === '' ) {
        return false;
    }

    $guru_id = qrg_solution_find_guru_by_pin( $pin );
    if ( $guru_id <= 0 ) {
        qrg_solution_write_log( [
            'type' => 'skip',
            'msg'  => 'PIN ' . $pin . ' tidak cocok dengan guru manapun',
            'sn'   => $sn,
        ] );
        return false;
    }

    $log_id = md5( $sn . '|' . $pin . '|' . $dt );
    if ( qrg_solution_log_already_processed( $log_id ) ) {
        return false;
    }

    $tanggal = substr( $dt, 0, 10 );
    $jam     = substr( $dt, 11, 8 );
    $inout   = (int) $row['inout'];

    $post_id = qrg_solution_upsert_presensi( $guru_id, $tanggal, $jam, $inout, [
        'device_sn' => $sn,
        'log_id'    => $log_id,
        'pin'       => $pin,
    ] );

    if ( $post_id > 0 ) {
        qrg_solution_mark_log_processed( $log_id );
        return true;
    }
    return false;
}

function qrg_solution_find_guru_by_pin( $pin ) {
    $pin = trim( (string) $pin );
    if ( $pin === '' ) {
        return 0;
    }

    $by_meta = get_posts( [
        'post_type'      => 'master_guru',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_guru_pin_absensi',
                'value' => $pin,
            ],
        ],
    ] );
    if ( ! empty( $by_meta[0] ) ) {
        return (int) $by_meta[0];
    }

    $by_nip = get_posts( [
        'post_type'      => 'master_guru',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_guru_nip',
                'value' => $pin,
            ],
        ],
    ] );
    if ( ! empty( $by_nip[0] ) ) {
        return (int) $by_nip[0];
    }

    return 0;
}

/**
 * Ambil record presensi untuk PIN mesin (via guru terhubung atau meta PIN perangkat).
 *
 * @param array{bulan?:int,tahun?:int,limit?:int} $args
 * @return array{guru_id:int,items:array<int,array<string,mixed>>}
 */
function qrg_solution_get_presensi_by_pin( $pin, $args = [] ) {
    $pin   = trim( (string) $pin );
    $empty = [ 'guru_id' => 0, 'items' => [] ];
    if ( $pin === '' ) {
        return $empty;
    }

    $bulan = isset( $args['bulan'] ) ? (int) $args['bulan'] : (int) current_time( 'n' );
    $tahun = isset( $args['tahun'] ) ? (int) $args['tahun'] : (int) current_time( 'Y' );
    $limit = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 60;
    if ( $bulan < 1 || $bulan > 12 ) {
        $bulan = (int) current_time( 'n' );
    }
    if ( $tahun < 2000 || $tahun > 2100 ) {
        $tahun = (int) current_time( 'Y' );
    }

    $guru_id = qrg_solution_find_guru_by_pin( $pin );
    $prefix  = sprintf( '%04d-%02d', $tahun, $bulan );

    $meta_or = [
        'relation' => 'OR',
        [
            'key'   => '_presensi_device_pin',
            'value' => $pin,
        ],
    ];
    if ( $guru_id > 0 ) {
        $meta_or[] = [
            'key'   => '_presensi_guru_id',
            'value' => (string) $guru_id,
        ];
    }

    $ids = get_posts( [
        'post_type'      => 'master_presensi_guru',
        'posts_per_page' => $limit,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'orderby'        => 'meta_value',
        'meta_key'       => '_presensi_tanggal',
        'order'          => 'DESC',
        'meta_query'     => [
            'relation' => 'AND',
            $meta_or,
            [
                'key'     => '_presensi_tanggal',
                'value'   => $prefix,
                'compare' => 'LIKE',
            ],
        ],
    ] );

    $items = [];
    $seen  = [];
    foreach ( $ids as $pid ) {
        $pid = (int) $pid;
        if ( isset( $seen[ $pid ] ) ) {
            continue;
        }
        $seen[ $pid ] = true;
        $tanggal = (string) get_post_meta( $pid, '_presensi_tanggal', true );
        $items[] = [
            'id'         => $pid,
            'tanggal'    => $tanggal,
            'jam_masuk'  => (string) get_post_meta( $pid, '_presensi_jam_masuk', true ),
            'jam_pulang' => (string) get_post_meta( $pid, '_presensi_jam_pulang', true ),
            'status'     => (string) get_post_meta( $pid, '_presensi_status', true ),
            'via'        => (string) get_post_meta( $pid, '_presensi_via', true ),
            'device'     => (string) get_post_meta( $pid, '_presensi_device_label', true ),
            'sn'         => (string) get_post_meta( $pid, '_presensi_device_sn', true ),
            'edit_url'   => get_edit_post_link( $pid, 'raw' ),
        ];
    }

    return [
        'guru_id' => $guru_id,
        'items'   => $items,
        'bulan'   => $bulan,
        'tahun'   => $tahun,
    ];
}

function qrg_solution_find_presensi_post( $guru_id, $tanggal ) {
    $ids = get_posts( [
        'post_type'      => 'master_presensi_guru',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => '_presensi_guru_id',
                'value' => (string) (int) $guru_id,
            ],
            [
                'key'   => '_presensi_tanggal',
                'value' => $tanggal,
            ],
        ],
    ] );
    return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
}

/**
 * @param array<string,string> $meta
 */
function qrg_solution_upsert_presensi( $guru_id, $tanggal, $jam, $inout, $meta = [] ) {
    $guru_id = (int) $guru_id;
    if ( $guru_id <= 0 || $tanggal === '' || $jam === '' ) {
        return 0;
    }

    $post_id   = qrg_solution_find_presensi_post( $guru_id, $tanggal );
    $jam_masuk = '';
    $jam_pulang = '';

    if ( $post_id > 0 ) {
        $jam_masuk  = (string) get_post_meta( $post_id, '_presensi_jam_masuk', true );
        $jam_pulang = (string) get_post_meta( $post_id, '_presensi_jam_pulang', true );
    }

    if ( $inout === 1 ) {
        $jam_pulang = $jam;
        if ( $jam_masuk === '' ) {
            $jam_masuk = $jam;
        }
    } else {
        if ( $jam_masuk === '' || strcmp( $jam, $jam_masuk ) < 0 ) {
            $jam_masuk = $jam;
        }
        if ( $jam_pulang === '' || ( $jam_masuk !== $jam && strcmp( $jam, $jam_pulang ) > 0 ) ) {
            if ( $jam !== $jam_masuk ) {
                $jam_pulang = $jam;
            }
        }
    }

    $nama_guru = get_the_title( $guru_id );
    $tgl_label = date_i18n( 'd M Y', strtotime( $tanggal ) );
    $title     = trim( sprintf( 'Presensi %s — %s (Hadir)', $nama_guru, $tgl_label ) );

    if ( $post_id <= 0 ) {
        $post_id = wp_insert_post( [
            'post_type'   => 'master_presensi_guru',
            'post_status' => 'publish',
            'post_title'  => $title,
        ], true );
        if ( is_wp_error( $post_id ) ) {
            return 0;
        }
        $post_id = (int) $post_id;
    } else {
        wp_update_post( [
            'ID'         => $post_id,
            'post_title' => $title,
        ] );
    }

    update_post_meta( $post_id, '_presensi_guru_id', $guru_id );
    update_post_meta( $post_id, '_presensi_tanggal', $tanggal );
    update_post_meta( $post_id, '_presensi_status', 'Hadir' );
    update_post_meta( $post_id, '_presensi_jam_masuk', $jam_masuk );
    update_post_meta( $post_id, '_presensi_jam_pulang', $jam_pulang );
    update_post_meta( $post_id, '_presensi_via', 'mesin' );
    update_post_meta( $post_id, '_presensi_keterangan', 'Sinkron mesin Solution' );

    if ( ! empty( $meta['device_sn'] ) ) {
        update_post_meta( $post_id, '_presensi_device_sn', sanitize_text_field( $meta['device_sn'] ) );
    }
    if ( ! empty( $meta['log_id'] ) ) {
        update_post_meta( $post_id, '_presensi_device_log_id', sanitize_text_field( $meta['log_id'] ) );
    }
    if ( ! empty( $meta['pin'] ) ) {
        update_post_meta( $post_id, '_presensi_device_pin', sanitize_text_field( $meta['pin'] ) );
    }
    if ( ! empty( $meta['device_sn'] ) ) {
        update_post_meta( $post_id, '_presensi_device_label', qrg_solution_device_label( $meta['device_sn'] ) );
    }

    return $post_id;
}

function qrg_solution_log_already_processed( $log_id ) {
    $processed = get_option( 'qrg_solution_processed_logs', [] );
    if ( ! is_array( $processed ) ) {
        $processed = [];
    }
    return isset( $processed[ $log_id ] );
}

function qrg_solution_mark_log_processed( $log_id ) {
    $processed = get_option( 'qrg_solution_processed_logs', [] );
    if ( ! is_array( $processed ) ) {
        $processed = [];
    }
    $processed[ $log_id ] = time();
    if ( count( $processed ) > 5000 ) {
        asort( $processed );
        $processed = array_slice( $processed, -3000, null, true );
    }
    update_option( 'qrg_solution_processed_logs', $processed, false );
}

function qrg_solution_client_ip() {
    return function_exists( 'qrg_get_client_ip' ) ? qrg_get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
}

// -------------------------------------------------------------------------
// Admin: panel di halaman Presensi
// -------------------------------------------------------------------------
function qrg_solution_handle_admin_post() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return false;
    }

    if ( isset( $_POST['qrg_solution_save'] ) && check_admin_referer( 'qrg_solution_settings', 'qrg_solution_nonce' ) ) {
        qrg_solution_save_settings( [
            'enabled'    => isset( $_POST['solution_enabled'] ),
            'timezone'   => isset( $_POST['solution_timezone'] ) ? wp_unslash( $_POST['solution_timezone'] ) : '7',
            'admin_user' => isset( $_POST['solution_admin_user'] ) ? wp_unslash( $_POST['solution_admin_user'] ) : '16020',
            'admin_pass' => isset( $_POST['solution_admin_pass'] ) ? wp_unslash( $_POST['solution_admin_pass'] ) : '16020',
        ] );
        wp_safe_redirect( qrg_solution_admin_url( [ 'tab' => 'pengaturan', 'updated' => '1' ] ) );
        exit;
    }

    if ( isset( $_POST['qrg_solution_add_device'] ) && check_admin_referer( 'qrg_solution_device_add', 'qrg_solution_device_add_nonce' ) ) {
        $devices   = qrg_solution_get_devices();
        $devices[] = qrg_solution_sanitize_device( [
            'nama'       => isset( $_POST['device_nama'] ) ? wp_unslash( $_POST['device_nama'] ) : '',
            'ip'         => isset( $_POST['device_ip'] ) ? wp_unslash( $_POST['device_ip'] ) : '',
            'sn'         => isset( $_POST['device_sn'] ) ? wp_unslash( $_POST['device_sn'] ) : '',
            'lokasi'     => isset( $_POST['device_lokasi'] ) ? wp_unslash( $_POST['device_lokasi'] ) : '',
            'admin_user' => isset( $_POST['device_admin_user'] ) ? wp_unslash( $_POST['device_admin_user'] ) : '16020',
            'admin_pass' => isset( $_POST['device_admin_pass'] ) ? wp_unslash( $_POST['device_admin_pass'] ) : '16020',
        ] );
        qrg_solution_save_devices( $devices );
        wp_safe_redirect( qrg_solution_admin_url( [ 'tab' => 'mesin', 'added' => '1' ] ) );
        exit;
    }

    if ( isset( $_POST['qrg_solution_update_devices'] ) && check_admin_referer( 'qrg_solution_devices', 'qrg_solution_devices_nonce' ) ) {
        $posted  = isset( $_POST['devices'] ) && is_array( $_POST['devices'] ) ? wp_unslash( $_POST['devices'] ) : [];
        $current = qrg_solution_get_devices();
        $by_id   = [];
        foreach ( $current as $device ) {
            $by_id[ $device['id'] ] = $device;
        }
        $next = [];
        foreach ( $posted as $id => $row ) {
            $id = sanitize_key( (string) $id );
            if ( $id === '' || ! isset( $by_id[ $id ] ) || ! is_array( $row ) ) {
                continue;
            }
            $next[] = qrg_solution_sanitize_device( [
                'id'         => $id,
                'nama'       => $row['nama'] ?? $by_id[ $id ]['nama'],
                'ip'         => $row['ip'] ?? $by_id[ $id ]['ip'],
                'sn'         => $row['sn'] ?? $by_id[ $id ]['sn'],
                'lokasi'     => $row['lokasi'] ?? $by_id[ $id ]['lokasi'],
                'admin_user' => $row['admin_user'] ?? ( $by_id[ $id ]['admin_user'] ?? '16020' ),
                'admin_pass' => $row['admin_pass'] ?? ( $by_id[ $id ]['admin_pass'] ?? '16020' ),
                'enabled'    => ! empty( $row['enabled'] ) ? '1' : '0',
                'last_seen'  => $by_id[ $id ]['last_seen'],
            ] );
        }
        qrg_solution_save_devices( $next );
        wp_safe_redirect( qrg_solution_admin_url( [ 'tab' => 'mesin', 'updated' => '1' ] ) );
        exit;
    }

    if ( isset( $_POST['qrg_solution_update_device'] ) && check_admin_referer( 'qrg_solution_device_edit', 'qrg_solution_device_edit_nonce' ) ) {
        $edit_id = isset( $_POST['device_id'] ) ? sanitize_key( wp_unslash( $_POST['device_id'] ) ) : '';
        $devices = qrg_solution_get_devices();
        $found   = false;
        foreach ( $devices as $i => $device ) {
            if ( $device['id'] !== $edit_id ) {
                continue;
            }
            $found = true;
            $devices[ $i ] = qrg_solution_sanitize_device( [
                'id'         => $edit_id,
                'nama'       => isset( $_POST['device_nama'] ) ? wp_unslash( $_POST['device_nama'] ) : $device['nama'],
                'ip'         => isset( $_POST['device_ip'] ) ? wp_unslash( $_POST['device_ip'] ) : $device['ip'],
                'sn'         => isset( $_POST['device_sn'] ) ? wp_unslash( $_POST['device_sn'] ) : $device['sn'],
                'lokasi'     => isset( $_POST['device_lokasi'] ) ? wp_unslash( $_POST['device_lokasi'] ) : $device['lokasi'],
                'admin_user' => isset( $_POST['device_admin_user'] ) ? wp_unslash( $_POST['device_admin_user'] ) : ( $device['admin_user'] ?? '16020' ),
                'admin_pass' => isset( $_POST['device_admin_pass'] ) ? wp_unslash( $_POST['device_admin_pass'] ) : ( $device['admin_pass'] ?? '16020' ),
                'enabled'    => ! empty( $_POST['device_enabled'] ) ? '1' : '0',
                'last_seen'  => $device['last_seen'] ?? '',
            ] );
            break;
        }
        if ( $found ) {
            qrg_solution_save_devices( $devices );
            wp_safe_redirect( qrg_solution_admin_url( [ 'tab' => 'mesin', 'updated' => '1' ] ) );
            exit;
        }
        wp_safe_redirect( qrg_solution_admin_url( [ 'tab' => 'mesin' ] ) );
        exit;
    }

    if ( isset( $_POST['qrg_solution_delete_device'] ) && check_admin_referer( 'qrg_solution_device_delete', 'qrg_solution_device_delete_nonce' ) ) {
        $del_id  = isset( $_POST['device_id'] ) ? sanitize_key( wp_unslash( $_POST['device_id'] ) ) : '';
        $devices = [];
        foreach ( qrg_solution_get_devices() as $device ) {
            if ( $device['id'] !== $del_id ) {
                $devices[] = $device;
            }
        }
        qrg_solution_save_devices( $devices );
        wp_safe_redirect( qrg_solution_admin_url( [ 'tab' => 'mesin', 'deleted' => '1' ] ) );
        exit;
    }

    if ( isset( $_POST['qrg_solution_clear_logs'] ) && check_admin_referer( 'qrg_solution_logs', 'qrg_solution_logs_nonce' ) ) {
        qrg_solution_clear_logs();
        wp_safe_redirect( qrg_solution_admin_url( [ 'tab' => 'log', 'cleared' => '1' ] ) );
        exit;
    }

    if ( isset( $_POST['qrg_solution_fetch_users'] ) && check_admin_referer( 'qrg_solution_users', 'qrg_solution_users_nonce' ) ) {
        $uid    = isset( $_POST['device_id'] ) ? sanitize_key( wp_unslash( $_POST['device_id'] ) ) : '';
        $device = qrg_solution_get_device( $uid );
        $fetch  = $device ? qrg_solution_fetch_device_users( $device ) : new WP_Error( 'no_device', 'Mesin tidak ditemukan.' );
        $flash  = is_wp_error( $fetch )
            ? [ 'error' => $fetch->get_error_message() ]
            : [
                'ok'      => ! empty( $fetch['ok'] ),
                'pending' => ! empty( $fetch['pending'] ),
                'count'   => (int) ( $fetch['count'] ?? 0 ),
                'message' => (string) ( $fetch['message'] ?? '' ),
            ];
        set_transient( 'qrg_solution_user_flash_' . get_current_user_id(), $flash, 120 );
        wp_safe_redirect( qrg_solution_admin_url( [ 'tab' => 'user', 'device' => $uid ] ) );
        exit;
    }

    if ( isset( $_POST['qrg_solution_fetch_fingers'] ) && check_admin_referer( 'qrg_solution_fingers', 'qrg_solution_fingers_nonce' ) ) {
        $uid    = isset( $_POST['device_id'] ) ? sanitize_key( wp_unslash( $_POST['device_id'] ) ) : '';
        $pin    = isset( $_POST['user_pin'] ) ? sanitize_text_field( wp_unslash( $_POST['user_pin'] ) ) : '';
        $device = qrg_solution_get_device( $uid );
        $fetch  = ( $device && $pin !== '' )
            ? qrg_solution_fetch_user_fingers( $device, $pin )
            : new WP_Error( 'invalid', 'Mesin atau PIN tidak valid.' );
        $flash  = is_wp_error( $fetch )
            ? [ 'error' => $fetch->get_error_message() ]
            : [
                'ok'      => ! empty( $fetch['ok'] ),
                'count'   => (int) ( $fetch['count'] ?? 0 ),
                'message' => (string) ( $fetch['message'] ?? '' ),
            ];
        set_transient( 'qrg_solution_finger_flash_' . get_current_user_id(), $flash, 120 );
        wp_safe_redirect( qrg_solution_admin_url( [ 'tab' => 'user', 'device' => $uid, 'finger' => $pin ] ) );
        exit;
    }

    if ( isset( $_POST['qrg_solution_fetch_attlog'] ) && check_admin_referer( 'qrg_solution_attlog', 'qrg_solution_attlog_nonce' ) ) {
        $uid    = isset( $_POST['device_id'] ) ? sanitize_key( wp_unslash( $_POST['device_id'] ) ) : '';
        $pin    = isset( $_POST['user_pin'] ) ? sanitize_text_field( wp_unslash( $_POST['user_pin'] ) ) : '';
        $bulan  = isset( $_POST['bulan'] ) ? absint( $_POST['bulan'] ) : (int) current_time( 'n' );
        $tahun  = isset( $_POST['tahun'] ) ? absint( $_POST['tahun'] ) : (int) current_time( 'Y' );
        $device = qrg_solution_get_device( $uid );
        $fetch  = ( $device && $pin !== '' )
            ? qrg_solution_fetch_user_attlog( $device, $pin, $bulan, $tahun )
            : new WP_Error( 'invalid', 'Mesin atau PIN tidak valid.' );
        $flash  = is_wp_error( $fetch )
            ? [ 'error' => $fetch->get_error_message() ]
            : [
                'ok'      => ! empty( $fetch['ok'] ),
                'count'   => (int) ( $fetch['count'] ?? 0 ),
                'message' => (string) ( $fetch['message'] ?? '' ),
            ];
        set_transient( 'qrg_solution_attlog_flash_' . get_current_user_id(), $flash, 120 );
        wp_safe_redirect( qrg_solution_admin_url( [
            'tab'    => 'user',
            'device' => $uid,
            'finger' => $pin,
            'bulan'  => $bulan,
            'tahun'  => $tahun,
        ] ) );
        exit;
    }

    if ( isset( $_POST['qrg_solution_fetch_device_attlog'] ) && check_admin_referer( 'qrg_solution_device_attlog', 'qrg_solution_device_attlog_nonce' ) ) {
        $uid    = isset( $_POST['device_id'] ) ? sanitize_key( wp_unslash( $_POST['device_id'] ) ) : '';
        $bulan  = isset( $_POST['bulan'] ) ? absint( $_POST['bulan'] ) : (int) current_time( 'n' );
        $tahun  = isset( $_POST['tahun'] ) ? absint( $_POST['tahun'] ) : (int) current_time( 'Y' );
        $ret_tab = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'presensi';
        if ( ! in_array( $ret_tab, [ 'presensi', 'rekap' ], true ) ) {
            $ret_tab = 'presensi';
        }

        if ( $uid === '' || $uid === 'all' ) {
            $fetch = qrg_solution_fetch_all_devices_attlog( $bulan, $tahun );
            $redir = [ 'tab' => $ret_tab, 'bulan' => $bulan, 'tahun' => $tahun ];
        } else {
            $device = qrg_solution_get_device( $uid );
            $fetch  = $device
                ? qrg_solution_fetch_device_attlog( $device, $bulan, $tahun )
                : new WP_Error( 'invalid', 'Mesin tidak ditemukan.' );
            $redir = [ 'tab' => $ret_tab, 'device' => $uid, 'bulan' => $bulan, 'tahun' => $tahun ];
        }
        if ( isset( $_POST['hari'] ) ) {
            $redir['hari'] = sanitize_text_field( wp_unslash( $_POST['hari'] ) );
        }

        $flash = is_wp_error( $fetch )
            ? [ 'error' => $fetch->get_error_message() ]
            : [
                'ok'      => ! empty( $fetch['ok'] ),
                'count'   => (int) ( $fetch['count'] ?? 0 ),
                'message' => (string) ( $fetch['message'] ?? '' ),
                'warning' => ! empty( $fetch['warning'] ),
            ];
        set_transient( 'qrg_solution_device_attlog_flash_' . get_current_user_id(), $flash, 120 );
        wp_safe_redirect( qrg_solution_admin_url( $redir ) );
        exit;
    }

    return false;
}

function qrg_solution_test_device_http( $ip ) {
    $ip = trim( $ip );
    if ( $ip === '' || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        return new WP_Error( 'invalid_ip', 'Alamat IP tidak valid.' );
    }
    $url = 'http://' . $ip . '/';
    $res = wp_remote_get( $url, [
        'timeout'   => 5,
        'sslverify' => false,
    ] );
    if ( is_wp_error( $res ) && function_exists( 'curl_init' ) ) {
        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
        ] );
        $raw  = curl_exec( $ch );
        $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $err  = curl_error( $ch );
        curl_close( $ch );
        if ( $raw === false ) {
            return new WP_Error( 'curl_fail', $err !== '' ? $err : $res->get_error_message() );
        }
        if ( $code >= 200 && $code < 500 ) {
            return [
                'ok'   => true,
                'code' => $code,
                'msg'  => 'Perangkat merespons di http://' . $ip . '/ (HTTP ' . $code . ')',
            ];
        }
        return new WP_Error( 'no_response', 'Perangkat tidak merespons (HTTP ' . $code . ').' );
    }
    if ( is_wp_error( $res ) ) {
        return $res;
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    if ( $code >= 200 && $code < 500 ) {
        return [
            'ok'   => true,
            'code' => $code,
            'msg'  => 'Perangkat merespons di http://' . $ip . '/ (HTTP ' . $code . ')',
        ];
    }
    return new WP_Error( 'no_response', 'Perangkat tidak merespons (HTTP ' . $code . ').' );
}

add_action( 'wp_ajax_qrg_solution_test_device', 'qrg_solution_ajax_test_device' );
function qrg_solution_ajax_test_device() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Akses ditolak.' ], 403 );
    }
    check_ajax_referer( 'qrg_solution_test', 'nonce' );
    $ip   = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
    $test = qrg_solution_test_device_http( $ip );
    if ( is_wp_error( $test ) ) {
        wp_send_json_error( [ 'message' => $test->get_error_message() ] );
    }
    wp_send_json_success( $test );
}

function qrg_solution_get_admin_tabs() {
    $devices = qrg_solution_get_devices();
    $logs    = qrg_solution_get_logs();
    $users_n = qrg_solution_count_cached_users();
    return [
        'mesin'      => 'Daftar Mesin' . ( $devices ? ' (' . count( $devices ) . ')' : '' ),
        'tambah'     => 'Tambah Mesin',
        'user'       => 'User' . ( $users_n ? ' (' . $users_n . ')' : '' ),
        'presensi'   => 'Lihat Presensi',
        'rekap'      => 'Rekap Harian',
        'pengaturan' => 'Pengaturan',
        'panduan'    => 'Panduan',
        'log'        => 'Log' . ( $logs ? ' (' . count( $logs ) . ')' : '' ),
    ];
}

/**
 * Jumlah user unik (PIN) tersimpan.
 */
function qrg_solution_count_cached_users( $device_id = '' ) {
    return count( qrg_solution_collect_users( $device_id ) );
}

/**
 * Kumpulkan user dari satu atau semua mesin. PIN unik (tidak diduplikasi).
 *
 * @return array<int,array<string,mixed>>
 */
function qrg_solution_collect_users( $device_id = '' ) {
    $device_id = sanitize_key( (string) $device_id );
    $devices  = qrg_solution_get_devices();
    $by_pin   = [];

    foreach ( $devices as $device ) {
        if ( $device_id !== '' && $device['id'] !== $device_id ) {
            continue;
        }
        $cache = qrg_solution_get_device_users( $device['id'] );
        $users = is_array( $cache['users'] ) ? $cache['users'] : [];
        foreach ( $users as $user ) {
            $pin = (string) ( $user['pin'] ?? '' );
            if ( $pin === '' ) {
                continue;
            }
            $device_nama = $device['nama'] !== '' ? $device['nama'] : $device['id'];
            $nama       = (string) ( $user['nama'] ?? '' );
            $card       = (string) ( $user['card'] ?? '' );
            $priv       = (string) ( $user['privilege'] ?? '0' );

            if ( ! isset( $by_pin[ $pin ] ) ) {
                $by_pin[ $pin ] = [
                    'pin'           => $pin,
                    'nama'          => $nama,
                    'card'          => $card,
                    'privilege'     => $priv,
                    'device_id'     => $device['id'],
                    'device_nama'   => $device_nama,
                    'device_ip'     => (string) ( $device['ip'] ?? '' ),
                    'device'        => $device,
                    'devices'       => [
                        [
                            'id'   => $device['id'],
                            'nama' => $device_nama,
                            'ip'   => (string) ( $device['ip'] ?? '' ),
                        ],
                    ],
                    'updated'       => (string) ( $cache['updated'] ?? '' ),
                    'source'        => (string) ( $cache['source'] ?? '' ),
                    'pending'       => ! empty( $cache['pending'] ),
                ];
                continue;
            }

            // PIN sama: gabungkan mesin, lengkapi data kosong.
            $exist = &$by_pin[ $pin ];
            $dup   = false;
            foreach ( $exist['devices'] as $drow ) {
                if ( $drow['id'] === $device['id'] ) {
                    $dup = true;
                    break;
                }
            }
            if ( ! $dup ) {
                $exist['devices'][] = [
                    'id'   => $device['id'],
                    'nama' => $device_nama,
                    'ip'   => (string) ( $device['ip'] ?? '' ),
                ];
            }
            if ( $exist['nama'] === '' && $nama !== '' ) {
                $exist['nama'] = $nama;
            }
            if ( $exist['card'] === '' && $card !== '' ) {
                $exist['card'] = $card;
            }
            if ( ( $exist['privilege'] === '' || $exist['privilege'] === '0' ) && $priv !== '' && $priv !== '0' ) {
                $exist['privilege'] = $priv;
            }
            if ( $exist['updated'] === '' && ! empty( $cache['updated'] ) ) {
                $exist['updated'] = (string) $cache['updated'];
                $exist['source']  = (string) ( $cache['source'] ?? '' );
            }
            unset( $exist );
        }
    }

    $rows = array_values( $by_pin );
    usort( $rows, static function ( $a, $b ) {
        $na = trim( (string) ( $a['nama'] ?? '' ) );
        $nb = trim( (string) ( $b['nama'] ?? '' ) );
        if ( $na === '' && $nb !== '' ) {
            return 1;
        }
        if ( $nb === '' && $na !== '' ) {
            return -1;
        }
        $cmp = strcasecmp( $na, $nb );
        if ( $cmp !== 0 ) {
            return $cmp;
        }
        return strnatcasecmp( (string) $a['pin'], (string) $b['pin'] );
    } );

    return $rows;
}

/**
 * Label FingerID 0–9.
 */
function qrg_solution_finger_label( $fid ) {
    $map = [
        0 => 'Jempol kanan',
        1 => 'Telunjuk kanan',
        2 => 'Jari tengah kanan',
        3 => 'Jari manis kanan',
        4 => 'Kelingking kanan',
        5 => 'Jempol kiri',
        6 => 'Telunjuk kiri',
        7 => 'Jari tengah kiri',
        8 => 'Jari manis kiri',
        9 => 'Kelingking kiri',
    ];
    $fid = (int) $fid;
    return isset( $map[ $fid ] ) ? $map[ $fid ] : ( 'Jari #' . $fid );
}

/**
 * @return array{updated:string,source:string,fingers:array}
 */
function qrg_solution_get_user_fingers( $device_id, $pin ) {
    $empty = [ 'updated' => '', 'source' => '', 'fingers' => [] ];
    $all   = get_option( 'qrg_solution_user_fingers', [] );
    if ( ! is_array( $all ) ) {
        return $empty;
    }
    $key = sanitize_key( (string) $device_id ) . '|' . sanitize_text_field( (string) $pin );
    if ( ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
        return $empty;
    }
    return array_merge( $empty, $all[ $key ] );
}

/**
 * @param array<int,array<string,mixed>> $fingers
 */
function qrg_solution_save_user_fingers( $device_id, $pin, $fingers, $source = 'soap' ) {
    $all = get_option( 'qrg_solution_user_fingers', [] );
    if ( ! is_array( $all ) ) {
        $all = [];
    }
    $key         = sanitize_key( (string) $device_id ) . '|' . sanitize_text_field( (string) $pin );
    $all[ $key ] = [
        'updated' => current_time( 'mysql' ),
        'source'  => sanitize_key( (string) $source ),
        'fingers' => array_values( $fingers ),
    ];
    // Batasi cache agar option tidak membengkak.
    if ( count( $all ) > 500 ) {
        $all = array_slice( $all, -400, null, true );
    }
    update_option( 'qrg_solution_user_fingers', $all, false );
}

/**
 * @return array<int,array{finger_id:int,valid:int,size:int,template:string,has_data:bool}>
 */
function qrg_solution_parse_soap_templates( $xml ) {
    $fingers = [];
    $seen    = [];
    if ( ! preg_match_all( '/<Row\b[^>]*>(.*?)<\/Row>/is', (string) $xml, $rows ) ) {
        // Beberapa firmware mengembalikan tanpa <Row>.
        if ( preg_match( '/<FingerID\b[^>]*>([^<]*)<\/FingerID>/i', (string) $xml ) ) {
            $rows = [ 1 => [ (string) $xml ] ];
        } else {
            return $fingers;
        }
    }
    foreach ( $rows[1] as $row ) {
        $get = static function ( $tag ) use ( $row ) {
            if ( preg_match( '/<' . $tag . '\b[^>]*>([^<]*)<\/' . $tag . '>/i', $row, $m ) ) {
                return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
            }
            return '';
        };
        $fid = $get( 'FingerID' );
        if ( $fid === '' || ! ctype_digit( $fid ) ) {
            continue;
        }
        $fid = (int) $fid;
        if ( isset( $seen[ $fid ] ) ) {
            continue;
        }
        $seen[ $fid ] = true;
        $valid        = (int) $get( 'Valid' );
        $size         = (int) $get( 'Size' );
        $tpl          = $get( 'Template' );
        if ( $size <= 0 && $tpl !== '' ) {
            $size = strlen( $tpl );
        }
        $has = ( $valid === 1 ) || ( $tpl !== '' && $size > 0 );
        $fingers[] = [
            'finger_id' => $fid,
            'valid'     => $valid,
            'size'      => $size,
            'template'  => $tpl,
            'has_data'  => $has,
        ];
    }
    usort( $fingers, static function ( $a, $b ) {
        return (int) $a['finger_id'] <=> (int) $b['finger_id'];
    } );
    return $fingers;
}

/**
 * Ambil template sidik jari via SOAP GetUserTemplate.
 *
 * @return array<int,array{finger_id:int,valid:int,size:int,template:string,has_data:bool}>|WP_Error
 */
function qrg_solution_soap_get_templates( $ip, $pin, $com_key = 0 ) {
    $ip  = trim( (string) $ip );
    $pin = trim( (string) $pin );
    if ( $ip === '' || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        return new WP_Error( 'invalid_ip', 'Alamat IP tidak valid.' );
    }
    if ( $pin === '' ) {
        return new WP_Error( 'invalid_pin', 'PIN tidak valid.' );
    }

    $keys = [];
    foreach ( [ 0, (int) $com_key ] as $k ) {
        if ( ! in_array( $k, $keys, true ) ) {
            $keys[] = $k;
        }
    }

    $last = new WP_Error( 'soap_fail', 'Mesin tidak mengirim data sidik jari.' );
    foreach ( $keys as $key ) {
        // Coba FingerID=ALL dulu.
        $xml  = '<GetUserTemplate><ArgComKey xsi:type="xsd:integer">' . (int) $key . '</ArgComKey><Arg><PIN xsi:type="xsd:integer">' . (int) $pin . '</PIN><FingerID>ALL</FingerID></Arg></GetUserTemplate>';
        $http = qrg_solution_device_http_post(
            'http://' . $ip . '/iWsService',
            $xml,
            [ 'Content-Type' => 'text/xml' ],
            25
        );
        if ( ! is_wp_error( $http ) && (int) $http['code'] < 400 && $http['body'] !== '' ) {
            $parsed = qrg_solution_parse_soap_templates( $http['body'] );
            if ( ! empty( $parsed ) ) {
                return $parsed;
            }
        }

        // Fallback: FingerID 0–9.
        $collected = [];
        $seen_fid  = [];
        for ( $fid = 0; $fid <= 9; $fid++ ) {
            $xml  = '<GetUserTemplate><ArgComKey xsi:type="xsd:integer">' . (int) $key . '</ArgComKey><Arg><PIN xsi:type="xsd:integer">' . (int) $pin . '</PIN><FingerID xsi:type="xsd:integer">' . $fid . '</FingerID></Arg></GetUserTemplate>';
            $http = qrg_solution_device_http_post(
                'http://' . $ip . '/iWsService',
                $xml,
                [ 'Content-Type' => 'text/xml' ],
                12
            );
            if ( is_wp_error( $http ) || (int) $http['code'] >= 400 || $http['body'] === '' ) {
                continue;
            }
            foreach ( qrg_solution_parse_soap_templates( $http['body'] ) as $row ) {
                $id = (int) $row['finger_id'];
                if ( isset( $seen_fid[ $id ] ) ) {
                    continue;
                }
                $seen_fid[ $id ] = true;
                $collected[]     = $row;
            }
        }
        if ( ! empty( $collected ) ) {
            usort( $collected, static function ( $a, $b ) {
                return (int) $a['finger_id'] <=> (int) $b['finger_id'];
            } );
            return $collected;
        }
        $last = new WP_Error( 'soap_empty', 'Tidak ada template sidik jari untuk PIN ' . $pin . ' (ComKey ' . (int) $key . ').' );
    }
    return $last;
}

/**
 * @param array<string,mixed> $device
 * @return array{ok:bool,count:int,message:string,fingers:array}|WP_Error
 */
function qrg_solution_fetch_user_fingers( $device, $pin ) {
    $ip = trim( (string) ( $device['ip'] ?? '' ) );
    if ( $ip === '' ) {
        return new WP_Error( 'no_ip', 'IP mesin belum diisi.' );
    }
    $cred    = qrg_solution_get_admin_credentials( $device );
    $com_key = ctype_digit( (string) $cred['pass'] ) ? (int) $cred['pass'] : 0;
    $result  = qrg_solution_soap_get_templates( $ip, $pin, $com_key );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    $store = [];
    foreach ( $result as $f ) {
        $tpl = (string) ( $f['template'] ?? '' );
        $store[] = [
            'finger_id' => (int) $f['finger_id'],
            'valid'     => (int) $f['valid'],
            'size'      => (int) $f['size'],
            'has_data'  => ! empty( $f['has_data'] ),
            'template'  => $tpl,
            'preview'   => $tpl !== '' ? substr( $tpl, 0, 48 ) . ( strlen( $tpl ) > 48 ? '…' : '' ) : '',
            'length'    => strlen( $tpl ),
        ];
    }
    qrg_solution_save_user_fingers( $device['id'], $pin, $store, 'soap' );
    $enrolled = 0;
    foreach ( $store as $f ) {
        if ( ! empty( $f['has_data'] ) ) {
            $enrolled++;
        }
    }
    return [
        'ok'      => true,
        'count'   => $enrolled,
        'message' => $enrolled > 0
            ? $enrolled . ' sidik jari ditemukan untuk PIN ' . $pin . '.'
            : 'Mesin merespons, tetapi tidak ada sidik jari terdaftar untuk PIN ' . $pin . '.',
        'fingers' => $store,
    ];
}

/**
 * @return array{updated:string,source:string,records:array}
 */
function qrg_solution_get_user_attlog( $device_id, $pin ) {
    $empty = [ 'updated' => '', 'source' => '', 'records' => [] ];
    $all   = get_option( 'qrg_solution_user_attlog', [] );
    if ( ! is_array( $all ) ) {
        return $empty;
    }
    $key = sanitize_key( (string) $device_id ) . '|' . sanitize_text_field( (string) $pin );
    if ( ! isset( $all[ $key ] ) || ! is_array( $all[ $key ] ) ) {
        return $empty;
    }
    return array_merge( $empty, $all[ $key ] );
}

/**
 * @param array<int,array<string,mixed>> $records
 */
function qrg_solution_save_user_attlog( $device_id, $pin, $records, $source = 'soap' ) {
    $all = get_option( 'qrg_solution_user_attlog', [] );
    if ( ! is_array( $all ) ) {
        $all = [];
    }
    $key         = sanitize_key( (string) $device_id ) . '|' . sanitize_text_field( (string) $pin );
    $all[ $key ] = [
        'updated' => current_time( 'mysql' ),
        'source'  => sanitize_key( (string) $source ),
        'records' => array_values( $records ),
    ];
    if ( count( $all ) > 300 ) {
        $all = array_slice( $all, -200, null, true );
    }
    update_option( 'qrg_solution_user_attlog', $all, false );
}

/**
 * Parse respons SOAP GetAttLog.
 *
 * @return array<int,array{pin:string,datetime:string,inout:int,status:int,verified:int}>
 */
function qrg_solution_parse_soap_attlog( $xml ) {
    $records = [];
    if ( ! preg_match_all( '/<Row\b[^>]*>(.*?)<\/Row>/is', (string) $xml, $rows ) ) {
        return $records;
    }
    foreach ( $rows[1] as $row ) {
        $get = static function ( $tag ) use ( $row ) {
            if ( preg_match( '/<' . $tag . '\b[^>]*>([^<]*)<\/' . $tag . '>/i', $row, $m ) ) {
                return trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
            }
            return '';
        };
        $pin = $get( 'PIN' );
        $dt  = $get( 'DateTime' );
        if ( $dt === '' ) {
            $dt = $get( 'Time' );
        }
        if ( $pin === '' || $dt === '' ) {
            continue;
        }
        $status = $get( 'Status' );
        if ( $status === '' ) {
            $status = $get( 'InOut' );
        }
        $verified = $get( 'Verified' );
        $records[] = [
            'pin'      => $pin,
            'datetime' => qrg_solution_normalize_datetime( $dt ),
            'inout'    => (int) $status,
            'status'   => (int) $status,
            'verified' => (int) $verified,
        ];
    }
    return $records;
}

/**
 * Ambil log absensi dari mesin via SOAP GetAttLog.
 *
 * @param string $pin PIN spesifik, atau "All" untuk semua user.
 * @return array<int,array{pin:string,datetime:string,inout:int,status:int,verified:int}>|WP_Error
 */
function qrg_solution_soap_get_attlog( $ip, $pin, $com_key = 0, $start = '', $end = '' ) {
    $ip  = trim( (string) $ip );
    $pin = trim( (string) $pin );
    if ( $ip === '' || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
        return new WP_Error( 'invalid_ip', 'Alamat IP tidak valid.' );
    }
    if ( $pin === '' ) {
        return new WP_Error( 'invalid_pin', 'PIN tidak valid.' );
    }

    $want_all = ( strcasecmp( $pin, 'All' ) === 0 );

    $keys = [];
    foreach ( [ 0, (int) $com_key ] as $k ) {
        if ( ! in_array( $k, $keys, true ) ) {
            $keys[] = $k;
        }
    }

    $date_xml = '';
    if ( $start !== '' && $end !== '' ) {
        $date_xml = '<StartTime>' . esc_html( $start ) . '</StartTime><EndTime>' . esc_html( $end ) . '</EndTime>';
    }

    $last = new WP_Error( 'soap_fail', 'Mesin tidak mengirim log absensi.' );
    foreach ( $keys as $key ) {
        // Jangan cast "All" ke (int) — jadi 0 dan hanya mengambil log PIN admin/0.
        if ( $want_all ) {
            $tries = [
                '<GetAttLog><ArgComKey xsi:type="xsd:integer">' . (int) $key . '</ArgComKey><Arg><PIN>All</PIN>' . $date_xml . '</Arg></GetAttLog>',
                '<GetAttLog><ArgComKey xsi:type="xsd:integer">' . (int) $key . '</ArgComKey><Arg><PIN xsi:type="xsd:string">All</PIN>' . $date_xml . '</Arg></GetAttLog>',
                '<GetAttLog><ArgComKey xsi:type="xsd:integer">' . (int) $key . '</ArgComKey><Arg>' . $date_xml . '</Arg></GetAttLog>',
            ];
        } else {
            $tries = [
                '<GetAttLog><ArgComKey xsi:type="xsd:integer">' . (int) $key . '</ArgComKey><Arg><PIN xsi:type="xsd:integer">' . (int) $pin . '</PIN>' . $date_xml . '</Arg></GetAttLog>',
                '<GetAttLog><ArgComKey xsi:type="xsd:integer">' . (int) $key . '</ArgComKey><Arg><PIN>' . esc_html( $pin ) . '</PIN>' . $date_xml . '</Arg></GetAttLog>',
            ];
        }
        foreach ( $tries as $xml ) {
            $http = qrg_solution_device_http_post(
                'http://' . $ip . '/iWsService',
                $xml,
                [ 'Content-Type' => 'text/xml' ],
                45
            );
            if ( is_wp_error( $http ) ) {
                $last = $http;
                continue;
            }
            if ( (int) $http['code'] >= 400 || $http['body'] === '' ) {
                $last = new WP_Error( 'soap_fail', 'Mesin tidak mengirim log absensi (HTTP ' . (int) $http['code'] . ').' );
                continue;
            }
            $parsed = qrg_solution_parse_soap_attlog( $http['body'] );
            if ( empty( $parsed ) ) {
                // Fallback: format ATTLOG teks.
                $parsed = qrg_solution_parse_attlog( $http['body'] );
            }
            if ( empty( $parsed ) ) {
                $last = new WP_Error( 'soap_empty', 'Respons mesin tidak berisi log absensi (ComKey ' . (int) $key . ').' );
                continue;
            }
            $filtered = [];
            foreach ( $parsed as $row ) {
                if ( empty( $row['datetime'] ) ) {
                    continue;
                }
                if ( ! $want_all && (string) ( $row['pin'] ?? '' ) !== (string) $pin ) {
                    continue;
                }
                $filtered[] = $row;
            }
            // Untuk All: jika hasil hanya PIN "0" (hasil cast salah / akun kosong), coba payload berikutnya.
            if ( $want_all && ! empty( $filtered ) ) {
                $only_zero = true;
                foreach ( $filtered as $row ) {
                    if ( (string) ( $row['pin'] ?? '' ) !== '0' && (string) ( $row['pin'] ?? '' ) !== '' ) {
                        $only_zero = false;
                        break;
                    }
                }
                if ( $only_zero && count( $filtered ) <= 5 ) {
                    $last = new WP_Error( 'soap_empty', 'Respons hanya berisi PIN 0; mencoba format lain…' );
                    continue;
                }
            }
            if ( ! empty( $filtered ) ) {
                usort( $filtered, static function ( $a, $b ) {
                    return strcmp( (string) $a['datetime'], (string) $b['datetime'] );
                } );
                return $filtered;
            }
            $last = new WP_Error(
                'soap_empty',
                $want_all
                    ? 'Tidak ada log absensi pada rentang ini.'
                    : ( 'Tidak ada log absensi untuk PIN ' . $pin . ' pada rentang ini.' )
            );
        }
    }
    return $last;
}

/**
 * Label status punch mesin.
 */
function qrg_solution_attlog_status_label( $status ) {
    $map = [
        0 => 'Masuk',
        1 => 'Pulang',
        2 => 'Istirahat keluar',
        3 => 'Istirahat masuk',
        4 => 'Lembur masuk',
        5 => 'Lembur pulang',
    ];
    $status = (int) $status;
    return isset( $map[ $status ] ) ? $map[ $status ] : ( 'Status ' . $status );
}

/**
 * Kelompokkan punch mesin per hari (masuk = pertama, pulang = terakhir bila beda).
 *
 * @param array<int,array{pin:string,datetime:string,inout:int,status:int}> $records
 * @return array<int,array<string,mixed>>
 */
function qrg_solution_group_attlog_days( $records, $bulan, $tahun ) {
    $prefix = sprintf( '%04d-%02d', (int) $tahun, (int) $bulan );
    $by_day = [];
    foreach ( (array) $records as $row ) {
        $dt = (string) ( $row['datetime'] ?? '' );
        if ( $dt === '' || strpos( $dt, $prefix ) !== 0 ) {
            continue;
        }
        $day = substr( $dt, 0, 10 );
        $jam = substr( $dt, 11, 8 );
        if ( ! isset( $by_day[ $day ] ) ) {
            $by_day[ $day ] = [
                'tanggal'    => $day,
                'jam_masuk'  => $jam,
                'jam_pulang' => '',
                'punches'    => [],
                'status'     => 'Hadir',
                'via'        => 'mesin',
            ];
        }
        $by_day[ $day ]['punches'][] = [
            'datetime' => $dt,
            'jam'      => $jam,
            'inout'    => (int) ( $row['inout'] ?? $row['status'] ?? 0 ),
            'label'    => qrg_solution_attlog_status_label( $row['inout'] ?? $row['status'] ?? 0 ),
        ];
        // Masuk: punch paling awal; pulang: paling akhir (jika beda dari masuk).
        if ( $jam !== '' && ( $by_day[ $day ]['jam_masuk'] === '' || strcmp( $jam, $by_day[ $day ]['jam_masuk'] ) < 0 ) ) {
            $by_day[ $day ]['jam_masuk'] = $jam;
        }
        if ( $jam !== '' && $jam !== $by_day[ $day ]['jam_masuk'] ) {
            if ( $by_day[ $day ]['jam_pulang'] === '' || strcmp( $jam, $by_day[ $day ]['jam_pulang'] ) > 0 ) {
                $by_day[ $day ]['jam_pulang'] = $jam;
            }
        }
    }
    krsort( $by_day );
    return array_values( $by_day );
}

/**
 * @param array<string,mixed> $device
 * @return array{ok:bool,count:int,saved:int,message:string,records:array}|WP_Error
 */
function qrg_solution_fetch_user_attlog( $device, $pin, $bulan = 0, $tahun = 0 ) {
    $ip = trim( (string) ( $device['ip'] ?? '' ) );
    if ( $ip === '' ) {
        return new WP_Error( 'no_ip', 'IP mesin belum diisi.' );
    }
    $bulan = $bulan > 0 ? (int) $bulan : (int) current_time( 'n' );
    $tahun = $tahun > 0 ? (int) $tahun : (int) current_time( 'Y' );
    $start = sprintf( '%04d-%02d-01 00:00:00', $tahun, $bulan );
    $end_ts = strtotime( sprintf( '%04d-%02d-01', $tahun, $bulan ) . ' +1 month -1 day' );
    $end    = $end_ts ? date( 'Y-m-d 23:59:59', $end_ts ) : sprintf( '%04d-%02d-28 23:59:59', $tahun, $bulan );

    $cred    = qrg_solution_get_admin_credentials( $device );
    $com_key = ctype_digit( (string) $cred['pass'] ) ? (int) $cred['pass'] : 0;
    $result  = qrg_solution_soap_get_attlog( $ip, $pin, $com_key, $start, $end );
    if ( is_wp_error( $result ) ) {
        // Coba tanpa filter tanggal (beberapa firmware tidak mendukung StartTime).
        $result = qrg_solution_soap_get_attlog( $ip, $pin, $com_key, '', '' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    }

    // Gabungkan dengan cache lama di luar bulan ini.
    $cache   = qrg_solution_get_user_attlog( $device['id'], $pin );
    $old     = is_array( $cache['records'] ) ? $cache['records'] : [];
    $prefix  = sprintf( '%04d-%02d', $tahun, $bulan );
    $merged  = [];
    $seen_dt = [];
    foreach ( $old as $row ) {
        $dt = (string) ( $row['datetime'] ?? '' );
        if ( $dt === '' || strpos( $dt, $prefix ) === 0 ) {
            continue; // diganti data baru bulan ini
        }
        $seen_dt[ $dt ] = true;
        $merged[]       = $row;
    }
    foreach ( $result as $row ) {
        $dt = (string) ( $row['datetime'] ?? '' );
        if ( $dt === '' || isset( $seen_dt[ $dt ] ) ) {
            continue;
        }
        $seen_dt[ $dt ] = true;
        $merged[]       = [
            'pin'      => (string) ( $row['pin'] ?? $pin ),
            'datetime' => $dt,
            'inout'    => (int) ( $row['inout'] ?? 0 ),
            'status'   => (int) ( $row['status'] ?? $row['inout'] ?? 0 ),
            'verified' => (int) ( $row['verified'] ?? 0 ),
        ];
    }
    usort( $merged, static function ( $a, $b ) {
        return strcmp( (string) $a['datetime'], (string) $b['datetime'] );
    } );
    qrg_solution_save_user_attlog( $device['id'], $pin, $merged, 'soap' );

    // Sinkron ke CPT bila PIN terhubung guru.
    $sn    = (string) ( $device['sn'] ?? '' );
    $saved = qrg_solution_process_attendance_records( $result, $sn );

    $in_month = 0;
    foreach ( $result as $row ) {
        if ( strpos( (string) ( $row['datetime'] ?? '' ), $prefix ) === 0 ) {
            $in_month++;
        }
    }

    return [
        'ok'      => true,
        'count'   => $in_month > 0 ? $in_month : count( $result ),
        'saved'   => (int) $saved,
        'message' => ( $in_month > 0 ? $in_month : count( $result ) ) . ' log absensi diambil dari mesin'
            . ( $saved > 0 ? '; ' . $saved . ' disimpan ke Data Presensi.' : '.' ),
        'records' => $merged,
    ];
}

/**
 * Cache log absensi per mesin (semua PIN).
 *
 * @return array{updated:string,source:string,records:array}
 */
function qrg_solution_get_device_attlog( $device_id ) {
    $empty = [ 'updated' => '', 'source' => '', 'records' => [] ];
    $all   = get_option( 'qrg_solution_device_attlog', [] );
    if ( ! is_array( $all ) ) {
        return $empty;
    }
    $id = sanitize_key( (string) $device_id );
    if ( $id === '' || ! isset( $all[ $id ] ) || ! is_array( $all[ $id ] ) ) {
        return $empty;
    }
    return array_merge( $empty, $all[ $id ] );
}

/**
 * @param array<int,array<string,mixed>> $records
 */
function qrg_solution_save_device_attlog( $device_id, $records, $source = 'soap' ) {
    $all = get_option( 'qrg_solution_device_attlog', [] );
    if ( ! is_array( $all ) ) {
        $all = [];
    }
    $id         = sanitize_key( (string) $device_id );
    $all[ $id ] = [
        'updated' => current_time( 'mysql' ),
        'source'  => sanitize_key( (string) $source ),
        'records' => array_values( $records ),
    ];
    if ( count( $all ) > 80 ) {
        $all = array_slice( $all, -50, null, true );
    }
    update_option( 'qrg_solution_device_attlog', $all, false );
}

/**
 * Ambil semua log absensi dari satu mesin (GetAttLog PIN=All).
 *
 * @param array<string,mixed> $device
 * @return array{ok:bool,count:int,saved:int,message:string,warning?:string}|WP_Error
 */
function qrg_solution_fetch_device_attlog( $device, $bulan = 0, $tahun = 0 ) {
    $ip = trim( (string) ( $device['ip'] ?? '' ) );
    if ( $ip === '' ) {
        return new WP_Error( 'no_ip', 'IP mesin belum diisi.' );
    }
    $bulan = $bulan > 0 ? (int) $bulan : (int) current_time( 'n' );
    $tahun = $tahun > 0 ? (int) $tahun : (int) current_time( 'Y' );
    $start = sprintf( '%04d-%02d-01 00:00:00', $tahun, $bulan );
    $end_ts = strtotime( sprintf( '%04d-%02d-01', $tahun, $bulan ) . ' +1 month -1 day' );
    $end    = $end_ts ? date( 'Y-m-d 23:59:59', $end_ts ) : sprintf( '%04d-%02d-28 23:59:59', $tahun, $bulan );
    $prefix = sprintf( '%04d-%02d', $tahun, $bulan );

    $cred    = qrg_solution_get_admin_credentials( $device );
    $com_key = ctype_digit( (string) $cred['pass'] ) ? (int) $cred['pass'] : 0;
    $result  = qrg_solution_soap_get_attlog( $ip, 'All', $com_key, $start, $end );
    if ( is_wp_error( $result ) ) {
        $result = qrg_solution_soap_get_attlog( $ip, 'All', $com_key, '', '' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    }

    // Filter klien: beberapa mesin mengabaikan StartTime/EndTime.
    $in_range = [];
    foreach ( $result as $row ) {
        $dt = (string) ( $row['datetime'] ?? '' );
        if ( $dt !== '' && strpos( $dt, $prefix ) === 0 ) {
            $in_range[] = $row;
        }
    }
    // Jika mesin kirim data di luar bulan: simpan semua, tapi hitung bulan terpilih.
    $to_store = ! empty( $in_range ) ? $in_range : $result;

    $cache   = qrg_solution_get_device_attlog( $device['id'] );
    $old     = is_array( $cache['records'] ) ? $cache['records'] : [];
    $merged  = [];
    $seen_dt = [];
    foreach ( $old as $row ) {
        $dt  = (string) ( $row['datetime'] ?? '' );
        $pin = (string) ( $row['pin'] ?? '' );
        // Ganti ulang data bulan yang sedang diambil.
        if ( $dt === '' || strpos( $dt, $prefix ) === 0 ) {
            continue;
        }
        // Buang cache lama yang hanya PIN 0 (hasil bug cast All→0).
        if ( $pin === '0' || $pin === '' ) {
            continue;
        }
        $k = $pin . '|' . $dt;
        $seen_dt[ $k ] = true;
        $merged[]      = $row;
    }
    foreach ( $to_store as $row ) {
        $dt  = (string) ( $row['datetime'] ?? '' );
        $pin = (string) ( $row['pin'] ?? '' );
        if ( $dt === '' || $pin === '' ) {
            continue;
        }
        $k = $pin . '|' . $dt;
        if ( isset( $seen_dt[ $k ] ) ) {
            continue;
        }
        $seen_dt[ $k ] = true;
        $merged[]      = [
            'pin'      => $pin,
            'datetime' => $dt,
            'inout'    => (int) ( $row['inout'] ?? 0 ),
            'status'   => (int) ( $row['status'] ?? $row['inout'] ?? 0 ),
            'verified' => (int) ( $row['verified'] ?? 0 ),
        ];
    }
    usort( $merged, static function ( $a, $b ) {
        return strcmp( (string) $b['datetime'], (string) $a['datetime'] );
    } );
    if ( count( $merged ) > 5000 ) {
        $merged = array_slice( $merged, 0, 5000 );
    }
    qrg_solution_save_device_attlog( $device['id'], $merged, 'soap' );

    $sn    = (string) ( $device['sn'] ?? '' );
    $saved = qrg_solution_process_attendance_records( ! empty( $in_range ) ? $in_range : $result, $sn );

    $in_month = count( $in_range );
    $total    = count( $result );
    if ( $in_month > 0 ) {
        $msg = $in_month . ' log absensi untuk ' . $prefix . ' diambil dari mesin';
    } elseif ( $total > 0 ) {
        $sample = (string) ( $result[0]['datetime'] ?? '' );
        $msg    = $total . ' log diambil dari mesin, tetapi tidak ada yang masuk periode ' . $prefix
            . ( $sample !== '' ? ' (contoh tanggal di mesin: ' . substr( $sample, 0, 10 ) . ')' : '' )
            . '. Ubah filter bulan/tahun lalu Tampilkan.';
    } else {
        $msg = 'Mesin tidak mengirim log absensi.';
    }
    if ( $saved > 0 ) {
        $msg .= '; ' . $saved . ' disimpan ke Data Presensi.';
    }

    return [
        'ok'      => true,
        'count'   => $in_month > 0 ? $in_month : $total,
        'saved'   => (int) $saved,
        'message' => $msg,
        'warning' => ( $in_month === 0 && $total > 0 ),
    ];
}

/**
 * Daftar siapa yang absen di mesin (dari cache log mesin saja).
 *
 * @param array<string,mixed> $device
 * @return array{items:array<int,array<string,mixed>>,people:int,punches:int,updated:string,source:string}
 */
function qrg_solution_list_device_presensi_who( $device, $bulan, $tahun ) {
    $bulan  = (int) $bulan;
    $tahun  = (int) $tahun;
    $prefix = sprintf( '%04d-%02d', $tahun, $bulan );
    $cache  = qrg_solution_get_device_attlog( $device['id'] );
    $raw    = is_array( $cache['records'] ) ? $cache['records'] : [];

    // Peta PIN → nama di mesin.
    $users_cache = qrg_solution_get_device_users( $device['id'] );
    $nama_map    = [];
    foreach ( (array) ( $users_cache['users'] ?? [] ) as $u ) {
        $p = (string) ( $u['pin'] ?? '' );
        if ( $p !== '' ) {
            $nama_map[ $p ] = (string) ( $u['nama'] ?? '' );
        }
    }

    $items      = [];
    $people     = [];
    $other_min  = '';
    $other_max  = '';
    $other_n    = 0;
    foreach ( $raw as $row ) {
        $dt = (string) ( $row['datetime'] ?? '' );
        if ( $dt === '' ) {
            continue;
        }
        if ( strpos( $dt, $prefix ) !== 0 ) {
            $other_n++;
            if ( $other_min === '' || $dt < $other_min ) {
                $other_min = $dt;
            }
            if ( $other_max === '' || $dt > $other_max ) {
                $other_max = $dt;
            }
            continue;
        }
        $pin  = (string) ( $row['pin'] ?? '' );
        $nama = $nama_map[ $pin ] ?? '';
        if ( $pin !== '' ) {
            $people[ $pin ] = true;
        }
        $items[] = [
            'pin'        => $pin,
            'nama'       => $nama !== '' ? $nama : ( $pin !== '' ? 'PIN ' . $pin : '—' ),
            'tanggal'    => substr( $dt, 0, 10 ),
            'jam'        => substr( $dt, 11, 8 ),
            'datetime'   => $dt,
            'status'     => qrg_solution_attlog_status_label( $row['inout'] ?? $row['status'] ?? 0 ),
            'source'     => 'mesin',
            'finger_url' => $pin !== '' ? qrg_solution_admin_url( [
                'tab'    => 'user',
                'device' => $device['id'],
                'finger' => $pin,
            ] ) : '',
        ];
    }

    usort( $items, static function ( $a, $b ) {
        return strcmp( (string) ( $b['datetime'] ?? '' ), (string) ( $a['datetime'] ?? '' ) );
    } );

    return [
        'items'     => $items,
        'people'    => count( $people ),
        'punches'   => count( $items ),
        'updated'   => (string) ( $cache['updated'] ?? '' ),
        'source'    => ! empty( $items ) ? 'mesin' : 'empty',
        'other_n'   => $other_n,
        'other_min' => $other_min,
        'other_max' => $other_max,
    ];
}

/**
 * Gabungkan log presensi semua mesin jadi satu daftar.
 *
 * @param string $device_id Kosong = semua mesin.
 * @return array{items:array<int,array<string,mixed>>,people:int,punches:int,updated:string,other_n:int,other_min:string,other_max:string,devices:int}
 */
function qrg_solution_list_all_presensi_who( $bulan, $tahun, $device_id = '' ) {
    $bulan     = (int) $bulan;
    $tahun     = (int) $tahun;
    $device_id = sanitize_key( (string) $device_id );
    $devices   = qrg_solution_get_devices();
    if ( $device_id !== '' ) {
        $one = qrg_solution_get_device( $device_id );
        $devices = $one ? [ $one ] : [];
    }

    $items     = [];
    $people    = [];
    $seen      = [];
    $updated   = '';
    $other_n   = 0;
    $other_min = '';
    $other_max = '';
    $used      = 0;

    foreach ( $devices as $device ) {
        $data = qrg_solution_list_device_presensi_who( $device, $bulan, $tahun );
        $used++;
        if ( ! empty( $data['updated'] ) && ( $updated === '' || $data['updated'] > $updated ) ) {
            $updated = (string) $data['updated'];
        }
        $other_n += (int) ( $data['other_n'] ?? 0 );
        if ( ! empty( $data['other_min'] ) && ( $other_min === '' || $data['other_min'] < $other_min ) ) {
            $other_min = (string) $data['other_min'];
        }
        if ( ! empty( $data['other_max'] ) && ( $other_max === '' || $data['other_max'] > $other_max ) ) {
            $other_max = (string) $data['other_max'];
        }
        $mesin_nama = (string) ( $device['nama'] !== '' ? $device['nama'] : $device['id'] );
        if ( ! empty( $device['lokasi'] ) ) {
            $mesin_nama .= ' · ' . $device['lokasi'];
        }
        foreach ( (array) ( $data['items'] ?? [] ) as $row ) {
            $pin = (string) ( $row['pin'] ?? '' );
            $dt  = (string) ( $row['datetime'] ?? '' );
            $key = $device['id'] . '|' . $pin . '|' . $dt;
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            if ( $pin !== '' ) {
                $people[ $pin ] = true;
            }
            $row['device_id']   = $device['id'];
            $row['mesin']       = $mesin_nama;
            $row['mesin_ip']    = (string) ( $device['ip'] ?? '' );
            $items[]            = $row;
        }
    }

    usort( $items, static function ( $a, $b ) {
        return strcmp( (string) ( $b['datetime'] ?? '' ), (string) ( $a['datetime'] ?? '' ) );
    } );

    return [
        'items'     => $items,
        'people'    => count( $people ),
        'punches'   => count( $items ),
        'updated'   => $updated,
        'other_n'   => $other_n,
        'other_min' => $other_min,
        'other_max' => $other_max,
        'devices'   => $used,
    ];
}

/**
 * Ambil log dari semua mesin terdaftar.
 *
 * @return array{ok:bool,count:int,saved:int,message:string,warning:bool}|WP_Error
 */
function qrg_solution_fetch_all_devices_attlog( $bulan = 0, $tahun = 0 ) {
    $devices = qrg_solution_get_devices();
    if ( empty( $devices ) ) {
        return new WP_Error( 'no_device', 'Belum ada mesin terdaftar.' );
    }
    $total   = 0;
    $saved   = 0;
    $ok_n    = 0;
    $fail    = [];
    $warn    = false;
    $parts   = [];
    foreach ( $devices as $device ) {
        $label = $device['nama'] !== '' ? $device['nama'] : $device['id'];
        if ( empty( $device['ip'] ) ) {
            $fail[] = $label . ': IP kosong';
            continue;
        }
        $fetch = qrg_solution_fetch_device_attlog( $device, $bulan, $tahun );
        if ( is_wp_error( $fetch ) ) {
            $fail[] = $label . ': ' . $fetch->get_error_message();
            continue;
        }
        $ok_n++;
        $total += (int) ( $fetch['count'] ?? 0 );
        $saved += (int) ( $fetch['saved'] ?? 0 );
        if ( ! empty( $fetch['warning'] ) ) {
            $warn = true;
        }
        $parts[] = $label . ' (' . (int) ( $fetch['count'] ?? 0 ) . ')';
    }
    if ( $ok_n === 0 ) {
        return new WP_Error( 'fetch_fail', 'Gagal mengambil log: ' . implode( '; ', $fail ) );
    }
    $msg = $total . ' log dari ' . $ok_n . ' mesin digabung'
        . ( $parts ? ': ' . implode( ', ', $parts ) : '' );
    if ( $fail ) {
        $msg .= '. Gagal: ' . implode( '; ', $fail );
        $warn = true;
    }
    if ( $saved > 0 ) {
        $msg .= '; ' . $saved . ' disimpan ke Data Presensi.';
    }
    return [
        'ok'      => true,
        'count'   => $total,
        'saved'   => $saved,
        'message' => $msg,
        'warning' => $warn,
    ];
}

/**
 * URL gambar QR (api.qrserver) — cocok untuk payload pendek.
 */
function qrg_solution_qr_image_url( $data, $size = 180 ) {
    $data = (string) $data;
    $size = max( 80, min( 400, (int) $size ) );
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&ecc=L&margin=6&data=' . rawurlencode( $data );
}

function qrg_solution_current_tab() {
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'mesin';
    // Kompatibilitas link lama ?users=device_id
    if ( isset( $_GET['users'] ) && sanitize_key( wp_unslash( $_GET['users'] ) ) !== '' && $tab === 'mesin' ) {
        $tab = 'user';
    }
    // Kompatibilitas link lama ?presensi=device_id di tab mesin
    if ( isset( $_GET['presensi'] ) && sanitize_key( wp_unslash( $_GET['presensi'] ) ) !== '' && ( $tab === 'mesin' || $tab === '' ) ) {
        $tab = 'presensi';
    }
    $tabs = array_keys( qrg_solution_get_admin_tabs() );
    if ( ! in_array( $tab, $tabs, true ) ) {
        $tab = 'mesin';
    }
    return $tab;
}

function qrg_solution_render_admin_tabs( $current = '' ) {
    if ( $current === '' ) {
        $current = qrg_solution_current_tab();
    }
    if ( isset( $_GET['users'] ) && sanitize_key( wp_unslash( $_GET['users'] ) ) !== '' ) {
        $current = 'user';
    }
    if ( isset( $_GET['finger'] ) && sanitize_text_field( wp_unslash( $_GET['finger'] ) ) !== '' ) {
        $current = 'user';
    }
    if ( isset( $_GET['presensi'] ) && sanitize_key( wp_unslash( $_GET['presensi'] ) ) !== '' ) {
        $current = 'presensi';
    }
    echo '<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:15px;">';
    foreach ( qrg_solution_get_admin_tabs() as $slug => $label ) {
        $url = qrg_solution_admin_url( [ 'tab' => $slug ] );
        $cls = 'nav-tab' . ( $current === $slug ? ' nav-tab-active' : '' );
        printf(
            '<a href="%s" class="%s">%s</a>',
            esc_url( $url ),
            esc_attr( $cls ),
            esc_html( $label )
        );
    }
    echo '</nav>';
}

function qrg_solution_render_finger_panel( $pin, $device_id = '' ) {
    $pin       = sanitize_text_field( (string) $pin );
    $device_id = sanitize_key( (string) $device_id );
    $back      = qrg_solution_admin_url( array_filter( [ 'tab' => 'user', 'device' => $device_id ] ) );

    if ( $pin === '' ) {
        echo '<div class="notice notice-error"><p>PIN tidak valid. <a href="' . esc_url( $back ) . '">Kembali</a></p></div>';
        return;
    }

    // Cari user + mesin sumber (prioritas device_id filter).
    $rows   = qrg_solution_collect_users( $device_id );
    $row    = null;
    foreach ( $rows as $r ) {
        if ( (string) $r['pin'] === $pin ) {
            $row = $r;
            break;
        }
    }
    if ( ! $row ) {
        // Coba semua mesin jika filter tidak menemukan.
        foreach ( qrg_solution_collect_users( '' ) as $r ) {
            if ( (string) $r['pin'] === $pin ) {
                $row = $r;
                break;
            }
        }
    }
    if ( ! $row ) {
        echo '<div class="notice notice-error"><p>User PIN <code>' . esc_html( $pin ) . '</code> tidak ditemukan. <a href="' . esc_url( $back ) . '">Kembali</a></p></div>';
        return;
    }

    $device = ! empty( $row['device'] ) ? $row['device'] : qrg_solution_get_device( $row['device_id'] );
    if ( ! $device ) {
        echo '<div class="notice notice-error"><p>Mesin sumber tidak ditemukan. <a href="' . esc_url( $back ) . '">Kembali</a></p></div>';
        return;
    }

    $cache = qrg_solution_get_user_fingers( $device['id'], $pin );
    $fingers = is_array( $cache['fingers'] ) ? $cache['fingers'] : [];

    $flash_key = 'qrg_solution_finger_flash_' . get_current_user_id();
    $flash     = get_transient( $flash_key );
    if ( $flash ) {
        delete_transient( $flash_key );
    }

    $form_action = qrg_solution_admin_url( [ 'tab' => 'user', 'device' => $device['id'], 'finger' => $pin ] );
    $is_admin    = qrg_solution_is_admin_login_pin( $pin, $device );
    $nama_user   = $row['nama'] !== '' ? $row['nama'] : ( $is_admin ? 'Admin mesin' : 'Tanpa nama' );

    $by_fid = [];
    foreach ( $fingers as $f ) {
        $by_fid[ (int) $f['finger_id'] ] = $f;
    }
    $enrolled_rows = [];
    for ( $i = 0; $i <= 9; $i++ ) {
        if ( empty( $by_fid[ $i ] ) ) {
            continue;
        }
        $f = $by_fid[ $i ];
        if ( ! empty( $f['has_data'] ) || ! empty( $f['template'] ) || ! empty( $f['preview'] ) ) {
            $enrolled_rows[] = $f;
        }
    }
    $enrolled_n = count( $enrolled_rows );

    $bulan_f = isset( $_GET['bulan'] ) ? absint( $_GET['bulan'] ) : (int) current_time( 'n' );
    $tahun_f = isset( $_GET['tahun'] ) ? absint( $_GET['tahun'] ) : (int) current_time( 'Y' );
    if ( $bulan_f < 1 || $bulan_f > 12 ) {
        $bulan_f = (int) current_time( 'n' );
    }
    if ( $tahun_f < 2000 || $tahun_f > 2100 ) {
        $tahun_f = (int) current_time( 'Y' );
    }

    $att_cache   = qrg_solution_get_user_attlog( $device['id'], $pin );
    $att_raw     = is_array( $att_cache['records'] ) ? $att_cache['records'] : [];
    $presensi_items = qrg_solution_group_attlog_days( $att_raw, $bulan_f, $tahun_f );
    $guru_id     = qrg_solution_find_guru_by_pin( $pin );
    $att_flash_key = 'qrg_solution_attlog_flash_' . get_current_user_id();
    $att_flash   = get_transient( $att_flash_key );
    if ( $att_flash ) {
        delete_transient( $att_flash_key );
    }
    $bulan_nama = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $punch_count = 0;
    foreach ( $att_raw as $ar ) {
        if ( strpos( (string) ( $ar['datetime'] ?? '' ), sprintf( '%04d-%02d', $tahun_f, $bulan_f ) ) === 0 ) {
            $punch_count++;
        }
    }

    qrg_solution_render_admin_tabs( 'user' );
    ?>
    <style>
        .qrg-sj { max-width: 980px; }
        .qrg-sj-head {
            display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between;
            gap: 12px; margin: 0 0 16px; padding: 14px 16px;
            background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .qrg-sj-head h2 { margin: 0 0 6px; font-size: 18px; line-height: 1.3; }
        .qrg-sj-head .meta { margin: 0; color: #646970; font-size: 13px; line-height: 1.5; }
        .qrg-sj-head .meta code { font-size: 12px; }
        .qrg-sj-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .qrg-sj-stats {
            display: flex; flex-wrap: wrap; gap: 8px 16px; margin: 0 0 16px; padding: 10px 14px;
            background: #f6f7f7; border: 1px solid #dcdcde;
        }
        .qrg-sj-stats strong { color: #1d2327; }
        .qrg-sj-slots {
            display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; margin: 0 0 20px;
        }
        @media (max-width: 782px) {
            .qrg-sj-slots { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .qrg-sj-slot {
            background: #fff; border: 1px solid #dcdcde; padding: 10px 12px; text-align: center;
        }
        .qrg-sj-slot.is-on { border-color: #00a32a; background: #edfaef; }
        .qrg-sj-slot .id { display: block; font-family: Consolas, Monaco, monospace; font-size: 12px; color: #646970; }
        .qrg-sj-slot .name { display: block; font-size: 12px; font-weight: 600; color: #1d2327; margin: 4px 0; line-height: 1.3; }
        .qrg-sj-slot .st { display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 3px; background: #f0f0f1; color: #646970; }
        .qrg-sj-slot.is-on .st { background: #00a32a; color: #fff; }
        .qrg-sj-section { margin: 0 0 8px; font-size: 14px; }
        .qrg-sj-list { margin: 0 0 16px; }
        .qrg-sj-item {
            background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin: 0 0 12px; overflow: hidden;
        }
        .qrg-sj-item__head {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 8px 12px; padding: 10px 14px; background: #f6f7f7; border-bottom: 1px solid #dcdcde;
        }
        .qrg-sj-item__head h3 { margin: 0; font-size: 14px; font-weight: 600; }
        .qrg-sj-badge {
            display: inline-block; padding: 2px 8px; border-radius: 3px;
            font-size: 11px; font-weight: 600; background: #edfaef; color: #007017;
        }
        .qrg-sj-item__body {
            display: grid; grid-template-columns: 180px 1fr; gap: 0;
        }
        @media (max-width: 782px) {
            .qrg-sj-item__body { grid-template-columns: 1fr; }
        }
        .qrg-sj-qr {
            padding: 16px; text-align: center; border-right: 1px solid #f0f0f1;
            background: #fafafa;
        }
        @media (max-width: 782px) {
            .qrg-sj-qr { border-right: 0; border-bottom: 1px solid #f0f0f1; }
        }
        .qrg-sj-qr img,
        .qrg-sj-qr .qrg-finger-qr img,
        .qrg-sj-qr .qrg-finger-qr canvas {
            width: 140px !important; height: 140px !important;
            background: #fff; border: 1px solid #dcdcde; padding: 6px; box-sizing: content-box;
            display: inline-block;
        }
        .qrg-sj-qr .qrg-finger-qr { min-height: 140px; display: inline-block; }
        .qrg-sj-qr .label { margin: 8px 0 0; font-size: 11px; color: #646970; }
        .qrg-sj-tpl { padding: 14px 16px; min-width: 0; }
        .qrg-sj-tpl .row-meta {
            display: flex; flex-wrap: wrap; gap: 8px 16px; margin: 0 0 10px;
            font-size: 12px; color: #646970;
        }
        .qrg-sj-tpl label { display: block; font-weight: 600; margin: 0 0 6px; font-size: 12px; }
        .qrg-sj-tpl textarea {
            width: 100%; min-height: 110px; max-height: 220px;
            font-family: Consolas, Monaco, monospace; font-size: 11px; line-height: 1.45;
            word-break: break-all; resize: vertical; margin: 0;
            background: #f6f7f7; border-color: #c3c4c7;
        }
        .qrg-sj-tpl .tpl-actions { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .qrg-sj-empty {
            margin: 0 0 16px; padding: 28px 16px; text-align: center;
            background: #fff; border: 1px dashed #c3c4c7; color: #646970;
        }
        .qrg-sj-presensi { margin-top: 24px; }
        .qrg-sj-presensi__bar {
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
            margin: 0 0 12px;
        }
        .qrg-sj-presensi__bar select { max-width: 160px; }
        .qrg-sj-presensi .wp-list-table { background: #fff; }
        .qrg-sj-presensi .col-tgl { width: 140px; }
        .qrg-sj-presensi .col-jam { width: 100px; }
        .qrg-sj-presensi .col-st { width: 100px; }
        .qrg-sj-presensi .col-via { width: 90px; }
    </style>

    <div class="qrg-sj">
        <div class="qrg-sj-head">
            <div>
                <h2>Sidik jari · PIN <code><?php echo esc_html( $pin ); ?></code></h2>
                <p class="meta">
                    <?php echo esc_html( $nama_user ); ?>
                    <?php if ( $is_admin ) : ?> · <strong>Admin mesin</strong><?php endif; ?>
                    <br>
                    Mesin: <strong><?php echo esc_html( $device['nama'] ); ?></strong>
                    <?php if ( ! empty( $device['ip'] ) ) : ?>
                        · IP <code><?php echo esc_html( $device['ip'] ); ?></code>
                    <?php endif; ?>
                    <?php if ( ! empty( $device['lokasi'] ) ) : ?>
                        · <?php echo esc_html( $device['lokasi'] ); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="qrg-sj-actions">
                <a href="<?php echo esc_url( $back ); ?>" class="button">← Daftar user</a>
                <form method="post" action="<?php echo esc_url( $form_action ); ?>" style="margin:0;display:inline;">
                    <?php wp_nonce_field( 'qrg_solution_fingers', 'qrg_solution_fingers_nonce' ); ?>
                    <input type="hidden" name="device_id" value="<?php echo esc_attr( $device['id'] ); ?>">
                    <input type="hidden" name="user_pin" value="<?php echo esc_attr( $pin ); ?>">
                    <?php submit_button( 'Ambil sidik jari', 'primary', 'qrg_solution_fetch_fingers', false ); ?>
                </form>
            </div>
        </div>

        <?php if ( is_array( $flash ) && ! empty( $flash['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $flash['error'] ); ?></p></div>
        <?php elseif ( is_array( $flash ) && ! empty( $flash['ok'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $flash['message'] ?: 'Data sidik jari diperbarui.' ); ?></p></div>
        <?php endif; ?>

        <?php if ( is_array( $att_flash ) && ! empty( $att_flash['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $att_flash['error'] ); ?></p></div>
        <?php elseif ( is_array( $att_flash ) && ! empty( $att_flash['ok'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $att_flash['message'] ?: 'Log absensi diperbarui.' ); ?></p></div>
        <?php endif; ?>

        <div class="qrg-sj-stats">
            <span><strong><?php echo (int) $enrolled_n; ?></strong> / 10 jari terisi</span>
            <span><strong><?php echo (int) count( $presensi_items ); ?></strong> hari · <strong><?php echo (int) $punch_count; ?></strong> punch (<?php echo esc_html( ( $bulan_nama[ $bulan_f ] ?? '' ) . ' ' . $tahun_f ); ?>)</span>
            <?php if ( ! empty( $att_cache['updated'] ) ) : ?>
                <span>Presensi mesin: <strong><?php echo esc_html( $att_cache['updated'] ); ?></strong></span>
            <?php endif; ?>
            <?php if ( ! empty( $cache['updated'] ) ) : ?>
                <span>Sidik jari: <strong><?php echo esc_html( $cache['updated'] ); ?></strong></span>
            <?php endif; ?>
        </div>

        <div class="qrg-sj-presensi">
            <h3 class="qrg-sj-section">Record presensi (dari mesin)</h3>
            <div class="qrg-sj-presensi__bar">
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0;">
                    <input type="hidden" name="page" value="qrg-presensi">
                    <input type="hidden" name="perangkat" value="1">
                    <input type="hidden" name="tab" value="user">
                    <input type="hidden" name="device" value="<?php echo esc_attr( $device['id'] ); ?>">
                    <input type="hidden" name="finger" value="<?php echo esc_attr( $pin ); ?>">
                    <label for="qrg-sj-bulan" class="screen-reader-text">Bulan</label>
                    <select name="bulan" id="qrg-sj-bulan">
                        <?php for ( $b = 1; $b <= 12; $b++ ) : ?>
                            <option value="<?php echo (int) $b; ?>" <?php selected( $bulan_f, $b ); ?>><?php echo esc_html( $bulan_nama[ $b ] ); ?></option>
                        <?php endfor; ?>
                    </select>
                    <label for="qrg-sj-tahun" class="screen-reader-text">Tahun</label>
                    <select name="tahun" id="qrg-sj-tahun">
                        <?php
                        $y_now = (int) current_time( 'Y' );
                        for ( $y = $y_now; $y >= $y_now - 5; $y-- ) :
                            ?>
                            <option value="<?php echo (int) $y; ?>" <?php selected( $tahun_f, $y ); ?>><?php echo (int) $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <?php submit_button( 'Tampilkan', 'secondary', '', false ); ?>
                </form>
                <form method="post" action="<?php echo esc_url( $form_action ); ?>" style="margin:0;display:inline;">
                    <?php wp_nonce_field( 'qrg_solution_attlog', 'qrg_solution_attlog_nonce' ); ?>
                    <input type="hidden" name="device_id" value="<?php echo esc_attr( $device['id'] ); ?>">
                    <input type="hidden" name="user_pin" value="<?php echo esc_attr( $pin ); ?>">
                    <input type="hidden" name="bulan" value="<?php echo (int) $bulan_f; ?>">
                    <input type="hidden" name="tahun" value="<?php echo (int) $tahun_f; ?>">
                    <?php submit_button( 'Ambil presensi dari mesin', 'primary', 'qrg_solution_fetch_attlog', false ); ?>
                </form>
            </div>
            <p class="description" style="margin-top:0;">Data diambil langsung dari mesin via SOAP <code>GetAttLog</code>. Klik <strong>Ambil presensi dari mesin</strong> untuk memperbarui.</p>

            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th scope="col" class="col-tgl">Tanggal</th>
                        <th scope="col" class="col-jam">Jam masuk</th>
                        <th scope="col" class="col-jam">Jam pulang</th>
                        <th scope="col">Detail punch</th>
                        <th scope="col" class="col-via">Sumber</th>
                        <th scope="col">Mesin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $att_raw ) ) : ?>
                        <tr class="no-items">
                            <td class="colspanchange" colspan="6">
                                Belum ada data dari mesin. Pastikan IP mesin terjangkau dari server, lalu klik <strong>Ambil presensi dari mesin</strong>.
                            </td>
                        </tr>
                    <?php elseif ( empty( $presensi_items ) ) : ?>
                        <tr class="no-items">
                            <td class="colspanchange" colspan="6">
                                Tidak ada log absensi PIN <code><?php echo esc_html( $pin ); ?></code> untuk <?php echo esc_html( ( $bulan_nama[ $bulan_f ] ?? '' ) . ' ' . $tahun_f ); ?>.
                                Coba bulan lain atau ambil ulang dari mesin.
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $presensi_items as $pr ) :
                            $tgl_label = $pr['tanggal'] !== '' ? date_i18n( 'd M Y (D)', strtotime( $pr['tanggal'] ) ) : '—';
                            $punches   = is_array( $pr['punches'] ?? null ) ? $pr['punches'] : [];
                            $punch_txt = [];
                            foreach ( $punches as $p ) {
                                $punch_txt[] = ( $p['jam'] ?? '' ) . ( ! empty( $p['label'] ) ? ' (' . $p['label'] . ')' : '' );
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $tgl_label ); ?></strong>
                                    <div class="description"><code><?php echo esc_html( $pr['tanggal'] ); ?></code></div>
                                </td>
                                <td><?php echo esc_html( $pr['jam_masuk'] !== '' ? $pr['jam_masuk'] : '—' ); ?></td>
                                <td><?php echo esc_html( $pr['jam_pulang'] !== '' ? $pr['jam_pulang'] : '—' ); ?></td>
                                <td>
                                    <?php if ( $punch_txt ) : ?>
                                        <span class="description"><?php echo esc_html( implode( ' · ', $punch_txt ) ); ?></span>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>Mesin</td>
                                <td>
                                    <?php echo esc_html( $device['nama'] ); ?>
                                    <?php if ( ! empty( $device['ip'] ) ) : ?>
                                        <div class="description"><code><?php echo esc_html( $device['ip'] ); ?></code></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ( ! empty( $presensi_items ) ) : ?>
                <p class="description">
                    <?php echo (int) count( $presensi_items ); ?> hari · <?php echo (int) $punch_count; ?> punch dari mesin
                    <?php if ( ! empty( $att_cache['updated'] ) ) : ?>
                        · terakhir diambil <?php echo esc_html( $att_cache['updated'] ); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <h3 class="qrg-sj-section">Ringkasan slot jari</h3>
        <div class="qrg-sj-slots">
            <?php for ( $i = 0; $i <= 9; $i++ ) :
                $f   = $by_fid[ $i ] ?? null;
                $has = $f && ( ! empty( $f['has_data'] ) || ! empty( $f['template'] ) || ! empty( $f['preview'] ) );
                ?>
                <div class="qrg-sj-slot<?php echo $has ? ' is-on' : ''; ?>">
                    <span class="id">#<?php echo (int) $i; ?></span>
                    <span class="name"><?php echo esc_html( qrg_solution_finger_label( $i ) ); ?></span>
                    <span class="st"><?php echo $has ? 'Terdaftar' : 'Kosong'; ?></span>
                </div>
            <?php endfor; ?>
        </div>

        <h3 class="qrg-sj-section">Detail &amp; QR template</h3>

        <?php if ( empty( $fingers ) ) : ?>
            <div class="qrg-sj-empty">
                Belum ada data sidik jari.<br>
                Klik <strong>Ambil dari mesin</strong> untuk mengunduh template.
            </div>
        <?php elseif ( empty( $enrolled_rows ) ) : ?>
            <div class="qrg-sj-empty">Tidak ada sidik jari terdaftar untuk PIN ini di mesin.</div>
        <?php else : ?>
            <div class="qrg-sj-list">
                <?php foreach ( $enrolled_rows as $f ) :
                    $fid     = (int) $f['finger_id'];
                    $tpl     = (string) ( $f['template'] ?? '' );
                    $partial = false;
                    if ( $tpl === '' && ! empty( $f['preview'] ) ) {
                        $tpl     = rtrim( (string) $f['preview'], '…' );
                        $partial = true;
                    }
                    $len     = ! empty( $f['length'] ) ? (int) $f['length'] : strlen( $tpl );
                    $qr_id   = 'qrg-finger-qr-' . $fid;
                    $tpl_id  = 'qrg-finger-tpl-' . $fid;
                    $use_api = ( $tpl !== '' && strlen( $tpl ) <= 1200 );
                    $qr_api  = $use_api ? qrg_solution_qr_image_url( $tpl, 140 ) : '';
                    ?>
                    <div class="qrg-sj-item">
                        <div class="qrg-sj-item__head">
                            <h3>
                                <code>#<?php echo (int) $fid; ?></code>
                                <?php echo esc_html( qrg_solution_finger_label( $fid ) ); ?>
                            </h3>
                            <span class="qrg-sj-badge">Terdaftar</span>
                        </div>
                        <div class="qrg-sj-item__body">
                            <div class="qrg-sj-qr">
                                <?php if ( $tpl === '' ) : ?>
                                    <p class="description">Tidak ada template</p>
                                <?php elseif ( $use_api ) : ?>
                                    <img src="<?php echo esc_url( $qr_api ); ?>" alt="QR jari <?php echo esc_attr( (string) $fid ); ?>" width="140" height="140" loading="lazy">
                                    <p class="label">QR template</p>
                                <?php else : ?>
                                    <div class="qrg-finger-qr" id="<?php echo esc_attr( $qr_id ); ?>" data-tpl-target="<?php echo esc_attr( $tpl_id ); ?>"></div>
                                    <p class="label qr-note">QR template</p>
                                <?php endif; ?>
                            </div>
                            <div class="qrg-sj-tpl">
                                <div class="row-meta">
                                    <span>Ukuran: <strong><?php echo $len > 0 ? esc_html( number_format_i18n( $len ) ) . ' karakter' : '—'; ?></strong></span>
                                    <?php if ( $partial ) : ?>
                                        <span style="color:#b32d2e;">Cuplikan lama — ambil ulang dari mesin</span>
                                    <?php endif; ?>
                                </div>
                                <label for="<?php echo esc_attr( $tpl_id ); ?>">Detail template</label>
                                <textarea id="<?php echo esc_attr( $tpl_id ); ?>" class="qrg-finger-tpl-data" readonly rows="6"><?php echo esc_textarea( $tpl ); ?></textarea>
                                <div class="tpl-actions">
                                    <button type="button" class="button button-small qrg-sj-copy" data-target="<?php echo esc_attr( $tpl_id ); ?>">Salin template</button>
                                    <span class="description qrg-sj-copy-msg" hidden>Disalin.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
            <script>
            (function() {
                function renderQr() {
                    if (typeof QRCode === 'undefined') return;
                    document.querySelectorAll('.qrg-finger-qr[data-tpl-target]').forEach(function(el) {
                        var tid = el.getAttribute('data-tpl-target');
                        var ta = tid ? document.getElementById(tid) : null;
                        var text = ta ? String(ta.value || '') : '';
                        if (!text) {
                            el.innerHTML = '<span class="description">Kosong</span>';
                            return;
                        }
                        el.innerHTML = '';
                        try {
                            new QRCode(el, {
                                text: text,
                                width: 140,
                                height: 140,
                                colorDark: '#1d2327',
                                colorLight: '#ffffff',
                                correctLevel: QRCode.CorrectLevel.L
                            });
                        } catch (err) {
                            var clipped = text.slice(0, 1960) + '|len:' + text.length;
                            try {
                                new QRCode(el, {
                                    text: clipped,
                                    width: 140,
                                    height: 140,
                                    correctLevel: QRCode.CorrectLevel.L
                                });
                                var note = el.parentNode.querySelector('.qr-note');
                                if (note) note.textContent = 'QR potongan (template terlalu panjang)';
                            } catch (err2) {
                                el.innerHTML = '<span class="description">Tidak bisa dibuat QR</span>';
                            }
                        }
                    });
                }
                document.querySelectorAll('.qrg-sj-copy').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var ta = document.getElementById(btn.getAttribute('data-target'));
                        if (!ta) return;
                        var msg = btn.parentNode.querySelector('.qrg-sj-copy-msg');
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(ta.value).then(function() {
                                if (msg) { msg.hidden = false; setTimeout(function(){ msg.hidden = true; }, 1500); }
                            });
                        } else {
                            ta.select();
                            document.execCommand('copy');
                            if (msg) { msg.hidden = false; setTimeout(function(){ msg.hidden = true; }, 1500); }
                        }
                    });
                });
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', renderQr);
                } else {
                    renderQr();
                }
            })();
            </script>
        <?php endif; ?>
    </div>
    <?php
}

function qrg_solution_render_users_panel( $filter_device_id = '' ) {
    $devices   = qrg_solution_get_devices();
    $filter_id = sanitize_key( (string) $filter_device_id );
    if ( $filter_id === '' && isset( $_GET['users'] ) ) {
        $filter_id = sanitize_key( wp_unslash( $_GET['users'] ) );
    }
    if ( $filter_id === '' && isset( $_GET['device'] ) ) {
        $filter_id = sanitize_key( wp_unslash( $_GET['device'] ) );
    }

    $finger_pin = isset( $_GET['finger'] ) ? sanitize_text_field( wp_unslash( $_GET['finger'] ) ) : '';
    if ( $finger_pin !== '' ) {
        qrg_solution_render_finger_panel( $finger_pin, $filter_id );
        return;
    }

    $filter_device = $filter_id !== '' ? qrg_solution_get_device( $filter_id ) : null;
    if ( $filter_id !== '' && ! $filter_device ) {
        echo '<div class="notice notice-error"><p>Mesin tidak ditemukan. <a href="' . esc_url( qrg_solution_admin_url( [ 'tab' => 'user' ] ) ) . '">Kembali ke daftar user</a></p></div>';
        return;
    }

    $rows = qrg_solution_collect_users( $filter_id );
    $total_users = count( $rows );
    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

    /**
     * @param array<string,mixed> $row
     */
    $haystack_of = static function ( $row, $guru_name = '' ) {
        $parts = [
            (string) ( $row['pin'] ?? '' ),
            (string) ( $row['nama'] ?? '' ),
            (string) ( $row['card'] ?? '' ),
            (string) ( $row['device_nama'] ?? '' ),
            qrg_solution_privilege_label( $row['privilege'] ?? '0' ),
            $guru_name,
        ];
        if ( ! empty( $row['devices'] ) && is_array( $row['devices'] ) ) {
            foreach ( $row['devices'] as $drow ) {
                $parts[] = (string) ( $drow['nama'] ?? '' );
                $parts[] = (string) ( $drow['ip'] ?? '' );
            }
        }
        return strtolower( implode( ' ', $parts ) );
    };

    $flash_key = 'qrg_solution_user_flash_' . get_current_user_id();
    $flash     = get_transient( $flash_key );
    if ( $flash ) {
        delete_transient( $flash_key );
    }

    $pending = false;
    $updated = '';
    $source  = '';
    if ( $filter_device ) {
        $cache   = qrg_solution_get_device_users( $filter_device['id'] );
        $pending = ! empty( $cache['pending'] ) || ( is_array( $flash ) && ! empty( $flash['pending'] ) );
        $updated = (string) ( $cache['updated'] ?? '' );
        $source  = (string) ( $cache['source'] ?? '' );
    } else {
        foreach ( $devices as $d ) {
            if ( ! empty( qrg_solution_get_device_users( $d['id'] )['pending'] ) ) {
                $pending = true;
                break;
            }
        }
        if ( is_array( $flash ) && ! empty( $flash['pending'] ) ) {
            $pending = true;
        }
    }

    $form_action = qrg_solution_admin_url( array_filter( [
        'tab'    => 'user',
        'device' => $filter_id,
    ] ) );
    $show_mesin_col = ( $filter_id === '' );
    $colspan        = $show_mesin_col ? 6 : 5;

    qrg_solution_render_admin_tabs( 'user' );
    ?>
    <h2 class="title">Daftar user</h2>
    <p class="description">
        Setiap PIN ditampilkan sekali (unik). Jika PIN ada di beberapa mesin, mesin digabung di kolom Mesin.
        <?php if ( $filter_device ) : ?>
            Filter: <strong><?php echo esc_html( $filter_device['nama'] ); ?></strong>
            <?php if ( $filter_device['ip'] ) : ?> · IP <code><?php echo esc_html( $filter_device['ip'] ); ?></code><?php endif; ?>
            · <a href="<?php echo esc_url( qrg_solution_admin_url( [ 'tab' => 'user' ] ) ); ?>">Tampilkan semua</a>
        <?php endif; ?>
    </p>

    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="qrg-user-toolbar" style="margin:0 0 12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <input type="hidden" name="page" value="qrg-presensi">
        <input type="hidden" name="perangkat" value="1">
        <input type="hidden" name="tab" value="user">
        <label for="qrg-user-device-filter" class="screen-reader-text">Filter mesin</label>
        <select name="device" id="qrg-user-device-filter">
            <option value="">Semua mesin</option>
            <?php foreach ( $devices as $d ) :
                $n = count( qrg_solution_collect_users( $d['id'] ) );
                $label = ( $d['nama'] !== '' ? $d['nama'] : $d['id'] ) . ( $n ? ' (' . $n . ')' : '' );
                ?>
                <option value="<?php echo esc_attr( $d['id'] ); ?>" <?php selected( $filter_id, $d['id'] ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="qrg-user-search" class="screen-reader-text">Cari user</label>
        <input type="search" id="qrg-user-search" name="s" value="<?php echo esc_attr( $search ); ?>" class="regular-text" placeholder="Cari PIN, nama, kartu…" autocomplete="off" style="max-width:280px;">
        <?php submit_button( 'Cari', 'secondary', '', false ); ?>
        <?php if ( $search !== '' ) : ?>
            <a class="button" href="<?php echo esc_url( qrg_solution_admin_url( array_filter( [ 'tab' => 'user', 'device' => $filter_id ] ) ) ); ?>">Reset</a>
        <?php endif; ?>
    </form>

    <?php if ( is_array( $flash ) && ! empty( $flash['error'] ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $flash['error'] ); ?></p></div>
    <?php elseif ( is_array( $flash ) && ! empty( $flash['pending'] ) ) : ?>
        <div class="notice notice-info"><p><?php echo esc_html( $flash['message'] ?: 'Menunggu mesin mengirim daftar user…' ); ?></p></div>
    <?php elseif ( is_array( $flash ) && ! empty( $flash['ok'] ) ) : ?>
        <div class="notice notice-success"><p><?php echo esc_html( $flash['message'] ?: 'Daftar user diperbarui.' ); ?></p></div>
    <?php endif; ?>

    <?php if ( $filter_device ) : ?>
        <form method="post" action="<?php echo esc_url( $form_action ); ?>" style="margin-bottom:12px;">
            <?php wp_nonce_field( 'qrg_solution_users', 'qrg_solution_users_nonce' ); ?>
            <input type="hidden" name="device_id" value="<?php echo esc_attr( $filter_device['id'] ); ?>">
            <p>
                <button type="submit" name="qrg_solution_fetch_users" class="button button-primary">Ambil user dari mesin</button>
                <?php if ( $updated ) : ?>
                    <span class="description" style="margin-left:8px;">Terakhir: <?php echo esc_html( $updated ); ?><?php echo $source ? ' · ' . esc_html( $source ) : ''; ?></span>
                <?php endif; ?>
            </p>
        </form>
    <?php elseif ( ! empty( $devices ) ) : ?>
        <p class="description" style="margin-bottom:12px;">Pilih mesin di filter di atas, lalu klik <strong>Ambil user dari mesin</strong> untuk memperbarui data.</p>
    <?php endif; ?>

    <?php if ( $pending ) : ?>
        <div class="notice notice-info"><p>Menunggu data dari mesin. Halaman akan dimuat ulang otomatis.</p></div>
        <script>setTimeout(function(){ location.reload(); }, 5000);</script>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped table-view-list" id="qrg-user-table">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-primary" style="width:100px;">PIN</th>
                <th scope="col" class="manage-column">Nama di mesin</th>
                <?php if ( $show_mesin_col ) : ?>
                    <th scope="col" class="manage-column" style="width:180px;">Mesin</th>
                <?php endif; ?>
                <th scope="col" class="manage-column" style="width:120px;">Kartu</th>
                <th scope="col" class="manage-column" style="width:120px;">Hak akses</th>
                <th scope="col" class="manage-column" style="width:120px;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $devices ) ) : ?>
                <tr class="no-items">
                    <td class="colspanchange" colspan="<?php echo (int) $colspan; ?>">
                        Belum ada mesin.
                        <a href="<?php echo esc_url( qrg_solution_admin_url( [ 'tab' => 'tambah' ] ) ); ?>">Tambah mesin</a> dulu.
                    </td>
                </tr>
            <?php elseif ( $total_users === 0 ) : ?>
                <tr class="no-items">
                    <td class="colspanchange" colspan="<?php echo (int) $colspan; ?>">
                        Belum ada data user.
                        <?php if ( $filter_device ) : ?>
                            Klik <strong>Ambil user dari mesin</strong>.
                        <?php else : ?>
                            Pilih mesin di filter, lalu ambil user dari mesin.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else : ?>
                <tr class="no-items" id="qrg-user-empty-search" hidden>
                    <td class="colspanchange" colspan="<?php echo (int) $colspan; ?>">Tidak ada user yang cocok dengan pencarian.</td>
                </tr>
                <?php foreach ( $rows as $row ) :
                    $pin      = (string) $row['pin'];
                    $device    = $row['device'];
                    $is_admin = qrg_solution_is_admin_login_pin( $pin, $device );
                    $finger_url = qrg_solution_admin_url( [
                        'tab'    => 'user',
                        'device' => $row['device_id'],
                        'finger' => $pin,
                    ] );
                    $devices_list = ! empty( $row['devices'] ) && is_array( $row['devices'] ) ? $row['devices'] : [
                        [ 'id' => $row['device_id'], 'nama' => $row['device_nama'], 'ip' => $row['device_ip'] ],
                    ];
                    $search_attr = $haystack_of( $row, '' );
                    ?>
                    <tr class="qrg-user-row" data-qrg-search="<?php echo esc_attr( $search_attr ); ?>">
                        <td class="column-primary"><strong><code><?php echo esc_html( $pin ); ?></code></strong></td>
                        <td>
                            <?php
                            $nama_tampil = $row['nama'] !== '' ? $row['nama'] : ( $is_admin ? 'Admin mesin' : '—' );
                            ?>
                            <strong>
                                <a href="<?php echo esc_url( $finger_url ); ?>" title="Lihat sidik jari PIN <?php echo esc_attr( $pin ); ?>">
                                    <?php echo esc_html( $nama_tampil ); ?>
                                </a>
                            </strong>
                            <?php if ( $is_admin ) : ?>
                                <span class="description"> — Admin login mesin</span>
                            <?php endif; ?>
                            <div class="row-actions">
                                <span class="id">PIN: <a href="<?php echo esc_url( $finger_url ); ?>"><code><?php echo esc_html( $pin ); ?></code></a></span>
                            </div>
                        </td>
                        <?php if ( $show_mesin_col ) : ?>
                            <td>
                                <?php
                                $links = [];
                                foreach ( $devices_list as $drow ) {
                                    $links[] = sprintf(
                                        '<a href="%s">%s</a>',
                                        esc_url( qrg_solution_admin_url( [ 'tab' => 'user', 'device' => $drow['id'] ] ) ),
                                        esc_html( $drow['nama'] )
                                    );
                                }
                                echo implode( ', ', $links );
                                ?>
                            </td>
                        <?php endif; ?>
                        <td><?php echo esc_html( $row['card'] !== '' ? $row['card'] : '—' ); ?></td>
                        <td><?php echo esc_html( $is_admin ? 'Super Admin' : qrg_solution_privilege_label( $row['privilege'] ) ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $finger_url ); ?>">Sidik jari</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ( $total_users > 0 ) : ?>
        <p class="description" id="qrg-user-count"><?php echo (int) $total_users; ?> user unik (PIN) ditampilkan.</p>
        <script>
        (function() {
            var input = document.getElementById('qrg-user-search');
            var rows = document.querySelectorAll('#qrg-user-table tr.qrg-user-row');
            var empty = document.getElementById('qrg-user-empty-search');
            var count = document.getElementById('qrg-user-count');
            var total = <?php echo (int) $total_users; ?>;
            if (!input || !rows.length) return;

            function apply() {
                var q = (input.value || '').toLowerCase().trim();
                var shown = 0;
                rows.forEach(function(tr) {
                    var hay = (tr.getAttribute('data-qrg-search') || '');
                    var ok = !q || hay.indexOf(q) !== -1;
                    tr.hidden = !ok;
                    if (ok) shown++;
                });
                if (empty) empty.hidden = shown !== 0;
                if (count) {
                    count.textContent = q
                        ? (shown + ' dari ' + total + ' user unik ditampilkan.')
                        : (shown + ' user unik (PIN) ditampilkan.');
                }
            }

            input.addEventListener('input', apply);
            input.addEventListener('search', apply);
            apply();
        })();
        </script>
    <?php endif; ?>
    <?php
}

/**
 * Tab Lihat Presensi — semua mesin digabung jadi satu data.
 */
function qrg_solution_render_presensi_tab() {
    $devices   = qrg_solution_get_devices();
    $device_id = isset( $_GET['device'] ) ? sanitize_key( wp_unslash( $_GET['device'] ) ) : '';
    if ( $device_id === '' && isset( $_GET['presensi'] ) ) {
        $device_id = sanitize_key( wp_unslash( $_GET['presensi'] ) );
    }

    $bulan = isset( $_GET['bulan'] ) ? absint( $_GET['bulan'] ) : (int) current_time( 'n' );
    $tahun = isset( $_GET['tahun'] ) ? absint( $_GET['tahun'] ) : (int) current_time( 'Y' );
    if ( $bulan < 1 || $bulan > 12 ) {
        $bulan = (int) current_time( 'n' );
    }
    if ( $tahun < 2000 || $tahun > 2100 ) {
        $tahun = (int) current_time( 'Y' );
    }
    $bulan_nama = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    $data  = qrg_solution_list_all_presensi_who( $bulan, $tahun, $device_id );
    $items = is_array( $data['items'] ) ? $data['items'] : [];

    $flash_key = 'qrg_solution_device_attlog_flash_' . get_current_user_id();
    $flash     = get_transient( $flash_key );
    if ( $flash ) {
        delete_transient( $flash_key );
    }

    $form_action = qrg_solution_admin_url( array_filter( [
        'tab'    => 'presensi',
        'device' => $device_id,
        'bulan'  => $bulan,
        'tahun'  => $tahun,
    ] ) );

    qrg_solution_render_admin_tabs( 'presensi' );
    ?>
    <h2 class="title">Lihat Presensi</h2>
    <p class="description">Semua log absensi dari mesin Solution digabung dalam satu daftar. Filter opsional per mesin / periode.</p>

    <?php if ( is_array( $flash ) && ! empty( $flash['error'] ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $flash['error'] ); ?></p></div>
    <?php elseif ( is_array( $flash ) && ! empty( $flash['warning'] ) ) : ?>
        <div class="notice notice-warning is-dismissible"><p><?php echo esc_html( $flash['message'] ?: 'Log diambil dengan peringatan.' ); ?></p></div>
    <?php elseif ( is_array( $flash ) && ! empty( $flash['ok'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $flash['message'] ?: 'Log absensi diperbarui.' ); ?></p></div>
    <?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px;">
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0;">
            <input type="hidden" name="page" value="qrg-presensi">
            <input type="hidden" name="perangkat" value="1">
            <input type="hidden" name="tab" value="presensi">
            <label for="qrg-presensi-device" class="screen-reader-text">Filter mesin</label>
            <select name="device" id="qrg-presensi-device">
                <option value="">Semua mesin</option>
                <?php foreach ( $devices as $d ) :
                    $label = $d['nama'] !== '' ? $d['nama'] : $d['id'];
                    if ( ! empty( $d['lokasi'] ) ) {
                        $label .= ' · ' . $d['lokasi'];
                    }
                    ?>
                    <option value="<?php echo esc_attr( $d['id'] ); ?>" <?php selected( $device_id, $d['id'] ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="bulan">
                <?php for ( $b = 1; $b <= 12; $b++ ) : ?>
                    <option value="<?php echo (int) $b; ?>" <?php selected( $bulan, $b ); ?>><?php echo esc_html( $bulan_nama[ $b ] ); ?></option>
                <?php endfor; ?>
            </select>
            <select name="tahun">
                <?php
                $y_now = (int) current_time( 'Y' );
                for ( $y = $y_now; $y >= $y_now - 5; $y-- ) :
                    ?>
                    <option value="<?php echo (int) $y; ?>" <?php selected( $tahun, $y ); ?>><?php echo (int) $y; ?></option>
                <?php endfor; ?>
            </select>
            <?php submit_button( 'Tampilkan', 'secondary', '', false ); ?>
        </form>
        <form method="post" action="<?php echo esc_url( $form_action ); ?>" style="margin:0;">
            <?php wp_nonce_field( 'qrg_solution_device_attlog', 'qrg_solution_device_attlog_nonce' ); ?>
            <input type="hidden" name="device_id" value="<?php echo esc_attr( $device_id !== '' ? $device_id : 'all' ); ?>">
            <input type="hidden" name="bulan" value="<?php echo (int) $bulan; ?>">
            <input type="hidden" name="tahun" value="<?php echo (int) $tahun; ?>">
            <input type="hidden" name="return_tab" value="presensi">
            <?php
            submit_button(
                $device_id !== '' ? 'Ambil dari mesin ini' : 'Ambil dari semua mesin',
                'primary',
                'qrg_solution_fetch_device_attlog',
                false
            );
            ?>
        </form>
    </div>

    <?php if ( empty( $devices ) ) : ?>
        <div class="notice notice-warning"><p>Belum ada mesin. <a href="<?php echo esc_url( qrg_solution_admin_url( [ 'tab' => 'tambah' ] ) ); ?>">Tambah mesin</a> terlebih dahulu.</p></div>
        <?php
        return;
    endif;
    ?>

    <p class="description" style="margin-top:0;">
        <strong><?php echo (int) ( $data['people'] ?? 0 ); ?></strong> orang ·
        <strong><?php echo (int) ( $data['punches'] ?? 0 ); ?></strong> punch ·
        <?php echo esc_html( ( $bulan_nama[ $bulan ] ?? '' ) . ' ' . $tahun ); ?> ·
        <?php echo (int) ( $data['devices'] ?? 0 ); ?> mesin
        <?php if ( ! empty( $data['updated'] ) ) : ?>
            · terakhir diambil: <?php echo esc_html( $data['updated'] ); ?>
        <?php endif; ?>
    </p>

    <table class="wp-list-table widefat fixed striped table-view-list" id="qrg-presensi-all-table">
        <thead>
            <tr>
                <th scope="col" style="width:140px;">Tanggal</th>
                <th scope="col" style="width:80px;">Jam</th>
                <th scope="col" style="width:80px;">PIN</th>
                <th scope="col">Nama di mesin</th>
                <th scope="col">Mesin</th>
                <th scope="col" style="width:110px;">Status</th>
                <th scope="col" style="width:90px;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr class="no-items">
                    <td class="colspanchange" colspan="7">
                        Belum ada data absensi untuk periode ini.
                        <?php if ( ! empty( $data['other_n'] ) ) : ?>
                            Ada <strong><?php echo (int) $data['other_n']; ?></strong> log di cache di luar periode
                            <?php if ( ! empty( $data['other_min'] ) ) : ?>
                                (<?php echo esc_html( substr( $data['other_min'], 0, 10 ) ); ?>
                                – <?php echo esc_html( substr( (string) $data['other_max'], 0, 10 ) ); ?>)
                            <?php endif; ?>.
                            Ubah bulan/tahun atau klik <strong>Ambil dari semua mesin</strong>.
                        <?php else : ?>
                            Klik <strong>Ambil dari semua mesin</strong> untuk memuat log.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $items as $row ) :
                    $tgl = $row['tanggal'] !== '' ? date_i18n( 'd M Y (D)', strtotime( $row['tanggal'] ) ) : '—';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $tgl ); ?></strong>
                            <div class="description"><code><?php echo esc_html( $row['tanggal'] ); ?></code></div>
                        </td>
                        <td><?php echo esc_html( $row['jam'] !== '' ? $row['jam'] : '—' ); ?></td>
                        <td><code><?php echo esc_html( $row['pin'] !== '' ? $row['pin'] : '—' ); ?></code></td>
                        <td>
                            <?php if ( ! empty( $row['finger_url'] ) ) : ?>
                                <a href="<?php echo esc_url( $row['finger_url'] ); ?>"><strong><?php echo esc_html( $row['nama'] ); ?></strong></a>
                            <?php else : ?>
                                <strong><?php echo esc_html( $row['nama'] ); ?></strong>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html( $row['mesin'] ?? '—' ); ?>
                            <?php if ( ! empty( $row['mesin_ip'] ) ) : ?>
                                <div class="description"><code><?php echo esc_html( $row['mesin_ip'] ); ?></code></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row['status'] ); ?></td>
                        <td>
                            <?php if ( ! empty( $row['finger_url'] ) ) : ?>
                                <a href="<?php echo esc_url( $row['finger_url'] ); ?>">Detail</a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Format durasi kerja dari jam masuk–pulang (H:i:s).
 */
function qrg_solution_format_work_duration( $jam_masuk, $jam_pulang ) {
    $jam_masuk  = trim( (string) $jam_masuk );
    $jam_pulang = trim( (string) $jam_pulang );
    if ( $jam_masuk === '' || $jam_pulang === '' || $jam_masuk === $jam_pulang ) {
        return '';
    }
    $a = strtotime( '1970-01-01 ' . $jam_masuk );
    $b = strtotime( '1970-01-01 ' . $jam_pulang );
    if ( ! $a || ! $b || $b <= $a ) {
        return '';
    }
    $diff = $b - $a;
    $h    = (int) floor( $diff / 3600 );
    $m    = (int) floor( ( $diff % 3600 ) / 60 );
    return $h . 'j ' . sprintf( '%02d', $m ) . 'm';
}

/**
 * Bangun rekap per user per hari (masuk & pulang) dari log gabungan mesin.
 *
 * @return array{users:array<int,array<string,mixed>>,stats:array<string,int>,updated:string}
 */
function qrg_solution_build_rekap_harian( $bulan, $tahun, $device_id = '' ) {
    $raw   = qrg_solution_list_all_presensi_who( $bulan, $tahun, $device_id );
    $items = is_array( $raw['items'] ) ? $raw['items'] : [];

    // pin|tanggal => day row
    $days = [];
    foreach ( $items as $row ) {
        $pin = (string) ( $row['pin'] ?? '' );
        $tgl = (string) ( $row['tanggal'] ?? '' );
        $jam = (string) ( $row['jam'] ?? '' );
        if ( $pin === '' || $tgl === '' || $jam === '' ) {
            continue;
        }
        $key = $pin . '|' . $tgl;
        if ( ! isset( $days[ $key ] ) ) {
            $days[ $key ] = [
                'pin'        => $pin,
                'nama'       => (string) ( $row['nama'] ?? '' ),
                'tanggal'    => $tgl,
                'jam_masuk'  => $jam,
                'jam_pulang' => '',
                'mesin'      => [],
                'punch_n'    => 0,
                'finger_url' => (string) ( $row['finger_url'] ?? '' ),
            ];
        }
        $d = &$days[ $key ];
        $d['punch_n']++;
        if ( $d['nama'] === '' || strpos( $d['nama'], 'PIN ' ) === 0 ) {
            if ( ! empty( $row['nama'] ) && strpos( (string) $row['nama'], 'PIN ' ) !== 0 ) {
                $d['nama'] = (string) $row['nama'];
            }
        }
        if ( ! empty( $row['mesin'] ) ) {
            $d['mesin'][ (string) $row['mesin'] ] = true;
        }
        if ( ! empty( $row['finger_url'] ) && $d['finger_url'] === '' ) {
            $d['finger_url'] = (string) $row['finger_url'];
        }
        if ( strcmp( $jam, $d['jam_masuk'] ) < 0 ) {
            // Punch lebih awal: naikkan masuk lama jadi kandidat pulang.
            if ( $d['jam_pulang'] === '' || strcmp( $d['jam_masuk'], $d['jam_pulang'] ) > 0 ) {
                $d['jam_pulang'] = $d['jam_masuk'];
            }
            $d['jam_masuk'] = $jam;
        } elseif ( strcmp( $jam, $d['jam_masuk'] ) > 0 ) {
            if ( $d['jam_pulang'] === '' || strcmp( $jam, $d['jam_pulang'] ) > 0 ) {
                $d['jam_pulang'] = $jam;
            }
        }
        unset( $d );
    }

    // Group by PIN.
    $by_pin = [];
    foreach ( $days as $day ) {
        $pin = $day['pin'];
        if ( ! isset( $by_pin[ $pin ] ) ) {
            $by_pin[ $pin ] = [
                'pin'        => $pin,
                'nama'       => $day['nama'] !== '' ? $day['nama'] : ( 'PIN ' . $pin ),
                'finger_url' => $day['finger_url'],
                'days'       => [],
                'hari_n'     => 0,
                'lengkap_n'  => 0,
                'belum_n'    => 0,
            ];
        }
        $u = &$by_pin[ $pin ];
        if ( ( $u['nama'] === '' || strpos( $u['nama'], 'PIN ' ) === 0 ) && $day['nama'] !== '' && strpos( $day['nama'], 'PIN ' ) !== 0 ) {
            $u['nama'] = $day['nama'];
        }
        if ( $day['finger_url'] !== '' && $u['finger_url'] === '' ) {
            $u['finger_url'] = $day['finger_url'];
        }
        $lengkap = ( $day['jam_masuk'] !== '' && $day['jam_pulang'] !== '' );
        $day['lengkap']  = $lengkap;
        $day['durasi']   = qrg_solution_format_work_duration( $day['jam_masuk'], $day['jam_pulang'] );
        $day['mesin']    = implode( ', ', array_keys( $day['mesin'] ) );
        $day['status']   = $lengkap ? 'lengkap' : 'belum';
        $u['days'][]     = $day;
        $u['hari_n']++;
        if ( $lengkap ) {
            $u['lengkap_n']++;
        } else {
            $u['belum_n']++;
        }
        unset( $u );
    }

    foreach ( $by_pin as &$u ) {
        usort( $u['days'], static function ( $a, $b ) {
            return strcmp( (string) $b['tanggal'], (string) $a['tanggal'] );
        } );
    }
    unset( $u );

    $users = array_values( $by_pin );
    usort( $users, static function ( $a, $b ) {
        return strcasecmp( (string) $a['nama'], (string) $b['nama'] );
    } );

    // User terdaftar di mesin (abaikan PIN admin login).
    $registered = [];
    foreach ( qrg_solution_collect_users( $device_id ) as $urow ) {
        $upin = (string) ( $urow['pin'] ?? '' );
        if ( $upin === '' ) {
            continue;
        }
        $dev = ! empty( $urow['device'] ) ? $urow['device'] : ( $device_id !== '' ? qrg_solution_get_device( $device_id ) : null );
        if ( qrg_solution_is_admin_login_pin( $upin, $dev ) ) {
            continue;
        }
        $registered[ $upin ] = (string) ( $urow['nama'] ?? '' );
    }

    $masuk_n  = 0;
    $pulang_n = 0;
    $today    = current_time( 'Y-m-d' );
    $masuk_today_pins  = [];
    $pulang_today_pins = [];
    foreach ( $days as $day ) {
        if ( ! empty( $day['jam_masuk'] ) ) {
            $masuk_n++;
        }
        if ( ! empty( $day['jam_pulang'] ) ) {
            $pulang_n++;
        }
        if ( ( $day['tanggal'] ?? '' ) === $today ) {
            if ( ! empty( $day['jam_masuk'] ) ) {
                $masuk_today_pins[ $day['pin'] ] = true;
            }
            if ( ! empty( $day['jam_pulang'] ) ) {
                $pulang_today_pins[ $day['pin'] ] = true;
            }
        }
    }

    $hadir_pins = array_fill_keys( array_keys( $by_pin ), true );
    $tidak_hadir = 0;
    foreach ( $registered as $upin => $_nama ) {
        if ( ! isset( $hadir_pins[ $upin ] ) ) {
            $tidak_hadir++;
        }
    }

    $user_mesin   = count( $registered );
    $hadir_n      = count( $users );
    $hadir_today  = count( $masuk_today_pins );
    $pulang_today = count( $pulang_today_pins );
    $tidak_today  = max( 0, $user_mesin - $hadir_today );

    $stats = [
        'orang'           => $hadir_n,
        'hari'            => count( $days ),
        'lengkap'         => 0,
        'belum'           => 0,
        'punches'         => (int) ( $raw['punches'] ?? 0 ),
        'masuk'           => $masuk_n,
        'pulang'          => $pulang_n,
        'hadir'           => $hadir_n,
        'tidak_hadir'     => $tidak_hadir,
        'user_mesin'      => $user_mesin,
        'masuk_today'     => $hadir_today,
        'pulang_today'    => $pulang_today,
        'hadir_today'     => $hadir_today,
        'tidak_today'     => $tidak_today,
        'today'           => $today,
        'today_in_period' => ( strpos( $today, sprintf( '%04d-%02d', (int) $tahun, (int) $bulan ) ) === 0 ),
    ];
    foreach ( $users as $u ) {
        $stats['lengkap'] += (int) $u['lengkap_n'];
        $stats['belum']   += (int) $u['belum_n'];
    }

    return [
        'users'   => $users,
        'stats'   => $stats,
        'updated' => (string) ( $raw['updated'] ?? '' ),
    ];
}

/**
 * Tab Rekap Harian — per user, per hari, masuk & pulang.
 */
function qrg_solution_render_rekap_tab() {
    $devices   = qrg_solution_get_devices();
    $device_id = isset( $_GET['device'] ) ? sanitize_key( wp_unslash( $_GET['device'] ) ) : '';
    $bulan     = isset( $_GET['bulan'] ) ? absint( $_GET['bulan'] ) : (int) current_time( 'n' );
    $tahun     = isset( $_GET['tahun'] ) ? absint( $_GET['tahun'] ) : (int) current_time( 'Y' );
    $search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    if ( $bulan < 1 || $bulan > 12 ) {
        $bulan = (int) current_time( 'n' );
    }
    if ( $tahun < 2000 || $tahun > 2100 ) {
        $tahun = (int) current_time( 'Y' );
    }

    $days_in_month = (int) date( 't', strtotime( sprintf( '%04d-%02d-01', $tahun, $bulan ) ) );
    $hari_param    = isset( $_GET['hari'] ) ? sanitize_text_field( wp_unslash( $_GET['hari'] ) ) : '';
    $tanggal_pilih = '';
    if ( $hari_param !== '' && $hari_param !== 'all' ) {
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $hari_param ) ) {
            $ts = strtotime( $hari_param );
            if ( $ts && (int) date( 'n', $ts ) === $bulan && (int) date( 'Y', $ts ) === $tahun ) {
                $tanggal_pilih = $hari_param;
            }
        } else {
            $dnum = absint( $hari_param );
            if ( $dnum >= 1 && $dnum <= $days_in_month ) {
                $tanggal_pilih = sprintf( '%04d-%02d-%02d', $tahun, $bulan, $dnum );
            }
        }
    } elseif ( ! isset( $_GET['hari'] ) ) {
        // Default: hari ini bila bulan yang dipilih adalah bulan berjalan.
        $today = current_time( 'Y-m-d' );
        if ( strpos( $today, sprintf( '%04d-%02d', $tahun, $bulan ) ) === 0 ) {
            $tanggal_pilih = $today;
        }
    }

    $bulan_nama = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    $rekap  = qrg_solution_build_rekap_harian( $bulan, $tahun, $device_id );
    $users  = is_array( $rekap['users'] ) ? $rekap['users'] : [];
    $stats  = is_array( $rekap['stats'] ) ? $rekap['stats'] : [];

    $day_rows  = [];
    $day_stats = null;
    if ( $tanggal_pilih !== '' ) {
        $masuk_pins  = [];
        $pulang_pins = [];
        foreach ( $users as $u ) {
            foreach ( (array) ( $u['days'] ?? [] ) as $day ) {
                if ( ( $day['tanggal'] ?? '' ) !== $tanggal_pilih ) {
                    continue;
                }
                $pin = (string) ( $u['pin'] ?? '' );
                $day_rows[] = [
                    'pin'        => $pin,
                    'nama'       => (string) ( $u['nama'] ?? '' ),
                    'finger_url' => (string) ( $u['finger_url'] ?? '' ),
                    'jam_masuk'  => (string) ( $day['jam_masuk'] ?? '' ),
                    'jam_pulang' => (string) ( $day['jam_pulang'] ?? '' ),
                    'durasi'     => (string) ( $day['durasi'] ?? '' ),
                    'lengkap'    => ! empty( $day['lengkap'] ),
                    'mesin'      => (string) ( $day['mesin'] ?? '' ),
                ];
                if ( ! empty( $day['jam_masuk'] ) ) {
                    $masuk_pins[ $pin ] = true;
                }
                if ( ! empty( $day['jam_pulang'] ) ) {
                    $pulang_pins[ $pin ] = true;
                }
            }
        }
        usort( $day_rows, static function ( $a, $b ) {
            $cmp = strcmp( (string) ( $a['jam_masuk'] ?? '' ), (string) ( $b['jam_masuk'] ?? '' ) );
            return 0 !== $cmp ? $cmp : strcasecmp( (string) ( $a['nama'] ?? '' ), (string) ( $b['nama'] ?? '' ) );
        } );
        $user_mesin = (int) ( $stats['user_mesin'] ?? 0 );
        $hadir_n    = count( $masuk_pins );
        $day_stats  = [
            'masuk'       => $hadir_n,
            'pulang'      => count( $pulang_pins ),
            'hadir'       => $hadir_n,
            'tidak_hadir' => max( 0, $user_mesin - $hadir_n ),
            'lengkap'     => count( array_filter( $day_rows, static function ( $r ) {
                return ! empty( $r['lengkap'] );
            } ) ),
            'belum'       => count( array_filter( $day_rows, static function ( $r ) {
                return empty( $r['lengkap'] );
            } ) ),
            'user_mesin'  => $user_mesin,
        ];
    }

    if ( $search !== '' ) {
        $q = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
        if ( $tanggal_pilih !== '' ) {
            $day_rows = array_values( array_filter( $day_rows, static function ( $r ) use ( $q ) {
                $hay = ( $r['nama'] ?? '' ) . ' ' . ( $r['pin'] ?? '' );
                $hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $hay ) : strtolower( $hay );
                return strpos( $hay, $q ) !== false;
            } ) );
        } else {
            $users = array_values( array_filter( $users, static function ( $u ) use ( $q ) {
                $hay = ( $u['nama'] ?? '' ) . ' ' . ( $u['pin'] ?? '' );
                $hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $hay ) : strtolower( $hay );
                return strpos( $hay, $q ) !== false;
            } ) );
        }
    }

    $flash_key = 'qrg_solution_device_attlog_flash_' . get_current_user_id();
    $flash     = get_transient( $flash_key );
    if ( $flash ) {
        delete_transient( $flash_key );
    }

    $form_action = qrg_solution_admin_url( array_filter( [
        'tab'    => 'rekap',
        'device' => $device_id,
        'bulan'  => $bulan,
        'tahun'  => $tahun,
        'hari'   => $tanggal_pilih !== '' ? (string) (int) date( 'j', strtotime( $tanggal_pilih ) ) : 'all',
        's'      => $search,
    ] ) );

    qrg_solution_render_admin_tabs( 'rekap' );
    ?>
    <style>
        .qrg-rekap { max-width: 1100px; }
        .qrg-rekap__intro { margin: 0 0 14px; color: #50575e; }
        .qrg-rekap__toolbar {
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
            margin: 0 0 16px;
        }
        .qrg-rekap__stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin: 0 0 12px;
        }
        .qrg-rekap__stats-block { margin: 0 0 18px; }
        .qrg-rekap__stats-title {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
        }
        .qrg-rekap__stats-title span { font-weight: 400; color: #646970; }
        @media (max-width: 782px) {
            .qrg-rekap__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .qrg-rekap-stat {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 10px;
            padding: 14px 16px;
            border-left: 4px solid var(--rekap-accent, #2271b1);
        }
        .qrg-rekap-stat__label {
            display: block; font-size: 12px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .03em; color: #646970;
        }
        .qrg-rekap-stat__value {
            display: block; font-size: 26px; line-height: 1.2; margin-top: 4px;
            color: #1d2327; font-weight: 600;
        }
        .qrg-rekap-stat__hint { display: block; margin-top: 2px; font-size: 12px; color: #646970; }
        .qrg-rekap-list { display: flex; flex-direction: column; gap: 10px; }
        .qrg-rekap-card {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 12px;
            overflow: hidden;
        }
        .qrg-rekap-card[open] { box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .qrg-rekap-card > summary {
            list-style: none;
            cursor: pointer;
            display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px;
            padding: 14px 16px;
            background: #f6f7f7;
        }
        .qrg-rekap-card > summary::-webkit-details-marker { display: none; }
        .qrg-rekap-card > summary::after {
            content: "▾";
            margin-left: auto;
            color: #646970;
            font-size: 14px;
        }
        .qrg-rekap-card[open] > summary::after { content: "▴"; }
        .qrg-rekap-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: #2271b1; color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 13px; flex: 0 0 auto;
        }
        .qrg-rekap-who { min-width: 0; flex: 1 1 180px; }
        .qrg-rekap-who strong { display: block; font-size: 15px; color: #1d2327; }
        .qrg-rekap-who span { display: block; font-size: 12px; color: #646970; margin-top: 2px; }
        .qrg-rekap-meta {
            display: flex; flex-wrap: wrap; gap: 6px;
            flex: 1 1 220px;
        }
        .qrg-rekap-pill {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 600;
            background: #f0f0f1; color: #3c434a;
        }
        .qrg-rekap-pill--ok { background: #edfaef; color: #007017; }
        .qrg-rekap-pill--warn { background: #fcf9e8; color: #996800; }
        .qrg-rekap-body { padding: 0 12px 12px; }
        .qrg-rekap-daytable {
            width: 100%; border-collapse: collapse; margin: 0;
            background: #fff;
        }
        .qrg-rekap-daytable th,
        .qrg-rekap-daytable td {
            padding: 10px 12px; text-align: left;
            border-top: 1px solid #f0f0f1;
            vertical-align: middle;
        }
        .qrg-rekap-daytable th {
            font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
            color: #646970; background: #fcfcfc;
        }
        .qrg-rekap-time {
            display: inline-flex; align-items: center; gap: 6px;
            font-weight: 600; font-variant-numeric: tabular-nums;
        }
        .qrg-rekap-dot {
            width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto;
        }
        .qrg-rekap-dot--in { background: #00a32a; }
        .qrg-rekap-dot--out { background: #2271b1; }
        .qrg-rekap-dot--miss { background: #d63638; }
        .qrg-rekap-badge {
            display: inline-block; padding: 3px 8px; border-radius: 6px;
            font-size: 11px; font-weight: 700;
        }
        .qrg-rekap-badge--ok { background: #edfaef; color: #007017; }
        .qrg-rekap-badge--warn { background: #fcf0f1; color: #b32d2e; }
        .qrg-rekap-empty {
            padding: 36px 20px; text-align: center;
            background: #fff; border: 1px dashed #c3c4c7; border-radius: 12px;
            color: #646970;
        }
        .qrg-rekap-empty strong { display: block; color: #1d2327; margin-bottom: 6px; font-size: 15px; }
        #qrg-rekap-empty-search { display: none; }
        #qrg-rekap-empty-search.is-visible { display: block; }
        .qrg-rekap-daychips {
            display: flex; flex-wrap: wrap; gap: 6px;
            margin: 0 0 16px;
        }
        .qrg-rekap-daychips a {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 36px; height: 32px; padding: 0 8px;
            border-radius: 8px; text-decoration: none;
            border: 1px solid #dcdcde; background: #fff; color: #1d2327;
            font-size: 13px; font-weight: 600;
        }
        .qrg-rekap-daychips a:hover { border-color: #2271b1; color: #2271b1; }
        .qrg-rekap-daychips a.is-active {
            background: #2271b1; border-color: #2271b1; color: #fff;
        }
        .qrg-rekap-daychips a.is-today:not(.is-active) {
            border-color: #00a32a; color: #007017;
        }
        .qrg-rekap-daychips a.has-data:not(.is-active) {
            background: #edfaef; border-color: #68de7c;
        }
    </style>

    <div class="qrg-rekap">
        <h2 class="title">Rekap Harian</h2>
        <p class="qrg-rekap__intro">
            Pilih <strong>hari</strong> untuk melihat jumlah hadir / masuk / pulang pada tanggal itu.
            Atau pilih <strong>Semua hari</strong> untuk rekap per orang sebulan.
        </p>

        <?php if ( is_array( $flash ) && ! empty( $flash['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $flash['error'] ); ?></p></div>
        <?php elseif ( is_array( $flash ) && ! empty( $flash['warning'] ) ) : ?>
            <div class="notice notice-warning is-dismissible"><p><?php echo esc_html( $flash['message'] ?: 'Log diambil dengan peringatan.' ); ?></p></div>
        <?php elseif ( is_array( $flash ) && ! empty( $flash['ok'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $flash['message'] ?: 'Log absensi diperbarui.' ); ?></p></div>
        <?php endif; ?>

        <div class="qrg-rekap__toolbar">
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0;">
                <input type="hidden" name="page" value="qrg-presensi">
                <input type="hidden" name="perangkat" value="1">
                <input type="hidden" name="tab" value="rekap">
                <select name="device">
                    <option value="">Semua mesin</option>
                    <?php foreach ( $devices as $d ) :
                        $label = $d['nama'] !== '' ? $d['nama'] : $d['id'];
                        if ( ! empty( $d['lokasi'] ) ) {
                            $label .= ' · ' . $d['lokasi'];
                        }
                        ?>
                        <option value="<?php echo esc_attr( $d['id'] ); ?>" <?php selected( $device_id, $d['id'] ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="bulan">
                    <?php for ( $b = 1; $b <= 12; $b++ ) : ?>
                        <option value="<?php echo (int) $b; ?>" <?php selected( $bulan, $b ); ?>><?php echo esc_html( $bulan_nama[ $b ] ); ?></option>
                    <?php endfor; ?>
                </select>
                <select name="tahun">
                    <?php
                    $y_now = (int) current_time( 'Y' );
                    for ( $y = $y_now; $y >= $y_now - 5; $y-- ) :
                        ?>
                        <option value="<?php echo (int) $y; ?>" <?php selected( $tahun, $y ); ?>><?php echo (int) $y; ?></option>
                    <?php endfor; ?>
                </select>
                <label for="qrg-rekap-hari" class="screen-reader-text">Pilih hari</label>
                <select name="hari" id="qrg-rekap-hari">
                    <option value="all" <?php selected( $tanggal_pilih === '' ); ?>>Semua hari</option>
                    <?php
                    $today_j  = (int) current_time( 'j' );
                    $today_ym = current_time( 'Y-m' );
                    $cur_ym   = sprintf( '%04d-%02d', $tahun, $bulan );
                    for ( $d = 1; $d <= $days_in_month; $d++ ) :
                        $tgl_opt = sprintf( '%04d-%02d-%02d', $tahun, $bulan, $d );
                        $label   = $d . ' · ' . date_i18n( 'D', strtotime( $tgl_opt ) );
                        if ( $cur_ym === $today_ym && $d === $today_j ) {
                            $label .= ' (hari ini)';
                        }
                        ?>
                        <option value="<?php echo (int) $d; ?>" <?php selected( $tanggal_pilih, $tgl_opt ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endfor; ?>
                </select>
                <input type="search" name="s" id="qrg-rekap-search" value="<?php echo esc_attr( $search ); ?>" placeholder="Cari nama / PIN…" class="regular-text" style="max-width:200px;" autocomplete="off">
                <?php submit_button( 'Tampilkan', 'secondary', '', false ); ?>
            </form>
            <form method="post" action="<?php echo esc_url( $form_action ); ?>" style="margin:0;">
                <?php wp_nonce_field( 'qrg_solution_device_attlog', 'qrg_solution_device_attlog_nonce' ); ?>
                <input type="hidden" name="device_id" value="<?php echo esc_attr( $device_id !== '' ? $device_id : 'all' ); ?>">
                <input type="hidden" name="bulan" value="<?php echo (int) $bulan; ?>">
                <input type="hidden" name="tahun" value="<?php echo (int) $tahun; ?>">
                <input type="hidden" name="return_tab" value="rekap">
                <input type="hidden" name="hari" value="<?php echo esc_attr( $tanggal_pilih !== '' ? (string) (int) date( 'j', strtotime( $tanggal_pilih ) ) : 'all' ); ?>">
                <?php
                submit_button(
                    $device_id !== '' ? 'Ambil dari mesin ini' : 'Ambil dari semua mesin',
                    'primary',
                    'qrg_solution_fetch_device_attlog',
                    false
                );
                ?>
            </form>
        </div>

        <?php
        $days_with_data = [];
        foreach ( (array) ( $rekap['users'] ?? [] ) as $u ) {
            foreach ( (array) ( $u['days'] ?? [] ) as $day ) {
                if ( ! empty( $day['tanggal'] ) ) {
                    $days_with_data[ $day['tanggal'] ] = true;
                }
            }
        }
        $chip_base = [
            'tab'    => 'rekap',
            'device' => $device_id,
            'bulan'  => $bulan,
            'tahun'  => $tahun,
            's'      => $search,
        ];
        ?>
        <div class="qrg-rekap-daychips" aria-label="Pilih tanggal cepat">
            <a href="<?php echo esc_url( qrg_solution_admin_url( array_merge( $chip_base, [ 'hari' => 'all' ] ) ) ); ?>" class="<?php echo $tanggal_pilih === '' ? 'is-active' : ''; ?>">Semua</a>
            <?php for ( $d = 1; $d <= $days_in_month; $d++ ) :
                $tgl_opt = sprintf( '%04d-%02d-%02d', $tahun, $bulan, $d );
                $url     = qrg_solution_admin_url( array_merge( $chip_base, [ 'hari' => (string) $d ] ) );
                $cls     = [];
                if ( $tanggal_pilih === $tgl_opt ) {
                    $cls[] = 'is-active';
                }
                if ( ! empty( $days_with_data[ $tgl_opt ] ) ) {
                    $cls[] = 'has-data';
                }
                if ( $tgl_opt === current_time( 'Y-m-d' ) ) {
                    $cls[] = 'is-today';
                }
                ?>
                <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( implode( ' ', $cls ) ); ?>" title="<?php echo esc_attr( date_i18n( 'l, j F Y', strtotime( $tgl_opt ) ) ); ?>"><?php echo (int) $d; ?></a>
            <?php endfor; ?>
        </div>

        <?php if ( empty( $devices ) ) : ?>
            <div class="qrg-rekap-empty">
                <strong>Belum ada mesin</strong>
                <a href="<?php echo esc_url( qrg_solution_admin_url( [ 'tab' => 'tambah' ] ) ); ?>">Tambah mesin</a> dulu, lalu ambil log absensi.
            </div>
            <?php
            echo '</div>';
            return;
        endif;

        if ( $tanggal_pilih !== '' && is_array( $day_stats ) ) :
            $tgl_label = date_i18n( 'l, j F Y', strtotime( $tanggal_pilih ) );
            ?>
            <div class="qrg-rekap__stats-block">
                <h3 class="qrg-rekap__stats-title">
                    Kehadiran tanggal
                    <span>· <?php echo esc_html( $tgl_label ); ?></span>
                </h3>
                <div class="qrg-rekap__stats">
                    <div class="qrg-rekap-stat" style="--rekap-accent:#00a32a;">
                        <span class="qrg-rekap-stat__label">Masuk</span>
                        <span class="qrg-rekap-stat__value"><?php echo (int) $day_stats['masuk']; ?></span>
                        <span class="qrg-rekap-stat__hint">orang absen masuk</span>
                    </div>
                    <div class="qrg-rekap-stat" style="--rekap-accent:#2271b1;">
                        <span class="qrg-rekap-stat__label">Pulang</span>
                        <span class="qrg-rekap-stat__value"><?php echo (int) $day_stats['pulang']; ?></span>
                        <span class="qrg-rekap-stat__hint">orang absen pulang</span>
                    </div>
                    <div class="qrg-rekap-stat" style="--rekap-accent:#135e96;">
                        <span class="qrg-rekap-stat__label">Hadir</span>
                        <span class="qrg-rekap-stat__value"><?php echo (int) $day_stats['hadir']; ?></span>
                        <span class="qrg-rekap-stat__hint">total orang hadir</span>
                    </div>
                    <div class="qrg-rekap-stat" style="--rekap-accent:#d63638;">
                        <span class="qrg-rekap-stat__label">Tidak hadir</span>
                        <span class="qrg-rekap-stat__value"><?php echo (int) $day_stats['tidak_hadir']; ?></span>
                        <span class="qrg-rekap-stat__hint">dari <?php echo (int) $day_stats['user_mesin']; ?> user mesin</span>
                    </div>
                </div>
                <p class="description" style="margin:0 0 12px;">
                    Lengkap: <strong><?php echo (int) $day_stats['lengkap']; ?></strong>
                    · Belum pulang: <strong><?php echo (int) $day_stats['belum']; ?></strong>
                </p>

                <?php if ( empty( $day_rows ) ) : ?>
                    <div class="qrg-rekap-empty">
                        <strong>Tidak ada yang hadir</strong>
                        Belum ada log absensi pada <?php echo esc_html( $tgl_label ); ?>.
                        Coba ambil ulang dari mesin atau pilih tanggal lain.
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped table-view-list">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th>Nama</th>
                                <th style="width:90px;">PIN</th>
                                <th style="width:100px;">Masuk</th>
                                <th style="width:100px;">Pulang</th>
                                <th style="width:90px;">Durasi</th>
                                <th style="width:110px;">Status</th>
                                <th>Mesin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $day_rows as $i => $row ) : ?>
                                <tr>
                                    <td><?php echo (int) ( $i + 1 ); ?></td>
                                    <td>
                                        <strong>
                                            <?php if ( ! empty( $row['finger_url'] ) ) : ?>
                                                <a href="<?php echo esc_url( $row['finger_url'] ); ?>"><?php echo esc_html( $row['nama'] ); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html( $row['nama'] ); ?>
                                            <?php endif; ?>
                                        </strong>
                                    </td>
                                    <td><code><?php echo esc_html( $row['pin'] ); ?></code></td>
                                    <td>
                                        <span class="qrg-rekap-time">
                                            <span class="qrg-rekap-dot qrg-rekap-dot--in" aria-hidden="true"></span>
                                            <?php echo esc_html( $row['jam_masuk'] !== '' ? substr( $row['jam_masuk'], 0, 5 ) : '—' ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ( $row['jam_pulang'] !== '' ) : ?>
                                            <span class="qrg-rekap-time">
                                                <span class="qrg-rekap-dot qrg-rekap-dot--out" aria-hidden="true"></span>
                                                <?php echo esc_html( substr( $row['jam_pulang'], 0, 5 ) ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="qrg-rekap-time" style="color:#b32d2e;">
                                                <span class="qrg-rekap-dot qrg-rekap-dot--miss" aria-hidden="true"></span>
                                                Belum
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $row['durasi'] !== '' ? $row['durasi'] : '—' ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $row['lengkap'] ) ) : ?>
                                            <span class="qrg-rekap-badge qrg-rekap-badge--ok">Lengkap</span>
                                        <?php else : ?>
                                            <span class="qrg-rekap-badge qrg-rekap-badge--warn">Belum pulang</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="description"><?php echo esc_html( $row['mesin'] !== '' ? $row['mesin'] : '—' ); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description" style="margin-top:10px;">
                        Menampilkan <strong><?php echo (int) count( $day_rows ); ?></strong> orang hadir pada <?php echo esc_html( $tgl_label ); ?>.
                    </p>
                <?php endif; ?>
            </div>
            </div>
            <?php
            return;
        endif;
        ?>

        <div class="qrg-rekap__stats-block">
            <h3 class="qrg-rekap__stats-title">
                Periode <?php echo esc_html( ( $bulan_nama[ $bulan ] ?? '' ) . ' ' . $tahun ); ?>
                <span>· rekap masuk / pulang / kehadiran (semua hari)</span>
            </h3>
            <div class="qrg-rekap__stats">
                <div class="qrg-rekap-stat" style="--rekap-accent:#00a32a;">
                    <span class="qrg-rekap-stat__label">Masuk</span>
                    <span class="qrg-rekap-stat__value"><?php echo (int) ( $stats['masuk'] ?? 0 ); ?></span>
                    <span class="qrg-rekap-stat__hint">hari dengan jam masuk</span>
                </div>
                <div class="qrg-rekap-stat" style="--rekap-accent:#2271b1;">
                    <span class="qrg-rekap-stat__label">Pulang</span>
                    <span class="qrg-rekap-stat__value"><?php echo (int) ( $stats['pulang'] ?? 0 ); ?></span>
                    <span class="qrg-rekap-stat__hint">hari dengan jam pulang</span>
                </div>
                <div class="qrg-rekap-stat" style="--rekap-accent:#135e96;">
                    <span class="qrg-rekap-stat__label">Hadir</span>
                    <span class="qrg-rekap-stat__value"><?php echo (int) ( $stats['hadir'] ?? 0 ); ?></span>
                    <span class="qrg-rekap-stat__hint">orang pernah absen bulan ini</span>
                </div>
                <div class="qrg-rekap-stat" style="--rekap-accent:#d63638;">
                    <span class="qrg-rekap-stat__label">Tidak hadir</span>
                    <span class="qrg-rekap-stat__value"><?php echo (int) ( $stats['tidak_hadir'] ?? 0 ); ?></span>
                    <span class="qrg-rekap-stat__hint">belum absen sama sekali / dari <?php echo (int) ( $stats['user_mesin'] ?? 0 ); ?> user</span>
                </div>
            </div>
            <p class="description" style="margin:0;">
                Lengkap (masuk+pulang): <strong><?php echo (int) ( $stats['lengkap'] ?? 0 ); ?></strong>
                · Belum pulang: <strong><?php echo (int) ( $stats['belum'] ?? 0 ); ?></strong>
                · Total punch: <strong><?php echo (int) ( $stats['punches'] ?? 0 ); ?></strong>
            </p>
        </div>

        <?php if ( empty( $rekap['users'] ) ) : ?>
            <div class="qrg-rekap-empty">
                <strong>Belum ada data rekap</strong>
                Ambil log dari mesin di tab ini, atau buka
                <a href="<?php echo esc_url( qrg_solution_admin_url( [ 'tab' => 'presensi', 'bulan' => $bulan, 'tahun' => $tahun ] ) ); ?>">Lihat Presensi</a>
                dulu.
                <?php if ( ! empty( $rekap['updated'] ) ) : ?>
                    <div style="margin-top:8px;">Cache terakhir: <?php echo esc_html( $rekap['updated'] ); ?></div>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div class="qrg-rekap-empty" id="qrg-rekap-empty-search">
                <strong>Tidak ada yang cocok</strong>
                Ubah kata pencarian nama / PIN.
            </div>
            <div class="qrg-rekap-list" id="qrg-rekap-list">
                <?php foreach ( $users as $idx => $user ) :
                    $nama = (string) $user['nama'];
                    $pin  = (string) $user['pin'];
                    $ini  = preg_replace( '/[^A-Za-z0-9]/', '', $nama );
                    $avatar = strtoupper( substr( $ini !== '' ? $ini : $pin, 0, 2 ) );
                    $hay = strtolower( $nama . ' ' . $pin );
                    $open_first = ( $idx === 0 && count( $users ) <= 8 );
                    ?>
                    <details class="qrg-rekap-card" data-qrg-search="<?php echo esc_attr( $hay ); ?>" <?php echo $open_first ? 'open' : ''; ?>>
                        <summary>
                            <span class="qrg-rekap-avatar" aria-hidden="true"><?php echo esc_html( $avatar ); ?></span>
                            <span class="qrg-rekap-who">
                                <strong>
                                    <?php if ( ! empty( $user['finger_url'] ) ) : ?>
                                        <a href="<?php echo esc_url( $user['finger_url'] ); ?>"><?php echo esc_html( $nama ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $nama ); ?>
                                    <?php endif; ?>
                                </strong>
                                <span>PIN <code><?php echo esc_html( $pin ); ?></code></span>
                            </span>
                            <span class="qrg-rekap-meta">
                                <span class="qrg-rekap-pill"><?php echo (int) $user['hari_n']; ?> hari</span>
                                <span class="qrg-rekap-pill qrg-rekap-pill--ok"><?php echo (int) $user['lengkap_n']; ?> lengkap</span>
                                <?php if ( (int) $user['belum_n'] > 0 ) : ?>
                                    <span class="qrg-rekap-pill qrg-rekap-pill--warn"><?php echo (int) $user['belum_n']; ?> belum pulang</span>
                                <?php endif; ?>
                            </span>
                        </summary>
                        <div class="qrg-rekap-body">
                            <table class="qrg-rekap-daytable">
                                <thead>
                                    <tr>
                                        <th>Hari</th>
                                        <th>Masuk</th>
                                        <th>Pulang</th>
                                        <th>Durasi</th>
                                        <th>Status</th>
                                        <th>Mesin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $user['days'] as $day ) :
                                        $tgl_label = $day['tanggal'] !== ''
                                            ? date_i18n( 'D, j M Y', strtotime( $day['tanggal'] ) )
                                            : '—';
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html( $tgl_label ); ?></strong>
                                                <div class="description"><code><?php echo esc_html( $day['tanggal'] ); ?></code></div>
                                            </td>
                                            <td>
                                                <span class="qrg-rekap-time">
                                                    <span class="qrg-rekap-dot qrg-rekap-dot--in" aria-hidden="true"></span>
                                                    <?php echo esc_html( $day['jam_masuk'] !== '' ? substr( $day['jam_masuk'], 0, 5 ) : '—' ); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ( $day['jam_pulang'] !== '' ) : ?>
                                                    <span class="qrg-rekap-time">
                                                        <span class="qrg-rekap-dot qrg-rekap-dot--out" aria-hidden="true"></span>
                                                        <?php echo esc_html( substr( $day['jam_pulang'], 0, 5 ) ); ?>
                                                    </span>
                                                <?php else : ?>
                                                    <span class="qrg-rekap-time" style="color:#b32d2e;">
                                                        <span class="qrg-rekap-dot qrg-rekap-dot--miss" aria-hidden="true"></span>
                                                        Belum
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html( $day['durasi'] !== '' ? $day['durasi'] : '—' ); ?></td>
                                            <td>
                                                <?php if ( ! empty( $day['lengkap'] ) ) : ?>
                                                    <span class="qrg-rekap-badge qrg-rekap-badge--ok">Lengkap</span>
                                                <?php else : ?>
                                                    <span class="qrg-rekap-badge qrg-rekap-badge--warn">Belum pulang</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="description"><?php echo esc_html( $day['mesin'] !== '' ? $day['mesin'] : '—' ); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <p class="description" style="margin-top:12px;">
                Menampilkan <strong><?php echo (int) count( $users ); ?></strong> orang
                <?php if ( ! empty( $rekap['updated'] ) ) : ?>
                    · data mesin terakhir: <?php echo esc_html( $rekap['updated'] ); ?>
                <?php endif; ?>
                · klik baris nama untuk membuka detail hari
            </p>
            <script>
            (function() {
                var input = document.getElementById('qrg-rekap-search');
                var cards = document.querySelectorAll('#qrg-rekap-list .qrg-rekap-card');
                var empty = document.getElementById('qrg-rekap-empty-search');
                if (!input || !cards.length) return;
                function apply() {
                    var q = (input.value || '').toLowerCase().trim();
                    var shown = 0;
                    cards.forEach(function(card) {
                        var hay = card.getAttribute('data-qrg-search') || '';
                        var ok = !q || hay.indexOf(q) !== -1;
                        card.hidden = !ok;
                        if (ok) shown++;
                    });
                    if (empty) {
                        if (shown === 0) empty.classList.add('is-visible');
                        else empty.classList.remove('is-visible');
                    }
                }
                input.addEventListener('input', apply);
                input.addEventListener('search', apply);
            })();
            </script>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Siapa yang absen di mesin ini.
 *
 * @param string $device_id
 * @param bool   $with_tabs Render nav tabs (false jika dipanggil dari tab Lihat Presensi).
 */
function qrg_solution_render_device_presensi_panel( $device_id, $with_tabs = true ) {
    $device = qrg_solution_get_device( $device_id );
    $back  = qrg_solution_admin_url( [ 'tab' => 'presensi' ] );
    if ( ! $device ) {
        if ( $with_tabs ) {
            qrg_solution_render_admin_tabs( 'presensi' );
        }
        echo '<div class="notice notice-error"><p>Mesin tidak ditemukan. <a href="' . esc_url( $back ) . '">Kembali</a></p></div>';
        return;
    }

    $bulan = isset( $_GET['bulan'] ) ? absint( $_GET['bulan'] ) : (int) current_time( 'n' );
    $tahun = isset( $_GET['tahun'] ) ? absint( $_GET['tahun'] ) : (int) current_time( 'Y' );
    if ( $bulan < 1 || $bulan > 12 ) {
        $bulan = (int) current_time( 'n' );
    }
    if ( $tahun < 2000 || $tahun > 2100 ) {
        $tahun = (int) current_time( 'Y' );
    }

    $data  = qrg_solution_list_device_presensi_who( $device, $bulan, $tahun );
    $items = is_array( $data['items'] ) ? $data['items'] : [];
    $bulan_nama = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    $flash_key = 'qrg_solution_device_attlog_flash_' . get_current_user_id();
    $flash     = get_transient( $flash_key );
    if ( $flash ) {
        delete_transient( $flash_key );
    }

    $form_action = qrg_solution_admin_url( [
        'tab'    => 'presensi',
        'device' => $device['id'],
        'bulan'  => $bulan,
        'tahun'  => $tahun,
    ] );

    if ( $with_tabs ) {
        qrg_solution_render_admin_tabs( 'presensi' );
        ?>
        <h2 class="title">
            Presensi di mesin: <?php echo esc_html( $device['nama'] !== '' ? $device['nama'] : $device['id'] ); ?>
            <a href="<?php echo esc_url( $back ); ?>" class="page-title-action">← Lihat Presensi</a>
        </h2>
        <?php
    } else {
        ?>
        <h3 style="margin:8px 0 8px;">
            <?php echo esc_html( $device['nama'] !== '' ? $device['nama'] : $device['id'] ); ?>
            <?php if ( ! empty( $device['ip'] ) ) : ?>
                · IP <code><?php echo esc_html( $device['ip'] ); ?></code>
            <?php endif; ?>
            <?php if ( ! empty( $device['sn'] ) ) : ?>
                · SN <code><?php echo esc_html( $device['sn'] ); ?></code>
            <?php endif; ?>
            <?php if ( ! empty( $device['lokasi'] ) ) : ?>
                · <?php echo esc_html( $device['lokasi'] ); ?>
            <?php endif; ?>
        </h3>
        <?php
    }
    ?>

    <?php if ( is_array( $flash ) && ! empty( $flash['error'] ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $flash['error'] ); ?></p></div>
    <?php elseif ( is_array( $flash ) && ! empty( $flash['warning'] ) ) : ?>
        <div class="notice notice-warning is-dismissible"><p><?php echo esc_html( $flash['message'] ?: 'Log diambil, tetapi tidak cocok dengan periode filter.' ); ?></p></div>
    <?php elseif ( is_array( $flash ) && ! empty( $flash['ok'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $flash['message'] ?: 'Log absensi diperbarui.' ); ?></p></div>
    <?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px;">
        <form method="post" action="<?php echo esc_url( $form_action ); ?>" style="margin:0;">
            <?php wp_nonce_field( 'qrg_solution_device_attlog', 'qrg_solution_device_attlog_nonce' ); ?>
            <input type="hidden" name="device_id" value="<?php echo esc_attr( $device['id'] ); ?>">
            <input type="hidden" name="bulan" value="<?php echo (int) $bulan; ?>">
            <input type="hidden" name="tahun" value="<?php echo (int) $tahun; ?>">
            <?php submit_button( 'Ambil dari mesin', 'primary', 'qrg_solution_fetch_device_attlog', false ); ?>
        </form>
    </div>

    <p class="description" style="margin-top:0;">
        <strong><?php echo (int) ( $data['people'] ?? 0 ); ?></strong> orang ·
        <strong><?php echo (int) ( $data['punches'] ?? 0 ); ?></strong> punch ·
        <?php echo esc_html( ( $bulan_nama[ $bulan ] ?? '' ) . ' ' . $tahun ); ?>
        <?php if ( ! empty( $data['updated'] ) ) : ?>
            · terakhir dari mesin: <?php echo esc_html( $data['updated'] ); ?>
        <?php else : ?>
            · klik <strong>Ambil dari mesin</strong> untuk memuat log punch
        <?php endif; ?>
    </p>

    <table class="wp-list-table widefat fixed striped table-view-list">
        <thead>
            <tr>
                <th scope="col" style="width:150px;">Tanggal</th>
                <th scope="col" style="width:90px;">Jam</th>
                <th scope="col" style="width:90px;">PIN</th>
                <th scope="col">Nama di mesin</th>
                <th scope="col" style="width:120px;">Status</th>
                <th scope="col" style="width:100px;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr class="no-items">
                    <td class="colspanchange" colspan="6">
                        Belum ada data absensi di mesin ini untuk periode ini.
                        <?php if ( ! empty( $data['other_n'] ) ) : ?>
                            Ada <strong><?php echo (int) $data['other_n']; ?></strong> log di cache di luar periode ini
                            <?php if ( ! empty( $data['other_min'] ) ) : ?>
                                (<?php echo esc_html( substr( $data['other_min'], 0, 10 ) ); ?>
                                – <?php echo esc_html( substr( (string) $data['other_max'], 0, 10 ) ); ?>)
                            <?php endif; ?>.
                            Ubah filter bulan/tahun atau klik <strong>Ambil dari mesin</strong> lagi.
                        <?php elseif ( ! empty( $device['ip'] ) ) : ?>
                            Klik <strong>Ambil dari mesin</strong>.
                        <?php else : ?>
                            Isi IP LAN mesin terlebih dahulu.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $items as $row ) :
                    $tgl = $row['tanggal'] !== '' ? date_i18n( 'd M Y (D)', strtotime( $row['tanggal'] ) ) : '—';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $tgl ); ?></strong>
                            <div class="description"><code><?php echo esc_html( $row['tanggal'] ); ?></code></div>
                        </td>
                        <td><?php echo esc_html( $row['jam'] !== '' ? $row['jam'] : '—' ); ?></td>
                        <td><code><?php echo esc_html( $row['pin'] !== '' ? $row['pin'] : '—' ); ?></code></td>
                        <td>
                            <?php if ( ! empty( $row['finger_url'] ) ) : ?>
                                <a href="<?php echo esc_url( $row['finger_url'] ); ?>"><strong><?php echo esc_html( $row['nama'] ); ?></strong></a>
                            <?php else : ?>
                                <strong><?php echo esc_html( $row['nama'] ); ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row['status'] ); ?></td>
                        <td>
                            <?php if ( ! empty( $row['finger_url'] ) ) : ?>
                                <a href="<?php echo esc_url( $row['finger_url'] ); ?>">Detail</a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

function qrg_solution_render_device_panel() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $tab = qrg_solution_current_tab();

    // Tab User atau link lama ?users=
    $view_users = isset( $_GET['users'] ) ? sanitize_key( wp_unslash( $_GET['users'] ) ) : '';
    $view_device = isset( $_GET['device'] ) ? sanitize_key( wp_unslash( $_GET['device'] ) ) : '';
    if ( $tab === 'user' || $view_users !== '' ) {
        qrg_solution_render_users_panel( $view_device !== '' ? $view_device : $view_users );
        return;
    }

    // Tab Lihat Presensi (+ kompatibilitas ?presensi=)
    if ( $tab === 'presensi' ) {
        qrg_solution_render_presensi_tab();
        return;
    }

    // Tab Rekap Harian (per user / per hari)
    if ( $tab === 'rekap' ) {
        qrg_solution_render_rekap_tab();
        return;
    }

    $settings    = qrg_solution_get_settings();
    $devices     = qrg_solution_get_devices();
    $logs        = qrg_solution_get_logs();
    $server      = qrg_solution_server_url_for_device();
    $base        = qrg_solution_iclock_base_url();
    $test_nonce  = wp_create_nonce( 'qrg_solution_test' );
    $host        = (string) parse_url( home_url(), PHP_URL_HOST );
    $form_mesin  = qrg_solution_admin_url( [ 'tab' => 'mesin' ] );
    $form_tambah = qrg_solution_admin_url( [ 'tab' => 'tambah' ] );
    $form_set    = qrg_solution_admin_url( [ 'tab' => 'pengaturan' ] );
    $form_log    = qrg_solution_admin_url( [ 'tab' => 'log' ] );

    qrg_solution_render_admin_tabs( $tab );

    if ( isset( $_GET['updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Perubahan disimpan.</p></div>';
    }
    if ( isset( $_GET['added'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Mesin ditambahkan.</p></div>';
    }
    if ( isset( $_GET['deleted'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Mesin dihapus.</p></div>';
    }
    if ( isset( $_GET['cleared'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Log dikosongkan.</p></div>';
    }

    if ( $tab === 'tambah' ) :
        ?>
        <h2 class="title">Tambah mesin</h2>
        <p class="description">Daftarkan mesin absensi Solution baru ke daftar perangkat.</p>
        <form method="post" action="<?php echo esc_url( $form_tambah ); ?>">
            <?php wp_nonce_field( 'qrg_solution_device_add', 'qrg_solution_device_add_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="device_nama">Nama</label></th>
                    <td><input type="text" id="device_nama" name="device_nama" class="regular-text" placeholder="Gerbang utama / Kampus 1" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="device_ip">IP LAN</label></th>
                    <td><input type="text" id="device_ip" name="device_ip" class="regular-text" placeholder="192.168.11.111"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="device_sn">Serial Number</label></th>
                    <td>
                        <input type="text" id="device_sn" name="device_sn" class="regular-text" placeholder="Opsional">
                        <p class="description">Bisa dikosongkan; terisi otomatis saat mesin connect.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="device_lokasi">Lokasi</label></th>
                    <td><input type="text" id="device_lokasi" name="device_lokasi" class="regular-text" placeholder="Lobby / Kantor"></td>
                </tr>
                <tr>
                    <th scope="row">Admin login</th>
                    <td>
                        <label for="device_admin_user">User
                            <input type="text" id="device_admin_user" name="device_admin_user" value="16020" class="small-text">
                        </label>
                        &nbsp;
                        <label for="device_admin_pass">Password
                            <input type="text" id="device_admin_pass" name="device_admin_pass" value="16020" class="small-text">
                        </label>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <?php submit_button( 'Tambah mesin', 'primary', 'qrg_solution_add_device', false ); ?>
                <a href="<?php echo esc_url( $form_mesin ); ?>" class="button">Batal</a>
            </p>
        </form>
        <?php
        return;
    endif;

    if ( $tab === 'pengaturan' ) :
        ?>
        <h2 class="title">Pengaturan</h2>
        <p class="description">
            Integrasi perangkat <a href="https://www.solution.co.id/" target="_blank" rel="noopener">Solution</a>
            via protokol ADMS Push.
        </p>
        <form method="post" action="<?php echo esc_url( $form_set ); ?>">
            <?php wp_nonce_field( 'qrg_solution_settings', 'qrg_solution_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Integrasi aktif</th>
                    <td>
                        <fieldset>
                            <label for="solution_enabled">
                                <input type="checkbox" id="solution_enabled" name="solution_enabled" value="1" <?php checked( $settings['enabled'], '1' ); ?>>
                                Terima data absensi dari semua mesin
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="solution_timezone">Zona waktu</label></th>
                    <td>
                        <input type="text" id="solution_timezone" name="solution_timezone" value="<?php echo esc_attr( $settings['timezone'] ); ?>" class="small-text">
                        <span class="description">WIB = 7</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Admin login mesin</th>
                    <td>
                        <label for="solution_admin_user">User
                            <input type="text" id="solution_admin_user" name="solution_admin_user" value="<?php echo esc_attr( $settings['admin_user'] ?? '16020' ); ?>" class="regular-text" style="width:120px;" placeholder="16020">
                        </label>
                        &nbsp;
                        <label for="solution_admin_pass">Password
                            <input type="text" id="solution_admin_pass" name="solution_admin_pass" value="<?php echo esc_attr( $settings['admin_pass'] ?? '16020' ); ?>" class="regular-text" style="width:120px;" placeholder="16020">
                        </label>
                        <p class="description">Default login web mesin. Bisa diganti per mesin di tab Daftar Mesin.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">URL server (ADMS)</th>
                    <td>
                        <input type="text" class="large-text code" readonly value="<?php echo esc_attr( $server ); ?>" onclick="this.select();">
                        <p class="description">Salin ke Cloud Server / ADMS di mesin. Base: <code><?php echo esc_html( $base ); ?></code></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Simpan pengaturan', 'primary', 'qrg_solution_save' ); ?>
        </form>
        <?php
        return;
    endif;

    if ( $tab === 'panduan' ) :
        ?>
        <h2 class="title">Panduan konfigurasi mesin</h2>
        <ol>
            <li>Buka web admin mesin: <code>http://IP-MESIN/</code> (login admin, default <code>16020</code> / <code>16020</code>).</li>
            <li>Menu <strong>Comm / Communication</strong> → <strong>Cloud Server Setting</strong> atau <strong>ADMS</strong>.</li>
            <li>Mode: <strong>ADMS</strong>.</li>
            <li>Server Address: <code><?php echo esc_html( $host ); ?></code></li>
            <li>Server Path / URL: <code><?php echo esc_html( $server ); ?></code></li>
            <li>Aktifkan <strong>Real-time Upload</strong>.</li>
            <li>Pastikan mesin dan server WordPress berada di jaringan yang sama (atau IP publik terjangkau).</li>
        </ol>
        <h3>Endpoint yang dipakai mesin</h3>
        <ul>
            <li><code><?php echo esc_html( home_url( '/iclock/cdata' ) ); ?></code></li>
            <li><code><?php echo esc_html( home_url( '/iclock/getrequest' ) ); ?></code></li>
            <li><code><?php echo esc_html( home_url( '/iclock/devicecmd' ) ); ?></code></li>
        </ul>
        <?php
        return;
    endif;

    if ( $tab === 'log' ) :
        ?>
        <h2 class="title">Log sinkronisasi</h2>
        <form method="post" action="<?php echo esc_url( $form_log ); ?>" onsubmit="return confirm('Kosongkan log sinkronisasi?');" style="margin-bottom:12px;">
            <?php wp_nonce_field( 'qrg_solution_logs', 'qrg_solution_logs_nonce' ); ?>
            <?php submit_button( 'Kosongkan log', 'secondary', 'qrg_solution_clear_logs', false, empty( $logs ) ? [ 'disabled' => 'disabled' ] : [] ); ?>
        </form>
        <table class="wp-list-table widefat fixed striped table-view-list">
            <thead>
                <tr>
                    <th scope="col" style="width:160px;">Waktu</th>
                    <th scope="col" style="width:100px;">Tipe</th>
                    <th scope="col">Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $logs ) ) : ?>
                    <tr class="no-items"><td class="colspanchange" colspan="3">Belum ada aktivitas.</td></tr>
                <?php else : ?>
                    <?php foreach ( array_slice( $logs, 0, 40 ) as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['time'] ?? '' ) ); ?></td>
                            <td><code><?php echo esc_html( (string) ( $row['type'] ?? '' ) ); ?></code></td>
                            <td>
                                <?php
                                $parts = [];
                                foreach ( [ 'mesin', 'sn', 'count', 'saved', 'msg', 'table', 'ip', 'preview' ] as $k ) {
                                    if ( ! empty( $row[ $k ] ) ) {
                                        $parts[] = $k . ': ' . $row[ $k ];
                                    }
                                }
                                echo esc_html( implode( ' · ', $parts ) );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
        return;
    endif;

    // Default tab: mesin
    $edit_id     = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
    $edit_device = $edit_id !== '' ? qrg_solution_get_device( $edit_id ) : null;

    if ( $edit_device ) :
        $admin_u = $edit_device['admin_user'] ?? '16020';
        $admin_p = $edit_device['admin_pass'] ?? '16020';
        $back    = qrg_solution_admin_url( [ 'tab' => 'mesin' ] );
        $form_edit = qrg_solution_admin_url( [ 'tab' => 'mesin', 'edit' => $edit_device['id'] ] );
        ?>
        <h2 class="title">
            Edit mesin
            <a href="<?php echo esc_url( $back ); ?>" class="page-title-action">← Kembali ke daftar</a>
        </h2>
        <p class="description">Perbarui data mesin <strong><?php echo esc_html( $edit_device['nama'] ?: $edit_device['id'] ); ?></strong>.</p>
        <form method="post" action="<?php echo esc_url( $form_edit ); ?>">
            <?php wp_nonce_field( 'qrg_solution_device_edit', 'qrg_solution_device_edit_nonce' ); ?>
            <input type="hidden" name="device_id" value="<?php echo esc_attr( $edit_device['id'] ); ?>">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Status</th>
                    <td>
                        <label>
                            <input type="checkbox" name="device_enabled" value="1" <?php checked( $edit_device['enabled'], '1' ); ?>>
                            Aktifkan mesin
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="device_nama">Nama</label></th>
                    <td><input type="text" id="device_nama" name="device_nama" class="regular-text" value="<?php echo esc_attr( $edit_device['nama'] ); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="device_lokasi">Lokasi</label></th>
                    <td><input type="text" id="device_lokasi" name="device_lokasi" class="regular-text" value="<?php echo esc_attr( $edit_device['lokasi'] ); ?>" placeholder="Kampus 1 / Lobby"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="device_ip">IP LAN</label></th>
                    <td>
                        <input type="text" id="device_ip" name="device_ip" class="regular-text qrg-device-ip" value="<?php echo esc_attr( $edit_device['ip'] ); ?>" placeholder="192.168.11.111">
                        <?php if ( $edit_device['ip'] ) : ?>
                            <p class="description">
                                <a href="<?php echo esc_url( 'http://' . $edit_device['ip'] . '/' ); ?>" target="_blank" rel="noopener">Buka web mesin</a>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="device_sn">Serial Number</label></th>
                    <td>
                        <input type="text" id="device_sn" name="device_sn" class="regular-text" value="<?php echo esc_attr( $edit_device['sn'] ); ?>" placeholder="Otomatis dari mesin">
                        <p class="description">Bisa dikosongkan; terisi otomatis saat mesin connect.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Admin login</th>
                    <td>
                        <label for="device_admin_user">User
                            <input type="text" id="device_admin_user" name="device_admin_user" value="<?php echo esc_attr( $admin_u ); ?>" class="small-text">
                        </label>
                        &nbsp;
                        <label for="device_admin_pass">Password
                            <input type="text" id="device_admin_pass" name="device_admin_pass" value="<?php echo esc_attr( $admin_p ); ?>" class="small-text">
                        </label>
                    </td>
                </tr>
                <?php if ( ! empty( $edit_device['last_seen'] ) ) : ?>
                    <tr>
                        <th scope="row">Terakhir konek</th>
                        <td><code><?php echo esc_html( $edit_device['last_seen'] ); ?></code></td>
                    </tr>
                <?php endif; ?>
            </table>
            <p class="submit">
                <?php submit_button( 'Simpan perubahan', 'primary', 'qrg_solution_update_device', false ); ?>
                <a href="<?php echo esc_url( $back ); ?>" class="button">Batal</a>
                <button type="button" class="button button-link-delete qrg-mesin-delete" style="margin-left:12px;color:#b32d2e;" data-delete-id="<?php echo esc_attr( $edit_device['id'] ); ?>" data-delete-name="<?php echo esc_attr( $edit_device['nama'] ); ?>">Hapus mesin</button>
            </p>
        </form>
        <form method="post" action="<?php echo esc_url( $form_mesin ); ?>" id="qrg-del-<?php echo esc_attr( $edit_device['id'] ); ?>" style="display:none;">
            <?php wp_nonce_field( 'qrg_solution_device_delete', 'qrg_solution_device_delete_nonce' ); ?>
            <input type="hidden" name="device_id" value="<?php echo esc_attr( $edit_device['id'] ); ?>">
            <input type="hidden" name="qrg_solution_delete_device" value="1">
        </form>
        <script>
        jQuery(function($) {
            $('.qrg-mesin-delete').on('click', function(e) {
                e.preventDefault();
                var id = $(this).data('delete-id');
                var name = $(this).data('delete-name') || 'ini';
                if (!confirm('Hapus mesin ' + name + '?')) return;
                var $form = $('#qrg-del-' + id);
                if ($form.length) $form.trigger('submit');
            });
        });
        </script>
        <?php
        return;
    endif;

    $online_count = 0;
    foreach ( $devices as $d ) {
        if ( ! empty( $d['last_seen'] ) && ( time() - (int) strtotime( $d['last_seen'] ) ) < 600 ) {
            $online_count++;
        }
    }
    ?>
    <style>
        .qrg-mesin-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.6;
            white-space: nowrap;
        }
        .qrg-mesin-badge--on { background: #edfaef; color: #007017; }
        .qrg-mesin-badge--off { background: #f0f0f1; color: #646970; }
        .qrg-mesin-badge--never { background: #fcf0f1; color: #8a2424; }
        .qrg-mesin-badge--disabled { background: #f0f0f1; color: #8c8f94; }
        #qrg-solution-test-result:not(:empty) {
            display: inline-block;
            margin: 8px 0 0;
            padding: 6px 10px;
            background: #fff;
            border-left: 3px solid #2271b1;
        }
        .qrg-mesin-table .column-status { width: 150px; }
        .qrg-mesin-table .column-ip { width: 130px; }
        .qrg-mesin-table .column-sn { width: 140px; }
        .qrg-mesin-table .column-users { width: 80px; }
        .qrg-mesin-table .column-aktif { width: 70px; }
        .qrg-mesin-table .column-aksi { width: 120px; }
        .qrg-mesin-table .row-actions .button-link { padding: 0; border: 0; background: none; cursor: pointer; }
        .qrg-mesin-table .row-actions .button-link-delete { color: #b32d2e; }
    </style>

    <h2 class="title">
        Daftar mesin
        <a href="<?php echo esc_url( qrg_solution_admin_url( [ 'tab' => 'tambah' ] ) ); ?>" class="page-title-action">Tambah mesin</a>
    </h2>
    <p class="description">
        <?php if ( ! empty( $devices ) ) : ?>
            <?php echo (int) count( $devices ); ?> mesin terdaftar
            · <?php echo (int) $online_count; ?> online
            · <?php echo (int) ( count( $devices ) - $online_count ); ?> offline / belum konek
        <?php else : ?>
            Belum ada mesin.
            <a href="<?php echo esc_url( qrg_solution_admin_url( [ 'tab' => 'tambah' ] ) ); ?>">Tambah mesin baru</a>.
        <?php endif; ?>
    </p>

    <table class="wp-list-table widefat fixed striped table-view-list qrg-mesin-table">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-aktif">Aktif</th>
                <th scope="col" class="manage-column column-primary">Nama mesin</th>
                <th scope="col" class="manage-column">Lokasi</th>
                <th scope="col" class="manage-column column-ip">IP LAN</th>
                <th scope="col" class="manage-column column-sn">Serial Number</th>
                <th scope="col" class="manage-column column-users">User</th>
                <th scope="col" class="manage-column column-status">Status</th>
                <th scope="col" class="manage-column column-aksi">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $devices ) ) : ?>
                <tr class="no-items">
                    <td class="colspanchange" colspan="8">
                        Belum ada mesin.
                        <a href="<?php echo esc_url( qrg_solution_admin_url( [ 'tab' => 'tambah' ] ) ); ?>">Tambah mesin</a>.
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $devices as $device ) :
                    $id     = $device['id'];
                    $online = ! empty( $device['last_seen'] ) && ( time() - (int) strtotime( $device['last_seen'] ) ) < 600;
                    $user_n = count( qrg_solution_get_device_users( $id )['users'] ?? [] );
                    $nama   = $device['nama'] !== '' ? $device['nama'] : 'Tanpa nama';
                    $aktif  = ( $device['enabled'] ?? '1' ) === '1';
                    if ( ! $aktif ) {
                        $badge_cls = 'qrg-mesin-badge--disabled';
                        $badge_txt = 'Nonaktif';
                    } elseif ( $online ) {
                        $badge_cls = 'qrg-mesin-badge--on';
                        $badge_txt = 'Online';
                    } elseif ( $device['last_seen'] ) {
                        $badge_cls = 'qrg-mesin-badge--off';
                        $badge_txt = 'Offline';
                    } else {
                        $badge_cls = 'qrg-mesin-badge--never';
                        $badge_txt = 'Belum konek';
                    }
                    $edit_url     = qrg_solution_admin_url( [ 'tab' => 'mesin', 'edit' => $id ] );
                    $users_url    = qrg_solution_admin_url( [ 'tab' => 'user', 'device' => $id ] );
                    $presensi_url = qrg_solution_admin_url( [ 'tab' => 'presensi' ] );
                    ?>
                    <tr data-device-id="<?php echo esc_attr( $id ); ?>">
                        <td data-colname="Aktif">
                            <?php echo $aktif ? '<span class="dashicons dashicons-yes" style="color:#00a32a;" title="Aktif"></span>' : '<span class="dashicons dashicons-minus" style="color:#a7aaad;" title="Nonaktif"></span>'; ?>
                        </td>
                        <td class="column-primary has-row-actions" data-colname="Nama">
                            <strong>
                                <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $nama ); ?></a>
                            </strong>
                            <div class="row-actions">
                                <span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>">Edit</a> | </span>
                                <span class="view"><a href="<?php echo esc_url( $users_url ); ?>">User<?php echo $user_n ? ' (' . (int) $user_n . ')' : ''; ?></a> | </span>
                                <span class="view"><a href="<?php echo esc_url( $presensi_url ); ?>">Presensi</a> | </span>
                                <span class="view">
                                    <button type="button" class="button-link qrg-solution-test-row" data-ip="<?php echo esc_attr( $device['ip'] ); ?>">Tes koneksi</button> |
                                </span>
                                <?php if ( $device['ip'] ) : ?>
                                    <span class="view"><a href="<?php echo esc_url( 'http://' . $device['ip'] . '/' ); ?>" target="_blank" rel="noopener">Buka web</a> | </span>
                                <?php endif; ?>
                                <span class="trash">
                                    <button type="button" class="button-link button-link-delete qrg-mesin-delete" data-delete-id="<?php echo esc_attr( $id ); ?>" data-delete-name="<?php echo esc_attr( $device['nama'] ); ?>">Hapus</button>
                                </span>
                            </div>
                            <button type="button" class="toggle-row"><span class="screen-reader-text">Tampilkan detail</span></button>
                        </td>
                        <td data-colname="Lokasi"><?php echo esc_html( $device['lokasi'] !== '' ? $device['lokasi'] : '—' ); ?></td>
                        <td data-colname="IP" class="qrg-device-ip-cell">
                            <?php if ( $device['ip'] ) : ?>
                                <code class="qrg-device-ip"><?php echo esc_html( $device['ip'] ); ?></code>
                            <?php else : ?>
                                <span class="description">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-colname="SN">
                            <?php if ( $device['sn'] ) : ?>
                                <code><?php echo esc_html( $device['sn'] ); ?></code>
                            <?php else : ?>
                                <span class="description">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-colname="User">
                            <a href="<?php echo esc_url( $users_url ); ?>"><?php echo (int) $user_n; ?></a>
                        </td>
                        <td data-colname="Status">
                            <span class="qrg-mesin-badge <?php echo esc_attr( $badge_cls ); ?>"><?php echo esc_html( $badge_txt ); ?></span>
                            <?php if ( ! $online && ! empty( $device['last_seen'] ) && $aktif ) : ?>
                                <div class="description" style="margin-top:4px;"><?php echo esc_html( $device['last_seen'] ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-colname="Aksi">
                            <a class="button button-small" href="<?php echo esc_url( $presensi_url ); ?>">Lihat presensi</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <p id="qrg-solution-test-result" class="description"></p>

    <?php foreach ( $devices as $device ) : ?>
        <form method="post" action="<?php echo esc_url( $form_mesin ); ?>" id="qrg-del-<?php echo esc_attr( $device['id'] ); ?>" style="display:none;">
            <?php wp_nonce_field( 'qrg_solution_device_delete', 'qrg_solution_device_delete_nonce' ); ?>
            <input type="hidden" name="device_id" value="<?php echo esc_attr( $device['id'] ); ?>">
            <input type="hidden" name="qrg_solution_delete_device" value="1">
        </form>
    <?php endforeach; ?>

    <script>
    jQuery(function($) {
        $('.qrg-solution-test-row').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $row = $btn.closest('tr');
            var ip = $row.find('.qrg-device-ip').text() || $btn.data('ip');
            var $out = $('#qrg-solution-test-result').text('Menghubungi ' + ip + '…');
            $btn.prop('disabled', true);
            $.post(ajaxurl, {
                action: 'qrg_solution_test_device',
                nonce: '<?php echo esc_js( $test_nonce ); ?>',
                ip: ip
            }).done(function(res) {
                $out.text(res.success ? (res.data.msg || 'OK') : (res.data && res.data.message ? res.data.message : 'Gagal'));
            }).fail(function() {
                $out.text('Permintaan gagal');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
        $('.qrg-mesin-delete').on('click', function(e) {
            e.preventDefault();
            var id = $(this).data('delete-id');
            var name = $(this).data('delete-name') || 'ini';
            if (!confirm('Hapus mesin ' + name + '?')) return;
            var $form = $('#qrg-del-' + id);
            if ($form.length) $form.trigger('submit');
        });
    });
    </script>
    <?php
}
