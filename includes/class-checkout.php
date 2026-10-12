<?php

if (!defined('ABSPATH')) {
	exit;
}

class Stora_Checkout {

	public function __construct(){

		add_shortcode('stora_checkout',[$this,'render']);

	}

	public function render(){

		$unit_id = isset($_GET['unit_id'])
			? sanitize_text_field((string) wp_unslash($_GET['unit_id']))
			: '';

		ob_start();

		include STORA_PLUGIN_PATH.'templates/checkout.php';

		return ob_get_clean();
	}

}

new Stora_Checkout();
