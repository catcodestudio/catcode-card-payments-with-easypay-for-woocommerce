( function () {
	'use strict';

	const settings = ( window.wc && window.wc.wcSettings )
		? window.wc.wcSettings.getSetting( 'catcode_easypay_data', {} )
		: {};

	const label = ( window.wp && window.wp.htmlEntities )
		? window.wp.htmlEntities.decodeEntities( settings.title || 'EasyPay' )
		: ( settings.title || 'EasyPay' );

	const el = window.wp.element.createElement;

	const Label = function () {
		return el( 'span', null, label );
	};

	const Content = function () {
		const text = ( window.wp && window.wp.htmlEntities )
			? window.wp.htmlEntities.decodeEntities( settings.description || '' )
			: ( settings.description || '' );
		return el( 'div', null, text );
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'catcode_easypay',
		label: el( Label, null ),
		content: el( Content, null ),
		edit: el( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: ( settings.supports || [ 'products' ] ),
		},
	} );
} )();
