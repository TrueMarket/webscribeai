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

In `readme.txt`, under `== Changelog ==`, add your entry at the top. A single change:

```
= 0.2.0 =
* Added Yoast SEO support.
```

Multiple changes in the same release just get one bullet each, under the one version heading:

```
= 0.2.0 =
* Added Yoast SEO support.
* Fixed broken image alt text generation.
* Improved error messages when the API key is invalid.
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

## Or just ask Claude

Instead of doing the steps above by hand, paste this prompt (fill in the changelog note — Claude figures out the next version number for you):

```
Release a new version of this plugin with this changelog note: "Changelog note"

- Determine the current version from the Stable tag in readme.txt, then bump the patch number by 1 (e.g. 0.2.1 -> 0.2.2) to get the new version X.Y.Z, unless I've said otherwise.
- Bump the version in webscribeai.php (Version header + WEBSCRIBEAI_VERSION constant) and the Stable tag in readme.txt to X.Y.Z.
- Add a changelog entry at the top of readme.txt's == Changelog == section for X.Y.Z with this note.
- Commit, push, tag vX.Y.Z, and push the tag — per the steps in README.md.
- Confirm the new version number isn't reused (must be higher than the previous Stable tag) before committing.
```

Got more than one change to ship at once? List them and Claude will add one bullet per note under the same version heading:

```
Release a new version of this plugin with these changelog notes:
- "Removed Freemius vendor code"

- Determine the current version from the Stable tag in readme.txt, then bump the patch number by 1 (e.g. 0.2.1 -> 0.2.2) to get the new version X.Y.Z, unless I've said otherwise.
- Bump the version in webscribeai.php (Version header + WEBSCRIBEAI_VERSION constant) and the Stable tag in readme.txt to X.Y.Z.
- Add a changelog entry at the top of readme.txt's == Changelog == section for X.Y.Z, with one bullet per note.
- Commit, push, tag vX.Y.Z, and push the tag — per the steps in README.md.
- Confirm the new version number isn't reused (must be higher than the previous Stable tag) before committing.
```

---

## Oops — undo a tag

If you tagged the wrong thing:

```bash
git push origin :refs/tags/v0.2.0   # delete it from GitHub
git tag -d v0.2.0                   # delete it locally
```

Fix the version, then tag again.
