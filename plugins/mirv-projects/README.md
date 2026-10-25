# MIRV Projects 1.0.4

WordPress plugin for public project catalogue.

Admin workflows:
- Public Projects > All Projects: create/edit/delete projects, title, full
  descriptions, summary, image, gallery URLs, links, technologies, status.
- Public Projects > Groups: create/change categories.
- Public Projects > Order and Display: drag to reorder groups and entries;
  set visibility, layout, column count; pin projects at top.
- Shortcodes: [mirv_projects_catalog], [mirv_projects_now].

Existing published WP pages are intentionally preserved at their canonical
/public_projects/<slug>/ URLs. Edit the new CPT records instead of the old pages.
New CPT entries automatically get normal project permalinks.
Initial backups are under /root/mirvp-backups (referenced in wp_options).

Daily home rotation is consistent for UTC day. REST refresh bypasses cached
home HTML. Project data uses native WP custom post type and taxonomy.

Unpublished migrated projects also hide their original public pages (HTTP 404); updating the record title updates the old public page's displayed title.

Catalog, legacy project pages and homepage caches are invalidated after project edits, deletion, category changes and reordering.

Settings now let you select a catalogue page and change the project permalink prefix. Fresh activation creates a simple catalogue page if none exists. Homepage cards have self-contained styling.
