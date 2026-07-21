=== AI SEO Filler ===
Contributors: mauromolinamazon
Donate link: https://github.com/Mauro-Molina/AI-SEO-Filler
Tags: seo, woocommerce, openai, gemini, automation
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate SEO titles, meta, focus keywords, slugs, content, and images with AI for Rank Math or Yoast.

== Description ==

AI SEO Filler helps you fill SEO fields for posts, pages, custom post types, and WooCommerce products using your own API keys. It writes meta into **Rank Math** or **Yoast SEO** (not affiliated with those products).

**What it generates**

* SEO title, meta description, and focus keyword
* Open Graph fields
* URL slug suggestions
* Optimized body content (configurable minimum word count)
* WooCommerce short descriptions
* Image alt texts
* Optional AI featured/gallery images

**Features**

* Rank Math and Yoast SEO integration with editor sync
* Preview mode with diff and SEO checklist
* Configurable fields (meta only, content, slug, alts, etc.)
* Revision backup before overwrite and per-post history with undo
* Bulk processing with pause/resume and filters
* Provider fallback (Gemini → Groq → OpenAI)
* WP-CLI: `wp ai-seo generate --post-id=42`

This plugin is not an official Rank Math, Yoast, Google, Groq, OpenAI, or Pollinations product.

== External services ==

AI SEO Filler is a **serviceware** plugin: when you generate SEO text or images, it sends content from your site to third-party AI APIs that you configure. No requests are made until an administrator saves an API key (where required) and starts a generation, preview, bulk job, image generation, or connection test.

**Data typically sent:** post title, content/excerpt (or product data), language/tone settings, focus keyword context, and prompts built by the plugin. **API keys** you enter are stored encrypted in the WordPress database and sent only to the matching provider as a Bearer/query credential. The plugin does **not** send WordPress passwords, cookies, or other plugins’ secrets.

### Google Gemini (Generative Language API)

* **Used for:** SEO text generation and optional image generation.
* **When:** Generate / preview / apply / bulk / test API / image jobs when Gemini is selected.
* **Endpoint family:** `https://generativelanguage.googleapis.com/`
* **Service:** https://ai.google.dev/
* **Terms:** https://ai.google.dev/gemini-api/terms
* **Privacy:** https://policies.google.com/privacy

### Groq

* **Used for:** SEO text generation (chat completions).
* **When:** Generate / preview / apply / bulk / test API when Groq is the active (or fallback) provider.
* **Endpoint:** `https://api.groq.com/openai/v1/chat/completions`
* **Service:** https://groq.com/
* **Terms:** https://groq.com/terms-of-use/
* **Privacy:** https://groq.com/privacy-policy/

### OpenAI

* **Used for:** SEO text generation and optional DALL·E / GPT Image generation.
* **When:** Generate / preview / apply / bulk / test API / image jobs when OpenAI is selected.
* **Endpoint family:** `https://api.openai.com/`
* **Service:** https://openai.com/
* **Terms:** https://openai.com/policies/terms-of-use/
* **Privacy:** https://openai.com/policies/privacy-policy/

### Pollinations (Flux images)

* **Used for:** Free-tier image generation (featured / gallery) without an OpenAI/Gemini image key.
* **When:** Image generation when Flux / Pollinations is selected (or auto-fallback).
* **Endpoint family:** `https://image.pollinations.ai/`
* **Service:** https://pollinations.ai/
* **Terms:** https://pollinations.ai/terms
* **Privacy:** https://pollinations.ai/privacy

You are responsible for complying with each provider’s terms and with applicable privacy laws for content you send from your site.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ai-seo-filler/` or install from the Plugins screen.
2. Activate **AI SEO Filler**.
3. Install and activate **Rank Math** or **Yoast SEO**.
4. Go to **AI SEO Filler → Settings**, choose a provider, and add your API key.
5. Open a post, page, or product and use **Generate all SEO** (or meta only / images).

== Frequently Asked Questions ==

= Which AI providers are supported? =

Google Gemini, Groq, and OpenAI for text. Images can use Pollinations (Flux), Gemini, or OpenAI.

= Do I need Rank Math or Yoast? =

Yes. The plugin writes SEO fields into one of those plugins. It does not replace them.

= Does the plugin send data without my consent? =

Generation only runs when you (or a user with the right capabilities) trigger it, or when an admin-started bulk job is processing. Configure keys under Settings first. See **External services** above.

= Where is source code / support? =

https://github.com/Mauro-Molina/AI-SEO-Filler

== Screenshots ==

1. Settings — AI providers, API keys, and status strip.
2. Post metabox — generate, preview diff, and undo.
3. Bulk processing — filters, progress, pause/resume.

== Changelog ==

= 0.3.2 =
* Plugin Check fixes: text domains, CSV export without direct filesystem calls, remove discouraged files from shipping path.

= 0.3.1 =
* Sync Rank Math permalink analyzer when applying slug (keyword-in-URL test).
* WordPress.org readiness: external services disclosure, privacy policy text, uninstall cleanup, directory assets.

= 0.3.0 =
* Stricter Rank Math keyword rules (slug, content start, occurrences).
* Thin-content warnings for meta-only mode.
* History undo improvements.

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

== Upgrade Notice ==

= 0.3.2 =
Coding standards and Plugin Check compliance improvements.

= 0.3.1 =
Recommended update for Rank Math URL keyword sync and privacy/uninstall improvements.
