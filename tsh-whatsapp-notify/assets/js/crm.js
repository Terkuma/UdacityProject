/**
 * TSH WhatsApp Notify — CRM JS (Phase 9)
 *
 * jQuery module managing the Customer CRM admin page.
 * Depends on tshWaCRM (localized via Menu.php) and tshWaCRMSeed (localized via Pages/CRM.php).
 */
/* global tshWaCRM, tshWaCRMSeed, jQuery */
(function ($) {
	'use strict';

	/* ===================================================================
	   State
	   =================================================================== */
	const CRM = {
		nonce:       tshWaCRM.nonce,
		ajaxUrl:     tshWaCRM.ajaxUrl,
		currency:    tshWaCRM.currency || '€',
		i18n:        tshWaCRM.i18n || {},
		lifecycle:   tshWaCRM.lifecycle || {},
		taskPriority:tshWaCRM.taskPriority || {},
		taskStatus:  tshWaCRM.taskStatus  || {},
		actIcons:    tshWaCRM.activityIcons || {},
		users:       tshWaCRM.users || [],
		settings:    tshWaCRM.settings || {},

		// Seed injected by server
		seed: (typeof tshWaCRMSeed !== 'undefined') ? tshWaCRMSeed : {},

		// Current pagination state
		customerPage: 1,
		customerPerPage: 25,
		customerFilters: {},
		customerTotal: 0,

		taskPage: 1,
		taskPerPage: 25,

		// Currently open profile
		profileId: null,
		timelineOffset: 0,
		timelineHasMore: true,

		// Import state
		importContent: null,
		importFormat: 'csv',

		// Segment rule fields
		ruleFields: [],

		// Dirty tracker for chart canvases
		charts: {},
	};

	/* ===================================================================
	   Helpers
	   =================================================================== */
	function ajax(action, data, cb) {
		$.post(CRM.ajaxUrl, $.extend({ action: action, nonce: CRM.nonce }, data), function (res) {
			if (typeof cb === 'function') cb(res);
		}, 'json').fail(function () {
			if (typeof cb === 'function') cb({ success: false, data: { message: CRM.i18n.error } });
		});
	}

	function fmtMoney(v) {
		return CRM.currency + parseFloat(v || 0).toFixed(2);
	}

	function fmtDate(s) {
		if (!s) return '—';
		try {
			return new Date(s).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
		} catch (e) { return s; }
	}

	function fmtDatetime(s) {
		if (!s) return '—';
		try {
			return new Date(s).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
		} catch (e) { return s; }
	}

	function esc(s) {
		return $('<div>').text(String(s || '')).html();
	}

	function lifecycleBadge(lc) {
		return '<span class="tsh-wa-crm-lifecycle tsh-wa-crm-lifecycle--' + esc(lc) + '">' + esc(CRM.lifecycle[lc] || lc) + '</span>';
	}

	function healthBadge(score) {
		score = parseFloat(score) || 0;
		let cls = 'critical';
		if (score >= 80) cls = 'excellent';
		else if (score >= 60) cls = 'good';
		else if (score >= 40) cls = 'average';
		else if (score >= 20) cls = 'poor';
		return '<span class="tsh-wa-crm-health tsh-wa-crm-health--' + cls + '">' + score.toFixed(0) + '</span>';
	}

	function avatarHtml(c) {
		const initials = ((c.first_name || '')[0] || '') + ((c.last_name || '')[0] || '');
		const bg = colorFromId(c.id);
		if (c.avatar_url) {
			return '<span class="tsh-wa-crm-avatar" style="background:' + bg + '"><img src="' + esc(c.avatar_url) + '" alt=""></span>';
		}
		return '<span class="tsh-wa-crm-avatar" style="background:' + bg + '">' + esc(initials || '?') + '</span>';
	}

	function colorFromId(id) {
		const colors = ['#2563eb','#16a34a','#dc2626','#d97706','#7c3aed','#0d9488','#db2777','#ea580c'];
		return colors[(id || 0) % colors.length];
	}

	function taskPriorityBadge(p) {
		return '<span class="tsh-wa-crm-task-priority priority-' + esc(p) + '">' + esc(CRM.taskPriority[p] || p) + '</span>';
	}

	function notify(msg, type) {
		type = type || 'success';
		const $n = $('<div class="notice notice-' + type + ' is-dismissible" style="margin:10px 0;"><p>' + esc(msg) + '</p></div>');
		$('.tsh-wa-crm-wrap .wp-heading-inline').after($n);
		setTimeout(function () { $n.fadeOut(400, function () { $n.remove(); }); }, 3000);
	}

	/* ===================================================================
	   Tab Navigation
	   =================================================================== */
	function initTabs() {
		$(document).on('click', '.tsh-wa-crm-tab', function (e) {
			e.preventDefault();
			const tab = $(this).data('tab');
			$('.tsh-wa-crm-tab').removeClass('active');
			$(this).addClass('active');
			$('.tsh-wa-crm-panel').addClass('tsh-wa-crm-panel--hidden');
			$('#crm-' + tab).removeClass('tsh-wa-crm-panel--hidden');
			onTabActivated(tab);
		});
	}

	function onTabActivated(tab) {
		switch (tab) {
			case 'dashboard':   initDashboard(); break;
			case 'customers':   loadCustomers(); break;
			case 'segments':    loadSegments(); break;
			case 'tasks':       loadAllTasks(); break;
			case 'analytics':   initAnalytics(); break;
			case 'duplicates':  /* on-demand */ break;
			case 'settings':    populateSettings(); break;
		}
	}

	/* ===================================================================
	   DASHBOARD
	   =================================================================== */
	function initDashboard() {
		const seed = CRM.seed.dashboard || {};
		renderStatCards(seed);
		renderOverdueTasks(CRM.seed.overdue_tasks || []);
		drawGrowthChart('crm-chart-growth', seed.growth || []);
		drawPieChart('crm-chart-lifecycle', seed.by_lifecycle || {});
		drawPieChart('crm-chart-health', seed.by_health || {});

		// Top customers by LTV
		ajax('tsh_wa_crm_list', { orderby: 'lifetime_value', order: 'DESC', per_page: 5, page: 1 }, function (res) {
			if (!res.success) return;
			const rows = res.data.customers || [];
			let html = '';
			rows.forEach(function (c) {
				html += '<div class="tsh-wa-crm-task-item" style="cursor:pointer" data-id="' + c.id + '">' +
					avatarHtml(c) +
					'<div class="tsh-wa-crm-task-body">' +
					'<div class="tsh-wa-crm-task-title">' + esc(c.full_name || c.first_name + ' ' + c.last_name) + (c.is_vip ? ' 👑' : '') + '</div>' +
					'<div class="tsh-wa-crm-task-meta">' + fmtMoney(c.lifetime_value) + ' · ' + c.total_orders + ' orders</div>' +
					'</div>' +
					'</div>';
			});
			$('#crm-top-list').html(html || '<p class="tsh-wa-crm-empty">No customers yet.</p>');
		});

		$('#crm-top-list').on('click', '[data-id]', function () {
			openProfile($(this).data('id'));
		});
	}

	function renderStatCards(data) {
		const stats = [
			{ icon: '👥', label: 'Total Customers', value: data.total_customers || 0 },
			{ icon: '🆕', label: 'New This Period',  value: data.new_customers   || 0 },
			{ icon: '👑', label: 'VIP Customers',    value: data.vip_count        || 0 },
			{ icon: '📦', label: 'Total Orders',     value: data.total_orders     || 0 },
			{ icon: '💰', label: 'Total Revenue',    value: fmtMoney(data.total_revenue) },
			{ icon: '📈', label: 'Avg LTV',          value: fmtMoney(data.avg_ltv) },
			{ icon: '❤️', label: 'Avg Health',       value: parseFloat(data.avg_health || 0).toFixed(0) },
			{ icon: '🚫', label: 'Blocked',          value: data.blocked_count    || 0 },
		];

		let html = '';
		stats.forEach(function (s) {
			html += '<div class="tsh-wa-crm-stat-card">' +
				'<div class="tsh-wa-crm-stat-card__icon">' + s.icon + '</div>' +
				'<div class="tsh-wa-crm-stat-card__label">' + esc(s.label) + '</div>' +
				'<div class="tsh-wa-crm-stat-card__value">' + esc(s.value) + '</div>' +
				'</div>';
		});
		$('#crm-stat-cards').html(html);
	}

	function renderOverdueTasks(tasks) {
		if (!tasks || !tasks.length) {
			$('#crm-overdue-list').html('<p class="tsh-wa-crm-empty">No overdue tasks.</p>');
			return;
		}
		let html = '';
		tasks.forEach(function (t) {
			html += '<div class="tsh-wa-crm-task-item">' +
				'<div class="tsh-wa-crm-task-body">' +
				'<div class="tsh-wa-crm-task-title">' + esc(t.title) + '</div>' +
				'<div class="tsh-wa-crm-task-meta tsh-wa-crm-task-due--overdue">Due: ' + fmtDatetime(t.due_at) + '</div>' +
				'</div>' +
				taskPriorityBadge(t.priority) +
				'</div>';
		});
		$('#crm-overdue-list').html(html);
	}

	/* ===================================================================
	   Charts (native SVG / canvas — no external library required)
	   =================================================================== */
	function drawGrowthChart(canvasId, dataArr) {
		const canvas = document.getElementById(canvasId);
		if (!canvas) return;
		const ctx = canvas.getContext('2d');
		const W = canvas.offsetWidth || 400;
		canvas.width  = W;
		canvas.height = parseInt(canvas.getAttribute('height') || '200');
		const H = canvas.height;

		if (!dataArr || !dataArr.length) {
			ctx.fillStyle = '#94a3b8';
			ctx.textAlign = 'center';
			ctx.fillText('No data', W / 2, H / 2);
			return;
		}

		const values  = dataArr.map(function (d) { return parseInt(d.count || d.value || 0); });
		const labels  = dataArr.map(function (d) { return d.date || d.label || ''; });
		const max     = Math.max.apply(null, values) || 1;
		const pad     = { top: 20, right: 20, bottom: 36, left: 40 };
		const chartW  = W - pad.left - pad.right;
		const chartH  = H - pad.top  - pad.bottom;
		const step    = chartW / Math.max(values.length - 1, 1);

		ctx.clearRect(0, 0, W, H);

		// Grid lines
		ctx.strokeStyle = '#e2e8f0';
		ctx.lineWidth   = 1;
		for (let i = 0; i <= 4; i++) {
			const y = pad.top + chartH - (i / 4) * chartH;
			ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + chartW, y); ctx.stroke();
		}

		// Area fill
		ctx.beginPath();
		values.forEach(function (v, i) {
			const x = pad.left + i * step;
			const y = pad.top  + chartH - (v / max) * chartH;
			i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
		});
		ctx.lineTo(pad.left + (values.length - 1) * step, pad.top + chartH);
		ctx.lineTo(pad.left, pad.top + chartH);
		ctx.closePath();
		ctx.fillStyle = 'rgba(37,99,235,.12)';
		ctx.fill();

		// Line
		ctx.beginPath();
		values.forEach(function (v, i) {
			const x = pad.left + i * step;
			const y = pad.top  + chartH - (v / max) * chartH;
			i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
		});
		ctx.strokeStyle = '#2563eb';
		ctx.lineWidth   = 2;
		ctx.stroke();

		// Labels (every Nth)
		ctx.fillStyle   = '#94a3b8';
		ctx.font        = '11px sans-serif';
		ctx.textAlign   = 'center';
		const labelStep = Math.ceil(labels.length / 7);
		labels.forEach(function (l, i) {
			if (i % labelStep === 0) {
				const x = pad.left + i * step;
				ctx.fillText(l.slice(-5), x, H - 8);
			}
		});
	}

	function drawPieChart(canvasId, dataObj) {
		const canvas = document.getElementById(canvasId);
		if (!canvas) return;
		const W = canvas.offsetWidth || 200;
		canvas.width  = W;
		canvas.height = parseInt(canvas.getAttribute('height') || '200');
		const H = canvas.height;
		const ctx = canvas.getContext('2d');
		ctx.clearRect(0, 0, W, H);

		const keys   = Object.keys(dataObj);
		const values = keys.map(function (k) { return parseInt(dataObj[k]) || 0; });
		const total  = values.reduce(function (a, b) { return a + b; }, 0);

		if (!total) {
			ctx.fillStyle = '#94a3b8';
			ctx.textAlign = 'center';
			ctx.fillText('No data', W / 2, H / 2);
			return;
		}

		const colors = ['#2563eb','#16a34a','#d97706','#dc2626','#7c3aed','#0d9488','#db2777','#ea580c','#64748b'];
		const cx = W / 2, cy = (H - 40) / 2, r = Math.min(cx, cy) - 10;
		let angle = -Math.PI / 2;

		values.forEach(function (v, i) {
			const slice = (v / total) * Math.PI * 2;
			ctx.beginPath();
			ctx.moveTo(cx, cy);
			ctx.arc(cx, cy, r, angle, angle + slice);
			ctx.closePath();
			ctx.fillStyle = colors[i % colors.length];
			ctx.fill();
			angle += slice;
		});

		// Legend
		ctx.font      = '11px sans-serif';
		ctx.textAlign = 'left';
		const lx = 10, ly = H - 36;
		keys.forEach(function (k, i) {
			const x = lx + i * Math.floor(W / keys.length);
			ctx.fillStyle = colors[i % colors.length];
			ctx.fillRect(x, ly, 10, 10);
			ctx.fillStyle = '#475569';
			ctx.fillText(k.slice(0, 7), x + 13, ly + 9);
		});
	}

	/* ===================================================================
	   CUSTOMER LIST
	   =================================================================== */
	function loadCustomers() {
		const filters = {
			search:    $('#crm-customer-search').val()      || '',
			lifecycle: $('#crm-filter-lifecycle').val()     || '',
			is_vip:    $('#crm-filter-vip').val()           || '',
			is_blocked:$('#crm-filter-blocked').val()       || '',
			orderby:   $('#crm-sort-by').val()              || 'created_at',
			order:     $('#crm-sort-order').val()           || 'DESC',
			per_page:  CRM.customerPerPage,
			page:      CRM.customerPage,
		};

		$('#crm-customer-tbody').html('<tr><td colspan="8" class="tsh-wa-crm-loading">Loading…</td></tr>');
		ajax('tsh_wa_crm_list', filters, function (res) {
			if (!res.success) { $('#crm-customer-tbody').html('<tr><td colspan="8">' + esc(CRM.i18n.error) + '</td></tr>'); return; }
			const d = res.data;
			CRM.customerTotal = d.total || 0;
			renderCustomerRows(d.customers || []);
			renderCustomerPagination(d.total || 0, d.pages || 1);
		});
	}

	function renderCustomerRows(customers) {
		if (!customers.length) {
			$('#crm-customer-tbody').html('<tr><td colspan="8" class="tsh-wa-crm-empty">' + esc(CRM.i18n.no_customers) + '</td></tr>');
			return;
		}

		let html = '';
		customers.forEach(function (c) {
			const name = c.full_name || (c.first_name + ' ' + c.last_name).trim() || '—';
			html += '<tr data-id="' + c.id + '">' +
				'<td>' + avatarHtml(c) + '</td>' +
				'<td>' +
					'<div class="tsh-wa-crm-customer-name" data-id="' + c.id + '">' + esc(name) +
						(c.is_vip ? '<span class="tsh-wa-crm-vip-crown">👑</span>' : '') +
						(c.is_blocked ? '<span class="tsh-wa-crm-blocked-badge">Blocked</span>' : '') +
					'</div>' +
					'<div class="tsh-wa-crm-customer-email">' + esc(c.email || '') + '</div>' +
				'</td>' +
				'<td>' + esc(c.phone || '—') + '</td>' +
				'<td>' + lifecycleBadge(c.lifecycle) + '</td>' +
				'<td class="col-orders">' + (c.total_orders || 0) + '</td>' +
				'<td class="col-ltv">' + fmtMoney(c.lifetime_value) + '</td>' +
				'<td class="col-health">' + healthBadge(c.health_score) + '</td>' +
				'<td class="col-actions">' +
					'<div class="tsh-wa-crm-table-actions">' +
						'<button class="button crm-btn-view-profile" data-id="' + c.id + '">View</button>' +
						'<button class="button button-link-delete crm-btn-delete-customer" data-id="' + c.id + '" data-name="' + esc(name) + '">Del</button>' +
					'</div>' +
				'</td>' +
			'</tr>';
		});
		$('#crm-customer-tbody').html(html);
	}

	function renderCustomerPagination(total, pages) {
		const page = CRM.customerPage;
		let html = '<span class="tsh-wa-crm-page-info">' + total + ' customers</span>';

		if (page > 1) html += '<button class="crm-page-prev" data-page="' + (page - 1) + '">‹ Prev</button>';

		for (let i = 1; i <= Math.min(pages, 7); i++) {
			html += '<button class="crm-page-btn' + (i === page ? ' current' : '') + '" data-page="' + i + '">' + i + '</button>';
		}

		if (page < pages) html += '<button class="crm-page-next" data-page="' + (page + 1) + '">Next ›</button>';

		$('#crm-pagination').html(html);
	}

	// Search & filter debounce
	let searchTimer;
	function bindCustomerControls() {
		$('#crm-customer-search').on('input', function () {
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				CRM.customerPage = 1;
				loadCustomers();
			}, 350);
		});

		$('#crm-filter-lifecycle, #crm-filter-vip, #crm-filter-blocked, #crm-sort-by, #crm-sort-order').on('change', function () {
			CRM.customerPage = 1;
			loadCustomers();
		});

		$(document).on('click', '.crm-page-btn, .crm-page-prev, .crm-page-next', function () {
			CRM.customerPage = parseInt($(this).data('page'));
			loadCustomers();
		});

		// Row click → profile
		$(document).on('click', '.crm-btn-view-profile, .tsh-wa-crm-customer-name, .tsh-wa-crm-avatar', function () {
			openProfile($(this).data('id'));
		});

		// Delete
		$(document).on('click', '.crm-btn-delete-customer', function () {
			const id   = $(this).data('id');
			const name = $(this).data('name');
			if (!confirm(CRM.i18n.confirm_delete + '\n\n' + name)) return;
			ajax('tsh_wa_crm_delete', { customer_id: id }, function (res) {
				if (res.success) { notify('Customer deleted.'); loadCustomers(); }
				else notify(res.data.message || CRM.i18n.error, 'error');
			});
		});
	}

	/* ===================================================================
	   ADD CUSTOMER FORM
	   =================================================================== */
	function bindCustomerForm() {
		$('#tsh-wa-crm-add-customer').on('click', function (e) {
			e.preventDefault();
			$('#crm-customer-form-title').text('Add Customer');
			$('#crm-form-customer-id').val('');
			$('#crm-customer-form-modal input, #crm-customer-form-modal textarea, #crm-customer-form-modal select').val('').prop('checked', false);
			$('#crm-form-lifecycle').val('lead');
			openModal('crm-customer-form-modal');
		});

		$('#crm-customer-form-close, #crm-btn-cancel-customer').on('click', function () {
			closeModal('crm-customer-form-modal');
		});

		$('#crm-btn-save-customer').on('click', function () {
			const id = $('#crm-form-customer-id').val();
			const data = {
				customer_id:     id,
				first_name:      $('#crm-form-first-name').val(),
				last_name:       $('#crm-form-last-name').val(),
				phone:           $('#crm-form-phone').val(),
				whatsapp_phone:  $('#crm-form-wa-phone').val(),
				email:           $('#crm-form-email').val(),
				country:         $('#crm-form-country').val(),
				city:            $('#crm-form-city').val(),
				lifecycle:       $('#crm-form-lifecycle').val(),
				is_vip:          $('#crm-form-is-vip').is(':checked') ? 1 : 0,
				marketing_consent: $('#crm-form-consent').is(':checked') ? 1 : 0,
			};
			const action = id ? 'tsh_wa_crm_update' : 'tsh_wa_crm_create';
			ajax(action, data, function (res) {
				if (res.success) {
					closeModal('crm-customer-form-modal');
					notify(CRM.i18n.customer_created);
					loadCustomers();
				} else {
					notify(res.data.message || CRM.i18n.error, 'error');
				}
			});
		});
	}

	/* ===================================================================
	   CUSTOMER PROFILE MODAL
	   =================================================================== */
	function openProfile(id) {
		CRM.profileId = id;
		CRM.timelineOffset = 0;
		CRM.timelineHasMore = true;

		// Reset UI
		$('#crm-profile-name').text('Loading…');
		$('#crm-profile-badges, #crm-profile-meta, #crm-profile-stats-bar').html('');
		$('#crm-profile-details, #crm-profile-scores-breakdown').html('');
		$('#crm-timeline-feed').html('<div class="tsh-wa-crm-loading">Loading timeline…</div>');
		$('#crm-notes-feed, #crm-tasks-feed, #crm-orders-list, #crm-messages-list').html('');
		$('#crm-customer-tags').html('');
		setProfileTab('overview');
		openModal('crm-profile-modal');

		ajax('tsh_wa_crm_profile', { customer_id: id }, function (res) {
			if (!res.success) { notify(res.data.message || CRM.i18n.error, 'error'); return; }
			renderProfile(res.data);
		});
	}

	function renderProfile(d) {
		const c = d.customer || {};
		const name = c.full_name || ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';

		// Avatar
		const bg = colorFromId(c.id);
		const initials = ((c.first_name || '')[0] || '') + ((c.last_name || '')[0] || '');
		$('#crm-profile-avatar').css('background', bg).text(initials || '?');
		if (c.avatar_url) $('#crm-profile-avatar').html('<img src="' + esc(c.avatar_url) + '" alt="">');

		// Name & badges
		$('#crm-profile-name').text(name);
		let badges = lifecycleBadge(c.lifecycle);
		if (c.is_vip)     badges += ' <span class="tsh-wa-crm-lifecycle" style="background:#fef3c7;color:#92400e;">👑 VIP</span>';
		if (c.is_blocked) badges += ' <span class="tsh-wa-crm-blocked-badge">Blocked</span>';
		$('#crm-profile-badges').html(badges);

		// Meta line
		const meta = [c.phone, c.email, c.city, c.country].filter(Boolean).join(' · ');
		$('#crm-profile-meta').text(meta);

		// Score ring
		const health = parseFloat(c.health_score) || 0;
		const circ = 2 * Math.PI * 34;
		const dash = (health / 100) * circ;
		$('#crm-score-val').text(health.toFixed(0));
		$('#crm-score-arc').css({ 'stroke-dasharray': dash + ' ' + circ });

		// Stats bar
		const stats = [
			{ label: 'Total Orders', value: c.total_orders || 0 },
			{ label: 'Completed',    value: c.completed_orders || 0 },
			{ label: 'LTV',          value: fmtMoney(c.lifetime_value) },
			{ label: 'Avg Order',    value: fmtMoney(c.avg_order_value) },
			{ label: 'Notes',        value: c.notes_count || 0 },
			{ label: 'Tasks',        value: c.tasks_count || 0 },
			{ label: 'First Order',  value: c.first_order_at ? fmtDate(c.first_order_at) : '—' },
			{ label: 'Last Order',   value: c.last_order_at  ? fmtDate(c.last_order_at)  : '—' },
		];
		let statsHtml = '';
		stats.forEach(function (s) {
			statsHtml += '<div class="tsh-wa-crm-profile-stat">' +
				'<div class="tsh-wa-crm-profile-stat__label">' + esc(s.label) + '</div>' +
				'<div class="tsh-wa-crm-profile-stat__value">' + esc(s.value) + '</div>' +
				'</div>';
		});
		$('#crm-profile-stats-bar').html(statsHtml);

		// Details panel
		const detailFields = [
			{ k: 'Phone',              v: c.phone },
			{ k: 'WhatsApp',          v: c.whatsapp_phone },
			{ k: 'Email',             v: c.email },
			{ k: 'Country',           v: c.country },
			{ k: 'State',             v: c.state },
			{ k: 'City',              v: c.city },
			{ k: 'Language',          v: c.language },
			{ k: 'Timezone',          v: c.timezone },
			{ k: 'Birthday',          v: c.birthday  ? fmtDate(c.birthday)    : null },
			{ k: 'Anniversary',       v: c.anniversary ? fmtDate(c.anniversary) : null },
			{ k: 'Source',            v: c.source },
			{ k: 'Created',           v: fmtDatetime(c.created_at) },
			{ k: 'Marketing Consent', v: c.marketing_consent ? '✅ Yes' : '❌ No' },
		];

		let detailHtml = '';
		detailFields.forEach(function (f) {
			if (!f.v) return;
			detailHtml += '<div class="tsh-wa-crm-detail-row">' +
				'<div class="tsh-wa-crm-detail-key">' + esc(f.k) + '</div>' +
				'<div class="tsh-wa-crm-detail-val">' + esc(f.v) + '</div>' +
				'</div>';
		});
		$('#crm-profile-details').html(detailHtml);

		// Scores breakdown
		const scores = d.scores || {};
		const scoreItems = [
			{ k: 'Engagement', v: scores.engagement_score },
			{ k: 'Purchase',   v: scores.purchase_score },
			{ k: 'Marketing',  v: scores.marketing_score },
			{ k: 'Support',    v: scores.support_score },
		];
		let scoreHtml = '<h4 style="margin:0 0 12px;font-size:13px;">Score Breakdown</h4>';
		scoreItems.forEach(function (s) {
			const pct = parseFloat(s.v) || 0;
			scoreHtml += '<div class="tsh-wa-crm-score-bar-row">' +
				'<div class="tsh-wa-crm-score-bar-label"><span>' + esc(s.k) + '</span><span>' + pct.toFixed(0) + '</span></div>' +
				'<div class="tsh-wa-crm-score-bar"><div class="tsh-wa-crm-score-bar__fill" style="width:' + pct + '%"></div></div>' +
				'</div>';
		});
		if (scores.rfm_score) {
			scoreHtml += '<div class="tsh-wa-crm-detail-row" style="margin-top:12px">' +
				'<div class="tsh-wa-crm-detail-key">RFM Score</div>' +
				'<div class="tsh-wa-crm-detail-val"><strong>' + esc(scores.rfm_score) + '</strong></div>' +
				'</div>';
		}
		$('#crm-profile-scores-breakdown').html(scoreHtml);

		// Set lifecycle select
		$('#crm-lifecycle-select').val(c.lifecycle);

		// Action button toggles
		$('#crm-profile-btn-toggle-vip').text(c.is_vip ? 'Revoke VIP' : 'Grant VIP');
		$('#crm-profile-btn-toggle-block').text(c.is_blocked ? 'Unblock' : 'Block');

		// Render orders from profile data
		if (d.orders && d.orders.length) {
			let ordHtml = '';
			d.orders.forEach(function (o) {
				ordHtml += '<div class="tsh-wa-crm-task-item">' +
					'<div class="tsh-wa-crm-task-body">' +
					'<div class="tsh-wa-crm-task-title">#' + esc(o.id) + ' — ' + esc(o.status) + '</div>' +
					'<div class="tsh-wa-crm-task-meta">' + fmtMoney(o.total) + ' · ' + fmtDate(o.date_created) + '</div>' +
					'</div></div>';
			});
			$('#crm-orders-list').html(ordHtml);
		} else {
			$('#crm-orders-list').html('<p class="tsh-wa-crm-empty">No orders found.</p>');
		}

		// Render messages from profile data
		if (d.conversations && d.conversations.length) {
			let msgHtml = '';
			d.conversations.forEach(function (m) {
				msgHtml += '<div class="tsh-wa-crm-task-item">' +
					'<div class="tsh-wa-crm-task-body">' +
					'<div class="tsh-wa-crm-task-title">' + esc(m.body || m.message || '—') + '</div>' +
					'<div class="tsh-wa-crm-task-meta">' + fmtDatetime(m.created_at) + '</div>' +
					'</div></div>';
			});
			$('#crm-messages-list').html(msgHtml);
		} else {
			$('#crm-messages-list').html('<p class="tsh-wa-crm-empty">No messages found.</p>');
		}

		// Tags
		renderCustomerTags(d.tags || [], CRM.seed.tags || []);

		// Notes & Tasks from profile
		renderNotes(d.notes || []);
		renderTasks(d.tasks || []);
	}

	function setProfileTab(name) {
		$('.tsh-wa-crm-profile-tab').removeClass('active');
		$('.tsh-wa-crm-profile-tab[data-ptab="' + name + '"]').addClass('active');
		$('.tsh-wa-crm-ptab').addClass('tsh-wa-crm-ptab--hidden');
		$('#ptab-' + name).removeClass('tsh-wa-crm-ptab--hidden');
	}

	function bindProfileTabs() {
		$(document).on('click', '.tsh-wa-crm-profile-tab', function () {
			const tab = $(this).data('ptab');
			setProfileTab(tab);
			if (tab === 'timeline') loadTimeline(true);
		});
	}

	function bindProfileActions() {
		$('#crm-profile-close').on('click', function () { closeModal('crm-profile-modal'); });
		$('.tsh-wa-crm-modal-overlay').on('click', function () {
			$(this).closest('.tsh-wa-crm-modal').hide();
		});

		// Edit customer
		$('#crm-profile-btn-edit').on('click', function () {
			ajax('tsh_wa_crm_get', { customer_id: CRM.profileId }, function (res) {
				if (!res.success) return;
				const c = res.data.customer;
				$('#crm-customer-form-title').text('Edit Customer');
				$('#crm-form-customer-id').val(c.id);
				$('#crm-form-first-name').val(c.first_name);
				$('#crm-form-last-name').val(c.last_name);
				$('#crm-form-phone').val(c.phone);
				$('#crm-form-wa-phone').val(c.whatsapp_phone);
				$('#crm-form-email').val(c.email);
				$('#crm-form-country').val(c.country);
				$('#crm-form-city').val(c.city);
				$('#crm-form-lifecycle').val(c.lifecycle);
				$('#crm-form-is-vip').prop('checked', !!parseInt(c.is_vip));
				$('#crm-form-consent').prop('checked', !!parseInt(c.marketing_consent));
				openModal('crm-customer-form-modal');
			});
		});

		// Toggle VIP
		$('#crm-profile-btn-toggle-vip').on('click', function () {
			const currentVip = $(this).text() === 'Revoke VIP' ? 1 : 0;
			ajax('tsh_wa_crm_set_vip', { customer_id: CRM.profileId, is_vip: currentVip ? 0 : 1 }, function (res) {
				if (res.success) { openProfile(CRM.profileId); notify(res.data.message); }
			});
		});

		// Block / Unblock
		$('#crm-profile-btn-toggle-block').on('click', function () {
			const isBlock = $(this).text() === 'Block';
			if (isBlock && !confirm(CRM.i18n.confirm_block)) return;
			ajax('tsh_wa_crm_block', { customer_id: CRM.profileId, is_blocked: isBlock ? 1 : 0 }, function (res) {
				if (res.success) { openProfile(CRM.profileId); notify(res.data.message); }
			});
		});

		// Rescore
		$('#crm-profile-btn-rescore').on('click', function () {
			ajax('tsh_wa_crm_recalculate_scores', { customer_id: CRM.profileId }, function (res) {
				if (res.success) { openProfile(CRM.profileId); notify('Scores recalculated.'); }
			});
		});

		// Delete from profile
		$('#crm-profile-btn-delete').on('click', function () {
			if (!confirm(CRM.i18n.confirm_delete)) return;
			ajax('tsh_wa_crm_delete', { customer_id: CRM.profileId }, function (res) {
				if (res.success) { closeModal('crm-profile-modal'); notify('Customer deleted.'); loadCustomers(); }
			});
		});

		// Update lifecycle
		$('#crm-btn-update-lifecycle').on('click', function () {
			ajax('tsh_wa_crm_update_lifecycle', { customer_id: CRM.profileId, lifecycle: $('#crm-lifecycle-select').val() }, function (res) {
				if (res.success) notify(res.data.message);
				else notify(res.data.message, 'error');
			});
		});
	}

	/* ===================================================================
	   TIMELINE
	   =================================================================== */
	function loadTimeline(reset) {
		if (reset) {
			CRM.timelineOffset = 0;
			CRM.timelineHasMore = true;
			$('#crm-timeline-feed').html('<div class="tsh-wa-crm-loading">Loading timeline…</div>');
		}

		if (!CRM.timelineHasMore) return;

		ajax('tsh_wa_crm_timeline', {
			customer_id: CRM.profileId,
			limit:  25,
			offset: CRM.timelineOffset,
			filter_type: $('#crm-timeline-filter').val() || '',
		}, function (res) {
			if (!res.success) return;
			const events = res.data.events || [];
			CRM.timelineHasMore = res.data.has_more;
			CRM.timelineOffset += events.length;

			if (CRM.timelineOffset <= events.length) {
				$('#crm-timeline-feed').html('');
			}

			events.forEach(function (ev) {
				$('#crm-timeline-feed').append(renderTimelineItem(ev));
			});

			$('#crm-timeline-load-more').toggle(CRM.timelineHasMore);
		});
	}

	function renderTimelineItem(ev) {
		const icon = CRM.actIcons[ev.type] || '📌';
		return $('<div class="tsh-wa-crm-timeline-item">' +
			'<div class="tsh-wa-crm-timeline-dot tsh-wa-crm-timeline-dot--' + esc(ev.type) + '">' + icon + '</div>' +
			'<div class="tsh-wa-crm-timeline-content">' +
				'<div class="tsh-wa-crm-timeline-subject">' + esc(ev.subject) + '</div>' +
				(ev.description ? '<div class="tsh-wa-crm-timeline-desc">' + esc(ev.description) + '</div>' : '') +
				'<div class="tsh-wa-crm-timeline-time">' + fmtDatetime(ev.created_at) + '</div>' +
			'</div>' +
		'</div>');
	}

	function bindTimeline() {
		$('#crm-timeline-load-more').on('click', function () { loadTimeline(false); });
		$('#crm-timeline-filter').on('change', function () { loadTimeline(true); });
	}

	/* ===================================================================
	   NOTES
	   =================================================================== */
	function renderNotes(notes) {
		if (!notes.length) { $('#crm-notes-feed').html('<p class="tsh-wa-crm-empty">No notes yet.</p>'); return; }
		let html = '';
		notes.forEach(function (n) {
			html += '<div class="tsh-wa-crm-note-card' + (n.is_pinned ? ' tsh-wa-crm-note-card--pinned' : '') + '" data-note-id="' + n.id + '">' +
				'<div class="tsh-wa-crm-note-body">' + esc(n.content) + '</div>' +
				'<div class="tsh-wa-crm-note-meta">' +
					(n.is_pinned ? '📌 Pinned · ' : '') +
					(n.is_private ? '🔒 Private · ' : '') +
					fmtDatetime(n.created_at) +
					' <a href="#" class="crm-btn-pin-note" data-id="' + n.id + '" data-pinned="' + (n.is_pinned ? 1 : 0) + '">' + (n.is_pinned ? 'Unpin' : 'Pin') + '</a>' +
					' <a href="#" class="crm-btn-delete-note" data-id="' + n.id + '">Delete</a>' +
				'</div>' +
				'</div>';
		});
		$('#crm-notes-feed').html(html);
	}

	function bindNotes() {
		$('#crm-btn-save-note').on('click', function () {
			const content = $('#crm-note-editor').val().trim();
			if (!content) return;
			ajax('tsh_wa_crm_add_note', {
				customer_id: CRM.profileId,
				content:     content,
				is_pinned:   $('#crm-note-pin').is(':checked') ? 1 : 0,
				is_private:  $('#crm-note-private').is(':checked') ? 1 : 0,
			}, function (res) {
				if (res.success) {
					$('#crm-note-editor').val('');
					notify(CRM.i18n.note_added);
					reloadProfileSection('notes');
				}
			});
		});

		$(document).on('click', '.crm-btn-delete-note', function (e) {
			e.preventDefault();
			const id = $(this).data('id');
			ajax('tsh_wa_crm_delete_note', { note_id: id }, function (res) {
				if (res.success) reloadProfileSection('notes');
			});
		});

		$(document).on('click', '.crm-btn-pin-note', function (e) {
			e.preventDefault();
			const id = $(this).data('id'), pinned = parseInt($(this).data('pinned'));
			ajax('tsh_wa_crm_pin_note', { note_id: id, pinned: pinned ? 0 : 1 }, function (res) {
				if (res.success) reloadProfileSection('notes');
			});
		});
	}

	/* ===================================================================
	   TASKS
	   =================================================================== */
	function renderTasks(tasks) {
		if (!tasks.length) { $('#crm-tasks-feed').html('<p class="tsh-wa-crm-empty">No tasks yet.</p>'); return; }
		let html = '';
		tasks.forEach(function (t) {
			html += '<div class="tsh-wa-crm-task-item" data-task-id="' + t.id + '">' +
				'<div class="tsh-wa-crm-task-check"><input type="checkbox" class="crm-task-complete" data-id="' + t.id + '"' + (t.status === 'completed' ? ' checked disabled' : '') + '></div>' +
				'<div class="tsh-wa-crm-task-body">' +
					'<div class="tsh-wa-crm-task-title' + (t.status === 'completed' ? ' completed' : '') + '">' + esc(t.title) + '</div>' +
					'<div class="tsh-wa-crm-task-meta">' +
						taskPriorityBadge(t.priority) +
						(t.due_at ? ' Due: <span class="' + (new Date(t.due_at) < new Date() && t.status !== 'completed' ? 'tsh-wa-crm-task-due--overdue' : '') + '">' + fmtDatetime(t.due_at) + '</span>' : '') +
					'</div>' +
				'</div>' +
				'<div class="tsh-wa-crm-task-actions">' +
					(t.status !== 'completed' ? '<button class="button crm-btn-complete-task" data-id="' + t.id + '">✓</button>' : '') +
					'<button class="button button-link-delete crm-btn-delete-task" data-id="' + t.id + '">Del</button>' +
				'</div>' +
			'</div>';
		});
		$('#crm-tasks-feed').html(html);
	}

	function bindTasks() {
		$('#crm-btn-add-task-modal').on('click', function () {
			$('#crm-task-modal-title').text('Add Task');
			$('#crm-task-id').val('');
			$('#crm-task-title, #crm-task-desc').val('');
			$('#crm-task-priority').val('medium');
			$('#crm-task-due').val('');
			$('#crm-task-status').val('pending');
			populateTaskAssignees();
			openModal('crm-task-modal');
		});

		$('#crm-task-modal-close, #crm-btn-cancel-task').on('click', function () { closeModal('crm-task-modal'); });

		$('#crm-btn-save-task').on('click', function () {
			const id = $('#crm-task-id').val();
			const data = {
				customer_id:  CRM.profileId,
				task_id:      id,
				title:        $('#crm-task-title').val(),
				description:  $('#crm-task-desc').val(),
				priority:     $('#crm-task-priority').val(),
				due_at:       $('#crm-task-due').val(),
				status:       $('#crm-task-status').val(),
				assigned_to:  $('#crm-task-assignee').val(),
			};
			const action = id ? 'tsh_wa_crm_update_task' : 'tsh_wa_crm_add_task';
			ajax(action, data, function (res) {
				if (res.success) { closeModal('crm-task-modal'); reloadProfileSection('tasks'); }
				else notify(res.data.message || CRM.i18n.error, 'error');
			});
		});

		$(document).on('click', '.crm-btn-complete-task', function () {
			const id = $(this).data('id');
			ajax('tsh_wa_crm_complete_task', { task_id: id }, function (res) {
				if (res.success) { notify(CRM.i18n.task_completed); reloadProfileSection('tasks'); }
			});
		});

		$(document).on('click', '.crm-btn-delete-task', function () {
			const id = $(this).data('id');
			ajax('tsh_wa_crm_delete_task', { task_id: id }, function (res) {
				if (res.success) reloadProfileSection('tasks');
			});
		});
	}

	function populateTaskAssignees() {
		let opts = '<option value="0">— Select user —</option>';
		(CRM.users || []).forEach(function (u) {
			opts += '<option value="' + esc(u.id) + '">' + esc(u.name) + '</option>';
		});
		$('#crm-task-assignee').html(opts);
	}

	function reloadProfileSection(section) {
		ajax('tsh_wa_crm_profile', { customer_id: CRM.profileId }, function (res) {
			if (!res.success) return;
			const d = res.data;
			if (section === 'notes') renderNotes(d.notes || []);
			if (section === 'tasks') renderTasks(d.tasks || []);
			if (section === 'tags')  renderCustomerTags(d.tags || [], CRM.seed.tags || []);
		});
	}

	/* ===================================================================
	   TAGS
	   =================================================================== */
	function renderCustomerTags(assignedTags, allTags) {
		let html = '';
		assignedTags.forEach(function (tag) {
			const color = tag.color || '#6b7280';
			html += '<span class="tsh-wa-crm-tag-chip" style="background:' + esc(color) + '">' +
				esc(tag.name) +
				'<span class="crm-tag-remove" data-tag-id="' + tag.id + '" title="Remove">×</span>' +
				'</span>';
		});
		$('#crm-customer-tags').html(html || '<span style="color:#94a3b8;font-size:12px">No tags.</span>');

		// Populate tag select
		const assignedIds = assignedTags.map(function (t) { return t.id; });
		let opts = '<option value="">— Select tag to add —</option>';
		(allTags || []).forEach(function (t) {
			if (!assignedIds.includes(t.id)) {
				opts += '<option value="' + t.id + '">' + esc(t.name) + '</option>';
			}
		});
		$('#crm-tag-select').html(opts);
	}

	function bindTags() {
		$(document).on('click', '.crm-tag-remove', function () {
			ajax('tsh_wa_crm_remove_tag', { customer_id: CRM.profileId, tag_id: $(this).data('tag-id') }, function (res) {
				if (res.success) reloadProfileSection('tags');
			});
		});

		$('#crm-btn-add-tag').on('click', function () {
			const tagId = $('#crm-tag-select').val();
			if (!tagId) return;
			ajax('tsh_wa_crm_add_tag', { customer_id: CRM.profileId, tag_id: tagId }, function (res) {
				if (res.success) reloadProfileSection('tags');
				else notify(res.data.message || CRM.i18n.error, 'error');
			});
		});
	}

	/* ===================================================================
	   SEGMENTS
	   =================================================================== */
	function loadSegments() {
		ajax('tsh_wa_crm_segments', {}, function (res) {
			if (!res.success) return;
			CRM.ruleFields = res.data.rule_fields || [];
			renderSegments(res.data.segments || []);
		});
	}

	function renderSegments(segs) {
		if (!segs.length) { $('#crm-segment-grid').html('<p class="tsh-wa-crm-empty">No segments yet. Create your first segment.</p>'); return; }

		let html = '';
		segs.forEach(function (s) {
			html += '<div class="tsh-wa-crm-segment-card" data-seg-id="' + s.id + '">' +
				'<h4>' + esc(s.name) + '</h4>' +
				(s.description ? '<p>' + esc(s.description) + '</p>' : '') +
				'<div class="tsh-wa-crm-segment-meta">' + (s.match_count || 0) + ' customers · ' + (s.last_computed ? 'Updated ' + fmtDate(s.last_computed) : 'Never computed') + '</div>' +
				'<div class="tsh-wa-crm-segment-actions">' +
					'<button class="button crm-btn-view-segment" data-id="' + s.id + '">View Members</button>' +
					'<button class="button crm-btn-edit-segment" data-id="' + s.id + '" data-rules=\'' + JSON.stringify(s.rules || []) + '\' data-name="' + esc(s.name) + '" data-desc="' + esc(s.description || '') + '">Edit</button>' +
					'<button class="button button-link-delete crm-btn-delete-segment" data-id="' + s.id + '">Delete</button>' +
				'</div>' +
			'</div>';
		});
		$('#crm-segment-grid').html(html);
	}

	function bindSegments() {
		$('#crm-btn-create-segment').on('click', function () {
			$('#crm-segment-edit-id').val('');
			$('#crm-segment-name, #crm-segment-desc').val('');
			$('#crm-rules-list').html('');
			addRuleRow({});
			openModal('crm-segment-modal');
		});

		$('#crm-segment-modal-close, #crm-btn-cancel-segment').on('click', function () { closeModal('crm-segment-modal'); });

		$('#crm-btn-add-rule').on('click', function () { addRuleRow({}); });

		$(document).on('click', '.crm-btn-edit-segment', function () {
			$('#crm-segment-edit-id').val($(this).data('id'));
			$('#crm-segment-name').val($(this).data('name'));
			$('#crm-segment-desc').val($(this).data('desc'));
			$('#crm-rules-list').html('');
			const rules = $(this).data('rules') || [];
			(Array.isArray(rules) ? rules : []).forEach(function (r) { addRuleRow(r); });
			openModal('crm-segment-modal');
		});

		$(document).on('click', '.crm-btn-delete-segment', function () {
			if (!confirm('Delete this segment?')) return;
			ajax('tsh_wa_crm_delete_segment', { segment_id: $(this).data('id') }, function (res) {
				if (res.success) { notify('Segment deleted.'); loadSegments(); }
			});
		});

		$(document).on('click', '.crm-btn-view-segment', function () {
			const id = $(this).data('id');
			// Simple: reload customers with this segment filter — just show count for now
			alert('Segment ' + id + ' — use the Customers tab to filter by this segment.');
		});

		$('#crm-btn-save-segment').on('click', function () {
			const rules = collectRules();
			const id = $('#crm-segment-edit-id').val();
			const data = {
				segment_id:  id,
				name:        $('#crm-segment-name').val(),
				description: $('#crm-segment-desc').val(),
				rules:       JSON.stringify(rules),
			};
			const action = id ? 'tsh_wa_crm_update_segment' : 'tsh_wa_crm_create_segment';
			ajax(action, data, function (res) {
				if (res.success) { closeModal('crm-segment-modal'); notify('Segment saved.'); loadSegments(); }
				else notify(res.data.message || CRM.i18n.error, 'error');
			});
		});

		$(document).on('click', '.crm-rule-remove', function () { $(this).closest('.tsh-wa-crm-rule-row').remove(); });
	}

	function addRuleRow(rule) {
		let fieldOpts = '';
		(CRM.ruleFields || []).forEach(function (f) {
			fieldOpts += '<option value="' + esc(f.key) + '"' + (rule.field === f.key ? ' selected' : '') + '>' + esc(f.label) + '</option>';
		});

		const opOpts = ['equals','not_equals','greater_than','less_than','contains','not_contains','is_empty','is_not_empty'].map(function (o) {
			return '<option value="' + o + '"' + (rule.operator === o ? ' selected' : '') + '>' + o.replace(/_/g,' ') + '</option>';
		}).join('');

		const $row = $('<div class="tsh-wa-crm-rule-row">' +
			'<select class="crm-rule-field tsh-wa-crm-filter">' + fieldOpts + '</select>' +
			'<select class="crm-rule-op tsh-wa-crm-filter">' + opOpts + '</select>' +
			'<input type="text" class="crm-rule-val" value="' + esc(rule.value || '') + '" placeholder="value">' +
			'<button class="crm-rule-remove" type="button">×</button>' +
		'</div>');
		$('#crm-rules-list').append($row);
	}

	function collectRules() {
		const rules = [];
		$('#crm-rules-list .tsh-wa-crm-rule-row').each(function () {
			rules.push({
				field:    $(this).find('.crm-rule-field').val(),
				operator: $(this).find('.crm-rule-op').val(),
				value:    $(this).find('.crm-rule-val').val(),
			});
		});
		return rules;
	}

	/* ===================================================================
	   ALL TASKS (tasks tab)
	   =================================================================== */
	function loadAllTasks() {
		const status = $('#crm-task-filter-status').val() || '';
		$('#crm-task-list-global').html('<div class="tsh-wa-crm-loading">Loading tasks…</div>');
		ajax('tsh_wa_crm_get_tasks', { status: status, per_page: CRM.taskPerPage, page: CRM.taskPage }, function (res) {
			if (!res.success) return;
			const tasks = res.data.tasks || [];
			if (!tasks.length) { $('#crm-task-list-global').html('<p class="tsh-wa-crm-empty">No tasks found.</p>'); return; }
			let html = '';
			tasks.forEach(function (t) {
				html += '<div class="tsh-wa-crm-task-item">' +
					'<div class="tsh-wa-crm-task-body">' +
					'<div class="tsh-wa-crm-task-title">' + esc(t.title) + '</div>' +
					'<div class="tsh-wa-crm-task-meta">' + taskPriorityBadge(t.priority) + (t.due_at ? ' Due: ' + fmtDatetime(t.due_at) : '') + '</div>' +
					'</div>' +
					(t.status !== 'completed' ? '<button class="button crm-btn-complete-task" data-id="' + t.id + '">✓ Done</button>' : '') +
					'</div>';
			});
			$('#crm-task-list-global').html(html);
		});
	}

	/* ===================================================================
	   ANALYTICS
	   =================================================================== */
	function initAnalytics() {
		loadAnalyticsByType('growth',    30, 'crm-analytics-growth');
		loadAnalyticsByType('ltv',       30, 'crm-analytics-ltv');
		loadAnalyticsByType('orders',    30, 'crm-analytics-orders');
		loadAnalyticsByType('countries', 30, 'crm-analytics-countries');
	}

	function loadAnalyticsByType(type, days, canvasId) {
		ajax('tsh_wa_crm_analytics', { type: type, days: days }, function (res) {
			if (!res.success) return;
			const d = res.data;
			if (type === 'growth')    drawGrowthChart(canvasId, d.growth   || []);
			if (type === 'ltv')       drawPieChart(canvasId, d.ltv         || {});
			if (type === 'orders')    drawPieChart(canvasId, d.orders       || {});
			if (type === 'countries') drawPieChart(canvasId, d.countries    || {});
		});
	}

	function bindAnalytics() {
		$(document).on('click', '.tsh-wa-crm-range-btn', function () {
			$('.tsh-wa-crm-range-btn').removeClass('active');
			$(this).addClass('active');
			loadAnalyticsByType('growth', $(this).data('days'), 'crm-analytics-growth');
		});
	}

	/* ===================================================================
	   IMPORT
	   =================================================================== */
	function bindImport() {
		$('#crm-import-browse').on('click', function (e) {
			e.preventDefault();
			$('#crm-import-file').trigger('click');
		});

		$('#crm-import-file').on('change', function () {
			readImportFile(this.files[0]);
		});

		// Drag and drop
		$('#crm-import-dropzone').on('dragover', function (e) {
			e.preventDefault();
			$(this).addClass('drag-over');
		}).on('dragleave drop', function (e) {
			$(this).removeClass('drag-over');
			if (e.type === 'drop') {
				e.preventDefault();
				readImportFile(e.originalEvent.dataTransfer.files[0]);
			}
		});

		$('#crm-btn-import-run').on('click', function () {
			if (!CRM.importContent) return;
			ajax('tsh_wa_crm_import', {
				format:   CRM.importFormat,
				conflict: $('#crm-import-conflict').val(),
				content:  CRM.importContent,
			}, function (res) {
				const $r = $('#crm-import-result').show();
				if (res.success) {
					$r.attr('class', 'tsh-wa-crm-import-result success')
					  .text('Imported ' + (res.data.imported || 0) + ' · Skipped ' + (res.data.skipped || 0) + ' · Errors ' + (res.data.errors || 0));
				} else {
					$r.attr('class', 'tsh-wa-crm-import-result error').text(res.data.message || CRM.i18n.error);
				}
			});
		});

		$('#crm-btn-woo-sync').on('click', function () {
			$(this).prop('disabled', true).text('Syncing…');
			ajax('tsh_wa_crm_import', { format: 'woo' }, function (res) {
				$('#crm-btn-woo-sync').prop('disabled', false).text('Sync WooCommerce Customers');
				$('#crm-woo-sync-result').text(res.success ? 'Sync complete.' : (res.data.message || 'Error.'));
			});
		});
	}

	function readImportFile(file) {
		if (!file) return;
		CRM.importFormat = file.name.endsWith('.json') ? 'json' : 'csv';
		$('#crm-import-format').val(CRM.importFormat);
		const reader = new FileReader();
		reader.onload = function (e) {
			CRM.importContent = e.target.result;
			$('#crm-import-preview-msg').text('File ready: ' + file.name + ' (' + file.size + ' bytes)');
			$('#crm-import-preview').show();
			$('#crm-import-result').hide();
		};
		reader.readAsText(file);
	}

	/* ===================================================================
	   EXPORT
	   =================================================================== */
	function bindExport() {
		$('#crm-btn-export-run').on('click', function () {
			$(this).prop('disabled', true).text('Exporting…');
			ajax('tsh_wa_crm_export', {
				format:    $('#crm-export-format').val(),
				lifecycle: $('#crm-export-lifecycle').val(),
				is_vip:    $('#crm-export-vip').val(),
			}, function (res) {
				$('#crm-btn-export-run').prop('disabled', false).text('Export & Download');
				if (!res.success) { $('#crm-export-result').text(res.data.message || CRM.i18n.error); return; }
				const fmt     = res.data.format || 'csv';
				const content = res.data.content || '';
				const mime    = fmt === 'json' ? 'application/json' : 'text/csv';
				const blob    = new Blob([content], { type: mime });
				const url     = URL.createObjectURL(blob);
				const a       = document.createElement('a');
				a.href        = url;
				a.download    = 'crm-customers.' + fmt;
				document.body.appendChild(a);
				a.click();
				setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1000);
			});
		});
	}

	/* ===================================================================
	   DUPLICATES
	   =================================================================== */
	function bindDuplicates() {
		$('#crm-btn-find-duplicates').on('click', function () {
			$(this).prop('disabled', true).text('Scanning…');
			$('#crm-duplicates-list').html('<div class="tsh-wa-crm-loading">Scanning…</div>');
			ajax('tsh_wa_crm_find_duplicates', {}, function (res) {
				$('#crm-btn-find-duplicates').prop('disabled', false).text('🔍 Find Duplicates');
				if (!res.success) { $('#crm-duplicates-list').html('<p class="tsh-wa-crm-empty">Error.</p>'); return; }
				const groups = res.data.groups || [];
				if (!groups.length) { $('#crm-duplicates-list').html('<p class="tsh-wa-crm-empty">No duplicates found.</p>'); return; }

				let html = '';
				groups.forEach(function (g) {
					const customers = g.customers || [];
					let cards = '';
					customers.forEach(function (c) {
						cards += '<div class="tsh-wa-crm-dup-card">' +
							'<strong>' + esc(c.full_name || c.first_name + ' ' + c.last_name) + '</strong>' +
							'<div>' + esc(c.phone) + '</div>' +
							'<div>' + esc(c.email || '') + '</div>' +
							'<div>' + fmtMoney(c.lifetime_value) + ' LTV · ' + (c.total_orders || 0) + ' orders</div>' +
						'</div>';
					});

					html += '<div class="tsh-wa-crm-dup-group">' +
						'<p><strong>' + (g.reason || 'Possible duplicate') + '</strong></p>' +
						'<div class="tsh-wa-crm-dup-customers">' + cards + '</div>' +
						'<button class="button button-primary crm-btn-merge" data-source="' + (customers[0] && customers[0].id) + '" data-target="' + (customers[1] && customers[1].id) + '">Merge (keep second)</button>' +
					'</div>';
				});
				$('#crm-duplicates-list').html(html);
			});
		});

		$(document).on('click', '.crm-btn-merge', function () {
			const src = $(this).data('source'), tgt = $(this).data('target');
			if (!confirm(CRM.i18n.confirm_merge)) return;
			ajax('tsh_wa_crm_merge', { source_id: src, target_id: tgt }, function (res) {
				if (res.success) { notify(CRM.i18n.merged_ok); $('#crm-btn-find-duplicates').trigger('click'); }
				else notify(res.data.message || CRM.i18n.error, 'error');
			});
		});
	}

	/* ===================================================================
	   SETTINGS
	   =================================================================== */
	function populateSettings() {
		const s = CRM.settings || {};
		$('#crm-setting-vip-ltv').val(s.vip_ltv_threshold   || 500);
		$('#crm-setting-vip-orders').val(s.vip_order_threshold || 5);
		$('#crm-setting-dormant-days').val(s.dormant_days       || 60);
		$('#crm-setting-inactive-days').val(s.inactive_days     || 90);
		$('#crm-setting-reminder-hours').val(s.task_reminder_hours || 24);
		$('#crm-setting-auto-sync').prop('checked',      !!s.auto_sync_woo);
		$('#crm-setting-auto-score').prop('checked',     !!s.auto_score_on_order);
		$('#crm-setting-auto-lifecycle').prop('checked', !!s.auto_lifecycle_cron);
	}

	function bindSettings() {
		$('#crm-settings-form').on('submit', function (e) {
			e.preventDefault();
			ajax('tsh_wa_crm_save_settings', {
				vip_ltv_threshold:   $('#crm-setting-vip-ltv').val(),
				vip_order_threshold: $('#crm-setting-vip-orders').val(),
				dormant_days:        $('#crm-setting-dormant-days').val(),
				inactive_days:       $('#crm-setting-inactive-days').val(),
				task_reminder_hours: $('#crm-setting-reminder-hours').val(),
				auto_sync_woo:       $('#crm-setting-auto-sync').is(':checked') ? 1 : 0,
				auto_score_on_order: $('#crm-setting-auto-score').is(':checked') ? 1 : 0,
				auto_lifecycle_cron: $('#crm-setting-auto-lifecycle').is(':checked') ? 1 : 0,
			}, function (res) {
				if (res.success) { CRM.settings = res.data.settings; notify(CRM.i18n.saved); }
				else notify(res.data.message || CRM.i18n.error, 'error');
			});
		});
	}

	/* ===================================================================
	   Modal helpers
	   =================================================================== */
	function openModal(id) {
		$('#' + id).show();
		$('body').addClass('crm-modal-open');
	}

	function closeModal(id) {
		$('#' + id).hide();
		$('body').removeClass('crm-modal-open');
	}

	/* ===================================================================
	   Bootstrap
	   =================================================================== */
	function init() {
		initTabs();
		initDashboard();     // load on page open
		bindCustomerControls();
		bindCustomerForm();
		bindProfileTabs();
		bindProfileActions();
		bindTimeline();
		bindNotes();
		bindTasks();
		bindTags();
		bindSegments();
		bindAnalytics();
		bindImport();
		bindExport();
		bindDuplicates();
		bindSettings();

		// Tasks tab filter
		$('#crm-task-filter-status').on('change', function () { CRM.taskPage = 1; loadAllTasks(); });
	}

	$(document).ready(init);

}(jQuery));
