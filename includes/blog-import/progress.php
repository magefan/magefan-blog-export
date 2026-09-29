<?php
/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mageshbl-bi">
	<p class="mageshbl-bi-error" data-mageshbl-bi-form-error hidden></p>

	<div class="mageshbl-bi-card" data-mageshbl-bi-progress hidden>
		<p class="mageshbl-bi-shop" data-mageshbl-bi-shop></p>
		<h2 data-mageshbl-bi-progress-title></h2>
		<div class="mageshbl-bi-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-mageshbl-bi-bar>
			<div class="mageshbl-bi-bar__fill" data-mageshbl-bi-bar-fill></div>
		</div>
		<p class="mageshbl-bi-count" data-mageshbl-bi-count></p>
		<p class="mageshbl-bi-note" data-mageshbl-bi-note hidden></p>
		<div class="mageshbl-bi-failed" data-mageshbl-bi-failed hidden>
			<h3><?php esc_html_e( 'Posts that failed', 'magefan-blog-export' ); ?></h3>
			<ul data-mageshbl-bi-failed-list></ul>
			<p class="description" data-mageshbl-bi-failed-more hidden></p>
		</div>
		<p class="mageshbl-bi-actions">
			<button type="button" class="button" data-mageshbl-bi-resume hidden><?php esc_html_e( 'Continue export', 'magefan-blog-export' ); ?></button>
			<button type="button" class="button button-primary" data-mageshbl-bi-new hidden><?php esc_html_e( 'Start a new export', 'magefan-blog-export' ); ?></button>
			<button type="button" class="button-link mageshbl-bi-cancel" data-mageshbl-bi-cancel hidden><?php esc_html_e( 'Cancel import', 'magefan-blog-export' ); ?></button>
		</p>
		<p class="mageshbl-bi-error" data-mageshbl-bi-error hidden></p>
	</div>
</div>
