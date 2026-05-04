<?php

class Stora_Webhook {

	public function __construct(){

		add_action('rest_api_init',function(){

			register_rest_route('stora/v1','/webhook',[
				'methods'=>'POST',
				'callback'=>[$this,'handle']
			]);

		});

	}

	public function handle($request){

		$payload = $request->get_json_params();

		if($payload['event']=='unit.updated'){
			Stora_Cache::clear('units');
		}

		if($payload['event']=='booking.confirmed'){
			// update order status if stored locally
		}

		return ['success'=>true];
	}

}

new Stora_Webhook();