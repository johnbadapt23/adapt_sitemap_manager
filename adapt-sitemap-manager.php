<?php
/**
 * Plugin Name: Adapt Sitemap Manager
 * Description: Unified sitemap manager for media attachments, posts, and ACF subscription downloads. Configure under Settings > Sitemap Manager.
 * Author: Adapt
 * Version: 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Option keys ───────────────────────────────────────────────────────────────
define( 'ADSX_ENABLE_MEDIA',      'adsx_enable_media' );
define( 'ADSX_ENABLE_POST',       'adsx_enable_post' );
define( 'ADSX_ENABLE_DOWNLOAD',   'adsx_enable_download' );
define( 'ADSX_OPTION_TERM',       'adsx_subscription_term' );
define( 'ADSX_OPTION_TYPE',       'adsx_sitemap_type' );
define( 'ADSX_DEFAULT_TERM',      'advantage-plus' );
define( 'ADSX_DEFAULT_TYPE',      'attachments' );
define( 'ADSX_MEDIA_DATE_FROM',   'adsx_media_date_from' );
define( 'ADSX_MEDIA_DATE_TO',     'adsx_media_date_to' );
define( 'ADSX_POST_DATE_FROM',    'adsx_post_date_from' );
define( 'ADSX_POST_DATE_TO',      'adsx_post_date_to' );
define( 'ADSX_DOWNLOAD_DATE_FROM', 'adsx_download_date_from' );
define( 'ADSX_DOWNLOAD_DATE_TO',   'adsx_download_date_to' );
define( 'ADSX_OPTION_FILENAME_FORMAT',   'adsx_filename_format' );
define( 'ADSX_DEFAULT_FILENAME_FORMAT',  'custom' );
define( 'ADSX_OPTION_SKIP_DUPLICATES',   'adsx_skip_duplicate_filenames' );
define( 'ADSX_DEFAULT_DATE_FROM', '2023-01-01' );

// ═══════════════════════════════════════════════════════════════════════════════
// 1. MEDIA SITEMAP HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Returns deduped, priority-sorted media library attachments.
 *
 * Rules:
 * - Types: PDF, Word, PPT, Excel, Text/CSV, JSON/XML
 * - Date range controlled by settings (defaults: from 2023-01-01, to today)
 * - Deduplicated by base filename (without extension)
 * - PPTX > PPT > all others; ties broken by newest modified date
 *
 * @param string|null $date_from Override start date (YYYY-MM-DD). Empty = use saved option (fallback: 2023-01-01).
 * @param string|null $date_to   Override end date (YYYY-MM-DD).   Empty = use saved option (fallback: today).
 */
function adsx_get_media_attachments( $date_from = null, $date_to = null ) {

    $date_from = $date_from ?? get_option( ADSX_MEDIA_DATE_FROM, '' );
    $date_to   = $date_to   ?? get_option( ADSX_MEDIA_DATE_TO,   '' );

    // Fallbacks: empty start → 2023-01-01, empty end → today.
    if ( empty( $date_from ) ) $date_from = ADSX_DEFAULT_DATE_FROM;
    if ( empty( $date_to ) )   $date_to   = current_time( 'Y-m-d' );

    $date_clause = [ 'after' => $date_from, 'before' => $date_to, 'inclusive' => true ];

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'posts_per_page' => -1,
        'date_query'     => [ $date_clause ],
        'post_mime_type' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
            'application/json',
            'application/xml',
            'text/xml',
        ],
    ] );

    $map = [];

    foreach ( $attachments as $attachment ) {

        $url = wp_get_attachment_url( $attachment->ID );
        if ( ! $url ) continue;

        $path     = parse_url( $url, PHP_URL_PATH );
        $filename = $path ? basename( $path ) : '';
        $base     = pathinfo( $filename, PATHINFO_FILENAME );
        if ( ! $base ) continue;

        $key      = strtolower( trim( $base ) );
        $ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        $modified = (int) get_post_modified_time( 'U', true, $attachment->ID );
        $priority = ( $ext === 'pptx' ) ? 3 : ( ( $ext === 'ppt' ) ? 2 : 1 );

        if ( ! isset( $map[ $key ] ) ) {
            $map[ $key ] = compact( 'attachment', 'url', 'priority', 'modified', 'filename' );
            continue;
        }

        $ex = $map[ $key ];
        if ( $priority > $ex['priority'] || ( $priority === $ex['priority'] && $modified > $ex['modified'] ) ) {
            $map[ $key ] = compact( 'attachment', 'url', 'priority', 'modified', 'filename' );
        }
    }

    uasort( $map, fn( $a, $b ) => $b['modified'] <=> $a['modified'] );

    return $map;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2. POST SITEMAP HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Returns post IDs for the post sitemap.
 *
 * - Published posts within the configured date range (by modified date)
 * - Deduped by permalink
 * - When $check_404 is true, each URL is HEAD-checked (accurate but slow)
 * - When $check_404 is false, HEAD checks are skipped (fast, used for the counter)
 *
 * @param bool        $check_404 Whether to live-check each URL for 404s.
 * @param string|null $date_from Override start date (YYYY-MM-DD). Empty = use saved option (fallback: 2023-01-01).
 * @param string|null $date_to   Override end date (YYYY-MM-DD).   Empty = use saved option (fallback: today).
 */
function adsx_get_post_ids( $check_404 = true, $date_from = null, $date_to = null ) {

    $date_from = $date_from ?? get_option( ADSX_POST_DATE_FROM, '' );
    $date_to   = $date_to   ?? get_option( ADSX_POST_DATE_TO,   '' );

    // Fallbacks: empty start → 2023-01-01, empty end → today.
    if ( empty( $date_from ) ) $date_from = ADSX_DEFAULT_DATE_FROM;
    if ( empty( $date_to ) )   $date_to   = current_time( 'Y-m-d' );

    $date_clause = [ 'column' => 'post_modified_gmt', 'after' => $date_from, 'before' => $date_to, 'inclusive' => true ];

    $query = new WP_Query( [
        'post_type'              => 'post',
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'orderby'                => 'modified',
        'order'                  => 'DESC',
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'suppress_filters'       => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'date_query'             => [ $date_clause ],
    ] );

    if ( ! $query->have_posts() ) return [];

    $results   = [];
    $seen_urls = [];

    foreach ( $query->posts as $post_id ) {

        if ( get_post_status( $post_id ) !== 'publish' ) continue;

        $url = get_permalink( $post_id );
        if ( ! $url || isset( $seen_urls[ $url ] ) ) continue;

        if ( $check_404 ) {
            $response = wp_remote_head( $url, [
                'timeout'    => 5,
                'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; Sitemap-Checker',
                'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
            ] );
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) === 404 ) continue;
        }

        $seen_urls[ $url ] = true;
        $results[]         = $post_id;
    }

    return $results;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 3. DOWNLOAD SITEMAP HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Resolves a single ACF value (string URL, attachment ID, or array) to a URL.
 *
 * String values are validated to actually look like a URL or absolute path
 * before being accepted — some fields carry over stray legacy text ("No",
 * "N/A", "TBD", etc.) that isn't a real download location, and blindly
 * trusting any non-empty string caused those to be treated as fetchable
 * URLs (and logged as fetch failures) instead of correctly being skipped.
 */
function adsx_resolve_url( $value ) {
    if ( is_string( $value ) && trim( $value ) !== '' ) {
        $value = trim( $value );
        if ( filter_var( $value, FILTER_VALIDATE_URL ) || $value[0] === '/' ) {
            return $value;
        }
        return null;
    }
    if ( is_numeric( $value ) && (int) $value > 0 ) {
        $url = wp_get_attachment_url( (int) $value );
        return $url ?: null;
    }
    if ( is_array( $value ) && ! empty( $value['url'] ) && is_string( $value['url'] ) ) return trim( $value['url'] );
    return null;
}

/**
 * Resolves an ACF file-field value (string URL, attachment ID, array, or
 * repeater/group rows) down to a single URL, or null.
 */
function adsx_resolve_download_field( $value ) {

    if ( is_string( $value ) || is_numeric( $value ) ) {
        return adsx_resolve_url( $value );
    }

    if ( is_array( $value ) && ! empty( $value ) ) {

        if ( ! empty( $value['url'] ) && is_string( $value['url'] ) ) {
            return trim( $value['url'] );
        }

        foreach ( $value as $row ) {

            if ( is_string( $row ) || is_numeric( $row ) ) {
                $url = adsx_resolve_url( $row );
                if ( $url ) return $url;
                continue;
            }

            if ( ! is_array( $row ) ) continue;

            // Pass 1: prefer sub-fields named like download/file/link/url.
            foreach ( $row as $key => $sub ) {
                $k = strtolower( (string) $key );
                if ( preg_match( '/image|thumb|icon/', $k ) ) continue;
                if ( preg_match( '/download|file|link|url/', $k ) ) {
                    $url = adsx_resolve_url( $sub );
                    if ( $url ) return $url;
                }
            }

            // Pass 2: any non-image sub-field with a valid URL.
            foreach ( $row as $key => $sub ) {
                $k = strtolower( (string) $key );
                if ( preg_match( '/image|thumb|icon/', $k ) ) continue;
                $url = adsx_resolve_url( $sub );
                if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) return $url;
            }
        }
    }

    return null;
}

/**
 * Checks a repeater field's rows, in order, for one whose `download`
 * sub-field resolves to a URL/attachment. Returns null if the field
 * doesn't exist on this post, has no rows, or no row resolves.
 */
function adsx_get_download_url_from_repeater_rows( $post_id, $field_name ) {

    $rows = function_exists( 'get_field' )
        ? get_field( $field_name, $post_id )
        : get_post_meta( $post_id, $field_name, true );

    if ( empty( $rows ) || ! is_array( $rows ) ) return null;

    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) || ! array_key_exists( 'download', $row ) ) continue;

        $url = adsx_resolve_download_field( $row['download'] );
        if ( $url ) return $url;
    }

    return null;
}

/**
 * Checks the layout-appropriate repeater(s) for a download URL.
 *
 * `post_layout_type` picks the primary repeater: "slide-layout" uses
 * `slide_preview_module`, anything else (including "normal" or a missing
 * value) goes straight to `preview_module`. `preview_module` is always the
 * fallback here too — it is not gated by post_layout_type at all — so if
 * "slide-layout" is set but `slide_preview_module` has no rows (or no row
 * resolves), `preview_module` is checked as well before giving up.
 */
function adsx_get_download_url_from_layout_module( $post_id ) {

    $layout_type = function_exists( 'get_field' )
        ? get_field( 'post_layout_type', $post_id )
        : get_post_meta( $post_id, 'post_layout_type', true );
    $layout_type = is_string( $layout_type ) ? strtolower( trim( $layout_type ) ) : $layout_type;

    if ( $layout_type === 'slide-layout' ) {
        $url = adsx_get_download_url_from_repeater_rows( $post_id, 'slide_preview_module' );
        if ( $url ) return $url;
    }

    // Either the layout is "normal"/unset/unrecognized, or slide-layout's
    // repeater had nothing usable — preview_module is the universal fallback.
    return adsx_get_download_url_from_repeater_rows( $post_id, 'preview_module' );
}

/**
 * Checks the `download_link` repeater field for a download URL — each row
 * has a `download_url` text sub-field (a plain string, not a file/array).
 * Returns the first row (in order) whose `download_url` resolves to a
 * valid URL, or null if the field doesn't exist, has no rows, or none
 * resolve.
 */
function adsx_get_download_url_from_download_link( $post_id ) {

    $rows = function_exists( 'get_field' )
        ? get_field( 'download_link', $post_id )
        : get_post_meta( $post_id, 'download_link', true );

    if ( empty( $rows ) || ! is_array( $rows ) ) return null;

    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) || ! array_key_exists( 'download_url', $row ) ) continue;

        $url = adsx_resolve_url( $row['download_url'] );
        if ( $url ) return $url;
    }

    return null;
}

/**
 * Checks the standalone `share_download_url` text field. This field is
 * only shown in ACF when `download` (Yes/No) is "yes", so we double-check
 * that flag here too — otherwise a stale value left over from before the
 * field was hidden could get picked up.
 */
function adsx_get_share_download_url( $post_id ) {

    $download = function_exists( 'get_field' )
        ? get_field( 'download', $post_id )
        : get_post_meta( $post_id, 'download', true );

    $flag = is_string( $download ) ? strtolower( trim( $download ) ) : $download;
    if ( ! in_array( $flag, [ 'yes', true, 1, '1' ], true ) ) return null;

    $share_url = function_exists( 'get_field' )
        ? get_field( 'share_download_url', $post_id )
        : get_post_meta( $post_id, 'share_download_url', true );

    return adsx_resolve_url( $share_url );
}

/**
 * Returns a URL for a post's download. Checked in order, first match wins:
 *
 * 1. The layout-appropriate repeater (`preview_module` or
 *    `slide_preview_module`, per `post_layout_type`) — first row whose
 *    `download` sub-field resolves to a URL/attachment.
 * 2. `download_link` repeater — first row whose `download_url` text
 *    sub-field is a valid URL.
 * 3. `share_download_url` text field — only considered when `download`
 *    (Yes/No) is "yes".
 */
function adsx_get_download_url( $post_id ) {

    $url = adsx_get_download_url_from_layout_module( $post_id );
    if ( $url ) return $url;

    $url = adsx_get_download_url_from_download_link( $post_id );
    if ( $url ) return $url;

    return adsx_get_share_download_url( $post_id );
}

/**
 * Builds the display filename used inside the "Download All Files" ZIP:
 * "<filter-types term>： <article title>.<ext>", or just "<article title>.<ext>"
 * if the post has no term in the `filter-types` taxonomy. Uses the post's
 * first assigned filter-types term when there's more than one.
 *
 * Uses "：" (U+FF1A, fullwidth colon) rather than a literal ":" — ":" is a
 * reserved character on Windows (NTFS treats it as an alternate-data-stream
 * separator), so any recipient extracting the ZIP on Windows would have it
 * silently rewritten to "_". The fullwidth colon reads almost identically
 * but is ordinary text as far as every filesystem is concerned. Only
 * characters that would corrupt the ZIP's internal path structure
 * (slashes) or raw control characters are stripped.
 */
function adsx_build_download_filename( $post_id, $extension ) {

    $title = get_the_title( $post_id );
    if ( $title === '' ) $title = 'file';

    $term_name = '';
    $terms     = wp_get_post_terms( $post_id, 'filter-types' );
    if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
        $term_name = $terms[0]->name;
    }

    $base = $term_name !== '' ? "{$term_name}\xEF\xBC\x9A{$title}" : $title; // \xEF\xBC\x9A = UTF-8 for "："

    $base = str_replace( [ '/', '\\' ], '-', $base );
    $base = preg_replace( '/[\x00-\x1F\x7F]/', '', $base );
    $base = trim( $base );
    if ( $base === '' ) $base = 'file';

    $extension = is_string( $extension ) ? strtolower( trim( $extension, '.' ) ) : '';

    return $extension !== '' ? "{$base}.{$extension}" : $base;
}

/**
 * Debug helper — dumps the raw `preview_module`, `download_link`,
 * `download`, and `share_download_url` field values plus the final
 * resolution decision for a single post. Not hooked to anything; run it
 * directly from the command line to see exactly why a specific post is or
 * isn't producing a download URL, e.g.:
 *
 *   wp eval 'adsx_debug_download_url( 72627 );'
 */
function adsx_debug_download_url( $post_id ) {

    if ( ! function_exists( 'get_field' ) ) {
        echo "ACF's get_field() isn't available in this context (is ACF active?).\n";
    }

    $layout_type         = function_exists( 'get_field' ) ? get_field( 'post_layout_type', $post_id )    : get_post_meta( $post_id, 'post_layout_type', true );
    $preview_module      = function_exists( 'get_field' ) ? get_field( 'preview_module', $post_id )      : get_post_meta( $post_id, 'preview_module', true );
    $slide_preview_module = function_exists( 'get_field' ) ? get_field( 'slide_preview_module', $post_id ) : get_post_meta( $post_id, 'slide_preview_module', true );
    $download_link       = function_exists( 'get_field' ) ? get_field( 'download_link', $post_id )       : get_post_meta( $post_id, 'download_link', true );
    $download            = function_exists( 'get_field' ) ? get_field( 'download', $post_id )            : get_post_meta( $post_id, 'download', true );
    $share_download_url  = function_exists( 'get_field' ) ? get_field( 'share_download_url', $post_id )  : get_post_meta( $post_id, 'share_download_url', true );

    echo "Post ID: {$post_id}\n";
    echo "Post title: " . get_the_title( $post_id ) . "\n";
    echo "Post status: " . get_post_status( $post_id ) . "\n";
    echo "post_layout_type (raw value): ";
    var_export( $layout_type );
    echo "\n";
    echo "preview_module (raw value): ";
    var_export( $preview_module );
    echo "\n";
    echo "slide_preview_module (raw value): ";
    var_export( $slide_preview_module );
    echo "\n";
    echo "download_link (raw value): ";
    var_export( $download_link );
    echo "\n";
    echo "download (raw value): ";
    var_export( $download );
    echo "\n";
    echo "share_download_url (raw value): ";
    var_export( $share_download_url );
    echo "\n";
    echo "Resolved from slide_preview_module directly: ";
    var_export( adsx_get_download_url_from_repeater_rows( $post_id, 'slide_preview_module' ) );
    echo "\n";
    echo "Resolved from preview_module directly: ";
    var_export( adsx_get_download_url_from_repeater_rows( $post_id, 'preview_module' ) );
    echo "\n";
    echo "Resolved via layout module (combined logic): ";
    var_export( adsx_get_download_url_from_layout_module( $post_id ) );
    echo "\n";
    echo "Resolved via download_link: ";
    var_export( adsx_get_download_url_from_download_link( $post_id ) );
    echo "\n";
    echo "Resolved via share_download_url: ";
    var_export( adsx_get_share_download_url( $post_id ) );
    echo "\n";
    echo "Final resolved download URL: ";
    var_export( adsx_get_download_url( $post_id ) );
    echo "\n";
}

/**
 * Attempts to map a URL directly to a local file path under the uploads
 * directory, without touching the database. This is far more reliable
 * than attachment_url_to_postid() (which can miss scaled/replaced media
 * or query strings) and far cheaper than a DB lookup or a remote HTTP
 * fetch — it's just string comparison + a single file_exists() check.
 *
 * The match is done on the URL *path* only (e.g. "/wp-content/uploads/2026/03/file.pptx"),
 * deliberately ignoring the domain. That makes it robust to CDN-rewritten
 * hostnames, http vs https, and www vs non-www — any of which would break
 * a full-URL prefix match even though the file is genuinely local.
 *
 * Returns null if the URL's path doesn't correspond to a file under this
 * site's uploads directory (or the resolved file doesn't exist on disk —
 * e.g. a genuinely external URL), in which case the caller should fall
 * back to a remote fetch.
 */
function adsx_url_to_local_path( $url ) {

    if ( ! is_string( $url ) || $url === '' ) return null;

    $upload_dir = wp_upload_dir();
    if ( empty( $upload_dir['baseurl'] ) || empty( $upload_dir['basedir'] ) ) return null;

    $url_path = parse_url( $url, PHP_URL_PATH );
    if ( ! $url_path ) return null;
    $url_path = urldecode( $url_path );

    $base_path = parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
    if ( ! $base_path ) return null;
    $base_path = untrailingslashit( $base_path );

    $pos = strpos( $url_path, $base_path );
    if ( $pos === false ) return null;

    $relative = ltrim( substr( $url_path, $pos + strlen( $base_path ) ), '/' );
    if ( $relative === '' ) return null;

    $path = trailingslashit( $upload_dir['basedir'] ) . $relative;

    return ( file_exists( $path ) && is_readable( $path ) ) ? $path : null;
}

/**
 * Returns sitemap entries for the download sitemap.
 *
 * @param string|null $term_slug    Subscription term slug (falls back to saved option).
 * @param string|null $sitemap_type 'attachments' or 'posts' (falls back to saved option).
 * @param string|null $date_from    Override start date (YYYY-MM-DD), by creation/publish date. Empty = use saved option (fallback: 2023-01-01).
 * @param string|null $date_to      Override end date (YYYY-MM-DD).   Empty = use saved option (fallback: today).
 */
function adsx_get_download_entries( $term_slug = null, $sitemap_type = null, $date_from = null, $date_to = null ) {

    $term_slug    = $term_slug    ?? get_option( ADSX_OPTION_TERM, ADSX_DEFAULT_TERM );
    $sitemap_type = $sitemap_type ?? get_option( ADSX_OPTION_TYPE, ADSX_DEFAULT_TYPE );
    $date_from    = $date_from    ?? get_option( ADSX_DOWNLOAD_DATE_FROM, '' );
    $date_to      = $date_to      ?? get_option( ADSX_DOWNLOAD_DATE_TO,   '' );

    // Fallbacks: empty start → 2023-01-01, empty end → today.
    if ( empty( $date_from ) ) $date_from = ADSX_DEFAULT_DATE_FROM;
    if ( empty( $date_to ) )   $date_to   = current_time( 'Y-m-d' );

    // Filter by creation/publish date, not modified date — posts that were
    // bulk-edited, migrated, or re-tagged all pick up the same modified
    // timestamp regardless of when they were actually created, which makes
    // a modified-date filter useless here.
    $date_clause = [ 'column' => 'post_date_gmt', 'after' => $date_from, 'before' => $date_to, 'inclusive' => true ];

    $query = new WP_Query( [
        'post_type'              => 'post',
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'orderby'                => 'date',
        'order'                  => 'DESC',
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'tax_query'              => [ [
            'taxonomy' => 'subscription',
            'field'    => 'slug',
            'terms'    => $term_slug,
        ] ],
        'date_query'             => [ $date_clause ],
    ] );

    if ( ! $query->have_posts() ) return [];

    $entries = [];

    foreach ( $query->posts as $post_id ) {
        $url = $sitemap_type === 'posts'
            ? get_permalink( $post_id )
            : adsx_get_download_url( $post_id );

        if ( ! $url ) continue;

        $entries[] = [
            'post_id' => $post_id,
            'url'     => $url,
            'lastmod' => get_post_modified_time( 'c', true, $post_id ),
        ];
    }

    return $entries;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 4. AJAX: LIVE COUNTERS
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_adsx_count_media', function () {
    check_ajax_referer( 'adsx_count_media', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );

    $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : null;
    $date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : null;

    wp_send_json_success( [ 'count' => count( adsx_get_media_attachments( $date_from, $date_to ) ) ] );
} );

add_action( 'wp_ajax_adsx_count_post', function () {
    check_ajax_referer( 'adsx_count_post', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );

    $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : null;
    $date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : null;

    // Skip 404 check here — too slow for a live counter.
    wp_send_json_success( [ 'count' => count( adsx_get_post_ids( false, $date_from, $date_to ) ) ] );
} );

add_action( 'wp_ajax_adsx_count_download', function () {
    check_ajax_referer( 'adsx_count_download', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );

    $term_slug = isset( $_GET['adsx_term'] ) && $_GET['adsx_term'] !== ''
        ? sanitize_title( wp_unslash( $_GET['adsx_term'] ) )
        : get_option( ADSX_OPTION_TERM, ADSX_DEFAULT_TERM );

    $sitemap_type = isset( $_GET['adsx_type'] ) && in_array( $_GET['adsx_type'], [ 'attachments', 'posts' ], true )
        ? sanitize_key( $_GET['adsx_type'] )
        : get_option( ADSX_OPTION_TYPE, ADSX_DEFAULT_TYPE );

    $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : null;
    $date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : null;

    wp_send_json_success( [ 'count' => count( adsx_get_download_entries( $term_slug, $sitemap_type, $date_from, $date_to ) ) ] );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// 5. ADMIN SETTINGS PAGE
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
    add_options_page(
        'Sitemap Manager',
        'Sitemap Manager',
        'manage_options',
        'adsx-sitemap-manager',
        'adsx_render_settings_page'
    );
} );

add_action( 'admin_init', function () {

    foreach ( [ ADSX_ENABLE_MEDIA, ADSX_ENABLE_POST, ADSX_ENABLE_DOWNLOAD ] as $key ) {
        register_setting( 'adsx_settings', $key, [
            'type'              => 'string',
            'sanitize_callback' => fn( $v ) => $v === '1' ? '1' : '0',
            'default'           => '0',
        ] );
    }

    register_setting( 'adsx_settings', ADSX_OPTION_TERM, [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_title',
        'default'           => ADSX_DEFAULT_TERM,
    ] );

    $sanitize_date = function ( $v ) {
        $v = sanitize_text_field( $v );
        // Accept YYYY-MM-DD only; reject anything else.
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
    };

    foreach ( [ ADSX_MEDIA_DATE_FROM, ADSX_MEDIA_DATE_TO, ADSX_POST_DATE_FROM, ADSX_POST_DATE_TO, ADSX_DOWNLOAD_DATE_FROM, ADSX_DOWNLOAD_DATE_TO ] as $key ) {
        register_setting( 'adsx_settings', $key, [
            'type'              => 'string',
            'sanitize_callback' => $sanitize_date,
            'default'           => '',
        ] );
    }

    register_setting( 'adsx_settings', ADSX_OPTION_TYPE, [
        'type'              => 'string',
        'sanitize_callback' => fn( $v ) => in_array( $v, [ 'attachments', 'posts' ], true ) ? $v : ADSX_DEFAULT_TYPE,
        'default'           => ADSX_DEFAULT_TYPE,
    ] );

    register_setting( 'adsx_settings', ADSX_OPTION_FILENAME_FORMAT, [
        'type'              => 'string',
        'sanitize_callback' => fn( $v ) => in_array( $v, [ 'custom', 'original' ], true ) ? $v : ADSX_DEFAULT_FILENAME_FORMAT,
        'default'           => ADSX_DEFAULT_FILENAME_FORMAT,
    ] );

    register_setting( 'adsx_settings', ADSX_OPTION_SKIP_DUPLICATES, [
        'type'              => 'string',
        'sanitize_callback' => fn( $v ) => $v === '1' ? '1' : '0',
        'default'           => '0',
    ] );
} );

function adsx_render_settings_page() {

    if ( ! current_user_can( 'manage_options' ) ) return;

    $enable_media    = get_option( ADSX_ENABLE_MEDIA )    === '1';
    $enable_post     = get_option( ADSX_ENABLE_POST )     === '1';
    $enable_download = get_option( ADSX_ENABLE_DOWNLOAD ) === '1';
    $selected_term   = get_option( ADSX_OPTION_TERM, ADSX_DEFAULT_TERM );
    $selected_type   = get_option( ADSX_OPTION_TYPE, ADSX_DEFAULT_TYPE );
    $selected_filename_format = get_option( ADSX_OPTION_FILENAME_FORMAT, ADSX_DEFAULT_FILENAME_FORMAT );
    $skip_duplicates          = get_option( ADSX_OPTION_SKIP_DUPLICATES ) === '1';

    $media_date_from    = get_option( ADSX_MEDIA_DATE_FROM,    '' );
    $media_date_to      = get_option( ADSX_MEDIA_DATE_TO,      '' );
    $post_date_from     = get_option( ADSX_POST_DATE_FROM,     '' );
    $post_date_to       = get_option( ADSX_POST_DATE_TO,       '' );
    $download_date_from = get_option( ADSX_DOWNLOAD_DATE_FROM, '' );
    $download_date_to   = get_option( ADSX_DOWNLOAD_DATE_TO,   '' );
    $today              = current_time( 'Y-m-d' );

    $terms     = get_terms( [ 'taxonomy' => 'subscription', 'hide_empty' => false ] );
    $has_terms = ! is_wp_error( $terms ) && ! empty( $terms );

    // Base URL without dates — JS appends them live.
    $media_zip_base_js = str_replace(
        '&amp;', '&',
        wp_nonce_url( admin_url( 'admin-post.php?action=adsx_download_media' ), 'adsx_download_media' )
    );
    // Static fallback href for the initial render (dates from saved options).
    $media_zip_url = esc_url( add_query_arg( [
        'date_from' => $media_date_from,
        'date_to'   => $media_date_to,
    ], str_replace( '&amp;', '&', wp_nonce_url( admin_url( 'admin-post.php?action=adsx_download_media' ), 'adsx_download_media' ) ) ) );
    // Base URL without term/dates — JS appends them live.
    $download_zip_base_js = str_replace(
        '&amp;', '&',
        wp_nonce_url( admin_url( 'admin-post.php?action=adsx_download_all' ), 'adsx_download_all' )
    );
    // Static fallback href for the initial render (term/dates/format from saved options).
    $download_zip_url = esc_url( add_query_arg( [
        'adsx_term'             => $selected_term,
        'date_from'             => $download_date_from,
        'date_to'               => $download_date_to,
        'adsx_filename_format'  => $selected_filename_format,
        'adsx_skip_duplicates'  => $skip_duplicates ? '1' : '0',
    ], str_replace( '&amp;', '&', wp_nonce_url( admin_url( 'admin-post.php?action=adsx_download_all' ), 'adsx_download_all' ) ) ) );

    $nonce_media    = wp_create_nonce( 'adsx_count_media' );
    $nonce_post     = wp_create_nonce( 'adsx_count_post' );
    $nonce_download = wp_create_nonce( 'adsx_count_download' );

    // Shared card styles
    $card  = 'background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:18px 20px;margin-bottom:16px;';
    $label = 'font-size:14px;font-weight:600;cursor:pointer;';
    $desc  = 'margin:.4em 0 0;color:#646970;font-size:12px;line-height:1.5;';
    $cnt   = 'display:inline-block;min-width:2em;font-weight:700;font-size:1.35em;vertical-align:middle;';
    ?>
    <div class="wrap" style="max-width:780px;">
        <h1>Sitemap Manager</h1>
        <p style="color:#3c434a;">Enable the sitemaps you need. Counters update live as you change settings — no need to save first.</p>

        <form method="post" action="options.php">
            <?php settings_fields( 'adsx_settings' ); ?>

            <?php // ──────────────────────────── MEDIA SITEMAP ──────────────────────────── ?>

            <div style="<?php echo esc_attr( $card ); ?>">

                <label style="<?php echo esc_attr( $label ); ?>">
                    <input type="hidden"   name="<?php echo esc_attr( ADSX_ENABLE_MEDIA ); ?>" value="0">
                    <input type="checkbox" name="<?php echo esc_attr( ADSX_ENABLE_MEDIA ); ?>" value="1"
                           id="adsx-enable-media" <?php checked( $enable_media ); ?>>
                    Media Sitemap
                </label>
                <code style="margin-left:8px;font-size:12px;"><?php echo esc_html( home_url( '/media-sitemap.xml' ) ); ?></code>

                <p style="<?php echo esc_attr( $desc ); ?>">
                    WordPress media library files (PDF, Word, PPT, Excel, CSV, JSON/XML) filtered by upload date.
                    Deduplicates by filename — PPTX beats PPT beats others; ties go to the newest file.
                </p>

                <div id="adsx-media-details" style="margin-top:16px;<?php echo $enable_media ? '' : 'display:none;'; ?>">

                    <table class="form-table" role="presentation" style="margin:0 0 12px;">
                        <tr>
                            <th style="width:150px;padding:6px 10px 6px 0;font-weight:600;">Date range</th>
                            <td style="padding:6px 0;">
                                <label>
                                    From&nbsp;
                                    <input type="date"
                                           name="<?php echo esc_attr( ADSX_MEDIA_DATE_FROM ); ?>"
                                           id="adsx-media-date-from"
                                           value="<?php echo esc_attr( $media_date_from ); ?>"
                                           placeholder="<?php echo esc_attr( ADSX_DEFAULT_DATE_FROM ); ?>"
                                           style="margin-right:12px;">
                                </label>
                                <label>
                                    To&nbsp;
                                    <input type="date"
                                           name="<?php echo esc_attr( ADSX_MEDIA_DATE_TO ); ?>"
                                           id="adsx-media-date-to"
                                           value="<?php echo esc_attr( $media_date_to ); ?>"
                                           max="<?php echo esc_attr( $today ); ?>">
                                </label>
                                <p style="margin:4px 0 0;color:#646970;font-size:12px;">
                                    Leave <em>From</em> empty to default to <?php echo esc_html( ADSX_DEFAULT_DATE_FROM ); ?>.
                                    Leave <em>To</em> empty to default to today.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p style="margin:0 0 10px;">
                        Items found:&nbsp;
                        <span id="adsx-counter-media" style="<?php echo esc_attr( $cnt ); ?>">…</span>
                        <span id="adsx-spinner-media" class="spinner" style="float:none;vertical-align:middle;visibility:hidden;"></span>
                    </p>
                    <a href="<?php echo esc_url( home_url( '/media-sitemap.xml' ) ); ?>"
                       target="_blank" class="button button-secondary">View Sitemap ↗</a>
                    <a href="<?php echo $media_zip_url; ?>"
                       id="adsx-media-zip-btn"
                       class="button button-secondary" style="margin-left:8px;">⬇ Download All Files</a>
                </div>
            </div>

            <?php // ──────────────────────────── POST SITEMAP ───────────────────────────── ?>

            <div style="<?php echo esc_attr( $card ); ?>">

                <label style="<?php echo esc_attr( $label ); ?>">
                    <input type="hidden"   name="<?php echo esc_attr( ADSX_ENABLE_POST ); ?>" value="0">
                    <input type="checkbox" name="<?php echo esc_attr( ADSX_ENABLE_POST ); ?>" value="1"
                           id="adsx-enable-post" <?php checked( $enable_post ); ?>>
                    Post Sitemap
                </label>
                <code style="margin-left:8px;font-size:12px;"><?php echo esc_html( home_url( '/post-sitemap.xml' ) ); ?></code>

                <p style="<?php echo esc_attr( $desc ); ?>">
                    Published posts filtered by modified date, sorted newest first.
                    Each URL is live 404-checked when the sitemap is served (may be slow on large sites).
                </p>

                <div id="adsx-post-details" style="margin-top:16px;<?php echo $enable_post ? '' : 'display:none;'; ?>">

                    <table class="form-table" role="presentation" style="margin:0 0 12px;">
                        <tr>
                            <th style="width:150px;padding:6px 10px 6px 0;font-weight:600;">Date range</th>
                            <td style="padding:6px 0;">
                                <label>
                                    From&nbsp;
                                    <input type="date"
                                           name="<?php echo esc_attr( ADSX_POST_DATE_FROM ); ?>"
                                           id="adsx-post-date-from"
                                           value="<?php echo esc_attr( $post_date_from ); ?>"
                                           placeholder="<?php echo esc_attr( ADSX_DEFAULT_DATE_FROM ); ?>"
                                           style="margin-right:12px;">
                                </label>
                                <label>
                                    To&nbsp;
                                    <input type="date"
                                           name="<?php echo esc_attr( ADSX_POST_DATE_TO ); ?>"
                                           id="adsx-post-date-to"
                                           value="<?php echo esc_attr( $post_date_to ); ?>"
                                           max="<?php echo esc_attr( $today ); ?>">
                                </label>
                                <p style="margin:4px 0 0;color:#646970;font-size:12px;">
                                    Leave <em>From</em> empty to default to <?php echo esc_html( ADSX_DEFAULT_DATE_FROM ); ?>.
                                    Leave <em>To</em> empty to default to today.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p style="margin:0 0 10px;">
                        Items found:&nbsp;
                        <span id="adsx-counter-post" style="<?php echo esc_attr( $cnt ); ?>">…</span>
                        <span id="adsx-spinner-post" class="spinner" style="float:none;vertical-align:middle;visibility:hidden;"></span>
                        <em style="font-size:12px;color:#646970;margin-left:6px;">approximate — 404 check skipped in counter</em>
                    </p>
                    <a href="<?php echo esc_url( home_url( '/post-sitemap.xml' ) ); ?>"
                       target="_blank" class="button button-secondary">View Sitemap ↗</a>
                </div>
            </div>

            <?php // ──────────────────────────── DOWNLOAD SITEMAP ───────────────────────── ?>

            <div style="<?php echo esc_attr( $card ); ?>">

                <label style="<?php echo esc_attr( $label ); ?>">
                    <input type="hidden"   name="<?php echo esc_attr( ADSX_ENABLE_DOWNLOAD ); ?>" value="0">
                    <input type="checkbox" name="<?php echo esc_attr( ADSX_ENABLE_DOWNLOAD ); ?>" value="1"
                           id="adsx-enable-download" <?php checked( $enable_download ); ?>>
                    Download Sitemap
                </label>
                <code style="margin-left:8px;font-size:12px;"><?php echo esc_html( home_url( '/download-sitemap.xml' ) ); ?></code>

                <p style="<?php echo esc_attr( $desc ); ?>">
                    Posts in a subscription taxonomy term. Checks, in order: the layout repeater — <code>preview_module</code>
                    or <code>slide_preview_module</code>, chosen by <code>post_layout_type</code> ("slide-layout" vs
                    anything else) — using the first row's <code>download</code> file; then the <code>download_link</code>
                    repeater's first row <code>download_url</code>; then <code>share_download_url</code> (only when
                    <code>download</code> is Yes). First valid URL found wins. "Posts" mode always uses the post permalink.
                </p>

                <div id="adsx-download-details" style="margin-top:16px;<?php echo $enable_download ? '' : 'display:none;'; ?>">

                    <table class="form-table" role="presentation" style="margin:0 0 12px;">
                        <tr>
                            <th style="width:150px;padding:6px 10px 6px 0;font-weight:600;">Subscription term</th>
                            <td style="padding:6px 0;">
                                <?php if ( $has_terms ) : ?>
                                    <select name="<?php echo esc_attr( ADSX_OPTION_TERM ); ?>"
                                            id="<?php echo esc_attr( ADSX_OPTION_TERM ); ?>">
                                        <?php foreach ( $terms as $term ) : ?>
                                            <option value="<?php echo esc_attr( $term->slug ); ?>"
                                                    <?php selected( $selected_term, $term->slug ); ?>>
                                                <?php echo esc_html( $term->name . ' (' . $term->count . ')' ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : ?>
                                    <em style="color:#646970;">No terms found in the "subscription" taxonomy.</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th style="padding:6px 10px 6px 0;font-weight:600;">URL type</th>
                            <td style="padding:6px 0;">
                                <label style="margin-right:16px;">
                                    <input type="radio"
                                           name="<?php echo esc_attr( ADSX_OPTION_TYPE ); ?>"
                                           value="attachments"
                                           <?php checked( $selected_type, 'attachments' ); ?>>
                                    <strong>Attachments</strong> — <code>preview_module</code> → <code>download_link</code> → <code>share_download_url</code>
                                </label>
                                <label>
                                    <input type="radio"
                                           name="<?php echo esc_attr( ADSX_OPTION_TYPE ); ?>"
                                           value="posts"
                                           <?php checked( $selected_type, 'posts' ); ?>>
                                    <strong>Posts</strong> — post permalink
                                </label>
                            </td>
                        </tr>
                        <tr id="adsx-filename-format-row" style="<?php echo $selected_type !== 'attachments' ? 'display:none;' : ''; ?>">
                            <th style="width:150px;padding:6px 10px 6px 0;font-weight:600;">ZIP filename</th>
                            <td style="padding:6px 0;">
                                <label style="margin-right:16px;">
                                    <input type="radio"
                                           name="<?php echo esc_attr( ADSX_OPTION_FILENAME_FORMAT ); ?>"
                                           value="custom"
                                           <?php checked( $selected_filename_format, 'custom' ); ?>>
                                    <strong>Custom</strong> — <code>Term：Article Title.ext</code>
                                </label>
                                <label>
                                    <input type="radio"
                                           name="<?php echo esc_attr( ADSX_OPTION_FILENAME_FORMAT ); ?>"
                                           value="original"
                                           <?php checked( $selected_filename_format, 'original' ); ?>>
                                    <strong>Original</strong> — the file's actual uploaded filename
                                </label>
                                <p style="margin:4px 0 0;color:#646970;font-size:12px;">
                                    Only affects the "Download All Files" ZIP — the XML sitemap always links to the real file URL either way.
                                </p>
                            </td>
                        </tr>
                        <tr id="adsx-skip-duplicates-row" style="<?php echo $selected_type !== 'attachments' ? 'display:none;' : ''; ?>">
                            <th style="width:150px;padding:6px 10px 6px 0;font-weight:600;">Duplicate filenames</th>
                            <td style="padding:6px 0;">
                                <label>
                                    <input type="hidden"   name="<?php echo esc_attr( ADSX_OPTION_SKIP_DUPLICATES ); ?>" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr( ADSX_OPTION_SKIP_DUPLICATES ); ?>" value="1"
                                           id="adsx-skip-duplicates" <?php checked( $skip_duplicates ); ?>>
                                    Skip duplicates instead of renaming them
                                </label>
                                <p style="margin:4px 0 0;color:#646970;font-size:12px;">
                                    When two files would end up with the same ZIP filename, the default is to append <code>-2</code>,
                                    <code>-3</code>, etc. so both are kept. Enable this to omit the later duplicate from the ZIP
                                    entirely instead — only the first file with that name is included.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th style="width:150px;padding:6px 10px 6px 0;font-weight:600;">Date range</th>
                            <td style="padding:6px 0;">
                                <label>
                                    From&nbsp;
                                    <input type="date"
                                           name="<?php echo esc_attr( ADSX_DOWNLOAD_DATE_FROM ); ?>"
                                           id="adsx-download-date-from"
                                           value="<?php echo esc_attr( $download_date_from ); ?>"
                                           placeholder="<?php echo esc_attr( ADSX_DEFAULT_DATE_FROM ); ?>"
                                           style="margin-right:12px;">
                                </label>
                                <label>
                                    To&nbsp;
                                    <input type="date"
                                           name="<?php echo esc_attr( ADSX_DOWNLOAD_DATE_TO ); ?>"
                                           id="adsx-download-date-to"
                                           value="<?php echo esc_attr( $download_date_to ); ?>"
                                           max="<?php echo esc_attr( $today ); ?>">
                                </label>
                                <p style="margin:4px 0 0;color:#646970;font-size:12px;">
                                    Filters by publish/creation date. Leave <em>From</em> empty to default to <?php echo esc_html( ADSX_DEFAULT_DATE_FROM ); ?>.
                                    Leave <em>To</em> empty to default to today.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p style="margin:0 0 10px;">
                        Items found:&nbsp;
                        <span id="adsx-counter-download" style="<?php echo esc_attr( $cnt ); ?>">…</span>
                        <span id="adsx-spinner-download" class="spinner" style="float:none;vertical-align:middle;visibility:hidden;"></span>
                        <span id="adsx-counter-download-note" style="font-size:12px;color:#646970;margin-left:6px;"></span>
                    </p>

                    <a href="<?php echo esc_url( home_url( '/download-sitemap.xml' ) ); ?>"
                       target="_blank" class="button button-secondary">View Sitemap ↗</a>
                    <a href="<?php echo esc_url( $download_zip_url ); ?>"
                       id="adsx-download-zip-btn"
                       class="button button-secondary"
                       style="margin-left:8px;<?php echo $selected_type !== 'attachments' ? 'display:none;' : ''; ?>">
                        ⬇ Download All Files
                    </a>

                </div>
            </div>

            <p class="submit">
                <?php submit_button( 'Save Changes', 'primary', 'submit', false ); ?>
            </p>

        </form>
    </div>

    <script>
    (function () {

        var ajaxUrl = <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

        // ── Section show/hide ──────────────────────────────────────────────────

        [ ['adsx-enable-media',    'adsx-media-details'],
          ['adsx-enable-post',     'adsx-post-details'],
          ['adsx-enable-download', 'adsx-download-details'] ].forEach( function ( pair ) {
            var cb     = document.getElementById( pair[0] );
            var detail = document.getElementById( pair[1] );
            if ( ! cb || ! detail ) return;
            cb.addEventListener( 'change', function () {
                detail.style.display = this.checked ? '' : 'none';
            } );
        } );

        // ── Generic AJAX counter ───────────────────────────────────────────────

        function fetchCount( action, nonce, params, counterId, spinnerId, onSuccess ) {
            var counter = document.getElementById( counterId );
            var spinner = document.getElementById( spinnerId );
            if ( ! counter ) return;

            counter.textContent = '…';
            if ( spinner ) spinner.style.visibility = 'visible';

            var url = ajaxUrl + '?action=' + encodeURIComponent( action )
                + '&nonce=' + encodeURIComponent( nonce );

            if ( params ) {
                Object.keys( params ).forEach( function ( k ) {
                    url += '&' + encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
                } );
            }

            var xhr = new XMLHttpRequest();
            xhr.open( 'GET', url, true );
            xhr.onload = function () {
                if ( spinner ) spinner.style.visibility = 'hidden';
                try {
                    var data = JSON.parse( xhr.responseText );
                    if ( data.success ) {
                        counter.textContent = data.data.count;
                        if ( onSuccess ) onSuccess( data.data );
                    } else {
                        counter.textContent = '—';
                    }
                } catch ( e ) {
                    counter.textContent = '—';
                }
            };
            xhr.onerror = function () {
                if ( spinner ) spinner.style.visibility = 'hidden';
                counter.textContent = '—';
            };
            xhr.send();
        }

        // ── Media counter ─────────────────────────────────────────────────────

        var mediaDateFrom  = document.getElementById( 'adsx-media-date-from' );
        var mediaDateTo    = document.getElementById( 'adsx-media-date-to' );
        var mediaZipBtn    = document.getElementById( 'adsx-media-zip-btn' );
        var mediaZipBase   = <?php echo json_encode( $media_zip_base_js ); ?>;
        var mediaTimer     = null;

        function updateMediaZipUrl() {
            if ( ! mediaZipBtn ) return;
            var from = mediaDateFrom ? mediaDateFrom.value : '';
            var to   = mediaDateTo   ? mediaDateTo.value   : '';
            var url  = mediaZipBase;
            if ( from ) url += '&date_from=' + encodeURIComponent( from );
            if ( to )   url += '&date_to='   + encodeURIComponent( to );
            mediaZipBtn.href = url;
        }

        function refreshMediaCount() {
            clearTimeout( mediaTimer );
            mediaTimer = setTimeout( function () {
                updateMediaZipUrl();
                fetchCount( 'adsx_count_media', <?php echo json_encode( $nonce_media ); ?>, {
                    date_from: mediaDateFrom ? mediaDateFrom.value : '',
                    date_to:   mediaDateTo   ? mediaDateTo.value   : '',
                }, 'adsx-counter-media', 'adsx-spinner-media' );
            }, 400 );
        }

        if ( mediaDateFrom ) mediaDateFrom.addEventListener( 'change', refreshMediaCount );
        if ( mediaDateTo )   mediaDateTo.addEventListener(   'change', refreshMediaCount );
        updateMediaZipUrl();
        refreshMediaCount();

        // ── Post counter ──────────────────────────────────────────────────────

        var postDateFrom = document.getElementById( 'adsx-post-date-from' );
        var postDateTo   = document.getElementById( 'adsx-post-date-to' );
        var postTimer    = null;

        function refreshPostCount() {
            clearTimeout( postTimer );
            postTimer = setTimeout( function () {
                fetchCount( 'adsx_count_post', <?php echo json_encode( $nonce_post ); ?>, {
                    date_from: postDateFrom ? postDateFrom.value : '',
                    date_to:   postDateTo   ? postDateTo.value   : '',
                }, 'adsx-counter-post', 'adsx-spinner-post' );
            }, 400 );
        }

        if ( postDateFrom ) postDateFrom.addEventListener( 'change', refreshPostCount );
        if ( postDateTo )   postDateTo.addEventListener(   'change', refreshPostCount );
        refreshPostCount();

        // ── Download counter (fires on load + on any control change) ──────────

        var termSelect      = document.getElementById( <?php echo json_encode( ADSX_OPTION_TERM ); ?> );
        var typeRadios      = document.querySelectorAll( 'input[name="<?php echo esc_js( ADSX_OPTION_TYPE ); ?>"]' );
        var filenameFormatRadios = document.querySelectorAll( 'input[name="<?php echo esc_js( ADSX_OPTION_FILENAME_FORMAT ); ?>"]' );
        var filenameFormatRow    = document.getElementById( 'adsx-filename-format-row' );
        var skipDuplicatesCheckbox = document.getElementById( 'adsx-skip-duplicates' );
        var skipDuplicatesRow      = document.getElementById( 'adsx-skip-duplicates-row' );
        var downloadDateFrom = document.getElementById( 'adsx-download-date-from' );
        var downloadDateTo   = document.getElementById( 'adsx-download-date-to' );
        var zipBtn     = document.getElementById( 'adsx-download-zip-btn' );
        var noteEl     = document.getElementById( 'adsx-counter-download-note' );
        var baseZipUrl = <?php echo json_encode( $download_zip_base_js ); ?>;
        var dlTimer    = null;

        function getSelectedType() {
            for ( var i = 0; i < typeRadios.length; i++ ) {
                if ( typeRadios[ i ].checked ) return typeRadios[ i ].value;
            }
            return 'attachments';
        }

        function getSelectedFilenameFormat() {
            for ( var i = 0; i < filenameFormatRadios.length; i++ ) {
                if ( filenameFormatRadios[ i ].checked ) return filenameFormatRadios[ i ].value;
            }
            return 'custom';
        }

        function updateZipUrl() {
            var term   = termSelect ? termSelect.value : '';
            var from   = downloadDateFrom ? downloadDateFrom.value : '';
            var to     = downloadDateTo   ? downloadDateTo.value   : '';
            var format = getSelectedFilenameFormat();
            var skip   = skipDuplicatesCheckbox && skipDuplicatesCheckbox.checked ? '1' : '0';
            var url    = baseZipUrl + '&adsx_term=' + encodeURIComponent( term );
            if ( from ) url += '&date_from=' + encodeURIComponent( from );
            if ( to )   url += '&date_to='   + encodeURIComponent( to );
            url += '&adsx_filename_format=' + encodeURIComponent( format );
            url += '&adsx_skip_duplicates=' + encodeURIComponent( skip );
            if ( zipBtn ) zipBtn.href = url;
        }

        function refreshDownloadCount() {
            clearTimeout( dlTimer );
            dlTimer = setTimeout( function () {
                var term = termSelect ? termSelect.value : '';
                var type = getSelectedType();
                updateZipUrl();
                if ( zipBtn ) zipBtn.style.display = type === 'attachments' ? '' : 'none';
                if ( filenameFormatRow )   filenameFormatRow.style.display   = type === 'attachments' ? '' : 'none';
                if ( skipDuplicatesRow )   skipDuplicatesRow.style.display   = type === 'attachments' ? '' : 'none';

                fetchCount(
                    'adsx_count_download',
                    <?php echo json_encode( $nonce_download ); ?>,
                    {
                        adsx_term: term,
                        adsx_type: type,
                        date_from: downloadDateFrom ? downloadDateFrom.value : '',
                        date_to:   downloadDateTo   ? downloadDateTo.value   : '',
                    },
                    'adsx-counter-download',
                    'adsx-spinner-download',
                    function () {
                        if ( noteEl ) {
                            noteEl.textContent = type === 'attachments'
                                ? 'posts with a resolvable download_link or download file'
                                : 'published posts in this term';
                        }
                    }
                );
            }, 300 );
        }

        if ( termSelect ) termSelect.addEventListener( 'change', refreshDownloadCount );
        typeRadios.forEach( function ( r ) { r.addEventListener( 'change', refreshDownloadCount ); } );
        filenameFormatRadios.forEach( function ( r ) { r.addEventListener( 'change', updateZipUrl ); } );
        if ( skipDuplicatesCheckbox ) skipDuplicatesCheckbox.addEventListener( 'change', updateZipUrl );
        if ( downloadDateFrom ) downloadDateFrom.addEventListener( 'change', refreshDownloadCount );
        if ( downloadDateTo )   downloadDateTo.addEventListener(   'change', refreshDownloadCount );

        updateZipUrl();
        refreshDownloadCount();

    }());
    </script>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// 6. ADMIN POST: DOWNLOAD MEDIA ZIP
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_post_adsx_download_media', function () {

    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.', 403 );
    check_admin_referer( 'adsx_download_media' );

    if ( ! class_exists( 'ZipArchive' ) ) wp_die( 'ZipArchive is not available on this server.' );

    // Use live date params from the button URL; fall back to saved options.
    $date_from = isset( $_GET['date_from'] ) && $_GET['date_from'] !== ''
        ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : null;
    $date_to   = isset( $_GET['date_to'] )   && $_GET['date_to']   !== ''
        ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : null;

    $items = adsx_get_media_attachments( $date_from, $date_to );
    if ( empty( $items ) ) wp_die( 'No media files found.' );

    $upload_dir = wp_upload_dir();
    if ( empty( $upload_dir['basedir'] ) ) wp_die( 'Could not access uploads directory.' );

    $zip_path = trailingslashit( $upload_dir['basedir'] ) . 'adsx-media-' . date( 'Y-m-d-His' ) . '.zip';
    $zip      = new ZipArchive();

    if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) wp_die( 'Could not create ZIP file.' );

    @set_time_limit( 300 );
    $added = [];

    foreach ( $items as $item ) {
        $file_path = get_attached_file( $item['attachment']->ID );
        if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            error_log( sprintf(
                'ADSX: media-sitemap zip skipped attachment %d (%s) — file missing or unreadable at %s',
                $item['attachment']->ID,
                $item['filename'],
                $file_path ?: '(no path)'
            ) );
            continue;
        }

        $name = basename( $file_path );
        if ( isset( $added[ strtolower( $name ) ] ) ) {
            $info = pathinfo( $name );
            $name = $info['filename'] . '-' . $item['attachment']->ID
                . ( isset( $info['extension'] ) ? '.' . $info['extension'] : '' );
        }
        $added[ strtolower( $name ) ] = true;
        $zip->addFile( $file_path, $name );
    }

    $zip->close();

    if ( ! file_exists( $zip_path ) || filesize( $zip_path ) === 0 ) {
        @unlink( $zip_path );
        wp_die( 'ZIP archive is empty — no readable local files found.' );
    }

    while ( ob_get_level() ) ob_end_clean();

    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="media-sitemap-files-' . date( 'Y-m-d' ) . '.zip"' );
    header( 'Content-Length: ' . filesize( $zip_path ) );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    readfile( $zip_path );
    @unlink( $zip_path );
    exit;
} );

// ═══════════════════════════════════════════════════════════════════════════════
// 7. ADMIN POST: DOWNLOAD ALL (DOWNLOAD SITEMAP ZIP)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_post_adsx_download_all', function () {

    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.', 403 );
    check_admin_referer( 'adsx_download_all' );

    if ( ! class_exists( 'ZipArchive' ) ) wp_die( 'ZipArchive is not available on this server.' );

    $term_slug = isset( $_GET['adsx_term'] ) && $_GET['adsx_term'] !== ''
        ? sanitize_title( wp_unslash( $_GET['adsx_term'] ) )
        : get_option( ADSX_OPTION_TERM, ADSX_DEFAULT_TERM );

    $date_from = isset( $_GET['date_from'] ) && $_GET['date_from'] !== ''
        ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : null;
    $date_to   = isset( $_GET['date_to'] )   && $_GET['date_to']   !== ''
        ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : null;

    $filename_format = isset( $_GET['adsx_filename_format'] ) && in_array( $_GET['adsx_filename_format'], [ 'custom', 'original' ], true )
        ? sanitize_key( $_GET['adsx_filename_format'] )
        : get_option( ADSX_OPTION_FILENAME_FORMAT, ADSX_DEFAULT_FILENAME_FORMAT );

    $skip_duplicates = isset( $_GET['adsx_skip_duplicates'] )
        ? $_GET['adsx_skip_duplicates'] === '1'
        : get_option( ADSX_OPTION_SKIP_DUPLICATES ) === '1';

    $entries = adsx_get_download_entries( $term_slug, 'attachments', $date_from, $date_to );
    if ( empty( $entries ) ) wp_die( 'No downloadable files found.' );

    $zip_path = tempnam( sys_get_temp_dir(), 'adsx_' ) . '.zip';
    $zip      = new ZipArchive();

    if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) wp_die( 'Could not create ZIP archive.' );

    @set_time_limit( 300 );
    $used = [];

    foreach ( $entries as $entry ) {

        $filename   = null;
        $local_path = null; // set when we can stream from disk instead of loading into memory
        $body       = null; // only used for remote-fetched content

        // Prefer streaming straight off local disk whenever the URL maps to
        // a file under the uploads directory — avoids both a self-referential
        // HTTP round trip (which can silently fail or time out en masse
        // across hundreds of files behind a WAF/CDN/loopback-blocking host)
        // and, just as importantly, avoids loading the whole file into PHP
        // memory: ZipArchive::addFile() streams the file straight from disk,
        // while addFromString() requires the entire file's bytes resident in
        // memory at once, which exhausts the PHP memory limit once enough
        // large files (e.g. multi-MB PPTX/video-embedded decks) accumulate
        // across a single export. This is a direct path check, not a DB
        // lookup, so it also catches files that attachment_url_to_postid()
        // would miss (scaled/replaced media, query strings, mismatched GUIDs).
        $candidate_path = adsx_url_to_local_path( $entry['url'] );
        if ( $candidate_path ) {
            $local_path = $candidate_path;
            $filename   = basename( $candidate_path );
        }

        // Fall back to a remote fetch for external / non-local URLs, or if
        // the local file couldn't be resolved for some reason.
        if ( $local_path === null ) {
            $response = wp_remote_get( $entry['url'], [
                'timeout'   => 60,
                'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            ] );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                error_log( sprintf(
                    'ADSX: download-sitemap zip skipped post %d — fetch failed for %s (%s)',
                    $entry['post_id'],
                    $entry['url'],
                    is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response )
                ) );
                continue;
            }

            $body = wp_remote_retrieve_body( $response );
            if ( empty( $body ) ) {
                error_log( sprintf(
                    'ADSX: download-sitemap zip skipped post %d — empty response body for %s',
                    $entry['post_id'],
                    $entry['url']
                ) );
                continue;
            }

            $filename = sanitize_file_name( basename( urldecode( parse_url( $entry['url'], PHP_URL_PATH ) ) ) ) ?: 'file';
        }

        // Rename to "<filter-types term>：<article title>.<ext>" instead of
        // the raw uploaded filename — unless "original" filenames were
        // requested, in which case $filename is left as the actual
        // uploaded/remote filename already set above.
        if ( $filename_format === 'custom' ) {
            $filename = adsx_build_download_filename( $entry['post_id'], pathinfo( $filename, PATHINFO_EXTENSION ) );
        }

        if ( isset( $used[ $filename ] ) ) {
            if ( $skip_duplicates ) {
                error_log( sprintf(
                    'ADSX: download-sitemap zip skipped post %d — "%s" is a duplicate filename (skip-duplicates enabled)',
                    $entry['post_id'],
                    $filename
                ) );
                continue;
            }
            $used[ $filename ]++;
            $info     = pathinfo( $filename );
            $filename = $info['filename'] . '-' . $used[ $filename ]
                . ( isset( $info['extension'] ) ? '.' . $info['extension'] : '' );
        } else {
            $used[ $filename ] = 1;
        }

        if ( $local_path !== null ) {
            $zip->addFile( $local_path, $filename );
        } else {
            $zip->addFromString( $filename, $body );
        }
    }

    $zip->close();

    if ( ! file_exists( $zip_path ) || filesize( $zip_path ) === 0 ) {
        @unlink( $zip_path );
        wp_die( 'ZIP archive is empty.' );
    }

    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="downloads-' . sanitize_title( $term_slug ) . '-' . date( 'Y-m-d' ) . '.zip"' );
    header( 'Content-Length: ' . filesize( $zip_path ) );
    header( 'Cache-Control: no-cache, must-revalidate' );
    header( 'Pragma: no-cache' );

    readfile( $zip_path );
    @unlink( $zip_path );
    exit;
} );

// ═══════════════════════════════════════════════════════════════════════════════
// 8. FRONTEND: SERVE XML SITEMAPS
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'init', function () {

    $request_base = basename( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );

    if ( $request_base === 'media-sitemap.xml'    ) $_GET['adsx_serve'] = 'media';
    if ( $request_base === 'post-sitemap.xml'     ) $_GET['adsx_serve'] = 'post';
    if ( $request_base === 'download-sitemap.xml' ) $_GET['adsx_serve'] = 'download';

    if ( ! isset( $_GET['adsx_serve'] ) ) return;

    $type = $_GET['adsx_serve'];

    // ── Media sitemap ──────────────────────────────────────────────────────────

    if ( $type === 'media' ) {

        if ( get_option( ADSX_ENABLE_MEDIA ) !== '1' ) { status_header( 404 ); exit; }

        $items = adsx_get_media_attachments();

        status_header( 200 );
        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, follow', true );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ( $items as $item ) {
            echo "  <url>\n";
            echo '    <loc>' . esc_url( $item['url'] ) . "</loc>\n";
            echo '    <lastmod>' . esc_html( get_the_modified_date( 'c', $item['attachment']->ID ) ) . "</lastmod>\n";
            echo "  </url>\n";
        }

        echo '</urlset>';
        exit;
    }

    // ── Post sitemap ───────────────────────────────────────────────────────────

    if ( $type === 'post' ) {

        if ( get_option( ADSX_ENABLE_POST ) !== '1' ) { status_header( 404 ); exit; }

        $post_ids = adsx_get_post_ids( true );

        status_header( 200 );
        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, follow', true );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ( $post_ids as $post_id ) {
            echo "  <url>\n";
            echo '    <loc>' . esc_url( get_permalink( $post_id ) ) . "</loc>\n";
            echo '    <lastmod>' . esc_html( get_post_modified_time( 'c', true, $post_id ) ) . "</lastmod>\n";
            echo "  </url>\n";
        }

        echo '</urlset>';
        exit;
    }

    // ── Download sitemap ───────────────────────────────────────────────────────

    if ( $type === 'download' ) {

        if ( get_option( ADSX_ENABLE_DOWNLOAD ) !== '1' ) { status_header( 404 ); exit; }

        $entries = adsx_get_download_entries();

        status_header( 200 );
        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, follow', true );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ( $entries as $entry ) {
            echo "  <url>\n";
            echo '    <loc>' . esc_url( $entry['url'] ) . "</loc>\n";
            echo '    <lastmod>' . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
            echo "  </url>\n";
        }

        echo '</urlset>';
        exit;
    }
} );
