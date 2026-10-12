<?php

if (!defined('ABSPATH')) {
	exit;
}

class Stora_Admin_Settings {

	public function __construct() {
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_init', [$this, 'handle_clear_cache']);
		add_action('admin_notices', [$this, 'render_admin_notices']);
		add_action('update_option_stora_api_key', [self::class, 'on_credentials_changed']);
		add_action('update_option_stora_client_id', [self::class, 'on_credentials_changed']);
		add_action('update_option_stora_client_secret', [self::class, 'on_credentials_changed']);
		add_action('update_option_stora_oauth_scope', [self::class, 'on_credentials_changed']);
	}

	public static function on_credentials_changed() {
		if (class_exists('Stora_Cache')) {
			Stora_Cache::clear_all();
		}
	}

	public static function oauth_scope_default(): string {
		return defined('STORA_OAUTH_SCOPE_DEFAULT')
			? (string) STORA_OAUTH_SCOPE_DEFAULT
			: 'public.site:read public.unit:read public.unit_type:read';
	}

	public static function has_api_credentials(): bool {
		if (defined('STORA_API_KEY') && is_string(STORA_API_KEY) && trim(STORA_API_KEY) !== '') {
			return true;
		}

		$client_id = function_exists('get_option') ? trim((string) get_option('stora_client_id', '')) : '';
		$client_secret = function_exists('get_option') ? trim((string) get_option('stora_client_secret', '')) : '';

		return $client_id !== '' && $client_secret !== '';
	}

	/**
	 * @return string[] Missing checkout-related OAuth scopes.
	 */
	public static function missing_checkout_scopes(): array {
		$scope = function_exists('get_option')
			? trim((string) get_option('stora_oauth_scope', self::oauth_scope_default()))
			: self::oauth_scope_default();
		$granted = preg_split('/\s+/', strtolower($scope), -1, PREG_SPLIT_NO_EMPTY);
		$required = [
			'public.contact:write',
			'public.order:write',
			'public.order:read',
			'public.coupon:read',
			'public.protection_level:read',
		];
		$missing = [];
		foreach ($required as $needed) {
			if (!in_array($needed, $granted, true)) {
				$missing[] = $needed;
			}
		}

		return $missing;
	}

	public function register_menu() {
		add_menu_page(
			'Stora',
			'Stora',
			'manage_options',
			'stora-dashboard',
			[$this, 'render_page'],
			'dashicons-admin-home',
			58
		);
	}

	public function register_settings() {
		register_setting(
			'stora_settings',
			'stora_api_key',
			[
				'type' => 'string',
				'sanitize_callback' => function ($value) {
					return is_string($value) ? trim($value) : '';
				},
				'default' => '',
			]
		);

		register_setting(
			'stora_settings',
			'stora_client_id',
			[
				'type' => 'string',
				'sanitize_callback' => function ($value) {
					return is_string($value) ? trim($value) : '';
				},
				'default' => '',
			]
		);

		register_setting(
			'stora_settings',
			'stora_client_secret',
			[
				'type' => 'string',
				'sanitize_callback' => function ($value) {
					return is_string($value) ? trim($value) : '';
				},
				'default' => '',
			]
		);

		register_setting(
			'stora_settings',
			'stora_oauth_scope',
			[
				'type' => 'string',
				'sanitize_callback' => function ($value) {
					return is_string($value) ? trim($value) : '';
				},
				'default' => self::oauth_scope_default(),
			]
		);

		register_setting(
			'stora_settings',
			'stora_webhook_secret',
			[
				'type' => 'string',
				'sanitize_callback' => function ($value) {
					return is_string($value) ? trim($value) : '';
				},
				'default' => '',
			]
		);
	}

	public function handle_clear_cache() {
		if (!current_user_can('manage_options')) {
			return;
		}
		if (!isset($_POST['stora_clear_cache'])) {
			return;
		}
		check_admin_referer('stora_clear_cache');
		if (class_exists('Stora_Cache')) {
			Stora_Cache::clear_all();
		}
		add_settings_error('stora_settings', 'stora_cache_cleared', 'Stora cache cleared.', 'updated');
	}

	public function render_admin_notices() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$on_stora_screen = $screen && isset($screen->id) && $screen->id === 'toplevel_page_stora-dashboard';
		if (!$on_stora_screen) {
			return;
		}

		if (!self::has_api_credentials()) {
			echo '<div class="notice notice-error"><p><strong>Stora:</strong> Add an API key or OAuth Client ID / Secret before using booking on the live site.</p></div>';
		}

		$missing_scopes = [];
		if (trim((string) get_option('stora_client_id', '')) !== '') {
			$missing_scopes = self::missing_checkout_scopes();
		}
		if ($missing_scopes) {
			echo '<div class="notice notice-warning"><p><strong>Stora:</strong> OAuth scope is missing checkout permissions: <code>'
				. esc_html(implode('</code>, <code>', $missing_scopes))
				. '</code>. Update the scope field below or in wp-config.php.</p></div>';
		}

		$webhook_secret = trim((string) get_option('stora_webhook_secret', ''));
		if ($webhook_secret === '' && !(class_exists('Stora_Webhook') && Stora_Webhook::allows_unsigned_requests())) {
			echo '<div class="notice notice-warning"><p><strong>Stora:</strong> Configure a webhook secret for production. Unsigned webhook requests are rejected until a secret is saved.</p></div>';
		}
	}

	public function render_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$api_key = get_option('stora_api_key', '');
		$is_active = is_string($api_key) && trim($api_key) !== '';
		$client_id = get_option('stora_client_id', '');
		$client_secret = get_option('stora_client_secret', '');
		$scope = get_option('stora_oauth_scope', self::oauth_scope_default());
		$webhook_secret = get_option('stora_webhook_secret', '');
		$has_oauth = is_string($client_id) && trim($client_id) !== '' && is_string($client_secret) && trim($client_secret) !== '';
		$webhook_url = class_exists('Stora_Webhook') ? Stora_Webhook::endpoint_url() : '';
		$plugin_version = defined('STORA_PLUGIN_VERSION') ? STORA_PLUGIN_VERSION : '1.1.0';
		settings_errors('stora_settings');
		?>
		<div class="wrap">
			<h1>
				Stora
				<?php if ($is_active || $has_oauth): ?>
					<span style="margin-left:8px; padding:2px 8px; border-radius:999px; background:#e7f7ed; color:#0a7a2f; font-size:12px; vertical-align:middle;">
						Active
					</span>
				<?php endif; ?>
			</h1>
			<p>Plugin version <code><?php echo esc_html($plugin_version); ?></code>. See <code>README.md</code> in the plugin folder for deployment steps.</p>
			<form method="post" action="options.php">
				<?php settings_fields('stora_settings'); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="stora_api_key">API key</label></th>
						<td>
							<input
								name="stora_api_key"
								id="stora_api_key"
								type="password"
								value="<?php echo esc_attr(is_string($api_key) ? $api_key : ''); ?>"
								class="regular-text"
								autocomplete="off"
							/>
							<p class="description" style="margin:6px 0 0;">
								If you have a Stora <strong>access token</strong>, paste it here. If you have a <strong>Client ID / Secret</strong>,
								use the OAuth fields below instead (recommended).
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stora_client_id">OAuth Client ID</label></th>
						<td>
							<input
								name="stora_client_id"
								id="stora_client_id"
								type="text"
								value="<?php echo esc_attr(is_string($client_id) ? $client_id : ''); ?>"
								class="regular-text"
								autocomplete="off"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stora_client_secret">OAuth Client Secret</label></th>
						<td>
							<input
								name="stora_client_secret"
								id="stora_client_secret"
								type="password"
								value="<?php echo esc_attr(is_string($client_secret) ? $client_secret : ''); ?>"
								class="regular-text"
								autocomplete="off"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stora_oauth_scope">OAuth scope</label></th>
						<td>
							<input
								name="stora_oauth_scope"
								id="stora_oauth_scope"
								type="text"
								value="<?php echo esc_attr(is_string($scope) ? $scope : self::oauth_scope_default()); ?>"
								class="large-text code"
								autocomplete="off"
							/>
							<p class="description" style="margin:6px 0 0;">
								Recommended booking scopes:<br>
								<code><?php echo esc_html(self::oauth_scope_default()); ?></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Webhook URL</th>
						<td>
							<?php if (is_string($webhook_url) && $webhook_url !== ''): ?>
								<input
									type="text"
									value="<?php echo esc_url($webhook_url); ?>"
									class="large-text code"
									readonly
									onclick="this.select();"
								/>
								<p class="description" style="margin:6px 0 0;">
									Register this URL in Stora. Subscribe to unit availability events such as
									<code>unit.updated</code>, <code>unit.available</code>, <code>unit.reserved</code>,
									<code>unit.occupied</code>, and <code>unit.unavailable</code>.
								</p>
							<?php else: ?>
								<p class="description">Webhook URL unavailable.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stora_webhook_secret">Webhook secret</label></th>
						<td>
							<input
								name="stora_webhook_secret"
								id="stora_webhook_secret"
								type="password"
								value="<?php echo esc_attr(is_string($webhook_secret) ? $webhook_secret : ''); ?>"
								class="regular-text"
								autocomplete="off"
							/>
							<p class="description" style="margin:6px 0 0;">
								<strong>Required on production.</strong> Paste the secret Stora provides when the webhook endpoint is created.
								Unsigned requests are rejected unless <code>STORA_WEBHOOK_ALLOW_UNSIGNED</code> is enabled in wp-config.php (local only).
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button('Save settings'); ?>
			</form>

			<hr>
			<h2>Maintenance</h2>
			<form method="post">
				<?php wp_nonce_field('stora_clear_cache'); ?>
				<p>Clear cached units, coupon lookup, and OAuth token. Use after changing Stora pricing or promotions.</p>
				<button type="submit" name="stora_clear_cache" value="1" class="button">Clear Stora cache</button>
			</form>
		</div>
		<?php
	}
}

new Stora_Admin_Settings();
