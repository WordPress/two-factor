/* global ajaxurl */
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
			if( true === r.success ) {
				$('tr[data-slug="' + $(link).data('deleteApplicationPassword') + '"]').remove();
			}
		});
	});

	$('#do_new_application_password').on('click', function( e ) {
		e.preventDefault();
		var app_name = $('input[name="new_application_password_name"]').val();
		var nonce    = $('input[name="create_application_password"]').val();
		var data = {
			'app_name'                          : app_name,
			'action'                            : 'create_application_password',
			'create_application_password': nonce,
		};

		$.post(ajaxurl, data, function(r) {
			alert(r);
		});
	});

});
