/**
 * An address found by the caller's geocoder, its coordinates, and an
 * OpenStreetMap that follows them.
 */

import { t } from '../lib/config';
import { announce, ours } from '../lib/dom';
import { ask, endpoint } from '../lib/lookups';

const Location = {
	init( root ) {
		ours( root, '.field-kit__location' ).forEach( function ( wrap ) {
			if ( wrap.dataset.fkBound ) {
				return;
			}

			wrap.dataset.fkBound = '1';

			const address = wrap.querySelector(
				'.field-kit__location-address'
			);
			const find = wrap.querySelector( '.field-kit__location-find' );
			let timer = null;

			if ( find && address ) {
				find.addEventListener( 'click', function () {
					Location.find( wrap );
				} );

				// Enter in the address box finds rather than sending the
				// form a step before it is ready.
				address.addEventListener( 'keydown', function ( event ) {
					if ( 'Enter' === event.key ) {
						event.preventDefault();
						Location.find( wrap );
					}
				} );
			}

			wrap.querySelectorAll(
				'.field-kit__location-lat, .field-kit__location-lng'
			).forEach( function ( input ) {
				input.addEventListener( 'input', function () {
					window.clearTimeout( timer );
					timer = window.setTimeout( function () {
						Location.preview( wrap );
					}, 500 );
				} );
			} );
		} );
	},

	find( wrap ) {
		const address = wrap.querySelector( '.field-kit__location-address' );
		const url = endpoint( 'lookup', {
			name: wrap.dataset.lookup || '',
			q: address ? address.value : '',
		} );

		if ( ! url || ! address || '' === address.value.trim() ) {
			return;
		}

		const status = wrap.querySelector( '.field-kit__location-status' );

		announce( status, t( 'finding', 'Finding…' ) );

		ask( url )
			.then( function ( answer ) {
				if ( ! answer.ok ) {
					announce(
						status,
						String(
							answer.json.message ||
								t(
									'notFound',
									'That address could not be found.'
								)
						)
					);

					return;
				}

				Location.set( wrap, answer.json );
				announce(
					status,
					t( 'found', 'Found: %s' ).replace(
						'%s',
						String( answer.json.address || '' )
					)
				);
			} )
			.catch( function () {
				announce(
					status,
					t( 'notFound', 'That address could not be found.' )
				);
			} );
	},

	set( wrap, place ) {
		const parts = {
			address: '.field-kit__location-address',
			lat: '.field-kit__location-lat',
			lng: '.field-kit__location-lng',
		};

		Object.keys( parts ).forEach( function ( key ) {
			const input = wrap.querySelector( parts[ key ] );

			if ( input && undefined !== place[ key ] ) {
				// A float out of JSON can carry its binary tail: 53.800800000000002.
				input.value =
					'address' === key
						? String( place[ key ] )
						: Location.number( place[ key ] );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		} );

		Location.preview( wrap );
	},

	/**
	 * Move the map to the coordinates in the boxes, or put it away
	 * when they are not a place.
	 *
	 * @param {Element} wrap The field.
	 */
	preview( wrap ) {
		const map = wrap.querySelector( '.field-kit__location-map' );

		if ( ! map ) {
			return;
		}

		const lat = parseFloat(
			( wrap.querySelector( '.field-kit__location-lat' ) || {} ).value
		);
		const lng = parseFloat(
			( wrap.querySelector( '.field-kit__location-lng' ) || {} ).value
		);

		if (
			isNaN( lat ) ||
			isNaN( lng ) ||
			Math.abs( lat ) > 90 ||
			Math.abs( lng ) > 180
		) {
			map.hidden = true;

			return;
		}

		let frame = map.querySelector( 'iframe' );
		let link = map.querySelector( 'a' );

		if ( ! frame ) {
			frame = document.createElement( 'iframe' );
			frame.className = 'field-kit__location-frame';
			frame.setAttribute( 'loading', 'lazy' );
			frame.setAttribute( 'referrerpolicy', 'no-referrer' );
			frame.title = wrap.dataset.mapTitle || t( 'mapTitle', 'Map' );
			map.appendChild( frame );

			link = document.createElement( 'a' );
			link.className = 'field-kit__location-link';
			link.target = '_blank';
			link.rel = 'noopener noreferrer';
			link.textContent = t( 'openMap', 'Open in OpenStreetMap' );
			map.appendChild( link );
		}

		frame.src = Location.url( lat, lng );
		link.href =
			'https://www.openstreetmap.org/?mlat=' +
			Location.number( lat ) +
			'&mlon=' +
			Location.number( lng ) +
			'#map=16/' +
			Location.number( lat ) +
			'/' +
			Location.number( lng );
		map.hidden = false;
	},

	/**
	 * OpenStreetMap's embedded map of a point, as LocationType::map_url()
	 * writes it.
	 *
	 * @param {number} lat Latitude.
	 * @param {number} lng Longitude.
	 * @return {string} The address.
	 */
	url( lat, lng ) {
		const d = 0.005;
		const box = [
			Math.max( -180, lng - d ),
			Math.max( -90, lat - d ),
			Math.min( 180, lng + d ),
			Math.min( 90, lat + d ),
		];

		return (
			'https://www.openstreetmap.org/export/embed.html?bbox=' +
			box.map( Location.number ).join( '%2C' ) +
			'&layer=mapnik&marker=' +
			Location.number( lat ) +
			'%2C' +
			Location.number( lng )
		);
	},

	number( value ) {
		const written = Number( value )
			.toFixed( 7 )
			.replace( /\.?0+$/, '' );

		return '-0' === written ? '0' : written;
	},
};

export default Location;
