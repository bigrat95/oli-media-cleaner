<?php
defined('ABSPATH') || exit;

class OLIMC_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_olimc_start_scan', [__CLASS__, 'ajax_start_scan']);
        add_action('wp_ajax_olimc_scan_batch', [__CLASS__, 'ajax_scan_batch']);
        add_action('wp_ajax_olimc_get_results', [__CLASS__, 'ajax_get_results']);
        add_action('wp_ajax_olimc_trash_single', [__CLASS__, 'ajax_trash_single']);
        add_action('wp_ajax_olimc_trash_bulk', [__CLASS__, 'ajax_trash_bulk']);
        add_action('wp_ajax_olimc_trash_all_batch', [__CLASS__, 'ajax_trash_all_batch']);
        add_action('wp_ajax_olimc_delete_single', [__CLASS__, 'ajax_delete_single']);
        add_action('wp_ajax_olimc_delete_bulk', [__CLASS__, 'ajax_delete_bulk']);
        add_action('wp_ajax_olimc_whitelist_single', [__CLASS__, 'ajax_whitelist_single']);
        add_action('wp_ajax_olimc_whitelist_bulk', [__CLASS__, 'ajax_whitelist_bulk']);
        add_action('wp_ajax_olimc_remove_whitelist', [__CLASS__, 'ajax_remove_whitelist']);
        add_action('wp_ajax_olimc_remove_whitelist_bulk', [__CLASS__, 'ajax_remove_whitelist_bulk']);
        add_action('wp_ajax_olimc_restore_single', [__CLASS__, 'ajax_restore_single']);
        add_action('wp_ajax_olimc_restore_bulk', [__CLASS__, 'ajax_restore_bulk']);
        add_action('wp_ajax_olimc_save_cron_settings', [__CLASS__, 'ajax_save_cron_settings']);
        add_action('wp_ajax_olimc_empty_trash_batch', [__CLASS__, 'ajax_empty_trash_batch']);

        // Cron hook
        add_action('olimc_scheduled_cleanup', [__CLASS__, 'run_scheduled_cleanup']);
    }

    public static function activate() {
        update_option('olimc_version', OLIMC_VERSION);
        // Initialize whitelist as empty
        if (false === get_option('olimc_whitelist')) {
            update_option('olimc_whitelist', [], false);
        }
    }

    public static function deactivate() {
        delete_option('olimc_scan_results');
        delete_option('olimc_scan_used_ids');
        delete_option('olimc_scan_date');
        wp_clear_scheduled_hook('olimc_scheduled_cleanup');
    }

    public static function add_menu() {
        add_submenu_page(
            'upload.php',
            __('Oli Media Cleaner', 'oli-media-cleaner'),
            __('Media Cleaner', 'oli-media-cleaner'),
            'manage_options',
            'oli-media-cleaner',
            [__CLASS__, 'render_page']
        );
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'media_page_oli-media-cleaner') return;

        wp_enqueue_style(
            'olimc-admin-css',
            OLIMC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OLIMC_VERSION
        );

        wp_enqueue_script(
            'olimc-admin-js',
            OLIMC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            OLIMC_VERSION,
            true
        );

        wp_localize_script('olimc-admin-js', 'olimcObj', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('olimc_nonce'),
            'strings' => [
                'scanning'       => __('Scanning...', 'oli-media-cleaner'),
                'scan_complete'  => __('Scan complete!', 'oli-media-cleaner'),
                'confirm_trash'  => __('Trash this file?', 'oli-media-cleaner'),
                'confirm_delete' => __('Permanently delete this file? This cannot be undone.', 'oli-media-cleaner'),
                'confirm_bulk_trash'  => __('Trash all selected files?', 'oli-media-cleaner'),
                'confirm_bulk_delete' => __('Permanently delete all selected files? This cannot be undone.', 'oli-media-cleaner'),
                'no_selection'        => __('No files selected.', 'oli-media-cleaner'),
                'confirm_trash_all'   => __('Trash ALL unused images? This will process all pages in batches.', 'oli-media-cleaner'),
                'confirm_empty_trash' => __('Permanently delete ALL trashed files? This cannot be undone.', 'oli-media-cleaner'),
            ],
        ]);
    }

    // ─── Page render ──────────────────────────────────────────────────

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        $scan_date = get_option('olimc_scan_date', '');
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'unused';
        $whitelist = get_option('olimc_whitelist', []);
        $base_url = admin_url('upload.php?page=oli-media-cleaner');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Oli Media Cleaner', 'oli-media-cleaner'); ?></h1>

            <div class="postbox" style="margin-top:20px;">
                <div class="inside" id="olimc-stats">
                    <?php self::render_stats(); ?>
                </div>
            </div>

            <p>
                <button type="button" id="olimc-scan-btn" class="button button-primary">
                    <?php esc_html_e('Scan for Unused Media', 'oli-media-cleaner'); ?>
                </button>
                <?php if ($scan_date): ?>
                    <span class="description" style="margin-left:10px;">
                        <?php
                        /* translators: %s: date and time of last scan */
                        printf( esc_html__( 'Last scan: %s', 'oli-media-cleaner' ), esc_html( date_i18n( 'M j, Y g:i a', strtotime( $scan_date ) ) ) );
                        ?>
                    </span>
                <?php endif; ?>
            </p>

            <div id="olimc-progress-wrap" style="display:none;margin-bottom:15px;">
                <div style="background:#e0e0e0;height:20px;border-radius:3px;overflow:hidden;max-width:500px;">
                    <div id="olimc-progress-fill" style="background:#2271b1;height:100%;width:0%;transition:width .3s;"></div>
                </div>
                <p class="description" id="olimc-progress-text">0%</p>
            </div>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url($base_url . '&tab=unused'); ?>"
                   class="nav-tab <?php echo $tab === 'unused' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Unused', 'oli-media-cleaner'); ?>
                    <span class="count" id="olimc-unused-count">(0)</span>
                </a>
                <a href="<?php echo esc_url($base_url . '&tab=whitelist'); ?>"
                   class="nav-tab <?php echo $tab === 'whitelist' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Whitelist', 'oli-media-cleaner'); ?>
                    <span class="count" id="olimc-whitelist-count">(<?php echo count($whitelist); ?>)</span>
                </a>
                <a href="<?php echo esc_url($base_url . '&tab=trash'); ?>"
                   class="nav-tab <?php echo $tab === 'trash' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Trash', 'oli-media-cleaner'); ?>
                    <span class="count" id="olimc-trash-count">(<?php echo esc_html( wp_count_posts('attachment')->trash ); ?>)</span>
                </a>
            </nav>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <label><input type="checkbox" id="olimc-select-all"> <?php esc_html_e('Select All', 'oli-media-cleaner'); ?></label>
                    <?php if ($tab === 'unused'): ?>
                        <button type="button" class="button" id="olimc-bulk-trash-btn"><?php esc_html_e('Trash Selected', 'oli-media-cleaner'); ?></button>
                        <button type="button" class="button" id="olimc-bulk-whitelist-btn"><?php esc_html_e('Whitelist Selected', 'oli-media-cleaner'); ?></button>
                        <button type="button" class="button" id="olimc-trash-all-btn" style="color:#b32d2e;"><?php esc_html_e('Trash All Unused', 'oli-media-cleaner'); ?></button>
                    <?php elseif ($tab === 'whitelist'): ?>
                        <button type="button" class="button" id="olimc-bulk-remove-whitelist-btn"><?php esc_html_e('Remove from Whitelist', 'oli-media-cleaner'); ?></button>
                    <?php elseif ($tab === 'trash'): ?>
                        <button type="button" class="button" id="olimc-bulk-restore-btn"><?php esc_html_e('Restore Selected', 'oli-media-cleaner'); ?></button>
                        <button type="button" class="button" id="olimc-bulk-delete-btn"><?php esc_html_e('Delete Permanently', 'oli-media-cleaner'); ?></button>
                        <button type="button" class="button" id="olimc-empty-trash-btn" style="color:#b32d2e;"><?php esc_html_e('Empty Trash', 'oli-media-cleaner'); ?></button>
                    <?php endif; ?>
                    <span id="olimc-selected-info" class="description" style="margin-left:8px;"></span>
                </div>
                <div class="alignright">
                    <?php
                    $scan_results = get_option('olimc_scan_results', []);
                    $found_exts = [];
                    foreach ($scan_results as $item) {
                        $ext = strtolower($item['ext'] ?? '');
                        if ($ext && !isset($found_exts[$ext])) $found_exts[$ext] = strtoupper($ext);
                    }
                    ksort($found_exts);

                    $groups = [
                        __('Images', 'oli-media-cleaner')    => ['jpg','jpeg','png','gif','webp','svg','ico','bmp','tiff','heic'],
                        __('Documents', 'oli-media-cleaner') => ['pdf','doc','docx','xls','xlsx','csv','ppt','pptx','txt','zip','rar'],
                        __('Video', 'oli-media-cleaner')     => ['mp4','mov','avi','webm','wmv','mkv'],
                        __('Audio', 'oli-media-cleaner')     => ['mp3','wav','ogg','flac','aac'],
                    ];
                    ?>
                    <select id="olimc-filter-type" style="vertical-align:middle;">
                        <option value=""><?php esc_html_e('All Types', 'oli-media-cleaner'); ?></option>
                        <?php foreach ($groups as $label => $exts):
                            $group_items = array_intersect_key($found_exts, array_flip($exts));
                            if (empty($group_items)) continue;
                        ?>
                        <optgroup label="<?php echo esc_attr($label); ?>">
                            <?php foreach ($group_items as $ext => $display): ?>
                            <option value="<?php echo esc_attr($ext); ?>"><?php echo esc_html($display); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach;
                        // Any extensions not in known groups
                        $known = array_merge(...array_values($groups));
                        $other = array_diff_key($found_exts, array_flip($known));
                        if (!empty($other)): ?>
                        <optgroup label="<?php esc_attr_e('Other', 'oli-media-cleaner'); ?>">
                            <?php foreach ($other as $ext => $display): ?>
                            <option value="<?php echo esc_attr($ext); ?>"><?php echo esc_html($display); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                    <input type="search" id="olimc-search" placeholder="<?php esc_attr_e('Search files...', 'oli-media-cleaner'); ?>" style="vertical-align:middle;">
                    <button type="button" id="olimc-search-btn" class="button"><?php esc_html_e('Search', 'oli-media-cleaner'); ?></button>
                </div>
            </div>

            <div id="olimc-results">
                <?php self::render_results_table($tab); ?>
            </div>

            <div class="tablenav bottom">
                <div class="alignleft actions">
                    <label><?php esc_html_e('Show', 'oli-media-cleaner'); ?>
                        <select id="olimc-per-page" style="vertical-align:middle;">
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <?php esc_html_e('per page', 'oli-media-cleaner'); ?>
                    </label>
                </div>
                <div class="alignright" id="olimc-pagination"></div>
                <br class="clear">
            </div>

            <div class="postbox" style="margin-top:30px;">
                <div class="postbox-header"><h2 style="padding:8px 12px;margin:0;"><?php esc_html_e('Scheduled Auto-Cleanup', 'oli-media-cleaner'); ?></h2></div>
                <div class="inside">
                    <?php
                    $cron_enabled = get_option('olimc_cron_enabled', false);
                    $cron_frequency = get_option('olimc_cron_frequency', 'daily');
                    $next_run = wp_next_scheduled('olimc_scheduled_cleanup');
                    ?>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Enable Auto-Cleanup', 'oli-media-cleaner'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="olimc-cron-enabled" <?php checked($cron_enabled); ?>>
                                    <?php esc_html_e('Automatically scan and trash unused images on a schedule', 'oli-media-cleaner'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Frequency', 'oli-media-cleaner'); ?></th>
                            <td>
                                <select id="olimc-cron-frequency">
                                    <option value="daily" <?php selected($cron_frequency, 'daily'); ?>><?php esc_html_e('Daily', 'oli-media-cleaner'); ?></option>
                                    <option value="twicedaily" <?php selected($cron_frequency, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'oli-media-cleaner'); ?></option>
                                    <option value="weekly" <?php selected($cron_frequency, 'weekly'); ?>><?php esc_html_e('Weekly', 'oli-media-cleaner'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Next Scheduled Run', 'oli-media-cleaner'); ?></th>
                            <td>
                                <span id="olimc-next-run">
                                    <?php echo $next_run ? esc_html( date_i18n('M j, Y g:i a', $next_run) ) : esc_html__('Not scheduled', 'oli-media-cleaner'); ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="olimc-save-cron-btn" class="button button-primary"><?php esc_html_e('Save Settings', 'oli-media-cleaner'); ?></button>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_stats() {
        $scan_results = get_option('olimc_scan_results', []);
        $total = (new OLIMC_Scanner())->get_total_attachment_count();
        $unused_count = count($scan_results);
        $used_count = $total - $unused_count;
        $whitelist = get_option('olimc_whitelist', []);

        $unused_size = 0;
        if (!empty($scan_results)) {
            foreach ($scan_results as $item) {
                $unused_size += (int)($item['file_size'] ?? 0);
            }
        }
        ?>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Total Media', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( number_format_i18n($total) ); ?></strong></td>
                <th><?php esc_html_e('In Use', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( number_format_i18n($used_count) ); ?></strong></td>
                <th><?php esc_html_e('Unused', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( number_format_i18n($unused_count) ); ?></strong></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Space to Free', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( size_format($unused_size) ); ?></strong></td>
                <th><?php esc_html_e('Whitelisted', 'oli-media-cleaner'); ?></th>
                <td><strong><?php echo esc_html( number_format_i18n(count($whitelist)) ); ?></strong></td>
                <td colspan="2"></td>
            </tr>
        </table>
        <?php
    }

    private static function render_results_table($tab, $page = 1, $per_page = 20, $search = '', $orderby = 'date', $order = 'desc', $filter_type = '') {
        $scanner = new OLIMC_Scanner();
        $all_items = [];
        $total_items = 0;

        if ($tab === 'unused') {
            $scan_results = get_option('olimc_scan_results', []);
            $whitelist = get_option('olimc_whitelist', []);
            $scan_results = array_filter($scan_results, function($item) use ($whitelist) {
                return !in_array((int)$item['id'], $whitelist, true);
            });
            $all_items = array_values($scan_results);

        } elseif ($tab === 'whitelist') {
            $whitelist = get_option('olimc_whitelist', []);
            foreach ($whitelist as $id) {
                $info = $scanner->get_attachment_info($id);
                if ($info) $all_items[] = $info;
            }

        } elseif ($tab === 'trash') {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'trash' ORDER BY ID ASC"
            );
            foreach ($rows as $row) {
                $info = $scanner->get_attachment_info($row->ID);
                if ($info) $all_items[] = $info;
            }
        }

        // Filter by search
        if ($search) {
            $search_lower = strtolower($search);
            $all_items = array_filter($all_items, function($item) use ($search_lower) {
                return strpos(strtolower($item['title'] ?? ''), $search_lower) !== false
                    || strpos(strtolower($item['url'] ?? ''), $search_lower) !== false
                    || strpos(strtolower($item['ext'] ?? ''), $search_lower) !== false
                    || strpos((string)($item['id'] ?? ''), $search_lower) !== false;
            });
        }

        // Filter by file type
        if ($filter_type) {
            $filter_lower = strtolower($filter_type);
            $all_items = array_filter($all_items, function($item) use ($filter_lower) {
                return strtolower($item['ext'] ?? '') === $filter_lower;
            });
        }

        $all_items = array_values($all_items);

        // Sort
        if ($orderby && !empty($all_items)) {
            usort($all_items, function($a, $b) use ($orderby, $order) {
                switch ($orderby) {
                    case 'name':
                        $cmp = strcasecmp($a['title'] ?? '', $b['title'] ?? '');
                        break;
                    case 'size':
                        $cmp = ((int)($a['file_size'] ?? 0)) - ((int)($b['file_size'] ?? 0));
                        break;
                    case 'type':
                        $cmp = strcasecmp($a['ext'] ?? '', $b['ext'] ?? '');
                        break;
                    case 'date':
                    default:
                        $cmp = strcmp($a['date'] ?? '', $b['date'] ?? '');
                        break;
                }
                return $order === 'asc' ? $cmp : -$cmp;
            });
        }

        $total_items = count($all_items);
        $items = array_slice($all_items, ($page - 1) * $per_page, $per_page);
        $total_pages = ceil($total_items / $per_page);

        if (empty($items)) {
            echo '<p class="description">';
            if ($tab === 'unused') {
                esc_html_e('No unused media found. Run a scan to detect unused files.', 'oli-media-cleaner');
            } elseif ($tab === 'whitelist') {
                esc_html_e('No whitelisted items.', 'oli-media-cleaner');
            } else {
                esc_html_e('Trash is empty.', 'oli-media-cleaner');
            }
            echo '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th class="check-column"><input type="checkbox" class="olimc-select-all-header"></th>';
        echo '<th>' . esc_html__('File', 'oli-media-cleaner') . '</th>';
        echo '<th class="olimc-sortable" data-sort="name" style="cursor:pointer;">' . esc_html__('Name', 'oli-media-cleaner') . wp_kses_post( self::sort_indicator('name', $orderby, $order) ) . '</th>';
        echo '<th class="olimc-sortable" data-sort="size" style="cursor:pointer;">' . esc_html__('Size', 'oli-media-cleaner') . wp_kses_post( self::sort_indicator('size', $orderby, $order) ) . '</th>';
        echo '<th class="olimc-sortable" data-sort="type" style="cursor:pointer;">' . esc_html__('Type', 'oli-media-cleaner') . wp_kses_post( self::sort_indicator('type', $orderby, $order) ) . '</th>';
        echo '<th class="olimc-sortable" data-sort="date" style="cursor:pointer;">' . esc_html__('Date', 'oli-media-cleaner') . wp_kses_post( self::sort_indicator('date', $orderby, $order) ) . '</th>';
        echo '<th>' . esc_html__('Actions', 'oli-media-cleaner') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $id = (int) $item['id'];
            $size_formatted = size_format($item['file_size']);
            $date_formatted = date_i18n('Y/m/d', strtotime($item['date']));
            $thumb = self::get_thumb_html($item);
            $edit_url = admin_url("post.php?post={$id}&action=edit");

            echo '<tr data-id="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '">';
            echo '<th class="check-column"><input type="checkbox" class="olimc-item-cb" value="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '"></th>';
            echo '<td>' . wp_kses_post( $thumb ) . '</td>';
            echo '<td><strong>' . esc_html($item['title']) . '</strong><br><span class="description">' . esc_html(wp_basename($item['url'] ?? '')) . '</span></td>';
            echo '<td>' . esc_html($size_formatted) . '</td>';
            echo '<td><code>' . esc_html(strtoupper($item['ext'])) . '</code></td>';
            echo '<td>' . esc_html($date_formatted) . '</td>';
            echo '<td>';

            if ($tab === 'unused') {
                echo '<a href="' . esc_url($item['url']) . '" target="_blank" class="button button-small">' . esc_html__('View', 'oli-media-cleaner') . '</a> ';
                echo '<a href="' . esc_url($edit_url) . '" target="_blank" class="button button-small">' . esc_html__('Edit', 'oli-media-cleaner') . '</a> ';
                echo '<button type="button" class="button button-small olimc-whitelist-btn" data-id="' . esc_attr($id) . '">' . esc_html__('Whitelist', 'oli-media-cleaner') . '</button> ';
                echo '<button type="button" class="button button-small olimc-trash-btn" data-id="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '">' . esc_html__('Trash', 'oli-media-cleaner') . '</button>';
            } elseif ($tab === 'whitelist') {
                echo '<a href="' . esc_url($item['url']) . '" target="_blank" class="button button-small">' . esc_html__('View', 'oli-media-cleaner') . '</a> ';
                echo '<button type="button" class="button button-small olimc-remove-whitelist-btn" data-id="' . esc_attr($id) . '">' . esc_html__('Remove', 'oli-media-cleaner') . '</button>';
            } elseif ($tab === 'trash') {
                echo '<button type="button" class="button button-small olimc-restore-btn" data-id="' . esc_attr($id) . '">' . esc_html__('Restore', 'oli-media-cleaner') . '</button> ';
                echo '<button type="button" class="button button-small olimc-delete-btn" data-id="' . esc_attr($id) . '" data-size="' . esc_attr($item['file_size']) . '" style="color:#b32d2e;">' . esc_html__('Delete', 'oli-media-cleaner') . '</button>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<div id="olimc-pag-data" data-total-pages="' . (int) $total_pages . '" data-current-page="' . (int) $page . '" data-total-items="' . (int) $total_items . '" style="display:none;"></div>';
    }

    private static function sort_indicator($col, $orderby, $order) {
        if ($col !== $orderby) {
            return ' <span style="color:#c3c4c7;">&#x25B5;&#x25BF;</span>';
        }
        $arrow = $order === 'asc' ? '&#x25B4;' : '&#x25BE;';
        return ' <span>' . $arrow . '</span>';
    }

    private static function get_thumb_html($item) {
        $mime = $item['mime'] ?? '';
        if (strpos($mime, 'image') !== false && !empty($item['thumb_url'])) {
            return '<img src="' . esc_url($item['thumb_url']) . '" alt="" width="40" height="40" style="object-fit:cover;">';
        }
        return wp_get_attachment_image($item['id'], [40, 40]) ?: '<span class="dashicons dashicons-media-default" style="font-size:20px;color:#8c8f94;"></span>';
    }

    // ─── AJAX handlers ────────────────────────────────────────────────

    private static function verify_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.');
        }
        if (!check_ajax_referer('olimc_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce.');
        }
    }

    /**
     * Step 1: Start scan — collect used IDs (heavy query, done once).
     */
    public static function ajax_start_scan() {
        self::verify_request();

        $scanner = new OLIMC_Scanner();
        $used_ids = $scanner->collect_used_ids();
        $total = $scanner->get_total_attachment_count();

        // Store used IDs for batch processing
        update_option('olimc_scan_used_ids', $used_ids, false);
        // Clear old results
        delete_option('olimc_scan_results');

        wp_send_json_success([
            'total'    => $total,
            'used'     => count($used_ids),
            /* translators: %1$d: number of used media, %2$d: total attachments */
            'message'  => sprintf(__('Found %1$d used media. Scanning %2$d total attachments...', 'oli-media-cleaner'), count($used_ids), $total),
        ]);
    }

    /**
     * Step 2: Process a batch of attachments — check if unused.
     */
    public static function ajax_scan_batch() {
        self::verify_request();

        $offset = (int) ($_POST['offset'] ?? 0);
        $batch_size = 50;

        $scanner = new OLIMC_Scanner();
        $used_ids = get_option('olimc_scan_used_ids', []);
        $whitelist = get_option('olimc_whitelist', []);

        global $wpdb;
        $attachment_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status != 'trash'
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $batch_size, $offset
        ));

        $unused_batch = [];
        foreach ($attachment_ids as $id) {
            $id = (int) $id;
            if (!in_array($id, $used_ids, true) && !in_array($id, $whitelist, true)) {
                $info = $scanner->get_attachment_info($id);
                if ($info) {
                    $unused_batch[] = $info;
                }
            }
        }

        // Append to stored results
        $existing = get_option('olimc_scan_results', []);
        $existing = array_merge($existing, $unused_batch);
        update_option('olimc_scan_results', $existing, false);

        $processed = $offset + count($attachment_ids);
        $total = $scanner->get_total_attachment_count();
        $done = count($attachment_ids) < $batch_size;

        if ($done) {
            update_option('olimc_scan_date', current_time('mysql'), false);
            // Cleanup temp
            delete_option('olimc_scan_used_ids');
        }

        wp_send_json_success([
            'processed'    => $processed,
            'total'        => $total,
            'unused_found' => count($existing),
            'done'         => $done,
        ]);
    }

    /**
     * Get paginated results (refreshes table via AJAX).
     */
    public static function ajax_get_results() {
        self::verify_request();

        $tab = sanitize_text_field($_POST['tab'] ?? 'unused');
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $search = sanitize_text_field($_POST['search'] ?? '');
        $orderby = sanitize_text_field($_POST['orderby'] ?? 'date');
        $order = sanitize_text_field($_POST['order'] ?? 'desc');
        $filter_type = sanitize_text_field($_POST['filter_type'] ?? '');
        $per_page = (int) ($_POST['per_page'] ?? 20);

        if (!in_array($orderby, ['name', 'size', 'type', 'date'], true)) $orderby = 'date';
        if (!in_array($order, ['asc', 'desc'], true)) $order = 'desc';
        if (!in_array($per_page, [20, 50, 100], true)) $per_page = 20;

        ob_start();
        self::render_results_table($tab, $page, $per_page, $search, $orderby, $order, $filter_type);
        $html = ob_get_clean();

        // Extract pagination data from hidden div
        $total_pages = 0;
        $total_items = 0;
        if (preg_match('/data-total-pages="(\d+)"/', $html, $m)) $total_pages = (int) $m[1];
        if (preg_match('/data-total-items="(\d+)"/', $html, $m)) $total_items = (int) $m[1];

        ob_start();
        self::render_stats();
        $stats_html = ob_get_clean();

        wp_send_json_success([
            'html'        => $html,
            'stats'       => $stats_html,
            'total_pages' => $total_pages,
            'total_items' => $total_items,
            'page'        => $page,
            'trash_count' => (int) wp_count_posts('attachment')->trash,
        ]);
    }

    /**
     * Trash a single unused file.
     */
    public static function ajax_trash_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $result = wp_trash_post($post_id);
        if ($result) {
            self::remove_from_scan_results($post_id);
            wp_send_json_success(['message' => __('File moved to trash.', 'oli-media-cleaner')]);
        }
        wp_send_json_error(__('Could not trash file.', 'oli-media-cleaner'));
    }

    /**
     * Trash multiple files.
     */
    public static function ajax_trash_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $trashed = 0;
        foreach ($ids as $id) {
            if (wp_trash_post($id)) {
                self::remove_from_scan_results($id);
                $trashed++;
            }
        }

        wp_send_json_success([
            /* translators: %d: number of files trashed */
            'message' => sprintf(__('%d files moved to trash.', 'oli-media-cleaner'), $trashed),
            'count'   => $trashed,
        ]);
    }

    /**
     * Permanently delete a single file.
     */
    public static function ajax_delete_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $result = wp_delete_attachment($post_id, true);
        if ($result) {
            self::remove_from_scan_results($post_id);
            wp_send_json_success(['message' => __('File permanently deleted.', 'oli-media-cleaner')]);
        }
        wp_send_json_error(__('Could not delete file.', 'oli-media-cleaner'));
    }

    /**
     * Permanently delete multiple files.
     */
    public static function ajax_delete_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $deleted = 0;
        foreach ($ids as $id) {
            if (wp_delete_attachment($id, true)) {
                self::remove_from_scan_results($id);
                $deleted++;
            }
        }

        wp_send_json_success([
            /* translators: %d: number of files deleted */
            'message' => sprintf(__('%d files permanently deleted.', 'oli-media-cleaner'), $deleted),
            'count'   => $deleted,
        ]);
    }

    /**
     * Add to whitelist.
     */
    public static function ajax_whitelist_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $whitelist = get_option('olimc_whitelist', []);
        if (!in_array($post_id, $whitelist, true)) {
            $whitelist[] = $post_id;
            update_option('olimc_whitelist', $whitelist, false);
        }

        wp_send_json_success(['message' => __('Added to whitelist.', 'oli-media-cleaner')]);
    }

    /**
     * Add multiple to whitelist.
     */
    public static function ajax_whitelist_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $whitelist = get_option('olimc_whitelist', []);
        $added = 0;

        foreach ($ids as $id) {
            if ($id > 0 && !in_array($id, $whitelist, true)) {
                $whitelist[] = $id;
                $added++;
            }
        }
        update_option('olimc_whitelist', $whitelist, false);

        wp_send_json_success([
            /* translators: %d: number of items added */
            'message' => sprintf(__('%d items added to whitelist.', 'oli-media-cleaner'), $added),
            'count'   => $added,
        ]);
    }

    /**
     * Remove from whitelist.
     */
    public static function ajax_remove_whitelist() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $whitelist = get_option('olimc_whitelist', []);
        $whitelist = array_values(array_diff($whitelist, [$post_id]));
        update_option('olimc_whitelist', $whitelist, false);

        wp_send_json_success(['message' => __('Removed from whitelist.', 'oli-media-cleaner')]);
    }

    /**
     * Remove multiple from whitelist.
     */
    public static function ajax_remove_whitelist_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $whitelist = get_option('olimc_whitelist', []);
        $whitelist = array_values(array_diff($whitelist, $ids));
        update_option('olimc_whitelist', $whitelist, false);

        wp_send_json_success([
            /* translators: %d: number of items removed */
            'message' => sprintf(__('%d items removed from whitelist.', 'oli-media-cleaner'), count($ids)),
            'count'   => count($ids),
        ]);
    }

    /**
     * Restore from trash.
     */
    public static function ajax_restore_single() {
        self::verify_request();

        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('Invalid ID.');

        $result = wp_untrash_post($post_id);
        if ($result) {
            wp_send_json_success(['message' => __('File restored.', 'oli-media-cleaner')]);
        }
        wp_send_json_error(__('Could not restore file.', 'oli-media-cleaner'));
    }

    /**
     * Restore multiple from trash.
     */
    public static function ajax_restore_bulk() {
        self::verify_request();

        $ids = array_map('intval', $_POST['ids'] ?? []);
        $restored = 0;
        foreach ($ids as $id) {
            if (wp_untrash_post($id)) {
                $restored++;
            }
        }

        wp_send_json_success([
            /* translators: %d: number of files restored */
            'message' => sprintf(__('%d files restored.', 'oli-media-cleaner'), $restored),
            'count'   => $restored,
        ]);
    }

    /**
     * Trash all unused in batches (AJAX).
     */
    public static function ajax_trash_all_batch() {
        self::verify_request();

        $batch_size = 50;
        $scan_results = get_option('olimc_scan_results', []);
        $whitelist = get_option('olimc_whitelist', []);

        // Filter out whitelisted
        $scan_results = array_filter($scan_results, function($item) use ($whitelist) {
            return !in_array((int)$item['id'], $whitelist, true);
        });
        $scan_results = array_values($scan_results);

        $total = count($scan_results);
        $batch = array_slice($scan_results, 0, $batch_size);
        $trashed = 0;

        foreach ($batch as $item) {
            if (wp_trash_post((int)$item['id'])) {
                self::remove_from_scan_results((int)$item['id']);
                $trashed++;
            }
        }

        $remaining = $total - $trashed;

        wp_send_json_success([
            'trashed'   => $trashed,
            'remaining' => max(0, $remaining),
            'total'     => $total,
            'done'      => $remaining <= 0,
        ]);
    }

    /**
     * Empty trash: permanently delete trashed attachments in batches.
     */
    public static function ajax_empty_trash_batch() {
        self::verify_request();

        global $wpdb;
        $batch_size = 50;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $trashed_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_status = 'trash'
             ORDER BY ID ASC
             LIMIT %d",
            $batch_size
        ) );

        $total_trashed = (int) wp_count_posts('attachment')->trash;
        $deleted = 0;

        foreach ($trashed_ids as $id) {
            if (wp_delete_attachment((int) $id, true)) {
                $deleted++;
            }
        }

        $remaining = max(0, $total_trashed - $deleted);

        wp_send_json_success([
            'deleted'   => $deleted,
            'remaining' => $remaining,
            'total'     => $total_trashed,
            'done'      => $remaining <= 0,
        ]);
    }

    /**
     * Save cron settings.
     */
    public static function ajax_save_cron_settings() {
        self::verify_request();

        $enabled = !empty($_POST['enabled']);
        $frequency = sanitize_text_field($_POST['frequency'] ?? 'daily');

        if (!in_array($frequency, ['daily', 'twicedaily', 'weekly'], true)) {
            $frequency = 'daily';
        }

        update_option('olimc_cron_enabled', $enabled, false);
        update_option('olimc_cron_frequency', $frequency, false);

        // Clear existing schedule
        wp_clear_scheduled_hook('olimc_scheduled_cleanup');

        $next_run = '';
        if ($enabled) {
            wp_schedule_event(time() + 60, $frequency, 'olimc_scheduled_cleanup');
            $next_run = date_i18n('M j, Y g:i a', wp_next_scheduled('olimc_scheduled_cleanup'));
        }

        wp_send_json_success([
            'message'  => $enabled
                /* translators: %1$s: frequency, %2$s: next run date */
                ? sprintf(__('Auto-cleanup enabled (%1$s). Next run: %2$s', 'oli-media-cleaner'), $frequency, $next_run)
                : __('Auto-cleanup disabled.', 'oli-media-cleaner'),
            'next_run' => $next_run ?: __('Not scheduled', 'oli-media-cleaner'),
        ]);
    }

    /**
     * Cron callback: scan + trash unused images.
     */
    public static function run_scheduled_cleanup() {
        $scanner = new OLIMC_Scanner();
        $used_ids = $scanner->collect_used_ids();
        $whitelist = get_option('olimc_whitelist', []);
        $total = $scanner->get_total_attachment_count();

        global $wpdb;
        $batch_size = 100;
        $offset = 0;
        $all_unused = [];

        while (true) {
            $attachment_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                 AND post_status != 'trash'
                 ORDER BY ID ASC
                 LIMIT %d OFFSET %d",
                $batch_size, $offset
            ));

            if (empty($attachment_ids)) break;

            foreach ($attachment_ids as $id) {
                $id = (int) $id;
                if (!in_array($id, $used_ids, true) && !in_array($id, $whitelist, true)) {
                    $info = $scanner->get_attachment_info($id);
                    if ($info) {
                        $all_unused[] = $info;
                        wp_trash_post($id);
                    }
                }
            }

            $offset += $batch_size;
            if (count($attachment_ids) < $batch_size) break;
        }

        // Update scan results and date
        update_option('olimc_scan_results', [], false);
        update_option('olimc_scan_date', current_time('mysql'), false);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private static function remove_from_scan_results($post_id) {
        $results = get_option('olimc_scan_results', []);
        $results = array_filter($results, function($item) use ($post_id) {
            return (int) $item['id'] !== (int) $post_id;
        });
        update_option('olimc_scan_results', array_values($results), false);
    }
}
