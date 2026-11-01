=== DEV3 AI Shared Stock & Inventory for WooCommerce ===
Contributors: dev3tech
Tags: woocommerce, inventory, shared stock, stock management, forecast
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shared stock pools, variable deduction, attribute stock rules and AI stockout forecasts for WooCommerce.

== Description ==

Sell one physical stock through many products. A **stock pool** holds the real units (cans, metres of fabric, blank tees); every product, variation or attribute value linked to it deducts from the same number.

**Shared stock**

* Pools shared by products, variations and attribute values (for example every "Linen" item draws from the linen fabric pool).
* Variable deduction: a 6-pack deducts 6, half a metre of fabric deducts 0.5.
* Kits: link one product to several pools and each sale deducts from all of them.
* Correct across orders, cancellations, refunds with restock, failed/pending round trips and admin order edits (36 tested scenarios, legacy and HPOS order storage).
* Product stock is kept in sync, so the storefront, the quantity box, feeds, the REST API and the block checkout show the shared stock.
* A whole-cart check stops two products from one pool being bought beyond what it holds (classic and block cart/checkout).
* Works with Product Bundles, Composite Products and Mix and Match line items (choose which lines deduct).
* Low-stock and out-of-stock alerts by email, a full stock history log, CSV import (with preview) and export.

**DEV3 AI (optional, your own Google Gemini key)**

* Stockout forecast per pool: statistics first (blended daily rate, trend, weekday pattern, 95% safety buffer), then DEV3 AI explains it in plain words.
* Reorder suggestions with quantity and date, and purchase-order emails drafted for your supplier. Review, edit, then send.
* "Set up pools for me": reads your products and attributes and proposes pools to accept, edit or reject.
* Anomaly alerts: sudden stock drops and likely typos (an extra zero, swapped digits); DEV3 AI explains them and suggests a fix you approve.
* Ask your inventory: "What will run out before Black Friday?"

Everything works without AI: forecasts, reorder suggestions, template purchase orders, rule-based pool setup and built-in answers.

== Installation ==

1. Upload the plugin zip under Plugins > Add New > Upload, then activate it. WooCommerce must be active.
2. Make sure WooCommerce > Settings > Products > Inventory > "Enable stock management" is on.
3. Open **DEV3 Shared Stock** in the admin menu, create a pool and link products (or use "Set up pools for me").
4. Optional: add a Google Gemini API key under API Config to turn on the DEV3 AI features.

== Frequently Asked Questions ==

= Does it change my products' stock? =
Yes, linked products get "Manage stock" turned on and a quantity of pool stock divided by units per sale. You can turn this off in Settings.

= What happens to old orders when I change a link? =
Each order line remembers the pools and quantities it used, so cancellations and refunds always return exactly what was taken.

= Is my API key safe? =
The key is stored encrypted on your server and never sent to the browser. Inventory data is sent to Google Gemini only when you use a DEV3 AI feature.

== Screenshots ==

1. Dashboard: what runs out first and what to reorder.
2. A pool: stock, forecast chart with plain-words explanation, reorder and purchase order.
3. Alerts with a suggested fix shown as a draft.
4. "Set up pools for me" proposals.
5. Ask your inventory.

== Changelog ==

= 1.0.0 =
* Initial release.
