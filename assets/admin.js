/**
 * SCORR Maintenance Check — admin screen behavior.
 *
 * Drives the batched SEO scan over admin-ajax. The scan runs ONLY when the
 * "Run SEO Scan" button is clicked — generating a report never triggers a
 * scan; the report simply includes the results of a scan run since the
 * last report (or omits the SEO section when there isn't one).
 */
( function () {
	'use strict';

	var cfg = window.scorrMC || {};

	function progressEls( id ) {
		var box = document.getElementById( id );
		return box
			? { box: box, bar: box.querySelector( '.scorr-mc-progress-bar' ), text: box.querySelector( '.scorr-mc-progress-text' ) }
			: null;
	}

	function setProgress( els, scanned, total, missing ) {
		if ( ! els ) {
			return;
		}
		els.box.hidden = false;
		var pct = total > 0 ? Math.round( ( scanned / total ) * 100 ) : 100;
		els.bar.style.width = pct + '%';
		els.text.textContent = 'Scanning ' + scanned + ' of ' + total + ' pages — ' + missing + ' missing so far';
	}

	function runScan( els ) {
		setProgress( els, 0, cfg.total || 0, 0 );

		var step = function ( offset ) {
			var body = new URLSearchParams();
			body.append( 'action', 'scorr_mc_seo_scan' );
			body.append( 'nonce', cfg.nonce );
			body.append( 'offset', String( offset ) );

			return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( ( json && json.data && json.data.message ) || 'Scan request failed.' );
					}
					var d = json.data;
					setProgress( els, d.offset, d.total, d.missing );
					if ( d.done ) {
						return d;
					}
					return step( d.offset );
				} );
		};

		return step( 0 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var scanBtn = document.getElementById( 'scorr-mc-scan' );
		if ( scanBtn ) {
			scanBtn.addEventListener( 'click', function () {
				var els = progressEls( 'scorr-mc-scan-progress' );
				scanBtn.disabled = true;
				runScan( els )
					.then( function () {
						els.text.textContent = 'Scan complete — reloading…';
						window.location.reload();
					} )
					.catch( function ( err ) {
						els.text.textContent = 'Scan failed: ' + err.message;
						scanBtn.disabled = false;
					} );
			} );
		}
	} );
}() );
