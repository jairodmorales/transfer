<?php
/** @var array $_ */
/** @var int $_['maxUrls'] */

use OCP\Defaults;
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
		var val = parseInt(document.getElementById('transfer-max-urls').value, 10);
		if (isNaN(val) || val < 1 || val > 10) {
			msg.textContent = t('transfer', 'Value must be between 1 and 10.');
			msg.className = 'msg error';
			msg.style.display = '';
			return;
		}

		OCP.AppConfig.setValue('transfer', 'max_urls', String(val), {
			success: function () {
				msg.textContent = t('transfer', 'Saved');
				msg.className = 'msg success';
				msg.style.display = '';
				setTimeout(function () { msg.style.display = 'none'; }, 3000);
			},
			error: function () {
				msg.textContent = t('transfer', 'Error saving settings.');
				msg.className = 'msg error';
				msg.style.display = '';
			},
		});
	});
}());
</script>
