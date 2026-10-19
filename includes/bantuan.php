<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Menu "Bantuan" berisi dokumentasi shortcode & cara penggunaan plugin
add_action( 'admin_menu', 'qrg_add_bantuan_submenu', 30 );
function qrg_add_bantuan_submenu() {
    add_submenu_page(
        'master-data-plugin',
        'Bantuan & Dokumentasi',
        'Bantuan',
        'manage_options',
        'qrg-bantuan',
        'qrg_bantuan_page_callback'
    );
}

/**
 * ID lampiran QR code pembayaran (opsi).
 *
 * @return int
 */
function qrg_get_pembayaran_qr_attachment_id() {
    return (int) get_option( 'qrg_pembayaran_qr_attachment_id', 0 );
}

/**
 * URL gambar QR pembayaran yang diunggah admin.
 *
 * @param string|array $size Ukuran gambar WP.
 * @return string
 */
function qrg_get_pembayaran_qr_url( $size = 'full' ) {
    $id = qrg_get_pembayaran_qr_attachment_id();
    if ( $id <= 0 ) {
        return '';
    }
    $url = wp_get_attachment_image_url( $id, $size );
    return $url ? (string) $url : '';
}

/**
 * Baca isi (payload) dari gambar QR code.
 *
 * @param string $image_url URL publik gambar.
 * @return string Payload atau string kosong.
 */
/**
 * Payload QR pembayaran bawaan (QRIS CPM DANA).
 *
 * @return string
 */
function qrg_get_default_pembayaran_qr_payload() {
    return 'hQVDUFYwMWFbTwegAAAGAiAgUAdRUklTQ1BNWgqTYAkVMFmDiUdPXyAOUllBTiBERVdBTlRPUk9fLQJpZF9QB0RBTkEuSURjG590GDI4MTAxMjAyMTMwOTg1OTUwMzQxNjI3MA==';
}

/**
 * Parse respons JSON API read-qr-code.
 *
 * @param string $body Body.
 * @return string
 */
function qrg_parse_qrserver_read_body( $body ) {
    $json = json_decode( (string) $body, true );
    if ( ! is_array( $json ) || empty( $json[0]['symbol'][0] ) ) {
        return '';
    }
    $symbol = $json[0]['symbol'][0];
    if ( ! empty( $symbol['error'] ) ) {
        return '';
    }
    return trim( (string) ( $symbol['data'] ?? '' ) );
}

/**
 * Baca payload QR dari URL gambar publik.
 *
 * @param string $image_url URL.
 * @return string
 */
function qrg_read_qr_payload_from_image_url( $image_url ) {
    $image_url = esc_url_raw( (string) $image_url );
    if ( $image_url === '' ) {
        return '';
    }

    $endpoint = 'https://api.qrserver.com/v1/read-qr-code/?fileurl=' . rawurlencode( $image_url );
    $response = wp_remote_get(
        $endpoint,
        [
            'timeout' => 20,
            'headers' => [ 'Accept' => 'application/json' ],
        ]
    );
    if ( is_wp_error( $response ) ) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    if ( $code < 200 || $code >= 300 || $body === '' ) {
        return '';
    }

    return qrg_parse_qrserver_read_body( $body );
}

/**
 * Baca payload QR dari file lokal (unggah ke API read-qr-code).
 *
 * @param string $file_path Path absolut.
 * @return string
 */
function qrg_read_qr_payload_from_local_file( $file_path ) {
    $file_path = (string) $file_path;
    if ( $file_path === '' || ! is_readable( $file_path ) ) {
        return '';
    }
    if ( ! function_exists( 'curl_init' ) ) {
        return '';
    }

    $mime = 'image/png';
    if ( function_exists( 'mime_content_type' ) ) {
        $detected = mime_content_type( $file_path );
        if ( is_string( $detected ) && $detected !== '' ) {
            $mime = $detected;
        }
    }

    $endpoint = 'https://api.qrserver.com/v1/read-qr-code/';
    $cfile    = class_exists( 'CURLFile' )
        ? new CURLFile( $file_path, $mime, basename( $file_path ) )
        : '@' . $file_path;

    $ch = curl_init( $endpoint );
    curl_setopt_array(
        $ch,
        [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [ 'file' => $cfile ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => [ 'Accept: application/json' ],
        ]
    );
    $body = curl_exec( $ch );
    $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    curl_close( $ch );
    if ( ! is_string( $body ) || $body === '' || $code < 200 || $code >= 300 ) {
        return '';
    }

    return qrg_parse_qrserver_read_body( $body );
}

/**
 * Interpretasi payload QR pembayaran (teks biasa / base64 QRIS CPM / EMV MPM).
 *
 * @param string $payload Isi QR.
 * @return array
 */
function qrg_interpret_pembayaran_qr_payload( $payload ) {
    $payload = trim( (string) $payload );
    $out     = [
        'raw'           => $payload,
        'type'          => 'unknown',
        'type_label'    => 'Tidak dikenali',
        'is_base64'     => false,
        'base64_raw'    => '',
        'decoded_ascii' => '',
        'decoded_hex'   => '',
        'decoded_runs'  => [],
        'fields'        => [],
        'summary'       => [],
    ];

    if ( $payload === '' ) {
        $out['type_label'] = 'Kosong';
        return $out;
    }

    // QRIS Merchant Presented Mode (teks EMV, biasanya diawali 000201).
    if ( preg_match( '/^000201/', $payload ) ) {
        $out['type']       = 'qris_mpm';
        $out['type_label'] = 'QRIS MPM (Merchant Presented Mode)';
        $out['fields']     = qrg_parse_emv_mpm_fields( $payload );
        $out['summary']    = qrg_summarize_qris_mpm( $out['fields'] );
        return $out;
    }

    // URL biasa.
    if ( preg_match( '#^https?://#i', $payload ) ) {
        $out['type']       = 'url';
        $out['type_label'] = 'URL / tautan';
        $out['summary']    = [
            [ 'label' => 'Tautan', 'value' => $payload ],
        ];
        return $out;
    }

    // Base64 (QRIS CPM DANA dll).
    if ( preg_match( '/^[A-Za-z0-9+\/=_-]+$/', $payload ) && strlen( $payload ) >= 16 ) {
        $try = strtr( $payload, '-_', '+/' );
        $pad = strlen( $try ) % 4;
        if ( $pad ) {
            $try .= str_repeat( '=', 4 - $pad );
        }
        $bin = base64_decode( $try, true );
        if ( $bin !== false && $bin !== '' ) {
            $out['is_base64']     = true;
            $out['base64_raw']    = $payload;
            $out['decoded_hex']   = bin2hex( $bin );
            $out['decoded_ascii'] = qrg_qr_bin_to_ascii_preview( $bin );
            $out['decoded_runs']  = qrg_qr_extract_printable_runs( $bin );

            $joined = implode( ' ', $out['decoded_runs'] );
            if ( stripos( $joined, 'QRIS' ) !== false || stripos( $joined, 'DANA' ) !== false || stripos( $joined, 'CPM' ) !== false ) {
                $out['type']       = 'qris_cpm';
                $out['type_label'] = 'QRIS CPM (Consumer Presented Mode) — data Base64';
            } else {
                $out['type']       = 'base64';
                $out['type_label'] = 'Data Base64';
            }
            $out['summary'] = qrg_summarize_qris_cpm_runs( $out['decoded_runs'], $joined );
            return $out;
        }
    }

    $out['type']       = 'text';
    $out['type_label'] = 'Teks biasa';
    $out['summary']    = [
        [ 'label' => 'Isi', 'value' => $payload ],
    ];
    return $out;
}

/**
 * @param string $bin Binary.
 * @return string
 */
function qrg_qr_bin_to_ascii_preview( $bin ) {
    $out = '';
    $len = strlen( $bin );
    for ( $i = 0; $i < $len; $i++ ) {
        $c = ord( $bin[ $i ] );
        $out .= ( $c >= 32 && $c < 127 ) ? $bin[ $i ] : '.';
    }
    return $out;
}

/**
 * @param string $bin Binary.
 * @return string[]
 */
function qrg_qr_extract_printable_runs( $bin ) {
    if ( ! preg_match_all( '/[\x20-\x7E]{3,}/', $bin, $m ) ) {
        return [];
    }
    return array_values( array_unique( $m[0] ) );
}

/**
 * Ringkas hasil decode QRIS CPM dari string terbaca.
 *
 * @param string[] $runs   Runs.
 * @param string   $joined Joined.
 * @return array<int,array{label:string,value:string}>
 */
function qrg_summarize_qris_cpm_runs( $runs, $joined ) {
    $summary  = [];
    $name     = '';
    $provider = '';
    $nmid     = '';

    foreach ( $runs as $run ) {
        $run = trim( $run );
        if ( $run === '' ) {
            continue;
        }
        if ( stripos( $run, 'DANA' ) !== false && $provider === '' ) {
            if ( preg_match( '/DANA(?:\.ID)?/i', $run, $dm ) ) {
                $provider = strtoupper( $dm[0] );
                if ( strcasecmp( $provider, 'DANA' ) === 0 ) {
                    $provider = 'DANA';
                } else {
                    $provider = 'DANA.ID';
                }
            } else {
                $provider = preg_replace( '/[^A-Za-z0-9.\-]/', '', $run );
            }
        }
        $name_candidate = trim( preg_replace( '/[_\-\.]+$/', '', $run ) );
        if (
            $name === ''
            && preg_match( '/^[A-Z][A-Z\s\.\'-]{2,40}$/', $name_candidate )
            && stripos( $name_candidate, 'QRIS' ) === false
            && stripos( $name_candidate, 'DANA' ) === false
            && stripos( $name_candidate, 'CPM' ) === false
            && stripos( $name_candidate, 'CPV' ) === false
        ) {
            $name = $name_candidate;
        }
        if ( preg_match( '/^\d{15,30}$/', $run ) && $nmid === '' ) {
            $nmid = $run;
        }
    }

    $summary[] = [ 'label' => 'Jenis', 'value' => 'QRIS CPM (biasanya untuk pembayaran e-wallet seperti DANA)' ];
    $summary[] = [ 'label' => 'Invoice', 'value' => 'Nominal tidak otomatis — siswa perlu input manual' ];
    if ( $name !== '' ) {
        $summary[] = [ 'label' => 'Nama / Merchant', 'value' => $name ];
    }
    if ( $provider !== '' ) {
        $summary[] = [ 'label' => 'Penyedia', 'value' => $provider ];
    }
    if ( $nmid !== '' ) {
        $summary[] = [ 'label' => 'ID / NMID', 'value' => $nmid ];
    }
    if ( stripos( $joined, 'QRISCPM' ) !== false || stripos( $joined, 'QRIS' ) !== false ) {
        $summary[] = [ 'label' => 'Standar', 'value' => 'QRIS Consumer Presented Mode' ];
    }
    return $summary;
}

/**
 * Parse sederhana tag EMV MPM (panjang 2 digit).
 *
 * @param string $payload Payload.
 * @return array<string,string>
 */
function qrg_parse_emv_mpm_fields( $payload ) {
    $fields = [];
    $i      = 0;
    $len    = strlen( $payload );
    while ( $i + 4 <= $len ) {
        $tag = substr( $payload, $i, 2 );
        $l   = (int) substr( $payload, $i + 2, 2 );
        $i  += 4;
        if ( $l < 0 || $i + $l > $len ) {
            break;
        }
        $val           = substr( $payload, $i, $l );
        $i            += $l;
        $fields[ $tag ] = $val;
    }
    return $fields;
}

/**
 * @param array<string,string> $fields Fields.
 * @return array<int,array{label:string,value:string}>
 */
function qrg_summarize_qris_mpm( $fields ) {
    $summary = [
        [ 'label' => 'Jenis', 'value' => 'QRIS MPM (ditampilkan merchant, dipindai pelanggan)' ],
        [ 'label' => 'Invoice', 'value' => 'Nominal otomatis mengikuti total tagihan siswa' ],
    ];
    if ( ! empty( $fields['59'] ) ) {
        $summary[] = [ 'label' => 'Nama Merchant', 'value' => $fields['59'] ];
    }
    if ( ! empty( $fields['60'] ) ) {
        $summary[] = [ 'label' => 'Kota', 'value' => $fields['60'] ];
    }
    if ( ! empty( $fields['15'] ) ) {
        $summary[] = [ 'label' => 'MCC', 'value' => $fields['15'] ];
    }
    if ( ! empty( $fields['54'] ) ) {
        $summary[] = [ 'label' => 'Nominal di master', 'value' => $fields['54'] . ' (akan diganti per tagihan)' ];
    }
    if ( ! empty( $fields['58'] ) ) {
        $summary[] = [ 'label' => 'Negara', 'value' => $fields['58'] ];
    }
    foreach ( $fields as $tag => $val ) {
        if ( (int) $tag >= 26 && (int) $tag <= 51 && is_string( $val ) && $val !== '' ) {
            $sub = qrg_parse_emv_mpm_fields( $val );
            if ( ! empty( $sub['02'] ) ) {
                $summary[] = [ 'label' => 'ID Merchant (tag ' . $tag . ')', 'value' => $sub['02'] ];
            }
            if ( ! empty( $sub['01'] ) ) {
                $summary[] = [ 'label' => 'NNS / Acquirer', 'value' => $sub['01'] ];
            }
        }
    }
    return $summary;
}

/**
 * Ambil & cache interpretasi QR aktif.
 *
 * @param bool $force Paksa baca ulang.
 * @return array{payload:string,info:array,error:string}
 */
function qrg_get_active_pembayaran_qr_interpretation( $force = false ) {
    $id  = qrg_get_pembayaran_qr_attachment_id();
    $out = [ 'payload' => '', 'info' => qrg_interpret_pembayaran_qr_payload( '' ), 'error' => '', 'source' => 'none' ];

    // Belum ada unggahan: pakai payload QR bawaan invoice.
    if ( $id <= 0 ) {
        $payload         = qrg_get_default_pembayaran_qr_payload();
        $out['payload']  = $payload;
        $out['info']     = qrg_interpret_pembayaran_qr_payload( $payload );
        $out['source']   = 'default';
        $out['info']['summary'] = array_merge(
            [ [ 'label' => 'Sumber', 'value' => 'QR bawaan sistem (belum ada unggahan)' ] ],
            $out['info']['summary']
        );
        return $out;
    }

    $file  = get_attached_file( $id );
    $mtime = ( $file && file_exists( $file ) ) ? (string) filemtime( $file ) : '0';
    $cache = get_option( 'qrg_pembayaran_qr_decode_cache', [] );
    if ( ! $force && is_array( $cache ) && (int) ( $cache['id'] ?? 0 ) === $id && (string) ( $cache['mtime'] ?? '' ) === $mtime && ! empty( $cache['payload'] ) ) {
        $out['payload'] = (string) $cache['payload'];
        $out['info']    = qrg_interpret_pembayaran_qr_payload( $out['payload'] );
        $out['source']  = 'cache';
        return $out;
    }

    $payload = '';
    if ( $file && is_readable( $file ) ) {
        $payload = qrg_read_qr_payload_from_local_file( $file );
    }
    if ( $payload === '' ) {
        $url = qrg_get_pembayaran_qr_url( 'full' );
        if ( $url !== '' ) {
            $payload = qrg_read_qr_payload_from_image_url( $url );
        }
    }
    if ( $payload === '' ) {
        $out['error']  = 'Gagal membaca QR dari gambar. Pastikan gambar jelas, atau tekan “Baca ulang”.';
        $out['source'] = 'upload';
        return $out;
    }

    update_option(
        'qrg_pembayaran_qr_decode_cache',
        [
            'id'      => $id,
            'mtime'   => $mtime,
            'payload' => $payload,
            'at'      => time(),
        ],
        false
    );

    $out['payload'] = $payload;
    $out['info']    = qrg_interpret_pembayaran_qr_payload( $payload );
    $out['source']  = 'upload';
    return $out;
}

/**
 * CRC-16/CCITT-FALSE untuk QRIS EMV MPM (tag 63).
 *
 * @param string $data Data termasuk "6304".
 * @return string 4 hex uppercase.
 */
function qrg_qris_crc16( $data ) {
    $crc = 0xFFFF;
    $len = strlen( $data );
    for ( $i = 0; $i < $len; $i++ ) {
        $crc ^= ( ord( $data[ $i ] ) << 8 );
        for ( $j = 0; $j < 8; $j++ ) {
            if ( $crc & 0x8000 ) {
                $crc = ( ( $crc << 1 ) ^ 0x1021 ) & 0xFFFF;
            } else {
                $crc = ( $crc << 1 ) & 0xFFFF;
            }
        }
    }
    return strtoupper( str_pad( dechex( $crc ), 4, '0', STR_PAD_LEFT ) );
}

/**
 * Susun satu objek TLV EMV.
 *
 * @param string $tag Tag 2 digit.
 * @param string $value Value.
 * @return string
 */
function qrg_qris_tlv( $tag, $value ) {
    $value = (string) $value;
    $tag   = str_pad( (string) $tag, 2, '0', STR_PAD_LEFT );
    return $tag . str_pad( (string) strlen( $value ), 2, '0', STR_PAD_LEFT ) . $value;
}

/**
 * Sisipkan / ganti nominal (tag 54) pada payload QRIS MPM, lalu hitung ulang CRC.
 *
 * @param string     $payload Payload master (000201…).
 * @param int|float  $amount  Nominal Rupiah.
 * @return string Payload baru, atau string kosong jika gagal.
 */
function qrg_qris_mpm_apply_amount( $payload, $amount ) {
    $payload = trim( (string) $payload );
    if ( $payload === '' || ! preg_match( '/^000201/', $payload ) ) {
        return '';
    }

    // Buang CRC lama.
    if ( preg_match( '/6304[0-9A-Fa-f]{4}$/', $payload ) ) {
        $payload = substr( $payload, 0, -8 );
    }

    $fields = qrg_parse_emv_mpm_fields( $payload );
    if ( empty( $fields['00'] ) ) {
        return '';
    }

    $amount = max( 0, (float) $amount );
    // Format QRIS IDR: dua desimal, titik sebagai pemisah.
    $amount_str = number_format( $amount, 2, '.', '' );

    $fields['01'] = '12'; // Dynamic QR
    $fields['54'] = $amount_str;
    if ( empty( $fields['53'] ) ) {
        $fields['53'] = '360';
    }
    unset( $fields['63'] );

    $order = [
        '00', '01',
        '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15',
        '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29',
        '30', '31', '32', '33', '34', '35', '36', '37', '38', '39', '40', '41', '42', '43',
        '44', '45', '46', '47', '48', '49', '50', '51',
        '52', '53', '54', '55', '56', '57', '58', '59', '60', '61', '62',
        '64', '65', '66', '67', '68', '69', '70', '71', '72', '73', '74', '75', '76', '77',
        '78', '79', '80', '81', '82', '83', '84', '85', '86', '87', '88', '89', '90', '91',
        '92', '93', '94', '95', '96', '97', '98', '99',
    ];

    $body = '';
    foreach ( $order as $tag ) {
        if ( ! isset( $fields[ $tag ] ) ) {
            continue;
        }
        $body .= qrg_qris_tlv( $tag, $fields[ $tag ] );
        unset( $fields[ $tag ] );
    }
    if ( ! empty( $fields ) ) {
        ksort( $fields );
        foreach ( $fields as $tag => $val ) {
            $body .= qrg_qris_tlv( $tag, $val );
        }
    }

    $body .= '6304';
    return $body . qrg_qris_crc16( $body );
}

/**
 * Payload master QR pembayaran (hasil baca / cache / bawaan).
 *
 * @param bool $force Paksa baca ulang gambar.
 * @return string
 */
function qrg_get_master_pembayaran_qr_payload( $force = false ) {
    if ( ! function_exists( 'qrg_get_active_pembayaran_qr_interpretation' ) ) {
        return qrg_get_default_pembayaran_qr_payload();
    }
    $interp = qrg_get_active_pembayaran_qr_interpretation( $force );
    $payload = isset( $interp['payload'] ) ? trim( (string) $interp['payload'] ) : '';
    return $payload !== '' ? $payload : qrg_get_default_pembayaran_qr_payload();
}

/**
 * Bangun URL gambar QR invoice (dinamis jika master = QRIS MPM).
 *
 * @param int|float $amount Nominal total tagihan.
 * @return array{url:string,fallback:string,payload:string,dynamic:bool,type:string,merchant:string}
 */
function qrg_build_invoice_pembayaran_qr( $amount ) {
    $amount  = max( 0, (float) $amount );
    $master  = qrg_get_master_pembayaran_qr_payload();
    $info    = qrg_interpret_pembayaran_qr_payload( $master );
    $type    = (string) ( $info['type'] ?? 'unknown' );
    $merchant = '';
    if ( ! empty( $info['summary'] ) && is_array( $info['summary'] ) ) {
        foreach ( $info['summary'] as $row ) {
            $label = (string) ( $row['label'] ?? '' );
            if ( in_array( $label, [ 'Nama Merchant', 'Nama / Merchant' ], true ) ) {
                $merchant = (string) ( $row['value'] ?? '' );
                break;
            }
        }
    }

    $out = [
        'url'      => '',
        'fallback' => '',
        'payload'  => $master,
        'dynamic'  => false,
        'type'     => $type,
        'merchant' => $merchant,
    ];

    if ( $type === 'qris_mpm' ) {
        $dynamic_payload = qrg_qris_mpm_apply_amount( $master, $amount );
        if ( $dynamic_payload !== '' ) {
            $enc              = rawurlencode( $dynamic_payload );
            $out['payload']   = $dynamic_payload;
            $out['dynamic']   = true;
            $out['url']       = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=M&margin=10&data=' . $enc;
            $out['fallback']  = 'https://quickchart.io/qr?size=300&margin=2&text=' . $enc;
            return $out;
        }
    }

    // CPM / non-MPM: tampilkan master apa adanya (gambar unggahan atau generate dari payload).
    $uploaded = qrg_get_pembayaran_qr_url( 'full' );
    if ( $uploaded !== '' ) {
        $out['url']      = $uploaded;
        $out['fallback'] = $uploaded;
        return $out;
    }

    $enc             = rawurlencode( $master );
    $out['url']      = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=M&margin=10&data=' . $enc;
    $out['fallback'] = 'https://quickchart.io/qr?size=300&margin=2&text=' . $enc;
    return $out;
}

/**
 * Proses unggah / hapus QR code pembayaran dari halaman Setting.
 *
 * @return array{error:string,success:string}
 */
function qrg_process_pembayaran_qr_upload() {
    $out = [ 'error' => '', 'success' => '' ];

    if ( ! current_user_can( 'manage_options' ) ) {
        return $out;
    }

    $doing_upload = isset( $_POST['qrg_pembayaran_qr_upload'] );
    $doing_remove = isset( $_POST['qrg_pembayaran_qr_remove'] );
    if ( ! $doing_upload && ! $doing_remove ) {
        return $out;
    }

    if ( ! isset( $_POST['qrg_pembayaran_qr_nonce'] )
        || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_pembayaran_qr_nonce'] ) ), 'qrg_pembayaran_qr_manage' ) ) {
        $out['error'] = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
        return $out;
    }

    if ( $doing_remove ) {
        $old = qrg_get_pembayaran_qr_attachment_id();
        delete_option( 'qrg_pembayaran_qr_attachment_id' );
        delete_option( 'qrg_pembayaran_qr_decode_cache' );
        if ( $old > 0 ) {
            // Hapus hanya jika attachment khusus QR (tanpa parent post).
            $parent = (int) wp_get_post_parent_id( $old );
            if ( $parent === 0 ) {
                wp_delete_attachment( $old, true );
            }
        }
        $out['success'] = 'QR code pembayaran dihapus. Invoice akan memakai QR bawaan lagi.';
        return $out;
    }

    if ( empty( $_FILES['qrg_pembayaran_qr']['name'] ) ) {
        $out['error'] = 'Pilih file QR code terlebih dahulu.';
        return $out;
    }

    $file = $_FILES['qrg_pembayaran_qr'];
    if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
        $out['error'] = 'Unggah gagal. Coba file gambar lain.';
        return $out;
    }
    if ( ! empty( $file['size'] ) && (int) $file['size'] > ( 5 * 1024 * 1024 ) ) {
        $out['error'] = 'Ukuran file maksimal 5 MB.';
        return $out;
    }
    if ( ! function_exists( 'qrg_handle_upload_safe' ) || ! function_exists( 'qrg_upload_mimes_bukti' ) ) {
        $out['error'] = 'Fitur unggah tidak tersedia.';
        return $out;
    }

    $movefile = qrg_handle_upload_safe( $file, qrg_upload_mimes_bukti() );
    if ( ! is_array( $movefile ) || ! empty( $movefile['error'] ) || empty( $movefile['file'] ) ) {
        $out['error'] = is_array( $movefile ) && ! empty( $movefile['error'] )
            ? (string) $movefile['error']
            : 'Gagal mengunggah QR code.';
        return $out;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attachment = [
        'post_mime_type' => isset( $movefile['type'] ) ? $movefile['type'] : 'image/jpeg',
        'post_title'     => 'QR Code Pembayaran Sekolah',
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];
    $attach_id = wp_insert_attachment( $attachment, $movefile['file'] );
    if ( is_wp_error( $attach_id ) || ! $attach_id ) {
        if ( ! empty( $movefile['file'] ) && file_exists( $movefile['file'] ) ) {
            @unlink( $movefile['file'] );
        }
        $out['error'] = 'Gagal menyimpan QR code.';
        return $out;
    }

    $meta = wp_generate_attachment_metadata( $attach_id, $movefile['file'] );
    if ( is_array( $meta ) ) {
        wp_update_attachment_metadata( $attach_id, $meta );
    }

    $old = qrg_get_pembayaran_qr_attachment_id();
    update_option( 'qrg_pembayaran_qr_attachment_id', (int) $attach_id, false );
    delete_option( 'qrg_pembayaran_qr_decode_cache' );
    if ( $old > 0 && $old !== (int) $attach_id && (int) wp_get_post_parent_id( $old ) === 0 ) {
        wp_delete_attachment( $old, true );
    }

    $out['success'] = 'QR code pembayaran berhasil diunggah. Gambar ini akan tampil di halaman invoice pembayaran siswa.';
    return $out;
}

// Daftar shortcode yang tersedia beserta dokumentasinya
function qrg_bantuan_shortcodes() {
    return [
        [
            'kode'       => '[form_pendaftaran_psb]',
            'judul'      => 'Form Pendaftaran Siswa Baru (PSB)',
            'deskripsi'  => 'Menampilkan formulir pendaftaran peserta didik baru di halaman/postingan publik. Data yang masuk otomatis tersimpan ke menu <strong>PSB</strong> dengan status "Menunggu".',
            'cara'       => [
                'Buat halaman baru melalui menu <strong>Halaman → Tambah Baru</strong>.',
                'Beri judul, misalnya "Pendaftaran Siswa Baru".',
                'Tempelkan shortcode <code>[form_pendaftaran_psb]</code> pada isi halaman.',
                'Terbitkan (Publish) halaman, lalu buka halaman tersebut untuk melihat formulirnya.',
                'Atur tampilan & field form melalui menu <strong>Setting Form PSB</strong>.',
            ],
        ],
        [
            'kode'       => '[qr_checkout]',
            'judul'      => 'Halaman Tagihan / Pembayaran Siswa',
            'deskripsi'  => 'Menampilkan form login pembayaran. Wali murid login dengan <strong>Google (email siswa)</strong> atau <strong>NIS</strong>, lalu baru bisa melihat daftar pembayaran siswa tersebut.',
            'cara'       => [
                'Pastikan setiap siswa sudah memiliki <strong>Email</strong> (akun Google) dan/atau <strong>NIS</strong> di Data Siswa.',
                'Isi <strong>Google Client ID</strong> di <strong>Sekolah → Setting → Login Google</strong> (dari Google Cloud Console).',
                'Portal utama siswa: buka <code>/sekolah/</code> (login sekali, lalu pilih Pembayaran, Pembelajaran, atau Perpustakaan).',
                'Atau buat halaman dengan shortcode <code>[qr_checkout]</code>.',
                'Login dengan Google: email akun harus sama dengan email siswa. Alternatif: NIS + password/PIN.',
                'Setelah berhasil, daftar pembayaran muncul beserta tombol bayar.',
                'Unggah <strong>QRIS MPM merchant</strong> di <strong>Sekolah → Setting</strong> agar invoice menampilkan QRIS dengan <strong>nominal otomatis</strong> sesuai tagihan.',
                'Integrasi gateway eksternal: aktifkan API di <strong>Sekolah → Setting</strong> (API Key, webhook lunas).',
            ],
        ],
        [
            'kode'       => '[portal_pembelajaran]',
            'judul'      => 'Portal Pembelajaran Siswa',
            'deskripsi'  => 'Portal siswa untuk melihat tugas dari guru, mengumpulkan jawaban (teks/file), dan melihat nilai. Login sama seperti pembayaran: <strong>Google</strong> atau <strong>NIS + PIN</strong>.',
            'cara'       => [
                'Guru/admin membuat tugas di halaman <strong>Mata Pelajaran → Daftar Tugas</strong> (pilih guru, kelas, mata pelajaran, deadline).',
                'Atau guru login di portal <code>/sekolah/guru/</code> → <strong>Jadwal &amp; Tugas</strong> (beri tugas dari slot mapel).',
                'Pastikan siswa punya <strong>Email</strong> (untuk Google) dan/atau <strong>NIS</strong> di Data Siswa. Client ID Google di <strong>Sekolah → Setting → Login Google</strong>.',
                'Portal utama siswa: <code>/sekolah/</code> → setelah login pilih <strong>Pembayaran</strong> atau kerjakan tugas dari jadwal.',
                'Atau buat halaman dengan shortcode <code>[portal_pembelajaran]</code>.',
                'URL lama <code>/master_siswa/nama-siswa/pembayaran/</code> dan <code>.../pembelajaran/</code> dialihkan ke portal <code>/sekolah/</code>.',
                'Guru memantau & menilai di halaman edit tugas (Monitoring) atau menu <strong>Hasil Pengerjaan</strong>.',
            ],
        ],
        [
            'kode'       => '/sekolah/guru/',
            'judul'      => 'Portal Guru',
            'deskripsi'  => 'Portal mandiri untuk guru (bagian dari /sekolah/): login NIP + No. Telepon, jadwal &amp; tugas, slip gaji, dan presensi. URL lama <code>/guru/</code> dialihkan otomatis.',
            'cara'       => [
                'Pastikan setiap guru punya <strong>NIP</strong> dan <strong>No. Telepon</strong> di Data Guru.',
                'Buka <code>/sekolah/guru/</code> — username = NIP, password = No. Telepon.',
                'Setelah masuk, pilih <strong>Jadwal &amp; Tugas</strong> / Gaji / Presensi.',
            ],
        ],
        [
            'kode'       => '/sekolah/buku/',
            'judul'      => 'Perpustakaan Digital',
            'deskripsi'  => 'Koleksi buku/dokumen PDF. <strong>Hanya bisa diakses setelah login</strong> di portal siswa <code>/sekolah/</code>. Admin mengunggah file di menu <strong>Sekolah → Perpustakaan</strong>.',
            'cara'       => [
                'Buka <strong>Sekolah → Perpustakaan → Tambah PDF</strong> (admin).',
                'Isi judul, kategori, deskripsi, lalu unggah file <strong>PDF</strong>.',
                'Set status <strong>Aktif</strong>.',
                'Siswa login di <code>/sekolah/</code>, lalu buka menu <strong>Perpustakaan</strong> (atau langsung <code>/sekolah/buku/</code> setelah login).',
                'Jika belum login, <code>/sekolah/buku/</code> otomatis dialihkan ke halaman login portal.',
                'URL lama <code>/perpustakaan/</code> dialihkan otomatis ke <code>/sekolah/buku/</code>.',
            ],
        ],
    ];
}

function qrg_handle_pembayaran_qr_admin_post() {
    $msg = qrg_process_pembayaran_qr_upload();
    if ( $msg['success'] !== '' || $msg['error'] !== '' ) {
        $redir = admin_url( 'admin.php?page=qrg-setting&qris=1' );
        if ( $msg['success'] !== '' ) {
            $redir = add_query_arg( 'qr', isset( $_POST['qrg_pembayaran_qr_remove'] ) ? 'removed' : 'ok', $redir );
        } else {
            $redir = add_query_arg( 'qr_err', rawurlencode( $msg['error'] ), $redir );
        }
        wp_safe_redirect( $redir );
        exit;
    }
}

/**
 * Panel admin QR Code Pembayaran (halaman Setting).
 */
function qrg_render_pembayaran_qr_admin_panel() {
    $notice_ok  = '';
    $notice_err = '';
    if ( isset( $_GET['qr'] ) ) {
        $flag = sanitize_key( wp_unslash( $_GET['qr'] ) );
        if ( $flag === 'ok' ) {
            $notice_ok = 'QR code pembayaran berhasil diunggah.';
        } elseif ( $flag === 'removed' ) {
            $notice_ok = 'QR code pembayaran dihapus.';
        }
    }
    if ( isset( $_GET['qr_err'] ) ) {
        $notice_err = sanitize_text_field( wp_unslash( $_GET['qr_err'] ) );
    }

    $qr_url     = qrg_get_pembayaran_qr_url( 'medium' );
    $qr_full    = qrg_get_pembayaran_qr_url( 'full' );
    $force_read = isset( $_GET['qr_reread'] ) && sanitize_key( wp_unslash( $_GET['qr_reread'] ) ) === '1';
    $qr_decode  = qrg_get_active_pembayaran_qr_interpretation( $force_read );
    $reread_url = admin_url( 'admin.php?page=qrg-setting&qris=1&qr_reread=1#qrg-qr-decode' );
    if ( ! $qr_full ) {
        $qr_full = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . rawurlencode( qrg_get_default_pembayaran_qr_payload() );
    }

    $sim_raw = isset( $_GET['qr_sim'] ) ? wp_unslash( $_GET['qr_sim'] ) : '50000';
    $sim_amount = (int) preg_replace( '/\D+/', '', (string) $sim_raw );
    if ( $sim_amount <= 0 ) {
        $sim_amount = 50000;
    }
    if ( $sim_amount > 999999999 ) {
        $sim_amount = 999999999;
    }
    $sim_qr       = qrg_build_invoice_pembayaran_qr( $sim_amount );
    $sim_dynamic  = ! empty( $sim_qr['dynamic'] );
    $sim_merchant = (string) ( $sim_qr['merchant'] ?? '' );
    $sim_payload  = (string) ( $sim_qr['payload'] ?? '' );
    $sim_img      = (string) ( $sim_qr['url'] ?? '' );
    $sim_fallback = (string) ( $sim_qr['fallback'] ?? $sim_img );
    $sim_type     = (string) ( $sim_qr['type'] ?? '' );
    $master_payload_for_js = qrg_get_master_pembayaran_qr_payload();
    ?>
        <?php if ( $notice_ok ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice_ok ); ?></p></div>
        <?php endif; ?>
        <?php if ( $notice_err ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $notice_err ); ?></p></div>
        <?php endif; ?>

        <style>
            .qrg-bantuan-qr {
                background: #fff;
                border: 1px solid #dcdcde;
                border-left: 4px solid #00a32a;
                border-radius: 10px;
                padding: 22px 24px;
                margin: 20px 0;
                max-width: 820px;
                animation: qrgBantuanCardIn .45s cubic-bezier(.2,.8,.2,1) both;
            }
            @keyframes qrgBantuanCardIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: none; }
            }
            .qrg-bantuan-qr__head {
                display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px;
            }
            .qrg-bantuan-qr__badge {
                width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
                background: linear-gradient(145deg, #4ade80, #16a34a);
                color: #fff; display: inline-flex; align-items: center; justify-content: center;
                box-shadow: 0 8px 18px rgba(22,163,74,.25);
                animation: qrgBantuanBadgePop .5s cubic-bezier(.2,.8,.2,1) .05s both;
            }
            .qrg-bantuan-qr__badge .dashicons { font-size: 22px; width: 22px; height: 22px; }
            @keyframes qrgBantuanBadgePop {
                from { opacity: 0; transform: scale(.7); }
                to { opacity: 1; transform: scale(1); }
            }
            .qrg-bantuan-qr h2 { margin: 0 0 4px; font-size: 18px; }
            .qrg-bantuan-qr__desc { margin: 0; color: #50575e; line-height: 1.5; }
            .qrg-bantuan-qr__layout {
                display: grid; grid-template-columns: 220px 1fr; gap: 20px; align-items: start;
            }
            @media (max-width: 720px) {
                .qrg-bantuan-qr__layout { grid-template-columns: 1fr; }
            }
            .qrg-bantuan-qr__preview-card {
                background: linear-gradient(180deg, #f8fafc, #f1f5f9);
                border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px; text-align: center;
                animation: qrgBantuanFadeUp .5s ease .1s both;
            }
            .qrg-bantuan-qr__preview-card img {
                max-width: 100%; width: 190px; height: auto; display: block; margin: 0 auto;
                border-radius: 10px; background: #fff; border: 1px solid #e2e8f0; padding: 8px;
                transition: transform .25s ease, box-shadow .25s ease;
            }
            .qrg-bantuan-qr__preview-card:hover img {
                transform: scale(1.03); box-shadow: 0 10px 24px rgba(15,23,42,.12);
            }
            .qrg-bantuan-qr__preview-label {
                margin: 10px 0 0; font-size: 12px; color: #646970; font-weight: 600;
            }
            .qrg-bantuan-qr__empty {
                background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px;
                padding: 12px 14px; margin: 0 0 14px; color: #785b0b; font-size: 13px;
                animation: qrgBantuanFadeUp .4s ease both;
            }
            @keyframes qrgBantuanFadeUp {
                from { opacity: 0; transform: translateY(8px); }
                to { opacity: 1; transform: none; }
            }
            .qrg-qr-up {
                background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
                border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px;
                animation: qrgBantuanFadeUp .5s ease .12s both;
            }
            .qrg-qr-up__title { margin: 0 0 4px; font-size: 14px; font-weight: 700; color: #1d2327; }
            .qrg-qr-up__hint { margin: 0 0 12px; font-size: 12px; color: #646970; line-height: 1.4; }
            .qrg-qr-up__file { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; clip: rect(0,0,0,0); }
            .qrg-qr-up__drop {
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                gap: 8px; min-height: 150px; padding: 18px 14px; cursor: pointer;
                border: 2px dashed #94a3b8; border-radius: 14px; background: #fff;
                transition: border-color .2s, background .2s, transform .2s, box-shadow .2s;
                text-align: center; position: relative; overflow: hidden;
            }
            .qrg-qr-up__drop::before {
                content: ""; position: absolute; inset: -40% auto auto -20%;
                width: 160px; height: 160px; border-radius: 50%;
                background: radial-gradient(circle, rgba(34,197,94,.14), transparent 70%);
                pointer-events: none; transition: transform .35s ease;
            }
            .qrg-qr-up__drop:hover {
                border-color: #22c55e; background: #f0fdf4;
                box-shadow: 0 8px 24px rgba(34,197,94,.12);
            }
            .qrg-qr-up__drop:hover::before { transform: scale(1.4); }
            .qrg-qr-up__drop.is-drag {
                border-color: #16a34a; background: #ecfdf5; transform: scale(.99);
                box-shadow: inset 0 0 0 2px rgba(22,163,74,.15);
            }
            .qrg-qr-up__drop.has-file {
                border-style: solid; border-color: #86efac; background: #f0fdf4;
            }
            .qrg-qr-up__ico {
                width: 52px; height: 52px; border-radius: 16px;
                background: linear-gradient(145deg, #bbf7d0, #4ade80);
                color: #14532d;
                display: flex; align-items: center; justify-content: center;
                font-size: 22px; position: relative; z-index: 1;
                animation: qrgQrIcoFloat 2.4s ease-in-out infinite;
                box-shadow: 0 8px 18px rgba(34,197,94,.22);
            }
            @keyframes qrgQrIcoFloat {
                0%, 100% { transform: translateY(0); }
                50% { transform: translateY(-5px); }
            }
            .qrg-qr-up__drop-title { font-size: 14px; font-weight: 700; color: #0f172a; position: relative; z-index: 1; }
            .qrg-qr-up__drop-sub { font-size: 12px; color: #64748b; position: relative; z-index: 1; }
            .qrg-qr-up__preview {
                display: none; margin-top: 12px; border-radius: 12px; overflow: hidden;
                border: 1px solid #e2e8f0; background: #fff;
                opacity: 0; transform: translateY(8px);
                transition: opacity .28s ease, transform .28s ease;
            }
            .qrg-qr-up__preview.is-on {
                display: block; opacity: 1; transform: none;
                animation: qrgBantuanFadeUp .35s ease both;
            }
            .qrg-qr-up__preview img {
                display: block; width: 100%; max-height: 220px; object-fit: contain; background: #0f172a;
            }
            .qrg-qr-up__meta {
                display: flex; justify-content: space-between; align-items: center; gap: 8px;
                padding: 10px 12px; font-size: 12px; color: #475569;
            }
            .qrg-qr-up__meta strong { color: #0f172a; word-break: break-all; }
            .qrg-qr-up__change {
                appearance: none; border: 0; background: none; color: #2271b1;
                font-size: 12px; font-weight: 700; cursor: pointer; padding: 6px 0;
            }
            .qrg-qr-up__actions {
                display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 14px;
            }
            .qrg-qr-up__submit {
                appearance: none; border: 0; min-height: 40px; padding: 0 16px;
                border-radius: 8px; background: #16a34a; color: #fff;
                font-size: 13px; font-weight: 700; cursor: pointer;
                display: inline-flex; align-items: center; gap: 6px;
                transition: transform .15s ease, box-shadow .15s ease, opacity .15s, background .15s;
                box-shadow: 0 6px 14px rgba(22,163,74,.25);
            }
            .qrg-qr-up__submit:hover { background: #15803d; }
            .qrg-qr-up__submit:active { transform: scale(.97); }
            .qrg-qr-up__submit:disabled { opacity: .5; cursor: not-allowed; box-shadow: none; }
            .qrg-qr-up__submit.is-ready {
                background: #15803d;
                animation: qrgQrReadyPulse 1.6s ease-in-out infinite;
            }
            @keyframes qrgQrReadyPulse {
                0%, 100% { box-shadow: 0 6px 14px rgba(22,163,74,.25); }
                50% { box-shadow: 0 8px 22px rgba(22,163,74,.4); }
            }
            .qrg-qr-up__remove {
                appearance: none; border: 0; background: none; color: #b32d2e;
                font-size: 13px; font-weight: 600; cursor: pointer; min-height: 36px; padding: 0 4px;
            }
            .qrg-qr-up__remove:hover { text-decoration: underline; }

            .qrg-qr-decode {
                margin-top: 18px;
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                overflow: hidden;
                box-shadow: 0 1px 2px rgba(15,23,42,.04);
                animation: qrgBantuanFadeUp .45s ease .18s both;
            }
            .qrg-qr-decode__head {
                display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap;
                padding: 16px 18px;
                background: linear-gradient(135deg, #f0f9ff 0%, #f8fafc 55%, #fff 100%);
                border-bottom: 1px solid #e2e8f0;
            }
            .qrg-qr-decode__title-wrap {
                display: flex; align-items: flex-start; gap: 12px; min-width: 0; flex: 1 1 220px;
            }
            .qrg-qr-decode__icon {
                flex: 0 0 auto; width: 40px; height: 40px; border-radius: 12px;
                background: linear-gradient(145deg, #bae6fd, #38bdf8);
                color: #0c4a6e;
                display: flex; align-items: center; justify-content: center;
                box-shadow: 0 6px 14px rgba(14,165,233,.2);
            }
            .qrg-qr-decode__icon .dashicons { width: 20px; height: 20px; font-size: 20px; }
            .qrg-qr-decode__head h3 {
                margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0f172a; line-height: 1.25;
            }
            .qrg-qr-decode__sub {
                margin: 0; font-size: 12px; color: #64748b; line-height: 1.45; max-width: 42ch;
            }
            .qrg-qr-decode__actions {
                display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            }
            .qrg-qr-decode__badge {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 5px 11px; border-radius: 999px; font-size: 11px; font-weight: 700;
                background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;
                max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .qrg-qr-decode__badge.is-warn { background: #fffbeb; color: #92400e; border-color: #fde68a; }
            .qrg-qr-decode__badge.is-err { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
            .qrg-qr-decode__reread {
                display: inline-flex; align-items: center; gap: 5px;
                appearance: none; text-decoration: none !important;
                min-height: 32px; padding: 0 12px; border-radius: 8px;
                border: 1px solid #cbd5e1; background: #fff; color: #334155;
                font-size: 12px; font-weight: 700; line-height: 1;
                transition: background .15s, border-color .15s, color .15s;
            }
            .qrg-qr-decode__reread:hover { background: #f8fafc; border-color: #94a3b8; color: #0f172a; }
            .qrg-qr-decode__reread .dashicons { width: 14px; height: 14px; font-size: 14px; }
            .qrg-qr-decode__status {
                margin: 0; padding: 12px 18px; font-size: 13px; line-height: 1.5; color: #64748b;
                background: #f8fafc; border-bottom: 1px solid #e2e8f0;
            }
            .qrg-qr-decode__status.is-err { background: #fef2f2; color: #991b1b; border-bottom-color: #fecaca; }
            .qrg-qr-decode__body { padding: 16px 18px 18px; }
            .qrg-qr-decode__facts {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 10px;
                margin: 0 0 14px;
            }
            .qrg-qr-decode__fact {
                background: linear-gradient(180deg, #f8fafc, #fff);
                border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px;
                min-width: 0;
            }
            .qrg-qr-decode__fact-label {
                display: block; margin: 0 0 4px; font-size: 11px; font-weight: 700;
                letter-spacing: .04em; text-transform: uppercase; color: #64748b;
            }
            .qrg-qr-decode__fact-value {
                display: block; font-size: 13px; font-weight: 700; color: #0f172a;
                line-height: 1.35; word-break: break-word;
            }
            .qrg-qr-decode__section {
                border: 1px solid #e2e8f0; border-radius: 12px; background: #fff;
                margin-top: 10px; overflow: hidden;
            }
            .qrg-qr-decode__section-head {
                display: flex; align-items: center; justify-content: space-between; gap: 10px;
                padding: 10px 12px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
            }
            .qrg-qr-decode__section-head h4 {
                margin: 0; font-size: 12px; font-weight: 700; color: #334155;
                display: flex; align-items: center; gap: 6px;
            }
            .qrg-qr-decode__section-head h4 .dashicons {
                width: 16px; height: 16px; font-size: 16px; color: #64748b;
            }
            .qrg-qr-decode__copy {
                appearance: none; border: 1px solid #cbd5e1; background: #fff;
                color: #475569; border-radius: 7px; min-height: 28px; padding: 0 10px;
                font-size: 11px; font-weight: 700; cursor: pointer;
                display: inline-flex; align-items: center; gap: 4px;
                transition: background .15s, border-color .15s, color .15s;
            }
            .qrg-qr-decode__copy:hover { background: #f1f5f9; color: #0f172a; }
            .qrg-qr-decode__copy.is-done { background: #dcfce7; border-color: #86efac; color: #166534; }
            .qrg-qr-decode__section-body { padding: 12px; }
            .qrg-qr-decode__hint {
                margin: 0 0 10px; font-size: 12px; color: #64748b; line-height: 1.45;
            }
            .qrg-qr-decode__tabs {
                display: inline-flex; flex-wrap: wrap; gap: 4px; padding: 3px;
                background: #f1f5f9; border-radius: 10px; margin-bottom: 10px;
            }
            .qrg-qr-decode__tab {
                appearance: none; border: 0; background: transparent; color: #64748b;
                min-height: 30px; padding: 0 12px; border-radius: 8px;
                font-size: 12px; font-weight: 700; cursor: pointer;
                transition: background .15s, color .15s, box-shadow .15s;
            }
            .qrg-qr-decode__tab.is-on {
                background: #fff; color: #0f172a;
                box-shadow: 0 1px 2px rgba(15,23,42,.08);
            }
            .qrg-qr-decode__pane { display: none; }
            .qrg-qr-decode__pane.is-on { display: block; }
            .qrg-qr-decode__mono {
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 11.5px; line-height: 1.5; word-break: break-all;
                background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: 12px 14px;
                max-height: 132px; overflow: auto; margin: 0;
                border: 1px solid #1e293b;
            }
            .qrg-qr-decode__mono.is-soft {
                background: #f8fafc; color: #334155; border-color: #e2e8f0;
            }
            .qrg-qr-decode__chips { display: flex; flex-wrap: wrap; gap: 6px; }
            .qrg-qr-decode__chip {
                background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;
                border-radius: 8px; padding: 5px 10px; font-size: 11px; font-weight: 700;
                word-break: break-word; max-width: 100%;
            }
            @media (max-width: 600px) {
                .qrg-qr-decode__head { padding: 14px; }
                .qrg-qr-decode__body { padding: 14px; }
                .qrg-qr-decode__facts { grid-template-columns: 1fr 1fr; }
            }

            .qrg-qr-sim {
                margin-top: 18px;
                background: #fff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                overflow: hidden;
                box-shadow: 0 1px 2px rgba(15,23,42,.04);
                animation: qrgBantuanFadeUp .45s ease .24s both;
            }
            .qrg-qr-sim__head {
                display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap;
                padding: 16px 18px;
                background: linear-gradient(135deg, #ecfdf5 0%, #f8fafc 55%, #fff 100%);
                border-bottom: 1px solid #e2e8f0;
            }
            .qrg-qr-sim__title-wrap {
                display: flex; align-items: flex-start; gap: 12px; min-width: 0; flex: 1 1 220px;
            }
            .qrg-qr-sim__icon {
                flex: 0 0 auto; width: 40px; height: 40px; border-radius: 12px;
                background: linear-gradient(145deg, #bbf7d0, #4ade80);
                color: #14532d;
                display: flex; align-items: center; justify-content: center;
                box-shadow: 0 6px 14px rgba(34,197,94,.2);
            }
            .qrg-qr-sim__icon .dashicons { width: 20px; height: 20px; font-size: 20px; }
            .qrg-qr-sim__head h3 {
                margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #0f172a; line-height: 1.25;
            }
            .qrg-qr-sim__sub {
                margin: 0; font-size: 12px; color: #64748b; line-height: 1.45; max-width: 48ch;
            }
            .qrg-qr-sim__badge {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 5px 11px; border-radius: 999px; font-size: 11px; font-weight: 700;
                background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;
            }
            .qrg-qr-sim__badge.is-off {
                background: #fff7ed; color: #9a3412; border-color: #fed7aa;
            }
            .qrg-qr-sim__body { padding: 16px 18px 18px; }
            .qrg-qr-sim__form {
                display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;
                margin-bottom: 14px;
            }
            .qrg-qr-sim__field { flex: 1 1 220px; min-width: 0; }
            .qrg-qr-sim__field label {
                display: block; margin: 0 0 6px; font-size: 12px; font-weight: 700; color: #475569;
            }
            .qrg-qr-sim__input-wrap {
                display: flex; align-items: center; gap: 0;
                border: 1px solid #cbd5e1; border-radius: 10px; background: #fff;
                overflow: hidden; transition: border-color .15s, box-shadow .15s;
            }
            .qrg-qr-sim__input-wrap:focus-within {
                border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.15);
            }
            .qrg-qr-sim__prefix {
                padding: 0 12px; font-size: 13px; font-weight: 700; color: #64748b;
                background: #f8fafc; border-right: 1px solid #e2e8f0; min-height: 42px;
                display: inline-flex; align-items: center;
            }
            .qrg-qr-sim__input {
                flex: 1; min-width: 0; border: 0 !important; box-shadow: none !important;
                margin: 0 !important; min-height: 42px; padding: 0 12px !important;
                font-size: 16px; font-weight: 700; color: #0f172a; background: transparent;
            }
            .qrg-qr-sim__presets { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
            .qrg-qr-sim__preset {
                appearance: none; border: 1px solid #cbd5e1; background: #fff; color: #334155;
                border-radius: 999px; min-height: 28px; padding: 0 10px;
                font-size: 11px; font-weight: 700; cursor: pointer;
            }
            .qrg-qr-sim__preset:hover { background: #f1f5f9; }
            .qrg-qr-sim__preset.is-on { background: #dcfce7; border-color: #86efac; color: #166534; }
            .qrg-qr-sim__btn {
                appearance: none; border: 0; min-height: 42px; padding: 0 16px; border-radius: 10px;
                background: #16a34a; color: #fff; font-size: 13px; font-weight: 700; cursor: pointer;
                display: inline-flex; align-items: center; gap: 6px;
                box-shadow: 0 6px 14px rgba(22,163,74,.22);
            }
            .qrg-qr-sim__btn:hover { background: #15803d; }
            .qrg-qr-sim__btn .dashicons { width: 16px; height: 16px; font-size: 16px; }
            .qrg-qr-sim__layout {
                display: grid; grid-template-columns: 200px 1fr; gap: 16px; align-items: start;
            }
            @media (max-width: 720px) {
                .qrg-qr-sim__layout { grid-template-columns: 1fr; }
            }
            .qrg-qr-sim__preview {
                background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px;
                padding: 14px; text-align: center;
            }
            .qrg-qr-sim__preview img {
                display: block; width: 100%; max-width: 180px; margin: 0 auto 10px;
                background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px;
            }
            .qrg-qr-sim__preview-label {
                margin: 0; font-size: 12px; font-weight: 700; color: #166534;
            }
            .qrg-qr-sim__preview-label.is-off { color: #9a3412; }
            .qrg-qr-sim__meta {
                display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px;
                margin-bottom: 10px;
            }
            .qrg-qr-sim__meta-item {
                background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px;
            }
            .qrg-qr-sim__meta-item span {
                display: block; font-size: 11px; font-weight: 700; color: #64748b;
                text-transform: uppercase; letter-spacing: .04em; margin-bottom: 3px;
            }
            .qrg-qr-sim__meta-item strong {
                display: block; font-size: 13px; color: #0f172a; word-break: break-word;
            }
            .qrg-qr-sim__warn {
                margin: 0 0 10px; padding: 10px 12px; border-radius: 10px;
                background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 12px; line-height: 1.45;
            }
            .qrg-qr-sim__ok {
                margin: 0 0 10px; padding: 10px 12px; border-radius: 10px;
                background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; font-size: 12px; line-height: 1.45;
            }
            .qrg-qr-sim .qrg-qr-decode__mono { max-height: 100px; }
            .qrg-qr-load {
                position: fixed; inset: 0; z-index: 100000;
                display: flex; align-items: center; justify-content: center;
                background: rgba(15, 23, 42, .42);
                backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
                opacity: 0; visibility: hidden; pointer-events: none;
                transition: opacity .22s ease, visibility .22s ease;
                padding: 20px;
            }
            .qrg-qr-load.is-on { opacity: 1; visibility: visible; pointer-events: auto; }
            .qrg-qr-load__card {
                width: min(360px, 100%); background: #fff; border-radius: 18px;
                padding: 24px 20px 20px; text-align: center;
                box-shadow: 0 18px 48px rgba(15,23,42,.22);
                transform: translateY(18px) scale(.96);
                transition: transform .3s cubic-bezier(.22,1,.36,1);
            }
            .qrg-qr-load.is-on .qrg-qr-load__card { transform: none; }
            .qrg-qr-load__orb {
                width: 64px; height: 64px; margin: 0 auto 14px; border-radius: 50%;
                background: linear-gradient(145deg, #bbf7d0, #22c55e);
                display: flex; align-items: center; justify-content: center;
                position: relative; animation: qrgQrOrbPulse 1.2s ease-in-out infinite;
            }
            .qrg-qr-load__orb::after {
                content: ""; position: absolute; inset: -6px; border-radius: 50%;
                border: 2px solid rgba(34,197,94,.35);
                animation: qrgQrRing 1.2s linear infinite;
            }
            @keyframes qrgQrOrbPulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.06); }
            }
            @keyframes qrgQrRing {
                from { transform: scale(.9); opacity: 1; }
                to { transform: scale(1.25); opacity: 0; }
            }
            .qrg-qr-load__orb .dashicons { color: #14532d; font-size: 28px; width: 28px; height: 28px; }
            .qrg-qr-load__title { margin: 0 0 6px; font-size: 16px; font-weight: 700; color: #0f172a; }
            .qrg-qr-load__tip { margin: 0 0 14px; font-size: 12px; color: #64748b; }
            .qrg-qr-load__bar {
                height: 8px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-bottom: 8px;
            }
            .qrg-qr-load__bar > span {
                display: block; height: 100%; width: 0%;
                background: linear-gradient(90deg, #4ade80, #16a34a, #4ade80);
                background-size: 200% 100%;
                border-radius: inherit;
                animation: qrgQrBarShine 1s linear infinite;
                transition: width .2s ease;
            }
            @keyframes qrgQrBarShine {
                from { background-position: 0% 0; }
                to { background-position: 200% 0; }
            }
            .qrg-qr-load__pct { font-size: 12px; font-weight: 700; color: #16a34a; }
            @media (prefers-reduced-motion: reduce) {
                .qrg-bantuan-qr,
                .qrg-bantuan-qr__badge,
                .qrg-bantuan-qr__preview-card,
                .qrg-bantuan-qr__empty,
                .qrg-qr-up,
                .qrg-qr-up__ico,
                .qrg-qr-up__submit.is-ready,
                .qrg-qr-decode,
                .qrg-qr-sim,
                .qrg-qr-load__orb,
                .qrg-qr-load__orb::after,
                .qrg-qr-load__bar > span {
                    animation: none !important;
                }
            }
        </style>

        <div class="qrg-bantuan-qr">
            <div class="qrg-bantuan-qr__head">
                <div class="qrg-bantuan-qr__badge" aria-hidden="true"><span class="dashicons dashicons-money-alt"></span></div>
                <div>
                    <h2>QR Code Pembayaran</h2>
                    <p class="qrg-bantuan-qr__desc">
                        Unggah gambar <strong>QRIS MPM merchant</strong> (biasanya diawali teks <code>000201</code>) dari aplikasi kasir / penyedia QRIS.
                        Sistem akan memakai QR ini sebagai master, lalu di halaman invoice siswa nominal diisi otomatis sesuai tagihan.
                        Jika yang diunggah adalah QR CPM / QR pribadi (Base64), invoice tetap menampilkan QR tersebut tanpa nominal otomatis.
                    </p>
                </div>
            </div>

            <div class="qrg-bantuan-qr__layout<?php echo $qr_url ? '' : ' is-empty'; ?>">
                <?php if ( $qr_url ) : ?>
                    <div class="qrg-bantuan-qr__preview-card">
                        <img src="<?php echo esc_url( $qr_url ); ?>" alt="QR Code Pembayaran aktif">
                        <p class="qrg-bantuan-qr__preview-label">QR aktif saat ini</p>
                    </div>
                <?php else : ?>
                    <div class="qrg-bantuan-qr__empty" style="grid-column:1/-1;">
                        Belum ada QR yang diunggah. Invoice saat ini memakai QR bawaan sistem.
                    </div>
                <?php endif; ?>

                <div class="qrg-qr-up"<?php echo $qr_url ? '' : ' style="grid-column:1/-1;"'; ?>>
                    <p class="qrg-qr-up__title"><?php echo $qr_url ? 'Ganti QR code' : 'Unggah QR code'; ?></p>
                    <p class="qrg-qr-up__hint">Seret &amp; lepas gambar ke kotak, atau ketuk untuk memilih file. Format JPG/PNG/WebP/GIF, maks. 5 MB.</p>

                    <form method="post" enctype="multipart/form-data" id="qrg-qr-up-form" novalidate>
                        <?php wp_nonce_field( 'qrg_pembayaran_qr_manage', 'qrg_pembayaran_qr_nonce' ); ?>
                        <input type="hidden" name="qrg_pembayaran_qr_upload" value="1">
                        <input type="file" class="qrg-qr-up__file" name="qrg_pembayaran_qr" id="qrg-qr-up-file" accept="image/jpeg,image/png,image/webp,image/gif" required>

                        <label class="qrg-qr-up__drop" id="qrg-qr-up-drop" for="qrg-qr-up-file">
                            <span class="qrg-qr-up__ico" aria-hidden="true">📷</span>
                            <span class="qrg-qr-up__drop-title" id="qrg-qr-up-drop-title">Seret gambar ke sini</span>
                            <span class="qrg-qr-up__drop-sub">atau ketuk untuk pilih dari komputer</span>
                        </label>

                        <div class="qrg-qr-up__preview" id="qrg-qr-up-preview">
                            <img src="" alt="Pratinjau QR" id="qrg-qr-up-preview-img">
                            <div class="qrg-qr-up__meta">
                                <strong id="qrg-qr-up-filename">—</strong>
                                <button type="button" class="qrg-qr-up__change" id="qrg-qr-up-change">Ganti</button>
                            </div>
                        </div>

                        <div class="qrg-qr-up__actions">
                            <button type="submit" class="qrg-qr-up__submit" id="qrg-qr-up-submit" disabled>
                                <span class="dashicons dashicons-upload" style="font-size:16px;width:16px;height:16px;"></span>
                                <?php echo $qr_url ? 'Unggah QR Baru' : 'Unggah QR Code'; ?>
                            </button>
                            <?php if ( $qr_url ) : ?>
                                <button type="submit" class="qrg-qr-up__remove" name="qrg_pembayaran_qr_remove" value="1" formnovalidate id="qrg-qr-up-remove" onclick="return confirm('Hapus QR code pembayaran? Invoice akan memakai QR bawaan.');">
                                    Hapus QR
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ( is_array( $qr_decode ) ) :
                $info      = isset( $qr_decode['info'] ) && is_array( $qr_decode['info'] ) ? $qr_decode['info'] : [];
                $payload   = (string) ( $qr_decode['payload'] ?? '' );
                $err       = (string) ( $qr_decode['error'] ?? '' );
                $ok        = $payload !== '' && $err === '';
                $badge_cls = $ok ? '' : ( $err !== '' ? ' is-err' : ' is-warn' );
                $badge_txt = $ok ? ( $info['type_label'] ?? 'Terbaca' ) : ( $err !== '' ? 'Gagal dibaca' : 'Menunggu' );
                $need_jsqr = ( ! $ok && $qr_url );
                $summary   = isset( $info['summary'] ) && is_array( $info['summary'] ) ? $info['summary'] : [];
                if ( empty( $summary ) && $ok ) {
                    $summary = [ [ 'label' => 'Jenis', 'value' => (string) ( $info['type_label'] ?? '—' ) ] ];
                }
                $runs = isset( $info['decoded_runs'] ) && is_array( $info['decoded_runs'] ) ? $info['decoded_runs'] : [];
                ?>
                <div class="qrg-qr-decode" id="qrg-qr-decode" data-qr-url="<?php echo esc_url( $qr_full ? $qr_full : $qr_url ); ?>" data-need-jsqr="<?php echo $need_jsqr ? '1' : '0'; ?>">
                    <div class="qrg-qr-decode__head">
                        <div class="qrg-qr-decode__title-wrap">
                            <div class="qrg-qr-decode__icon" aria-hidden="true"><span class="dashicons dashicons-visibility"></span></div>
                            <div>
                                <h3>Arti QR aktif saat ini</h3>
                                <p class="qrg-qr-decode__sub">Ringkasan isi QR pembayaran yang dipakai di invoice siswa.</p>
                            </div>
                        </div>
                        <div class="qrg-qr-decode__actions">
                            <span class="qrg-qr-decode__badge<?php echo esc_attr( $badge_cls ); ?>" id="qrg-qr-decode-badge" title="<?php echo esc_attr( $badge_txt ); ?>"><?php echo esc_html( $badge_txt ); ?></span>
                            <?php if ( $qr_url ) : ?>
                                <a class="qrg-qr-decode__reread" href="<?php echo esc_url( $reread_url ); ?>">
                                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                                    Baca ulang
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ( ! $ok ) : ?>
                        <p class="qrg-qr-decode__status<?php echo $err !== '' ? ' is-err' : ''; ?>" id="qrg-qr-decode-status">
                            <?php echo esc_html( $err !== '' ? $err : 'Mencoba membaca QR…' ); ?>
                            <?php if ( $need_jsqr ) : ?> Sistem juga akan mencoba membaca ulang di browser Anda.<?php endif; ?>
                        </p>
                    <?php else : ?>
                        <p class="qrg-qr-decode__status" id="qrg-qr-decode-status" hidden></p>
                    <?php endif; ?>

                    <div class="qrg-qr-decode__body" id="qrg-qr-decode-body" <?php echo $ok ? '' : 'hidden'; ?>>
                        <div class="qrg-qr-decode__facts" id="qrg-qr-decode-summary">
                            <?php foreach ( $summary as $row ) : ?>
                                <div class="qrg-qr-decode__fact">
                                    <span class="qrg-qr-decode__fact-label"><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></span>
                                    <span class="qrg-qr-decode__fact-value"><?php echo esc_html( (string) ( $row['value'] ?? '' ) ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="qrg-qr-decode__section">
                            <div class="qrg-qr-decode__section-head">
                                <h4><span class="dashicons dashicons-editor-code" aria-hidden="true"></span> Isi QR (mentah)</h4>
                                <button type="button" class="qrg-qr-decode__copy" data-copy-target="qrg-qr-decode-raw">Salin</button>
                            </div>
                            <div class="qrg-qr-decode__section-body">
                                <pre class="qrg-qr-decode__mono" id="qrg-qr-decode-raw"><?php echo esc_html( $payload ); ?></pre>
                            </div>
                        </div>

                        <div class="qrg-qr-decode__section" id="qrg-qr-decode-b64-wrap" <?php echo ! empty( $info['is_base64'] ) ? '' : 'hidden'; ?>>
                            <div class="qrg-qr-decode__section-head">
                                <h4><span class="dashicons dashicons-database" aria-hidden="true"></span> Decode Base64</h4>
                            </div>
                            <div class="qrg-qr-decode__section-body">
                                <p class="qrg-qr-decode__hint">Payload QR terdeteksi sebagai Base64. Pilih tampilan hasil decode di bawah.</p>
                                <div class="qrg-qr-decode__tabs" role="tablist" aria-label="Tampilan decode Base64">
                                    <button type="button" class="qrg-qr-decode__tab is-on" data-pane="ascii" role="tab" aria-selected="true">ASCII</button>
                                    <button type="button" class="qrg-qr-decode__tab" data-pane="hex" role="tab" aria-selected="false">HEX</button>
                                    <button type="button" class="qrg-qr-decode__tab" data-pane="tokens" role="tab" aria-selected="false">Token</button>
                                </div>
                                <div class="qrg-qr-decode__pane is-on" data-pane="ascii" role="tabpanel">
                                    <div class="qrg-qr-decode__section-head" style="padding:0 0 8px;background:transparent;border:0;">
                                        <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Karakter terbaca</span>
                                        <button type="button" class="qrg-qr-decode__copy" data-copy-target="qrg-qr-decode-ascii">Salin</button>
                                    </div>
                                    <pre class="qrg-qr-decode__mono is-soft" id="qrg-qr-decode-ascii"><?php echo esc_html( (string) ( $info['decoded_ascii'] ?? '' ) ); ?></pre>
                                </div>
                                <div class="qrg-qr-decode__pane" data-pane="hex" role="tabpanel" hidden>
                                    <div class="qrg-qr-decode__section-head" style="padding:0 0 8px;background:transparent;border:0;">
                                        <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Representasi HEX</span>
                                        <button type="button" class="qrg-qr-decode__copy" data-copy-target="qrg-qr-decode-hex">Salin</button>
                                    </div>
                                    <pre class="qrg-qr-decode__mono" id="qrg-qr-decode-hex"><?php echo esc_html( (string) ( $info['decoded_hex'] ?? '' ) ); ?></pre>
                                </div>
                                <div class="qrg-qr-decode__pane" data-pane="tokens" role="tabpanel" hidden>
                                    <p class="qrg-qr-decode__hint" style="margin-bottom:8px;">String yang terbaca dari data biner:</p>
                                    <div class="qrg-qr-decode__chips" id="qrg-qr-decode-chips">
                                        <?php if ( empty( $runs ) ) : ?>
                                            <span class="qrg-qr-decode__chip" style="background:#f8fafc;color:#64748b;border-color:#e2e8f0;">Tidak ada token</span>
                                        <?php else : ?>
                                            <?php foreach ( $runs as $run ) : ?>
                                                <span class="qrg-qr-decode__chip"><?php echo esc_html( $run ); ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            $sim_presets = [ 10000, 25000, 50000, 100000, 250000, 500000 ];
            $sim_fmt     = 'Rp ' . number_format( $sim_amount, 0, ',', '.' );
            ?>
            <div class="qrg-qr-sim" id="qrg-qr-sim"
                data-master="<?php echo esc_attr( $master_payload_for_js ); ?>"
                data-type="<?php echo esc_attr( $sim_type ); ?>"
                data-dynamic="<?php echo $sim_dynamic ? '1' : '0'; ?>">
                <div class="qrg-qr-sim__head">
                    <div class="qrg-qr-sim__title-wrap">
                        <div class="qrg-qr-sim__icon" aria-hidden="true"><span class="dashicons dashicons-smartphone"></span></div>
                        <div>
                            <h3>Simulasi QRIS dinamis</h3>
                            <p class="qrg-qr-sim__sub">Uji nominal otomatis seperti di halaman invoice siswa. Masukkan angka, lalu lihat pratinjau QR hasil generate.</p>
                        </div>
                    </div>
                    <span class="qrg-qr-sim__badge<?php echo $sim_dynamic ? '' : ' is-off'; ?>" id="qrg-qr-sim-badge">
                        <?php echo $sim_dynamic ? 'Nominal otomatis aktif' : 'Nominal otomatis tidak tersedia'; ?>
                    </span>
                </div>
                <div class="qrg-qr-sim__body">
                    <form class="qrg-qr-sim__form" method="get" action="" id="qrg-qr-sim-form">
                        <input type="hidden" name="page" value="qrg-setting">
                        <input type="hidden" name="qris" value="1">
                        <div class="qrg-qr-sim__field">
                            <label for="qrg-qr-sim-amount">Nominal simulasi</label>
                            <div class="qrg-qr-sim__input-wrap">
                                <span class="qrg-qr-sim__prefix">Rp</span>
                                <input class="qrg-qr-sim__input" type="text" inputmode="numeric" name="qr_sim" id="qrg-qr-sim-amount"
                                    value="<?php echo esc_attr( number_format( $sim_amount, 0, ',', '.' ) ); ?>"
                                    autocomplete="off" aria-describedby="qrg-qr-sim-hint">
                            </div>
                            <div class="qrg-qr-sim__presets" id="qrg-qr-sim-presets">
                                <?php foreach ( $sim_presets as $preset ) : ?>
                                    <button type="button" class="qrg-qr-sim__preset<?php echo (int) $preset === (int) $sim_amount ? ' is-on' : ''; ?>" data-amount="<?php echo (int) $preset; ?>">
                                        <?php echo esc_html( number_format( $preset, 0, ',', '.' ) ); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="qrg-qr-sim__btn" id="qrg-qr-sim-submit">
                            <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                            Simulasikan
                        </button>
                    </form>

                    <?php if ( $sim_dynamic ) : ?>
                        <p class="qrg-qr-sim__ok" id="qrg-qr-sim-hint">
                            Master QR terdeteksi sebagai <strong>QRIS MPM</strong>. Pratinjau di bawah memakai nominal
                            <strong id="qrg-qr-sim-hint-amount"><?php echo esc_html( $sim_fmt ); ?></strong>
                            (sama seperti yang akan muncul di invoice siswa).
                        </p>
                    <?php else : ?>
                        <p class="qrg-qr-sim__warn" id="qrg-qr-sim-hint">
                            Master QR saat ini bukan QRIS MPM (<code>000201…</code>), jadi nominal tidak bisa disisipkan otomatis.
                            Unggah QRIS merchant MPM di atas agar simulasi &amp; invoice memakai nominal dinamis.
                        </p>
                    <?php endif; ?>

                    <div class="qrg-qr-sim__layout">
                        <div class="qrg-qr-sim__preview">
                            <?php if ( $sim_img !== '' ) : ?>
                                <img src="<?php echo esc_url( $sim_img ); ?>"
                                     data-fallback="<?php echo esc_url( $sim_fallback ); ?>"
                                     alt="Pratinjau QRIS simulasi"
                                     id="qrg-qr-sim-img"
                                     onerror="if(this.dataset.fallback && this.src!==this.dataset.fallback){this.src=this.dataset.fallback;}">
                            <?php else : ?>
                                <img src="" alt="Pratinjau QRIS simulasi" id="qrg-qr-sim-img" hidden>
                            <?php endif; ?>
                            <p class="qrg-qr-sim__preview-label<?php echo $sim_dynamic ? '' : ' is-off'; ?>" id="qrg-qr-sim-preview-label">
                                <?php echo $sim_dynamic ? 'QRIS dinamis siap dipindai' : 'QR master (tanpa nominal otomatis)'; ?>
                            </p>
                        </div>
                        <div>
                            <div class="qrg-qr-sim__meta">
                                <div class="qrg-qr-sim__meta-item">
                                    <span>Nominal</span>
                                    <strong id="qrg-qr-sim-meta-amount"><?php echo esc_html( $sim_fmt ); ?></strong>
                                </div>
                                <div class="qrg-qr-sim__meta-item">
                                    <span>Mode</span>
                                    <strong id="qrg-qr-sim-meta-mode"><?php echo $sim_dynamic ? 'Dinamis (tag 54)' : 'Statis'; ?></strong>
                                </div>
                                <div class="qrg-qr-sim__meta-item">
                                    <span>Merchant</span>
                                    <strong id="qrg-qr-sim-meta-merchant"><?php echo esc_html( $sim_merchant !== '' ? $sim_merchant : '—' ); ?></strong>
                                </div>
                                <div class="qrg-qr-sim__meta-item">
                                    <span>Jenis master</span>
                                    <strong id="qrg-qr-sim-meta-type"><?php echo esc_html( $sim_type !== '' ? $sim_type : '—' ); ?></strong>
                                </div>
                            </div>
                            <div class="qrg-qr-decode__section">
                                <div class="qrg-qr-decode__section-head">
                                    <h4><span class="dashicons dashicons-editor-code" aria-hidden="true"></span> Payload hasil simulasi</h4>
                                    <button type="button" class="qrg-qr-decode__copy" data-copy-target="qrg-qr-sim-payload">Salin</button>
                                </div>
                                <div class="qrg-qr-decode__section-body">
                                    <pre class="qrg-qr-decode__mono" id="qrg-qr-sim-payload"><?php echo esc_html( $sim_payload ); ?></pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="qrg-qr-load" id="qrg-qr-load" aria-hidden="true" role="status">
            <div class="qrg-qr-load__card">
                <div class="qrg-qr-load__orb" aria-hidden="true"><span class="dashicons dashicons-cloud-upload"></span></div>
                <p class="qrg-qr-load__title" id="qrg-qr-load-title">Mengunggah QR…</p>
                <p class="qrg-qr-load__tip">Mohon tunggu, jangan tutup halaman ini.</p>
                <div class="qrg-qr-load__bar" aria-hidden="true"><span id="qrg-qr-load-bar"></span></div>
                <div class="qrg-qr-load__pct" id="qrg-qr-load-pct">0%</div>
            </div>
        </div>
    <script>
    jQuery(function($) {
        (function () {
            var form = document.getElementById('qrg-qr-up-form');
            var fileInput = document.getElementById('qrg-qr-up-file');
            var drop = document.getElementById('qrg-qr-up-drop');
            var dropTitle = document.getElementById('qrg-qr-up-drop-title');
            var preview = document.getElementById('qrg-qr-up-preview');
            var previewImg = document.getElementById('qrg-qr-up-preview-img');
            var filenameEl = document.getElementById('qrg-qr-up-filename');
            var changeBtn = document.getElementById('qrg-qr-up-change');
            var submitBtn = document.getElementById('qrg-qr-up-submit');
            var removeBtn = document.getElementById('qrg-qr-up-remove');
            var load = document.getElementById('qrg-qr-load');
            var loadTitle = document.getElementById('qrg-qr-load-title');
            var loadBar = document.getElementById('qrg-qr-load-bar');
            var loadPct = document.getElementById('qrg-qr-load-pct');
            if (!form || !fileInput || !drop) return;

            var previewUrl = '';
            var progressTimer = null;

            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
            }

            function clearPreview() {
                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                    previewUrl = '';
                }
                if (preview) preview.classList.remove('is-on');
                if (previewImg) previewImg.removeAttribute('src');
                if (filenameEl) filenameEl.textContent = '—';
                drop.classList.remove('has-file');
                if (dropTitle) dropTitle.textContent = 'Seret gambar ke sini';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.remove('is-ready');
                }
            }

            function setFile(file) {
                if (!file) {
                    clearPreview();
                    return;
                }
                if (!/^image\/(jpeg|png|webp|gif)$/i.test(file.type)) {
                    window.alert('Format tidak didukung. Gunakan JPG, PNG, WebP, atau GIF.');
                    fileInput.value = '';
                    clearPreview();
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    window.alert('Ukuran file maksimal 5 MB.');
                    fileInput.value = '';
                    clearPreview();
                    return;
                }
                if (previewUrl) URL.revokeObjectURL(previewUrl);
                previewUrl = URL.createObjectURL(file);
                if (previewImg) previewImg.src = previewUrl;
                if (preview) preview.classList.add('is-on');
                if (filenameEl) filenameEl.textContent = file.name + ' · ' + formatSize(file.size);
                drop.classList.add('has-file');
                if (dropTitle) dropTitle.textContent = 'File siap diunggah';
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.add('is-ready');
                }
            }

            function showLoader(title) {
                if (!load) return;
                if (loadTitle) loadTitle.textContent = title || 'Mengunggah QR…';
                if (loadBar) loadBar.style.width = '8%';
                if (loadPct) loadPct.textContent = '8%';
                load.classList.add('is-on');
                load.setAttribute('aria-hidden', 'false');
                var pct = 8;
                if (progressTimer) clearInterval(progressTimer);
                progressTimer = setInterval(function () {
                    pct = Math.min(92, pct + Math.random() * 9 + 2);
                    if (loadBar) loadBar.style.width = pct.toFixed(0) + '%';
                    if (loadPct) loadPct.textContent = pct.toFixed(0) + '%';
                    if (pct >= 92) clearInterval(progressTimer);
                }, 180);
            }

            fileInput.addEventListener('change', function () {
                setFile(fileInput.files && fileInput.files[0] ? fileInput.files[0] : null);
            });

            ['dragenter', 'dragover'].forEach(function (evt) {
                drop.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    drop.classList.add('is-drag');
                });
            });
            ['dragleave', 'drop'].forEach(function (evt) {
                drop.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    drop.classList.remove('is-drag');
                });
            });
            drop.addEventListener('drop', function (e) {
                var files = e.dataTransfer && e.dataTransfer.files;
                if (!files || !files.length) return;
                try {
                    var dt = new DataTransfer();
                    dt.items.add(files[0]);
                    fileInput.files = dt.files;
                } catch (err) {
                    // Browser lama: cukup set preview, submit tetap butuh pilih manual.
                }
                setFile(files[0]);
            });

            if (changeBtn) {
                changeBtn.addEventListener('click', function () {
                    fileInput.value = '';
                    clearPreview();
                    fileInput.click();
                });
            }

            form.addEventListener('submit', function (e) {
                var isRemove = e.submitter && e.submitter.getAttribute('name') === 'qrg_pembayaran_qr_remove';
                if (isRemove) {
                    showLoader('Menghapus QR…');
                    return;
                }
                if (!fileInput.files || !fileInput.files.length) {
                    e.preventDefault();
                    drop.classList.add('is-drag');
                    setTimeout(function () { drop.classList.remove('is-drag'); }, 450);
                    return;
                }
                showLoader('Mengunggah QR…');
            });

            if (removeBtn) {
                removeBtn.addEventListener('click', function () {
                    // Biarkan confirm native di onclick; loader akan muncul saat submit.
                });
            }
        })();

        (function () {
            document.addEventListener('click', function (e) {
                var copyBtn = e.target.closest('.qrg-qr-decode__copy');
                if (!copyBtn) return;
                var targetId = copyBtn.getAttribute('data-copy-target');
                var el = targetId ? document.getElementById(targetId) : null;
                var text = el ? (el.textContent || '') : '';
                if (!text) return;
                e.preventDefault();
                var done = function () {
                    var original = copyBtn.textContent;
                    copyBtn.classList.add('is-done');
                    copyBtn.textContent = 'Tersalin';
                    setTimeout(function () {
                        copyBtn.classList.remove('is-done');
                        copyBtn.textContent = original || 'Salin';
                    }, 1400);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done).catch(function () {});
                } else {
                    var tmp = document.createElement('textarea');
                    tmp.value = text;
                    document.body.appendChild(tmp);
                    tmp.select();
                    document.execCommand('copy');
                    tmp.remove();
                    done();
                }
            });
        })();

        (function () {
            var box = document.getElementById('qrg-qr-decode');
            if (!box) return;
            var url = box.getAttribute('data-qr-url') || '';
            var needJsqr = box.getAttribute('data-need-jsqr') === '1';

            function escapeHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function setPane(name) {
                var tabs = box.querySelectorAll('.qrg-qr-decode__tab');
                var panes = box.querySelectorAll('.qrg-qr-decode__pane');
                tabs.forEach(function (tab) {
                    var on = tab.getAttribute('data-pane') === name;
                    tab.classList.toggle('is-on', on);
                    tab.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                panes.forEach(function (pane) {
                    var on = pane.getAttribute('data-pane') === name;
                    pane.classList.toggle('is-on', on);
                    pane.hidden = !on;
                });
            }

            box.addEventListener('click', function (e) {
                var tab = e.target.closest('.qrg-qr-decode__tab');
                if (tab && box.contains(tab)) {
                    setPane(tab.getAttribute('data-pane'));
                }
            });

            function binToAscii(bytes) {
                var out = '';
                for (var i = 0; i < bytes.length; i++) {
                    var c = bytes[i];
                    out += (c >= 32 && c < 127) ? String.fromCharCode(c) : '.';
                }
                return out;
            }
            function toHex(bytes) {
                var h = '';
                for (var i = 0; i < bytes.length; i++) {
                    h += ('0' + bytes[i].toString(16)).slice(-2);
                }
                return h;
            }
            function extractRuns(bytes) {
                var s = '';
                for (var i = 0; i < bytes.length; i++) {
                    var c = bytes[i];
                    s += (c >= 32 && c < 127) ? String.fromCharCode(c) : '\0';
                }
                var runs = s.match(/[\x20-\x7E]{3,}/g) || [];
                var uniq = [];
                runs.forEach(function (r) {
                    if (uniq.indexOf(r) === -1) uniq.push(r);
                });
                return uniq;
            }
            function b64ToBytes(str) {
                try {
                    var n = str.replace(/-/g, '+').replace(/_/g, '/');
                    while (n.length % 4) n += '=';
                    var bin = atob(n);
                    var out = new Uint8Array(bin.length);
                    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
                    return out;
                } catch (e) {
                    return null;
                }
            }
            function summarize(payload) {
                var info = {
                    type_label: 'Teks biasa',
                    is_base64: false,
                    decoded_ascii: '',
                    decoded_hex: '',
                    decoded_runs: [],
                    summary: []
                };
                if (/^000201/.test(payload)) {
                    info.type_label = 'QRIS MPM (Merchant Presented Mode)';
                    info.summary = [{ label: 'Jenis', value: info.type_label }, { label: 'Payload EMV', value: payload.slice(0, 80) + (payload.length > 80 ? '…' : '') }];
                    return info;
                }
                if (/^https?:\/\//i.test(payload)) {
                    info.type_label = 'URL / tautan';
                    info.summary = [{ label: 'Tautan', value: payload }];
                    return info;
                }
                if (/^[A-Za-z0-9+\/=_-]+$/.test(payload) && payload.length >= 16) {
                    var bytes = b64ToBytes(payload);
                    if (bytes && bytes.length) {
                        info.is_base64 = true;
                        info.decoded_ascii = binToAscii(bytes);
                        info.decoded_hex = toHex(bytes);
                        info.decoded_runs = extractRuns(bytes);
                        var joined = info.decoded_runs.join(' ');
                        info.type_label = /QRIS|DANA|CPM/i.test(joined)
                            ? 'QRIS CPM (Consumer Presented Mode) — data Base64'
                            : 'Data Base64';
                        info.summary = [{ label: 'Jenis', value: info.type_label }];
                        info.decoded_runs.forEach(function (run) {
                            var clean = run.replace(/[_\-\.]+$/g, '').trim();
                            if (/^[A-Z][A-Z\s\.\'-]{2,40}$/.test(clean) && !/QRIS|DANA|CPM|CPV/i.test(clean)) {
                                info.summary.push({ label: 'Nama / Merchant', value: clean });
                            }
                            if (/DANA/i.test(run) && !info.summary.some(function (x) { return x.label === 'Penyedia'; })) {
                                var m = run.match(/DANA(?:\.ID)?/i);
                                info.summary.push({ label: 'Penyedia', value: m ? (m[0].toUpperCase() === 'DANA' ? 'DANA' : 'DANA.ID') : 'DANA' });
                            }
                            if (/^\d{15,30}$/.test(run)) {
                                info.summary.push({ label: 'ID / NMID', value: run });
                            }
                        });
                        return info;
                    }
                }
                info.summary = [{ label: 'Isi', value: payload }];
                return info;
            }
            function render(payload) {
                var info = summarize(payload);
                var badge = document.getElementById('qrg-qr-decode-badge');
                var status = document.getElementById('qrg-qr-decode-status');
                var body = document.getElementById('qrg-qr-decode-body');
                var summary = document.getElementById('qrg-qr-decode-summary');
                var raw = document.getElementById('qrg-qr-decode-raw');
                var wrap = document.getElementById('qrg-qr-decode-b64-wrap');
                var ascii = document.getElementById('qrg-qr-decode-ascii');
                var hex = document.getElementById('qrg-qr-decode-hex');
                var chips = document.getElementById('qrg-qr-decode-chips');
                if (badge) {
                    badge.className = 'qrg-qr-decode__badge';
                    badge.textContent = info.type_label;
                    badge.setAttribute('title', info.type_label);
                }
                if (status) {
                    status.hidden = true;
                    status.classList.remove('is-err');
                }
                if (body) body.hidden = false;
                if (summary) {
                    summary.innerHTML = info.summary.map(function (row) {
                        return '<div class="qrg-qr-decode__fact">' +
                            '<span class="qrg-qr-decode__fact-label">' + escapeHtml(row.label) + '</span>' +
                            '<span class="qrg-qr-decode__fact-value">' + escapeHtml(row.value) + '</span>' +
                            '</div>';
                    }).join('');
                }
                if (raw) raw.textContent = payload;
                if (wrap) wrap.hidden = !info.is_base64;
                if (ascii) ascii.textContent = info.decoded_ascii || '';
                if (hex) hex.textContent = info.decoded_hex || '';
                if (chips) {
                    if (!info.decoded_runs || !info.decoded_runs.length) {
                        chips.innerHTML = '<span class="qrg-qr-decode__chip" style="background:#f8fafc;color:#64748b;border-color:#e2e8f0;">Tidak ada token</span>';
                    } else {
                        chips.innerHTML = info.decoded_runs.map(function (r) {
                            return '<span class="qrg-qr-decode__chip">' + escapeHtml(r) + '</span>';
                        }).join('');
                    }
                }
                setPane('ascii');
            }

            if (!needJsqr || !url) return;

            function loadScript(src) {
                return new Promise(function (resolve, reject) {
                    var s = document.createElement('script');
                    s.src = src;
                    s.onload = resolve;
                    s.onerror = reject;
                    document.head.appendChild(s);
                });
            }

            function decodeWithJsQR() {
                return new Promise(function (resolve, reject) {
                    var img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.onload = function () {
                        try {
                            var canvas = document.createElement('canvas');
                            canvas.width = img.naturalWidth || img.width;
                            canvas.height = img.naturalHeight || img.height;
                            var ctx = canvas.getContext('2d');
                            ctx.drawImage(img, 0, 0);
                            var data = ctx.getImageData(0, 0, canvas.width, canvas.height);
                            var code = window.jsQR(data.data, data.width, data.height);
                            if (code && code.data) resolve(String(code.data).trim());
                            else reject(new Error('QR tidak terbaca'));
                        } catch (e) {
                            reject(e);
                        }
                    };
                    img.onerror = function () { reject(new Error('Gagal memuat gambar QR')); };
                    img.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'qrg_cb=' + Date.now();
                });
            }

            loadScript('https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js')
                .then(decodeWithJsQR)
                .then(render)
                .catch(function () {
                    var status = document.getElementById('qrg-qr-decode-status');
                    if (status) {
                        status.hidden = false;
                        status.classList.add('is-err');
                        status.textContent = 'Gagal membaca QR di server maupun browser. Unggah ulang gambar yang lebih jelas, lalu tekan Baca ulang.';
                    }
                });
        })();

        (function () {
            var sim = document.getElementById('qrg-qr-sim');
            if (!sim) return;
            var form = document.getElementById('qrg-qr-sim-form');
            var input = document.getElementById('qrg-qr-sim-amount');
            var presets = document.getElementById('qrg-qr-sim-presets');
            var img = document.getElementById('qrg-qr-sim-img');
            var payloadEl = document.getElementById('qrg-qr-sim-payload');
            var metaAmount = document.getElementById('qrg-qr-sim-meta-amount');
            var metaMode = document.getElementById('qrg-qr-sim-meta-mode');
            var hintAmount = document.getElementById('qrg-qr-sim-hint-amount');
            var previewLabel = document.getElementById('qrg-qr-sim-preview-label');
            var badge = document.getElementById('qrg-qr-sim-badge');
            var master = sim.getAttribute('data-master') || '';
            var canDynamic = sim.getAttribute('data-dynamic') === '1' || /^000201/.test(master);
            var timer = null;

            function digitsOnly(v) {
                return String(v || '').replace(/\D+/g, '');
            }
            function formatRp(n) {
                n = Math.max(0, parseInt(n, 10) || 0);
                return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }
            function formatLabel(n) {
                return 'Rp ' + formatRp(n);
            }
            function parseFields(payload) {
                var fields = {};
                var i = 0;
                while (i + 4 <= payload.length) {
                    var tag = payload.substr(i, 2);
                    var len = parseInt(payload.substr(i + 2, 2), 10);
                    i += 4;
                    if (isNaN(len) || len < 0 || i + len > payload.length) break;
                    fields[tag] = payload.substr(i, len);
                    i += len;
                }
                return fields;
            }
            function tlv(tag, value) {
                value = String(value);
                tag = String(tag).padStart(2, '0');
                return tag + String(value.length).padStart(2, '0') + value;
            }
            function crc16(data) {
                var crc = 0xFFFF;
                for (var i = 0; i < data.length; i++) {
                    crc ^= (data.charCodeAt(i) << 8);
                    for (var j = 0; j < 8; j++) {
                        if (crc & 0x8000) crc = ((crc << 1) ^ 0x1021) & 0xFFFF;
                        else crc = (crc << 1) & 0xFFFF;
                    }
                }
                return crc.toString(16).toUpperCase().padStart(4, '0');
            }
            function applyAmount(payload, amount) {
                payload = String(payload || '').trim();
                if (!/^000201/.test(payload)) return '';
                if (/6304[0-9A-Fa-f]{4}$/.test(payload)) payload = payload.slice(0, -8);
                var fields = parseFields(payload);
                if (!fields['00']) return '';
                var amountStr = (Math.max(0, Number(amount) || 0)).toFixed(2);
                fields['01'] = '12';
                fields['54'] = amountStr;
                if (!fields['53']) fields['53'] = '360';
                delete fields['63'];
                var order = ['00','01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31','32','33','34','35','36','37','38','39','40','41','42','43','44','45','46','47','48','49','50','51','52','53','54','55','56','57','58','59','60','61','62','64','65','66','67','68','69','70','71','72','73','74','75','76','77','78','79','80','81','82','83','84','85','86','87','88','89','90','91','92','93','94','95','96','97','98','99'];
                var body = '';
                order.forEach(function (tag) {
                    if (fields[tag] == null) return;
                    body += tlv(tag, fields[tag]);
                    delete fields[tag];
                });
                Object.keys(fields).sort().forEach(function (tag) {
                    body += tlv(tag, fields[tag]);
                });
                body += '6304';
                return body + crc16(body);
            }
            function qrImageUrl(payload) {
                return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=M&margin=10&data=' + encodeURIComponent(payload);
            }
            function syncPresets(n) {
                if (!presets) return;
                presets.querySelectorAll('.qrg-qr-sim__preset').forEach(function (btn) {
                    btn.classList.toggle('is-on', parseInt(btn.getAttribute('data-amount'), 10) === n);
                });
            }
            function updateLive() {
                if (!input) return;
                var n = parseInt(digitsOnly(input.value), 10) || 0;
                if (n <= 0) n = 0;
                if (n > 999999999) n = 999999999;
                input.value = n ? formatRp(n) : '';
                syncPresets(n);
                if (metaAmount) metaAmount.textContent = n ? formatLabel(n) : '—';
                if (hintAmount) hintAmount.textContent = n ? formatLabel(n) : '—';
                if (!canDynamic || !master || n <= 0) return;
                var dyn = applyAmount(master, n);
                if (!dyn) return;
                if (payloadEl) payloadEl.textContent = dyn;
                if (img) {
                    img.hidden = false;
                    img.src = qrImageUrl(dyn);
                    img.dataset.fallback = 'https://quickchart.io/qr?size=300&margin=2&text=' + encodeURIComponent(dyn);
                }
                if (metaMode) metaMode.textContent = 'Dinamis (tag 54)';
                if (previewLabel) {
                    previewLabel.classList.remove('is-off');
                    previewLabel.textContent = 'QRIS dinamis siap dipindai';
                }
                if (badge) {
                    badge.classList.remove('is-off');
                    badge.textContent = 'Nominal otomatis aktif';
                }
            }

            if (input) {
                input.addEventListener('input', function () {
                    var caret = input.selectionStart;
                    var before = digitsOnly(input.value.slice(0, caret)).length;
                    var n = digitsOnly(input.value);
                    input.value = n ? formatRp(n) : '';
                    // restore rough caret
                    var pos = 0, count = 0;
                    while (pos < input.value.length && count < before) {
                        if (/\d/.test(input.value.charAt(pos))) count++;
                        pos++;
                    }
                    try { input.setSelectionRange(pos, pos); } catch (err) {}
                    clearTimeout(timer);
                    timer = setTimeout(updateLive, 280);
                });
            }
            if (presets) {
                presets.addEventListener('click', function (e) {
                    var btn = e.target.closest('.qrg-qr-sim__preset');
                    if (!btn) return;
                    var n = parseInt(btn.getAttribute('data-amount'), 10) || 0;
                    if (input) input.value = formatRp(n);
                    updateLive();
                });
            }
            if (form) {
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (!input) return;
                    var n = digitsOnly(input.value) || '50000';
                    var url = new URL(window.location.href);
                    url.searchParams.set('page', 'qrg-setting');
                    url.searchParams.set('qris', '1');
                    url.searchParams.set('qr_sim', n);
                    url.searchParams.delete('qr_reread');
                    url.hash = 'qrg-qr-sim';
                    window.location.href = url.toString();
                });
            }
            if (window.location.hash === '#qrg-qr-sim' || /[?&]qr_sim=/.test(window.location.search)) {
                setTimeout(function () {
                    sim.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 80);
            }
        })();
    });
    </script>
    <?php
}

function qrg_bantuan_page_callback() {
    $shortcodes = qrg_bantuan_shortcodes();
    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-editor-help" style="font-size:30px; width:30px; height:30px; margin-right:6px; vertical-align:middle;"></span>Bantuan &amp; Dokumentasi</h1>
        <p style="font-size:14px; color:#555; max-width:760px;">
            Halaman ini berisi panduan penggunaan plugin <strong>Sekolah</strong>, terutama daftar <em>shortcode</em>
            yang dapat Anda tempelkan ke halaman atau postingan WordPress untuk menampilkan formulir dan data ke publik.
        </p>

        <div class="qrg-bantuan-intro" style="background:#fff; border:1px solid #dcdcde; border-left:4px solid #2271b1; padding:16px 20px; border-radius:4px; max-width:760px; margin:20px 0;">
            <h2 style="margin-top:0;">Apa itu Shortcode?</h2>
            <p style="margin-bottom:0;">
                Shortcode adalah kode singkat dalam tanda kurung siku, contohnya <code>[form_pendaftaran_psb]</code>.
                Cukup salin kode tersebut lalu tempelkan ke isi halaman/postingan. WordPress akan otomatis
                mengubahnya menjadi tampilan yang sesuai (formulir, daftar, dll).
            </p>
        </div>

        <h2>Daftar Shortcode Tersedia</h2>

        <?php foreach ( $shortcodes as $sc ) : ?>
            <div class="qrg-bantuan-card" style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:20px 24px; margin-bottom:24px; max-width:760px;">
                <h3 style="margin-top:0; display:flex; align-items:center; gap:10px;">
                    <span class="dashicons dashicons-shortcode" style="color:#2271b1;"></span>
                    <?php echo esc_html( $sc['judul'] ); ?>
                </h3>

                <div style="display:flex; align-items:center; gap:10px; margin:12px 0;">
                    <input type="text" readonly value="<?php echo esc_attr( $sc['kode'] ); ?>"
                        onclick="this.select();"
                        style="font-family:monospace; font-size:14px; padding:8px 12px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; width:320px; max-width:100%; color:#2271b1; font-weight:600;">
                    <button type="button" class="button qrg-copy-shortcode" data-code="<?php echo esc_attr( $sc['kode'] ); ?>">
                        <span class="dashicons dashicons-clipboard" style="vertical-align:text-bottom;"></span> Salin
                    </button>
                </div>

                <p style="color:#50575e;"><?php echo wp_kses_post( $sc['deskripsi'] ); ?></p>

                <p style="font-weight:600; margin-bottom:6px;">Cara pakai:</p>
                <ol style="margin-top:0; color:#50575e; line-height:1.7;">
                    <?php foreach ( $sc['cara'] as $langkah ) : ?>
                        <li><?php echo wp_kses_post( $langkah ); ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endforeach; ?>

        <h2>Butuh bantuan lebih lanjut?</h2>
        <div style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:20px 24px; max-width:760px;">
            <p style="margin:0; color:#50575e;">
                Hubungi pengembang plugin:
                <strong><a href="https://blog.serverstb.com" target="_blank" rel="noopener noreferrer">Ryan Dewantoro</a></strong>.
            </p>
            <p style="margin:8px 0 0; color:#50575e;">
                Email: <a href="mailto:admin@serverstb.com">admin@serverstb.com</a>
            </p>
        </div>
    </div>

    <script>
    jQuery(function($) {
        $('.qrg-copy-shortcode').on('click', function() {
            var code = $(this).data('code');
            var $btn = $(this);
            var done = function() {
                var original = $btn.html();
                $btn.html('<span class="dashicons dashicons-yes" style="vertical-align:text-bottom;"></span> Tersalin!');
                setTimeout(function() { $btn.html(original); }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(done);
            } else {
                var $tmp = $('<textarea>').val(code).appendTo('body').select();
                document.execCommand('copy');
                $tmp.remove();
                done();
            }
        });
    });
    </script>
    <?php
}
