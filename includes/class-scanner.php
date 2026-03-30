<?php
defined('ABSPATH') || exit;

class OLIMC_Scanner {

    private $used_ids = [];
    private $path_cache = [];

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

        $this->collect_featured_images();
        $this->collect_post_content_images();
        $this->collect_postmeta_images();
        $this->collect_option_images();
        $this->collect_woocommerce_gallery();
        $this->collect_woocommerce_variations();
        $this->collect_site_identity();
        $this->collect_widget_images();
        $this->collect_elementor_images();
        $this->collect_acf_all_fields();
        $this->collect_acf_option_pages();
        $this->collect_taxonomy_thumbnails();
        $this->collect_term_description_images();
        $this->collect_theme_files();
        $this->collect_css_background_images();
        $this->collect_serialized_postmeta();

        $this->used_ids = array_unique(array_map('intval', array_filter($this->used_ids)));
        return $this->used_ids;
    }

    /**
     * Check if a specific attachment ID is used.
     */
    public function is_used($attachment_id) {
        return in_array((int) $attachment_id, $this->used_ids, true);
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
        // Get all post content that references uploads
        $rows = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_content LIKE '%wp-content/uploads%'
             AND post_status != 'trash'
             AND post_type NOT IN ('revision', 'attachment')"
        );

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
            // Fallback: find filenames in uploads and resolve to IDs
            if (preg_match_all('#wp-content/uploads/([^\s"\'<>]+)#', $content, $matches)) {
                foreach ($matches[1] as $path) {
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $attachment_ids[] = $id;
                }
            }
        }

        $this->used_ids = array_merge($this->used_ids, $attachment_ids);
    }

    private function collect_postmeta_images() {
        global $wpdb;
        // Find attachment IDs stored in postmeta (numeric values that are valid attachments)
        // This catches ACF image fields stored as IDs, WooCommerce product images, etc.
        $rows = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_value REGEXP '^[0-9]+$'
             AND meta_value > 0
             AND meta_key NOT IN ('_edit_lock', '_edit_last', '_wp_old_date', '_price', '_regular_price', '_sale_price', '_stock', '_weight', '_length', '_width', '_height', 'total_sales', '_product_version', '_wp_page_template')
             AND CAST(meta_value AS UNSIGNED) IN (
                SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'
             )"
        );
        $this->used_ids = array_merge($this->used_ids, $rows);

        // Find URLs in postmeta
        $url_rows = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_value LIKE '%wp-content/uploads%'
             AND meta_key NOT LIKE '_transient%'"
        );

        foreach ($url_rows as $val) {
            if (preg_match_all('#wp-content/uploads/([^\s"\'<>,;}\]]+)#', $val, $matches)) {
                foreach ($matches[1] as $path) {
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $this->used_ids[] = $id;
                }
            }
        }
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
            // URLs in widget content
            if (preg_match_all('#wp-content/uploads/([^\s"\'<>,;}\\\]+)#', $serialized, $matches)) {
                foreach ($matches[1] as $path) {
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $this->used_ids[] = $id;
                }
            }
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
            // Find image URLs
            if (preg_match_all('#wp-content/uploads/([^\s"\'<>,;}\\\]+)#', $json, $matches)) {
                foreach ($matches[1] as $path) {
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $this->used_ids[] = $id;
                }
            }
        }

        // Elementor global CSS and data
        $elementor_global = get_option('elementor_custom_icon_sets_config');
        if (is_array($elementor_global)) {
            $serialized = serialize($elementor_global);
            if (preg_match_all('#wp-content/uploads/([^\s"\'<>,;}\\\]+)#', $serialized, $matches)) {
                foreach ($matches[1] as $path) {
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $this->used_ids[] = $id;
                }
            }
        }
    }

    /**
     * Deep ACF scan — covers ALL field types:
     * image, file, gallery, repeater, flexible_content, group, clone, post_object with images.
     */
    private function collect_acf_all_fields() {
        if (!function_exists('acf_get_field_groups')) return;
        global $wpdb;

        // 1. Get all ACF field groups and their fields (recursive for sub-fields)
        $image_keys = [];
        $file_keys = [];
        $gallery_keys = [];
        $all_acf_keys = [];

        $field_groups = acf_get_field_groups();
        foreach ($field_groups as $group) {
            $fields = acf_get_fields($group['key']);
            if ($fields) {
                $this->collect_acf_field_keys_recursive($fields, $image_keys, $file_keys, $gallery_keys, $all_acf_keys);
            }
        }

        // 2. Image and File fields (stored as attachment ID in postmeta)
        // Already partially caught by collect_postmeta_images, but let's be explicit
        $image_file_keys = array_merge($image_keys, $file_keys);
        if (!empty($image_file_keys)) {
            $placeholders = implode(',', array_fill(0, count($image_file_keys), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key IN ($placeholders)
                 AND meta_value REGEXP '^[0-9]+$'
                 AND meta_value > 0",
                ...$image_file_keys
            ) );
            $this->used_ids = array_merge($this->used_ids, $ids);
        }

        // 3. Gallery fields (stored as serialized array of attachment IDs)
        if (!empty($gallery_keys)) {
            $placeholders = implode(',', array_fill(0, count($gallery_keys), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key IN ($placeholders)
                 AND meta_value != ''",
                ...$gallery_keys
            ) );
            foreach ($rows as $val) {
                $data = @unserialize($val);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if (is_numeric($item)) {
                            $this->used_ids[] = (int) $item;
                        } elseif (is_array($item) && isset($item['id'])) {
                            $this->used_ids[] = (int) $item['id'];
                        } elseif (is_array($item) && isset($item['ID'])) {
                            $this->used_ids[] = (int) $item['ID'];
                        }
                    }
                }
            }
        }

        // 4. Repeater / Flexible Content sub-fields
        // ACF stores repeater sub-fields as: {parent_field}_{row_index}_{sub_field}
        // We look for any meta_key that matches known image/file/gallery sub-field names
        $this->collect_acf_repeater_sub_fields($image_keys, $file_keys, $gallery_keys);

        // 5. Serialized ACF arrays (image/file return format = array)
        $rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_value LIKE 'a:%'
             AND (meta_value LIKE '%\"id\"%' OR meta_value LIKE '%\"ID\"%')
             AND meta_value LIKE '%upload%'"
        );
        foreach ($rows as $val) {
            $this->extract_ids_from_serialized($val);
        }

        // 6. ACF URL-based return format (image field returns URL string)
        if (!empty($image_file_keys)) {
            $placeholders = implode(',', array_fill(0, count($image_file_keys), '%s'));
            $like_uploads = '%' . $wpdb->esc_like('wp-content/uploads') . '%';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $urls = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key IN ($placeholders)
                 AND meta_value LIKE %s",
                ...array_merge($image_file_keys, [$like_uploads])
            ) );
            foreach ($urls as $url) {
                if (preg_match('#wp-content/uploads/([^\s"\'\'<>,;}\]]+)#', $url, $m)) {
                    $id = $this->find_attachment_by_path($m[1]);
                    if ($id) $this->used_ids[] = $id;
                }
            }
        }
    }

    /**
     * Recursively collect ACF field keys by type.
     */
    private function collect_acf_field_keys_recursive($fields, &$image_keys, &$file_keys, &$gallery_keys, &$all_keys) {
        foreach ($fields as $field) {
            $all_keys[] = $field['name'];

            switch ($field['type']) {
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

            // Recurse into sub_fields (repeater, group, clone, flexible_content layouts)
            if (!empty($field['sub_fields'])) {
                $this->collect_acf_field_keys_recursive($field['sub_fields'], $image_keys, $file_keys, $gallery_keys, $all_keys);
            }
            if (!empty($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (!empty($layout['sub_fields'])) {
                        $this->collect_acf_field_keys_recursive($layout['sub_fields'], $image_keys, $file_keys, $gallery_keys, $all_keys);
                    }
                }
            }
        }
    }

    /**
     * Scan repeater/flex sub-fields stored as {parent}_{index}_{child} in postmeta.
     */
    private function collect_acf_repeater_sub_fields($image_keys, $file_keys, $gallery_keys) {
        $sub_field_names = array_merge($image_keys, $file_keys, $gallery_keys);
        if (empty($sub_field_names)) return;
        global $wpdb;

        // Build LIKE conditions for each sub-field name
        $like_conditions = [];
        foreach ($sub_field_names as $name) {
            $escaped = $wpdb->esc_like($name);
            $like_conditions[] = $wpdb->prepare("meta_key LIKE %s", '%_' . $escaped);
        }

        $where = implode(' OR ', $like_conditions);
        $rows = $wpdb->get_results(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE ($where)
             AND meta_value != ''"
        );

        foreach ($rows as $row) {
            $val = $row->meta_value;
            $key = $row->meta_key;

            // Determine if this sub-field is image/file (numeric ID) or gallery (serialized array)
            $is_gallery = false;
            foreach ($gallery_keys as $gk) {
                if (preg_match('/_\d+_' . preg_quote($gk, '/') . '$/', $key) || $key === $gk) {
                    $is_gallery = true;
                    break;
                }
            }

            if ($is_gallery) {
                $data = @unserialize($val);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if (is_numeric($item)) {
                            $this->used_ids[] = (int) $item;
                        } elseif (is_array($item) && isset($item['id'])) {
                            $this->used_ids[] = (int) $item['id'];
                        }
                    }
                }
            } elseif (is_numeric($val) && (int)$val > 0) {
                $this->used_ids[] = (int) $val;
            } elseif (strpos($val, 'wp-content/uploads') !== false) {
                if (preg_match('#wp-content/uploads/([^\s"\'\'<>,;}\]]+)#', $val, $m)) {
                    $id = $this->find_attachment_by_path($m[1]);
                    if ($id) $this->used_ids[] = $id;
                }
            }
        }
    }

    /**
     * Extract attachment IDs from serialized data recursively.
     */
    private function extract_ids_from_serialized($val) {
        $data = @unserialize($val);
        if (!is_array($data)) return;

        foreach ($data as $key => $item) {
            if (is_array($item)) {
                if (isset($item['id']) && is_numeric($item['id'])) {
                    $this->used_ids[] = (int) $item['id'];
                }
                if (isset($item['ID']) && is_numeric($item['ID'])) {
                    $this->used_ids[] = (int) $item['ID'];
                }
                if (isset($item['url']) && strpos($item['url'], 'wp-content/uploads') !== false) {
                    if (preg_match('#wp-content/uploads/([^\s"\'\'<>,;}\]]+)#', $item['url'], $m)) {
                        $fid = $this->find_attachment_by_path($m[1]);
                        if ($fid) $this->used_ids[] = $fid;
                    }
                }
                // Recurse
                $this->extract_ids_from_serialized(serialize($item));
            } elseif (is_numeric($item) && (int)$item > 0) {
                // Could be gallery array of IDs
            }
        }
    }

    /**
     * Scan ACF Options pages — data stored in wp_options with 'options_' prefix.
     */
    private function collect_acf_option_pages() {
        if (!function_exists('acf_get_field_groups')) return;
        global $wpdb;

        // ACF options are stored in wp_options as options_{field_name}
        $like_options = $wpdb->esc_like('options_') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value != ''",
            $like_options
        ) );

        foreach ($rows as $row) {
            $val = $row->option_value;

            // Numeric ID (image/file field)
            if (is_numeric($val) && (int)$val > 0) {
                // Verify it's an attachment
                $is_attachment = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment'",
                    (int) $val
                ));
                if ($is_attachment) {
                    $this->used_ids[] = (int) $val;
                }
                continue;
            }

            // Serialized array (gallery or image array format)
            if (strpos($val, 'a:') === 0) {
                $this->extract_ids_from_serialized($val);
                continue;
            }

            // URL
            if (strpos($val, 'wp-content/uploads') !== false) {
                if (preg_match('#wp-content/uploads/([^\s"\'\'<>,;}\]]+)#', $val, $m)) {
                    $id = $this->find_attachment_by_path($m[1]);
                    if ($id) $this->used_ids[] = $id;
                }
            }
        }
    }

    /**
     * Scan ALL files in the active theme for hardcoded image references.
     * Checks PHP, CSS, and JS files for wp-content/uploads paths.
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
     * Recursively scan a directory for files referencing wp-content/uploads.
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

            // Find upload paths
            if (preg_match_all('#wp-content/uploads/([^\s"\'\'<>\)\},;]+)#', $content, $matches)) {
                foreach ($matches[1] as $path) {
                    $path = rtrim($path, '\\');
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $this->used_ids[] = $id;
                }
            }

            // Find get_site_url() . '/wp-content/uploads/...' patterns
            if (preg_match_all('#/wp-content/uploads/([^\s"\'\'<>\)\},;]+)#', $content, $matches)) {
                foreach ($matches[1] as $path) {
                    $path = rtrim($path, '\\');
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $this->used_ids[] = $id;
                }
            }

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
        // Post content with inline styles
        $rows = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_content LIKE '%background%upload%'
             AND post_status != 'trash'
             AND post_type NOT IN ('revision', 'attachment')"
        );

        foreach ($rows as $content) {
            if (preg_match_all('#background(?:-image)?\s*:\s*url\s*\(\s*[\'\'"]*([^\)\'\"]+wp-content/uploads/[^\)\'\"]+)#i', $content, $matches)) {
                foreach ($matches[1] as $url) {
                    if (preg_match('#wp-content/uploads/([^\s"\'\'<>\)\},;]+)#', $url, $m)) {
                        $id = $this->find_attachment_by_path($m[1]);
                        if ($id) $this->used_ids[] = $id;
                    }
                }
            }
        }
    }

    /**
     * Deep scan serialized arrays in postmeta for attachment references.
     * Catches complex plugin data structures.
     */
    private function collect_serialized_postmeta() {
        global $wpdb;
        // Find serialized arrays that contain upload references
        $rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_value LIKE 'a:%'
             AND meta_value LIKE '%wp-content/uploads%'
             AND meta_key NOT LIKE '_transient%'
             LIMIT 5000"
        );

        foreach ($rows as $val) {
            // Extract all upload paths from the serialized string
            if (preg_match_all('#wp-content/uploads/([^\s"\'\'<>,;}\\\]]+)#', $val, $matches)) {
                foreach ($matches[1] as $path) {
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $this->used_ids[] = $id;
                }
            }
            // Also extract numeric IDs from serialized data
            $this->extract_ids_from_serialized($val);
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
        $rows = $wpdb->get_col(
            "SELECT description FROM {$wpdb->term_taxonomy}
             WHERE description LIKE '%wp-content/uploads%'"
        );

        foreach ($rows as $description) {
            // Match wp-image-123 class
            if (preg_match_all('/wp-image-(\d+)/', $description, $matches)) {
                $this->used_ids = array_merge($this->used_ids, $matches[1]);
            }
            // Match image URLs in uploads
            if (preg_match_all('#wp-content/uploads/([^\s"\'<>\)\},;]+)#', $description, $matches)) {
                foreach ($matches[1] as $path) {
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $this->used_ids[] = $id;
                }
            }
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

        // URLs stored in termmeta
        $meta_urls = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
             WHERE meta_value LIKE '%wp-content/uploads%'"
        );
        foreach ($meta_urls as $val) {
            if (preg_match_all('#wp-content/uploads/([^\s"\'<>\)\},;]+)#', $val, $matches)) {
                foreach ($matches[1] as $path) {
                    $id = $this->find_attachment_by_path($path);
                    if ($id) $this->used_ids[] = $id;
                }
            }
        }
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
}
