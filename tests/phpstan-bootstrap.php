<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * PHPStan bootstrap. Stubs WP / WC functions + classes the plugin uses
 * so static analysis can see them without a real WP install.
 *
 * Only stub what we actually call from src/. Don't pull in WP itself —
 * that would be slow and bring in transitive baggage.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}
if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
    }
}
if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value): bool
    {
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient(string $name): mixed
    {
        return false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $name, mixed $value, int $expiration = 0): bool
    {
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $name): bool
    {
        return true;
    }
}
if (!function_exists('wp_send_json')) {
    function wp_send_json(mixed $response, ?int $status_code = null): never
    {
        exit;
    }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action): bool|int
    {
        return 1;
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return '';
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return true;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return $str;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'http://example/wp-admin/' . $path;
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        return basename($file);
    }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $cb): void
    {
    }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $cb): void
    {
    }
}
if (!function_exists('deactivate_plugins')) {
    function deactivate_plugins(string|array $plugins): void
    {
    }
}
if (!function_exists('wp_die')) {
    function wp_die(string $message = '', string $title = '', array $args = []): never
    {
        exit;
    }
}
if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, string $src, array $deps = [], string|false|null $ver = false, bool $in_footer = false): bool
    {
        return true;
    }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|false|null $ver = false, bool $in_footer = false): void
    {
    }
}
if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        return true;
    }
}

if (!function_exists('WC')) {
    function WC(): object
    {
        return new \stdClass();
    }
}

// Database global used by Cache::flushAll + PlaceholderRate
if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $options = 'wp_options';
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }
        public function query(string $query): int|bool
        {
            return 0;
        }
        public function get_var(string $query): ?string
        {
            return null;
        }
        public function insert(string $table, array $data, ?array $format = null): int|false
        {
            return 1;
        }
        public function delete(string $table, array $where, ?array $where_format = null): int|false
        {
            return 0;
        }
    }
}

// WP-CLI stubs (only relevant when running under wp-cli).
// Note: we deliberately do NOT `define('WP_CLI', ...)` here. If we did,
// PHPStan would see the runtime check `defined('WP_CLI') && WP_CLI` as
// having a known boolean value and flag it as always-false. Leaving the
// constant undefined at analysis time matches real production behavior:
// the constant is only defined when wp-cli is actually loaded.
if (!class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static function add_command(string $name, mixed $callable): void
        {
        }
        public static function success(string $msg): void
        {
        }
        public static function error(string $msg): never
        {
            exit(1);
        }
        public static function warning(string $msg): void
        {
        }
        public static function log(string $msg): void
        {
        }
    }
}
