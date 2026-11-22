=== CatCode Card Payments with EasyPay for WooCommerce ===
Contributors: catcodestudio
Tags: easypay, payment gateway, apple pay, оплата карткою, еквайринг
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card, Apple Pay and Google Pay payments in WooCommerce through the EasyPay payment page, with signed notifications and status re-checks.

== Description ==

An independent WooCommerce payment gateway for EasyPay (easypay.ua, Ukraine), built on the EasyPay Merchant API. The buyer is sent to the secure EasyPay payment page, and the order is confirmed automatically once EasyPay reports the payment.

EasyPay is a third-party product; this plugin is an independent integration, not affiliated with or endorsed by EasyPay. You need your own EasyPay merchant account to accept live payments.

Features:

* Creates an EasyPay order and sends the buyer to the EasyPay payment page: Visa/Mastercard, Apple Pay and Google Pay — whatever is connected for your merchant. Card data never touches your site.
* Confirms the order from the EasyPay notification: the `Sign` header is verified, and the payment status is then requested from EasyPay itself before the order is marked paid.
* Reconciles the status when the buyer returns to the shop, in case the notification is late.
* Guards against double charging: the browser "Back" button after a payment, a notification racing the buyer's return, and a paid amount lower than the order total.
* Sandbox with the public EasyPay test merchant from the EasyPay documentation — test the whole flow without a contract.
* "Check keys" button that verifies PartnerKey, ServiceKey and the signature without creating an order.
* Optional EasyPay reminder about an unpaid order (SMS or Viber) to the buyer's phone.
* Optional filter that accepts notifications only from the EasyPay IP addresses.
* SecretKey stored encrypted; classic and block checkout; HPOS compatible.

= Опис українською =

Платіжний модуль EasyPay для WooCommerce: оплата карткою Visa/Mastercard, Apple Pay і Google Pay на захищеній сторінці EasyPay. Замовлення підтверджується підписаним сповіщенням, а статус оплати додатково перевіряється запитом до EasyPay. Є пісочниця з публічним тестовим мерчантом, кнопка перевірки ключів, звірка статусу після повернення покупця, захист від подвійної оплати, класичний і блоковий чекаут, сумісність з HPOS. Валюта — гривня (UAH).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it (WooCommerce must be active).
2. Go to WooCommerce → Settings → Payments → EasyPay.
3. Leave the sandbox on to try it with the EasyPay test merchant (test cards are listed in the EasyPay Merchant API documentation), or turn it off and enter the PartnerKey, ServiceKey and SecretKey issued by your EasyPay manager.
4. Press "Check keys".
5. The notification URL is sent with every order, so nothing has to be configured in the EasyPay account.

== External services ==

This plugin connects to the EasyPay Merchant API (`https://merchantapi.easypay.ua`), operated by EasyPay (easypay.ua). The connection is required for the gateway to work: without it no payment can be created or verified.

What is sent, and when:

* When a customer places an order with this gateway: a session request (`/api/system/createApp`, `/api/system/createPage`) with your PartnerKey, then `/api/merchant/createOrder` with your ServiceKey, the EasyPay order number built from the order ID, the amount, the order description (order number and shop name), the return and notification URLs of your site, the customer's e-mail address and — only if the "Unpaid order reminder" option is on — the customer's phone number.
* When EasyPay sends a notification, when the customer returns from the payment page, on the thank-you page and when you press "Check keys": `/api/merchant/orderState` with your ServiceKey and the EasyPay order number, to read the current payment status.

Every request is signed with your SecretKey, which never leaves your server. The customer enters card details on the EasyPay page; card data is never sent to or stored by your site. Nothing is sent to EasyPay unless a payment is started, a status is checked, or you press "Check keys".

Service provider: EasyPay (easypay.ua).
Public offer (terms): https://cdn.easypay.ua/marketing/rules/public_offer_generalkd__.pdf
Personal data processing: https://cdn.easypay.ua/docs/Personal_data_and_use_phone.pdf

== Frequently Asked Questions ==

= Do I need a contract with EasyPay? =

For real payments, yes. The sandbox works without one.

= Which currencies are supported? =

Hryvnia (UAH) only. The gateway hides itself when the store currency is different.

= The buyer paid but the order is still pending. =

Make sure the site is reachable from the internet over HTTPS and the SecretKey is right ("Check keys"). The order is also reconciled when the buyer returns to the shop.

= Does card data pass through my site? =

No. The card is entered on the EasyPay page; the shop receives only a signed notification.

= Does it work with the block checkout? =

Yes, both the classic shortcode checkout and the block-based checkout are supported.

== Changelog ==

= 1.0.1 =
* Fixed: on sites where every language has a URL prefix (Polylang), the buyer came back from EasyPay to a "Page not found" page instead of the thank-you page.

= 1.0.0 =
* First release.
