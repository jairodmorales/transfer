<?php
/** @var array $_ */
/** @var int $_['maxUrls'] */
/** @var int $_['retentionDays'] */
/** @var int $_['maxSizeMb'] */
/** @var string $_['domainBlocklist'] */

use OCP\IL10N;

/** @var IL10N $l */
?>

<div class="section">
	<h2><?php p($l->t('Transfer')); ?></h2>

	<form id="transfer-admin-form">
		<div class="transfer-admin-row">
			<label for="transfer-max-urls"><?php p($l->t('Maximum URLs per dialog')); ?></label>
			<input
				id="transfer-max-urls"
				type="number"
				min="1"
				max="10"
				step="1"
				value="<?php p($_['maxUrls']); ?>"
			/>
			<em class="transfer-admin-hint">
				<?php p($l->t('Between 1 and 10. Users will not be able to add more URL rows than this limit.')); ?>
			</em>
		</div>

		<div class="transfer-admin-row">
			<label for="transfer-retention-days"><?php p($l->t('Job history retention')); ?></label>
			<input
				id="transfer-retention-days"
				type="number"
				min="1"
				max="365"
				step="1"
				value="<?php p($_['retentionDays']); ?>"
			/>
			<em class="transfer-admin-hint">
				<?php p($l->t('Days to keep completed transfer records. Older rows are deleted automatically once a week.')); ?>
			</em>
		</div>

		<div class="transfer-admin-row">
			<label for="transfer-max-size-mb"><?php p($l->t('Maximum file size (MB)')); ?></label>
			<input
				id="transfer-max-size-mb"
				type="number"
				min="0"
				max="102400"
				step="1"
				value="<?php p($_['maxSizeMb']); ?>"
			/>
			<em class="transfer-admin-hint">
				<?php p($l->t('Maximum size in MB a user may download in a single transfer. 0 means no limit.')); ?>
			</em>
		</div>

		<div class="transfer-admin-row transfer-admin-row--wide">
			<label for="transfer-domain-blocklist"><?php p($l->t('Blocked domains')); ?></label>
			<textarea
				id="transfer-domain-blocklist"
				rows="6"
				placeholder="evil.com&#10;*.malicious.org"
			><?php p($_['domainBlocklist']); ?></textarea>
			<em class="transfer-admin-hint">
				<?php p($l->t('One domain per line. Use *.example.com to block all subdomains. Users cannot download from these domains.')); ?>
			</em>
		</div>

		<input
			type="submit"
			class="button"
			value="<?php p($l->t('Save')); ?>"
		/>
		<span id="transfer-admin-msg" class="msg" style="display:none"></span>
	</form>
</div>

<style>
.transfer-admin-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 320px;
	margin-bottom: 16px;
}
.transfer-admin-row--wide {
	max-width: 480px;
}
.transfer-admin-row label {
	font-weight: 600;
}
.transfer-admin-row input[type="number"] {
	width: 80px;
}
.transfer-admin-row textarea {
	width: 100%;
	font-family: monospace;
	font-size: 0.875em;
	resize: vertical;
}
.transfer-admin-hint {
	font-size: 0.875em;
	color: var(--color-text-maxcontrast, #767676);
}
</style>

<script>
(function () {
	var form = document.getElementById('transfer-admin-form');
	var msg  = document.getElementById('transfer-admin-msg');

	form.addEventListener('submit', function (e) {
		e.preventDefault();

		var maxUrls = parseInt(document.getElementById('transfer-max-urls').value, 10);
		if (isNaN(maxUrls) || maxUrls < 1 || maxUrls > 10) {
			showMsg(t('transfer', 'Maximum URLs must be between 1 and 10.'), 'error');
			return;
		}

		var retentionDays = parseInt(document.getElementById('transfer-retention-days').value, 10);
		if (isNaN(retentionDays) || retentionDays < 1 || retentionDays > 365) {
			showMsg(t('transfer', 'Retention must be between 1 and 365 days.'), 'error');
			return;
		}

		var maxSizeMb = parseInt(document.getElementById('transfer-max-size-mb').value, 10);
		if (isNaN(maxSizeMb) || maxSizeMb < 0 || maxSizeMb > 102400) {
			showMsg(t('transfer', 'Maximum file size must be between 0 and 102400 MB.'), 'error');
			return;
		}

		var domainBlocklist = document.getElementById('transfer-domain-blocklist').value;

		var settings = [
			['max_urls',         String(maxUrls)],
			['retention_days',   String(retentionDays)],
			['max_size_mb',      String(maxSizeMb)],
			['domain_blocklist', domainBlocklist],
		];

		var saved = 0, failed = false;

		function onSaved() {
			if (failed) return;
			if (++saved < settings.length) return;
			showMsg(t('transfer', 'Saved'), 'success');
			setTimeout(function () { msg.style.display = 'none'; }, 3000);
		}

		function onError() {
			if (failed) return;
			failed = true;
			showMsg(t('transfer', 'Error saving settings.'), 'error');
		}

		settings.forEach(function (pair) {
			OCP.AppConfig.setValue('transfer', pair[0], pair[1], { success: onSaved, error: onError });
		});
	});

	function showMsg(text, type) {
		msg.textContent = text;
		msg.className = 'msg ' + type;
		msg.style.display = '';
	}
}());
</script>
