/**
 * Scrumptious card extras: photo gallery lightbox and share buttons.
 * Loaded only on pages whose recipe cards show a gallery or share buttons.
 * Works without it: gallery links open the photo, share links open the network.
 */
( function ( root ) {
	'use strict';

	var config = root.scrumptiousExtras || { i18n: {} };
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

	function emit( node, name, detail ) {
		if ( typeof root.CustomEvent === 'function' ) {
			node.dispatchEvent( new root.CustomEvent( 'scrumptious:' + name, { bubbles: true, detail: detail } ) );
		}
	}

	/* Gallery lightbox (native <dialog>; without JS the links open the full photo). */
	function initGallery( card ) {
		var links = Array.prototype.slice.call( card.querySelectorAll( '.swp-gallery__link' ) );
		if ( ! links.length || typeof root.HTMLDialogElement !== 'function' ) {
			return;
		}
		var box, img, caption, index = 0;
		function show( i ) {
			index = ( i + links.length ) % links.length;
			img.src = links[ index ].href;
			img.alt = ( links[ index ].querySelector( 'img' ) || {} ).alt || '';
			caption.textContent = links[ index ].getAttribute( 'data-caption' ) || ( index + 1 ) + ' / ' + links.length;
		}
		function build() {
			box = el( 'dialog', { class: 'swp-lightbox', 'aria-label': t( 'gallery', 'Gallery' ) } );
			img = el( 'img', { alt: '' } );
			caption = el( 'span', { class: 'swp-lightbox__caption' } );
			var bar = el( 'div', { class: 'swp-lightbox__bar' } );
			var prev = el( 'button', { type: 'button', 'aria-label': t( 'prev', 'Previous photo' ) }, '‹' );
			var next = el( 'button', { type: 'button', 'aria-label': t( 'next', 'Next photo' ) }, '›' );
			var close = el( 'button', { type: 'button', 'aria-label': t( 'close', 'Close' ) }, '×' );
			prev.addEventListener( 'click', function () {
				show( index - 1 );
			} );
			next.addEventListener( 'click', function () {
				show( index + 1 );
			} );
			close.addEventListener( 'click', function () {
				box.close();
			} );
			box.addEventListener( 'click', function ( e ) {
				if ( e.target === box ) {
					box.close();
				}
			} );
			box.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'ArrowLeft' ) {
					show( index - 1 );
				} else if ( e.key === 'ArrowRight' ) {
					show( index + 1 );
				}
			} );
			if ( links.length < 2 ) {
				prev.hidden = true;
				next.hidden = true;
			}
			bar.appendChild( prev );
			bar.appendChild( caption );
			bar.appendChild( next );
			bar.appendChild( close );
			box.appendChild( img );
			box.appendChild( bar );
			document.body.appendChild( box );
		}
		links.forEach( function ( a, i ) {
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( ! box ) {
					build();
				}
				show( i );
				box.showModal();
			} );
		} );
	}

	/* Share buttons: native share sheet where available, copy link, and a DOM event. */
	function initShare( card ) {
		var box = card.querySelector( '.swp-share' );
		if ( ! box ) {
			return;
		}
		var url = box.getAttribute( 'data-url' );
		var title = box.getAttribute( 'data-title' );
		var native = box.querySelector( '.swp-share__native' );
		if ( native && typeof navigator.share === 'function' ) {
			native.hidden = false;
			native.addEventListener( 'click', function () {
				navigator.share( { title: title, url: url } ).then( function () {
					emit( card, 'share', { network: 'native', url: url } );
				} ).catch( function () {} );
			} );
		}
		var copy = box.querySelector( '.swp-share__copy' );
		if ( copy ) {
			copy.hidden = false;
			copy.addEventListener( 'click', function () {
				var done = function () {
					var text = copy.querySelector( '.swp-share__text' ) || copy;
					var label = text.textContent;
					text.textContent = t( 'copied', 'Link copied!' );
					copy.classList.add( 'is-copied' );
					root.setTimeout( function () {
						text.textContent = label;
						copy.classList.remove( 'is-copied' );
					}, 2000 );
					emit( card, 'share', { network: 'copy', url: url } );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( url ).then( done ).catch( function () {
						root.prompt( t( 'copyPrompt', 'Copy this link:' ), url ); // eslint-disable-line no-alert
					} );
				} else {
					root.prompt( t( 'copyPrompt', 'Copy this link:' ), url ); // eslint-disable-line no-alert
				}
			} );
		}
		box.addEventListener( 'click', function ( e ) {
			var a = e.target.closest ? e.target.closest( 'a[data-network]' ) : null;
			if ( a ) {
				emit( card, 'share', { network: a.getAttribute( 'data-network' ), url: url } );
			}
		} );
	}

	function boot() {
		document.querySelectorAll( '.swp-card' ).forEach( function ( card ) {
			initGallery( card );
			initShare( card );
		} );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )( window );
