<?php
/**
 * Self-update from GitHub releases.
 *
 * Checks the repository's latest GitHub release, shows the update in the
 * WordPress Plugins screen, installs it on demand, and (optionally) applies
 * it automatically in the background.
 *
 * Configuration (any one of):
 *   - Plugin header `Update URI: https://github.com/OWNER/REPO`
 *   - define( 'OC_BUNDLES_GITHUB_REPO', 'OWNER/REPO' );  // overrides the header
 * Optional:
 *   - define( 'OC_BUNDLES_GITHUB_TOKEN', '...' );  // private repos / higher rate limit
 *   - define( 'OC_BUNDLES_AUTO_UPDATE', false );   // disable automatic background updates
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Updater {

	/** @var string Plugin basename, e.g. oc-bundles/oc-bundles.php */
	protected $file;

	/** @var string Plugin folder slug, e.g. oc-bundles */
	protected $slug;

	/** @var string Current installed version */
	protected $version;

	/** @var string GitHub repo "owner/name" */
	protected $repo;

	/** @var string Transient cache key */
	protected $cache_key;

	/** @var int Cache lifetime for the GitHub response */
	protected $cache_ttl;

	/**
	 * Wire up the updater if a repository is configured.
	 */
	public static function init() {
		$repo = self::resolve_repo();
		if ( '' === $repo ) {
			return;
		}
		new self( $repo );
	}

	/**
	 * Resolve the GitHub repo from a constant or the Update URI header.
	 *
	 * @return string "owner/name" or '' when unset/placeholder.
	 */
	protected static function resolve_repo() {
		$repo = '';
		if ( defined( 'OC_BUNDLES_GITHUB_REPO' ) && OC_BUNDLES_GITHUB_REPO ) {
			$repo = OC_BUNDLES_GITHUB_REPO;
		} else {
			$data = get_file_data( OC_BUNDLES_FILE, array( 'uri' => 'Update URI' ) );
			if ( ! empty( $data['uri'] ) && preg_match( '~github\.com/([^/]+/[^/#?]+)~i', $data['uri'], $m ) ) {
				$repo = $m[1];
			}
		}
		$repo = trim( (string) $repo, '/' );
		if ( '' === $repo || false !== stripos( $repo, 'OWNER/REPO' ) ) {
			return '';
		}
		return $repo;
	}

	/**
	 * @param string $repo "owner/name".
	 */
	public function __construct( $repo ) {
		$this->repo      = $repo;
		$this->file      = plugin_basename( OC_BUNDLES_FILE );
		$this->slug      = dirname( $this->file );
		$this->version   = OC_BUNDLES_VERSION;
		$this->cache_key = 'oc_bundles_gh_' . md5( $repo );
		$this->cache_ttl = 6 * HOUR_IN_SECONDS;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ) );

		if ( ! defined( 'OC_BUNDLES_AUTO_UPDATE' ) || OC_BUNDLES_AUTO_UPDATE ) {
			add_filter( 'auto_update_plugin', array( $this, 'auto_update' ), 10, 2 );
		}
	}

	/**
	 * Fetch (cached) the latest release payload from the GitHub API.
	 *
	 * @return array
	 */
	protected function api() {
		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$url  = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'oc-bundles-updater',
			),
		);
		if ( defined( 'OC_BUNDLES_GITHUB_TOKEN' ) && OC_BUNDLES_GITHUB_TOKEN ) {
			$args['headers']['Authorization'] = 'Bearer ' . OC_BUNDLES_GITHUB_TOKEN;
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Short negative cache so a hiccup doesn't hammer the API.
			set_transient( $this->cache_key, array(), 30 * MINUTE_IN_SECONDS );
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		set_transient( $this->cache_key, $data, $this->cache_ttl );
		return $data;
	}

	/**
	 * Normalize the latest release into version + downloadable package.
	 *
	 * @return array|null { version, package, data } or null.
	 */
	protected function latest() {
		$data = $this->api();
		if ( empty( $data['tag_name'] ) ) {
			return null;
		}

		$version = ltrim( $data['tag_name'], 'vV' );

		// Prefer an attached .zip asset; fall back to the source zipball.
		$package = '';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}
		if ( '' === $package && ! empty( $data['zipball_url'] ) ) {
			$package = $data['zipball_url'];
		}

		return array(
			'version' => $version,
			'package' => $package,
			'data'    => $data,
		);
	}

	/**
	 * Inject an available update into the plugins update transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$latest = $this->latest();
		if ( ! $latest || '' === $latest['package'] ) {
			return $transient;
		}

		$home = 'https://github.com/' . $this->repo;

		if ( version_compare( $latest['version'], $this->version, '>' ) ) {
			$transient->response[ $this->file ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->file,
				'new_version' => $latest['version'],
				'url'         => $home,
				'package'     => $latest['package'],
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => '',
				'requires'    => '',
			);
		} else {
			// Listing it under no_update keeps the auto-update toggle working.
			$transient->no_update[ $this->file ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->file,
				'new_version' => $this->version,
				'url'         => $home,
				'package'     => '',
				'icons'       => array(),
			);
		}

		return $transient;
	}

	/**
	 * Provide data for the "View details" popup.
	 *
	 * @param false|object|array $result Default result.
	 * @param string             $action API action.
	 * @param object             $args   Request args.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$latest = $this->latest();
		if ( ! $latest ) {
			return $result;
		}

		$data      = $latest['data'];
		$changelog = isset( $data['body'] ) ? wp_kses_post( $data['body'] ) : '';

		return (object) array(
			'name'          => 'Original Concepts Bundles',
			'slug'          => $this->slug,
			'version'       => $latest['version'],
			'author'        => '<a href="https://originalconcepts.co.il/">Original Concepts</a>',
			'homepage'      => 'https://github.com/' . $this->repo,
			'download_link' => $latest['package'],
			'trunk'         => $latest['package'],
			'requires'      => '',
			'tested'        => '',
			'last_updated'  => isset( $data['published_at'] ) ? $data['published_at'] : '',
			'sections'      => array(
				'description' => __( 'WooCommerce product bundles by Original Concepts.', 'oc-bundles' ),
				'changelog'   => $changelog ? wpautop( $changelog ) : '',
			),
		);
	}

	/**
	 * Rename the unpacked GitHub folder (e.g. owner-repo-tag/) to the plugin slug.
	 *
	 * @param string $source        Unpacked source dir.
	 * @param string $remote_source Remote source dir.
	 * @param object $upgrader      Upgrader instance.
	 * @param array  $hook_extra    Extra info (contains 'plugin').
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->file ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $this->slug;
		$desired = trailingslashit( $desired );

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
			return $desired;
		}

		return $source;
	}

	/**
	 * Enable automatic background updates for this plugin.
	 *
	 * @param bool|null $update Whether to auto-update.
	 * @param object    $item   The update item.
	 * @return bool|null
	 */
	public function auto_update( $update, $item ) {
		if ( is_object( $item ) && ! empty( $item->plugin ) && $item->plugin === $this->file ) {
			return true;
		}
		return $update;
	}

	/**
	 * Clear the cached GitHub response (after an update completes).
	 */
	public function clear_cache() {
		delete_transient( $this->cache_key );
	}
}
