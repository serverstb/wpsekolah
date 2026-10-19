<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Registrasi CPT Tagihan & Submenu Generate Massal
add_action( 'init', 'qrg_register_tagihan_cpt' );
function qrg_register_tagihan_cpt() {
    register_post_type( 'master_tagihan', [
        'labels' => [
            'name'          => 'Tagihan Siswa',
            'singular_name' => 'Tagihan',
            'menu_name'     => 'Tagihan Siswa',
            'add_new_item'  => 'Buat Tagihan Baru',
            'edit_item'     => 'Edit Tagihan',
            'all_items'     => 'Data Tagihan',
        ],
        'public'             => true,
        'publicly_queryable' => true,
        'exclude_from_search'=> true,
        'show_in_menu'       => false, // Akses via Sekolah → Akuntansi
        'has_archive'        => false,
        'rewrite'            => array('slug' => 'spp'),
        'supports'           => [ 'title' ],
    ]);
}

// Halaman Generate Massal (tersembunyi dari sidebar, akses via tombol di daftar Tagihan)
add_action( 'admin_menu', 'qrg_add_generate_tagihan_submenu', 20 );
function qrg_add_generate_tagihan_submenu() {
    add_submenu_page(
        null,
        'Generate Tagihan Massal',
        'Generate',
        'manage_options',
        'qrg-generate-kelas',
        'qrg_generate_tagihan_kelas_callback'
    );
}

// Tombol "Jenis Biaya" dan "Generate Tagihan" di samping "Buat Tagihan Baru"
add_action( 'admin_footer-edit.php', 'qrg_tagihan_header_action_button' );
function qrg_tagihan_header_action_button() {
    global $typenow;
    if ( $typenow !== 'master_tagihan' || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $akun_url     = admin_url( 'admin.php?page=qrg-akuntansi' );
    $biaya_url    = admin_url( 'edit.php?post_type=master_biaya' );
    $generate_url = admin_url( 'admin.php?page=qrg-generate-kelas' );
    ?>
    <script>
    jQuery(function($) {
        var $wrap = $('.wrap h1').first();
        var $btn = $('.wrap .page-title-action').first();
        if ($wrap.length) {
            var $akun = $('<a href="<?php echo esc_url( $akun_url ); ?>" class="page-title-action">← Keuangan</a>');
            if ($btn.length) {
                $akun.insertBefore($btn);
            } else {
                $akun.insertAfter($wrap);
            }
        }
        if (!$btn.length) {
            return;
        }
        var $biaya = $('<a href="<?php echo esc_url( $biaya_url ); ?>" class="page-title-action">Jenis Biaya</a>')
            .insertAfter($btn);
        $('<a href="<?php echo esc_url( $generate_url ); ?>" class="page-title-action">Generate Tagihan</a>')
            .insertAfter($biaya);
    });
    </script>
    <?php
}

// Saat di halaman Generate, tandai menu Tagihan Siswa sebagai aktif
add_filter( 'parent_file', 'qrg_generate_tagihan_parent_file' );
function qrg_generate_tagihan_parent_file( $parent_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-generate-kelas' ) {
        return 'master-data-plugin';
    }
    return $parent_file;
}

add_filter( 'submenu_file', 'qrg_generate_tagihan_submenu_file' );
function qrg_generate_tagihan_submenu_file( $submenu_file ) {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'qrg-generate-kelas' ) {
        return 'edit.php?post_type=master_tagihan';
    }
    return $submenu_file;
}

// 2. Fungsi Eksekusi & Tampilan Halaman "Generate By Kelas"
function qrg_generate_tagihan_kelas_callback() {
    // Proses pembuatan massal jika form disubmit
    if ( isset($_POST['qrg_do_generate_massal']) && check_admin_referer('qrg_massal_nonce_action', 'qrg_massal_nonce') ) {
        $kelas_target = absint( $_POST['massal_kelas'] );
        $biaya_id     = absint( $_POST['massal_biaya_id'] );
        $nama_tagihan = sanitize_text_field( $_POST['massal_nama_tagihan'] );

        if ( $kelas_target && $biaya_id && $nama_tagihan !== '' ) {
            $nominal     = (int) get_post_meta( $biaya_id, '_biaya_nominal', true );
            $kelas_label = qrg_get_kelas_label( $kelas_target );

            $siswa_posts = function_exists( 'qrg_get_siswa_by_kelas' )
                ? qrg_get_siswa_by_kelas( $kelas_target )
                : [];

            $count = 0;
            $skipped = 0;

            if ( ! empty( $siswa_posts ) ) {
                foreach ( $siswa_posts as $siswa_post ) {
                    $siswa_id   = (int) $siswa_post->ID;
                    $nama_siswa = $siswa_post->post_title;

                    // Lewati jika tagihan dengan keterangan sama sudah ada untuk siswa ini
                    $existing = get_posts( [
                        'post_type'      => 'master_tagihan',
                        'posts_per_page' => 1,
                        'post_status'    => 'publish',
                        'fields'         => 'ids',
                        'meta_query'     => [
                            'relation' => 'AND',
                            [
                                'key'   => '_tagihan_siswa_id',
                                'value' => (string) $siswa_id,
                            ],
                            [
                                'key'   => '_tagihan_keterangan',
                                'value' => $nama_tagihan,
                            ],
                        ],
                    ] );
                    if ( ! empty( $existing ) ) {
                        $skipped++;
                        continue;
                    }

                    $invoice_title = $nama_tagihan . ' - ' . $nama_siswa;
                    $tagihan_id = wp_insert_post( [
                        'post_title'  => $invoice_title,
                        'post_type'   => 'master_tagihan',
                        'post_status' => 'publish',
                    ] );

                    if ( $tagihan_id && ! is_wp_error( $tagihan_id ) ) {
                        update_post_meta( $tagihan_id, '_tagihan_siswa_id', $siswa_id );
                        update_post_meta( $tagihan_id, '_tagihan_nominal', $nominal );
                        update_post_meta( $tagihan_id, '_tagihan_keterangan', $nama_tagihan );
                        qrg_tagihan_set_status( $tagihan_id, 'Belum Dibayar', [
                            'via'     => 'sistem',
                            'catatan' => 'Generate massal',
                        ] );
                        $count++;
                    }
                }

                $message = 'Berhasil membuat <strong>' . $count . ' tagihan</strong> untuk <strong>' . esc_html( $kelas_label ) . '</strong>.';
                if ( $skipped > 0 ) {
                    $message .= ' <strong>' . $skipped . ' siswa</strong> dilewati karena sudah memiliki tagihan dengan keterangan yang sama.';
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Tidak ditemukan data siswa di <strong>' . esc_html( $kelas_label ) . '</strong>. Gagal membuat tagihan.</p></div>';
            }
        }
    }

    $daftar_kelas = qrg_get_daftar_kelas();
    ?>
    <div class="wrap">
        <h1>
            Generate Tagihan Massal per Kelas
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=master_tagihan' ) ); ?>" class="page-title-action">← Tagihan Siswa</a>
        </h1>
        <p>Fitur ini mempermudah pembuatan invoice akademik sekaligus untuk seluruh siswa di kelas tertentu.</p>
        
        <div class="card" style="max-width: 600px; margin-top: 20px; padding: 20px;">
            <form method="POST" action="" id="qrg-generate-massal-form">
                <?php wp_nonce_field('qrg_massal_nonce_action', 'qrg_massal_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th>Pilih Kelas Target</th>
                        <td>
                            <select name="massal_kelas" id="massal_kelas" required style="width: 100%;">
                                <option value="">-- Pilih Kelas --</option>
                                <?php 
                                if ( ! empty( $daftar_kelas ) ) {
                                    foreach ( $daftar_kelas as $kelas_post ) {
                                        echo '<option value="' . esc_attr( $kelas_post->ID ) . '">' . esc_html( qrg_get_kelas_label( $kelas_post->ID ) ) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                            <p class="description">Kelas diambil dari menu <strong>Data Kelas</strong>. Pastikan siswa sudah dipilih kelasnya di profil siswa.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Pilih Jenis Biaya</th>
                        <td>
                            <select name="massal_biaya_id" id="massal_biaya_id" required style="width: 100%;">
                                <option value="">-- Pilih Jenis Biaya --</option>
                                <?php 
                                $biaya_query = new WP_Query([
                                    'post_type'      => 'master_biaya',
                                    'posts_per_page' => -1,
                                    'orderby'        => 'title',
                                    'order'          => 'ASC'
                                ]);
                                if ( $biaya_query->have_posts() ) {
                                    while ( $biaya_query->have_posts() ) {
                                        $biaya_query->the_post();
                                        $nominal_b = get_post_meta( get_the_ID(), '_biaya_nominal', true );
                                        echo '<option value="' . get_the_ID() . '">' . get_the_title() . ' (Rp ' . number_format(intval($nominal_b), 0, ',', '.') . ')</option>';
                                    }
                                    wp_reset_postdata();
                                }
                                ?>
                            </select>
                            <p class="description">Nominal tagihan mengikuti jenis biaya yang dipilih. Tagihan dibuat untuk <strong>semua siswa</strong> di kelas target.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Keterangan / Nama Tagihan</th>
                        <td>
                            <input type="text" name="massal_nama_tagihan" id="massal_nama_tagihan" placeholder="Contoh: SPP Bulan Agustus 2026" required class="large-text">
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="qrg_do_generate_massal" id="qrg-generate-massal-btn" value="1" class="button button-primary button-large">
                        Mulai Generate Tagihan Massal ⚡
                    </button>
                </p>
            </form>
        </div>
    </div>

    <div id="qrg-massal-modal" class="qrg-massal-modal" aria-hidden="true" role="dialog" aria-labelledby="qrg-massal-modal-title">
        <div class="qrg-massal-modal__backdrop"></div>
        <div class="qrg-massal-modal__panel">
            <div class="qrg-massal-modal__confirm" id="qrg-massal-confirm-view">
                <div class="qrg-massal-modal__icon qrg-massal-modal__icon--warn">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <h2 id="qrg-massal-modal-title">Konfirmasi Generate Massal</h2>
                <p class="qrg-massal-modal__lead">Apakah Anda yakin ingin membuat tagihan untuk seluruh siswa di kelas terpilih?</p>
                <ul class="qrg-massal-modal__summary">
                    <li><span>Kelas</span><strong id="qrg-massal-sum-kelas">—</strong></li>
                    <li><span>Jenis Biaya</span><strong id="qrg-massal-sum-biaya">—</strong></li>
                    <li><span>Nama Tagihan</span><strong id="qrg-massal-sum-nama">—</strong></li>
                </ul>
                <div class="qrg-massal-modal__actions">
                    <button type="button" class="button button-large" id="qrg-massal-cancel">Batal</button>
                    <button type="button" class="button button-primary button-large" id="qrg-massal-confirm">Ya, Generate Sekarang</button>
                </div>
            </div>
            <div class="qrg-massal-modal__loading" id="qrg-massal-loading-view" hidden>
                <div class="qrg-massal-loader" aria-hidden="true">
                    <div class="qrg-massal-loader__ring"></div>
                    <div class="qrg-massal-loader__ring"></div>
                    <div class="qrg-massal-loader__ring"></div>
                    <div class="qrg-massal-loader__bolt">⚡</div>
                </div>
                <h2>Sedang membuat tagihan…</h2>
                <p class="qrg-massal-modal__lead">Mohon tunggu, sistem sedang membuat tagihan untuk seluruh siswa di kelas terpilih.</p>
                <div class="qrg-massal-progress">
                    <div class="qrg-massal-progress__bar"></div>
                </div>
                <p class="qrg-massal-modal__hint">Jangan tutup atau refresh halaman ini.</p>
            </div>
        </div>
    </div>

    <style>
        .qrg-massal-modal {
            position: fixed;
            inset: 0;
            z-index: 100100;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity .22s ease, visibility .22s ease;
        }
        .qrg-massal-modal.is-open {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .qrg-massal-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, .55);
            backdrop-filter: blur(3px);
        }
        .qrg-massal-modal__panel {
            position: relative;
            width: min(440px, calc(100vw - 32px));
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
            padding: 28px 28px 24px;
            text-align: center;
            transform: translateY(12px) scale(.98);
            transition: transform .22s ease;
        }
        .qrg-massal-modal.is-open .qrg-massal-modal__panel {
            transform: translateY(0) scale(1);
        }
        .qrg-massal-modal__icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 14px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qrg-massal-modal__icon--warn {
            background: linear-gradient(145deg, #fff7ed, #ffedd5);
            color: #c2410c;
        }
        .qrg-massal-modal__icon .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
        }
        .qrg-massal-modal h2 {
            margin: 0 0 8px;
            font-size: 20px;
            color: #0f172a;
            line-height: 1.3;
        }
        .qrg-massal-modal__lead {
            margin: 0 0 18px;
            color: #475569;
            font-size: 14px;
            line-height: 1.55;
        }
        .qrg-massal-modal__summary {
            list-style: none;
            margin: 0 0 22px;
            padding: 0;
            text-align: left;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
        }
        .qrg-massal-modal__summary li {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 14px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }
        .qrg-massal-modal__summary li:last-child { border-bottom: 0; }
        .qrg-massal-modal__summary span { color: #64748b; }
        .qrg-massal-modal__summary strong {
            color: #0f172a;
            text-align: right;
            word-break: break-word;
        }
        .qrg-massal-modal__actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .qrg-massal-modal__actions .button {
            min-width: 120px;
        }
        .qrg-massal-modal__loading[hidden],
        .qrg-massal-modal__confirm[hidden] {
            display: none !important;
        }
        .qrg-massal-loader {
            position: relative;
            width: 88px;
            height: 88px;
            margin: 8px auto 18px;
        }
        .qrg-massal-loader__ring {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 3px solid transparent;
            animation: qrg-massal-spin 1.2s linear infinite;
        }
        .qrg-massal-loader__ring:nth-child(1) {
            border-top-color: #2271b1;
            border-right-color: #2271b1;
        }
        .qrg-massal-loader__ring:nth-child(2) {
            inset: 8px;
            border-bottom-color: #3b82f6;
            border-left-color: #3b82f6;
            animation-duration: .9s;
            animation-direction: reverse;
        }
        .qrg-massal-loader__ring:nth-child(3) {
            inset: 16px;
            border-top-color: #93c5fd;
            animation-duration: 1.5s;
        }
        .qrg-massal-loader__bolt {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            animation: qrg-massal-pulse 1.1s ease-in-out infinite;
        }
        .qrg-massal-progress {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
            margin: 0 0 12px;
        }
        .qrg-massal-progress__bar {
            width: 35%;
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #2271b1, #60a5fa, #2271b1);
            background-size: 200% 100%;
            animation: qrg-massal-progress 1.4s ease-in-out infinite;
        }
        .qrg-massal-modal__hint {
            margin: 0;
            font-size: 12px;
            color: #94a3b8;
        }
        @keyframes qrg-massal-spin {
            to { transform: rotate(360deg); }
        }
        @keyframes qrg-massal-pulse {
            0%, 100% { transform: scale(1); opacity: .85; }
            50% { transform: scale(1.12); opacity: 1; }
        }
        @keyframes qrg-massal-progress {
            0% { transform: translateX(-120%); background-position: 0% 0; }
            50% { background-position: 100% 0; }
            100% { transform: translateX(320%); background-position: 0% 0; }
        }
        body.qrg-massal-modal-open { overflow: hidden; }
    </style>

    <script>
    (function() {
        var form = document.getElementById('qrg-generate-massal-form');
        var modal = document.getElementById('qrg-massal-modal');
        if (!form || !modal) return;

        var confirmView = document.getElementById('qrg-massal-confirm-view');
        var loadingView = document.getElementById('qrg-massal-loading-view');
        var btnCancel = document.getElementById('qrg-massal-cancel');
        var btnConfirm = document.getElementById('qrg-massal-confirm');
        var allowSubmit = false;

        function selectedText(selectEl) {
            if (!selectEl || selectEl.selectedIndex < 0) return '—';
            var opt = selectEl.options[selectEl.selectedIndex];
            return opt && opt.value ? opt.text : '—';
        }

        function openModal() {
            document.getElementById('qrg-massal-sum-kelas').textContent = selectedText(document.getElementById('massal_kelas'));
            document.getElementById('qrg-massal-sum-biaya').textContent = selectedText(document.getElementById('massal_biaya_id'));
            var nama = (document.getElementById('massal_nama_tagihan').value || '').trim();
            document.getElementById('qrg-massal-sum-nama').textContent = nama || '—';

            confirmView.hidden = false;
            loadingView.hidden = true;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('qrg-massal-modal-open');
            btnConfirm.focus();
        }

        function closeModal() {
            if (allowSubmit) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('qrg-massal-modal-open');
        }

        function showLoadingAndSubmit() {
            allowSubmit = true;
            confirmView.hidden = true;
            loadingView.hidden = false;
            btnConfirm.disabled = true;
            btnCancel.disabled = true;

            // Pastikan flag generate ikut terkirim (terutama jika form.submit() dipakai)
            var flag = form.querySelector('input[name="qrg_do_generate_massal"][type="hidden"]');
            if (!flag) {
                flag = document.createElement('input');
                flag.type = 'hidden';
                flag.name = 'qrg_do_generate_massal';
                flag.value = '1';
                form.appendChild(flag);
            }

            var submitBtn = document.getElementById('qrg-generate-massal-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
            }

            setTimeout(function() {
                if (typeof form.requestSubmit === 'function' && submitBtn) {
                    form.requestSubmit(submitBtn);
                } else {
                    form.submit();
                }
            }, 350);
        }

        form.addEventListener('submit', function(e) {
            if (allowSubmit) return;
            e.preventDefault();
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            openModal();
        });

        btnCancel.addEventListener('click', closeModal);
        modal.querySelector('.qrg-massal-modal__backdrop').addEventListener('click', closeModal);
        btnConfirm.addEventListener('click', showLoadingAndSubmit);

        document.addEventListener('keydown', function(e) {
            if (!modal.classList.contains('is-open') || allowSubmit) return;
            if (e.key === 'Escape') closeModal();
        });
    })();
    </script>
    <?php
}

// 3. Slug URL unik berbasis ID (SHA-256). Hindari post_ID=0 saat insert.
add_filter( 'wp_unique_post_slug', 'qrg_generate_sha256_slug', 10, 6 );
function qrg_generate_sha256_slug( $slug, $post_ID, $post_status, $post_type, $post_parent, $original_slug ) {
    if ( $post_type === 'master_tagihan' && (int) $post_ID > 0 ) {
        return hash( 'sha256', 'tagihan-spp-' . (int) $post_ID );
    }
    return $slug;
}

/**
 * Pastikan slug tagihan unik setelah post punya ID.
 */
add_action( 'save_post_master_tagihan', 'qrg_tagihan_ensure_unique_slug', 20, 2 );
function qrg_tagihan_ensure_unique_slug( $post_id, $post = null ) {
    $post_id = (int) $post_id;
    if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    if ( get_post_type( $post_id ) !== 'master_tagihan' ) {
        return;
    }

    $expected = hash( 'sha256', 'tagihan-spp-' . $post_id );
    $current  = $post instanceof WP_Post ? $post->post_name : (string) get_post_field( 'post_name', $post_id );
    if ( $current === $expected ) {
        return;
    }

    // Hindari loop recursive
    remove_action( 'save_post_master_tagihan', 'qrg_tagihan_ensure_unique_slug', 20 );
    wp_update_post( [
        'ID'        => $post_id,
        'post_name' => $expected,
    ] );
    add_action( 'save_post_master_tagihan', 'qrg_tagihan_ensure_unique_slug', 20, 2 );
}

/**
 * Perbaiki slug tagihan yang bentrok (satu kali).
 */
add_action( 'admin_init', 'qrg_tagihan_repair_duplicate_slugs_once' );
function qrg_tagihan_repair_duplicate_slugs_once() {
    if ( get_option( 'qrg_tagihan_slugs_repaired_v1' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $ids = get_posts( [
        'post_type'      => 'master_tagihan',
        'posts_per_page' => -1,
        'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ] );

    remove_action( 'save_post_master_tagihan', 'qrg_tagihan_ensure_unique_slug', 20 );
    foreach ( $ids as $tid ) {
        $tid      = (int) $tid;
        $expected = hash( 'sha256', 'tagihan-spp-' . $tid );
        $current  = (string) get_post_field( 'post_name', $tid );
        if ( $current !== $expected ) {
            wp_update_post( [
                'ID'        => $tid,
                'post_name' => $expected,
            ] );
        }
    }
    add_action( 'save_post_master_tagihan', 'qrg_tagihan_ensure_unique_slug', 20, 2 );

    flush_rewrite_rules( false );
    update_option( 'qrg_tagihan_slugs_repaired_v1', 1 );
}

/**
 * Sumber perubahan status tagihan.
 *
 * @return array<string,string>
 */
function qrg_tagihan_status_via_labels() {
    return [
        'api'    => 'API',
        'manual' => 'Manual',
        'siswa'  => 'Siswa',
        'sistem' => 'Sistem',
    ];
}

/**
 * @param string $via
 * @return string
 */
function qrg_tagihan_status_via_label( $via ) {
    $map = qrg_tagihan_status_via_labels();
    $via = sanitize_key( (string) $via );
    return isset( $map[ $via ] ) ? $map[ $via ] : 'Manual';
}

/**
 * @param int $tagihan_id
 * @return array<int,array<string,mixed>>
 */
function qrg_tagihan_get_status_log( $tagihan_id ) {
    $tagihan_id = (int) $tagihan_id;
    if ( $tagihan_id <= 0 ) {
        return [];
    }
    $raw = get_post_meta( $tagihan_id, '_tagihan_status_log', true );
    if ( is_array( $raw ) ) {
        return $raw;
    }
    if ( is_string( $raw ) && $raw !== '' ) {
        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }
    }
    return [];
}

/**
 * @param int   $tagihan_id
 * @param array $entry
 */
function qrg_tagihan_append_status_log( $tagihan_id, $entry ) {
    $tagihan_id = (int) $tagihan_id;
    if ( $tagihan_id <= 0 ) {
        return;
    }
    $log   = qrg_tagihan_get_status_log( $tagihan_id );
    $log[] = $entry;
    if ( count( $log ) > 80 ) {
        $log = array_slice( $log, -80 );
    }
    update_post_meta( $tagihan_id, '_tagihan_status_log', $log );
    if ( ! empty( $entry['via'] ) ) {
        update_post_meta( $tagihan_id, '_tagihan_status_via', sanitize_key( (string) $entry['via'] ) );
    }
}

/**
 * Ubah status tagihan dan catat log (API / manual / siswa / sistem).
 *
 * @param int    $tagihan_id
 * @param string $status
 * @param array  $args { via?:string, catatan?:string, oleh?:string, force?:bool }
 * @return bool True jika status berubah atau force.
 */
function qrg_tagihan_set_status( $tagihan_id, $status, $args = [] ) {
    $tagihan_id = (int) $tagihan_id;
    $status     = sanitize_text_field( (string) $status );
    if ( $tagihan_id <= 0 || $status === '' ) {
        return false;
    }

    $via     = isset( $args['via'] ) ? sanitize_key( (string) $args['via'] ) : 'manual';
    $catatan = isset( $args['catatan'] ) ? sanitize_text_field( (string) $args['catatan'] ) : '';
    $force   = ! empty( $args['force'] );
    $old     = (string) get_post_meta( $tagihan_id, '_tagihan_status', true );

    if ( $old === $status && ! $force ) {
        return false;
    }

    update_post_meta( $tagihan_id, '_tagihan_status', $status );

    $oleh = isset( $args['oleh'] ) ? sanitize_text_field( (string) $args['oleh'] ) : '';
    if ( $oleh === '' ) {
        if ( $via === 'api' ) {
            $oleh = 'API pembayaran';
        } elseif ( $via === 'siswa' ) {
            $oleh = 'Siswa / portal';
        } elseif ( $via === 'sistem' ) {
            $oleh = 'Sistem';
        } else {
            $user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
            $oleh = ( $user && ! empty( $user->ID ) ) ? (string) $user->display_name : 'Admin';
        }
    }

    $ip = '';
    if ( function_exists( 'qrg_get_client_ip' ) ) {
        $ip = (string) qrg_get_client_ip();
    } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
    }

    qrg_tagihan_append_status_log( $tagihan_id, [
        'at'      => current_time( 'mysql' ),
        'from'    => $old,
        'to'      => $status,
        'via'     => $via,
        'oleh'    => $oleh,
        'catatan' => $catatan,
        'ip'      => $ip,
    ] );

    return true;
}

/**
 * HTML badge sumber status.
 *
 * @param string $via
 * @return string
 */
function qrg_tagihan_status_via_badge( $via ) {
    $via   = sanitize_key( (string) $via );
    $label = qrg_tagihan_status_via_label( $via );
    $class = 'qrg-st-via qrg-st-via--' . ( $via !== '' ? $via : 'manual' );
    return '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
}

/**
 * URL bayar tagihan yang andal (portal /sekolah/bayar/{id}/).
 */
function qrg_get_tagihan_bayar_url( $tagihan_id ) {
    $tagihan_id = (int) $tagihan_id;
    if ( $tagihan_id <= 0 ) {
        return home_url( '/' );
    }
    if ( function_exists( 'qrg_get_sekolah_portal_url' ) ) {
        return trailingslashit( qrg_get_sekolah_portal_url() ) . 'bayar/' . $tagihan_id . '/';
    }
    return get_permalink( $tagihan_id );
}

// Meta Box Tagihan Individual (Tetap dipertahankan)
add_action( 'add_meta_boxes', 'qrg_tagihan_meta_boxes' );
function qrg_tagihan_meta_boxes() {
    add_meta_box( 'tagihan_detail', 'Detail Tagihan & Integrasi Siswa', 'qrg_tagihan_fields_callback', 'master_tagihan', 'normal', 'high' );
}

function qrg_tagihan_fields_callback( $post ) {
    wp_nonce_field( 'qrg_save_tagihan_meta', 'qrg_tagihan_nonce' );
    
    $selected_siswa   = get_post_meta( $post->ID, '_tagihan_siswa_id', true );
    $selected_biaya   = get_post_meta( $post->ID, '_tagihan_biaya_tambahan_id', true );
    $nominal          = get_post_meta( $post->ID, '_tagihan_nominal', true );
    $keterangan       = get_post_meta( $post->ID, '_tagihan_keterangan', true );
    $status           = get_post_meta( $post->ID, '_tagihan_status', true );
    $bukti_url        = get_post_meta( $post->ID, '_tagihan_bukti_url', true );

    // Prefill siswa dari halaman Data Siswa (?siswa_id=...)
    if ( empty( $selected_siswa ) && isset( $_GET['siswa_id'] ) ) {
        $selected_siswa = absint( $_GET['siswa_id'] );
    }

    $siswa_query = new WP_Query([
        'post_type'      => 'master_siswa',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC'
    ]);

    $biaya_query = new WP_Query([
        'post_type'      => 'master_biaya',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC'
    ]);
    ?>
    <table class="form-table">
        <?php if ( $post->post_status === 'publish' ) : ?>
        <tr>
            <th>Link Pembayaran Siswa</th>
            <td>
                <input type="text" value="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" class="large-text" readonly onclick="this.select();">
            </td>
        </tr>
        <?php endif; ?>
        
        <?php if ( ! empty( $bukti_url ) ) : ?>
        <tr style="background: #f0f6fb;">
            <th><span style="color: #0073aa; font-weight: bold;">📷 Bukti Transfer Siswa</span></th>
            <td>
                <div style="margin-bottom: 10px;">
                    <a href="<?php echo esc_url( $bukti_url ); ?>" target="_blank" class="button button-secondary">🔍 Buka Gambar di Tab Baru</a>
                </div>
                <div style="max-width: 300px; border: 4px solid #ddd; border-radius: 4px; background: #fff; padding: 5px;">
                    <img src="<?php echo esc_url( $bukti_url ); ?>" style="max-width: 100%; height: auto; display: block;">
                </div>
            </td>
        </tr>
        <?php endif; ?>

        <tr>
            <th><label>Pilih Siswa</label></th>
            <td>
                <select name="tagihan_siswa_id" style="min-width: 350px;">
                    <option value="">-- Pilih Siswa Terdaftar --</option>
                    <?php 
                    if ( $siswa_query->have_posts() ) {
                        while ( $siswa_query->have_posts() ) {
                            $siswa_query->the_post();
                            $nisn = get_post_meta( get_the_ID(), '_siswa_nisn', true );
                            echo '<option value="' . get_the_ID() . '" ' . selected( $selected_siswa, get_the_ID(), false ) . '>' . get_the_title() . ' (NISN: ' . $nisn . ')</option>';
                        }
                        wp_reset_postdata();
                    }
                    ?>
                </select>
            </td>
        </tr>
        <tr>
            <th>Nominal Tagihan (Rp)</th>
            <td><input type="number" name="tagihan_nominal" value="<?php echo esc_attr( $nominal ); ?>" class="regular-text"></td>
        </tr>
        <tr>
            <th><label for="tagihan_keterangan">Keterangan</label></th>
            <td>
                <textarea id="tagihan_keterangan" name="tagihan_keterangan" rows="4" class="large-text" placeholder="Contoh: Pembayaran SPP bulan Juli 2026"><?php echo esc_textarea( $keterangan ); ?></textarea>
                <p class="description">Catatan tambahan yang akan ditampilkan pada rincian tagihan siswa.</p>
            </td>
        </tr>
        <tr>
            <th>Pilih Biaya Tambahan (Opsional)</th>
            <td>
                <select name="tagihan_biaya_tambahan_id" style="min-width: 350px;">
                    <option value="">-- Tanpa Biaya Tambahan --</option>
                    <?php 
                    if ( $biaya_query->have_posts() ) {
                        while ( $biaya_query->have_posts() ) {
                            $biaya_query->the_post();
                            $nominal_b = get_post_meta( get_the_ID(), '_biaya_nominal', true );
                            echo '<option value="' . get_the_ID() . '" ' . selected( $selected_biaya, get_the_ID(), false ) . '>' . get_the_title() . ' (Rp ' . number_format(intval($nominal_b), 0, ',', '.') . ')</option>';
                        }
                        wp_reset_postdata();
                    }
                    ?>
                </select>
            </td>
        </tr>
        <tr>
            <th>Status Pembayaran</th>
            <td>
                <select name="tagihan_status">
                    <option value="Belum Dibayar" <?php selected( $status, 'Belum Dibayar' ); ?>>Belum Dibayar (Pending)</option>
                    <option value="Sedang Ditinjau" <?php selected( $status, 'Sedang Ditinjau' ); ?>>Sedang Ditinjau (Perlu Dicek)</option>
                    <option value="Lunas" <?php selected( $status, 'Lunas' ); ?>>Lunas</option>
                </select>
                <p class="description">Perubahan lewat form ini tercatat sebagai <strong>Manual</strong>. Update dari webhook tercatat sebagai <strong>API</strong>.</p>
            </td>
        </tr>
        <tr>
            <th>Log Status</th>
            <td>
                <?php
                $status_log = qrg_tagihan_get_status_log( $post->ID );
                if ( empty( $status_log ) ) :
                    ?>
                    <p class="description" style="margin:0;">Belum ada riwayat. Log muncul setelah status diubah lewat admin (manual), API, atau unggah bukti siswa.</p>
                    <?php
                else :
                    $status_log = array_reverse( $status_log );
                    ?>
                    <table class="widefat striped" style="max-width:820px;">
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Sumber</th>
                                <th>Perubahan</th>
                                <th>Oleh</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $status_log as $row ) :
                                $from = isset( $row['from'] ) && $row['from'] !== '' ? (string) $row['from'] : '—';
                                $to   = isset( $row['to'] ) ? (string) $row['to'] : '—';
                                $at   = isset( $row['at'] ) ? (string) $row['at'] : '';
                                $at_f = $at !== '' ? mysql2date( 'd M Y H:i', $at ) : '—';
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $at_f ); ?></td>
                                    <td><?php echo qrg_tagihan_status_via_badge( isset( $row['via'] ) ? $row['via'] : 'manual' ); ?></td>
                                    <td><?php echo esc_html( $from . ' → ' . $to ); ?></td>
                                    <td><?php echo esc_html( isset( $row['oleh'] ) ? $row['oleh'] : '—' ); ?></td>
                                    <td><?php echo esc_html( isset( $row['catatan'] ) && $row['catatan'] !== '' ? $row['catatan'] : '—' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                endif;
                ?>
            </td>
        </tr>
    </table>
    <?php
}

// Simpan Meta Data Tagihan Individual
add_action( 'save_post_master_tagihan', 'qrg_save_tagihan_details' );
function qrg_save_tagihan_details( $post_id ) {
    if ( ! isset( $_POST['qrg_tagihan_nonce'] ) || ! wp_verify_nonce( $_POST['qrg_tagihan_nonce'], 'qrg_save_tagihan_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! function_exists( 'qrg_can_save_post_meta' ) || ! qrg_can_save_post_meta( $post_id ) ) return;

    if ( isset( $_POST['tagihan_siswa_id'] ) ) update_post_meta( $post_id, '_tagihan_siswa_id', sanitize_text_field( $_POST['tagihan_siswa_id'] ) );
    if ( isset( $_POST['tagihan_nominal'] ) ) update_post_meta( $post_id, '_tagihan_nominal', sanitize_text_field( $_POST['tagihan_nominal'] ) );
    if ( isset( $_POST['tagihan_keterangan'] ) ) update_post_meta( $post_id, '_tagihan_keterangan', sanitize_textarea_field( wp_unslash( $_POST['tagihan_keterangan'] ) ) );
    if ( isset( $_POST['tagihan_status'] ) ) {
        qrg_tagihan_set_status(
            $post_id,
            sanitize_text_field( wp_unslash( $_POST['tagihan_status'] ) ),
            [ 'via' => 'manual' ]
        );
    }
    
    $biaya_tambahan_id = isset( $_POST['tagihan_biaya_tambahan_id'] ) ? sanitize_text_field( $_POST['tagihan_biaya_tambahan_id'] ) : '';
    update_post_meta( $post_id, '_tagihan_biaya_tambahan_id', $biaya_tambahan_id );
}

/**
 * Hitung ringkasan tagihan per siswa.
 *
 * @param int $siswa_id ID post master_siswa.
 * @return array{count:int,total:int,lunas:int,belum:int,ditinjau:int,items:array}
 */
function qrg_get_siswa_tagihan_summary( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $summary  = [
        'count'    => 0,
        'total'    => 0,
        'lunas'    => 0,
        'belum'    => 0,
        'ditinjau' => 0,
        'items'    => [],
    ];

    if ( $siswa_id <= 0 ) {
        return $summary;
    }

    $tagihan_ids = get_posts( [
        'post_type'      => 'master_tagihan',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_tagihan_siswa_id',
                'value' => (string) $siswa_id,
            ],
        ],
    ] );

    foreach ( $tagihan_ids as $tagihan_id ) {
        $nominal  = (int) get_post_meta( $tagihan_id, '_tagihan_nominal', true );
        $biaya_id = get_post_meta( $tagihan_id, '_tagihan_biaya_tambahan_id', true );
        if ( $biaya_id ) {
            $nominal += (int) get_post_meta( $biaya_id, '_biaya_nominal', true );
        }
        $status = get_post_meta( $tagihan_id, '_tagihan_status', true );
        if ( ! $status ) {
            $status = 'Belum Dibayar';
        }

        $summary['count']++;
        $summary['total'] += $nominal;
        if ( $status === 'Lunas' ) {
            $summary['lunas'] += $nominal;
        } elseif ( $status === 'Sedang Ditinjau' ) {
            $summary['ditinjau'] += $nominal;
        } else {
            $summary['belum'] += $nominal;
        }

        $summary['items'][] = [
            'id'      => (int) $tagihan_id,
            'title'   => get_the_title( $tagihan_id ),
            'nominal' => $nominal,
            'status'  => $status,
        ];
    }

    return $summary;
}

// Filter daftar tagihan berdasarkan siswa (dari halaman Data Siswa)
add_action( 'pre_get_posts', 'qrg_filter_tagihan_by_siswa' );
function qrg_filter_tagihan_by_siswa( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_tagihan' ) {
        return;
    }
    $siswa_id = isset( $_GET['siswa_id'] ) ? absint( $_GET['siswa_id'] ) : 0;
    if ( ! $siswa_id ) {
        return;
    }
    $meta_query   = (array) $query->get( 'meta_query' );
    $meta_query[] = [
        'key'   => '_tagihan_siswa_id',
        'value' => (string) $siswa_id,
    ];
    $query->set( 'meta_query', $meta_query );
}

add_action( 'restrict_manage_posts', 'qrg_tagihan_siswa_filter_notice' );
function qrg_tagihan_siswa_filter_notice() {
    global $typenow;
    if ( $typenow !== 'master_tagihan' ) {
        return;
    }
    $siswa_id = isset( $_GET['siswa_id'] ) ? absint( $_GET['siswa_id'] ) : 0;
    if ( ! $siswa_id ) {
        return;
    }
    $nama = get_the_title( $siswa_id );
    echo '<span class="description" style="margin-left:8px;display:inline-block;padding:4px 0;">';
    echo 'Filter siswa: <strong>' . esc_html( $nama ? $nama : '#' . $siswa_id ) . '</strong> ';
    echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=master_tagihan' ) ) . '">Hapus filter</a>';
    echo '</span>';
}

// Atur Susunan Kolom Tabel Admin
add_filter( 'manage_master_tagihan_posts_columns', 'qrg_set_tagihan_columns' );
function qrg_set_tagihan_columns($columns) {
    unset($columns['date']);
    $columns['title']         = 'No. Invoice / Keterangan';
    $columns['nama_siswa']    = 'Nama Siswa / Pendaftar';
    $columns['kelas_siswa']   = 'Kelas / Jalur';
    $columns['nominal']       = 'Total Tagihan';
    $columns['status_bayar']  = 'Status';
    $columns['log_status']    = 'Log Status';
    return $columns;
}

add_action( 'manage_master_tagihan_posts_custom_column' , 'qrg_fill_tagihan_columns', 10, 2 );
function qrg_fill_tagihan_columns( $column, $post_id ) {
    $siswa_id = get_post_meta( $post_id, '_tagihan_siswa_id', true );
    $psb_id   = (int) get_post_meta( $post_id, '_tagihan_psb_id', true );
    $jalur_id = (int) get_post_meta( $post_id, '_tagihan_jalur_id', true );
    
    switch ( $column ) {
        case 'nama_siswa' :
            if ( $siswa_id ) {
                echo esc_html( get_the_title( $siswa_id ) );
            } elseif ( $psb_id ) {
                echo esc_html( get_the_title( $psb_id ) );
                echo '<div style="font-size:11px;color:#666;">Pendaftar PSB</div>';
            } else {
                echo '<span style="color:red;">Belum Dipilih</span>';
            }
            break;
        case 'kelas_siswa' :
            if ( $siswa_id ) {
                echo esc_html( qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) ) );
            } elseif ( $jalur_id && function_exists( 'qrg_get_jalur_label' ) ) {
                echo esc_html( qrg_get_jalur_label( $jalur_id ) );
            } else {
                echo '-';
            }
            break;
        case 'nominal' :
            $nominal = get_post_meta( $post_id, '_tagihan_nominal', true );
            $biaya_id = get_post_meta( $post_id, '_tagihan_biaya_tambahan_id', true );
            $total = intval($nominal);
            if ( $biaya_id ) {
                $biaya_nominal = get_post_meta( $biaya_id, '_biaya_nominal', true );
                $total += intval($biaya_nominal);
            }
            echo '<strong>Rp ' . number_format( $total, 0, ',', '.' ) . '</strong>';
            if ( $biaya_id ) {
                $biaya_title = get_the_title( $biaya_id );
                $biaya_nominal = get_post_meta( $biaya_id, '_biaya_nominal', true );
                echo '<div style="font-size:11px; color:#666;">(+ ' . esc_html($biaya_title) . ': Rp ' . number_format(intval($biaya_nominal), 0, ',', '.') . ')</div>';
            }
            break;
        case 'status_bayar' :
            $status = get_post_meta( $post_id, '_tagihan_status', true );
            if ( $status === 'Lunas' ) { $color = '#4caf50'; } 
            elseif ( $status === 'Sedang Ditinjau' ) { $color = '#2196f3'; } 
            else { $color = '#ff9800'; }
            echo '<span style="background:'.$color.'; color:#fff; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:bold;">'.esc_html($status).'</span>';
            $via = (string) get_post_meta( $post_id, '_tagihan_status_via', true );
            if ( $via === '' ) {
                $log_last = qrg_tagihan_get_status_log( $post_id );
                if ( ! empty( $log_last ) ) {
                    $last = $log_last[ count( $log_last ) - 1 ];
                    $via  = isset( $last['via'] ) ? (string) $last['via'] : '';
                }
            }
            if ( $via !== '' ) {
                echo '<div style="margin-top:5px;">' . qrg_tagihan_status_via_badge( $via ) . '</div>';
            }
            break;
        case 'log_status' :
            $log = qrg_tagihan_get_status_log( $post_id );
            if ( empty( $log ) ) {
                echo '<span style="color:#94a3b8;">—</span>';
                break;
            }
            $log = array_slice( array_reverse( $log ), 0, 4 );
            echo '<div class="qrg-st-log">';
            foreach ( $log as $row ) {
                $from = isset( $row['from'] ) && $row['from'] !== '' ? (string) $row['from'] : '—';
                $to   = isset( $row['to'] ) ? (string) $row['to'] : '—';
                $at   = isset( $row['at'] ) ? (string) $row['at'] : '';
                $at_f = $at !== '' ? mysql2date( 'd M Y H:i', $at ) : '';
                echo '<div class="qrg-st-log__row">';
                echo qrg_tagihan_status_via_badge( isset( $row['via'] ) ? $row['via'] : 'manual' );
                echo ' <span>' . esc_html( $from . ' → ' . $to ) . '</span>';
                if ( $at_f !== '' ) {
                    echo '<div class="qrg-st-log__time">' . esc_html( $at_f ) . '</div>';
                }
                echo '</div>';
            }
            echo '</div>';
            break;
    }
}

add_action( 'admin_head-edit.php', 'qrg_tagihan_status_log_admin_css' );
function qrg_tagihan_status_log_admin_css() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_tagihan' ) {
        return;
    }
    ?>
    <style>
        .column-log_status { width: 220px; }
        .qrg-st-via {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .02em;
            padding: 1px 7px;
            border-radius: 999px;
            line-height: 1.6;
            vertical-align: middle;
        }
        .qrg-st-via--api { background: #ede9fe; color: #5b21b6; }
        .qrg-st-via--manual { background: #e2e8f0; color: #334155; }
        .qrg-st-via--siswa { background: #dbeafe; color: #1d4ed8; }
        .qrg-st-via--sistem { background: #f1f5f9; color: #475569; }
        .qrg-st-log { font-size: 11px; line-height: 1.4; color: #475569; }
        .qrg-st-log__row { margin: 0 0 7px; }
        .qrg-st-log__row:last-child { margin-bottom: 0; }
        .qrg-st-log__time { color: #94a3b8; font-size: 10px; margin-top: 1px; }
        .post-type-master_tagihan .qrg-st-via { margin-right: 2px; }
    </style>
    <?php
}

add_action( 'admin_head-post.php', 'qrg_tagihan_status_log_edit_css' );
add_action( 'admin_head-post-new.php', 'qrg_tagihan_status_log_edit_css' );
function qrg_tagihan_status_log_edit_css() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'master_tagihan' ) {
        return;
    }
    ?>
    <style>
        .qrg-st-via {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
        }
        .qrg-st-via--api { background: #ede9fe; color: #5b21b6; }
        .qrg-st-via--manual { background: #e2e8f0; color: #334155; }
        .qrg-st-via--siswa { background: #dbeafe; color: #1d4ed8; }
        .qrg-st-via--sistem { background: #f1f5f9; color: #475569; }
    </style>
    <?php
}

add_action( 'restrict_manage_posts', 'qrg_tagihan_status_via_filter_dropdown', 20 );
function qrg_tagihan_status_via_filter_dropdown() {
    global $typenow;
    if ( $typenow !== 'master_tagihan' ) {
        return;
    }
    $current = isset( $_GET['status_via'] ) ? sanitize_key( wp_unslash( $_GET['status_via'] ) ) : '';
    echo '<select name="status_via">';
    echo '<option value="">Semua sumber status</option>';
    foreach ( qrg_tagihan_status_via_labels() as $key => $label ) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr( $key ),
            selected( $current, $key, false ),
            esc_html( $label )
        );
    }
    echo '</select>';
}

add_action( 'pre_get_posts', 'qrg_filter_tagihan_by_status_via' );
function qrg_filter_tagihan_by_status_via( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->id !== 'edit-master_tagihan' ) {
        return;
    }
    $via = isset( $_GET['status_via'] ) ? sanitize_key( wp_unslash( $_GET['status_via'] ) ) : '';
    if ( $via === '' || ! isset( qrg_tagihan_status_via_labels()[ $via ] ) ) {
        return;
    }
    $meta_query   = (array) $query->get( 'meta_query' );
    $meta_query[] = [
        'key'   => '_tagihan_status_via',
        'value' => $via,
    ];
    $query->set( 'meta_query', $meta_query );
}