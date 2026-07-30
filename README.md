# WebscribeAI — How to Release an Update

Every site running this plugin checks this GitHub repo for new versions.
**To push an update everywhere: bump the version number, push, and tag it.**

---

## Steps

Example: you added Yoast SEO support and want to ship it as version `0.2.0`.

### 1. Bump the version in three places (they must all match)

| File | What to change |
|------|----------------|
| `webscribeai.php` | the `Version:` line in the header near the top |
| `webscribeai.php` | the `WEBSCRIBEAI_VERSION` constant just below it |
| `readme.txt` | the `Stable tag:` line |

### 2. Add a changelog note

In `readme.txt`, under `== Changelog ==`, add your entry at the top:

```
= 0.2.0 =
* Added Yoast SEO support.
```

### 3. Commit, push, tag, push the tag

Run these from the plugin folder:

```bash
git add -A
git commit -m "Release 0.2.0"
git push
git tag v0.2.0
git push origin v0.2.0
```

**Done.** Every site will show the update on the Plugins screen within ~12 hours.
To see it right away on a site: **Plugins → "Check for updates"** under WebscribeAI.

---

## Two rules to remember

1. **The tag must match the version number.** Tag `v0.2.0` = version `0.2.0`.
   Keep the tag, the `Version:` header, and `Stable tag:` identical, or WordPress
   will keep re-offering the update.
2. **Always go up, never reuse a number.** `0.1.9` → `0.2.0` → `1.0.0`, etc.

---

## Oops — undo a tag

If you tagged the wrong thing:

```bash
git push origin :refs/tags/v0.2.0   # delete it from GitHub
git tag -d v0.2.0                   # delete it locally
```

Fix the version, then tag again.
