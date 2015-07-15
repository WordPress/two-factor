jQuery(document).ready(function ($) {

	$('.delete').on('click', function( e ) {
		e.preventDefault();
		var link = $(this).find('a');
		var data = {
			'_nonce_delete_application_password': $(link).data('nonce'),
			'delete_application_password':        $(link).data('deleteApplicationPassword'),
			'action':                             $(link).data('action')
		};

		$.post(ajaxurl, data, function(r) {
			if( true == r.success ) {
				$('tr[data-slug="' + $(link).data('deleteApplicationPassword') + '"]').remove();
			}
		});
	});

});
