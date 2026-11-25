<?php
/** Restore confirmation panel, shared by the Import and Backups tabs. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			<section class="nlwm-card" id="nlwm-confirm" hidden>
				<h2>Ready to restore</h2>
				<dl class="nlwm-facts" id="nlwm-facts"></dl>
				<div class="nlwm-field" id="nlwm-pw-row" hidden>
		<label for="nlwm-restore-pw">🔒 This backup is encrypted. Password:</label>
		<input type="password" id="nlwm-restore-pw" class="nlwm-input" autocomplete="off">
	</div>
	<div class="nlwm-alert is-warn" id="nlwm-warn-newer" hidden>This backup comes from a newer WordPress version than this site. Update WordPress here first for the safest result.</div>
				<div class="nlwm-checks is-tight">
					<label class="nlwm-check"><input type="checkbox" id="nlwm-safety" checked><span><strong>Back up this site first</strong><small>Creates a restore point of the current site so you can undo.</small></span></label>
					<label class="nlwm-check"><input type="checkbox" id="nlwm-email"><span><strong>Change e-mail domains too</strong><small>e.g. info@old-domain → info@new-domain</small></span></label>
					<label class="nlwm-check"><input type="checkbox" id="nlwm-skip-files"><span><strong>Database only</strong><small>Keep the files that are on this site now.</small></span></label>
					<label class="nlwm-check"><input type="checkbox" id="nlwm-skip-db"><span><strong>Files only</strong><small>Keep this site's database.</small></span></label>
				</div>
				<div class="nlwm-alert is-warn">Everything on this site (posts, pages, users, settings, plugins, themes, media) is replaced by the backup. After the restore, log in with a user from the backup.</div>
				<label class="nlwm-check is-confirm"><input type="checkbox" id="nlwm-understand"><span><strong>I understand that this site will be overwritten.</strong></span></label>
				<div class="nlwm-row">
					<button type="button" class="nlwm-btn is-danger" id="nlwm-restore" disabled>Restore now</button>
					<button type="button" class="nlwm-btn is-ghost" id="nlwm-restore-cancel">Cancel</button>
				</div>
			</section>
