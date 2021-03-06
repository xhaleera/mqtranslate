<?php
function mqtrans_currentUserCanEdit($lang) {
	global $q_config;
	
	$cu = wp_get_current_user();
	if ($cu->has_cap('edit_users') || empty($q_config['ul_lang_protection']))
		return true;
	else
	{
		$user_meta = get_user_meta($cu->ID);
		if (empty($user_meta) || !is_array($user_meta) || !array_key_exists('mqtranslate_language_access', $user_meta))
			$user_langs = $q_config['enabled_languages'];
		else
			$user_langs = explode(',', get_user_meta($cu->ID, 'mqtranslate_language_access', true));
		return in_array($lang, $user_langs);
	}
}

function mqtrans_currentUserCanView($lang) {
	global $q_config;
	
	$cu = wp_get_current_user();
	if ($cu->has_cap('edit_users') || empty($q_config['ul_lang_protection']))
		return true;
	else
	{
		$master_lang = get_user_meta($cu->ID, 'mqtranslate_master_language', true);
		if (empty($master_lang))
			return ($lang === $q_config['default_language']);
		else
			return ($lang === $master_lang || $lang === $q_config['default_language']);	
	}
}

function mqtrans_userProfile($user) {
	global $q_config;

	if (empty($q_config['ul_lang_protection']))
		return;
	
	$cu = wp_get_current_user();
	$langs = qtrans_getSortedLanguages();
	
	echo '<h3>'.__('mqTranslate User Language Settings', 'mqtranslate') . "</h3>\n";
	echo "<table class=\"form-table\">\n<tbody>\n";
	
	// Editable languages
	$user_meta = get_user_meta($user->ID);
	if (empty($user_meta) || !is_array($user_meta) || !array_key_exists('mqtranslate_language_access', $user_meta))
		$user_langs = $q_config['enabled_languages'];
	else
		$user_langs = explode(',', get_user_meta($user->ID, 'mqtranslate_language_access', true));
	echo "<tr>\n";
	if ($cu->ID == $user->ID)
		echo '<th>'.__('You can edit posts in', 'mqtranslate') . "</th>\n";
	else
		echo '<th>'.__('This user can edit posts in', 'mqtranslate') . "</th>\n";
	echo "<td>";
	if ($user->has_cap('edit_users'))
	{
		if (empty($langs))
			_e('No language available', 'mqtranslate');
		else if ($cu->ID == $user->ID)
			_e('As an Administrator, you can edit posts in all languages.', 'mqtranslate');
		else
			_e('As an Administrator, this user can edit posts in all languages.', 'mqtranslate');
	}
	else if ($cu->has_cap('edit_users'))
	{
		if (empty($langs))
			_e('No language available', 'mqtranslate')."\n";
		else
		{
			$checkboxes = array();
			foreach ($langs as $l) {
				$name = "mqtrans_user_lang_{$l}";
				$checked = (in_array($l, $user_langs)) ? 'checked' : '';
				$checkboxes[] = "<label for=\"{$name}\"><input type=\"checkbox\" name=\"mqtrans_user_lang[]\" id=\"{$name}\" value=\"{$l}\" {$checked} /> {$q_config['language_name'][$l]}</label>\n";
			}
			echo implode("<br />\n", $checkboxes);
		}
	}
	else
	{
		$intersect = array_intersect($langs, $user_langs);
		if (empty($intersect))
			_e('No language selected', 'mqtranslate')."\n";
		else
		{
			$languages = array();
			foreach ($intersect as $l)
				$languages[] = $q_config['language_name'][$l];
			echo implode(', ', $languages);
		}
	}
	echo "</td>\n";
	echo "</tr>\n";
	
	// Master language
	$user_master_lang = get_user_meta($user->ID, 'mqtranslate_master_language', true);
	echo "<tr>\n";
	echo '<th>' . __('Master language', 'mqtranslate') . "</th>\n";
	echo "<td>\n";
	if ($user->has_cap('edit_users'))
		_e('Not applicable to Administrators', 'mqtranslate');
	else if ($cu->has_cap('edit_users'))
	{
		echo "<select name=\"mqtrans_master_lang\">\n";
		echo '<option value="">' . __('Default Language', 'mqtranslate') . "</option>\n";
		foreach ($langs as $l)
		{
			if ($l == $q_config['default_language'])
				continue;
			$selected = ($user_master_lang == $l) ? ' selected' : '';
			echo "<option value=\"{$l}\"{$selected}>{$q_config['language_name'][$l]}</option>\n";
		}
		echo "</select>\n";
		echo '<span class="description">' . __('Language from which texts should be translated by this user', 'mqtranslate') . "</span>\n";
	}
	else
	{
		if (empty($langs) || empty($user_master_lang) || !in_array($user_master_lang, $langs))
			_e('Default Language', 'mqtranslate');
		else
			echo $q_config['language_name'][$user_master_lang];
	}
	echo "</td>\n";
	echo "</tr>\n";
	
	echo "</tbody>\n</table>\n";
}

function mqtrans_userProfileUpdate($user_id) {
	global $q_config;
	$cu = wp_get_current_user();
	if ($cu->has_cap('edit_users') && !empty($q_config['ul_lang_protection'])) {
		// Editable languages
		$langs = (empty($_POST['mqtrans_user_lang'])) ? array() : $_POST['mqtrans_user_lang'];
		if (!is_array($langs))
			$langs = array();
		update_user_meta($user_id, 'mqtranslate_language_access', implode(',', $langs));
		
		// Master language
		if (empty($_POST['mqtrans_master_lang']))
			delete_user_meta($user_id, 'mqtranslate_master_language');
		else
			update_user_meta($user_id, 'mqtranslate_master_language', $_POST['mqtrans_master_lang']);
	}
}

function qtrans_isEmptyContent($value) {
	$str = trim(strip_tags($value, '<img>,<embed>,<object>,<iframe>'));
	return empty($str);
}

function mqtrans_postUpdated($post_ID, $after, $before) {
	global $wpdb, $q_config;

	// Don't handle custom post types
	if (!in_array($after->post_type, array( 'post', 'page' )) && !in_array($after->post_type, $q_config['allowed_custom_post_types']))
		return;
	
	$fields = array('title', 'content', 'excerpt');
	
	$containers = array();
	$maps = array();
	
	$cu = wp_get_current_user();
	if ($cu->has_cap('edit_users') || empty($q_config['ul_lang_protection']))
	{
		foreach ($fields as $f) {
			$containers[$f] = qtrans_split($after->{"post_{$f}"}, true, $maps[$f]);
			foreach ($containers[$f] as $k => $v) {
				if (qtrans_isEmptyContent($v))
					unset($containers[$f]);
			}
		}
	}
	else
	{
		foreach ($fields as $f) {
			$beforeMap = array();
			$maps[$f] = array();
			$beforeField = qtrans_split($before->{"post_{$f}"}, true, $beforeMap);
			$afterField = qtrans_split($after->{"post_{$f}"}, true, $maps[$f]);
			foreach ($afterField as $k => $v) {
				if (!mqtrans_currentUserCanEdit($k))
					unset($afterField[$k], $maps[$f][$k]);
			}
			$containers[$f] = array_merge($beforeField, $afterField);
			$maps[$f] = array_merge($beforeMap, $maps[$f]);
		}
	}
	
	$data = array();
	foreach ($fields as $f)
		$data["post_{$f}"] = qtrans_join($containers[$f], $maps[$f]);
	if (get_magic_quotes_gpc())
		$data = stripslashes_deep($data);
	$where = array('ID' => $post_ID);
	
	$wpdb->update($wpdb->posts, $data, $where);
}

function mqtrans_filterPostMetaData($original_value, $object_id, $meta_key, $single) {
	if ($meta_key == '_menu_item_url')
	{
		$meta = wp_cache_get($object_id, 'post_meta');
		if (!empty($meta) && array_key_exists($meta_key, $meta) && !empty($meta[$meta_key]))
		{
			if ($single === false)
			{
				if (is_array($meta[$meta_key]))
					$meta = $meta[$meta_key];
				else
					$meta = array($meta[$meta_key]);
				$meta = array_map('qtrans_convertURL', $meta);
			}
			else
			{
				if (is_array($meta[$meta_key]))
					$meta = $meta[$meta_key][0];
				else
					$meta = $meta[$meta_key];
				$meta = qtrans_convertURL($meta);
			}
			return $meta;
		}
	}
	return null;
}

function mqtrans_team_options() {
	global $q_config;
?>
	<?php qtrans_admin_section_start(__('Team Settings', 'mqtranslate'), 'team'); ?>
	<table class="form-table" id="qtranslate-admin-team" style="display: none">
			<tr>
				<th scope="row"><?php _e('User-level Language Protection', 'mqtranslate') ?></th>
				<td>
					<label for="ul_lang_protection"><input type="checkbox" name="ul_lang_protection" id="ul_lang_protection" value="1"<?php echo ($q_config['ul_lang_protection'])?' checked="checked"':''; ?>/> <?php _e('Enable user-level language protection', 'mqtranslate'); ?></label>
					<br />
					<small><?php _e('When enabled, this option allows you to select which language is editable on a user-level account basis. NOTE: Only post title, content and excerpt are supported at this time.', 'mqtranslate') ?></small>
				</td>
			</tr>
	</table>
<?php
	qtrans_admin_section_end('team');
}

function mqtrans_load_team_options() {
	global $q_config;
	$opt = get_option('mqtranslate_ul_lang_protection');
	if ($opt === false)
		$q_config['ul_lang_protection'] = true;
	else
		$q_config['ul_lang_protection'] = ($opt == '1');
}

function mqtrans_save_team_options() {
	qtrans_updateSetting('ul_lang_protection', QT_BOOLEAN);
}

function mqtrans_preConfigJS($config) {
	global $q_config;
	
	if (empty($q_config['ul_lang_protection']) || current_user_can('edit_users'))
		return $config;
	
	$config['editable_languages'] = array();
	$config['visible_languages'] = array();
	foreach ($config['enabled_languages'] as $lang) {
		if (mqtrans_currentUserCanEdit($lang))
			$config['editable_languages'][] = $lang;
		else if (mqtrans_currentUserCanView($lang))
			$config['visible_languages'][] = $lang;
	}
	
	return $config;
}

if (!defined('WP_ADMIN'))
	add_filter('get_post_metadata', 'mqtrans_filterPostMetaData', 10, 4);

add_filter('pre_qtranslate_js',				'mqtrans_preConfigJS');

add_action('edit_user_profile', 			'mqtrans_userProfile');
add_action('show_user_profile',				'mqtrans_userProfile');
add_action('profile_update',				'mqtrans_userProfileUpdate');
add_action('post_updated',					'mqtrans_postUpdated', 10, 3);

add_action('qtranslate_configuration_after-general', 		'mqtrans_team_options', 9);
add_action('qtranslate_loadConfig',			'mqtrans_load_team_options');
add_action('qtranslate_saveConfig',			'mqtrans_save_team_options');
?>