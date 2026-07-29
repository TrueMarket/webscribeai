/* global webscribeaiMetaBox, jQuery */
( function ( $ ) {
    'use strict';

    var cfg = webscribeaiMetaBox;

    // -------------------------------------------------------------------------
    // Generate button
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.webscribeai-generate-btn', function () {
        var $btn    = $( this );
        var postId  = $btn.data( 'post-id' );
        var $box    = $( '#webscribeai-metabox-' + postId );
        var $spinner = $( '#webscribeai-spinner-' + postId );
        var $result  = $( '#webscribeai-result-' + postId );
        var $message = $( '#webscribeai-message-' + postId );

        $result.hide();
        $message.hide().removeClass( 'is-success is-error is-info' );
        $spinner.show();
        $btn.prop( 'disabled', true ).find( '.dashicons' ).addClass( 'spin' );

        $.ajax( {
            url:    cfg.ajaxUrl,
            method: 'POST',
            data:   {
                action:  'webscribeai_generate',
                nonce:   cfg.nonce,
                post_id: postId,
            },
        } )
        .done( function ( response ) {
            if ( ! response.success ) {
                showMessage( $message, 'is-error', response.data.message || cfg.i18n.error );
                return;
            }

            // Populate preview fields.
            $result.find( '.webscribeai-gen-title' ).val( response.data.title );
            $result.find( '.webscribeai-gen-desc' ).val( response.data.description );
            $result.slideDown( 200 );
        } )
        .fail( function () {
            showMessage( $message, 'is-error', cfg.i18n.error );
        } )
        .always( function () {
            $spinner.hide();
            $btn.prop( 'disabled', false ).find( '.dashicons' ).removeClass( 'spin' );
        } );
    } );

    // -------------------------------------------------------------------------
    // Apply button
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.webscribeai-apply-btn', function () {
        var $btn     = $( this );
        var postId   = $btn.data( 'post-id' );
        var $box     = $( '#webscribeai-metabox-' + postId );
        var $result  = $( '#webscribeai-result-' + postId );
        var $message = $( '#webscribeai-message-' + postId );
        var override = $box.find( '.webscribeai-override-checkbox' ).prop( 'checked' );

        var title       = $result.find( '.webscribeai-gen-title' ).val();
        var description = $result.find( '.webscribeai-gen-desc' ).val();

        // Warn if overriding existing data.
        if ( override ) {
            if ( ! window.confirm( cfg.i18n.confirmApply ) ) {
                return;
            }
        }

        $btn.prop( 'disabled', true ).text( cfg.i18n.applying );

        $.ajax( {
            url:    cfg.ajaxUrl,
            method: 'POST',
            data:   {
                action:      'webscribeai_apply',
                nonce:       cfg.nonce,
                post_id:     postId,
                title:       title,
                description: description,
                override:    override ? 'true' : 'false',
            },
        } )
        .done( function ( response ) {
            $result.slideUp( 150 );

            if ( ! response.success ) {
                showMessage( $message, 'is-error', response.data.message || cfg.i18n.error );
                return;
            }

            if ( response.data.skipped ) {
                showMessage( $message, 'is-info', response.data.message || cfg.i18n.skipped );
            } else {
                // Update the "current" display.
                $box.find( '.webscribeai-current-title' ).text( title );
                $box.find( '.webscribeai-current-desc' ).text( description );
                showMessage( $message, 'is-success', response.data.message || cfg.i18n.applied );
            }
        } )
        .fail( function () {
            showMessage( $message, 'is-error', cfg.i18n.error );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).html(
                '<span class="dashicons dashicons-saved"></span> ' + cfg.i18n.generate.replace( 'Generate', 'Apply' )
            );
        } );
    } );

    // -------------------------------------------------------------------------
    // Discard button
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.webscribeai-discard-btn', function () {
        var $result = $( this ).closest( '.webscribeai-result' );
        $result.slideUp( 150 );
    } );

    // -------------------------------------------------------------------------
    // Sidebar single-action button (TSF mode)
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.webscribeai-sidebar-generate-btn', function () {
        var $btn      = $( this );
        var postId    = parseInt( $( '#post_ID' ).val(), 10 ) || parseInt( cfg.currentPostId, 10 ) || parseInt( $btn.data( 'post-id' ), 10 );
        var override  = !! cfg.override;
        var $message  = $( '#webscribeai-sidebar-message-' + postId );

        if ( ! postId ) {
            showMessage( $message, 'is-error', cfg.i18n.invalidPost || cfg.i18n.error );
            return;
        }

        $message.hide().removeClass( 'is-success is-error is-info' );
        $btn.prop( 'disabled', true ).text( cfg.i18n.generating );

        // Step 1: generate suggested title + description.
        $.ajax( {
            url:    cfg.ajaxUrl,
            method: 'POST',
            data:   {
                action:  'webscribeai_generate',
                nonce:   cfg.nonce,
                post_id: postId,
            },
        } )
        .done( function ( generateResponse ) {
            if ( ! generateResponse.success ) {
                showMessage( $message, 'is-error', generateResponse.data.message || cfg.i18n.error );
                return;
            }

            // Step 2: apply directly to SEO fields.
            $.ajax( {
                url:    cfg.ajaxUrl,
                method: 'POST',
                data:   {
                    action:      'webscribeai_apply',
                    nonce:       cfg.nonce,
                    post_id:     postId,
                    title:       generateResponse.data.title,
                    description: generateResponse.data.description,
                    override:    override ? 'true' : 'false',
                },
            } )
            .done( function ( applyResponse ) {
                if ( ! applyResponse.success ) {
                    showMessage( $message, 'is-error', applyResponse.data.message || cfg.i18n.error );
                    return;
                }

                if ( applyResponse.data.skipped ) {
                    showMessage( $message, 'is-info', applyResponse.data.message || cfg.i18n.skipped );
                    return;
                }

                // Reflect applied values in TSF UI fields immediately.
                // TSF uses these IDs/names in the post metabox form.
                $( '#autodescription_title, input[name="autodescription[_genesis_title]"]' )
                    .val( generateResponse.data.title )
                    .trigger( 'input' )
                    .trigger( 'change' );
                $( '#autodescription_description, textarea[name="autodescription[_genesis_description]"]' )
                    .val( generateResponse.data.description )
                    .trigger( 'input' )
                    .trigger( 'change' );

                showMessage( $message, 'is-success', cfg.i18n.appliedSidebar || cfg.i18n.applied );
            } )
            .fail( function () {
                showMessage( $message, 'is-error', cfg.i18n.error );
            } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( cfg.i18n.generate );
            } );
        } )
        .fail( function () {
            showMessage( $message, 'is-error', cfg.i18n.error );
            $btn.prop( 'disabled', false ).text( cfg.i18n.generate );
        } );
    } );

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    function showMessage( $el, type, text ) {
        $el
            .removeClass( 'is-success is-error is-info' )
            .addClass( type )
            .text( text )
            .slideDown( 150 );

        // Auto-hide success messages.
        if ( 'is-success' === type ) {
            setTimeout( function () {
                $el.slideUp( 300 );
            }, 4000 );
        }
    }

}( jQuery ) );
