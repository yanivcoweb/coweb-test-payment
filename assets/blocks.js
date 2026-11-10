( function () {
	var settings = window.wc.wcSettings.getSetting( 'coweb_test_payment_data', {} );
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var createElement = window.wp.element.createElement;
	var title = decodeEntities( settings.title || '' );

	var Content = function () {
		return createElement( 'div', null, decodeEntities( settings.description || '' ) );
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'coweb_test_payment',
		label: createElement( 'span', null, title ),
		ariaLabel: title,
		content: createElement( Content ),
		edit: createElement( Content ),
		// ההרשאה נבדקת בשרת: אם המשתמש לא מורשה הסקריפט הזה לא נטען בכלל.
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
