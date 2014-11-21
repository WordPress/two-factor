<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Application_Passwords_List_Table extends WP_List_Table {

	function get_columns() {
		return array(
			'name'      => __( 'Name' ),
			'last_used' => __( 'Last Used' ),
			'last_ip'   => __( 'Last IP' ),
		);
	}

	function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = array();
		$this->_column_headers = array( $columns, $hidden, $sortable );
	}

	function column_default( $item, $column_name ) {
		switch( $column_name ) {
			case 'name':
				return esc_html( $item['name'] );
			case 'last_used':
				return $item['last_used'] ? $item['last_used'] : __( 'Never' );
			case 'last_ip':
				return $item['last_ip'] ? $item['last_ip'] : __( 'Never Used' );
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