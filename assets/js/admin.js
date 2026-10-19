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
        $modelSelect.find( 'optgroup, option' ).each( function () {
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


    // =========================================================================
    // Bulk Populate tab
    // =========================================================================

    var $tbody = $( '#webscribeai-post-rows' );

    if ( ! $tbody.length ) {
        return; // Settings tab.
    }

    // wp_localize_script() turns top-level scalars into strings.
    cfg.canGenerate    = '1' === String( cfg.canGenerate );
    cfg.lastErrorCount = parseInt( cfg.lastErrorCount, 10 ) || 0;

    var i18n          = cfg.i18n;
    var PER_PAGE      = parseInt( cfg.perPage, 10 ) || 20;
    var seoLimits     = cfg.seoLimits || { title: 60, titleWarn: 70, description: 160, descriptionWarn: 180 };
    var $actionSelect = $( '#webscribeai-bulk-action-select' );

    // Minimal sprintf: supports %s / %d and numbered %1$s / %1$d placeholders.
    function fmt( str ) {
        var args = Array.prototype.slice.call( arguments, 1 );
        var next = 0;
        return String( str ).replace( /%(?:(\d+)\$)?[sd]/g, function ( match, n ) {
            var value = n ? args[ parseInt( n, 10 ) - 1 ] : args[ next++ ];
            return undefined === value ? '' : value;
        } );
    }

    function post( data, timeout ) {
        return $.ajax( {
            url:     cfg.ajaxUrl,
            method:  'POST',
            data:    $.extend( { nonce: cfg.nonce }, data ),
            timeout: timeout || 0,
        } );
    }

    // One AI call is capped at 60s server-side; give it headroom, but never
    // let a hung request stall the whole job.
    var REQUEST_TIMEOUT = 90000;

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    var state = {
        mode:    'browse', // 'browse' = filtered post list, 'job' = rows of the current/last job.
        filters: { post_type: '', view: 'all', search: '' },
        page:    1,
        total:   0,
        counts:  null,
    };

    // Selected post IDs, kept across pages. Keys are 'p' + ID so object key
    // order stays insertion order (integer-like keys would be sorted).
    var selection = {
        ids:         {},
        count:       0,
        allMatching: false, // true once "Select all N matching" was used.
    };

    var job      = null; // The current or last bulk job — see startJob().
    var rowState = {};   // post ID => { status, text }, so statuses survive paging.
    var titles   = {};   // post ID => post title, for the progress panel.

    function isRunning() {
        return !! ( job && job.running );
    }

    function totalPages() {
        return Math.max( 1, Math.ceil( state.total / PER_PAGE ) );
    }

    function jobViewIds() {
        if ( ! job ) {
            return [];
        }
        if ( 'errors' === job.view ) {
            return job.failed.map( function ( entry ) { return entry.post_id; } );
        }
        return job.ids;
    }

    // -------------------------------------------------------------------------
    // Loading rows
    // -------------------------------------------------------------------------

    var listRequest = null;
    var listSeq     = 0;

    function loadRows() {
        var seq  = ++listSeq;
        var data = { action: 'webscribeai_bulk_list' };

        if ( listRequest ) {
            listRequest.abort();
        }

        if ( 'job' === state.mode ) {
            var ids   = jobViewIds();
            var slice = ids.slice( ( state.page - 1 ) * PER_PAGE, state.page * PER_PAGE );

            state.total = ids.length;

            if ( ! slice.length ) {
                listRequest = null;
                $tbody.removeClass( 'is-loading' ).html( emptyRow( i18n.noRows ) );
                onRowsRendered();
                return;
            }

            data.include = slice;
        } else {
            $.extend( data, state.filters, { paged: state.page } );
        }

        $tbody.addClass( 'is-loading' );

        listRequest = post( data )
            .done( function ( response ) {
                if ( seq !== listSeq ) {
                    return;
                }
                if ( ! response.success ) {
                    $tbody.html( emptyRow( i18n.loadError ) );
                    return;
                }

                if ( 'browse' === state.mode ) {
                    state.total  = response.data.total;
                    state.counts = response.data.counts;

                    // The current page can disappear when posts drop out of
                    // the view (e.g. "Missing title" after generating titles).
                    if ( state.page > totalPages() ) {
                        state.page = totalPages();
                        loadRows();
                        return;
                    }

                    renderViews();
                }

                $tbody.html( response.data.html );
                onRowsRendered();
            } )
            .fail( function ( xhr, status ) {
                if ( seq === listSeq && 'abort' !== status ) {
                    $tbody.html( emptyRow( i18n.loadError ) );
                }
            } )
            .always( function () {
                if ( seq === listSeq ) {
                    $tbody.removeClass( 'is-loading' );
                    listRequest = null;
                }
            } );
    }

    function emptyRow( text ) {
        return $( '<tr>' ).addClass( 'webscribeai-empty-row' ).append(
            $( '<td>' ).attr( 'colspan', 8 ).text( text )
        );
    }

    function onRowsRendered() {
        $tbody.find( 'tr.webscribeai-post-row' ).each( function () {
            var $row = $( this );
            var id   = parseInt( $row.attr( 'data-post-id' ), 10 );

            titles[ id ] = $row.attr( 'data-title' );

            if ( rowState[ id ] ) {
                paintRow( $row, rowState[ id ] );
            }
        } );

        syncCheckboxes();
        renderPager();

        if ( isRunning() ) {
            updateRunPanel();
        }
    }

    // Fetch every post ID matching the current filters (all pages).
    function fetchIds( needsMeta ) {
        return post( $.extend( {
            action:     'webscribeai_bulk_ids',
            needs_meta: needsMeta ? 'true' : 'false',
        }, state.filters ) ).then( function ( response ) {
            if ( ! response.success ) {
                return $.Deferred().reject().promise();
            }
            return response.data.items.map( function ( item ) {
                titles[ item[ 0 ] ] = item[ 1 ];
                return item[ 0 ];
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Views, filters and pagination
    // -------------------------------------------------------------------------

    function renderViews() {
        $( '#webscribeai-views li' ).each( function () {
            var $li   = $( this );
            var view  = $li.attr( 'data-view' );
            var count = state.counts ? ( state.counts[ view ] || 0 ) : 0;

            $li.find( '.webscribeai-view-count' ).text( count );
            $li.find( 'a' ).toggleClass( 'current', view === state.filters.view );

            if ( 'errors' === view ) {
                $li.prop( 'hidden', 0 === count && 'errors' !== state.filters.view );
            }
        } );
    }

    function setFilter( key, value ) {
        if ( isRunning() || state.filters[ key ] === value ) {
            return;
        }
        state.filters[ key ] = value;
        state.page = 1;
        clearSelection();
        loadRows();
    }

    $( '#webscribeai-views' ).on( 'click', 'a', function ( e ) {
        e.preventDefault();
        setFilter( 'view', $( this ).attr( 'data-view' ) );
    } );

    $( '#webscribeai-filter-type' ).on( 'change', function () {
        setFilter( 'post_type', $( this ).val() );
    } );

    var searchTimer = null;

    $( '#webscribeai-filter-search' ).on( 'input search', function () {
        var value = $.trim( $( this ).val() );
        clearTimeout( searchTimer );
        searchTimer = setTimeout( function () {
            setFilter( 'search', value );
        }, 300 );
    } );

    function renderPager() {
        var $containers = $( '.webscribeai-pagination' ).empty();
        var pages       = totalPages();

        if ( 0 === state.total ) {
            return;
        }

        var start = ( state.page - 1 ) * PER_PAGE + 1;
        var end   = Math.min( state.page * PER_PAGE, state.total );

        $containers.each( function () {
            var $controls = $( this );

            $controls.append(
                $( '<span>' ).addClass( 'webscribeai-page-count' ).text( fmt( i18n.pageCount, start, end, state.total ) )
            );

            if ( pages <= 1 ) {
                return;
            }

            $controls.append(
                $( '<button>' )
                    .attr( { type: 'button', class: 'button webscribeai-page-btn' } )
                    .text( '‹ ' + i18n.pagePrev )
                    .attr( 'data-page', state.page - 1 )
                    .prop( 'disabled', state.page <= 1 )
            );

            getPageNumbers( state.page, pages ).forEach( function ( p ) {
                if ( '…' === p ) {
                    $controls.append( $( '<span>' ).addClass( 'webscribeai-page-gap' ).text( '…' ) );
                } else {
                    $controls.append(
                        $( '<button>' )
                            .attr( { type: 'button', class: 'button webscribeai-page-btn' + ( p === state.page ? ' is-active' : '' ) } )
                            .text( p )
                            .attr( 'data-page', p )
                    );
                }
            } );

            $controls.append(
                $( '<button>' )
                    .attr( { type: 'button', class: 'button webscribeai-page-btn' } )
                    .text( i18n.pageNext + ' ›' )
                    .attr( 'data-page', state.page + 1 )
                    .prop( 'disabled', state.page >= pages )
            );
        } );
    }

    function getPageNumbers( current, total ) {
        if ( total <= 7 ) {
            var all = [];
            for ( var i = 1; i <= total; i++ ) { all.push( i ); }
            return all;
        }
        var result = [ 1 ];
        if ( current > 3 ) { result.push( '…' ); }
        var rangeStart = Math.max( 2, current - 1 );
        var rangeEnd   = Math.min( total - 1, current + 1 );
        for ( var j = rangeStart; j <= rangeEnd; j++ ) { result.push( j ); }
        if ( current < total - 2 ) { result.push( '…' ); }
        result.push( total );
        return result;
    }

    $( document ).on( 'click', '.webscribeai-page-btn', function () {
        var page = parseInt( $( this ).attr( 'data-page' ), 10 );

        // Paging by hand during a job means the user wants to look around —
        // stop following the job so the table doesn't jump back.
        if ( isRunning() && job.follow ) {
            job.follow = false;
            $( '#webscribeai-follow' ).prop( 'checked', false );
        }

        state.page = Math.max( 1, Math.min( page, totalPages() ) );
        loadRows();

        if ( $( this ).closest( '#webscribeai-pagination-bottom' ).length ) {
            $( 'html, body' ).animate( { scrollTop: $( '.webscribeai-post-table' ).offset().top - 160 }, 200 );
        }
    } );

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------

    function setSelected( id, on ) {
        var key = 'p' + id;
        if ( on && ! selection.ids[ key ] ) {
            selection.ids[ key ] = true;
            selection.count++;
        } else if ( ! on && selection.ids[ key ] ) {
            delete selection.ids[ key ];
            selection.count--;
            selection.allMatching = false;
        }
    }

    function selectOnly( ids ) {
        selection.ids   = {};
        selection.count = 0;
        ids.forEach( function ( id ) { setSelected( id, true ); } );
        selection.allMatching = true;
        syncCheckboxes();
    }

    function clearSelection() {
        selection.ids         = {};
        selection.count       = 0;
        selection.allMatching = false;
        syncCheckboxes();
    }

    function selectedIds() {
        return Object.keys( selection.ids ).map( function ( key ) {
            return parseInt( key.slice( 1 ), 10 );
        } );
    }

    function syncCheckboxes() {
        var locked = isRunning();
        var $boxes = $tbody.find( '.webscribeai-post-checkbox' );
        var allOn  = $boxes.length > 0;

        $boxes.each( function () {
            var on = !! selection.ids[ 'p' + this.value ];
            this.checked  = on;
            this.disabled = locked;
            if ( ! on ) {
                allOn = false;
            }
        } );

        $( '#webscribeai-page-toggle' ).prop( { checked: allOn, disabled: locked || ! $boxes.length } );
        $( '#webscribeai-selected-count' ).text( selection.count ? fmt( i18n.selectedCount, selection.count ) : '' );

        renderSelectBanner( allOn, $boxes.length );
    }

    // Gmail-style banner: makes selecting beyond the visible page explicit.
    function renderSelectBanner( pageAllOn, visible ) {
        var $banner = $( '#webscribeai-select-banner' ).empty();

        if ( ! isRunning() && selection.allMatching ) {
            $banner.append(
                document.createTextNode( fmt( i18n.allMatchingSelected, selection.count ) + ' ' ),
                $( '<button>' ).attr( { type: 'button', class: 'button-link', id: 'webscribeai-clear-selection' } ).text( i18n.clearSelection )
            );
        } else if ( ! isRunning() && pageAllOn && state.total > visible ) {
            $banner.append(
                document.createTextNode( fmt( i18n.pageSelected, visible ) + ' ' ),
                $( '<button>' ).attr( { type: 'button', class: 'button-link', id: 'webscribeai-select-matching' } ).text( fmt( i18n.selectAllMatching, state.total ) )
            );
        } else {
            $banner.prop( 'hidden', true );
            return;
        }

        $banner.prop( 'hidden', false );
    }

    $( document ).on( 'click', '#webscribeai-select-matching', function () {
        var $btn = $( this ).prop( 'disabled', true );

        if ( 'job' === state.mode ) {
            selectOnly( jobViewIds() );
            return;
        }

        fetchIds( false )
            .done( selectOnly )
            .fail( function () {
                $btn.prop( 'disabled', false );
                alert( i18n.loadError );
            } );
    } );

    $( document ).on( 'click', '#webscribeai-clear-selection', clearSelection );

    $( '#webscribeai-page-toggle' ).on( 'change', function () {
        var on = this.checked;
        $tbody.find( '.webscribeai-post-checkbox' ).each( function () {
            setSelected( parseInt( this.value, 10 ), on );
        } );
        syncCheckboxes();
    } );

    // Row checkboxes, with shift-click range selection.
    var lastClicked = null;

    $tbody.on( 'click', '.webscribeai-post-checkbox', function ( e ) {
        var on = this.checked;

        if ( e.shiftKey && lastClicked && lastClicked !== this && $.contains( $tbody[ 0 ], lastClicked ) ) {
            var $boxes = $tbody.find( '.webscribeai-post-checkbox' );
            var a      = $boxes.index( this );
            var b      = $boxes.index( lastClicked );

            $boxes.slice( Math.min( a, b ), Math.max( a, b ) + 1 ).each( function () {
                setSelected( parseInt( this.value, 10 ), on );
            } );
        } else {
            setSelected( parseInt( this.value, 10 ), on );
        }

        lastClicked = this;
        syncCheckboxes();
    } );

    // -------------------------------------------------------------------------
    // Starting jobs
    // -------------------------------------------------------------------------

    var ACTIONS = {
        generate_meta:        { kind: 'generate', field: 'both',        confirm: i18n.confirmGenerateMeta },
        generate_title:       { kind: 'generate', field: 'title',       confirm: i18n.confirmGenerateTitle },
        generate_description: { kind: 'generate', field: 'description', confirm: i18n.confirmGenerateDescription },
        remove_title:         { kind: 'remove',   field: 'title',       confirm: i18n.confirmRemoveTitle },
        remove_description:   { kind: 'remove',   field: 'description', confirm: i18n.confirmRemoveDescription },
        remove_all:           { kind: 'remove',   field: 'all',         confirm: i18n.confirmRemoveAll },
    };

    function overrideOn() {
        return $( '#webscribeai-bulk-override' ).prop( 'checked' );
    }

    $actionSelect.on( 'change', function () {
        $( '#webscribeai-bulk-apply' ).prop( 'disabled', isRunning() || '' === $( this ).val() );
    } );

    $( '#webscribeai-bulk-apply' ).on( 'click', function () {
        var action = ACTIONS[ $actionSelect.val() ];
        var ids    = selectedIds();

        if ( ! action ) {
            alert( i18n.chooseAction );
            return;
        }

        if ( ! ids.length ) {
            alert( i18n.noSelection );
            return;
        }

        if ( ! window.confirm( fmt( action.confirm, ids.length ) ) ) {
            return;
        }

        startJob( action.kind, ids, action.field, 'generate' === action.kind && overrideOn() );
    } );

    $( '#webscribeai-bulk-run-all' ).on( 'click', function () {
        var override = overrideOn();
        var $btn     = $( this ).prop( 'disabled', true );

        // In the job view the server skips posts that already have meta, so
        // the job's own rows can be sent as-is.
        var source = 'job' === state.mode
            ? $.Deferred().resolve( jobViewIds().slice() ).promise()
            : fetchIds( ! override );

        source
            .done( function ( ids ) {
                if ( ! ids.length ) {
                    alert( override ? i18n.viewEmpty : i18n.nothingToGenerate );
                    return;
                }

                if ( ! window.confirm( fmt( i18n.confirmGenerateMeta, ids.length ) ) ) {
                    return;
                }

                startJob( 'generate', ids, 'both', override );
            } )
            .fail( function () {
                alert( i18n.loadError );
            } )
            .always( function () {
                if ( ! isRunning() ) {
                    $btn.prop( 'disabled', ! cfg.canGenerate );
                }
            } );
    } );

    // Disable everything that would change what the running job is doing.
    function setLocked( locked ) {
        $( '#webscribeai-bulk-override, #webscribeai-filter-type, #webscribeai-filter-search' ).prop( 'disabled', locked );
        $actionSelect.prop( 'disabled', locked );
        $( '#webscribeai-bulk-apply' ).prop( 'disabled', locked || '' === $actionSelect.val() );
        $( '#webscribeai-bulk-run-all' ).prop( 'disabled', locked || ! cfg.canGenerate );
        $( '.webscribeai-bulk-wrap' ).toggleClass( 'is-running', locked );
        syncCheckboxes();
    }

    // -------------------------------------------------------------------------
    // Job engine — processes posts one at a time to stay within API rate limits.
    // -------------------------------------------------------------------------

    function startJob( kind, ids, field, override ) {
        job = {
            kind:          kind,     // 'generate' | 'remove'
            ids:           ids,
            field:         field,
            override:      override,
            index:         0,        // Position of the post being processed.
            running:       true,
            stopRequested: false,
            succeeded:     0,
            skipped:       0,
            failed:        [],       // [ { post_id, message } ]
            limitMessage:  '',
            startedAt:     Date.now(),
            itemStartedAt: 0,        // When the current post's request was sent.
            ticker:        setInterval( tick, 1000 ),
            view:          'all',    // Job view filter: 'all' | 'errors'.
            follow:        true,     // Keep the page holding the current post in view.
        };

        ids.forEach( function ( id ) {
            setRowStatus( id, 'queued', i18n.queued );
        } );

        // Persist the queue so the run can be resumed if the user navigates away.
        if ( 'generate' === kind ) {
            saveQueue( ids, override, field );
        }

        $( '#webscribeai-resume-notice, #webscribeai-last-errors-notice' ).remove();

        clearSelection();
        resetRunPanel();
        setLocked( true );

        state.mode = 'job';
        state.page = 1;
        renderJobBar();
        loadRows();

        processNext();
    }

    function processNext() {
        if ( job.stopRequested || job.index >= job.ids.length ) {
            finishJob();
            return;
        }

        var id = job.ids[ job.index ];

        job.itemStartedAt = Date.now();
        setRowStatus( id, 'processing', i18n.processing );
        followProgress();
        updateRunPanel();

        var request = 'generate' === job.kind
            ? post( {
                action:   'webscribeai_bulk_generate',
                post_id:  id,
                override: job.override ? 'true' : 'false',
                field:    job.field,
            }, REQUEST_TIMEOUT )
            : post( {
                action:  'webscribeai_bulk_remove',
                post_id: id,
                field:   job.field,
            }, REQUEST_TIMEOUT );

        // .then() rather than .done()/.always(): an exception while handling
        // one result must not stop the loop, and jQuery skips the remaining
        // .done()/.always() callbacks when one of them throws.
        request.then(
            function ( response ) {
                try {
                    handleResult( id, response );
                } catch ( e ) {
                    window.console && console.error( e );
                    recordFailure( id, e.message );
                }
            },
            function ( xhr, status ) {
                recordFailure( id, 'timeout' === status ? i18n.timedOut : '' );
            }
        ).always( function () {
            job.index++;
            job.itemStartedAt = 0;
            updateRunPanel();
            // Small delay to be polite to the API.
            setTimeout( processNext, 300 );
        } );
    }

    function handleResult( id, response ) {
        var data = response.data || {};

        if ( data.post_title ) {
            titles[ id ] = data.post_title;
        }

        if ( data.usage ) {
            updateUsage( data.usage );
        }

        if ( ! response.success ) {
            recordFailure( id, data.message || '' );

            if ( data.limit_reached ) {
                // Retrying the rest would just hit the same monthly cap —
                // stop the loop instead of burning through every remaining row.
                job.stopRequested = true;
                job.limitMessage  = data.message;
            }
            return;
        }

        if ( 'remove' === job.kind ) {
            job.succeeded++;
            setRowStatus( id, 'success', i18n.removed );
            clearRowMetaField( id, job.field );
            pushFeed( id, 'success', i18n.removed );
        } else if ( 'skipped' === data.status ) {
            job.skipped++;
            setRowStatus( id, 'skipped', i18n.skipped );
            pushFeed( id, 'skipped', i18n.skipped );
        } else {
            job.succeeded++;
            setRowStatus( id, 'success', i18n.success );
            updateRowMeta( id, job.field, data.title, data.description );
            pushFeed( id, 'success', data.title || data.description || i18n.success );
        }
    }

    function recordFailure( id, message ) {
        var text = i18n.error + ( message ? ': ' + message : '' );
        job.failed.push( { post_id: id, message: message } );
        setRowStatus( id, 'error', text );
        pushFeed( id, 'error', text );
    }

    function finishJob() {
        var stopped = job.index < job.ids.length;
        var title;

        job.running = false;
        clearInterval( job.ticker );

        // Posts the job never reached go back to having no status.
        job.ids.slice( job.index ).forEach( clearRowStatus );

        if ( 'generate' === job.kind ) {
            clearQueue();
            saveErrors( job.failed );
            title = job.limitMessage || ( stopped ? i18n.bulkStopped : i18n.bulkComplete );
        } else {
            title = stopped ? i18n.bulkRemoveStopped : i18n.bulkRemoveComplete;
        }

        setLocked( false );
        finishRunPanel( title, stopped );
        renderJobBar();

        // Only pop the modal if any work actually happened — an immediate
        // Stop click shouldn't need dismissing.
        if ( 'generate' === job.kind && job.index > 0 ) {
            showCompletionModal( title );
        }
    }

    function followProgress() {
        if ( 'job' !== state.mode || ! job.follow || 'all' !== job.view ) {
            return;
        }

        var target = Math.floor( job.index / PER_PAGE ) + 1;

        if ( target !== state.page ) {
            state.page = target;
            loadRows();
        }
    }

    $( '#webscribeai-stop-btn' ).on( 'click', function () {
        if ( isRunning() ) {
            job.stopRequested = true;
            $( this ).prop( 'disabled', true );
        }
    } );

    // -------------------------------------------------------------------------
    // Run panel (sticky progress, current post, ETA and a feed of recent results)
    // -------------------------------------------------------------------------

    var $panel = $( '#webscribeai-run-panel' );

    var FEED_ICONS = {
        success: 'dashicons-yes',
        skipped: 'dashicons-minus',
        error:   'dashicons-warning',
    };

    function resetRunPanel() {
        $( '#webscribeai-run-feed' ).empty();
        $( '#webscribeai-run-title' ).text( 'generate' === job.kind ? i18n.runGenerating : i18n.runRemoving );
        $( '#webscribeai-stop-btn' ).prop( { hidden: false, disabled: false } );
        $( '#webscribeai-run-close' ).prop( 'hidden', true );
        $panel.removeClass( 'is-finished is-warning' ).prop( 'hidden', false );
        updateRunPanel();
    }

    function updateRunPanel() {
        var total = job.ids.length;
        var done  = job.index;
        var pct   = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
        var stats = 'generate' === job.kind
            ? fmt( i18n.statsGenerate, job.succeeded, job.skipped, job.failed.length )
            : fmt( i18n.statsRemove, job.succeeded, job.failed.length );

        if ( job.running ) {
            var eta = etaText();
            if ( eta ) {
                stats += ' · ' + eta;
            }
        }

        var current = job.running && done < total ? job.ids[ done ] : null;
        var label   = '';

        if ( current ) {
            label = fmt( i18n.runCurrent, titles[ current ] || '#' + current );

            // AI calls can take a while — show the post is still being worked on.
            var elapsed = currentElapsed();
            if ( elapsed >= 3 ) {
                label += ' · ' + fmt( i18n.elapsed, elapsed );
            }
        }

        $( '#webscribeai-progress-fill' ).css( 'width', pct + '%' );
        $( '#webscribeai-run-counter' ).text( done + ' / ' + total );
        $( '#webscribeai-run-stats' ).text( stats );
        $( '#webscribeai-run-current' ).text( label );
    }

    function currentElapsed() {
        return job.itemStartedAt ? Math.floor( ( Date.now() - job.itemStartedAt ) / 1000 ) : 0;
    }

    // Once a second while a job runs: refresh the panel and the elapsed time
    // on the row being processed.
    function tick() {
        if ( ! isRunning() ) {
            return;
        }

        updateRunPanel();

        var id = job.ids[ job.index ];
        if ( id && rowState[ id ] && 'processing' === rowState[ id ].status && currentElapsed() >= 3 ) {
            $( '#webscribeai-result-' + id ).text( i18n.processing + ' ' + fmt( i18n.elapsed, currentElapsed() ) );
        }
    }

    function etaText() {
        if ( job.index < 3 ) {
            return ''; // Too early for a meaningful estimate.
        }

        var perItem = ( Date.now() - job.startedAt ) / job.index;
        var seconds = Math.round( ( perItem * ( job.ids.length - job.index ) ) / 1000 );

        if ( seconds < 60 ) {
            return i18n.etaSoon;
        }

        var minutes = Math.round( seconds / 60 );

        if ( minutes < 60 ) {
            return fmt( i18n.etaMinutes, minutes );
        }

        return fmt( i18n.etaHours, Math.floor( minutes / 60 ), minutes % 60 );
    }

    function finishRunPanel( title, stopped ) {
        $( '#webscribeai-run-title' ).text( title );
        $( '#webscribeai-stop-btn' ).prop( 'hidden', true );
        $( '#webscribeai-run-close' ).prop( 'hidden', false );
        $panel.addClass( 'is-finished' ).toggleClass( 'is-warning', stopped || job.failed.length > 0 );
        updateRunPanel();
    }

    function pushFeed( id, status, detail ) {
        var $feed = $( '#webscribeai-run-feed' );

        $( '<li>' ).addClass( 'is-' + status ).append(
            $( '<span>' ).addClass( 'dashicons ' + FEED_ICONS[ status ] ).attr( 'aria-hidden', 'true' ),
            $( '<span>' ).addClass( 'webscribeai-run-feed-post' ).text( titles[ id ] || '#' + id ),
            $( '<span>' ).addClass( 'webscribeai-run-feed-detail' ).text( detail || '' )
        ).prependTo( $feed );

        $feed.children().slice( 5 ).remove();
    }

    $( '#webscribeai-run-close' ).on( 'click', function () {
        $panel.prop( 'hidden', true );
    } );

    // -------------------------------------------------------------------------
    // Job view bar (shown instead of the filters while viewing a job's rows)
    // -------------------------------------------------------------------------

    function renderJobBar() {
        var inJob = 'job' === state.mode;

        $( '#webscribeai-browse-bar' ).prop( 'hidden', inJob );
        $( '#webscribeai-job-bar' ).prop( 'hidden', ! inJob );

        if ( ! inJob ) {
            return;
        }

        var running = isRunning();

        $( '#webscribeai-job-label' ).text( running ? fmt( i18n.jobRunning, job.ids.length ) : i18n.jobFinished );
        $( '#webscribeai-follow-wrap' ).prop( 'hidden', ! running );
        $( '#webscribeai-follow' ).prop( 'checked', job.follow );
        $( '#webscribeai-job-filters' ).prop( 'hidden', running );
        $( '#webscribeai-job-show-all' )
            .text( fmt( i18n.jobShowAll, job.ids.length ) )
            .toggleClass( 'is-active', 'all' === job.view );
        $( '#webscribeai-job-show-errors' )
            .text( fmt( i18n.jobShowErrors, job.failed.length ) )
            .toggleClass( 'is-active', 'errors' === job.view )
            .prop( 'disabled', 0 === job.failed.length );
        $( '#webscribeai-job-exit' ).prop( 'hidden', running );
    }

    function setJobView( view ) {
        job.view   = view;
        state.page = 1;
        clearSelection();
        renderJobBar();
        loadRows();
    }

    $( '#webscribeai-follow' ).on( 'change', function () {
        job.follow = this.checked;
        followProgress();
    } );

    $( '#webscribeai-job-show-all' ).on( 'click', function () {
        setJobView( 'all' );
    } );

    $( '#webscribeai-job-show-errors' ).on( 'click', function () {
        setJobView( 'errors' );
    } );

    $( '#webscribeai-job-exit' ).on( 'click', function () {
        state.mode = 'browse';
        state.page = 1;
        clearSelection();
        renderJobBar();
        loadRows(); // Also refreshes the view counts.
    } );

    // -------------------------------------------------------------------------
    // Row status
    // -------------------------------------------------------------------------

    var ROW_CLASSES  = 'webscribeai-queued webscribeai-processing webscribeai-done webscribeai-errored';
    var CELL_CLASSES = 'is-queued is-processing is-success is-skipped is-error';

    var STATUS_CLASSES = {
        queued:     [ 'webscribeai-queued', 'is-queued' ],
        processing: [ 'webscribeai-processing', 'is-processing' ],
        success:    [ 'webscribeai-done', 'is-success' ],
        skipped:    [ 'webscribeai-done', 'is-skipped' ],
        error:      [ 'webscribeai-errored', 'is-error' ],
    };

    function setRowStatus( postId, status, text ) {
        rowState[ postId ] = { status: status, text: text };

        var $row = $( '#webscribeai-row-' + postId );
        if ( $row.length ) {
            paintRow( $row, rowState[ postId ] );
        }
    }

    function clearRowStatus( postId ) {
        delete rowState[ postId ];

        var $row = $( '#webscribeai-row-' + postId );
        if ( $row.length ) {
            paintRow( $row, null );
        }
    }

    function paintRow( $row, rowStatus ) {
        var $cell = $row.find( '.webscribeai-cell-result' );

        $row.removeClass( ROW_CLASSES );
        $cell.removeClass( CELL_CLASSES ).text( '' );

        if ( ! rowStatus ) {
            return;
        }

        var classes = STATUS_CLASSES[ rowStatus.status ];
        $row.addClass( classes[ 0 ] );
        $cell.addClass( classes[ 1 ] ).text( rowStatus.text );
    }

    function isRowBusy( postId ) {
        var rowStatus = rowState[ postId ];
        return !! rowStatus && ( 'queued' === rowStatus.status || 'processing' === rowStatus.status );
    }

    // -------------------------------------------------------------------------
    // Completion modal — summarizes a finished bulk generation run.
    // -------------------------------------------------------------------------

    function buildModalStat( num, label, cssClass ) {
        return $( '<div>' ).addClass( 'webscribeai-modal-stat ' + cssClass ).append(
            $( '<span>' ).addClass( 'webscribeai-modal-stat-num' ).text( num ),
            $( '<span>' ).addClass( 'webscribeai-modal-stat-label' ).text( label )
        );
    }

    function showCompletionModal( title ) {
        var hasErrors = job.failed.length > 0;

        $( '#webscribeai-modal-icon' )
            .toggleClass( 'is-warning', hasErrors )
            .html( '<span class="dashicons ' + ( hasErrors ? 'dashicons-warning' : 'dashicons-yes-alt' ) + '"></span>' );

        $( '#webscribeai-modal-title' ).text( title );

        $( '#webscribeai-modal-stats' ).empty().append(
            buildModalStat( job.succeeded, i18n.modalGenerated, 'is-generated' ),
            buildModalStat( job.skipped, i18n.modalSkipped, 'is-skipped' ),
            buildModalStat( job.failed.length, i18n.modalErrors, 'is-errors' )
        );

        $( '#webscribeai-modal-note' ).text( hasErrors ? i18n.modalErrorsNote : '' ).prop( 'hidden', ! hasErrors );
        $( '#webscribeai-modal-ok-btn' ).text( i18n.modalOk );

        $( '#webscribeai-bulk-modal-overlay' ).show();
        $( '#webscribeai-modal-ok-btn' ).trigger( 'focus' );
    }

    $( '#webscribeai-modal-ok-btn' ).on( 'click', function () {
        $( '#webscribeai-bulk-modal-overlay' ).hide();
    } );

    // -------------------------------------------------------------------------
    // Usage count — updated from each generation response instead of a reload.
    // -------------------------------------------------------------------------

    function updateUsage( usage ) {
        var reached = usage.count >= usage.limit;

        $( '.webscribeai-usage-summary' )
            .toggleClass( 'notice-warning', reached )
            .toggleClass( 'notice-info', ! reached )
            .find( 'p' ).text( fmt( i18n.usageSummary, usage.count, usage.limit ) );

        if ( reached && cfg.canGenerate ) {
            cfg.canGenerate = false;
            $actionSelect.find( 'option[value^="generate_"]' ).prop( 'disabled', true );
            if ( 0 === String( $actionSelect.val() ).indexOf( 'generate_' ) ) {
                $actionSelect.val( '' );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Persistence helpers
    // -------------------------------------------------------------------------

    function saveQueue( postIds, override, field ) {
        post( {
            action:   'webscribeai_save_queue',
            pending:  postIds,
            override: override ? 'true' : 'false',
            field:    field || 'both',
        } );
    }

    function clearQueue() {
        post( { action: 'webscribeai_clear_queue' } );
    }

    // Persists the failed rows from this run (or clears the stored list if
    // none failed). They back the "Errors" view until the next run.
    function saveErrors( entries ) {
        return post( {
            action: 'webscribeai_save_errors',
            errors: entries,
        } );
    }

    // -------------------------------------------------------------------------
    // Notices: resumable job and errors from the last run
    // -------------------------------------------------------------------------

    if ( cfg.savedQueue && cfg.savedQueue.pending.length > 0 ) {
        $( '<div>' )
            .attr( 'id', 'webscribeai-resume-notice' )
            .addClass( 'notice notice-warning' )
            .append(
                $( '<p>' ).append(
                    $( '<strong>' ).text( i18n.resumeNotice ),
                    document.createTextNode( ' ' + fmt( i18n.resumePosts, cfg.savedQueue.pending.length ) + ' ' ),
                    $( '<button>' ).attr( { type: 'button', class: 'button button-primary', id: 'webscribeai-resume-btn' } ).text( i18n.resumeBtn ),
                    document.createTextNode( ' ' ),
                    $( '<button>' ).attr( { type: 'button', class: 'button', id: 'webscribeai-dismiss-resume-btn' } ).text( i18n.dismissBtn )
                )
            )
            .prependTo( '.webscribeai-bulk-wrap' );
    }

    $( document ).on( 'click', '#webscribeai-resume-btn', function () {
        startJob( 'generate', cfg.savedQueue.pending, cfg.savedQueue.field || 'both', cfg.savedQueue.override );
    } );

    $( document ).on( 'click', '#webscribeai-dismiss-resume-btn', function () {
        $( '#webscribeai-resume-notice' ).remove();
        clearQueue();
    } );

    if ( cfg.lastErrorCount > 0 ) {
        $( '<div>' )
            .attr( 'id', 'webscribeai-last-errors-notice' )
            .addClass( 'notice notice-error' )
            .append(
                $( '<p>' ).append(
                    $( '<strong>' ).text( fmt( i18n.lastErrorsCount, cfg.lastErrorCount ) ),
                    document.createTextNode( ' ' ),
                    $( '<button>' ).attr( { type: 'button', class: 'button', id: 'webscribeai-show-last-errors-btn' } ).text( i18n.showErrorsBtn ),
                    document.createTextNode( ' ' ),
                    $( '<button>' ).attr( { type: 'button', class: 'button', id: 'webscribeai-dismiss-last-errors-btn' } ).text( i18n.dismissBtn )
                )
            )
            .prependTo( '.webscribeai-bulk-wrap' );
    }

    $( document ).on( 'click', '#webscribeai-show-last-errors-btn', function () {
        setFilter( 'view', 'errors' );
    } );

    $( document ).on( 'click', '#webscribeai-dismiss-last-errors-btn', function () {
        $( '#webscribeai-last-errors-notice' ).remove();
        saveErrors( [] ).always( function () {
            if ( 'errors' === state.filters.view ) {
                state.filters.view = 'all';
            }
            if ( 'browse' === state.mode ) {
                loadRows();
            }
        } );
    } );

    // -------------------------------------------------------------------------
    // SEO length validity bar
    // -------------------------------------------------------------------------

    function buildValidityBar( text, limit, warnLimit ) {
        var length = text.length;
        var $bar   = $( '<div>' ).addClass( 'webscribeai-vbar' );
        var $fill  = $( '<div>' ).addClass( 'webscribeai-vbar-fill' );

        if ( 0 === length ) {
            return $bar.addClass( 'is-empty' ).append( $fill );
        }

        var barState;
        if ( length <= limit ) {
            barState = 'is-good';
        } else if ( length <= warnLimit ) {
            barState = 'is-warn';
        } else {
            barState = 'is-bad';
        }

        $fill.css( 'width', Math.min( 100, ( length / limit ) * 100 ) + '%' );
        $bar.addClass( barState ).attr( 'title', length + ' / ' + limit + ' ' + i18n.characters );

        return $bar.append( $fill );
    }

    function renderMetaCell( $cell, text, limit, warnLimit ) {
        var $text = $( '<span>' ).addClass( 'webscribeai-meta-text' ).text( text );
        $cell.empty().append( $text, buildValidityBar( text, limit, warnLimit ) );
    }

    // Live update while typing in the inline editor.
    $( document ).on( 'input', '.webscribeai-edit-title', function () {
        $( this ).siblings( '.webscribeai-vbar' )
            .replaceWith( buildValidityBar( $( this ).val(), seoLimits.title, seoLimits.titleWarn ) );
    } );

    $( document ).on( 'input', '.webscribeai-edit-desc', function () {
        $( this ).siblings( '.webscribeai-vbar' )
            .replaceWith( buildValidityBar( $( this ).val(), seoLimits.description, seoLimits.descriptionWarn ) );
    } );

    function updateRowMeta( postId, field, title, description ) {
        var $row = $( '#webscribeai-row-' + postId );

        // title/description come back null when that field was left untouched
        // (already had a value and override wasn't requested) — don't blank the cell.
        if ( ( 'title' === field || 'both' === field ) && 'string' === typeof title ) {
            renderMetaCell( $row.find( '.webscribeai-cell-title' ), title, seoLimits.title, seoLimits.titleWarn );
        }

        if ( ( 'description' === field || 'both' === field ) && 'string' === typeof description ) {
            renderMetaCell( $row.find( '.webscribeai-cell-desc' ), description, seoLimits.description, seoLimits.descriptionWarn );
        }

        // Show edit button if not already in edit mode.
        if ( $row.length && ! $row.hasClass( 'webscribeai-editing' ) ) {
            var $actionsCell = $row.find( '.webscribeai-cell-actions' );
            if ( 0 === $actionsCell.find( '.webscribeai-edit-btn' ).length ) {
                $actionsCell.html( makeEditBtn( postId ) );
            }
        }
    }

    function clearRowMetaField( postId, field ) {
        var $row = $( '#webscribeai-row-' + postId );

        if ( 'title' === field || 'all' === field ) {
            renderMetaCell( $row.find( '.webscribeai-cell-title' ), '', seoLimits.title, seoLimits.titleWarn );
        }

        if ( 'description' === field || 'all' === field ) {
            renderMetaCell( $row.find( '.webscribeai-cell-desc' ), '', seoLimits.description, seoLimits.descriptionWarn );
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
            title:          i18n.edit,
        } ).html( '<span class="dashicons dashicons-edit"></span>' );
    }

    function exitEditMode( $row, title, desc ) {
        var postId = $row.attr( 'data-post-id' );
        $row.removeClass( 'webscribeai-editing' );
        renderMetaCell( $row.find( '.webscribeai-cell-title' ), title, seoLimits.title, seoLimits.titleWarn );
        renderMetaCell( $row.find( '.webscribeai-cell-desc' ), desc, seoLimits.description, seoLimits.descriptionWarn );
        $row.find( '.webscribeai-cell-actions' ).html( makeEditBtn( postId ) );
    }

    // Enter edit mode.
    $( document ).on( 'click', '.webscribeai-edit-btn', function () {
        var postId = $( this ).data( 'post-id' );
        var $row   = $( '#webscribeai-row-' + postId );

        // A queued or running row is about to be overwritten by the job.
        if ( $row.hasClass( 'webscribeai-editing' ) || isRowBusy( postId ) ) {
            return;
        }

        var origTitle = $row.find( '.webscribeai-cell-title .webscribeai-meta-text' ).text();
        var origDesc  = $row.find( '.webscribeai-cell-desc .webscribeai-meta-text' ).text();

        $row.data( { 'orig-title': origTitle, 'orig-desc': origDesc } ).addClass( 'webscribeai-editing' );

        $row.find( '.webscribeai-cell-title' ).html(
            $( '<input>' ).attr( { type: 'text', class: 'webscribeai-edit-title widefat', maxlength: 100 } ).val( origTitle )
        ).append( buildValidityBar( origTitle, seoLimits.title, seoLimits.titleWarn ) );
        $row.find( '.webscribeai-cell-desc' ).html(
            $( '<textarea>' ).attr( { class: 'webscribeai-edit-desc widefat', rows: 2, maxlength: 250 } ).val( origDesc )
        ).append( buildValidityBar( origDesc, seoLimits.description, seoLimits.descriptionWarn ) );
        $row.find( '.webscribeai-cell-actions' ).html(
            $( '<div>' ).addClass( 'webscribeai-edit-actions' )
                .append( $( '<button>' ).attr( { type: 'button', class: 'button-link webscribeai-save-btn', 'data-post-id': postId, title: i18n.save } ).html( '<span class="dashicons dashicons-yes-alt"></span>' ) )
                .append( $( '<button>' ).attr( { type: 'button', class: 'button-link webscribeai-cancel-btn', 'data-post-id': postId, title: i18n.cancel } ).html( '<span class="dashicons dashicons-no-alt"></span>' ) )
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

        post( {
            action:      'webscribeai_apply',
            post_id:     postId,
            title:       newTitle,
            description: newDesc,
            override:    'true',
        } )
        .done( function ( response ) {
            if ( response.success ) {
                exitEditMode( $row, newTitle, newDesc );
                setRowStatus( postId, 'success', i18n.saved );
            } else {
                $btn.prop( 'disabled', false ).find( '.dashicons' ).removeClass( 'dashicons-update-alt' ).addClass( 'dashicons-yes-alt' );
                var msg = ( response.data && response.data.message ) ? response.data.message : '';
                setRowStatus( postId, 'error', i18n.editError + ( msg ? ': ' + msg : '' ) );
            }
        } )
        .fail( function () {
            $btn.prop( 'disabled', false ).find( '.dashicons' ).removeClass( 'dashicons-update-alt' ).addClass( 'dashicons-yes-alt' );
            setRowStatus( postId, 'error', i18n.editError );
        } );
    } );

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    renderJobBar();
    loadRows();

}( jQuery ) );
