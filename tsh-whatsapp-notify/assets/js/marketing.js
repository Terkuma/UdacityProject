/**
 * TSH WhatsApp Notify — Marketing & Broadcast Engine JS
 * Phase 8
 *
 * jQuery-based module. Requires tshWaAdmin (nonce/ajaxUrl) and
 * tshWaMarketing (i18n) and tshWaMarketingData (page data).
 */
/* global tshWaAdmin, tshWaMarketing, tshWaMarketingData, jQuery */
(function ( $ ) {
	'use strict';

	const ajax   = () => tshWaAdmin.ajaxUrl;
	const nonce  = () => tshWaAdmin.nonce;
	const cfg    = window.tshWaMarketing   || {};
	const data   = window.tshWaMarketingData || {};
	const i18n   = cfg.i18n || {};

	// =========================================================================
	// Utilities
	// =========================================================================

	function ajaxPost( action, params, onSuccess, onError ) {
		return $.post( ajax(), Object.assign( { action, _ajax_nonce: nonce() }, params ) )
			.done( function ( res ) {
				if ( res && res.success ) {
					onSuccess( res.data );
				} else {
					const msg = res && res.data && res.data.message ? res.data.message : ( i18n.error || 'Error.' );
					if ( onError ) onError( msg ); else toast( msg, 'error' );
				}
			} )
			.fail( function () {
				const msg = i18n.error || 'Request failed.';
				if ( onError ) onError( msg ); else toast( msg, 'error' );
			} );
	}

	let toastTimer = {};

	function toast( message, type ) {
		type = type || 'success';
		const id  = 'toast-' + Date.now();
		const el  = $( `<div class="mkt-toast ${type}" id="${id}">` ).text( message );
		$( '#mkt-toasts' ).append( el );
		toastTimer[ id ] = setTimeout( () => $( '#' + id ).fadeOut( 300, function () { $( this ).remove(); } ), 3500 );
	}

	function statusBadge( status ) {
		const labels = {
			draft:     'Draft',
			scheduled: 'Scheduled',
			running:   'Running',
			paused:    'Paused',
			completed: 'Completed',
			failed:    'Failed',
			cancelled: 'Cancelled',
			archived:  'Archived',
		};
		const dot = [ 'running', 'scheduled', 'completed' ].includes( status )
			? '<span class="mkt-badge-dot"></span>' : '';
		return `<span class="mkt-badge mkt-badge-${status}">${dot}${labels[ status ] || status}</span>`;
	}

	function typeBadge( type ) {
		const labels = { onetime: 'One-time', scheduled: 'Scheduled', recurring: 'Recurring' };
		const icons  = { onetime: '📨', scheduled: '🗓️', recurring: '🔁' };
		return `${icons[ type ] || ''} <span style="font-size:12px;">${labels[ type ] || type}</span>`;
	}

	function fmt( n ) { return Number( n || 0 ).toLocaleString(); }

	function fmtDate( d ) {
		if ( ! d || d === '0000-00-00 00:00:00' ) return '–';
		return new Date( d.replace( ' ', 'T' ) ).toLocaleDateString();
	}

	// =========================================================================
	// View switcher
	// =========================================================================

	$( '.mkt-tab' ).on( 'click', function () {
		$( '.mkt-tab' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		const view = $( this ).data( 'view' );
		$( '.mkt-view' ).removeClass( 'active' );
		$( '#mkt-view-' + view ).addClass( 'active' );

		if ( view === 'dashboard'  ) loadDashboard();
		if ( view === 'library'    ) loadLibrary();
		if ( view === 'segments'   ) loadSegments();
	} );

	// =========================================================================
	// Campaign list view
	// =========================================================================

	let listPage = 1;
	let listTotal = 0;
	const perPage = 15;

	function loadCampaigns() {
		const $tbody = $( '#mkt-campaigns-tbody' );
		$tbody.html( '<tr><td colspan="8"><div class="mkt-loading"><div class="mkt-spinner"></div> Loading…</div></td></tr>' );
		$( '#mkt-pagination' ).hide();

		ajaxPost( 'tsh_wa_mkt_list', {
			status:   $( '#mkt-filter-status' ).val(),
			type:     $( '#mkt-filter-type' ).val(),
			search:   $( '#mkt-search' ).val(),
			per_page: perPage,
			page:     listPage,
		}, function ( res ) {
			listTotal = res.total || 0;
			renderCampaignTable( res.rows || [] );
			renderPagination();
		} );
	}

	function renderCampaignTable( rows ) {
		const $tbody = $( '#mkt-campaigns-tbody' );
		if ( ! rows.length ) {
			$tbody.html( '<tr><td colspan="8"><div class="mkt-empty"><div class="mkt-empty-icon">📭</div><div class="mkt-empty-title">No campaigns found</div><div class="mkt-empty-desc">Create your first campaign to get started.</div></div></td></tr>' );
			return;
		}
		const html = rows.map( r => `
			<tr>
				<td>
					<div class="mkt-campaign-name">${esc(r.name)}</div>
					${ r.description ? `<div class="mkt-campaign-desc">${esc(r.description)}</div>` : '' }
				</td>
				<td>${statusBadge(r.status)}</td>
				<td>${typeBadge(r.type)}</td>
				<td>${fmt(r.total_audience)}</td>
				<td>${fmt(r.total_sent)}</td>
				<td>${r.total_failed > 0 ? `<span style="color:#ef4444;font-weight:600;">${fmt(r.total_failed)}</span>` : '0'}</td>
				<td>${fmtDate(r.send_at || r.sent_at)}</td>
				<td>
					<div class="mkt-row-actions">
						<button class="mkt-action-btn launch" data-action="launch" data-id="${r.id}" title="Launch">🚀</button>
						<button class="mkt-action-btn" data-action="edit"      data-id="${r.id}" title="Edit">✏️</button>
						<button class="mkt-action-btn" data-action="analytics" data-id="${r.id}" title="Analytics">📊</button>
						<button class="mkt-action-btn" data-action="duplicate" data-id="${r.id}" title="Duplicate">📋</button>
						<button class="mkt-action-btn" data-action="logs"      data-id="${r.id}" title="Logs">📝</button>
						${ r.status === 'running' ? `<button class="mkt-action-btn" data-action="pause" data-id="${r.id}" title="Pause">⏸️</button>` : '' }
						${ r.status === 'paused'  ? `<button class="mkt-action-btn" data-action="resume" data-id="${r.id}" title="Resume">▶️</button>` : '' }
						<button class="mkt-action-btn danger" data-action="delete" data-id="${r.id}" title="Delete">🗑️</button>
					</div>
				</td>
			</tr>` ).join( '' );
		$tbody.html( html );
	}

	function esc( str ) {
		return $( '<div>' ).text( str || '' ).html();
	}

	function renderPagination() {
		const $pag = $( '#mkt-pagination' );
		if ( listTotal <= perPage ) { $pag.hide(); return; }

		const totalPages = Math.ceil( listTotal / perPage );
		const start      = ( listPage - 1 ) * perPage + 1;
		const end        = Math.min( listPage * perPage, listTotal );

		$( '#mkt-pagination-info' ).text( `Showing ${start}–${end} of ${listTotal}` );

		const btns = [];
		btns.push( `<button data-page="${listPage - 1}" ${listPage <= 1 ? 'disabled' : ''}>‹</button>` );
		for ( let p = 1; p <= totalPages; p++ ) {
			if ( totalPages > 10 && Math.abs( p - listPage ) > 2 && p !== 1 && p !== totalPages ) {
				if ( p === 2 || p === totalPages - 1 ) btns.push( '<button disabled>…</button>' );
				continue;
			}
			btns.push( `<button data-page="${p}" class="${p === listPage ? 'active' : ''}">${p}</button>` );
		}
		btns.push( `<button data-page="${listPage + 1}" ${listPage >= totalPages ? 'disabled' : ''}>›</button>` );

		$( '#mkt-pagination-btns' ).html( btns.join( '' ) );
		$pag.show();
	}

	// Pagination click
	$( document ).on( 'click', '#mkt-pagination-btns button:not(:disabled)', function () {
		listPage = +$( this ).data( 'page' );
		loadCampaigns();
	} );

	// Search + filter (debounced)
	let searchTimer;
	$( '#mkt-search, #mkt-filter-status, #mkt-filter-type' ).on( 'input change', function () {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( () => { listPage = 1; loadCampaigns(); }, 300 );
	} );

	// Row action delegation
	$( document ).on( 'click', '[data-action]', function () {
		const action = $( this ).data( 'action' );
		const id     = +$( this ).data( 'id' );

		switch ( action ) {
			case 'edit':      openBuilder( id ); break;
			case 'analytics': openAnalytics( id ); break;
			case 'logs':      openLogs( id ); break;
			case 'duplicate': duplicateCampaign( id ); break;
			case 'launch':    launchCampaign( id ); break;
			case 'pause':     pauseCampaign( id ); break;
			case 'resume':    resumeCampaign( id ); break;
			case 'cancel':    cancelCampaign( id ); break;
			case 'archive':   archiveCampaign( id ); break;
			case 'delete':    deleteCampaign( id ); break;
		}
	} );

	// New campaign button
	$( '#mkt-btn-new-campaign' ).on( 'click', function ( e ) {
		e.preventDefault();
		openBuilder( 0 );
	} );

	// =========================================================================
	// Campaign actions
	// =========================================================================

	function launchCampaign( id ) {
		if ( ! confirm( 'Launch this campaign now?' ) ) return;
		ajaxPost( 'tsh_wa_mkt_launch', { campaign_id: id }, function () {
			toast( 'Campaign launched!', 'success' );
			loadCampaigns();
		} );
	}

	function pauseCampaign( id ) {
		ajaxPost( 'tsh_wa_mkt_pause', { campaign_id: id }, function () {
			toast( 'Campaign paused.', 'success' );
			loadCampaigns();
		} );
	}

	function resumeCampaign( id ) {
		ajaxPost( 'tsh_wa_mkt_resume', { campaign_id: id }, function () {
			toast( 'Campaign resumed.', 'success' );
			loadCampaigns();
		} );
	}

	function cancelCampaign( id ) {
		if ( ! confirm( i18n.confirm_cancel || 'Cancel this campaign?' ) ) return;
		ajaxPost( 'tsh_wa_mkt_cancel', { campaign_id: id }, function () {
			toast( 'Campaign cancelled.', 'success' );
			loadCampaigns();
		} );
	}

	function archiveCampaign( id ) {
		if ( ! confirm( i18n.confirm_archive || 'Archive this campaign?' ) ) return;
		ajaxPost( 'tsh_wa_mkt_archive', { campaign_id: id }, function () {
			toast( 'Campaign archived.', 'success' );
			loadCampaigns();
		} );
	}

	function deleteCampaign( id ) {
		if ( ! confirm( i18n.confirm_delete || 'Delete this campaign?' ) ) return;
		ajaxPost( 'tsh_wa_mkt_delete', { campaign_id: id }, function () {
			toast( 'Campaign deleted.', 'success' );
			loadCampaigns();
		} );
	}

	function duplicateCampaign( id ) {
		ajaxPost( 'tsh_wa_mkt_duplicate', { campaign_id: id }, function ( res ) {
			toast( 'Campaign duplicated.', 'success' );
			loadCampaigns();
			openBuilder( res.campaign_id );
		} );
	}

	// =========================================================================
	// Campaign builder modal
	// =========================================================================

	let builderCampaignId = 0;
	let currentStep       = 1;
	const TOTAL_STEPS     = 6;
	let campaignType      = 'onetime';
	let selectedTemplateA = 0;
	let selectedTemplateB = 0;
	let audienceType      = 'all_customers';
	let rulesLogic        = 'AND';
	let rules             = [];
	let couponEnabled     = false;
	let msgsPerMin        = 30;
	let msgsPerHour       = 1000;
	let batchSize         = 100;
	let retryAttempts     = 3;

	function openBuilder( campaignId ) {
		builderCampaignId = campaignId;
		currentStep       = 1;
		rules             = [];
		selectedTemplateA = 0;
		selectedTemplateB = 0;

		if ( campaignId ) {
			$( '#mkt-builder-modal-title' ).text( 'Edit Campaign' );
			ajaxPost( 'tsh_wa_mkt_get', { campaign_id: campaignId }, function ( res ) {
				populateBuilder( res.campaign );
				showModal( 'mkt-builder-modal' );
				goToStep( 1 );
			} );
		} else {
			$( '#mkt-builder-modal-title' ).text( 'New Campaign' );
			resetBuilder();
			showModal( 'mkt-builder-modal' );
			goToStep( 1 );
		}
	}

	function resetBuilder() {
		$( '#mkt-campaign-name' ).val( '' );
		$( '#mkt-campaign-description' ).val( '' );
		$( '#mkt-message-body' ).val( '' );
		$( '#mkt-char-count' ).text( '0' );
		campaignType = 'onetime';
		$( '.mkt-type-card' ).removeClass( 'active' );
		$( '.mkt-type-card[data-type="onetime"]' ).addClass( 'active' );
		audienceType = 'all_customers';
		rules = [];
		renderRules();
		selectedTemplateA = 0;
		selectedTemplateB = 0;
		$( '.mkt-template-card' ).removeClass( 'active' );
		$( '#mkt-coupon-enabled' ).prop( 'checked', false );
		$( '#mkt-coupon-fields' ).removeClass( 'visible' );
		$( '#mkt-ab-enabled' ).prop( 'checked', false );
		$( '#mkt-ab-fields' ).hide();
		$( '#mkt-send-timing' ).val( 'immediate' );
		$( '#mkt-scheduled-time-field' ).hide();
		$( '#mkt-recurring-fields' ).hide();
		updateAudienceTypeGrid();
	}

	function populateBuilder( c ) {
		$( '#mkt-campaign-name' ).val( c.name || '' );
		$( '#mkt-campaign-description' ).val( c.description || '' );

		// Type
		campaignType = c.type || 'onetime';
		$( '.mkt-type-card' ).removeClass( 'active' );
		$( `.mkt-type-card[data-type="${campaignType}"]` ).addClass( 'active' );

		// Audience
		const ac = c.audience_config || {};
		audienceType = ac.type || 'all_customers';
		rules        = ac.rules || [];
		rulesLogic   = ac.logic || 'AND';
		updateAudienceTypeGrid();
		renderRules();

		// Template
		selectedTemplateA = +( c.template_id   || 0 );
		selectedTemplateB = +( c.template_b_id || 0 );

		// Message
		const mc = c.message_config || {};
		$( '#mkt-message-body' ).val( mc.body || '' ).trigger( 'input' );

		// Coupon
		const cc = c.coupon_config || {};
		couponEnabled = !! cc.enabled;
		$( '#mkt-coupon-enabled' ).prop( 'checked', couponEnabled );
		if ( couponEnabled ) $( '#mkt-coupon-fields' ).addClass( 'visible' );
		if ( cc.discount_type ) $( '#mkt-coupon-type' ).val( cc.discount_type );
		if ( cc.amount )      $( '#mkt-coupon-amount' ).val( cc.amount );
		if ( cc.expiry_days ) $( '#mkt-coupon-expiry' ).val( cc.expiry_days );
		if ( cc.usage_limit ) $( '#mkt-coupon-usage-limit' ).val( cc.usage_limit );
		if ( cc.min_spend )   $( '#mkt-coupon-min-spend' ).val( cc.min_spend );
		if ( cc.max_spend )   $( '#mkt-coupon-max-spend' ).val( cc.max_spend );
		if ( cc.prefix )      $( '#mkt-coupon-prefix' ).val( cc.prefix );

		// Throttle
		const tc = c.throttle_config || {};
		msgsPerMin    = +( tc.msgs_per_minute || 30 );
		msgsPerHour   = +( tc.msgs_per_hour   || 1000 );
		batchSize     = +( tc.batch_size      || 100 );
		retryAttempts = +( tc.retry_attempts  || 3 );
		$( '#mkt-msgs-per-min' ).val( msgsPerMin );
		$( '#mkt-msgs-per-hour' ).val( msgsPerHour );
		$( '#mkt-batch-size' ).val( batchSize );
		$( '#mkt-retry-attempts' ).val( retryAttempts );

		// Schedule
		if ( c.send_at ) {
			$( '#mkt-send-timing' ).val( 'scheduled' );
			$( '#mkt-scheduled-time-field' ).show();
			$( '#mkt-send-at' ).val( c.send_at.replace( ' ', 'T' ).slice( 0, 16 ) );
		}

		// Recurring
		const sc = c.schedule_config || {};
		if ( sc.recurrence ) {
			$( '#mkt-recurrence' ).val( sc.recurrence );
			$( '#mkt-recurrence-day' ).val( sc.day_of_week || 1 );
			$( '#mkt-recurrence-time' ).val( sc.time || '09:00' );
		}

		// A/B
		if ( c.template_b_id ) {
			$( '#mkt-ab-enabled' ).prop( 'checked', true );
			$( '#mkt-ab-fields' ).show();
			$( '#mkt-ab-split' ).val( c.ab_split_ratio || 50 ).trigger( 'input' );
		}
	}

	// Step navigation
	$( '#mkt-btn-next' ).on( 'click', function () {
		if ( currentStep < TOTAL_STEPS ) goToStep( currentStep + 1 );
	} );

	$( '#mkt-btn-prev' ).on( 'click', function () {
		if ( currentStep > 1 ) goToStep( currentStep - 1 );
	} );

	$( '.mkt-step' ).on( 'click', function () {
		const target = +$( this ).data( 'step' );
		if ( target < currentStep ) goToStep( target );
	} );

	function goToStep( step ) {
		currentStep = step;
		$( '.mkt-step-panel' ).removeClass( 'active' );
		$( `.mkt-step-panel[data-panel="${step}"]` ).addClass( 'active' );

		$( '.mkt-step' ).each( function () {
			const n = +$( this ).data( 'step' );
			$( this ).removeClass( 'active done' );
			if ( n === step ) $( this ).addClass( 'active' );
			if ( n < step )   $( this ).addClass( 'done' );
		} );

		$( '#mkt-btn-prev' ).prop( 'disabled', step <= 1 );
		$( '#mkt-btn-next' ).toggle( step < TOTAL_STEPS );
		$( '#mkt-step-label' ).text( `Step ${step} of ${TOTAL_STEPS}` );

		if ( step === 3 ) renderTemplateGrid();
		if ( step === 6 ) loadPreview();
	}

	// Campaign type selection
	$( '.mkt-type-card' ).on( 'click', function () {
		$( '.mkt-type-card' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		campaignType = $( this ).data( 'type' );
		$( '#mkt-recurring-fields' ).toggle( campaignType === 'recurring' );
	} );

	// =========================================================================
	// Audience builder
	// =========================================================================

	function updateAudienceTypeGrid() {
		const types  = data.audienceTypes || {};
		const custom = [ 'custom', 'saved_segment' ];
		let html     = '';

		Object.entries( types ).forEach( ( [ key, label ] ) => {
			html += `<div class="mkt-audience-chip ${ key === audienceType ? 'active' : '' }" data-type="${key}">${label}</div>`;
		} );

		$( '#mkt-audience-type-grid' ).html( html );
		$( '#mkt-audience-count-row' ).show();
		$( '#mkt-rules-section' ).toggle( audienceType === 'custom' );
		$( '#mkt-saved-segment-field' ).toggle( audienceType === 'saved_segment' );
	}

	$( document ).on( 'click', '.mkt-audience-chip', function () {
		$( '.mkt-audience-chip' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		audienceType = $( this ).data( 'type' );
		$( '#mkt-audience-count' ).text( '–' );
		$( '#mkt-rules-section' ).toggle( audienceType === 'custom' );
		$( '#mkt-saved-segment-field' ).toggle( audienceType === 'saved_segment' );
	} );

	// Rules
	const ruleFields = [
		{ value: 'order_count',     label: 'Order Count' },
		{ value: 'lifetime_value',  label: 'Lifetime Value' },
		{ value: 'registration_date', label: 'Registration Date' },
		{ value: 'last_purchase',   label: 'Last Purchase' },
		{ value: 'first_order_date',label: 'First Order Date' },
		{ value: 'billing_country', label: 'Billing Country' },
		{ value: 'billing_state',   label: 'Billing State' },
		{ value: 'billing_city',    label: 'Billing City' },
	];

	const numericOps = [
		{ value: 'greater_than',          label: '>' },
		{ value: 'less_than',             label: '<' },
		{ value: 'greater_than_or_equal', label: '>=' },
		{ value: 'less_than_or_equal',    label: '<=' },
		{ value: 'equals',                label: '=' },
	];

	const dateOps = [
		{ value: 'within_days',       label: 'Within last N days' },
		{ value: 'more_than_days_ago',label: 'More than N days ago' },
		{ value: 'before',            label: 'Before date' },
		{ value: 'after',             label: 'After date' },
	];

	function renderRules() {
		const $container = $( '#mkt-rules-container' );
		if ( ! rules.length ) {
			$container.html( '<div style="font-size:12px;color:#9ca3af;padding:8px;">No rules — all customers matching the selected audience type will be included.</div>' );
			return;
		}

		const fieldOpts = ruleFields.map( f => `<option value="${f.value}">${f.label}</option>` ).join( '' );

		const html = rules.map( ( rule, i ) => {
			const isDate    = [ 'registration_date', 'last_purchase', 'first_order_date' ].includes( rule.field );
			const opOptions = ( isDate ? dateOps : numericOps ).map( o => `<option value="${o.value}" ${o.value === rule.operator ? 'selected' : ''}>${o.label}</option>` ).join( '' );

			return `
			<div class="mkt-rule-row" data-rule="${i}">
				<select class="mkt-rule-field" data-field>
					${ruleFields.map( f => `<option value="${f.value}" ${f.value === rule.field ? 'selected' : ''}>${f.label}</option>` ).join( '' )}
				</select>
				<select class="mkt-rule-operator" data-op>${opOptions}</select>
				<input type="text" class="mkt-rule-value" data-val value="${esc(String(rule.value || ''))}">
				<button class="mkt-rule-remove" data-remove="${i}">✕</button>
			</div>`;
		} ).join( '' );

		$container.html( html );
	}

	$( '#mkt-btn-add-rule' ).on( 'click', function () {
		rules.push( { field: 'order_count', operator: 'greater_than', value: 1 } );
		renderRules();
	} );

	$( document ).on( 'click', '[data-remove]', function () {
		const idx = +$( this ).data( 'remove' );
		rules.splice( idx, 1 );
		renderRules();
	} );

	$( document ).on( 'change', '.mkt-rule-field', function () {
		const idx   = +$( this ).closest( '.mkt-rule-row' ).data( 'rule' );
		rules[ idx ].field = $( this ).val();
		renderRules();
	} );

	$( document ).on( 'change', '.mkt-rule-operator', function () {
		const idx = +$( this ).closest( '.mkt-rule-row' ).data( 'rule' );
		rules[ idx ].operator = $( this ).val();
	} );

	$( document ).on( 'input', '.mkt-rule-value', function () {
		const idx = +$( this ).closest( '.mkt-rule-row' ).data( 'rule' );
		rules[ idx ].value = $( this ).val();
	} );

	$( '.mkt-logic-btn' ).on( 'click', function () {
		$( '.mkt-logic-btn' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		rulesLogic = $( this ).data( 'logic' );
	} );

	$( '#mkt-btn-estimate' ).on( 'click', function () {
		$( '#mkt-audience-count' ).text( '…' );
		ajaxPost( 'tsh_wa_mkt_estimate_audience', {
			audience_config: JSON.stringify( getAudienceConfig() ),
		}, function ( res ) {
			$( '#mkt-audience-count' ).text( res.count ? res.count.toLocaleString() : '0' );
		} );
	} );

	function getAudienceConfig() {
		return { type: audienceType, rules, logic: rulesLogic };
	}

	// =========================================================================
	// Template picker
	// =========================================================================

	function renderTemplateGrid() {
		const templates = data.metaTemplates || [];
		const search    = ( $( '#mkt-template-search' ).val() || '' ).toLowerCase();

		const filtered = templates.filter( t =>
			! search || ( t.name || '' ).toLowerCase().includes( search ) || ( t.body || '' ).toLowerCase().includes( search )
		);

		const makeCard = ( t, selectedId, gridId ) => {
			const active = +t.id === selectedId ? 'active' : '';
			return `<div class="mkt-template-card ${active}" data-id="${t.id}" data-grid="${gridId}">
				<div class="mkt-template-name">${esc(t.name)}</div>
				<div class="mkt-template-preview">${esc(t.body || '')}</div>
				<div class="mkt-template-status">${statusBadge(t.status || 'approved')}</div>
			</div>`;
		};

		if ( ! filtered.length ) {
			const empty = '<div class="mkt-empty" style="padding:20px;"><div class="mkt-empty-icon">📋</div><div class="mkt-empty-desc">No approved templates found. Sync your Meta templates first.</div></div>';
			$( '#mkt-template-grid' ).html( empty );
			$( '#mkt-template-grid-b' ).html( empty );
			return;
		}

		$( '#mkt-template-grid' ).html( filtered.map( t => makeCard( t, selectedTemplateA, 'a' ) ).join( '' ) );
		$( '#mkt-template-grid-b' ).html( filtered.map( t => makeCard( t, selectedTemplateB, 'b' ) ).join( '' ) );
	}

	$( '#mkt-template-search' ).on( 'input', function () { renderTemplateGrid(); } );

	$( document ).on( 'click', '.mkt-template-card', function () {
		const grid = $( this ).data( 'grid' );
		const id   = +$( this ).data( 'id' );

		if ( grid === 'b' ) {
			selectedTemplateB = id;
			$( '#mkt-template-grid-b .mkt-template-card' ).removeClass( 'active' );
		} else {
			selectedTemplateA = id;
			$( '#mkt-template-grid .mkt-template-card' ).removeClass( 'active' );
		}

		$( this ).addClass( 'active' );
	} );

	// A/B test toggle
	$( '#mkt-ab-enabled' ).on( 'change', function () {
		$( '#mkt-ab-fields' ).toggle( this.checked );
	} );

	$( '#mkt-ab-split' ).on( 'input', function () {
		const b = +this.value;
		$( '#mkt-split-b-label' ).text( b );
		$( '#mkt-split-a-label' ).text( 100 - b );
		$( this ).css( 'background', `linear-gradient(to right, var(--mkt-green) ${100 - b}%, var(--mkt-blue) ${100 - b}%)` );
	} );

	// =========================================================================
	// Message body
	// =========================================================================

	$( '#mkt-message-body' ).on( 'input', function () {
		$( '#mkt-char-count' ).text( this.value.length );
		$( '#mkt-message-preview-body' ).text( this.value || '(No message body entered yet)' );
	} );

	$( '.mkt-var-chip' ).on( 'click', function () {
		const $ta  = $( '#mkt-message-body' );
		const pos  = $ta[0].selectionStart || $ta.val().length;
		const val  = $ta.val();
		const v    = $( this ).data( 'var' );
		$ta.val( val.slice( 0, pos ) + v + val.slice( pos ) ).trigger( 'input' );
	} );

	// =========================================================================
	// Coupon engine
	// =========================================================================

	$( '#mkt-coupon-enabled' ).on( 'change', function () {
		couponEnabled = this.checked;
		$( '#mkt-coupon-fields' ).toggleClass( 'visible', this.checked );
	} );

	function getCouponConfig() {
		if ( ! couponEnabled ) return {};
		return {
			enabled:      true,
			discount_type:$( '#mkt-coupon-type' ).val(),
			amount:       +$( '#mkt-coupon-amount' ).val(),
			expiry_days:  +$( '#mkt-coupon-expiry' ).val(),
			usage_limit:  +$( '#mkt-coupon-usage-limit' ).val(),
			min_spend:    $( '#mkt-coupon-min-spend' ).val() || null,
			max_spend:    $( '#mkt-coupon-max-spend' ).val() || null,
			prefix:       $( '#mkt-coupon-prefix' ).val() || 'TSH',
		};
	}

	// =========================================================================
	// Schedule / throttle
	// =========================================================================

	$( '#mkt-send-timing' ).on( 'change', function () {
		$( '#mkt-scheduled-time-field' ).toggle( this.value === 'scheduled' );
	} );

	$( '.mkt-preset-btn' ).on( 'click', function () {
		$( '.mkt-preset-btn' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		msgsPerMin  = +$( this ).data( 'msgs-min' )  || 30;
		msgsPerHour = +$( this ).data( 'msgs-hour' ) || 1000;
		batchSize   = +$( this ).data( 'batch' )     || 100;
		const isCustom = msgsPerMin === 0;
		$( '#mkt-throttle-custom' ).toggle( isCustom );
		if ( ! isCustom ) {
			$( '#mkt-msgs-per-min' ).val( msgsPerMin );
			$( '#mkt-msgs-per-hour' ).val( msgsPerHour );
			$( '#mkt-batch-size' ).val( batchSize );
		}
	} );

	$( '#mkt-msgs-per-min, #mkt-msgs-per-hour, #mkt-batch-size' ).on( 'input', function () {
		msgsPerMin  = +$( '#mkt-msgs-per-min' ).val()  || 30;
		msgsPerHour = +$( '#mkt-msgs-per-hour' ).val() || 1000;
		batchSize   = +$( '#mkt-batch-size' ).val()    || 100;
	} );

	function getThrottleConfig() {
		return {
			msgs_per_minute: msgsPerMin,
			msgs_per_hour:   msgsPerHour,
			batch_size:      batchSize,
			retry_attempts:  +$( '#mkt-retry-attempts' ).val() || 3,
		};
	}

	function getScheduleConfig() {
		if ( campaignType !== 'recurring' ) return {};
		return {
			recurrence:   $( '#mkt-recurrence' ).val(),
			day_of_week:  +$( '#mkt-recurrence-day' ).val(),
			time:         $( '#mkt-recurrence-time' ).val(),
			timezone:     'site',
		};
	}

	// =========================================================================
	// Preview (Step 6)
	// =========================================================================

	function loadPreview() {
		$( '#mkt-preview-loading' ).show();
		$( '#mkt-preview-content' ).hide();

		const abEnabled   = $( '#mkt-ab-enabled' ).is( ':checked' );
		const couponOn    = couponEnabled;
		const abSplit     = +$( '#mkt-ab-split' ).val() || 50;

		ajaxPost( 'tsh_wa_mkt_preview', {
			audience_config: JSON.stringify( getAudienceConfig() ),
			throttle_config: JSON.stringify( getThrottleConfig() ),
			template_b_id:   abEnabled ? selectedTemplateB : 0,
			coupon_config:   JSON.stringify( getCouponConfig() ),
		}, function ( res ) {
			$( '#mkt-preview-loading' ).hide();
			$( '#mkt-preview-content' ).show();

			$( '#mkt-prev-audience' ).text( ( res.audience_count || 0 ).toLocaleString() );

			const mins = res.estimated_minutes || 0;
			const dur  = mins < 60 ? `${mins} min` : `${Math.round(mins/60)} hr ${mins%60} min`;
			$( '#mkt-prev-duration' ).text( dur );
			$( '#mkt-prev-rate' ).text( `${res.msgs_per_minute} msg/min` );

			if ( abEnabled ) {
				$( '#mkt-prev-ab-row' ).show();
				$( '#mkt-prev-ab-split' ).text( `${100-abSplit}% template A / ${abSplit}% template B` );
			} else {
				$( '#mkt-prev-ab-row' ).hide();
			}

			if ( couponOn ) {
				$( '#mkt-prev-coupon-row' ).show();
				$( '#mkt-prev-coupon-info' ).text( `${$( '#mkt-coupon-amount' ).val()}${$('#mkt-coupon-type').val() === 'percent' ? '%' : ' ' + (cfg.currency||'$')} off, expires in ${$('#mkt-coupon-expiry').val()} days` );
			} else {
				$( '#mkt-prev-coupon-row' ).hide();
			}
		} );

		// Message preview
		$( '#mkt-message-preview-body' ).text( $( '#mkt-message-body' ).val() || '(No message body entered yet)' );
	}

	// =========================================================================
	// Builder: save / launch
	// =========================================================================

	function collectCampaignData( status ) {
		const abEnabled  = $( '#mkt-ab-enabled' ).is( ':checked' );
		const sendTiming = $( '#mkt-send-timing' ).val();
		const sendAt     = sendTiming === 'scheduled' ? $( '#mkt-send-at' ).val().replace( 'T', ' ' ) : null;

		return {
			name:            $( '#mkt-campaign-name' ).val(),
			description:     $( '#mkt-campaign-description' ).val(),
			status:          status || 'draft',
			type:            campaignType,
			template_id:     selectedTemplateA || null,
			template_b_id:   abEnabled ? ( selectedTemplateB || null ) : null,
			ab_split_ratio:  abEnabled ? ( +$( '#mkt-ab-split' ).val() || 50 ) : 50,
			send_at:         sendAt,
			audience_config: JSON.stringify( getAudienceConfig() ),
			message_config:  JSON.stringify( { body: $( '#mkt-message-body' ).val() } ),
			schedule_config: JSON.stringify( getScheduleConfig() ),
			coupon_config:   JSON.stringify( getCouponConfig() ),
			throttle_config: JSON.stringify( getThrottleConfig() ),
		};
	}

	function saveCampaign( status, callback ) {
		const payload = collectCampaignData( status );

		if ( ! payload.name ) { toast( 'Campaign name is required.', 'error' ); return; }

		const action = builderCampaignId ? 'tsh_wa_mkt_update' : 'tsh_wa_mkt_create';
		if ( builderCampaignId ) payload.campaign_id = builderCampaignId;

		ajaxPost( action, payload, function ( res ) {
			if ( ! builderCampaignId && res.campaign_id ) {
				builderCampaignId = res.campaign_id;
			}
			toast( 'Campaign saved.', 'success' );
			if ( callback ) callback( res );
		} );
	}

	$( '#mkt-btn-save-draft' ).on( 'click', function () {
		saveCampaign( 'draft', function () { closeModal( 'mkt-builder-modal' ); loadCampaigns(); } );
	} );

	$( '#mkt-btn-schedule-launch' ).on( 'click', function () {
		saveCampaign( 'draft', function () {
			ajaxPost( 'tsh_wa_mkt_launch', { campaign_id: builderCampaignId, schedule_only: 1 }, function () {
				toast( 'Campaign scheduled!', 'success' );
				closeModal( 'mkt-builder-modal' );
				loadCampaigns();
			} );
		} );
	} );

	$( '#mkt-btn-launch' ).on( 'click', function () {
		saveCampaign( 'draft', function () {
			ajaxPost( 'tsh_wa_mkt_launch', { campaign_id: builderCampaignId }, function () {
				toast( 'Campaign launched! 🚀', 'success' );
				closeModal( 'mkt-builder-modal' );
				loadCampaigns();
			} );
		} );
	} );

	$( '#mkt-builder-close' ).on( 'click', function () { closeModal( 'mkt-builder-modal' ); } );

	// =========================================================================
	// Analytics modal
	// =========================================================================

	function openAnalytics( campaignId ) {
		showModal( 'mkt-analytics-modal' );
		const $body = $( '#mkt-analytics-body' );
		$body.html( '<div class="mkt-loading"><div class="mkt-spinner"></div> Loading…</div>' );

		ajaxPost( 'tsh_wa_mkt_analytics', { campaign_id: campaignId }, function ( res ) {
			const c = res.campaign || {};
			const d = res.delivery || {};
			const ab = res.ab_test || {};

			$( '#mkt-analytics-title' ).text( 'Analytics: ' + ( c.name || '' ) );

			const sent      = +( c.total_sent      || 0 );
			const delivered = +( c.total_delivered || 0 );
			const read      = +( c.total_read      || 0 );
			const failed    = +( c.total_failed    || 0 );
			const audience  = Math.max( 1, +( c.total_audience || 1 ) );

			const delivRate = sent > 0 ? Math.round( ( delivered / sent ) * 100 ) : 0;
			const readRate  = sent > 0 ? Math.round( ( read / sent ) * 100 )      : 0;

			let html = `
			<div class="mkt-analytics-grid">
				${statCard('Audience',  fmt(c.total_audience), '', 100)}
				${statCard('Sent',      fmt(sent), '', Math.round((sent/audience)*100))}
				${statCard('Delivered', fmt(delivered), delivRate + '% rate', delivRate)}
				${statCard('Read',      fmt(read), readRate + '% rate', readRate)}
				${statCard('Failed',    fmt(failed), '', 0, 'red')}
				${statCard('Coupons',   fmt(c.total_coupons), '', 0)}
				${statCard('Revenue',   (cfg.currency||'$') + fmt(res.revenue || 0), '', 0, 'purple')}
				${statCard('Orders',    fmt(res.orders || 0), (res.conversion_rate||0) + '% conversion', 0)}
			</div>`;

			// A/B test section
			if ( ab.variants ) {
				const va = ab.variants.a || {};
				const vb = ab.variants.b || {};
				const totalA = Math.max(1, va.total || 0);
				const totalB = Math.max(1, vb.total || 0);
				const rateA  = totalA > 0 ? Math.round( ( (va.sent||0) / totalA ) * 100 ) : 0;
				const rateB  = totalB > 0 ? Math.round( ( (vb.sent||0) / totalB ) * 100 ) : 0;
				const winner = ab.winner;

				html += `
				<h3 style="font-size:13px;font-weight:700;margin:20px 0 10px;">A/B Test Results</h3>
				<div class="mkt-ab-bars">
					<div class="mkt-ab-bar-row">
						<span class="mkt-ab-bar-label">Variant A ${winner==='a' ? '🏆' : ''}</span>
						<div class="mkt-ab-bar-track"><div class="mkt-ab-bar-fill mkt-ab-bar-fill-a" style="width:${rateA}%"></div></div>
						<span class="mkt-ab-bar-pct">${rateA}%</span>
					</div>
					<div class="mkt-ab-bar-row">
						<span class="mkt-ab-bar-label">Variant B ${winner==='b' ? '🏆' : ''}</span>
						<div class="mkt-ab-bar-track"><div class="mkt-ab-bar-fill mkt-ab-bar-fill-b" style="width:${rateB}%"></div></div>
						<span class="mkt-ab-bar-pct">${rateB}%</span>
					</div>
				</div>`;
			}

			$body.html( html );
		} );
	}

	function statCard( label, value, sub, pct, accent ) {
		return `
		<div class="mkt-analytics-card">
			<div class="mkt-analytics-label">${label}</div>
			<div class="mkt-analytics-value" style="${accent==='red' ? 'color:#ef4444' : accent==='purple' ? 'color:#8b5cf6' : ''}">${value}</div>
			${sub ? `<div style="font-size:11px;color:#9ca3af;margin-top:4px;">${sub}</div>` : ''}
			<div class="mkt-analytics-bar"><div class="mkt-analytics-bar-fill" style="width:${pct}%"></div></div>
		</div>`;
	}

	// =========================================================================
	// Logs modal
	// =========================================================================

	function openLogs( campaignId ) {
		showModal( 'mkt-logs-modal' );
		$( '#mkt-logs-body' ).html( '<div class="mkt-loading"><div class="mkt-spinner"></div> Loading…</div>' );

		ajaxPost( 'tsh_wa_mkt_logs', { campaign_id: campaignId, limit: 100 }, function ( res ) {
			const logs = res.logs || [];
			if ( ! logs.length ) {
				$( '#mkt-logs-body' ).html( '<div class="mkt-empty"><div class="mkt-empty-icon">📭</div><div class="mkt-empty-title">No logs</div></div>' );
				return;
			}

			const levelColor = { info: '#6b7280', warning: '#d97706', error: '#ef4444', debug: '#8b5cf6' };
			const html = logs.map( l => `
				<div style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:12px;">
					<span style="color:#9ca3af;white-space:nowrap;">${l.created_at || ''}</span>
					<span style="font-weight:700;color:${levelColor[l.level]||'#6b7280'};width:55px;flex-shrink:0;">${(l.level||'').toUpperCase()}</span>
					<span style="flex:1;">${esc(l.message)}</span>
				</div>` ).join( '' );

			$( '#mkt-logs-body' ).html( html );
		} );
	}

	// =========================================================================
	// Dashboard
	// =========================================================================

	function loadDashboard() {
		const days = +$( '#mkt-dashboard-days' ).val() || 30;

		ajaxPost( 'tsh_wa_mkt_dashboard', { days }, function ( res ) {
			$( '[data-stat]' ).each( function () {
				const key = $( this ).data( 'stat' );
				let val   = res[ key ] || 0;
				if ( key === 'revenue' )         val = ( cfg.currency || '$' ) + Number( val ).toFixed( 2 );
				else if ( key === 'conversion_rate' ) val = Number( val ).toFixed( 1 ) + '%';
				else                             val = fmt( val );
				$( this ).text( val );
			} );

			const today = res.today || {};
			$( '[data-today]' ).each( function () {
				$( this ).text( fmt( today[ $( this ).data( 'today' ) ] || 0 ) );
			} );
		} );
	}

	$( '#mkt-refresh-dashboard' ).on( 'click', loadDashboard );
	$( '#mkt-dashboard-days' ).on( 'change', loadDashboard );

	// =========================================================================
	// Template library
	// =========================================================================

	function loadLibrary() {
		$( '#mkt-library-container' ).html( '<div class="mkt-loading"><div class="mkt-spinner"></div> Loading…</div>' );

		ajaxPost( 'tsh_wa_mkt_templates', {}, function ( res ) {
			const groups = res.templates || {};
			if ( ! Object.keys( groups ).length ) {
				$( '#mkt-library-container' ).html( '<div class="mkt-empty"><div class="mkt-empty-icon">📭</div><div class="mkt-empty-title">No templates</div></div>' );
				return;
			}

			let html = '';
			Object.entries( groups ).forEach( ( [ category, items ] ) => {
				html += `<h3 style="font-size:13px;font-weight:700;margin:20px 0 12px;text-transform:capitalize;">${category}</h3>`;
				html += '<div class="mkt-library-grid">';
				html += items.map( t => `
					<div class="mkt-library-card">
						<div class="mkt-library-card-header">
							<div class="mkt-library-card-title">${esc(t.name)}</div>
							<span class="mkt-library-card-category">${esc(t.category)}</span>
						</div>
						<div class="mkt-library-card-desc">${esc(t.description)}</div>
						<div style="display:flex;gap:8px;margin-top:4px;">
							<button class="mkt-btn mkt-btn-primary" style="height:32px;font-size:12px;" data-use-tpl="${esc(t.id)}">
								Use Template
							</button>
							<span style="font-size:11px;color:#9ca3af;align-self:center;">${t.type}</span>
						</div>
					</div>` ).join( '' );
				html += '</div>';
			} );

			$( '#mkt-library-container' ).html( html );
		} );
	}

	$( document ).on( 'click', '[data-use-tpl]', function () {
		const tplId = $( this ).data( 'use-tpl' );
		$( this ).text( 'Creating…' ).prop( 'disabled', true );

		ajaxPost( 'tsh_wa_mkt_import_template', { template_id: tplId }, function ( res ) {
			toast( 'Campaign created from template!', 'success' );
			openBuilder( res.campaign_id );
		}, function ( err ) {
			toast( err, 'error' );
		} );
	} );

	// =========================================================================
	// Segments
	// =========================================================================

	function loadSegments() {
		$( '#mkt-segments-container' ).html( '<div class="mkt-loading"><div class="mkt-spinner"></div> Loading…</div>' );

		ajaxPost( 'tsh_wa_mkt_segments', {}, function ( res ) {
			const segs   = res.segments || [];
			const labels = res.type_labels || {};

			if ( ! segs.length ) {
				$( '#mkt-segments-container' ).html( '<div class="mkt-empty"><div class="mkt-empty-icon">🎯</div><div class="mkt-empty-title">No saved segments</div><div class="mkt-empty-desc">Save audience configurations to reuse across campaigns.</div></div>' );
				return;
			}

			const html = `
			<div class="mkt-table-wrap">
				<table class="mkt-table">
					<thead><tr>
						<th>Name</th><th>Audience Type</th><th>Rules</th><th>Created</th><th>Actions</th>
					</tr></thead>
					<tbody>
						${segs.map( s => `
							<tr>
								<td style="font-weight:600;">${esc(s.name)}</td>
								<td>${esc(labels[s.config?.type] || s.config?.type || '')}</td>
								<td>${(s.config?.rules||[]).length} rule(s)</td>
								<td>${fmtDate(s.created_at)}</td>
								<td>
									<button class="mkt-btn mkt-btn-ghost" data-del-segment="${s.id}" style="color:#ef4444;">Delete</button>
								</td>
							</tr>` ).join( '' )}
					</tbody>
				</table>
			</div>`;

			$( '#mkt-segments-container' ).html( html );
		} );
	}

	$( '#mkt-btn-new-segment' ).on( 'click', function () {
		const name = prompt( 'Segment name:' );
		if ( ! name ) return;
		toast( 'Opening audience builder to configure the new segment…' );
		openBuilder( 0 );
	} );

	$( document ).on( 'click', '[data-del-segment]', function () {
		const id = +$( this ).data( 'del-segment' );
		if ( ! confirm( 'Delete this segment?' ) ) return;
		ajaxPost( 'tsh_wa_mkt_delete_segment', { segment_id: id }, function () {
			toast( 'Segment deleted.' );
			loadSegments();
		} );
	} );

	// =========================================================================
	// Import / Export
	// =========================================================================

	// Drag + drop
	$( '#mkt-drop-zone' ).on( 'click', function () { $( '#mkt-import-file' ).click(); } );

	$( '#mkt-drop-zone' )
		.on( 'dragover', function ( e ) { e.preventDefault(); $( this ).addClass( 'drag-over' ); } )
		.on( 'dragleave drop', function () { $( this ).removeClass( 'drag-over' ); } )
		.on( 'drop', function ( e ) {
			e.preventDefault();
			const file = e.originalEvent.dataTransfer.files[0];
			if ( file ) readImportFile( file );
		} );

	$( '#mkt-import-file' ).on( 'change', function () {
		if ( this.files[0] ) readImportFile( this.files[0] );
	} );

	function readImportFile( file ) {
		const reader = new FileReader();
		reader.onload = e => $( '#mkt-import-json' ).val( e.target.result );
		reader.readAsText( file );
	}

	$( '#mkt-btn-import' ).on( 'click', function () {
		const json        = $( '#mkt-import-json' ).val().trim();
		const replace_all = $( '#mkt-import-replace' ).is( ':checked' ) ? 1 : 0;

		if ( ! json ) { toast( 'Please paste JSON or upload a file.', 'error' ); return; }

		$( this ).text( 'Importing…' ).prop( 'disabled', true );

		ajaxPost( 'tsh_wa_mkt_import', { json, replace_all }, function ( res ) {
			$( '#mkt-btn-import' ).text( 'Import Campaigns' ).prop( 'disabled', false );
			$( '#mkt-import-result' ).html( `<div class="mkt-toast success" style="position:static;max-width:100%;animation:none;">✅ Imported ${res.imported} campaign(s). Skipped: ${res.skipped}.</div>` );
			if ( res.imported ) loadCampaigns();
		}, function ( err ) {
			$( '#mkt-btn-import' ).text( 'Import Campaigns' ).prop( 'disabled', false );
			$( '#mkt-import-result' ).html( `<div class="mkt-toast error" style="position:static;max-width:100%;animation:none;">❌ ${err}</div>` );
		} );
	} );

	$( '#mkt-btn-export' ).on( 'click', function () {
		const idsRaw       = $( '#mkt-export-ids' ).val().trim();
		const campaign_ids = idsRaw ? idsRaw.split( ',' ).map( n => +n.trim() ).filter( Boolean ) : [];
		const include_stats= $( '#mkt-export-stats' ).is( ':checked' ) ? 1 : 0;

		$( this ).text( 'Preparing…' ).prop( 'disabled', true );

		ajaxPost( 'tsh_wa_mkt_export', { campaign_ids: JSON.stringify( campaign_ids ), include_stats }, function ( res ) {
			$( '#mkt-btn-export' ).text( 'Download JSON' ).prop( 'disabled', false );
			const blob = new Blob( [ res.json ], { type: 'application/json' } );
			const url  = URL.createObjectURL( blob );
			const a    = document.createElement( 'a' );
			a.href     = url;
			a.download = `tsh-wa-campaigns-${Date.now()}.json`;
			document.body.appendChild( a );
			a.click();
			document.body.removeChild( a );
			URL.revokeObjectURL( url );
		} );
	} );

	// =========================================================================
	// Modal helpers
	// =========================================================================

	function showModal( id ) {
		$( '#' + id ).addClass( 'visible' );
		$( 'body' ).css( 'overflow', 'hidden' );
	}

	function closeModal( id ) {
		$( '#' + id ).removeClass( 'visible' );
		$( 'body' ).css( 'overflow', '' );
	}

	// Close via overlay click or close button
	$( document ).on( 'click', '.mkt-modal-overlay', function ( e ) {
		if ( $( e.target ).hasClass( 'mkt-modal-overlay' ) ) closeModal( $( this ).attr( 'id' ) );
	} );

	$( document ).on( 'click', '[data-modal]', function () {
		closeModal( $( this ).data( 'modal' ) );
	} );

	// =========================================================================
	// Init
	// =========================================================================

	function init() {
		loadCampaigns();
		updateAudienceTypeGrid();
		renderRules();

		// Populate saved segments in audience picker
		const segs = data.savedSegments || [];
		if ( segs.length ) {
			const opts = segs.map( s => `<option value="${s.id}">${esc(s.name)}</option>` ).join( '' );
			$( '#mkt-saved-segment-select' ).append( opts );
		}
	}

	$( init );

} )( jQuery );
