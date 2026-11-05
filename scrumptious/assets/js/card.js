/**
 * Scrumptious recipe card: servings scaling, US/metric conversion, cook mode,
 * tap-to-check, and star ratings. Vanilla JS, no dependencies, progressive
 * enhancement (the card is complete without it).
 */
( function ( root ) {
	'use strict';

	/* ---------- Pure helpers (exported for tests) ---------- */

	var FRACTIONS = [
		[ 0, '' ], [ 1 / 8, '⅛' ], [ 1 / 4, '¼' ], [ 1 / 3, '⅓' ], [ 3 / 8, '⅜' ], [ 1 / 2, '½' ],
		[ 5 / 8, '⅝' ], [ 2 / 3, '⅔' ], [ 3 / 4, '¾' ], [ 7 / 8, '⅞' ], [ 1, '' ],
	];

	var US = { cup: 1, tbsp: 1, tsp: 1, 'fl oz': 1, oz: 1, lb: 1, pt: 1, qt: 1, gal: 1 };
	var METRIC = { g: 1, kg: 1, ml: 1, l: 1 };

	// US unit -> [metric base unit, factor].
	var TO_METRIC = {
		cup: [ 'ml', 236.588 ], tbsp: [ 'ml', 14.787 ], tsp: [ 'ml', 4.929 ], 'fl oz': [ 'ml', 29.574 ],
		pt: [ 'ml', 473.176 ], qt: [ 'ml', 946.353 ], gal: [ 'ml', 3785.41 ],
		oz: [ 'g', 28.3495 ], lb: [ 'g', 453.592 ],
	};

	/** Nice fraction text for cooking amounts: 1.5 -> "1½", 0.333 -> "⅓", 1.04 -> "1". */
	function formatAmount( n, precise, coarse, unit ) {
		if ( ! isFinite( n ) || n <= 0 ) {
			return '0';
		}
		if ( precise ) {
			// Metric, rounded the way a cook measures: litres and kilos to 0.1;
			// ml and grams whole under 10, to the nearest 5 under 100, then to the nearest 10.
			if ( unit === 'l' || unit === 'kg' ) {
				return String( n >= 10 ? Math.round( n ) : Math.round( n * 10 ) / 10 );
			}
			var r = n >= 100 ? Math.round( n / 10 ) * 10 : n >= 10 ? Math.round( n / 5 ) * 5 : Math.max( 1, Math.round( n ) );
			return String( r );
		}
		// Kitchen rounding: nearest 1/8 (measured) or 1/4 (countable items like eggs); whole numbers from 20 up.
		var step = n >= 20 ? 1 : coarse ? 4 : 8;
		var thirds = Math.round( n * 3 ) / 3;
		// Thirds are common in recipes (⅓ cup) and don't fall on eighths, so keep them when close.
		n = n < 20 && ! coarse && Math.abs( n - thirds ) < 0.04 && thirds > 0 ? thirds : Math.max( 1 / step, Math.round( n * step ) / step );
		var whole = Math.floor( n );
		var frac = n - whole;
		for ( var i = 0; i < FRACTIONS.length; i++ ) {
			if ( Math.abs( frac - FRACTIONS[ i ][ 0 ] ) < 0.03 ) {
				if ( FRACTIONS[ i ][ 0 ] === 1 ) {
					return String( whole + 1 );
				}
				return whole === 0 && FRACTIONS[ i ][ 1 ] ? FRACTIONS[ i ][ 1 ] : String( whole ) + FRACTIONS[ i ][ 1 ];
			}
		}
		return String( Math.round( n * 100 ) / 100 );
	}

	/** Which system a recipe is written in, by majority of unit keys. */
	function detectSystem( keys ) {
		var us = 0;
		var metric = 0;
		keys.forEach( function ( k ) {
			if ( US[ k ] ) {
				us++;
			} else if ( METRIC[ k ] ) {
				metric++;
			}
		} );
		if ( ! us && ! metric ) {
			return '';
		}
		return metric > us ? 'metric' : 'us';
	}

	/** Convert value+unitKey to the target system. Returns { value, unit } or null when not convertible. */
	function convert( value, unit, target ) {
		if ( target === 'metric' && ( unit === 'tsp' || unit === 'tbsp' ) ) {
			return null; // Measuring spoons are used in metric kitchens too; "2 tsp" beats "9.9 ml".
		}
		if ( target === 'metric' && TO_METRIC[ unit ] ) {
			var m = TO_METRIC[ unit ];
			var v = value * m[ 1 ];
			if ( m[ 0 ] === 'ml' && v >= 1000 ) {
				return { value: v / 1000, unit: 'l', precise: true };
			}
			if ( m[ 0 ] === 'g' && v >= 1000 ) {
				return { value: v / 1000, unit: 'kg', precise: true };
			}
			return { value: v, unit: m[ 0 ], precise: true };
		}
		if ( target === 'us' && METRIC[ unit ] ) {
			if ( unit === 'g' || unit === 'kg' ) {
				var grams = unit === 'kg' ? value * 1000 : value;
				return grams >= 454 ? { value: grams / 453.592, unit: 'lb' } : { value: grams / 28.3495, unit: 'oz' };
			}
			var ml = unit === 'l' ? value * 1000 : value;
			if ( ml < 14 ) {
				return { value: ml / 4.929, unit: 'tsp' };
			}
			if ( ml < 59 ) {
				return { value: ml / 14.787, unit: 'tbsp' };
			}
			return ml >= 3785 ? { value: ml / 946.353, unit: 'qt' } : { value: ml / 236.588, unit: 'cup' };
		}
		return null;
	}

	function unitLabel( unit, value ) {
		if ( unit === 'cup' ) {
			return value > 1.01 ? 'cups' : 'cup';
		}
		return unit;
	}

	/** Keep a written unit word in step with the amount: "cup" <-> "cups", "pinch" <-> "pinches". */
	var PLURALS = {
		cup: 'cups', clove: 'cloves', pinch: 'pinches', dash: 'dashes', can: 'cans', jar: 'jars', stick: 'sticks',
		slice: 'slices', sprig: 'sprigs', bunch: 'bunches', head: 'heads', handful: 'handfuls', piece: 'pieces',
		package: 'packages', packet: 'packets', pound: 'pounds', ounce: 'ounces', teaspoon: 'teaspoons',
		tablespoon: 'tablespoons', quart: 'quarts', pint: 'pints', gallon: 'gallons', gram: 'grams', liter: 'liters',
		litre: 'litres', kilogram: 'kilograms', milliliter: 'milliliters', millilitre: 'millilitres', cube: 'cubes',
		sheet: 'sheets', leaf: 'leaves', fillet: 'fillets', stalk: 'stalks', sprinkle: 'sprinkles',
	};
	var SINGULARS = {};
	Object.keys( PLURALS ).forEach( function ( k ) {
		SINGULARS[ PLURALS[ k ] ] = k;
	} );

	function pluralize( text, value ) {
		var lower = ( text || '' ).toLowerCase();
		var many = value > 1.01;
		var next = many ? PLURALS[ lower ] : SINGULARS[ lower ];
		if ( ! next ) {
			return text;
		}
		return text.charAt( 0 ) === text.charAt( 0 ).toUpperCase() ? next.charAt( 0 ).toUpperCase() + next.slice( 1 ) : next;
	}

	var api = { formatAmount: formatAmount, detectSystem: detectSystem, convert: convert, unitLabel: unitLabel, pluralize: pluralize };
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
		return;
	}

	/* ---------- DOM behaviour ---------- */

	var config = root.scrumptious || { rest: '', i18n: {} };
	var t = function ( key, fallback ) {
		return ( config.i18n && config.i18n[ key ] ) || fallback;
	};

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			node.setAttribute( k, attrs[ k ] );
		} );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	/* Public DOM events for themes/add-ons: scrumptious:ready|change|cook|rated (bubble from .swp-card). */
	function emit( card, name, detail ) {
		if ( typeof root.CustomEvent === 'function' ) {
			card.dispatchEvent( new root.CustomEvent( 'scrumptious:' + name, { bubbles: true, detail: detail } ) );
		}
	}

	function initCard( card ) {
		var features = ( card.getAttribute( 'data-features' ) || '' ).split( ' ' );
		var has = function ( f ) {
			return features.indexOf( f ) !== -1;
		};
		var baseServings = parseFloat( card.getAttribute( 'data-servings' ) ) || 0;
		var items = Array.prototype.slice.call( card.querySelectorAll( '.swp-ingredient[data-amount]' ) );
		var state = { scale: 1, system: '' };

		items.forEach( function ( li ) {
			var a = li.querySelector( '.swp-ingredient__amount' );
			var u = li.querySelector( '.swp-ingredient__unit' );
			li._swp = { amountHtml: a ? a.textContent : '', unitHtml: u ? u.textContent : '' };
		} );

		var original = detectSystem(
			items.map( function ( li ) {
				return li.getAttribute( 'data-unit' ) || '';
			} )
		);
		state.system = original;

		function render() {
			items.forEach( function ( li ) {
				var a = li.querySelector( '.swp-ingredient__amount' );
				if ( ! a ) {
					return;
				}
				var u = li.querySelector( '.swp-ingredient__unit' );
				if ( state.scale === 1 && state.system === original ) {
					a.textContent = li._swp.amountHtml;
					if ( u ) {
						u.textContent = li._swp.unitHtml;
					}
					return;
				}
				var val = parseFloat( li.getAttribute( 'data-amount' ) ) * state.scale;
				var max = li.hasAttribute( 'data-amount-max' ) ? parseFloat( li.getAttribute( 'data-amount-max' ) ) * state.scale : null;
				var unit = li.getAttribute( 'data-unit' ) || '';
				var out = state.system !== original ? convert( val, unit, state.system ) : null;
				if ( out ) {
					var outMax = max !== null ? convert( max, unit, state.system ) : null;
					a.textContent = formatAmount( out.value, out.precise, false, out.unit ) + ( outMax ? '–' + formatAmount( outMax.value, outMax.precise, false, outMax.unit ) : '' );
					if ( u ) {
						u.textContent = unitLabel( out.unit, out.value );
					}
				} else {
					var coarse = ! unit;
					a.textContent = formatAmount( val, false, coarse ) + ( max !== null ? '–' + formatAmount( max, false, coarse ) : '' );
					if ( u ) {
						u.textContent = pluralize( li._swp.unitHtml, max !== null ? max : val );
					}
				}
			} );
			var servings = card.querySelector( '.swp-servings' );
			if ( servings && baseServings ) {
				servings.textContent = formatAmount( baseServings * state.scale );
			}
			var out = card.querySelector( '.swp-tools__servings' );
			if ( out ) {
				out.textContent = formatAmount( baseServings * state.scale );
			}
			card.querySelectorAll( '[data-scale]' ).forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', String( parseFloat( b.getAttribute( 'data-scale' ) ) === state.scale ) );
			} );
			card.querySelectorAll( '[data-system]' ).forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', String( b.getAttribute( 'data-system' ) === state.system ) );
			} );
			emit( card, 'change', { scale: state.scale, system: state.system } );
		}

		/* Toolbar */
		var ingredients = card.querySelector( '.swp-card__ingredients' );
		if ( ingredients && items.length && ( has( 'scale' ) || ( has( 'units' ) && original ) ) ) {
			var bar = el( 'div', { class: 'swp-tools' } );

			if ( has( 'scale' ) ) {
				var scaleGroup = el( 'div', { class: 'swp-tools__group', role: 'group', 'aria-label': t( 'scale', 'Scale recipe' ) } );
				if ( baseServings ) {
					var minus = el( 'button', { type: 'button', class: 'swp-tool', 'aria-label': t( 'fewer', 'Fewer servings' ) }, '−' );
					var plus = el( 'button', { type: 'button', class: 'swp-tool', 'aria-label': t( 'more', 'More servings' ) }, '+' );
					var count = el( 'output', { class: 'swp-tools__servings', 'aria-live': 'polite' }, formatAmount( baseServings ) );
					minus.addEventListener( 'click', function () {
						var next = Math.max( 1, Math.round( baseServings * state.scale ) - 1 );
						state.scale = next / baseServings;
						render();
					} );
					plus.addEventListener( 'click', function () {
						var next = Math.round( baseServings * state.scale ) + 1;
						state.scale = next / baseServings;
						render();
					} );
					scaleGroup.appendChild( minus );
					scaleGroup.appendChild( count );
					scaleGroup.appendChild( plus );
				}
				[ 1, 2, 3 ].forEach( function ( m ) {
					var b = el( 'button', { type: 'button', class: 'swp-tool', 'data-scale': String( m ), 'aria-pressed': String( m === 1 ) }, m + '×' );
					b.addEventListener( 'click', function () {
						state.scale = m;
						render();
					} );
					scaleGroup.appendChild( b );
				} );
				bar.appendChild( scaleGroup );
			}

			if ( has( 'units' ) && original ) {
				var unitGroup = el( 'div', { class: 'swp-tools__group', role: 'group', 'aria-label': t( 'units', 'Units' ) } );
				[
					[ 'us', t( 'us', 'US' ) ],
					[ 'metric', t( 'metric', 'Metric' ) ],
				].forEach( function ( pair ) {
					var b = el( 'button', { type: 'button', class: 'swp-tool', 'data-system': pair[ 0 ], 'aria-pressed': String( pair[ 0 ] === original ) }, pair[ 1 ] );
					b.addEventListener( 'click', function () {
						state.system = pair[ 0 ];
						render();
					} );
					unitGroup.appendChild( b );
				} );
				bar.appendChild( unitGroup );
			}

			var heading = ingredients.querySelector( 'h3' );
			ingredients.insertBefore( bar, heading ? heading.nextSibling : ingredients.firstChild );
		}

		/* Tap to check off ingredients and steps */
		card.addEventListener( 'click', function ( e ) {
			var target = e.target.closest ? e.target.closest( '.swp-ingredient, .swp-step' ) : null;
			if ( ! target || e.target.closest( 'a, button' ) ) {
				return;
			}
			target.classList.toggle( 'is-done' );
		} );

		/* Cook mode: keep the screen awake */
		var actions = card.querySelector( '.swp-card__actions' );
		if ( has( 'cook' ) && 'wakeLock' in navigator ) {
			if ( ! actions ) {
				actions = el( 'div', { class: 'swp-card__actions' } );
				var heading2 = card.querySelector( '.swp-card__heading' );
				( heading2 || card ).appendChild( actions );
			}
			var lock = null;
			var cook = el( 'button', { type: 'button', class: 'swp-button swp-button--cook', 'aria-pressed': 'false' }, t( 'cook', 'Cook mode' ) );
			cook.title = t( 'cookHelp', 'Keeps your screen on while you cook' );
			var release = function () {
				if ( lock ) {
					lock.release();
					lock = null;
				}
				cook.setAttribute( 'aria-pressed', 'false' );
				card.classList.remove( 'is-cooking' );
			};
			cook.addEventListener( 'click', function () {
				if ( lock ) {
					release();
					return;
				}
				navigator.wakeLock.request( 'screen' ).then( function ( l ) {
					lock = l;
					cook.setAttribute( 'aria-pressed', 'true' );
					card.classList.add( 'is-cooking' );
					emit( card, 'cook', { on: true } );
					l.addEventListener( 'release', function () {
						lock = null;
						cook.setAttribute( 'aria-pressed', 'false' );
						card.classList.remove( 'is-cooking' );
						emit( card, 'cook', { on: false } );
					} );
				} ).catch( function () {} );
			} );
			document.addEventListener( 'visibilitychange', function () {
				if ( document.visibilityState === 'visible' && cook.getAttribute( 'aria-pressed' ) === 'true' && ! lock ) {
					cook.click();
				}
			} );
			actions.appendChild( cook );
		}

		/* Ratings */
		var rating = card.querySelector( '.swp-rating[data-recipe]' );
		if ( rating && config.rest ) {
			var id = rating.getAttribute( 'data-recipe' );
			var storeKey = 'swp-rated-' + id;
			var mine = 0;
			try {
				mine = parseInt( root.localStorage.getItem( storeKey ), 10 ) || 0;
			} catch ( err ) {}
			var status = rating.querySelector( '.swp-rating__text' );
			var buttons = rating.querySelectorAll( '.swp-rating__star' );
			var mark = function ( n ) {
				buttons.forEach( function ( b ) {
					b.classList.toggle( 'is-hover', parseInt( b.getAttribute( 'data-value' ), 10 ) <= n );
				} );
			};
			rating.classList.add( 'is-interactive' );
			if ( mine ) {
				rating.classList.add( 'is-rated' );
			}
			buttons.forEach( function ( b ) {
				var v = parseInt( b.getAttribute( 'data-value' ), 10 );
				b.disabled = false;
				b.addEventListener( 'mouseenter', function () {
					mark( v );
				} );
				b.addEventListener( 'focus', function () {
					mark( v );
				} );
				b.addEventListener( 'mouseleave', function () {
					mark( 0 );
				} );
				b.addEventListener( 'blur', function () {
					mark( 0 );
				} );
				b.addEventListener( 'click', function () {
					rating.classList.add( 'is-busy' );
					fetch( config.rest, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { recipe_id: parseInt( id, 10 ), rating: v } ),
						credentials: 'same-origin',
					} )
						.then( function ( r ) {
							return r.json().then( function ( body ) {
								if ( ! r.ok ) {
									throw body;
								}
								return body;
							} );
						} )
						.then( function ( body ) {
							rating.style.setProperty( '--swp-rating', String( body.average ) );
							if ( status ) {
								status.textContent = t( 'thanks', 'Thanks for rating!' ) + ' ' + body.average + ' (' + body.count + ')';
							}
							rating.classList.add( 'is-rated' );
							emit( card, 'rated', { rating: v, average: body.average, count: body.count } );
							try {
								root.localStorage.setItem( storeKey, String( v ) );
							} catch ( err ) {}
						} )
						.catch( function ( err ) {
							if ( status ) {
								status.textContent = ( err && err.message ) || t( 'error', 'Could not save your rating.' );
							}
						} )
						.then( function () {
							rating.classList.remove( 'is-busy' );
						} );
				} );
			} );
		}
	}

	function boot() {
		document.querySelectorAll( '.swp-card' ).forEach( function ( card ) {
			initCard( card );
			emit( card, 'ready', { recipeId: parseInt( card.getAttribute( 'data-recipe-id' ), 10 ) || 0 } );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )( typeof window !== 'undefined' ? window : globalThis );
