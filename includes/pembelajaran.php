<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * =========================================================
 * MODUL PEMBELAJARAN
 * - Guru membuat tugas untuk kelas
 * - Siswa mengerjakan via portal [portal_pembelajaran]
 * - Admin/guru monitoring & menilai hasil
 * =========================================================
 */

// -------------------------------------------------------------------------
// 1. CPT Tugas & Jawaban
// -------------------------------------------------------------------------
add_action( 'init', 'qrg_register_pembelajaran_cpts' );
function qrg_register_pembelajaran_cpts() {
    register_post_type( 'master_tugas', [
        'labels' => [
            'name'               => 'Daftar Tugas',
            'singular_name'      => 'Tugas',
            'menu_name'          => 'Daftar Tugas',
            'add_new'            => 'Buat Tugas',
            'add_new_item'       => 'Buat Tugas Baru',
            'edit_item'          => 'Edit Tugas',
            'all_items'          => 'Daftar Tugas',
            'search_items'       => 'Cari Tugas',
            'not_found'          => 'Belum ada tugas',
            'not_found_in_trash' => 'Tidak ada tugas di tong sampah',
        ],
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => false, // Akses via tombol di Mata Pelajaran
        'supports'           => [ 'title', 'editor' ],
        'capability_type'    => 'post',
        'has_archive'        => false,
        'rewrite'            => false,
    ] );

    register_post_type( 'master_jawaban', [
        'labels' => [
            'name'          => 'Jawaban Siswa',
            'singular_name' => 'Jawaban',
            'menu_name'     => 'Hasil Pengerjaan',
            'all_items'     => 'Hasil Pengerjaan',
            'edit_item'     => 'Nilai Jawaban',
            'not_found'     => 'Belum ada jawaban',
        ],
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => false, // Akses via tombol di Daftar Tugas / Mata Pelajaran
        'supports'           => [ 'title' ],
        'capability_type'    => 'post',
        'has_archive'        => false,
        'rewrite'            => false,
    ] );
}

add_filter( 'enter_title_here', 'qrg_tugas_title_placeholder', 10, 2 );
function qrg_tugas_title_placeholder( $title, $post ) {
    if ( isset( $post->post_type ) && $post->post_type === 'master_tugas' ) {
        return 'Judul tugas, contoh: Latihan Matematika Bab 3';
    }
    return $title;
}

// -------------------------------------------------------------------------
// 2. Helper
// -------------------------------------------------------------------------
function qrg_get_tugas_meta( $tugas_id ) {
    $lampiran = get_post_meta( $tugas_id, '_tugas_lampiran', true );
    if ( ! is_array( $lampiran ) ) {
        $lampiran = $lampiran ? [ $lampiran ] : [];
    }
    $soal_cbt = get_post_meta( $tugas_id, '_tugas_soal_cbt', true );
    if ( ! is_array( $soal_cbt ) ) {
        $soal_cbt = [];
    }
    $tipe = get_post_meta( $tugas_id, '_tugas_tipe', true );
    if ( ! in_array( $tipe, [ 'esai', 'cbt' ], true ) ) {
        $tipe = 'esai';
    }

    return [
        'guru_id'   => (int) get_post_meta( $tugas_id, '_tugas_guru_id', true ),
        'kelas_id'  => (int) get_post_meta( $tugas_id, '_tugas_kelas_id', true ),
        'mapel_id'  => (int) get_post_meta( $tugas_id, '_tugas_mapel_id', true ),
        'mapel'     => qrg_tugas_resolve_mapel_label( $tugas_id ),
        'deadline'  => (string) get_post_meta( $tugas_id, '_tugas_deadline', true ),
        'poin_max'  => (int) get_post_meta( $tugas_id, '_tugas_poin_max', true ),
        'tipe'      => $tipe,
        'lampiran'  => $lampiran,
        'soal_cbt'  => $soal_cbt,
    ];
}

/**
 * Ambil label mapel tugas (dari master_mapel atau teks lama).
 *
 * @param int $tugas_id ID master_tugas.
 * @return string
 */
function qrg_tugas_resolve_mapel_label( $tugas_id ) {
    $mapel_id = (int) get_post_meta( $tugas_id, '_tugas_mapel_id', true );
    if ( $mapel_id && function_exists( 'qrg_get_mapel_label' ) ) {
        $label = qrg_get_mapel_label( $mapel_id );
        if ( $label !== '' ) {
            return $label;
        }
    }
    return (string) get_post_meta( $tugas_id, '_tugas_mapel', true );
}

function qrg_tugas_tipe_label( $tipe ) {
    return $tipe === 'cbt' ? 'CBT (Pilihan Ganda)' : 'Esai / Lampiran';
}

/**
 * Sanitize daftar soal CBT dari POST.
 */
function qrg_sanitize_soal_cbt( $raw ) {
    if ( ! is_array( $raw ) ) {
        return [];
    }
    $out = [];
    foreach ( $raw as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $pertanyaan = isset( $row['pertanyaan'] ) ? sanitize_textarea_field( wp_unslash( $row['pertanyaan'] ) ) : '';
        if ( $pertanyaan === '' ) {
            continue;
        }
        $opsi_in = isset( $row['opsi'] ) && is_array( $row['opsi'] ) ? $row['opsi'] : [];
        $opsi    = [];
        foreach ( $opsi_in as $o ) {
            $o = sanitize_text_field( wp_unslash( $o ) );
            if ( $o !== '' ) {
                $opsi[] = $o;
            }
        }
        if ( count( $opsi ) < 2 ) {
            continue;
        }
        $kunci = isset( $row['kunci'] ) ? absint( $row['kunci'] ) : 0;
        if ( $kunci >= count( $opsi ) ) {
            $kunci = 0;
        }
        $out[] = [
            'pertanyaan' => $pertanyaan,
            'opsi'       => $opsi,
            'kunci'      => $kunci,
        ];
    }
    return $out;
}

/**
 * Hitung nilai CBT otomatis.
 */
function qrg_hitung_nilai_cbt( $soal_cbt, $jawaban_siswa, $poin_max = 100 ) {
    $total = count( $soal_cbt );
    if ( $total < 1 ) {
        return 0;
    }
    $benar = 0;
    foreach ( $soal_cbt as $i => $soal ) {
        $pilih = isset( $jawaban_siswa[ $i ] ) ? (int) $jawaban_siswa[ $i ] : -1;
        $kunci = isset( $soal['kunci'] ) ? (int) $soal['kunci'] : -2;
        if ( $pilih === $kunci ) {
            $benar++;
        }
    }
    $poin_max = $poin_max > 0 ? $poin_max : 100;
    return round( ( $benar / $total ) * $poin_max, 1 );
}

function qrg_find_jawaban( $tugas_id, $siswa_id ) {
    $posts = get_posts( [
        'post_type'      => 'master_jawaban',
        'post_status'    => [ 'publish', 'private' ],
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [
                'key'   => '_jawaban_tugas_id',
                'value' => (int) $tugas_id,
            ],
            [
                'key'   => '_jawaban_siswa_id',
                'value' => (int) $siswa_id,
            ],
        ],
    ] );
    return ! empty( $posts ) ? (int) $posts[0] : 0;
}

function qrg_get_jawaban_by_tugas( $tugas_id ) {
    return get_posts( [
        'post_type'      => 'master_jawaban',
        'post_status'    => [ 'publish', 'private' ],
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            [
                'key'   => '_jawaban_tugas_id',
                'value' => (int) $tugas_id,
            ],
        ],
    ] );
}

function qrg_get_tugas_for_siswa( $siswa_id ) {
    $kelas_id = (int) get_post_meta( $siswa_id, '_siswa_kelas', true );
    if ( ! $kelas_id ) {
        return [];
    }
    return get_posts( [
        'post_type'      => 'master_tugas',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            [
                'key'   => '_tugas_kelas_id',
                'value' => $kelas_id,
            ],
        ],
    ] );
}

function qrg_tugas_monitoring_summary( $tugas_id ) {
    $meta       = qrg_get_tugas_meta( $tugas_id );
    $siswa_list = $meta['kelas_id'] ? qrg_get_siswa_by_kelas( $meta['kelas_id'] ) : [];
    $total      = count( $siswa_list );
    $submitted  = 0;
    $graded     = 0;

    foreach ( $siswa_list as $siswa ) {
        $jid = qrg_find_jawaban( $tugas_id, $siswa->ID );
        if ( ! $jid ) {
            continue;
        }
        $submitted++;
        $status = get_post_meta( $jid, '_jawaban_status', true );
        if ( $status === 'Dinilai' ) {
            $graded++;
        }
    }

    return [
        'total'     => $total,
        'submitted' => $submitted,
        'pending'   => max( 0, $total - $submitted ),
        'graded'    => $graded,
    ];
}

function qrg_siswa_portal_auth_id() {
    return function_exists( 'qrg_pembayaran_get_auth_siswa_id' ) ? qrg_pembayaran_get_auth_siswa_id() : 0;
}

function qrg_siswa_portal_set_auth( $siswa_id ) {
    if ( function_exists( 'qrg_pembayaran_set_auth_siswa_id' ) ) {
        qrg_pembayaran_set_auth_siswa_id( $siswa_id );
    }
}

function qrg_siswa_portal_logout() {
    if ( function_exists( 'qrg_pembayaran_logout' ) ) {
        qrg_pembayaran_logout();
    }
}

// -------------------------------------------------------------------------
// 3. Meta box Tugas
// -------------------------------------------------------------------------
add_action( 'add_meta_boxes', 'qrg_tugas_meta_boxes' );
function qrg_tugas_meta_boxes() {
    add_meta_box( 'tugas_detail', 'Detail Tugas', 'qrg_tugas_fields_callback', 'master_tugas', 'normal', 'high' );
    add_meta_box( 'tugas_monitoring', 'Monitoring Hasil Pengerjaan', 'qrg_tugas_monitoring_callback', 'master_tugas', 'normal', 'default' );
}

function qrg_tugas_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_tugas_meta', 'qrg_tugas_nonce' );
    $meta  = qrg_get_tugas_meta( $post->ID );
    $guru  = function_exists( 'qrg_get_daftar_guru' ) ? qrg_get_daftar_guru() : [];
    $kelas = function_exists( 'qrg_get_daftar_kelas' ) ? qrg_get_daftar_kelas() : [];
    $mapel = function_exists( 'qrg_get_daftar_mapel' ) ? qrg_get_daftar_mapel( true ) : [];
    $poin  = $meta['poin_max'] > 0 ? $meta['poin_max'] : 100;
    $tipe  = $meta['tipe'];
    $soal  = ! empty( $meta['soal_cbt'] ) ? $meta['soal_cbt'] : [
        [ 'pertanyaan' => '', 'opsi' => [ '', '', '', '' ], 'kunci' => 0 ],
    ];
    $selected_mapel_id = ! empty( $meta['mapel_id'] ) ? (int) $meta['mapel_id'] : 0;
    ?>
    <p style="color:#666; font-style:italic;">Isi <strong>judul tugas</strong> di kolom atas. Isi petunjuk di editor konten. Pilih tipe <strong>Esai</strong> atau <strong>CBT</strong>, dan boleh menambahkan lampiran.</p>
    <table class="form-table">
        <tr>
            <th><label for="tugas_tipe">Tipe Tugas</label></th>
            <td>
                <label style="margin-right:16px;">
                    <input type="radio" name="tugas_tipe" value="esai" <?php checked( $tipe, 'esai' ); ?> class="qrg-tugas-tipe">
                    Esai / Jawaban teks + file siswa
                </label>
                <label>
                    <input type="radio" name="tugas_tipe" value="cbt" <?php checked( $tipe, 'cbt' ); ?> class="qrg-tugas-tipe">
                    CBT (Pilihan Ganda, dinilai otomatis)
                </label>
            </td>
        </tr>
        <tr>
            <th><label for="tugas_guru_id">Guru Pemberi Tugas</label></th>
            <td>
                <select name="tugas_guru_id" id="tugas_guru_id" style="min-width:320px;" required>
                    <option value="">-- Pilih Guru --</option>
                    <?php foreach ( $guru as $g ) : ?>
                        <option value="<?php echo (int) $g->ID; ?>" <?php selected( $meta['guru_id'], $g->ID ); ?>><?php echo esc_html( $g->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="tugas_kelas_id">Kelas Tujuan</label></th>
            <td>
                <select name="tugas_kelas_id" id="tugas_kelas_id" style="min-width:320px;" required>
                    <option value="">-- Pilih Kelas --</option>
                    <?php foreach ( $kelas as $k ) : ?>
                        <option value="<?php echo (int) $k->ID; ?>" <?php selected( $meta['kelas_id'], $k->ID ); ?>><?php echo esc_html( qrg_get_kelas_label( $k->ID ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Tugas hanya tampil untuk siswa di kelas ini.</p>
            </td>
        </tr>
        <tr>
            <th><label for="tugas_mapel_id">Mata Pelajaran</label></th>
            <td>
                <select name="tugas_mapel_id" id="tugas_mapel_id" style="min-width:320px;">
                    <option value="">-- Pilih Mata Pelajaran --</option>
                    <?php foreach ( $mapel as $m ) : ?>
                        <option value="<?php echo (int) $m->ID; ?>" <?php selected( $selected_mapel_id, (int) $m->ID ); ?>>
                            <?php echo esc_html( function_exists( 'qrg_get_mapel_label' ) ? qrg_get_mapel_label( $m->ID ) : $m->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    Kelola daftar di menu <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=master_mapel' ) ); ?>">Mata Pelajaran</a>.
                    <?php if ( empty( $mapel ) ) : ?>
                        <span style="color:#b32d2e;">Belum ada mapel aktif. Tambahkan terlebih dahulu.</span>
                    <?php endif; ?>
                </p>
                <?php if ( ! $selected_mapel_id && ! empty( $meta['mapel'] ) ) : ?>
                    <p class="description" style="color:#b45309;margin-top:6px;">
                        Data lama (teks): <strong><?php echo esc_html( $meta['mapel'] ); ?></strong> — pilih mapel dari daftar lalu simpan untuk memperbarui.
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="tugas_deadline">Batas Pengumpulan</label></th>
            <td><input type="datetime-local" name="tugas_deadline" id="tugas_deadline" value="<?php echo esc_attr( $meta['deadline'] ? str_replace( ' ', 'T', substr( $meta['deadline'], 0, 16 ) ) : '' ); ?>"></td>
        </tr>
        <tr>
            <th><label for="tugas_poin_max">Nilai Maksimal</label></th>
            <td><input type="number" name="tugas_poin_max" id="tugas_poin_max" class="small-text" min="1" max="1000" value="<?php echo (int) $poin; ?>"></td>
        </tr>
        <tr>
            <th><label for="tugas_lampiran_files">Lampiran Guru</label></th>
            <td>
                <?php if ( ! empty( $meta['lampiran'] ) ) : ?>
                    <ul style="margin:0 0 10px; padding-left:18px;">
                        <?php foreach ( $meta['lampiran'] as $i => $url ) : ?>
                            <li>
                                <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( parse_url( $url, PHP_URL_PATH ) ) ); ?></a>
                                <label style="margin-left:8px; color:#b32d2e;">
                                    <input type="checkbox" name="tugas_lampiran_hapus[]" value="<?php echo esc_attr( $url ); ?>"> Hapus
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <input type="file" name="tugas_lampiran_files[]" id="tugas_lampiran_files" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.zip,.rar">
                <p class="description">Bisa unggah lebih dari satu file (materi, soal, rubrik, dll).</p>
            </td>
        </tr>
    </table>

    <div id="qrg-cbt-builder" style="<?php echo $tipe === 'cbt' ? '' : 'display:none;'; ?> margin-top:10px; border-top:1px solid #ddd; padding-top:16px;">
        <h3 style="margin-top:0;">Soal CBT (Pilihan Ganda)</h3>
        <p class="description">Tambahkan soal. Pilih radio pada opsi yang benar sebagai kunci jawaban. Nilai dihitung otomatis saat siswa mengumpulkan.</p>
        <div id="qrg-cbt-soal-list">
            <?php foreach ( $soal as $idx => $s ) :
                $opsi = isset( $s['opsi'] ) && is_array( $s['opsi'] ) ? $s['opsi'] : [ '', '', '', '' ];
                while ( count( $opsi ) < 4 ) {
                    $opsi[] = '';
                }
                $kunci = isset( $s['kunci'] ) ? (int) $s['kunci'] : 0;
                ?>
                <div class="qrg-cbt-soal" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; gap:10px; margin-bottom:8px;">
                        <strong>Soal <span class="qrg-cbt-num"><?php echo (int) $idx + 1; ?></span></strong>
                        <button type="button" class="button-link-delete qrg-cbt-hapus">Hapus soal</button>
                    </div>
                    <textarea name="tugas_soal_cbt[<?php echo (int) $idx; ?>][pertanyaan]" rows="2" class="large-text" placeholder="Tulis pertanyaan..."><?php echo esc_textarea( $s['pertanyaan'] ?? '' ); ?></textarea>
                    <div style="margin-top:10px;">
                        <?php foreach ( $opsi as $oi => $ov ) : ?>
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                <label title="Kunci jawaban" style="white-space:nowrap;">
                                    <input type="radio" name="tugas_soal_cbt[<?php echo (int) $idx; ?>][kunci]" value="<?php echo (int) $oi; ?>" <?php checked( $kunci, $oi ); ?>>
                                    <?php echo chr( 65 + (int) $oi ); ?>
                                </label>
                                <input type="text" name="tugas_soal_cbt[<?php echo (int) $idx; ?>][opsi][]" value="<?php echo esc_attr( $ov ); ?>" class="regular-text" placeholder="Opsi <?php echo chr( 65 + (int) $oi ); ?>" style="flex:1;">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p><button type="button" class="button" id="qrg-cbt-tambah">+ Tambah Soal</button></p>
    </div>

    <script>
    (function ($) {
        function toggleCbt() {
            var tipe = $('input.qrg-tugas-tipe:checked').val();
            $('#qrg-cbt-builder').toggle(tipe === 'cbt');
        }
        $(document).on('change', '.qrg-tugas-tipe', toggleCbt);
        toggleCbt();

        function renumber() {
            $('#qrg-cbt-soal-list .qrg-cbt-soal').each(function (i) {
                $(this).find('.qrg-cbt-num').text(i + 1);
                $(this).find('textarea, input').each(function () {
                    var name = $(this).attr('name');
                    if (!name) return;
                    name = name.replace(/tugas_soal_cbt\[\d+\]/, 'tugas_soal_cbt[' + i + ']');
                    $(this).attr('name', name);
                });
            });
        }

        $('#qrg-cbt-tambah').on('click', function () {
            var i = $('#qrg-cbt-soal-list .qrg-cbt-soal').length;
            var html = '' +
                '<div class="qrg-cbt-soal" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:12px;">' +
                '<div style="display:flex; justify-content:space-between; gap:10px; margin-bottom:8px;">' +
                '<strong>Soal <span class="qrg-cbt-num">' + (i + 1) + '</span></strong>' +
                '<button type="button" class="button-link-delete qrg-cbt-hapus">Hapus soal</button></div>' +
                '<textarea name="tugas_soal_cbt[' + i + '][pertanyaan]" rows="2" class="large-text" placeholder="Tulis pertanyaan..."></textarea>' +
                '<div style="margin-top:10px;">';
            for (var o = 0; o < 4; o++) {
                html += '<div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">' +
                    '<label style="white-space:nowrap;"><input type="radio" name="tugas_soal_cbt[' + i + '][kunci]" value="' + o + '"' + (o === 0 ? ' checked' : '') + '> ' + String.fromCharCode(65 + o) + '</label>' +
                    '<input type="text" name="tugas_soal_cbt[' + i + '][opsi][]" class="regular-text" placeholder="Opsi ' + String.fromCharCode(65 + o) + '" style="flex:1;"></div>';
            }
            html += '</div></div>';
            $('#qrg-cbt-soal-list').append(html);
        });

        $(document).on('click', '.qrg-cbt-hapus', function () {
            if ($('#qrg-cbt-soal-list .qrg-cbt-soal').length <= 1) {
                alert('Minimal satu soal.');
                return;
            }
            $(this).closest('.qrg-cbt-soal').remove();
            renumber();
        });

        // Pastikan form post mendukung upload file
        $('form#post').attr('enctype', 'multipart/form-data');
    })(jQuery);
    </script>
    <?php
}

function qrg_tugas_monitoring_callback( $post ) {
    if ( $post->post_status === 'auto-draft' || ! $post->ID ) {
        echo '<p style="color:#666;">Simpan tugas terlebih dahulu untuk melihat monitoring.</p>';
        return;
    }

    $meta       = qrg_get_tugas_meta( $post->ID );
    $summary    = qrg_tugas_monitoring_summary( $post->ID );
    $siswa_list = $meta['kelas_id'] ? qrg_get_siswa_by_kelas( $meta['kelas_id'] ) : [];
    $hasil_url  = admin_url( 'edit.php?post_type=master_jawaban&tugas_id=' . (int) $post->ID );
    ?>
    <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:10px 14px; min-width:110px;">
            <div style="font-size:12px; color:#1d4ed8;">Total Siswa</div>
            <div style="font-size:22px; font-weight:700;"><?php echo (int) $summary['total']; ?></div>
        </div>
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:6px; padding:10px 14px; min-width:110px;">
            <div style="font-size:12px; color:#047857;">Sudah Mengumpulkan</div>
            <div style="font-size:22px; font-weight:700;"><?php echo (int) $summary['submitted']; ?></div>
        </div>
        <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:6px; padding:10px 14px; min-width:110px;">
            <div style="font-size:12px; color:#c2410c;">Belum</div>
            <div style="font-size:22px; font-weight:700;"><?php echo (int) $summary['pending']; ?></div>
        </div>
        <div style="background:#f5f3ff; border:1px solid #ddd6fe; border-radius:6px; padding:10px 14px; min-width:110px;">
            <div style="font-size:12px; color:#6d28d9;">Sudah Dinilai</div>
            <div style="font-size:22px; font-weight:700;"><?php echo (int) $summary['graded']; ?></div>
        </div>
        <div style="align-self:center;">
            <a class="button button-primary" href="<?php echo esc_url( $hasil_url ); ?>">Lihat Semua Jawaban</a>
        </div>
    </div>

    <?php if ( empty( $siswa_list ) ) : ?>
        <p style="color:#888;">Belum ada siswa di kelas tujuan, atau kelas belum dipilih.</p>
        <?php
        return;
    endif;
    ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Siswa</th>
                <th>Status</th>
                <th>Dikirim</th>
                <th>Nilai</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $siswa_list as $siswa ) :
                $jid     = qrg_find_jawaban( $post->ID, $siswa->ID );
                $status  = $jid ? get_post_meta( $jid, '_jawaban_status', true ) : '';
                $nilai   = $jid ? get_post_meta( $jid, '_jawaban_nilai', true ) : '';
                $dikirim = $jid ? get_the_date( 'd/m/Y H:i', $jid ) : '-';
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $siswa->post_title ); ?></strong></td>
                    <td>
                        <?php if ( ! $jid ) : ?>
                            <span style="color:#b45309;">Belum mengumpulkan</span>
                        <?php elseif ( $status === 'Dinilai' ) : ?>
                            <span style="color:#15803d; font-weight:600;">Dinilai</span>
                        <?php else : ?>
                            <span style="color:#1d4ed8; font-weight:600;">Sudah dikirim</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $dikirim ); ?></td>
                    <td><?php echo $nilai !== '' ? esc_html( $nilai ) : '-'; ?></td>
                    <td>
                        <?php if ( $jid ) : ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $jid ) ); ?>">Nilai / Lihat</a>
                        <?php else : ?>
                            <span style="color:#aaa;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

add_action( 'save_post_master_tugas', 'qrg_save_tugas_details' );
function qrg_save_tugas_details( $post_id ) {
    if ( ! isset( $_POST['qrg_tugas_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_tugas_nonce'], 'qrg_save_tugas_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    if ( isset( $_POST['tugas_guru_id'] ) ) {
        update_post_meta( $post_id, '_tugas_guru_id', absint( $_POST['tugas_guru_id'] ) );
    }
    if ( isset( $_POST['tugas_kelas_id'] ) ) {
        update_post_meta( $post_id, '_tugas_kelas_id', absint( $_POST['tugas_kelas_id'] ) );
    }
    if ( isset( $_POST['tugas_mapel_id'] ) ) {
        $mapel_id = absint( $_POST['tugas_mapel_id'] );
        update_post_meta( $post_id, '_tugas_mapel_id', $mapel_id );
        if ( $mapel_id && function_exists( 'qrg_get_mapel_label' ) ) {
            // Simpan juga label teks agar kompatibel dengan tampilan lama
            $label = get_the_title( $mapel_id );
            update_post_meta( $post_id, '_tugas_mapel', sanitize_text_field( $label ) );
        } elseif ( ! $mapel_id ) {
            update_post_meta( $post_id, '_tugas_mapel', '' );
        }
    } elseif ( isset( $_POST['tugas_mapel'] ) ) {
        // Fallback kompatibilitas form lama
        update_post_meta( $post_id, '_tugas_mapel', sanitize_text_field( wp_unslash( $_POST['tugas_mapel'] ) ) );
    }
    if ( isset( $_POST['tugas_poin_max'] ) ) {
        update_post_meta( $post_id, '_tugas_poin_max', absint( $_POST['tugas_poin_max'] ) );
    }
    if ( isset( $_POST['tugas_deadline'] ) ) {
        $dl = sanitize_text_field( wp_unslash( $_POST['tugas_deadline'] ) );
        $dl = str_replace( 'T', ' ', $dl );
        update_post_meta( $post_id, '_tugas_deadline', $dl );
    }

    $tipe = isset( $_POST['tugas_tipe'] ) ? sanitize_text_field( wp_unslash( $_POST['tugas_tipe'] ) ) : 'esai';
    if ( ! in_array( $tipe, [ 'esai', 'cbt' ], true ) ) {
        $tipe = 'esai';
    }
    update_post_meta( $post_id, '_tugas_tipe', $tipe );

    if ( $tipe === 'cbt' && isset( $_POST['tugas_soal_cbt'] ) ) {
        update_post_meta( $post_id, '_tugas_soal_cbt', qrg_sanitize_soal_cbt( $_POST['tugas_soal_cbt'] ) );
    }

    // Lampiran: hapus yang dicentang + upload baru
    $lampiran = get_post_meta( $post_id, '_tugas_lampiran', true );
    if ( ! is_array( $lampiran ) ) {
        $lampiran = $lampiran ? [ $lampiran ] : [];
    }
    if ( ! empty( $_POST['tugas_lampiran_hapus'] ) && is_array( $_POST['tugas_lampiran_hapus'] ) ) {
        $hapus = array_map( 'esc_url_raw', wp_unslash( $_POST['tugas_lampiran_hapus'] ) );
        $lampiran = array_values( array_filter( $lampiran, function ( $url ) use ( $hapus ) {
            return ! in_array( $url, $hapus, true );
        } ) );
    }

    if ( ! empty( $_FILES['tugas_lampiran_files']['name'] ) && is_array( $_FILES['tugas_lampiran_files']['name'] ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $files = $_FILES['tugas_lampiran_files'];
        foreach ( $files['name'] as $i => $name ) {
            if ( empty( $name ) ) {
                continue;
            }
            $file = [
                'name'     => $files['name'][ $i ],
                'type'     => $files['type'][ $i ],
                'tmp_name' => $files['tmp_name'][ $i ],
                'error'    => $files['error'][ $i ],
                'size'     => $files['size'][ $i ],
            ];
            $upload = function_exists( 'qrg_handle_upload_safe' )
                ? qrg_handle_upload_safe( $file, qrg_upload_mimes_dokumen() )
                : wp_handle_upload( $file, [ 'test_form' => false ] );
            if ( $upload && ! isset( $upload['error'] ) && ! empty( $upload['url'] ) ) {
                $lampiran[] = esc_url_raw( $upload['url'] );
            }
        }
    }
    update_post_meta( $post_id, '_tugas_lampiran', array_values( array_unique( $lampiran ) ) );
}

// -------------------------------------------------------------------------
// 4. Kolom daftar tugas
// -------------------------------------------------------------------------
add_filter( 'manage_master_tugas_posts_columns', 'qrg_set_tugas_columns' );
function qrg_set_tugas_columns( $columns ) {
    $new = [];
    $new['cb']        = $columns['cb'];
    $new['title']     = 'Judul Tugas';
    $new['tipe']      = 'Tipe';
    $new['mapel']     = 'Mapel';
    $new['kelas']     = 'Kelas';
    $new['guru']      = 'Guru';
    $new['deadline']  = 'Deadline';
    $new['progress']  = 'Progress';
    $new['date']      = 'Dibuat';
    return $new;
}

add_action( 'manage_master_tugas_posts_custom_column', 'qrg_fill_tugas_columns', 10, 2 );
function qrg_fill_tugas_columns( $column, $post_id ) {
    $meta = qrg_get_tugas_meta( $post_id );
    switch ( $column ) {
        case 'tipe':
            $label = qrg_tugas_tipe_label( $meta['tipe'] );
            $color = $meta['tipe'] === 'cbt' ? '#6d28d9' : '#0369a1';
            echo '<span style="color:' . esc_attr( $color ) . '; font-weight:600;">' . esc_html( $label ) . '</span>';
            if ( ! empty( $meta['lampiran'] ) ) {
                echo '<br><span style="color:#64748b; font-size:12px;">' . count( $meta['lampiran'] ) . ' lampiran</span>';
            }
            break;
        case 'mapel':
            echo esc_html( $meta['mapel'] ?: '-' );
            break;
        case 'kelas':
            echo $meta['kelas_id'] ? esc_html( qrg_get_kelas_label( $meta['kelas_id'] ) ) : '-';
            break;
        case 'guru':
            echo $meta['guru_id'] ? esc_html( get_the_title( $meta['guru_id'] ) ) : '-';
            break;
        case 'deadline':
            echo $meta['deadline'] ? esc_html( date_i18n( 'd M Y H:i', strtotime( $meta['deadline'] ) ) ) : '-';
            break;
        case 'progress':
            $s = qrg_tugas_monitoring_summary( $post_id );
            echo '<strong>' . (int) $s['submitted'] . '/' . (int) $s['total'] . '</strong> mengumpulkan';
            echo '<br><span style="color:#6d28d9;">' . (int) $s['graded'] . ' dinilai</span>';
            break;
    }
}

// -------------------------------------------------------------------------
// 5. Meta box & kolom Jawaban (penilaian)
// -------------------------------------------------------------------------
add_action( 'add_meta_boxes', 'qrg_jawaban_meta_boxes' );
function qrg_jawaban_meta_boxes() {
    add_meta_box( 'jawaban_detail', 'Detail Jawaban & Penilaian', 'qrg_jawaban_fields_callback', 'master_jawaban', 'normal', 'high' );
}

function qrg_jawaban_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_jawaban_meta', 'qrg_jawaban_nonce' );

    $tugas_id  = (int) get_post_meta( $post->ID, '_jawaban_tugas_id', true );
    $siswa_id  = (int) get_post_meta( $post->ID, '_jawaban_siswa_id', true );
    $isi       = get_post_meta( $post->ID, '_jawaban_isi', true );
    $file_url  = get_post_meta( $post->ID, '_jawaban_file_url', true );
    $nilai     = get_post_meta( $post->ID, '_jawaban_nilai', true );
    $catatan   = get_post_meta( $post->ID, '_jawaban_catatan_guru', true );
    $status    = get_post_meta( $post->ID, '_jawaban_status', true ) ?: 'Dikirim';
    $jawaban_cbt = get_post_meta( $post->ID, '_jawaban_cbt', true );
    if ( ! is_array( $jawaban_cbt ) ) {
        $jawaban_cbt = [];
    }
    $tugas_meta = $tugas_id ? qrg_get_tugas_meta( $tugas_id ) : [];
    $tipe       = isset( $tugas_meta['tipe'] ) ? $tugas_meta['tipe'] : 'esai';
    $poin_max  = $tugas_id ? (int) get_post_meta( $tugas_id, '_tugas_poin_max', true ) : 100;
    if ( $poin_max <= 0 ) {
        $poin_max = 100;
    }
    ?>
    <table class="form-table">
        <tr>
            <th>Tugas</th>
            <td>
                <?php if ( $tugas_id ) : ?>
                    <a href="<?php echo esc_url( get_edit_post_link( $tugas_id ) ); ?>"><strong><?php echo esc_html( get_the_title( $tugas_id ) ); ?></strong></a>
                    <span style="color:#64748b;"> · <?php echo esc_html( qrg_tugas_tipe_label( $tipe ) ); ?></span>
                <?php else : ?>
                    -
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Siswa</th>
            <td>
                <?php if ( $siswa_id ) : ?>
                    <a href="<?php echo esc_url( get_edit_post_link( $siswa_id ) ); ?>"><strong><?php echo esc_html( get_the_title( $siswa_id ) ); ?></strong></a>
                    <span style="color:#64748b;"> · <?php echo esc_html( qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) ) ); ?></span>
                <?php else : ?>
                    -
                <?php endif; ?>
            </td>
        </tr>
        <?php if ( $tipe === 'cbt' && ! empty( $tugas_meta['soal_cbt'] ) ) : ?>
        <tr>
            <th>Jawaban CBT</th>
            <td>
                <ol style="margin:0; padding-left:18px;">
                    <?php foreach ( $tugas_meta['soal_cbt'] as $i => $soal ) :
                        $pilih = isset( $jawaban_cbt[ $i ] ) ? (int) $jawaban_cbt[ $i ] : -1;
                        $kunci = isset( $soal['kunci'] ) ? (int) $soal['kunci'] : -1;
                        $benar = ( $pilih === $kunci );
                        $label_pilih = ( $pilih >= 0 && isset( $soal['opsi'][ $pilih ] ) )
                            ? chr( 65 + $pilih ) . '. ' . $soal['opsi'][ $pilih ]
                            : '(tidak dijawab)';
                        $label_kunci = isset( $soal['opsi'][ $kunci ] )
                            ? chr( 65 + $kunci ) . '. ' . $soal['opsi'][ $kunci ]
                            : '-';
                        ?>
                        <li style="margin-bottom:10px;">
                            <div><strong><?php echo esc_html( $soal['pertanyaan'] ); ?></strong></div>
                            <div style="color:<?php echo $benar ? '#15803d' : '#b91c1c'; ?>;">
                                Jawaban siswa: <?php echo esc_html( $label_pilih ); ?> <?php echo $benar ? '✓' : '✗'; ?>
                            </div>
                            <?php if ( ! $benar ) : ?>
                                <div style="color:#64748b; font-size:12px;">Kunci: <?php echo esc_html( $label_kunci ); ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </td>
        </tr>
        <?php else : ?>
        <tr>
            <th>Jawaban Teks</th>
            <td>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; white-space:pre-wrap;"><?php echo esc_html( $isi ?: '(kosong)' ); ?></div>
            </td>
        </tr>
        <?php if ( $file_url ) : ?>
        <tr>
            <th>Lampiran</th>
            <td><a class="button" href="<?php echo esc_url( $file_url ); ?>" target="_blank" rel="noopener">Unduh / Lihat File</a></td>
        </tr>
        <?php endif; ?>
        <?php endif; ?>
        <tr>
            <th><label for="jawaban_nilai">Nilai (0–<?php echo (int) $poin_max; ?>)</label></th>
            <td>
                <input type="number" name="jawaban_nilai" id="jawaban_nilai" class="small-text" min="0" max="<?php echo (int) $poin_max; ?>" step="0.1" value="<?php echo esc_attr( $nilai ); ?>">
                <?php if ( $tipe === 'cbt' ) : ?>
                    <p class="description">Nilai CBT dihitung otomatis, bisa diubah manual jika perlu.</p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><label for="jawaban_status">Status</label></th>
            <td>
                <select name="jawaban_status" id="jawaban_status">
                    <option value="Dikirim" <?php selected( $status, 'Dikirim' ); ?>>Dikirim</option>
                    <option value="Dinilai" <?php selected( $status, 'Dinilai' ); ?>>Dinilai</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="jawaban_catatan_guru">Catatan Guru</label></th>
            <td><textarea name="jawaban_catatan_guru" id="jawaban_catatan_guru" rows="4" class="large-text"><?php echo esc_textarea( $catatan ); ?></textarea></td>
        </tr>
    </table>
    <?php
}

add_action( 'save_post_master_jawaban', 'qrg_save_jawaban_details' );
function qrg_save_jawaban_details( $post_id ) {
    if ( ! isset( $_POST['qrg_jawaban_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_jawaban_nonce'], 'qrg_save_jawaban_meta' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    if ( isset( $_POST['jawaban_nilai'] ) ) {
        update_post_meta( $post_id, '_jawaban_nilai', sanitize_text_field( wp_unslash( $_POST['jawaban_nilai'] ) ) );
    }
    if ( isset( $_POST['jawaban_status'] ) ) {
        update_post_meta( $post_id, '_jawaban_status', sanitize_text_field( wp_unslash( $_POST['jawaban_status'] ) ) );
    }
    if ( isset( $_POST['jawaban_catatan_guru'] ) ) {
        update_post_meta( $post_id, '_jawaban_catatan_guru', sanitize_textarea_field( wp_unslash( $_POST['jawaban_catatan_guru'] ) ) );
    }

    // Auto set Dinilai jika nilai diisi
    if ( isset( $_POST['jawaban_nilai'] ) && $_POST['jawaban_nilai'] !== '' && empty( $_POST['jawaban_status'] ) ) {
        update_post_meta( $post_id, '_jawaban_status', 'Dinilai' );
    }
}

add_filter( 'manage_master_jawaban_posts_columns', 'qrg_set_jawaban_columns' );
function qrg_set_jawaban_columns( $columns ) {
    return [
        'cb'      => $columns['cb'],
        'title'   => 'Ringkasan',
        'tugas'   => 'Tugas',
        'siswa'   => 'Siswa',
        'status'  => 'Status',
        'nilai'   => 'Nilai',
        'date'    => 'Dikirim',
    ];
}

add_action( 'manage_master_jawaban_posts_custom_column', 'qrg_fill_jawaban_columns', 10, 2 );
function qrg_fill_jawaban_columns( $column, $post_id ) {
    $tugas_id = (int) get_post_meta( $post_id, '_jawaban_tugas_id', true );
    $siswa_id = (int) get_post_meta( $post_id, '_jawaban_siswa_id', true );
    switch ( $column ) {
        case 'tugas':
            echo $tugas_id ? '<a href="' . esc_url( get_edit_post_link( $tugas_id ) ) . '">' . esc_html( get_the_title( $tugas_id ) ) . '</a>' : '-';
            break;
        case 'siswa':
            echo $siswa_id ? esc_html( get_the_title( $siswa_id ) ) : '-';
            break;
        case 'status':
            $status = get_post_meta( $post_id, '_jawaban_status', true ) ?: 'Dikirim';
            $color  = $status === 'Dinilai' ? '#15803d' : '#1d4ed8';
            echo '<span style="color:' . esc_attr( $color ) . '; font-weight:600;">' . esc_html( $status ) . '</span>';
            break;
        case 'nilai':
            $nilai = get_post_meta( $post_id, '_jawaban_nilai', true );
            echo $nilai !== '' ? esc_html( $nilai ) : '-';
            break;
    }
}

// Filter hasil pengerjaan by tugas_id
add_action( 'pre_get_posts', 'qrg_filter_jawaban_by_tugas' );
function qrg_filter_jawaban_by_tugas( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( $query->get( 'post_type' ) !== 'master_jawaban' ) {
        return;
    }
    if ( empty( $_GET['tugas_id'] ) ) {
        return;
    }
    $tugas_id = absint( $_GET['tugas_id'] );
    $meta_query = (array) $query->get( 'meta_query' );
    $meta_query[] = [
        'key'   => '_jawaban_tugas_id',
        'value' => $tugas_id,
    ];
    $query->set( 'meta_query', $meta_query );
}

add_action( 'restrict_manage_posts', 'qrg_jawaban_tugas_filter_notice' );
function qrg_jawaban_tugas_filter_notice() {
    global $typenow;
    if ( $typenow !== 'master_jawaban' || empty( $_GET['tugas_id'] ) ) {
        return;
    }
    $tugas_id = absint( $_GET['tugas_id'] );
    echo '<span style="margin-left:8px;">Filter tugas: <strong>' . esc_html( get_the_title( $tugas_id ) ) . '</strong></span> ';
    echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=master_jawaban' ) ) . '" class="button">Reset</a>';
}

// Tombol kembali ke Mata Pelajaran + Hasil Pengerjaan di halaman Daftar Tugas
add_action( 'admin_footer-edit.php', 'qrg_tugas_header_action_button' );
add_action( 'admin_footer-post.php', 'qrg_tugas_header_action_button' );
add_action( 'admin_footer-post-new.php', 'qrg_tugas_header_action_button' );
function qrg_tugas_header_action_button() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'master_tugas' || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $mapel_url = admin_url( 'edit.php?post_type=master_mapel' );
    $hasil_url = admin_url( 'edit.php?post_type=master_jawaban' );
    $is_list   = ( $screen->base === 'edit' );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        if (!$wrap.length) return;
        var $existing = $wrap.siblings('.page-title-action').first();
        var $back = $('<a href="<?php echo esc_url( $mapel_url ); ?>" class="page-title-action">← Mata Pelajaran</a>');
        if ($existing.length) {
            $back.insertBefore($existing);
        } else {
            $back.insertAfter($wrap);
        }
        <?php if ( $is_list ) : ?>
        $('<a href="<?php echo esc_url( $hasil_url ); ?>" class="page-title-action">Hasil Pengerjaan</a>')
            .insertAfter($existing.length ? $existing : $back);
        <?php endif; ?>
    });
    </script>
    <?php
}

// Tombol kembali ke Daftar Tugas di halaman Hasil Pengerjaan
add_action( 'admin_footer-edit.php', 'qrg_jawaban_header_back_button' );
function qrg_jawaban_header_back_button() {
    global $typenow;
    if ( $typenow !== 'master_jawaban' || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $tugas_url = admin_url( 'edit.php?post_type=master_tugas' );
    $mapel_url = admin_url( 'edit.php?post_type=master_mapel' );
    ?>
    <script>
    jQuery(function($) {
        var $h1 = $('.wrap h1').first();
        if (!$h1.length) {
            return;
        }
        $h1.after(
            '<a href="<?php echo esc_url( $mapel_url ); ?>" class="page-title-action qrg-back-pembelajaran">← Mata Pelajaran</a>' +
            '<a href="<?php echo esc_url( $tugas_url ); ?>" class="page-title-action qrg-back-pembelajaran">← Daftar Tugas</a>'
        );
    });
    </script>
    <?php
}

// Saat di Daftar Tugas / Hasil Pengerjaan, tandai menu Mata Pelajaran aktif
add_filter( 'parent_file', 'qrg_tugas_parent_file' );
function qrg_tugas_parent_file( $parent_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && in_array( $screen->post_type, [ 'master_tugas', 'master_jawaban' ], true ) ) {
        return 'master-data-plugin';
    }
    if ( isset( $_GET['post'] ) ) {
        $pt = get_post_type( absint( $_GET['post'] ) );
        if ( in_array( $pt, [ 'master_tugas', 'master_jawaban' ], true ) ) {
            return 'master-data-plugin';
        }
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_tugas_submenu_file' );
function qrg_tugas_submenu_file( $submenu_file ) {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && in_array( $screen->post_type, [ 'master_tugas', 'master_jawaban' ], true ) ) {
        return 'edit.php?post_type=master_mapel';
    }
    if ( isset( $_GET['post'] ) ) {
        $pt = get_post_type( absint( $_GET['post'] ) );
        if ( in_array( $pt, [ 'master_tugas', 'master_jawaban' ], true ) ) {
            return 'edit.php?post_type=master_mapel';
        }
    }
    return $submenu_file;
}

// Sembunyikan submenu jawaban / tugas jika masih muncul
add_action( 'admin_menu', 'qrg_hide_jawaban_add_new', 999 );
function qrg_hide_jawaban_add_new() {
    remove_submenu_page( 'master-data-plugin', 'edit.php?post_type=master_jawaban' );
    remove_submenu_page( 'master-data-plugin', 'post-new.php?post_type=master_jawaban' );
    remove_submenu_page( 'master-data-plugin', 'edit.php?post_type=master_tugas' );
    remove_submenu_page( 'master-data-plugin', 'post-new.php?post_type=master_tugas' );
}

add_action( 'admin_head', 'qrg_hide_jawaban_add_button' );
function qrg_hide_jawaban_add_button() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'master_jawaban' ) {
        return;
    }
    echo '<style>
        .wrap .page-title-action:not(.qrg-back-pembelajaran){display:none!important;}
        .wrap .page-title-action.qrg-back-pembelajaran{display:inline-block!important;}
    </style>';
}

// -------------------------------------------------------------------------
// 6. Shortcode portal siswa [portal_pembelajaran]
// -------------------------------------------------------------------------
add_shortcode( 'portal_pembelajaran', 'qrg_portal_pembelajaran_shortcode' );
function qrg_portal_pembelajaran_shortcode() {
    ob_start();

    $error   = '';
    $success = '';
    $auth_id = qrg_siswa_portal_auth_id();

    if ( isset( $_GET['qrg_portal_logout'] ) || isset( $_GET['qrg_logout'] ) ) {
        qrg_siswa_portal_logout();
        $auth_id = 0;
    }

    // Login NIS + rate limit
    if ( isset( $_POST['qrg_portal_login'] ) && wp_verify_nonce( $_POST['qrg_portal_nonce'], 'qrg_portal_login' ) ) {
        $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'login_nis', 8, 900 ) : true;
        if ( is_wp_error( $rl ) ) {
            $error = $rl->get_error_message();
        } else {
            $nis = isset( $_POST['qrg_portal_nis'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_portal_nis'] ) ) : '';
            $siswa_id = function_exists( 'qrg_find_siswa_by_nis' ) ? qrg_find_siswa_by_nis( $nis ) : 0;
            if ( $siswa_id && function_exists( 'qrg_pembayaran_verify_nis' ) && qrg_pembayaran_verify_nis( $siswa_id, $nis ) ) {
                if ( function_exists( 'qrg_rate_limit_clear' ) ) {
                    qrg_rate_limit_clear( 'login_nis' );
                }
                qrg_siswa_portal_set_auth( $siswa_id );
                $auth_id = $siswa_id;
            } else {
                if ( function_exists( 'qrg_rate_limit_hit' ) ) {
                    qrg_rate_limit_hit( 'login_nis', 900 );
                }
                $error = 'Kredensial salah. Silakan coba lagi.';
            }
        }
    }

    // Submit jawaban (esai / CBT)
    if ( $auth_id && isset( $_POST['qrg_submit_jawaban'] ) ) {
        $submit  = qrg_process_submit_jawaban( $auth_id );
        $error   = $submit['error'] ?: $error;
        $success = $submit['success'] ?: $success;
    }

    $view_tugas = isset( $_GET['tugas'] ) ? absint( $_GET['tugas'] ) : 0;
    $google_on  = function_exists( 'qrg_google_auth_enabled' ) && qrg_google_auth_enabled();
    $google_cid = $google_on ? qrg_get_google_client_id() : '';
    $ajax_url   = admin_url( 'admin-ajax.php' );
    $portal_nonce = wp_create_nonce( 'qrg_google_portal_login' );
    $redirect_url = ( is_singular() || is_page() ) ? get_permalink() : home_url( add_query_arg( [] ) );
    if ( ! $redirect_url ) {
        $redirect_url = home_url( '/' );
    }

    // Enqueue Google script on frontend when needed
    if ( ! $auth_id && $google_on ) {
        wp_enqueue_script( 'qrg-google-gsi', 'https://accounts.google.com/gsi/client', [], null, true );
    }
    ?>
    <div class="qrg-portal-wrap" style="max-width:820px; margin:20px auto; font-family:sans-serif; color:#1e293b;">
        <style>
            .qrg-portal-wrap .card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:18px 20px; margin-bottom:14px; }
            .qrg-portal-wrap .err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:10px 12px; border-radius:6px; margin-bottom:12px; }
            .qrg-portal-wrap .ok { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; padding:10px 12px; border-radius:6px; margin-bottom:12px; }
            .qrg-portal-wrap .btn { display:inline-block; background:#2271b1; color:#fff; border:none; padding:10px 14px; border-radius:6px; font-weight:700; cursor:pointer; text-decoration:none; font-size:13px; }
            .qrg-portal-wrap .btn-muted { background:#64748b; }
            .qrg-portal-wrap .btn-green { background:#16a34a; }
            .qrg-portal-wrap textarea, .qrg-portal-wrap input[type=password], .qrg-portal-wrap input[type=text] { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:6px; }
            .qrg-portal-wrap .meta { color:#64748b; font-size:13px; }
            .qrg-portal-wrap .badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:700; }
            .qrg-portal-wrap .badge-ok { background:#dcfce7; color:#166534; }
            .qrg-portal-wrap .badge-wait { background:#fef3c7; color:#92400e; }
            .qrg-portal-wrap .badge-done { background:#dbeafe; color:#1d4ed8; }
            .qrg-portal-login { max-width:360px; margin:0 auto; }
            .qrg-portal-login h3 { text-align:center; margin:0 0 6px; }
            .qrg-portal-login .sub { text-align:center; color:#64748b; font-size:14px; margin:0 0 18px; }
            .qrg-google-wrap { text-align:center; margin-bottom:18px; }
            .qrg-google-wrap .g_id_signin { display:inline-flex !important; justify-content:center; }
            .qrg-google-status { font-size:13px; color:#64748b; margin-top:10px; min-height:18px; }
            .qrg-divider { display:flex; align-items:center; gap:10px; color:#94a3b8; font-size:12px; margin:18px 0; }
            .qrg-divider::before, .qrg-divider::after { content:""; flex:1; height:1px; background:#e2e8f0; }
            .qrg-nis-toggle { display:block; width:100%; text-align:center; font-size:13px; color:#2271b1; cursor:pointer; margin-bottom:12px; background:none; border:none; text-decoration:underline; }
            .qrg-nis-panel { display:none; }
            .qrg-nis-panel.open { display:block; }
            .qrg-calc { max-width:300px; margin:0 auto; }
            .qrg-calc-screen {
                background:#0f172a; color:#e2e8f0; border-radius:12px; padding:18px 16px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size:28px; letter-spacing:6px; text-align:right; min-height:36px;
                margin-bottom:14px; box-shadow:inset 0 2px 8px rgba(0,0,0,.35);
                word-break:break-all; line-height:1.2;
            }
            .qrg-calc-screen.empty { color:#64748b; letter-spacing:2px; font-size:15px; text-align:center; }
            .qrg-calc-hint { text-align:center; font-size:13px; color:#64748b; margin:0 0 14px; }
            .qrg-calc-pad { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }
            .qrg-calc-pad button {
                appearance:none; border:none; border-radius:12px; padding:18px 0;
                font-size:22px; font-weight:700; cursor:pointer;
                background:#e2e8f0; color:#0f172a;
                box-shadow:0 2px 0 #94a3b8; transition:transform .05s, background .15s;
                user-select:none; -webkit-tap-highlight-color:transparent;
            }
            .qrg-calc-pad button:active { transform:translateY(2px); box-shadow:none; }
            .qrg-calc-pad button.key-fn { background:#cbd5e1; color:#334155; font-size:16px; }
            .qrg-calc-pad button.key-ok {
                background:#16a34a; color:#fff; box-shadow:0 2px 0 #15803d;
                grid-column:1 / -1; font-size:16px; letter-spacing:.5px;
            }
            .qrg-calc-pad button.key-ok:disabled { opacity:.45; cursor:not-allowed; }
        </style>

        <?php if ( $error && $auth_id ) : ?><div class="err"><?php echo esc_html( $error ); ?></div><?php endif; ?>
        <?php if ( $success ) : ?><div class="ok"><?php echo esc_html( $success ); ?></div><?php endif; ?>

        <?php if ( ! $auth_id ) : ?>
            <div class="card qrg-portal-login">
                <h3>Login Pembelajaran</h3>
                <p class="sub">Masuk untuk melihat dan mengumpulkan tugas</p>

                <?php if ( $error ) : ?>
                    <div class="err" id="qrg-login-err"><?php echo esc_html( $error ); ?></div>
                <?php else : ?>
                    <div class="err" id="qrg-login-err" style="display:none;"></div>
                <?php endif; ?>

                <?php if ( $google_on ) : ?>
                    <div class="qrg-google-wrap">
                        <p style="font-size:13px; color:#64748b; margin:0 0 12px;">Masuk dengan akun Google yang emailnya terdaftar di Data Siswa.</p>
                        <div id="qrg-google-btn"></div>
                        <div class="qrg-google-status" id="qrg-google-status"></div>
                    </div>
                    <div class="qrg-divider">atau</div>
                    <button type="button" class="qrg-nis-toggle" id="qrg-nis-toggle">Login dengan NIS</button>
                <?php endif; ?>

                <div class="qrg-nis-panel<?php echo $google_on ? '' : ' open'; ?>" id="qrg-nis-panel">
                    <form method="post" id="qrg-calc-form" class="qrg-calc" autocomplete="off">
                        <?php wp_nonce_field( 'qrg_portal_login', 'qrg_portal_nonce' ); ?>
                        <input type="hidden" name="qrg_portal_nis" id="qrg_nis_password" value="" required>
                        <input type="hidden" name="qrg_portal_login" value="1">
                        <p class="qrg-calc-hint">Masukkan NIS dengan keypad</p>
                        <div class="qrg-calc-screen empty" id="qrg-calc-screen" aria-live="polite">NIS</div>
                        <div class="qrg-calc-pad" role="group" aria-label="Keypad NIS">
                            <button type="button" data-key="1">1</button>
                            <button type="button" data-key="2">2</button>
                            <button type="button" data-key="3">3</button>
                            <button type="button" data-key="4">4</button>
                            <button type="button" data-key="5">5</button>
                            <button type="button" data-key="6">6</button>
                            <button type="button" data-key="7">7</button>
                            <button type="button" data-key="8">8</button>
                            <button type="button" data-key="9">9</button>
                            <button type="button" class="key-fn" data-key="clear" title="Hapus semua">C</button>
                            <button type="button" data-key="0">0</button>
                            <button type="button" class="key-fn" data-key="back" title="Hapus satu">⌫</button>
                            <button type="submit" class="key-ok" id="qrg-calc-ok" disabled>Masuk</button>
                        </div>
                    </form>
                </div>

                <script>
                (function () {
                    var toggle = document.getElementById('qrg-nis-toggle');
                    var panel = document.getElementById('qrg-nis-panel');
                    if (toggle && panel) {
                        toggle.addEventListener('click', function () {
                            panel.classList.toggle('open');
                            toggle.textContent = panel.classList.contains('open')
                                ? 'Sembunyikan login NIS'
                                : 'Login dengan NIS';
                        });
                    }

                    var input = document.getElementById('qrg_nis_password');
                    var screen = document.getElementById('qrg-calc-screen');
                    var okBtn = document.getElementById('qrg-calc-ok');
                    var form = document.getElementById('qrg-calc-form');
                    if (!input || !screen || !okBtn || !form) return;
                    var value = '';

                    function render() {
                        input.value = value;
                        if (!value) {
                            screen.textContent = 'NIS';
                            screen.classList.add('empty');
                        } else {
                            screen.textContent = value.replace(/./g, '•');
                            screen.classList.remove('empty');
                        }
                        okBtn.disabled = value.length === 0;
                    }

                    function press(key) {
                        if (key === 'clear') {
                            value = '';
                        } else if (key === 'back') {
                            value = value.slice(0, -1);
                        } else if (/^[0-9]$/.test(key) && value.length < 20) {
                            value += key;
                        }
                        render();
                    }

                    form.querySelectorAll('.qrg-calc-pad button[data-key]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            press(btn.getAttribute('data-key'));
                        });
                    });

                    document.addEventListener('keydown', function (e) {
                        if (panel && !panel.classList.contains('open')) return;
                        if (e.key >= '0' && e.key <= '9') {
                            e.preventDefault();
                            press(e.key);
                        } else if (e.key === 'Backspace') {
                            e.preventDefault();
                            press('back');
                        } else if (e.key === 'Escape') {
                            e.preventDefault();
                            press('clear');
                        } else if (e.key === 'Enter' && value.length) {
                            e.preventDefault();
                            form.submit();
                        }
                    });

                    render();
                })();
                </script>

                <?php if ( $google_on ) : ?>
                <script src="https://accounts.google.com/gsi/client" async defer></script>
                <script>
                window.qrgHandlePortalGoogle = function (response) {
                    var errBox = document.getElementById('qrg-login-err');
                    var status = document.getElementById('qrg-google-status');
                    if (status) status.textContent = 'Memverifikasi akun Google...';
                    if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }

                    var body = new FormData();
                    body.append('action', 'qrg_google_portal_login');
                    body.append('credential', response.credential || '');
                    body.append('nonce', '<?php echo esc_js( $portal_nonce ); ?>');
                    body.append('redirect', '<?php echo esc_js( $redirect_url ); ?>');

                    fetch('<?php echo esc_url( $ajax_url ); ?>', {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin'
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        if (data && data.success && data.data && data.data.redirect) {
                            if (status) status.textContent = 'Berhasil. Mengalihkan...';
                            window.location.href = data.data.redirect;
                            return;
                        }
                        var msg = (data && data.data && data.data.message) ? data.data.message : 'Login Google gagal.';
                        if (errBox) { errBox.textContent = msg; errBox.style.display = 'block'; }
                        if (status) status.textContent = '';
                    }).catch(function () {
                        if (errBox) { errBox.textContent = 'Koneksi gagal. Coba lagi.'; errBox.style.display = 'block'; }
                        if (status) status.textContent = '';
                    });
                };

                function qrgInitPortalGoogle() {
                    if (!window.google || !google.accounts || !google.accounts.id) {
                        setTimeout(qrgInitPortalGoogle, 200);
                        return;
                    }
                    google.accounts.id.initialize({
                        client_id: '<?php echo esc_js( $google_cid ); ?>',
                        callback: window.qrgHandlePortalGoogle,
                        auto_select: false,
                        cancel_on_tap_outside: true
                    });
                    var btn = document.getElementById('qrg-google-btn');
                    if (btn) {
                        google.accounts.id.renderButton(btn, {
                            theme: 'outline', size: 'large', shape: 'rectangular', text: 'signin_with', width: 320
                        });
                    }
                }
                qrgInitPortalGoogle();
                </script>
                <?php endif; ?>
            </div>
        <?php else :
            $base = ( is_singular() || is_page() ) ? get_permalink() : home_url( add_query_arg( [] ) );
            if ( ! $base ) {
                $base = home_url( '/' );
            }
            // Samakan logout query dengan panel
            if ( isset( $_GET['qrg_portal_logout'] ) ) {
                // already handled above
            }
            qrg_render_siswa_tugas_panel( $auth_id, remove_query_arg( [ 'tugas', 'qrg_portal_logout' ], $base ), $view_tugas, $error, $success );
            ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


/**
 * URL pembelajaran siswa → digabung ke portal jadwal /sekolah/jadwal/
 */
function qrg_get_siswa_pembelajaran_url( $siswa_id = 0, $tugas_id = 0 ) {
    if ( function_exists( 'qrg_get_sekolah_portal_url' ) ) {
        $args = $tugas_id ? [ 'tugas' => (int) $tugas_id ] : [];
        return qrg_get_sekolah_portal_url( 'jadwal', $args );
    }
    $post = get_post( $siswa_id );
    if ( ! $post || $post->post_type !== 'master_siswa' ) {
        return home_url( '/sekolah/jadwal/' );
    }
    $url = trailingslashit( get_permalink( $siswa_id ) ) . 'pembelajaran/';
    if ( $tugas_id ) {
        $url = add_query_arg( 'tugas', (int) $tugas_id, $url );
    }
    return $url;
}

// Rewrite: /master_siswa/{slug}/pembelajaran/
add_action( 'init', 'qrg_pembelajaran_rewrite_rules' );
function qrg_pembelajaran_rewrite_rules() {
    add_rewrite_rule(
        '^master_siswa/([^/]+)/pembelajaran/?$',
        'index.php?master_siswa=$matches[1]&qrg_pembelajaran=1',
        'top'
    );
}

add_filter( 'query_vars', 'qrg_pembelajaran_query_vars' );
function qrg_pembelajaran_query_vars( $vars ) {
    $vars[] = 'qrg_pembelajaran';
    return $vars;
}

add_action( 'admin_init', 'qrg_pembelajaran_flush_rewrite_once' );
function qrg_pembelajaran_flush_rewrite_once() {
    if ( get_option( 'qrg_pembelajaran_rewrite_flushed_v1' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'qrg_pembelajaran_rewrite_flushed_v1', 1 );
}

add_action( 'template_redirect', 'qrg_intercept_pembelajaran_url' );
function qrg_intercept_pembelajaran_url() {
    if ( (int) get_query_var( 'qrg_pembelajaran' ) === 1 && is_singular( 'master_siswa' ) ) {
        $auth_id  = function_exists( 'qrg_siswa_portal_auth_id' ) ? qrg_siswa_portal_auth_id() : 0;
        $siswa_id = get_queried_object_id();
        $tugas    = isset( $_GET['tugas'] ) ? absint( $_GET['tugas'] ) : 0;
        if ( function_exists( 'qrg_get_sekolah_portal_url' ) ) {
            if ( $auth_id && (int) $auth_id === (int) $siswa_id ) {
                $args = $tugas ? [ 'tugas' => $tugas ] : [];
                wp_safe_redirect( qrg_get_sekolah_portal_url( 'jadwal', $args ) );
            } else {
                wp_safe_redirect( add_query_arg( 'goto', 'jadwal', qrg_get_sekolah_portal_url() ) );
            }
        } else {
            qrg_render_siswa_pembelajaran_page( $siswa_id );
        }
        exit;
    }
}

/**
 * Proses submit jawaban siswa. Return pesan error atau success.
 *
 * @return array{error:string,success:string}
 */
function qrg_process_submit_jawaban( $auth_id ) {
    $result = [ 'error' => '', 'success' => '' ];
    if ( ! $auth_id || ! isset( $_POST['qrg_submit_jawaban'] ) ) {
        return $result;
    }
    if ( ! wp_verify_nonce( $_POST['qrg_jawaban_submit_nonce'] ?? '', 'qrg_submit_jawaban_' . $auth_id ) ) {
        $result['error'] = 'Sesi tidak valid. Muat ulang halaman.';
        return $result;
    }

    $tugas_id = isset( $_POST['tugas_id'] ) ? absint( $_POST['tugas_id'] ) : 0;
    $isi      = isset( $_POST['jawaban_isi'] ) ? sanitize_textarea_field( wp_unslash( $_POST['jawaban_isi'] ) ) : '';
    $meta     = $tugas_id ? qrg_get_tugas_meta( $tugas_id ) : [];
    $tipe     = isset( $meta['tipe'] ) ? $meta['tipe'] : 'esai';

    $allowed = false;
    if ( $tugas_id ) {
        foreach ( qrg_get_tugas_for_siswa( $auth_id ) as $t ) {
            if ( (int) $t->ID === $tugas_id ) {
                $allowed = true;
                break;
            }
        }
    }

    if ( ! $allowed ) {
        $result['error'] = 'Tugas tidak valid untuk siswa ini.';
        return $result;
    }

    $jawaban_cbt = [];
    if ( $tipe === 'cbt' ) {
        $raw = isset( $_POST['jawaban_cbt'] ) && is_array( $_POST['jawaban_cbt'] ) ? $_POST['jawaban_cbt'] : [];
        foreach ( $meta['soal_cbt'] as $i => $_soal ) {
            $jawaban_cbt[ $i ] = isset( $raw[ $i ] ) ? absint( $raw[ $i ] ) : -1;
        }
        if ( empty( $meta['soal_cbt'] ) ) {
            $result['error'] = 'Soal CBT belum tersedia.';
            return $result;
        }
    } elseif ( $isi === '' && empty( $_FILES['jawaban_file']['name'] ) ) {
        $result['error'] = 'Isi jawaban teks atau unggah file.';
        return $result;
    }

    $existing = qrg_find_jawaban( $tugas_id, $auth_id );
    $file_url = $existing ? get_post_meta( $existing, '_jawaban_file_url', true ) : '';

    if ( $tipe !== 'cbt' && ! empty( $_FILES['jawaban_file']['name'] ) ) {
        $upload = function_exists( 'qrg_handle_upload_safe' )
            ? qrg_handle_upload_safe( $_FILES['jawaban_file'], qrg_upload_mimes_dokumen() )
            : null;
        if ( is_array( $upload ) && ! empty( $upload['url'] ) && empty( $upload['error'] ) ) {
            $file_url = esc_url_raw( $upload['url'] );
        } else {
            $result['error'] = isset( $upload['error'] ) ? $upload['error'] : 'Upload gagal atau tipe file tidak diizinkan.';
            return $result;
        }
    }

    $title = 'Jawaban: ' . get_the_title( $auth_id ) . ' — ' . get_the_title( $tugas_id );
    if ( $existing ) {
        $jawaban_id = $existing;
        wp_update_post( [
            'ID'         => $jawaban_id,
            'post_title' => $title,
        ] );
    } else {
        $jawaban_id = wp_insert_post( [
            'post_type'   => 'master_jawaban',
            'post_status' => 'publish',
            'post_title'  => $title,
        ] );
    }

    if ( $jawaban_id && ! is_wp_error( $jawaban_id ) ) {
        update_post_meta( $jawaban_id, '_jawaban_tugas_id', $tugas_id );
        update_post_meta( $jawaban_id, '_jawaban_siswa_id', $auth_id );
        update_post_meta( $jawaban_id, '_jawaban_isi', $isi );
        update_post_meta( $jawaban_id, '_jawaban_file_url', $file_url );

        if ( $tipe === 'cbt' ) {
            $nilai = qrg_hitung_nilai_cbt( $meta['soal_cbt'], $jawaban_cbt, $meta['poin_max'] );
            update_post_meta( $jawaban_id, '_jawaban_cbt', $jawaban_cbt );
            update_post_meta( $jawaban_id, '_jawaban_nilai', $nilai );
            update_post_meta( $jawaban_id, '_jawaban_status', 'Dinilai' );
            $result['success'] = 'Jawaban CBT berhasil dikirim. Nilai otomatis: ' . $nilai;
        } else {
            update_post_meta( $jawaban_id, '_jawaban_status', 'Dikirim' );
            $result['success'] = 'Jawaban berhasil dikirim.';
        }
    } else {
        $result['error'] = 'Gagal menyimpan jawaban.';
    }

    return $result;
}

/**
 * HTML daftar / detail tugas (setelah login).
 */
/**
 * Panel daftar / detail tugas siswa.
 *
 * @param int    $siswa_id   ID siswa.
 * @param string $base_url   URL dasar pembelajaran.
 * @param int    $view_tugas ID tugas (0 = daftar).
 * @param string $error      Pesan error.
 * @param string $success    Pesan sukses.
 * @param array  $opts       embedded=true sembunyikan header/logout (portal /sekolah/).
 */
function qrg_render_siswa_tugas_panel( $siswa_id, $base_url, $view_tugas = 0, $error = '', $success = '', $opts = [] ) {
    $siswa_id   = (int) $siswa_id;
    $nama       = get_the_title( $siswa_id );
    $kelas      = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $logout_url = add_query_arg( 'qrg_logout', '1', $base_url );
    $tugas_list = qrg_get_tugas_for_siswa( $siswa_id );
    $embedded   = ! empty( $opts['embedded'] );
    $standalone = ! $embedded;
    ?>
    <?php if ( $standalone ) : ?>
    <style>
        .qrg-sk-ajar {
            --ios-bg: #F2F2F7; --ios-card: #FFFFFF; --ios-ink: #1C1C1E;
            --ios-secondary: #8E8E93; --ios-blue: #007AFF; --ios-green: #34C759;
            --ios-red: #FF3B30; --ios-fill: #E5E5EA; --ios-sep: rgba(60,60,67,.12);
            --ios-shadow: 0 8px 28px rgba(0,0,0,.06); --ios-radius: 16px;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Helvetica, Arial, sans-serif;
            color: var(--ios-ink); max-width: 430px; margin: 0 auto;
        }
        .qrg-sk-ajar .err { background:rgba(255,59,48,.1); color:var(--ios-red); border:0; padding:12px 14px; border-radius:12px; margin-bottom:14px; font-size:14px; font-weight:500; }
        .qrg-sk-ajar .ok { background:rgba(52,199,89,.12); color:#248A3D; border:0; padding:12px 14px; border-radius:12px; margin-bottom:14px; font-size:14px; }
        .qrg-sk-ajar .btn { display:inline-flex; align-items:center; justify-content:center; background:var(--ios-blue); color:#fff!important; border:none; padding:12px 18px; border-radius:980px; font-size:15px; font-weight:600; cursor:pointer; text-decoration:none; min-height:44px; }
        .qrg-sk-ajar .btn-muted { background:var(--ios-fill); color:var(--ios-ink)!important; }
        .qrg-sk-ajar .btn-green { background:var(--ios-green); }
        .qrg-sk-ajar .topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:18px; }
        .qrg-sk-ajar .qrg-ajar-back { display:inline-flex; align-items:center; gap:4px; margin:0 0 14px; padding:8px 14px 8px 10px; border-radius:980px; background:var(--ios-fill); color:var(--ios-blue); text-decoration:none; font-size:14px; font-weight:600; min-height:36px; }
        .qrg-sk-ajar .qrg-ajar-list { background:var(--ios-card); border-radius:var(--ios-radius); overflow:hidden; box-shadow:var(--ios-shadow); }
        .qrg-sk-ajar .qrg-ajar-item { display:flex; align-items:center; gap:12px; padding:14px; text-decoration:none; color:inherit; border-bottom:1px solid var(--ios-sep); }
        .qrg-sk-ajar .qrg-ajar-item:last-child { border-bottom:0; }
        .qrg-sk-ajar .qrg-ajar-item:active { background:#E5E5EA; }
        .qrg-sk-ajar .qrg-ajar-item__body { flex:1; min-width:0; }
        .qrg-sk-ajar .qrg-ajar-item__title { display:block; font-size:16px; font-weight:600; margin:0 0 4px; }
        .qrg-sk-ajar .qrg-ajar-item__meta { display:block; font-size:13px; color:var(--ios-secondary); margin:0 0 8px; }
        .qrg-sk-ajar .qrg-ajar-item__chev { width:8px; height:8px; flex-shrink:0; border-right:2px solid #C7C7CC; border-top:2px solid #C7C7CC; transform:rotate(45deg); }
        .qrg-sk-ajar .qrg-ajar-badge { display:inline-flex; padding:3px 10px; border-radius:980px; font-size:12px; font-weight:600; }
        .qrg-sk-ajar .qrg-ajar-badge--wait { background:rgba(255,149,0,.14); color:#C93400; }
        .qrg-sk-ajar .qrg-ajar-badge--sent { background:rgba(0,122,255,.12); color:var(--ios-blue); }
        .qrg-sk-ajar .qrg-ajar-badge--ok { background:rgba(52,199,89,.14); color:#1B7A31; }
        .qrg-sk-ajar .qrg-ajar-badge--type { background:var(--ios-fill); color:var(--ios-ink); }
        .qrg-sk-ajar .qrg-ajar-badge--late { background:rgba(255,59,48,.12); color:var(--ios-red); }
        .qrg-sk-ajar .qrg-ajar-empty { text-align:center; padding:28px 18px; color:var(--ios-secondary); font-size:15px; background:var(--ios-card); border-radius:var(--ios-radius); box-shadow:var(--ios-shadow); }
        .qrg-sk-ajar .qrg-ajar-card { background:var(--ios-card); border-radius:var(--ios-radius); padding:16px; box-shadow:var(--ios-shadow); margin-bottom:12px; }
        .qrg-sk-ajar .qrg-ajar-card h3 { margin:0 0 8px; font-size:20px; font-weight:700; }
        .qrg-sk-ajar .qrg-ajar-card h4 { margin:18px 0 10px; font-size:15px; font-weight:600; }
        .qrg-sk-ajar .qrg-ajar-meta-row { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:0 0 12px; font-size:13px; color:var(--ios-secondary); }
        .qrg-sk-ajar .qrg-ajar-body { font-size:15px; line-height:1.55; margin:0 0 14px; }
        .qrg-sk-ajar .qrg-ajar-attach { background:var(--ios-bg); border-radius:12px; padding:12px 14px; margin-bottom:14px; }
        .qrg-sk-ajar .qrg-ajar-attach strong { display:block; font-size:13px; font-weight:600; margin-bottom:8px; color:var(--ios-secondary); }
        .qrg-sk-ajar .qrg-ajar-attach ul { margin:0; padding:0; list-style:none; }
        .qrg-sk-ajar .qrg-ajar-attach a { color:var(--ios-blue); text-decoration:none; font-size:14px; font-weight:500; }
        .qrg-sk-ajar .qrg-ajar-soal { background:var(--ios-bg); border-radius:12px; padding:14px; margin-bottom:10px; }
        .qrg-sk-ajar .qrg-ajar-soal__q { margin:0 0 10px; font-weight:600; font-size:15px; }
        .qrg-sk-ajar .qrg-ajar-opt { display:flex; align-items:flex-start; gap:10px; padding:10px 12px; margin-bottom:6px; background:var(--ios-card); border-radius:10px; border:1.5px solid transparent; cursor:pointer; font-size:14px; }
        .qrg-sk-ajar .qrg-ajar-opt:has(input:checked) { border-color:var(--ios-blue); background:rgba(0,122,255,.06); }
        .qrg-sk-ajar textarea { width:100%; box-sizing:border-box; padding:12px 14px; border:0; border-radius:12px; background:var(--ios-bg); font:inherit; font-size:15px; min-height:120px; resize:vertical; }
        .qrg-sk-ajar .qrg-ajar-label { display:block; font-size:13px; font-weight:600; color:var(--ios-secondary); margin:0 0 6px; }
        .qrg-sk-ajar .qrg-ajar-actions { margin-top:16px; }
        .qrg-sk-ajar .qrg-ajar-actions .btn { width:100%; border-radius:14px; }
        .qrg-sk-ajar .qrg-ajar-filters { display:flex; gap:8px; overflow-x:auto; padding:0 0 12px; scrollbar-width:none; }
        .qrg-sk-ajar .qrg-ajar-filter { appearance:none; border:0; flex:0 0 auto; padding:8px 14px; border-radius:980px; background:var(--ios-fill); color:var(--ios-ink); font-size:13px; font-weight:600; cursor:pointer; min-height:36px; white-space:nowrap; }
        .qrg-sk-ajar .qrg-ajar-filter.is-on { background:var(--ios-blue); color:#fff; }
        .qrg-sk-ajar .ios-section__label { margin:0 0 8px 4px; font-size:13px; color:var(--ios-secondary); text-transform:uppercase; letter-spacing:.02em; }
    </style>
    <?php endif; ?>
    <div class="qrg-sk-ajar qrg-tugas-panel">
        <?php if ( $error ) : ?><div class="err"><?php echo esc_html( $error ); ?></div><?php endif; ?>
        <?php if ( $success ) : ?><div class="ok"><?php echo esc_html( $success ); ?></div><?php endif; ?>

        <?php if ( $standalone ) : ?>
        <div class="topbar">
            <div>
                <h1 style="margin:0;font-size:1.75rem;font-weight:800;letter-spacing:-.03em;">Pembelajaran</h1>
                <p style="margin:4px 0 0;color:var(--ios-secondary);font-size:15px;"><?php echo esc_html( $nama ); ?><?php echo $kelas ? ' · Kelas ' . esc_html( $kelas ) : ''; ?></p>
            </div>
            <a class="btn btn-muted" href="<?php echo esc_url( $logout_url ); ?>">Keluar</a>
        </div>
        <?php endif; ?>

        <?php
        if ( $view_tugas ) :
            $tugas = get_post( $view_tugas );
            $ok    = false;
            foreach ( $tugas_list as $t ) {
                if ( (int) $t->ID === $view_tugas ) {
                    $ok = true;
                    break;
                }
            }
            if ( ! $ok || ! $tugas ) :
                echo '<div class="err">Tugas tidak ditemukan.</div>';
                echo '<p><a class="qrg-ajar-back" href="' . esc_url( $base_url ) . '">← Kembali</a></p>';
            else :
                $meta     = qrg_get_tugas_meta( $view_tugas );
                $existing = qrg_find_jawaban( $view_tugas, $siswa_id );
                $isi_old  = $existing ? get_post_meta( $existing, '_jawaban_isi', true ) : '';
                $file_old = $existing ? get_post_meta( $existing, '_jawaban_file_url', true ) : '';
                $nilai    = $existing ? get_post_meta( $existing, '_jawaban_nilai', true ) : '';
                $catatan  = $existing ? get_post_meta( $existing, '_jawaban_catatan_guru', true ) : '';
                $status   = $existing ? get_post_meta( $existing, '_jawaban_status', true ) : '';
                $cbt_old  = $existing ? get_post_meta( $existing, '_jawaban_cbt', true ) : [];
                if ( ! is_array( $cbt_old ) ) {
                    $cbt_old = [];
                }
                $deadline = $meta['deadline'];
                $is_late  = $deadline && strtotime( $deadline ) < time();
                $tipe     = $meta['tipe'];
                ?>
                <a class="qrg-ajar-back" href="<?php echo esc_url( $base_url ); ?>">← Daftar tugas</a>
                <div class="qrg-ajar-card">
                    <h3><?php echo esc_html( $tugas->post_title ); ?></h3>
                    <div class="qrg-ajar-meta-row">
                        <span class="qrg-ajar-badge qrg-ajar-badge--type"><?php echo esc_html( qrg_tugas_tipe_label( $tipe ) ); ?></span>
                        <?php if ( $meta['mapel'] ) : ?>
                            <span><?php echo esc_html( $meta['mapel'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( ! empty( $meta['guru_id'] ) ) : ?>
                            <span>· <?php echo esc_html( get_the_title( $meta['guru_id'] ) ); ?></span>
                        <?php endif; ?>
                        <?php if ( $deadline ) : ?>
                            <span>· <?php echo esc_html( date_i18n( 'd M Y H:i', strtotime( $deadline ) ) ); ?></span>
                            <?php if ( $is_late ) : ?><span class="qrg-ajar-badge qrg-ajar-badge--late">Lewat deadline</span><?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ( trim( (string) $tugas->post_content ) !== '' ) : ?>
                        <div class="qrg-ajar-body"><?php echo wp_kses_post( wpautop( $tugas->post_content ) ); ?></div>
                    <?php endif; ?>

                    <?php if ( ! empty( $meta['lampiran'] ) ) : ?>
                        <div class="qrg-ajar-attach">
                            <strong>Lampiran guru</strong>
                            <ul>
                                <?php foreach ( $meta['lampiran'] as $url ) : ?>
                                    <li><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ( $existing && $status === 'Dinilai' ) : ?>
                        <div class="ok">
                            Sudah dinilai. Nilai: <strong><?php echo esc_html( $nilai !== '' ? $nilai : '-' ); ?></strong>
                            <?php if ( $catatan ) : ?><br>Catatan guru: <?php echo esc_html( $catatan ); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <h4><?php echo $tipe === 'cbt' ? 'Kerjakan soal' : 'Kirim jawaban'; ?></h4>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'qrg_submit_jawaban_' . $siswa_id, 'qrg_jawaban_submit_nonce' ); ?>
                        <input type="hidden" name="tugas_id" value="<?php echo (int) $view_tugas; ?>">

                        <?php if ( $tipe === 'cbt' ) : ?>
                            <?php if ( empty( $meta['soal_cbt'] ) ) : ?>
                                <p style="margin:0;color:var(--ios-secondary);font-size:14px;">Soal CBT belum tersedia.</p>
                            <?php else : ?>
                                <?php foreach ( $meta['soal_cbt'] as $si => $soal ) :
                                    $pilih = isset( $cbt_old[ $si ] ) ? (int) $cbt_old[ $si ] : -1;
                                    ?>
                                    <div class="qrg-ajar-soal">
                                        <p class="qrg-ajar-soal__q"><?php echo (int) $si + 1; ?>. <?php echo esc_html( $soal['pertanyaan'] ); ?></p>
                                        <?php foreach ( (array) $soal['opsi'] as $oi => $opsi ) : ?>
                                            <label class="qrg-ajar-opt">
                                                <input type="radio" name="jawaban_cbt[<?php echo (int) $si; ?>]" value="<?php echo (int) $oi; ?>" <?php checked( $pilih, $oi ); ?> required>
                                                <span><?php echo esc_html( chr( 65 + (int) $oi ) . '. ' . $opsi ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php else : ?>
                            <label class="qrg-ajar-label" for="qrg-jawaban-isi">Jawaban teks</label>
                            <textarea id="qrg-jawaban-isi" name="jawaban_isi" rows="6" placeholder="Tulis jawaban di sini…"><?php echo esc_textarea( $isi_old ); ?></textarea>
                            <div class="qrg-ajar-file">
                                <label class="qrg-ajar-label" for="qrg-jawaban-file">Lampiran (opsional)</label>
                                <input id="qrg-jawaban-file" type="file" name="jawaban_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip">
                                <?php if ( $file_old ) : ?>
                                    <p style="margin:8px 0 0;font-size:13px;color:var(--ios-secondary);">File sebelumnya: <a href="<?php echo esc_url( $file_old ); ?>" target="_blank" rel="noopener" style="color:var(--ios-blue);">Lihat</a></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="qrg-ajar-actions">
                            <button type="submit" name="qrg_submit_jawaban" value="1" class="btn btn-green"><?php echo $existing ? 'Perbarui jawaban' : 'Kumpulkan jawaban'; ?></button>
                        </div>
                    </form>
                </div>
                <?php
            endif;
        else :
            $rows = [];
            foreach ( $tugas_list as $tugas ) {
                $meta     = qrg_get_tugas_meta( $tugas->ID );
                $existing = qrg_find_jawaban( $tugas->ID, $siswa_id );
                $status   = $existing ? get_post_meta( $existing, '_jawaban_status', true ) : '';
                $nilai    = $existing ? get_post_meta( $existing, '_jawaban_nilai', true ) : '';
                if ( ! $existing ) {
                    $state = 'belum';
                } elseif ( $status === 'Dinilai' ) {
                    $state = 'dinilai';
                } else {
                    $state = 'dikirim';
                }
                $rows[] = [
                    'post'   => $tugas,
                    'meta'   => $meta,
                    'state'  => $state,
                    'nilai'  => $nilai,
                    'url'    => add_query_arg( 'tugas', $tugas->ID, $base_url ),
                ];
            }
            $counts = [
                'semua'   => count( $rows ),
                'belum'   => 0,
                'dikirim' => 0,
                'dinilai' => 0,
            ];
            foreach ( $rows as $r ) {
                $counts[ $r['state'] ]++;
            }
            ?>
            <p class="ios-section__label">Daftar tugas</p>
            <?php if ( ! empty( $rows ) ) : ?>
            <div class="qrg-ajar-filters" role="tablist" aria-label="Filter status">
                <button type="button" class="qrg-ajar-filter is-on" data-filter="semua">Semua (<?php echo (int) $counts['semua']; ?>)</button>
                <button type="button" class="qrg-ajar-filter" data-filter="belum">Belum (<?php echo (int) $counts['belum']; ?>)</button>
                <button type="button" class="qrg-ajar-filter" data-filter="dikirim">Dikirim (<?php echo (int) $counts['dikirim']; ?>)</button>
                <button type="button" class="qrg-ajar-filter" data-filter="dinilai">Dinilai (<?php echo (int) $counts['dinilai']; ?>)</button>
            </div>
            <?php endif; ?>

            <?php if ( empty( $rows ) ) : ?>
                <div class="qrg-ajar-empty">Belum ada tugas untuk kelas Anda.</div>
            <?php else : ?>
                <div class="qrg-ajar-list" id="qrg-ajar-list">
                    <?php foreach ( $rows as $row ) :
                        $meta = $row['meta'];
                        $meta_bits = array_filter( [
                            $meta['mapel'] ? $meta['mapel'] : '',
                            $meta['deadline'] ? 'Deadline ' . date_i18n( 'd M Y', strtotime( $meta['deadline'] ) ) : '',
                        ] );
                        if ( $row['state'] === 'belum' ) {
                            $badge_cls = 'qrg-ajar-badge--wait';
                            $badge_txt = 'Belum dikerjakan';
                        } elseif ( $row['state'] === 'dinilai' ) {
                            $badge_cls = 'qrg-ajar-badge--ok';
                            $badge_txt = 'Dinilai' . ( $row['nilai'] !== '' ? ' · ' . $row['nilai'] : '' );
                        } else {
                            $badge_cls = 'qrg-ajar-badge--sent';
                            $badge_txt = 'Sudah dikirim';
                        }
                        ?>
                        <a class="qrg-ajar-item" href="<?php echo esc_url( $row['url'] ); ?>" data-state="<?php echo esc_attr( $row['state'] ); ?>">
                            <div class="qrg-ajar-item__body">
                                <span class="qrg-ajar-item__title"><?php echo esc_html( $row['post']->post_title ); ?></span>
                                <span class="qrg-ajar-item__meta">
                                    <?php echo esc_html( qrg_tugas_tipe_label( $meta['tipe'] ) ); ?>
                                    <?php echo $meta_bits ? ' · ' . esc_html( implode( ' · ', $meta_bits ) ) : ''; ?>
                                </span>
                                <span class="qrg-ajar-badge <?php echo esc_attr( $badge_cls ); ?>"><?php echo esc_html( $badge_txt ); ?></span>
                            </div>
                            <span class="qrg-ajar-item__chev" aria-hidden="true"></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <p class="qrg-ajar-empty" id="qrg-ajar-empty-filter" hidden>Tidak ada tugas pada filter ini.</p>
                <script>
                (function () {
                    var root = document.querySelector('.qrg-sk-ajar');
                    if (!root || root.getAttribute('data-filter-ready')) return;
                    root.setAttribute('data-filter-ready', '1');
                    var filters = root.querySelectorAll('.qrg-ajar-filter');
                    var items = root.querySelectorAll('.qrg-ajar-item');
                    var empty = root.querySelector('#qrg-ajar-empty-filter');
                    var list = root.querySelector('#qrg-ajar-list');
                    if (!filters.length) return;
                    function apply(key) {
                        var shown = 0;
                        for (var i = 0; i < items.length; i++) {
                            var on = key === 'semua' || items[i].getAttribute('data-state') === key;
                            items[i].style.display = on ? '' : 'none';
                            if (on) shown++;
                        }
                        if (empty) empty.hidden = shown > 0;
                        if (list) list.style.display = shown > 0 ? '' : 'none';
                    }
                    for (var f = 0; f < filters.length; f++) {
                        filters[f].addEventListener('click', function (e) {
                            var btn = e.currentTarget;
                            for (var j = 0; j < filters.length; j++) filters[j].classList.remove('is-on');
                            btn.classList.add('is-on');
                            apply(btn.getAttribute('data-filter') || 'semua');
                        });
                    }
                })();
                </script>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Halaman penuh /master_siswa/{slug}/pembelajaran/
 */
function qrg_render_siswa_pembelajaran_page( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $error    = '';
    $success  = '';
    $auth_id  = qrg_siswa_portal_auth_id();
    $base_url = qrg_get_siswa_pembelajaran_url( $siswa_id );

    if ( isset( $_GET['qrg_logout'] ) && $auth_id === $siswa_id ) {
        qrg_siswa_portal_logout();
        wp_safe_redirect( $base_url );
        exit;
    }

    // Login NIS (khusus siswa ini) + rate limit
    if ( isset( $_POST['qrg_pembelajaran_login'] ) && wp_verify_nonce( $_POST['qrg_pembelajaran_nonce'] ?? '', 'qrg_pembelajaran_login_' . $siswa_id ) ) {
        $rl = function_exists( 'qrg_rate_limit_check' ) ? qrg_rate_limit_check( 'login_nis', 8, 900 ) : true;
        if ( is_wp_error( $rl ) ) {
            $error = $rl->get_error_message();
        } else {
            $password = isset( $_POST['qrg_nis_password'] ) ? sanitize_text_field( wp_unslash( $_POST['qrg_nis_password'] ) ) : '';
            if ( function_exists( 'qrg_pembayaran_verify_nis' ) && qrg_pembayaran_verify_nis( $siswa_id, $password ) ) {
                if ( function_exists( 'qrg_rate_limit_clear' ) ) {
                    qrg_rate_limit_clear( 'login_nis' );
                }
                qrg_siswa_portal_set_auth( $siswa_id );
                wp_safe_redirect( $base_url );
                exit;
            }
            if ( function_exists( 'qrg_rate_limit_hit' ) ) {
                qrg_rate_limit_hit( 'login_nis', 900 );
            }
            $error = 'Kredensial salah. Silakan coba lagi.';
        }
    }

    $logged_in = ( $auth_id === $siswa_id );

    if ( $logged_in ) {
        $submit = qrg_process_submit_jawaban( $siswa_id );
        $error  = $submit['error'] ?: $error;
        $success = $submit['success'];
    }

    $nama          = get_the_title( $siswa_id );
    $kelas         = function_exists( 'qrg_get_kelas_label' ) ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) ) : '';
    $google_on     = function_exists( 'qrg_google_auth_enabled' ) && qrg_google_auth_enabled();
    $google_cid    = $google_on ? qrg_get_google_client_id() : '';
    $siswa_email   = trim( (string) get_post_meta( $siswa_id, '_siswa_email', true ) );
    $google_nonce  = wp_create_nonce( 'qrg_google_login_' . $siswa_id );
    $ajax_url      = admin_url( 'admin-ajax.php' );
    $view_tugas    = isset( $_GET['tugas'] ) ? absint( $_GET['tugas'] ) : 0;

    status_header( 200 );
    nocache_headers();
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pembelajaran — <?php echo esc_html( $nama ); ?></title>
        <?php if ( $google_on && ! $logged_in ) : ?>
            <script src="https://accounts.google.com/gsi/client" async defer></script>
        <?php endif; ?>
        <style>
            body { background:#f4f6f9; font-family:sans-serif; margin:0; padding:20px; color:#1e293b; }
            .qrg-box { max-width:820px; margin:40px auto; background:#fff; border-radius:10px; box-shadow:0 4px 14px rgba(0,0,0,.08); padding:28px 30px; }
            .qrg-box.login { max-width:380px; }
            h1 { margin:0 0 6px; font-size:22px; }
            .sub { color:#64748b; margin:0 0 22px; font-size:14px; }
            .err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:10px 12px; border-radius:6px; margin-bottom:16px; font-size:14px; }
            .qrg-google-wrap { text-align:center; margin-bottom:18px; }
            .qrg-google-status { font-size:13px; color:#64748b; margin-top:10px; min-height:18px; }
            .qrg-divider { display:flex; align-items:center; gap:10px; color:#94a3b8; font-size:12px; margin:18px 0; }
            .qrg-divider::before, .qrg-divider::after { content:""; flex:1; height:1px; background:#e2e8f0; }
            .qrg-nis-toggle { display:block; width:100%; text-align:center; font-size:13px; color:#2271b1; cursor:pointer; margin-bottom:12px; background:none; border:none; text-decoration:underline; }
            .qrg-nis-panel { display:none; }
            .qrg-nis-panel.open { display:block; }
            .qrg-calc { max-width:300px; margin:0 auto; }
            .qrg-calc-screen {
                background:#0f172a; color:#e2e8f0; border-radius:12px; padding:18px 16px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size:28px; letter-spacing:6px; text-align:right; min-height:36px;
                margin-bottom:14px; box-shadow:inset 0 2px 8px rgba(0,0,0,.35);
                word-break:break-all; line-height:1.2;
            }
            .qrg-calc-screen.empty { color:#64748b; letter-spacing:2px; font-size:15px; text-align:center; }
            .qrg-calc-hint { text-align:center; font-size:13px; color:#64748b; margin:0 0 14px; }
            .qrg-calc-pad { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; }
            .qrg-calc-pad button {
                appearance:none; border:none; border-radius:12px; padding:18px 0;
                font-size:22px; font-weight:700; cursor:pointer;
                background:#e2e8f0; color:#0f172a;
                box-shadow:0 2px 0 #94a3b8; user-select:none;
            }
            .qrg-calc-pad button:active { transform:translateY(2px); box-shadow:none; }
            .qrg-calc-pad button.key-fn { background:#cbd5e1; color:#334155; font-size:16px; }
            .qrg-calc-pad button.key-ok {
                background:#16a34a; color:#fff; box-shadow:0 2px 0 #15803d;
                grid-column:1 / -1; font-size:16px;
            }
            .qrg-calc-pad button.key-ok:disabled { opacity:.45; cursor:not-allowed; }
        </style>
    </head>
    <body>
        <div class="qrg-box<?php echo $logged_in ? '' : ' login'; ?>">
            <?php if ( ! $logged_in ) : ?>
                <h1 style="text-align:center;">Login Pembelajaran</h1>
                <p class="sub" style="text-align:center;">Siswa: <strong><?php echo esc_html( $nama ); ?></strong><?php echo $kelas ? ' · Kelas ' . esc_html( $kelas ) : ''; ?></p>
                <?php if ( $error ) : ?>
                    <div class="err" id="qrg-login-err"><?php echo esc_html( $error ); ?></div>
                <?php else : ?>
                    <div class="err" id="qrg-login-err" style="display:none;"></div>
                <?php endif; ?>

                <?php if ( $google_on ) : ?>
                    <div class="qrg-google-wrap">
                        <?php if ( $siswa_email === '' ) : ?>
                            <div class="err">Email siswa belum diisi di Data Siswa. Login Google belum bisa dipakai.</div>
                        <?php else : ?>
                            <p style="font-size:13px; color:#64748b; margin:0 0 12px;">Masuk dengan akun Google yang emailnya sama dengan siswa.</p>
                            <div id="qrg-google-btn"></div>
                            <div class="qrg-google-status" id="qrg-google-status"></div>
                        <?php endif; ?>
                    </div>
                    <div class="qrg-divider">atau</div>
                    <button type="button" class="qrg-nis-toggle" id="qrg-nis-toggle">Login dengan NIS</button>
                <?php endif; ?>

                <div class="qrg-nis-panel<?php echo $google_on ? '' : ' open'; ?>" id="qrg-nis-panel">
                    <form method="post" id="qrg-calc-form" class="qrg-calc" autocomplete="off">
                        <?php wp_nonce_field( 'qrg_pembelajaran_login_' . $siswa_id, 'qrg_pembelajaran_nonce' ); ?>
                        <input type="hidden" name="qrg_nis_password" id="qrg_nis_password" value="" required>
                        <input type="hidden" name="qrg_pembelajaran_login" value="1">
                        <p class="qrg-calc-hint">Masukkan NIS dengan keypad</p>
                        <div class="qrg-calc-screen empty" id="qrg-calc-screen" aria-live="polite">NIS</div>
                        <div class="qrg-calc-pad" role="group" aria-label="Keypad NIS">
                            <button type="button" data-key="1">1</button>
                            <button type="button" data-key="2">2</button>
                            <button type="button" data-key="3">3</button>
                            <button type="button" data-key="4">4</button>
                            <button type="button" data-key="5">5</button>
                            <button type="button" data-key="6">6</button>
                            <button type="button" data-key="7">7</button>
                            <button type="button" data-key="8">8</button>
                            <button type="button" data-key="9">9</button>
                            <button type="button" class="key-fn" data-key="clear">C</button>
                            <button type="button" data-key="0">0</button>
                            <button type="button" class="key-fn" data-key="back">⌫</button>
                            <button type="submit" class="key-ok" id="qrg-calc-ok" disabled>Masuk</button>
                        </div>
                    </form>
                </div>

                <script>
                (function () {
                    var toggle = document.getElementById('qrg-nis-toggle');
                    var panel = document.getElementById('qrg-nis-panel');
                    if (toggle && panel) {
                        toggle.addEventListener('click', function () {
                            panel.classList.toggle('open');
                            toggle.textContent = panel.classList.contains('open') ? 'Sembunyikan login NIS' : 'Login dengan NIS';
                        });
                    }
                    var input = document.getElementById('qrg_nis_password');
                    var screen = document.getElementById('qrg-calc-screen');
                    var okBtn = document.getElementById('qrg-calc-ok');
                    var form = document.getElementById('qrg-calc-form');
                    if (!input || !screen || !okBtn || !form) return;
                    var value = '';
                    function render() {
                        input.value = value;
                        if (!value) { screen.textContent = 'NIS'; screen.classList.add('empty'); }
                        else { screen.textContent = value.replace(/./g, '•'); screen.classList.remove('empty'); }
                        okBtn.disabled = value.length === 0;
                    }
                    function press(key) {
                        if (key === 'clear') value = '';
                        else if (key === 'back') value = value.slice(0, -1);
                        else if (/^[0-9]$/.test(key) && value.length < 20) value += key;
                        render();
                    }
                    form.querySelectorAll('.qrg-calc-pad button[data-key]').forEach(function (btn) {
                        btn.addEventListener('click', function () { press(btn.getAttribute('data-key')); });
                    });
                    document.addEventListener('keydown', function (e) {
                        if (panel && !panel.classList.contains('open')) return;
                        if (e.key >= '0' && e.key <= '9') { e.preventDefault(); press(e.key); }
                        else if (e.key === 'Backspace') { e.preventDefault(); press('back'); }
                        else if (e.key === 'Escape') { e.preventDefault(); press('clear'); }
                        else if (e.key === 'Enter' && value.length) { e.preventDefault(); form.submit(); }
                    });
                    render();
                })();
                </script>

                <?php if ( $google_on && $siswa_email !== '' ) : ?>
                <script>
                window.qrgHandleGoogleCredential = function (response) {
                    var errBox = document.getElementById('qrg-login-err');
                    var status = document.getElementById('qrg-google-status');
                    if (status) status.textContent = 'Memverifikasi akun Google...';
                    if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }
                    var body = new FormData();
                    body.append('action', 'qrg_google_pembayaran_login');
                    body.append('siswa_id', '<?php echo (int) $siswa_id; ?>');
                    body.append('credential', response.credential || '');
                    body.append('nonce', '<?php echo esc_js( $google_nonce ); ?>');
                    body.append('redirect', '<?php echo esc_js( $base_url ); ?>');
                    fetch('<?php echo esc_url( $ajax_url ); ?>', { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data && data.success && data.data && data.data.redirect) {
                                if (status) status.textContent = 'Berhasil. Mengalihkan...';
                                window.location.href = data.data.redirect;
                                return;
                            }
                            var msg = (data && data.data && data.data.message) ? data.data.message : 'Login Google gagal.';
                            if (errBox) { errBox.textContent = msg; errBox.style.display = 'block'; }
                            if (status) status.textContent = '';
                        }).catch(function () {
                            if (errBox) { errBox.textContent = 'Koneksi gagal. Coba lagi.'; errBox.style.display = 'block'; }
                            if (status) status.textContent = '';
                        });
                };
                function qrgInitGoogle() {
                    if (!window.google || !google.accounts || !google.accounts.id) { setTimeout(qrgInitGoogle, 200); return; }
                    google.accounts.id.initialize({
                        client_id: '<?php echo esc_js( $google_cid ); ?>',
                        callback: window.qrgHandleGoogleCredential,
                        auto_select: false
                    });
                    google.accounts.id.renderButton(document.getElementById('qrg-google-btn'), {
                        theme: 'outline', size: 'large', shape: 'rectangular', text: 'signin_with', width: 320
                    });
                }
                qrgInitGoogle();
                </script>
                <?php endif; ?>
            <?php else : ?>
                <?php qrg_render_siswa_tugas_panel( $siswa_id, $base_url, $view_tugas, $error, $success ); ?>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

