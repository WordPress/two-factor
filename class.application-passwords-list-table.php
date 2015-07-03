<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Application_Passwords_List_Table extends WP_List_Table {

	function get_columns() {
		return array(
			'name'      => __( 'Name' ),
			'created'   => __( 'Created' ),
			'last_used' => __( 'Last Used' ),
			'last_ip'   => __( 'Last IP' ),
		);
	}

	function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = array();
		$primary  = 'name';
		$this->_column_headers = array( $columns, $hidden, $sortable, $primary );
	}

	function column_default( $item, $column_name ) {
		switch( $column_name ) {
			case 'name':
				return esc_html( $item['name'] );
			case 'created':
				if ( empty( $item['created'] ) ) {
					return __( 'Unknown' );
				}
				return date( get_option( 'date_format' ), $item['created'] );
			case 'last_used':
				if ( empty( $item['last_used'] ) ) {
					return __( 'Never' );
				}
				return $item['last_used'];
			case 'last_ip':
				if ( empty( $item['last_ip'] ) ) {
					return __( 'Never Used' );
				}
				return $item['last_ip'];
			default:
				return 'WTF^^?';
		}
	}

	/**
	 * Pull into the child class to prevent conflicting nonces.
	 */
	protected function display_tablenav( $which ) {
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">

			<div class="alignleft actions bulkactions">
				<?php $this->bulk_actions( $which ); ?>
			</div>
			<?php
			$this->extra_tablenav( $which );
			$this->pagination( $which );
			?>

			<br class="clear" />
		</div>
		<?php
	}

}