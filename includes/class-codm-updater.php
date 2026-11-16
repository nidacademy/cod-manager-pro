<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub releases.
 *
 * The plugin header says "Update URI: https://github.com/nidacademy/cod-manager-pro",
 * so WordPress asks this class (filter update_plugins_github.com) instead of
 * WordPress.org. A release is a tag like v1.1.0 with cod-manager-pro.zip attached;
 * it then shows under Dashboard > Updates like any other plugin update.
 * The "View details" window is CODM_Plugin_Info.
 */
class CODM_Updater {

	const REPO     = 'NIDACADEMY/cod-manager-pro';
	const CACHE    = 'codm_github_release';
	const BASENAME = 'cod-manager-pro/cod-manager-pro.php';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
	}

	/**
	 * The latest release: version, zip and page, or null. Asked at most every 12 hours.
	 *
	 * @return array|null
	 */
	public static function latest() {
		$cached = get_site_transient( self::CACHE );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}
		$out = null;
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'COD Manager Pro updater',
				),
			)
		);
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$json    = json_decode( wp_remote_retrieve_body( $res ), true );
			$version = ltrim( (string) ( isset( $json['tag_name'] ) ? $json['tag_name'] : '' ), 'vV' );
			$package = '';
			foreach ( (array) ( isset( $json['assets'] ) ? $json['assets'] : array() ) as $asset ) {
				if ( preg_match( '/\.zip$/i', (string) ( isset( $asset['name'] ) ? $asset['name'] : '' ) ) ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
			if ( preg_match( '/^\d+(\.\d+)*$/', $version ) && 0 === strpos( $package, 'https://github.com/' ) ) {
				$out = array(
					'version' => $version,
					'package' => $package,
					'url'     => isset( $json['html_url'] ) ? (string) $json['html_url'] : 'https://github.com/' . self::REPO,
				);
			}
		}
		set_site_transient( self::CACHE, $out ? $out : 0, $out ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Update data for WordPress (false = nothing to say).
	 *
	 * @param array|false $update      Earlier answer.
	 * @param array       $plugin_data Plugin header.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::BASENAME !== $plugin_file ) {
			return $update;
		}
		$latest = self::latest();
		if ( ! $latest ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => 'cod-manager-pro',
			'plugin'       => self::BASENAME,
			'version'      => $latest['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $latest['package'],
			'requires'     => '6.2',
			'requires_php' => '7.4',
		);
	}

	/**
	 * After an update, ask GitHub again next time.
	 */
	public static function forget() {
		delete_site_transient( self::CACHE );
	}
}
