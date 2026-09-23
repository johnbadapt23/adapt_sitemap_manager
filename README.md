# Adapt Sitemap Manager

WordPress plugin that serves three sitemaps, configured under **Settings > Sitemap Manager**:

- **Media sitemap** (`/media-sitemap.xml`): media library documents filtered by upload date.
- **Post sitemap** (`/post-sitemap.xml`): published posts filtered by modified date and, optionally, by taxonomy terms.
- **Download sitemap** (`/download-sitemap.xml`): posts in a subscription term, linking to the resolved download file or the permalink.

## Post sitemap taxonomy filter

1. Tick one or more taxonomies (for example Categories, Tags or any custom taxonomy registered for posts).
2. For each ticked taxonomy, tick the terms to include. The term lists support search, select all and clear.
3. Choose how terms are matched:
   - **Any selected term**: a post is included if it has at least one ticked term.
   - **All selected terms**: a post is included only if it has every ticked term.

A ticked taxonomy with no terms ticked includes any post that has at least one term in that taxonomy. With no taxonomies ticked, no taxonomy filter is applied. The "Items found" counter updates live as the selection changes.

## Updates from GitHub

The plugin updates itself from the GitHub releases of this repository, in the same way as a plugin from WordPress.org:

- WordPress checks for a newer release as part of its normal update checks (roughly twice a day; results are cached for six hours).
- When a newer release exists, the usual "There is a new version" notice appears on the Plugins screen and under Dashboard > Updates, with a one-click update. Auto-updates can be enabled from the Plugins screen.
- To check immediately, click **Check for updates** in the plugin's row on the Plugins screen.

### Publishing a new version

1. Update `Version:` in the header of `adapt-sitemap-manager.php` (for example `1.2.1`).
2. Commit and push to `main`.
3. Tag the commit with the same version, prefixed with `v`, and push the tag:

   ```bash
   git tag v1.2.1
   git push origin v1.2.1
   ```

The **Release** GitHub Action then checks that the tag matches the header version, lints the PHP, builds `adapt-sitemap-manager.zip` and publishes the release. If the tag and header do not match, the workflow fails and no release is published, which prevents sites from being offered an update they can never complete.

### First installation of the updater version

Sites running a version earlier than 1.2.0 do not have the updater yet, so 1.2.0 must be installed manually once:

1. Download `adapt-sitemap-manager.zip` from the [latest release](https://github.com/johnbadapt23/adapt_sitemap_manager/releases/latest).
2. In WordPress, go to **Plugins > Add New > Upload Plugin**, upload the zip and choose **Replace current with uploaded** if prompted.

If the existing copy was installed as a single file directly in `wp-content/plugins/` (not inside a folder), deactivate and delete that file first. Otherwise both copies define the same functions and activating the new one causes a fatal error. Settings are stored in the database and are kept.

The plugin must live in its own folder (for example `wp-content/plugins/adapt-sitemap-manager/`) for updates to install correctly.

### Optional: GitHub token

The repository is public, so no token is needed. If a host shares its IP address with many other sites and hits GitHub's unauthenticated rate limit (60 requests per hour), add a read-only token to `wp-config.php`:

```php
define( 'ADSX_GITHUB_TOKEN', 'github_pat_...' );
```

## Requirements

- WordPress 5.8 or later
- PHP 7.4 or later
- ACF (for the download sitemap field resolution)
- `ZipArchive` (for the "Download All Files" exports)
