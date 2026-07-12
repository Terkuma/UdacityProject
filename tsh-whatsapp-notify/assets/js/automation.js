/**
 * TSH WhatsApp Notify — Phase 7 Workflow Automation Engine JS
 *
 * Visual node-based workflow builder with drag-drop, SVG connections,
 * zoom, minimap, undo/redo, auto-save, and full AJAX integration.
 *
 * @package TSH\WhatsAppNotify
 */

/* global tshWaAutomation, jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof tshWaAutomation === 'undefined' ) {
		return;
	}

	var cfg  = tshWaAutomation;
	var i18n = cfg.i18n || {};
	var $doc = $( document );

	// =========================================================================
	// Utilities
	// =========================================================================

	function escHtml( str ) {
		return $( '<div>' ).text( String( str || '' ) ).html();
	}

	function uid() {
		return 'n_' + Math.random().toString( 36 ).substr( 2, 9 );
	}

	function ajaxPost( action, data, successCb, errorCb ) {
		var payload = $.extend( {}, data, {
			action      : action,
			_ajax_nonce : cfg.nonce,
		} );
		$.post( cfg.ajaxUrl, payload )
			.done( function ( res ) {
				if ( res && res.success ) {
					if ( successCb ) { successCb( res.data ); }
				} else {
					var msg = ( res && res.data && res.data.message ) ? res.data.message : i18n.error;
					if ( errorCb ) { errorCb( msg ); } else { showToast( msg, 'error' ); }
				}
			} )
			.fail( function () {
				if ( errorCb ) { errorCb( i18n.error ); } else { showToast( i18n.error, 'error' ); }
			} );
	}

	function showToast( msg, type ) {
		type = type || 'success';
		var $t = $( '<div class="tsh-wa-toast tsh-wa-toast--' + escHtml( type ) + '">' + escHtml( msg ) + '</div>' );
		$( 'body' ).append( $t );
		setTimeout( function () { $t.fadeOut( 300, function () { $t.remove(); } ); }, 3500 );
	}

	function statusBadge( status ) {
		var map = {
			active    : 'success',
			inactive  : 'warning',
			draft     : 'grey',
			completed : 'success',
			failed    : 'danger',
			pending   : 'grey',
			running   : 'warning',
			cancelled : 'grey',
		};
		var cls = map[ status ] || 'grey';
		return '<span class="tsh-wa-badge tsh-wa-badge--' + cls + '">' + escHtml( ucFirst( status ) ) + '</span>';
	}

	function ucFirst( s ) {
		s = String( s || '' );
		return s.charAt( 0 ).toUpperCase() + s.slice( 1 );
	}

	function formatDuration( ms ) {
		if ( ! ms ) { return '—'; }
		ms = parseInt( ms, 10 );
		if ( ms < 1000 ) { return ms + 'ms'; }
		return ( ms / 1000 ).toFixed( 1 ) + 's';
	}

	// =========================================================================
	// State
	// =========================================================================

	var state = {
		// Current workflow being edited.
		workflowId        : null,
		workflowStatus    : 'draft',
		nodes             : {},   // id → node object
		edges             : [],   // [ { id, from, fromPort, to, toPort } ]
		// Canvas transform.
		zoom              : 1,
		panX              : 0,
		panY              : 0,
		// Interaction.
		draggingNodeId    : null,
		dragOffX          : 0,
		dragOffY          : 0,
		connecting        : null,   // { fromNodeId, fromPort, x, y }
		selectedNodeId    : null,
		selectedEdgeId    : null,
		// Undo/redo.
		history           : [],
		historyIndex      : -1,
		maxHistory        : 50,
		// Auto-save.
		autoSaveTimer     : null,
		autoSaveDirty     : false,
		// Panning.
		isPanning         : false,
		panStartX         : 0,
		panStartY         : 0,
		panStartViewX     : 0,
		panStartViewY     : 0,
		// List view.
		listPage          : 1,
		listStatus        : 'all',
		listSearch        : '',
		listTimer         : null,
		// History view.
		historyWfId       : null,
		historyWfName     : '',
		historyPage       : 1,
	};

	// =========================================================================
	// DOM references — populated in init()
	// =========================================================================

	var $listView, $builderView, $historyView;
	var $wfTableBody, $canvas, $canvasSvg, $canvasWrap, $zoomLevel;
	var $minimap, $minimapCanvas, $configPanel, $configBody, $configTitle;
	var $builderName, $builderStatus, $autosave, $historyBody;

	// =========================================================================
	// VIEW SWITCHING
	// =========================================================================

	function showView( view ) {
		$listView.hide();
		$builderView.hide();
		$historyView.hide();
		if ( 'list'    === view ) { $listView.show();    }
		if ( 'builder' === view ) { $builderView.show(); }
		if ( 'history' === view ) { $historyView.show(); }
	}

	// =========================================================================
	// LIST VIEW
	// =========================================================================

	function loadWorkflowList() {
		ajaxPost( 'tsh_wa_wf_list', {
			page    : state.listPage,
			per_page: 50,
			status  : state.listStatus,
			search  : state.listSearch,
		}, function ( data ) {
			renderWorkflowTable( data.workflows || [], data.total || 0 );
		} );
	}

	function renderWorkflowTable( workflows, total ) {
		$( '#tsh-wa-wf-total' ).text( total + ' ' + ( 1 === total ? 'workflow' : 'workflows' ) );

		if ( ! workflows.length ) {
			$wfTableBody.html(
				'<tr id="tsh-wa-wf-empty-row"><td colspan="6" class="tsh-wa-wf-empty">' +
				'<div class="tsh-wa-wf-empty-state">' +
				'<span class="dashicons dashicons-controls-play tsh-wa-wf-empty-icon"></span>' +
				'<p>' + escHtml( i18n.no_history ) + '</p>' +
				'<button type="button" class="button button-primary" id="tsh-wa-wf-first-create">Create First Workflow</button>' +
				'</div></td></tr>'
			);
			return;
		}

		var html = '';
		$.each( workflows, function ( i, wf ) {
			var trigger    = ( cfg.triggers && cfg.triggers[ wf.trigger_type ] )
				? cfg.triggers[ wf.trigger_type ].label
				: wf.trigger_type;
			var lastRun    = wf.last_run_at ? wf.last_run_at : '—';
			var statusBadgeHtml = statusBadge( wf.status );
			var toggleBtn  = 'active' === wf.status
				? '<button type="button" class="button button-small tsh-wa-wf-deactivate-btn" data-id="' + escHtml( wf.id ) + '" title="Deactivate"><span class="dashicons dashicons-controls-pause"></span></button>'
				: '<button type="button" class="button button-small tsh-wa-wf-activate-btn" data-id="' + escHtml( wf.id ) + '" title="Activate"><span class="dashicons dashicons-controls-play"></span></button>';

			html += '<tr class="tsh-wa-wf-row" data-id="' + escHtml( wf.id ) + '">' +
				'<td class="column-name"><strong><a href="#" class="tsh-wa-wf-edit-link" data-id="' + escHtml( wf.id ) + '">' + escHtml( wf.name ) + '</a></strong>' +
				( wf.description ? '<p class="tsh-wa-wf-desc">' + escHtml( wf.description ) + '</p>' : '' ) + '</td>' +
				'<td class="column-trigger"><span class="tsh-wa-wf-trigger-tag">' + escHtml( trigger ) + '</span></td>' +
				'<td class="column-status">' + statusBadgeHtml + '</td>' +
				'<td class="column-runs">' + escHtml( wf.run_count || 0 ) + '</td>' +
				'<td class="column-last-run">' + escHtml( lastRun ) + '</td>' +
				'<td class="column-actions"><div class="tsh-wa-wf-row-actions">' +
				'<button type="button" class="button button-small tsh-wa-wf-edit-btn" data-id="' + escHtml( wf.id ) + '" title="Edit"><span class="dashicons dashicons-edit"></span></button>' +
				toggleBtn +
				'<button type="button" class="button button-small tsh-wa-wf-history-btn" data-id="' + escHtml( wf.id ) + '" data-name="' + escHtml( wf.name ) + '" title="History"><span class="dashicons dashicons-list-view"></span></button>' +
				'<button type="button" class="button button-small tsh-wa-wf-duplicate-btn" data-id="' + escHtml( wf.id ) + '" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>' +
				'<button type="button" class="button button-small tsh-wa-wf-delete-btn" data-id="' + escHtml( wf.id ) + '" data-name="' + escHtml( wf.name ) + '" title="Delete"><span class="dashicons dashicons-trash"></span></button>' +
				'</div></td></tr>';
		} );

		$wfTableBody.html( html );
	}

	// =========================================================================
	// BUILDER — INIT / LOAD / SAVE
	// =========================================================================

	function openBuilder( workflowId ) {
		state.workflowId   = workflowId || null;
		state.nodes        = {};
		state.edges        = [];
		state.selectedNodeId = null;
		state.selectedEdgeId = null;
		clearHistory();
		hideConfigPanel();
		showView( 'builder' );

		if ( workflowId ) {
			ajaxPost( 'tsh_wa_wf_get', { workflow_id: workflowId }, function ( data ) {
				var wf = data.workflow;
				$builderName.val( wf.name || '' );
				$builderStatus.val( wf.status || 'draft' );
				state.workflowStatus = wf.status;

				// Load nodes.
				var nodes = wf.nodes || [];
				$.each( nodes, function ( i, n ) {
					state.nodes[ n.id ] = n;
				} );

				// Load edges.
				state.edges = wf.edges || [];

				renderAllNodes();
				renderAllEdges();
				fitCanvas();
			} );
		} else {
			// New workflow.
			$builderName.val( '' );
			$builderStatus.val( 'draft' );
			state.workflowStatus = 'draft';
			$canvas.empty();
			clearSvgEdges();
			fitCanvas();
		}
	}

	function saveWorkflow( onDone, silent ) {
		var name = $builderName.val().trim();
		if ( ! name ) { name = i18n.untitled; }

		var nodes = Object.values( state.nodes );
		var payload = {
			name       : name,
			status     : $builderStatus.val(),
			nodes      : JSON.stringify( nodes ),
			edges      : JSON.stringify( state.edges ),
		};

		if ( state.workflowId ) {
			payload.workflow_id = state.workflowId;
		}

		var action = state.workflowId ? 'tsh_wa_wf_update' : 'tsh_wa_wf_create';

		if ( ! silent ) {
			setAutosaveLabel( i18n.saving );
		}

		ajaxPost( action, payload, function ( data ) {
			if ( data.workflow_id ) {
				state.workflowId = data.workflow_id;
			}
			state.autoSaveDirty = false;
			setAutosaveLabel( i18n.auto_saved );
			if ( ! silent ) { showToast( i18n.saved, 'success' ); }
			if ( onDone ) { onDone(); }
		}, function ( err ) {
			setAutosaveLabel( '' );
			showToast( err, 'error' );
		} );
	}

	function setAutosaveLabel( msg ) {
		$autosave.text( msg );
		if ( i18n.auto_saved === msg ) {
			$autosave.addClass( 'saved' );
		} else {
			$autosave.removeClass( 'saved' );
		}
	}

	function markDirty() {
		state.autoSaveDirty = true;
		clearTimeout( state.autoSaveTimer );
		state.autoSaveTimer = setTimeout( function () {
			if ( state.autoSaveDirty ) {
				saveWorkflow( null, true );
			}
		}, cfg.autoSaveDelay || 3000 );
	}

	// =========================================================================
	// CANVAS — COORDINATE UTILITIES
	// =========================================================================

	function applyTransform() {
		$canvas.css( 'transform', 'translate(' + state.panX + 'px,' + state.panY + 'px) scale(' + state.zoom + ')' );
		$zoomLevel.text( Math.round( state.zoom * 100 ) + '%' );
		drawMinimap();
	}

	function canvasToScreen( cx, cy ) {
		return {
			x : cx * state.zoom + state.panX,
			y : cy * state.zoom + state.panY,
		};
	}

	function screenToCanvas( sx, sy ) {
		var wrap = $canvasWrap[ 0 ].getBoundingClientRect();
		return {
			x : ( sx - wrap.left - state.panX ) / state.zoom,
			y : ( sy - wrap.top  - state.panY ) / state.zoom,
		};
	}

	function fitCanvas() {
		var nodeIds = Object.keys( state.nodes );
		if ( ! nodeIds.length ) {
			state.panX  = 80;
			state.panY  = 80;
			state.zoom  = 1;
			applyTransform();
			return;
		}

		var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
		$.each( state.nodes, function ( id, n ) {
			minX = Math.min( minX, n.x );
			minY = Math.min( minY, n.y );
			maxX = Math.max( maxX, n.x + 200 );
			maxY = Math.max( maxY, n.y + 100 );
		} );

		var wrapW = $canvasWrap.width();
		var wrapH = $canvasWrap.height();
		var padX  = 60;
		var padY  = 60;
		var scaleX = ( wrapW - padX * 2 ) / ( maxX - minX );
		var scaleY = ( wrapH - padY * 2 ) / ( maxY - minY );
		state.zoom  = Math.min( Math.max( Math.min( scaleX, scaleY ), 0.3 ), 1.2 );
		state.panX  = padX - minX * state.zoom;
		state.panY  = padY - minY * state.zoom;
		applyTransform();
	}

	// =========================================================================
	// CANVAS — NODE RENDERING
	// =========================================================================

	function nodeLabel( n ) {
		if ( n.config && n.config.name ) { return n.config.name; }
		if ( 'trigger' === n.type ) {
			var t = cfg.triggers && cfg.triggers[ n.triggerType ];
			return t ? t.label : ( n.triggerType || 'Trigger' );
		}
		var a = cfg.actions && cfg.actions[ n.type ];
		return a ? a.label : ucFirst( n.type || 'Node' );
	}

	function nodeIcon( n ) {
		if ( 'trigger' === n.type ) {
			var t = cfg.triggers && cfg.triggers[ n.triggerType ];
			return ( t && t.icon ) ? t.icon : '⚡';
		}
		var a = cfg.actions && cfg.actions[ n.type ];
		return ( a && a.icon ) ? a.icon : '▶';
	}

	function nodeSummary( n ) {
		if ( n.config ) {
			if ( n.config.template_id )  { return 'Template: ' + n.config.template_id; }
			if ( n.config.message )      { return n.config.message.substr( 0, 60 ); }
			if ( n.config.duration )     { return n.config.duration + ' ' + ( n.config.unit || 'minutes' ); }
			if ( n.config.url )          { return n.config.url.substr( 0, 50 ); }
		}
		return '';
	}

	function renderNode( n ) {
		$( '#tsh-wa-node-' + n.id ).remove();

		var isCondition = ( 'condition' === n.type );
		var summary     = nodeSummary( n );
		var isTrigger   = ( 'trigger' === n.type );

		var $node = $( '<div>' )
			.addClass( 'tsh-wa-node' )
			.addClass( isTrigger ? 'tsh-wa-node--trigger' : 'tsh-wa-node--' + n.type )
			.attr( 'id', 'tsh-wa-node-' + n.id )
			.attr( 'data-id', n.id )
			.css( { left: n.x + 'px', top: n.y + 'px' } );

		$node.html(
			'<div class="tsh-wa-node__header">' +
				'<span class="tsh-wa-node__icon">' + escHtml( nodeIcon( n ) ) + '</span>' +
				'<span class="tsh-wa-node__title">' + escHtml( nodeLabel( n ) ) + '</span>' +
			'</div>' +
			'<div class="tsh-wa-node__body">' +
				'<div class="tsh-wa-node__label">' + escHtml( nodeLabel( n ) ) + '</div>' +
				( summary ? '<div class="tsh-wa-node__summary">' + escHtml( summary ) + '</div>' : '' ) +
			'</div>' +
			( isCondition
				? '<div class="tsh-wa-node__branches">' +
					'<div class="tsh-wa-node__branch">' +
						'<div class="tsh-wa-node__port tsh-wa-node__port--output" data-node="' + n.id + '" data-port="yes" style="border-color:#059669;"></div>' +
						'<span class="tsh-wa-node__branch-label">Yes</span>' +
					'</div>' +
					'<div class="tsh-wa-node__branch">' +
						'<div class="tsh-wa-node__port tsh-wa-node__port--output" data-node="' + n.id + '" data-port="no" style="border-color:#dc2626;"></div>' +
						'<span class="tsh-wa-node__branch-label">No</span>' +
					'</div>' +
				  '</div>'
				: '<div class="tsh-wa-node__ports">' +
					( isTrigger ? '' : '<div class="tsh-wa-node__port tsh-wa-node__port--input" data-node="' + n.id + '" data-port="in"></div>' ) +
					'<div class="tsh-wa-node__port tsh-wa-node__port--output" data-node="' + n.id + '" data-port="out"></div>' +
				  '</div>'
			) +
			''
		);

		// Drag-to-move.
		$node.on( 'mousedown', function ( e ) {
			if ( $( e.target ).hasClass( 'tsh-wa-node__port' ) ) { return; }
			e.preventDefault();
			selectNode( n.id );
			var canvasPos = screenToCanvas( e.clientX, e.clientY );
			state.draggingNodeId = n.id;
			state.dragOffX = canvasPos.x - n.x;
			state.dragOffY = canvasPos.y - n.y;
			$node.addClass( 'dragging-node' );
		} );

		$canvas.append( $node );
		$( '#tsh-wa-canvas-hint' ).hide();
	}

	function renderAllNodes() {
		$canvas.empty();
		$.each( state.nodes, function ( id, n ) {
			renderNode( n );
		} );
	}

	// =========================================================================
	// CANVAS — EDGE RENDERING
	// =========================================================================

	function clearSvgEdges() {
		$canvasSvg.find( '.tsh-wa-connection-path' ).remove();
	}

	function getPortCenter( nodeId, port ) {
		var n    = state.nodes[ nodeId ];
		if ( ! n ) { return { x: 0, y: 0 }; }
		var $el  = $( '#tsh-wa-node-' + nodeId + ' [data-port="' + port + '"]' );
		if ( ! $el.length ) { return { x: n.x + 200, y: n.y + 50 }; }
		var el   = $el[ 0 ];
		var wrap = $canvasWrap[ 0 ].getBoundingClientRect();
		var r    = el.getBoundingClientRect();
		// Convert screen coords back to canvas coords.
		return {
			x : ( r.left + r.width  / 2 - wrap.left - state.panX ) / state.zoom,
			y : ( r.top  + r.height / 2 - wrap.top  - state.panY ) / state.zoom,
		};
	}

	function cubicPath( x1, y1, x2, y2 ) {
		var dx = Math.abs( x2 - x1 ) * 0.5;
		return 'M ' + x1 + ' ' + y1 +
			' C ' + ( x1 + dx ) + ' ' + y1 +
			' ' + ( x2 - dx ) + ' ' + y2 +
			' ' + x2 + ' ' + y2;
	}

	function renderEdge( edge ) {
		$( '#tsh-wa-edge-' + edge.id ).remove();

		var from = getPortCenter( edge.from, edge.fromPort || 'out' );
		var to   = getPortCenter( edge.to,   edge.toPort   || 'in'  );
		var d    = cubicPath( from.x, from.y, to.x, to.y );

		// SVG path must be in viewport coords — use canvas transform.
		// We draw in canvas space (not screen space) since the SVG is positioned
		// over the canvas-wrap and we track the canvas transform ourselves.
		var scaleStr = 'translate(' + state.panX + ',' + state.panY + ') scale(' + state.zoom + ')';

		var $g = $( document.createElementNS( 'http://www.w3.org/2000/svg', 'g' ) )
			.attr( 'id', 'tsh-wa-edge-' + edge.id )
			.attr( 'data-edge-id', edge.id );

		var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		$( path ).attr( 'd', d )
			.attr( 'transform', scaleStr )
			.addClass( 'tsh-wa-connection-path' )
			.on( 'click', function ( e ) {
				e.stopPropagation();
				selectEdge( edge.id );
			} );

		$g.append( path );
		$canvasSvg.append( $g );
	}

	function renderAllEdges() {
		clearSvgEdges();
		$.each( state.edges, function ( i, edge ) {
			renderEdge( edge );
		} );
	}

	function refreshEdgesForNode( nodeId ) {
		$.each( state.edges, function ( i, edge ) {
			if ( edge.from === nodeId || edge.to === nodeId ) {
				renderEdge( edge );
			}
		} );
	}

	// =========================================================================
	// CANVAS — SELECTION
	// =========================================================================

	function selectNode( nodeId ) {
		$( '.tsh-wa-node' ).removeClass( 'selected' );
		state.selectedNodeId = nodeId;
		state.selectedEdgeId = null;
		$( '.tsh-wa-connection-path' ).removeClass( 'selected' );
		if ( nodeId ) {
			$( '#tsh-wa-node-' + nodeId ).addClass( 'selected' );
			showConfigPanel( nodeId );
		} else {
			hideConfigPanel();
		}
	}

	function selectEdge( edgeId ) {
		state.selectedEdgeId = edgeId;
		state.selectedNodeId = null;
		$( '.tsh-wa-node' ).removeClass( 'selected' );
		$( '.tsh-wa-connection-path' ).removeClass( 'selected' );
		$( '#tsh-wa-edge-' + edgeId + ' path' ).addClass( 'selected' );
		hideConfigPanel();
	}

	function deleteSelected() {
		if ( state.selectedNodeId ) {
			deleteNode( state.selectedNodeId );
		} else if ( state.selectedEdgeId ) {
			deleteEdge( state.selectedEdgeId );
		}
	}

	function deleteNode( nodeId ) {
		pushHistory();
		delete state.nodes[ nodeId ];
		$( '#tsh-wa-node-' + nodeId ).remove();
		// Remove connected edges.
		state.edges = state.edges.filter( function ( e ) {
			if ( e.from === nodeId || e.to === nodeId ) {
				$( '#tsh-wa-edge-' + e.id ).remove();
				return false;
			}
			return true;
		} );
		state.selectedNodeId = null;
		hideConfigPanel();
		showToast( i18n.node_deleted, 'success' );
		markDirty();
		updateHint();
	}

	function deleteEdge( edgeId ) {
		pushHistory();
		state.edges = state.edges.filter( function ( e ) { return e.id !== edgeId; } );
		$( '#tsh-wa-edge-' + edgeId ).remove();
		state.selectedEdgeId = null;
		markDirty();
	}

	function updateHint() {
		if ( ! Object.keys( state.nodes ).length ) {
			$( '#tsh-wa-canvas-hint' ).show();
		} else {
			$( '#tsh-wa-canvas-hint' ).hide();
		}
	}

	// =========================================================================
	// NODE CONFIG PANEL
	// =========================================================================

	function showConfigPanel( nodeId ) {
		var n = state.nodes[ nodeId ];
		if ( ! n ) { return; }
		$configTitle.text( nodeLabel( n ) );
		$configBody.html( buildConfigForm( n ) );
		$configPanel.show();
	}

	function hideConfigPanel() {
		$configPanel.hide();
		$configBody.html( '' );
	}

	function buildConfigForm( n ) {
		var html = '';
		var c    = n.config || {};

		if ( 'trigger' === n.type ) {
			var t = cfg.triggers && cfg.triggers[ n.triggerType ];
			html += fieldText( 'custom_hook', 'Custom Hook (if applicable)', c.custom_hook || '' );
			if ( t && t.fields ) {
				$.each( t.fields, function ( i, f ) {
					html += buildField( f, c );
				} );
			}
			return html;
		}

		switch ( n.type ) {
			case 'send_whatsapp':
			case 'queue_whatsapp':
				html += fieldSelect( 'template_id', 'WhatsApp Template', c.template_id || '', buildMetaTemplateOptions( c.template_id ) );
				html += fieldText( 'phone_override', 'Phone Override (leave blank for order phone)', c.phone_override || '' );
				html += fieldTextarea( 'message', 'Fallback Message (if no template)', c.message || '' );
				html += varPicker();
				break;

			case 'delay':
			case 'wait':
				html += fieldNumber( 'duration', 'Duration', c.duration || 1, 1 );
				html += fieldSelect( 'unit', 'Unit', c.unit || 'minutes', [
					[ 'minutes', 'Minutes' ],
					[ 'hours',   'Hours'   ],
					[ 'days',    'Days'    ],
				] );
				html += fieldSelect( 'delay_type', 'Delay Type', c.delay_type || 'relative', [
					[ 'relative',       'Relative (from trigger)' ],
					[ 'business_hours', 'Business Hours only'     ],
					[ 'specific_time',  'Specific Time of Day'    ],
				] );
				break;

			case 'condition':
				html += conditionBuilder( c );
				break;

			case 'add_order_note':
			case 'update_order_note':
				html += fieldTextarea( 'note', 'Note Text', c.note || '' );
				html += fieldCheckbox( 'customer_note', 'Customer-facing note', c.customer_note || false );
				html += varPicker();
				break;

			case 'assign_conversation':
				html += agentSelect( c.user_id || '' );
				break;

			case 'update_customer_label':
				html += fieldText( 'label', 'Label slug', c.label || '' );
				break;

			case 'create_coupon':
				html += fieldText( 'coupon_prefix', 'Coupon Code Prefix', c.coupon_prefix || 'AUTO' );
				html += fieldNumber( 'discount_amount', 'Discount %', c.discount_amount || 10, 1, 100 );
				html += fieldNumber( 'expiry_days', 'Expires in (days)', c.expiry_days || 7, 1 );
				break;

			case 'webhook':
				html += fieldText( 'url', 'Webhook URL', c.url || '' );
				html += fieldSelect( 'method', 'HTTP Method', c.method || 'POST', [
					[ 'POST',   'POST'   ],
					[ 'GET',    'GET'    ],
					[ 'PUT',    'PUT'    ],
					[ 'PATCH',  'PATCH'  ],
					[ 'DELETE', 'DELETE' ],
				] );
				html += fieldTextarea( 'body', 'Body (JSON, supports variables)', c.body || '' );
				html += varPicker();
				break;

			case 'send_email':
				html += fieldText( 'to', 'To (email or variable)', c.to || '{{customer_email}}' );
				html += fieldText( 'subject', 'Subject', c.subject || '' );
				html += fieldTextarea( 'body', 'Body', c.body || '' );
				html += varPicker();
				break;

			case 'log_event':
				html += fieldText( 'event_name', 'Event Name', c.event_name || '' );
				html += fieldTextarea( 'data', 'Additional Data (JSON)', c.data || '' );
				break;

			default:
				html += '<p style="font-size:12px;color:#94a3b8;">No settings for this node type.</p>';
		}

		return html;
	}

	function fieldText( name, label, value ) {
		return '<div class="tsh-wa-config-field"><label class="tsh-wa-field-label">' + escHtml( label ) + '</label>' +
			'<input type="text" class="regular-text tsh-wa-config-input" data-key="' + escHtml( name ) + '" value="' + escHtml( value ) + '"></div>';
	}

	function fieldTextarea( name, label, value ) {
		return '<div class="tsh-wa-config-field"><label class="tsh-wa-field-label">' + escHtml( label ) + '</label>' +
			'<textarea class="large-text tsh-wa-config-input" rows="4" data-key="' + escHtml( name ) + '">' + escHtml( value ) + '</textarea></div>';
	}

	function fieldNumber( name, label, value, min, max ) {
		var extra = min !== undefined ? ' min="' + min + '"' : '';
		if ( max !== undefined ) { extra += ' max="' + max + '"'; }
		return '<div class="tsh-wa-config-field"><label class="tsh-wa-field-label">' + escHtml( label ) + '</label>' +
			'<input type="number" class="small-text tsh-wa-config-input" data-key="' + escHtml( name ) + '" value="' + escHtml( value ) + '"' + extra + '></div>';
	}

	function fieldSelect( name, label, selected, options ) {
		var html = '<div class="tsh-wa-config-field"><label class="tsh-wa-field-label">' + escHtml( label ) + '</label>' +
			'<select class="tsh-wa-config-input" data-key="' + escHtml( name ) + '">';
		$.each( options, function ( i, opt ) {
			var val = $.isArray( opt ) ? opt[ 0 ] : opt;
			var txt = $.isArray( opt ) ? opt[ 1 ] : opt;
			html += '<option value="' + escHtml( val ) + '"' + ( val == selected ? ' selected' : '' ) + '>' + escHtml( txt ) + '</option>';
		} );
		html += '</select></div>';
		return html;
	}

	function fieldCheckbox( name, label, checked ) {
		return '<div class="tsh-wa-config-field"><label>' +
			'<input type="checkbox" class="tsh-wa-config-input" data-key="' + escHtml( name ) + '" ' + ( checked ? 'checked' : '' ) + '> ' +
			escHtml( label ) + '</label></div>';
	}

	function buildField( f, c ) {
		switch ( f.type ) {
			case 'text':     return fieldText( f.key, f.label, c[ f.key ] || f.default || '' );
			case 'number':   return fieldNumber( f.key, f.label, c[ f.key ] || f.default || 0, f.min, f.max );
			case 'textarea': return fieldTextarea( f.key, f.label, c[ f.key ] || f.default || '' );
			case 'select':   return fieldSelect( f.key, f.label, c[ f.key ] || f.default || '', f.options || [] );
		}
		return '';
	}

	function varPicker() {
		var vars = cfg.variables || [];
		var html = '<div class="tsh-wa-config-field tsh-wa-var-picker">' +
			'<label class="tsh-wa-field-label">Insert Variable</label>' +
			'<select class="tsh-wa-var-select"><option value="">— pick variable —</option>';
		$.each( vars, function ( i, v ) {
			html += '<option value="{{' + escHtml( v.key ) + '}}">{{' + escHtml( v.key ) + '}} — ' + escHtml( v.label ) + '</option>';
		} );
		html += '</select><p class="tsh-wa-field-hint">Select to copy the placeholder to clipboard.</p></div>';
		return html;
	}

	function conditionBuilder( c ) {
		var conditions  = cfg.conditions || {};
		var group       = c.conditions || [];
		var html = '<div class="tsh-wa-config-field"><label class="tsh-wa-field-label">Conditions (ALL must match)</label>' +
			'<div class="tsh-wa-conditions-builder" id="tsh-wa-cond-builder">';

		$.each( group, function ( i, cond ) {
			html += conditionRow( cond, conditions );
		} );

		html += '</div><button type="button" class="button tsh-wa-add-condition-btn" id="tsh-wa-add-cond">+ Add Condition</button></div>';
		return html;
	}

	function conditionRow( cond, conditions ) {
		var html = '<div class="tsh-wa-condition-row" data-idx="' + escHtml( cond.idx || 0 ) + '">' +
			'<select class="tsh-wa-cond-field">';
		$.each( conditions, function ( key, def ) {
			html += '<option value="' + escHtml( key ) + '"' + ( cond.field === key ? ' selected' : '' ) + '>' + escHtml( def.label ) + '</option>';
		} );
		html += '</select>' +
			'<select class="tsh-wa-cond-op">' +
			'<option value="="' + ( '=' === cond.op ? ' selected' : '' ) + '>=</option>' +
			'<option value="!="' + ( '!=' === cond.op ? ' selected' : '' ) + '>≠</option>' +
			'<option value=">"' + ( '>' === cond.op ? ' selected' : '' ) + '>></option>' +
			'<option value="<"' + ( '<' === cond.op ? ' selected' : '' ) + '><</option>' +
			'<option value="contains"' + ( 'contains' === cond.op ? ' selected' : '' ) + '>contains</option>' +
			'</select>' +
			'<input type="text" class="tsh-wa-cond-value" value="' + escHtml( cond.value || '' ) + '" placeholder="value">' +
			'<button type="button" class="button-link tsh-wa-remove-cond">✕</button>' +
			'</div>';
		return html;
	}

	function agentSelect( selected ) {
		var agents = cfg.agents || [];
		var html = '<div class="tsh-wa-config-field"><label class="tsh-wa-field-label">Assign to Agent</label>' +
			'<select class="tsh-wa-config-input" data-key="user_id">' +
			'<option value="">— Unassign —</option>';
		$.each( agents, function ( i, a ) {
			html += '<option value="' + escHtml( a.id ) + '"' + ( a.id == selected ? ' selected' : '' ) + '>' + escHtml( a.name ) + '</option>';
		} );
		html += '</select></div>';
		return html;
	}

	function buildMetaTemplateOptions( selected ) {
		// Returns a flat list of option pairs; meta templates come from cfg if available.
		var opts = [ [ '', '— Select Template —' ] ];
		var tpls = cfg.metaTemplates || [];
		$.each( tpls, function ( i, t ) {
			opts.push( [ t.id, t.name ] );
		} );
		if ( ! tpls.length ) {
			opts.push( [ selected || '', selected ? '(ID: ' + selected + ')' : 'Enter template ID manually' ] );
		}
		return opts;
	}

	function readConfigFromPanel() {
		var config = {};
		$configBody.find( '.tsh-wa-config-input' ).each( function () {
			var key = $( this ).data( 'key' );
			if ( ! key ) { return; }
			if ( 'checkbox' === $( this ).attr( 'type' ) ) {
				config[ key ] = $( this ).is( ':checked' );
			} else {
				config[ key ] = $( this ).val();
			}
		} );

		// Read condition builder.
		var conds = [];
		$configBody.find( '.tsh-wa-condition-row' ).each( function ( i ) {
			conds.push( {
				idx   : i,
				field : $( this ).find( '.tsh-wa-cond-field' ).val(),
				op    : $( this ).find( '.tsh-wa-cond-op' ).val(),
				value : $( this ).find( '.tsh-wa-cond-value' ).val(),
			} );
		} );
		if ( conds.length ) {
			config.conditions = conds;
		}

		return config;
	}

	// =========================================================================
	// UNDO / REDO
	// =========================================================================

	function getSnapshot() {
		return {
			nodes : JSON.parse( JSON.stringify( state.nodes ) ),
			edges : JSON.parse( JSON.stringify( state.edges ) ),
		};
	}

	function pushHistory() {
		// Trim forward history.
		state.history = state.history.slice( 0, state.historyIndex + 1 );
		state.history.push( getSnapshot() );
		if ( state.history.length > state.maxHistory ) {
			state.history.shift();
		}
		state.historyIndex = state.history.length - 1;
		updateUndoRedoBtns();
	}

	function clearHistory() {
		state.history      = [ getSnapshot() ];
		state.historyIndex = 0;
		updateUndoRedoBtns();
	}

	function undo() {
		if ( state.historyIndex <= 0 ) { return; }
		state.historyIndex--;
		restoreSnapshot( state.history[ state.historyIndex ] );
	}

	function redo() {
		if ( state.historyIndex >= state.history.length - 1 ) { return; }
		state.historyIndex++;
		restoreSnapshot( state.history[ state.historyIndex ] );
	}

	function restoreSnapshot( snap ) {
		state.nodes = JSON.parse( JSON.stringify( snap.nodes ) );
		state.edges = JSON.parse( JSON.stringify( snap.edges ) );
		renderAllNodes();
		renderAllEdges();
		updateUndoRedoBtns();
		hideConfigPanel();
		updateHint();
		markDirty();
	}

	function updateUndoRedoBtns() {
		$( '#tsh-wa-builder-undo' ).prop( 'disabled', state.historyIndex <= 0 );
		$( '#tsh-wa-builder-redo' ).prop( 'disabled', state.historyIndex >= state.history.length - 1 );
	}

	// =========================================================================
	// MINIMAP
	// =========================================================================

	function drawMinimap() {
		if ( ! $minimapCanvas.length ) { return; }
		var canvas = $minimapCanvas[ 0 ];
		var ctx    = canvas.getContext( '2d' );
		var w      = canvas.width;
		var h      = canvas.height;
		ctx.clearRect( 0, 0, w, h );

		var nodeIds = Object.keys( state.nodes );
		if ( ! nodeIds.length ) { return; }

		// Compute bounding box of all nodes.
		var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
		$.each( state.nodes, function ( id, n ) {
			minX = Math.min( minX, n.x );
			minY = Math.min( minY, n.y );
			maxX = Math.max( maxX, n.x + 200 );
			maxY = Math.max( maxY, n.y + 120 );
		} );

		var rangeX  = maxX - minX || 1;
		var rangeY  = maxY - minY || 1;
		var scale   = Math.min( ( w - 16 ) / rangeX, ( h - 16 ) / rangeY );
		var offX    = 8;
		var offY    = 8;

		// Draw edges.
		ctx.strokeStyle = '#94a3b8';
		ctx.lineWidth   = 1;
		$.each( state.edges, function ( i, edge ) {
			var fn = state.nodes[ edge.from ];
			var tn = state.nodes[ edge.to ];
			if ( ! fn || ! tn ) { return; }
			ctx.beginPath();
			ctx.moveTo( offX + ( fn.x + 200 - minX ) * scale, offY + ( fn.y + 60 - minY ) * scale );
			ctx.lineTo( offX + ( tn.x - minX ) * scale,       offY + ( tn.y + 60 - minY ) * scale );
			ctx.stroke();
		} );

		// Draw nodes.
		$.each( state.nodes, function ( id, n ) {
			ctx.fillStyle   = 'trigger' === n.type ? '#7c3aed' : '#2563eb';
			ctx.strokeStyle = '#fff';
			ctx.lineWidth   = 1;
			var nx = offX + ( n.x - minX ) * scale;
			var ny = offY + ( n.y - minY ) * scale;
			var nw = Math.max( 8, 200 * scale );
			var nh = Math.max( 5, 80 * scale );
			ctx.fillRect( nx, ny, nw, nh );
		} );

		// Draw viewport rectangle.
		var wrapW = $canvasWrap.width();
		var wrapH = $canvasWrap.height();
		var vx    = offX + ( -state.panX / state.zoom - minX ) * scale;
		var vy    = offY + ( -state.panY / state.zoom - minY ) * scale;
		var vw    = ( wrapW / state.zoom ) * scale;
		var vh    = ( wrapH / state.zoom ) * scale;
		ctx.strokeStyle = '#25d366';
		ctx.lineWidth   = 1.5;
		ctx.strokeRect( vx, vy, vw, vh );
	}

	// =========================================================================
	// DRAG FROM PALETTE → CANVAS
	// =========================================================================

	var paletteDrag = { active: false, nodeType: null, triggerType: null, label: null };

	function bindPaletteDrag() {
		$doc.on( 'dragstart', '.tsh-wa-palette-node', function ( e ) {
			paletteDrag.active      = true;
			paletteDrag.nodeType    = $( this ).data( 'node-type' );
			paletteDrag.triggerType = $( this ).data( 'trigger-type' );
			paletteDrag.label       = $( this ).data( 'label' );
			$( this ).addClass( 'dragging' );
			var dt = e.originalEvent.dataTransfer;
			dt.effectAllowed = 'copy';
			dt.setData( 'text/plain', 'tsh-wa-node' );
		} );

		$doc.on( 'dragend', '.tsh-wa-palette-node', function () {
			paletteDrag.active = false;
			$( this ).removeClass( 'dragging' );
		} );

		$canvasWrap.on( 'dragover', function ( e ) {
			e.preventDefault();
			e.originalEvent.dataTransfer.dropEffect = 'copy';
		} );

		$canvasWrap.on( 'drop', function ( e ) {
			e.preventDefault();
			if ( ! paletteDrag.active ) { return; }

			var pos = screenToCanvas( e.originalEvent.clientX, e.originalEvent.clientY );

			pushHistory();

			var id = uid();
			var n  = {
				id          : id,
				type        : paletteDrag.nodeType,
				triggerType : paletteDrag.triggerType || null,
				x           : Math.max( 0, pos.x - 100 ),
				y           : Math.max( 0, pos.y - 40 ),
				config      : {},
			};

			state.nodes[ id ] = n;
			renderNode( n );
			selectNode( id );
			markDirty();
			updateHint();
		} );
	}

	// =========================================================================
	// MOUSE MOVE — node drag, canvas pan, connection drawing
	// =========================================================================

	function bindCanvasMouse() {

		// Middle-button / space panning.
		$canvasWrap.on( 'mousedown', function ( e ) {
			if ( e.button === 1 || ( e.button === 0 && e.altKey ) ) {
				e.preventDefault();
				state.isPanning  = true;
				state.panStartX  = e.clientX;
				state.panStartY  = e.clientY;
				state.panStartViewX = state.panX;
				state.panStartViewY = state.panY;
				$canvasWrap.addClass( 'canvas-panning' );
			}
		} );

		$doc.on( 'mousemove.builder', function ( e ) {

			// Pan.
			if ( state.isPanning ) {
				state.panX = state.panStartViewX + ( e.clientX - state.panStartX );
				state.panY = state.panStartViewY + ( e.clientY - state.panStartY );
				applyTransform();
				return;
			}

			// Node drag.
			if ( state.draggingNodeId ) {
				var pos = screenToCanvas( e.clientX, e.clientY );
				var n   = state.nodes[ state.draggingNodeId ];
				if ( n ) {
					n.x = Math.max( 0, pos.x - state.dragOffX );
					n.y = Math.max( 0, pos.y - state.dragOffY );
					$( '#tsh-wa-node-' + state.draggingNodeId ).css( { left: n.x + 'px', top: n.y + 'px' } );
					refreshEdgesForNode( state.draggingNodeId );
					drawMinimap();
				}
				return;
			}

			// Connection drawing.
			if ( state.connecting ) {
				var cPos = screenToCanvas( e.clientX, e.clientY );
				var from = state.connecting;
				$( '#tsh-wa-edge-drawing' ).remove();
				var d = cubicPath( from.x, from.y, cPos.x, cPos.y );
				var scaleStr = 'translate(' + state.panX + ',' + state.panY + ') scale(' + state.zoom + ')';
				var path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
				$( path ).attr( {
					d         : d,
					transform : scaleStr,
					id        : 'tsh-wa-edge-drawing',
				} ).addClass( 'tsh-wa-connection-path tsh-wa-connection-path--drawing' );
				$canvasSvg.append( path );
			}
		} );

		$doc.on( 'mouseup.builder', function ( e ) {

			if ( state.isPanning ) {
				state.isPanning = false;
				$canvasWrap.removeClass( 'canvas-panning' );
				return;
			}

			if ( state.draggingNodeId ) {
				$( '#tsh-wa-node-' + state.draggingNodeId ).removeClass( 'dragging-node' );
				pushHistory();
				markDirty();
				state.draggingNodeId = null;
				return;
			}

			if ( state.connecting ) {
				$( '#tsh-wa-edge-drawing' ).remove();
				state.connecting = null;
			}
		} );

		// Wheel zoom.
		$canvasWrap.on( 'wheel', function ( e ) {
			e.preventDefault();
			var delta   = e.originalEvent.deltaY > 0 ? -0.08 : 0.08;
			var newZoom = Math.min( Math.max( state.zoom + delta, 0.25 ), 2 );
			// Zoom toward cursor.
			var wrap = $canvasWrap[ 0 ].getBoundingClientRect();
			var mx   = e.clientX - wrap.left;
			var my   = e.clientY - wrap.top;
			state.panX = mx - ( mx - state.panX ) * ( newZoom / state.zoom );
			state.panY = my - ( my - state.panY ) * ( newZoom / state.zoom );
			state.zoom = newZoom;
			applyTransform();
		} );

		// Click on canvas background — deselect.
		$canvasWrap.on( 'click', function ( e ) {
			if ( $( e.target ).is( $canvasWrap ) || $( e.target ).is( $canvas ) ||
				$( e.target ).is( $canvasSvg ) || $( e.target ).is( '#tsh-wa-canvas-hint' ) ||
				$( e.target ).closest( '#tsh-wa-canvas-hint' ).length ) {
				selectNode( null );
			}
		} );

		// Output port — start connection.
		$doc.on( 'mousedown', '.tsh-wa-node__port--output', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var nodeId = $( this ).data( 'node' );
			var port   = $( this ).data( 'port' );
			var center = getPortCenter( nodeId, port );
			state.connecting = { fromNodeId: nodeId, fromPort: port, x: center.x, y: center.y };
		} );

		// Input port — complete connection.
		$doc.on( 'mouseup', '.tsh-wa-node__port--input', function ( e ) {
			if ( ! state.connecting ) { return; }
			e.stopPropagation();
			var toNodeId = $( this ).data( 'node' );
			var toPort   = $( this ).data( 'port' ) || 'in';
			var from     = state.connecting;
			state.connecting = null;
			$( '#tsh-wa-edge-drawing' ).remove();

			if ( from.fromNodeId === toNodeId ) { return; }

			// Prevent duplicate edges between same ports.
			var duplicate = state.edges.some( function ( ed ) {
				return ed.from === from.fromNodeId && ed.fromPort === from.fromPort && ed.to === toNodeId;
			} );
			if ( duplicate ) { return; }

			pushHistory();
			var edge = { id: uid(), from: from.fromNodeId, fromPort: from.fromPort, to: toNodeId, toPort: toPort };
			state.edges.push( edge );
			renderEdge( edge );
			markDirty();
		} );
	}

	// =========================================================================
	// KEYBOARD SHORTCUTS
	// =========================================================================

	function bindKeyboard() {
		$doc.on( 'keydown', function ( e ) {
			// Only active in builder view.
			if ( ! $builderView.is( ':visible' ) ) { return; }

			// Delete / Backspace — delete selected node or edge.
			if ( ( 46 === e.which || 8 === e.which ) &&
				! $( e.target ).is( 'input, textarea, select' ) ) {
				e.preventDefault();
				deleteSelected();
				return;
			}

			// Ctrl+Z — undo.
			if ( e.ctrlKey && 90 === e.which && ! e.shiftKey ) {
				e.preventDefault();
				undo();
			}

			// Ctrl+Y or Ctrl+Shift+Z — redo.
			if ( ( e.ctrlKey && 89 === e.which ) || ( e.ctrlKey && e.shiftKey && 90 === e.which ) ) {
				e.preventDefault();
				redo();
			}

			// Ctrl+S — save.
			if ( e.ctrlKey && 83 === e.which ) {
				e.preventDefault();
				saveWorkflow();
			}
		} );
	}

	// =========================================================================
	// HISTORY VIEW
	// =========================================================================

	function openHistoryView( wfId, wfName ) {
		state.historyWfId   = wfId;
		state.historyWfName = wfName;
		state.historyPage   = 1;
		$( '#tsh-wa-history-title' ).text( wfName + ' — ' + 'Execution History' );
		showView( 'history' );
		loadHistoryPage();
	}

	function loadHistoryPage() {
		$historyBody.html( '<tr><td colspan="7"><span class="spinner is-active"></span></td></tr>' );
		ajaxPost( 'tsh_wa_wf_history', {
			workflow_id : state.historyWfId,
			page        : state.historyPage,
			per_page    : 30,
		}, function ( data ) {
			renderHistoryRows( data.runs || [] );
		} );
	}

	function renderHistoryRows( runs ) {
		if ( ! runs.length ) {
			$historyBody.html( '<tr><td colspan="7">' + escHtml( i18n.no_history ) + '</td></tr>' );
			return;
		}
		var html = '';
		$.each( runs, function ( i, r ) {
			html += '<tr>' +
				'<td>#' + escHtml( r.id ) + '</td>' +
				'<td>' + statusBadge( r.status ) + '</td>' +
				'<td>' + escHtml( r.trigger_type ) + '</td>' +
				'<td>' + escHtml( r.started_at || '—' ) + '</td>' +
				'<td>' + formatDuration( r.execution_time_ms ) + '</td>' +
				'<td>' + escHtml( r.steps_completed || 0 ) + '</td>' +
				'<td><button type="button" class="button button-small tsh-wa-view-run-btn" data-run="' + escHtml( r.id ) + '">View</button></td>' +
				'</tr>';
		} );
		$historyBody.html( html );
	}

	function openRunDetail( runId ) {
		var $modal = $( '#tsh-wa-run-detail-modal' );
		$( '#tsh-wa-run-detail-body' ).html( '<span class="spinner is-active"></span>' );
		$modal.show();
		ajaxPost( 'tsh_wa_wf_run_detail', { run_id: runId }, function ( data ) {
			var run  = data.run  || {};
			var logs = data.logs || [];

			var html = '<table class="form-table" style="margin:0 0 16px;">' +
				'<tr><th>Status</th><td>' + statusBadge( run.status ) + '</td></tr>' +
				'<tr><th>Started</th><td>' + escHtml( run.started_at || '—' ) + '</td></tr>' +
				'<tr><th>Duration</th><td>' + formatDuration( run.execution_time_ms ) + '</td></tr>' +
				'<tr><th>Steps</th><td>' + escHtml( run.steps_completed || 0 ) + '</td></tr>' +
				( run.error_message ? '<tr><th>Error</th><td style="color:#dc2626">' + escHtml( run.error_message ) + '</td></tr>' : '' ) +
				'</table>';

			if ( logs.length ) {
				html += '<h4 style="margin:0 0 8px;">Execution Log</h4><ul class="tsh-wa-run-logs">';
				$.each( logs, function ( i, log ) {
					html += '<li class="tsh-wa-run-log-item tsh-wa-run-log-item--' + escHtml( log.level ) + '">' +
						'<span class="tsh-wa-run-log-level">' + escHtml( log.level ) + '</span>' +
						( log.node_type ? '<span class="tsh-wa-run-log-node">' + escHtml( log.node_type ) + '</span>' : '' ) +
						'<span class="tsh-wa-run-log-msg">' + escHtml( log.message ) + '</span>' +
						'</li>';
				} );
				html += '</ul>';
			} else {
				html += '<p style="color:#6b7280;font-size:13px;">No log entries.</p>';
			}

			$( '#tsh-wa-run-detail-body' ).html( html );
		} );
	}

	// =========================================================================
	// TEMPLATE LIBRARY
	// =========================================================================

	function openTemplatesModal() {
		$( '#tsh-wa-templates-modal' ).show();
	}

	// =========================================================================
	// IMPORT / EXPORT
	// =========================================================================

	function openImportModal() {
		$( '#tsh-wa-import-json' ).val( '' );
		$( '#tsh-wa-import-modal' ).show();
	}

	function doImport() {
		var json = $( '#tsh-wa-import-json' ).val().trim();
		var mode = $( '#tsh-wa-import-mode' ).val();
		if ( ! json ) { showToast( 'Paste JSON first.', 'error' ); return; }
		if ( 'replace' === mode && ! window.confirm( i18n.confirm_replace ) ) { return; }
		ajaxPost( 'tsh_wa_wf_import', { json: json, mode: mode }, function () {
			showToast( i18n.imported, 'success' );
			$( '#tsh-wa-import-modal' ).hide();
			loadWorkflowList();
		} );
	}

	function doExportAll() {
		ajaxPost( 'tsh_wa_wf_export', { workflow_ids: [] }, function ( data ) {
			var blob = new Blob( [ data.json ], { type: 'application/json' } );
			var url  = URL.createObjectURL( blob );
			var a    = document.createElement( 'a' );
			a.href   = url;
			a.download = data.filename || 'workflows.json';
			a.click();
			URL.revokeObjectURL( url );
		} );
	}

	// =========================================================================
	// EVENT BINDING
	// =========================================================================

	function bindEvents() {

		// ---- LIST VIEW -------------------------------------------------------

		// Create new.
		$doc.on( 'click', '#tsh-wa-wf-create-btn, #tsh-wa-wf-first-create', function () {
			openBuilder( null );
		} );

		// Edit.
		$doc.on( 'click', '.tsh-wa-wf-edit-btn, .tsh-wa-wf-edit-link', function ( e ) {
			e.preventDefault();
			var id = $( this ).data( 'id' );
			openBuilder( id );
		} );

		// Activate.
		$doc.on( 'click', '.tsh-wa-wf-activate-btn', function () {
			var id = $( this ).data( 'id' );
			ajaxPost( 'tsh_wa_wf_activate', { workflow_id: id }, function () {
				showToast( i18n.activated, 'success' );
				loadWorkflowList();
			} );
		} );

		// Deactivate.
		$doc.on( 'click', '.tsh-wa-wf-deactivate-btn', function () {
			var id = $( this ).data( 'id' );
			ajaxPost( 'tsh_wa_wf_deactivate', { workflow_id: id }, function () {
				showToast( i18n.deactivated, 'success' );
				loadWorkflowList();
			} );
		} );

		// History.
		$doc.on( 'click', '.tsh-wa-wf-history-btn', function () {
			var id   = $( this ).data( 'id' );
			var name = $( this ).data( 'name' );
			openHistoryView( id, name );
		} );

		// Duplicate.
		$doc.on( 'click', '.tsh-wa-wf-duplicate-btn', function () {
			var id = $( this ).data( 'id' );
			ajaxPost( 'tsh_wa_wf_duplicate', { workflow_id: id }, function () {
				showToast( i18n.duplicated, 'success' );
				loadWorkflowList();
			} );
		} );

		// Delete.
		$doc.on( 'click', '.tsh-wa-wf-delete-btn', function () {
			var id   = $( this ).data( 'id' );
			var name = $( this ).data( 'name' );
			if ( ! window.confirm( i18n.confirm_delete + '\n\n' + name ) ) { return; }
			ajaxPost( 'tsh_wa_wf_delete', { workflow_id: id }, function () {
				showToast( i18n.deleted, 'success' );
				loadWorkflowList();
			} );
		} );

		// Search.
		$doc.on( 'input', '#tsh-wa-wf-search', function () {
			clearTimeout( state.listTimer );
			var val = $( this ).val();
			state.listTimer = setTimeout( function () {
				state.listSearch = val;
				state.listPage   = 1;
				loadWorkflowList();
			}, 350 );
		} );

		// Status filter.
		$doc.on( 'change', '#tsh-wa-wf-filter-status', function () {
			state.listStatus = $( this ).val();
			state.listPage   = 1;
			loadWorkflowList();
		} );

		// Template library button.
		$doc.on( 'click', '#tsh-wa-wf-templates-btn', openTemplatesModal );

		// Import button.
		$doc.on( 'click', '#tsh-wa-wf-import-btn', openImportModal );

		// ---- BUILDER --------------------------------------------------------

		// Back to list.
		$doc.on( 'click', '#tsh-wa-builder-back-btn', function () {
			showView( 'list' );
			loadWorkflowList();
		} );

		// Save.
		$doc.on( 'click', '#tsh-wa-builder-save-btn', function () {
			saveWorkflow();
		} );

		// Undo / redo buttons.
		$doc.on( 'click', '#tsh-wa-builder-undo', undo );
		$doc.on( 'click', '#tsh-wa-builder-redo', redo );

		// Zoom.
		$doc.on( 'click', '#tsh-wa-zoom-in',  function () {
			state.zoom = Math.min( state.zoom + 0.1, 2 );
			applyTransform();
		} );
		$doc.on( 'click', '#tsh-wa-zoom-out', function () {
			state.zoom = Math.max( state.zoom - 0.1, 0.25 );
			applyTransform();
		} );
		$doc.on( 'click', '#tsh-wa-builder-zoom-fit', fitCanvas );

		// Test run.
		$doc.on( 'click', '#tsh-wa-builder-test-btn', function () {
			if ( ! state.workflowId ) {
				showToast( 'Save the workflow first before testing.', 'error' );
				return;
			}
			showToast( i18n.test_running, 'success' );
			ajaxPost( 'tsh_wa_wf_test_run', { workflow_id: state.workflowId }, function ( data ) {
				showToast( i18n.test_done + ' (Run #' + data.run_id + ')', 'success' );
			}, function ( err ) {
				showToast( i18n.test_failed + ': ' + err, 'error' );
			} );
		} );

		// Node config — apply.
		$doc.on( 'click', '#tsh-wa-node-config-save', function () {
			if ( ! state.selectedNodeId ) { return; }
			var n = state.nodes[ state.selectedNodeId ];
			if ( ! n ) { return; }
			pushHistory();
			n.config = readConfigFromPanel();
			renderNode( n );
			refreshEdgesForNode( n.id );
			markDirty();
			showToast( 'Node updated.', 'success' );
		} );

		// Node config — delete node.
		$doc.on( 'click', '#tsh-wa-node-delete', function () {
			if ( state.selectedNodeId ) {
				deleteNode( state.selectedNodeId );
			}
		} );

		// Config close.
		$doc.on( 'click', '#tsh-wa-config-close', function () {
			selectNode( null );
		} );

		// Add condition.
		$doc.on( 'click', '#tsh-wa-add-cond', function () {
			var $builder = $( '#tsh-wa-cond-builder' );
			var count    = $builder.find( '.tsh-wa-condition-row' ).length;
			$builder.append( conditionRow( { idx: count, field: '', op: '=', value: '' }, cfg.conditions || {} ) );
		} );

		// Remove condition.
		$doc.on( 'click', '.tsh-wa-remove-cond', function () {
			$( this ).closest( '.tsh-wa-condition-row' ).remove();
		} );

		// Variable picker.
		$doc.on( 'change', '.tsh-wa-var-select', function () {
			var val = $( this ).val();
			if ( ! val ) { return; }
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( val );
			}
			showToast( 'Copied: ' + val, 'success' );
			$( this ).val( '' );
		} );

		// ---- HISTORY VIEW ---------------------------------------------------

		$doc.on( 'click', '#tsh-wa-history-back', function () {
			showView( 'list' );
		} );

		$doc.on( 'click', '.tsh-wa-view-run-btn', function () {
			openRunDetail( $( this ).data( 'run' ) );
		} );

		// ---- TEMPLATES MODAL ------------------------------------------------

		$doc.on( 'click', '.tsh-wa-import-template-btn', function () {
			var key = $( this ).data( 'key' );
			ajaxPost( 'tsh_wa_wf_import_template', { template_key: key }, function ( data ) {
				showToast( i18n.template_imported, 'success' );
				$( '#tsh-wa-templates-modal' ).hide();
				openBuilder( data.workflow_id );
			} );
		} );

		// ---- IMPORT MODAL ---------------------------------------------------

		$doc.on( 'click', '#tsh-wa-import-confirm-btn', doImport );

		// ---- MODALS — close -------------------------------------------------

		$doc.on( 'click', '.tsh-wa-modal-close, [data-modal-close]', function () {
			var target = $( this ).data( 'modal' ) || $( this ).data( 'modal-close' );
			if ( target ) {
				$( '#' + target ).hide();
			} else {
				$( this ).closest( '.tsh-wa-modal-overlay' ).hide();
			}
		} );

		$doc.on( 'click', '.tsh-wa-modal-overlay', function ( e ) {
			if ( $( e.target ).is( '.tsh-wa-modal-overlay' ) ) {
				$( this ).hide();
			}
		} );
	}

	// =========================================================================
	// INIT
	// =========================================================================

	function init() {
		// Cache DOM.
		$listView    = $( '#tsh-wa-wf-list-view' );
		$builderView = $( '#tsh-wa-wf-builder-view' );
		$historyView = $( '#tsh-wa-wf-history-view' );
		$wfTableBody = $( '#tsh-wa-wf-table-body' );
		$canvas      = $( '#tsh-wa-canvas' );
		$canvasSvg   = $( '#tsh-wa-connections-svg' );
		$canvasWrap  = $( '#tsh-wa-canvas-wrap' );
		$zoomLevel   = $( '#tsh-wa-zoom-level' );
		$minimap     = $( '#tsh-wa-minimap' );
		$minimapCanvas = $( '#tsh-wa-minimap-canvas' );
		$configPanel = $( '#tsh-wa-node-config-panel' );
		$configBody  = $( '#tsh-wa-node-config-body' );
		$configTitle = $( '#tsh-wa-config-title' );
		$builderName = $( '#tsh-wa-builder-name' );
		$builderStatus = $( '#tsh-wa-builder-status' );
		$autosave    = $( '#tsh-wa-builder-autosave' );
		$historyBody = $( '#tsh-wa-history-body' );

		// If not on the automation page, bail.
		if ( ! $listView.length ) { return; }

		showView( 'list' );

		bindEvents();
		bindPaletteDrag();
		bindCanvasMouse();
		bindKeyboard();

		// Load initial list (table already rendered server-side, but refresh to ensure AJAX state).
		// Only refresh if table is empty (server rendered it otherwise).
		if ( ! $wfTableBody.find( '.tsh-wa-wf-row' ).length ) {
			loadWorkflowList();
		}

		// Connect builder name/status changes to dirty flag.
		$doc.on( 'input change', '#tsh-wa-builder-name, #tsh-wa-builder-status', function () {
			if ( $builderView.is( ':visible' ) ) { markDirty(); }
		} );

		applyTransform();
	}

	// =========================================================================
	// Bootstrap
	// =========================================================================

	$( init );

}( jQuery ) );
