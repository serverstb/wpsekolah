<?php
/**
 * Informasi & pengumuman — portal siswa /sekolah/informasi/
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', 'qrg_register_pengumuman_cpt' );
function qrg_register_pengumuman_cpt() {
    register_post_type( 'master_pengumuman', [
        'labels' => [
            'name'               => 'Pengumuman',
            'singular_name'      => 'Pengumuman',
            'add_new'            => 'Tambah Pengumuman',
            'add_new_item'       => 'Tambah Pengumuman',
            'edit_item'          => 'Edit Pengumuman',
            'new_item'           => 'Pengumuman Baru',
            'view_item'          => 'Lihat Pengumuman',
            'search_items'       => 'Cari Pengumuman',
            'not_found'          => 'Belum ada pengumuman',
            'not_found_in_trash' => 'Tidak ada di tong sampah',
            'all_items'          => 'Pengumuman',
            'menu_name'          => 'Pengumuman',
        ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => 'master-data-plugin',
        'supports'        => [ 'title', 'editor' ],
        'capability_type' => 'post',
        'map_meta_cap'    => true,
        'menu_position'   => 27,
    ] );
}

add_action( 'add_meta_boxes', 'qrg_pengumuman_metaboxes' );
function qrg_pengumuman_metaboxes() {
    add_meta_box(
        'qrg_pengumuman_detail',
        'Pengaturan Pengumuman',
        'qrg_pengumuman_metabox_render',
        'master_pengumuman',
        'side',
        'high'
    );
}

/**
 * @param WP_Post $post Post.
 */
function qrg_pengumuman_metabox_render( $post ) {
    wp_nonce_field( 'qrg_pengumuman_admin_save', 'qrg_pengumuman_admin_nonce' );
    $pin    = (string) get_post_meta( $post->ID, '_pengumuman_pin', true ) === '1';
    $aktif  = get_post_meta( $post->ID, '_pengumuman_aktif', true );
    if ( $aktif === '' ) {
        $aktif = '1';
    }
    $aktif = (string) $aktif === '1';
    ?>
    <p>
        <label>
            <input type="checkbox" name="pengumuman_aktif" value="1" <?php checked( $aktif ); ?>>
            Tampilkan di portal siswa
        </label>
    </p>
    <p>
        <label>
            <input type="checkbox" name="pengumuman_pin" value="1" <?php checked( $pin ); ?>>
            Sematkan (tampil di atas)
        </label>
    </p>
    <p class="description">Isi pengumuman di editor konten. Judul wajib diisi.</p>
    <?php
}

add_action( 'save_post_master_pengumuman', 'qrg_pengumuman_save_meta' );
function qrg_pengumuman_save_meta( $post_id ) {
    if ( ! isset( $_POST['qrg_pengumuman_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qrg_pengumuman_admin_nonce'] ) ), 'qrg_pengumuman_admin_save' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    update_post_meta( $post_id, '_pengumuman_aktif', ! empty( $_POST['pengumuman_aktif'] ) ? '1' : '0' );
    update_post_meta( $post_id, '_pengumuman_pin', ! empty( $_POST['pengumuman_pin'] ) ? '1' : '0' );
}

add_filter( 'manage_master_pengumuman_posts_columns', 'qrg_pengumuman_columns' );
function qrg_pengumuman_columns( $columns ) {
    return [
        'cb'    => $columns['cb'],
        'title' => 'Judul',
        'pin'   => 'Sematan',
        'aktif' => 'Portal',
        'date'  => 'Tanggal',
    ];
}

add_action( 'manage_master_pengumuman_posts_custom_column', 'qrg_pengumuman_column_content', 10, 2 );
function qrg_pengumuman_column_content( $column, $post_id ) {
    if ( $column === 'pin' ) {
        echo get_post_meta( $post_id, '_pengumuman_pin', true ) === '1' ? '★ Ya' : '—';
    } elseif ( $column === 'aktif' ) {
        $aktif = get_post_meta( $post_id, '_pengumuman_aktif', true );
        if ( $aktif === '' ) {
            $aktif = '1';
        }
        echo $aktif === '1' ? 'Aktif' : 'Nonaktif';
    }
}

/**
 * Daftar pengumuman aktif untuk portal.
 *
 * @return WP_Post[]
 */
function qrg_pengumuman_get_aktif() {
    $posts = get_posts( [
        'post_type'      => 'master_pengumuman',
        'posts_per_page' => 50,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'   => '_pengumuman_aktif',
                'value' => '1',
            ],
            [
                'key'     => '_pengumuman_aktif',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ] );

    if ( empty( $posts ) ) {
        return [];
    }

    usort( $posts, function ( $a, $b ) {
        $pa = get_post_meta( $a->ID, '_pengumuman_pin', true ) === '1' ? 1 : 0;
        $pb = get_post_meta( $b->ID, '_pengumuman_pin', true ) === '1' ? 1 : 0;
        if ( $pa !== $pb ) {
            return $pb - $pa;
        }
        return strtotime( $b->post_date ) <=> strtotime( $a->post_date );
    } );

    return $posts;
}

/**
 * Cuplikan teks pengumuman.
 *
 * @param WP_Post $post Post.
 * @param int     $words Jumlah kata.
 * @return string
 */
function qrg_pengumuman_excerpt( $post, $words = 28 ) {
    $text = wp_strip_all_tags( (string) $post->post_content );
    $text = preg_replace( '/\s+/u', ' ', trim( $text ) );
    if ( $text === '' ) {
        return '';
    }
    return wp_trim_words( $text, $words, '…' );
}

/**
 * Halaman portal: daftar / detail pengumuman.
 *
 * @param int $siswa_id ID siswa.
 */
function qrg_render_sekolah_portal_informasi( $siswa_id ) {
    $siswa_id = (int) $siswa_id;
    $nama     = get_the_title( $siswa_id );
    $kelas    = function_exists( 'qrg_get_kelas_label' )
        ? qrg_get_kelas_label( get_post_meta( $siswa_id, '_siswa_kelas', true ) )
        : '';
    $site     = get_bloginfo( 'name' );
    $base_url = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url( 'informasi' )
        : home_url( '/sekolah/informasi/' );
    $hub_url  = function_exists( 'qrg_get_sekolah_portal_url' )
        ? qrg_get_sekolah_portal_url()
        : home_url( '/sekolah/' );
    $akun_url = add_query_arg( 'tab', 'akun', $hub_url );
    $avatar_sm = function_exists( 'qrg_get_siswa_avatar_url' ) ? qrg_get_siswa_avatar_url( $siswa_id, 'thumbnail' ) : '';
    $initial  = function_exists( 'mb_substr' )
        ? mb_strtoupper( mb_substr( (string) $nama, 0, 1 ) )
        : strtoupper( substr( (string) $nama, 0, 1 ) );
    if ( $initial === '' ) {
        $initial = 'S';
    }

    $view_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
    $items   = qrg_pengumuman_get_aktif();
    $detail  = null;

    if ( $view_id > 0 ) {
        foreach ( $items as $item ) {
            if ( (int) $item->ID === $view_id ) {
                $detail = $item;
                break;
            }
        }
        if ( ! $detail ) {
            $maybe = get_post( $view_id );
            if ( $maybe && $maybe->post_type === 'master_pengumuman' && $maybe->post_status === 'publish' ) {
                $aktif = get_post_meta( $view_id, '_pengumuman_aktif', true );
                if ( $aktif === '' || $aktif === '1' ) {
                    $detail = $maybe;
                }
            }
        }
        if ( ! $detail ) {
            wp_safe_redirect( $base_url );
            exit;
        }
    }

    $page_title  = $detail ? get_the_title( $detail ) : 'Informasi';
    $hero_main   = $detail ? 'Detail' : ( (string) count( $items ) . ' info' );
    $hero_meta   = $detail
        ? get_the_date( 'd M Y', $detail )
        : ( count( $items ) > 0 ? 'Pengumuman sekolah terbaru' : 'Belum ada pengumuman' );

    $extra = '<style>
        .qrg-sk--info-page .dana-sheet { padding-top: 18px; }
        .qrg-sk--info-page .dana-sheet__label { margin-top: 0; }
        .qrg-info-back {
            display: inline-flex; align-items: center; gap: 4px;
            color: #fff; text-decoration: none; font-size: 13px; font-weight: 600;
            opacity: .92; margin-bottom: 10px;
        }
        .qrg-info-back:active { opacity: .75; }
        .qrg-info-list {
            background: #fff; border-radius: 16px; overflow: hidden;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .qrg-info-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 16px; text-decoration: none; color: inherit;
            border-top: 1px solid #F2F2F7; -webkit-tap-highlight-color: transparent;
        }
        .qrg-info-item:first-child { border-top: 0; }
        .qrg-info-item:active { background: #F7F8FA; }
        .qrg-info-item__ico {
            flex: 0 0 auto; width: 42px; height: 42px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(243,104,224,.14); color: #C445B8; font-size: 16px; font-weight: 800;
        }
        .qrg-info-item__ico.is-pin {
            background: rgba(255,149,0,.14); color: #C93400;
        }
        .qrg-info-item__body { flex: 1; min-width: 0; }
        .qrg-info-item__title {
            margin: 0; font-size: 15px; font-weight: 700; letter-spacing: -.01em;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .qrg-info-item__excerpt {
            margin: 4px 0 0; font-size: 13px; color: #8E8E93; line-height: 1.4;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .qrg-info-item__meta {
            margin: 6px 0 0; font-size: 11px; font-weight: 700; color: #AEAEB2;
        }
        .qrg-info-item__chev { flex: 0 0 auto; color: #C7C7CC; font-size: 18px; font-weight: 700; margin-top: 8px; }
        .qrg-info-empty {
            text-align: center; padding: 28px 16px; color: #8E8E93; font-size: 14px;
            background: #F7F8FA; border: 1px dashed rgba(60,60,67,.18); border-radius: 16px;
        }
        .qrg-info-card {
            background: #fff; border-radius: 16px; padding: 18px 16px;
            border: 1px solid rgba(60,60,67,.08); box-shadow: 0 4px 16px rgba(15,23,42,.04);
        }
        .qrg-info-card__title {
            margin: 0 0 8px; font-size: 1.2rem; font-weight: 800; letter-spacing: -.02em; line-height: 1.3;
        }
        .qrg-info-card__meta {
            margin: 0 0 14px; font-size: 12px; font-weight: 700; color: #8E8E93;
        }
        .qrg-info-card__meta .pin {
            display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 999px;
            background: rgba(255,149,0,.14); color: #C93400; margin-right: 6px;
        }
        .qrg-info-card__body {
            font-size: 15px; line-height: 1.55; color: #1C1C1E;
        }
        .qrg-info-card__body p { margin: 0 0 12px; }
        .qrg-info-card__body p:last-child { margin-bottom: 0; }
        .qrg-info-card__body img { max-width: 100%; height: auto; border-radius: 12px; }
        .qrg-info-card__body a { color: #0086F0; }
    </style>';

    if ( function_exists( 'qrg_sekolah_portal_shell_start' ) ) {
        qrg_sekolah_portal_shell_start( $page_title, $extra, 'beranda' );
    }
    ?>
    <div class="qrg-sk qrg-sk--dana qrg-sk--info-page">
        <div class="dana-top">
            <a class="qrg-info-back" href="<?php echo esc_url( $detail ? $base_url : $hub_url ); ?>">
                ← <?php echo $detail ? 'Daftar informasi' : 'Beranda'; ?>
            </a>
            <div class="dana-top__row">
                <div class="dana-top__brand">
                    <a class="dana-top__logo<?php echo $avatar_sm ? ' has-photo' : ''; ?>" href="<?php echo esc_url( $akun_url ); ?>" aria-label="Profil">
                        <?php if ( $avatar_sm ) : ?>
                            <img src="<?php echo esc_url( $avatar_sm ); ?>" alt="" width="36" height="36">
                        <?php else : ?>
                            <?php echo esc_html( $initial ); ?>
                        <?php endif; ?>
                    </a>
                    <div>
                        <strong><?php echo esc_html( $nama ); ?></strong>
                        <span><?php echo $kelas ? 'Kelas ' . esc_html( $kelas ) : esc_html( $site ); ?></span>
                    </div>
                </div>
                <a class="dana-top__bell" href="<?php echo esc_url( $hub_url ); ?>" aria-label="Beranda">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
                </a>
            </div>
            <div class="dana-balance">
                <p class="dana-balance__label"><?php echo $detail ? 'Pengumuman' : 'Informasi sekolah'; ?></p>
                <div class="dana-balance__row">
                    <p class="dana-balance__amount" style="font-size:1.45rem;"><?php echo esc_html( $hero_main ); ?></p>
                </div>
                <p class="dana-balance__meta"><?php echo esc_html( $hero_meta ); ?> · <?php echo esc_html( $site ); ?></p>
            </div>
        </div>

        <div class="dana-sheet">
            <?php if ( $detail ) : ?>
                <p class="dana-sheet__label">Isi pengumuman</p>
                <article class="qrg-info-card">
                    <h1 class="qrg-info-card__title"><?php echo esc_html( get_the_title( $detail ) ); ?></h1>
                    <p class="qrg-info-card__meta">
                        <?php if ( get_post_meta( $detail->ID, '_pengumuman_pin', true ) === '1' ) : ?>
                            <span class="pin">Sematan</span>
                        <?php endif; ?>
                        <?php echo esc_html( get_the_date( 'd M Y · H:i', $detail ) ); ?>
                    </p>
                    <div class="qrg-info-card__body">
                        <?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- konten admin via WP editor.
                        echo apply_filters( 'the_content', $detail->post_content );
                        ?>
                    </div>
                </article>
            <?php else : ?>
                <p class="dana-sheet__label">Pengumuman</p>
                <?php if ( empty( $items ) ) : ?>
                    <div class="qrg-info-empty">Belum ada pengumuman untuk ditampilkan.</div>
                <?php else : ?>
                    <div class="qrg-info-list">
                        <?php foreach ( $items as $item ) :
                            $is_pin  = get_post_meta( $item->ID, '_pengumuman_pin', true ) === '1';
                            $excerpt = qrg_pengumuman_excerpt( $item );
                            $url     = add_query_arg( 'id', (int) $item->ID, $base_url );
                            ?>
                            <a class="qrg-info-item" href="<?php echo esc_url( $url ); ?>">
                                <span class="qrg-info-item__ico<?php echo $is_pin ? ' is-pin' : ''; ?>" aria-hidden="true">
                                    <?php echo $is_pin ? '★' : 'i'; ?>
                                </span>
                                <div class="qrg-info-item__body">
                                    <p class="qrg-info-item__title"><?php echo esc_html( get_the_title( $item ) ); ?></p>
                                    <?php if ( $excerpt !== '' ) : ?>
                                        <p class="qrg-info-item__excerpt"><?php echo esc_html( $excerpt ); ?></p>
                                    <?php endif; ?>
                                    <p class="qrg-info-item__meta"><?php echo esc_html( get_the_date( 'd M Y', $item ) ); ?></p>
                                </div>
                                <span class="qrg-info-item__chev" aria-hidden="true">›</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    if ( function_exists( 'qrg_sekolah_portal_shell_end' ) ) {
        qrg_sekolah_portal_shell_end();
    }
}
