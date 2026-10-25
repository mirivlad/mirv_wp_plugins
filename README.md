# MIRV WordPress Plugins

A public monorepo of independently versioned WordPress plugins.

| Directory | Plugin | Short description |
|---|---|---|
| [plugins/mirv-projects](plugins/mirv-projects) | MIRV Projects | Project catalogue with ordered categories, metadata and homepage cards |
| [plugins/mirv-category-blocks](plugins/mirv-category-blocks) | MIRV Category Blocks | WordPress category and latest-post cards |
| [plugins/mirv-plugin-manager](plugins/mirv-plugin-manager) | MIRV Plugin Manager | In-dashboard installer and GitHub Releases update bridge |

## Installation

One-time: install MIRV Plugin Manager from its GitHub Release ZIP using
WordPress Plugins > Add New Plugin > Upload Plugin. Activate the manager.

Afterwards: Plugins > MIRV Plugins > Install installs available plugins
directly from individual GitHub Releases. Each plugin can use the standard
WordPress Update now and Enable auto-updates controls.

The updater uses WordPress Update URI support (WordPress 5.8+) and verifies
release ZIP SHA-256 checksums. Only a fixed set of plugins from this repository
is trusted. Sites need outbound HTTPS to api.github.com and github.com.

## Independent releases

Tag using PLUGIN-vX.Y.Z, e.g. mirv-projects-v1.0.3.
GitHub Actions validates the version in the plugin header, builds a ZIP
containing only that directory, creates a matching ZIP SHA-256 sidecar,
and publishes both as Release assets.

The ZIP root is always the plugin slug directory. When extending this repo,
register the new plugin slug in the manager's allow-list before publishing.

The migration script that imported the author's old WordPress pages is NOT in
this public repository.

## Development

Run bash scripts/check.sh. The release workflow builds only tagged plugins.
The legacy-page shim in MIRV Projects only takes effect on sites having its
original migration metadata, so new installations work independently.
