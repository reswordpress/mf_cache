<?php

class mf_cache
{
	function __construct()
	{
		list($this->upload_path, $this->upload_url) = get_uploads_folder('mf_cache', true);
		$this->clean_url = get_site_url_clean(array('trim' => "/"));
	}

	function fetch_request()
	{
		$this->http_host = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : "");
		$this->request_uri = $_SERVER['REQUEST_URI'];

		$this->clean_url = $this->http_host.$this->request_uri;
	}

	function create_dir()
	{
		$this->dir2create = $this->upload_path.trim($this->clean_url, "/");

		if(!is_dir($this->dir2create))
		{
			if(preg_match("/\?/", $this->dir2create) || !mkdir($this->dir2create, 0755, true))
			{
				do_log(sprintf(__("I could not create %s", 'lang_cache'), $this->dir2create));

				return false;
			}
		}

		return true;
	}

	function parse_file_address($suffix = 'html')
	{
		$this->suffix = $suffix;

		if($this->create_dir())
		{
			$this->file_address = $this->dir2create."/index.".$this->suffix;
		}

		else
		{
			$this->file_address = $this->upload_path.$this->http_host."-".md5($this->request_uri).".".$this->suffix;
		}
	}

	function is_user_cache_allowed()
	{
		if(is_user_logged_in())
		{
			if(get_option('setting_activate_logged_in_cache', 'no') == 'yes')
			{
				return true;
			}

			else
			{
				return false;
			}
		}

		else
		{
			return true;
		}
	}

	function get_or_set_file_content()
	{
		if(count($_POST) == 0 && $this->is_user_cache_allowed() && strlen($this->file_address) <= 255 && file_exists(realpath($this->file_address)) && filesize($this->file_address) > 0)
		{
			$out = get_file_content(array('file' => $this->file_address));

			if(get_option_or_default('setting_cache_debug') == 'yes')
			{
				switch($this->suffix)
				{
					case 'html':
						$out .= "<!-- Cached ".date("Y-m-d H:i:s")." -->";
					break;

					case 'json':
						$arr_out = json_decode($out, true);
						$arr_out['cached'] = date("Y-m-d H:i:s");
						//$arr_out['cached_file'] = $this->file_address;
						$out = json_encode($arr_out);
					break;
				}
			}

			echo $out;
			exit;
		}
		
		else
		{
			ob_start(array($this, 'cache_save'));
		}
	}

	function compress_html($in)
	{
		$exkludera = array('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '/>(\n|\r|\t|\r\n|  |	)+/', '/(\n|\r|\t|\r\n|  |	)+</');
		$inkludera = array('', '>', '<');

		$out = preg_replace($exkludera, $inkludera, $in);

		//If content is empty at this stage something has gone wrong and should be reversed
		if(strlen($out) == 0)
		{
			$out = $in;
		}

		else
		{
			if(get_option_or_default('setting_cache_debug') == 'yes')
			{
				$out .= "<!-- Compressed ".date("Y-m-d H:i:s")." -->";
			}
		}

		return $out;
	}

	function cache_save($out)
	{
		if(strlen($out) > 0)
		{
			switch($this->suffix)
			{
				case 'html':
					if(get_option_or_default('setting_compress_html', 'yes') == 'yes')
					{
						$out = $this->compress_html($out);
					}
				break;
			}

			if(count($_POST) == 0)
			{
				$success = set_file_content(array('file' => $this->file_address, 'mode' => 'w', 'content' => $out));

				if(get_option_or_default('setting_cache_debug') == 'yes')
				{
					switch($this->suffix)
					{
						case 'html':
							$out .= "<!-- Dynamic ".date("Y-m-d H:i:s")." -->";
						break;

						case 'json':
							$arr_out = json_decode($out, true);
							$arr_out['dynamic'] = date("Y-m-d H:i:s");
							$out = json_encode($arr_out);
						break;
					}
				}

				/*if($success == false)
				{
					do_log(sprintf(__("I could not save the cache for %s", 'lang_cache'), $this->file_address));
				}*/
			}
		}

		return $out;
	}

	function count_files()
	{
		global $globals;

		$upload_path_site = $this->upload_path.trim($this->clean_url, "/"); //."/"

		$globals['count'] = 0;
		$globals['date_first'] = $globals['date_last'] = "";
		get_file_info(array('path' => $upload_path_site, 'callback' => "count_files"));

		$this->file_amount = $globals['count'];

		return $this->file_amount;
	}

	function clear($time_limit = 0)
	{
		$upload_path_site = $this->upload_path.trim($this->clean_url, "/"); //."/"

		if($this->count_files() > 0)
		{
			get_file_info(array('path' => $upload_path_site, 'callback' => "delete_files", 'folder_callback' => "delete_folders", 'time_limit' => $time_limit));

			$this->count_files();
		}
	}

	function populate()
	{
		$arr_data = array();

		foreach(get_post_types(array('public' => true, 'exclude_from_search' => false), 'names') as $post_type)
		{
			if($post_type != 'attachment')
			{
				//do_log("Populate all with post_type: ".$post_type);

				get_post_children(array('post_type' => $post_type), $arr_data);
			}
		}

		foreach($arr_data as $post_id => $post_title)
		{
			if(get_post_status($post_id) == 'publish')
			{
				get_url_content(get_permalink($post_id)); //list($content, $headers) = , true
			}
		}

		update_option('mf_cache_prepopulated', date("Y-m-d H:i:s"));
	}
}