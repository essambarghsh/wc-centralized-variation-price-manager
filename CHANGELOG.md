# Changelog

All notable changes to WC Centralized Variation Price Manager will be documented in this file.

## [1.0.0] - 2026-01-19

### Initial Release

First public release of WC Centralized Variation Price Manager.

### Added

#### Core Features
- **Centralized Dashboard** — View all unique variation combinations in a single, sortable table
- **Bulk Price Updates** — Update regular and sale prices for all matching variations with one click
- **Search & Filter** — Quickly find specific attribute combinations by name or value
- **Pagination** — Efficiently handle thousands of variations without performance degradation

#### Smart Processing
- **Inconsistency Detection** — Visual indicators highlight variations with mismatched prices across products
- **Skip Unchanged** — Automatically skips variations that already have the target price to save processing time
- **Background Processing** — Large updates run asynchronously; close the page and let it work
- **Real-time Progress** — Monitor background task progress with status updates and logs

#### Technical
- **HPOS Compatible** — Full support for WooCommerce High-Performance Order Storage
- **Translation Ready** — Fully internationalized with `__()` and `esc_html__()` functions
- **Arabic Translation** — Complete Arabic (ar) translation included
- **Optimized SQL** — Direct database queries for maximum performance on large catalogs
- **Transaction Safe** — Database transactions ensure data integrity during bulk operations
- **WooCommerce Integration** — Seamless integration with WooCommerce admin menu

### Requirements
- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.2+

### Compatibility
- Tested up to WooCommerce 8.0
- Compatible with WooCommerce HPOS (Custom Order Tables)
