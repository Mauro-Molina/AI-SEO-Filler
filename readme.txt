=== AI SEO Filler ===
Contributors: mauromolinamazon
Tags: seo, rank-math, yoast, woocommerce, ai, gemini, openai
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generates SEO fields for WooCommerce products, posts and pages using AI, optimized for Rank Math and Yoast SEO.

== Description ==

AI SEO Filler uses Google Gemini, Groq, or OpenAI to generate:

* SEO title, meta description, and focus keyword
* Open Graph fields
* URL slug suggestions
* Optimized post content (600+ words for Rank Math)
* WooCommerce short descriptions
* Image alt texts

**Features**

* Rank Math and Yoast SEO integration with Gutenberg sync
* Preview mode with diff and SEO score checklist
* Configurable field generation (meta only, content, slug, etc.)
* Revision backup before overwrite
* Bulk processing with Action Scheduler, pause/resume, filters
* Provider fallback (Gemini → Groq → OpenAI)
* WP-CLI: `wp ai-seo generate --post-id=42`
* Generation history per post

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ai-seo-filler/`
2. Activate through the Plugins menu
3. Go to **AI SEO Filler → Settings** and add your API key
4. Open a post/product and click **Generate all SEO**

== Frequently Asked Questions ==

= Which AI providers are supported? =

Google Gemini, Groq, and OpenAI (GPT-4o Mini recommended).

= Does it work with Rank Math? =

Yes. Meta fields sync to the Gutenberg sidebar in real time.

== Changelog ==

= 0.2.0 =
* Preview mode with diff and SEO checklist
* OpenAI provider and provider fallback
* Configurable generation fields and Rank Math rules
* WooCommerce rich product data
* Bulk: filters, pause/resume, Action Scheduler, CSV export
* Gutenberg sidebar panel
* WP-CLI commands
* PHPUnit tests

= 0.1.0 =
* Initial release
