<?php
/**
 * GitHub release updater for Adapt Sitemap Manager.
 *
 * Lets WordPress treat a public GitHub repository as the plugin's update
 * source. When a newer release exists on GitHub, the standard "update
 * available" notice appears under Plugins and Dashboard > Updates, and the
 * one-click update (or auto-update, if enabled) installs it.
 *
 * Where versions come from:
 * 1. The latest published GitHub release (created automatically by the
 *    Release workflow when a version tag such as v1.2.1 is pushed). If the
 *    release has a .zip asset, that asset is installed; otherwise the
 *    release's source archive is used.
 * 2. Only if the repository has no releases at all, the highest semver-style
 *    tag is used instead, installed from its source archive.
 *
 * GitHub API responses are cached for 6 hours to stay well within the
 * unauthenticated rate limit (60 requests per hour per server IP). Use the
 * "Check for updates" link on the Plugins screen to bypass the cache.
 *
 * Optional: define ADSX_GITHUB_TOKEN in wp-config.php to authenticate API
 * requests (only needed if a host shares its IP with many other sites and
 * hits the rate limit). Not required for a public repository.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'ADSX_GitHub_Updater' ) ) :

class ADSX_GitHub_Updater {

    /** @var string Absolute path to the main plugin file. */
    private $file;

    /** @var string Plugin basename, e.g. "adapt-sitemap-manager/adapt-sitemap-manager.php". */
    private $basename;

    /** @var string Plugin folder name, used as the slug and the install directory. */
    private $slug;

    /** @var string GitHub "owner/repo". */
    private $repo;

    /** @var string Site transient key for the cached release lookup. */
    private $cache_key;

    public function __construct( $file, $repo ) {

        $this->file      = $file;
        $this->basename  = plugin_basename( $file );
        $dir             = dirname( $this->basename );
        $this->slug      = ( $dir && $dir !== '.' ) ? $dir : 'adapt-sitemap-manager';
        $this->repo      = trim( $repo, '/' );
        $this->cache_key = 'adsx_gh_update_' . md5( $this->repo );

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugins_api' ], 20, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
        add_action( 'upgrader_process_complete', [ $this, 'purge_cache' ], 10, 2 );
        add_filter( 'plugin_action_links_' . $this->basename, [ $this, 'action_links' ] );
        add_action( 'admin_post_adsx_check_updates', [ $this, 'handle_check_now' ] );
    }

    // ─── Version lookup ──────────────────────────────────────────────────────

    /** Installed version, read from the plugin header. */
    public function get_installed_version() {
        $data = get_file_data( $this->file, [ 'Version' => 'Version' ] );
        return isset( $data['Version'] ) ? trim( $data['Version'] ) : '0';
    }

    /**
     * Latest available version on GitHub, or null.
     *
     * @return array|null { version, tag, package, changelog, published, url }
     */
    public function get_latest( $force = false ) {

        if ( ! $force ) {
            $cached = get_site_transient( $this->cache_key );
            if ( is_array( $cached ) ) {
                return empty( $cached ) ? null : $cached;
            }
        }

        $latest   = null;
        $api_ok   = false;
        $response = $this->api_get( "/repos/{$this->repo}/releases/latest" );

        if ( $response['code'] === 200 && is_array( $response['body'] ) ) {
            $api_ok  = true;
            $release = $response['body'];
            $version = $this->tag_to_version( $release['tag_name'] ?? '' );

            if ( $version !== null ) {
                $package = $release['zipball_url'] ?? '';
                foreach ( (array) ( $release['assets'] ?? [] ) as $asset ) {
                    if ( isset( $asset['name'], $asset['browser_download_url'] ) && substr( strtolower( $asset['name'] ), -4 ) === '.zip' ) {
                        $package = $asset['browser_download_url'];
                        break;
                    }
                }

                $latest = [
                    'version'   => $version,
                    'tag'       => $release['tag_name'],
                    'package'   => $package,
                    'changelog' => (string) ( $release['body'] ?? '' ),
                    'published' => (string) ( $release['published_at'] ?? '' ),
                    'url'       => (string) ( $release['html_url'] ?? "https://github.com/{$this->repo}/releases" ),
                ];
            }
        } elseif ( $response['code'] === 404 ) {
            // No releases yet: fall back to the highest version tag.
            $api_ok = true;
            $tags   = $this->api_get( "/repos/{$this->repo}/tags?per_page=100" );

            if ( $tags['code'] === 200 && is_array( $tags['body'] ) ) {
                foreach ( $tags['body'] as $tag ) {
                    $version = $this->tag_to_version( $tag['name'] ?? '' );
                    if ( $version === null ) continue;
                    if ( $latest === null || version_compare( $version, $latest['version'], '>' ) ) {
                        $latest = [
                            'version'   => $version,
                            'tag'       => $tag['name'],
                            'package'   => "https://github.com/{$this->repo}/archive/refs/tags/" . rawurlencode( $tag['name'] ) . '.zip',
                            'changelog' => '',
                            'published' => '',
                            'url'       => "https://github.com/{$this->repo}/tree/" . rawurlencode( $tag['name'] ),
                        ];
                    }
                }
            } else {
                $api_ok = false;
            }
        }

        // Cache a successful lookup for 6 hours. On API failure, cache the
        // empty result for 30 minutes so a GitHub outage or rate limit does
        // not trigger a request on every admin page load.
        set_site_transient( $this->cache_key, $latest ?: [], $api_ok ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );

        return $latest;
    }

    /** "v1.2.0" or "1.2.0" → "1.2.0"; anything that is not a version → null. */
    private function tag_to_version( $tag ) {
        $tag = trim( (string) $tag );
        if ( ! preg_match( '/^v?(\d+(?:\.\d+){0,3}(?:-[0-9A-Za-z.\-]+)?)$/', $tag, $m ) ) return null;
        return $m[1];
    }

    /** GET a GitHub API path. Returns [ 'code' => int, 'body' => mixed ]. */
    private function api_get( $path ) {

        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ) . '; adapt-sitemap-manager',
        ];

        $token = defined( 'ADSX_GITHUB_TOKEN' ) ? ADSX_GITHUB_TOKEN : '';
        $token = apply_filters( 'adsx_github_token', $token );
        if ( $token ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get( 'https://api.github.com' . $path, [
            'timeout' => 10,
            'headers' => $headers,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'ADSX: GitHub update check failed: ' . $response->get_error_message() );
            return [ 'code' => 0, 'body' => null ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 && $code !== 404 ) {
            error_log( sprintf( 'ADSX: GitHub update check returned HTTP %d for %s', $code, $path ) );
        }

        return [
            'code' => $code,
            'body' => json_decode( wp_remote_retrieve_body( $response ), true ),
        ];
    }

    // ─── WordPress hooks ─────────────────────────────────────────────────────

    /** Adds this plugin to the update_plugins transient when a newer version exists. */
    public function inject_update( $transient ) {

        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        $latest  = $this->get_latest();
        $current = $this->get_installed_version();

        $item = (object) [
            'id'            => "github.com/{$this->repo}",
            'slug'          => $this->slug,
            'plugin'        => $this->basename,
            'new_version'   => $current,
            'url'           => "https://github.com/{$this->repo}",
            'package'       => '',
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'requires_php'  => '7.4',
        ];

        if ( $latest && version_compare( $latest['version'], $current, '>' ) ) {
            $item->new_version = $latest['version'];
            $item->package     = $latest['package'];

            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) $transient->response = [];
            $transient->response[ $this->basename ] = $item;
            if ( isset( $transient->no_update[ $this->basename ] ) ) unset( $transient->no_update[ $this->basename ] );
        } else {
            // Listing the plugin under no_update enables the auto-update toggle.
            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) $transient->no_update = [];
            $transient->no_update[ $this->basename ] = $item;
            if ( isset( $transient->response[ $this->basename ] ) ) unset( $transient->response[ $this->basename ] );
        }

        return $transient;
    }

    /** Supplies the "View details" modal content on the Plugins screen. */
    public function plugins_api( $result, $action, $args ) {

        if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $latest = $this->get_latest();
        $data   = get_plugin_data( $this->file, false, false );

        $changelog = $latest && $latest['changelog'] !== ''
            ? wpautop( esc_html( $latest['changelog'] ) )
            : '<p>See the <a href="' . esc_url( "https://github.com/{$this->repo}/releases" ) . '" target="_blank" rel="noopener">release history on GitHub</a>.</p>';

        return (object) [
            'name'          => $data['Name'] ?: 'Adapt Sitemap Manager',
            'slug'          => $this->slug,
            'version'       => $latest ? $latest['version'] : $this->get_installed_version(),
            'author'        => $data['Author'] ?? 'Adapt',
            'homepage'      => "https://github.com/{$this->repo}",
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'last_updated'  => $latest ? $latest['published'] : '',
            'download_link' => $latest ? $latest['package'] : '',
            'sections'      => [
                'description' => '<p>' . esc_html( $data['Description'] ?? '' ) . '</p>',
                'changelog'   => $changelog,
            ],
        ];
    }

    /**
     * GitHub source archives extract to a folder such as
     * "adapt_sitemap_manager-1.2.0". Rename it to the installed plugin's
     * folder so WordPress replaces the plugin in place instead of
     * installing a second copy.
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {

        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) return $source;

        $desired = trailingslashit( $remote_source ) . $this->slug;

        if ( untrailingslashit( $source ) === $desired ) {
            return $source;
        }

        if ( $wp_filesystem->exists( $desired ) ) {
            $wp_filesystem->delete( $desired, true );
        }

        if ( ! $wp_filesystem->move( untrailingslashit( $source ), $desired, true ) ) {
            return new WP_Error( 'adsx_update_rename_failed', 'Adapt Sitemap Manager: could not prepare the update folder.' );
        }

        return trailingslashit( $desired );
    }

    /** Clears the cached lookup after this plugin is updated. */
    public function purge_cache( $upgrader, $options ) {
        if ( ( $options['type'] ?? '' ) !== 'plugin' ) return;
        $plugins = (array) ( $options['plugins'] ?? [] );
        if ( isset( $options['plugin'] ) ) $plugins[] = $options['plugin'];
        if ( in_array( $this->basename, $plugins, true ) ) {
            delete_site_transient( $this->cache_key );
        }
    }

    /** Adds a "Check for updates" link to the plugin's row on the Plugins screen. */
    public function action_links( $links ) {
        if ( ! current_user_can( 'update_plugins' ) ) return $links;

        $url = wp_nonce_url( admin_url( 'admin-post.php?action=adsx_check_updates' ), 'adsx_check_updates' );
        $links[] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';

        $settings = admin_url( 'options-general.php?page=adsx-sitemap-manager' );
        array_unshift( $links, '<a href="' . esc_url( $settings ) . '">Settings</a>' );

        return $links;
    }

    /** Handles the "Check for updates" link: bypass the cache and refresh. */
    public function handle_check_now() {

        if ( ! current_user_can( 'update_plugins' ) ) wp_die( 'Unauthorized.', 403 );
        check_admin_referer( 'adsx_check_updates' );

        delete_site_transient( $this->cache_key );
        $this->get_latest( true );

        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        wp_safe_redirect( admin_url( 'plugins.php?plugin_status=all' ) );
        exit;
    }
}

endif;
