<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Adds the "OpenSalesTax" subtab under WooCommerce → Settings → Tax.
 *
 * Uses WC's standard settings hooks: the `woocommerce_get_sections_tax`
 * filter to register the section, and `woocommerce_get_settings_tax`
 * to declare the fields.
 */
final class Settings
{
    private const SECTION_ID = 'opensalestax';

    public function register(): void
    {
        add_filter('woocommerce_get_sections_tax', [$this, 'registerSection']);
        add_filter('woocommerce_get_settings_tax', [$this, 'registerFields'], 10, 2);
        add_action('woocommerce_update_options_tax_' . self::SECTION_ID, [Cache::class, 'flushAll']);
        add_action('woocommerce_update_options_tax_' . self::SECTION_ID, [$this, 'saveTaxClassMap']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    /**
     * @param mixed $sections
     * @return array<string, string>
     */
    public function registerSection($sections): array
    {
        $out = is_array($sections) ? $sections : [];
        $out[self::SECTION_ID] = 'OpenSalesTax';
        return $out;
    }

    /**
     * @param mixed  $settings
     * @param mixed  $current_section
     * @return array<int, array<string, mixed>>
     */
    public function registerFields($settings, $current_section): array
    {
        if ($current_section !== self::SECTION_ID) {
            return is_array($settings) ? $settings : [];
        }

        return [
            [
                'title' => 'OpenSalesTax',
                'type'  => 'title',
                'desc'  => $this->renderDisclaimerHtml(),
                'id'    => 'opensalestax_section_title',
            ],
            [
                'title'    => 'Engine base URL',
                'desc'     => 'Your OpenSalesTax engine URL, e.g. <code>http://your-engine:8080</code>. Required.',
                'id'       => 'opensalestax_base_url',
                'type'     => 'text',
                'css'      => 'min-width:380px;',
                'desc_tip' => 'Self-host the engine via the OpenSalesTax docker-compose. See the README for details.',
            ],
            [
                'title'    => 'API key (optional)',
                'desc'     => 'Sent as the <code>X-API-Key</code> header. Leave blank if your engine does not require auth.',
                'id'       => 'opensalestax_api_key',
                'type'     => 'password',
                'css'      => 'min-width:380px;',
            ],
            [
                'title'    => 'Cache TTL (minutes)',
                'desc'     => 'How long to cache tax calculations per (ZIP × category × line amount). 0 disables caching.',
                'id'       => 'opensalestax_cache_ttl_minutes',
                'type'     => 'number',
                'default'  => '60',
                'css'      => 'width:120px;',
            ],
            [
                'title'    => 'Error fallback',
                'desc'     => 'What to do when the engine is unreachable. <strong>block</strong> = no tax line (recommended). <strong>zero</strong> = charge $0 tax + log a warning.',
                'id'       => 'opensalestax_error_fallback',
                'type'     => 'select',
                'default'  => 'block',
                'options'  => [
                    'block' => 'Block — return no tax line (safer)',
                    'zero'  => 'Zero — charge $0 tax + log',
                ],
            ],
            [
                'title'    => 'Per-state nexus filter',
                'desc'     => 'When enabled, skip the engine call entirely for destinations outside your nexus list — WooCommerce falls back to its built-in tax-rate handling (typically: no tax). Default off = engine is consulted for every taxable line.',
                'id'       => 'opensalestax_nexus_enabled',
                'type'     => 'select',
                'default'  => '0',
                'options'  => [
                    '0' => 'Disabled (default) — engine handles every state',
                    '1' => 'Enabled — only listed states',
                ],
            ],
            [
                'title'    => 'Nexus states',
                'desc'     => 'Comma- or space-separated list of 2-letter US state codes where you have nexus, e.g. <code>MN, WI, IA</code>. Only used when the filter above is Enabled. Empty list with the filter on = no states are taxed.',
                'id'       => 'opensalestax_nexus_states',
                'type'     => 'text',
                'default'  => '',
                'css'      => 'min-width:380px;',
                'placeholder' => 'MN, WI, IA',
            ],
            [
                'title'    => 'Calculation log',
                'desc'     => 'Capture each tax calculation (cache hits, engine calls, errors) in a 50-entry ring buffer for troubleshooting. Adds one option-write per calculation; leave OFF in production unless investigating an issue.',
                'id'       => CalculationLog::ENABLED_OPTION,
                'type'     => 'select',
                'default'  => '0',
                'options'  => [
                    '0' => 'Disabled (default)',
                    '1' => 'Enabled — log recent calculations',
                ],
            ],
            [
                'title'    => 'Test connection',
                'type'     => 'opensalestax_test_button',  // custom field type rendered below
                'id'       => 'opensalestax_test_button',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'opensalestax_section_end',
            ],
            [
                'type' => 'opensalestax_tax_class_table',
                'id'   => 'opensalestax_tax_class_table',
            ],
            [
                'type' => 'opensalestax_recent_log',
                'id'   => 'opensalestax_recent_log',
            ],
        ];
    }

    /**
     * Custom field type for the "Test Connection" button.
     * Registered via woocommerce_admin_field_<type>.
     */
    public function renderTestButton(): void
    {
        add_action('woocommerce_admin_field_opensalestax_test_button', static function (): void {
            ?>
            <tr valign="top">
                <th scope="row" class="titledesc">Test connection</th>
                <td class="forminp">
                    <button type="button" class="button" id="opensalestax-test-connection">Test connection</button>
                    <span id="opensalestax-test-result" style="margin-left:1em;font-family:monospace;"></span>
                </td>
            </tr>
            <?php
        });
    }

    /**
     * Custom field type that renders the WC tax-class → OST category mapper
     * as a table of `<select>` rows. Saves through `saveTaxClassMap()` on
     * the WC settings-save action.
     */
    public function renderTaxClassTable(): void
    {
        add_action('woocommerce_admin_field_opensalestax_tax_class_table', static function (): void {
            $effective = TaxClassMap::loadEffectiveMap();
            $custom = TaxClassMap::loadCustomMap();
            $slugs = self::collectAllTaxClassSlugs($effective);

            echo '<h3 style="margin-top:2em;">' . esc_html__('Tax class → OST category mapping', 'opensalestax-for-woocommerce') . '</h3>';
            echo '<p class="description">';
            echo esc_html__('Map each WooCommerce tax class to one of the OpenSalesTax categories. Defaults below mirror the engine\'s built-in behavior; customize as needed for clothing, groceries, or any custom WC class. Saving here also updates your override map.', 'opensalestax-for-woocommerce');
            echo '</p>';

            echo '<table class="widefat striped" style="max-width:760px;">';
            echo '<thead><tr>';
            echo '<th style="width:35%;">' . esc_html__('WC tax class', 'opensalestax-for-woocommerce') . '</th>';
            echo '<th>' . esc_html__('OST category', 'opensalestax-for-woocommerce') . '</th>';
            echo '<th style="width:15%;">' . esc_html__('Status', 'opensalestax-for-woocommerce') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($slugs as $slug) {
                $label = $slug === '' ? __('Standard (empty slug)', 'opensalestax-for-woocommerce') : $slug;
                $current = $effective[$slug] ?? TaxClassMap::FALLBACK_CATEGORY;
                $isCustom = isset($custom[$slug]);

                $fieldName = 'opensalestax_tax_class_map[' . esc_attr($slug) . ']';

                echo '<tr>';
                echo '<td><code>' . esc_html((string) $label) . '</code></td>';
                echo '<td><select name="' . esc_attr($fieldName) . '" style="min-width:220px;">';
                // Skip option = empty string. Build the selected attribute as a
                // discrete string we can pass through esc_attr().
                $skipSelected = $current === TaxClassMap::SKIP_CATEGORY ? 'selected' : '';
                echo '<option value="" ' . esc_attr($skipSelected) . '>' . esc_html__('— Skip (non-taxable) —', 'opensalestax-for-woocommerce') . '</option>';
                foreach (TaxClassMap::VALID_CATEGORIES as $cat) {
                    $sel = $current === $cat ? 'selected' : '';
                    echo '<option value="' . esc_attr($cat) . '" ' . esc_attr($sel) . '>' . esc_html($cat) . '</option>';
                }
                echo '</select></td>';
                echo '<td>';
                if ($isCustom) {
                    echo '<span style="color:#2271b1;">' . esc_html__('Custom', 'opensalestax-for-woocommerce') . '</span>';
                } else {
                    echo '<span style="color:#646970;">' . esc_html__('Default', 'opensalestax-for-woocommerce') . '</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            echo '<p>';
            echo '<label><input type="checkbox" name="opensalestax_tax_class_map_reset" value="1" /> ';
            echo esc_html__('Reset all to defaults (clears any custom overrides on save)', 'opensalestax-for-woocommerce');
            echo '</label></p>';
        });
    }

    /**
     * Save handler — fires on `woocommerce_update_options_tax_opensalestax`
     * after the WC settings form posts back. Reads the
     * `opensalestax_tax_class_map[<slug>]` array from $_POST and
     * persists each via TaxClassMap::set().
     */
    public function saveTaxClassMap(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // This handler fires on `woocommerce_update_options_tax_<section>`,
        // which WC only triggers after verifying its own
        // `woocommerce-settings` nonce. Re-verify defensively here so
        // Plugin Check's nonce scanner is satisfied and so the handler is
        // safe even if a future WC release moves the verification.
        $rawNonce = '';
        if (isset($_REQUEST['_wpnonce']) && is_string($_REQUEST['_wpnonce'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field is the next call.
            $unslashed = wp_unslash($_REQUEST['_wpnonce']);
            $rawNonce = is_string($unslashed) ? sanitize_text_field($unslashed) : '';
        }
        if (!wp_verify_nonce($rawNonce, 'woocommerce-settings')) {
            return;
        }

        // Honor the "Reset all" checkbox first.
        $resetFlag = '';
        if (isset($_POST['opensalestax_tax_class_map_reset']) && is_string($_POST['opensalestax_tax_class_map_reset'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field is the next call.
            $unslashed = wp_unslash($_POST['opensalestax_tax_class_map_reset']);
            $resetFlag = is_string($unslashed) ? sanitize_text_field($unslashed) : '';
        }
        if ($resetFlag === '1') {
            TaxClassMap::reset();
            return;
        }

        if (!isset($_POST['opensalestax_tax_class_map']) || !is_array($_POST['opensalestax_tax_class_map'])) {
            return;
        }

        // Sanitize the raw $_POST array: unslash and walk every key/value
        // through sanitize_text_field() before any business logic touches it.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each entry is sanitize_text_field()'d in the foreach below.
        $rawPosted = wp_unslash($_POST['opensalestax_tax_class_map']);
        if (!is_array($rawPosted)) {
            return;
        }
        $posted = [];
        foreach ($rawPosted as $k => $v) {
            $sk = is_string($k) ? sanitize_text_field($k) : '';
            $sv = is_string($v) ? sanitize_text_field($v) : '';
            if ($sk !== '' || $k === '') {
                // Preserve empty string key (WC's standard tax class) but
                // drop non-string keys outright.
                $posted[$sk] = $sv;
            }
        }

        // Build the new custom-map atomically so we don't end up half-applied
        // if one entry has an invalid value. Validate each first.
        $validated = [];
        foreach ($posted as $slug => $cat) {
            // Skip values are valid (empty string).
            if ($cat !== TaxClassMap::SKIP_CATEGORY && !in_array($cat, TaxClassMap::VALID_CATEGORIES, true)) {
                continue; // Silently drop invalid; the dropdown shouldn't produce bad values.
            }
            $validated[$slug] = $cat;
        }

        // Persist the entire map by clearing then setting each entry. We
        // bypass TaxClassMap::set's option-write-per-call to avoid N writes
        // on save; do it as a single update_option.
        $encoded = wp_json_encode($validated);
        update_option(TaxClassMap::OPTION_KEY, $encoded === false ? '' : $encoded);
    }

    /**
     * Collect every WC tax-class slug that should appear in the mapper:
     * - WC's built-in standard ('') / standard / reduced-rate / zero-rate
     * - Any user-defined classes (read from the option WC stores them in)
     * - Anything already present in our effective map
     *
     * @param array<string, string> $effective
     * @return array<int, string>
     */
    private static function collectAllTaxClassSlugs(array $effective): array
    {
        $slugs = [
            '',           // WC Standard (empty slug)
            'reduced-rate',
            'zero-rate',
        ];

        // WC stores user-defined classes in the `woocommerce_tax_classes`
        // option as a newline-separated string of display names. Convert
        // each to a slug the same way WC does.
        $raw = get_option('woocommerce_tax_classes', '');
        if (is_string($raw) && $raw !== '') {
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                $slug = sanitize_title($name);
                if ($slug !== '' && !in_array($slug, $slugs, true)) {
                    $slugs[] = $slug;
                }
            }
        }

        // Anything in our effective map that we missed.
        foreach (array_keys($effective) as $slug) {
            if (!in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Custom field type that renders the recent-calculations log table
     * below the settings form.
     */
    public function renderRecentLog(): void
    {
        add_action('woocommerce_admin_field_opensalestax_recent_log', static function (): void {
            $entries = CalculationLog::getRecent(20);
            $enabled = CalculationLog::isEnabled();
            echo '<h3 style="margin-top:2em;">' . esc_html__('Recent calculations', 'opensalestax-for-woocommerce') . '</h3>';
            if (!$enabled) {
                echo '<p class="description">'
                    . esc_html__('Logging is currently disabled. Switch the "Calculation log" option above to "Enabled" and save changes to start capturing calculations.', 'opensalestax-for-woocommerce')
                    . '</p>';
                if (count($entries) === 0) {
                    return;
                }
                echo '<p class="description"><em>' . esc_html__('Showing entries from the last time logging was enabled.', 'opensalestax-for-woocommerce') . '</em></p>';
            }
            if (count($entries) === 0) {
                echo '<p class="description">'
                    . esc_html__('No calculations have been recorded yet. Run a test cart through checkout, then refresh this page.', 'opensalestax-for-woocommerce')
                    . '</p>';
                return;
            }
            echo '<table class="widefat striped" style="max-width:100%;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Time (UTC)', 'opensalestax-for-woocommerce') . '</th>';
            echo '<th>' . esc_html__('Source', 'opensalestax-for-woocommerce') . '</th>';
            echo '<th>' . esc_html__('ZIP', 'opensalestax-for-woocommerce') . '</th>';
            echo '<th>' . esc_html__('Category', 'opensalestax-for-woocommerce') . '</th>';
            echo '<th style="text-align:right;">' . esc_html__('Amount', 'opensalestax-for-woocommerce') . '</th>';
            echo '<th style="text-align:right;">' . esc_html__('Tax', 'opensalestax-for-woocommerce') . '</th>';
            echo '<th style="text-align:right;">' . esc_html__('Duration', 'opensalestax-for-woocommerce') . '</th>';
            echo '<th>' . esc_html__('Note / Error', 'opensalestax-for-woocommerce') . '</th>';
            echo '</tr></thead><tbody>';
            $stringify = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
            foreach ($entries as $e) {
                $tax = $e['tax_total'] ?? null;
                $dur = $e['duration_ms'] ?? null;
                $err = isset($e['error']) && is_string($e['error']) ? $e['error'] : '';
                $orderId = $e['order_id'] ?? null;
                $note = $err !== '' ? $err : ($orderId !== null ? 'order=' . $stringify($orderId) : '');
                echo '<tr>';
                echo '<td>' . esc_html($stringify($e['ts'] ?? '')) . '</td>';
                echo '<td>' . esc_html($stringify($e['source'] ?? '')) . '</td>';
                echo '<td>' . esc_html($stringify($e['zip5'] ?? '')) . '</td>';
                echo '<td>' . esc_html($stringify($e['category'] ?? '')) . '</td>';
                echo '<td style="text-align:right;">$' . esc_html($stringify($e['amount'] ?? '0')) . '</td>';
                echo '<td style="text-align:right;">' . ($tax === null ? '—' : '$' . esc_html($stringify($tax))) . '</td>';
                echo '<td style="text-align:right;">' . ($dur === null ? '—' : esc_html($stringify($dur)) . 'ms') . '</td>';
                echo '<td>' . esc_html($note) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        });
    }

    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if (!str_contains($hookSuffix, 'wc-settings')) {
            return;
        }
        // Inline a tiny JS snippet that wires the Test Connection button to
        // the AJAX action registered in ConnectionTester. Avoids shipping a
        // separate JS file for ~20 lines.
        $ajaxUrl = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('opensalestax_test_connection');
        // Built as a standard concatenated string (no heredoc) per WP-org
        // Plugin Check policy.
        $js = "(function() {\n"
            . "    const btn = document.getElementById('opensalestax-test-connection');\n"
            . "    if (!btn) return;\n"
            . "    btn.addEventListener('click', async function() {\n"
            . "        const result = document.getElementById('opensalestax-test-result');\n"
            . "        result.textContent = 'Testing\xE2\x80\xA6';\n"
            . "        try {\n"
            . '            const resp = await fetch(' . $this->jsonEncode($ajaxUrl) . ", {\n"
            . "                method: 'POST',\n"
            . "                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },\n"
            . "                body: 'action=opensalestax_test_connection&_nonce=" . esc_js($nonce) . "',\n"
            . "            });\n"
            . "            const data = await resp.json();\n"
            . "            if (data && data.ok) {\n"
            . "                result.textContent = '\xE2\x9C\x93 ' + (data.message || 'OK');\n"
            . "                result.style.color = 'green';\n"
            . "            } else {\n"
            . "                result.textContent = '\xE2\x9C\x97 ' + (data && data.error ? data.error : 'Unknown error');\n"
            . "                result.style.color = '#d63638';\n"
            . "            }\n"
            . "        } catch (e) {\n"
            . "            result.textContent = '\xE2\x9C\x97 ' + e.message;\n"
            . "            result.style.color = '#d63638';\n"
            . "        }\n"
            . "    });\n"
            . "})();\n";
        wp_register_script('opensalestax-admin', '', [], '0.1.0', true);
        wp_enqueue_script('opensalestax-admin');
        wp_add_inline_script('opensalestax-admin', $js);

        // Register the custom field renderers (idempotent).
        $this->renderTestButton();
        $this->renderRecentLog();
        $this->renderTaxClassTable();
    }

    private function renderDisclaimerHtml(): string
    {
        return '<div style="background:#fff8e5;border-left:4px solid #ffb900;padding:0.6em 1em;margin:0.5em 0;">'
            . '<p><strong>Disclaimer:</strong> Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. '
            . 'Verify against your state Department of Revenue before remitting.</p>'
            . '</div>';
    }

    private function jsonEncode(string $value): string
    {
        $encoded = json_encode($value);
        return $encoded === false ? '""' : $encoded;
    }
}
