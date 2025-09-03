<?php
/**
 * Plugin Name: Purge Non‑Admin Users
 * Description: Safely delete all users who do not have the Administrator capability. Includes a dry‑run, role filters, multisite support, and a WP‑CLI command.
 * Version: 1.0.0
 * Author: Your Name
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

class Purge_Non_Admin_Users_Plugin {
	const NONCE_ACTION = 'prau_purge_users';
	const OPTION_KEY   = 'prau_settings';

	public function __construct() {
		// Admin UI
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('network_admin_menu', [$this, 'add_network_menu']);
		add_action('admin_init', [$this, 'maybe_handle_action']);

		// Default settings
		add_action('admin_init', function() {
			$defaults = [
				'exclude_roles' => [ 'administrator' ], // always enforced in code too
				'dry_run'       => true,
				'scope'         => is_multisite() ? 'site' : 'site', // site|network
			];
			$current = get_site_option(self::OPTION_KEY, []);
			if (empty($current)) {
				update_site_option(self::OPTION_KEY, $defaults);
			}
		});

		// WP-CLI
		if (defined('WP_CLI') && WP_CLI) {
			WP_CLI::add_command('users purge_non_admins', [$this, 'cli_purge_command']);
		}
	}

	public function add_menu() {
		add_users_page(
			'Purge Non‑Admin Users',
			'Purge Non‑Admins',
			'manage_options',
			'prau_purge',
			[$this, 'render_page']
		);
	}

	public function add_network_menu() {
		if (!is_multisite()) return;
		add_submenu_page(
			'users.php',
			'Network Purge Non‑Admin Users',
			'Purge Non‑Admins (Network)',
			'manage_network_users',
			'prau_network_purge',
			[$this, 'render_network_page']
		);
	}

	private function get_settings() {
		$settings = get_site_option(self::OPTION_KEY, []);
		// Sanitize
		$settings['exclude_roles'] = isset($settings['exclude_roles']) && is_array($settings['exclude_roles'])
			? array_unique(array_map('sanitize_key', $settings['exclude_roles']))
			: ['administrator'];
		$settings['dry_run'] = isset($settings['dry_run']) ? (bool) $settings['dry_run'] : true;
		$settings['scope']   = isset($settings['scope']) && in_array($settings['scope'], ['site','network'], true)
			? $settings['scope']
			: (is_multisite() ? 'site' : 'site');
		return $settings;
	}

	public function render_page() {
		if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions.'));
		$settings = $this->get_settings();
		$this->render_ui('site', $settings);
	}

	public function render_network_page() {
		if (!current_user_can('manage_network_users')) wp_die(__('Insufficient permissions.'));
		$settings = $this->get_settings();
		$this->render_ui('network', $settings);
	}

	private function render_ui($context, $settings) {
		$is_network = ($context === 'network');
		?>
		<div class="wrap">
			<h1><?php echo esc_html($is_network ? 'Purge Non‑Admin Users (Network)' : 'Purge Non‑Admin Users'); ?></h1>
			<p><strong>Danger zone:</strong> This tool will delete all users that <em>do not</em> have the Administrator capability. It will never delete the current user or any user with Administrator capabilities. Take a full database backup before running.</p>

			<form method="post">
				<?php wp_nonce_field(self::NONCE_ACTION, '_wpnonce'); ?>
				<input type="hidden" name="prau_action" value="save_settings" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Exclude Roles</th>
						<td>
							<?php
							$editable_roles = get_editable_roles();
							foreach ($editable_roles as $role => $details) {
								$checked = in_array($role, $settings['exclude_roles'], true) ? 'checked' : '';
								printf(
									'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="exclude_roles[]" value="%1$s" %2$s> %3$s</label>',
									esc_attr($role),
									$checked,
									esc_html($details['name'])
								);
							}
							?>
							<p class="description">Selected roles will be spared. Administrators are always excluded regardless of this list.</p>
						</td>
					</tr>
					<?php if (is_multisite()) : ?>
					<tr>
						<th scope="row">Scope</th>
						<td>
							<select name="scope">
								<option value="site" <?php selected($settings['scope'], 'site'); ?>>Current site only</option>
								<option value="network" <?php selected($settings['scope'], 'network'); ?>>Entire network (all sites)</option>
							</select>
							<p class="description">Choose whether to purge users from this site only or across the network.</p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row">Dry run</th>
						<td>
							<label><input type="checkbox" name="dry_run" value="1" <?php checked($settings['dry_run'], true); ?>> Do not delete; just list how many users <em>would</em> be deleted.</label>
						</td>
					</tr>
				</table>

				<?php submit_button('Save settings'); ?>
			</form>

			<hr />

			<form method="post" onsubmit="return confirm('This will permanently delete users who are not admins. Have you taken a full backup?');">
				<?php wp_nonce_field(self::NONCE_ACTION, '_wpnonce'); ?>
				<input type="hidden" name="prau_action" value="purge" />
				<p>
					<label for="prau_confirm">Type <strong>DELETE</strong> to confirm:</label>
					<input type="text" name="prau_confirm" id="prau_confirm" value="" class="regular-text" />
				</p>
				<?php submit_button($settings['dry_run'] ? 'Run Dry‑Run' : 'Delete Non‑Admin Users', 'delete'); ?>
			</form>
		</div>
		<?php
	}

	public function maybe_handle_action() {
		if (!is_admin()) return;
		$action = isset($_POST['prau_action']) ? sanitize_key($_POST['prau_action']) : '';
		if (!$action) return;
		if (!check_admin_referer(self::NONCE_ACTION)) return;

		if ($action === 'save_settings') {
			if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions.'));
			$exclude = isset($_POST['exclude_roles']) ? array_map('sanitize_key', (array) $_POST['exclude_roles']) : [];
			$dry     = isset($_POST['dry_run']);
			$scope   = isset($_POST['scope']) ? sanitize_key($_POST['scope']) : 'site';
			$payload = [
				'exclude_roles' => array_values(array_unique(array_filter($exclude))),
				'dry_run'       => (bool) $dry,
				'scope'         => in_array($scope, ['site','network'], true) ? $scope : 'site',
			];
			update_site_option(self::OPTION_KEY, $payload);
			add_action('admin_notices', function() {
				printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Settings saved.'));
			});
		} elseif ($action === 'purge') {
			$confirm = isset($_POST['prau_confirm']) ? trim(wp_unslash($_POST['prau_confirm'])) : '';
			if (strtoupper($confirm) !== 'DELETE') {
				add_action('admin_notices', function() {
					printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('Confirmation failed. Type DELETE to proceed.'));
				});
				return;
			}
			$this->handle_purge();
		}
	}

	private function handle_purge() {
		$settings = $this->get_settings();
		$scope_network = is_multisite() && $settings['scope'] === 'network';

		$result = $scope_network ? $this->purge_network($settings) : $this->purge_site(get_current_blog_id(), $settings);

		$label = $settings['dry_run'] ? 'Dry‑run' : 'Deleted';
		$total = intval($result['total']);
		$across = $scope_network ? ' across the network' : '';
		$message = sprintf('%s %d user(s)%s.', $label, $total, $across);
		add_action('admin_notices', function() use ($message) {
			printf('<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html($message));
		});
	}

	private function purge_network(array $settings) : array {
		$total = 0;
		$sites = get_sites(['fields' => 'ids']);
		foreach ($sites as $site_id) {
			switch_to_blog($site_id);
			$res = $this->purge_site($site_id, $settings);
			$total += intval($res['total']);
			restore_current_blog();
		}
		return ['total' => $total];
	}

	private function purge_site(int $site_id, array $settings) : array {
		$args = [
			'fields' => ['ID'],
			'number' => -1,
			'exclude' => [get_current_user_id()], // never delete self
		];

		$users = get_users($args);
		$count = 0;
		foreach ($users as $user) {
			$uid = intval($user->ID);

			// Always skip administrators or anyone with the capability.
			if (user_can($uid, 'administrator')) continue;

			// Skip excluded roles if the user has any of them on this site.
			$roles_here = function_exists('get_userdata') ? (array) get_userdata($uid)->roles : [];
			if (!empty(array_intersect($settings['exclude_roles'], $roles_here))) continue;

			if ($settings['dry_run']) {
				$count++;
				continue;
			}

			if (is_multisite()) {
				// Remove from this blog only. Do not delete the user account globally unless they are not a member of any site.
				remove_user_from_blog($uid, $site_id);
				// If user is not a member of any sites anymore, delete the network user entirely.
				$blogs = get_blogs_of_user($uid);
				if (empty($blogs)) {
					wpmu_delete_user($uid);
				}
			} else {
				wp_delete_user($uid);
			}
			$count++;
		}
		return ['total' => $count];
	}

	/**
	 * WP‑CLI: wp users purge_non_admins [--network] [--no-dry-run] [--exclude-roles=role1,role2]
	 */
	public function cli_purge_command($args, $assoc_args) {
		$dry = !isset($assoc_args['no-dry-run']);
		$exclude_roles = isset($assoc_args['exclude-roles'])
			? array_map('sanitize_key', array_filter(array_map('trim', explode(',', $assoc_args['exclude-roles']))))
			: ['administrator'];
		$scope_network = is_multisite() && isset($assoc_args['network']);

		$settings = [
			'exclude_roles' => $exclude_roles,
			'dry_run' => $dry,
			'scope' => $scope_network ? 'network' : 'site',
		];

		$result = $scope_network ? $this->purge_network($settings) : $this->purge_site(get_current_blog_id(), $settings);
		$action = $dry ? 'Would delete' : 'Deleted';
		$across = $scope_network ? ' across the network' : '';
		WP_CLI::success(sprintf('%s %d user(s)%s.', $action, intval($result['total']), $across));
	}
}

new Purge_Non_Admin_Users_Plugin();
