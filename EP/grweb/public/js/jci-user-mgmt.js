/*
 * JCI-EP-WEB-Patch — patch-permissies UI binnen het Manage modal.
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
 * Per-user feature-permission UI hooks for the native Manage modal
 * (Backbone-rendered).
 *
 * Three checkboxes are injected into account_details_tpl on alarmdb.php /
 * trend.php / graphic.php (see PHP for the markup):
 *   - alarm_ack     : may acknowledge alarms
 *   - alarm_delete  : may delete alarms
 *   - trend_edit    : may edit per-table units / state texts in trend page
 *
 * Behaviour:
 *   - On modal open OR account-tab switch, fetch the full perms map from
 *     user_perms_api.php?cmd=get-all (admin-only). For non-admins the call
 *     fails 403 and we leave the defaults (all checked) in place.
 *   - Checkboxes default to checked at template render time so a user with
 *     no entry in user_perms.json reads "all allowed" (which matches what
 *     the server enforces).
 *   - On change of any checkbox, POST cmd=save with the new value. On
 *     failure, revert the checkbox.
 *
 * The native "Update" button on the panel doesn't touch our flags — it
 * still posts the original JCI account fields. We persist independently
 * per-toggle so the user gets immediate feedback.
 */
(function ($) {
	'use strict';
	if (!window.jQuery) return;
	if (!$('#accountManagementModal').length) return;

	var API = '../app/user_perms_api.php';
	// Pages that live under app/ (alarmdb.php, trend.php, graphic.php) all
	// resolve `../app/...` correctly because they're served from app/ already
	// (so `../app/` = same dir). Keep the `../app/` prefix so the script also
	// works if it's ever loaded from a different directory.

	// Non-admins must not see (let alone toggle) the patch-permission
	// checkboxes — the API rejects their writes anyway, so the UI would be
	// misleading. cur_account_info.is_admin is set in the page header.
	var IS_ADMIN = !!(window.cur_account_info && window.cur_account_info.is_admin);
	if (!IS_ADMIN) {
		// Hide the section whenever it appears (re-render-safe).
		var style = document.createElement('style');
		style.type = 'text/css';
		style.appendChild(document.createTextNode('.jci-perms-group { display: none !important; }'));
		document.head.appendChild(style);
		return;
	}

	var cache = null;       // { <user_id>: { alarm_ack, alarm_delete, trend_edit } }
	var fetched = false;    // we've made at least one get-all call
	var fetching = false;

	function fetchPerms(cb) {
		if (fetched) { cb && cb(cache); return; }
		if (fetching) {
			// Stack callbacks while a fetch is in flight.
			$(document).one('jci-perms-fetched', function () { cb && cb(cache); });
			return;
		}
		fetching = true;
		$.ajax({
			url: API + '?cmd=get-all',
			dataType: 'json',
			cache: false
		}).done(function (data) {
			cache = (data && data.perms) || {};
		}).fail(function () {
			// Non-admin or transient error — UI defaults stay (checked).
			cache = {};
		}).always(function () {
			fetched = true;
			fetching = false;
			$(document).trigger('jci-perms-fetched');
			cb && cb(cache);
		});
	}

	function applyToCheckboxes() {
		fetchPerms(function (perms) {
			$('.jci-perm').each(function () {
				var $cb = $(this);
				var uid = String($cb.data('userid'));
				var key = $cb.data('perm');
				if (!uid || !key) return;
				var rec = perms[uid] || {};
				// Missing key in JSON store = default true.
				var val = (key in rec) ? !!rec[key] : true;
				$cb.prop('checked', val);
			});
		});
	}

	// Bootstrap 2 modals fire 'shown' (no namespace), Bootstrap 3 fires
	// 'shown.bs.modal' / 'shown.bs.tab'. Bind both to be safe across pages.
	$(document).on('shown shown.bs.modal shown.bs.tab', function () {
		applyToCheckboxes();
	});

	// The Backbone view re-renders #accountsPanel when the user switches
	// between account name-tabs in the left rail. Catch those re-renders so
	// the freshly-rendered checkboxes get populated even if no Bootstrap
	// event fires for the inner tab swap.
	if (window.MutationObserver) {
		$(function () {
			var pane = document.getElementById('accountsPanel');
			if (!pane) return;
			var pending = false;
			var mo = new MutationObserver(function () {
				// Coalesce bursts of mutations into one apply.
				if (pending) return;
				pending = true;
				setTimeout(function () {
					pending = false;
					applyToCheckboxes();
				}, 0);
			});
			mo.observe(pane, { childList: true, subtree: true });
		});
	}

	// Auto-save on toggle. Reverts checkbox state on failure.
	$(document).on('change', '.jci-perm', function () {
		var $cb = $(this);
		var uid = String($cb.data('userid'));
		var key = $cb.data('perm');
		if (!uid || !key) return;
		var val = $cb.is(':checked');

		var data = { cmd: 'save', user_id: uid };
		data[key] = val ? 'true' : 'false';

		$cb.prop('disabled', true);
		$.ajax({
			url: API,
			type: 'POST',
			dataType: 'json',
			data: data
		}).done(function (res) {
			if (res && res.ok && res.perms) {
				if (!cache) cache = {};
				cache[uid] = res.perms;
			} else {
				$cb.prop('checked', !val);
			}
		}).fail(function () {
			$cb.prop('checked', !val);
		}).always(function () {
			$cb.prop('disabled', false);
		});
	});
})(jQuery);
