<?php
/**
 * Represents a generic WordPress plugin.
 *
 * @package Two_Factor
 */

/**
 * Class Two_Factor_Plugin
 */
class Two_Factor_Plugin {

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	protected $file;

	/**
	 * Create a plugin object.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	public function __construct( $file ) {
		$this->file = $file;
	}

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @return string
	 */
	public function file() {
		return $file;
	}

	/**
	 * Absolute path to the plugin directory.
	 *
	 * @return string
	 */
	public function dir() {
		return dirname( $this->file );
	}

	/**
	 * Get a public URL of a file path relative to the plugin root directory.
	 *
	 * @param  string $file File path relative to the plugin root directory.
	 *
	 * @return string
	 */
	public function url_to( $file ) {
		return plugins_url( $file, $this->file );
	}

	/**
	 * Get absolute path of a file path relative to the plugin root directory.
	 *
	 * @param  string $file File path relative to the plugin root directory.
	 *
	 * @return string
	 */
	public function path_to( $file ) {
		return sprintf( '%s/%s', $this->dir(), ltrim( $file, '/' ) );
	}

	/**
	 * Get the current plugin version from the plugin meta.
	 *
	 * @return string
	 */
	public function version() {
		return $this->meta( 'Version' );
	}

	/**
	 * Get any plugin meta attribute value.
	 *
	 * @param  string $key Meta attribute key.
	 *
	 * @return string|null
	 */
	protected function meta( $key ) {
		static $meta;

		if ( isset( $meta ) ) {
			$meta = get_plugin_data( $this->file );
		}

		if ( isset( $meta->$key ) ) {
			return $meta->$key;
		}

		return null;
	}

}
