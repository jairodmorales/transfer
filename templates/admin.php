<?php
/** @var array $_ */
/** @var int $_['maxUrls'] */
/** @var int $_['retentionDays'] */

use OCP\IL10N;

/** @var IL10N $l */
script('transfer', 'transfer-main');
?>

<div class="section">
	<h2><?php p($l->t('Transfer')); ?></h2>

	<p class="settings-hint">
		<?php p($l->t('Configure how many URLs a user can submit at once via the "Upload by link" dialog.')); ?>
	</p>

	<form id="transfer-admin-form">
		<div class="transfer-admin-row">
			<label for="transfer-max-urls"><?php p($l->t('Maximum URLs per dialog')); ?></label>
			<input
				id="transfer-max-urls"
				type="number"
				name="maxUrls"
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
				name="retentionDays"
				min="1"
				max="365"
				step="1"
				value="<?php p($_['retentionDays']); ?>"
			/>
			<em class="transfer-admin-hint">
				<?php p($l->t('Days to keep completed transfer records. Older rows are deleted automatically once a week.')); ?>
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
.transfer-admin-row label {
	font-weight: 600;
}
.transfer-admin-row input[type="number"] {
	width: 80px;
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
			msg.textContent = t('transfer', 'Maximum URLs must be between 1 and 10.');
			msg.className = 'msg error';
			msg.style.display = '';
			return;
		}

		var retentionDays = parseInt(document.getElementById('transfer-retention-days').value, 10);
		if (isNaN(retentionDays) || retentionDays < 1 || retentionDays > 365) {
			msg.textContent = t('transfer', 'Retention must be between 1 and 365 days.');
			msg.className = 'msg error';
			msg.style.display = '';
			return;
		}

		var saved = 0;
		function onSaved() {
			saved++;
			if (saved < 2) return;
			msg.textContent = t('transfer', 'Saved');
			msg.className = 'msg success';
			msg.style.display = '';
			setTimeout(function () { msg.style.display = 'none'; }, 3000);
		}
		function onError() {
			msg.textContent = t('transfer', 'Error saving settings.');
			msg.className = 'msg error';
			msg.style.display = '';
		}

		OCP.AppConfig.setValue('transfer', 'max_urls', String(maxUrls), { success: onSaved, error: onError });
		OCP.AppConfig.setValue('transfer', 'retention_days', String(retentionDays), { success: onSaved, error: onError });
	});
}());
</script>
