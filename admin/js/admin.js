/**
 * AI SEO Filler — admin JavaScript.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.aiSeoFiller || {};
	var pollTimer = null;

	/**
	 * Sets status message CSS class and text on a container element.
	 *
	 * @param {jQuery} $el     Target element.
	 * @param {string} type    State class: loading, success, or error.
	 * @param {string} message Text to display.
	 */
	function setStatus( $el, type, message ) {
		$el
			.removeClass( 'is-loading is-success is-error' )
			.addClass( 'is-' + type )
			.text( message );
	}

	/**
	 * Whether the block editor data layer is available.
	 *
	 * @return {boolean}
	 */
	function canUseBlockEditor() {
		return Boolean(
			window.wp &&
			wp.data &&
			wp.data.select &&
			wp.data.dispatch
		);
	}

	/**
	 * Pushes saved SEO meta into the Gutenberg editor so Rank Math / Yoast UI updates.
	 *
	 * @param {Object} seoData    Full SEO payload from the server.
	 * @param {Object} editorMeta Meta keys ready for core/editor editPost().
	 * @return {boolean} True when the editor state was updated.
	 */
	function applySeoToBlockEditor( seoData, editorMeta ) {
		if ( ! canUseBlockEditor() ) {
			return false;
		}

		var select   = wp.data.select( 'core/editor' );
		var dispatch = wp.data.dispatch( 'core/editor' );

		if ( ! select || ! dispatch || ! dispatch.editPost ) {
			return false;
		}

		var edits = {};

		if ( editorMeta && Object.keys( editorMeta ).length ) {
			edits.meta = Object.assign( {}, select.getEditedPostAttribute( 'meta' ) || {}, editorMeta );
		}

		if ( seoData && seoData.slug ) {
			edits.slug = seoData.slug;
		}

		if ( seoData && seoData.optimized_content ) {
			edits.content = seoData.optimized_content;
		}

		if ( ! Object.keys( edits ).length ) {
			return false;
		}

		dispatch.editPost( edits );

		if ( window.wp.hooks && wp.hooks.doAction ) {
			wp.hooks.doAction( 'rank_math_data_changed' );
		}

		return true;
	}

	/**
	 * Single-item SEO generation from the post editor metabox.
	 */
	$( document ).on( 'click', '.ai-seo-filler-generate', function () {
		var $btn    = $( this );
		var postId  = $btn.data( 'post-id' );
		var $status = $btn.siblings( '.ai-seo-filler-status' );

		if ( ! postId ) {
			return;
		}

		$btn.prop( 'disabled', true );
		setStatus( $status, 'loading', cfg.i18n.generating );

		$.post( cfg.ajaxUrl, {
			action:  'ai_seo_filler_generate_single',
			nonce:   cfg.nonce,
			post_id: postId
		} )
			.done( function ( response ) {
				if ( response.success ) {
					var payload    = response.data || {};
					var seoData    = payload.data || {};
					var editorMeta = payload.editorMeta || null;
					var message    = payload.message || cfg.i18n.success;
					var synced     = applySeoToBlockEditor( seoData, editorMeta );

					if ( synced ) {
						message += ' ' + ( cfg.i18n.synced || '' );
					}

					setStatus( $status, 'success', message );
				} else {
					var msg = ( response.data && response.data.message ) ? response.data.message : cfg.i18n.error;
					setStatus( $status, 'error', msg );
				}
			} )
			.fail( function () {
				setStatus( $status, 'error', cfg.i18n.error );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	/**
	 * Updates the bulk progress UI from a status response object.
	 *
	 * @param {Object} data Status payload from the server.
	 */
	function updateBulkUI( data ) {
		var total     = data.total || 0;
		var processed = data.processed || 0;
		var percent   = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;

		$( '#ai-seo-filler-progress-bar' ).css( 'width', percent + '%' );
		$( '#ai-seo-filler-bulk-status-label' ).text( data.status ? data.status.charAt( 0 ).toUpperCase() + data.status.slice( 1 ) : 'Idle' );
		$( '#ai-seo-filler-bulk-total' ).text( total );
		$( '#ai-seo-filler-bulk-processed' ).text( processed );
		$( '#ai-seo-filler-bulk-pending' ).text( data.pending || 0 );
		$( '#ai-seo-filler-bulk-errors' ).text( data.errors || 0 );

		var $cancelBtn = $( '.ai-seo-filler-cancel-bulk' );
		$cancelBtn.prop( 'disabled', data.status === 'idle' || data.status === 'completed' );

		if ( data.error_log && data.error_log.length ) {
			var $log = $( '#ai-seo-filler-error-log' );
			if ( ! $log.length ) {
				$log = $( '<div class="ai-seo-filler-error-log" id="ai-seo-filler-error-log"><h3>Recent Errors</h3><ul></ul></div>' );
				$( '#ai-seo-filler-bulk-progress' ).append( $log );
			}
			var $ul = $log.find( 'ul' ).empty();
			data.error_log.forEach( function ( err ) {
				$ul.append( $( '<li></li>' ).text( 'Post #' + err.post_id + ': ' + err.message ) );
			} );
		}

		if ( data.status === 'completed' ) {
			$( '#ai-seo-filler-bulk-message' ).addClass( 'is-success' ).text( cfg.i18n.completed );
			stopPolling();
		}
	}

	/**
	 * Polls the server for bulk queue status.
	 */
	function pollBulkStatus() {
		$.post( cfg.ajaxUrl, {
			action: 'ai_seo_filler_bulk_status',
			nonce:  cfg.nonce
		} ).done( function ( response ) {
			if ( response.success ) {
				updateBulkUI( response.data );

				if ( response.data.status === 'processing' ) {
					startPolling();
				}
			}
		} );
	}

	function startPolling() {
		stopPolling();
		pollTimer = setInterval( pollBulkStatus, 5000 );
	}

	function stopPolling() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	/**
	 * Start bulk processing button handler.
	 */
	$( document ).on( 'click', '.ai-seo-filler-start-bulk', function () {
		if ( ! window.confirm( cfg.i18n.confirmBulk ) ) {
			return;
		}

		var $btn    = $( this );
		var $msg    = $( '#ai-seo-filler-bulk-message' );
		var postType   = $( '#ai_seo_filler_bulk_post_type' ).val();
		var postStatus = $( '#ai_seo_filler_bulk_post_status' ).val();

		$btn.prop( 'disabled', true );
		$msg.removeClass( 'is-success is-error' ).addClass( 'is-loading' ).text( cfg.i18n.processing );

		$.post( cfg.ajaxUrl, {
			action:      'ai_seo_filler_start_bulk',
			nonce:       cfg.nonce,
			post_type:   postType,
			post_status: postStatus
		} )
			.done( function ( response ) {
				if ( response.success ) {
					$msg.removeClass( 'is-loading' ).addClass( 'is-success' ).text( response.data.message );
					updateBulkUI( {
						status:    'processing',
						total:     response.data.total,
						processed: 0,
						pending:   response.data.total,
						errors:    0
					} );
					startPolling();
				} else {
					var msg = ( response.data && response.data.message ) ? response.data.message : cfg.i18n.error;
					$msg.removeClass( 'is-loading' ).addClass( 'is-error' ).text( msg );
				}
			} )
			.fail( function () {
				$msg.removeClass( 'is-loading' ).addClass( 'is-error' ).text( cfg.i18n.error );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	/**
	 * Cancel bulk queue button handler.
	 */
	$( document ).on( 'click', '.ai-seo-filler-cancel-bulk', function () {
		if ( ! window.confirm( cfg.i18n.confirmCancel ) ) {
			return;
		}

		$.post( cfg.ajaxUrl, {
			action: 'ai_seo_filler_cancel_bulk',
			nonce:  cfg.nonce
		} ).done( function ( response ) {
			stopPolling();
			updateBulkUI( {
				status:    'idle',
				total:     0,
				processed: 0,
				pending:   0,
				errors:    0
			} );
			$( '#ai-seo-filler-bulk-message' ).removeClass( 'is-loading is-error' ).addClass( 'is-success' ).text(
				response.success ? ( response.data.message || cfg.i18n.cancelled ) : cfg.i18n.error
			);
		} );
	} );

	// Resume polling when the bulk page loads with an active queue.
	$( function () {
		var initial = window.aiSeoFillerBulk;
		if ( initial && initial.status === 'processing' ) {
			startPolling();
		}

		initProviderPanels();
	} );

	/**
	 * Shows/hides Gemini and Groq settings panels based on provider selection.
	 */
	function initProviderPanels() {
		var $select  = $( '#ai_seo_filler_ai_provider' );
		var $panels  = $( '.ai-seo-filler-provider-panel' );

		if ( ! $select.length || ! $panels.length ) {
			return;
		}

		function togglePanels() {
			var provider = $select.val();

			$panels.each( function () {
				var $panel = $( this );
				$panel.toggleClass( 'is-hidden', $panel.data( 'provider' ) !== provider );
			} );
		}

		$select.on( 'change', togglePanels );
		togglePanels();
	}

}( jQuery ) );
