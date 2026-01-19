# WC Centralized Variation Price Manager

**Bulk edit WooCommerce variation prices from one centralized dashboard.** Update regular and sale prices for all products sharing the same attribute combination — in seconds.

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-3.0%2B-purple.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-777BB4.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## The Problem

Managing WooCommerce variable product prices is tedious. If you sell products with shared attributes (like **Size: Large** or **Color: Red**), updating prices means:

- Opening each product individually
- Navigating to the Variations tab
- Finding the specific variation
- Updating the price
- Repeating for every product...

**This doesn't scale.** Stores with hundreds of products and shared variation attributes waste hours on repetitive price updates.

---

## The Solution

**WC Centralized Variation Price Manager** groups all variations by their attribute combination and lets you update them in bulk from a single screen.

| Before | After |
|--------|-------|
| Update 100 products one by one | Update all 100 in one click |
| 2+ hours of manual work | 30 seconds |
| Human errors and inconsistencies | Consistent pricing guaranteed |

---

## Features

### Core Functionality
- **Centralized Dashboard** — View all unique variation combinations in one table
- **Bulk Price Updates** — Update regular and sale prices for all matching variations instantly
- **Search & Filter** — Quickly find specific attribute combinations
- **Pagination** — Handle thousands of variations without performance issues

### Smart Updates
- **Inconsistency Detection** — Highlights variations with mismatched prices across products
- **Skip Unchanged** — Automatically skips variations that already have the target price
- **Background Processing** — Large updates run in the background; close the page and let it work

### Technical Excellence
- **HPOS Compatible** — Works with WooCommerce High-Performance Order Storage
- **Translation Ready** — Fully internationalized with Arabic translation included
- **Optimized SQL** — Direct database queries for maximum performance
- **Transaction Safe** — Database transactions ensure data integrity

---

## Screenshots

### Main Dashboard
The centralized interface showing all variation combinations with their prices.

### Bulk Update
Select a variation combination, enter new prices, and update all matching products.

### Background Processing
Monitor progress for large updates with real-time status and logs.

---

## Installation

### From GitHub
1. Download the latest release as a ZIP file
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Activate the plugin

### Manual Installation
1. Clone or download this repository
2. Upload the `wc-centralized-variation-price-manager` folder to `/wp-content/plugins/`
3. Activate through the **Plugins** menu in WordPress

---

## Usage

1. Navigate to **Variation Prices** in your WordPress admin menu
2. Browse or search for the variation combination you want to update
3. Enter the new **Regular Price** and/or **Sale Price**
4. Click **Update** to apply changes to all matching variations

### Tips
- Leave a price field empty to keep the existing value
- Clear the sale price field and click Update to remove sale pricing
- Use the search box to filter by attribute names or values

---

## Requirements

| Requirement | Version |
|------------|---------|
| WordPress | 5.0 or higher |
| WooCommerce | 3.0 or higher |
| PHP | 7.2 or higher |

---

## Frequently Asked Questions

### Will this work with any variable product?
Yes. The plugin works with all WooCommerce variable products regardless of how many attributes or variations they have.

### Is it safe to use on a live store?
Yes. The plugin uses database transactions to ensure data integrity. However, we always recommend backing up your database before bulk operations.

### Can I undo changes?
The plugin doesn't include an undo feature. Make sure to backup your database before making bulk changes.

### Does it work with WooCommerce HPOS?
Yes. The plugin is fully compatible with WooCommerce High-Performance Order Storage.

---

## Contributing

Contributions are welcome! Please feel free to submit issues or pull requests.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## Author

**Essam Barghsh**

- Website: [esssam.com](https://esssam.com)
- Company: [ashwab.com](https://ashwab.com)

---

## License

This project is licensed under the **GNU General Public License v2.0** — see the [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) file for details.

### Commercial Use Restriction

While this plugin is open source and free to use for personal and non-commercial purposes, **commercial use requires explicit permission from the author**.

If you wish to:
- Use this plugin in a commercial product or service
- Distribute this plugin as part of a paid theme or plugin bundle
- Offer services based on this plugin

Please contact **Essam Barghsh** at [esssam.com](https://esssam.com) to obtain a commercial license.

---

## Support

For bug reports and feature requests, please use the [GitHub Issues](https://github.com/yourusername/wc-centralized-variation-price-manager/issues) page.

---

## Changelog

### 1.0.0
- Initial release
- Centralized variation price management
- Bulk price updates with background processing
- Search and pagination
- Inconsistent price detection
- HPOS compatibility
- Arabic translation

---

## Keywords

WooCommerce, variation prices, bulk edit, product variations, price manager, variable products, WooCommerce plugin, bulk pricing, variation management, WordPress eCommerce, WooCommerce bulk edit, attribute pricing
