<?php

class Yubico_U2F_Wrapper {

	public static function get( $appId ) {
		require_once( 'includes/Yubico/U2F.php' );

		return new u2flib_server\U2F( $appId );
	}

}
