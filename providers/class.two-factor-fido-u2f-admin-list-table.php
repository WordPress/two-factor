<?php
// Load the parent class if it doesn't exist.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class for displaying the list of security key items.
 *
 * @since 0.1-dev
 * @access private
 *
 * @package Two_Factor
 */
class Two_Factor_FIDO_U2F_Admin_List_Table extends WP_List_Table {

	/**
	 * Get a list of columns.
	 *
	 * @since 0.1-dev
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'name'      => wp_strip_all_tags( __( 'Name' ) ),
			'added'   => wp_strip_all_tags( __( 'Added' ) ),
			'last_used' => wp_strip_all_tags( __( 'Last Used' ) ),
		);
	}

	/**
	 * Prepares the list of items for displaying.
	 *
	 * @since 0.1-dev
	 */
	public function prepare_items() {
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = array();
		$primary  = 'name';
		$this->_column_headers = array( $columns, $hidden, $sortable, $primary );
	}

	/**
	 * Generates content for a single row of the table
	 *
	 * @since 0.1-dev
	 * @access protected
	 *
	 * @param object $item The current item.
	 * @param string $column_name The current column name.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				$actions = array(
					'delete' => Two_Factor_FIDO_U2F_Admin::delete_link( $item ),
				);
				return esc_html( $item->name ) . self::row_actions( $actions );
			case 'added':
				return date( get_option( 'date_format', 'r' ), $item->added );
			case 'last_used':
				return date( get_option( 'date_format', 'r' ), $item->last_used );
			default:
				return 'WTF^^?';
		}
	}

	/**
	 * Generates custom table navigation to prevent conflicting nonces.
	 *
	 * @since 0.1-dev
	 * @access protected
	 *
	 * @param string $which The location of the bulk actions: 'top' or 'bottom'.
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
