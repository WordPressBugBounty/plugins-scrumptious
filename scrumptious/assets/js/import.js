/**
 * Recipes → Import: runs the import in small batches with a progress bar.
 */
( function () {
	var apiFetch = window.wp.apiFetch;
	var __ = window.wp.i18n.__;
	var sprintf = window.wp.i18n.sprintf;
	var _n = window.wp.i18n._n;

	function run( source, step, onBatch ) {
		var cursor = 0;
		function next() {
			return apiFetch( {
				path: '/scrumptious/v1/import/' + source + '/' + step,
				method: 'POST',
				data: { cursor: cursor },
			} ).then( function ( res ) {
				cursor = res.cursor;
				onBatch( res.results || [] );
				return res.done ? null : next();
			} );
		}
		return next();
	}

	// After an import that brought recipes in: one friendly review request (while it's still unanswered).
	var review = window.scrumptiousImportReview || false;
	function askForReview( card, imported ) {
		if ( ! review || imported < 1 || document.querySelector( '.swp-import-review' ) ) {
			return;
		}
		var box = document.createElement( 'div' );
		box.className = 'swp-import-review';
		var p = document.createElement( 'p' );
		p.textContent = sprintf(
			/* translators: %d: number of recipes imported */
			_n(
				'Imported %d recipe. If switching was easy, a quick review on WordPress.org helps other food bloggers find Scrumptious.',
				'Imported %d recipes. If switching was easy, a quick review on WordPress.org helps other food bloggers find Scrumptious.',
				imported,
				'scrumptious'
			),
			imported
		);
		var actions = document.createElement( 'p' );
		var go = document.createElement( 'a' );
		go.className = 'button button-primary';
		go.href = review.go;
		go.target = '_blank';
		go.rel = 'noopener';
		go.textContent = __( 'Leave a review', 'scrumptious' );
		go.addEventListener( 'click', function () {
			window.setTimeout( function () {
				box.remove();
			}, 300 );
		} );
		var no = document.createElement( 'a' );
		no.className = 'button-link';
		no.href = review.later;
		no.textContent = __( 'No thanks', 'scrumptious' );
		actions.appendChild( go );
		actions.appendChild( document.createTextNode( ' ' ) );
		actions.appendChild( no );
		box.appendChild( p );
		box.appendChild( actions );
		card.appendChild( box );
		review = false;
	}

	document.querySelectorAll( '.swp-import' ).forEach( function ( card ) {
		var source = card.getAttribute( 'data-source' );
		var total = parseInt( card.getAttribute( 'data-total' ), 10 ) || 1;
		var runBtn = card.querySelector( '.swp-import__run' );
		var undoBtn = card.querySelector( '.swp-import__undo' );
		var swap = card.querySelector( '.swp-import__swap' );
		var bar = card.querySelector( '.swp-import__progress' );
		var status = card.querySelector( '.swp-import__status' );
		var errors = card.querySelector( '.swp-import__errors' );

		function busy( on ) {
			runBtn.disabled = on;
			if ( undoBtn ) {
				undoBtn.disabled = on;
			}
			bar.style.display = on ? 'block' : bar.style.display;
		}
		function fail( e ) {
			busy( false );
			status.textContent = ( e && e.message ) || __( 'Something went wrong. Please try again.', 'scrumptious' );
		}
		function addError( text ) {
			var li = document.createElement( 'li' );
			li.textContent = text;
			errors.appendChild( li );
		}

		runBtn.addEventListener( 'click', function () {
			var done = 0;
			var counts = { imported: 0, skipped: 0, error: 0 };
			var posts = 0;
			errors.textContent = '';
			busy( true );
			bar.value = 0;
			status.textContent = __( 'Importing recipes…', 'scrumptious' );

			run( source, 'recipes', function ( results ) {
				results.forEach( function ( r ) {
					counts[ r.status ] = ( counts[ r.status ] || 0 ) + 1;
					if ( r.status === 'error' ) {
						addError( sprintf( '#%d: %s', r.source_id, r.message ) );
					}
				} );
				done += results.length;
				bar.value = Math.min( 100, Math.round( ( done / total ) * ( swap.checked ? 70 : 100 ) ) );
				status.textContent = sprintf( __( 'Importing recipes… %1$d of %2$d', 'scrumptious' ), done, total );
			} )
				.then( function () {
					if ( ! swap.checked ) {
						return null;
					}
					status.textContent = __( 'Updating posts…', 'scrumptious' );
					return run( source, 'posts', function ( results ) {
						results.forEach( function ( r ) {
							if ( r.status === 'updated' ) {
								posts++;
							}
						} );
						bar.value = Math.min( 99, bar.value + 3 );
					} );
				} )
				.then( function () {
					bar.value = 100;
					busy( false );
					status.textContent =
						sprintf(
							/* translators: 1: imported, 2: skipped, 3: errors */
							__( 'Done. %1$d imported, %2$d already imported, %3$d could not be read.', 'scrumptious' ),
							counts.imported,
							counts.skipped,
							counts.error
						) +
						' ' +
						sprintf( _n( '%d post updated.', '%d posts updated.', posts, 'scrumptious' ), posts );
					askForReview( card, counts.imported );
				} )
				.catch( fail );
		} );

		if ( undoBtn ) {
			undoBtn.addEventListener( 'click', function () {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( __( 'Put the original recipe cards back in your posts? Imported Scrumptious recipes are kept.', 'scrumptious' ) ) ) {
					return;
				}
				var restored = 0;
				busy( true );
				bar.style.display = 'none';
				status.textContent = __( 'Restoring posts…', 'scrumptious' );
				run( source, 'undo', function ( results ) {
					restored += results.length;
				} )
					.then( function () {
						busy( false );
						undoBtn.style.display = 'none';
						status.textContent = sprintf( _n( '%d post restored to its original content.', '%d posts restored to their original content.', restored, 'scrumptious' ), restored );
					} )
					.catch( fail );
			} );
		}
	} );
} )();
