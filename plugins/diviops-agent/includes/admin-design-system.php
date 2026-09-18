<?php
/** Read-only dashboard shell. Registry reads are deferred to the inspector asset. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<section id="diviops-design-system" class="diviops-ds" aria-labelledby="diviops-ds-title">
	<div class="diviops-intro">
		<h2 id="diviops-ds-title"><?php esc_html_e( 'Design System', 'diviops-agent' ); ?></h2>
		<p><?php esc_html_e( 'Saved presets and variables on this site. Read-only; stored definitions are not a computed-style or visual-correctness guarantee.', 'diviops-agent' ); ?></p>
	</div>
	<?php if ( ! function_exists( 'et_get_option' ) ) : ?>
		<p class="diviops-callout" role="status"><?php esc_html_e( 'Design System unavailable: Divi is not active. No preset or variable registries have been requested.', 'diviops-agent' ); ?></p>
	<?php else : ?>
	<div class="diviops-ds-toolbar">
		<div class="diviops-ds-views" role="group" aria-label="<?php esc_attr_e( 'Design System view', 'diviops-agent' ); ?>">
			<button type="button" class="button" data-view="presets" aria-pressed="true"><?php esc_html_e( 'Presets', 'diviops-agent' ); ?></button>
			<button type="button" class="button" data-view="variables" aria-pressed="false"><?php esc_html_e( 'Variables', 'diviops-agent' ); ?></button>
		</div>
		<label class="diviops-ds-search"><span class="screen-reader-text"><?php esc_html_e( 'Search name, ID or type', 'diviops-agent' ); ?></span><input type="search" data-search placeholder="<?php esc_attr_e( 'Search name, ID or type', 'diviops-agent' ); ?>" /></label>
		<button type="button" class="button" data-refresh title="<?php esc_attr_e( 'Refresh registry', 'diviops-agent' ); ?>" aria-label="<?php esc_attr_e( 'Refresh registry', 'diviops-agent' ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
	</div>
	<p data-status role="status" aria-live="polite"><?php esc_html_e( 'Not loaded.', 'diviops-agent' ); ?></p>
	<div data-notices></div>
	<div class="diviops-ds-workspace">
		<section class="diviops-ds-list" aria-label="<?php esc_attr_e( 'Registry entries', 'diviops-agent' ); ?>"><ul data-list></ul><button type="button" class="button" data-more hidden><?php esc_html_e( 'Show more', 'diviops-agent' ); ?></button></section>
		<section class="diviops-ds-detail" data-detail tabindex="-1" aria-label="<?php esc_attr_e( 'Selected entry', 'diviops-agent' ); ?>"><p><?php esc_html_e( 'No entry selected.', 'diviops-agent' ); ?></p></section>
	</div>
	<noscript><p><?php esc_html_e( 'JavaScript is required for on-demand inspection. The same data is available through the DiviOps preset and variable tools.', 'diviops-agent' ); ?></p></noscript>
	<?php endif; ?>
</section>
