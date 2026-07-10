/**
 * AI SEO Filler — admin JavaScript.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.aiSeoFiller || {};
	var pollTimer = null;
	var previewState = null;
	var imageSelectState = null;
	var alertTimers = [];
	var loaderState = { count: 0, $overlay: null };

	function ensureGlobalLoader() {
		if ( loaderState.$overlay && loaderState.$overlay.length ) {
			return loaderState.$overlay;
		}

		var $overlay = $( '<div id="ai-seo-filler-loader" class="ai-seo-filler-loader" aria-hidden="true" role="status" aria-live="polite">' +
			'<div class="ai-seo-filler-loader__backdrop"></div>' +
			'<div class="ai-seo-filler-loader__panel">' +
				'<div class="ai-seo-filler-loader__spinner" aria-hidden="true"></div>' +
				'<p class="ai-seo-filler-loader__message"></p>' +
			'</div>' +
		'</div>' );

		$( 'body' ).append( $overlay );
		loaderState.$overlay = $overlay;

		return $overlay;
	}

	function showGlobalLoader( message ) {
		var $overlay = ensureGlobalLoader();

		loaderState.count++;

		$overlay
			.find( '.ai-seo-filler-loader__message' )
			.text( message || ( cfg.i18n && cfg.i18n.loading ) || 'Loading…' );

		$overlay.attr( 'aria-hidden', 'false' ).addClass( 'is-visible' );
		$( 'body' ).addClass( 'ai-seo-filler-is-loading' );
	}

	function hideGlobalLoader() {
		loaderState.count = Math.max( 0, loaderState.count - 1 );

		if ( loaderState.count > 0 || ! loaderState.$overlay ) {
			return;
		}

		loaderState.$overlay.attr( 'aria-hidden', 'true' ).removeClass( 'is-visible' );
		$( 'body' ).removeClass( 'ai-seo-filler-is-loading' );
	}

	function getLoaderMessageForAction( action ) {
		var map = {
			ai_seo_filler_generate_single: cfg.i18n.generating,
			ai_seo_filler_apply:           cfg.i18n.applying,
			ai_seo_filler_generate_images: cfg.i18n.generatingImages,
			ai_seo_filler_apply_images:    cfg.i18n.imagesApplying,
			ai_seo_filler_test_api:        cfg.i18n.testing,
			ai_seo_filler_start_bulk:      cfg.i18n.processing,
			ai_seo_filler_pause_bulk:      cfg.i18n.pausing,
			ai_seo_filler_resume_bulk:     cfg.i18n.resuming,
			ai_seo_filler_cancel_bulk:    cfg.i18n.cancelling
		};

		return map[ action ] || cfg.i18n.loading || 'Loading…';
	}

	/**
	 * Central AJAX helper — shows global loader until the request completes.
	 *
	 * @param {Object} data    POST body (must include action).
	 * @param {Object} options loaderMessage, showLoader (default true).
	 * @return {jqXHR}
	 */
	function aiSeoPost( data, options ) {
		options = options || {};
		var showLoader = false !== options.showLoader;
		var message    = options.loaderMessage || getLoaderMessageForAction( data.action );

		if ( showLoader ) {
			showGlobalLoader( message );
		}

		return $.post( cfg.ajaxUrl, data ).always( function () {
			if ( showLoader ) {
				hideGlobalLoader();
			}
		} );
	}

	function renderInlineLoader( message ) {
		return '<span class="ai-seo-filler-inline-loader" aria-hidden="true"></span> ' + escapeHtml( message );
	}

	function escapeHtml( text ) {
		return String( text )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function ensureAlertContainer() {
		if ( ! $( '#ai-seo-filler-alerts' ).length ) {
			$( 'body' ).append( '<div id="ai-seo-filler-alerts" class="ai-seo-filler-alerts" aria-live="assertive" aria-atomic="true"></div>' );
		}
		return $( '#ai-seo-filler-alerts' );
	}

	/**
	 * Shows a prominent toast alert (success, error, or info).
	 *
	 * @param {string} type    success | error | info
	 * @param {string} message Message body.
	 * @param {Object} options Optional settings.
	 */
	function showAlert( type, message, options ) {
		options = options || {};

		if ( ! message ) {
			return;
		}

		var $container = ensureAlertContainer();
		var titles = {
			success: cfg.i18n.alertSuccess || 'Success',
			error:   cfg.i18n.alertError || 'Error',
			info:    cfg.i18n.alertInfo || 'AI SEO Filler'
		};
		var icons = {
			success: '✓',
			error:   '✕',
			info:    'ℹ'
		};
		var duration = options.duration;

		if ( typeof duration === 'undefined' ) {
			duration = 'error' === type ? 0 : 8000;
		}

		var $alert = $( '<div class="ai-seo-filler-alert ai-seo-filler-alert--' + type + '" role="alert">' +
			'<div class="ai-seo-filler-alert__icon" aria-hidden="true">' + icons[ type ] + '</div>' +
			'<div class="ai-seo-filler-alert__body">' +
				'<strong class="ai-seo-filler-alert__title">' + escapeHtml( titles[ type ] || titles.info ) + '</strong>' +
				'<p class="ai-seo-filler-alert__message">' + escapeHtml( message ) + '</p>' +
			'</div>' +
			'<button type="button" class="ai-seo-filler-alert__close" aria-label="' + escapeHtml( cfg.i18n.close || 'Close' ) + '">×</button>' +
		'</div>' );

		$container.prepend( $alert );

		requestAnimationFrame( function () {
			$alert.addClass( 'is-visible' );
		} );

		$alert.find( '.ai-seo-filler-alert__close' ).on( 'click', function () {
			hideAlert( $alert );
		} );

		if ( duration > 0 ) {
			var timer = setTimeout( function () {
				hideAlert( $alert );
			}, duration );
			alertTimers.push( timer );
		}
	}

	function hideAlert( $alert ) {
		$alert.removeClass( 'is-visible' );
		setTimeout( function () {
			$alert.remove();
		}, 300 );
	}

	/**
	 * Extracts error message from a jQuery AJAX failure.
	 *
	 * @param {Object} jqXHR    XHR object.
	 * @param {string} fallback Fallback message.
	 * @return {string}
	 */
	function getAjaxErrorMessage( jqXHR, fallback ) {
		if ( jqXHR && jqXHR.responseJSON ) {
			if ( jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
				return jqXHR.responseJSON.data.message;
			}
			if ( jqXHR.responseJSON.message ) {
				return jqXHR.responseJSON.message;
			}
		}

		return fallback || cfg.i18n.error;
	}

	function notifyError( message, $status ) {
		if ( $status ) {
			setStatus( $status, 'error', message );
		}
		showAlert( 'error', message );
	}

	function notifySuccess( message, $status ) {
		if ( $status ) {
			setStatus( $status, 'success', message );
		}
		showAlert( 'success', message );
	}

	function setStatus( $el, type, message ) {
		if ( ! $el || ! $el.length ) {
			return;
		}

		$el.removeClass( 'is-loading is-success is-error' ).addClass( 'is-' + type );

		if ( 'loading' === type ) {
			$el.html( renderInlineLoader( message ) );
			return;
		}

		$el.text( message );
	}

	function canUseBlockEditor() {
		return Boolean( window.wp && wp.data && wp.data.select && wp.data.dispatch );
	}

	function triggerInputEvents( $el ) {
		$el.trigger( 'input change keyup' );

		var el = $el.get( 0 );

		if ( ! el ) {
			return;
		}

		try {
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} catch ( e ) {
			// IE11 fallback omitted — WP admin targets modern browsers.
		}
	}

	function setNativeInputValue( el, value ) {
		if ( ! el ) {
			return;
		}

		var tag = el.tagName ? el.tagName.toLowerCase() : '';

		if ( 'input' === tag ) {
			var inputSetter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' );

			if ( inputSetter && inputSetter.set ) {
				inputSetter.set.call( el, value );
				return;
			}
		}

		if ( 'textarea' === tag ) {
			var areaSetter = Object.getOwnPropertyDescriptor( window.HTMLTextAreaElement.prototype, 'value' );

			if ( areaSetter && areaSetter.set ) {
				areaSetter.set.call( el, value );
				return;
			}
		}

		el.value = value;
	}

	function setFieldBySelectors( selectors, value ) {
		if ( ! value && value !== 0 ) {
			return false;
		}

		var updated = false;

		selectors.some( function ( selector ) {
			var $field = $( selector ).first();

			if ( ! $field.length ) {
				return false;
			}

			if ( $field.is( '[contenteditable="true"]' ) ) {
				$field.text( value );
			} else if ( $field.is( 'input, textarea' ) ) {
				setNativeInputValue( $field.get( 0 ), value );
			} else {
				$field.val( value );
			}

			triggerInputEvents( $field );
			updated = true;
			return true;
		} );

		return updated;
	}

	function setEditorHtml( editorId, html ) {
		if ( ! html ) {
			return false;
		}

		var updated = false;
		var $textarea = $( '#' + editorId );

		if ( $textarea.length ) {
			$textarea.val( html );
			triggerInputEvents( $textarea );
			updated = true;
		}

		if ( typeof window.tinymce !== 'undefined' ) {
			window.tinymce.editors.forEach( function ( editor ) {
				if ( editor.id === editorId || editor.targetElm === $textarea.get( 0 ) ) {
					editor.setContent( html );
					editor.fire( 'change' );
					updated = true;
				}
			} );
		}

		return updated;
	}

	var RANK_MATH_FIELD_SELECTORS = {
		rank_math_title: [
			'input[name="rank_math_title"]',
			'#rank_math_title',
			'#rank-math-title',
			'[data-field="title"] input',
			'.rank-math-title input'
		],
		rank_math_description: [
			'textarea[name="rank_math_description"]',
			'#rank_math_description',
			'#rank-math-description',
			'[data-field="description"] textarea',
			'.rank-math-description textarea'
		],
		rank_math_focus_keyword: [
			'input[name="rank_math_focus_keyword"]',
			'#rank_math_focus_keyword',
			'#rank-math-focus-keyword',
			'[data-field="focusKeyword"] input',
			'.rank-math-focus-keyword input'
		],
		rank_math_facebook_title: [
			'input[name="rank_math_facebook_title"]',
			'#rank_math_facebook_title'
		],
		rank_math_facebook_description: [
			'textarea[name="rank_math_facebook_description"]',
			'#rank_math_facebook_description'
		],
		rank_math_twitter_title: [
			'input[name="rank_math_twitter_title"]',
			'#rank_math_twitter_title'
		],
		rank_math_twitter_description: [
			'textarea[name="rank_math_twitter_description"]',
			'#rank_math_twitter_description'
		]
	};

	function applySeoToRankMathClassic( editorMeta ) {
		if ( ! editorMeta || ! Object.keys( editorMeta ).length ) {
			return false;
		}

		var synced = false;

		Object.keys( editorMeta ).forEach( function ( metaKey ) {
			var selectors = RANK_MATH_FIELD_SELECTORS[ metaKey ];

			if ( selectors && setFieldBySelectors( selectors, editorMeta[ metaKey ] ) ) {
				synced = true;
			}
		} );

		// Rank Math React sidebar: search within known wrappers.
		if ( ! synced ) {
			var $wrap = $( '#rank-math-metabox, #rank-math-sidebar, .rank-math-editor, .rank-math-metabox-wrap' );

			if ( $wrap.length && editorMeta.rank_math_title ) {
				var $title = $wrap.find( 'input' ).filter( '[name*="title"], [id*="title"]' ).first();
				var $desc  = $wrap.find( 'textarea' ).filter( '[name*="description"], [id*="description"]' ).first();
				var $kw    = $wrap.find( 'input' ).filter( '[name*="focus"], [id*="focus"], [name*="keyword"]' ).first();

				if ( $title.length && setFieldBySelectors( [ '#' + $title.attr( 'id' ) ], editorMeta.rank_math_title ) ) {
					synced = true;
				}

				if ( editorMeta.rank_math_description && $desc.length ) {
					setNativeInputValue( $desc.get( 0 ), editorMeta.rank_math_description );
					triggerInputEvents( $desc );
					synced = true;
				}

				if ( editorMeta.rank_math_focus_keyword && $kw.length ) {
					setNativeInputValue( $kw.get( 0 ), editorMeta.rank_math_focus_keyword );
					triggerInputEvents( $kw );
					synced = true;
				}
			}
		}

		if ( synced && window.wp && wp.hooks && wp.hooks.doAction ) {
			wp.hooks.doAction( 'rank_math_data_changed' );
		}

		return synced;
	}

	function getRankMathDispatch() {
		if ( ! window.wp || ! wp.data || ! wp.data.dispatch ) {
			return null;
		}

		try {
			return wp.data.dispatch( 'rank-math' );
		} catch ( e ) {
			return null;
		}
	}

	function refreshRankMathEditor() {
		if ( window.rankMathEditor && typeof window.rankMathEditor.refresh === 'function' ) {
			window.rankMathEditor.refresh( 'title' );
			window.rankMathEditor.refresh( 'description' );
			window.rankMathEditor.refresh( 'keyword' );
			window.rankMathEditor.refresh( 'content' );
		}
	}

	var rankMathPreviewActive = false;
	var rankMathSnapshot      = null;

	function canUseRankMathAnalyzer() {
		return 'rankmath' === cfg.seoPlugin && !! getRankMathDispatch() && window.wp && wp.hooks;
	}

	function getRankMathSelect() {
		if ( ! window.wp || ! wp.data || ! wp.data.select ) {
			return null;
		}

		try {
			return wp.data.select( 'rank-math' );
		} catch ( e ) {
			return null;
		}
	}

	function buildEditorMetaFromData( seoData, focusKeyword ) {
		var meta = {};

		if ( ! seoData ) {
			return meta;
		}

		if ( seoData.meta_title ) {
			meta.rank_math_title = seoData.meta_title;
		}

		if ( seoData.meta_description ) {
			meta.rank_math_description = seoData.meta_description;
		}

		var keyword = focusKeyword || seoData.focus_keyword;

		if ( keyword ) {
			meta.rank_math_focus_keyword = keyword;
		}

		if ( seoData.og_title ) {
			meta.rank_math_facebook_title = seoData.og_title;
			meta.rank_math_twitter_title  = seoData.og_title;
		}

		if ( seoData.og_description ) {
			meta.rank_math_facebook_description = seoData.og_description;
			meta.rank_math_twitter_description  = seoData.og_description;
		}

		return meta;
	}

	function snapshotRankMathState() {
		var select = getRankMathSelect();

		if ( ! select ) {
			return null;
		}

		var snapshot = {};

		if ( typeof select.getTitle === 'function' ) {
			snapshot.title = select.getTitle();
		}

		if ( typeof select.getDescription === 'function' ) {
			snapshot.description = select.getDescription();
		}

		if ( typeof select.getKeywords === 'function' ) {
			snapshot.keywords = select.getKeywords();
		}

		if ( typeof select.getSerpSlug === 'function' ) {
			snapshot.slug = select.getSerpSlug();
		}

		return snapshot;
	}

	function restoreRankMathState( snapshot ) {
		var dispatch = getRankMathDispatch();

		if ( ! dispatch || ! snapshot ) {
			return;
		}

		if ( snapshot.title && typeof dispatch.updateTitle === 'function' ) {
			dispatch.updateTitle( snapshot.title );
			dispatch.updateSerpTitle( snapshot.title );
		}

		if ( snapshot.description && typeof dispatch.updateDescription === 'function' ) {
			dispatch.updateDescription( snapshot.description );
			dispatch.updateSerpDescription( snapshot.description );
		}

		if ( snapshot.keywords && typeof dispatch.updateKeywords === 'function' ) {
			dispatch.updateKeywords( snapshot.keywords );
			applySeoToRankMathTagify( snapshot.keywords );
		}

		if ( snapshot.slug && typeof dispatch.updateSerpSlug === 'function' ) {
			dispatch.updateSerpSlug( snapshot.slug );
		}

		if ( typeof dispatch.refreshResults === 'function' ) {
			dispatch.refreshResults();
		}

		refreshRankMathEditor();
	}

	function registerRankMathPreviewHooks( seoData ) {
		unregisterRankMathPreviewHooks();

		if ( ! window.wp || ! wp.hooks || ! seoData ) {
			return;
		}

		rankMathPreviewActive = true;

		wp.hooks.addFilter( 'rank_math_content', 'ai-seo-filler-preview', function ( content ) {
			return seoData.optimized_content || content;
		} );

		wp.hooks.addFilter( 'rank_math_title', 'ai-seo-filler-preview', function ( title ) {
			return seoData.meta_title || title;
		} );

		wp.hooks.addFilter( 'rank_math_dataCollector_data', 'ai-seo-filler-preview', function ( data ) {
			data = data || {};

			if ( seoData.slug ) {
				data.slug = seoData.slug;
			}

			if ( seoData.meta_description ) {
				data.description = seoData.meta_description;
			}

			if ( seoData.short_description ) {
				data.excerpt = seoData.short_description;
			}

			return data;
		} );
	}

	function unregisterRankMathPreviewHooks() {
		if ( ! window.wp || ! wp.hooks ) {
			rankMathPreviewActive = false;
			return;
		}

		wp.hooks.removeFilter( 'rank_math_content', 'ai-seo-filler-preview' );
		wp.hooks.removeFilter( 'rank_math_title', 'ai-seo-filler-preview' );
		wp.hooks.removeFilter( 'rank_math_dataCollector_data', 'ai-seo-filler-preview' );
		rankMathPreviewActive = false;
	}

	function gatherRankMathChecklistTests() {
		var tests     = [];
		var selectors = '#rank_math_metabox .rank-math-checklist li, #rank-math-metabox .rank-math-checklist li, .rank-math-editor-general .rank-math-checklist li';

		$( selectors ).each( function () {
			var $li   = $( this );
			var pass  = $li.hasClass( 'test-ok' ) || $li.hasClass( 'test-check-ok' );
			var fail  = $li.hasClass( 'test-fail' );
			var label = $li.find( '.rank-math-test-text' ).first().text() ||
				$li.find( 'span span' ).first().text() ||
				$.trim( $li.text() );

			if ( label ) {
				tests.push( {
					label: label.trim(),
					pass:  pass && ! fail
				} );
			}
		} );

		return tests;
	}

	function readRankMathScoreFromDom() {
		var $badge = $( '#rank_math_metabox .rank-math-score, #rank-math-metabox .rank-math-score, .rank-math-seo-score, .rank-math-result-score' );

		if ( ! $badge.length ) {
			return null;
		}

		var match = $badge.first().text().match( /(\d+)/ );

		return match ? parseInt( match[1], 10 ) : null;
	}

	function runRankMathLiveScore( seoData, editorMeta, options ) {
		options = options || {};

		return new Promise( function ( resolve ) {
			if ( ! canUseRankMathAnalyzer() || ! seoData ) {
				resolve( null );
				return;
			}

			var previewData = Object.assign( {}, seoData );

			if ( options.focusKeyword ) {
				previewData.focus_keyword = options.focusKeyword;
			}

			function startAnalysis() {
				registerRankMathPreviewHooks( previewData );

				var meta = editorMeta || buildEditorMetaFromData( previewData, options.focusKeyword );

				if ( options.focusKeyword ) {
					meta = Object.assign( {}, meta, { rank_math_focus_keyword: options.focusKeyword } );
				}

				applySeoToRankMathStore( meta, previewData );

				var dispatch = getRankMathDispatch();

				if ( dispatch && typeof dispatch.refreshResults === 'function' ) {
					dispatch.refreshResults();
				}

				refreshRankMathEditor();

				var attempts    = 0;
				var maxAttempts = 24;

				function poll() {
					attempts++;

					var select = getRankMathSelect();
					var score  = null;

					if ( select && typeof select.getAnalysisScore === 'function' ) {
						score = select.getAnalysisScore();
					}

					if ( ( null === score || undefined === score ) && attempts >= 3 ) {
						score = readRankMathScoreFromDom();
					}

					var tests = gatherRankMathChecklistTests();

					if ( typeof score === 'number' && score >= 0 && ( tests.length > 0 || attempts >= maxAttempts ) ) {
						unregisterRankMathPreviewHooks();
						resolve( {
							score:    score,
							tests:    tests,
							passed:   tests.filter( function ( test ) { return test.pass; } ).length,
							total:    tests.length,
							estimate: false
						} );
						return;
					}

					if ( attempts >= maxAttempts ) {
						unregisterRankMathPreviewHooks();

						if ( typeof score === 'number' && score >= 0 ) {
							resolve( {
								score:    score,
								tests:    tests,
								passed:   tests.filter( function ( test ) { return test.pass; } ).length,
								total:    tests.length,
								estimate: false
							} );
						} else {
							resolve( null );
						}

						return;
					}

					setTimeout( poll, 150 );
				}

				setTimeout( poll, 120 );
			}

			var startDelay = options.afterApply ? 650 : 120;
			setTimeout( startAnalysis, startDelay );
		} );
	}

	function updateChecklistUi( $container, checklist, options ) {
		options = options || {};

		if ( ! $container || ! $container.length ) {
			return;
		}

		var $modal = $container.closest( '#ai-seo-filler-modal' );

		if ( options.calculating ) {
			renderChecklist( $container, checklist || { tests: [], score: 0, estimate: true }, { calculating: true } );

			if ( $modal.length ) {
				$modal.find( '.ai-seo-filler-modal__score' )
					.html( renderInlineLoader( cfg.i18n.scoreCalculating || 'Calculating…' ) )
					.show();
			}

			return;
		}

		if ( ! checklist || ! checklist.tests ) {
			$container.empty();
			return;
		}

		renderChecklist( $container, checklist );

		if ( $modal.length && typeof checklist.score === 'number' ) {
			$modal.find( '.ai-seo-filler-modal__score' ).text( checklist.score + '/100' ).show();
		}
	}

	function refreshRankMathChecklist( seoData, editorMeta, options ) {
		var $container = options.$container;

		if ( ! $container || ! $container.length ) {
			return;
		}

		if ( ! canUseRankMathAnalyzer() ) {
			if ( options.fallbackChecklist ) {
				renderChecklist( $container, options.fallbackChecklist );
			}
			return;
		}

		updateChecklistUi( $container, options.fallbackChecklist, { calculating: true } );

		runRankMathLiveScore( seoData, editorMeta, options ).then( function ( liveChecklist ) {
			if ( liveChecklist ) {
				updateChecklistUi( $container, liveChecklist );
			} else if ( options.fallbackChecklist ) {
				updateChecklistUi( $container, options.fallbackChecklist );
			}
		} );
	}

	function applySeoToRankMathTagify( keyword ) {
		if ( ! keyword ) {
			return false;
		}

		var tagsEl = document.querySelector( '.rank-math-focus-keyword tags.tagify' );

		if ( ! tagsEl || ! tagsEl.tagify ) {
			return false;
		}

		tagsEl.tagify.removeAllTags();
		keyword.split( ',' ).forEach( function ( part ) {
			part = part.trim();
			if ( part ) {
				tagsEl.tagify.addTags( part );
			}
		} );

		return true;
	}

	function applySeoToRankMathStore( editorMeta, seoData ) {
		var dispatch = getRankMathDispatch();

		if ( ! dispatch || ! editorMeta ) {
			return false;
		}

		var synced  = false;
		var title   = editorMeta.rank_math_title || '';
		var desc    = editorMeta.rank_math_description || '';
		var keyword = editorMeta.rank_math_focus_keyword || ( seoData && seoData.focus_keyword ) || '';

		if ( title && typeof dispatch.updateTitle === 'function' ) {
			dispatch.updateTitle( title );
			synced = true;
		}

		if ( title && typeof dispatch.updateSerpTitle === 'function' ) {
			dispatch.updateSerpTitle( title );
			synced = true;
		}

		if ( desc && typeof dispatch.updateDescription === 'function' ) {
			dispatch.updateDescription( desc );
			synced = true;
		}

		if ( desc && typeof dispatch.updateSerpDescription === 'function' ) {
			dispatch.updateSerpDescription( desc );
			synced = true;
		}

		if ( keyword && typeof dispatch.updateKeywords === 'function' ) {
			dispatch.updateKeywords( keyword );
			synced = true;
		}

		if ( seoData && seoData.slug && typeof dispatch.updateSerpSlug === 'function' ) {
			dispatch.updateSerpSlug( seoData.slug );
			synced = true;
		}

		if ( editorMeta.rank_math_facebook_title && typeof dispatch.updateFacebookTitle === 'function' ) {
			dispatch.updateFacebookTitle( editorMeta.rank_math_facebook_title );
			synced = true;
		}

		if ( editorMeta.rank_math_facebook_description && typeof dispatch.updateFacebookDescription === 'function' ) {
			dispatch.updateFacebookDescription( editorMeta.rank_math_facebook_description );
			synced = true;
		}

		if ( editorMeta.rank_math_twitter_title && typeof dispatch.updateTwitterTitle === 'function' ) {
			dispatch.updateTwitterTitle( editorMeta.rank_math_twitter_title );
			synced = true;
		}

		if ( editorMeta.rank_math_twitter_description && typeof dispatch.updateTwitterDescription === 'function' ) {
			dispatch.updateTwitterDescription( editorMeta.rank_math_twitter_description );
			synced = true;
		}

		if ( applySeoToRankMathTagify( keyword ) ) {
			synced = true;
		}

		if ( typeof dispatch.refreshResults === 'function' ) {
			dispatch.refreshResults();
		}

		refreshRankMathEditor();

		if ( synced && window.wp && wp.hooks && wp.hooks.doAction ) {
			wp.hooks.doAction( 'rank_math_data_changed' );
		}

		return synced;
	}

	function applySeoToClassicPostFields( seoData ) {
		if ( ! seoData ) {
			return false;
		}

		var synced = false;

		if ( seoData.slug ) {
			if ( setFieldBySelectors( [ '#post_name', '#new-post-slug', 'input[name="post_name"]' ], seoData.slug ) ) {
				synced = true;
			}

			var $slugDisplay = $( '#editable-post-name, #editable-post-name-full' );

			if ( $slugDisplay.length ) {
				$slugDisplay.text( seoData.slug );
				synced = true;
			}
		}

		if ( seoData.optimized_content && setEditorHtml( 'content', seoData.optimized_content ) ) {
			synced = true;
		}

		if ( seoData.short_description && setEditorHtml( 'excerpt', seoData.short_description ) ) {
			synced = true;
		}

		if ( synced && ( seoData.optimized_content || seoData.short_description ) ) {
			refreshRankMathEditor();
			var rmDispatch = getRankMathDispatch();
			if ( rmDispatch && typeof rmDispatch.refreshResults === 'function' ) {
				rmDispatch.refreshResults();
			}
		}

		return synced;
	}

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

		if ( seoData && seoData.short_description ) {
			edits.excerpt = seoData.short_description;
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

	function applySeoToEditor( seoData, editorMeta ) {
		var synced = false;

		// Rank Math React metabox (classic + block) — primary sync path.
		if ( applySeoToRankMathStore( editorMeta, seoData ) ) {
			synced = true;
		}

		if ( applySeoToBlockEditor( seoData, editorMeta ) ) {
			synced = true;
		}

		if ( applySeoToRankMathClassic( editorMeta ) ) {
			synced = true;
		}

		if ( applySeoToClassicPostFields( seoData ) ) {
			synced = true;
		}

		// Rank Math may finish mounting after AJAX.
		setTimeout( function () {
			applySeoToRankMathStore( editorMeta, seoData );
		}, 150 );

		setTimeout( function () {
			applySeoToRankMathStore( editorMeta, seoData );
		}, 600 );

		return synced;
	}

	function truncateText( text, max ) {
		text = String( text || '' );

		if ( text.length <= max ) {
			return text;
		}

		return text.substring( 0, max - 1 ) + '…';
	}

	function getDiffLabel( key ) {
		var labels = ( cfg.i18n && cfg.i18n.diffLabels ) ? cfg.i18n.diffLabels : {};
		return labels[ key ] || key;
	}

	function renderDiffTable( diff ) {
		var emptyLabel = ( cfg.i18n && cfg.i18n.emptyValue ) ? cfg.i18n.emptyValue : '(empty)';
		var html = '<table class="ai-seo-filler-diff-table"><tbody>';

		Object.keys( diff ).forEach( function ( key ) {
			var row = diff[ key ];

			if ( ! row || typeof row !== 'object' || ! ( 'new' in row ) ) {
				return;
			}

			var oldVal = row.old || emptyLabel;
			var newVal = row.new || emptyLabel;

			if ( 'slug' === key ) {
				oldVal = truncateText( oldVal, 72 );
				newVal = truncateText( newVal, 72 );
			}

			if ( 'meta_description' === key || 'meta_title' === key ) {
				oldVal = truncateText( oldVal, 140 );
				newVal = truncateText( newVal, 140 );
			}

			html += '<tr>' +
				'<th scope="row">' + escapeHtml( getDiffLabel( key ) ) + '</th>' +
				'<td class="ai-seo-filler-diff-cell">' +
					'<div class="ai-seo-filler-diff-old" title="' + escapeHtml( row.old || '' ) + '">' + escapeHtml( oldVal ) + '</div>' +
					'<div class="ai-seo-filler-diff-new" title="' + escapeHtml( row.new || '' ) + '">' + escapeHtml( newVal ) + '</div>' +
				'</td>' +
			'</tr>';
		} );

		html += '</tbody></table>';
		return html;
	}

	function renderKeywordPills( keywords, selected ) {
		var hint = ( cfg.i18n && cfg.i18n.keywordHint ) ? cfg.i18n.keywordHint : '';
		var html = '<div class="ai-seo-filler-kw-section">' +
			'<p class="ai-seo-filler-kw-label"><strong>' + escapeHtml( cfg.i18n.focusKeyword || 'Focus keyword' ) + '</strong></p>';

		if ( hint ) {
			html += '<p class="ai-seo-filler-kw-hint">' + escapeHtml( hint ) + '</p>';
		}

		html += '<div class="ai-seo-filler-kw-pills">';

		keywords.forEach( function ( kw, i ) {
			if ( ! kw ) {
				return;
			}

			var isChecked = selected ? ( kw === selected ) : ( 0 === i );
			html += '<label class="ai-seo-filler-kw-pill' + ( isChecked ? ' is-active' : '' ) + '">' +
				'<input type="radio" name="ai_seo_kw" value="' + escapeHtml( kw ) + '"' + ( isChecked ? ' checked' : '' ) + '>' +
				'<span>' + escapeHtml( kw ) + '</span>' +
			'</label>';
		} );

		html += '</div></div>';
		return html;
	}

	function renderChecklist( $container, checklist, options ) {
		options = options || {};

		if ( ! checklist ) {
			$container.empty();
			return;
		}

		if ( ! checklist.tests ) {
			checklist.tests = [];
		}

		var scoreLabel  = cfg.i18n.score || 'Score';
		var calculating = options.calculating;
		var scoreHtml   = calculating
			? '<span class="ai-seo-filler-inline-loader" aria-hidden="true"></span> ' + escapeHtml( cfg.i18n.scoreCalculating || 'Calculating…' )
			: checklist.score + '<small>/100</small>';
		var noteHtml    = '';

		if ( ! calculating && ! checklist.estimate && cfg.i18n.scoreRankMathNote ) {
			noteHtml = '<p class="ai-seo-filler-checklist__note">' + escapeHtml( cfg.i18n.scoreRankMathNote ) + '</p>';
		}

		var html = '<div class="ai-seo-filler-checklist">' +
			'<div class="ai-seo-filler-checklist__score' + ( calculating ? ' is-calculating' : '' ) + '">' +
				'<span class="ai-seo-filler-checklist__score-label">' + escapeHtml( scoreLabel ) + '</span>' +
				'<span class="ai-seo-filler-checklist__score-value">' + scoreHtml + '</span>' +
			'</div>' +
			noteHtml +
			'<ul class="ai-seo-filler-checklist__list">';

		checklist.tests.forEach( function ( test ) {
			html += '<li class="ai-seo-filler-checklist__item ' + ( test.pass ? 'is-pass' : 'is-fail' ) + '">' +
				'<span class="ai-seo-filler-checklist__icon" aria-hidden="true">' + ( test.pass ? '✓' : '✗' ) + '</span>' +
				'<span>' + escapeHtml( test.label ) + '</span>' +
			'</li>';
		} );

		html += '</ul></div>';
		$container.html( html );
	}

	function ensureModal() {
		if ( $( '#ai-seo-filler-modal' ).length ) {
			return $( '#ai-seo-filler-modal' );
		}

		var $modal = $( '<div id="ai-seo-filler-modal" class="ai-seo-filler-modal" style="display:none;" aria-hidden="true">' +
			'<div class="ai-seo-filler-modal__backdrop"></div>' +
			'<div class="ai-seo-filler-modal__dialog" role="dialog" aria-labelledby="ai-seo-filler-modal-title">' +
				'<header class="ai-seo-filler-modal__header">' +
					'<h2 id="ai-seo-filler-modal-title">' + escapeHtml( cfg.i18n.previewTitle || 'SEO Preview' ) + '</h2>' +
					'<span class="ai-seo-filler-modal__score"></span>' +
					'<button type="button" class="ai-seo-filler-modal__close ai-seo-filler-close-modal" aria-label="' + escapeHtml( cfg.i18n.close || 'Close' ) + '">×</button>' +
				'</header>' +
				'<div class="ai-seo-filler-modal__body">' +
					'<div class="ai-seo-filler-modal__keywords"></div>' +
					'<div class="ai-seo-filler-modal__diff-wrap">' +
						'<h3 class="ai-seo-filler-modal__section-title">' + escapeHtml( cfg.i18n.changesTitle || 'Changes' ) + '</h3>' +
						'<div class="ai-seo-filler-modal__diff"></div>' +
					'</div>' +
					'<div class="ai-seo-filler-modal__checklist"></div>' +
				'</div>' +
				'<footer class="ai-seo-filler-modal__actions">' +
					'<button type="button" class="button button-primary ai-seo-filler-apply-preview">' + escapeHtml( cfg.i18n.apply || 'Apply' ) + '</button>' +
					'<button type="button" class="button ai-seo-filler-close-modal">' + escapeHtml( cfg.i18n.cancel || 'Cancel' ) + '</button>' +
				'</footer>' +
			'</div></div>' );

		$( 'body' ).append( $modal );
		return $modal;
	}

	function updatePreviewKeywordRow( $modal, keyword ) {
		if ( ! previewState || ! keyword ) {
			return;
		}

		var diff = previewState.payload.diff || {};

		if ( diff.focus_keyword ) {
			diff.focus_keyword.new = keyword;
		}

		$modal.find( '.ai-seo-filler-modal__diff' ).html( renderDiffTable( diff ) );
		$modal.find( '.ai-seo-filler-kw-pill' ).removeClass( 'is-active' );
		$modal.find( 'input[name="ai_seo_kw"]:checked' ).closest( '.ai-seo-filler-kw-pill' ).addClass( 'is-active' );
	}

	function showPreviewModal( payload, postId, $status ) {
		var $modal = ensureModal();
		previewState = { payload: payload, postId: postId, $status: $status };

		if ( canUseRankMathAnalyzer() && ! rankMathSnapshot ) {
			rankMathSnapshot = snapshotRankMathState();
		}

		$modal.find( '.ai-seo-filler-modal__diff' ).html( renderDiffTable( payload.diff || {} ) );

		var keywords = [ payload.data.focus_keyword ].concat( payload.alternatives || [] );
		$modal.find( '.ai-seo-filler-modal__keywords' ).html( renderKeywordPills( keywords, payload.data.focus_keyword ) );

		refreshRankMathChecklist( payload.data, payload.editorMeta, {
			$container:        $modal.find( '.ai-seo-filler-modal__checklist' ),
			fallbackChecklist: payload.checklist
		} );

		$modal.attr( 'aria-hidden', 'false' ).show();
		$( 'body' ).addClass( 'ai-seo-filler-modal-open' );
		showAlert( 'info', payload.message || cfg.i18n.previewReady );
	}

	function runGenerate( postId, mode, $btn, $status ) {
		$btn.prop( 'disabled', true );
		setStatus( $status, 'loading', cfg.i18n.generating );

		aiSeoPost( {
			action:  'ai_seo_filler_generate_single',
			nonce:   cfg.nonce,
			post_id: postId,
			mode:    mode || 'full'
		} )
			.done( function ( response ) {
				if ( ! response.success ) {
					var errMsg = ( response.data && response.data.message ) ? response.data.message : cfg.i18n.error;
					notifyError( errMsg, $status );
					return;
				}

				var payload = response.data || {};

				if ( payload.preview ) {
					setStatus( $status, 'success', payload.message || cfg.i18n.previewReady );
					showPreviewModal( payload, postId, $status );
					return;
				}

				var synced = applySeoToEditor( payload.data || {}, payload.editorMeta );
				var message = payload.message || cfg.i18n.success;
				if ( synced ) {
					message += ' ' + ( cfg.i18n.synced || '' );
				}
				notifySuccess( message, $status );
				refreshRankMathChecklist( payload.data || {}, payload.editorMeta, {
					$container:        $status.siblings( '.ai-seo-filler-checklist' ),
					fallbackChecklist: payload.checklist
				} );
			} )
			.fail( function ( jqXHR ) {
				notifyError( getAjaxErrorMessage( jqXHR, cfg.i18n.error ), $status );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	}

	$( document ).on( 'click', '.ai-seo-filler-generate', function () {
		var $btn = $( this );
		runGenerate( $btn.data( 'post-id' ), $btn.data( 'mode' ) || 'full', $btn, $btn.siblings( '.ai-seo-filler-status' ) );
	} );

	$( document ).on( 'click', '.ai-seo-filler-generate-meta', function () {
		var $btn = $( this );
		runGenerate( $btn.data( 'post-id' ), 'meta_only', $btn, $btn.siblings( '.ai-seo-filler-status' ) );
	} );

	$( document ).on( 'click', '.ai-seo-filler-generate-images', function () {
		var $btn    = $( this );
		var postId  = $btn.data( 'post-id' );
		var isProduct = parseInt( $btn.data( 'is-product' ), 10 ) === 1;
		var $status = $btn.closest( '.ai-seo-filler-metabox-images' ).find( '.ai-seo-filler-images-status' );
		var confirmMsg = ( cfg.i18n && cfg.i18n.confirmImages )
			? cfg.i18n.confirmImages
			: 'Generate AI images? You will choose the featured image before they are applied.';

		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );
		setStatus( $status, 'loading', cfg.i18n.generatingImages || 'Generating images…' );

		aiSeoPost( {
			action:  'ai_seo_filler_generate_images',
			nonce:   cfg.nonce,
			post_id: postId
		} )
			.done( function ( response ) {
				if ( ! response.success ) {
					var errMsg = ( response.data && response.data.message ) ? response.data.message : cfg.i18n.error;
					setStatus( $status, 'error', errMsg );
					notifyError( errMsg, $status );
					return;
				}

				var payload = response.data || {};
				setStatus( $status, 'success', payload.message || cfg.i18n.imagesSelectTitle || 'Choose featured image' );

				if ( payload.needs_selection && payload.images && payload.images.length ) {
					openImageSelectModal( {
						postId: postId,
						isProduct: typeof payload.is_product !== 'undefined' ? !! payload.is_product : isProduct,
						stagingKey: payload.staging_key || '',
						images: payload.images,
						suggestedFeatured: payload.suggested_featured || ( payload.images[0] && payload.images[0].id ) || 0,
						$status: $status
					} );
					return;
				}

				notifySuccess( payload.message || cfg.i18n.imagesSuccess || cfg.i18n.success, $status );
			} )
			.fail( function ( jqXHR ) {
				var errMsg = getAjaxErrorMessage( jqXHR, cfg.i18n.error );
				setStatus( $status, 'error', errMsg );
				notifyError( errMsg, $status );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	function ensureImageSelectModal() {
		var $modal = $( '#ai-seo-filler-images-modal' );

		if ( $modal.length ) {
			return $modal;
		}

		$modal = $(
			'<div id="ai-seo-filler-images-modal" class="ai-seo-filler-modal ai-seo-filler-images-modal" style="display:none;" aria-hidden="true">' +
				'<div class="ai-seo-filler-modal__backdrop ai-seo-filler-images-modal-dismiss"></div>' +
				'<div class="ai-seo-filler-modal__dialog ai-seo-filler-images-modal__dialog" role="dialog" aria-labelledby="ai-seo-filler-images-modal-title">' +
					'<header class="ai-seo-filler-modal__header">' +
						'<h2 id="ai-seo-filler-images-modal-title">' + escapeHtml( cfg.i18n.imagesSelectTitle || 'Choose featured image' ) + '</h2>' +
						'<button type="button" class="ai-seo-filler-modal__close ai-seo-filler-images-modal-dismiss" aria-label="' + escapeHtml( cfg.i18n.close || 'Close' ) + '">×</button>' +
					'</header>' +
					'<div class="ai-seo-filler-modal__body">' +
						'<p class="ai-seo-filler-images-modal__hint"></p>' +
						'<div class="ai-seo-filler-images-modal__grid"></div>' +
					'</div>' +
					'<footer class="ai-seo-filler-modal__actions">' +
						'<button type="button" class="button ai-seo-filler-images-modal-dismiss">' + escapeHtml( cfg.i18n.imagesDiscard || 'Discard' ) + '</button>' +
						'<button type="button" class="button button-primary ai-seo-filler-apply-images">' + escapeHtml( cfg.i18n.imagesApply || 'Apply selection' ) + '</button>' +
					'</footer>' +
				'</div>' +
			'</div>'
		);

		$( 'body' ).append( $modal );
		return $modal;
	}

	function renderImageSelectGrid( images, featuredId, isProduct ) {
		var html = '';

		images.forEach( function ( image ) {
			var id = parseInt( image.id, 10 ) || 0;
			var isFeatured = id === featuredId;
			var roleLabel = isFeatured
				? ( cfg.i18n.imagesFeaturedBadge || 'Featured' )
				: ( isProduct ? ( cfg.i18n.imagesGalleryBadge || 'Gallery' ) : '' );
			var src = image.thumb || image.url || '';

			html += '<label class="ai-seo-filler-images-modal__card' + ( isFeatured ? ' is-featured' : '' ) + '">' +
				'<input type="radio" name="ai_seo_featured_image" value="' + id + '"' + ( isFeatured ? ' checked' : '' ) + ' />' +
				'<span class="ai-seo-filler-images-modal__media">' +
					( src ? '<img src="' + escapeHtml( src ) + '" alt="' + escapeHtml( image.alt || image.title || '' ) + '" loading="lazy" />' : '' ) +
					( roleLabel ? '<span class="ai-seo-filler-images-modal__badge">' + escapeHtml( roleLabel ) + '</span>' : '' ) +
				'</span>' +
				'<span class="ai-seo-filler-images-modal__meta">' + escapeHtml( image.title || ( 'Image #' + id ) ) + '</span>' +
			'</label>';
		} );

		return html;
	}

	function openImageSelectModal( state ) {
		imageSelectState = state || null;

		var $modal = ensureImageSelectModal();
		var featuredId = parseInt( state.suggestedFeatured, 10 ) || 0;
		var hint = state.isProduct
			? ( cfg.i18n.imagesSelectHint || 'Click an image to set it as featured. The others will be added to the product gallery.' )
			: ( cfg.i18n.imagesSelectHintPost || 'Click an image to set it as the featured image.' );

		$modal.find( '.ai-seo-filler-images-modal__hint' ).text( hint );
		$modal.find( '.ai-seo-filler-images-modal__grid' ).html( renderImageSelectGrid( state.images || [], featuredId, !! state.isProduct ) );
		$modal.show().attr( 'aria-hidden', 'false' );
		$( 'body' ).addClass( 'ai-seo-filler-modal-open' );
	}

	function closeImageSelectModal( discard ) {
		var $modal = $( '#ai-seo-filler-images-modal' );
		var state = imageSelectState;

		$modal.hide().attr( 'aria-hidden', 'true' );
		$( 'body' ).removeClass( 'ai-seo-filler-modal-open' );

		if ( discard && state && state.stagingKey ) {
			aiSeoPost( {
				action: 'ai_seo_filler_discard_images',
				nonce: cfg.nonce,
				post_id: state.postId,
				staging_key: state.stagingKey
			}, { showLoader: false } );
		}

		imageSelectState = null;
	}

	$( document ).on( 'change', 'input[name="ai_seo_featured_image"]', function () {
		if ( ! imageSelectState ) {
			return;
		}

		var featuredId = parseInt( $( this ).val(), 10 ) || 0;
		var $modal = $( '#ai-seo-filler-images-modal' );
		$modal.find( '.ai-seo-filler-images-modal__grid' ).html(
			renderImageSelectGrid( imageSelectState.images || [], featuredId, !! imageSelectState.isProduct )
		);
	} );

	$( document ).on( 'click', '.ai-seo-filler-images-modal-dismiss', function () {
		closeImageSelectModal( true );
	} );

	function syncProductEditorImages( payload ) {
		var editor = payload && payload.editor ? payload.editor : null;
		var featuredId = parseInt( ( editor && editor.featured_id ) || payload.featured_id || 0, 10 ) || 0;
		var galleryIds = ( editor && editor.gallery_ids ) ? editor.gallery_ids : ( payload.gallery_ids || [] );
		var $thumbInput = $( '#_thumbnail_id' );
		var $postImage = $( '#postimagediv .inside' );
		var $galleryInput = $( '#product_image_gallery' );
		var $galleryList = $( '#product_images_container ul.product_images' );

		if ( featuredId ) {
			if ( $thumbInput.length ) {
				$thumbInput.val( String( featuredId ) );
			}

			if ( $postImage.length ) {
				var featuredHtml = ( editor && editor.featured_html ) ? editor.featured_html : '';
				var removeLabel = ( editor && editor.remove_label ) ? editor.remove_label : 'Remove product image';

				if ( ! featuredHtml && editor && editor.featured_thumb ) {
					featuredHtml = '<img src="' + escapeHtml( editor.featured_thumb ) + '" style="max-width:100%;height:auto;" alt="" />';
				}

				if ( featuredHtml ) {
					$postImage.html(
						'<p class="hide-if-no-js"><a href="#" id="set-post-thumbnail" aria-describedby="set-post-thumbnail-desc">' +
							featuredHtml +
						'</a></p>' +
						'<p class="hide-if-no-js howto" id="set-post-thumbnail-desc">' +
							escapeHtml( 'Click the image to edit or update' ) +
						'</p>' +
						'<p class="hide-if-no-js"><a href="#" id="remove-post-thumbnail">' + escapeHtml( removeLabel ) + '</a></p>' +
						'<input type="hidden" id="_thumbnail_id" name="_thumbnail_id" value="' + featuredId + '" />'
					);
				}
			}

			if ( typeof window.WPSetThumbnailID === 'function' ) {
				window.WPSetThumbnailID( featuredId );
			}
		}

		galleryIds = ( galleryIds || [] ).map( function ( id ) {
			return parseInt( id, 10 ) || 0;
		} ).filter( Boolean );

		if ( $galleryInput.length ) {
			$galleryInput.val( galleryIds.join( ',' ) );
		}

		if ( $galleryList.length ) {
			var galleryItems = ( editor && editor.gallery ) ? editor.gallery : [];
			var listHtml = '';

			galleryIds.forEach( function ( id ) {
				var item = null;
				galleryItems.forEach( function ( candidate ) {
					if ( parseInt( candidate.id, 10 ) === id ) {
						item = candidate;
					}
				} );

				if ( ! item ) {
					( payload.images || [] ).forEach( function ( candidate ) {
						if ( parseInt( candidate.id, 10 ) === id ) {
							item = {
								id: id,
								thumb: candidate.thumb || candidate.url,
								html: ''
							};
						}
					} );
				}

				if ( ! item ) {
					return;
				}

				var imageHtml = item.html
					? item.html
					: ( item.thumb ? '<img src="' + escapeHtml( item.thumb ) + '" alt="" />' : '' );
				var deleteLabel = ( editor && editor.delete_label ) ? editor.delete_label : 'Delete';

				listHtml += '<li class="image" data-attachment_id="' + id + '">' +
					imageHtml +
					'<ul class="actions"><li><a href="#" class="delete tips" data-tip="' + escapeHtml( deleteLabel ) + '">' +
					escapeHtml( deleteLabel ) +
					'</a></li></ul></li>';
			} );

			$galleryList.html( listHtml );
		}
	}

	$( document ).on( 'click', '.ai-seo-filler-apply-images', function () {
		if ( ! imageSelectState ) {
			return;
		}

		var $modal = $( '#ai-seo-filler-images-modal' );
		var featuredId = parseInt( $modal.find( 'input[name="ai_seo_featured_image"]:checked' ).val(), 10 ) || 0;
		var $status = imageSelectState.$status;
		var galleryIds = [];

		if ( ! featuredId ) {
			showAlert( 'error', cfg.i18n.imagesSelectRequired || 'Select a featured image first.' );
			return;
		}

		( imageSelectState.images || [] ).forEach( function ( image ) {
			var id = parseInt( image.id, 10 ) || 0;
			if ( id && id !== featuredId ) {
				galleryIds.push( id );
			}
		} );

		$modal.find( 'button' ).prop( 'disabled', true );

		aiSeoPost( {
			action: 'ai_seo_filler_apply_images',
			nonce: cfg.nonce,
			post_id: imageSelectState.postId,
			staging_key: imageSelectState.stagingKey,
			featured_id: featuredId,
			gallery_ids: JSON.stringify( galleryIds )
		} )
			.done( function ( response ) {
				if ( ! response.success ) {
					var errMsg = ( response.data && response.data.message ) ? response.data.message : cfg.i18n.error;
					notifyError( errMsg, $status );
					return;
				}

				var payload = response.data || {};
				var message = payload.message || cfg.i18n.imagesSuccess || cfg.i18n.success;

				closeImageSelectModal( false );
				syncProductEditorImages( payload );

				if ( $status && $status.length ) {
					setStatus( $status, 'success', message );

					if ( payload.images && payload.images.length ) {
						var thumbs = '<div class="ai-seo-filler-images-preview">';
						payload.images.forEach( function ( image ) {
							if ( image.url || image.thumb ) {
								thumbs += '<img src="' + escapeHtml( image.thumb || image.url ) + '" alt="' + escapeHtml( image.alt || '' ) + '" width="48" height="48" loading="lazy" />';
							}
						} );
						thumbs += '</div>';
						$status.append( thumbs );
					}
				}

				notifySuccess( message, $status );
			} )
			.fail( function ( jqXHR ) {
				notifyError( getAjaxErrorMessage( jqXHR, cfg.i18n.error ), $status );
			} )
			.always( function () {
				$modal.find( 'button' ).prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'click', '.ai-seo-filler-close-modal, .ai-seo-filler-modal__backdrop', function () {
		unregisterRankMathPreviewHooks();

		if ( rankMathSnapshot ) {
			restoreRankMathState( rankMathSnapshot );
			rankMathSnapshot = null;
		}

		$( '#ai-seo-filler-modal' ).hide().attr( 'aria-hidden', 'true' );
		$( 'body' ).removeClass( 'ai-seo-filler-modal-open' );
		previewState = null;
	} );

	$( document ).on( 'change', 'input[name="ai_seo_kw"]', function () {
		var keyword = $( this ).val();
		var $modal  = $( '#ai-seo-filler-modal' );

		updatePreviewKeywordRow( $modal, keyword );

		if ( previewState && canUseRankMathAnalyzer() ) {
			refreshRankMathChecklist( previewState.payload.data, previewState.payload.editorMeta, {
				$container:        $modal.find( '.ai-seo-filler-modal__checklist' ),
				fallbackChecklist: previewState.payload.checklist,
				focusKeyword:      keyword
			} );
		}
	} );

	$( document ).on( 'click', '.ai-seo-filler-apply-preview', function () {
		if ( ! previewState ) {
			return;
		}

		var focusKeyword = $( 'input[name="ai_seo_kw"]:checked' ).val() || '';
		var $status      = previewState.$status;
		var $modal       = $( '#ai-seo-filler-modal' );

		$modal.find( 'button' ).prop( 'disabled', true );
		setStatus( $status, 'loading', cfg.i18n.applying || 'Applying…' );

		aiSeoPost( {
			action:        'ai_seo_filler_apply',
			nonce:         cfg.nonce,
			post_id:       previewState.postId,
			preview_key:   previewState.payload.previewKey,
			focus_keyword: focusKeyword
		} ).done( function ( response ) {
			unregisterRankMathPreviewHooks();
			rankMathSnapshot = null;

			$( '#ai-seo-filler-modal' ).hide().attr( 'aria-hidden', 'true' );
			$( 'body' ).removeClass( 'ai-seo-filler-modal-open' );

			if ( response.success ) {
				var payload = response.data || {};
				var synced = applySeoToEditor( payload.data || {}, payload.editorMeta );
				var message = payload.message || cfg.i18n.success;

				if ( synced ) {
					message += ' ' + ( cfg.i18n.synced || '' );
				}

				notifySuccess( message, $status );

				refreshRankMathChecklist( payload.data || {}, payload.editorMeta, {
					$container:        $status.siblings( '.ai-seo-filler-checklist' ),
					fallbackChecklist: payload.checklist,
					focusKeyword:      focusKeyword,
					afterApply:        true
				} );
			} else {
				notifyError( ( response.data && response.data.message ) ? response.data.message : cfg.i18n.error, $status );
			}
		} ).fail( function ( jqXHR ) {
			notifyError( getAjaxErrorMessage( jqXHR, cfg.i18n.error ), $status );
		} ).always( function () {
			$modal.find( 'button' ).prop( 'disabled', false );
		} );

		previewState = null;
	} );

	$( document ).on( 'click', '.ai-seo-filler-test-api', function () {
		var $btn = $( this );
		var $msg = $( '#ai-seo-filler-test-result' );
		$btn.prop( 'disabled', true );
		$msg.html( renderInlineLoader( cfg.i18n.testing || 'Testing…' ) );

		aiSeoPost( { action: 'ai_seo_filler_test_api', nonce: cfg.nonce } )
			.done( function ( r ) {
				var msg = r.success ? ( r.data.message || 'OK' ) : ( r.data.message || cfg.i18n.error );
				$msg.text( msg );
				if ( r.success ) {
					showAlert( 'success', msg );
				} else {
					showAlert( 'error', msg );
				}
			} )
			.fail( function ( jqXHR ) {
				var msg = getAjaxErrorMessage( jqXHR, cfg.i18n.error );
				$msg.text( msg );
				showAlert( 'error', msg );
			} )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	function getOpenAiKeyInput() {
		return $( '#ai_seo_filler_openai_api_key' );
	}

	function getOpenAiKeyValue() {
		return $.trim( getOpenAiKeyInput().val() || '' );
	}

	function previewSecret( value ) {
		var key = $.trim( value || '' );
		if ( key.length < 12 ) {
			return key ? '••••••••' : '';
		}
		var prefixLen = Math.min( 10, Math.floor( key.length / 3 ) );
		var suffixLen = Math.min( 4, Math.floor( key.length / 4 ) );
		return key.slice( 0, prefixLen ) + '…' + key.slice( -suffixLen );
	}

	function markOpenAiKeyConfigured( key ) {
		var $status = $( '#ai-seo-filler-openai-key-status' );
		var preview = previewSecret( key || getOpenAiKeyValue() );
		var label = ( cfg.i18n.openaiKeyOk || 'OpenAI configured' ) + ( preview ? ' (' + preview + ')' : '' );
		if ( $status.length ) {
			$status.html( '<span class="ai-seo-filler-badge ai-seo-filler-badge--ok">' + label + '</span>' );
		}
		getOpenAiKeyInput().attr( 'data-has-secret', '1' );
		getOpenAiKeyInput().closest( '.ai-seo-filler-secret-field' ).find( '.ai-seo-filler-copy-secret' ).prop( 'disabled', false );
	}

	$( document ).on( 'click', '.ai-seo-filler-toggle-secret', function () {
		var $btn = $( this );
		var $input = $btn.closest( '.ai-seo-filler-secret-field' ).find( '.ai-seo-filler-secret-input' );
		var masked = $input.toggleClass( 'is-masked' ).hasClass( 'is-masked' );
		$btn.text( masked ? ( cfg.i18n.showKey || 'Show' ) : ( cfg.i18n.hideKey || 'Hide' ) );
		$btn.attr( 'aria-label', masked ? ( cfg.i18n.showKey || 'Show' ) : ( cfg.i18n.hideKey || 'Hide' ) );
	} );

	$( document ).on( 'click', '.ai-seo-filler-copy-secret', function () {
		var $btn = $( this );
		var $input = $btn.closest( '.ai-seo-filler-secret-field' ).find( '.ai-seo-filler-secret-input' );
		var value = $.trim( $input.val() || '' );
		var original = $btn.text();

		if ( ! value ) {
			showAlert( 'error', cfg.i18n.copyFailed || 'Could not copy the key.' );
			return;
		}

		function done( ok ) {
			$btn.text( ok ? ( cfg.i18n.copiedKey || 'Copied!' ) : ( cfg.i18n.copyFailed || 'Copy failed' ) );
			setTimeout( function () { $btn.text( original ); }, 1500 );
			if ( ok ) {
				showAlert( 'success', cfg.i18n.copiedKey || 'Copied!' );
			} else {
				showAlert( 'error', cfg.i18n.copyFailed || 'Could not copy the key.' );
			}
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( function () {
				done( true );
			} ).catch( function () {
				done( false );
			} );
			return;
		}

		$input.removeClass( 'is-masked' ).trigger( 'focus' ).trigger( 'select' );
		try {
			done( document.execCommand( 'copy' ) );
		} catch ( e ) {
			done( false );
		}
		$input.addClass( 'is-masked' );
	} );

	$( document ).on( 'input', '.ai-seo-filler-secret-input', function () {
		var $input = $( this );
		var hasValue = $.trim( $input.val() || '' ).length > 0;
		$input.closest( '.ai-seo-filler-secret-field' ).find( '.ai-seo-filler-copy-secret' ).prop( 'disabled', ! hasValue );
	} );

	$( document ).on( 'click', '.ai-seo-filler-save-openai-key', function () {
		var $btn = $( this );
		var $msg = $( '#ai-seo-filler-openai-key-result' );
		var key = getOpenAiKeyValue();

		$btn.prop( 'disabled', true );
		$msg.html( renderInlineLoader( cfg.i18n.savingKey || 'Saving key…' ) );

		aiSeoPost( { action: 'ai_seo_filler_save_openai_key', nonce: cfg.nonce, key: key } )
			.done( function ( r ) {
				var msg = r.success ? ( r.data.message || cfg.i18n.openaiKeySaved || 'Saved' ) : ( r.data.message || cfg.i18n.error );
				$msg.text( msg );
				if ( r.success ) {
					markOpenAiKeyConfigured( key );
					showAlert( 'success', msg );
				} else {
					showAlert( 'error', msg );
				}
			} )
			.fail( function ( jqXHR ) {
				var msg = getAjaxErrorMessage( jqXHR, cfg.i18n.error );
				$msg.text( msg );
				showAlert( 'error', msg );
			} )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	$( document ).on( 'click', '.ai-seo-filler-test-openai-key', function () {
		var $btn = $( this );
		var $msg = $( '#ai-seo-filler-openai-key-result' );
		var key = getOpenAiKeyValue();

		$btn.prop( 'disabled', true );
		$msg.html( renderInlineLoader( cfg.i18n.testing || 'Testing…' ) );

		aiSeoPost( { action: 'ai_seo_filler_test_openai_key', nonce: cfg.nonce, key: key } )
			.done( function ( r ) {
				var msg = r.success ? ( r.data.message || 'OK' ) : ( r.data.message || cfg.i18n.error );
				$msg.text( msg );
				showAlert( r.success ? 'success' : 'error', msg );
			} )
			.fail( function ( jqXHR ) {
				var msg = getAjaxErrorMessage( jqXHR, cfg.i18n.error );
				$msg.text( msg );
				showAlert( 'error', msg );
			} )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );

	function updateBulkUI( data ) {
		var total = data.total || 0;
		var processed = data.processed || 0;
		var percent = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;

		$( '#ai-seo-filler-progress-bar' ).css( 'width', percent + '%' );
		$( '#ai-seo-filler-bulk-status-label' ).text( data.status ? data.status.charAt( 0 ).toUpperCase() + data.status.slice( 1 ) : 'Idle' );
		$( '#ai-seo-filler-bulk-total' ).text( total );
		$( '#ai-seo-filler-bulk-processed' ).text( processed );
		$( '#ai-seo-filler-bulk-pending' ).text( data.pending || 0 );
		$( '#ai-seo-filler-bulk-errors' ).text( data.errors || 0 );

		$( '.ai-seo-filler-cancel-bulk' ).prop( 'disabled', data.status === 'idle' || data.status === 'completed' );
		$( '.ai-seo-filler-pause-bulk' ).prop( 'disabled', data.status !== 'processing' );
		$( '.ai-seo-filler-resume-bulk' ).prop( 'disabled', data.status !== 'paused' );

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
			showAlert( 'success', cfg.i18n.completed );
			stopPolling();
		}
	}

	function pollBulkStatus() {
		aiSeoPost( { action: 'ai_seo_filler_bulk_status', nonce: cfg.nonce }, { showLoader: false } ).done( function ( response ) {
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

	$( document ).on( 'click', '.ai-seo-filler-start-bulk', function () {
		if ( ! window.confirm( cfg.i18n.confirmBulk ) ) {
			return;
		}

		var $btn = $( this );
		var $msg = $( '#ai-seo-filler-bulk-message' );

		$btn.prop( 'disabled', true );
		$msg.removeClass( 'is-success is-error' ).addClass( 'is-loading' ).html( renderInlineLoader( cfg.i18n.processing ) );

		aiSeoPost( {
			action:      'ai_seo_filler_start_bulk',
			nonce:       cfg.nonce,
			post_type:   $( '#ai_seo_filler_bulk_post_type' ).val(),
			post_status: $( '#ai_seo_filler_bulk_post_status' ).val(),
			only_empty:  $( '#ai_seo_filler_bulk_only_empty' ).is( ':checked' ) ? 1 : 0,
			days:        $( '#ai_seo_filler_bulk_days' ).val(),
			category:    $( '#ai_seo_filler_bulk_category' ).val()
		} ).done( function ( response ) {
			if ( response.success ) {
				var msg = response.data.message;
				$msg.removeClass( 'is-loading' ).addClass( 'is-success' ).text( msg );
				if ( response.data.estimate_minutes ) {
					$msg.append( ' (~' + response.data.estimate_minutes + ' min)' );
					msg += ' (~' + response.data.estimate_minutes + ' min)';
				}
				showAlert( 'success', msg );
				updateBulkUI( { status: 'processing', total: response.data.total, processed: 0, pending: response.data.total, errors: 0 } );
				startPolling();
			} else {
				var errMsg = ( response.data && response.data.message ) ? response.data.message : cfg.i18n.error;
				$msg.removeClass( 'is-loading' ).addClass( 'is-error' ).text( errMsg );
				showAlert( 'error', errMsg );
			}
		} ).fail( function ( jqXHR ) {
			var errMsg = getAjaxErrorMessage( jqXHR, cfg.i18n.error );
			$msg.removeClass( 'is-loading' ).addClass( 'is-error' ).text( errMsg );
			showAlert( 'error', errMsg );
		} ).always( function () { $btn.prop( 'disabled', false ); } );
	} );

	$( document ).on( 'click', '.ai-seo-filler-pause-bulk', function () {
		aiSeoPost( { action: 'ai_seo_filler_pause_bulk', nonce: cfg.nonce } ).done( function ( r ) {
			if ( r.success ) {
				showAlert( 'info', r.data.message || cfg.i18n.paused );
			}
			pollBulkStatus();
		} );
	} );

	$( document ).on( 'click', '.ai-seo-filler-resume-bulk', function () {
		aiSeoPost( { action: 'ai_seo_filler_resume_bulk', nonce: cfg.nonce } ).done( function ( r ) {
			if ( r.success ) {
				showAlert( 'success', r.data.message || cfg.i18n.resumed );
			}
			startPolling();
		} );
	} );

	$( document ).on( 'click', '.ai-seo-filler-cancel-bulk', function () {
		if ( ! window.confirm( cfg.i18n.confirmCancel ) ) {
			return;
		}
		aiSeoPost( { action: 'ai_seo_filler_cancel_bulk', nonce: cfg.nonce } ).done( function ( r ) {
			stopPolling();
			updateBulkUI( { status: 'idle', total: 0, processed: 0, pending: 0, errors: 0 } );
			showAlert( 'info', r.success ? ( r.data.message || cfg.i18n.cancelled ) : cfg.i18n.error );
		} );
	} );

	$( document ).on( 'click', '.ai-seo-filler-export-log', function () {
		window.location.href = cfg.ajaxUrl + '?action=ai_seo_filler_export_log&nonce=' + encodeURIComponent( cfg.nonce );
	} );

	$( function () {
		if ( window.aiSeoFillerBulk && window.aiSeoFillerBulk.status === 'processing' ) {
			startPolling();
		}
		initProviderPanels();
	} );

	function initProviderPanels() {
		var $select = $( '#ai_seo_filler_ai_provider' );
		var $panels = $( '.ai-seo-filler-provider-panel' );
		if ( ! $select.length || ! $panels.length ) {
			return;
		}
		function togglePanels() {
			var provider = $select.val();
			$panels.each( function () {
				$( this ).toggleClass( 'is-hidden', $( this ).data( 'provider' ) !== provider );
			} );
		}
		$select.on( 'change', togglePanels );
		togglePanels();
	}

	// Expose for Gutenberg sidebar and other scripts.
	window.aiSeoFillerShowAlert = showAlert;
	window.aiSeoFillerGetAjaxError = getAjaxErrorMessage;
	window.aiSeoFillerApplyToEditor = applySeoToEditor;
	window.aiSeoFillerPost = aiSeoPost;
	window.aiSeoFillerShowLoader = showGlobalLoader;
	window.aiSeoFillerHideLoader = hideGlobalLoader;

}( jQuery ) );
