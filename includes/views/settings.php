<?php
defined( 'ABSPATH' ) || exit;

$codm_tabs = CODM_Settings::tabs();
$codm_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
$codm_tab  = isset( $codm_tabs[ $codm_tab ] ) ? $codm_tab : 'general';
$codm_s    = CODM_DB::settings();
$codm_post = esc_url( admin_url( 'admin-post.php' ) );
$codm_cur  = CODM_DB::setting( 'currency_label' );

$codm_open_form = function ( $tab ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="codm-card codm-settings-form">';
	wp_nonce_field( 'codm_save_settings' );
	echo '<input type="hidden" name="action" value="codm_save_settings"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
};
$codm_close_form = function () {
	echo '<p class="codm-actions"><button class="codm-btn codm-btn--primary">' . esc_html__( 'Save settings', 'cod-manager-pro' ) . '</button></p></form>';
};
?>
<div class="wrap codm">
	<?php CODM_Admin::header( 'codm-settings', __( 'Settings', 'cod-manager-pro' ) ); ?>

	<nav class="codm-tabs codm-tabs--plain" aria-label="<?php esc_attr_e( 'Settings sections', 'cod-manager-pro' ); ?>">
		<?php foreach ( $codm_tabs as $codm_key => $codm_label ) : ?>
			<a class="codm-tab<?php echo $codm_key === $codm_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-settings&tab=' . $codm_key ) ); ?>"><?php echo esc_html( $codm_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'general' === $codm_tab ) : ?>
		<?php $codm_open_form( 'general' ); ?>
			<div class="codm-field">
				<label for="codm-cc"><?php esc_html_e( 'Country calling code', 'cod-manager-pro' ); ?></label>
				<input id="codm-cc" name="country_code" class="codm-input codm-input--short" value="<?php echo esc_attr( $codm_s['country_code'] ); ?>">
				<p class="codm-help"><?php esc_html_e( 'Turns local numbers like 0612345678 into 212612345678 for WhatsApp links and duplicate detection.', 'cod-manager-pro' ); ?></p>
			</div>
			<div class="codm-field">
				<label for="codm-cur"><?php esc_html_e( 'Currency label', 'cod-manager-pro' ); ?></label>
				<input id="codm-cur" name="currency_label" class="codm-input codm-input--short" value="<?php echo esc_attr( $codm_s['currency_label'] ); ?>">
				<p class="codm-help"><?php esc_html_e( 'Shown after amounts in COD Manager Pro, e.g. DH or MAD.', 'cod-manager-pro' ); ?></p>
			</div>
			<h2><?php esc_html_e( 'Shipping labels', 'cod-manager-pro' ); ?></h2>
			<p class="codm-help"><?php esc_html_e( 'Printed in the sender block, so the courier knows where to bring returns.', 'cod-manager-pro' ); ?></p>
			<div class="codm-field">
				<label for="codm-sp"><?php esc_html_e( 'Sender phone', 'cod-manager-pro' ); ?></label>
				<input id="codm-sp" name="store_phone" class="codm-input" value="<?php echo esc_attr( $codm_s['store_phone'] ); ?>">
			</div>
			<div class="codm-field">
				<label for="codm-scity"><?php esc_html_e( 'Sender city', 'cod-manager-pro' ); ?></label>
				<input id="codm-scity" name="store_city" class="codm-input" value="<?php echo esc_attr( $codm_s['store_city'] ); ?>" placeholder="<?php esc_attr_e( 'Casablanca', 'cod-manager-pro' ); ?>">
			</div>
			<div class="codm-field">
				<label for="codm-saddr"><?php esc_html_e( 'Sender address (optional)', 'cod-manager-pro' ); ?></label>
				<input id="codm-saddr" name="store_address" class="codm-input" style="width:100%;max-width:520px" value="<?php echo esc_attr( $codm_s['store_address'] ); ?>">
			</div>
			<div class="codm-field">
				<label><input type="checkbox" name="allow_open" value="1" <?php checked( $codm_s['allow_open'] ); ?>> <?php esc_html_e( 'Customers may open the parcel before paying (default for new orders)', 'cod-manager-pro' ); ?></label>
				<p class="codm-help"><?php esc_html_e( 'Printed on every label. You can change it per order in the order panel.', 'cod-manager-pro' ); ?></p>
			</div>
			<div class="codm-field">
				<label><input type="checkbox" name="fragile" value="1" <?php checked( $codm_s['fragile'] ); ?>> <?php esc_html_e( 'Mark all parcels as fragile', 'cod-manager-pro' ); ?></label>
			</div>
			<h2><?php esc_html_e( 'Orders', 'cod-manager-pro' ); ?></h2>
			<div class="codm-field">
				<label><input type="checkbox" name="hold_cod" value="1" <?php checked( $codm_s['hold_cod'] ); ?>> <?php esc_html_e( 'Put new cash-on-delivery orders in "Awaiting confirmation" in WooCommerce', 'cod-manager-pro' ); ?></label>
				<p class="codm-help"><?php esc_html_e( 'Recommended. Orders only move to Processing once your team confirms them.', 'cod-manager-pro' ); ?></p>
			</div>
			<div class="codm-field">
				<label><input type="checkbox" name="only_cod" value="1" <?php checked( $codm_s['only_cod'] ); ?>> <?php esc_html_e( 'Only manage cash-on-delivery orders (ignore card payments)', 'cod-manager-pro' ); ?></label>
			</div>
			<div class="codm-field">
				<label for="codm-ls"><?php esc_html_e( 'Low-stock alert at', 'cod-manager-pro' ); ?></label>
				<input id="codm-ls" type="number" min="0" name="low_stock" class="codm-input codm-input--short" value="<?php echo (int) $codm_s['low_stock']; ?>"> <?php esc_html_e( 'units or fewer', 'cod-manager-pro' ); ?>
			</div>
			<div class="codm-field">
				<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $codm_s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all COD Manager Pro data when the plugin is deleted', 'cod-manager-pro' ); ?></label>
				<p class="codm-help"><?php esc_html_e( 'Leave this off unless you are sure. Deactivating the plugin never deletes data.', 'cod-manager-pro' ); ?></p>
			</div>
		<?php $codm_close_form(); ?>

	<?php elseif ( 'shipping' === $codm_tab ) : ?>
		<?php
		$codm_companies = CODM_Shipping::companies();
		$codm_carriers  = CODM_Shipping::carriers();
		?>
		<section class="codm-card">
			<div class="codm-card-head"><h2><?php esc_html_e( 'Shipping companies', 'cod-manager-pro' ); ?></h2></div>
			<p class="codm-help"><?php esc_html_e( 'Add each courier you work with and what they charge you. These fees are used for profit and COD reconciliation.', 'cod-manager-pro' ); ?></p>
			<div class="codm-table-wrap">
				<table class="codm-table codm-table--compact codm-companies">
					<thead><tr>
						<th><?php esc_html_e( 'Name', 'cod-manager-pro' ); ?></th>
						<th><?php esc_html_e( 'Phone', 'cod-manager-pro' ); ?></th>
						<th><?php echo esc_html( sprintf( /* translators: %s currency */ __( 'Delivery fee (%s)', 'cod-manager-pro' ), $codm_cur ) ); ?></th>
						<th><?php echo esc_html( sprintf( /* translators: %s currency */ __( 'Return fee (%s)', 'cod-manager-pro' ), $codm_cur ) ); ?></th>
						<th><?php esc_html_e( 'Active', 'cod-manager-pro' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_merge( array_values( $codm_companies ), array( null ) ) as $codm_c ) : ?>
						<?php $codm_fid = 'codm-company-' . ( $codm_c ? (int) $codm_c->id : 'new' ); ?>
						<tr<?php echo $codm_c ? '' : ' class="codm-new-row"'; ?>>
							<td><input form="<?php echo esc_attr( $codm_fid ); ?>" name="name" class="codm-input" value="<?php echo esc_attr( $codm_c ? $codm_c->name : '' ); ?>" placeholder="<?php esc_attr_e( 'New company name', 'cod-manager-pro' ); ?>" aria-label="<?php esc_attr_e( 'Name', 'cod-manager-pro' ); ?>"></td>
							<td><input form="<?php echo esc_attr( $codm_fid ); ?>" name="phone" class="codm-input" value="<?php echo esc_attr( $codm_c ? $codm_c->phone : '' ); ?>" aria-label="<?php esc_attr_e( 'Phone', 'cod-manager-pro' ); ?>"></td>
							<td><input form="<?php echo esc_attr( $codm_fid ); ?>" name="default_cost" inputmode="decimal" class="codm-input codm-input--short" value="<?php echo esc_attr( $codm_c ? $codm_c->default_cost : '' ); ?>" aria-label="<?php esc_attr_e( 'Delivery fee', 'cod-manager-pro' ); ?>"></td>
							<td><input form="<?php echo esc_attr( $codm_fid ); ?>" name="return_cost" inputmode="decimal" class="codm-input codm-input--short" value="<?php echo esc_attr( $codm_c ? $codm_c->return_cost : '' ); ?>" aria-label="<?php esc_attr_e( 'Return fee', 'cod-manager-pro' ); ?>"></td>
							<td><input type="hidden" form="<?php echo esc_attr( $codm_fid ); ?>" name="carrier" value="<?php echo esc_attr( $codm_c ? $codm_c->carrier : 'manual' ); ?>"><input form="<?php echo esc_attr( $codm_fid ); ?>" type="checkbox" name="active" value="1" <?php checked( $codm_c ? (int) $codm_c->active : 1, 1 ); ?> aria-label="<?php esc_attr_e( 'Active', 'cod-manager-pro' ); ?>"></td>
							<td>
								<form id="<?php echo esc_attr( $codm_fid ); ?>" method="post" action="<?php echo $codm_post; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
									<?php wp_nonce_field( 'codm_save_company' ); ?>
									<input type="hidden" name="action" value="codm_save_company">
									<input type="hidden" name="id" value="<?php echo $codm_c ? (int) $codm_c->id : 0; ?>">
									<button class="codm-btn<?php echo $codm_c ? '' : ' codm-btn--primary'; ?>"><?php echo $codm_c ? esc_html__( 'Save', 'cod-manager-pro' ) : esc_html__( 'Add company', 'cod-manager-pro' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>

		<?php $codm_open_form( 'shipping' ); ?>
			<h2><?php esc_html_e( 'Defaults for new orders', 'cod-manager-pro' ); ?></h2>
			<div class="codm-field">
				<label for="codm-dc"><?php esc_html_e( 'Default shipping company', 'cod-manager-pro' ); ?></label>
				<select id="codm-dc" name="default_company" class="codm-input">
					<option value="0"><?php esc_html_e( 'None', 'cod-manager-pro' ); ?></option>
					<?php foreach ( CODM_Shipping::companies( true ) as $codm_c ) : ?>
						<option value="<?php echo (int) $codm_c->id; ?>" <?php selected( (int) $codm_s['default_company'], (int) $codm_c->id ); ?>><?php echo esc_html( $codm_c->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php $codm_close_form(); ?>

	<?php elseif ( 'customers' === $codm_tab ) : ?>
		<?php $codm_open_form( 'customers' ); ?>
			<div class="codm-field">
				<label><input type="checkbox" name="block_blacklisted" value="1" <?php checked( $codm_s['block_blacklisted'] ); ?>> <?php esc_html_e( 'Refuse cash on delivery for blacklisted phone numbers', 'cod-manager-pro' ); ?></label>
				<p class="codm-help"><?php esc_html_e( 'Blacklist a customer from the order details panel on the Orders screen.', 'cod-manager-pro' ); ?></p>
			</div>
			<div class="codm-field">
				<label for="codm-rr"><?php esc_html_e( 'Mark a customer as high risk after', 'cod-manager-pro' ); ?></label>
				<input id="codm-rr" type="number" min="1" name="risk_returns" class="codm-input codm-input--short" value="<?php echo (int) $codm_s['risk_returns']; ?>"> <?php esc_html_e( 'refused deliveries', 'cod-manager-pro' ); ?>
			</div>
			<div class="codm-field">
				<label for="codm-dup"><?php esc_html_e( 'Refuse a repeat order of the same product from the same phone within', 'cod-manager-pro' ); ?></label>
				<input id="codm-dup" type="number" min="0" max="720" name="dup_hours" class="codm-input codm-input--short" value="<?php echo (int) $codm_s['dup_hours']; ?>"> <?php esc_html_e( 'hours', 'cod-manager-pro' ); ?>
				<p class="codm-help"><?php esc_html_e( 'Stops double orders (double clicks, impatient customers): the same phone cannot order the same product again within this many hours. 0 = off.', 'cod-manager-pro' ); ?></p>
			</div>
			<div class="codm-field">
				<label><input type="checkbox" name="ma_phone_only" value="1" <?php checked( $codm_s['ma_phone_only'] ); ?>> <?php esc_html_e( 'Only accept valid Moroccan phone numbers (05, 06, 07 + 8 digits)', 'cod-manager-pro' ); ?></label>
				<p class="codm-help"><?php esc_html_e( 'Blocks fake or mistyped numbers at checkout. Leave off if you deliver outside Morocco.', 'cod-manager-pro' ); ?></p>
			</div>
		<?php $codm_close_form(); ?>

	<?php elseif ( 'whatsapp' === $codm_tab ) : ?>
		<?php $codm_providers = CODM_WhatsApp::providers(); ?>
		<?php $codm_open_form( 'whatsapp' ); ?>
			<p class="codm-help"><?php esc_html_e( 'Tags you can use: {name} {order} {products} {total} {city} {address} {store} {company} {tracking}', 'cod-manager-pro' ); ?></p>
			<?php foreach ( CODM_WhatsApp::templates() as $codm_key => $codm_label ) : ?>
				<div class="codm-field">
					<label for="codm-wa-<?php echo esc_attr( $codm_key ); ?>"><?php echo esc_html( $codm_label ); ?></label>
					<textarea id="codm-wa-<?php echo esc_attr( $codm_key ); ?>" name="wa_<?php echo esc_attr( $codm_key ); ?>" rows="5" class="codm-input codm-textarea"><?php echo esc_textarea( $codm_s[ 'wa_' . $codm_key ] ); ?></textarea>
				</div>
			<?php endforeach; ?>
			<h2><?php esc_html_e( 'Automatic messages', 'cod-manager-pro' ); ?></h2>
			<?php if ( ! $codm_providers ) : ?>
				<p class="codm-help"><?php esc_html_e( 'Messages open in WhatsApp with one click from each order. Automatic sending needs a WhatsApp API provider add-on (for example WhatsApp Cloud API), which plugs into COD Manager Pro through the codm_whatsapp_providers filter.', 'cod-manager-pro' ); ?></p>
				<input type="hidden" name="wa_provider" value="manual">
			<?php else : ?>
				<div class="codm-field">
					<label for="codm-wap"><?php esc_html_e( 'Provider', 'cod-manager-pro' ); ?></label>
					<select id="codm-wap" name="wa_provider" class="codm-input">
						<option value="manual"><?php esc_html_e( 'Manual (click to chat)', 'cod-manager-pro' ); ?></option>
						<?php foreach ( $codm_providers as $codm_pk => $codm_provider ) : ?>
							<option value="<?php echo esc_attr( $codm_pk ); ?>" <?php selected( $codm_s['wa_provider'], $codm_pk ); ?>><?php echo esc_html( $codm_provider->label() ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="codm-field">
					<label><input type="checkbox" name="wa_auto" value="1" <?php checked( $codm_s['wa_auto'] ); ?>> <?php esc_html_e( 'Send automatically when an order is confirmed, shipped, delivered or returned', 'cod-manager-pro' ); ?></label>
				</div>
			<?php endif; ?>
		<?php $codm_close_form(); ?>

	<?php else : ?>
		<section class="codm-card">
			<div class="codm-card-head"><h2><?php esc_html_e( 'Import WooCommerce orders', 'cod-manager-pro' ); ?></h2></div>
			<p class="codm-help"><?php esc_html_e( 'Adds orders that are not in COD Manager Pro yet. Their status is taken from WooCommerce.', 'cod-manager-pro' ); ?></p>
			<form method="post" action="<?php echo $codm_post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" class="codm-form-row">
				<?php wp_nonce_field( 'codm_tools' ); ?>
				<input type="hidden" name="action" value="codm_tools"><input type="hidden" name="do" value="import">
				<label><?php esc_html_e( 'From the last', 'cod-manager-pro' ); ?>
					<select name="days" class="codm-input"><option value="30">30</option><option value="90" selected>90</option><option value="180">180</option><option value="365">365</option></select>
				</label>
				<span><?php esc_html_e( 'days', 'cod-manager-pro' ); ?></span>
				<button class="codm-btn codm-btn--primary"><?php esc_html_e( 'Import orders', 'cod-manager-pro' ); ?></button>
			</form>
		</section>

		<section class="codm-card">
			<div class="codm-card-head"><h2><?php esc_html_e( 'Recalculate product costs', 'cod-manager-pro' ); ?></h2></div>
			<p class="codm-help"><?php esc_html_e( 'After adding or changing cost prices on your products, update open orders and fill in delivered orders that had no cost yet.', 'cod-manager-pro' ); ?></p>
			<form method="post" action="<?php echo $codm_post; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
				<?php wp_nonce_field( 'codm_tools' ); ?>
				<input type="hidden" name="action" value="codm_tools"><input type="hidden" name="do" value="costs">
				<button class="codm-btn"><?php esc_html_e( 'Recalculate costs', 'cod-manager-pro' ); ?></button>
			</form>
		</section>

		<section class="codm-card">
			<div class="codm-card-head"><h2><?php esc_html_e( 'Team', 'cod-manager-pro' ); ?></h2></div>
			<p class="codm-help"><?php esc_html_e( 'Give confirmation agents the "COD Agent" role. They only see the Orders screen, without revenue, costs or profit. Shop managers see everything except Settings.', 'cod-manager-pro' ); ?></p>
			<a class="codm-btn" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"><?php esc_html_e( 'Add a team member', 'cod-manager-pro' ); ?></a>
		</section>
	<?php endif; ?>
</div>
