# Contributing

## Developer Certificate of Origin (DCO)

Every commit must be signed off:

```bash
git commit -s -m "Your message"
```

CI enforces this on every PR. See https://developercertificate.org for the full text.

## License

This plugin is **dual-licensed** — recipients may use it under
either:

* [Apache License 2.0](LICENSE-APACHE.txt), OR
* [GNU GPL version 2 or later](LICENSE-GPL.txt)

See [`LICENSE`](LICENSE) for the SPDX declaration and rationale
(the dual licensing exists primarily for WordPress.org plugin
directory compatibility and to keep the OpenSalesTax portfolio's
licensing footprint consistent across ecosystems).

By contributing you agree your contribution is licensed under
**both** options — the recipient picks. This is the same pattern
the Odoo connector uses (LGPL/AGPL).

Every source file must carry an
`SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later` header.

## Dev install

```bash
git clone https://github.com/ejosterberg/opensalestax-for-woocommerce.git
cd opensalestax-for-woocommerce
composer install
```

The plugin's `composer.json` declares a VCS repository pointing at the public [`opensalestax-php`](https://github.com/ejosterberg/opensalestax-php) SDK, so Composer will clone the SDK from GitHub during install. Once the SDK lands on Packagist, the VCS entry will be removed and Composer will resolve from Packagist by default.

If you want to actively co-develop both the plugin and the SDK, clone them as siblings and override the VCS repo with a Composer path repo locally:

```
~/projects/
├── opensalestax-php/             ← the SDK
└── opensalestax-for-woocommerce/     ← this plugin
```

```bash
# in the plugin dir:
composer config repositories.opensalestax-php path '../opensalestax-php'
composer update ejosterberg/opensalestax
```

The path repo override is local to your `composer.json`; don't commit it.

## Running tests

Unit tests (no WP/WC bootstrap; uses `10up/wp_mock`):

```bash
composer test
```

Integration tests against a real WP+WooCom instance:

```bash
export WP_VM_BASE_URL=http://10.32.161.9          # the WP+WooCom test VM
export OPENSALESTAX_BASE_URL=http://10.32.161.126:8080
composer test-live
```

Integration tests are **skipped** when `WP_VM_BASE_URL` is unset.

## Static analysis + lint

```bash
composer stan      # phpstan --level=max
composer lint      # php-cs-fixer dry-run
composer lint-fix  # php-cs-fixer apply
```

CI runs all three on every push.

## Reporting issues

GitHub issues. Include:

- WordPress + WooCommerce versions (`wp core version` and `wp plugin get woocommerce --field=version`)
- The OpenSalesTax engine version (`curl http://your-engine/v1/health`)
- Your PHP version (`php --version`)
- A minimal reproducer (cart contents + shipping address ZIP)

Security issues: email ejosterberg@gmail.com directly rather than opening a public issue.
