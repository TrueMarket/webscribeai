=== WebscribeAI ===
Contributors: jimlaroche
Tags: seo, meta, ai
Requires at least: 6.0
Tested up to: 7.0.2
Requires PHP: 8.0
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically populate SEO meta titles and descriptions using AI (Anthropic Claude or OpenAI GPT).

== Description ==

WebscribeAI uses AI to analyse your posts and pages and generate optimised SEO meta titles and descriptions.

**Key features:**

* **Per-post generation** — A meta box on every post/page edit screen lets you generate and preview meta data before applying it.
* **Bulk Populate** — Select any combination of posts and pages in the settings screen and generate all their meta data in one go.
* **Override protection** — Optionally prevent existing meta data from being overwritten.
* **The SEO Framework integration** — Writes directly to The SEO Framework's meta fields when detected.
* **Standalone mode** — Works without any SEO plugin by adding `<meta>` tags to `<head>` automatically.
* **Custom post types** — Supports all public post types that have UI.
* **Two AI providers** — Choose between Anthropic (Claude) or OpenAI (GPT).
* **Automatic updates** — Delivers plugin updates straight from the official GitHub repository.

== Installation ==

1. Upload the `webscribeai` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Settings → WebscribeAI** and enter your API key.
4. Choose your preferred AI provider and model.
5. Start generating SEO meta data from any post/page edit screen.

== Configuration ==

= API Providers =

**Anthropic (Recommended)**
Claude models are highly accurate for structured text generation tasks like SEO copy.
Get your key from: https://console.anthropic.com/account/keys

**OpenAI**
GPT models are a solid alternative.
Get your key from: https://platform.openai.com/api-keys

= Override Existing Meta =

When **unchecked** (default), title and description are protected independently: if a post/page is missing only one of the two, that one is still generated and saved, while any existing value is left untouched.

When **checked**, existing meta data will be replaced with the AI-generated values.

= Automatic Updates =

WebscribeAI checks its public GitHub repository for new releases and shows available updates on the **Plugins** screen, just like plugins hosted on WordPress.org. No configuration is required.

== Frequently Asked Questions ==

= Which AI provider should I use? =

Anthropic's Claude models are recommended for their precise, structured output which is ideal for SEO copy that must fit within character limits. Claude Opus 4.8 is the default model, and Claude Fable 5 is available for the most demanding tasks.

= Does this work with custom post types? =

Yes. WebscribeAI adds its meta box to all public post types that have a UI, including any custom post types registered by themes or other plugins.

= What happens if I don't have an SEO plugin? =

WebscribeAI automatically outputs `<meta name="title">` and `<meta name="description">` tags in your site's `<head>` for singular post/page views.

= Does it integrate with Yoast SEO or Rank Math? =

Currently only The SEO Framework is natively supported. Yoast SEO and Rank Math integration is planned for a future release. The standalone meta tags work regardless.

= How are updates delivered? =

Updates are served from the plugin's public GitHub repository. When a new release or tag is published there with a higher version number, WordPress will offer the update on the Plugins screen.

== Changelog ==

= 0.2.2 =
* Added monthly 500 generation cap, resetting on the 1st of every month.
* Added validity analyzer in bulk populate tab.
* Tightened AI prompt to stick closer to 60 characters for titles and 160 for descriptions.
* Added confirmation modal after bulk generation.

= 0.2.1 =
* Encrypt API key.

= 0.2.0 =
* Simplified Bulk Populate UI.

= 0.1.9 =
* Fixed: bulk and per-post generation no longer skips a whole post when only one of title/description is missing — the missing field is now generated while an existing field is left untouched (when "Override existing" is off).

= 0.1.8 =
* Added automatic update checking from the plugin's GitHub repository.
