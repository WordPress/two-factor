/* global ajaxurl, jQuery */

/**
 * Checks that an element has a non-empty `name` and `value` property.
 *
 * @param  {Element} element The element to check
 * @return {Boolean} true if the element is an input, false if not
 */
var isValidElement = function( element ) {
	return element.name && element.value;
};

/**
 * Checks if an element’s value can be saved (e.g. not an unselected checkbox).
 *
 * @param  {Element} element The element to check
 * @return {Boolean} true if the value should be added, false if not
 */
var isValidValue = function( element ) {
	return ( ! [ 'checkbox', 'radio' ].includes( element.type ) || element.checked );
};

/**
 * Checks if an input is a checkbox, because checkboxes allow multiple values.
 *
 * @param  {Element} element The element to check
 * @return {Boolean} true if the element is a checkbox, false if not
 */
var isCheckbox = function( element ) {
	return 'checkbox' === element.type;
};

/**
 * Retrieves input data from a form and returns it as a JSON object.
 *
 * @param  {HTMLFormControlsCollection} elements the form elements
 * @return {Object} form data as an object literal
 */
var formToJSON = function( elements ) {
	return [].reduce.call( elements, function( data, element ) {

		// Make sure the element has the required properties and should be added.
		if ( ! isValidElement( element ) || ! isValidValue( element ) ) {
			return data;
		}

		/*
		 * Some fields allow for more than one value, so we need to check if this
		 * is one of those fields and, if so, store the values as an array.
		 */
		if ( isCheckbox( element ) ) {
			data[ element.name ] = ( data[ element.name ] || [] ).concat( element.value );
		} else {
			data[ element.name ] = element.value;
		}

		return data;
	}, {} );
};

/**
 * A handler function to prevent default submission and run our custom script.
 *
 * @param  {Event} event  the submit event triggered by the user
 */
var handleFormSubmit = function( event ) {

	// Get form data.
	var formData = formToJSON( event.target.elements );

	event.preventDefault();

	formData.action = 'two_factor_force_form_submit';

	// Submit data to WordPress.
	jQuery.post(
		ajaxurl,
		formData,
		function () {
			window.location.reload();
		}
	);
};

window.addEventListener( 'load', function() {
	var form = document.querySelector( '#force_2fa_form' );
	form.addEventListener( 'submit', handleFormSubmit );
} );
