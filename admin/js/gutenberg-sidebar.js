( function ( wp ) {
	'use strict';

	if ( ! wp.plugins || ! wp.editPost ) {
		return;
	}

	var cfg = window.aiSeoFiller || {};
	var el = wp.element.createElement;
	var PluginSidebar = wp.editPost.PluginSidebar;
	var PanelBody = wp.components.PanelBody;
	var Button = wp.components.Button;
	var useState = wp.element.useState;

	function alert( type, message ) {
		if ( window.aiSeoFillerShowAlert ) {
			window.aiSeoFillerShowAlert( type, message );
		}
	}

	function Sidebar() {
		var postId = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		var status = useState( '' );
		var setStatus = status[1];

		function generate( mode ) {
			var loadingMsg = cfg.i18n.generating || 'Generating…';

			setStatus( loadingMsg );

			var postData = {
				action: 'ai_seo_filler_generate_single',
				nonce: cfg.nonce,
				post_id: postId,
				mode: mode || 'full'
			};

			var request = window.aiSeoFillerPost
				? window.aiSeoFillerPost( postData )
				: jQuery.post( cfg.ajaxUrl, postData );

			request
				.done( function ( response ) {
					if ( ! response.success ) {
						var errMsg = ( response.data && response.data.message ) ? response.data.message : ( cfg.i18n.error || 'Error' );
						setStatus( errMsg );
						alert( 'error', errMsg );
						return;
					}

					var payload = response.data || {};
					var msg = payload.message || cfg.i18n.success || 'OK';

					if ( window.aiSeoFillerApplyToEditor ) {
						var synced = window.aiSeoFillerApplyToEditor( payload.data || {}, payload.editorMeta );
						if ( synced ) {
							msg += ' ' + ( cfg.i18n.synced || '' );
						}
					}

					setStatus( msg );
					alert( 'success', msg );
				} )
				.fail( function ( jqXHR ) {
					var errMsg = window.aiSeoFillerGetAjaxError
						? window.aiSeoFillerGetAjaxError( jqXHR, cfg.i18n.error )
						: ( cfg.i18n.error || 'Error' );
					setStatus( errMsg );
					alert( 'error', errMsg );
				} );
		}

		return el(
			PluginSidebar,
			{ name: 'ai-seo-filler-sidebar', title: 'AI SEO Filler', icon: 'search' },
			el( PanelBody, { initialOpen: true },
				el( Button, { variant: 'primary', onClick: function () { generate( 'full' ); } }, cfg.i18n.generating ? 'Generate all SEO' : 'Generate' ),
				el( 'div', { style: { marginTop: '8px' } },
					el( Button, { variant: 'secondary', onClick: function () { generate( 'meta_only' ); } }, 'Meta only' )
				),
				status[0] ? el( 'p', { className: 'ai-seo-filler-sidebar-status is-loading', style: { marginTop: '10px', fontSize: '12px' } }, status[0] ) : null
			)
		);
	}

	wp.plugins.registerPlugin( 'ai-seo-filler', { render: Sidebar } );
}( window.wp ) );
