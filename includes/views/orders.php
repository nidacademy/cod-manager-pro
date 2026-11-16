<?php
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap codm">
	<?php CODM_Admin::header( 'codm-orders', __( 'Orders', 'cod-manager-pro' ) ); ?>

	<div class="codm-tabs" id="codm-status-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Order status', 'cod-manager-pro' ); ?>"></div>

	<div class="codm-toolbar codm-filters">
		<input type="search" id="codm-search" class="codm-input codm-input--search" placeholder="<?php esc_attr_e( 'Search order, name, phone, city or tracking', 'cod-manager-pro' ); ?>" aria-label="<?php esc_attr_e( 'Search orders', 'cod-manager-pro' ); ?>">
		<select id="codm-city" class="codm-input" aria-label="<?php esc_attr_e( 'City', 'cod-manager-pro' ); ?>"><option value=""><?php esc_html_e( 'All cities', 'cod-manager-pro' ); ?></option></select>
		<select id="codm-source" class="codm-input" aria-label="<?php esc_attr_e( 'Source', 'cod-manager-pro' ); ?>"><option value=""><?php esc_html_e( 'All sources', 'cod-manager-pro' ); ?></option></select>
		<select id="codm-company" class="codm-input" aria-label="<?php esc_attr_e( 'Shipping company', 'cod-manager-pro' ); ?>"><option value=""><?php esc_html_e( 'All companies', 'cod-manager-pro' ); ?></option></select>
		<input type="date" id="codm-from" class="codm-input" aria-label="<?php esc_attr_e( 'From date', 'cod-manager-pro' ); ?>">
		<input type="date" id="codm-to" class="codm-input" aria-label="<?php esc_attr_e( 'To date', 'cod-manager-pro' ); ?>">
		<button type="button" class="codm-btn" id="codm-reset"><?php esc_html_e( 'Clear filters', 'cod-manager-pro' ); ?></button>
		<?php if ( current_user_can( CODM_Roles::CAP_FINANCE ) ) : ?>
			<a class="codm-btn codm-push" id="codm-export" href="#"><?php esc_html_e( 'Export CSV', 'cod-manager-pro' ); ?></a>
		<?php endif; ?>
	</div>

	<div class="codm-bulk" id="codm-bulk" hidden>
		<strong id="codm-bulk-count"></strong>
		<select id="codm-bulk-status" class="codm-input" aria-label="<?php esc_attr_e( 'New status', 'cod-manager-pro' ); ?>"></select>
		<button type="button" class="codm-btn codm-btn--primary" id="codm-bulk-apply"><?php esc_html_e( 'Change status', 'cod-manager-pro' ); ?></button>
		<button type="button" class="codm-btn" id="codm-bulk-labels"><?php esc_html_e( 'Print labels', 'cod-manager-pro' ); ?></button>
		<?php if ( current_user_can( CODM_Roles::CAP_FINANCE ) ) : ?>
			<button type="button" class="codm-btn" id="codm-bulk-export"><?php esc_html_e( 'Export selected', 'cod-manager-pro' ); ?></button>
		<?php endif; ?>
		<button type="button" class="codm-link" id="codm-bulk-clear"><?php esc_html_e( 'Clear selection', 'cod-manager-pro' ); ?></button>
	</div>

	<div class="codm-subtabs" id="codm-subtabs" hidden></div>
	<div class="codm-queue" id="codm-orders" aria-live="polite"></div>
	<div class="codm-pager" id="codm-pager"></div>

	<div class="codm-drawer" id="codm-drawer" hidden>
		<div class="codm-drawer-backdrop" data-close></div>
		<aside class="codm-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="codm-drawer-title" tabindex="-1">
			<div id="codm-drawer-body"></div>
		</aside>
	</div>
</div>
