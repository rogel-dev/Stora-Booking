<?php

if (!defined('ABSPATH')) {
	exit;
}

class Stora_Admin_Settings {
	public function __construct() {
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_init', [$this, 'register_settings']);
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
	}

	public function render_page() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$api_key = get_option('stora_api_key', '');
		$is_active = is_string($api_key) && trim($api_key) !== '';
		?>
		<div class="wrap">
			<h1>
				Stora
				<?php if ($is_active): ?>
					<span style="margin-left:8px; padding:2px 8px; border-radius:999px; background:#e7f7ed; color:#0a7a2f; font-size:12px; vertical-align:middle;">
						Active
					</span>
				<?php endif; ?>
			</h1>
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
							<?php if ($is_active): ?>
								<div style="margin-top:6px; color:#0a7a2f; font-weight:600;">Active</div>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button('Save'); ?>
			</form>
		</div>
		<?php
	}
}

new Stora_Admin_Settings();

