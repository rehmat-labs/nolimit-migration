<?php
/**
 * Main admin page.
 *
 * @var string $tab
 * @var string $nlwm_dir_error
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nlwm_settings = NLWM_Util::settings();
$nlwm_tables   = array();
try {
	$nlwm_db     = new NLWM_Database();
	$nlwm_tables = $nlwm_db->table_stats();
} catch ( Exception $e ) {
	$nlwm_dir_error = $nlwm_dir_error ? $nlwm_dir_error : $e->getMessage();
}
$nlwm_free    = NLWM_Util::free_space( NLWM_Util::backups_dir() );
$nlwm_next    = NLWM_Schedule::next_run();
$nlwm_base    = admin_url( 'admin.php?page=' . NLWM_SLUG );
$nlwm_tabs    = NLWM_Admin::tabs();
$nlwm_clouds    = NLWM_Storages::connected();
$nlwm_pro       = (bool) apply_filters( 'nlwm_pro_active', false );
$nlwm_brand     = NLWM_Util::brand();
$nlwm_exclude = array(
	'no_spam'             => array( 'Spam & trashed comments', 'Skip spam and trashed comments' ),
	'no_revisions'        => array( 'Post revisions', 'Skip old post revisions (often 30–60% of the posts table)' ),
	'no_transients'       => array( 'Transients', 'Skip temporary cached data stored in the options table' ),
	'no_wc_logs'          => array( 'WooCommerce sessions & logs', 'Skip shopper sessions and the history of finished background tasks. Orders, products and customers are kept' ),
	'no_media'           => array( 'Media library', 'Skip wp-content/uploads' ),
	'no_themes'           => array( 'All themes', '' ),
	'no_inactive_themes'  => array( 'Inactive themes', 'Keep only the active theme (and its parent)' ),
	'no_plugins'          => array( 'All plugins', '' ),
	'no_inactive_plugins' => array( 'Inactive plugins', '' ),
	'no_muplugins'        => array( 'Must-use plugins', '' ),
	'no_cache'            => array( 'Cache folders', 'Skip page-cache and builder CSS cache folders (they rebuild themselves)' ),
	'no_database'         => array( 'Database', 'Files only, no database' ),
);
// The WooCommerce switch only makes sense on a shop.
if ( ! class_exists( 'WooCommerce' ) ) {
	unset( $nlwm_exclude['no_wc_logs'] );
}
?>
<div class="wrap nlwm" id="nlwm">
	<h1 class="screen-reader-text"><?php echo esc_html( $nlwm_brand['name'] ); ?></h1>

	<header class="nlwm-head">
		<div class="nlwm-brand">
			<img src="<?php echo esc_url( $nlwm_brand['logo'] ); ?>" alt="" width="44" height="44">
			<div>
				<?php if ( $nlwm_brand['white_label'] ) : ?>
					<div class="nlwm-title"><?php echo esc_html( $nlwm_brand['name'] ); ?></div>
				<?php else : ?>
					<div class="nlwm-title">NoLimit <span class="nlwm-grad-text">Migration</span><?php echo $nlwm_pro ? ' <span class="nlwm-pro-badge">PRO</span>' : ''; ?></div>
				<?php endif; ?>
				<div class="nlwm-sub"><?php echo esc_html( $nlwm_brand['tagline'] ); ?></div>
			</div>
		</div>
		<nav class="nlwm-tabs" aria-label="Sections">
			<?php foreach ( $nlwm_tabs as $nlwm_key => $nlwm_label ) : ?>
				<a href="<?php echo esc_url( 'export' === $nlwm_key ? $nlwm_base : $nlwm_base . '&tab=' . $nlwm_key ); ?>" class="<?php echo $tab === $nlwm_key ? 'is-active' : ''; ?>" <?php echo $tab === $nlwm_key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $nlwm_label ); ?></a>
			<?php endforeach; ?>
		</nav>
	</header>

	<?php if ( $nlwm_dir_error ) : ?>
		<div class="nlwm-alert is-error"><?php echo esc_html( $nlwm_dir_error ); ?></div>
	<?php endif; ?>

	<div class="nlwm-grid">
		<main class="nlwm-main">

		<?php if ( 'export' === $tab ) : ?>
			<section class="nlwm-card">
				<h2>Create a backup</h2>
				<p class="nlwm-muted">Packs your database, media, themes and plugins into one <code>.<?php echo esc_html( NLWM_EXT ); ?></code> file. Use it to restore this site or to move it to another domain or host.</p>
				<div class="nlwm-row">
					<input type="text" id="nlwm-label" class="nlwm-input" placeholder="Label (optional), e.g. Before theme update" maxlength="80">
					<button type="button" class="nlwm-btn is-primary" id="nlwm-export">Create backup</button>
				</div>
				<?php if ( $nlwm_clouds ) : ?>
					<div class="nlwm-inline nlwm-targets">
						<span>Also upload to:</span>
						<?php foreach ( $nlwm_clouds as $nlwm_cloud ) : ?>
							<label><input type="checkbox" name="nlwm_storage" value="<?php echo esc_attr( $nlwm_cloud->id() ); ?>" <?php checked( 'local' !== $nlwm_settings['storage'] ); ?>> <?php echo esc_html( $nlwm_cloud->label() ); ?></label>
						<?php endforeach; ?>
						<?php if ( 'remote' === $nlwm_settings['storage'] ) : ?><small>(the local copy is removed after upload)</small><?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( NLWM_Archive::can_seal() ) : ?>
					<label class="nlwm-inline">Encrypt with password <input type="password" id="nlwm-password" class="nlwm-input" autocomplete="new-password" placeholder="optional, AES-256"></label>
				<?php elseif ( ! $nlwm_pro ) : ?>
					<p class="nlwm-muted nlwm-small">🔒 Google Drive &amp; S3 cloud backups, password encryption and hourly schedules are in <a href="<?php echo esc_url( NLWM_Util::site_url( 'pricing', 'export' ) ); ?>" target="_blank" rel="noopener">NoLimit Pro</a>.</p>
				<?php endif; ?>

				<details class="nlwm-adv">
					<summary>Advanced options</summary>

					<h3>Leave out</h3>
					<div class="nlwm-checks">
						<?php foreach ( $nlwm_exclude as $nlwm_key => $nlwm_item ) : ?>
							<label class="nlwm-check">
								<input type="checkbox" name="exclude" value="<?php echo esc_attr( $nlwm_key ); ?>">
								<span><strong><?php echo esc_html( $nlwm_item[0] ); ?></strong><?php if ( $nlwm_item[1] ) : ?><small><?php echo esc_html( $nlwm_item[1] ); ?></small><?php endif; ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<h3>Find &amp; replace in the backup</h3>
					<p class="nlwm-muted">Applied when the backup is restored, on top of the automatic URL and path change. Serialized and JSON data stay valid.</p>
					<div id="nlwm-replace"></div>
					<button type="button" class="nlwm-btn is-ghost is-small" id="nlwm-add-replace">+ Add find &amp; replace</button>

					<h3>Exclude files</h3>
					<textarea id="nlwm-exclude-paths" class="nlwm-input" rows="3" placeholder="One per line, relative to wp-content. Wildcards allowed, e.g.&#10;uploads/2019&#10;*.log&#10;plugins/huge-plugin"></textarea>
					<label class="nlwm-inline">Skip files larger than <input type="number" id="nlwm-max-mb" class="nlwm-input is-num" min="0" value="0"> MB <small>(0 = no limit)</small></label>

					<?php if ( $nlwm_tables ) : ?>
						<h3>Exclude database tables</h3>
						<div class="nlwm-tables">
							<?php foreach ( $nlwm_tables as $nlwm_t => $nlwm_size ) : ?>
								<label><input type="checkbox" name="exclude_table" value="<?php echo esc_attr( $nlwm_t ); ?>"> <code><?php echo esc_html( $nlwm_t ); ?></code> <small><?php echo esc_html( NLWM_Util::format_bytes( $nlwm_size ) ); ?></small></label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</details>
			</section>

		<?php elseif ( 'import' === $tab ) : ?>
			<section class="nlwm-card">
				<h2>Restore or migrate a site</h2>
				<p class="nlwm-muted">Bring in a backup made with this plugin (<code>.<?php echo esc_html( NLWM_EXT ); ?></code>). URLs and paths are switched to this site automatically.</p>

				<div class="nlwm-sources">
					<div class="nlwm-drop" id="nlwm-drop" tabindex="0" role="button" aria-label="Choose a backup file to upload">
						<input type="file" id="nlwm-file" hidden>
						<div class="nlwm-drop-icon" aria-hidden="true">⬆</div>
						<strong>Drop a backup file here</strong>
						<span>or click to choose. Any size: the file is sent in <?php echo esc_html( NLWM_Util::format_bytes( NLWM_Util::max_chunk_bytes() ) ); ?> pieces, so your host's upload limit does not matter.</span>
					</div>

					<div class="nlwm-url">
						<h3>Import from URL</h3>
						<p class="nlwm-muted">Paste a direct download link to a backup file, for example a transfer link from another site running NoLimit Migration. The file is pulled server-to-server, so nothing goes through your computer.</p>
						<div class="nlwm-row">
							<input type="url" id="nlwm-url" class="nlwm-input" placeholder="https://…">
							<button type="button" class="nlwm-btn" id="nlwm-fetch">Fetch backup</button>
						</div>
					</div>
				</div>
				<p class="nlwm-muted">Already on this server? Pick it from <a href="<?php echo esc_url( $nlwm_base . '&tab=backups' ); ?>">Backups</a> and press Restore.</p>
			</section>

			<?php include NLWM_DIR . '/views/confirm.php'; ?>

		<?php elseif ( 'backups' === $tab ) : ?>
			<section class="nlwm-card">
				<div class="nlwm-card-head">
					<h2>Backups on this server</h2>
					<a class="nlwm-btn is-small" href="<?php echo esc_url( $nlwm_base ); ?>">New backup</a>
				</div>
				<div id="nlwm-backups" class="nlwm-backups" aria-live="polite"><p class="nlwm-muted">Loading…</p></div>
				<?php if ( $nlwm_clouds ) : ?>
					<div class="nlwm-card-head nlwm-drive-head">
						<h2>In the cloud</h2>
						<span class="nlwm-inline">
							<select id="nlwm-cloud-pick" class="nlwm-input" aria-label="Cloud storage">
								<?php foreach ( $nlwm_clouds as $nlwm_cloud ) : ?>
									<option value="<?php echo esc_attr( $nlwm_cloud->id() ); ?>"><?php echo esc_html( $nlwm_cloud->label() ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="nlwm-btn is-small is-ghost" id="nlwm-drive-refresh">Refresh</button>
						</span>
					</div>
					<div id="nlwm-drive-files" class="nlwm-backups" aria-live="polite"><p class="nlwm-muted">Loading…</p></div>
				<?php endif; ?>
				<p class="nlwm-muted nlwm-small">Stored in <code><?php echo esc_html( str_replace( ABSPATH, '', NLWM_Util::backups_dir() ) ); ?></code>, locked against direct web access. <strong>Transfer link</strong> creates a private, expiring link: paste it into <em>Restore / Import → Import from URL</em> on the new site.</p>
			</section>

			<?php include NLWM_DIR . '/views/confirm.php'; ?>

		<?php elseif ( 'drive' === $tab ) : ?>
			<?php do_action( 'nlwm_storage_settings' ); ?>
			<?php if ( ! $nlwm_pro ) : ?>
				<section class="nlwm-card nlwm-pro-card">
					<h2>Cloud backups with NoLimit Pro</h2>
					<p class="nlwm-muted">Send backups automatically to Google Drive, Amazon S3, Cloudflare R2, Backblaze B2, Wasabi or DigitalOcean Spaces, and restore straight from there — plus password-encrypted backups and hourly schedules.</p>
					<a class="nlwm-btn is-primary" href="<?php echo esc_url( NLWM_Util::site_url( 'pricing', 'storage' ) ); ?>" target="_blank" rel="noopener">See Pro plans</a>
				</section>
			<?php endif; ?>

			<section class="nlwm-card">
				<h2>Where backups are kept</h2>
				<form id="nlwm-drive-settings" class="nlwm-form">
					<fieldset class="nlwm-field">
						<?php foreach ( array(
							'local' => array( 'This server only', 'Backups stay in wp-content on this server.' ),
							'both'   => array( 'Cloud + this server', 'Upload every backup to your connected cloud storage and keep a local copy for fast restores.' ),
							'remote' => array( 'Cloud only', 'Upload, then delete the local copy to save disk space. Restoring downloads it from the cloud first.' ),
						) as $nlwm_key => $nlwm_item ) : ?>
							<label class="nlwm-check"><input type="radio" name="storage" value="<?php echo esc_attr( $nlwm_key ); ?>" <?php checked( $nlwm_settings['storage'], $nlwm_key ); ?> <?php disabled( ! $nlwm_clouds && 'local' !== $nlwm_key ); ?>><span><strong><?php echo esc_html( $nlwm_item[0] ); ?></strong><small><?php echo esc_html( $nlwm_item[1] ); ?></small></span></label>
						<?php endforeach; ?>
					</fieldset>
					<div class="nlwm-field">
						<label for="nlwm-dret">Keep in the cloud</label>
						<span class="nlwm-inline"><input type="number" id="nlwm-dret" name="drive_retention" class="nlwm-input is-num" min="1" max="100" value="<?php echo esc_attr( $nlwm_settings['drive_retention'] ); ?>"> newest scheduled backups <small>(manual backups are never deleted)</small></span>
					</div>
					<div class="nlwm-row"><button type="submit" class="nlwm-btn is-primary">Save</button><span class="nlwm-muted" id="nlwm-drive-saved"></span></div>
				</form>
			</section>

		<?php elseif ( 'schedule' !== $tab ) : ?>
			<?php do_action( 'nlwm_admin_tab_' . $tab ); ?>
		<?php else : ?>
			<section class="nlwm-card">
				<h2>Automatic backups</h2>
				<form id="nlwm-settings" class="nlwm-form">
					<div class="nlwm-field">
						<label for="nlwm-schedule">How often</label>
						<select id="nlwm-schedule" name="schedule" class="nlwm-input">
							<?php foreach ( NLWM_Schedule::frequencies() as $nlwm_key => $nlwm_label ) : ?>
								<option value="<?php echo esc_attr( $nlwm_key ); ?>" <?php selected( $nlwm_settings['schedule'], $nlwm_key ); ?>><?php echo esc_html( $nlwm_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="nlwm-field">
						<label for="nlwm-time">Start time (site time zone)</label>
						<input type="time" id="nlwm-time" name="schedule_time" class="nlwm-input" value="<?php echo esc_attr( $nlwm_settings['schedule_time'] ); ?>">
					</div>
					<div class="nlwm-field">
						<label for="nlwm-retention">Keep the newest</label>
						<span class="nlwm-inline"><input type="number" id="nlwm-retention" name="retention" class="nlwm-input is-num" min="1" max="100" value="<?php echo esc_attr( $nlwm_settings['retention'] ); ?>"> scheduled backups <small>(older ones are deleted; locked and manual backups are never touched)</small></span>
					</div>
					<div class="nlwm-field">
						<label for="nlwm-notify">E-mail report to</label>
						<span class="nlwm-inline">
							<input type="email" id="nlwm-notify" name="notify_email" class="nlwm-input" value="<?php echo esc_attr( $nlwm_settings['notify_email'] ); ?>" placeholder="you@example.com">
							<select name="notify_on" class="nlwm-input" aria-label="When to send">
								<option value="failure" <?php selected( $nlwm_settings['notify_on'], 'failure' ); ?>>only on failure</option>
								<option value="always" <?php selected( $nlwm_settings['notify_on'], 'always' ); ?>>after every backup</option>
							</select>
						</span>
					</div>
					<fieldset class="nlwm-field">
						<legend>Leave out of scheduled backups</legend>
						<div class="nlwm-checks">
							<?php foreach ( $nlwm_exclude as $nlwm_key => $nlwm_item ) : ?>
								<?php if ( 'no_database' === $nlwm_key ) { continue; } ?>
								<label class="nlwm-check"><input type="checkbox" name="exclude[]" value="<?php echo esc_attr( $nlwm_key ); ?>" <?php checked( in_array( $nlwm_key, (array) $nlwm_settings['exclude'], true ) ); ?>><span><strong><?php echo esc_html( $nlwm_item[0] ); ?></strong></span></label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<div class="nlwm-field">
						<label for="nlwm-sched-paths">Exclude files</label>
						<textarea id="nlwm-sched-paths" name="exclude_paths" rows="3" class="nlwm-input" placeholder="One per line, relative to wp-content"><?php echo esc_textarea( $nlwm_settings['exclude_paths'] ); ?></textarea>
					</div>
					<div class="nlwm-field">
						<label for="nlwm-sched-max">Skip files larger than</label>
						<span class="nlwm-inline"><input type="number" id="nlwm-sched-max" name="max_file_mb" class="nlwm-input is-num" min="0" value="<?php echo esc_attr( $nlwm_settings['max_file_mb'] ); ?>"> MB</span>
					</div>
					<div class="nlwm-row">
						<button type="submit" class="nlwm-btn is-primary">Save schedule</button>
						<span class="nlwm-muted" id="nlwm-next"><?php echo $nlwm_next ? esc_html( 'Next run: ' . wp_date( 'j M Y, H:i', $nlwm_next ) ) : ''; ?></span>
					</div>
				</form>
				<p class="nlwm-muted nlwm-small">Scheduled backups use WP-Cron, which runs when someone visits the site. On quiet sites, add a real cron job that calls <code>wp-cron.php</code>, or run <code>wp nolimit backup</code> from the server's crontab.</p>
			</section>
		<?php endif; ?>

		</main>

		<aside class="nlwm-side">
			<div class="nlwm-card is-side">
				<h3>Server check</h3>
				<ul class="nlwm-sys">
					<li><span>PHP</span><strong><?php echo esc_html( PHP_VERSION ); ?></strong></li>
					<li><span>Memory limit</span><strong><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></strong></li>
					<li><span>Max execution</span><strong><?php echo esc_html( ini_get( 'max_execution_time' ) ? ini_get( 'max_execution_time' ) . ' s' : 'unlimited' ); ?></strong></li>
					<li><span>Host upload limit</span><strong><?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?> <em class="nlwm-ok">bypassed</em></strong></li>
					<li><span>Database</span><strong><?php echo esc_html( isset( $nlwm_db ) ? $nlwm_db->version() : '?' ); ?></strong></li>
					<li><span>Free disk</span><strong><?php echo esc_html( null === $nlwm_free ? 'not reported by host' : NLWM_Util::format_bytes( $nlwm_free ) ); ?></strong></li>
					<li><span>Schedule</span><strong><?php echo esc_html( $nlwm_next ? wp_date( 'j M, H:i', $nlwm_next ) : 'off' ); ?></strong></li>
					<li><span>Cloud storage</span><strong><?php echo esc_html( $nlwm_clouds ? implode( ', ', array_map( function ( $c ) { return $c->label(); }, $nlwm_clouds ) ) : 'not connected' ); ?></strong></li>
				</ul>
			</div>
			<div class="nlwm-card is-side">
				<h3>Moving a site in 3 steps</h3>
				<ol class="nlwm-steps">
					<li>Old site: <strong>Backups → Transfer link</strong>.</li>
					<li>New site: install WordPress and this plugin.</li>
					<li>New site: <strong>Restore / Import → Import from URL</strong>, paste, restore.</li>
				</ol>
			</div>
			<?php if ( ! $nlwm_brand['white_label'] ) : ?>
				<?php if ( ! $nlwm_pro ) : ?>
					<div class="nlwm-card is-side nlwm-pro-card">
						<h3>NoLimit Pro</h3>
						<ul class="nlwm-ticks">
							<li>Google Drive, S3, Cloudflare R2, Backblaze B2</li>
							<li>Password-encrypted backups</li>
							<li>Hourly backups, Slack/Discord alerts</li>
							<li>White-label for agencies</li>
						</ul>
						<a class="nlwm-btn is-primary is-small" href="<?php echo esc_url( NLWM_Util::site_url( 'pricing', 'sidebar' ) ); ?>" target="_blank" rel="noopener">From $39/year</a>
					</div>
				<?php endif; ?>
				<div class="nlwm-card is-side">
					<h3>Rather not do it yourself?</h3>
					<p class="nlwm-muted nlwm-small">Our team moves your site for you, usually within 24–48 hours. Fixed price from $49.</p>
					<a class="nlwm-btn is-small" href="<?php echo esc_url( NLWM_Util::site_url( 'services', 'sidebar' ) ); ?>" target="_blank" rel="noopener">Hire us to migrate</a>
				</div>
				<p class="nlwm-foot"><?php echo esc_html( $nlwm_brand['name'] ); ?> <?php echo esc_html( NLWM_VERSION ); ?> · <a href="<?php echo esc_url( NLWM_Util::site_url( 'docs', 'footer' ) ); ?>" target="_blank" rel="noopener">Docs</a> · <a href="<?php echo esc_url( NLWM_Util::site_url( '', 'footer' ) ); ?>" target="_blank" rel="noopener">nolimitmigration.com</a></p>
			<?php endif; ?>
		</aside>
	</div>

	<div class="nlwm-modal" id="nlwm-modal" hidden role="dialog" aria-modal="true" aria-labelledby="nlwm-modal-title">
		<div class="nlwm-modal-box">
			<h2 id="nlwm-modal-title">Working…</h2>
			<div class="nlwm-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="nlwm-bar"></span></div>
			<div class="nlwm-progress-line"><span id="nlwm-msg">Starting…</span><strong id="nlwm-pct">0%</strong></div>
			<p class="nlwm-muted nlwm-small" id="nlwm-keep">Keep this tab open until it finishes.</p>
			<div id="nlwm-result"></div>
			<details class="nlwm-log"><summary>Details</summary><pre id="nlwm-log"></pre></details>
			<div class="nlwm-row is-end">
				<button type="button" class="nlwm-btn is-ghost" id="nlwm-cancel">Cancel</button>
				<button type="button" class="nlwm-btn" id="nlwm-close" hidden>Close</button>
			</div>
		</div>
	</div>
</div>
