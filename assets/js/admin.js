/* global webscribeaiAdmin, jQuery */
( function ( $ ) {
    'use strict';

    var cfg = webscribeaiAdmin;

    // =========================================================================
    // Settings tab: provider / model interaction
    // =========================================================================

    var $providerRadios = $( 'input[name="webscribeai_settings[api_provider]"]' );
    var $modelSelect    = $( '#webscribeai_ai_model' );

    function filterModelsByProvider( provider ) {
        $modelSelect.find( 'option' ).each( function () {
            var $opt = $( this );
            var show = $opt.data( 'provider' ) === provider;
            $opt.prop( 'disabled', ! show ).toggle( show );
        } );

        // Select the first visible option if current is hidden.
        var $selected = $modelSelect.find( 'option:selected' );
        if ( $selected.data( 'provider' ) !== provider ) {
            $modelSelect.find( 'option[data-provider="' + provider + '"]' ).first().prop( 'selected', true );
        }
    }

    $providerRadios.on( 'change', function () {
        filterModelsByProvider( $( this ).val() );
    } );

    // Init on page load.
    var currentProvider = $providerRadios.filter( ':checked' ).val() || 'anthropic';
    filterModelsByProvider( currentProvider );

    // API key show/hide toggle.
    $( '#webscribeai-toggle-key' ).on( 'click', function () {
        var $key = $( '#webscribeai_api_key' );
        $key.attr( 'type', $key.attr( 'type' ) === 'password' ? 'text' : 'password' );
    } );

    // =========================================================================
    // Bulk Populate tab
    // =========================================================================

    // --- Select / Deselect All ---

    $( '#webscribeai-select-all' ).on( 'click', function () {
        $( '.webscribeai-post-checkbox' ).prop( 'checked', true );
        $( '.webscribeai-group-toggle' ).prop( 'checked', true );
    } );

    $( '#webscribeai-deselect-all' ).on( 'click', function () {
        $( '.webscribeai-post-checkbox' ).prop( 'checked', false );
        $( '.webscribeai-group-toggle' ).prop( 'checked', false );
    } );

    // --- Group toggle ---

    $( document ).on( 'change', '.webscribeai-group-toggle', function () {
        var group   = $( this ).data( 'group' );
        var checked = $( this ).prop( 'checked' );
        $( '.webscribeai-post-checkbox' ).filter( function () {
            return $( this ).closest( 'tr' ).data( 'group' ) === group;
        } ).prop( 'checked', checked );
    } );

    // --- Shift-click range selection ---

    var $lastChecked = null;

    $( document ).on( 'click', '.webscribeai-post-checkbox', function ( e ) {
        var $all = $( '.webscribeai-post-checkbox' );

        if ( e.shiftKey && $lastChecked && $lastChecked[ 0 ] !== this ) {
            var allBoxes  = $all.toArray();
            var idxThis   = allBoxes.indexOf( this );
            var idxLast   = allBoxes.indexOf( $lastChecked[ 0 ] );
            var start     = Math.min( idxThis, idxLast );
            var end       = Math.max( idxThis, idxLast );
            var newState  = $( this ).prop( 'checked' );

            allBoxes.slice( start, end + 1 ).forEach( function ( box ) {
                $( box ).prop( 'checked', newState );
            } );
        }

        $lastChecked = $( this );
    } );

    // --- Resume banner (shown when a previous run was interrupted) ---

    if ( cfg.savedQueue && cfg.savedQueue.pending.length > 0 ) {
        var $resumeNotice = $( '<div>' )
            .attr( 'id', 'webscribeai-resume-notice' )
            .addClass( 'notice notice-warning' )
            .css( 'padding', '8px 12px' )
            .html(
                '<p>' +
                '<strong>' + cfg.i18n.resumeNotice + '</strong> ' +
                cfg.savedQueue.pending.length + ' ' + cfg.i18n.resumePosts +
                ' &nbsp;<button type="button" class="button button-primary" id="webscribeai-resume-btn">' + cfg.i18n.resumeBtn + '</button>' +
                ' <button type="button" class="button" id="webscribeai-dismiss-resume-btn">' + cfg.i18n.dismissBtn + '</button>' +
                '</p>'
            );
        $( '.webscribeai-bulk-wrap' ).prepend( $resumeNotice );
    }

    $( document ).on( 'click', '#webscribeai-resume-btn', function () {
        $( '#webscribeai-resume-notice' ).remove();
        runBulk( cfg.savedQueue.pending, cfg.savedQueue.override, cfg.savedQueue.field || 'both' );
    } );

    $( document ).on( 'click', '#webscribeai-dismiss-resume-btn', function () {
        $( '#webscribeai-resume-notice' ).remove();
        clearQueue();
    } );

    // --- Generate All ---

    $( '#webscribeai-bulk-run-all' ).on( 'click', function () {
        var override = $( '#webscribeai-bulk-override' ).prop( 'checked' );
        var postIds  = [];
        $( '.webscribeai-post-checkbox' ).each( function () {
            // When not overriding, only skip posts where BOTH title and description
            // already exist — a post missing just one field still needs processing.
            var hasTitle       = '1' === String( $( this ).data( 'has-title' ) );
            var hasDescription = '1' === String( $( this ).data( 'has-description' ) );
            if ( ! override && hasTitle && hasDescription ) {
                return;
            }
            postIds.push( parseInt( $( this ).val(), 10 ) );
        } );

        if ( 0 === postIds.length ) {
            alert( cfg.i18n.noSelection );
            return;
        }

        if ( ! window.confirm( cfg.i18n.confirmAll ) ) {
            return;
        }

        runBulk( postIds, override, 'both' );
    } );

    // --- Bulk Actions dropdown ---

    $( '#webscribeai-bulk-action-select' ).on( 'change', function () {
        $( '#webscribeai-bulk-apply' ).prop( 'disabled', '' === $( this ).val() );
    } );

    $( '#webscribeai-bulk-apply' ).on( 'click', function () {
        var action = $( '#webscribeai-bulk-action-select' ).val();

        if ( '' === action ) {
            alert( cfg.i18n.chooseAction );
            return;
        }

        var postIds = [];
        $( '.webscribeai-post-checkbox:checked' ).each( function () {
            postIds.push( parseInt( $( this ).val(), 10 ) );
        } );

        if ( 0 === postIds.length ) {
            alert( cfg.i18n.noSelection );
            return;
        }

        if ( 'generate_meta' === action || 'generate_title' === action || 'generate_description' === action ) {
            var genFieldMap = {
                generate_meta:        'both',
                generate_title:       'title',
                generate_description: 'description',
            };
            var genConfirmMap = {
                generate_meta:        cfg.i18n.confirmBulk,
                generate_title:       cfg.i18n.confirmGenerateTitle,
                generate_description: cfg.i18n.confirmGenerateDescription,
            };
            if ( ! window.confirm( genConfirmMap[ action ] ) ) {
                return;
            }
            var override = $( '#webscribeai-bulk-override' ).prop( 'checked' );
            runBulk( postIds, override, genFieldMap[ action ] );
        } else if ( 'remove_title' === action || 'remove_description' === action || 'remove_all' === action ) {
            var fieldMap = {
                remove_title:       'title',
                remove_description: 'description',
                remove_all:         'all',
            };
            var confirmMap = {
                remove_title:       cfg.i18n.confirmRemoveTitle,
                remove_description: cfg.i18n.confirmRemoveDescription,
                remove_all:         cfg.i18n.confirmRemoveAll,
            };
            if ( ! window.confirm( confirmMap[ action ] ) ) {
                return;
            }
            runBulkRemove( postIds, fieldMap[ action ] );
        }
    } );

    // -------------------------------------------------------------------------
    // Bulk engine — processes posts one at a time sequentially to avoid
    // overwhelming the API rate limits.
    // -------------------------------------------------------------------------

    var bulkStopRequested = false;

    $( '#webscribeai-stop-btn' ).on( 'click', function () {
        bulkStopRequested = true;
        $( this ).prop( 'disabled', true );
    } );

    function runBulk( postIds, override, field ) {
        field = field || 'both';

        var total     = postIds.length;
        var done      = 0;
        var succeeded = 0;
        var skipped   = 0;
        var failed    = 0;

        var $applyBtn  = $( '#webscribeai-bulk-apply' );
        var $runAllBtn = $( '#webscribeai-bulk-run-all' );
        var $progress  = $( '#webscribeai-bulk-progress' );
        var $fill      = $( '#webscribeai-progress-fill' );
        var $label     = $( '#webscribeai-progress-label' );
        var $results   = $( '#webscribeai-bulk-results' );
        var $stopBtn   = $( '#webscribeai-stop-btn' );

        bulkStopRequested = false;
        $applyBtn.prop( 'disabled', true );
        $runAllBtn.prop( 'disabled', true );
        $stopBtn.prop( 'disabled', false ).show();
        $results.hide();
        $progress.show();
        updateProgress( 0, total, $fill, $label );

        // Persist the queue so the run can be resumed if the user navigates away.
        saveQueue( postIds, override, field );

        // Mark all selected rows as "processing".
        postIds.forEach( function ( id ) {
            setRowStatus( id, 'processing', cfg.i18n.processing );
        } );

        // Process sequentially via recursive callback.
        function processNext( index ) {
            if ( bulkStopRequested ) {
                onStop( postIds.slice( index ) );
                return;
            }

            if ( index >= total ) {
                onComplete();
                return;
            }

            var postId = postIds[ index ];

            $.ajax( {
                url:    cfg.ajaxUrl,
                method: 'POST',
                data:   {
                    action:   'webscribeai_bulk_generate',
                    nonce:    cfg.nonce,
                    post_id:  postId,
                    override: override ? 'true' : 'false',
                    field:    field,
                },
            } )
            .done( function ( response ) {
                done++;

                if ( ! response.success ) {
                    failed++;
                    setRowStatus( postId, 'error', cfg.i18n.error + ': ' + ( response.data.message || '' ) );
                } else if ( 'skipped' === response.data.status ) {
                    skipped++;
                    setRowStatus( postId, 'skipped', cfg.i18n.skipped );
                } else {
                    succeeded++;
                    setRowStatus( postId, 'success', cfg.i18n.success );
                    // Update the live cells in the table.
                    updateRowMeta( postId, field, response.data.title, response.data.description );
                }

                updateProgress( done, total, $fill, $label );
            } )
            .fail( function () {
                done++;
                failed++;
                setRowStatus( postId, 'error', cfg.i18n.error );
                updateProgress( done, total, $fill, $label );
            } )
            .always( function () {
                // Small delay to be polite to the API.
                setTimeout( function () {
                    processNext( index + 1 );
                }, 300 );
            } );
        }

        function onComplete() {
            clearQueue();
            $stopBtn.hide();
            $applyBtn.prop( 'disabled', false );
            $runAllBtn.prop( 'disabled', false );
            $results
                .html(
                    '<strong>' + cfg.i18n.bulkComplete + '</strong> &mdash; ' +
                    succeeded + ' ' + cfg.i18n.success + ', ' +
                    skipped  + ' ' + cfg.i18n.skipped  + ', ' +
                    failed   + ' ' + cfg.i18n.error
                )
                .show();
        }

        function onStop( remainingIds ) {
            clearQueue();
            bulkStopRequested = false;
            $stopBtn.hide();
            $applyBtn.prop( 'disabled', false );
            $runAllBtn.prop( 'disabled', false );

            remainingIds.forEach( function ( id ) {
                $( '#webscribeai-row-' + id ).removeClass( 'webscribeai-processing webscribeai-done webscribeai-errored' );
                $( '#webscribeai-result-' + id ).removeClass( 'is-success is-skipped is-error is-processing' ).text( '' );
            } );

            $results
                .html(
                    '<strong>' + cfg.i18n.bulkStopped + '</strong> &mdash; ' +
                    succeeded + ' ' + cfg.i18n.success + ', ' +
                    skipped  + ' ' + cfg.i18n.skipped  + ', ' +
                    failed   + ' ' + cfg.i18n.error
                )
                .show();
        }

        processNext( 0 );
    }

    // -------------------------------------------------------------------------
    // Bulk removal — clears a single SEO meta field (title or description)
    // for each selected post. Shares the progress UI with runBulk().
    // -------------------------------------------------------------------------

    function runBulkRemove( postIds, field ) {
        var total     = postIds.length;
        var done      = 0;
        var succeeded = 0;
        var failed    = 0;

        var $applyBtn  = $( '#webscribeai-bulk-apply' );
        var $runAllBtn = $( '#webscribeai-bulk-run-all' );
        var $progress  = $( '#webscribeai-bulk-progress' );
        var $fill      = $( '#webscribeai-progress-fill' );
        var $label     = $( '#webscribeai-progress-label' );
        var $results   = $( '#webscribeai-bulk-results' );
        var $stopBtn   = $( '#webscribeai-stop-btn' );

        bulkStopRequested = false;
        $applyBtn.prop( 'disabled', true );
        $runAllBtn.prop( 'disabled', true );
        $stopBtn.prop( 'disabled', false ).show();
        $results.hide();
        $progress.show();
        updateProgress( 0, total, $fill, $label );

        postIds.forEach( function ( id ) {
            setRowStatus( id, 'processing', cfg.i18n.processing );
        } );

        function processNext( index ) {
            if ( bulkStopRequested ) {
                onStop( postIds.slice( index ) );
                return;
            }

            if ( index >= total ) {
                onComplete();
                return;
            }

            var postId = postIds[ index ];

            $.ajax( {
                url:    cfg.ajaxUrl,
                method: 'POST',
                data:   {
                    action:  'webscribeai_bulk_remove',
                    nonce:   cfg.nonce,
                    post_id: postId,
                    field:   field,
                },
            } )
            .done( function ( response ) {
                done++;

                if ( ! response.success ) {
                    failed++;
                    setRowStatus( postId, 'error', cfg.i18n.error + ': ' + ( response.data.message || '' ) );
                } else {
                    succeeded++;
                    setRowStatus( postId, 'success', cfg.i18n.removed );
                    clearRowMetaField( postId, field );
                }

                updateProgress( done, total, $fill, $label );
            } )
            .fail( function () {
                done++;
                failed++;
                setRowStatus( postId, 'error', cfg.i18n.error );
                updateProgress( done, total, $fill, $label );
            } )
            .always( function () {
                setTimeout( function () {
                    processNext( index + 1 );
                }, 300 );
            } );
        }

        function onComplete() {
            $stopBtn.hide();
            $applyBtn.prop( 'disabled', false );
            $runAllBtn.prop( 'disabled', false );
            $results
                .html(
                    '<strong>' + cfg.i18n.bulkRemoveComplete + '</strong> &mdash; ' +
                    succeeded + ' ' + cfg.i18n.removed + ', ' +
                    failed   + ' ' + cfg.i18n.error
                )
                .show();
        }

        function onStop( remainingIds ) {
            bulkStopRequested = false;
            $stopBtn.hide();
            $applyBtn.prop( 'disabled', false );
            $runAllBtn.prop( 'disabled', false );

            remainingIds.forEach( function ( id ) {
                $( '#webscribeai-row-' + id ).removeClass( 'webscribeai-processing webscribeai-done webscribeai-errored' );
                $( '#webscribeai-result-' + id ).removeClass( 'is-success is-skipped is-error is-processing' ).text( '' );
            } );

            $results
                .html(
                    '<strong>' + cfg.i18n.bulkRemoveStopped + '</strong> &mdash; ' +
                    succeeded + ' ' + cfg.i18n.removed + ', ' +
                    failed   + ' ' + cfg.i18n.error
                )
                .show();
        }

        processNext( 0 );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function updateProgress( done, total, $fill, $label ) {
        var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
        $fill.css( 'width', pct + '%' );
        $label.text( done + ' / ' + total );
    }

    function setRowStatus( postId, status, text ) {
        var $row    = $( '#webscribeai-row-' + postId );
        var $cell   = $( '#webscribeai-result-' + postId );

        $row.removeClass( 'webscribeai-processing webscribeai-done webscribeai-errored' );
        $cell.removeClass( 'is-success is-skipped is-error is-processing' );

        var rowClass  = 'webscribeai-processing';
        var cellClass = 'is-processing';

        if ( 'success' === status ) {
            rowClass  = 'webscribeai-done';
            cellClass = 'is-success';
        } else if ( 'skipped' === status ) {
            rowClass  = 'webscribeai-done';
            cellClass = 'is-skipped';
        } else if ( 'error' === status ) {
            rowClass  = 'webscribeai-errored';
            cellClass = 'is-error';
        }

        $row.addClass( rowClass );
        $cell.addClass( cellClass ).text( text );
    }

    function saveQueue( postIds, override, field ) {
        $.ajax( {
            url:    cfg.ajaxUrl,
            method: 'POST',
            data:   {
                action:   'webscribeai_save_queue',
                nonce:    cfg.nonce,
                pending:  postIds,
                override: override ? 'true' : 'false',
                field:    field || 'both',
            },
        } );
    }

    function clearQueue() {
        $.ajax( {
            url:    cfg.ajaxUrl,
            method: 'POST',
            data:   {
                action: 'webscribeai_clear_queue',
                nonce:  cfg.nonce,
            },
        } );
    }

    function updateRowMeta( postId, field, title, description ) {
        var $row = $( '#webscribeai-row-' + postId );

        // title/description come back null when that field was left untouched
        // (already had a value and override wasn't requested) — don't blank the cell.
        if ( ( 'title' === field || 'both' === field ) && null !== title ) {
            $row.find( '.webscribeai-cell-title' ).html( $( '<span>' ).addClass( 'webscribeai-meta-text' ).text( title ) );
        }

        if ( ( 'description' === field || 'both' === field ) && null !== description ) {
            $row.find( '.webscribeai-cell-desc' ).html( $( '<span>' ).addClass( 'webscribeai-meta-text' ).text( description ) );
        }

        // Show edit button if not already in edit mode.
        if ( ! $row.hasClass( 'webscribeai-editing' ) ) {
            var $actionsCell = $row.find( '.webscribeai-cell-actions' );
            if ( 0 === $actionsCell.find( '.webscribeai-edit-btn' ).length ) {
                $actionsCell.html( makeEditBtn( postId ) );
            }
        }
    }

    function clearRowMetaField( postId, field ) {
        var $row = $( '#webscribeai-row-' + postId );

        if ( 'title' === field || 'all' === field ) {
            $row.find( '.webscribeai-cell-title' ).html( $( '<span>' ).addClass( 'webscribeai-meta-text' ).text( '' ) );
        }

        if ( 'description' === field || 'all' === field ) {
            $row.find( '.webscribeai-cell-desc' ).html( $( '<span>' ).addClass( 'webscribeai-meta-text' ).text( '' ) );
        }
    }

    // =========================================================================
    // Inline meta editing
    // =========================================================================

    function makeEditBtn( postId ) {
        return $( '<button>' ).attr( {
            type:           'button',
            class:          'button-link webscribeai-edit-btn',
            'data-post-id': postId,
            title:          cfg.i18n.edit,
        } ).html( '<span class="dashicons dashicons-edit"></span>' );
    }

    function exitEditMode( $row, title, desc ) {
        var postId = $row.attr( 'id' ).replace( 'webscribeai-row-', '' );
        $row.removeClass( 'webscribeai-editing' );
        $row.find( '.webscribeai-cell-title' ).html( $( '<span>' ).addClass( 'webscribeai-meta-text' ).text( title ) );
        $row.find( '.webscribeai-cell-desc' ).html( $( '<span>' ).addClass( 'webscribeai-meta-text' ).text( desc ) );
        $row.find( '.webscribeai-cell-actions' ).html( makeEditBtn( postId ) );
    }

    // Enter edit mode.
    $( document ).on( 'click', '.webscribeai-edit-btn', function () {
        var postId = $( this ).data( 'post-id' );
        var $row   = $( '#webscribeai-row-' + postId );

        if ( $row.hasClass( 'webscribeai-editing' ) ) {
            return;
        }

        var origTitle = $row.find( '.webscribeai-cell-title .webscribeai-meta-text' ).text();
        var origDesc  = $row.find( '.webscribeai-cell-desc .webscribeai-meta-text' ).text();

        $row.data( { 'orig-title': origTitle, 'orig-desc': origDesc } ).addClass( 'webscribeai-editing' );

        $row.find( '.webscribeai-cell-title' ).html(
            $( '<input>' ).attr( { type: 'text', class: 'webscribeai-edit-title widefat', maxlength: 70 } ).val( origTitle )
        );
        $row.find( '.webscribeai-cell-desc' ).html(
            $( '<textarea>' ).attr( { class: 'webscribeai-edit-desc widefat', rows: 2, maxlength: 200 } ).val( origDesc )
        );
        $row.find( '.webscribeai-cell-actions' ).html(
            $( '<div>' ).addClass( 'webscribeai-edit-actions' )
                .append( $( '<button>' ).attr( { type: 'button', class: 'button-link webscribeai-save-btn', 'data-post-id': postId, title: cfg.i18n.save } ).html( '<span class="dashicons dashicons-yes-alt"></span>' ) )
                .append( $( '<button>' ).attr( { type: 'button', class: 'button-link webscribeai-cancel-btn', 'data-post-id': postId, title: cfg.i18n.cancel } ).html( '<span class="dashicons dashicons-no-alt"></span>' ) )
        );

        $row.find( '.webscribeai-edit-title' ).trigger( 'focus' );
    } );

    // Cancel edit.
    $( document ).on( 'click', '.webscribeai-cancel-btn', function () {
        var postId = $( this ).data( 'post-id' );
        var $row   = $( '#webscribeai-row-' + postId );
        exitEditMode( $row, $row.data( 'orig-title' ), $row.data( 'orig-desc' ) );
    } );

    // Save inline edits.
    $( document ).on( 'click', '.webscribeai-save-btn', function () {
        var $btn     = $( this );
        var postId   = $btn.data( 'post-id' );
        var $row     = $( '#webscribeai-row-' + postId );
        var newTitle = $.trim( $row.find( '.webscribeai-edit-title' ).val() );
        var newDesc  = $.trim( $row.find( '.webscribeai-edit-desc' ).val() );

        $btn.prop( 'disabled', true ).find( '.dashicons' ).removeClass( 'dashicons-yes-alt' ).addClass( 'dashicons-update-alt' );

        $.ajax( {
            url:    cfg.ajaxUrl,
            method: 'POST',
            data: {
                action:      'webscribeai_apply',
                nonce:       cfg.nonce,
                post_id:     postId,
                title:       newTitle,
                description: newDesc,
                override:    'true',
            },
        } )
        .done( function ( response ) {
            if ( response.success ) {
                exitEditMode( $row, newTitle, newDesc );
                setRowStatus( postId, 'success', cfg.i18n.saved );
            } else {
                $btn.prop( 'disabled', false ).find( '.dashicons' ).removeClass( 'dashicons-update-alt' ).addClass( 'dashicons-yes-alt' );
                var msg = ( response.data && response.data.message ) ? response.data.message : '';
                setRowStatus( postId, 'error', cfg.i18n.editError + ( msg ? ': ' + msg : '' ) );
            }
        } )
        .fail( function () {
            $btn.prop( 'disabled', false ).text( cfg.i18n.save );
            setRowStatus( postId, 'error', cfg.i18n.editError );
        } );
    } );

    // =========================================================================
    // Per-group pagination
    // =========================================================================

    var BULK_PER_PAGE = cfg.perPage || 20;

    function initGroupPagination() {
        $( '.webscribeai-post-group' ).each( function () {
            var $group = $( this );
            var $rows  = $group.find( 'tr.webscribeai-post-row' );

            if ( $rows.length <= BULK_PER_PAGE ) {
                return; // No pagination needed.
            }

            var totalPages = Math.ceil( $rows.length / BULK_PER_PAGE );
            $group.append( $( '<div>' ).addClass( 'webscribeai-pagination' ) );
            showPage( $group, 1, $rows );
            renderPaginationControls( $group, 1, totalPages );
        } );
    }

    function showPage( $group, page, $rows ) {
        var start = ( page - 1 ) * BULK_PER_PAGE;
        var end   = start + BULK_PER_PAGE;
        $rows.each( function ( i ) {
            $( this ).toggleClass( 'webscribeai-paged-hidden', i < start || i >= end );
        } );
        $group.data( 'current-page', page );
    }

    function renderPaginationControls( $group, current, total ) {
        var $controls = $group.find( '.webscribeai-pagination' );
        $controls.empty();

        var $prev = $( '<button>' )
            .attr( { type: 'button', class: 'button webscribeai-page-prev' } )
            .text( '\u2039 ' + cfg.i18n.pagePrev )
            .prop( 'disabled', current <= 1 );
        $controls.append( $prev );

        getPageNumbers( current, total ).forEach( function ( p ) {
            if ( '\u2026' === p ) {
                $controls.append( $( '<span>' ).addClass( 'webscribeai-page-gap' ).text( '\u2026' ) );
            } else {
                $controls.append(
                    $( '<button>' )
                        .attr( { type: 'button', class: 'button webscribeai-page-btn' + ( p === current ? ' is-active' : '' ) } )
                        .text( p )
                        .data( 'page', p )
                );
            }
        } );

        var $next = $( '<button>' )
            .attr( { type: 'button', class: 'button webscribeai-page-next' } )
            .text( cfg.i18n.pageNext + ' \u203a' )
            .prop( 'disabled', current >= total );
        $controls.append( $next );

        var rowCount = $group.find( 'tr.webscribeai-post-row' ).length;
        var start    = ( current - 1 ) * BULK_PER_PAGE + 1;
        var end      = Math.min( current * BULK_PER_PAGE, rowCount );
        $controls.append(
            $( '<span>' ).addClass( 'webscribeai-page-count' ).text( start + '\u2013' + end + ' / ' + rowCount )
        );
    }

    function getPageNumbers( current, total ) {
        if ( total <= 7 ) {
            var all = [];
            for ( var i = 1; i <= total; i++ ) { all.push( i ); }
            return all;
        }
        var result = [ 1 ];
        if ( current > 3 ) { result.push( '\u2026' ); }
        var rangeStart = Math.max( 2, current - 1 );
        var rangeEnd   = Math.min( total - 1, current + 1 );
        for ( var j = rangeStart; j <= rangeEnd; j++ ) { result.push( j ); }
        if ( current < total - 2 ) { result.push( '\u2026' ); }
        result.push( total );
        return result;
    }

    function goToPage( $group, page ) {
        var $rows      = $group.find( 'tr.webscribeai-post-row' );
        var totalPages = Math.ceil( $rows.length / BULK_PER_PAGE );
        page = Math.max( 1, Math.min( page, totalPages ) );
        showPage( $group, page, $rows );
        renderPaginationControls( $group, page, totalPages );
        $( 'html, body' ).animate( { scrollTop: $group.offset().top - 60 }, 200 );
    }

    $( document ).on( 'click', '.webscribeai-page-btn', function () {
        goToPage( $( this ).closest( '.webscribeai-post-group' ), $( this ).data( 'page' ) );
    } );

    $( document ).on( 'click', '.webscribeai-page-prev', function () {
        var $group = $( this ).closest( '.webscribeai-post-group' );
        goToPage( $group, ( $group.data( 'current-page' ) || 1 ) - 1 );
    } );

    $( document ).on( 'click', '.webscribeai-page-next', function () {
        var $group = $( this ).closest( '.webscribeai-post-group' );
        goToPage( $group, ( $group.data( 'current-page' ) || 1 ) + 1 );
    } );

    initGroupPagination();

}( jQuery ) );
