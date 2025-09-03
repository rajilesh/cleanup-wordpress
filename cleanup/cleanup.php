<?php
/**
 * Plugin Name: CPT Cleanup (Bulk Delete Custom Post Types)
 * Description: Safely bulk-delete items of selected or all custom post types. Includes dry-run and WP-CLI.
 * Version: 1.0.0
 * Author: You
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class CPT_Cleanup {
    const NONCE = 'cpt_cleanup_nonce';
    const SLUG  = 'cpt-cleanup';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_tools_page']);
        add_action('admin_post_cpt_cleanup_run', [$this, 'handle_form']);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('cpt-cleanup', [$this, 'register_cli_command']);
        }
    }

    public function add_tools_page() {
        add_management_page(
            'CPT Cleanup',
            'CPT Cleanup',
            'manage_options',
            self::SLUG,
            [$this, 'render_page']
        );
    }

    private function get_custom_post_types() {
        // Non-builtin post types only
        $types = get_post_types(['_builtin' => false], 'objects');
        // Sort by label for UX
        uasort($types, function($a, $b){ return strcasecmp($a->labels->name, $b->labels->name); });
        return $types;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        $types = $this->get_custom_post_types();
        ?>
        <div class="wrap">
            <h1>CPT Cleanup</h1>
            <p><strong>Danger zone:</strong> This tool permanently deletes posts of the selected custom post types. Always take a database backup first.</p>
            <?php if (empty($types)) : ?>
                <div class="notice notice-info"><p>No non-builtin custom post types were found.</p></div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE, self::NONCE); ?>
                    <input type="hidden" name="action" value="cpt_cleanup_run" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Select CPTs</th>
                            <td>
                                <label><input type="checkbox" id="cpt_cleanup_select_all" /> <strong>All custom post types</strong></label>
                                <p style="margin-top:8px;">Or choose specific types:</p>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                                <?php foreach ($types as $type => $obj): ?>
                                    <label style="display:block;border:1px solid #ddd;padding:6px;border-radius:4px;">
                                        <input class="cpt_cleanup_type" type="checkbox" name="types[]" value="<?php echo esc_attr($type); ?>" />
                                        <?php echo esc_html($obj->labels->name); ?>
                                        <code style="opacity:.7"><?php echo esc_html($type); ?></code>
                                    </label>
                                <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Batch size</th>
                            <td>
                                <input type="number" name="batch_size" value="200" min="10" max="1000" />
                                <p class="description">How many posts to delete per batch (helps avoid timeouts).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Dry run</th>
                            <td>
                                <label><input type="checkbox" name="dry_run" value="1" /> Count only (don’t delete)</label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Confirmation</th>
                            <td>
                                <input type="text" name="confirm" placeholder='Type DELETE to confirm' style="width:260px;" />
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Run Cleanup', 'delete'); ?>
                </form>
                <script>
                    (function(){
                        const all = document.getElementById('cpt_cleanup_select_all');
                        const boxes = document.querySelectorAll('.cpt_cleanup_type');
                        if (all) {
                            all.addEventListener('change', e => {
                                boxes.forEach(b => b.checked = all.checked);
                            });
                        }
                    })();
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_form() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) wp_die('Invalid nonce.');

        $selected_types = isset($_POST['types']) && is_array($_POST['types']) ? array_map('sanitize_key', $_POST['types']) : [];
        $batch_size     = isset($_POST['batch_size']) ? max(10, min(1000, intval($_POST['batch_size']))) : 200;
        $dry_run        = !empty($_POST['dry_run']);
        $confirm        = isset($_POST['confirm']) ? trim(sanitize_text_field($_POST['confirm'])) : '';

        $all_types = array_keys($this->get_custom_post_types());
        if (empty($selected_types)) {
            // If user ticked "All", the JS checks all boxes; but as a fallback, choose all when none submitted
            $selected_types = $all_types;
        } else {
            // Validate against actual CPT list
            $selected_types = array_values(array_intersect($selected_types, $all_types));
        }

        if (empty($selected_types)) {
            $this->redirect_notice('No custom post types selected.', 'error');
        }

        if (!$dry_run && strtoupper($confirm) !== 'DELETE') {
            $this->redirect_notice('Confirmation failed. Type DELETE to proceed (or enable Dry Run).', 'error');
        }

        $result = $this->process_deletions($selected_types, $batch_size, $dry_run);

        $msg = $dry_run
            ? sprintf('Dry run complete: %d total item(s) found across %d CPT(s). Nothing was deleted.', $result['total_found'], count($selected_types))
            : sprintf('Deletion complete: %d item(s) deleted across %d CPT(s).', $result['total_deleted'], count($selected_types));

        $this->redirect_notice($msg, 'success');
    }

    private function process_deletions(array $types, int $batch_size, bool $dry_run) : array {
        @set_time_limit(0);
        wp_suspend_cache_invalidation(true);

        $total_found = 0;
        $total_deleted = 0;

        foreach ($types as $type) {
            // Count first (for both dry run and progress)
            $count = (int) wp_count_posts($type)->publish
                   + (int) wp_count_posts($type)->draft
                   + (int) wp_count_posts($type)->pending
                   + (int) wp_count_posts($type)->private
                   + (int) wp_count_posts($type)->future
                   + (int) wp_count_posts($type)->trash;

            // Fallback accurate count via query for any custom statuses
            $q = new WP_Query([
                'post_type'              => $type,
                'post_status'            => 'any',
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => false,
            ]);
            if (isset($q->found_posts) && $q->found_posts > $count) {
                $count = (int) $q->found_posts;
            }
            $total_found += $count;

            if ($dry_run || $count === 0) continue;

            // Batch delete loop
            do {
                $ids = get_posts([
                    'post_type'              => $type,
                    'post_status'            => 'any',
                    'numberposts'            => $batch_size,
                    'fields'                 => 'ids',
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'suppress_filters'       => true,
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]);

                if (empty($ids)) break;

                foreach ($ids as $id) {
                    // Hard delete (skip Trash) for completeness; change to false to use Trash.
                    $deleted = wp_delete_post($id, true);
                    if ($deleted) $total_deleted++;
                }

                // Be nice to server
                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }
                usleep(100000); // 100ms
            } while (!empty($ids));
        }

        wp_suspend_cache_invalidation(false);
        return ['total_found' => $total_found, 'total_deleted' => $total_deleted];
    }

    private function redirect_notice($message, $type = 'success') {
        $url = add_query_arg([
            'page' => self::SLUG,
            self::SLUG . '_notice' => rawurlencode($message),
            self::SLUG . '_type'   => $type
        ], admin_url('tools.php'));
        wp_safe_redirect($url);
        exit;
    }

    // Hook admin_notices to show our message
    public static function maybe_admin_notice() {
        if (!is_admin()) return;
        if (!isset($_GET[self::SLUG . '_notice'])) return;
        $type = isset($_GET[self::SLUG . '_type']) ? sanitize_key($_GET[self::SLUG . '_type']) : 'success';
        $msg  = wp_kses_post(rawurldecode($_GET[self::SLUG . '_notice']));
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), $msg);
    }

    /**
     * WP-CLI: wp cpt-cleanup delete --all | --types=book,course [--dry-run] [--batch-size=200] [--force]
     */
    public function register_cli_command($args, $assoc_args) {
        if (!class_exists('WP_CLI')) return;

        $sub = $args[0] ?? null;
        if ($sub !== 'delete') {
            WP_CLI::error('Usage: wp cpt-cleanup delete --all | --types=foo,bar [--dry-run] [--batch-size=200] [--force]');
        }

        $all        = isset($assoc_args['all']);
        $types_arg  = isset($assoc_args['types']) ? (string)$assoc_args['types'] : '';
        $dry_run    = isset($assoc_args['dry-run']);
        $batch_size = isset($assoc_args['batch-size']) ? max(10, min(1000, (int)$assoc_args['batch-size'])) : 200;
        $force      = isset($assoc_args['force']); // Just a second confirmation layer for CLI

        $types = array_keys($this->get_custom_post_types());
        if (!$all && !$types_arg) {
            WP_CLI::error('Provide --all or --types=list');
        }

        $selected = $all ? $types : array_values(array_intersect($types, array_map('sanitize_key', array_filter(array_map('trim', explode(',', $types_arg))))));
        if (empty($selected)) {
            WP_CLI::error('No valid custom post types found from your selection.');
        }

        if (!$dry_run && !$force) {
            WP_CLI::error('Refusing to delete without --force. Add --dry-run to preview or --force to proceed.');
        }

        WP_CLI::log(($dry_run ? 'Dry run' : 'Deleting') . ' for CPTs: ' . implode(', ', $selected));
        $res = $this->process_deletions($selected, $batch_size, $dry_run);
        if ($dry_run) {
            WP_CLI::success(sprintf('Dry run complete: %d item(s) found.', $res['total_found']));
        } else {
            WP_CLI::success(sprintf('Deletion complete: %d item(s) deleted.', $res['total_deleted']));
        }
    }
}

add_action('plugins_loaded', function() {
    (new CPT_Cleanup());
});
add_action('admin_notices', ['CPT_Cleanup', 'maybe_admin_notice']);
