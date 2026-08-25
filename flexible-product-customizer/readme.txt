=== Flexible Product Customizer for WooCommerce ===
Contributors: community
Tags: woocommerce, product customizer, personalization, print, webview
Requires at least: 6.5
Requires PHP: 7.4
WC requires at least: 9.6
Stable tag: 1.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Visual product customization with reusable templates, secure uploads, cart previews, production PNG files, and seven-day temporary retention.

== Description ==

Flexible Product Customizer adds a visual editor to selected WooCommerce products. Administrators create reusable templates with color attributes, attribute-specific surface images, bounded work areas, fonts, and element limits. Each product can expose a subset of template attributes and surfaces, with a price increment charged only when a surface is used.

Customers can upload PNG, JPEG, or WebP images, add styled text, move and scale elements, rotate in 90-degree steps, and preview every surface. Cylindrical templates add a live wrapped preview while preserving a flat production file. Circular surfaces clip designs to round bounds for products like buttons, clocks, and round mousepads. Saved designs appear in the cart and order. Store managers can download original uploads and transparent production PNG files from the order.

Temporary customizations expire exactly seven days after creation. An hourly cleanup removes expired files and data. Completed orders are permanent and never enter that cleanup flow.

The plugin declares HPOS and Cart/Checkout Blocks compatibility and includes REST plus WebView integration points for mobile clients.

All plugin interfaces and customer messages are available in English and Spanish from WooCommerce > Customizer settings.

== Installation ==

1. Upload and activate the plugin with WooCommerce active.
2. Create and publish a template under WooCommerce > Customization.
3. Enable customization in the product data panel and select the template.
4. Ensure PHP accepts 10 MB uploads (`upload_max_filesize=10M`, `post_max_size=12M` or greater).

== Frequently Asked Questions ==

= Which image types are accepted? =

PNG, JPEG, and WebP. PNG is recommended for transparency and print quality. Files are limited to 10 MB and 10,000 x 10,000 pixels.

= What happens after seven days? =

Unordered session files and data are deleted automatically. A stale cart line is removed the next time that cart loads. The exact expiration date is shown after saving and in the cart.

= Are order files deleted? =

No. Creating an order permanently claims the customization and disables its expiration.

== Changelog ==

= 1.6.2 =
* Moves bundled template installation to a safe init hook after WordPress rewrite is available.

= 1.6.1 =
* Ensures the bundled template library installs itself even when the plugin database version was already updated.

= 1.6.0 =
* Adds a bundled blank-template library for shirts, hoodies, sweatshirts, caps, mugs, posters, banners, tumblers, notebooks, lanyards, clocks, mousepads, buttons, and puzzles.
* Adds circular editable surface support with customer-side clipping and admin preview controls.
* Installs 121 lightweight bundled PNG mockups as reusable template assets.

= 1.5.1 =
* Adds configurable cylindrical preview angles for front, left, and right product views.
* Allows customer design objects to extend beyond the editable area while clipping the final render to the printable bounds.

= 1.5.0 =
* Separates cylindrical print maps from mockup projection frames.
* Shows a live curved product preview alongside the printable design on desktop.
* Adds optional projection masks and lighting overlays.
* Adds one-click surface duplication with independent image assignments.
* Installs a reusable cylindrical mug sample template.

= 1.4.0 =

* Added flat and cylindrical template types with automatic migration of existing templates to flat.
* Added per-surface wrap angle, top and bottom diameter, and curvature shading controls.
* Added a local Three.js wrapped preview with drag and keyboard rotation for cylindrical products.
* Kept production PNG files flat while saving the projected product view as the customer preview.

= 1.3.1 =

* Compacted mobile text controls into two horizontal rows with icon-only selection actions.
* Made outline thickness expand only while the mobile outline tool is active.

= 1.3.0 =

* Added color attributes with their own enabled surfaces and base images, including automatic migration from default/override images.
* Added collapsible attribute and surface panels, proportional pointer resizing, and integer geometry.
* Added a global WOFF2, WOFF, TTF, and OTF font library with template selection pills.
* Made saved customization mandatory before add to cart, including variation matching and visible per-surface extras.
* Added mobile contextual selection tools, adjustable outline thickness, product previews, and full-size linked cart previews.

= 1.2.1 =

* Moved the customer editor into the browser top layer so theme modules cannot cover it.
* Rebuilt the editor mobile-first with safe-area spacing, stable touch controls, and a desktop sidebar layout.
* Added dynamic viewport sizing, scroll locking, and focus restoration when closing the editor.

= 1.2.0 =

* Added confirmed template persistence before WordPress publish/update submission.
* Added pixel-based canvas, independent base image placement, and pixel editing areas.
* Added drag, edge/corner resizing, fitting, centering, and edge alignment tools.
* Migrated existing percentage templates and temporary session snapshots automatically.
* Integrated Templates and Settings as tabs of the same admin module.

= 1.1.0 =

* Added selectable English and Spanish interfaces and a central settings page.
* Added template-driven product color and surface selectors.
* Added per-surface price increments charged only when used.
* Fixed template persistence, live canvas/work-area previews, and editor initialization.
* Moved customer text color choice to the standard color picker.

= 1.0.0 =

* Initial release.
