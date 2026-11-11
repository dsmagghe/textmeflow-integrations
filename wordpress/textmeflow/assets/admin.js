/**
 * TextMeFlow admin: connection status, WhatsApp QR pairing, test message.
 * Talks only to this site's admin-ajax.php; the TextMeFlow token never reaches the browser.
 */
( function () {
	'use strict';

	var cfg = window.textmeflowAdmin;
	if ( ! cfg || ! cfg.connected ) {
		return;
	}
	var t = cfg.i18n;
	var POLL_MS = 3000;
	var MAX_RENEWALS = 3;

	var el = {
		status: document.getElementById( 'tmf-status' ),
		qrBox: document.getElementById( 'tmf-qr' ),
		qrImg: document.getElementById( 'tmf-qr-img' ),
		qrMsg: document.getElementById( 'tmf-qr-msg' ),
		newCode: document.getElementById( 'tmf-new-code' ),
		upgrade: document.getElementById( 'tmf-upgrade' ),
		test: document.getElementById( 'tmf-test' ),
		testResult: document.getElementById( 'tmf-test-result' ),
		phone: document.getElementById( 'tmf-owner-phone' )
	};

	var timer = null;
	var renewals = 0;

	/**
	 * POST to admin-ajax.php and resolve with the parsed JSON.
	 */
	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_wpnonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } )
			.catch( function () { return { success: false, data: { message: t.genericError } }; } );
	}

	function stopPolling() {
		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}
	}

	function showQr( visible ) {
		el.qrBox.hidden = ! visible;
	}

	function showError( data ) {
		stopPolling();
		el.status.textContent = ( data && data.message ) || t.genericError;
		el.status.className = 'tmf-status tmf-status-error';
		if ( data && data.code === 'device_limit' && data.upgrade_url ) {
			showQr( true );
			el.qrImg.hidden = true;
			el.qrMsg.textContent = data.message;
			el.upgrade.href = data.upgrade_url;
			el.upgrade.hidden = false;
		}
	}

	function loadStatus() {
		stopPolling();
		el.status.textContent = t.checking;
		el.status.className = 'tmf-status';
		post( 'textmeflow_status' ).then( function ( res ) {
			if ( ! res.success ) {
				showError( res.data );
				return;
			}
			if ( res.data.linked ) {
				el.status.textContent = res.data.phone ? t.connectedTo.replace( '%s', res.data.phone ) : t.connectedNo;
				el.status.className = 'tmf-status tmf-status-ok';
				showQr( false );
			} else {
				el.status.textContent = t.notLinked;
				renewals = 0;
				startPair();
			}
		} );
	}

	function renderPair( data ) {
		showQr( true );
		el.upgrade.hidden = true;
		el.newCode.hidden = true;
		if ( data.qr ) {
			el.qrImg.src = data.qr;
			el.qrImg.hidden = false;
			el.qrMsg.textContent = t.scanQr;
		} else {
			el.qrImg.hidden = true;
			el.qrMsg.textContent = t.preparing;
		}
	}

	function startPair() {
		stopPolling();
		el.newCode.hidden = true;
		el.qrMsg.textContent = t.preparing;
		showQr( true );
		post( 'textmeflow_pair_start' ).then( function ( res ) {
			if ( ! res.success ) {
				showError( res.data );
				return;
			}
			renderPair( res.data );
			schedulePoll( res.data.pair_id );
		} );
	}

	function schedulePoll( pairId ) {
		timer = window.setTimeout( function () { poll( pairId ); }, POLL_MS );
	}

	function poll( pairId ) {
		post( 'textmeflow_pair_poll', { pair_id: pairId } ).then( function ( res ) {
			if ( ! res.success ) {
				showError( res.data );
				return;
			}
			var status = res.data.status;
			if ( status === 'connected' ) {
				loadStatus();
			} else if ( status === 'expired' ) {
				if ( renewals < MAX_RENEWALS ) {
					renewals += 1;
					startPair();
				} else {
					el.qrImg.hidden = true;
					el.qrMsg.textContent = t.expired;
					el.newCode.hidden = false;
				}
			} else {
				renderPair( res.data );
				schedulePoll( pairId );
			}
		} );
	}

	if ( el.newCode ) {
		el.newCode.addEventListener( 'click', function () {
			renewals = 0;
			startPair();
		} );
	}

	if ( el.test ) {
		el.test.addEventListener( 'click', function () {
			el.testResult.textContent = t.sending;
			el.testResult.className = '';
			el.test.disabled = true;
			post( 'textmeflow_test', { phone: el.phone ? el.phone.value : '' } ).then( function ( res ) {
				el.test.disabled = false;
				if ( res.success ) {
					el.testResult.textContent = t.testOk;
					el.testResult.className = 'tmf-ok';
				} else {
					el.testResult.textContent = ( res.data && res.data.message ) || t.genericError;
					el.testResult.className = 'tmf-error';
				}
			} );
		} );
	}

	loadStatus();
}() );
