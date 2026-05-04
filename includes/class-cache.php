<?php

class Stora_Cache {

	public static function get($key){

		return get_transient('stora_'.$key);
	}

	public static function set($key,$value){

		set_transient('stora_'.$key,$value,STORA_CACHE_TTL);
	}

	public static function clear($key){

		delete_transient('stora_'.$key);
	}

}