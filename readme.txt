=== COD Manager Pro ===
Contributors: nidacademy
Tags: cash on delivery, cod, woocommerce orders, order confirmation, shipping labels
Requires at least: 6.2
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage orders efficiently with COD Manager Pro. Simplify order management, streamline your workflow, and keep your COD store organized with a powerful, easy-to-use solution for WooCommerce.

== Description ==

COD Manager Pro is built for WooCommerce stores that sell with cash on delivery (COD). It turns your orders into a clear daily work queue, from the confirmation call to the money your courier pays you back.

= Order work queue =
* Four tabs that tell your team what to do next: To call, To ship, With courier, Done.
* One card per order with the customer, phone (Call and WhatsApp buttons), city, address, products and amount to collect.
* One click per step: Confirm / No answer / Cancel, then Ship (shipping company + tracking number), then Delivered or Returned.
* "No answer" counter on orders you could not reach.
* Duplicate-order and customer-risk flags (trusted, watch, high risk, blacklisted).
* Order panel with editing, notes, full history and WhatsApp message templates.
* Bulk actions, printable shipping labels (10x15 cm thermal or A4) with a Code 128 barcode, CSV export.

= Simple order process =
New → Confirmed → Shipped → Delivered, plus Cancelled and Returned. Statuses stay in sync with WooCommerce in both directions. Stock is kept while orders are on their way and put back on returns.

= Profit and courier money =
* Cost price per product and variation.
* Profit per order, profit and loss for any period, expenses (ads, packaging, salaries...).
* Courier reconciliation: what each shipping company still owes you, payouts received, differences.
* Excel export (5 sheets) and a print-ready PDF report with your store name.

= Fewer fake orders =
* Customer database keyed by phone number, with a blacklist.
* Optional checks at checkout: refuse blacklisted numbers, block repeat orders of the same product, accept only Moroccan phone numbers.
* The checkout message never reveals why an order was refused, and visitors who keep trying are paused.

= Team =
* "COD Agent" role: agents see only the Orders screen, without revenue, costs, profit or customer exports.
* Managers see finance; only administrators change settings.

= Marketing sources =
Shows where each order came from (Facebook Ads, Instagram, TikTok Ads, Google, Direct...) using WooCommerce order attribution, with results per source on the Dashboard.

= Languages =
English, French and Arabic included (Arabic is also used on Moroccan Arabic sites).

== Installation ==

1. Plugins > Add New > Upload Plugin, choose the zip file, then Activate. WooCommerce must be active.
2. COD Manager Pro > Settings > Shipping companies: add your couriers and their delivery and return fees, and choose a default company.
3. Products: fill in "Cost price" on each product (and variation) so profit is calculated.
4. COD Manager Pro > Settings > General: store phone and address (printed on shipping labels).
5. Settings > Tools: import your recent WooCommerce orders.
6. Users: give confirmation agents the "COD Agent" role.

== Frequently Asked Questions ==

= What happens to my orders if I deactivate the plugin? =
Orders in the plugin's own statuses are moved to WooCommerce statuses (Awaiting confirmation → On hold, Shipped → Processing, Returned → Cancelled) so they stay visible. Reactivating puts them back. No emails are sent and stock is not touched.

When you click Deactivate, a window asks whether to keep or delete the plugin data, and lets you download a backup (ZIP of CSV files) first. Keeping is the default. WooCommerce orders are never deleted.

= Is my data deleted when I delete the plugin? =
No, unless you tick "Delete all data when the plugin is deleted" in Settings > General.

= Can I send WhatsApp messages automatically? =
Messages open in WhatsApp in one click. Automatic sending needs a WhatsApp API provider add-on that plugs in through the `codm_whatsapp_providers` filter.

= I use my own order form instead of the WooCommerce checkout. =
Use the `codm_check_order` filter (arguments: message, array with `phone` and `product_id` or `product_ids`) to apply the same order checks, and `codm_order_initial_status` for the starting status. Tracking data (utm_*, fbclid, ttclid, gclid, ref) can be saved on the order in the `_codm_attr` meta.

== Developers ==

* Actions: `codm_order_created`, `codm_order_status_changed`, `codm_order_removed`.
* Filters: `codm_check_order`, `codm_order_initial_status`, `codm_whatsapp_providers`, `codm_carriers`.
* Interfaces: `CODM_WhatsApp_Provider`, `CODM_Carrier`.

== External services ==

COD Manager Pro does not send your orders or customer data to any outside service.

* WhatsApp (wa.me): the WhatsApp buttons open a wa.me link in your own browser with the customer's number and the message you chose. Nothing is sent until you press send in WhatsApp. https://www.whatsapp.com/legal/privacy-policy
* GitHub (api.github.com): the plugin checks for new releases at most every 12 hours so updates show under Dashboard > Updates. No site data is sent. https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement
* The "View details" window links to Nid Academy pages (nidacademy.org, YouTube, Skool, Trustpilot). They open only when you click them.

== Updates ==

New versions are published as GitHub releases (https://github.com/nidacademy/cod-manager-pro) and appear under Dashboard > Updates.

== Changelog ==

= 1.0.0 =
* First public release on GitHub.
* Updates from GitHub releases.
