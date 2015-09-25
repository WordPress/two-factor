/* global inlineEditL10n, ajaxurl */

var inlineEditKey;
(function($) {
inlineEditKey = {

	init : function() {
		var t = this, row = $('#security-keys-section #inline-edit');

		t.what = '#key'+'-';

		$('#security-keys-section #the-list').on('click', 'a.editinline', function(){
			inlineEditKey.edit(this);
			return false;
		});

		// prepare the edit row
		row.keyup( function( e ) {
			if ( e.which === 27 ) {
				return inlineEditKey.revert();
			}
		});

		$( 'a.cancel', row ).click( function() {
			return inlineEditKey.revert();
		});
		$( 'a.save', row ).click( function() {
			return inlineEditKey.save(this);
		});
		$( 'input, select', row ).keydown( function( e ) {
			if ( e.which === 13 ) {
				return inlineEditKey.save( this );
			}
		});
	},

	toggle : function(el) {
		var t = this;
		$(t.what+t.getId(el)).css('display') === 'none' ? t.revert() : t.edit(el);
	},

	edit : function(id) {
		var editRow, rowData, val,
			t = this;
		t.revert();

		if ( typeof(id) === 'object' ) {
			id = t.getId(id);
		}

		editRow = $('#inline-edit').clone(true), rowData = $('#inline_'+id);
		$( 'td', editRow ).attr( 'colspan', $( 'th:visible, td:visible', '#security-keys-section .widefat thead' ).length );

		$(t.what+id).hide().after(editRow).after('<tr class="hidden"></tr>');

		val = $('.name', rowData);
		val.find( 'img' ).replaceWith( function() { return this.alt; } );
		val = val.text();
		$(':input[name="name"]', editRow).val( val );

		$(editRow).attr('id', 'edit-'+id).addClass('inline-editor').show();
		$('.ptitle', editRow).eq(0).focus();

		return false;
	},

	save : function(id) {
		var params, fields;

		if( typeof(id) === 'object' ) {
			id = this.getId(id);
		}

		$( '#security-keys-section table.widefat .spinner' ).addClass( 'is-active' );

		params = {
			action: 'inline-save-key',
			keyHandle: id
		};

		fields = $('#edit-'+id).find(':input').serialize();
		params = fields + '&' + $.param(params);

		// make ajax request
		$.post( ajaxurl, params,
			function(r) {
				var row, new_id, option_value;
				$( '#security-keys-section table.widefat .spinner' ).removeClass( 'is-active' );

				if (r) {
					if ( -1 !== r.indexOf( '<tr' ) ) {
						$(inlineEditKey.what+id).siblings('tr.hidden').addBack().remove();
						new_id = $(r).attr('id');

						$('#edit-'+id).before(r).remove();

						if ( new_id ) {
							option_value = new_id.replace( 'key-', '' );
							row = $( '#' + new_id );
						} else {
							option_value = id;
							row = $( inlineEditKey.what + id );
						}

						row.hide().fadeIn();
					} else {
						$('#edit-'+id+' .inline-edit-save .error').html(r).show();
					}
				} else {
					$('#edit-'+id+' .inline-edit-save .error').html(inlineEditL10n.error).show();
				}
			}
		);
		return false;
	},

	revert : function() {
		var id = $('#security-keys-section table.widefat tr.inline-editor').attr('id');

		if ( id ) {
			$( '#security-keys-section table.widefat .spinner' ).removeClass( 'is-active' );
			$('#'+id).siblings('tr.hidden').addBack().remove();
			id = id.replace(/\w+\-/, '');
			$(this.what+id).show();
		}

		return false;
	},

	getId : function(o) {
		var id = o.tagName === 'TR' ? o.id : $(o).parents('tr').attr('id');
		return id.replace(/\w+\-/, '');
	}
};

$(document).ready(function(){inlineEditKey.init();});
})(jQuery);
