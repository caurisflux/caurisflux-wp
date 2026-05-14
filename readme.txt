=== CaurisFlux for WooCommerce ===
Contributors: devagencecauris, caurispay
Tags: payment, woocommerce, mobile money, wave, africa
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Mobile Money (Wave, Orange Money, MTN, Free Money, Moov, Airtel...) and Card payments (3D Secure) in Africa through CaurisFlux.

== Description ==

**CaurisFlux for WooCommerce** is a payment gateway that lets merchants accept Mobile Money and Card payments across UEMOA, CEMAC, Ghana, Nigeria and more, using the CaurisFlux pan-African aggregator.

= Supported payment methods =

* **Mobile Money**: Wave, Orange Money, MTN MoMo, Free Money, Moov Money, Airtel Money, Mixx By Yas, M-Pesa, Opay and more.
* **Cards**: Visa / Mastercard with 3D Secure.
* **Multi-currency**: XOF, XAF, GHS, NGN, EUR, USD with automatic conversion.
* **Multi-country**: SN, CI, ML, BF, BJ, TG, NE, GN, CM, GA, CG, TD, GH, NG, KE and more.

= How it works =

When a customer chooses CaurisFlux at checkout, they are redirected to a secure payment page hosted by CaurisFlux where they pick a method and confirm. No card data is stored on your server (PCI-DSS out of scope).

= Payment notifications =

The plugin exposes a REST webhook endpoint that receives HMAC SHA256 signed notifications from CaurisFlux and automatically updates the order status (paid, failed, cancelled, expired).

= Sandbox / Production =

You can switch environments from the settings page. Use `pk_test_*` / `sk_test_*` keys to test without moving real funds.

== External services ==

This plugin relies on the CaurisFlux API to process payments. It is required for the plugin to work: when a customer places an order using the CaurisFlux gateway, the plugin sends the order data to CaurisFlux so a hosted checkout page can be generated, and CaurisFlux later notifies the plugin (through a webhook) when the payment is completed, failed, cancelled, expired, or refunded.

The plugin connects to the following endpoints, depending on the selected environment:

* Production: `https://prod-api.caurisflux.com/api/v1`
* Sandbox: `https://sandbox-api.caurisflux.com/api/v1`

Data sent to CaurisFlux when initiating a payment:

* Order ID, order key, order number, order total, order currency.
* Customer billing first name, last name, email and phone number (when available).
* Return URL and cancel URL of your store, used to redirect the customer back after payment.

Data is sent only when an order is placed using the CaurisFlux gateway, when the merchant checks the payment status of an order from the admin, and during the optional API health check from the admin notice.

This service is provided by CaurisFlux ("Cauris Pay"):

* Terms of Service: https://caurisflux.com/terms
* Privacy Policy: https://caurisflux.com/privacy

== Installation ==

1. Upload the `caurisflux-wp` folder to `/wp-content/plugins/`, or install it from the WordPress plugin directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **WooCommerce → Settings → Payments → CaurisFlux**.
4. Enter your **API Key** (format `pk_xxx:sk_xxx`) and your **Webhook Secret**.
5. Copy the **Webhook URL** shown on the settings page and configure it in your CaurisFlux dashboard.
6. Pick the **Sandbox** environment to test, then switch to **Production** to go live.

== Frequently Asked Questions ==

= Where do I find my CaurisFlux API keys? =

Sign in to your CaurisFlux merchant dashboard and open the **API Keys** menu. A Sandbox key pair (`pk_test_...:sk_test_...`) is generated automatically when your account is created.

= Does the plugin store card data? =

No. The checkout page is hosted by CaurisFlux. No card data flows through your WordPress site.

= Which currency should I configure in WooCommerce? =

Use the merchant's primary currency (XOF for UEMOA, XAF for CEMAC, etc.). CaurisFlux automatically converts to the customer's local currency on the hosted checkout page.

= My webhook is not working. What should I check? =

* Make sure the webhook URL is copied from the settings page (`/wp-json/caurisflux/v1/webhook`).
* Make sure the HMAC secret matches the one configured on the CaurisFlux side.
* Make sure WordPress permalinks are NOT set to "Plain" (the REST API requires pretty permalinks).
* Enable **debug logs** in the settings, then inspect **WooCommerce → Status → Logs**.

== Changelog ==

= 1.0.0 =
* First stable release.
* WooCommerce payment gateway with hosted checkout.
* HMAC SHA256 signed webhooks.
* Sandbox / Production environments.
* HPOS (High-Performance Order Storage) compatibility.
* Built-in debug logs available through WooCommerce → Status → Logs.

== Upgrade Notice ==

= 1.0.0 =
First public release.
