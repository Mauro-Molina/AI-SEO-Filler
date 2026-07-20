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
			if ( mode === 'meta_only' && cfg.thinContent ) {
				var confirmMsg = ( cfg.i18n && cfg.i18n.thinContentConfirm )
					? cfg.i18n.thinContentConfirm
					: 'This item has little body content. Meta only will not fix Rank Math content tests. Continue anyway?';

				if ( ! window.confirm( confirmMsg ) ) {
					return;
				}
			}

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
				cfg.thinContent
					? el(
						'p',
						{
							className: 'ai-seo-filler-metabox-notice',
							style: {
								margin: '0 0 10px',
								padding: '8px 10px',
								borderLeft: '3px solid #dba617',
								background: '#fcf9e8',
								fontSize: '12px',
								lineHeight: '1.45',
								color: '#664d03'
							}
						},
						( cfg.i18n && cfg.i18n.thinContentWarning )
							? cfg.i18n.thinContentWarning
							: 'Little body content detected. Prefer Generate all SEO for Rank Math content tests.'
					)
					: null,
				el( Button, {
					variant: 'primary',
					onClick: function () { generate( 'full' ); }
				}, ( cfg.i18n && cfg.i18n.generateAll ) || 'Generate all SEO' ),
				el( 'div', { style: { marginTop: '8px' } },
					el( Button, {
						variant: 'secondary',
						onClick: function () { generate( 'meta_only' ); }
					}, ( cfg.i18n && cfg.i18n.metaOnly ) || 'Meta only' )
				),
				status[0] ? el( 'p', { className: 'ai-seo-filler-sidebar-status', style: { marginTop: '10px', fontSize: '12px' } }, status[0] ) : null
			)
		);
	}

	wp.plugins.registerPlugin( 'ai-seo-filler', { render: Sidebar } );
}( window.wp ) );
