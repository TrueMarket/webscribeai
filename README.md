# WebscribeAI

A WordPress plugin that writes SEO meta titles and descriptions for your posts and
pages using AI (Anthropic Claude or OpenAI GPT).

- **Per-post generation:** a meta box on the post/page edit screen generates a title
  and description you can preview before applying.
- **Bulk Populate:** filter posts by type, title search, or view (missing title,
  missing description, too long, errors from the last run), then generate meta for a
  selection or for everything in the view. Rows load a page at a time, so large sites
  stay fast. While a job runs, a sticky panel shows progress, the current post, an ETA,
  and the latest results, and the table follows the job page by page. A validity bar
  shows whether each existing title and description is a good length.
- **The SEO Framework integration:** writes straight into TSF's own fields when TSF
  is active, and follows its title separator and site-title settings.
- **Standalone mode:** with no SEO plugin installed, the plugin prints
  `<meta name="title">` and `<meta name="description">` tags in `<head>` itself.
- **Override protection:** existing titles and descriptions are left alone unless you
  turn on "Override existing". Each field is checked separately, so a post missing only
  its description still gets one.
- **Custom post types:** every public post type with an admin UI is supported.
- **Automatic updates** from this GitHub repo (see [How updates reach sites](#how-updates-reach-sites)).

**Requirements:** WordPress 6.0+, PHP 8.0+, and an Anthropic or OpenAI API key.

---

## Installation

1. Copy the `webscribeai` folder into `wp-content/plugins/` (or zip it and upload it
   under **Plugins → Add New → Upload Plugin**).
2. Activate **WebscribeAI** on the **Plugins** screen.
3. Go to **Tools → WebscribeAI → Settings**.
4. Choose a provider (Anthropic or OpenAI), paste your API key, and pick a model.
5. Optionally add primary and secondary keywords for the AI to work in.

API keys:
- Anthropic: https://console.anthropic.com/account/keys
- OpenAI: https://platform.openai.com/api-keys

---

## How it works

1. **Content is collected.** For a given post, the plugin gathers the title, the body
   content and any ACF field text (`includes/class-webscribeai-api.php`).
2. **The AI is asked for meta.** The content, your keywords and your TSF title format
   go into a prompt that asks for a ~60-character title and a ~160-character
   description, returned as JSON. The request goes to the Anthropic Messages API or
   the OpenAI Chat Completions API, depending on your settings.
3. **The result is saved.** `includes/class-webscribeai-seo.php` writes the values:
   - TSF active: to `_genesis_title` / `_genesis_description`.
   - No TSF: to `_webscribeai_seo_title` / `_webscribeai_seo_description`, which are
     output on the front end via `wp_head`.

Other details:

- **Usage cap:** each site can make 500 generations per month. The count resets on
  the 1st (`includes/class-webscribeai-usage.php`).
- **API key storage:** the key is encrypted at rest with AES-256-CBC using the site's
  `AUTH_KEY` salt (`includes/class-webscribeai-encryption.php`).
- **Admin requests:** generation, applying and bulk runs go through `wp_ajax_webscribeai_*`
  handlers in `admin/class-webscribeai-ajax.php`.

### Project layout

| Path | Purpose |
|------|---------|
| `webscribeai.php` | Plugin header, constants, bootstrap, update checker setup |
| `includes/class-webscribeai-api.php` | Prompt building and AI provider calls |
| `includes/class-webscribeai-seo.php` | Reading/writing meta (TSF or standalone), `wp_head` output |
| `includes/class-webscribeai-meta-box.php` | Meta box on the post edit screen |
| `includes/class-webscribeai-usage.php` | Monthly generation cap |
| `includes/class-webscribeai-encryption.php` | API key encryption |
| `admin/` | Settings / Bulk Populate screens and AJAX handlers |
| `admin/class-webscribeai-bulk-table.php` | Bulk Populate queries (filters, view counts, paging) and row rendering |
| `assets/` | Admin and meta box CSS/JS |
| `readme.txt` | WordPress-format readme, including the changelog |
| `vendor/plugin-update-checker/` | Third-party update library (don't edit) |

### How updates reach sites

The plugin bundles [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker),
set up in `init_updater()` in `webscribeai.php`. It points at this repo's `main`
branch and compares the installed version against the latest release or tag here.
When a higher version is tagged, WordPress offers the update on the **Plugins**
screen like any WordPress.org plugin. Sites check about every 12 hours. To check
immediately, click **Check for updates** under WebscribeAI on the Plugins screen.

Two rules follow from this:

1. **The tag must match the version.** Tag `v0.2.4` means `Version: 0.2.4` in
   `webscribeai.php`. If they differ, WordPress keeps offering the same update.
2. **Never reuse a version number.** Versions only go up.

---

## How to release an update

Paste this prompt to Claude Code from the plugin folder:

```
Release a new version of this plugin. Bump the Version header and
WEBSCRIBEAI_VERSION in webscribeai.php together (patch bump unless
I say otherwise). Add a new entry at the top of the == Changelog ==
section in readme.txt containing a short and concise list of all changes in the past tense.

Show me the list of changes before commiting.

Commit everything, tag the commit vX.Y.Z to match, and push both main and the tag to origin.
```

To undo a tag that was pushed by mistake:

```bash
git push origin :refs/tags/vX.Y.Z   # delete it from GitHub
git tag -d vX.Y.Z                   # delete it locally
```
