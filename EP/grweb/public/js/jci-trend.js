/*
 * JCI-EP-WEB-Patch — native trendpagina (ApexCharts + persistente config).
 * Copyright (C) 2026 Dymotica B.V. <info@dymotica.nl>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 * ----
 *
 * Mirrors the EasyIO ApexChartPlugin that this user already runs natively
 * on the controller. Talks to app/trend_api.php for schema + windowed data,
 * and uses ApexCharts for rendering. Per-point unit (for analog) and state
 * texts (off/on for binary) live in localStorage, scoped per table.
 */
(function ($) {
	'use strict';
	if (!window.jQuery) return;
	if (!$('#trendContainer').length) return;
	if (typeof ApexCharts === 'undefined') {
		console.warn('[trend] ApexCharts not loaded');
		return;
	}
	if (typeof moment === 'undefined') {
		console.warn('[trend] moment.js not loaded');
		return;
	}

	var API = 'trend_api.php';
	var PERMS_API = 'user_perms_api.php';
	var TABLE = window.JCI_TREND_TABLE || '';
	if (!TABLE) return;

	// Per-user feature permission. Default = true so unrestricted users
	// (and offline / API failures) keep working as before. The body class
	// drives CSS that hides the pencil-edit button when the user isn't
	// allowed to edit — visibility checkboxes stay functional regardless.
	var PERMS = { trend_edit: true };
	function fetchPerms() {
		$.ajax({
			url: PERMS_API + '?cmd=get-mine',
			dataType: 'json',
			cache: false
		}).done(function (data) {
			if (data && data.perms && 'trend_edit' in data.perms) {
				PERMS.trend_edit = !!data.perms.trend_edit;
			}
			$('body').toggleClass('jci-noperm-trend-edit', !PERMS.trend_edit);
		});
	}

	// Vivid color palette — Material 500-shade saturated colors so each line
	// reads clearly against the white grid even when many series overlap.
	var PALETTE = [
		'#E53935', '#1E88E5', '#43A047', '#FB8C00', '#8E24AA',
		'#00ACC1', '#FDD835', '#6D4C41', '#3949AB', '#D81B60',
		'#7CB342', '#F4511E', '#00897B', '#5E35B1', '#C0CA33',
		'#039BE5', '#EF6C00', '#546E7A', '#AD1457', '#558B2F'
	];

	// Stroke width for analog lines (px). Thicker than Apex's default 2 for
	// better legibility on dense charts.
	var ANALOG_STROKE_W = 3;
	// Opacity of binary-point background bands. High enough to see the
	// "on" intervals at a glance without hiding the analog lines on top.
	var BOOL_BAND_OPACITY = 0.22;

	// ---------- State -----------------------------------------------------
	var state = {
		floatCols: [],   // [{name, unit, color}]
		boolCols: [],    // [{name, stateTexts:{off,on}, color}]
		visible: {},     // { colName: bool }
		dateFrom: null,
		dateTo: null,
		chart: null,
		rows: null,
		panMode: false,
		preset: null,        // active rolling preset key, e.g. '24h'
		refreshTimer: null   // interval id for auto-refresh
	};

	// ---------- Server-side config -----------------------------------------
	// Per-table config (visibility / units / stateTexts) lives in a JSON
	// file on the controller alongside the PHP. This keeps the configuration
	// shared across browsers and users — anyone hitting the trend page sees
	// the same units, state texts and default visibility.
	var serverCfg = { visible: {}, units: {}, stateTexts: {} };

	function fetchConfig() {
		return $.ajax({ url: API, dataType: 'json', data: { cmd: 'get-config', table: TABLE } })
			.done(function (resp) {
				var c = (resp && resp.config) || {};
				serverCfg = {
					visible: c.visible || {},
					units: c.units || {},
					stateTexts: c.stateTexts || {}
				};
			});
	}

	function postConfig() {
		return $.ajax({
			url: API, method: 'POST', dataType: 'json',
			data: {
				cmd: 'save-config',
				table: TABLE,
				visible: JSON.stringify(serverCfg.visible),
				units: JSON.stringify(serverCfg.units),
				stateTexts: JSON.stringify(serverCfg.stateTexts)
			}
		});
	}

	// Visibility-only save. Allowed for every signed-in user (no trend_edit
	// gate) — used by the per-row visibility checkboxes so non-edit users
	// can still pick which lines they want plotted.
	function postVisibleOnly() {
		return $.ajax({
			url: API, method: 'POST', dataType: 'json',
			data: {
				cmd: 'save-visible',
				table: TABLE,
				visible: JSON.stringify(serverCfg.visible)
			}
		});
	}

	function getCfg() { return serverCfg; }

	// ---------- Schema classification -----------------------------------
	function isFloat(t) { return /(float|real|double|numeric|decimal)/i.test(t || ''); }
	function isBool(t)  { return /(bool|boolean|bit)/i.test(t || ''); }

	function loadSchema() {
		return $.ajax({
			url: API, dataType: 'json',
			data: { cmd: 'schema', table: TABLE }
		}).done(function (resp) {
			var rows = (resp && resp.schema) || [];
			var cfg = getCfg();
			var colorIdx = 0;
			state.floatCols = [];
			state.boolCols = [];
			rows.forEach(function (col) {
				var name = col.name;
				var type = (col.type || '').toLowerCase();
				if (!name || name === 'dt') return;
				if (isBool(type)) {
					state.boolCols.push({
						name: name,
						color: PALETTE[colorIdx++ % PALETTE.length],
						stateTexts: cfg.stateTexts[name] || { off: '0', on: '1' }
					});
				} else if (isFloat(type) || /int/i.test(type)) {
					// Treat integers as analog too, unless explicitly bool.
					state.floatCols.push({
						name: name,
						color: PALETTE[colorIdx++ % PALETTE.length],
						unit: cfg.units[name] || ''
					});
				}
			});
			// Visibility precedence:
			//   1. URL `?visible=col1,col2` — overrides everything (deep-link case).
			//      Empty `?visible=` means "all hidden". Missing param = use 2/3.
			//   2. Server-saved per-column boolean.
			//   3. Default: first 3 floats + all bools.
			var urlVisible = readUrlVisibleSet();
			state.floatCols.forEach(function (c, i) {
				if (urlVisible) state.visible[c.name] = urlVisible.has(c.name);
				else state.visible[c.name] = (typeof cfg.visible[c.name] === 'boolean') ? cfg.visible[c.name] : (i < 3);
			});
			state.boolCols.forEach(function (c) {
				if (urlVisible) state.visible[c.name] = urlVisible.has(c.name);
				else state.visible[c.name] = (typeof cfg.visible[c.name] === 'boolean') ? cfg.visible[c.name] : true;
			});
		});
	}

	// ---------- Deep-link URL params ------------------------------------
	// Public surface (read on init, written after every state change):
	//   ?table=<name>                 — already handled by trend.php
	//   ?visible=col1,col2,col3       — only these points checked. URL-encoded.
	//                                   Missing = use server default. Empty = none.
	//   ?preset=24h | 7d | today | …  — applies a quick-range preset key.
	//   ?from=YYYY-MM-DD HH:mm:ss     — wall-clock; overrides preset's from.
	//   ?to=YYYY-MM-DD HH:mm:ss       — wall-clock; overrides preset's to.
	function readUrlParams() {
		var p = {};
		try {
			var u = new URL(window.location.href);
			u.searchParams.forEach(function (v, k) { p[k] = v; });
		} catch (e) { /* old browsers — fall through */ }
		return p;
	}
	// Returns a Set of visible col names from URL, or null if param missing.
	function readUrlVisibleSet() {
		var p = readUrlParams();
		if (!('visible' in p)) return null;
		var raw = String(p.visible);
		if (raw === '') return new Set();
		return new Set(raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean));
	}
	function writeUrlState() {
		try {
			var u = new URL(window.location.href);
			// Visible — comma-joined column names. Encode happens automatically.
			var vis = Object.keys(state.visible).filter(function (k) { return state.visible[k]; });
			u.searchParams.set('visible', vis.join(','));
			// Preset OR from/to (mutually exclusive in the URL — preset wins
			// if active, else explicit range).
			if (state.preset) {
				u.searchParams.set('preset', state.preset);
				u.searchParams.delete('from');
				u.searchParams.delete('to');
			} else {
				u.searchParams.delete('preset');
				if (state.dateFrom) u.searchParams.set('from', state.dateFrom); else u.searchParams.delete('from');
				if (state.dateTo)   u.searchParams.set('to',   state.dateTo);   else u.searchParams.delete('to');
			}
			window.history.replaceState(null, '', u.toString());
		} catch (e) { /* ignore — non-critical */ }
	}

	// ---------- Points-config card --------------------------------------
	function renderPointsList() {
		var $list = $('#trend-points-list');
		var html = '';
		state.floatCols.forEach(function (c) {
			html += pointRow(c, 'float');
		});
		state.boolCols.forEach(function (c) {
			html += pointRow(c, 'bool');
		});
		if (!state.floatCols.length && !state.boolCols.length) {
			html = '<div class="trend-points-empty">No points found</div>';
		}
		$list.html(html);
	}

	function pointRow(col, kind) {
		var checked = state.visible[col.name] ? 'checked' : '';
		var swatch = '<span class="trend-point-swatch" style="background:' + col.color + '"></span>';
		var label = '<span class="trend-point-name">' + escapeHtml(col.name) + '</span>';
		var input;
		if (kind === 'float') {
			input = '<div class="trend-point-input">' +
				'<label>Unit</label>' +
				'<input type="text" data-kind="unit" data-col="' + escapeHtml(col.name) + '" value="' + escapeHtml(col.unit || '') + '" placeholder="e.g. °C">' +
			'</div>';
		} else {
			input = '<div class="trend-point-input trend-point-states">' +
				'<label>State texts</label>' +
				'<div class="trend-point-state-pair">' +
					'<input type="text" data-kind="state-off" data-col="' + escapeHtml(col.name) + '" value="' + escapeHtml(col.stateTexts.off || '0') + '" placeholder="0">' +
					'<input type="text" data-kind="state-on"  data-col="' + escapeHtml(col.name) + '" value="' + escapeHtml(col.stateTexts.on  || '1') + '" placeholder="1">' +
				'</div>' +
			'</div>';
		}
		return '<div class="trend-point-row" data-col="' + escapeHtml(col.name) + '">' +
			'<label class="trend-point-toggle">' +
				'<input type="checkbox" data-kind="visible" data-col="' + escapeHtml(col.name) + '" ' + checked + '>' +
				swatch + label +
				'<span class="trend-point-kind">' + (kind === 'bool' ? 'binary' : 'analog') + '</span>' +
			'</label>' +
			input +
		'</div>';
	}

	function bindPointsEvents() {
		// Visibility checkboxes are always editable. Toggling saves to the
		// server immediately so the chosen-lines preference is shared too.
		$('#trend-points-list').on('change', 'input[type="checkbox"][data-kind="visible"]', function () {
			var col = $(this).data('col');
			var on  = $(this).prop('checked');
			state.visible[col] = on;
			serverCfg.visible[col] = on;
			postVisibleOnly();   // fire and forget — allowed for any signed-in user
			writeUrlState();
			renderChart();
		});
		// Unit / state-text inputs are edit-mode only. They mutate state in
		// memory; persistence only happens when the user clicks Save.
		$('#trend-points-list').on('change input', 'input[type="text"]', function () {
			var col = $(this).data('col');
			var kind = $(this).data('kind');
			var val = $(this).val();
			if (kind === 'unit') {
				var entry = findFloat(col); if (entry) entry.unit = val;
			} else if (kind === 'state-off' || kind === 'state-on') {
				var entry2 = findBool(col); if (!entry2) return;
				var key = kind === 'state-off' ? 'off' : 'on';
				entry2.stateTexts = entry2.stateTexts || { off: '0', on: '1' };
				entry2.stateTexts[key] = val;
			}
			renderChart();
		});

		// Edit-mode toggles
		var $card = $('#trend-points-card');
		$card.on('click', '.trend-edit-btn', function () {
			$card.addClass('editing');
		});
		$card.on('click', '.trend-save-btn', function () {
			// Sync state -> serverCfg, then POST.
			var units = {}, stateTexts = {};
			state.floatCols.forEach(function (c) { units[c.name] = c.unit || ''; });
			state.boolCols.forEach(function (c) { stateTexts[c.name] = c.stateTexts || { off: '0', on: '1' }; });
			serverCfg.units = units;
			serverCfg.stateTexts = stateTexts;
			postConfig().done(function () {
				$card.removeClass('editing');
			}).fail(function (xhr) {
				var msg = (xhr && xhr.responseJSON && xhr.responseJSON.error) || 'Save failed';
				alert(msg);
			});
		});
		$card.on('click', '.trend-cancel-btn', function () {
			// Discard local edits — reload from server and re-render.
			fetchConfig().always(function () {
				state.floatCols.forEach(function (c) { c.unit = serverCfg.units[c.name] || ''; });
				state.boolCols.forEach(function (c) {
					c.stateTexts = serverCfg.stateTexts[c.name] || { off: '0', on: '1' };
				});
				renderPointsList();
				$card.removeClass('editing');
				renderChart();
			});
		});
	}

	function findFloat(name) { return state.floatCols.filter(function (c) { return c.name === name; })[0]; }
	function findBool(name)  { return state.boolCols.filter(function (c) { return c.name === name; })[0]; }

	// ---------- Date+time range picker (bootstrap-datetimepicker) -------
	function initDateRange() {
		var $fromWrap = $('[data-role="trend-dt-from"]');
		var $toWrap   = $('[data-role="trend-dt-to"]');
		if (!$fromWrap.length || typeof $fromWrap.datetimepicker !== 'function') return;
		var opts = {
			format: 'yyyy-MM-dd hh:mm',
			pickDate: true,
			pickTime: true,
			pickSeconds: false,
			maskInput: false
		};
		$fromWrap.datetimepicker(opts);
		$toWrap.datetimepicker(opts);
		var fp = $fromWrap.data('datetimepicker');
		var tp = $toWrap.data('datetimepicker');

		// Default range: last 7 days (now - 7d → now).
		var now = new Date();
		var sevenAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
		// Picker uses UTC internally for setValue; pass UTC equivalent of
		// the LOCAL clock time so the input shows the user's local time.
		fp.setValue(Date.UTC(sevenAgo.getFullYear(), sevenAgo.getMonth(), sevenAgo.getDate(),
			sevenAgo.getHours(), sevenAgo.getMinutes()));
		tp.setValue(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate(),
			now.getHours(), now.getMinutes()));
		updateLabels();
		// state.dateFrom / dateTo are sent UTC to the SQL WHERE clause
		// (DB stores UTC). Picker times are local; convert with moment.utc.
		state.dateFrom = moment(sevenAgo).utc().format('YYYY-MM-DD HH:mm:00');
		state.dateTo   = moment(now).utc().format('YYYY-MM-DD HH:mm:59');

		// Manual picker change → user is overriding any active preset.
		$fromWrap.on('changeDate', function (e) {
			state.preset = null; updatePresetLabel();
			if (!e.date) { state.dateFrom = null; updateLabels(); writeUrlState(); loadAndRender(); return; }
			state.dateFrom = moment.utc(e.date).format('YYYY-MM-DD HH:mm:00');
			if (tp) tp.setStartDate(e.date);
			updateLabels(); writeUrlState(); loadAndRender();
		});
		$toWrap.on('changeDate', function (e) {
			state.preset = null; updatePresetLabel();
			if (!e.date) { state.dateTo = null; updateLabels(); writeUrlState(); loadAndRender(); return; }
			state.dateTo = moment.utc(e.date).format('YYYY-MM-DD HH:mm:59');
			if (fp) fp.setEndDate(e.date);
			updateLabels(); writeUrlState(); loadAndRender();
		});
	}
	function updateLabels() {
		var f = $('[data-role="trend-date-from"]').val();
		var t = $('[data-role="trend-date-to"]').val();
		var $fl = $('[data-role="trend-dt-from-label"]');
		var $tl = $('[data-role="trend-dt-to-label"]');
		$fl.text(f || 'From').toggleClass('is-empty', !f);
		$tl.text(t || 'To').toggleClass('is-empty', !t);
	}

	// ---------- Data load + chart render --------------------------------
	// Always over-fetch by 50% on each side of the visible window so panning
	// inside the loaded range needs no refetch. state.loadedFrom/loadedTo
	// track the actual query bounds; the visible chart range stays at
	// state.dateFrom/dateTo.
	function loadAndRender() {
		showLoading(true);
		var allCols = state.floatCols.map(function (c) { return c.name; })
			.concat(state.boolCols.map(function (c) { return c.name; }));
		var fetchFrom = state.dateFrom || moment().startOf('day').format('YYYY-MM-DD HH:mm:ss');
		var fetchTo   = state.dateTo   || moment().endOf('day').format('YYYY-MM-DD HH:mm:ss');
		var span = parseLocalDt(fetchTo) - parseLocalDt(fetchFrom);
		if (isFinite(span) && span > 0) {
			var pad = span * 0.5;   // 50% buffer on each side
			fetchFrom = moment(parseLocalDt(state.dateFrom) - pad).format('YYYY-MM-DD HH:mm:ss');
			fetchTo   = moment(parseLocalDt(state.dateTo)   + pad).format('YYYY-MM-DD HH:mm:ss');
		}
		state.loadedFrom = fetchFrom;
		state.loadedTo   = fetchTo;
		$.ajax({
			url: API, dataType: 'json',
			data: {
				cmd: 'query',
				table: TABLE,
				from: fetchFrom,
				to:   fetchTo,
				cols: allCols.join(','),
				tz:   moment().utcOffset()    // browser's local UTC offset (minutes)
			}
		}).done(function (resp) {
			state.rows = (resp && resp.rows) || [];
			renderChart();
		}).always(function () { showLoading(false); });
	}

	// Returns true when the visible range is well inside the buffer we already
	// loaded — meaning we can re-render from cache without hitting the DB.
	function isInsideLoadedRange() {
		if (!state.loadedFrom || !state.loadedTo) return false;
		var lf = parseLocalDt(state.loadedFrom), lt = parseLocalDt(state.loadedTo);
		var vf = parseLocalDt(state.dateFrom),    vt = parseLocalDt(state.dateTo);
		if (!isFinite(lf+lt+vf+vt)) return false;
		// Stay inside with a 10% safety margin so we refetch BEFORE hitting
		// the buffer edge.
		var span = lt - lf;
		var safe = span * 0.10;
		return (vf - safe) >= lf && (vt + safe) <= lt;
	}

	function showLoading(on) { $('#trend-loading').toggle(!!on); }

	function renderChart() {
		var rows = state.rows || [];
		// Even when the window has no data, render the chart with the
		// requested x-axis range so the user can see they're looking at
		// the right window (just without lines). Hide the textual "no data"
		// card; the empty axes communicate the same thing.
		$('#trend-empty').hide();
		var seriesList = [];
		var colors = [];
		var widths = [];
		var annotations = [];
		// Float series
		state.floatCols.forEach(function (c) {
			if (!state.visible[c.name]) return;
			var pts = rows.map(function (r) {
				var ts = parseDt(r.dt);
				var v = r[c.name];
				var y = (v === '' || v === null || typeof v === 'undefined') ? null : Number(v);
				return { x: ts, y: y };
			}).filter(function (p) { return p.x !== null; });
			seriesList.push({ name: c.name, data: pts });
			colors.push(c.color);
			widths.push(ANALOG_STROKE_W);
		});
		// Bool series + shaded annotations for "on" ranges
		state.boolCols.forEach(function (c) {
			if (!state.visible[c.name]) return;
			var pts = rows.map(function (r) {
				var ts = parseDt(r.dt);
				var v = r[c.name];
				var y = (v === 1 || v === '1' || v === true || v === 'true') ? 1 : 0;
				return { x: ts, y: y };
			}).filter(function (p) { return p.x !== null; });
			seriesList.push({ name: c.name, data: pts });
			colors.push(c.color);
			widths.push(0);
			annotations = annotations.concat(boolRanges(pts, c.color));
		});

		// Compute the requested window in local-ms (matches how parseDt
		// interprets server rows). We always pin xaxis.min/max to this so
		// the user sees the full picked range — even if the rows array is
		// empty for that window, the axes still render.
		var wMin = parseLocalDt(state.dateFrom);
		var wMax = parseLocalDt(state.dateTo);

		var opts = {
			chart: {
				type: 'line',
				height: '100%',
				toolbar: { show: false },     // we drive zoom/pan/export from our own toolbar
				animations: { enabled: false },
				zoom: { enabled: !state.panMode, type: 'x', autoScaleYaxis: false },
				events: {
					// Sync the date pickers to whatever range Apex is currently
					// showing (zoomed via drag-to-zoom, our toolbar buttons, or
					// scrolled via pan).
					zoomed: function (ctx, e) { syncPickersToRange(e && e.xaxis); },
					scrolled: function (ctx, e) { syncPickersToRange(e && e.xaxis); }
				}
			},
			series: seriesList,
			colors: colors,
			stroke: { curve: 'monotoneCubic', width: widths },
			markers: { size: 0 },
			xaxis: {
				type: 'datetime',
				min: wMin || undefined,
				max: wMax || undefined,
				labels: { rotate: -45, datetimeUTC: false, format: 'yyyy-MM-dd HH:mm:ss' }
			},
			yaxis: { decimalsInFloat: 2, forceNiceScale: true },
			grid: { show: true },
			legend: { show: false },
			annotations: { xaxis: annotations },
			tooltip: {
				shared: true,
				x: { format: 'yyyy-MM-dd HH:mm:ss' },
				y: {
					formatter: function (value, opts) {
						if (value === null || typeof value === 'undefined') return value;
						var name = opts.w.globals.seriesNames[opts.seriesIndex];
						var bool = findBool(name);
						if (bool) return value === 1 ? bool.stateTexts.on : bool.stateTexts.off;
						var fl = findFloat(name);
						return value + (fl && fl.unit ? ' ' + fl.unit : '');
					}
				}
			},
			noData: { text: '' }
		};

		if (!state.chart) {
			state.chart = new ApexCharts(document.getElementById('trend-chart'), opts);
			state.chart.render().then(function () { fitChartToContainer(); });
			observeChartResize();
		} else {
			state.chart.updateOptions(opts, false, false);
			fitChartToContainer();
		}
	}

	// Apex's chart only auto-resizes on window resize. Use ResizeObserver
	// on the wrap so the chart also follows sidebar-collapse transitions
	// and any container reflow.
	var _chartResizeObserver = null;
	function observeChartResize() {
		if (!window.ResizeObserver || _chartResizeObserver) return;
		var wrap = document.querySelector('.trend-chart-wrap');
		if (!wrap) return;
		_chartResizeObserver = new ResizeObserver(function () { fitChartToContainer(); });
		_chartResizeObserver.observe(wrap);
	}
	function fitChartToContainer() {
		if (!state.chart) return;
		var wrap = document.querySelector('.trend-chart-wrap');
		if (!wrap) return;
		var h = wrap.clientHeight - 16; // padding compensation
		if (h <= 0) return;
		try {
			state.chart.updateOptions({ chart: { height: h } }, false, false, false);
		} catch (e) { /* ignore */ }
	}

	// PHP applies the browser's tz offset to the dt column before returning,
	// so the strings here are already in LOCAL clock time. Parse without a
	// 'Z' suffix → JS Date interprets as local; ApexCharts (with
	// datetimeUTC: false) renders the same wall-clock time the user sees.
	function parseDt(dt) {
		if (!dt) return null;
		var ts = new Date((dt + '').replace(' ', 'T')).getTime();
		return isNaN(ts) ? null : ts;
	}
	// state.dateFrom / dateTo are stored as moment.utc(picker).format(...)
	// — the user's wall-clock value as an ISO-ish string. Parse the same
	// way as row dt so picker range and chart x-values share a coordinate
	// system.
	var parseLocalDt = parseDt;

	function boolRanges(pts, color) {
		var out = [];
		var startTs = null, lastTs = null;
		pts.forEach(function (p) {
			if (p.y === 1 && startTs === null) startTs = p.x;
			if (p.y !== 1 && startTs !== null) {
				out.push({ x: startTs, x2: lastTs || p.x, fillColor: color, opacity: BOOL_BAND_OPACITY, borderColor: 'transparent' });
				startTs = null;
			}
			lastTs = p.x;
		});
		if (startTs !== null && lastTs !== null) {
			out.push({ x: startTs, x2: lastTs, fillColor: color, opacity: BOOL_BAND_OPACITY, borderColor: 'transparent' });
		}
		return out;
	}

	function escapeHtml(s) {
		if (s == null) return '';
		return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	// ---------- Toolbar: zoom / pan / export (driven by ApexCharts) -----
	function bindToolbar() {
		$('#trend-refresh').on('click', loadAndRender);

		// Zoom in: shrink current x-range by 25% around its midpoint.
		$('#trend-zoom-in').on('click', function () {
			zoomBy(0.5);
		});
		$('#trend-zoom-out').on('click', function () {
			zoomBy(2);
		});
		$('#trend-zoom-reset').on('click', function () {
			if (!state.chart) return;
			// Reset to the full picker window so the user always sees the
			// range they selected (matches xaxis.min/max baseline).
			var wMin = parseLocalDt(state.dateFrom);
			var wMax = parseLocalDt(state.dateTo);
			if (wMin && wMax) state.chart.zoomX(wMin, wMax);
		});
		$('#trend-pan').on('click', function () {
			togglePanMode(!state.panMode);
		});

		// Export — uses ApexCharts' own export pipeline (PNG/SVG/CSV).
		$('#trend-export-menu').on('click', 'a[data-export]', function (e) {
			e.preventDefault();
			doExport($(this).data('export'));
		});

		// Collapse / expand the points-config card so the chart can grow.
		$('#trend-points-collapse').on('click', function () {
			$('body').addClass('jci-trend-points-collapsed');
			fitChartToContainer();
		});
		$('#trend-points-expand').on('click', function () {
			$('body').removeClass('jci-trend-points-collapsed');
			fitChartToContainer();
		});

		// Quick-range presets — set both pickers and reload.
		$('#trend-preset-menu').on('click', 'a[data-preset]', function (e) {
			e.preventDefault();
			applyPreset($(this).data('preset'));
		});
	}

	// Sync the date pickers to whatever range Apex is currently showing.
	// Called on Apex's `zoomed` and `scrolled` events.
	//
	// IMPORTANT: state.dateFrom/dateTo store WALL-CLOCK strings (matching
	// the picker flow which formats moment.utc(e.date) — picker uses
	// Date.UTC internally so its "UTC" is actually the user's wall-clock).
	// xaxis.min from Apex is a real epoch ms in the user's local TZ.
	// `moment(ms).format(...)` (no .utc()) gives the wall-clock string —
	// formatting WITH .utc() would shift by tz_offset and parseLocalDt
	// would then re-parse to a DIFFERENT local-ms, leaving an empty band
	// on the left edge of the chart equal to the timezone offset.
	function syncPickersToRange(xaxis) {
		if (!xaxis || !isFinite(xaxis.min) || !isFinite(xaxis.max)) return;
		var fromMoment = moment(xaxis.min);
		var toMoment   = moment(xaxis.max);
		setPickerValues(fromMoment.toDate(), toMoment.toDate());
		state.dateFrom = fromMoment.format('YYYY-MM-DD HH:mm:ss');
		state.dateTo   = toMoment.format('YYYY-MM-DD HH:mm:ss');
		// User-driven zoom/pan implies they're picking a custom range, so
		// drop any active rolling preset.
		state.preset = null;
		updateLabels();
		updatePresetLabel();
		writeUrlState();
		// If the new visible range is comfortably inside the over-fetched
		// buffer, no DB round-trip is needed — just refetch when we get
		// near the buffer edge or zoomed beyond it. This makes pan smooth.
		if (isInsideLoadedRange()) {
			clearTimeout(syncPickersToRange._t);
			return;
		}
		// Outside (or near) the buffer → schedule a refetch. Long debounce
		// so a continuous pan doesn't spam the controller.
		clearTimeout(syncPickersToRange._t);
		syncPickersToRange._t = setTimeout(loadAndRender, 1500);
	}

	// Set both date-picker widgets to the given Date objects without
	// firing the changeDate handlers (they would re-trigger reload).
	function setPickerValues(fromDate, toDate) {
		var $fromWrap = $('[data-role="trend-dt-from"]');
		var $toWrap   = $('[data-role="trend-dt-to"]');
		var fp = $fromWrap.data('datetimepicker');
		var tp = $toWrap.data('datetimepicker');
		// Picker uses UTC internally for setValue; pass UTC equivalent of
		// the LOCAL clock time so the input shows the user's local time.
		if (fp && fromDate) {
			fp.setValue(Date.UTC(fromDate.getFullYear(), fromDate.getMonth(), fromDate.getDate(),
				fromDate.getHours(), fromDate.getMinutes()));
		}
		if (tp && toDate) {
			tp.setValue(Date.UTC(toDate.getFullYear(), toDate.getMonth(), toDate.getDate(),
				toDate.getHours(), toDate.getMinutes()));
		}
	}

	// Preset definitions. Returning {from, to} as Date objects in LOCAL time.
	// `rolling: true` means the next auto-refresh tick re-evaluates the
	// preset against the new "now" so the window slides forward over time.
	var PRESETS = {
		'1h':        { rolling: true,  label: 'Last hour',     calc: function (n) { return { from: addHours(n, -1),   to: n }; } },
		'6h':        { rolling: true,  label: 'Last 6 hours',  calc: function (n) { return { from: addHours(n, -6),   to: n }; } },
		'24h':       { rolling: true,  label: 'Last day',      calc: function (n) { return { from: addHours(n, -24),  to: n }; } },
		'7d':        { rolling: true,  label: 'Last week',     calc: function (n) { return { from: addHours(n, -168), to: n }; } },
		'30d':       { rolling: true,  label: 'Last 30 days',  calc: function (n) { return { from: addHours(n, -720), to: n }; } },
		'today':     { rolling: true,  label: 'Today',         calc: function (n) { return { from: startOfDay(n),     to: endOfDay(n) }; } },
		'yesterday': { rolling: false, label: 'Yesterday',     calc: function (n) { var y = addHours(n, -24); return { from: startOfDay(y), to: endOfDay(y) }; } },
		'thisweek':  { rolling: true,  label: 'This week',     calc: function (n) { return { from: startOfWeek(n),    to: endOfDay(n) }; } },
		'thismonth': { rolling: true,  label: 'This month',    calc: function (n) { return { from: new Date(n.getFullYear(), n.getMonth(), 1), to: endOfDay(n) }; } }
	};
	function addHours(d, h) { return new Date(d.getTime() + h * 60 * 60 * 1000); }

	// Quick-range preset handler. Stores the active preset key on state so
	// the auto-refresh tick can re-evaluate rolling ranges (Last day rolls
	// forward as time passes, etc.).
	function applyPreset(key) {
		var def = PRESETS[key];
		if (!def) return;
		state.preset = key;
		updatePresetLabel();
		applyRange(def.calc(new Date()));
		writeUrlState();
		loadAndRender();
	}

	// Apply a {from, to} window: update pickers, state.dateFrom/dateTo (in
	// wall-clock format to match the picker flow), and labels.
	function applyRange(range) {
		setPickerValues(range.from, range.to);
		state.dateFrom = moment(range.from).format('YYYY-MM-DD HH:mm:00');
		state.dateTo   = moment(range.to).format('YYYY-MM-DD HH:mm:59');
		updateLabels();
	}

	function updatePresetLabel() {
		var $lbl = $('.trend-preset-label');
		if (!$lbl.length) return;
		var def = state.preset ? PRESETS[state.preset] : null;
		$lbl.text(def ? def.label : 'Quick range');
		$('#trend-preset-toggle').toggleClass('has-preset', !!def);
	}

	// Auto-refresh timer — every 60s, refetch data. If a rolling preset is
	// active, re-evaluate it against current time so the window slides
	// forward.
	function startAutoRefresh() {
		if (state.refreshTimer) clearInterval(state.refreshTimer);
		state.refreshTimer = setInterval(function () {
			var def = state.preset ? PRESETS[state.preset] : null;
			if (def && def.rolling) {
				applyRange(def.calc(new Date()));
			}
			loadAndRender();
		}, 60 * 1000);
	}
	function startOfDay(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0); }
	function endOfDay(d)   { return new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23, 59, 59); }
	function startOfWeek(d) {
		var day = d.getDay();          // 0 = Sun
		var diff = (day === 0 ? -6 : 1 - day); // make Monday the start of week
		var s = new Date(d.getFullYear(), d.getMonth(), d.getDate() + diff, 0, 0, 0);
		return s;
	}

	// ---------- Pan mode (manual implementation) ------------------------
	// Apex has no "enter pan mode" public API when its own toolbar is
	// hidden, so we handle pan ourselves: drag → shift the visible x-range.
	// While pan is on, Apex's drag-to-zoom is disabled (otherwise both fire
	// at once and the chart re-zooms instead of panning).
	function togglePanMode(on) {
		state.panMode = !!on;
		$('#trend-pan').toggleClass('active', state.panMode);
		$('.trend-chart-wrap').toggleClass('trend-pan-mode', state.panMode);
		if (state.chart) {
			try {
				state.chart.updateOptions({ chart: { zoom: { enabled: !state.panMode, type: 'x' } } }, false, false, false);
			} catch (e) { /* ignore */ }
		}
	}

	function bindPanDrag() {
		var $wrap = $('.trend-chart-wrap');
		if (!$wrap.length) return;
		var drag = null;   // { startX, startMin, startMax }
		$wrap.on('mousedown', function (e) {
			if (!state.panMode || !state.chart) return;
			var g = state.chart.w && state.chart.w.globals;
			if (!g || !isFinite(g.minX) || !isFinite(g.maxX)) return;
			drag = { startX: e.clientX, startMin: g.minX, startMax: g.maxX, width: $wrap[0].clientWidth };
			e.preventDefault();
		});
		$(document).on('mousemove.trendpan', function (e) {
			if (!drag) return;
			var dx = e.clientX - drag.startX;
			var span = drag.startMax - drag.startMin;
			var dt = -(dx / drag.width) * span;
			state.chart.zoomX(drag.startMin + dt, drag.startMax + dt);
		});
		$(document).on('mouseup.trendpan', function () { drag = null; });
	}

	function zoomBy(factor) {
		if (!state.chart) return;
		var globals = state.chart.w && state.chart.w.globals;
		if (!globals) return;
		var minX = globals.minX, maxX = globals.maxX;
		if (!isFinite(minX) || !isFinite(maxX)) return;
		var mid = (minX + maxX) / 2;
		var half = (maxX - minX) * factor / 2;
		state.chart.zoomX(mid - half, mid + half);
	}

	function doExport(fmt) {
		if (!state.chart || !state.chart.exports) {
			console.warn('[trend] chart not ready for export');
			return;
		}
		var stamp = moment().format('YYYYMMDD_HHmmss');
		var base = TABLE + '-' + stamp;
		try {
			if (fmt === 'png') {
				state.chart.dataURI().then(function (uri) {
					triggerDownloadDataUri(uri.imgURI, base + '.png');
				});
			} else if (fmt === 'svg') {
				// ApexCharts has a built-in SVG export that triggers a download.
				state.chart.exports.exportToSVG(state.chart.ctx);
			} else if (fmt === 'csv') {
				// Custom CSV: ISO datetime + (unit) appended to each
				// numeric column header. Apex's built-in exporter only
				// emits date (no time) and has no hook for units.
				exportCsvWithUnits(base + '.csv');
			}
		} catch (e) {
			console.warn('[trend] export failed:', e);
		}
	}

	// Build CSV from state.rows (already in local-clock from the API).
	// One column per visible point. Float headers get the unit in
	// parentheses; bool headers get "(0/1)" so the value column meaning
	// is unambiguous.
	function exportCsvWithUnits(filename) {
		var rows = state.rows || [];
		var visibleFloats = state.floatCols.filter(function (c) { return state.visible[c.name]; });
		var visibleBools  = state.boolCols.filter(function (c) { return state.visible[c.name]; });
		var headers = ['datetime'];
		visibleFloats.forEach(function (c) {
			headers.push(c.name + (c.unit ? ' (' + c.unit + ')' : ''));
		});
		visibleBools.forEach(function (c) {
			headers.push(c.name + ' (0/1)');
		});
		var lines = [headers.map(csvCell).join(',')];
		rows.forEach(function (r) {
			var line = [csvCell(r.dt || '')];
			visibleFloats.forEach(function (c) {
				var v = r[c.name];
				line.push(v === null || typeof v === 'undefined' ? '' : csvCell(v));
			});
			visibleBools.forEach(function (c) {
				var v = r[c.name];
				if (v === null || typeof v === 'undefined' || v === '') line.push('');
				else line.push(csvCell((v === 1 || v === '1' || v === true || v === 'true') ? 1 : 0));
			});
			lines.push(line.join(','));
		});
		var csv = '﻿' + lines.join('\r\n');   // BOM so Excel reads UTF-8
		var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
		var url = URL.createObjectURL(blob);
		triggerDownloadDataUri(url, filename);
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	// Quote a CSV cell when it contains comma/quote/newline; double internal
	// quotes per RFC 4180.
	function csvCell(v) {
		if (v === null || typeof v === 'undefined') return '';
		var s = String(v);
		if (/[",\r\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
		return s;
	}

	function triggerDownloadDataUri(uri, filename) {
		var a = document.createElement('a');
		a.href = uri; a.download = filename;
		document.body.appendChild(a); a.click(); document.body.removeChild(a);
	}

	// ---------- Init ----------------------------------------------------
	$(function () {
		fetchPerms();
		// Fetch saved config first, then schema, then render. Config drives
		// default unit/state-text/visibility values so the UI is correct on
		// first render.
		fetchConfig().always(function () {
			loadSchema().done(function () {
				renderPointsList();
				bindPointsEvents();
				bindToolbar();
				bindPanDrag();
				initDateRange();
				// Apply ?preset / ?from / ?to AFTER initDateRange so URL
				// values override the default 7d window. Visibility was
				// already merged in loadSchema via readUrlVisibleSet().
				applyUrlRangeIfPresent();
				writeUrlState();   // canonicalize URL on first paint
				loadAndRender();
				startAutoRefresh();
			});
		});
	});

	// Read URL ?preset / ?from / ?to and apply on init. Preset wins if both
	// are present (matches the writeUrlState convention).
	function applyUrlRangeIfPresent() {
		var p = readUrlParams();
		if (p.preset && PRESETS[p.preset]) {
			state.preset = p.preset;
			updatePresetLabel();
			applyRange(PRESETS[p.preset].calc(new Date()));
			return;
		}
		if (p.from || p.to) {
			if (p.from) state.dateFrom = String(p.from);
			if (p.to)   state.dateTo   = String(p.to);
			// Push back into the picker widgets so the inputs reflect the URL.
			var fromDate = p.from ? moment(String(p.from), 'YYYY-MM-DD HH:mm:ss').toDate() : null;
			var toDate   = p.to   ? moment(String(p.to),   'YYYY-MM-DD HH:mm:ss').toDate() : null;
			setPickerValues(fromDate, toDate);
			updateLabels();
		}
	}
})(jQuery);
