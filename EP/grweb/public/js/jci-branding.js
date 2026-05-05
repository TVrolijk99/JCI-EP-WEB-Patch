/*
 * JCI-EP-WEB-Patch — topbar-branding editor (admin only).
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
 * Pencil button next to the topbar title opens #brandingEditModal
 * (rendered server-side only for admins). Inside:
 *   - Title input (auto-saves on Save click).
 *   - File input — uploads via multipart/form-data on Save click.
 *   - Delete logo button — clears the stored file.
 *
 * Live updates the #logo .jci-brand-title and .jci-brand-logo so the
 * admin sees the change without a reload.
 */
(function ($) {
	'use strict';
	if (!window.jQuery) return;
	if (!$('#brandingEditModal').length) return;   // non-admin or missing markup

	var API = '../app/branding_api.php';

	// In-memory cache of the last-known server state. Used to revert
	// the modal fields if the user opens the modal, edits, then cancels.
	var current = { title: '', logo_url: '' };

	function setMsg(text, isError) {
		var $msg = $('#brandingMsg');
		if (!text) { $msg.hide().text(''); return; }
		$msg.text(text)
		    .toggleClass('jci-branding-msg-error', !!isError)
		    .toggleClass('jci-branding-msg-ok', !isError)
		    .show();
	}

	// Apply branding to the topbar (live update without reload).
	function applyToTopbar(branding) {
		var $logo  = $('#logo');
		var $title = $logo.find('.jci-brand-title');
		var $img   = $logo.find('.jci-brand-logo');

		$title.text(branding.title || '');
		$logo.attr('data-app-title', branding.title || '');

		if (branding.logo_url) {
			if (!$img.length) {
				$img = $('<img class="jci-brand-logo" alt="">').prependTo($logo);
			}
			$img.attr('src', branding.logo_url).show();
		} else {
			$img.remove();
		}
	}

	function applyToModal(branding) {
		$('#brandingTitleInput').val(branding.title || '');
		var $preview = $('#brandingLogoPreview');
		var $empty   = $('#brandingLogoEmpty');
		var $del     = $('#brandingLogoDelete');
		if (branding.logo_url) {
			$preview.attr('src', branding.logo_url).show();
			if (branding.logo_is_custom) {
				$empty.hide();
				$del.prop('disabled', false);
			} else {
				$empty.text('Standaard logo').show();
				$del.prop('disabled', true);
			}
		} else {
			$preview.removeAttr('src').hide();
			$empty.text('Geen logo').show();
			$del.prop('disabled', true);
		}
		$('#brandingLogoFile').val('');
		setMsg('');
	}

	function fetchBranding(cb) {
		$.ajax({
			url: API + '?cmd=get',
			dataType: 'json',
			cache: false
		}).done(function (data) {
			if (data && data.branding) {
				current = data.branding;
				cb && cb(null, current);
			} else {
				cb && cb('bad response');
			}
		}).fail(function (xhr) {
			cb && cb((xhr && xhr.responseJSON && xhr.responseJSON.error) || 'fetch failed');
		});
	}

	function postTitle(title, cb) {
		$.ajax({
			url: API,
			type: 'POST',
			dataType: 'json',
			data: { cmd: 'save-title', title: title }
		}).done(function (res) {
			if (res && res.ok) cb && cb(null, res.branding);
			else cb && cb((res && res.error) || 'save failed');
		}).fail(function (xhr) {
			cb && cb((xhr && xhr.responseJSON && xhr.responseJSON.error) || 'save failed');
		});
	}

	function uploadLogo(file, cb) {
		var fd = new FormData();
		fd.append('cmd', 'upload-logo');
		fd.append('logo', file);
		$.ajax({
			url: API,
			type: 'POST',
			dataType: 'json',
			data: fd,
			processData: false,
			contentType: false
		}).done(function (res) {
			if (res && res.ok) cb && cb(null, res.branding);
			else cb && cb((res && res.error) || 'upload failed');
		}).fail(function (xhr) {
			cb && cb((xhr && xhr.responseJSON && xhr.responseJSON.error) || 'upload failed');
		});
	}

	function deleteLogo(cb) {
		$.ajax({
			url: API,
			type: 'POST',
			dataType: 'json',
			data: { cmd: 'delete-logo' }
		}).done(function (res) {
			if (res && res.ok) cb && cb(null, res.branding);
			else cb && cb((res && res.error) || 'delete failed');
		}).fail(function (xhr) {
			cb && cb((xhr && xhr.responseJSON && xhr.responseJSON.error) || 'delete failed');
		});
	}

	// --- Wiring -----------------------------------------------------------

	// Populate modal whenever it opens. Catches both BS3
	// `shown.bs.modal` and BS2 `shown` (legacy plugin pages still load
	// BS2 from user.css). Also fetch fresh state in case another tab
	// changed it.
	$(document).on('show shown.bs.modal show.bs.modal', '#brandingEditModal', function () {
		fetchBranding(function (err, b) {
			if (err) { setMsg(err, true); return; }
			applyToModal(b);
		});
	});

	// Save button: persist title (always) + upload file if one was
	// chosen. Visibility/file-only and title-only flows share the same
	// button. We chain title -> upload so the order is deterministic.
	$(document).on('click', '#brandingSaveBtn', function () {
		var title = $.trim($('#brandingTitleInput').val() || '');
		if (!title) { setMsg('Titel mag niet leeg zijn', true); return; }

		var $btn = $(this).prop('disabled', true);
		setMsg('Bezig met opslaan…', false);

		postTitle(title, function (err, b) {
			if (err) { $btn.prop('disabled', false); setMsg(err, true); return; }
			current = b;
			applyToTopbar(b);

			var file = $('#brandingLogoFile')[0].files[0];
			if (!file) {
				$btn.prop('disabled', false);
				applyToModal(b);
				setMsg('Opgeslagen', false);
				return;
			}
			uploadLogo(file, function (err2, b2) {
				$btn.prop('disabled', false);
				if (err2) { setMsg(err2, true); return; }
				current = b2;
				applyToTopbar(b2);
				applyToModal(b2);
				setMsg('Opgeslagen', false);
			});
		});
	});

	// Delete current logo (no confirm — admin-only and trivially undone
	// by re-uploading).
	$(document).on('click', '#brandingLogoDelete', function () {
		var $btn = $(this).prop('disabled', true);
		setMsg('Bezig met verwijderen…', false);
		deleteLogo(function (err, b) {
			$btn.prop('disabled', false);
			if (err) { setMsg(err, true); return; }
			current = b;
			applyToTopbar(b);
			applyToModal(b);
			setMsg('Logo verwijderd', false);
		});
	});

	// Live preview of the chosen file before save.
	$(document).on('change', '#brandingLogoFile', function () {
		var file = this.files && this.files[0];
		if (!file) return;
		if (!window.FileReader) return;
		var reader = new FileReader();
		reader.onload = function (e) {
			$('#brandingLogoPreview').attr('src', e.target.result).show();
			$('#brandingLogoEmpty').hide();
		};
		reader.readAsDataURL(file);
	});

	// Initial fetch so the in-memory cache is warm. Server-rendered
	// HTML already shows the right values; this just keeps `current`
	// up to date for the modal.
	fetchBranding(function () {});
})(jQuery);
