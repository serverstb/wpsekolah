<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// EXPORT & IMPORT DATA GURU & SISWA
// ============================================================

// 1. Handle POST/GET requests (export/import) sebelum HTML dikirim
add_action( 'admin_init', 'qrg_handle_export_import_actions' );
function qrg_handle_export_import_actions() {
    if ( isset( $_GET['qrg_action'] ) && $_GET['qrg_action'] === 'export_guru' ) {
        if ( ! current_user_can('manage_options') ) wp_die('Akses ditolak.');
        check_admin_referer('qrg_export_guru');
        qrg_do_export_guru();
        exit;
    }

    if ( isset( $_GET['qrg_action'] ) && $_GET['qrg_action'] === 'export_siswa' ) {
        if ( ! current_user_can('manage_options') ) wp_die('Akses ditolak.');
        check_admin_referer('qrg_export_siswa');
        qrg_do_export_siswa();
        exit;
    }

    if ( isset( $_POST['qrg_import_type'] ) && $_POST['qrg_import_type'] === 'guru' ) {
        if ( ! current_user_can('manage_options') ) wp_die('Akses ditolak.');
        check_admin_referer('qrg_import_guru');
        $result = qrg_do_import_guru();
        $base = isset($_POST['_redirect_back']) && $_POST['_redirect_back'] === 'list'
            ? admin_url('edit.php?post_type=master_guru')
            : admin_url('admin.php?page=qrg-export-import&tab=guru');
        $redirect = add_query_arg(['qrg_import_done' => '1', 'imported' => $result['imported'], 'skipped' => $result['skipped']], $base);
        if ( ! empty($result['error']) ) {
            $redirect = add_query_arg('import_error', urlencode($result['error']), $redirect);
        }
        wp_redirect($redirect);
        exit;
    }

    if ( isset( $_POST['qrg_import_type'] ) && $_POST['qrg_import_type'] === 'siswa' ) {
        if ( ! current_user_can('manage_options') ) wp_die('Akses ditolak.');
        check_admin_referer('qrg_import_siswa');
        $result = qrg_do_import_siswa();
        $base = isset($_POST['_redirect_back']) && $_POST['_redirect_back'] === 'list'
            ? admin_url('edit.php?post_type=master_siswa')
            : admin_url('admin.php?page=qrg-export-import&tab=siswa');
        $redirect = add_query_arg(['qrg_import_done' => '1', 'imported' => $result['imported'], 'skipped' => $result['skipped']], $base);
        if ( ! empty($result['error']) ) {
            $redirect = add_query_arg('import_error', urlencode($result['error']), $redirect);
        }
        wp_redirect($redirect);
        exit;
    }
}

// ============================================================
// TOOLBAR EXPORT/IMPORT DI HALAMAN LIST GURU & SISWA
// ============================================================

// Tambah tombol filter/toolbar di atas tabel list
add_action( 'restrict_manage_posts', 'qrg_list_export_import_toolbar' );
function qrg_list_export_import_toolbar( $post_type ) {
    if ( $post_type !== 'master_guru' && $post_type !== 'master_siswa' ) return;
    if ( ! current_user_can('manage_options') ) return;

    if ( $post_type === 'master_guru' ) {
        $export_url = wp_nonce_url(
            admin_url('edit.php?post_type=master_guru&qrg_action=export_guru'),
            'qrg_export_guru'
        );
        $type_label  = 'Guru';
        $import_type = 'guru';
    } else {
        $export_url = wp_nonce_url(
            admin_url('edit.php?post_type=master_siswa&qrg_action=export_siswa'),
            'qrg_export_siswa'
        );
        $type_label  = 'Siswa';
        $import_type = 'siswa';
    }
    ?>
    <style>
        .qrg-toolbar { display:inline-flex; align-items:center; gap:8px; margin-left:4px; }
        .qrg-btn-dl { display:inline-flex; align-items:center; gap:5px; padding:0 12px; height:30px; line-height:30px;
                      background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff!important; border-radius:5px;
                      font-size:12.5px; font-weight:600; text-decoration:none!important; border:none;
                      box-shadow:0 1px 4px rgba(37,99,235,.3); transition:all .15s; cursor:pointer; }
        .qrg-btn-dl:hover { background:linear-gradient(135deg,#1e40af,#1d4ed8); transform:translateY(-1px); }
        .qrg-btn-up { display:inline-flex; align-items:center; gap:5px; padding:0 12px; height:30px; line-height:30px;
                      background:linear-gradient(135deg,#059669,#10b981); color:#fff!important; border-radius:5px;
                      font-size:12.5px; font-weight:600; border:none; cursor:pointer;
                      box-shadow:0 1px 4px rgba(16,185,129,.3); transition:all .15s; }
        .qrg-btn-up:hover { background:linear-gradient(135deg,#047857,#059669); transform:translateY(-1px); }

        /* Modal overlay */
        #qrg-import-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
                            z-index:99999; align-items:center; justify-content:center; }
        #qrg-import-modal.open { display:flex; }
        .qrg-modal-box { background:#fff; border-radius:14px; padding:32px; width:540px; max-width:94vw;
                         box-shadow:0 20px 60px rgba(0,0,0,.25); animation:qrgSlideIn .2s ease; }
        @keyframes qrgSlideIn { from { transform:translateY(-20px); opacity:0; } to { transform:translateY(0); opacity:1; } }
        .qrg-modal-box h3 { font-size:17px; font-weight:700; color:#1e293b; margin:0 0 6px; }
        .qrg-modal-box p  { color:#64748b; font-size:13px; margin:0 0 18px; }
        .qrg-dz { border:2px dashed #cbd5e1; border-radius:10px; padding:24px; text-align:center;
                  background:#f8fafc; cursor:pointer; transition:all .2s; }
        .qrg-dz:hover,.qrg-dz.over { border-color:#2563eb; background:#eff6ff; }
        .qrg-dz .dz-icon { font-size:32px; display:block; margin-bottom:6px; }
        .qrg-dz p { margin:0 0 4px; font-size:13px; color:#64748b; }
        .qrg-dz-chosen { display:none; align-items:center; gap:8px; background:#eff6ff; border:1px solid #bfdbfe;
                          border-radius:8px; padding:9px 14px; margin-top:10px; font-size:13px;
                          color:#1d4ed8; font-weight:600; }
        .qrg-dz-chosen.v { display:flex; }
        .qrg-modal-chk { display:flex; align-items:flex-start; gap:9px; font-size:13px; color:#475569;
                          margin:14px 0; cursor:pointer; line-height:1.5; }
        .qrg-modal-chk input { margin-top:3px; flex-shrink:0; }
        .qrg-modal-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
        .qrg-btn-submit { display:inline-flex; align-items:center; gap:6px; padding:10px 22px;
                           background:linear-gradient(135deg,#059669,#10b981); color:#fff; border-radius:8px;
                           font-weight:700; font-size:13.5px; border:none; cursor:pointer; transition:all .2s;
                           box-shadow:0 2px 6px rgba(16,185,129,.3); }
        .qrg-btn-submit:hover:not(:disabled) { background:linear-gradient(135deg,#047857,#059669); transform:translateY(-1px); }
        .qrg-btn-submit:disabled { opacity:.5; cursor:not-allowed; }
        .qrg-btn-cancel { padding:10px 18px; background:#f1f5f9; color:#475569; border-radius:8px;
                           font-weight:600; font-size:13.5px; border:1px solid #e2e8f0; cursor:pointer; }
        .qrg-btn-cancel:hover { background:#e2e8f0; }
        .qrg-tpl-hint { background:#fffbeb; border:1px solid #fde68a; border-radius:8px;
                        padding:10px 14px; font-size:12px; color:#92400e; margin-bottom:16px; }
    </style>

    <span class="qrg-toolbar">
        <a href="<?php echo esc_url($export_url); ?>" class="qrg-btn-dl">
            ⬇️ Export CSV
        </a>
        <button type="button" class="qrg-btn-up" onclick="qrgOpenModal('<?php echo esc_js($import_type); ?>')">
            ⬆️ Import CSV
        </button>
    </span>
    <?php
}

// Admin notice setelah import berhasil/gagal di halaman list
add_action( 'admin_notices', 'qrg_list_import_notice' );
function qrg_list_import_notice() {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array($screen->post_type, ['master_guru', 'master_siswa']) ) return;
    if ( ! isset($_GET['qrg_import_done']) ) return;

    $imported = intval($_GET['imported'] ?? 0);
    $skipped  = intval($_GET['skipped']  ?? 0);
    $error    = isset($_GET['import_error']) ? urldecode($_GET['import_error']) : '';

    if ( $error ) {
        echo '<div class="notice notice-error is-dismissible"><p>❌ <strong>Import Gagal:</strong> ' . esc_html($error) . '</p></div>';
    } else {
        $msg = '✅ <strong>Import Berhasil!</strong> ' . $imported . ' data berhasil diimpor.';
        if ( $skipped > 0 ) $msg .= ' | <strong>' . $skipped . '</strong> data dilewati (duplikat / format tidak valid).';
        echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
    }
}

// Modal Import (dicetak di footer halaman list guru & siswa)
add_action( 'admin_footer', 'qrg_list_import_modal' );
function qrg_list_import_modal() {
    $screen = get_current_screen();
    if ( ! $screen ) return;

    $show_guru  = ( $screen->id === 'edit-master_guru' );
    $show_siswa = ( $screen->id === 'edit-master_siswa' );
    if ( ! $show_guru && ! $show_siswa ) return;

    $guru_action  = admin_url('admin-post.php');
    $siswa_action = admin_url('admin-post.php');
    ?>
    <div id="qrg-import-modal">
        <div class="qrg-modal-box">

            <!-- ── Modal Guru ── -->
            <div id="qrg-modal-guru" style="display:none;">
                <h3>⬆️ Import Data Guru dari CSV</h3>
                <p>Upload file CSV untuk menambahkan atau memperbarui data guru secara massal.</p>
                <div class="qrg-tpl-hint">
                    📋 <strong>Format kolom:</strong> Nama Guru*, NUPTK, NIP, Jabatan, Pendidikan, Tgl Lahir, Sejak Tahun, Alamat, No. Telepon, Nama Bank, No. Rekening<br>
                    💡 Klik <em>Export CSV</em> terlebih dahulu untuk mendapat template siap pakai.
                </div>
                <form method="post" enctype="multipart/form-data" id="qrg-form-guru"
                      action="<?php echo esc_url(admin_url('admin.php')); ?>">
                    <?php wp_nonce_field('qrg_import_guru'); ?>
                    <input type="hidden" name="qrg_import_type" value="guru">
                    <input type="hidden" name="page" value="qrg-export-import">
                    <input type="hidden" name="_redirect_back" value="list">

                    <div class="qrg-dz" id="qdz-guru" onclick="document.getElementById('qfi-guru').click()">
                        <span class="dz-icon">📂</span>
                        <p><strong>Klik untuk memilih file CSV</strong></p>
                        <p style="font-size:12px;color:#94a3b8;">atau seret &amp; lepas file di sini · hanya .csv</p>
                    </div>
                    <input type="file" id="qfi-guru" name="guru_csv" style="display:none" accept=".csv"
                           onchange="qrgModalPick('guru')">
                    <div class="qrg-dz-chosen" id="qfc-guru"><span>📄</span><span id="qfn-guru"></span></div>

                    <label class="qrg-modal-chk">
                        <input type="checkbox" name="guru_update_duplicate" value="1">
                        <span><strong>Update data duplikat</strong> — Jika nama guru sudah ada, data akan diperbarui. Jika tidak dicentang, data duplikat akan dilewati.</span>
                    </label>

                    <div class="qrg-modal-footer">
                        <button type="button" class="qrg-btn-cancel" onclick="qrgCloseModal()">Batal</button>
                        <button type="submit" class="qrg-btn-submit" id="qbs-guru" disabled>⬆️ Mulai Import</button>
                    </div>
                </form>
            </div>

            <!-- ── Modal Siswa ── -->
            <div id="qrg-modal-siswa" style="display:none;">
                <h3>⬆️ Import Data Siswa dari CSV</h3>
                <p>Upload file CSV untuk menambahkan atau memperbarui data siswa secara massal.</p>
                <div class="qrg-tpl-hint">
                    📋 <strong>Format kolom:</strong> Nama Siswa*, NISN*, Kelas*, Asal Sekolah, Tempat Lahir, Tgl Lahir, Alamat, Nama Ortu, Pend. Ortu, No. Telepon<br>
                    💡 Klik <em>Export CSV</em> terlebih dahulu untuk mendapat template siap pakai. Duplikat dideteksi via <strong>NISN</strong>.
                </div>
                <form method="post" enctype="multipart/form-data" id="qrg-form-siswa"
                      action="<?php echo esc_url(admin_url('admin.php')); ?>">
                    <?php wp_nonce_field('qrg_import_siswa'); ?>
                    <input type="hidden" name="qrg_import_type" value="siswa">
                    <input type="hidden" name="page" value="qrg-export-import">
                    <input type="hidden" name="_redirect_back" value="list">

                    <div class="qrg-dz" id="qdz-siswa" onclick="document.getElementById('qfi-siswa').click()">
                        <span class="dz-icon">📂</span>
                        <p><strong>Klik untuk memilih file CSV</strong></p>
                        <p style="font-size:12px;color:#94a3b8;">atau seret &amp; lepas file di sini · hanya .csv</p>
                    </div>
                    <input type="file" id="qfi-siswa" name="siswa_csv" style="display:none" accept=".csv"
                           onchange="qrgModalPick('siswa')">
                    <div class="qrg-dz-chosen" id="qfc-siswa"><span>📄</span><span id="qfn-siswa"></span></div>

                    <label class="qrg-modal-chk">
                        <input type="checkbox" name="siswa_update_duplicate" value="1">
                        <span><strong>Update data duplikat</strong> — Jika NISN siswa sudah ada, data akan diperbarui. Jika tidak dicentang, data duplikat akan dilewati.</span>
                    </label>

                    <div class="qrg-modal-footer">
                        <button type="button" class="qrg-btn-cancel" onclick="qrgCloseModal()">Batal</button>
                        <button type="submit" class="qrg-btn-submit" id="qbs-siswa" disabled>⬆️ Mulai Import</button>
                    </div>
                </form>
            </div>

        </div><!-- /.qrg-modal-box -->
    </div><!-- /#qrg-import-modal -->

    <script>
    function qrgOpenModal(type) {
        document.getElementById('qrg-modal-guru').style.display  = type === 'guru'  ? '' : 'none';
        document.getElementById('qrg-modal-siswa').style.display = type === 'siswa' ? '' : 'none';
        document.getElementById('qrg-import-modal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function qrgCloseModal() {
        document.getElementById('qrg-import-modal').classList.remove('open');
        document.body.style.overflow = '';
    }
    // Close when clicking overlay
    document.getElementById('qrg-import-modal').addEventListener('click', function(e) {
        if (e.target === this) qrgCloseModal();
    });
    // ESC to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') qrgCloseModal();
    });

    function qrgModalPick(t) {
        var f = document.getElementById('qfi-' + t);
        if (f.files && f.files[0]) {
            document.getElementById('qfn-' + t).textContent = f.files[0].name + ' (' + (f.files[0].size/1024).toFixed(1) + ' KB)';
            document.getElementById('qfc-' + t).classList.add('v');
            document.getElementById('qbs-' + t).disabled = false;
        }
    }

    // Drag-and-drop per zona
    ['guru','siswa'].forEach(function(t) {
        var z = document.getElementById('qdz-' + t);
        if (!z) return;
        z.addEventListener('dragover',  function(e){ e.preventDefault(); z.classList.add('over'); });
        z.addEventListener('dragleave', function(){ z.classList.remove('over'); });
        z.addEventListener('drop', function(e){
            e.preventDefault(); z.classList.remove('over');
            var inp = document.getElementById('qfi-' + t);
            if (e.dataTransfer.files.length) {
                // Assign via DataTransfer
                var dt = new DataTransfer();
                dt.items.add(e.dataTransfer.files[0]);
                inp.files = dt.files;
                qrgModalPick(t);
            }
        });
        // Konfirmasi submit
        var form = document.getElementById('qrg-form-' + t);
        if (form) form.addEventListener('submit', function(ev) {
            var label = t === 'guru' ? 'Guru' : 'Siswa';
            if (!confirm('Yakin ingin memulai import Data ' + label + '? Proses ini tidak dapat dibatalkan.')) ev.preventDefault();
        });
    });
    </script>
    <?php
}


function qrg_do_export_guru() {
    $posts = get_posts([
        'post_type'      => 'master_guru',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $filename = 'data-guru-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // BOM for Excel

    fputcsv($output, [
        'Nama Guru', 'NUPTK', 'NIP', 'Jabatan', 'Pendidikan Terakhir',
        'Tanggal Lahir', 'Sejak Tahun', 'Alamat', 'No. Telepon', 'Nama Bank', 'No. Rekening',
    ]);

    foreach ( $posts as $post ) {
        fputcsv($output, [
            $post->post_title,
            get_post_meta($post->ID, '_guru_nuptk', true),
            get_post_meta($post->ID, '_guru_nip', true),
            get_post_meta($post->ID, '_guru_jabatan', true),
            get_post_meta($post->ID, '_guru_pendidikan', true),
            get_post_meta($post->ID, '_guru_tgl_lahir', true),
            get_post_meta($post->ID, '_guru_sejak', true),
            get_post_meta($post->ID, '_guru_alamat', true),
            get_post_meta($post->ID, '_guru_no_telp', true),
            get_post_meta($post->ID, '_guru_nama_bank', true),
            get_post_meta($post->ID, '_guru_no_rek', true),
        ]);
    }
    fclose($output);
}

// ============================================================
// EXPORT SISWA
// ============================================================
function qrg_do_export_siswa() {
    $posts = get_posts([
        'post_type'      => 'master_siswa',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $filename = 'data-siswa-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Nama Siswa', 'NISN', 'Kelas', 'Asal Sekolah', 'Tempat Lahir',
        'Tanggal Lahir', 'Alamat', 'Nama Orang Tua / Wali',
        'Pendidikan Terakhir Orang Tua', 'No. Telepon',
    ]);

    foreach ( $posts as $post ) {
        fputcsv($output, [
            $post->post_title,
            get_post_meta($post->ID, '_siswa_nisn', true),
            qrg_get_kelas_label( get_post_meta($post->ID, '_siswa_kelas', true) ),
            get_post_meta($post->ID, '_siswa_asal_sekolah', true),
            get_post_meta($post->ID, '_siswa_tempat_lahir', true),
            get_post_meta($post->ID, '_siswa_tgl_lahir', true),
            get_post_meta($post->ID, '_siswa_alamat', true),
            get_post_meta($post->ID, '_siswa_nama_ortu', true),
            get_post_meta($post->ID, '_siswa_pend_ortu', true),
            get_post_meta($post->ID, '_siswa_no_telp', true),
        ]);
    }
    fclose($output);
}

// ============================================================
// IMPORT GURU
// ============================================================
function qrg_do_import_guru() {
    $result = ['imported' => 0, 'skipped' => 0, 'error' => ''];

    if ( empty($_FILES['guru_csv']['tmp_name']) ) {
        $result['error'] = 'File CSV tidak ditemukan. Silakan pilih file terlebih dahulu.';
        return $result;
    }

    $handle = fopen($_FILES['guru_csv']['tmp_name'], 'r');
    if ( ! $handle ) {
        $result['error'] = 'Gagal membuka file CSV.';
        return $result;
    }

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $header = fgetcsv($handle);
    if ( ! $header ) {
        $result['error'] = 'File CSV kosong atau format tidak valid.';
        fclose($handle);
        return $result;
    }

    $update_mode = isset($_POST['guru_update_duplicate']) && $_POST['guru_update_duplicate'] === '1';

    while ( ($row = fgetcsv($handle)) !== false ) {
        if ( count($row) < 2 ) { $result['skipped']++; continue; }

        $nama = isset($row[0]) ? sanitize_text_field($row[0]) : '';
        if ( empty($nama) ) { $result['skipped']++; continue; }

        $existing = get_posts([
            'post_type'   => 'master_guru',
            'title'       => $nama,
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        if ( ! empty($existing) ) {
            if ( $update_mode ) {
                $post_id = $existing[0];
            } else {
                $result['skipped']++;
                continue;
            }
        } else {
            $post_id = wp_insert_post([
                'post_title'  => $nama,
                'post_type'   => 'master_guru',
                'post_status' => 'publish',
            ]);
        }

        if ( is_wp_error($post_id) || ! $post_id ) { $result['skipped']++; continue; }

        update_post_meta($post_id, '_guru_nuptk',     isset($row[1])  ? sanitize_text_field($row[1])     : '');
        update_post_meta($post_id, '_guru_nip',       isset($row[2])  ? sanitize_text_field($row[2])     : '');
        update_post_meta($post_id, '_guru_jabatan',   isset($row[3])  ? sanitize_text_field($row[3])     : '');
        update_post_meta($post_id, '_guru_pendidikan',isset($row[4])  ? sanitize_text_field($row[4])     : '');
        update_post_meta($post_id, '_guru_tgl_lahir', isset($row[5])  ? sanitize_text_field($row[5])     : '');
        update_post_meta($post_id, '_guru_sejak',     isset($row[6])  ? sanitize_text_field($row[6])     : '');
        update_post_meta($post_id, '_guru_alamat',    isset($row[7])  ? sanitize_textarea_field($row[7]) : '');
        update_post_meta($post_id, '_guru_no_telp',   isset($row[8])  ? sanitize_text_field($row[8])     : '');
        update_post_meta($post_id, '_guru_nama_bank', isset($row[9])  ? sanitize_text_field($row[9])     : '');
        update_post_meta($post_id, '_guru_no_rek',    isset($row[10]) ? sanitize_text_field($row[10])    : '');

        $result['imported']++;
    }

    fclose($handle);
    return $result;
}

// ============================================================
// IMPORT SISWA
// ============================================================
function qrg_do_import_siswa() {
    $result = ['imported' => 0, 'skipped' => 0, 'error' => ''];

    if ( empty($_FILES['siswa_csv']['tmp_name']) ) {
        $result['error'] = 'File CSV tidak ditemukan. Silakan pilih file terlebih dahulu.';
        return $result;
    }

    $handle = fopen($_FILES['siswa_csv']['tmp_name'], 'r');
    if ( ! $handle ) {
        $result['error'] = 'Gagal membuka file CSV.';
        return $result;
    }

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $header = fgetcsv($handle);
    if ( ! $header ) {
        $result['error'] = 'File CSV kosong atau format tidak valid.';
        fclose($handle);
        return $result;
    }

    $update_mode = isset($_POST['siswa_update_duplicate']) && $_POST['siswa_update_duplicate'] === '1';

    while ( ($row = fgetcsv($handle)) !== false ) {
        if ( count($row) < 2 ) { $result['skipped']++; continue; }

        $nama = isset($row[0]) ? sanitize_text_field($row[0]) : '';
        $nisn = isset($row[1]) ? sanitize_text_field($row[1]) : '';
        if ( empty($nama) ) { $result['skipped']++; continue; }

        // Deteksi duplikat berdasarkan NISN
        $existing_by_nisn = [];
        if ( ! empty($nisn) ) {
            global $wpdb;
            $existing_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_siswa_nisn' AND meta_value = %s LIMIT 1",
                $nisn
            ));
            if ( ! empty($existing_ids) ) {
                $existing_by_nisn = $existing_ids;
            }
        }

        if ( ! empty($existing_by_nisn) ) {
            if ( $update_mode ) {
                $post_id = $existing_by_nisn[0];
            } else {
                $result['skipped']++;
                continue;
            }
        } else {
            $post_id = wp_insert_post([
                'post_title'  => $nama,
                'post_type'   => 'master_siswa',
                'post_status' => 'publish',
            ]);
        }

        if ( is_wp_error($post_id) || ! $post_id ) { $result['skipped']++; continue; }

        update_post_meta($post_id, '_siswa_nisn',         $nisn);
        update_post_meta($post_id, '_siswa_kelas',        isset($row[2]) ? qrg_resolve_kelas_value($row[2]) : '');
        update_post_meta($post_id, '_siswa_asal_sekolah', isset($row[3]) ? sanitize_text_field($row[3])     : '');
        update_post_meta($post_id, '_siswa_tempat_lahir', isset($row[4]) ? sanitize_text_field($row[4])     : '');
        update_post_meta($post_id, '_siswa_tgl_lahir',    isset($row[5]) ? sanitize_text_field($row[5])     : '');
        update_post_meta($post_id, '_siswa_alamat',       isset($row[6]) ? sanitize_textarea_field($row[6]) : '');
        update_post_meta($post_id, '_siswa_nama_ortu',    isset($row[7]) ? sanitize_text_field($row[7])     : '');
        update_post_meta($post_id, '_siswa_pend_ortu',    isset($row[8]) ? sanitize_text_field($row[8])     : '');
        update_post_meta($post_id, '_siswa_no_telp',      isset($row[9]) ? sanitize_text_field($row[9])     : '');

        $result['imported']++;
    }

    fclose($handle);
    return $result;
}

// ============================================================
// HALAMAN ADMIN
// ============================================================
function qrg_export_import_page_callback() {
    $active_tab   = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'guru';
    $import_done  = isset($_GET['import_done']) && $_GET['import_done'] === '1';
    $imported     = isset($_GET['imported']) ? intval($_GET['imported']) : 0;
    $skipped      = isset($_GET['skipped'])  ? intval($_GET['skipped'])  : 0;
    $import_error = isset($_GET['import_error']) ? urldecode($_GET['import_error']) : '';

    $guru_export_url  = wp_nonce_url( admin_url('admin.php?page=qrg-export-import&qrg_action=export_guru'),  'qrg_export_guru' );
    $siswa_export_url = wp_nonce_url( admin_url('admin.php?page=qrg-export-import&qrg_action=export_siswa'), 'qrg_export_siswa' );
    ?>
    <style>
        .qrg-ei-wrap { max-width: 900px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .qrg-ei-header h1 { font-size: 26px; font-weight: 700; color: #1e293b; margin: 0 0 6px; }
        .qrg-ei-header p  { color: #64748b; margin: 0 0 28px; font-size: 14px; }
        .qrg-tabs { display: flex; gap: 4px; border-bottom: 2px solid #e2e8f0; margin-bottom: 28px; }
        .qrg-tab  { padding: 10px 22px; border-radius: 6px 6px 0 0; background: transparent; font-size: 14px;
                    font-weight: 600; color: #64748b; cursor: pointer; text-decoration: none;
                    border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all .2s; }
        .qrg-tab:hover  { color: #2563eb; background: #f0f4ff; }
        .qrg-tab.active { color: #2563eb; border-bottom-color: #2563eb; background: #f0f4ff; }
        .qrg-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 28px;
                    margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
        .qrg-card h2 { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 700;
                       color: #1e293b; margin: 0 0 8px; }
        .qrg-card h2 .ico { width: 32px; height: 32px; border-radius: 8px; display:inline-flex;
                             align-items:center; justify-content:center; font-size:16px; }
        .qrg-card-desc { color: #64748b; font-size: 13px; margin: 0 0 18px; line-height: 1.6; }
        .qrg-btn-export { display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px;
                          background: linear-gradient(135deg, #1d4ed8, #2563eb); color: #fff; border-radius: 8px;
                          font-weight: 600; font-size: 14px; text-decoration: none;
                          box-shadow: 0 2px 6px rgba(37,99,235,.3); transition: all .2s; }
        .qrg-btn-export:hover { color:#fff; transform: translateY(-1px); background: linear-gradient(135deg,#1e40af,#1d4ed8);
                                 box-shadow: 0 4px 12px rgba(37,99,235,.4); }
        .qrg-import-zone { border: 2px dashed #cbd5e1; border-radius: 10px; padding: 30px 20px; text-align: center;
                           background: #f8fafc; transition: all .2s; cursor: pointer; }
        .qrg-import-zone:hover, .qrg-import-zone.dragover { border-color: #2563eb; background: #eff6ff; }
        .qrg-import-zone .zi { font-size: 36px; display:block; margin-bottom: 8px; }
        .qrg-import-zone p { margin: 0 0 8px; color: #64748b; font-size: 13px; }
        .qrg-file-chosen { display: none; align-items: center; gap: 10px; background: #eff6ff;
                           border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 16px;
                           margin-top: 12px; font-size: 13px; color: #1d4ed8; font-weight: 600; }
        .qrg-file-chosen.show { display: flex; }
        .qrg-check-label { display:flex; align-items:flex-start; gap:10px; font-size:13px;
                           color:#475569; margin:16px 0; cursor:pointer; line-height:1.5; }
        .qrg-check-label input { margin-top:3px; flex-shrink:0; }
        .qrg-btn-import { display:inline-flex; align-items:center; gap:8px; padding:11px 28px;
                          background:linear-gradient(135deg,#059669,#10b981); color:#fff; border-radius:8px;
                          font-weight:700; font-size:14px; border:none; cursor:pointer; transition:all .2s;
                          box-shadow:0 2px 6px rgba(16,185,129,.3); }
        .qrg-btn-import:hover:not(:disabled) { background:linear-gradient(135deg,#047857,#059669); transform:translateY(-1px);
                                                box-shadow:0 4px 12px rgba(16,185,129,.4); }
        .qrg-btn-import:disabled { opacity:.5; cursor:not-allowed; }
        .qrg-notice { display:flex; align-items:flex-start; gap:12px; padding:14px 18px; border-radius:10px;
                      margin-bottom:20px; font-size:13.5px; }
        .qrg-notice.ok  { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
        .qrg-notice.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        .qrg-notice .ni { font-size:18px; flex-shrink:0; }
        .tpl-box { background:#fffbeb; border:1px solid #fde68a; border-radius:10px;
                   padding:16px 20px; margin-bottom:20px; }
        .tpl-box p { margin:0 0 10px; font-size:13px; color:#92400e; font-weight:600; }
        .tpl-table { width:100%; border-collapse:collapse; font-size:12.5px; }
        .tpl-table th { background:#f8fafc; padding:8px 12px; text-align:left; color:#475569;
                        font-weight:600; border-bottom:1px solid #e2e8f0; }
        .tpl-table td { padding:7px 12px; color:#64748b; border-bottom:1px solid #f1f5f9; }
        .tpl-table tr:last-child td { border-bottom:none; }
        .cn { width:36px; text-align:center; color:#94a3b8; font-weight:700; }
        .req { color:#dc2626; font-size:11px; font-weight:700; }
    </style>

    <div class="qrg-ei-wrap">
        <div class="qrg-ei-header">
            <h1>📤 Export &amp; Import Data</h1>
            <p>Ekspor data ke file CSV atau impor data dari file CSV untuk Data Guru dan Data Siswa.</p>
        </div>

        <?php if ( $import_done ) : ?>
            <?php if ( $import_error ) : ?>
            <div class="qrg-notice err">
                <span class="ni">❌</span>
                <div><strong>Import Gagal:</strong> <?php echo esc_html($import_error); ?></div>
            </div>
            <?php else : ?>
            <div class="qrg-notice ok">
                <span class="ni">✅</span>
                <div>
                    <strong>Import Berhasil!</strong><br>
                    <strong><?php echo $imported; ?></strong> data berhasil diimpor.
                    <?php if ($skipped > 0) echo ' | <strong>' . $skipped . '</strong> data dilewati (duplikat / format tidak valid).'; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="qrg-tabs">
            <a href="?page=qrg-export-import&tab=guru"  class="qrg-tab <?php echo $active_tab==='guru'  ? 'active':''; ?>">👨‍🏫 Data Guru</a>
            <a href="?page=qrg-export-import&tab=siswa" class="qrg-tab <?php echo $active_tab==='siswa' ? 'active':''; ?>">🎒 Data Siswa</a>
        </div>

        <?php if ( $active_tab === 'guru' ) : ?>

        <div class="qrg-card">
            <h2><span class="ico" style="background:#eff6ff;">⬇️</span> Export Data Guru</h2>
            <p class="qrg-card-desc">Unduh seluruh data guru ke dalam file <strong>CSV</strong> yang dapat dibuka di Excel atau Google Sheets.</p>
            <p style="font-size:13px;color:#475569;margin-bottom:18px;">
                📊 Total: <strong style="color:#1e293b;"><?php echo esc_html(wp_count_posts('master_guru')->publish); ?> guru</strong>
            </p>
            <a href="<?php echo esc_url($guru_export_url); ?>" class="qrg-btn-export">⬇️&nbsp; Download CSV Data Guru</a>
        </div>

        <div class="qrg-card">
            <h2><span class="ico" style="background:#f0fdf4;">⬆️</span> Import Data Guru dari CSV</h2>
            <p class="qrg-card-desc">Upload file CSV untuk menambahkan atau memperbarui data guru secara massal.</p>
            <div class="tpl-box">
                <p>📋 Format Kolom CSV (urutan harus sesuai):</p>
                <table class="tpl-table">
                    <tr><th class="cn">#</th><th>Kolom</th><th>Contoh</th></tr>
                    <tr><td class="cn">1</td><td><strong>Nama Guru</strong> <span class="req">*</span></td><td>Budi Santoso, S.Pd</td></tr>
                    <tr><td class="cn">2</td><td>NUPTK</td><td>1234567890123456</td></tr>
                    <tr><td class="cn">3</td><td>NIP</td><td>196501011990011001</td></tr>
                    <tr><td class="cn">4</td><td>Jabatan</td><td>Wali Kelas 10-A</td></tr>
                    <tr><td class="cn">5</td><td>Pendidikan Terakhir</td><td>S1 / D4</td></tr>
                    <tr><td class="cn">6</td><td>Tanggal Lahir</td><td>1975-06-15</td></tr>
                    <tr><td class="cn">7</td><td>Sejak Tahun</td><td>2005</td></tr>
                    <tr><td class="cn">8</td><td>Alamat</td><td>Jl. Melati No.10 Surabaya</td></tr>
                    <tr><td class="cn">9</td><td>No. Telepon</td><td>08123456789</td></tr>
                    <tr><td class="cn">10</td><td>Nama Bank</td><td>Bank Mandiri</td></tr>
                    <tr><td class="cn">11</td><td>No. Rekening</td><td>1234567890</td></tr>
                </table>
                <p style="margin:10px 0 0;font-size:12px;">💡 Export data guru terlebih dahulu untuk template CSV yang siap pakai.</p>
            </div>
            <form method="post" enctype="multipart/form-data" id="form-guru">
                <?php wp_nonce_field('qrg_import_guru'); ?>
                <input type="hidden" name="qrg_import_type" value="guru">
                <div class="qrg-import-zone" id="dz-guru" onclick="document.getElementById('f-guru').click()">
                    <span class="zi">📂</span>
                    <p><strong>Klik untuk memilih file CSV</strong></p>
                    <p>atau seret &amp; lepas file ke sini</p>
                    <p style="font-size:12px;color:#94a3b8;">Hanya file .csv</p>
                </div>
                <input type="file" name="guru_csv" id="f-guru" style="display:none" accept=".csv" onchange="qrgPick('guru')">
                <div class="qrg-file-chosen" id="fc-guru"><span>📄</span><span id="fn-guru"></span></div>
                <label class="qrg-check-label">
                    <input type="checkbox" name="guru_update_duplicate" value="1">
                    <span><strong>Update data duplikat</strong> — Jika nama guru sudah ada, data akan diperbarui. Jika tidak dicentang, data duplikat akan dilewati.</span>
                </label>
                <button type="submit" class="qrg-btn-import" id="bi-guru" disabled>⬆️&nbsp; Mulai Import Data Guru</button>
            </form>
        </div>

        <?php elseif ( $active_tab === 'siswa' ) : ?>

        <div class="qrg-card">
            <h2><span class="ico" style="background:#eff6ff;">⬇️</span> Export Data Siswa</h2>
            <p class="qrg-card-desc">Unduh seluruh data siswa ke dalam file <strong>CSV</strong> yang dapat dibuka di Excel atau Google Sheets.</p>
            <p style="font-size:13px;color:#475569;margin-bottom:18px;">
                📊 Total: <strong style="color:#1e293b;"><?php echo esc_html(wp_count_posts('master_siswa')->publish); ?> siswa</strong>
            </p>
            <a href="<?php echo esc_url($siswa_export_url); ?>" class="qrg-btn-export">⬇️&nbsp; Download CSV Data Siswa</a>
        </div>

        <div class="qrg-card">
            <h2><span class="ico" style="background:#f0fdf4;">⬆️</span> Import Data Siswa dari CSV</h2>
            <p class="qrg-card-desc">Upload file CSV untuk menambahkan atau memperbarui data siswa secara massal.</p>
            <div class="tpl-box">
                <p>📋 Format Kolom CSV (urutan harus sesuai):</p>
                <table class="tpl-table">
                    <tr><th class="cn">#</th><th>Kolom</th><th>Contoh</th></tr>
                    <tr><td class="cn">1</td><td><strong>Nama Siswa</strong> <span class="req">*</span></td><td>Ahmad Fauzi</td></tr>
                    <tr><td class="cn">2</td><td>NISN <span class="req">*</span></td><td>0034567890</td></tr>
                    <tr><td class="cn">3</td><td>Kelas <span class="req">*</span></td><td>10-A</td></tr>
                    <tr><td class="cn">4</td><td>Asal Sekolah</td><td>SMP Negeri 1 Sidoarjo</td></tr>
                    <tr><td class="cn">5</td><td>Tempat Lahir</td><td>Surabaya</td></tr>
                    <tr><td class="cn">6</td><td>Tanggal Lahir</td><td>2008-03-22</td></tr>
                    <tr><td class="cn">7</td><td>Alamat</td><td>Jl. Merdeka No.5 Surabaya</td></tr>
                    <tr><td class="cn">8</td><td>Nama Orang Tua / Wali</td><td>Hendra Kusuma</td></tr>
                    <tr><td class="cn">9</td><td>Pendidikan Terakhir Orang Tua</td><td>S1</td></tr>
                    <tr><td class="cn">10</td><td>No. Telepon</td><td>08129876543</td></tr>
                </table>
                <p style="margin:10px 0 0;font-size:12px;">💡 Duplikat dideteksi berdasarkan <strong>NISN</strong>. Export data siswa terlebih dahulu untuk template siap pakai.</p>
            </div>
            <form method="post" enctype="multipart/form-data" id="form-siswa">
                <?php wp_nonce_field('qrg_import_siswa'); ?>
                <input type="hidden" name="qrg_import_type" value="siswa">
                <div class="qrg-import-zone" id="dz-siswa" onclick="document.getElementById('f-siswa').click()">
                    <span class="zi">📂</span>
                    <p><strong>Klik untuk memilih file CSV</strong></p>
                    <p>atau seret &amp; lepas file ke sini</p>
                    <p style="font-size:12px;color:#94a3b8;">Hanya file .csv</p>
                </div>
                <input type="file" name="siswa_csv" id="f-siswa" style="display:none" accept=".csv" onchange="qrgPick('siswa')">
                <div class="qrg-file-chosen" id="fc-siswa"><span>📄</span><span id="fn-siswa"></span></div>
                <label class="qrg-check-label">
                    <input type="checkbox" name="siswa_update_duplicate" value="1">
                    <span><strong>Update data duplikat</strong> — Jika NISN siswa sudah ada, data akan diperbarui. Jika tidak dicentang, data duplikat akan dilewati.</span>
                </label>
                <button type="submit" class="qrg-btn-import" id="bi-siswa" disabled>⬆️&nbsp; Mulai Import Data Siswa</button>
            </form>
        </div>

        <?php endif; ?>
    </div>

    <script>
    function qrgPick(t) {
        var f = document.getElementById('f-' + t);
        if (f.files && f.files[0]) {
            document.getElementById('fn-' + t).textContent = f.files[0].name + ' (' + (f.files[0].size/1024).toFixed(1) + ' KB)';
            document.getElementById('fc-' + t).classList.add('show');
            document.getElementById('bi-' + t).disabled = false;
        }
    }
    ['guru','siswa'].forEach(function(t) {
        var z = document.getElementById('dz-' + t);
        if (!z) return;
        z.addEventListener('dragover', function(e){ e.preventDefault(); z.classList.add('dragover'); });
        z.addEventListener('dragleave', function(){ z.classList.remove('dragover'); });
        z.addEventListener('drop', function(e){
            e.preventDefault(); z.classList.remove('dragover');
            document.getElementById('f-' + t).files = e.dataTransfer.files;
            qrgPick(t);
        });
        var form = document.getElementById('form-' + t);
        if (form) form.addEventListener('submit', function(e){
            var label = t === 'guru' ? 'Guru' : 'Siswa';
            if (!confirm('Yakin ingin memulai import Data ' + label + '? Proses ini tidak dapat dibatalkan.')) e.preventDefault();
        });
    });
    </script>
    <?php
}
