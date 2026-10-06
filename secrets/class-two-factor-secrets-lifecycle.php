<?php
/**
 * Looks after the secrets providers keep for users.
 *
 * @package Two_Factor
 */

/**
 * Cleanup, warnings and reporting for every secret a provider declares.
 *
 * Providers say which user meta keys hold secrets through
 * Two_Factor_Provider::user_secret_meta_keys(). Everything here works from that
 * list and never instantiates a provider, so it keeps working for a provider the
 * site has turned off: its users' secrets still have to be deleted with the user,
 * warned about when unreachable and reported in Site Health.
 *
 * @since 0.18.0
 */
class Two_Factor_Secrets_Lifecycle {

	/**
	 * Register the hooks.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function add_hooks() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice_secrets_api_missing' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'admin_notice_secrets_api_missing' ) );

		// On multisite, `delete_user` also fires when a user is only removed from one site, so wait for the network-level deletion.
		if ( is_multisite() ) {
			add_action( 'wpmu_delete_user', array( __CLASS__, 'delete_user_secrets' ) );
		} else {
			add_action( 'delete_user', array( __CLASS__, 'delete_user_secrets' ) );
		}
	}

	/**
	 * Delete every secret held for a user when the user account is deleted.
	 *
	 * @since 0.18.0
	 *
	 * @param int $user_id ID of the user being deleted.
	 * @return void
	 */
	public static function delete_user_secrets( $user_id ) {
		foreach ( Two_Factor_Core::get_provider_secrets() as $slug => $secret ) {
			Two_Factor_Secrets::delete_stored_secret( (int) $user_id, $slug, $secret['meta_key'] );
		}
	}

	/**
	 * Delete every user's secrets from the Secrets API, for plugin uninstall.
	 *
	 * When the Secrets API is absent the secrets are left behind in the store, which is
	 * acceptable because they cannot be reached from here.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function delete_all_secrets() {
		if ( ! Two_Factor_Secrets::is_api_present() ) {
			return;
		}

		foreach ( array_keys( Two_Factor_Core::get_provider_secrets() ) as $slug ) {
			$seen = array();

			do {
				// The marker is removed with each secret, so the same offset always yields the next page.
				$user_ids = ( new WP_User_Query(
					array(
						'blog_id'      => 0,
						'fields'       => 'ID',
						'number'       => 100,
						'offset'       => 0,
						'orderby'      => 'ID',
						'meta_key'     => Two_Factor_Secrets::get_marker_meta_key( $slug ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall only.
						'meta_compare' => 'EXISTS',
						'count_total'  => false,
					)
				) )->get_results();

				$new_ids = array_diff( array_map( 'intval', $user_ids ), $seen );

				foreach ( $new_ids as $user_id ) {
					$seen[] = $user_id;
					Two_Factor_Secrets::delete_user_secret( (int) $user_id, $slug );
				}
			} while ( ! empty( $new_ids ) );
		}

		Two_Factor_Secrets::clear_affected_users_cache();
	}

	/**
	 * Whether any user has a secret in the Secrets API that this site cannot currently reach.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function has_affected_users() {
		return Two_Factor_Secrets::has_affected_users( array_keys( Two_Factor_Core::get_provider_secrets() ) );
	}

	/**
	 * Warn administrators that some users' authenticator secrets are unreachable.
	 *
	 * @since 0.18.0
	 *
	 * @return void
	 */
	public static function admin_notice_secrets_api_missing() {
		$capability = is_network_admin() ? 'manage_network_options' : 'manage_options';

		if ( ! current_user_can( $capability ) || ! self::has_affected_users() ) {
			return;
		}

		wp_admin_notice(
			esc_html__( 'Authenticator app secrets for one or more users were stored with the WordPress Secrets API, which is no longer available on this site. Those users cannot use their authenticator app until it is restored. Re-activate the Secrets API, or reset those users\' authenticator app so they can set it up again.', 'two-factor' ),
			array(
				'type'        => 'error',
				'dismissible' => false,
			)
		);
	}

	/**
	 * Register the Site Health test for secret storage.
	 *
	 * @since 0.18.0
	 *
	 * @param array $tests Site Health tests.
	 *
	 * @return array
	 */
	public static function register_site_health_test( $tests ) {
		$tests['direct']['two_factor_secret_storage'] = array(
			'label' => __( 'Authenticator app secret storage', 'two-factor' ),
			'test'  => array( __CLASS__, 'site_health_secret_storage' ),
		);

		return $tests;
	}

	/**
	 * Whether any user still has a plaintext secret in user meta.
	 *
	 * Not cached.
	 *
	 * @since 0.18.0
	 *
	 * @return bool
	 */
	public static function has_plaintext_users() {
		foreach ( Two_Factor_Core::get_provider_secrets() as $secret ) {
			$users = get_users(
				array(
					'blog_id'      => 0,
					'meta_key'     => $secret['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single-row lookup for Site Health.
					'meta_value'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single-row lookup for Site Health.
					'meta_compare' => '!=',
					'number'       => 1,
					'fields'       => 'ID',
					'count_total'  => false,
				)
			);

			if ( ! empty( $users ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Site Health test result for secret storage.
	 *
	 * @since 0.18.0
	 *
	 * @return array
	 */
	public static function site_health_secret_storage() {
		$result = array(
			'label'       => '',
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'two-factor' ),
				'color' => 'blue',
			),
			'description' => '',
			'actions'     => '',
			'test'        => 'two_factor_secret_storage',
		);

		if ( self::has_affected_users() ) {
			$result['label']          = __( 'Some authenticator app secrets are unavailable', 'two-factor' );
			$result['status']         = 'critical';
			$result['badge']['color'] = 'red';
			$result['description']    = sprintf(
				'<p>%s</p>',
				esc_html__( 'Some users\' authenticator app secrets were stored with the WordPress Secrets API, which this site cannot currently reach. Those users cannot use their authenticator app. Re-activate the Secrets API, or reset those users\' authenticator app so they can set it up again. Secrets are never moved back out of the Secrets API.', 'two-factor' )
			);

			return $result;
		}

		if ( ! Two_Factor_Secrets::is_api_present() ) {
			$result['label']       = __( 'Authenticator app secrets are stored in user meta', 'two-factor' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'TOTP secrets are stored in user meta. When the WordPress Secrets API is available, an administrator can choose to store them encrypted instead.', 'two-factor' )
			);

			return $result;
		}

		if ( ! Two_Factor_Secrets::is_enabled() ) {
			// Opted in on the settings screen, but a filter turned storage back off: a deliberate choice, not a to-do.
			if ( Two_Factor_Secrets::is_opted_in() ) {
				$result['label']       = __( 'Authenticator app secrets remain in user meta', 'two-factor' );
				$result['description'] = sprintf(
					'<p>%s</p>',
					esc_html__( 'Storing authenticator app secrets with the WordPress Secrets API is disabled by the two_factor_use_secrets_api filter, so new secrets are stored in user meta.', 'two-factor' )
				);

				return $result;
			}

			$result['label']          = __( 'Authenticator app secrets can be stored encrypted', 'two-factor' );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['description']    = sprintf(
				'<p>%s</p>',
				esc_html__( 'The WordPress Secrets API is available, but authenticator app secrets are still stored in user meta, where anyone with a copy of the database can read them. Turning on encrypted storage is a one-way change that depends on this site\'s secrets key, so an administrator has to turn it on.', 'two-factor' )
			);
			$result['actions']        = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'options-general.php?page=two-factor-settings' ) ),
				esc_html__( 'Review Two-Factor settings', 'two-factor' )
			);

			return $result;
		}

		if ( ! Two_Factor_Secrets::is_provider_writable() ) {
			$result['label']       = __( 'Authenticator app secrets remain in user meta', 'two-factor' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'The active secrets provider is read-only, so new authenticator app secrets are stored in user meta and existing ones are not migrated.', 'two-factor' )
			);

			return $result;
		}

		if ( ! self::has_plaintext_users() ) {
			$result['label']       = __( 'Authenticator app secrets are stored securely', 'two-factor' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %s: name of the secrets storage provider. */
					esc_html__( 'Authenticator app secrets are stored with the WordPress Secrets API (%s).', 'two-factor' ),
					esc_html( Two_Factor_Secrets::provider_label() )
				)
			);

			return $result;
		}

		$result['label']          = __( 'Some authenticator app secrets are not yet migrated', 'two-factor' );
		$result['status']         = 'recommended';
		$result['badge']['color'] = 'orange';
		$result['description']    = sprintf(
			'<p>%s</p>',
			sprintf(
				/* translators: %s: WP-CLI migrate command. */
				esc_html__( 'Some authenticator app secrets are still stored in user meta. They move to the WordPress Secrets API when those users next log in, or you can run %s to migrate them all now.', 'two-factor' ),
				'<code>wp two-factor secrets migrate</code>'
			)
		);

		return $result;
	}

	/**
	 * Count users by where one secret is stored.
	 *
	 * @since 0.18.0
	 *
	 * @param string $slug     Secret slug.
	 * @param string $meta_key User meta key that holds the plaintext value.
	 * @return array{plaintext: int, migrated: int, affected: int}
	 */
	public static function count_users_by_storage( $slug, $meta_key ) {
		$marker_key = Two_Factor_Secrets::get_marker_meta_key( $slug );

		$count = function ( $key, $compare, $value = null ) {
			$args = array(
				'blog_id'      => 0,
				'fields'       => 'ID',
				'number'       => 1,
				'count_total'  => true,
				'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- CLI reporting.
				'meta_compare' => $compare,
			);

			if ( null !== $value ) {
				$args['meta_value'] = $value; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- CLI reporting.
			}

			$query = new WP_User_Query( $args );

			return (int) $query->get_total();
		};

		$affected = Two_Factor_Secrets::is_api_present()
			? $count( $marker_key, '!=', (string) get_current_network_id() )
			: $count( $marker_key, 'EXISTS' );

		return array(
			'plaintext' => $count( $meta_key, '!=', '' ),
			'migrated'  => $count( $marker_key, 'EXISTS' ),
			'affected'  => $affected,
		);
	}
}
