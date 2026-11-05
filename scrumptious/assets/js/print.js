/* Scrumptious print view: Print button and show/hide toggles. */
( function () {
	var root = document.getElementById( 'swp-print' );
	if ( ! root ) {
		return;
	}
	root.querySelectorAll( '[data-toggle]' ).forEach( function ( el ) {
		el.addEventListener( 'change', function () {
			root.classList.toggle( el.getAttribute( 'data-toggle' ), ! el.checked );
		} );
	} );
	var btn = root.querySelector( '[data-print]' );
	if ( btn ) {
		btn.addEventListener( 'click', function () {
			window.print();
		} );
	}
} )();
