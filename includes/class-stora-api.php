<?php

class Stora_API {

	private $last_debug = null;

	public function get_last_debug() {
		return $this->last_debug;
	}

	/**
	 * Best URL for a unit type image. Stora Public API 2025-09 uses:
	 * `unit_type.images.default.{ original, small, medium, large }` (each a full HTTPS URL).
	 *
	 * @param array<string,mixed>|mixed $unit_type
	 */
	public static function resolve_unit_type_image_url($unit_type) {
		if (!is_array($unit_type)) {
			return '';
		}

		if (!empty($unit_type['images']) && is_array($unit_type['images'])) {
			$images = $unit_type['images'];
			if (isset($images['default']) && is_array($images['default'])) {
				$def = $images['default'];
				foreach (['medium', 'large', 'original', 'small'] as $size) {
					if (!empty($def[$size]) && is_string($def[$size]) && $def[$size] !== '') {
						return $def[$size];
					}
				}
			}
			$keys = array_keys($images);
			$is_list = $keys === range(0, count($images) - 1);
			if ($is_list) {
				foreach ($images as $img) {
					if (is_string($img) && $img !== '') {
						return $img;
					}
					if (is_array($img)) {
						foreach (['url', 'src', 'href'] as $uk) {
							if (!empty($img[$uk]) && is_string($img[$uk]) && $img[$uk] !== '') {
								return $img[$uk];
							}
						}
					}
				}
			}
		}

		$scalar_keys = ['image_url', 'image', 'photo_url', 'thumbnail_url', 'cover_image', 'hero_image', 'picture_url'];
		foreach ($scalar_keys as $k) {
			if (empty($unit_type[$k])) {
				continue;
			}
			$v = $unit_type[$k];
			if (is_string($v) && $v !== '') {
				return $v;
			}
			if (is_array($v) && !empty($v['url']) && is_string($v['url']) && $v['url'] !== '') {
				return $v['url'];
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed>|mixed $ut
	 */
	private function unit_type_has_full_dimensions($ut) {
		if (!is_array($ut) || empty($ut['dimensions']) || !is_array($ut['dimensions'])) {
			return false;
		}
		$d = $ut['dimensions'];
		return isset($d['length'], $d['width'], $d['height']);
	}

	private function enrich_units_with_unit_types($data){
		if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
			return $data;
		}

		$unit_type_ids = [];
		$needs_enrichment = false;

		foreach ($data['data'] as $u) {
			$ut = $u['unit_type'] ?? null;
			$utid = is_array($ut) ? ($ut['id'] ?? null) : null;
			if (is_string($utid) && $utid !== '') {
				$unit_type_ids[$utid] = true;
			}

			// If unit_type is missing common display fields, we should enrich.
			if (!is_array($ut) || !isset($ut['name']) || !isset($ut['prices'])) {
				$needs_enrichment = true;
			} elseif (self::resolve_unit_type_image_url($ut) === '') {
				// List responses often omit expanded images; fetch full unit type for image URLs.
				$needs_enrichment = true;
			} elseif (!array_key_exists('description', $ut)) {
				// Ensure unit type description is available for display.
				$needs_enrichment = true;
			} elseif (!$this->unit_type_has_full_dimensions($ut)) {
				$needs_enrichment = true;
			}
		}

		if (!$needs_enrichment || !$unit_type_ids) {
			return $data;
		}

		$unit_types = [];
		foreach (array_keys($unit_type_ids) as $utid) {
			$ut = $this->get_unit_type($utid);
			if (is_array($ut) && isset($ut['unit_type']) && is_array($ut['unit_type'])) {
				$unit_types[$utid] = $ut['unit_type'];
			}
		}

		if (!$unit_types) {
			return $data;
		}

		foreach ($data['data'] as $i => $u) {
			$utid = null;
			if (isset($u['unit_type']) && is_array($u['unit_type'])) {
				$utid = $u['unit_type']['id'] ?? null;
			}
			if (is_string($utid) && isset($unit_types[$utid])) {
				$data['data'][$i]['unit_type'] = $unit_types[$utid];
			}
		}

		return $data;
	}

	private function request($endpoint,$method='GET',$body=null){

		$base_url = rtrim((string) STORA_API_BASE, '/');
		$endpoint_path = ltrim((string) $endpoint, '/');
		$url = $base_url . '/' . $endpoint_path;

		$this->last_debug = [
			'endpoint' => $endpoint,
			'method' => $method,
			'url' => $url,
			'response_code' => null,
			'wp_error' => null,
			'body_snippet' => null,
		];

		if (!defined('STORA_API_KEY') || !is_string(STORA_API_KEY) || STORA_API_KEY === '') {
			$this->last_debug['wp_error'] = 'Missing STORA_API_KEY';
			return null;
		}

		$args = [
			'method'=>$method,
			'timeout' => 15,
			'headers'=>[
				'Authorization'=>'Bearer '.STORA_API_KEY,
				'Accept' => 'application/json',
				'Content-Type'=>'application/json'
			]
		];

		if($body){
			$args['body'] = json_encode($body);
		}

		$response = wp_remote_request($url,$args);

		if(is_wp_error($response)){
			$this->last_debug['wp_error'] = $response->get_error_message();
			return null;
		}

		$code = wp_remote_retrieve_response_code($response);
		$this->last_debug['response_code'] = $code;
		if ($code < 200 || $code >= 300) {
			$raw_body = wp_remote_retrieve_body($response);
			if (is_string($raw_body) && $raw_body !== '') {
				$this->last_debug['body_snippet'] = substr($raw_body, 0, 2000);
			}
			return null;
		}

		$raw_body = wp_remote_retrieve_body($response);
		if (!is_string($raw_body) || $raw_body === '') {
			return null;
		}

		$this->last_debug['body_snippet'] = substr($raw_body, 0, 2000);

		$decoded = json_decode($raw_body, true);
		if (!is_array($decoded)) {
			return null;
		}

		return $decoded;
	}

	public function get_units(){

		// Optional cache bypass for admins (useful when sites/units change in Stora).
		// Example: add `?stora_nocache=1` to the page URL while logged in.
		$can_bypass = function_exists('is_user_logged_in')
			&& is_user_logged_in()
			&& function_exists('current_user_can')
			&& current_user_can('manage_options');
		$nocache = $can_bypass && isset($_GET['stora_nocache']) && (string) $_GET['stora_nocache'] === '1';
		if ($nocache) {
			Stora_Cache::clear('units');
		}

		$cached = Stora_Cache::get('units');

		if($cached){
			// Normalise cached responses too (in case API version/shape changed).
			if (is_array($cached) && isset($cached['units']) && is_array($cached['units'])) {
				$cached = ['data' => $cached['units']];
			}

			if (is_array($cached)) {
				return $this->enrich_units_with_unit_types($cached);
			}

			return $cached;
		}

		// Stora list endpoints are paginated. Fetch all pages so we don't miss sites/units.
		$all = [];
		$page = 1;
		$limit = 100; // max per API docs
		$units_request_debug = null;

		while (true) {
			$endpoint = 'units?page=' . (int) $page . '&limit=' . (int) $limit;
			$res = $this->request($endpoint);
			if ($units_request_debug === null) {
				$units_request_debug = $this->last_debug;
			}

			$list = null;
			if (is_array($res) && isset($res['data']) && is_array($res['data'])) {
				$list = $res['data'];
			} elseif (is_array($res) && isset($res['units']) && is_array($res['units'])) {
				$list = $res['units'];
			}

			if (!is_array($list)) {
				// Preserve original behavior on failure.
				return null;
			}

			$all = array_merge($all, $list);

			$next = null;
			if (is_array($res) && isset($res['meta']) && is_array($res['meta']) && isset($res['meta']['pagination']) && is_array($res['meta']['pagination'])) {
				$next = $res['meta']['pagination']['next'] ?? null;
			}

			if ($next === null) {
				break;
			}

			$page = (int) $next;
			if ($page < 1) {
				break;
			}
		}

		$data = ['data' => $all];
		$data = $this->enrich_units_with_unit_types($data);

		// Only cache successful responses.
		if (is_array($data)) {
			Stora_Cache::set('units',$data);
		}

		// Keep debug pointing at the primary /units request.
		if ($units_request_debug !== null) {
			$this->last_debug = $units_request_debug;
		}

		return $data;
	}

	public function get_unit_type($unit_type_id){

		$unit_type_id = (string) $unit_type_id;
		if ($unit_type_id === '') {
			return null;
		}

		// Cache key includes expand so older entries without expanded images are not reused.
		$cache_key = 'unit_type_' . $unit_type_id . '_expand_images';
		$cached = Stora_Cache::get($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		// Unexpanded responses return `images.default: null`; expand=images returns real URLs.
		$endpoint = 'unit_types/' . rawurlencode($unit_type_id) . '?expand=' . rawurlencode('images');
		$data = $this->request($endpoint);
		if (is_array($data)) {
			Stora_Cache::set($cache_key, $data);
		}

		return $data;
	}

	public function create_order($data){
		return $this->request('orders','POST',$data);
	}

	public function update_order($order_id, $data){
		$order_id = (string) $order_id;
		if ($order_id === '') {
			return null;
		}
		return $this->request('orders/' . rawurlencode($order_id), 'PATCH', $data);
	}

	public function list_contacts($query = []){
		$endpoint = 'contacts';
		if (is_array($query) && $query) {
			$endpoint .= '?' . http_build_query($query);
		}
		$res = $this->request($endpoint, 'GET');

		// Normalise list response shape { contacts: [...] } to { data: [...] } for internal callers.
		if (is_array($res) && isset($res['contacts']) && is_array($res['contacts']) && !isset($res['data'])) {
			$res['data'] = $res['contacts'];
		}

		return $res;
	}

	public function list_coupons($query = []){
		$endpoint = 'coupons';
		if (is_array($query) && $query) {
			$endpoint .= '?' . http_build_query($query);
		}
		return $this->request($endpoint, 'GET');
	}

	public function validate_order($order_id, $expand = []){
		$order_id = (string) $order_id;
		if ($order_id === '') {
			return null;
		}
		$endpoint = 'orders/' . rawurlencode($order_id) . '/validate';
		if (is_array($expand) && $expand) {
			$endpoint .= '?' . http_build_query(['expand' => array_values($expand)]);
		}
		return $this->request($endpoint, 'GET');
	}

	public function finalize_order($order_id, $data){
		$order_id = (string) $order_id;
		if ($order_id === '') {
			return null;
		}
		return $this->request('orders/' . rawurlencode($order_id) . '/finalize','POST',$data);
	}

	public function create_reservation($data){

		return $this->request('reservations','POST',$data);
	}
	

}