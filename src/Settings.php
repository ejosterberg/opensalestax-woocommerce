<?php

// SPDX-License-Identifier: Apache-2.0

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
                'title'    => 'Test connection',
                'type'     => 'opensalestax_test_button',  // custom field type rendered below
                'id'       => 'opensalestax_test_button',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'opensalestax_section_end',
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
        $js = <<<JS
(function() {
    const btn = document.getElementById('opensalestax-test-connection');
    if (!btn) return;
    btn.addEventListener('click', async function() {
        const result = document.getElementById('opensalestax-test-result');
        result.textContent = 'Testing…';
        try {
            const resp = await fetch({$this->jsonEncode($ajaxUrl)}, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=opensalestax_test_connection&_nonce={$nonce}',
            });
            const data = await resp.json();
            if (data && data.ok) {
                result.textContent = '✓ ' + (data.message || 'OK');
                result.style.color = 'green';
            } else {
                result.textContent = '✗ ' + (data && data.error ? data.error : 'Unknown error');
                result.style.color = '#d63638';
            }
        } catch (e) {
            result.textContent = '✗ ' + e.message;
            result.style.color = '#d63638';
        }
    });
})();
JS;
        wp_register_script('opensalestax-admin', '', [], '0.1.0', true);
        wp_enqueue_script('opensalestax-admin');
        wp_add_inline_script('opensalestax-admin', $js);

        // Register the custom field renderer (idempotent).
        $this->renderTestButton();
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
