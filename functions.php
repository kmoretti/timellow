<?php
if (!defined('ABSPATH')) {
    exit;
}

define('TIMELLOW_THEME_VERSION', '1.0.12');
define('TIMELLOW_DB_VERSION', '1.0.0');
define('TIMELLOW_THEME_UPDATE_REPO', 'jkjoy/timellow');
define('TIMELLOW_THEME_UPDATE_CACHE_KEY', 'timellow_theme_update_release');
define('TIMELLOW_THEME_UPDATE_ZIP_ASSET', 'timellow.zip');
define('TIMELLOW_SHUOSHUO_POST_TYPE', 'shuoshuo');

function timellow_get_default_options()
{
    return array(
        'top_video' => 0,
        'top_image' => 0,
        'avatar_image' => 0,
        'avatar_link' => '',
        'avatar_mirror' => '',
        'tencent_map_key' => '',
        'beian_info' => '',
        'auto_collapse' => '1',
        'custom_css' => '',
        'custom_js' => '',
        'analytics' => '',
    );
}

function timellow_get_option($key, $fallback = null)
{
    $defaults = timellow_get_default_options();

    if (array_key_exists($key, $defaults)) {
        $fallback = $defaults[$key];
    }

    return get_theme_mod('timellow_' . $key, $fallback);
}

function timellow_get_media_url($key)
{
    $value = timellow_get_option($key);

    if (empty($value)) {
        return '';
    }

    if (is_numeric($value)) {
        $url = wp_get_attachment_url((int) $value);

        return $url ? $url : '';
    }

    return esc_url_raw($value);
}

function timellow_get_frontend_tencent_map_key()
{
    if (is_admin() || !current_user_can('edit_posts')) {
        return '';
    }

    return trim((string) timellow_get_option('tencent_map_key'));
}

function timellow_get_relative_rest_url($route = '')
{
    $url = rest_url(ltrim((string) $route, '/'));
    $parts = wp_parse_url($url);

    if (!$parts || empty($parts['path'])) {
        return $url;
    }

    $relative = (string) $parts['path'];

    if (!empty($parts['query'])) {
        $relative .= '?' . $parts['query'];
    }

    if (!empty($parts['fragment'])) {
        $relative .= '#' . $parts['fragment'];
    }

    return $relative;
}

function timellow_get_theme_stylesheet()
{
    return wp_get_theme()->get_stylesheet();
}

function timellow_normalize_version_string($version)
{
    $version = trim((string) $version);

    if ($version === '') {
        return '';
    }

    return ltrim($version, "vV \t\n\r\0\x0B");
}

function timellow_find_github_release_asset($assets)
{
    if (!is_array($assets)) {
        return array();
    }

    foreach ($assets as $asset) {
        if (
            !is_array($asset) ||
            empty($asset['name']) ||
            empty($asset['browser_download_url'])
        ) {
            continue;
        }

        if ((string) $asset['name'] === TIMELLOW_THEME_UPDATE_ZIP_ASSET) {
            return $asset;
        }
    }

    foreach ($assets as $asset) {
        if (
            !is_array($asset) ||
            empty($asset['name']) ||
            empty($asset['browser_download_url'])
        ) {
            continue;
        }

        if (strtolower(pathinfo((string) $asset['name'], PATHINFO_EXTENSION)) === 'zip') {
            return $asset;
        }
    }

    return array();
}

function timellow_get_github_theme_release()
{
    $cached = get_site_transient(TIMELLOW_THEME_UPDATE_CACHE_KEY);

    if (is_array($cached) && !empty($cached['version'])) {
        return $cached;
    }

    $headers = array(
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
    );
    $github_token = trim((string) apply_filters('timellow_github_theme_update_token', ''));

    if ($github_token !== '') {
        $headers['Authorization'] = 'Bearer ' . $github_token;
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/' . TIMELLOW_THEME_UPDATE_REPO . '/releases/latest',
        array(
            'timeout' => 15,
            'headers' => $headers,
        )
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code !== 200 || !is_array($body)) {
        return new WP_Error(
            'timellow_theme_update_failed',
            '无法获取主题更新信息'
        );
    }

    $asset = timellow_find_github_release_asset(isset($body['assets']) ? $body['assets'] : array());
    $release = array(
        'version' => timellow_normalize_version_string(isset($body['tag_name']) ? $body['tag_name'] : ''),
        'details_url' => !empty($body['html_url']) ? esc_url_raw((string) $body['html_url']) : '',
        'package' => !empty($asset['browser_download_url']) ? esc_url_raw((string) $asset['browser_download_url']) : '',
        'published_at' => !empty($body['published_at']) ? sanitize_text_field((string) $body['published_at']) : '',
    );

    if ($release['version'] === '' || $release['package'] === '') {
        return new WP_Error(
            'timellow_theme_update_incomplete',
            'GitHub Release 缺少版本号或可安装的 zip 资源'
        );
    }

    set_site_transient(TIMELLOW_THEME_UPDATE_CACHE_KEY, $release, 6 * HOUR_IN_SECONDS);

    return $release;
}

function timellow_filter_github_theme_update($update, $theme_data, $theme_stylesheet, $locales)
{
    if ($theme_stylesheet !== timellow_get_theme_stylesheet()) {
        return $update;
    }

    $get_theme_header = static function ($key) use ($theme_data, $theme_stylesheet) {
        if ($theme_data instanceof WP_Theme) {
            return $theme_data->get($key);
        }

        if (is_array($theme_data) && array_key_exists($key, $theme_data)) {
            return $theme_data[$key];
        }

        return wp_get_theme($theme_stylesheet)->get($key);
    };

    $release = timellow_get_github_theme_release();

    if (is_wp_error($release)) {
        return false;
    }

    $current_version = timellow_normalize_version_string($get_theme_header('Version'));

    if (
        $current_version === '' ||
        version_compare($release['version'], $current_version, '<=')
    ) {
        return false;
    }

    return array(
        'theme' => $theme_stylesheet,
        'version' => $release['version'],
        'url' => $release['details_url'],
        'package' => $release['package'],
        'requires' => $get_theme_header('RequiresWP'),
        'requires_php' => $get_theme_header('RequiresPHP'),
    );
}
add_filter('update_themes_github.com', 'timellow_filter_github_theme_update', 10, 4);

function timellow_clear_theme_update_cache()
{
    delete_site_transient(TIMELLOW_THEME_UPDATE_CACHE_KEY);
    delete_site_transient('update_themes');
    delete_site_transient('update_themes_github.com');
    wp_clean_themes_cache(false);
}
add_action('switch_theme', 'timellow_clear_theme_update_cache');

function timellow_clear_theme_update_cache_on_themes_page()
{
    timellow_clear_theme_update_cache();
}
add_action('load-themes.php', 'timellow_clear_theme_update_cache_on_themes_page', 1);

function timellow_clear_theme_update_cache_after_upgrade($upgrader, $hook_extra)
{
    if (!is_array($hook_extra) || empty($hook_extra['type']) || $hook_extra['type'] !== 'theme') {
        return;
    }

    timellow_clear_theme_update_cache();
}
add_action('upgrader_process_complete', 'timellow_clear_theme_update_cache_after_upgrade', 10, 2);

function timellow_setup_theme()
{
    add_theme_support('title-tag');
    add_theme_support('automatic-feed-links');
    add_theme_support('post-thumbnails');
    add_theme_support(
        'html5',
        array(
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
            'style',
            'script',
        )
    );
}
add_action('after_setup_theme', 'timellow_setup_theme');

function timellow_register_shuoshuo_post_type()
{
    register_post_type(
        TIMELLOW_SHUOSHUO_POST_TYPE,
        array(
            'labels' => array(
                'name' => '说说',
                'singular_name' => '说说',
                'menu_name' => '说说',
                'name_admin_bar' => '说说',
                'add_new' => '写说说',
                'add_new_item' => '写说说',
                'edit_item' => '编辑说说',
                'new_item' => '新说说',
                'view_item' => '查看说说',
                'search_items' => '搜索说说',
                'not_found' => '暂无说说',
                'not_found_in_trash' => '回收站中暂无说说',
                'all_items' => '全部说说',
            ),
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-format-status',
            'menu_position' => 5,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports' => array('title', 'editor', 'author', 'thumbnail', 'comments', 'revisions'),
            'has_archive' => true,
            'rewrite' => array(
                'slug' => 'shuoshuo',
                'with_front' => false,
            ),
            'show_in_rest' => false,
        )
    );
}
add_action('init', 'timellow_register_shuoshuo_post_type');

function timellow_maybe_flush_shuoshuo_rewrite_rules()
{
    $rewrite_version = '1';

    if (get_option('timellow_shuoshuo_rewrite_version') === $rewrite_version) {
        return;
    }

    flush_rewrite_rules(false);
    update_option('timellow_shuoshuo_rewrite_version', $rewrite_version);
}
add_action('init', 'timellow_maybe_flush_shuoshuo_rewrite_rules', 20);

function timellow_include_shuoshuo_in_main_query($query)
{
    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    if ($query->is_home() || $query->is_search() || $query->is_author()) {
        $query->set('post_type', array('post', TIMELLOW_SHUOSHUO_POST_TYPE));
    }
}
add_action('pre_get_posts', 'timellow_include_shuoshuo_in_main_query');

add_filter('pre_option_link_manager_enabled', '__return_true');
add_filter('use_block_editor_for_post', '__return_false', 100);
add_filter('use_block_editor_for_post_type', '__return_false', 100);
add_filter('use_widgets_block_editor', '__return_false');
add_filter('gutenberg_use_widgets_block_editor', '__return_false');
add_filter('should_load_remote_block_patterns', '__return_false');

function timellow_show_admin_bar($show)
{
    return is_admin() ? $show : false;
}
add_filter('show_admin_bar', 'timellow_show_admin_bar');

function timellow_cleanup_frontend_wp_head()
{
    if (is_admin()) {
        return;
    }

    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'wp_shortlink_wp_head', 10);
    remove_action('wp_head', 'rest_output_link_wp_head', 10);
    remove_action('template_redirect', 'rest_output_link_header', 11);
    remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
    remove_action('wp_head', 'wp_oembed_add_host_js');
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    remove_action('wp_head', '_admin_bar_bump_cb');
    remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
}
add_action('init', 'timellow_cleanup_frontend_wp_head');

function timellow_cleanup_frontend_wp_assets()
{
    if (is_admin()) {
        return;
    }

    wp_dequeue_style('wp-block-library');
    wp_deregister_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_deregister_style('wp-block-library-theme');
    wp_dequeue_style('global-styles');
    wp_deregister_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
    wp_deregister_style('classic-theme-styles');
    wp_dequeue_style('admin-bar');
    wp_deregister_style('admin-bar');
    wp_dequeue_script('wp-embed');
    wp_deregister_script('wp-embed');

    if (!current_user_can('edit_posts')) {
        wp_dequeue_style('dashicons');
        wp_deregister_style('dashicons');
    }
}
add_action('wp_enqueue_scripts', 'timellow_cleanup_frontend_wp_assets', 100);

function timellow_cleanup_legacy_theme_mods()
{
    if (get_option('timellow_legacy_cleanup_v1') === '1') {
        return;
    }

    remove_theme_mod('timellow_logo_image');
    update_option('timellow_legacy_cleanup_v1', '1');
}
add_action('init', 'timellow_cleanup_legacy_theme_mods');

function timellow_enqueue_assets()
{
    if (!is_admin() && current_user_can('edit_posts')) {
        wp_enqueue_media();
    }

    $tencent_map_key = timellow_get_frontend_tencent_map_key();

    if ($tencent_map_key !== '') {
        wp_enqueue_script(
            'timellow-tencent-map',
            add_query_arg(
                array(
                    'v' => '1.exp',
                    'key' => $tencent_map_key,
                    'libraries' => 'service',
                ),
                'https://map.qq.com/api/gljs'
            ),
            array(),
            null,
            false
        );
    }

    if (!is_admin()) {
        wp_deregister_script('jquery');
        wp_register_script(
            'jquery',
            get_template_directory_uri() . '/assets/js/jquery.min.js',
            array(),
            '3.7.1',
            false
        );
    }

    wp_enqueue_style(
        'timellow-bulma',
        get_template_directory_uri() . '/assets/css/bulma.min.css',
        array(),
        '1.0.0'
    );
    wp_enqueue_style(
        'timellow-fancybox',
        get_template_directory_uri() . '/assets/css/fancybox.css',
        array('timellow-bulma'),
        '5.0.0'
    );
    wp_enqueue_style(
        'timellow-main',
        get_template_directory_uri() . '/assets/css/timellow.css',
        array('timellow-fancybox'),
        filemtime(get_template_directory() . '/assets/css/timellow.css')
    );

    wp_enqueue_script(
        'jquery',
        get_template_directory_uri() . '/assets/js/jquery.min.js',
        array(),
        '3.7.1',
        false
    );
    wp_enqueue_script(
        'timellow-alpine',
        get_template_directory_uri() . '/assets/js/alpinejs.js',
        array(),
        '3.14.0',
        false
    );
    wp_enqueue_script(
        'timellow-fancybox',
        get_template_directory_uri() . '/assets/js/fancybox.umd.js',
        array(),
        '5.0.0',
        false
    );
    wp_enqueue_script(
        'timellow-scrollload',
        get_template_directory_uri() . '/assets/js/scrollload.min.js',
        array(),
        '1.0.0',
        false
    );
    wp_enqueue_script(
        'timellow-music-player',
        get_template_directory_uri() . '/assets/js/music-player.js',
        array('jquery'),
        filemtime(get_template_directory() . '/assets/js/music-player.js'),
        false
    );
    wp_enqueue_script(
        'timellow-app',
        get_template_directory_uri() . '/assets/js/timellow.js',
        array('jquery', 'timellow-fancybox', 'timellow-scrollload', 'timellow-music-player'),
        filemtime(get_template_directory() . '/assets/js/timellow.js'),
        false
    );
}
add_action('wp_enqueue_scripts', 'timellow_enqueue_assets', 20);

function timellow_output_custom_head_code()
{
    $custom_css = (string) timellow_get_option('custom_css');
    $analytics = (string) timellow_get_option('analytics');

    if ($custom_css !== '') {
        echo "<style id=\"timellow-custom-css\">\n" . $custom_css . "\n</style>\n";
    }

    if ($analytics !== '') {
        echo $analytics . "\n";
    }
}
add_action('wp_head', 'timellow_output_custom_head_code', 99);

function timellow_output_custom_footer_code()
{
    $custom_js = (string) timellow_get_option('custom_js');

    if ($custom_js !== '') {
        echo "<script id=\"timellow-custom-js\">\n" . $custom_js . "\n</script>\n";
    }
}
add_action('wp_footer', 'timellow_output_custom_footer_code', 99);

function timellow_sanitize_raw_text($value)
{
    return is_string($value) ? trim($value) : '';
}

function timellow_register_customizer($wp_customize)
{
    $wp_customize->add_section(
        'timellow_theme_options',
        array(
            'title' => '主题设置',
            'priority' => 30,
        )
    );

    $media_fields = array(
        'top_video' => array('label' => '顶部背景视频', 'mime_type' => 'video'),
        'top_image' => array('label' => '顶部背景图片', 'mime_type' => 'image'),
        'avatar_image' => array(
            'label' => '头像设置',
            'mime_type' => 'image',
            'description' => '优先使用这里设置的头像；未设置时回退为 Gravatar 头像。',
        ),
    );

    foreach ($media_fields as $key => $field) {
        $wp_customize->add_setting(
            'timellow_' . $key,
            array(
                'default' => timellow_get_default_options()[$key],
                'sanitize_callback' => 'absint',
            )
        );

        $wp_customize->add_control(
            new WP_Customize_Media_Control(
                $wp_customize,
                'timellow_' . $key,
                array(
                    'section' => 'timellow_theme_options',
                    'label' => $field['label'],
                    'mime_type' => $field['mime_type'],
                    'description' => isset($field['description']) ? $field['description'] : '',
                )
            )
        );
    }

    $text_fields = array(
        'avatar_link' => array('label' => '头像点击跳转链接', 'type' => 'url', 'sanitize' => 'esc_url_raw'),
        'avatar_mirror' => array(
            'label' => '头像镜像地址',
            'type' => 'url',
            'sanitize' => 'esc_url_raw',
            'description' => '可填写 Gravatar 镜像地址，例如 https://cravatar.cn/avatar/。留空则使用默认头像地址。',
        ),
        'tencent_map_key' => array(
            'label' => '腾讯地图 API Key',
            'type' => 'text',
            'sanitize' => 'sanitize_text_field',
            'description' => '用于前端撰写中的“所在位置”地址获取，请在腾讯位置服务中启用 WebService API。',
        ),
        'beian_info' => array('label' => '备案信息', 'type' => 'text', 'sanitize' => 'sanitize_text_field'),
    );

    foreach ($text_fields as $key => $field) {
        $wp_customize->add_setting(
            'timellow_' . $key,
            array(
                'default' => timellow_get_default_options()[$key],
                'sanitize_callback' => $field['sanitize'],
            )
        );

        $wp_customize->add_control(
            'timellow_' . $key,
            array(
                'section' => 'timellow_theme_options',
                'label' => $field['label'],
                'type' => $field['type'],
                'description' => isset($field['description']) ? $field['description'] : '',
            )
        );
    }

    $wp_customize->add_setting(
        'timellow_auto_collapse',
        array(
            'default' => timellow_get_default_options()['auto_collapse'],
            'sanitize_callback' => function ($value) {
                return $value === '0' ? '0' : '1';
            },
        )
    );
    $wp_customize->add_control(
        'timellow_auto_collapse',
        array(
            'section' => 'timellow_theme_options',
            'label' => '列表页自动收起正文',
            'type' => 'radio',
            'choices' => array(
                '1' => '是',
                '0' => '否',
            ),
        )
    );

    $textarea_fields = array(
        'custom_css' => '自定义 CSS',
        'custom_js' => '自定义 JavaScript',
        'analytics' => '统计代码',
    );

    foreach ($textarea_fields as $key => $label) {
        $wp_customize->add_setting(
            'timellow_' . $key,
            array(
                'default' => timellow_get_default_options()[$key],
                'sanitize_callback' => 'timellow_sanitize_raw_text',
            )
        );

        $wp_customize->add_control(
            'timellow_' . $key,
            array(
                'section' => 'timellow_theme_options',
                'label' => $label,
                'type' => 'textarea',
            )
        );
    }
}
add_action('customize_register', 'timellow_register_customizer');

function timellow_register_post_meta_box()
{
    add_meta_box(
        'timellow-post-meta',
        '内容设置',
        'timellow_render_post_meta_box',
        array('post', TIMELLOW_SHUOSHUO_POST_TYPE, 'page'),
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'timellow_register_post_meta_box');

function timellow_render_post_meta_box($post)
{
    wp_nonce_field('timellow_post_meta', 'timellow_post_meta_nonce');

    $position = timellow_get_post_position($post->ID);
    $position_url = get_post_meta($post->ID, '_timellow_position_url', true);
    $is_advertise = get_post_meta($post->ID, '_timellow_is_advertise', true);
    $can_sticky_shuoshuo = $post->post_type === TIMELLOW_SHUOSHUO_POST_TYPE && timellow_user_can_sticky_posts(TIMELLOW_SHUOSHUO_POST_TYPE);
    ?>
    <p>
        <label for="timellow_position"><strong>发布定位</strong></label>
        <input type="text" class="widefat" id="timellow_position" name="timellow_position" value="<?php echo esc_attr($position); ?>">
    </p>
    <p>
        <label for="timellow_position_url"><strong>定位跳转地址</strong></label>
        <input type="url" class="widefat" id="timellow_position_url" name="timellow_position_url" value="<?php echo esc_attr($position_url); ?>">
    </p>
    <p>
        <label>
            <input type="checkbox" name="timellow_is_advertise" value="1" <?php checked($is_advertise, '1'); ?>>
            标记为广告
        </label>
    </p>
    <?php if ($can_sticky_shuoshuo) : ?>
        <p>
            <label>
                <input type="checkbox" name="timellow_is_sticky" value="1" <?php checked(is_sticky($post->ID)); ?>>
                说说置顶
            </label>
        </p>
    <?php endif; ?>
    <?php
}

function timellow_save_post_meta($post_id)
{
    if (
        !isset($_POST['timellow_post_meta_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['timellow_post_meta_nonce'])), 'timellow_post_meta')
    ) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $position = isset($_POST['timellow_position']) ? timellow_normalize_position_label(wp_unslash($_POST['timellow_position'])) : '';
    $position_url = isset($_POST['timellow_position_url']) ? esc_url_raw(wp_unslash($_POST['timellow_position_url'])) : '';
    $is_advertise = isset($_POST['timellow_is_advertise']) ? '1' : '0';

    update_post_meta($post_id, '_timellow_position', $position);
    update_post_meta($post_id, '_timellow_position_url', $position_url);
    update_post_meta($post_id, '_timellow_is_advertise', $is_advertise);

    if (get_post_type($post_id) === TIMELLOW_SHUOSHUO_POST_TYPE && timellow_user_can_sticky_posts(TIMELLOW_SHUOSHUO_POST_TYPE)) {
        $is_sticky = isset($_POST['timellow_is_sticky']);

        if (get_post_status($post_id) === 'publish' && $is_sticky) {
            stick_post($post_id);
        } else {
            unstick_post($post_id);
        }
    }
}
add_action('save_post', 'timellow_save_post_meta');

function timellow_current_url()
{
    $scheme = is_ssl() ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? wp_unslash($_SERVER['HTTP_HOST']) : wp_parse_url(home_url(), PHP_URL_HOST);
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';

    return esc_url_raw($scheme . $host . $request_uri);
}

function timellow_get_edit_url()
{
    return admin_url('post-new.php');
}

function timellow_escape_img_src($url)
{
    return esc_url($url, array('http', 'https'));
}

function timellow_get_theme_avatar_url()
{
    return timellow_get_media_url('avatar_image');
}

function timellow_apply_avatar_mirror($avatar_url)
{
    $avatar_url = esc_url_raw((string) $avatar_url);
    $mirror_url = trim((string) timellow_get_option('avatar_mirror'));

    if ($avatar_url === '' || $mirror_url === '') {
        return $avatar_url;
    }

    $avatar_host = strtolower((string) wp_parse_url($avatar_url, PHP_URL_HOST));

    if ($avatar_host === '' || !preg_match('/(^|\.)gravatar\.com$/', $avatar_host)) {
        return $avatar_url;
    }

    $mirror_parts = wp_parse_url($mirror_url);

    if (!$mirror_parts || empty($mirror_parts['scheme']) || empty($mirror_parts['host'])) {
        return $avatar_url;
    }

    $avatar_path = (string) wp_parse_url($avatar_url, PHP_URL_PATH);
    $avatar_query = (string) wp_parse_url($avatar_url, PHP_URL_QUERY);

    if ($avatar_path === '') {
        return $avatar_url;
    }

    $mirror_path = isset($mirror_parts['path']) ? (string) $mirror_parts['path'] : '';

    if ($mirror_path === '' || $mirror_path === '/') {
        $target_path = $avatar_path;
    } else {
        $normalized_mirror_path = '/' . trim($mirror_path, '/');
        $avatar_hash = basename($avatar_path);

        if ($avatar_hash !== '' && preg_match('#/avatar$#i', $normalized_mirror_path)) {
            $target_path = $normalized_mirror_path . '/' . rawurlencode(rawurldecode($avatar_hash));
        } else {
            $target_path = $normalized_mirror_path . '/' . ltrim($avatar_path, '/');
        }
    }

    $mirrored_url = $mirror_parts['scheme'] . '://' . $mirror_parts['host'];

    if (!empty($mirror_parts['port'])) {
        $mirrored_url .= ':' . (int) $mirror_parts['port'];
    }

    $mirrored_url .= $target_path;

    $query_args = array();

    if (!empty($mirror_parts['query'])) {
        wp_parse_str((string) $mirror_parts['query'], $query_args);
    }

    if ($avatar_query !== '') {
        $avatar_query_args = array();
        wp_parse_str($avatar_query, $avatar_query_args);
        $query_args = array_merge($query_args, $avatar_query_args);
    }

    if (!empty($query_args)) {
        $mirrored_url = add_query_arg($query_args, $mirrored_url);
    }

    return esc_url_raw($mirrored_url);
}

function timellow_get_wp_avatar_url($email, $size = 64)
{
    $theme_avatar_url = timellow_get_theme_avatar_url();

    if ($theme_avatar_url !== '') {
        return $theme_avatar_url;
    }

    return timellow_apply_avatar_mirror(
        get_avatar_url(
            $email,
            array('size' => $size)
        )
    );
}

function timellow_get_post_author_avatar_url($post = null, $size = 64)
{
    $post = get_post($post);

    if (!$post) {
        return timellow_get_wp_avatar_url((string) get_option('admin_email'), $size);
    }

    $author_id = (int) $post->post_author;
    $email = get_the_author_meta('user_email', $author_id);

    return timellow_get_wp_avatar_url($email, $size);
}

function timellow_get_site_avatar_data($size = 96)
{
    $admins = get_users(
        array(
            'role' => 'administrator',
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
        )
    );

    if (!empty($admins) && $admins[0] instanceof WP_User) {
        $admin = $admins[0];

        return array(
            'url' => timellow_get_wp_avatar_url($admin->user_email, $size),
            'name' => $admin->display_name,
        );
    }

    $site_name = get_bloginfo('name');
    $admin_email = (string) get_option('admin_email');

    return array(
        'url' => timellow_get_wp_avatar_url($admin_email, $size),
        'name' => $site_name,
    );
}

function timellow_extract_image_srcs($html)
{
    $srcs = array();
    preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches);

    if (!empty($matches[1])) {
        $srcs = $matches[1];
    }

    return $srcs;
}

function timellow_extract_video_src($html)
{
    if (preg_match('/<video[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        return $matches[1];
    }

    if (preg_match('/<video[^>]*>.*?<source[^>]+src=["\']([^"\']+)["\']/is', $html, $matches)) {
        return $matches[1];
    }

    return null;
}

function timellow_filter_content($html)
{
    return strip_tags($html, '<p><a>');
}

function timellow_truncate_html_with_tags($html, $length)
{
    $text_length = 0;
    $output = '';
    $tag_stack = array();
    $i = 0;
    $html_length = mb_strlen($html, 'UTF-8');

    while ($i < $html_length && $text_length < $length) {
        $char = mb_substr($html, $i, 1, 'UTF-8');

        if ($char === '<') {
            $tag_end = mb_strpos($html, '>', $i);
            if ($tag_end !== false) {
                $tag_content = mb_substr($html, $i, $tag_end - $i + 1, 'UTF-8');
                $output .= $tag_content;

                if (preg_match('/<(\w+)(?:\s|>)/i', $tag_content, $matches)) {
                    $tag_name = $matches[1];
                    if (!preg_match('/<\w+[^>]*\/>/i', $tag_content) && !preg_match('/<\/\w+>/i', $tag_content)) {
                        $tag_stack[] = $tag_name;
                    }
                }

                if (preg_match('/<\/(\w+)>/i', $tag_content, $matches)) {
                    $tag_name = $matches[1];
                    $reversed = array_reverse($tag_stack, true);
                    $key = array_search($tag_name, $reversed, true);
                    if ($key !== false) {
                        array_splice($tag_stack, count($tag_stack) - 1 - $key, 1);
                    }
                }

                $i = $tag_end + 1;
                continue;
            }
        }

        $output .= $char;
        $text_length++;
        $i++;
    }

    while (!empty($tag_stack)) {
        $tag = array_pop($tag_stack);
        $output .= '</' . $tag . '>';
    }

    return preg_replace('/(<br\s*\/?>|\s|&nbsp;|<p>\s*<\/p>)+$/i', '', $output);
}

function timellow_generate_content_with_summary($full_content, $summary_length = 100)
{
    $plain_text = strip_tags($full_content);
    $plain_text = preg_replace('/\s+/', ' ', $plain_text);
    $plain_text = trim($plain_text);

    if (mb_strlen($plain_text, 'UTF-8') > $summary_length) {
        $summary = timellow_truncate_html_with_tags($full_content, $summary_length) . '...';
        $is_truncated = true;
    } else {
        $summary = $full_content;
        $is_truncated = false;
    }

    return array(
        'summary' => $summary,
        'full_content' => $full_content,
        'is_truncated' => $is_truncated,
    );
}

function timellow_render_music_card($url, $title, $artist = '', $cover = '')
{
    $title = (string) $title;
    $artist = $artist !== '' ? $artist : '未知艺术家';
    $cover = $cover !== '' ? $cover : get_template_directory_uri() . '/assets/images/default-music-cover.svg';

    ob_start();
    ?>
    <div class="music-card" data-music-player>
        <div class="media">
            <div class="media-left">
                <figure class="image is-64x64">
                    <img src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr($title); ?>">
                </figure>
            </div>
            <div class="media-content">
                <p class="title is-6"><?php echo esc_html($title); ?></p>
                <p class="subtitle is-7"><?php echo esc_html($artist); ?></p>
                <div class="music-controls">
                    <button class="button is-small play-btn" aria-label="播放">
                        <span class="icon play-icon">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M3 2l10 6-10 6z"/>
                            </svg>
                        </span>
                        <span class="icon pause-icon is-hidden">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M5 3h2v10H5zM9 3h2v10H9z"/>
                            </svg>
                        </span>
                    </button>
                    <div class="progress-wrapper">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                    </div>
                    <span class="time is-size-7">00:00 / 00:00</span>
                </div>
            </div>
        </div>
        <audio preload="metadata">
            <source src="<?php echo esc_url($url); ?>" type="audio/mpeg">
            您的浏览器不支持音频播放。
        </audio>
    </div>
    <?php

    return ob_get_clean();
}

function timellow_parse_music_shortcode_string($content)
{
    $pattern = '/\[music\s+url=["\']([^"\']+)["\']\s+title=["\']([^"\']+)["\'](?:\s+artist=["\']([^"\']*)["\'])?(?:\s+cover=["\']([^"\']*)["\'])?\]/';

    return preg_replace_callback(
        $pattern,
        function ($matches) {
            $url = $matches[1];
            $title = $matches[2];
            $artist = isset($matches[3]) ? $matches[3] : '';
            $cover = isset($matches[4]) ? $matches[4] : '';

            return timellow_render_music_card($url, $title, $artist, $cover);
        },
        $content
    );
}

function timellow_music_shortcode($atts)
{
    $atts = shortcode_atts(
        array(
            'url' => '',
            'title' => '',
            'artist' => '',
            'cover' => '',
        ),
        $atts,
        'music'
    );

    if ($atts['url'] === '' || $atts['title'] === '') {
        return '';
    }

    return timellow_render_music_card($atts['url'], $atts['title'], $atts['artist'], $atts['cover']);
}
add_shortcode('music', 'timellow_music_shortcode');

function timellow_extract_music_shortcodes($content)
{
    $shortcodes = array();
    $pattern = '/\[music\s+url=["\']([^"\']+)["\']\s+title=["\']([^"\']+)["\'](?:\s+artist=["\']([^"\']*)["\'])?(?:\s+cover=["\']([^"\']*)["\'])?\]/';

    preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $shortcodes[] = $match[0];
    }

    return array(
        'shortcodes' => $shortcodes,
        'content' => preg_replace($pattern, '', $content),
    );
}

function timellow_generate_content_with_summary_and_music($full_content, $summary_length = 100)
{
    $extracted = timellow_extract_music_shortcodes($full_content);
    $music_shortcodes = $extracted['shortcodes'];
    $content_without_music = $extracted['content'];
    $result = timellow_generate_content_with_summary($content_without_music, $summary_length);

    $result['music_shortcodes'] = !empty($music_shortcodes) ? implode("\n\n", $music_shortcodes) : '';

    return $result;
}

function timellow_get_post_rendered_content($post = null)
{
    $post = get_post($post);

    if (!$post) {
        return '';
    }

    $content = apply_filters('the_content', $post->post_content);

    return timellow_add_lightbox_to_content_images($content, $post->ID);
}

function timellow_add_lightbox_to_content_images($content, $post_id = 0)
{
    if (!is_string($content) || $content === '') {
        return $content;
    }

    $post_id = absint($post_id);
    $gallery_id = $post_id > 0 ? 'post-detail-' . $post_id : 'post-detail';

    return preg_replace_callback(
        '/<img\b[^>]*>/i',
        function ($matches) use ($gallery_id) {
            $img_tag = $matches[0];
            $has_fancybox = preg_match('/\bdata-fancybox\b/i', $img_tag) === 1;
            $has_data_src = preg_match('/\bdata-src\s*=/i', $img_tag) === 1;

            if ($has_fancybox && $has_data_src) {
                return $img_tag;
            }

            if (!preg_match('/\bsrc\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $img_tag, $src_matches)) {
                return $img_tag;
            }

            $src = '';
            if (isset($src_matches[2]) && $src_matches[2] !== '') {
                $src = $src_matches[2];
            } elseif (isset($src_matches[3]) && $src_matches[3] !== '') {
                $src = $src_matches[3];
            } elseif (isset($src_matches[4])) {
                $src = $src_matches[4];
            }

            $attributes = '';
            if (!$has_fancybox) {
                $attributes .= ' data-fancybox="' . esc_attr($gallery_id) . '"';
            }
            if (!$has_data_src) {
                $attributes .= ' data-src="' . esc_url($src) . '"';
            }
            if ($attributes === '') {
                return $img_tag;
            }

            $injected = rtrim($img_tag);
            if (substr($injected, -2) === '/>') {
                $injected = substr($injected, 0, -2) . $attributes . '>';
            } else {
                $injected = substr($injected, 0, -1) . $attributes . '>';
            }

            return $injected;
        },
        $content
    );
}

function timellow_clean_position_fragment($value)
{
    $value = trim(wp_strip_all_tags((string) $value));

    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/u', ' ', $value);

    if (!is_string($value)) {
        return '';
    }

    return trim($value, " \t\n\r\0\x0B,，、;；|｜/／\\-_.·•・");
}

function timellow_format_position_city($city)
{
    $city = timellow_clean_position_fragment($city);

    if ($city === '') {
        return '';
    }

    $formatted = preg_replace('/(?:特别行政区|市)$/u', '', $city);

    if (!is_string($formatted) || $formatted === '') {
        return $city;
    }

    return $formatted;
}

function timellow_extract_position_city($position)
{
    $position = timellow_clean_position_fragment($position);

    if ($position === '') {
        return '';
    }

    if (preg_match('/^(北京市|上海市|天津市|重庆市|香港特别行政区|澳门特别行政区)/u', $position, $matches)) {
        return $matches[1];
    }

    $city_source = preg_replace('/^.*?(?:省|自治区|特别行政区)/u', '', $position, 1);

    if (!is_string($city_source) || $city_source === '') {
        $city_source = $position;
    }

    if (preg_match('/^([\p{Han}]{2,16}(?:自治州|地区|盟|市))/u', $city_source, $matches)) {
        return $matches[1];
    }

    return '';
}

function timellow_strip_position_place_prefix($place)
{
    $place = timellow_clean_position_fragment($place);

    if ($place === '') {
        return '';
    }

    $patterns = array(
        '/^[\p{Han}]{1,12}(?:区|县|旗|市)(?=[\p{Han}A-Za-z0-9])/u',
        '/^[\p{Han}A-Za-z0-9]{1,24}(?:镇|乡|街道|街|路|大道|巷|胡同|村|社区|工业园|开发区)(?=[\p{Han}A-Za-z0-9])/u',
        '/^[A-Za-z0-9一二三四五六七八九十百千零〇\-]{1,12}(?:号|弄|栋|座|层|室)(?=[\p{Han}A-Za-z0-9])/u',
    );

    for ($index = 0; $index < 5; $index++) {
        $updated = false;

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $place, $matches)) {
                continue;
            }

            $remaining = mb_substr($place, mb_strlen($matches[0], 'UTF-8'), null, 'UTF-8');
            $remaining = timellow_clean_position_fragment($remaining);

            if ($remaining === '') {
                continue;
            }

            $place = $remaining;
            $updated = true;
            break;
        }

        if (!$updated) {
            break;
        }
    }

    return $place;
}

function timellow_is_generic_position_place($place)
{
    $place = timellow_clean_position_fragment($place);

    if ($place === '') {
        return true;
    }

    return preg_match(
        '/^(?:[\p{Han}]{1,12}(?:区|县|旗|市|镇|乡|街道|街|路|大道|巷|胡同|村|社区|工业园|开发区)|[A-Za-z0-9一二三四五六七八九十百千零〇\-]{1,12}(?:号|弄|栋|座|层|室))$/u',
        $place
    ) === 1;
}

function timellow_normalize_position_label($position)
{
    $position = timellow_clean_position_fragment($position);

    if ($position === '') {
        return '';
    }

    if (preg_match('/[·•・]/u', $position)) {
        $parts = preg_split('/\s*[·•・]\s*/u', $position);
        $parts = array_values(array_filter(array_map('timellow_clean_position_fragment', (array) $parts)));

        if (!empty($parts)) {
            $city = timellow_format_position_city($parts[0]);
            $place = isset($parts[1]) ? timellow_clean_position_fragment($parts[1]) : '';
            $place = timellow_strip_position_place_prefix($place);
            $place = timellow_clean_position_fragment($place);

            if (timellow_is_generic_position_place($place)) {
                $place = '';
            }

            if ($city !== '' && $place !== '' && $city !== $place) {
                return $city . '·' . $place;
            }

            if ($place !== '') {
                return $place;
            }

            if ($city !== '') {
                return $city;
            }
        }
    }

    $city = timellow_extract_position_city($position);
    $display_city = timellow_format_position_city($city);

    if ($city === '') {
        return $position;
    }

    $city_offset = mb_strpos($position, $city, 0, 'UTF-8');
    $place = $position;

    if ($city_offset !== false) {
        $place = mb_substr($position, $city_offset + mb_strlen($city, 'UTF-8'), null, 'UTF-8');
    }

    $place = timellow_strip_position_place_prefix($place);
    $place = timellow_clean_position_fragment($place);

    if (timellow_is_generic_position_place($place)) {
        $place = '';
    }

    if ($place === '' || $place === $city || $place === $display_city) {
        return $display_city !== '' ? $display_city : $city;
    }

    if ($display_city === '') {
        return $place;
    }

    return $display_city . '·' . $place;
}

function timellow_get_post_position($post_id)
{
    return timellow_normalize_position_label((string) get_post_meta($post_id, '_timellow_position', true));
}

function timellow_get_post_position_url($post_id)
{
    return get_post_meta($post_id, '_timellow_position_url', true);
}

function timellow_is_post_ad($post_id)
{
    return get_post_meta($post_id, '_timellow_is_advertise', true) === '1';
}

function timellow_is_comment_related_to_top_comment($comment_id, $top_comment_id, $comment_map)
{
    if (!isset($comment_map[$comment_id])) {
        return false;
    }

    $comment = $comment_map[$comment_id];

    if ((int) $comment['parent'] === (int) $top_comment_id) {
        return true;
    }

    if ((int) $comment['parent'] === 0) {
        return false;
    }

    return timellow_is_comment_related_to_top_comment($comment['parent'], $top_comment_id, $comment_map);
}

function timellow_get_comment_user_group($comment)
{
    if (!empty($comment->user_id)) {
        $user = get_userdata((int) $comment->user_id);
        if ($user && in_array('administrator', (array) $user->roles, true)) {
            return 'administrator';
        }
    }

    return '';
}

function timellow_prepare_comment_payload($comment)
{
    $comment = get_comment($comment);

    if (!$comment) {
        return array();
    }

    return array(
        'coid' => (int) $comment->comment_ID,
        'id' => (int) $comment->comment_ID,
        'parent' => (int) $comment->comment_parent,
        'author' => $comment->comment_author,
        'url' => $comment->comment_author_url,
        'text' => wp_strip_all_tags($comment->comment_content),
        'userGroup' => timellow_get_comment_user_group($comment),
    );
}

function timellow_get_post_latest_comments_with_replies($post_id, $limit = 5)
{
    $top_comments = get_comments(
        array(
            'post_id' => $post_id,
            'status' => 'approve',
            'type' => 'comment',
            'parent' => 0,
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
            'number' => $limit,
        )
    );

    if (empty($top_comments)) {
        return array();
    }

    $all_comments = get_comments(
        array(
            'post_id' => $post_id,
            'status' => 'approve',
            'type' => 'comment',
            'orderby' => 'comment_date_gmt',
            'order' => 'ASC',
            'number' => 0,
        )
    );

    $comment_map = array();

    foreach ($all_comments as $comment) {
        $comment_map[$comment->comment_ID] = array(
            'coid' => (int) $comment->comment_ID,
            'parent' => (int) $comment->comment_parent,
            'author' => $comment->comment_author,
            'url' => $comment->comment_author_url,
            'text' => wp_strip_all_tags($comment->comment_content),
            'userGroup' => timellow_get_comment_user_group($comment),
            'created' => mysql2date('U', $comment->comment_date_gmt),
        );
    }

    $comment_tree = array();

    foreach ($top_comments as $top_comment) {
        $item = timellow_prepare_comment_payload($top_comment);
        $item['replies'] = array();
        $item['level'] = 0;
        $related_replies = array();

        foreach ($all_comments as $child_comment) {
            if ((int) $child_comment->comment_parent === 0) {
                continue;
            }

            if (timellow_is_comment_related_to_top_comment($child_comment->comment_ID, $top_comment->comment_ID, $comment_map)) {
                $payload = timellow_prepare_comment_payload($child_comment);
                $parent_comment = isset($comment_map[$child_comment->comment_parent]) ? $comment_map[$child_comment->comment_parent] : null;

                $payload['parentAuthor'] = $parent_comment ? $parent_comment['author'] : '';
                $payload['parentUrl'] = $parent_comment ? $parent_comment['url'] : '';
                $payload['parentUserGroup'] = $parent_comment ? $parent_comment['userGroup'] : '';
                $payload['created'] = mysql2date('U', $child_comment->comment_date_gmt);
                $related_replies[] = $payload;
            }
        }

        usort(
            $related_replies,
            function ($a, $b) {
                return ((int) $a['created']) <=> ((int) $b['created']);
            }
        );

        $item['replies'] = $related_replies;
        $comment_tree[] = $item;
    }

    return $comment_tree;
}

function timellow_format_comment_time($timestamp)
{
    // Compare against a standard Unix timestamp to avoid timezone offset drift.
    $now = current_time('timestamp', true);
    $diff = $now - (int) $timestamp;

    if ($diff < 60) {
        return '刚刚';
    }

    if ($diff < 3600) {
        return floor($diff / 60) . '分钟前';
    }

    if ($diff < 86400) {
        return floor($diff / 3600) . '小时前';
    }

    if ($diff < 2592000) {
        return floor($diff / 86400) . '天前';
    }

    return wp_date('Y年m月d日', (int) $timestamp);
}

function timellow_render_comment_delete_button($post_id, $comment_id)
{
    if (!timellow_user_is_administrator()) {
        return;
    }
    ?>
    <button type="button"
            class="pcc-comment-delete"
            @click.stop="deleteComment($event, <?php echo (int) $post_id; ?>, <?php echo (int) $comment_id; ?>)">删除</button>
    <?php
}

function timellow_render_comment_items($post_id, $limit = 5)
{
    $comments = timellow_get_post_latest_comments_with_replies($post_id, $limit);

    foreach ($comments as $comment) {
        ?>
        <div class="pcc-comment-item" data-comment-id="<?php echo esc_attr($comment['coid']); ?>">
            <a href="<?php echo esc_url($comment['url'] ? $comment['url'] : '#'); ?>"><?php echo esc_html($comment['author']); ?></a>
            <?php if (!empty($comment['userGroup']) && $comment['userGroup'] === 'administrator') : ?>
                <span class="author-badge">作者</span>
            <?php endif; ?>
            <span>:</span>
            <span class="cursor-help pcc-comment-content"
                  @click="showReplyForm($event, '<?php echo esc_attr($post_id); ?>', '<?php echo esc_attr($comment['coid']); ?>', <?php echo esc_attr(wp_json_encode($comment['author'], JSON_UNESCAPED_UNICODE)); ?>)"><?php echo esc_html($comment['text']); ?></span>
            <?php timellow_render_comment_delete_button($post_id, $comment['coid']); ?>
        </div>
        <?php

        foreach ($comment['replies'] as $reply) {
            ?>
            <div class="pcc-comment-item" data-comment-id="<?php echo esc_attr($reply['coid']); ?>">
                <a href="<?php echo esc_url($reply['url'] ? $reply['url'] : '#'); ?>"><?php echo esc_html($reply['author']); ?></a>
                <?php if (!empty($reply['userGroup']) && $reply['userGroup'] === 'administrator') : ?>
                    <span class="author-badge">作者</span>
                <?php endif; ?>
                <span>回复</span>
                <a href="<?php echo esc_url($reply['parentUrl'] ? $reply['parentUrl'] : '#'); ?>"><?php echo esc_html($reply['parentAuthor']); ?></a>
                <?php if (!empty($reply['parentUserGroup']) && $reply['parentUserGroup'] === 'administrator') : ?>
                    <span class="author-badge">作者</span>
                <?php endif; ?>
                <span>:</span>
                <span class="cursor-help pcc-comment-content"
                      @click="showReplyForm($event, '<?php echo esc_attr($post_id); ?>', '<?php echo esc_attr($reply['coid']); ?>', <?php echo esc_attr(wp_json_encode($reply['author'], JSON_UNESCAPED_UNICODE)); ?>)"><?php echo esc_html($reply['text']); ?></span>
                <?php timellow_render_comment_delete_button($post_id, $reply['coid']); ?>
            </div>
            <?php
        }
    }
}

function timellow_render_post_action_block($post_id, $detail = false, $comment_limit = 5)
{
    $post = get_post($post_id);
    $post_type = $post ? $post->post_type : '';
    $datetime = $detail ? get_the_date('Y年m月d日', $post_id) : timellow_format_comment_time(get_post_time('U', true, $post_id));
    $can_manage_frontend_post = timellow_user_can_manage_frontend_post($post_id);
    $can_edit_article_in_admin = $post_type === 'post' && current_user_can('edit_post', $post_id);
    $can_delete_feed_post = timellow_user_can_delete_feed_post($post_id);
    $article_edit_url = $can_edit_article_in_admin ? get_edit_post_link($post_id, 'raw') : '';
    $delete_label = $post_type === 'post' ? '文章' : '说说';
    ?>
    <div class="post-time">
        <time datetime="<?php echo esc_attr(get_the_date('Y年m月d日', $post_id)); ?>"><?php echo esc_html($datetime); ?></time>
        <div class="post-time-comment" x-data="{ptcmShow: false}" :id="'ptcm-' + <?php echo (int) $post_id; ?>">
            <div class="ptc-more" @click="togglePostTimeComment($event, <?php echo (int) $post_id; ?>)">
                <svg t="1709204592505" class="icon" viewBox="0 0 1024 1024" version="1.1"
                     xmlns="http://www.w3.org/2000/svg" p-id="16237" width="16" height="16">
                    <path d="M229.2 512m-140 0a140 140 0 1 0 280 0 140 140 0 1 0-280 0Z" p-id="16238" fill="#8a8a8a"></path>
                    <path d="M794.8 512m-140 0a140 140 0 1 0 280 0 140 140 0 1 0-280 0Z" p-id="16239" fill="#8a8a8a"></path>
                </svg>
            </div>
            <div class="post-time-comment-modal" x-show="ptcmShow" x-transition.in.duration.300ms.origin.top.right>
                <button type="button" class="ptcm-action ptcm-good like-menu-btn" data-cid="<?php echo (int) $post_id; ?>" @click="toggleLike($event, <?php echo (int) $post_id; ?>)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" height="16" width="16"
                         stroke-width="1.5" stroke="currentColor" class="size-6 like-menu-icon">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/>
                    </svg>
                    <span class="like-menu-text">点赞</span>
                </button>
                <button type="button" class="ptcm-action ptcm-comment" @click="showPostReplyForm($event, <?php echo (int) $post_id; ?>)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" height="16" width="16"
                         stroke-width="1.5" stroke="currentColor" class="size-6">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z"/>
                    </svg>
                    评论
                </button>
                <?php if ($can_manage_frontend_post && current_user_can('edit_post', $post_id)) : ?>
                    <button type="button" class="ptcm-action ptcm-edit" @click.stop="editPost($event, <?php echo (int) $post_id; ?>)">编辑</button>
                <?php elseif ($article_edit_url) : ?>
                    <a class="ptcm-action ptcm-edit" href="<?php echo esc_url($article_edit_url); ?>" @click.stop>编辑</a>
                <?php endif; ?>
                <?php if ($can_delete_feed_post) : ?>
                    <button type="button" class="ptcm-action ptcm-delete" @click.stop="deletePost($event, <?php echo (int) $post_id; ?>, '<?php echo esc_js($delete_label); ?>')">删除</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="post-comment-container" data-cid="<?php echo (int) $post_id; ?>">
        <div class="pcc-like-list" data-cid="<?php echo (int) $post_id; ?>">
            <div class="pcc-like-summary">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" height="16" width="16"
                     stroke-width="1.5" stroke="currentColor" class="like-icon">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/>
                </svg>
                <span class="like-users-text"></span>
            </div>
        </div>
        <div class="pcc-comment-list">
            <?php timellow_render_comment_items($post_id, $comment_limit); ?>
        </div>
    </div>
    <?php
}

function timellow_get_article_share_image_url($post_id)
{
    $thumbnail_url = get_the_post_thumbnail_url($post_id, 'thumbnail');

    if ($thumbnail_url) {
        return $thumbnail_url;
    }

    $content_images = timellow_extract_image_srcs((string) get_post_field('post_content', $post_id));

    if (!empty($content_images[0])) {
        return $content_images[0];
    }

    $site_icon_url = get_site_icon_url(96);

    if ($site_icon_url) {
        return $site_icon_url;
    }

    $site_avatar = timellow_get_site_avatar_data(96);

    if (!empty($site_avatar['url'])) {
        return $site_avatar['url'];
    }

    return get_template_directory_uri() . '/assets/images/default-avatar.svg';
}

function timellow_get_article_share_terms($post_id, $limit = 3)
{
    $terms = get_the_category($post_id);

    if (empty($terms) || is_wp_error($terms)) {
        $terms = get_the_tags($post_id);
    }

    if (empty($terms) || is_wp_error($terms)) {
        return array();
    }

    $items = array();

    foreach ($terms as $term) {
        if (count($items) >= $limit) {
            break;
        }

        $term_link = get_term_link($term);

        $items[] = array(
            'name' => $term->name,
            'url' => is_wp_error($term_link) ? '' : $term_link,
        );
    }

    return $items;
}

function timellow_render_article_share_content($post)
{
    $post = get_post($post);

    if (!$post) {
        return;
    }

    $permalink = get_permalink($post);
    $title = get_the_title($post);
    $image_url = timellow_get_article_share_image_url($post->ID);
    $terms = timellow_get_article_share_terms($post->ID);

    if ($title === '') {
        $title = '未命名文章';
    }
    ?>
    <div class="post-content article-share-content">
        <div class="article-share-update">分享了一篇文章</div>

        <?php if (!empty($terms)) : ?>
            <!--<div class="article-share-tags">
                <?php foreach ($terms as $term) : ?>
                    <?php if (!empty($term['url'])) : ?>
                        <a class="article-share-tag" href="<?php echo esc_url($term['url']); ?>"><?php echo esc_html($term['name']); ?></a>
                    <?php else : ?>
                        <span class="article-share-tag"><?php echo esc_html($term['name']); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>-->
        <?php endif; ?>

        <a class="article-share-card" href="<?php echo esc_url($permalink); ?>">
            <span class="article-share-thumb">
                <img src="<?php echo timellow_escape_img_src($image_url); ?>" alt="">
            </span>
            <span class="article-share-card-title"><?php echo esc_html($title); ?></span>
        </a>
    </div>
    <?php
}

function timellow_render_post_article($post = null, $args = array())
{
    $post = get_post($post);

    if (!$post) {
        return;
    }

    $args = wp_parse_args(
        $args,
        array(
            'detail' => false,
            'page' => false,
            'comment_limit' => 5,
        )
    );

    $author_id = (int) $post->post_author;
    $author_name = get_the_author_meta('display_name', $author_id);
    $author_url = get_author_posts_url($author_id);
    $avatar_url = timellow_get_post_author_avatar_url($post, 64);
    $filtered_content = timellow_filter_content($post->post_content);
    $content_with_summary = timellow_generate_content_with_summary_and_music($filtered_content, 100);
    $music_html = $content_with_summary['music_shortcodes'] !== '' ? timellow_parse_music_shortcode_string($content_with_summary['music_shortcodes']) : '';
    $auto_collapse = timellow_get_option('auto_collapse') !== '0';
    $video_src = timellow_extract_video_src($post->post_content);
    $images = $video_src ? array() : timellow_extract_image_srcs($post->post_content);
    $is_article_share = !$args['detail'] && !$args['page'] && $post->post_type === 'post';
    ?>
    <article class="post-item post-type-<?php echo esc_attr($post->post_type); ?><?php echo $args['detail'] ? ' post-detail-item' : ''; ?>">
        <div class="post-item-left">
            <a href="<?php echo esc_url($author_url); ?>">
                <img alt="<?php echo esc_attr($author_name); ?>" src="<?php echo timellow_escape_img_src($avatar_url); ?>">
            </a>
        </div>
        <div class="post-item-right">
            <h2 class="post-title">
                <a href="<?php echo esc_url($author_url); ?>"><?php echo esc_html($author_name); ?></a>
                <?php if ($args['page']) : ?>
                    <span class="page-badge">页面</span>
                <?php else : ?>
                    <?php if (is_sticky($post->ID)) : ?>
                        <span class="top-badge">置顶</span>
                    <?php endif; ?>
                    <?php if (timellow_is_post_ad($post->ID)) : ?>
                        <span class="ad-badge">广告</span>
                    <?php endif; ?>
                <?php endif; ?>
            </h2>

            <?php if ($is_article_share) : ?>
                <?php timellow_render_article_share_content($post); ?>
            <?php else : ?>
                <div class="post-content">
                    <?php if ($args['detail']) : ?>
                        <?php echo timellow_get_post_rendered_content($post); ?>
                    <?php else : ?>
                        <?php
                        if ($auto_collapse) {
                            if ($content_with_summary['is_truncated']) {
                                echo '<div class="summary-' . esc_attr($post->ID) . '">' . $content_with_summary['summary'] . '<span class="show_all_btn cursor-pointer" data-cid="' . esc_attr($post->ID) . '">全文</span></div>';
                                echo '<div class="hidden full_content-' . esc_attr($post->ID) . '">' . $content_with_summary['full_content'] . '<div><span class="hide_all_btn cursor-pointer" data-cid="' . esc_attr($post->ID) . '">收起</span></div></div>';
                            } else {
                                echo '<div>' . $content_with_summary['full_content'] . '</div>';
                            }

                            echo $music_html;
                        } else {
                            echo '<div class="full-content-display">' . $content_with_summary['full_content'] . '</div>';
                            echo $music_html;
                        }
                        ?>
                    <?php endif; ?>
                </div>

                <?php if (!$args['detail']) : ?>
                    <?php
                    if ($video_src) {
                        get_template_part('components/post/post-video', null, array(
                            'video_url' => $video_src,
                        ));
                    } else {
                        get_template_part('components/post/post-images', null, array(
                            'images' => $images,
                            'post_id' => $post->ID,
                        ));
                    }
                    ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            if (!$is_article_share) {
                get_template_part('components/post/post-position', null, array(
                    'post_id' => $post->ID,
                ));
            }

            timellow_render_post_action_block($post->ID, $args['detail'], (int) $args['comment_limit']);
            ?>
        </div>
    </article>
    <?php
}

function timellow_render_pagination_state($query = null)
{
    $query = $query instanceof WP_Query ? $query : $GLOBALS['wp_query'];
    $current_page = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));
    $total_pages = max(1, (int) $query->max_num_pages);
    $next_url = $total_pages > $current_page ? get_next_posts_page_link($total_pages) : '';
    ?>
    <div class="pagination" style="display: none;">
        <span class="current-page" data-page="<?php echo esc_attr($current_page); ?>"></span>
        <span class="total-pages" data-total="<?php echo esc_attr($total_pages); ?>"></span>
        <span class="next-page-url" data-url="<?php echo esc_url($next_url); ?>"></span>
    </div>
    <?php
}

function timellow_get_archive_heading()
{
    if (is_post_type_archive(TIMELLOW_SHUOSHUO_POST_TYPE)) {
        return '说说';
    }

    if (is_category()) {
        return sprintf('分类 %s 下的文章', single_cat_title('', false));
    }

    if (is_search()) {
        return sprintf('包含关键字 %s 的内容', get_search_query());
    }

    if (is_tag()) {
        return sprintf('标签 %s 下的文章', single_tag_title('', false));
    }

    if (is_author()) {
        $author = get_queried_object();
        return sprintf('%s 发布的内容', $author ? $author->display_name : '');
    }

    $title = get_the_archive_title();
    return wp_strip_all_tags($title);
}

function timellow_get_friend_links()
{
    $bookmarks = get_bookmarks(
        array(
            'orderby' => 'name',
            'order' => 'ASC',
            'limit' => -1,
            'hide_invisible' => 1,
        )
    );

    if (empty($bookmarks)) {
        return array();
    }

    $links = array();
    $default_avatar = get_template_directory_uri() . '/assets/images/default-avatar.svg';

    foreach ($bookmarks as $bookmark) {
        $avatar = '';

        if (!empty($bookmark->link_image)) {
            if (is_numeric($bookmark->link_image)) {
                $attachment_url = wp_get_attachment_url((int) $bookmark->link_image);
                $avatar = $attachment_url ? $attachment_url : '';
            } else {
                $avatar = esc_url_raw($bookmark->link_image);
            }
        }

        $description = '';

        if (!empty($bookmark->link_description)) {
            $description = sanitize_text_field($bookmark->link_description);
        } elseif (!empty($bookmark->link_notes)) {
            $description = sanitize_text_field($bookmark->link_notes);
        }

        $links[] = array(
            'id' => (int) $bookmark->link_id,
            'name' => sanitize_text_field($bookmark->link_name),
            'url' => esc_url_raw($bookmark->link_url),
            'description' => $description,
            'avatar' => $avatar !== '' ? esc_url_raw($avatar) : $default_avatar,
        );
    }

    return $links;
}

function timellow_get_likes_table_name()
{
    global $wpdb;

    return $wpdb->prefix . 'timellow_likes';
}

function timellow_install_theme_data()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = timellow_get_likes_table_name();
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        actor_key varchar(191) NOT NULL,
        author_name varchar(191) NOT NULL DEFAULT '',
        author_email varchar(191) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY post_actor (post_id, actor_key),
        KEY post_id (post_id)
    ) {$charset_collate};";

    dbDelta($sql);
    update_option('timellow_db_version', TIMELLOW_DB_VERSION);
}
add_action('after_switch_theme', 'timellow_install_theme_data');

function timellow_maybe_install_theme_data()
{
    if (get_option('timellow_db_version') !== TIMELLOW_DB_VERSION) {
        timellow_install_theme_data();
    }
}
add_action('init', 'timellow_maybe_install_theme_data');

function timellow_resolve_like_actor(WP_REST_Request $request)
{
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $author = trim((string) $user->display_name);

        if ($author === '') {
            $author = trim((string) $user->user_login);
        }

        if ($author === '') {
            $author = '登录用户';
        }

        return array(
            'key' => 'user:' . $user->ID,
            'author' => $author,
            'email' => $user->user_email,
        );
    }

    $comment_author = (string) $request->get_param('comment_author');
    $comment_email = (string) $request->get_param('comment_email');
    $anonymous_id = (string) $request->get_param('anonymous_id');

    if ($comment_email !== '') {
        return array(
            'key' => 'comment:' . sha1(strtolower(trim($comment_email)) . '|' . trim($comment_author)),
            'author' => $comment_author !== '' ? $comment_author : '匿名用户',
            'email' => sanitize_email($comment_email),
        );
    }

    if ($anonymous_id !== '') {
        return array(
            'key' => 'anon:' . sanitize_key($anonymous_id),
            'author' => '匿名用户',
            'email' => '',
        );
    }

    return array(
        'key' => '',
        'author' => '匿名用户',
        'email' => '',
    );
}

function timellow_get_like_actor_alias_keys(WP_REST_Request $request, $actor_key = '')
{
    $alias_keys = array();
    $comment_author = (string) $request->get_param('comment_author');
    $comment_email = sanitize_email((string) $request->get_param('comment_email'));
    $anonymous_id = sanitize_key((string) $request->get_param('anonymous_id'));

    if ($comment_email !== '') {
        $alias_keys[] = 'comment:' . sha1(strtolower(trim($comment_email)) . '|' . trim($comment_author));
    }

    if ($anonymous_id !== '') {
        $alias_keys[] = 'anon:' . $anonymous_id;
    }

    return array_values(
        array_filter(
            array_unique($alias_keys),
            static function ($key) use ($actor_key) {
                return $key !== '' && $key !== $actor_key;
            }
        )
    );
}

function timellow_maybe_migrate_like_actor_alias($post_id, $actor, $alias_keys)
{
    global $wpdb;

    $post_id = (int) $post_id;
    $actor_key = isset($actor['key']) ? (string) $actor['key'] : '';

    if (
        $post_id <= 0 ||
        $actor_key === '' ||
        strpos($actor_key, 'user:') !== 0 ||
        empty($alias_keys)
    ) {
        return;
    }

    $table = timellow_get_likes_table_name();
    $existing_user = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND actor_key = %s",
            $post_id,
            $actor_key
        )
    );

    if ($existing_user) {
        return;
    }

    foreach ($alias_keys as $alias_key) {
        $alias_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE post_id = %d AND actor_key = %s",
                $post_id,
                $alias_key
            )
        );

        if (!$alias_id) {
            continue;
        }

        $wpdb->update(
            $table,
            array(
                'actor_key' => $actor_key,
                'author_name' => isset($actor['author']) ? (string) $actor['author'] : '',
                'author_email' => isset($actor['email']) ? (string) $actor['email'] : '',
            ),
            array(
                'id' => (int) $alias_id,
            ),
            array('%s', '%s', '%s'),
            array('%d')
        );

        break;
    }
}

function timellow_get_like_row_author_name($row)
{
    $author_name = trim((string) (isset($row['author_name']) ? $row['author_name'] : ''));

    if ($author_name !== '') {
        return $author_name;
    }

    $actor_key = (string) (isset($row['actor_key']) ? $row['actor_key'] : '');

    if (strpos($actor_key, 'user:') === 0) {
        $user_id = absint(substr($actor_key, 5));

        if ($user_id > 0) {
            $user = get_userdata($user_id);

            if ($user instanceof WP_User) {
                $display_name = trim((string) $user->display_name);

                if ($display_name !== '') {
                    return $display_name;
                }

                $user_login = trim((string) $user->user_login);

                if ($user_login !== '') {
                    return $user_login;
                }
            }
        }
    }

    return '匿名用户';
}

function timellow_get_like_rows($post_id)
{
    global $wpdb;
    $table = timellow_get_likes_table_name();

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id ASC",
            $post_id
        ),
        ARRAY_A
    );
}

function timellow_prepare_like_response($post_id, $actor_key = '')
{
    $rows = timellow_get_like_rows($post_id);
    $like_users = array();
    $has_anonymous_user = false;

    foreach ($rows as $row) {
        $author = timellow_get_like_row_author_name($row);

        if ($author === '匿名用户') {
            if ($has_anonymous_user) {
                continue;
            }

            $has_anonymous_user = true;
        }

        $like_users[] = array(
            'author' => $author,
        );
    }

    $liked = false;

    if ($actor_key !== '') {
        foreach ($rows as $row) {
            if ($row['actor_key'] === $actor_key) {
                $liked = true;
                break;
            }
        }
    }

    return array(
        'success' => true,
        'likes' => count($rows),
        'isLiked' => $liked,
        'likeUsers' => $like_users,
    );
}

function timellow_handle_get_likes(WP_REST_Request $request)
{
    $post_id = (int) $request->get_param('cid');
    $actor = timellow_resolve_like_actor($request);
    $alias_keys = timellow_get_like_actor_alias_keys($request, $actor['key']);

    timellow_maybe_migrate_like_actor_alias($post_id, $actor, $alias_keys);

    return timellow_prepare_like_response($post_id, $actor['key']);
}

function timellow_handle_toggle_like(WP_REST_Request $request)
{
    global $wpdb;

    $post_id = (int) $request->get_param('cid');
    $actor = timellow_resolve_like_actor($request);
    $alias_keys = timellow_get_like_actor_alias_keys($request, $actor['key']);

    timellow_maybe_migrate_like_actor_alias($post_id, $actor, $alias_keys);

    if ($post_id <= 0 || $actor['key'] === '') {
        return array(
            'success' => false,
            'message' => '点赞参数无效',
        );
    }

    $table = timellow_get_likes_table_name();
    $existing = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND actor_key = %s",
            $post_id,
            $actor['key']
        )
    );

    if ($existing) {
        $wpdb->delete(
            $table,
            array(
                'post_id' => $post_id,
                'actor_key' => $actor['key'],
            ),
            array('%d', '%s')
        );
    } else {
        $wpdb->insert(
            $table,
            array(
                'post_id' => $post_id,
                'actor_key' => $actor['key'],
                'author_name' => $actor['author'],
                'author_email' => $actor['email'],
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
    }

    return timellow_prepare_like_response($post_id, $actor['key']);
}

function timellow_user_can_frontend_post()
{
    return is_user_logged_in() && current_user_can('edit_posts');
}

function timellow_user_can_sticky_posts($post_type = 'post')
{
    $post_type_object = get_post_type_object($post_type);

    if (
        !$post_type_object ||
        empty($post_type_object->cap) ||
        empty($post_type_object->cap->edit_others_posts)
    ) {
        return current_user_can('edit_others_posts');
    }

    return current_user_can($post_type_object->cap->edit_others_posts);
}

function timellow_user_is_administrator()
{
    return is_user_logged_in() && current_user_can('manage_options');
}

function timellow_verify_rest_nonce(WP_REST_Request $request)
{
    $nonce = (string) $request->get_header('X-WP-Nonce');

    if ($nonce === '') {
        $nonce = (string) $request->get_param('_wpnonce');
    }

    return $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest');
}

function timellow_user_can_manage_frontend_post($post_id)
{
    $post = get_post($post_id);

    if (!$post || $post->post_type !== TIMELLOW_SHUOSHUO_POST_TYPE || !timellow_user_is_administrator()) {
        return false;
    }

    return current_user_can('edit_post', $post_id);
}

function timellow_user_can_delete_feed_post($post_id)
{
    $post = get_post($post_id);

    if (!$post || !in_array($post->post_type, array('post', TIMELLOW_SHUOSHUO_POST_TYPE), true)) {
        return false;
    }

    if ($post->post_type === TIMELLOW_SHUOSHUO_POST_TYPE && !timellow_user_is_administrator()) {
        return false;
    }

    return current_user_can('delete_post', $post_id);
}

function timellow_resolve_attachment_id_from_url($url)
{
    $url = esc_url_raw((string) $url);

    if ($url === '') {
        return 0;
    }

    $attachment_id = attachment_url_to_postid($url);

    if ($attachment_id > 0) {
        return (int) $attachment_id;
    }

    $file_name = wp_basename((string) wp_parse_url($url, PHP_URL_PATH));

    if ($file_name === '') {
        return 0;
    }

    $attachments = get_posts(
        array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_wp_attached_file',
                    'value' => $file_name,
                    'compare' => 'LIKE',
                ),
            ),
        )
    );

    if (empty($attachments[0])) {
        return 0;
    }

    return (int) $attachments[0];
}

function timellow_extract_frontend_post_text($content)
{
    $content = (string) $content;

    if ($content === '') {
        return '';
    }

    $content = preg_replace('/<p>\s*(?:<video\b[^>]*>.*?<\/video>|<img\b[^>]*>)\s*<\/p>/is', '', $content);
    $content = preg_replace('/<video\b[^>]*>.*?<\/video>/is', '', $content);
    $content = preg_replace('/<img\b[^>]*>/i', '', $content);
    $content = preg_replace('/<\/p>\s*<p>/i', "\n\n", $content);
    $content = preg_replace('/<br\s*\/?>/i', "\n", $content);

    $text = html_entity_decode(wp_strip_all_tags($content), ENT_QUOTES, get_bloginfo('charset'));
    $text = preg_replace("/\r\n?/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim($text);
}

function timellow_get_frontend_post_media_payload($post_id)
{
    $post = get_post($post_id);

    if (!$post) {
        return array();
    }

    $items = array();
    $video_src = timellow_extract_video_src($post->post_content);

    if ($video_src) {
        $attachment_id = timellow_resolve_attachment_id_from_url($video_src);

        if ($attachment_id > 0) {
            $attachment_url = wp_get_attachment_url($attachment_id);

            if ($attachment_url) {
                $items[] = array(
                    'id' => (int) $attachment_id,
                    'mediaType' => 'video',
                    'type' => (string) get_post_mime_type($attachment_id),
                    'url' => esc_url_raw($attachment_url),
                    'preview' => esc_url_raw($attachment_url),
                );
            }
        }
    }

    foreach (timellow_extract_image_srcs($post->post_content) as $image_src) {
        $attachment_id = timellow_resolve_attachment_id_from_url($image_src);

        if ($attachment_id <= 0) {
            continue;
        }

        $attachment_url = wp_get_attachment_url($attachment_id);

        if (!$attachment_url) {
            continue;
        }

        $preview_url = wp_get_attachment_image_url($attachment_id, 'medium');
        $items[] = array(
            'id' => (int) $attachment_id,
            'mediaType' => 'image',
            'type' => (string) get_post_mime_type($attachment_id),
            'url' => esc_url_raw($attachment_url),
            'preview' => esc_url_raw($preview_url ? $preview_url : $attachment_url),
        );
    }

    return $items;
}

function timellow_prepare_frontend_post_editor_payload($post_id)
{
    $post = get_post($post_id);

    if (!$post || $post->post_type !== TIMELLOW_SHUOSHUO_POST_TYPE) {
        return array();
    }

    return array(
        'postId' => (int) $post_id,
        'content' => timellow_extract_frontend_post_text($post->post_content),
        'position' => timellow_get_post_position($post_id),
        'visibility' => $post->post_status === 'private' ? 'private' : 'public',
        'isAdvertise' => timellow_is_post_ad($post_id),
        'isSticky' => is_sticky($post_id),
        'mediaFiles' => timellow_get_frontend_post_media_payload($post_id),
    );
}

function timellow_collect_frontend_media_files($file_params)
{
    $media_files = array();

    if (!is_array($file_params)) {
        return $media_files;
    }

    foreach ($file_params as $key => $file) {
        if (strpos((string) $key, 'media_') !== 0) {
            continue;
        }

        if (!is_array($file)) {
            continue;
        }

        $media_files[$key] = $file;
    }

    uksort(
        $media_files,
        function ($left, $right) {
            $left_index = (int) preg_replace('/\D+/', '', (string) $left);
            $right_index = (int) preg_replace('/\D+/', '', (string) $right);

            return $left_index <=> $right_index;
        }
    );

    return $media_files;
}

function timellow_collect_frontend_attachment_ids(WP_REST_Request $request)
{
    $raw_ids = $request->get_param('attachment_ids');

    if (is_array($raw_ids)) {
        return array_values(array_unique(array_filter(array_map('absint', $raw_ids))));
    }

    if (!is_string($raw_ids) || trim($raw_ids) === '') {
        return array();
    }

    $decoded = json_decode(wp_unslash($raw_ids), true);

    if (is_array($decoded)) {
        return array_values(array_unique(array_filter(array_map('absint', $decoded))));
    }

    $parts = array_map('trim', explode(',', $raw_ids));

    return array_values(array_unique(array_filter(array_map('absint', $parts))));
}

function timellow_cleanup_uploaded_attachments($attachment_ids)
{
    foreach ((array) $attachment_ids as $attachment_id) {
        $attachment_id = (int) $attachment_id;

        if ($attachment_id > 0) {
            wp_delete_attachment($attachment_id, true);
        }
    }
}

function timellow_resolve_frontend_post_status($visibility)
{
    if ($visibility === 'private') {
        if (!current_user_can('publish_posts') || !current_user_can('edit_private_posts')) {
            return new WP_Error('timellow_private_forbidden', '当前账号无权发布私密说说');
        }

        return 'private';
    }

    return current_user_can('publish_posts') ? 'publish' : 'pending';
}

function timellow_upload_frontend_media($media_files, $post_id)
{
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $images = array();
    $video = null;
    $attachment_ids = array();

    foreach ($media_files as $file) {
        if (empty($file['name']) || (isset($file['error']) && (int) $file['error'] === UPLOAD_ERR_NO_FILE)) {
            continue;
        }

        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            timellow_cleanup_uploaded_attachments($attachment_ids);

            return new WP_Error('timellow_media_missing', '上传文件不存在');
        }

        if (isset($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            timellow_cleanup_uploaded_attachments($attachment_ids);

            return new WP_Error('timellow_media_error', '媒体上传失败，请重试');
        }

        $file_name = isset($file['name']) ? (string) $file['name'] : '';
        $file_type = isset($file['type']) ? (string) $file['type'] : '';
        $detected_type = wp_check_filetype($file_name);
        $mime_type = $file_type !== '' ? $file_type : (isset($detected_type['type']) ? (string) $detected_type['type'] : '');
        $is_image = strpos($mime_type, 'image/') === 0;
        $is_video = strpos($mime_type, 'video/') === 0;

        if (!$is_image && !$is_video) {
            timellow_cleanup_uploaded_attachments($attachment_ids);

            return new WP_Error('timellow_media_invalid', '仅支持上传图片或视频');
        }

        if ($is_video && ($video !== null || !empty($images))) {
            timellow_cleanup_uploaded_attachments($attachment_ids);

            return new WP_Error('timellow_video_limit', '只能上传 1 个视频，且不能与图片混传');
        }

        if ($is_image && $video !== null) {
            timellow_cleanup_uploaded_attachments($attachment_ids);

            return new WP_Error('timellow_image_mix', '上传视频后不能继续上传图片');
        }

        if ($is_image && count($images) >= 9) {
            timellow_cleanup_uploaded_attachments($attachment_ids);

            return new WP_Error('timellow_image_limit', '最多只能上传 9 张图片');
        }

        $attachment_id = media_handle_sideload($file, $post_id);

        if (is_wp_error($attachment_id)) {
            timellow_cleanup_uploaded_attachments($attachment_ids);

            return $attachment_id;
        }

        $attachment_url = wp_get_attachment_url($attachment_id);

        if (!$attachment_url) {
            $attachment_ids[] = $attachment_id;
            timellow_cleanup_uploaded_attachments($attachment_ids);

            return new WP_Error('timellow_media_url', '媒体保存失败，请重试');
        }

        $attachment_ids[] = $attachment_id;

        if ($is_video) {
            $video = array(
                'id' => $attachment_id,
                'url' => $attachment_url,
            );
            continue;
        }

        $images[] = array(
            'id' => $attachment_id,
            'url' => $attachment_url,
        );
    }

    return array(
        'images' => $images,
        'video' => $video,
        'attachment_ids' => $attachment_ids,
        'cleanup_attachment_ids' => $attachment_ids,
    );
}

function timellow_prepare_frontend_library_media($attachment_ids)
{
    $images = array();
    $video = null;
    $validated_attachment_ids = array();

    foreach ((array) $attachment_ids as $attachment_id) {
        $attachment_id = (int) $attachment_id;

        if ($attachment_id <= 0) {
            continue;
        }

        $attachment = get_post($attachment_id);

        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new WP_Error('timellow_attachment_invalid', '所选媒体不存在或已被删除');
        }

        if (!current_user_can('edit_post', $attachment_id)) {
            return new WP_Error('timellow_attachment_forbidden', '没有权限使用所选媒体');
        }

        $mime_type = (string) get_post_mime_type($attachment_id);
        $is_image = strpos($mime_type, 'image/') === 0;
        $is_video = strpos($mime_type, 'video/') === 0;

        if (!$is_image && !$is_video) {
            return new WP_Error('timellow_attachment_type', '媒体库中仅支持图片和视频');
        }

        if ($is_video && ($video !== null || !empty($images))) {
            return new WP_Error('timellow_attachment_video_limit', '只能选择 1 个视频，且不能与图片混选');
        }

        if ($is_image && $video !== null) {
            return new WP_Error('timellow_attachment_mix', '选择视频后不能再添加图片');
        }

        if ($is_image && count($images) >= 9) {
            return new WP_Error('timellow_attachment_image_limit', '最多只能选择 9 张图片');
        }

        $attachment_url = wp_get_attachment_url($attachment_id);

        if (!$attachment_url) {
            return new WP_Error('timellow_attachment_url', '所选媒体地址无效');
        }

        $validated_attachment_ids[] = $attachment_id;

        if ($is_video) {
            $video = array(
                'id' => $attachment_id,
                'url' => $attachment_url,
            );
            continue;
        }

        $images[] = array(
            'id' => $attachment_id,
            'url' => $attachment_url,
        );
    }

    return array(
        'images' => $images,
        'video' => $video,
        'attachment_ids' => $validated_attachment_ids,
        'cleanup_attachment_ids' => array(),
    );
}

function timellow_attach_media_to_post($attachment_ids, $post_id)
{
    foreach ((array) $attachment_ids as $attachment_id) {
        $attachment_id = (int) $attachment_id;

        if ($attachment_id <= 0) {
            continue;
        }

        wp_update_post(
            array(
                'ID' => $attachment_id,
                'post_parent' => (int) $post_id,
            )
        );
    }
}

function timellow_build_frontend_post_content($text, $media)
{
    $parts = array();
    $text = trim((string) $text);

    if ($text !== '') {
        $parts[] = wpautop(esc_html($text));
    }

    if (!empty($media['video']['url'])) {
        $parts[] = '<p><video controls controlsList="nodownload" preload="metadata" src="' . esc_url($media['video']['url']) . '"></video></p>';
    }

    if (!empty($media['images']) && is_array($media['images'])) {
        foreach ($media['images'] as $image) {
            if (empty($image['url'])) {
                continue;
            }

            $parts[] = '<p><img src="' . esc_url($image['url']) . '" alt=""></p>';
        }
    }

    return implode("\n\n", $parts);
}

function timellow_generate_frontend_post_title($text)
{
    $plain_text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));

    if ($plain_text === '') {
        return '说说 ' . wp_date('Y-m-d H:i');
    }

    $title = mb_substr($plain_text, 0, 30, 'UTF-8');

    if (mb_strlen($plain_text, 'UTF-8') > 30) {
        $title .= '...';
    }

    return $title;
}

function timellow_get_frontend_post_success_message($status)
{
    switch ($status) {
        case 'private':
            return '已保存为私密说说';

        case 'pending':
            return '说说已提交，等待审核';

        default:
            return '发布成功';
    }
}

function timellow_handle_get_post_editor_data(WP_REST_Request $request)
{
    $post_id = absint($request->get_param('postId'));

    if (!timellow_verify_rest_nonce($request)) {
        return array(
            'success' => false,
            'message' => '请求已过期，请刷新页面后重试',
        );
    }

    if (!timellow_user_can_manage_frontend_post($post_id)) {
        return array(
            'success' => false,
            'message' => '没有权限编辑这条说说',
        );
    }

    $payload = timellow_prepare_frontend_post_editor_payload($post_id);

    if (empty($payload)) {
        return array(
            'success' => false,
            'message' => '说说不存在或已被删除',
        );
    }

    return array(
        'success' => true,
        'post' => $payload,
    );
}

function timellow_handle_update_post(WP_REST_Request $request)
{
    $post_id = absint($request->get_param('postId'));

    if (!timellow_verify_rest_nonce($request)) {
        return array(
            'success' => false,
            'message' => '请求已过期，请刷新页面后重试',
        );
    }

    if (!timellow_user_can_manage_frontend_post($post_id)) {
        return array(
            'success' => false,
            'message' => '没有权限编辑这条说说',
        );
    }

    $content = sanitize_textarea_field((string) $request->get_param('content'));
    $position = timellow_normalize_position_label((string) $request->get_param('position'));
    $position_url = esc_url_raw((string) $request->get_param('positionUrl'));
    $visibility = sanitize_key((string) $request->get_param('visibility'));
    $is_advertise = (string) $request->get_param('isAdvertise') === '1' ? '1' : '0';
    $is_sticky = (string) $request->get_param('isSticky') === '1' && timellow_user_can_sticky_posts(TIMELLOW_SHUOSHUO_POST_TYPE);
    $attachment_ids = timellow_collect_frontend_attachment_ids($request);
    $media_files = timellow_collect_frontend_media_files($request->get_file_params());
    $return_url = esc_url_raw((string) $request->get_param('returnUrl'));

    if ($content === '' && empty($media_files) && empty($attachment_ids)) {
        return array(
            'success' => false,
            'message' => '请输入说说内容或选择图片/视频',
        );
    }

    $status = timellow_resolve_frontend_post_status($visibility === 'private' ? 'private' : 'public');

    if (is_wp_error($status)) {
        return array(
            'success' => false,
            'message' => $status->get_error_message(),
        );
    }

    if (!empty($attachment_ids)) {
        $media = timellow_prepare_frontend_library_media($attachment_ids);
    } elseif (!empty($media_files)) {
        $media = timellow_upload_frontend_media($media_files, $post_id);
    } else {
        $media = array(
            'images' => array(),
            'video' => null,
            'attachment_ids' => array(),
            'cleanup_attachment_ids' => array(),
        );
    }

    if (is_wp_error($media)) {
        return array(
            'success' => false,
            'message' => $media->get_error_message(),
        );
    }

    $title = timellow_generate_frontend_post_title($content);
    $post_content = timellow_build_frontend_post_content($content, $media);
    $updated_post = wp_update_post(
        array(
            'ID' => $post_id,
            'post_status' => $status,
            'post_title' => $title,
            'post_content' => $post_content,
        ),
        true
    );

    if (is_wp_error($updated_post)) {
        timellow_cleanup_uploaded_attachments($media['cleanup_attachment_ids']);

        return array(
            'success' => false,
            'message' => $updated_post->get_error_message(),
        );
    }

    update_post_meta($post_id, '_timellow_position', $position);
    update_post_meta($post_id, '_timellow_position_url', $position_url);
    update_post_meta($post_id, '_timellow_is_advertise', $is_advertise);
    timellow_attach_media_to_post($media['attachment_ids'], $post_id);

    if ($status === 'publish' && $is_sticky) {
        stick_post($post_id);
    } else {
        unstick_post($post_id);
    }

    if (!empty($media['images'][0]['id'])) {
        set_post_thumbnail($post_id, (int) $media['images'][0]['id']);
    } else {
        delete_post_thumbnail($post_id);
    }

    $redirect = $return_url !== '' ? $return_url : home_url('/');

    if ($status === 'private') {
        $private_url = get_permalink($post_id);

        if ($private_url) {
            $redirect = $private_url;
        }
    }

    return array(
        'success' => true,
        'message' => '说说已更新',
        'postId' => $post_id,
        'status' => $status,
        'redirect' => esc_url_raw($redirect),
    );
}

function timellow_handle_delete_comment(WP_REST_Request $request)
{
    $payload = $request->get_json_params();
    $comment_id = is_array($payload) && isset($payload['commentId']) ? absint($payload['commentId']) : absint($request->get_param('commentId'));
    $comment = get_comment($comment_id);

    if (!timellow_verify_rest_nonce($request)) {
        return array(
            'success' => false,
            'message' => '请求已过期，请刷新页面后重试',
        );
    }

    if (!$comment || !timellow_user_is_administrator() || !current_user_can('moderate_comments')) {
        return array(
            'success' => false,
            'message' => '没有权限删除这条评论',
        );
    }

    if (!wp_delete_comment($comment_id, true)) {
        return array(
            'success' => false,
            'message' => '评论删除失败，请稍后重试',
        );
    }

    return array(
        'success' => true,
        'message' => '评论已删除',
        'postId' => (int) $comment->comment_post_ID,
        'deletedIds' => array($comment_id),
    );
}

function timellow_handle_delete_post(WP_REST_Request $request)
{
    $payload = $request->get_json_params();
    $post_id = is_array($payload) && isset($payload['postId']) ? absint($payload['postId']) : absint($request->get_param('postId'));
    $post = get_post($post_id);
    $label = $post && $post->post_type === 'post' ? '文章' : '说说';
    $denied_message = $post && $post->post_type === 'post' ? '没有权限删除这篇文章' : '没有权限删除这条说说';

    if (!timellow_verify_rest_nonce($request)) {
        return array(
            'success' => false,
            'message' => '请求已过期，请刷新页面后重试',
        );
    }

    if (!timellow_user_can_delete_feed_post($post_id)) {
        return array(
            'success' => false,
            'message' => $denied_message,
        );
    }

    if (!wp_trash_post($post_id)) {
        return array(
            'success' => false,
            'message' => $label . '移入回收站失败，请稍后重试',
        );
    }

    return array(
        'success' => true,
        'message' => $label . '已移入回收站',
        'postId' => $post_id,
        'redirect' => esc_url_raw(home_url('/')),
    );
}

function timellow_handle_create_post(WP_REST_Request $request)
{
    if (!timellow_user_can_frontend_post()) {
        return array(
            'success' => false,
            'message' => '请先登录具有发布说说权限的账号',
        );
    }

    $content = sanitize_textarea_field((string) $request->get_param('content'));
    $position = timellow_normalize_position_label((string) $request->get_param('position'));
    $position_url = esc_url_raw((string) $request->get_param('positionUrl'));
    $visibility = sanitize_key((string) $request->get_param('visibility'));
    $is_advertise = (string) $request->get_param('isAdvertise') === '1' ? '1' : '0';
    $is_sticky = (string) $request->get_param('isSticky') === '1' && timellow_user_can_sticky_posts(TIMELLOW_SHUOSHUO_POST_TYPE);
    $attachment_ids = timellow_collect_frontend_attachment_ids($request);
    $media_files = timellow_collect_frontend_media_files($request->get_file_params());

    if ($content === '' && empty($media_files) && empty($attachment_ids)) {
        return array(
            'success' => false,
            'message' => '请输入说说内容或选择图片/视频',
        );
    }

    $status = timellow_resolve_frontend_post_status($visibility === 'private' ? 'private' : 'public');

    if (is_wp_error($status)) {
        return array(
            'success' => false,
            'message' => $status->get_error_message(),
        );
    }

    $title = timellow_generate_frontend_post_title($content);
    $post_id = wp_insert_post(
        array(
            'post_type' => TIMELLOW_SHUOSHUO_POST_TYPE,
            'post_status' => 'draft',
            'post_title' => $title,
            'post_content' => '',
            'post_author' => get_current_user_id(),
            'comment_status' => 'open',
        ),
        true
    );

    if (is_wp_error($post_id)) {
        return array(
            'success' => false,
            'message' => $post_id->get_error_message(),
        );
    }

    if (!empty($attachment_ids)) {
        $media = timellow_prepare_frontend_library_media($attachment_ids);
    } else {
        $media = timellow_upload_frontend_media($media_files, (int) $post_id);
    }

    if (is_wp_error($media)) {
        wp_delete_post((int) $post_id, true);

        return array(
            'success' => false,
            'message' => $media->get_error_message(),
        );
    }

    $post_content = timellow_build_frontend_post_content($content, $media);
    $updated_post = wp_update_post(
        array(
            'ID' => (int) $post_id,
            'post_status' => $status,
            'post_title' => $title,
            'post_content' => $post_content,
        ),
        true
    );

    if (is_wp_error($updated_post)) {
        timellow_cleanup_uploaded_attachments($media['cleanup_attachment_ids']);
        wp_delete_post((int) $post_id, true);

        return array(
            'success' => false,
            'message' => $updated_post->get_error_message(),
        );
    }

    update_post_meta((int) $post_id, '_timellow_position', $position);
    update_post_meta((int) $post_id, '_timellow_position_url', $position_url);
    update_post_meta((int) $post_id, '_timellow_is_advertise', $is_advertise);
    timellow_attach_media_to_post($media['attachment_ids'], (int) $post_id);

    if ($status === 'publish' && $is_sticky) {
        stick_post((int) $post_id);
    } else {
        unstick_post((int) $post_id);
    }

    if (!empty($media['images'][0]['id'])) {
        set_post_thumbnail((int) $post_id, (int) $media['images'][0]['id']);
    }

    $redirect = home_url('/');

    if ($status === 'private') {
        $private_url = get_permalink((int) $post_id);

        if ($private_url) {
            $redirect = $private_url;
        }
    }

    return array(
        'success' => true,
        'message' => timellow_get_frontend_post_success_message($status),
        'postId' => (int) $post_id,
        'status' => $status,
        'redirect' => esc_url_raw($redirect),
    );
}

function timellow_handle_add_comment(WP_REST_Request $request)
{
    $payload = $request->get_json_params();

    if (!is_array($payload)) {
        return array(
            'success' => false,
            'message' => '评论数据格式错误',
        );
    }

    $post_id = isset($payload['cid']) ? (int) $payload['cid'] : 0;
    $parent = isset($payload['coid']) ? (int) $payload['coid'] : 0;
    $author = isset($payload['author']) ? sanitize_text_field($payload['author']) : '';
    $email = isset($payload['mail']) ? sanitize_email($payload['mail']) : '';
    $url = isset($payload['url']) ? esc_url_raw($payload['url']) : '';
    $text = isset($payload['text']) ? wp_kses_post($payload['text']) : '';

    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $author = $user->display_name !== '' ? $user->display_name : $user->user_login;
        $email = sanitize_email($user->user_email);
        $url = $user->user_url !== '' ? esc_url_raw($user->user_url) : '';
    }

    if ($post_id <= 0 || trim(wp_strip_all_tags($text)) === '') {
        return array(
            'success' => false,
            'message' => '请填写必要信息',
        );
    }

    if (!is_user_logged_in() && ($author === '' || $email === '')) {
        return array(
            'success' => false,
            'message' => '请填写必要信息',
        );
    }

    if (!comments_open($post_id)) {
        return array(
            'success' => false,
            'message' => '评论已关闭',
        );
    }

    if (get_option('comment_registration') && !is_user_logged_in()) {
        return array(
            'success' => false,
            'message' => '当前站点需要登录后才可评论',
        );
    }

    $commentdata = array(
        'comment_post_ID' => $post_id,
        'comment_parent' => $parent,
        'comment_author' => $author,
        'comment_author_email' => $email,
        'comment_author_url' => $url,
        'comment_content' => $text,
        'user_id' => get_current_user_id(),
        'comment_type' => 'comment',
    );

    $comment_id = wp_new_comment(wp_slash($commentdata), true);

    if (is_wp_error($comment_id)) {
        return array(
            'success' => false,
            'message' => $comment_id->get_error_message(),
        );
    }

    $comment = get_comment($comment_id);

    if (!$comment) {
        return array(
            'success' => false,
            'message' => '评论发表失败',
        );
    }

    if ((string) $comment->comment_approved !== '1') {
        return array(
            'success' => false,
            'message' => '评论已提交，待审核后显示',
        );
    }

    return array(
        'success' => true,
        'comment' => timellow_prepare_comment_payload($comment),
    );
}

function timellow_handle_friend_links()
{
    return array(
        'success' => true,
        'data' => timellow_get_friend_links(),
    );
}

function timellow_register_rest_routes()
{
    register_rest_route(
        'timellow/v1',
        '/action',
        array(
            'methods' => WP_REST_Server::ALLMETHODS,
            'callback' => 'timellow_rest_action_handler',
            'permission_callback' => '__return_true',
        )
    );
}
add_action('rest_api_init', 'timellow_register_rest_routes');

function timellow_rest_action_handler(WP_REST_Request $request)
{
    $action = sanitize_key((string) $request->get_param('do'));

    switch ($action) {
        case 'getlikes':
            return rest_ensure_response(timellow_handle_get_likes($request));

        case 'like':
            return rest_ensure_response(timellow_handle_toggle_like($request));

        case 'addcomment':
            return rest_ensure_response(timellow_handle_add_comment($request));

        case 'createpost':
            return rest_ensure_response(timellow_handle_create_post($request));

        case 'getposteditordata':
            return rest_ensure_response(timellow_handle_get_post_editor_data($request));

        case 'updatepost':
            return rest_ensure_response(timellow_handle_update_post($request));

        case 'deletepost':
            return rest_ensure_response(timellow_handle_delete_post($request));

        case 'deletecomment':
            return rest_ensure_response(timellow_handle_delete_comment($request));

        case 'getfriendlinks':
            return rest_ensure_response(timellow_handle_friend_links());

        default:
            return rest_ensure_response(
                array(
                    'success' => false,
                    'message' => '未知操作',
                )
            );
    }
}

function timellow_login_failed_redirect($username)
{
    $referrer = wp_get_referer();

    if (!$referrer || strpos($referrer, 'wp-login.php') !== false || strpos($referrer, 'wp-admin') !== false) {
        return;
    }

    $redirect = add_query_arg('login_error', 'invalid', remove_query_arg(array('login_error', 'loggedout'), $referrer));
    wp_safe_redirect($redirect);
    exit;
}
add_action('wp_login_failed', 'timellow_login_failed_redirect');

function timellow_empty_login_redirect($user, $username, $password)
{
    if (!empty($username) && !empty($password)) {
        return $user;
    }

    $referrer = wp_get_referer();

    if (!$referrer || strpos($referrer, 'wp-login.php') !== false || strpos($referrer, 'wp-admin') !== false) {
        return $user;
    }

    wp_safe_redirect(add_query_arg('login_error', 'empty', remove_query_arg(array('login_error', 'loggedout'), $referrer)));
    exit;
}
add_filter('authenticate', 'timellow_empty_login_redirect', 30, 3);
