<?php

class mf_cache
{
	function __construct()
	{
		list($this->upload_path, $this->upload_url) = get_uploads_folder('mf_cache', true); //.(is_user_logged_in() ? '/logged_in' : '')
		$this->clean_url = $this->clean_url_orig = get_site_url_clean(array('trim' => "/"));

		$this->site_url = get_site_url();
		$this->site_url_clean = remove_protocol(array('url' => $this->site_url));

		$this->meta_prefix = "mf_cache_";

		$this->arr_styles = $this->arr_scripts = array();
	}

	function run_cron()
	{
		global $globals;

		if(get_option('setting_activate_cache') == 'yes')
		{
			//Overall expiry
			########################
			$setting_cache_expires = get_site_option('setting_cache_expires');
			$setting_cache_api_expires = get_site_option('setting_cache_api_expires');
			$setting_cache_prepopulate = get_option('setting_cache_prepopulate');

			if($setting_cache_prepopulate == 'yes' && $setting_cache_expires > 0 && get_option('option_cache_prepopulated') < date("Y-m-d H:i:s", strtotime("-".$setting_cache_expires." hour")))
			{
				$this->clear();

				if($this->file_amount == 0)
				{
					$this->populate();
				}
			}

			else
			{
				$this->clear(array(
					'time_limit' => 60 * 60 * $setting_cache_expires,
					'time_limit_api' => 60 * $setting_cache_api_expires,
				));
			}
			########################

			//Individual expiry
			########################
			$this->get_posts2populate();

			if(isset($this->arr_posts) && is_array($this->arr_posts))
			{
				foreach($this->arr_posts as $post_id => $post_title)
				{
					$post_expires = get_post_meta($post_id, $this->meta_prefix.'expires', true);

					if($post_expires > 0)
					{
						$post_date = get_the_date("Y-m-d H:i:s", $post_id);

						if($post_date < date("Y-m-d H:i:s", strtotime("-".$post_expires." minute")))
						{
							$post_url = get_permalink($post_id);

							$this->clean_url = remove_protocol(array('url' => $post_url, 'clean' => true));
							$this->clear(array('time_limit' => 60 * $post_expires, 'allow_depth' => false));

							if($setting_cache_prepopulate == 'yes')
							{
								get_url_content(array('url' => $post_url));
							}
						}
					}
				}
			}
			########################
		}
	}

	function admin_init()
	{
		$plugin_include_url = plugin_dir_url(__FILE__);
		$plugin_version = get_plugin_version(__FILE__);

		mf_enqueue_script('script_cache', $plugin_include_url."script_wp.js", array('plugin_url' => $plugin_include_url, 'ajax_url' => admin_url('admin-ajax.php')), $plugin_version);
	}

	function check_htaccess_cache($data)
	{
		if(basename($data['file']) == ".htaccess")
		{
			$content = get_file_content(array('file' => $data['file']));

			$setting_cache_expires = get_site_option('setting_cache_expires', 24);
			$setting_cache_api_expires = get_site_option('setting_cache_api_expires');

			$file_page_expires = "modification plus ".$setting_cache_expires." ".($setting_cache_expires > 1 ? "hours" : "hour");
			$file_api_expires = $setting_cache_api_expires > 0 ? "modification plus ".$setting_cache_api_expires." ".($setting_cache_api_expires > 1 ? "minutes" : "minute") : "";

			$cache_file_path = str_replace(ABSPATH, "", WP_CONTENT_DIR)."/uploads/mf_cache/%{SERVER_NAME}%{ENV:FILTERED_REQUEST}";
			$cache_logged_in_file_path = str_replace(ABSPATH, "", WP_CONTENT_DIR)."/uploads/mf_cache/logged_in/%{SERVER_NAME}%{ENV:FILTERED_REQUEST}";

			$recommend_htaccess = "AddDefaultCharset UTF-8

			RewriteEngine On

			RewriteCond %{THE_REQUEST} ^[A-Z]{3,9}\ (.*)\ HTTP/
			RewriteRule ^(.*) - [E=FILTERED_REQUEST:%1]\n";

			$unused_test = "<IfModule mod_headers.c>
				RewriteCond %{REQUEST_URI} !^.*[^/]$
				RewriteCond %{REQUEST_URI} !^.*//.*$
				RewriteCond %{REQUEST_METHOD} !POST
				RewriteCond %{HTTP:Cookie} !^.*(comment_author_|wordpress_logged_in|wp-postpass_).*$
				RewriteCond '%{HTTP:Accept-encoding}' 'gzip'
				RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/mf_cache/%{SERVER_NAME}%{ENV:FILTERED_REQUEST}index.html.gz -f
				RewriteRule ^(.*) 'wp-content/uploads/mf_cache/%{SERVER_NAME}%{ENV:FILTERED_REQUEST}index.html.gz' [L]

				# Serve gzip compressed CSS files if they exist and the client accepts gzip.
				RewriteCond '%{HTTP:Accept-encoding}' 'gzip'
				RewriteCond '%{REQUEST_FILENAME}\.gz' -s
				RewriteRule '^(.*)\.css' '$1\.css\.gz' [QSA]

				# Serve gzip compressed JS files if they exist and the client accepts gzip.
				RewriteCond '%{HTTP:Accept-encoding}' 'gzip'
				RewriteCond '%{REQUEST_FILENAME}\.gz' -s
				RewriteRule '^(.*)\.js' '$1\.js\.gz' [QSA]

				# Serve correct content types, and prevent mod_deflate double gzip.
				RewriteRule '\.css\.gz$' '-' [T=text/css,E=no-gzip:1]
				RewriteRule '\.js\.gz$' '-' [T=text/javascript,E=no-gzip:1]

				<FilesMatch '(\.js\.gz|\.css\.gz)$'>
					# Serve correct encoding type.
					Header append Content-Encoding gzip

					# Force proxies to cache gzipped & non-gzipped css/js files separately.
					Header append Vary Accept-Encoding
				</FilesMatch>
			</IfModule>";

			if(1 == 2 && get_option('setting_activate_cache_logged_in') == 'yes')
			{
				$recommend_htaccess .= "\nRewriteCond %{REQUEST_URI} !^.*[^/]$
				RewriteCond %{REQUEST_URI} !^.*//.*$
				RewriteCond %{REQUEST_METHOD} !POST
				RewriteCond %{HTTP:Cookie} ^.*(wordpress_logged_in).*$
				RewriteCond %{DOCUMENT_ROOT}/".$cache_logged_in_file_path."index.html -f
				RewriteRule ^(.*) '".$cache_logged_in_file_path."index.html' [L]

				RewriteCond %{REQUEST_URI} !^.*[^/]$
				RewriteCond %{REQUEST_URI} !^.*//.*$
				RewriteCond %{REQUEST_METHOD} !POST
				RewriteCond %{HTTP:Cookie} ^.*(wordpress_logged_in).*$
				RewriteCond %{DOCUMENT_ROOT}/".$cache_logged_in_file_path."index.json -f
				RewriteRule ^(.*) '".$cache_logged_in_file_path."index.json' [L]";
			}

			$recommend_htaccess .= "\nRewriteCond %{REQUEST_URI} !^.*[^/]$
			RewriteCond %{REQUEST_URI} !^.*//.*$
			RewriteCond %{REQUEST_METHOD} !POST
			RewriteCond %{HTTP:Cookie} !^.*(comment_author_|wordpress_logged_in|wp-postpass_).*$
			RewriteCond %{DOCUMENT_ROOT}/".$cache_file_path."index.html -f
			RewriteRule ^(.*) '".$cache_file_path."index.html' [L]

			RewriteCond %{REQUEST_URI} !^.*[^/]$
			RewriteCond %{REQUEST_URI} !^.*//.*$
			RewriteCond %{REQUEST_METHOD} !POST
			RewriteCond %{HTTP:Cookie} !^.*(comment_author_|wordpress_logged_in|wp-postpass_).*$
			RewriteCond %{DOCUMENT_ROOT}/".$cache_file_path."index.json -f
			RewriteRule ^(.*) '".$cache_file_path."index.json' [L]

			<IfModule mod_expires.c>
				ExpiresActive On
				ExpiresDefault 'access plus 1 month'
				ExpiresByType text/html '".$file_page_expires."'
				ExpiresByType text/xml '".$file_page_expires."'
				ExpiresByType application/json '".($file_api_expires != '' ? $file_api_expires : $file_page_expires)."'
				ExpiresByType text/cache-manifest 'access plus 0 seconds'

				Header append Cache-Control 'public, must-revalidate'

				Header unset ETag
			</IfModule>

			FileETag None

			<IfModule mod_filter.c>
				AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript image/jpeg image/png image/gif image/x-icon
			</Ifmodule>";

			$old_md5 = get_match("/BEGIN MF Cache \((.*?)\)/is", $content, false);
			$new_md5 = md5($recommend_htaccess);

			if($new_md5 != $old_md5)
			{
				echo "<div class='mf_form'>"
					."<h3 class='display_warning'><i class='fa fa-exclamation-triangle yellow'></i> ".sprintf(__("Add this to the beginning of %s", 'lang_cache'), ".htaccess")."</h3>"
					."<p class='input'>".nl2br("# BEGIN MF Cache (".$new_md5.")\n".htmlspecialchars($recommend_htaccess)."\n# END MF Cache")."</p>"
				."</div>";
			}
		}
	}

	function settings_cache()
	{
		$options_area = __FUNCTION__;

		add_settings_section($options_area, "", array($this, $options_area."_callback"), BASE_OPTIONS_PAGE);

		$arr_settings = array();

		if(get_option('setting_no_public_pages') != 'yes' && get_option('setting_theme_core_login') != 'yes')
		{
			$arr_settings['setting_activate_cache'] = __("Activate", 'lang_cache');

			if(get_option('setting_activate_cache') == 'yes')
			{
				$arr_settings['setting_cache_expires'] = __("Expires", 'lang_cache');
				$arr_settings['setting_cache_api_expires'] = __("API Expires", 'lang_cache');

				if(is_plugin_active('mf_theme_core/index.php'))
				{
					$arr_settings['setting_cache_prepopulate'] = __("Prepopulate", 'lang_cache');
				}

				if(strpos(remove_protocol(array('url' => get_site_url(), 'clean' => true)), "/") == false)
				{
					$arr_settings['setting_strip_domain'] = __("Force relative URLs", 'lang_cache');
				}

				else
				{
					delete_option('setting_strip_domain');
				}

				if(get_option('setting_cache_prepopulate') == 'yes')
				{
					$arr_settings['setting_appcache_activate'] = __("Activate AppCache", 'lang_cache');

					if(get_option('setting_appcache_activate') == 'yes')
					{
						$arr_settings['setting_appcache_fallback_page'] = __("Fallback Page", 'lang_cache');
					}

					else
					{
						delete_option('setting_appcache_pages_url');
					}
				}

				$arr_settings['setting_cache_debug'] = __("Debug", 'lang_cache');
			}

			else
			{
				delete_option('setting_appcache_pages_url');
				delete_option('option_cache_prepopulated');
			}
		}

		else
		{
			$arr_settings['setting_cache_inactivated'] = __("Inactivated", 'lang_cache');

			delete_option('setting_activate_cache');
		}

		$arr_settings['setting_activate_compress'] = __("Compress & Merge", 'lang_cache');

		show_settings_fields(array('area' => $options_area, 'object' => $this, 'settings' => $arr_settings));
	}

	function settings_cache_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);

		echo settings_header($setting_key, __("Cache", 'lang_cache'));
	}

	function setting_activate_compress_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option_or_default($setting_key, 'no');

		echo show_select(array('data' => get_yes_no_for_select(), 'name' => $setting_key, 'value' => $option, 'suffix' => __("This will gather styles and scripts into one file each for faster delivery", 'lang_cache')));
	}

	function setting_activate_cache_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option_or_default($setting_key, 'no');

		echo show_select(array('data' => get_yes_no_for_select(), 'name' => $setting_key, 'value' => $option));

		if($option == 'yes')
		{
			get_file_info(array('path' => get_home_path(), 'callback' => array($this, 'check_htaccess_cache'), 'allow_depth' => false));
		}

		if($this->count_files() > 0)
		{
			$cache_debug_text = sprintf(__("%d cached files", 'lang_cache'), $this->file_amount);

			if($this->file_amount_date_first > DEFAULT_DATE)
			{
				$cache_debug_text .= " (".format_date($this->file_amount_date_first);

					if($this->file_amount_date_last > $this->file_amount_date_first && format_date($this->file_amount_date_last) != format_date($this->file_amount_date_first))
					{
						$cache_debug_text .= " - ".format_date($this->file_amount_date_last);
					}

				$cache_debug_text .= ")";
			}

			echo "<div>" // class='form_buttons'
				.show_button(array('type' => 'button', 'name' => 'btnCacheClear', 'text' => __("Clear", 'lang_cache'), 'class' => 'button-secondary'));

				if(IS_SUPER_ADMIN && is_multisite())
				{
					echo show_button(array('type' => 'button', 'name' => 'btnCacheClearAll', 'text' => __("Clear All Sites", 'lang_cache'), 'class' => 'button-secondary'));
				}

			echo "</div>
			<div id='cache_debug'>".$cache_debug_text."</div>";
		}
	}

	function setting_cache_inactivated_callback()
	{
		echo "<p>".__("Since visitors are being redirected to the login page it is not possible to activate the cache, because that would prevent the redirect to work properly.", 'lang_cache')."</p>";
	}

	function setting_cache_expires_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		settings_save_site_wide($setting_key);
		$option = get_site_option($setting_key, get_option_or_default($setting_key, 24));

		echo show_textfield(array('type' => 'number', 'name' => $setting_key, 'value' => $option, 'xtra' => "min='1' max='240'", 'suffix' => __("hours", 'lang_cache')));
	}

	function setting_cache_api_expires_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		settings_save_site_wide($setting_key);
		$option = get_site_option($setting_key, get_option($setting_key));

		$setting_max = get_site_option('setting_cache_expires', 24) * 60;

		echo show_textfield(array('type' => 'number', 'name' => $setting_key, 'value' => $option, 'xtra' => "min='0' max='".$setting_max."'", 'suffix' => __("minutes", 'lang_cache')));
	}

	function setting_cache_prepopulate_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option_or_default($setting_key, 'no');

		$suffix = "";

		if($option == 'yes')
		{
			$setting_cache_expires = get_site_option('setting_cache_expires');

			if($setting_cache_expires > 0)
			{
				$option_cache_prepopulated = get_option('option_cache_prepopulated');

				if($option_cache_prepopulated > DEFAULT_DATE)
				{
					$populate_next = format_date(date("Y-m-d H:i:s", strtotime($option_cache_prepopulated." +".$setting_cache_expires." hour")));

					$suffix = sprintf(__("The cache was last populated %s and will be populated again %s", 'lang_cache'), format_date($option_cache_prepopulated), $populate_next);
				}

				else
				{
					$suffix = sprintf(__("The cache has not been populated yet but will be %s", 'lang_cache'), get_next_cron());
				}
			}
		}

		echo show_select(array('data' => get_yes_no_for_select(), 'name' => $setting_key, 'value' => $option, 'suffix' => $suffix));

		if($option == 'yes')
		{
			$this->get_posts2populate();

			$count_posts = count($this->arr_posts);

			$option_cache_prepopulated_one = get_option('option_cache_prepopulated_one');
			$option_cache_prepopulated_total = get_option('option_cache_prepopulated_total');

			$populate_info = "";
			$length_min = 0;

			if($option_cache_prepopulated_total > 0)
			{
				$length_min = round($option_cache_prepopulated_total / 60);

				if($length_min > 0)
				{
					$populate_info = " (".sprintf(__("%s files, %s min", 'lang_cache'), $count_posts, mf_format_number($length_min, 1)).")";
					$populate_info = " (".sprintf(__("%s min", 'lang_cache'), mf_format_number($length_min, 1)).")";
				}
			}

			else if($option_cache_prepopulated_one > 0)
			{
				if($count_posts > 0)
				{
					$length_min = round($option_cache_prepopulated_one * $count_posts / 60);

					if($length_min > 0)
					{
						$populate_info = " (".sprintf(__("Approx. %s min", 'lang_cache'), mf_format_number($length_min, 1)).")";
					}
				}
			}

			echo "<div>" // class='form_buttons'
				.show_button(array('type' => 'button', 'name' => 'btnCachePopulate', 'text' => __("Populate", 'lang_cache').$populate_info, 'class' => 'button-secondary'))
			."</div>
			<div id='cache_populate'></div>";
		}
	}

	function setting_strip_domain_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option_or_default($setting_key, 'no');

		echo show_select(array('data' => get_yes_no_for_select(), 'name' => $setting_key, 'value' => $option));
	}

	function setting_appcache_activate_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option($setting_key, 'no');

		$setting_appcache_pages_url = get_option('setting_appcache_pages_url');
		$count_temp = count($setting_appcache_pages_url);

		if($count_temp > 0 && $option == 'yes')
		{
			$suffix = sprintf(__("There are %d resources added to the AppCache right now", 'lang_cache'), $count_temp);
		}

		else
		{
			$suffix = __("This will further improve the cache performance since it caches all pages on the site for offline use", 'lang_cache');
		}

		echo show_select(array('data' => get_yes_no_for_select(), 'name' => $setting_key, 'value' => $option, 'suffix' => $suffix));
	}

	function setting_appcache_fallback_page_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option($setting_key);

		$arr_data = array();
		get_post_children(array('add_choose_here' => true), $arr_data);

		echo show_select(array('data' => $arr_data, 'name' => $setting_key, 'value' => $option, 'suffix' => "<a href='".admin_url("post-new.php?post_type=page")."'><i class='fa fa-plus-circle fa-lg'></i></a>", 'description' => __("This page will be displayed as a fallback if the visitor is offline and a page on the site is not cached", 'lang_cache')));
	}

	function setting_cache_debug_callback()
	{
		$setting_key = get_setting_key(__FUNCTION__);
		$option = get_option_or_default($setting_key, 'no');

		echo show_select(array('data' => get_yes_no_for_select(), 'name' => $setting_key, 'value' => $option));

		if($option == 'yes')
		{
			echo "<div>" // class='form_buttons'
				.show_button(array('type' => 'button', 'name' => 'btnCacheTest', 'text' => __("Test", 'lang_cache'), 'class' => 'button-secondary'))
			."</div>
			<div id='cache_test'></div>";
		}
	}

	function wp_head()
	{
		if(get_option('setting_activate_cache') == 'yes')
		{
			$plugin_include_url = plugin_dir_url(__FILE__);
			$plugin_version = get_plugin_version(__FILE__);

			mf_enqueue_script('script_cache', $plugin_include_url."script.js", $plugin_version);

			if(get_option('setting_appcache_activate') == 'yes' && count(get_option('setting_appcache_pages_url')) > 0)
			{
				echo "<meta name='apple-mobile-web-app-capable' content='yes'>
				<meta name='mobile-web-app-capable' content='yes'>";
			}
		}
	}

	function fetch_request()
	{
		$this->http_host = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : "");
		$this->request_uri = $_SERVER['REQUEST_URI'];

		$this->clean_url = $this->http_host.$this->request_uri;
	}

	function is_page_inactivated()
	{
		global $post;

		if(isset($post->ID))
		{
			if(get_post_meta($post->ID, $this->meta_prefix.'expires', true) == -1)
			{
				return true;
			}
		}

		return false;
	}

	function get_header()
	{
		if(get_option('setting_activate_cache') == 'yes' && $this->is_page_inactivated() == false)
		{
			$this->fetch_request();
			$this->get_or_set_file_content();
		}
	}

	function language_attributes($html)
	{
		if(get_option('setting_activate_cache') == 'yes' && get_option('setting_appcache_activate') == 'yes' && count(get_option('setting_appcache_pages_url')) > 0)
		{
			$html .= " manifest='".$this->site_url."/wp-content/plugins/mf_cache/include/manifest.appcache.php'";
		}

		return $html;
	}

	function get_type($src)
	{
		return (substr(remove_protocol(array('url' => $src)), 0, strlen($this->site_url_clean)) == $this->site_url_clean ? 'internal' : 'external');
	}

	function admin_bar()
	{
		global $wp_admin_bar;

		if(IS_ADMIN && (get_option('setting_activate_cache') == 'yes' || get_site_option('setting_activate_compress') > 0) && $this->count_files() > 0)
		{
			$wp_admin_bar->add_node(array(
				'id' => 'cache',
				'title' => "<a href='#clear_cache' class='color_red'>".__("Clear Cache", 'lang_cache')."</a>",
			));
		}
	}

	function rwmb_meta_boxes($meta_boxes)
	{
		global $wpdb;

		if(is_plugin_active('mf_theme_core/index.php') && get_option('setting_activate_cache') == 'yes' && get_site_option('setting_cache_expires') > 0)
		{
			$setting_cache_expires = get_site_option('setting_cache_expires');

			$meta_boxes[] = array(
				'id' => $this->meta_prefix.'cache',
				'title' => __("Cache", 'lang_cache'),
				'post_types' => array('page', 'post'),
				'context' => 'side',
				'priority' => 'low',
				'fields' => array(
					array(
						'name' => __("Expires", 'lang_cache')." (".__("minutes", 'lang_cache').")",
						'id' => $this->meta_prefix.'expires',
						'type' => 'number',
						//'std' => 15,
						'attributes' => array(
							'min' => -1,
							'max' => ($setting_cache_expires * 60),
						),
						'desc' => sprintf(__("Overrides the default value (if less than %s). -1 = inactivated on this page", 'lang_cache'), $setting_cache_expires." ".__("hours", 'lang_cache')),
					),
				)
			);
		}

		return $meta_boxes;
	}

	function check_page_expiry()
	{
		$result = array();

		$out = "";

		//$obj_cache = new mf_cache();
		$this->get_posts2populate();

		$arr_posts_with_expiry = array();

		if(isset($this->arr_posts) && is_array($this->arr_posts))
		{
			foreach($this->arr_posts as $post_id => $post_title)
			{
				$post_expires = get_post_meta($post_id, $this->meta_prefix.'expires', true);

				if($post_expires > 0)
				{
					$arr_posts_with_expiry[$post_id] = array('title' => $post_title, 'expires' => $post_expires);
				}
			}
		}

		if(count($arr_posts_with_expiry) > 0)
		{
			$out .= "<h4>".__("Exceptions", 'lang_cache')." <a href='".admin_url("edit.php?post_type=page")."'><i class='fa fa-plus-circle fa-lg'></i></a></h4>
			<table class='widefat striped'>";

				foreach($arr_posts_with_expiry as $post_id => $post)
				{
					$out .= "<tr>
						<td><a href='".admin_url("post.php?post=".$post_id."&action=edit")."'>".$post['title']."</a></td>
						<td><a href='".get_permalink($post_id)."'><i class='fa fa-link fa-lg'></i></a></td>
						<td>".$post['expires']." ".__("minutes", 'lang_cache')."</td>
					</tr>";
				}

			$out .= "</table>";
		}

		else
		{
			$page_on_front = get_option('page_on_front');

			if($page_on_front > 0)
			{
				$out .= "<p><em>".sprintf(__("You can override the default value on individual pages, for example on the %shome page%s by editing and scrolling down to Cache in the right column", 'lang_cache'), "<a href='".admin_url("post.php?post=".$page_on_front."&action=edit")."'>", "</a>")."</em></p>";
			}
		}

		if($out != '')
		{
			$result['success'] = true;
			$result['message'] = $out;
		}

		else
		{
			$result['success'] = false;
			$result['error'] = "";
		}

		header('Content-Type: application/json');
		echo json_encode($result);
		die();
	}

	function clear_cache()
	{
		global $done_text, $error_text;

		$result = array();

		// Needs to init a new object to work properly
		$obj_cache = new mf_cache();

		$obj_cache->count_files();
		$obj_cache->file_amount_old = $obj_cache->file_amount;

		$obj_cache->clear();

		if($obj_cache->file_amount == 0 || $obj_cache->file_amount < $obj_cache->file_amount_old)
		{
			delete_option('option_cache_prepopulated');

			$done_text = __("I successfully cleared the cache for you", 'lang_cache');
		}

		else
		{
			$error_text = __("I could not clear the cache. Please make sure that the credentials are correct", 'lang_cache');
		}

		$out = get_notification();

		if($done_text != '')
		{
			$result['success'] = true;
			$result['message'] = $out;
		}

		else
		{
			$result['error'] = $out;
		}

		header('Content-Type: application/json');
		echo json_encode($result);
		die();
	}

	function clear_all_cache()
	{
		global $done_text, $error_text;

		$result = array();

		// Needs to init a new object to work properly
		$obj_cache = new mf_cache();
		$obj_cache->clean_url = "";

		$obj_cache->count_files();
		$obj_cache->file_amount_old = $obj_cache->file_amount;

		$obj_cache->clear();

		if($obj_cache->file_amount == 0 || $obj_cache->file_amount < $obj_cache->file_amount_old)
		{
			delete_option('option_cache_prepopulated');

			$done_text = __("I successfully cleared the cache on all sites for you", 'lang_cache');
		}

		else
		{
			$error_text = __("I could not clear the cache on all sites. Please make sure that the credentials are correct", 'lang_cache');
		}

		$out = get_notification();

		if($done_text != '')
		{
			$result['success'] = true;
			$result['message'] = $out;
		}

		else
		{
			$result['error'] = $out;
		}

		header('Content-Type: application/json');
		echo json_encode($result);
		die();
	}

	function populate_cache()
	{
		global $done_text, $error_text;

		$result = array();

		// Needs to init a new object to work properly
		$obj_cache = new mf_cache();

		$obj_cache->count_files();
		$obj_cache->file_amount_old = $obj_cache->file_amount;

		$obj_cache->clear();

		$after_clear = $this->file_amount;

		if($obj_cache->file_amount == 0 || $obj_cache->file_amount < $obj_cache->file_amount_old)
		{
			$obj_cache->populate();

			if($obj_cache->count_files() > 0)
			{
				$done_text = __("I successfully populated the cache for you", 'lang_cache');
			}

			else
			{
				$error_text = __("No files were populated", 'lang_cache');
			}

			$after_populate = $obj_cache->file_amount;
		}

		else
		{
			$error_text = __("I could not clear the cache before population. Please make sure that the credentials are correct", 'lang_cache');
		}

		$out = get_notification();

		if($done_text != '')
		{
			$result['success'] = true;
			$result['message'] = $out;
		}

		else
		{
			$result['error'] = $out;
		}

		header('Content-Type: application/json');
		echo json_encode($result);
		die();
	}

	function test_cache()
	{
		global $done_text, $error_text;

		$result = array();

		$site_url = get_site_url();

		list($content, $headers) = get_url_content(array('url' => $site_url, 'catch_head' => true));
		$time_1st = $headers['total_time'];

		if(preg_match("/\<\!\-\- Dynamic /i", $content))
		{
			list($content, $headers) = get_url_content(array('url' => $site_url, 'catch_head' => true));
			$time_2nd = $headers['total_time'];
		}

		if(!preg_match("/\<\!\-\- Dynamic /i", $content)) //preg_match("/\<\!\-\- Compressed /i", $content)
		{
			if(isset($time_2nd))
			{
				$done_text = sprintf(__("The cache was successfully tested. The site was loaded in %ss the first time and then again cached in %ss", 'lang_cache'), mf_format_number($time_1st, 1), mf_format_number($time_2nd, 2));
			}

			else
			{
				$done_text = sprintf(__("The cache was successfully tested. The site was loaded cached in %ss", 'lang_cache'), mf_format_number($time_1st, 2));
			}
		}

		else
		{
			$error_text = __("Something is not working as it should. Let an admin have a look and fix any issues with it", 'lang_cache');
		}

		$out = get_notification();

		if($done_text != '')
		{
			$result['success'] = true;
			$result['message'] = $out;
		}

		else
		{
			$result['error'] = $out;
		}

		header('Content-Type: application/json');
		echo json_encode($result);
		die();
	}

	function should_load_as_url()
	{
		if(substr($this->arr_resource['file'], 0, 3) == "/wp-")
		{
			$this->arr_resource['file'] = $this->site_url.$this->arr_resource['file'];
		}

		$this->arr_resource['file'] = validate_url($this->arr_resource['file'], false);

		/*else if(substr(remove_protocol(array('url' => $this->arr_resource['file'])), 0, strlen($this->site_url_clean)) != $this->site_url_clean)
		{
			$this->arr_resource['type'] = 'external';
		}*/

		return ($this->arr_resource['type'] == 'external'); // || get_file_suffix($this->arr_resource['file']) == 'php'
	}

	function enqueue_style($data)
	{
		if($data['file'] != '' && (get_option('setting_activate_cache') == 'yes' || get_option('setting_activate_compress') == 'yes'))
		{
			$this->arr_styles[$data['handle']] = array(
				'source' => 'known',
				'type' => $this->get_type($data['file']),
				'file' => $data['file'],
				'version' => $data['version'],
			);
		}
	}

	function print_styles()
	{
		global $error_text;

		if(get_option('setting_activate_cache') == 'yes' || get_option('setting_activate_compress') == 'yes')
		{
			$file_url_base = $this->site_url."/wp-content";
			$file_dir_base = WP_CONTENT_DIR;

			//Does not work in files where relative URLs to images or fonts are used
			#####################
			/*global $wp_styles;

			//do_log("Styles: ".var_export($wp_styles, true));

			foreach($wp_styles->queue as $style)
			{
				if(isset($wp_styles->registered[$style]))
				{
					$handle = $wp_styles->registered[$style]->handle;
					$src = $wp_styles->registered[$style]->src;
					$data = isset($wp_styles->registered[$style]->extra['data']) ? $wp_styles->registered[$style]->extra['data'] : "";
					$ver = $wp_styles->registered[$style]->ver;

					if(!isset($this->arr_styles[$handle]))
					{
						$this->arr_styles[$handle] = array(
							'source' => 'unknown',
							'type' => $this->get_type($src),
							'file' => $src,
							'version' => $ver,
						);
					}
				}
			}

			//do_log("Styles: ".var_export($this->arr_styles, true));*/
			#####################

			if(count($this->arr_styles) > 0)
			{
				$version = 0;
				$output = $this->errors = "";

				foreach($this->arr_styles as $handle => $this->arr_resource)
				{
					$version += point2int($this->arr_resource['version']);

					if($this->should_load_as_url())
					{
						list($content, $headers) = get_url_content(array('url' => $this->arr_resource['file'], 'catch_head' => true));

						if($headers['http_code'] != 200)
						{
							$content = "";
						}
					}

					else if(get_file_suffix($this->arr_resource['file']) == 'php')
					{
						ob_start();

							include_once(str_replace($file_url_base, $file_dir_base, $this->arr_resource['file']));

						$content = ob_get_clean();
					}

					else
					{
						$content = get_file_content(array('file' => str_replace($file_url_base, $file_dir_base, $this->arr_resource['file'])));
					}

					if($content != '')
					{
						$output .= $content;
					}

					else
					{
						$this->errors .= ($this->errors != '' ? "," : "").$handle;

						unset($this->arr_styles[$handle]);
					}
				}

				if($output != '')
				{
					$this->fetch_request();

					list($upload_path, $upload_url) = get_uploads_folder("mf_cache/".$this->http_host."/styles", true);

					if($upload_path != '')
					{
						$version = int2point($version);

						$file = "style-".$version.".min.css";

						$output = $this->compress_css($output);

						$success = set_file_content(array('file' => $upload_path.$file, 'mode' => 'w', 'content' => $output));

						/*if($success && function_exists('gzencode'))
						{
							$success = set_file_content(array('file' => $upload_path.$file.".gz", 'mode' => 'w', 'content' => gzencode($output)));
						}*/

						if($this->errors != '')
						{
							$error_text = sprintf(__("There were errors in '%s' when fetching style resources (%s)", 'lang_cache'), $this->errors, var_export($this->arr_styles, true));
						}

						else if($success == true)
						{
							foreach($this->arr_styles as $handle => $this->arr_resource)
							{
								wp_deregister_style($handle);
							}

							mf_enqueue_style('mf_styles', $upload_url.$file, null);
						}
					}

					if($error_text != '')
					{
						do_log($error_text, 'auto-draft');
					}
				}
			}
		}
	}

	function enqueue_script($data)
	{
		if($data['file'] != '' && (get_option('setting_activate_cache') == 'yes' || get_option('setting_activate_compress') == 'yes'))
		{
			$this->arr_scripts[$data['handle']] = array(
				'source' => 'known',
				'type' => $this->get_type($data['file']),
				'file' => $data['file'],
				'translation' => $data['translation'],
				'version' => $data['version'],
			);
		}
	}

	function output_js($data)
	{
		global $error_text;

		$this->fetch_request();

		list($upload_path, $upload_url) = get_uploads_folder("mf_cache/".$this->http_host."/scripts", true);

		if($upload_path != '')
		{
			if(isset($data['handle']) && $data['handle'] != '')
			{
				$data['filename'] = "script-".$data['handle'].".js";
			}

			else
			{
				$data['version'] = int2point($data['version']);
				$data['filename'] = "script-".$data['version'].".min.js";
				$data['content'] = $this->compress_js($data['content']);
			}

			$success = set_file_content(array('file' => $upload_path.$data['filename'], 'mode' => 'w', 'content' => $data['content']));

			/*if($success && function_exists('gzencode'))
			{
				$success = set_file_content(array('file' => $upload_path.$data['filename'].".gz", 'mode' => 'w', 'content' => gzencode($data['content'])));
			}*/

			if($this->errors != '')
			{
				$error_text = sprintf(__("There were errors in %s when fetching script resources (%s)", 'lang_cache'), $this->errors, var_export($this->arr_scripts, true));
			}

			else if($success == true)
			{
				if(isset($data['handle']) && $data['handle'] != '')
				{
					wp_deregister_script($data['handle']);

					wp_enqueue_script($data['handle'], $upload_url.$data['filename'], array('jquery'), null, true); //$data['version']

					unset($this->arr_scripts[$data['handle']]);
				}

				else
				{
					foreach($this->arr_scripts as $handle => $this->arr_resource)
					{
						wp_deregister_script($handle);
					}

					mf_enqueue_script('mf_scripts', $upload_url.$data['filename'], null);

					if(isset($data['translation']) && $data['translation'] != '')
					{
						echo "<script>".$data['translation']."</script>";
					}
				}
			}
		}

		else if($error_text != '')
		{
			do_log($error_text, 'auto-draft');
		}
	}

	function print_scripts()
	{
		if(get_option('setting_activate_cache') == 'yes' || get_option('setting_activate_compress') == 'yes')
		{
			$setting_merge_js_type = array('known_internal', 'known_external'); //, 'unknown_internal', 'unknown_external'

			$file_url_base = $this->site_url."/wp-content";
			$file_dir_base = WP_CONTENT_DIR;

			//Does not work in files where relative URLs to images or fonts are used
			#####################
			/*global $wp_scripts;

			foreach($wp_scripts->queue as $script)
			{
				if(isset($wp_scripts->registered[$script]))
				{
					$handle = $wp_scripts->registered[$script]->handle;
					$src = $wp_scripts->registered[$script]->src;
					$data = isset($wp_scripts->registered[$script]->extra['data']) ? $wp_scripts->registered[$script]->extra['data'] : "";
					$ver = $wp_scripts->registered[$script]->ver;

					if(!isset($this->arr_scripts[$handle]))
					{
						if(substr($src, 0, 3) == "/wp-")
						{
							$src = $this->site_url.$src;
						}

						$this->arr_scripts[$handle] = array(
							'source' => 'unknown',
							'type' => $this->get_type($src),
							'file' => $src,
							//'translation' => $translation,
							'extra' => $data,
							'version' => $ver,
						);
					}
				}
			}

			//do_log("Scripts: ".var_export($this->arr_scripts, true));*/
			#####################

			if(count($this->arr_scripts) > 0)
			{
				$version = 0;
				$output = $translation = $this->errors = "";

				foreach($this->arr_scripts as $handle => $this->arr_resource)
				{
					$merge_type = $this->arr_resource['source']."_".$this->arr_resource['type'];

					$version += point2int($this->arr_resource['version']);

					if(isset($this->arr_resource['translation']))
					{
						$count_temp = count($this->arr_resource['translation']);

						if(is_array($this->arr_resource['translation']) && $count_temp > 0)
						{
							$translation_values = "";

							foreach($this->arr_resource['translation'] as $key => $value)
							{
								$translation_values .= ($translation_values != '' ? "," : "")."'".$key."': ".(is_array($value) ? wp_json_encode($value) : "\"".$value."\"");
							}

							if($translation_values != '')
							{
								$translation .= "var ".$handle." = {".$translation_values."};";
							}
						}
					}

					/*else if(isset($this->arr_resource['extra']))
					{
						$translation .= $this->arr_resource['extra'];
					}*/

					$content = "";

					if($this->should_load_as_url())
					{
						if(in_array($merge_type, $setting_merge_js_type))
						{
							list($content, $headers) = get_url_content(array('url' => $this->arr_resource['file'], 'catch_head' => true));

							if($headers['http_code'] != 200)
							{
								$content = "";
							}
						}

						if($content != '')
						{
							$this->output_js(array('handle' => $handle, 'content' => $content, 'version' => $this->arr_resource['version']));
						}

						else
						{
							$this->errors .= ($this->errors != '' ? "," : "").$handle;

							unset($this->arr_scripts[$handle]);
						}
					}

					else
					{
						if(in_array($merge_type, $setting_merge_js_type))
						{
							$content = get_file_content(array('file' => str_replace($file_url_base, $file_dir_base, $this->arr_resource['file'])));
						}

						if($content != '')
						{
							$output .= $content;
						}

						else
						{
							$this->errors .= ($this->errors != '' ? "," : "").$handle;

							unset($this->arr_scripts[$handle]);
						}
					}
				}

				if($output != '')
				{
					$this->output_js(array('content' => $output, 'version' => $version, 'translation' => $translation));
				}
			}
		}
	}

	function style_loader_tag($tag)
	{
		if(get_option('setting_activate_cache') == 'yes' || get_option('setting_activate_compress') == 'yes')
		{
			$tag = str_replace("  ", " ", $tag);
			$tag = str_replace(" />", ">", $tag);
			$tag = str_replace(" type='text/css'", "", $tag);
			$tag = str_replace(' type="text/css"', "", $tag);
		}

		return $tag;
	}

	function script_loader_tag($tag)
	{
		if(get_option('setting_activate_cache') == 'yes' || get_option('setting_activate_compress') == 'yes')
		{
			$tag = str_replace(" type='text/javascript'", "", $tag);
			$tag = str_replace(' type="text/javascript"', "", $tag);
			//$tag = str_replace(" src", " async src", $tag); //defer
		}

		return $tag;
	}

	function is_password_protected()
	{
		global $post;

		return isset($post->post_password) && $post->post_password != '';
	}

	function create_dir()
	{
		$this->dir2create = $this->upload_path.trim($this->clean_url, "/");

		if(!@is_dir($this->dir2create)) // && !preg_match("/\?/", $this->dir2create) //Won't work with Webshop/JSON
		{
			if(strlen($this->dir2create) > 256 || !@mkdir($this->dir2create, 0755, true))
			{
				return false;
			}
		}

		return true;
	}

	function parse_file_address()
	{
		if($this->create_dir())
		{
			$this->file_address = $this->dir2create."/index.".$this->suffix;
		}

		else if(@is_dir($this->upload_path.$this->http_host))
		{
			$this->file_address = $this->upload_path.$this->http_host."/".md5($this->request_uri).".".$this->suffix;
		}

		else
		{
			$this->file_address = "";
		}
	}

	function get_or_set_file_content($suffix = 'html')
	{
		$this->suffix = $suffix;

		/* It is important that is_user_logged_in() is checked here so that it never is saved as a logged in user. This will potentially mean that the admin bar will end up in the cached version of the site */
		if(get_option('setting_activate_cache') == 'yes' && !is_user_logged_in()) //get_option('setting_activate_cache_logged_in') == 'yes'
		{
			$this->parse_file_address();

			if($this->file_address != '' && strlen($this->file_address) <= 255)
			{
				if(count($_POST) == 0 && file_exists(realpath($this->file_address)) && @filesize($this->file_address) > 0)
				{
					$out = get_file_content(array('file' => $this->file_address));

					/*if($this->suffix == 'json')
					{
						do_log("Fetching JSON from ".$this->file_address);
					}*/

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
		}
	}

	function strip_domain($code)
	{
		if(get_option('setting_strip_domain') == 'yes')
		{
			$code = str_replace(array("http:".$this->site_url_clean, "https:".$this->site_url_clean), "", $code);
		}

		return $code;
	}

	function compress_html($in)
	{
		$exkludera = array('!/\*[^*]*\*+([^/][^*]*\*+)*/!',
			'/>(\n|\r|\t|\r\n|  |	)+/',
			'/(\n|\r|\t|\r\n|  |	)+</',
			"/(width|height)=[\"\']\d*[\"\']\s/"
		);
		$inkludera = array('', '>', '<', '');

		$out = preg_replace($exkludera, $inkludera, $in);
		$out = $this->strip_domain($out);

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

	function compress_css($in)
	{
		$exkludera = array('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '/(\n|\r|\t|\r\n|  |	)+/', '/(:|,) /', '/;}/');
		$inkludera = array('', '', '$1', '}');

		$out = preg_replace($exkludera, $inkludera, $in);
		$out = $this->strip_domain($out);

		return $out;
	}

	function compress_js($in)
	{
		$exkludera = array('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '/(\n|\r|\t|\r\n|  |	)+/');

		$out = preg_replace($exkludera, '', $in);
		$out = $this->strip_domain($out);

		return $out;
	}

	function cache_save($out)
	{
		if(strlen($out) > 0 && $this->is_password_protected() == false)
		{
			switch($this->suffix)
			{
				case 'html':
					$out = $this->compress_html($out);
				break;
			}

			if(count($_POST) == 0)
			{
				$success = set_file_content(array('file' => $this->file_address, 'mode' => 'w', 'content' => $out, 'log' => false));

				/*if($success && function_exists('gzencode'))
				{
					$success = set_file_content(array('file' => $this->file_address.".gz", 'mode' => 'w', 'content' => gzencode($out."<!-- gzip -->"), 'log' => false));
				}*/

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
			}
		}

		return $out;
	}

	function gather_count_files($data)
	{
		$this->file_amount++;

		$file_date_time = date("Y-m-d H:i:s", @filemtime($data['file']));

		if($this->file_amount_date_first == '' || $file_date_time < $this->file_amount_date_first)
		{
			$this->file_amount_date_first = $file_date_time;
		}

		if($this->file_amount_date_last == '' || $file_date_time > $this->file_amount_date_last)
		{
			$this->file_amount_date_last = $file_date_time;
		}
	}

	function count_files()
	{
		$upload_path_site = $this->upload_path.trim($this->clean_url_orig, "/");

		$this->file_amount = 0;
		$this->file_amount_date_first = $this->file_amount_date_last = "";
		get_file_info(array('path' => $upload_path_site, 'callback' => array($this, 'gather_count_files')));

		return $this->file_amount;
	}

	function delete_file($data)
	{
		if(!isset($data['time_limit'])){		$data['time_limit'] = 60 * 60 * 24 * 2;} //2 days
		if(!isset($data['time_limit_api'])){	$data['time_limit_api'] = 60 * 60 * 24;} //1 day

		$time_now = time();
		$time_file = @filemtime($data['file']);
		$suffix_file = get_file_suffix($data['file'], true);

		if($suffix_file == 'json')
		{
			if($data['time_limit_api'] == 0 || ($time_now - $time_file >= $data['time_limit_api']))
			{
				@unlink($data['file']);
			}
		}

		else if($data['time_limit'] == 0 || ($time_now - $time_file >= $data['time_limit']))
		{
			@unlink($data['file']);
		}
	}

	function delete_folder($data)
	{
		$folder = $data['path']."/".$data['child'];

		if(is_dir($folder) && count(@scandir($folder)) == 2)
		{
			@rmdir($folder);
			//do_log("Deleted Folder: ".$folder);
		}
	}

	function clear($data = array())
	{
		if(!isset($data['time_limit'])){		$data['time_limit'] = 0;}
		if(!isset($data['time_limit_api'])){	$data['time_limit_api'] = ($data['time_limit'] * 60);}
		if(!isset($data['allow_depth'])){		$data['allow_depth'] = true;}

		$upload_path_site = $this->upload_path.trim($this->clean_url, "/");

		if($this->count_files() > 0)
		{
			$data_temp = $data;
			$data_temp['path'] = $upload_path_site;
			$data_temp['callback'] = array($this, 'delete_file');
			$data_temp['folder_callback'] = array($this, 'delete_folder');

			get_file_info($data_temp);

			$this->count_files();
		}
	}

	function get_posts2populate()
	{
		if(class_exists('mf_theme_core'))
		{
			$obj_theme_core = new mf_theme_core();

			$obj_theme_core->get_public_posts(array('allow_noindex' => true));

			$this->arr_posts = $obj_theme_core->arr_public_posts;
		}

		/*else
		{
			do_log(sprintf(__("%s is needed for population to work properly", 'lang_cache'), "MF Theme Core"));
		}*/
	}

	function populate()
	{
		$obj_microtime = new mf_microtime();

		update_option('option_cache_prepopulated', date("Y-m-d H:i:s"), 'no');

		$i = 0;

		$this->get_posts2populate();

		if(isset($this->arr_posts) && is_array($this->arr_posts))
		{
			foreach($this->arr_posts as $post_id => $post_title)
			{
				if($i == 0)
				{
					$obj_microtime->save_now();
				}

				get_url_content(array('url' => get_permalink($post_id)));

				if($i == 0)
				{
					$microtime_old = $obj_microtime->now;

					$obj_microtime->save_now();

					update_option('option_cache_prepopulated_one', $obj_microtime->now - $microtime_old, 'no');
				}

				$i++;

				/*if($i % 5 == 0)
				{*/
					sleep(1);
					set_time_limit(60);
				//}
			}

			$obj_microtime->save_now();
			update_option('option_cache_prepopulated_total', $obj_microtime->now - $obj_microtime->time_orig, 'no');
			update_option('option_cache_prepopulated', date("Y-m-d H:i:s"), 'no');

			$this->update_appcache_urls();
		}
	}

	function update_appcache_urls()
	{
		$arr_urls = array();

		$arr_urls[md5($this->site_url."/")] = $this->site_url."/";

		foreach($this->arr_posts as $post_id => $post_title)
		{
			$post_url = get_permalink($post_id);

			$content = get_url_content(array('url' => $post_url));

			$arr_urls[md5($post_url)] = $post_url;

			if($content != '')
			{
				$arr_tags = get_match_all('/\<img(.*?)\>/is', $content);

				foreach($arr_tags as $tag)
				{
					$resource_url = get_match('/src=[\'"](.*?)[\'"]/is', $tag, false);

					if($resource_url != '' && substr($resource_url, 0, 2) != "//")
					{
						$arr_urls[md5($resource_url)] = $resource_url;
					}
				}

				$arr_tags = get_match_all('/\<link(.*?)\>/is', $content);

				foreach($arr_tags as $tag)
				{
					if(!preg_match("/(shortlink|dns-prefetch)/", $tag))
					{
						$resource_url = get_match('/href=[\'"](.*?)[\'"]/is', $tag, false);

						if($resource_url != '' && substr($resource_url, 0, 2) != "//")
						{
							$arr_urls[md5($resource_url)] = $resource_url;
						}

						if(preg_match('/rel=[\'"]stylesheet[\'"]/', $tag))
						{
							$content_style = get_url_content(array('url' => $resource_url));

							if($content_style != '')
							{
								$arr_style_urls = get_match_all('/url\((.*?)\)/is', $content_style, false);

								foreach($arr_style_urls[0] as $style_resource_url)
								{
									$style_resource_url = trim($style_resource_url, "'");
									$style_resource_url = trim($style_resource_url, '"');

									if(substr($style_resource_url, 0, 5) != 'data:')
									{
										$resourse_suffix = get_file_suffix($style_resource_url);

										if(!in_array($resourse_suffix, array('eot', 'woff', 'woff2')))
										{
											$arr_urls[md5($style_resource_url)] = $style_resource_url;
										}
									}
								}
							}
						}
					}
				}

				$arr_tags = get_match_all('/\<script(.*?)\>/is', $content);

				foreach($arr_tags as $tag)
				{
					$resource_url = get_match('/src=[\'"](.*?)[\'"]/is', $tag, false);

					if($resource_url != '' && substr($resource_url, 0, 2) != "//")
					{
						$arr_urls[md5($resource_url)] = $resource_url;
					}
				}
			}
		}

		update_option('setting_appcache_pages_url', $arr_urls, 'no');
	}
}