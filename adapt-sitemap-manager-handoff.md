# Adapt Sitemap Manager — Work Log / Handoff Notes

Plugin file: `adapt-sitemap-manager.php` (now `Version: 1.2.0`; see section 8). Repository: https://github.com/johnbadapt23/adapt_sitemap_manager

This document summarizes everything changed in this session, in the order the underlying problems were found, so work can continue from a fresh session/account without re-deriving context.

**Project location:** `F:\Will optimize\adapt-sitemap-manager-project\` — this folder (containing this file and `adapt-sitemap-manager.php`) is what to connect/open when continuing this work from another account.

## Background

The plugin manages three sitemaps: Media Sitemap (raw media library files), Post Sitemap (published posts), and Download Sitemap (posts in a subscription taxonomy term, linking to either an attached downloadable file or the post permalink). All the work in this session was on the **Download Sitemap** feature and its "Download All Files" ZIP export.

## 1. Download resolution logic (the field-mapping saga)

This went through many corrections as the actual ACF field structure was clarified. The **final, current logic** lives in `adsx_get_download_url( $post_id )` and is checked in this order, first match wins:

1. **Layout-aware repeater** (`adsx_get_download_url_from_layout_module()`):
   - Read `post_layout_type` (a select field).
   - If it's `"slide-layout"`, check the `slide_preview_module` repeater first — first row whose `download` sub-field resolves to a URL/attachment wins.
   - `preview_module` is always the fallback here too (not gated by `post_layout_type` at all) — so if `slide_preview_module` has no rows/nothing resolves, or the layout is anything else (including unset), `preview_module`'s rows are checked the same way.
2. **`download_link` repeater** (`adsx_get_download_url_from_download_link()`): each row has a `download_url` **text** sub-field (plain string URL, not a file/array). First row with a valid URL wins.
3. **`share_download_url`** text field (`adsx_get_share_download_url()`): only consulted when the separate `download` field (a Yes/No select) is `"yes"`.

Supporting fixes made along the way that are still in effect:

- `adsx_resolve_url()` validates that a resolved string actually looks like a URL (`FILTER_VALIDATE_URL`) or an absolute path (starts with `/`) before accepting it — this was added because one post's `download` field held the literal text `"no"`, which the code was blindly treating as a fetchable URL, causing a log entry like `fetch failed for no`.
- `adsx_resolve_download_field()` is a shared helper that resolves a File-field-style value (string, numeric attachment ID, array with `url` key, or repeater rows) down to a URL — used for the `preview_module`/`slide_preview_module` rows.

### Debug helper

`adsx_debug_download_url( $post_id )` is defined in the plugin (not hooked to anything) for diagnosing a specific post. Run via WP-CLI:

```
wp eval 'adsx_debug_download_url( 12345 );'
```

It prints the raw values of `post_layout_type`, `preview_module`, `slide_preview_module`, `download_link`, `download`, and `share_download_url`, plus what each individual resolution stage returns and the final resolved URL. **Use this first** if a specific post's file still isn't showing up — it will show exactly which field holds the data and why the resolver isn't finding it, rather than guessing at the field structure again.

## 2. Download Sitemap date filter (new feature)

Added a date range (`From`/`To`) to the Download Sitemap settings card, matching the pattern already used by Media/Post sitemaps:

- New options: `ADSX_DOWNLOAD_DATE_FROM` / `ADSX_DOWNLOAD_DATE_TO` (option keys `adsx_download_date_from` / `adsx_download_date_to`).
- Filters by **the article's own creation/publish date** (`post_date_gmt` column) — **not** the modified date, and **not** the attached file's own upload date. This was an explicit fix: it originally used `post_modified_gmt`, which gave nonsensical results (e.g. showing the full count in one narrow window and zero everywhere else) because posts had been bulk-edited/migrated at some point, giving them all a similar modified timestamp regardless of when they were actually created.
- Confirmed and documented: even if the *attached file* itself was uploaded outside the selected date range, it's still included as long as the *article* falls inside the range — the query only ever filters on the post, never the file.
- Defaults: empty "From" → `2023-01-01`, empty "To" → today (same convention as the other two sitemaps).
- Wired into: the live AJAX counter, the "Download All Files" ZIP export, and the on-page description text.

## 3. Performance and reliability fixes for the ZIP export

Several rounds of real-world failures on the live site led to these fixes, all still in effect:

- **Counter was slow when "Attachments" was selected.** Root cause: an `attachment_url_to_postid()` database lookup was being run for *every* entry inside `adsx_get_download_entries()`, including on every keystroke of the live counter. Fixed by making that lookup opt-in (`$resolve_attachment_ids` parameter, later removed entirely — see next point) so only the actual ZIP export pays that cost, not the counter or the XML sitemap.
- **ZIP only contained a fraction of the counted files** (reported as 863 counted / 138 downloaded, then 787 / 111 after other fixes). Root cause: matching a resolved URL back to a local file (to avoid an unreliable outbound HTTP fetch to the site's own server) was too strict — it required an exact match against `attachment_url_to_postid()` or a full-URL string prefix match against `wp_upload_dir()['baseurl']`, which fails for CDN-rewritten domains, `www.` mismatches, scaled/replaced media, etc. Any entry that failed this check fell back to a **remote self-fetch** (`wp_remote_get()` back to the site's own domain), and doing that for hundreds of files in one request was failing/timing out en masse — silently, with no error surfaced.
  - **Fix:** `adsx_url_to_local_path( $url )` — matches purely on the **URL path** (e.g. `/wp-content/uploads/2026/03/file.pptx`), ignoring the domain entirely. This correctly identifies local files regardless of CDN/www/scheme differences, without touching the database, and only falls back to a remote HTTP fetch for URLs that are genuinely external.
  - Every remote-fetch failure and every "ZIP archive is empty" skip now logs to `debug.log` via `error_log()`, prefixed `ADSX:`, so future gaps are diagnosable without needing to guess again.
- **Fatal error: "Allowed memory size exhausted"** during export, at the line calling `file_get_contents()`. Root cause: every local file was being read entirely into a PHP string before being compressed into the ZIP (`addFromString()`), and once the local-path fix above meant *many more* files were resolving locally (including larger PPTX/video-embedded decks), those accumulated past the PHP memory limit.
  - **Fix:** local files are now added via `ZipArchive::addFile( $local_path, $filename )`, which streams the file straight from disk — libzip reads and compresses it incrementally at `$zip->close()` time rather than requiring the whole file resident in PHP memory at once. Only genuinely remote-fetched content (which has no local path) still goes through `addFromString()`.

## 4. ZIP filename customization (new feature)

- **Custom filenames:** `adsx_build_download_filename( $post_id, $extension )` renames each file inside the ZIP to `"<filter-types term>：<article title>.<ext>"` (using the post's first assigned term in the `filter-types` taxonomy; falls back to just the title if the post has no term).
  - Uses the **fullwidth colon "："** (Unicode U+FF1A, written in code as the UTF-8 byte escape `\xEF\xBC\x9A` so file encoding can't silently break it), not a literal `:` — a literal colon is a reserved character on Windows (NTFS treats it as an alternate-data-stream separator) and gets silently rewritten to `_` on extraction. The fullwidth colon reads almost identically but survives extraction untouched.
  - No space after the colon, per final formatting request: `Market Narratives：AI Ambition is Outpacing Financial Readiness.pdf`.
- **New setting — "ZIP filename" (Custom vs Original):** a toggle in the Download Sitemap card (only shown/relevant in "Attachments" mode) letting the custom format above be disabled in favor of each file's actual original uploaded filename. Option key: `ADSX_OPTION_FILENAME_FORMAT` (`adsx_filename_format`), values `custom` (default) / `original`. Wired into the live "Download All Files" button URL and the ZIP handler.

## 5. Duplicate-filename handling (new feature)

- **Media Sitemap** (unchanged, documented for reference): deduplicates by base filename across the whole media library — if two attachments share a base name, only one is kept (PPTX beats PPT beats everything else, ties go to the newest). The loser is dropped from both the XML sitemap and its own ZIP export entirely, not renamed.
- **Download Sitemap ZIP** (changed): previously, if two different articles produced the same ZIP filename, the second got a `-2`, `-3`, etc. suffix so both were kept. Added a new checkbox — **"Skip duplicates instead of renaming them"** — that, when enabled, omits the later duplicate from the ZIP entirely (only the first file with that name is included), instead of renaming it. Option key: `ADSX_OPTION_SKIP_DUPLICATES` (`adsx_skip_duplicate_filenames`), default off (renames, as before). Skips are logged to `debug.log`. Also only shown/relevant in "Attachments" mode.

## 6. PPTX → PDF conversion — built, then removed

A LibreOffice-based (`soffice --headless --convert-to pdf`) conversion feature was built to convert PPT/PPTX files to PDF on export, with disk caching to avoid reconverting on every export. **This was subsequently removed at request** ("we don't need the conversion to happen now while downloading") — the ZIP export currently includes files in their original format only.

If this is wanted again later: it requires LibreOffice actually installed on the server and `shell_exec()` not disabled in PHP — neither of which could be verified from this session (no server/SSH access). The removed implementation converted via a shelled-out `soffice` command, cached results in `wp-content/uploads/adsx-pdf-cache/` keyed by source path + modified time, and fell back to the original file untouched if conversion wasn't available or failed. Re-implementing would need to re-add: the `ADSX_PDF_CACHE_SUBDIR` constant, `adsx_is_shell_exec_usable()`, `adsx_find_soffice_binary()`, `adsx_rrmdir()`, `adsx_convert_to_pdf()`, and the call site inside the ZIP export loop.

## 7. Miscellaneous fixes/clarifications confirmed during this session

- **Mime types for the Media Sitemap** were already correct (PDF, Word, PPT/PPTX, Excel, text/CSV, JSON/XML all present in the `post_mime_type` whitelist) — the original PPTX complaint turned out to be about the *Download* Sitemap's field-resolution logic, not the Media Sitemap's mime filter. No code change was needed there.
- **Published-only confirmed:** both the Post Sitemap and Download Sitemap query with `'post_status' => 'publish'` only — drafts, pending, private, scheduled, and trashed posts are excluded from counts, the ZIP, and the XML output. The Media Sitemap's `'post_status' => 'inherit'` is unrelated — that's just the normal status WordPress gives all attachments regardless of their parent post's status.

## Suggested next steps for whoever continues this

1. Re-deploy the current `adapt-sitemap-manager.php` and bump the version header (currently still `1.1.1`).
2. Run a full "Download All Files" export and confirm the "Items found" count now matches the actual ZIP file count, using today's `adsx_url_to_local_path()`-based logic.
3. If any specific post's file is still missing, run `wp eval 'adsx_debug_download_url( POST_ID );'` first before making further code changes — it will show exactly which of the four resolution tiers (preview_module / slide_preview_module / download_link / share_download_url) should be firing and why it isn't.
4. Check `debug.log` for any `ADSX:` prefixed entries after an export — they now explain every skip (fetch failure, empty response, duplicate skipped) instead of failing silently.

## 8. Post Sitemap taxonomy filter and GitHub updater (v1.2.0)

### Taxonomy filter (Post Sitemap card)

- New options: `adsx_post_taxonomies` (array of taxonomy names), `adsx_post_terms` (array of term IDs), `adsx_post_tax_relation` (`OR` or `AND`, default `OR`).
- Selectable taxonomies are every taxonomy registered for `post` with `show_ui` (post formats excluded), via `adsx_get_post_filter_taxonomies()`.
- UI: tick one or more taxonomies; each ticked taxonomy shows a scrollable, searchable checkbox list of its terms (hierarchical taxonomies are indented), with "Select all" (visible search results only) and "Clear". Term lists for unticked taxonomies are hidden and disabled, so they are neither submitted nor counted.
- Matching (`adsx_build_post_tax_query()`):
  - "Any selected term" (`OR`): post has at least one ticked term.
  - "All selected terms" (`AND`): post has every ticked term.
  - A ticked taxonomy with no terms ticked means "has any term in this taxonomy" (`EXISTS`).
  - Hierarchical terms include their children (WordPress default).
- `adsx_normalize_post_tax_filter()` drops unknown taxonomies and any term that does not belong to a ticked taxonomy.
- Wired into the XML sitemap (saved options) and the live "Items found" counter (sends `post_taxonomies`, `post_terms`, `post_relation`).
- Verified against a real WordPress 6.8 install: all combinations of any/all, EXISTS, parent/child categories and save/reload produced the expected posts.

### Self-updater

- `includes/class-adsx-github-updater.php`, bootstrapped from the main file with `ADSX_GITHUB_REPO`.
- Reads the latest GitHub release (prefers a `.zip` asset; falls back to the release source archive). Only if the repo has no releases does it fall back to the highest version tag.
- Hooks: `pre_set_site_transient_update_plugins` (update notice and auto-update toggle), `plugins_api` ("View details" modal with release notes), `upgrader_source_selection` (renames GitHub's extracted folder to the installed plugin folder), plus a "Check for updates" row link that bypasses the six-hour cache.
- `.github/workflows/release.yml`: on a `v*` tag, checks the tag matches the header `Version:`, lints PHP, builds `adapt-sitemap-manager.zip` with `git archive` (respecting `.gitattributes` export-ignore) and publishes the release.
- Release process and first-install notes are in `README.md`.
- Verified with a mocked GitHub release: update offered, changelog shown, plugin replaced in place from a GitHub-style archive folder.
