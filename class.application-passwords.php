<?php
/**
 * Class for displaying, modifying, & sanitizing application passwords.
 *
 * @since 0.1-dev
 *
 * @package Two_Factor
 */
class Application_Passwords {

	/**
	 * The user meta application password key.
	 * @type string
	 */
	const USERMETA_KEY_APPLICATION_PASSWORDS = '_application_passwords';

	/**
	 * Add various hooks.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 */
	public static function add_hooks() {
		add_filter( 'authenticate',                array( __CLASS__, 'authenticate' ), 10, 3 );
		add_action( 'show_user_security_settings', array( __CLASS__, 'show_user_profile' ) );
		add_action( 'personal_options_update',     array( __CLASS__, 'catch_submission' ), 0 );
		add_action( 'edit_user_profile_update',    array( __CLASS__, 'catch_submission' ), 0 );
		add_action( 'load-profile.php',            array( __CLASS__, 'catch_delete_application_password' ) );
		add_action( 'load-user-edit.php',          array( __CLASS__, 'catch_delete_application_password' ) );
		add_action( 'rest_api_init',               array( __CLASS__, 'rest_api_init' ) );
		add_filter( 'determine_current_user',      array( __CLASS__, 'rest_api_auth_handler' ), 20 );
	}

	/**
	 * Handle declaration of REST API endpoints.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 */
	public static function rest_api_init() {
		/**
		 * List existing application passwords
		 */
		register_rest_route( '2fa/v1', '/application-passwords/(?P<user_id>[\d]+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => __CLASS__ . '::rest_list_application_passwords',
			'permission_callback' => __CLASS__ . '::rest_edit_user_callback',
		) );

		/**
		 * Add new application passwords
		 */
		register_rest_route( '2fa/v1', '/application-passwords/(?P<user_id>[\d]+)/add', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => __CLASS__ . '::rest_add_application_password',
			'permission_callback' => __CLASS__ . '::rest_edit_user_callback',
			'args' => array(
				'name' => array(
					'required' => true,
				),
			),
		) );

		/**
		 * Delete an application password
		 */
		register_rest_route( '2fa/v1', '/application-passwords/(?P<user_id>[\d]+)/(?P<slug>[\da-fA-F]{12})', array(
			'methods' => WP_REST_Server::DELETABLE,
			'callback' => __CLASS__ . '::rest_delete_application_password',
			'permission_callback' => __CLASS__ . '::rest_edit_user_callback',
		) );
	}

	/**
	 * REST API endpoint to list existing application passwords for a user.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public static function rest_list_application_passwords( $data ) {
		$application_passwords = self::get_user_application_passwords( $data['user_id'] );
		$with_slugs = array();

		if ( $application_passwords ) {
			foreach ( $application_passwords as $item ) {
				$item['slug'] = self::password_unique_slug( $item );
				unset( $item['raw'] );
				unset( $item['password'] );

				$item['created'] = date( get_option( 'date_format', 'r' ), $item['created'] );

				if ( empty( $item['last_used'] ) ) {
					$item['last_used'] =  __( 'Never' );
				} else {
					$item['last_used'] = date( get_option( 'date_format', 'r' ), $item['last_used'] );
				}

				if ( empty( $item['last_ip'] ) ) {
					$item['last_ip'] =  __( 'Never Used' );
				}

				$with_slugs[ $item['slug'] ] = $item;
			}
		}

		return $with_slugs;
	}

	/**
	 * REST API endpoint to add a new application password for a user.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param $data
	 *
	 * @return array
	 */
	public static function rest_add_application_password( $data ) {
		// Modified version of `self::create_new_application_password` as that only returns the pw, not the row.
		$new_password    = wp_generate_password( 16, false );
		$hashed_password = wp_hash_password( $new_password );
		$new_item        = array(
			'name'      => $data['name'],
			'password'  => $hashed_password,
			'created'   => time(),
			'last_used' => null,
			'last_ip'   => null,
		);

		// Fetch the existing records.
		$passwords = self::get_user_application_passwords( $data['user_id'] );
		if ( ! $passwords ) {
			$passwords = array();
		}

		// Save the new one in the db.
		$passwords[] = $new_item;
		self::set_user_application_passwords( $data['user_id'], $passwords );

		// Some tidying before we return it.
		$new_item['slug']      = self::password_unique_slug( $new_item );
		$new_item['created']   = date( get_option( 'date_format', 'r' ), $new_item['created'] );
		$new_item['last_used'] = __( 'Never' );
		$new_item['last_ip']   = __( 'Never Used' );
		unset( $new_item['password'] );

		return array(
			'row'      => $new_item,
			'password' => self::chunk_password( $new_password )
		);
	}

	/**
	 * REST API endpoint to delete a given application password.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param $data
	 *
	 * @return bool
	 */
	public static function rest_delete_application_password( $data ) {
		return self::delete_application_password( $data['user_id'], $data['slug'] );
	}

	/**
	 * Whether or not the current user can edit the specified user.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param $data
	 *
	 * @return bool
	 */
	public static function rest_edit_user_callback( $data ) {
		return current_user_can( 'edit_user', $data['user_id'] );
	}

	/**
	 * Loosely Based on https://github.com/WP-API/Basic-Auth/blob/master/basic-auth.php
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param $user
	 *
	 * @return WP_User|bool
	 */
	public static function rest_api_auth_handler( $input_user ){
		// Don't authenticate twice
		if ( ! empty( $input_user ) ) {
			return $input_user;
		}

		// Check that we're trying to authenticate
		if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			return $input_user;
		}

		$user = self::authenticate( $input_user, $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );

		if ( is_a( $user, 'WP_User' ) ) {
			return $user->ID;
		}

		// If it wasn't a user what got returned, just pass on what we had received originally.
		return $input_user;
	}

	/**
	 * Filter the user to authenticate.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param WP_User $input_user User to authenticate.
	 * @param string  $username   User login.
	 * @param string  $password   User password.
	 *
	 * @return mixed
	 */
	public static function authenticate( $input_user, $username, $password ) {
		$api_request = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( ! apply_filters( 'application_password_is_api_request', $api_request ) ) {
			return $input_user;
		}

		$user = get_user_by( 'login',  $username );

		// If the login name is invalid, short circuit.
		if ( ! $user ) {
			return $input_user;
		}

		/*
		 * Strip out anything non-alphanumeric. This is so passwords can be used with
		 * or without spaces to indicate the groupings for readability.
		 *
		 * Generated application passwords are exclusively alphanumeric.
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

		// By default, return what we've been passed.
		return $input_user;
	}

	/**
	 * Display the application password section in a users profile.
	 *
	 * This executes during the `show_user_security_settings` action.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param WP_User $user WP_User object of the logged-in user.
	 */
	public static function show_user_profile( $user ) {
		wp_enqueue_script( 'application-passwords', plugin_dir_url( __FILE__ ) . 'application-passwords.js', array() );
		wp_localize_script( 'application-passwords', 'appPass', array(
			'root'       => esc_url_raw( rest_url() ),
			'namespace'  => '2fa/v1',
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'user_id'    => $user->ID,
		) );

		wp_nonce_field( "user_application_passwords-{$user->ID}", '_nonce_user_application_passwords' );
		$new_password      = null;
		$new_password_name = null;

		$application_passwords = self::get_user_application_passwords( $user->ID );
		if ( $application_passwords ) {
			foreach ( $application_passwords as &$application_password ) {
				if ( ! empty( $application_password['raw'] ) ) {
					$new_password      = $application_password['raw'];
					$new_password_name = $application_password['name'];
					unset( $application_password['raw'] );
				}
			}
			unset( $application_password );
		}

		// If we've got a new one, update the db record to not save it there any longer.
		if ( $new_password ) {
			self::set_user_application_passwords( $user->ID, $application_passwords );
		}
		?>
		<div class="application-passwords" id="application-passwords-section">
			<h3><?php esc_html_e( 'Application Passwords' ); ?></h3>
			<p><?php esc_html_e( 'Application Passwords are used to allow authentication via non-interactive systems, such as XMLRPC, where you would not otherwise be able to use your normal password due to the inability to complete the second factor of authentication.' ); ?></p>
			<div class="create-application-password">
				<input type="text" size="30" name="new_application_password_name" placeholder="<?php esc_attr_e( 'New Application Password Name' ); ?>" class="input" />
				<?php submit_button( __( 'Add New' ), 'secondary', 'do_new_application_password', false ); ?>
			</div>

			<?php if ( $new_password ) : ?>
			<p class="new-application-password">
				<?php
				printf(
					esc_html_x( 'Your new password for %1$s is %2$s.', 'application, password' ),
					'<strong>' . esc_html( $new_password_name ) . '</strong>',
					'<kbd>' . esc_html( self::chunk_password( $new_password ) ) . '</kbd>'
				);
				?>
			</p>
			<?php endif; ?>

			<?php
				require( dirname( __FILE__ ) . '/class.application-passwords-list-table.php' );
				// @todo Isn't this class already loaded in Two_Factor_Core::get_providers()?
				$application_passwords_list_table = new Application_Passwords_List_Table();
				$application_passwords_list_table->items = $application_passwords;
				$application_passwords_list_table->prepare_items();
				$application_passwords_list_table->display();
			?>
		</div>

		<script type="text/html" id="tmpl-new-application-password">
			<p class="new-application-password">
				<?php
				printf(
					esc_html_x( 'Your new password for %1$s is %2$s.', 'application, password' ),
					'<strong>{{ data.name }}</strong>',
					'<kbd>{{ data.password }}</kbd>'
				);
				?>
			</p>
		</script>
		<script type="text/html" id="tmpl-application-password-row">
			<tr data-slug="{{ data.slug }}">
				<td class="name column-name has-row-actions column-primary" data-colname="<?php echo esc_attr( 'Name' ); ?>">
					{{ data.name }}
				</td>
				<td class="created column-created" data-colname="<?php echo esc_attr( 'Created' ); ?>">
					{{ data.created }}
				</td>
				<td class="last_used column-last_used" data-colname="<?php echo esc_attr( 'Last Used' ); ?>">
					{{ data.last_used }}
				</td>
				<td class="last_ip column-last_ip" data-colname="<?php echo esc_attr( 'Last IP' ); ?>">
					{{ data.last_ip }}
				</td>
			</tr>
		</script>
		<?php
	}

	/**
	 * Catch the non-ajax submission from the new form.
	 *
	 * This executes during the `personal_options_update` & `edit_user_profile_update` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param int $user_id User ID.
	 */
	public static function catch_submission( $user_id ) {
		if ( ! empty( $_REQUEST['do_new_application_password'] ) ) {
			check_admin_referer( "user_application_passwords-{$user_id}", '_nonce_user_application_passwords' );

			self::create_new_application_password( $user_id, sanitize_text_field( $_POST['new_application_password_name'] ) );

			wp_safe_redirect( add_query_arg( array(
				'new_app_pass' => 1,
			), wp_get_referer() ) . '#application-passwords-section' );
			exit;
		}
	}

	/**
	 * Catch the delete application password request.
	 *
	 * This executes during the `load-profile.php` & `load-user-edit.php` actions.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 */
	public static function catch_delete_application_password() {
		$user_id = get_current_user_id();
		if ( ! empty( $_REQUEST['delete_application_password'] ) ) {
			$slug = $_REQUEST['delete_application_password'];
			check_admin_referer( "delete_application_password-{$slug}", '_nonce_delete_application_password' );

			self::delete_application_password( $user_id, $slug );

			wp_safe_redirect( remove_query_arg( 'new_app_pass', wp_get_referer() ) . '#application-passwords-section' );
		}
	}

	/**
	 * Generate a new application password.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param int    $user_id User ID.
	 * @param string $name Password name.
	 * @return string
	 */
	public static function create_new_application_password( $user_id, $name ) {
		$passwords       = self::get_user_application_passwords( $user_id );
		$new_password    = wp_generate_password( 16, false );
		$hashed_password = wp_hash_password( $new_password );

		$new_item  = array(
			'name'      => $name,
			'raw'       => $new_password, // THIS LINE GETS DELETED IN SUBSEQUENT REQUEST.
			'password'  => $hashed_password,
			'created'   => time(),
			'last_used' => null,
			'last_ip'   => null,
		);

		if ( ! $passwords ) {
			$passwords = array();
		}

		$passwords[] = $new_item;
		self::set_user_application_passwords( $user_id, $passwords );

		return self::chunk_password( $new_password );
	}

	/**
	 * Generate a link to delete a specified application password.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	public static function delete_link( $item ) {
		$slug = self::password_unique_slug( $item );
		$delete_link = add_query_arg( 'delete_application_password', $slug );
		$delete_link = wp_nonce_url( $delete_link, "delete_application_password-{$slug}", '_nonce_delete_application_password' );
		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $delete_link ), esc_html__( 'Delete' ) );
	}

	/**
	 * Delete a specified application password.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @see Application_Passwords::password_unique_slug()
	 *
	 * @param int    $user_id User ID.
	 * @param string $slug The generated slug of the password in question.
	 * @return bool Whether the password was successfully found and deleted.
	 */
	public static function delete_application_password( $user_id, $slug ) {
		$passwords = self::get_user_application_passwords( $user_id );

		foreach ( $passwords as $key => $item ) {
			if ( self::password_unique_slug( $item ) === $slug ) {
				unset( $passwords[ $key ] );
				self::set_user_application_passwords( $user_id, $passwords );
				return true;
			}
		}

		// Specified Application Password not found!
		return false;
	}

	/**
	 * Generate a unique repeateable slug from the hashed password, name, and when it was created.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param array $item The current item.
	 * @return string
	 */
	public static function password_unique_slug( $item ) {
		$concat = $item['name'] . '|' . $item['password'] . '|' . $item['created'];
		$hash   = md5( $concat );
		return substr( $hash, 0, 12 );
	}

	/**
	 * Sanitize and then split a passowrd into smaller chunks.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param string $raw_password Users raw password.
	 * @return string
	 */
	public static function chunk_password( $raw_password ) {
		$raw_password = preg_replace( '/[^a-z\d]/i', '', $raw_password );
		return trim( chunk_split( $raw_password, 4, ' ' ) );
	}

	/**
	 * Get a users application passwords.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_user_application_passwords( $user_id ) {
		return get_user_meta( $user_id, self::USERMETA_KEY_APPLICATION_PASSWORDS, true );
	}

	/**
	 * Set a users application passwords.
	 *
	 * @since 0.1-dev
	 *
	 * @access public
	 * @static
	 *
	 * @param int   $user_id User ID.
	 * @param array $passwords Application passwords.
	 *
	 * @return bool
	 */
	public static function set_user_application_passwords( $user_id, $passwords ) {
		return update_user_meta( $user_id, self::USERMETA_KEY_APPLICATION_PASSWORDS, $passwords );
	}
}
