<?php

class Two_Factor_Core {

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	/**
	 * Class constructor.  Sets up filters and actions.
	 */
	private function __construct() {
		add_action( 'init', 'two_factor_get_providers' );
	}

	/**
	 * For each provider, include it and then instantiate it.
	 *
	 * @return array
	 */
	function two_factor_get_providers() {
		$providers = array(
			'Two_Factor_Email'    => TWO_FACTOR_DIR . 'providers/class.two-factor-email.php',
			'Two_Factor_Totp'     => TWO_FACTOR_DIR . 'providers/class.two-factor-totp.php',
			'Two_Factor_Fido_U2f' => TWO_FACTOR_DIR . 'providers/class.two-factor-fido-u2f.php',
		);

		/**
		 * Filter the supplied providers.
		 *
		 * This lets third-parties either remove providers (such as Email), or
		 * add their own providers (such as text message or Clef).
		 *
		 * @param array $providers A key-value array where the key is the class name, and
		 *                         the value is the path to the file containing the class.
		 */
		$providers = apply_filters( 'two_factor_providers', $providers );

		/**
		 * For each filtered provider,
		 */
		foreach ( $providers as $class => $path ) {
			include_once( $path );

			/**
			 * Confirm that it's been successfully included before instantiating.
			 */
			if ( class_exists( $class ) ) {
				$providers[ $class ] = call_user_func( array( $class, 'get_instance' ) );
			}
		}

		return $providers;
	}
}
