=== OpenApp Gateway for woocommerce ===
Contributors: openapp
Tags: woocommerce, payment gateway, openapp
Tested up to: 6.6
Stable tag: 1.41
License: GPLv2 or later

Wtyczka OpenApp Gateway dla woocommerce integruje OpenApp jako metodę płatności w sklepie WooCommerce.

== Opis ==
Wtyczka OpenApp Gateway dla woocommerce integruje OpenApp jako metodę płatności w sklepie WooCommerce. Klienci mogą skanować kod QR w koszyku lub podczas płatności i dokonywać płatności bezpośrednio z aplikacji mobilnej OA.

== Ujawnienie usług stron trzecich ==
Ta wtyczka opiera się na OpenApp, usłudze strony trzeciej, do przetwarzania płatności. Podczas korzystania z tej wtyczki dane mogą być przesyłane do OpenApp w celu przetworzenia płatności. Ponadto ta wtyczka dynamicznie ładuje plik JavaScript z serwerów OpenApp w celu renderowania kodu QR.

Więcej informacji na temat obsługi danych przez OpenApp można znaleźć na stronie:

Usługa OpenApp: https://open-app.com
Warunki użytkowania OpenApp: https://open-app.com/terms-and-conditions/

Ta wtyczka korzysta z zewnętrznego pliku JavaScript hostowanego przez OpenApp w celu ułatwienia przetwarzania płatności:

* Domenta: https://static.prd.open-pay.com
* Plik JavaScript: openapp.min.0.0.4.js
* Cel: renderowanie kodu QR OpenApp potrzebnego do funkcji bramki płatności w WooCommerce.

Korzystając z tej wtyczki, zgadzasz się na warunki korzystania z OpenApp. Ważne jest, aby zapoznać się z tymi dokumentami, aby zapewnić zgodność z przepisami o ochronie danych osobowych odpowiednimi dla Twojej firmy i lokalizacji.

== Funkcjonalności ==
- Dodaje OpenApp jako bramkę płatności w WooCommerce.
- Osadzanie kodów QR za pomocą shotcode.
- Logowanie przy użyciu funkcji logowania OA.

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/openapp-payment-gateway` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to WooCommerce > Settings > Payments and enable 'OpenApp'.
4. Configure the payment gateway settings as required.
5. Navigate to WooCommerce > Shipping > Shipping zones > Poland and update the details. Assign OpenApp mapping to each shipping method that you wish to display in the OpenApp application.

== Changelog ==
= 1.41 =
* Fixed rest_cookie_invalid_nonce error for oa_login shortcode caused by cache plugin when nonce is older than 12-24 hours.

= 1.40 =
* Improved: Shortened `basketId` for QR codes from 32 characters to 10 characters

= 1.39 =
* Fixed: Updated JSON encoding in request bodies to ensure proper handling of special characters and prevent escaping of slashes in URLs.

= 1.38 =
* Added: Option for real-time cart synchronization with the OpenApp mobile app.
* Updated: Payment method title set to 'OpenApp'.

= 1.37 =
* Updated `deliveryOptions` to only display the cheapest option for multiple methods sharing the same key.
* Changed `oa_status_changed` method to utilize the `multiFulfillment` endpoint instead of the `fulfillment`.

= 1.36 =
* Update: Now using WooCommerce `order_number` instead of `order_key` for data sent to OpenApp.

= 1.35 =
* Improved order processing sequence to ensure shipping details are included in the initial order confirmation email. Previously, the order status update was triggered prematurely, omitting shipping information.

= 1.34 =
* Updated the plugin directory name and main PHP file to align with the public plugin directory listing requirements.
* Fixed a deprecation warning by replacing the `FILTER_SANITIZE_STRING` constant.

= 1.33 =
* Fixed an issue where the payment method was not displayed during checkout and in the initial order confirmation email.

= 1.32 =
* Improved data sanitization and filtering
* Updated readme to clearly disclose the use of third-party service OpenApp

= 1.31 =
* Improved `store_cart_in_db` function to properly recognize and respond to products added programmatically via `WC()->cart->add_to_cart($prod_id, 1)`.

= 1.30 =
* Implemented unique function prefixes for conflict avoidance.
* Enhanced security through improved data sanitization and escaping.
* Updated README with license details and third-party service information.
* Isolated debug functionality into a dedicated development plugin.
* Refactor code using Plugin Check (PCP) remarks
* Bugfix: Recreated user session for oa-login process.
* Addressed uninstallation error when WooCommerce is inactive.
* Refined 'store_cart_in_db' triggering using is_woo_request().
* Utilized a more specific 'woocommerce_thankyou_openapp' hook.
* Removed 'set_time_limit(0)' from SSE method
* Replaced 'wp_create_user' with 'wc_create_new_customer' for user creation.
* Improved QR codes Ajax refresh with added 500ms delay
* Change plugin name (and directory) to: OpenApp Gateway for WooCommerce

= 1.26 =
* Introduced a daily scheduled task to clean up carts older than 30 days.
* Enhanced query performance by adding an index to the cart_session_id column in the database table.
* Implemented automatic table schema updates, eliminating the need for manual plugin reactivation after updates.

= 1.25 =
* Added a new setting to allow admins to select the default order status for new orders.

= 1.24 =
* Fixed a bug in the shipping method fetch function by excluding endpoint from REST API

= 1.23 =
* Prevent REST API endpoint caching by adding no-cache headers.
* Added basic support for WooCommerce shipping class calculations.

= 1.22 =
* Added support for the Flexible Shipping plugin by Octolize: mapping to OpenApp methods and dynamic calculation of shipping costs

= 1.21 =
* Implemented Server-Sent Events (SSE) to enhance background frontend checks and improve thank you page redirection.
* Added a new button in wp-admin for testing Server-Sent Events (SSE) Support on the server.
* Refined the order key reset process to trigger only via the woocommerce_thankyou hook.
* Improved the performance of the `create_new_wc_order` function, specifically for the `oa_woocommerce_persistent_cart` SQL update.

= 1.20 =
* Feature: WooCommerce order status updates now synchronize with OpenApp status.

= 1.19 =
* Added validation and default assignment for 'Interval Time' in JavaScript to handle cases where the value might be undefined, NaN, or less than or equal to 0.

= 1.18 =
* Added 'Interval Time' option in settings to allow customization of the order redirection checking interval.
* Ensured compatibility with WordPress installations in subdirectories by dynamically setting AJAX URLs using the site's base URL.
* Various JavaScript optimizations and improvements for order redirection checking.
* readme.txt added

= 1.17 =
* Resolved an issue preventing db_table creation on MariaDB versions 10.2.7 and above.

= 1.16 =
* Fix incorrect shippingCosts calculation.
* Allow costs with dot or comma: 17.90, 17,90.

= 1.15 =
* Render shortcodes also on is_archive() template.

= 1.14 =
* Testing Mode option available only on staging or local (.dev | .local domains).
* On plugin deactivation - remove all old logs: /wp-content/uploads/*-log837104.txt.
* Development function 'Disable plugin using link' removed.

= 1.13 =
* Add OpenApp mapping for Inpost Paczkomaty plugin (https://pl.wordpress.org/plugins/inpost-paczkomaty/).
* Add ?ver to assets.

= 1.12 =
* Echo http_code from response removed.

= 1.11 =
* Fix QR code basket value (should be in grosze).

= 1.10 =
* Add lang attr to shortcodes (QR codes).
* Assign WooCommerce customer account to every order (using ['deliveryDetails']['email']).
* Fix thank_you_page requires login: assign order to client using thank_you_page hook.

= 1.09 =
* Optimize shortcodes, allow multiple instances.
* Move js/css to files.
* oa_status_changed, new endpoint /v1/orders/fulfillment.
* Recheck testHmacSignaturePost().
* Save oaOrderId as post_meta.
* /v1/orders/fulfillment - hardcode more params.

= 1.0.8 =
* Global wp action for 'store_cart_in_db' (can be used in custom external code to refresh db content).
Usage:  do_action('oa_update_cart_in_db', true);

= 1.0.7 =
* Order - add shipping method as netto value.
* Shortcode - refactor shortcodes, load lib in header.
* Shipping methods - load from woo shipping zones.
* Woo shipping methods - add custom field to map OA methods.
* Fix missing get_product_object() (Variation products fix).
* Refactor shortcodes.
* Set CSS OpenAppCheckout class only if cart not empty.
* Refactor create_product_output_array_from_cart.