<?php

class Application_Passwords {

	const USERMETA_KEY_APPLICATION_PASSWORDS = '_application_passwords';

	static function get_instance() {
		static $instance;
		$class = __CLASS__;
		if ( ! is_a( $instance, $class ) ) {
			$instance = new $class;
		}
		return $instance;
	}

	function __construct() {
		add_filter( 'authenticate',             array( $this, 'authenticate' ), 10, 3 );
		add_action( 'show_user_profile',        array( $this, 'show_user_profile' ) );
		add_action( 'edit_user_profile',        array( $this, 'show_user_profile' ) );
		add_action( 'personal_options_update',  array( $this, 'catch_submission' ), 0 );
		add_action( 'edit_user_profile_update', array( $this, 'catch_submission' ), 0 );
	}

	function authenticate( $input_user, $username, $password ) {
		$api_request = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
		if ( ! apply_filters( 'application_password_is_api_request', $api_request ) ) {
			return $input_user;
		}

		$user = get_user_by( 'login',  $username );

		/**
		 * If the login name is invalid, short circuit.
		 */
		if ( ! $user ) {
			return $input_user;
		}

		/**
		 * Strip out anything non-alphanumeric.  This is so
		 * that passwords can be used with or without spaces
		 * to indicate the groupings for readability.
		 */
		$password = preg_replace( '/[^a-z\d]/i', '', $password );

		$hashed_passwords = get_user_meta( $user->ID, self::USERMETA_KEY_APPLICATION_PASSWORDS, true );

		foreach ( $hashed_passwords as $key => $item ) {
			if ( wp_check_password( $password, $item['password'], $user->ID ) ) {
				$item['last_used'] = time();
				$item['last_ip']   = $_SERVER['REMOTE_ADDR'];
				$hashed_passwords[ $key ] = $item;
				update_user_meta( $user->ID, self::USERMETA_KEY_APPLICATION_PASSWORDS, $hashed_passwords );
				return $user;
			}
		}

		/**
		 * By default, continue what we've been passed.
		 */
		return $input_user;
	}

	function show_user_profile( $user ) {
		wp_nonce_field( "user_application_passwords-{$user->ID}", '_nonce_user_application_passwords' );
		$application_passwords = $this->get_user_application_passwords( $user->ID );
		$new_password      = null;
		$new_password_name = null;
		foreach ( $application_passwords as &$application_password ) {
			if ( ! empty( $application_password['raw'] ) ) {
				$new_password      = $application_password['raw'];
				$new_password_name = $application_password['name'];
				unset( $application_password['raw'] );
			}
		}
		unset( $application_password );

		// If we've got a new one, update the db record to not save it there any longer.
		if ( $new_password ) {
			$this->set_user_application_passwords( $user->ID, $application_passwords );
		}
		?>
		<div class="application-passwords" id="application-passwords-section">
			<h3><?php esc_html_e( 'Application Passwords', 'two-factor' ); ?></h3>
			<div class="create-application-password">
				<input type="text" size="30" name="new_application_password_name" placeholder="<?php esc_attr_e( 'New Application Password Name', 'two-factor' ); ?>" />
				<?php submit_button( __( 'Add New', 'two-factor' ), 'secondary', 'do_new_application_password', false ); ?>
			</div>

			<?php if ( $new_password ) : ?>
			<p class="new-application-password">
				<?php printf( __( 'Your new password for <strong>%s</strong> is <kbd>%s</kbd>.' ), esc_html( $new_password_name ), $this->chunk_password( $new_password ) ); ?>
			</p>
			<?php endif; ?>

			<?php
				require( dirname( __FILE__ ) . '/class.application-passwords-list-table.php' );
				$application_passwords_list_table = new Application_Passwords_List_Table();
				$application_passwords_list_table->items = $application_passwords;
				$application_passwords_list_table->prepare_items();
				$application_passwords_list_table->display();
			?>
		</div>
		<?php
	}

	/**
	 * Catch the non-ajax submission from the new form.
	 */
	function catch_submission( $user_id ) {
		if ( ! empty( $_REQUEST['do_new_application_password'] ) ) {
			check_admin_referer( "user_application_passwords-{$user_id}", '_nonce_user_application_passwords' );

			$this->create_new_application_password( $user_id, sanitize_text_field( $_POST['new_application_password_name'] ) );

			wp_safe_redirect( add_query_arg( array(
					'new_app_pass' => 1,
				), wp_get_referer() ) . '#application-passwords-section' );
			exit;
		}
	}

	function create_new_application_password( $user_id, $name ) {
		$passwords       = $this->get_user_application_passwords( $user_id );
		$new_password    = wp_generate_password( 16, false );
		$hashed_password = wp_hash_password( $new_password );

		$new_item  = array(
			'name'      => $name,
		    'raw'       => $new_password, // THIS LINE GETS DELETED IN SUBSEQUENT REQUEST
			'password'  => $hashed_password,
			'last_used' => null,
			'last_ip'   => null,
		);

		if ( ! $passwords ) {
			$passwords = array();
		}

		$passwords[] = $new_item;
		$this->set_user_application_passwords( $user_id, $passwords );

		return chunk_split( $new_password, 4, ' ' );
	}

	function chunk_password( $raw_password ) {
		$raw_password = preg_replace( '/[^a-z\d]/i', '', $raw_password );
		return trim( chunk_split( $raw_password, 4, ' ' ) );
	}

	function get_user_application_passwords( $user_id ) {
		return get_user_meta( $user_id, self::USERMETA_KEY_APPLICATION_PASSWORDS, true );
	}

	function set_user_application_passwords( $user_id, $items ) {
		return update_user_meta( $user_id, self::USERMETA_KEY_APPLICATION_PASSWORDS, $items );
	}
}