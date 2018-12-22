<?php

/**
 * REST API endpoint for the FIDO U2F provider.
 */
class Two_Factor_FIDO_U2F_Rest {

	/**
	 * Instance of the U2F provider.
	 *
	 * @var Two_Factor_FIDO_U2F
	 */
	protected $provider;

	/**
	 * Setup the REST class.
	 *
	 * @param Two_Factor_FIDO_U2F $provider Instance of the U2F provider.
	 */
	public function __construct( $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Hook into WP.
	 */
	public function add_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * Register the FIDO U2F API endpoints.
	 *
	 * @return void
	 */
	public function register_endpoints() {
		register_rest_route( 'two-factor/v1', '/app_id/(?P<user_id>\d+)', array(
			'methods' => 'GET',
			'callback' => array( $this, 'endpoint_get_app_id' ),
			'args' => array(
				'user_id' => array(
					'validate_callback' => function( $param, $request, $key ) {
						return is_numeric( $param );
					}
				),
			)
		) );
	}

	/**
	 * Response for the App ID request.
	 *
	 * @param  WP_REST_Request $request REST request.
	 *
	 * @return array
	 */
	public function endpoint_get_app_id( $request ) {
		return $this->provider->get_trusted_facets( $request['user_id'] );
	}
}
