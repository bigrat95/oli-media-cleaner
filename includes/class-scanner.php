<?php
defined('ABSPATH') || exit;

class OLIMC_Scanner {

    private $used_ids = [];
    private $path_cache = [];
    /** @var array<int, true>|null */
    private $attachment_id_index = null;
    /** @var string[] Field keys (field_xxx) for image / file / gallery, filled while walking ACF definitions. */
    private $acf_media_field_key_buffer = [];

    /**
     * Run a full scan. Returns array of unused attachment IDs.
     * Works in batches via AJAX to avoid timeouts.
     */
    public function get_all_attachment_ids() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status != 'trash'
             ORDER BY ID ASC"
        );
    }

    /**
     * Get total attachment count.
     */
    public function get_total_attachment_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status != 'trash'"
        );
    }

    /**
     * Collect all used attachment IDs from every source we know about.
     */
    public function collect_used_ids() {
        $this->used_ids = [];
        $this->ensure_attachment_lookup();

        // AJAX and cron must call OLIMC_Acf_Meta_Scanner::step() instead. This
        // synchronous helper stops before max_execution_time on web requests.
        $engine   = new OLIMC_Acf_Meta_Scanner($this);
        $state    = $engine->initial_state();
        $deadline = $this->synchronous_scan_deadline();
        $guard    = 0;
        while (!in_array($state['phase'], ['done', 'failed'], true) && $guard < 1000000) {
            if (microtime(true) >= $deadline) {
                $state['phase'] = 'failed';
                $state['error'] = 'Scan stopped before the PHP time limit.';
                break;
            }
            $remaining_ms = (int) max(200, ($deadline - microtime(true)) * 1000);
            $state        = $engine->step($state, min(2000, $remaining_ms));
            $guard++;
        }

        if (!OLIMC_Acf_Meta_Scanner::may_mark_unused($state)) {
            // Incomplete or failed scan: every known attachment stays "used".
            $ids = $this->get_all_attachment_ids();
            if (empty($ids) && is_array($this->attachment_id_index)) {
                $ids = array_keys($this->attachment_id_index);
            }
            $this->used_ids = array_map('intval', $ids);
            return $this->used_ids;
        }

        $this->used_ids = array_map('intval', array_keys($state['used']));
        return $this->used_ids;
    }

    /**
     * Wall clock after which a synchronous scan must stop and fail safe.
     *
     * Web SAPIs honour max_execution_time (minus a few seconds). CLI has no
     * request limit; the cap only prevents a runaway loop.
     *
     * @return float
     */
    private function synchronous_scan_deadline() {
        $max = function_exists('ini_get') ? (int) ini_get('max_execution_time') : 0;
        $cli = function_exists('php_sapi_name') && php_sapi_name() === 'cli';
        if ($cli || $max <= 0) {
            return microtime(true) + 3600;
        }
        return microtime(true) + max(5, $max - 5);
    }

    /**
     * Sources that are not the ACF meta walk (content, WooCommerce, theme files, …).
     */
    public function collect_auxiliary_sources() {
        $this->collect_featured_images();
        $this->collect_post_content_images();
        $this->collect_option_images();
        $this->collect_woocommerce_gallery();
        $this->collect_woocommerce_variations();
        $this->collect_site_identity();
        $this->collect_widget_images();
        $this->collect_elementor_images();
        $this->collect_taxonomy_thumbnails();
        $this->collect_term_description_images();
        $this->collect_theme_files();
        $this->collect_css_background_images();
    }

    /**
     * Load ACF, including local JSON and PHP field groups, then list media fields.
     *
     * Returns null when ACF is inactive or field groups could not be resolved
     * (caller must use the raw-meta fallback). An empty list means ACF is active
     * and simply has no image/file/gallery fields.
     *
     * @return array{names: string[], keys: string[], gallery_names: string[]}|null
     */
    public function get_acf_media_field_targets() {
        $this->acf_media_field_key_buffer = [];
        if (!$this->ensure_acf_loaded()) {
            return null;
        }

        $groups = acf_get_field_groups();
        if (!is_array($groups) || $groups === []) {
            return null;
        }

        $image_keys   = [];
        $file_keys    = [];
        $gallery_keys = [];
        $all_keys     = [];
        $resolved     = false;

        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['key'])) {
                continue;
            }
            $fields = function_exists('acf_get_fields') ? acf_get_fields($group['key']) : null;
            if (!is_array($fields)) {
                continue;
            }
            $resolved = true;
            $this->collect_acf_field_keys_recursive($fields, $image_keys, $file_keys, $gallery_keys, $all_keys);
        }

        if (!$resolved) {
            return null;
        }

        return [
            'names'         => array_values(array_unique(array_merge($image_keys, $file_keys, $gallery_keys))),
            'keys'          => array_values(array_unique($this->acf_media_field_key_buffer)),
            'gallery_names' => array_values(array_unique($gallery_keys)),
        ];
    }

    /**
     * Make sure ACF has booted and local JSON / PHP groups are included.
     *
     * Cron and early AJAX used to call acf_get_field_groups() before acf/init,
     * which skips theme JSON (the concert field group on sites like osm.ca).
     *
     * @return bool Whether the ACF API is available.
     */
    private function ensure_acf_loaded() {
        if (!function_exists('acf_get_field_groups')) {
            return false;
        }
        $init_done       = function_exists('did_action') && did_action('init');
        $acf_init_done   = function_exists('did_action') && did_action('acf/init');
        $fields_included = function_exists('did_action') && did_action('acf/include_fields');

        // Local JSON is loaded on acf/include_fields. After WordPress init, do not
        // call acf_init() again (that re-registers hooks). Call include_fields
        // directly when it has not run yet. acf_init() is only for a scan that
        // really is running before init.
        if (!$fields_included && function_exists('acf_include_fields')) {
            if (!$init_done && !$acf_init_done && function_exists('acf_init')) {
                acf_init();
            } else {
                acf_include_fields();
            }
        }
        return true;
    }

    /**
     * IDs recorded by auxiliary collectors since the last drain.
     *
     * @return int[]
     */
    public function drain_used_ids() {
        $ids = $this->used_ids;
        $this->used_ids = [];
        return $ids;
    }

    /**
     * Recursively collect ACF field names by type.
     *
     * Clone fields do not expose the referenced group's fields as sub_fields, so
     * we resolve $field['clone'] (group keys and/or field keys) via the ACF API.
     *
     * @param array      $fields        Field definitions.
     * @param array      $image_keys    Image field names.
     * @param array      $file_keys     File field names.
     * @param array      $gallery_keys  Gallery field names.
     * @param array      $all_keys      Every field name seen.
     * @param array|null $visited_clones Clone selectors already followed.
     */
    private function collect_acf_field_keys_recursive($fields, &$image_keys, &$file_keys, &$gallery_keys, &$all_keys, &$visited_clones = null) {
        if ($visited_clones === null) {
            $visited_clones = [];
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            if (!empty($field['name'])) {
                $all_keys[] = $field['name'];
            }

            $type = isset($field['type']) ? $field['type'] : '';

            switch ($type) {
                case 'image':
                    $image_keys[] = $field['name'];
                    break;
                case 'file':
                    $file_keys[] = $field['name'];
                    break;
                case 'gallery':
                    $gallery_keys[] = $field['name'];
                    break;
            }

            if (in_array($type, ['image', 'file', 'gallery'], true) && !empty($field['key']) && is_string($field['key'])) {
                $this->acf_media_field_key_buffer[] = $field['key'];
            }

            if (!empty($field['sub_fields'])) {
                $this->collect_acf_field_keys_recursive($field['sub_fields'], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
            }
            if (!empty($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (!empty($layout['sub_fields'])) {
                        $this->collect_acf_field_keys_recursive($layout['sub_fields'], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
                    }
                }
            }

            if ($type === 'clone' && !empty($field['clone']) && is_array($field['clone'])) {
                $this->collect_acf_cloned_fields($field['clone'], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
            }
        }
    }

    /**
     * Follow ACF clone selectors to the referenced field group or field definitions.
     *
     * @param array $clone_selectors Group keys (group_xxx) and/or field keys (field_xxx).
     * @param array $image_keys      Image field names.
     * @param array $file_keys       File field names.
     * @param array $gallery_keys    Gallery field names.
     * @param array $all_keys        Every field name seen.
     * @param array $visited_clones  Clone selectors already followed.
     */
    private function collect_acf_cloned_fields($clone_selectors, &$image_keys, &$file_keys, &$gallery_keys, &$all_keys, &$visited_clones) {
        foreach ($clone_selectors as $clone_selector) {
            if (!is_string($clone_selector) || $clone_selector === '') {
                continue;
            }
            if (isset($visited_clones[$clone_selector])) {
                continue;
            }
            $visited_clones[$clone_selector] = true;

            $cloned_fields = function_exists('acf_get_fields') ? acf_get_fields($clone_selector) : null;
            if (!empty($cloned_fields) && is_array($cloned_fields)) {
                $this->collect_acf_field_keys_recursive($cloned_fields, $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
                continue;
            }

            if (!function_exists('acf_get_field')) {
                continue;
            }
            $cloned_field = acf_get_field($clone_selector);
            if (!empty($cloned_field) && is_array($cloned_field)) {
                $this->collect_acf_field_keys_recursive([$cloned_field], $image_keys, $file_keys, $gallery_keys, $all_keys, $visited_clones);
            }
        }
    }

    /**
     * Whether a meta key is this ACF field, including repeater / flex / clone prefixes.
     *
     * Matches `images`, `content_blocks_4_images`, and
     * `content_blocks_4_image_carousel_images` (clone with prefix_name).
     *
     * @param string $meta_key   Stored meta key.
     * @param string $field_name ACF field name.
     * @return bool
     */
    public function acf_meta_key_matches_field($meta_key, $field_name) {
        if ($meta_key === $field_name) {
            return true;
        }
        $suffix = '_' . $field_name;
        return substr($meta_key, -strlen($suffix)) === $suffix;
    }

    /**
     * Check if a specific attachment ID is used.
     */
    public function is_used($attachment_id) {
        return in_array((int) $attachment_id, $this->used_ids, true);
    }

    /**
     * Merge attachment IDs discovered by meta parsers.
     *
     * @param int[]|int|string $ids Attachment ID or list of IDs.
     */
    public function merge_used_attachment_ids( $ids ) {
        if ( ! is_array( $ids ) ) {
            $ids = [ $ids ];
        }
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) {
                $this->used_ids[] = $id;
            }
        }
    }

    /**
     * Build a lookup of valid attachment post IDs (for safe generic meta matching).
     */
    public function ensure_attachment_lookup() {
        if ( null !== $this->attachment_id_index ) {
            return;
        }
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );
        $this->attachment_id_index = array_fill_keys( array_map( 'intval', $ids ), true );
    }

    /**
     * @param int $id Attachment ID.
     * @return bool
     */
    public function is_known_attachment_id( $id ) {
        $this->ensure_attachment_lookup();
        return isset( $this->attachment_id_index[ (int) $id ] );
    }

    /**
     * Resolve a relative uploads path to an attachment ID (for parsers).
     *
     * @param string $path Upload path relative to the site uploads directory.
     * @return int
     */
    public function resolve_upload_path_to_id( $path ) {
        return (int) $this->find_attachment_by_path( $path );
    }

    /**
     * Get attachment info for display.
     */
    public function get_attachment_info($attachment_id) {
        $post = get_post($attachment_id);
        if (!$post) return null;

        $mime = get_post_mime_type($attachment_id);
        $is_image = strpos($mime, 'image') !== false;

        if ($is_image) {
            $url = wp_get_original_image_url($attachment_id);
            $thumb_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        } else {
            $url = wp_get_attachment_url($attachment_id);
            $thumb_url = '';
        }

        $file_size = $this->calculate_total_size($attachment_id, $mime);

        $ext_info = wp_check_filetype($url ?: '');

        return [
            'id'         => $attachment_id,
            'title'      => $post->post_title,
            'url'        => $url,
            'thumb_url'  => $thumb_url,
            'mime'       => $mime,
            'ext'        => $ext_info['ext'] ?? '',
            'file_size'  => $file_size,
            'date'       => $post->post_date,
        ];
    }

    /**
     * Calculate total disk size of an attachment (original + all thumbnails).
     */
    public function calculate_total_size($attachment_id, $mime = '') {
        if (!$mime) {
            $mime = get_post_mime_type($attachment_id);
        }

        $total = 0;

        if (strpos($mime, 'image') !== false) {
            $meta = wp_get_attachment_metadata($attachment_id);
            $upload_dir = wp_upload_dir();
            $basedir = $upload_dir['basedir'];

            // Main file
            if (!empty($meta['file'])) {
                $main_path = $basedir . '/' . $meta['file'];
                $total += wp_filesize($main_path);
                $dir = dirname($main_path);
            }

            // Thumbnails
            if (!empty($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (!empty($size['file']) && isset($dir)) {
                        $total += wp_filesize($dir . '/' . $size['file']);
                    }
                }
            }

            // Original image (if scaled)
            if (!empty($meta['original_image']) && isset($dir)) {
                $total += wp_filesize($dir . '/' . $meta['original_image']);
            }
        } else {
            $file = get_attached_file($attachment_id);
            if ($file) {
                $total = wp_filesize($file);
            }
        }

        return $total;
    }

    // ─── Collection methods ───────────────────────────────────────────

    private function collect_featured_images() {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id'
             AND meta_value > 0"
        );
        $this->used_ids = array_merge($this->used_ids, $ids);
    }

    private function collect_post_content_images() {
        global $wpdb;
        $like = $this->upload_content_like();
        if ($like === null) {
            return;
        }
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_content LIKE %s
             AND post_status != 'trash'
             AND post_type NOT IN ('revision', 'attachment')",
            $like
        ));

        $attachment_ids = [];
        foreach ($rows as $content) {
            // Match wp-image-123 class (Gutenberg/Classic editor)
            if (preg_match_all('/wp-image-(\d+)/', $content, $matches)) {
                $attachment_ids = array_merge($attachment_ids, $matches[1]);
            }
            // Match attachment page links
            if (preg_match_all('/\?attachment_id=(\d+)/', $content, $matches)) {
                $attachment_ids = array_merge($attachment_ids, $matches[1]);
            }
            // Match data-id attributes
            if (preg_match_all('/data-id=["\'](\d+)["\']/', $content, $matches)) {
                $attachment_ids = array_merge($attachment_ids, $matches[1]);
            }
            foreach (OLIMC_Media_Usage_Parser::relative_upload_paths($content) as $path) {
                $id = $this->find_attachment_by_path($path);
                if ($id) $attachment_ids[] = $id;
            }
        }

        $this->used_ids = array_merge($this->used_ids, $attachment_ids);
    }

    private function collect_option_images() {
        // Theme mods (custom logo, header image, background image)
        $theme_mods = get_theme_mods();
        if (!empty($theme_mods['custom_logo'])) {
            $this->used_ids[] = $theme_mods['custom_logo'];
        }
        if (!empty($theme_mods['header_image_data']->attachment_id)) {
            $this->used_ids[] = $theme_mods['header_image_data']->attachment_id;
        }
        if (!empty($theme_mods['background_image'])) {
            $id = attachment_url_to_postid($theme_mods['background_image']);
            if ($id) $this->used_ids[] = $id;
        }
    }

    private function collect_woocommerce_gallery() {
        if (!class_exists('WooCommerce')) return;
        global $wpdb;

        // Product gallery images
        $galleries = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND meta_value != ''"
        );

        foreach ($galleries as $gallery) {
            $ids = array_filter(array_map('intval', explode(',', $gallery)));
            $this->used_ids = array_merge($this->used_ids, $ids);
        }
    }

    private function collect_woocommerce_variations() {
        if (!class_exists('WooCommerce')) return;
        global $wpdb;

        // Variation images
        $variation_images = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.post_type = 'product_variation'
             AND pm.meta_key = '_thumbnail_id'
             AND pm.meta_value > 0"
        );
        $this->used_ids = array_merge($this->used_ids, $variation_images);
    }

    private function collect_site_identity() {
        $site_logo = get_option('site_logo');
        if ($site_logo) $this->used_ids[] = $site_logo;

        $site_icon = get_option('site_icon');
        if ($site_icon) $this->used_ids[] = $site_icon;
    }

    private function collect_widget_images() {
        // Check widget data for image references
        $widget_types = ['widget_media_image', 'widget_media_gallery', 'widget_media_video', 'widget_media_audio', 'widget_custom_html', 'widget_text'];

        foreach ($widget_types as $type) {
            $widgets = get_option($type);
            if (!is_array($widgets)) continue;

            $serialized = serialize($widgets);
            // Find numeric attachment IDs in widget data
            if (preg_match_all('/"attachment_id";i:(\d+)/', $serialized, $matches)) {
                $this->used_ids = array_merge($this->used_ids, $matches[1]);
            }
            if (preg_match_all('/"ids";s:\d+:"([\d,]+)"/', $serialized, $matches)) {
                foreach ($matches[1] as $ids_str) {
                    $this->used_ids = array_merge($this->used_ids, explode(',', $ids_str));
                }
            }
            $this->merge_upload_paths_from_text($serialized);
        }
    }

    private function collect_elementor_images() {
        global $wpdb;
        // Elementor stores data in postmeta _elementor_data as JSON
        $rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_elementor_data'
             AND meta_value != ''"
        );

        foreach ($rows as $json) {
            // Find "id" fields with numeric values (image widgets)
            if (preg_match_all('/"id"\s*:\s*(\d+)/', $json, $matches)) {
                foreach ($matches[1] as $id) {
                    if ((int)$id > 0 && (int)$id < 999999999) {
                        $this->used_ids[] = $id;
                    }
                }
            }
            $this->merge_upload_paths_from_text($json);
        }

        // Elementor global CSS and data
        $elementor_global = get_option('elementor_custom_icon_sets_config');
        if (is_array($elementor_global)) {
            $this->merge_upload_paths_from_text(serialize($elementor_global));
        }
    }

    /**
     * Scan ALL files in the active theme for hardcoded image references.
     * Checks PHP, CSS, and JS files for the current upload location.
     */
    private function collect_theme_files() {
        $theme_dir = get_stylesheet_directory();
        $parent_theme_dir = get_template_directory();

        $dirs = [$theme_dir];
        if ($parent_theme_dir !== $theme_dir) {
            $dirs[] = $parent_theme_dir;
        }

        foreach ($dirs as $dir) {
            $this->scan_directory_for_uploads($dir);
        }
    }

    /**
     * Recursively scan a directory for files referencing the current upload location.
     */
    private function scan_directory_for_uploads($dir) {
        if (!is_dir($dir)) return;

        $extensions = ['php', 'css', 'js', 'html', 'htm', 'json'];
        $skip_dirs = ['node_modules', '.git', 'vendor', 'dist', 'build'];

        $iterator = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($iterator, function($current, $key, $iterator) use ($skip_dirs) {
            if ($current->isDir()) {
                return !in_array($current->getFilename(), $skip_dirs);
            }
            return true;
        });
        $files = new RecursiveIteratorIterator($filter);

        foreach ($files as $file) {
            if (!$file->isFile()) continue;

            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $extensions)) continue;

            // Skip very large files (> 1MB)
            if ($file->getSize() > 1048576) continue;

            $content = @file_get_contents($file->getPathname());
            if (!$content) continue;

            $this->merge_upload_paths_from_text($content);

            // Find attachment IDs in PHP: wp_get_attachment_image(123), wp_get_attachment_url(456)
            if ($ext === 'php') {
                if (preg_match_all('/wp_get_attachment_(?:image|url|image_url|image_src)\s*\(\s*(\d+)/', $content, $matches)) {
                    $this->used_ids = array_merge($this->used_ids, $matches[1]);
                }
            }
        }
    }

    /**
     * Find images referenced in CSS background-image in post_content and postmeta.
     */
    private function collect_css_background_images() {
        global $wpdb;
        $like = $this->upload_content_like();
        if ($like === null) {
            return;
        }
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_content LIKE %s
             AND post_content LIKE %s
             AND post_status != 'trash'
             AND post_type NOT IN ('revision', 'attachment')",
            '%' . $wpdb->esc_like('background') . '%',
            $like
        ));

        foreach ($rows as $content) {
            $this->merge_upload_paths_from_text($content);
        }
    }

    /**
     * Collect taxonomy term thumbnail IDs (WooCommerce category images, etc.).
     * These are stored as 'thumbnail_id' in wp_termmeta.
     */
    private function collect_taxonomy_thumbnails() {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
             WHERE meta_key = 'thumbnail_id'
             AND meta_value > 0"
        );
        $this->used_ids = array_merge($this->used_ids, $ids);
    }

    /**
     * Collect images referenced in taxonomy term descriptions.
     * Covers product categories, product attributes (pa_finish, pa_color, etc.),
     * post categories, tags, and any custom taxonomy.
     */
    private function collect_term_description_images() {
        global $wpdb;
        $like = $this->upload_content_like();
        $rows = [];
        if ($like !== null) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT description FROM {$wpdb->term_taxonomy}
                 WHERE description LIKE %s",
                $like
            ));
        }

        foreach ($rows as $description) {
            // Match wp-image-123 class
            if (preg_match_all('/wp-image-(\d+)/', $description, $matches)) {
                $this->used_ids = array_merge($this->used_ids, $matches[1]);
            }
            $this->merge_upload_paths_from_text($description);
        }

        // Also check termmeta for image IDs/URLs (some plugins store images in term meta)
        $meta_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
             WHERE meta_value REGEXP '^[0-9]+$'
             AND meta_value > 0
             AND meta_key NOT IN ('thumbnail_id', 'order', 'product_count_product_cat')
             AND CAST(meta_value AS UNSIGNED) IN (
                SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'
             )"
        );
        $this->used_ids = array_merge($this->used_ids, $meta_ids);

        if ($like !== null) {
            $meta_urls = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
                 WHERE meta_value LIKE %s",
                $like
            ));
            foreach ($meta_urls as $val) {
                $this->merge_upload_paths_from_text($val);
            }
        }
    }

    /**
     * Attachment IDs that are not in $used but share an upload path with one that is.
     *
     * Paths are the attached file and generated sizes (including original_image).
     * Null means the lookup failed; the caller must not mark anything unused.
     *
     * @param array<int, mixed> $used Used attachment IDs.
     * @return array<int, int>|null Map of attachment ID => 1, or null on database error.
     */
    public function ids_sharing_files_with_used(array $used) {
        $normalized = [];
        foreach ($used as $id => $_unused) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[$id] = 1;
            }
        }
        if ($normalized === []) {
            return [];
        }
        $index = $this->upload_path_index();
        if ($index === null) {
            return null;
        }
        $added = [];
        $guard = 0;
        do {
            $changed = false;
            $guard++;
            foreach ($index as $ids) {
                if (count($ids) < 2) {
                    continue;
                }
                $hit = false;
                foreach ($ids as $id) {
                    if (isset($normalized[$id]) || isset($added[$id])) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                foreach ($ids as $id) {
                    if (!isset($normalized[$id]) && !isset($added[$id])) {
                        $added[$id] = 1;
                        $changed = true;
                    }
                }
            }
        } while ($changed && $guard < 10);
        return $added;
    }

    /**
     * Relative upload paths of this attachment that another attachment also references.
     *
     * Null means the lookup failed. Callers must not unlink files in that case.
     *
     * @param int $attachment_id Attachment ID.
     * @return string[]|null
     */
    public function shared_relative_upload_paths($attachment_id) {
        $attachment_id = (int) $attachment_id;
        $index = $this->upload_path_index();
        if ($index === null) {
            return null;
        }
        $shared = [];
        foreach ($index as $path => $ids) {
            if (count($ids) > 1 && in_array($attachment_id, $ids, true)) {
                $shared[] = $path;
            }
        }
        return $shared;
    }

    /**
     * Whether an absolute filesystem path is one of the relative upload paths.
     *
     * @param string   $absolute  Absolute path passed to wp_delete_file.
     * @param string[] $relatives Paths relative to the uploads directory.
     * @return bool
     */
    public static function absolute_path_matches_relative($absolute, array $relatives) {
        $absolute = str_replace('\\', '/', (string) $absolute);
        foreach ($relatives as $relative) {
            $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
            if ($relative === '') {
                continue;
            }
            $suffix = '/' . $relative;
            if (substr($absolute, -strlen($suffix)) === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * path => attachment IDs that reference it (original file or a size).
     *
     * @return array<string, int[]>|null
     */
    private function upload_path_index() {
        global $wpdb;
        $wpdb->last_error = '';
        $files = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            '_wp_attached_file'
        ));
        if (!empty($wpdb->last_error) || !is_array($files)) {
            return null;
        }
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            '_wp_attachment_metadata'
        ));
        if (!empty($wpdb->last_error) || !is_array($meta_rows)) {
            return null;
        }

        $attached = [];
        foreach ($files as $row) {
            $attached[(int) $row->post_id] = (string) $row->meta_value;
        }
        $metadata = [];
        foreach ($meta_rows as $row) {
            $metadata[(int) $row->post_id] = $row->meta_value;
        }
        $index = [];
        $ids = array_unique(array_merge(array_keys($attached), array_keys($metadata)));
        foreach ($ids as $id) {
            $paths = $this->relative_paths_for_attachment_row(
                isset($attached[$id]) ? $attached[$id] : '',
                isset($metadata[$id]) ? $metadata[$id] : ''
            );
            foreach ($paths as $path) {
                $index[$path][] = (int) $id;
            }
        }
        foreach ($index as $path => $list) {
            $index[$path] = array_values(array_unique($list));
        }
        return $index;
    }

    /**
     * @param string $attached_file _wp_attached_file value.
     * @param mixed  $meta_value    Raw _wp_attachment_metadata value.
     * @return string[]
     */
    private function relative_paths_for_attachment_row($attached_file, $meta_value) {
        $paths = [];
        $attached_file = $this->normalize_relative_upload_path($attached_file);
        if ($attached_file !== '') {
            $paths[] = $attached_file;
        }
        $meta = $this->decode_attachment_meta($meta_value);
        $base = $attached_file;
        if (is_array($meta) && !empty($meta['file']) && is_string($meta['file'])) {
            $file = $this->normalize_relative_upload_path($meta['file']);
            if ($file !== '') {
                $paths[] = $file;
                $base = $file;
            }
        }
        $dir = ($base !== '' && strpos($base, '/') !== false) ? dirname($base) : '';
        if ($dir === '.') {
            $dir = '';
        }
        if (is_array($meta) && !empty($meta['original_image']) && is_string($meta['original_image'])) {
            $paths[] = $this->join_upload_dir($dir, $meta['original_image']);
        }
        if (is_array($meta) && !empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (is_array($size) && !empty($size['file']) && is_string($size['file'])) {
                    $paths[] = $this->join_upload_dir($dir, $size['file']);
                }
            }
        }
        $out = [];
        foreach ($paths as $path) {
            $path = $this->normalize_relative_upload_path($path);
            if ($path !== '') {
                $out[] = $path;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param mixed $value Meta value.
     * @return array|null
     */
    private function decode_attachment_meta($value) {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $data = function_exists('maybe_unserialize') ? maybe_unserialize($value) : @unserialize($value);
        return is_array($data) ? $data : null;
    }

    /**
     * @param string $dir  Directory relative to uploads.
     * @param string $file File name or relative path.
     * @return string
     */
    private function join_upload_dir($dir, $file) {
        $file = $this->normalize_relative_upload_path($file);
        if ($file === '') {
            return '';
        }
        if ($dir === '' || strpos($file, '/') !== false) {
            return $file;
        }
        return $dir . '/' . $file;
    }

    /**
     * @param string $path Upload path.
     * @return string
     */
    private function normalize_relative_upload_path($path) {
        if (!is_string($path)) {
            return '';
        }
        $path = str_replace('\\', '/', trim($path));
        return ltrim($path, '/');
    }

    // ─── Utility ──────────────────────────────────────────────────────

    /**
     * Find attachment ID from a partial upload path (e.g. "2025/06/photo.jpg").
     * Caches lookups for performance.
     */
    private function find_attachment_by_path($path) {
        global $wpdb;
        // Clean path
        $path = trim($path, '"\'\\/ ');
        // Remove thumbnail size suffix for lookup (e.g. photo-300x200.jpg -> photo.jpg)
        $clean = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $path);

        if (isset($this->path_cache[$clean])) {
            return $this->path_cache[$clean];
        }

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
             AND meta_value = %s
             LIMIT 1",
            $clean
        ));

        // If not found, try with the original (might be a thumbnail filename)
        if (!$id && $clean !== $path) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wp_attached_file'
                 AND meta_value = %s
                 LIMIT 1",
                $path
            ));
        }

        $this->path_cache[$clean] = $id ? (int) $id : 0;
        return $this->path_cache[$clean];
    }

    /**
     * LIKE pattern for the current upload location, or null when it is unknown.
     *
     * @return string|null
     */
    private function upload_content_like() {
        global $wpdb;
        $fragment = OLIMC_Media_Usage_Parser::upload_search_fragment();
        if ($fragment === '') {
            return null;
        }
        return '%' . $wpdb->esc_like($fragment) . '%';
    }

    /**
     * @param string $text Text that may cite an upload URL or path.
     */
    private function merge_upload_paths_from_text($text) {
        foreach (OLIMC_Media_Usage_Parser::relative_upload_paths($text) as $path) {
            $id = $this->find_attachment_by_path($path);
            if ($id) {
                $this->used_ids[] = $id;
            }
        }
    }
}
