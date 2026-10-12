(function($) {
    'use strict';

    var OLIMC = {
        currentTab: 'unused',
        currentPage: 1,
        scanning: false,
        searchTerm: '',
        sortBy: 'date',
        sortOrder: 'desc',
        filterType: '',
        perPage: 20,

        str: function(key) {
            return (olimcObj.strings && olimcObj.strings[key]) || key;
        },

        escapeText: function(text) {
            return $('<div>').text(text == null ? '' : text).html();
        },

        format: function(template) {
            var args = Array.prototype.slice.call(arguments, 1);
            return String(template).replace(/%(\d+)\$[sd]/g, function(_, n) {
                var value = args[parseInt(n, 10) - 1];
                return value === undefined ? '' : value;
            }).replace(/%[sd]/g, function() {
                var value = args.shift();
                return value === undefined ? '' : value;
            }).replace(/%%/g, '%');
        },

        init: function() {
            // Detect current tab from URL
            var params = new URLSearchParams(window.location.search);
            this.currentTab = params.get('tab') || 'unused';

            this.restorePerPage();
            this.bindEvents();
            this.updateBulkBar();
            this.loadResults();
        },

        restorePerPage: function() {
            // Remember the user's "per page" choice across reloads so the list
            // and pagination keep reflecting it instead of snapping back to 20.
            var saved;
            try { saved = parseInt(window.localStorage.getItem('olimcPerPage'), 10); } catch (e) {}
            if (saved === 20 || saved === 50 || saved === 100) {
                this.perPage = saved;
                $('#olimc-per-page').val(String(saved));
            }
        },

        bindEvents: function() {
            var self = this;

            // Scan button
            $('#olimc-scan-btn').on('click', function() {
                self.startScan();
            });

            // Tab clicks - use AJAX instead of page reload
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).attr('href').match(/tab=(\w+)/);
                if (tab) {
                    self.currentTab = tab[1];
                    self.currentPage = 1;
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    var url = new URL(window.location);
                    url.searchParams.set('tab', self.currentTab);
                    window.history.replaceState({}, '', url);
                    self.searchTerm = '';
                    self.filterType = '';
                    self.sortBy = 'date';
                    self.sortOrder = 'desc';
                    $('#olimc-search').val('');
                    $('#olimc-filter-type').val('');
                    self.updateBulkBar();
                    self.loadResults();
                }
            });

            // Select all checkbox (table header)
            $(document).on('change', '.olimc-select-all-header', function() {
                var checked = $(this).prop('checked');
                $('.olimc-item-cb').prop('checked', checked);
                $('.olimc-select-all-header').prop('checked', checked);
                self.updateSelectedInfo();
            });

            // Individual checkbox
            $(document).on('change', '.olimc-item-cb', function() {
                self.updateSelectedInfo();
            });

            // Single actions
            $(document).on('click', '.olimc-trash-btn', function() {
                var id = $(this).data('id');
                if (confirm(self.str('confirm_trash'))) {
                    self.trashSingle(id, $(this).closest('tr'));
                }
            });

            $(document).on('click', '.olimc-delete-btn', function() {
                var id = $(this).data('id');
                if (confirm(self.str('confirm_delete'))) {
                    self.deleteSingle(id, $(this).closest('tr'));
                }
            });

            $(document).on('click', '.olimc-whitelist-btn', function() {
                var id = $(this).data('id');
                self.whitelistSingle(id, $(this).closest('tr'));
            });

            $(document).on('click', '.olimc-remove-whitelist-btn', function() {
                var id = $(this).data('id');
                self.removeWhitelist(id, $(this).closest('tr'));
            });

            $(document).on('click', '.olimc-restore-btn', function() {
                var id = $(this).data('id');
                self.restoreSingle(id, $(this).closest('tr'));
            });

            // Bulk actions
            $('#olimc-bulk-trash-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(self.str('no_selection'), 'info'); return; }
                if (confirm(self.str('confirm_bulk_trash'))) {
                    self.trashBulk(ids);
                }
            });

            $('#olimc-bulk-delete-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(self.str('no_selection'), 'info'); return; }
                if (confirm(self.str('confirm_bulk_delete'))) {
                    self.deleteBulk(ids);
                }
            });

            $('#olimc-bulk-whitelist-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(self.str('no_selection'), 'info'); return; }
                self.whitelistBulk(ids);
            });

            $('#olimc-bulk-remove-whitelist-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(self.str('no_selection'), 'info'); return; }
                if (confirm(self.str('confirm_remove_whitelist'))) {
                    self.removeWhitelistBulk(ids);
                }
            });

            $('#olimc-remove-all-whitelist-btn').on('click', function() {
                if (!confirm(self.str('confirm_remove_whitelist_all'))) return;
                self.removeWhitelistAll();
            });

            $('#olimc-bulk-restore-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(self.str('no_selection'), 'info'); return; }
                self.restoreBulk(ids);
            });

            // Trash All
            $('#olimc-trash-all-btn').on('click', function() {
                if (!confirm(self.str('confirm_trash_all'))) return;
                self.trashAll();
            });

            // Restore All
            $('#olimc-restore-all-btn').on('click', function() {
                if (!confirm(self.str('confirm_restore_all'))) return;
                self.restoreAll();
            });

            // Empty Trash — strong warning: irreversible, backup first
            $('#olimc-empty-trash-btn').on('click', function() {
                if (!confirm(self.str('confirm_empty_trash'))) return;
                self.emptyTrash();
            });

            // Cron settings
            $('#olimc-save-cron-btn').on('click', function() {
                self.saveCronSettings();
            });

            // Sort by column header
            $(document).on('click', '.olimc-sortable', function() {
                var col = $(this).data('sort');
                if (self.sortBy === col) {
                    self.sortOrder = self.sortOrder === 'asc' ? 'desc' : 'asc';
                } else {
                    self.sortBy = col;
                    self.sortOrder = col === 'date' ? 'desc' : 'asc';
                }
                self.currentPage = 1;
                self.loadResults();
            });

            // Per page
            $('#olimc-per-page').on('change', function() {
                self.perPage = parseInt($(this).val(), 10) || 20;
                try { window.localStorage.setItem('olimcPerPage', self.perPage); } catch (e) {}
                self.currentPage = 1;
                self.loadResults();
            });

            // Filter by type
            $('#olimc-filter-type').on('change', function() {
                self.filterType = $(this).val();
                self.currentPage = 1;
                self.loadResults();
            });

            // Export current (filtered) view to CSV
            $('#olimc-export-csv-btn').on('click', function() {
                self.exportCsv();
            });

            // Search
            $('#olimc-search-btn').on('click', function() {
                self.searchTerm = $('#olimc-search').val().trim();
                self.currentPage = 1;
                self.loadResults();
            });
            $('#olimc-search').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.searchTerm = $(this).val().trim();
                    self.currentPage = 1;
                    self.loadResults();
                }
            }).on('search', function() {
                if ($(this).val() === '') {
                    self.searchTerm = '';
                    self.currentPage = 1;
                    self.loadResults();
                }
            });

            // Pagination
            $(document).on('click', '.olimc-page-btn', function() {
                var page = $(this).data('page');
                if (page && page !== self.currentPage) {
                    self.currentPage = page;
                    self.loadResults();
                    $('html, body').animate({ scrollTop: $('#olimc-results').offset().top - 50 }, 300);
                }
            });
        },

        // ─── Scan ───────────────────────────────────────────────────

        startScan: function() {
            if (this.scanning) return;
            this.scanning = true;

            var self = this;
            var $btn = $('#olimc-scan-btn');
            var $progress = $('#olimc-progress-wrap');
            var $fill = $('#olimc-progress-fill');
            var $text = $('#olimc-progress-text');

            // Immediate feedback: the first step (collecting used IDs) can take
            // several seconds with no measurable %, so show a spinner + an
            // indeterminate "working" bar right away instead of a frozen 0%.
            $btn.prop('disabled', true).html('<span class="olimc-spinner"></span>' + this.str('scanning'));
            $progress.show();
            $fill.css('width', '100%').addClass('olimc-indeterminate');
            $text.html('<span class="olimc-spinner"></span>' + this.escapeText(this.str('preparing')));

            // Step 1: Start scan (collect used IDs)
            $.post(olimcObj.ajaxurl, {
                action: 'olimc_start_scan',
                nonce: olimcObj.nonce
            }, function(res) {
                if (!res.success) {
                    self.toast(res.data || self.str('scan_failed'), 'error');
                    self.resetScanUI();
                    return;
                }

                // Switch from indeterminate to real progress.
                $fill.removeClass('olimc-indeterminate').css('width', '5%');
                $text.text('5%');

                // Step 2: Process batches
                self.scanBatch(0, res.data.total);
            }).fail(function() {
                self.toast(self.str('network_error'), 'error');
                self.resetScanUI();
            });
        },

        scanBatch: function(offset, total) {
            var self = this;
            var $fill = $('#olimc-progress-fill');
            var $text = $('#olimc-progress-text');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_scan_batch',
                nonce: olimcObj.nonce,
                offset: offset
            }, function(res) {
                if (!res.success) {
                    self.toast(self.str('batch_scan_error'), 'error');
                    self.resetScanUI();
                    return;
                }

                var pct = Math.min(100, Math.round((res.data.processed / total) * 100));
                $fill.css('width', pct + '%');
                $text.text(self.format(self.str('unused_found_progress'), pct, res.data.unused_found));

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text(self.str('scan_complete') + ' ' + self.format(self.str('unused_files_count'), res.data.unused_found));
                    self.toast(self.str('scan_complete') + ' ' + self.format(self.str('found_unused'), res.data.unused_found), 'success');
                    self.scanning = false;
                    self.currentPage = 1;
                    self.loadResults();

                    setTimeout(function() {
                        $('#olimc-progress-wrap').fadeOut();
                        $('#olimc-scan-btn').prop('disabled', false).text(self.str('rescan'));
                    }, 3000);
                } else {
                    // Next batch
                    self.scanBatch(res.data.processed, total);
                }
            }).fail(function() {
                self.toast(self.str('network_error_scan'), 'error');
                self.resetScanUI();
            });
        },

        resetScanUI: function() {
            this.scanning = false;
            $('#olimc-scan-btn').prop('disabled', false).text(this.str('scan_button'));
            $('#olimc-progress-fill').removeClass('olimc-indeterminate');
            $('#olimc-progress-wrap').hide();
        },

        exportCsv: function() {
            // Download reflects the current tab + active filters/sort (all pages).
            var params = $.param({
                action: 'olimc_export_csv',
                nonce: olimcObj.nonce,
                tab: this.currentTab,
                search: this.searchTerm,
                orderby: this.sortBy,
                order: this.sortOrder,
                filter_type: this.filterType
            });
            window.location.href = olimcObj.ajaxurl + '?' + params;
        },

        // ─── Load results via AJAX ──────────────────────────────────

        loadResults: function() {
            var self = this;
            var $results = $('#olimc-results');

            $results.css('opacity', '0.5');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_get_results',
                nonce: olimcObj.nonce,
                tab: this.currentTab,
                page: this.currentPage,
                search: this.searchTerm,
                orderby: this.sortBy,
                order: this.sortOrder,
                filter_type: this.filterType,
                per_page: this.perPage
            }, function(res) {
                if (res.success) {
                    $results.html(res.data.html).css('opacity', '1');
                    $('#olimc-stats').html(res.data.stats);
                    if (typeof res.data.filter_options !== 'undefined') {
                        // Keep the type filter in sync with the latest scan results
                        // (new extensions appear without a full page reload).
                        var $filter = $('#olimc-filter-type');
                        $filter.html(res.data.filter_options);
                        $filter.val(self.filterType);
                    }
                    self.buildPagination(res.data.total_pages || 0, res.data.total_items || 0);
                    self.updateTabCounts(res.data.trash_count);
                    $('.olimc-select-all-header').prop('checked', false);
                    self.updateSelectedInfo();
                }
            });
        },

        buildPagination: function(totalPages, totalItems) {
            var $pag = $('#olimc-pagination');
            if (!$pag.length) return;
            totalPages = totalPages || 0;
            totalItems = totalItems || 0;
            var currentPage = this.currentPage;

            $pag.empty();

            if (totalPages <= 1) {
                if (totalItems > 0) $pag.append('<span class="olimc-page-info">' + this.format(this.str('items_count'), totalItems) + '</span>');
                return;
            }

            $pag.append('<button class="button olimc-page-btn" data-page="' + (currentPage - 1) + '" ' + (currentPage <= 1 ? 'disabled' : '') + '>&laquo;</button>');

            var start = Math.max(1, currentPage - 2);
            var end = Math.min(totalPages, currentPage + 2);

            if (start > 1) {
                $pag.append('<button class="button olimc-page-btn" data-page="1">1</button>');
                if (start > 2) $pag.append('<span class="olimc-page-info">…</span>');
            }

            for (var i = start; i <= end; i++) {
                $pag.append('<button class="button olimc-page-btn ' + (i === currentPage ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>');
            }

            if (end < totalPages) {
                if (end < totalPages - 1) $pag.append('<span class="olimc-page-info">…</span>');
                $pag.append('<button class="button olimc-page-btn" data-page="' + totalPages + '">' + totalPages + '</button>');
            }

            $pag.append('<button class="button olimc-page-btn" data-page="' + (currentPage + 1) + '" ' + (currentPage >= totalPages ? 'disabled' : '') + '>&raquo;</button>');
            $pag.append('<span class="olimc-page-info">' + this.format(this.str('items_count'), totalItems) + '</span>');
        },

        updateTabCounts: function(trashCount) {
            var $cells = $('#olimc-stats .form-table td strong');
            var unused = $cells.eq(2).text().replace(/,/g, '') || '0';
            var whitelist = $cells.eq(4).text().replace(/,/g, '') || '0';
            $('#olimc-unused-count').text('(' + unused + ')');
            $('#olimc-whitelist-count').text('(' + whitelist + ')');
            if (typeof trashCount !== 'undefined') {
                $('#olimc-trash-count').text('(' + trashCount + ')');
            }
        },

        updateBulkBar: function() {
            // Tab-scoped buttons stay in the DOM; visibility is refined in updateActionButtons
            // (selection vs "has items" vs Trash All Unused).
            this.updateActionButtons();
        },

        tabItemCount: function(tab) {
            var id = tab === 'whitelist' ? '#olimc-whitelist-count'
                : tab === 'trash' ? '#olimc-trash-count'
                : '#olimc-unused-count';
            var n = parseInt(String($(id).text()).replace(/[^\d]/g, ''), 10);
            return isNaN(n) ? 0 : n;
        },

        updateActionButtons: function() {
            var tab = this.currentTab;
            var selected = $('.olimc-item-cb:checked').length;
            var hasItems = this.tabItemCount(tab) > 0;

            // Hide every tab action first, then reveal the ones that apply.
            $('.olimc-tab-unused, .olimc-tab-whitelist, .olimc-tab-trash').hide();

            // Selection-based actions (Trash/Whitelist/Remove/Restore/Delete Selected).
            $('.olimc-needs-selection').each(function() {
                var $btn = $(this);
                if ($btn.hasClass('olimc-tab-' + tab) && selected >= 1) {
                    $btn.show();
                }
            });

            // Always-on when the tab has items (Remove All / Restore All / Empty Trash).
            $('.olimc-when-has-items').each(function() {
                var $btn = $(this);
                if ($btn.hasClass('olimc-tab-' + tab) && hasItems) {
                    $btn.show();
                }
            });

            // "Trash All Unused" only when more than one item is selected on Unused.
            $('#olimc-trash-all-btn').toggle(tab === 'unused' && selected > 1);
        },

        // ─── Actions ────────────────────────────────────────────────

        trashSingle: function(id, $row) {
            var self = this;
            $row.addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_trash_single',
                nonce: olimcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        deleteSingle: function(id, $row) {
            var self = this;
            $row.addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_delete_single',
                nonce: olimcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        whitelistSingle: function(id, $row) {
            var self = this;
            $row.addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_whitelist_single',
                nonce: olimcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        removeWhitelist: function(id, $row) {
            var self = this;
            $row.addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_remove_whitelist',
                nonce: olimcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        restoreSingle: function(id, $row) {
            var self = this;
            $row.addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_restore_single',
                nonce: olimcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        trashBulk: function(ids) {
            var self = this;
            $('.olimc-item-cb:checked').closest('tr').addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_trash_bulk',
                nonce: olimcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.olimc-loading').removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        deleteBulk: function(ids) {
            var self = this;
            $('.olimc-item-cb:checked').closest('tr').addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_delete_bulk',
                nonce: olimcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.olimc-loading').removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        whitelistBulk: function(ids) {
            var self = this;
            $('.olimc-item-cb:checked').closest('tr').addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_whitelist_bulk',
                nonce: olimcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.olimc-loading').removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        removeWhitelistBulk: function(ids) {
            var self = this;
            $('.olimc-item-cb:checked').closest('tr').addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_remove_whitelist_bulk',
                nonce: olimcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.olimc-loading').removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        removeWhitelistAll: function() {
            var self = this;
            var $btn = $('#olimc-remove-all-whitelist-btn');
            $btn.prop('disabled', true).text(self.str('starting'));

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_remove_whitelist_all',
                nonce: olimcObj.nonce
            }, function(res) {
                $btn.prop('disabled', false).text(self.str('remove_all_whitelist'));
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    self.toast(res.data || self.str('network_error'), 'error');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text(self.str('remove_all_whitelist'));
                self.toast(self.str('network_error'), 'error');
            });
        },

        restoreBulk: function(ids) {
            var self = this;
            $('.olimc-item-cb:checked').closest('tr').addClass('olimc-loading');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_restore_bulk',
                nonce: olimcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.olimc-loading').removeClass('olimc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        trashAll: function() {
            var self = this;
            var $btn = $('#olimc-trash-all-btn');
            var $progress = $('#olimc-progress-wrap');
            var $fill = $('#olimc-progress-fill');
            var $text = $('#olimc-progress-text');
            var totalStart = 0;

            $btn.prop('disabled', true).text(self.str('trashing'));
            $progress.show();
            $fill.css('width', '0%');
            $text.text(self.str('starting'));

            self.trashAllBatch(totalStart);
        },

        trashAllBatch: function(totalStart) {
            var self = this;
            var $fill = $('#olimc-progress-fill');
            var $text = $('#olimc-progress-text');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_trash_all_batch',
                nonce: olimcObj.nonce
            }, function(res) {
                if (!res.success) {
                    self.toast(self.str('error_trashing'), 'error');
                    self.resetTrashAllUI();
                    return;
                }

                if (totalStart === 0) totalStart = res.data.total || 1;
                var trashed = totalStart - res.data.remaining;
                var pct = Math.min(100, Math.round((trashed / totalStart) * 100));
                $fill.css('width', pct + '%');
                $text.text(self.format(self.str('trashed_progress'), pct, trashed, totalStart));

                // Update tab counts live
                $('#olimc-unused-count').text('(' + res.data.remaining + ')');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text(self.format(self.str('done_trashed'), totalStart));
                    self.toast(self.format(self.str('done_trashed'), totalStart), 'success');
                    self.currentPage = 1;
                    self.loadResults();
                    setTimeout(function() {
                        $('#olimc-progress-wrap').fadeOut();
                        self.resetTrashAllUI();
                    }, 3000);
                } else {
                    self.trashAllBatch(totalStart);
                }
            }).fail(function() {
                self.toast(self.str('network_error'), 'error');
                self.resetTrashAllUI();
            });
        },

        resetTrashAllUI: function() {
            $('#olimc-trash-all-btn').prop('disabled', false).text(this.str('trash_all_unused'));
        },

        restoreAll: function() {
            var self = this;
            var $btn = $('#olimc-restore-all-btn');
            var $progress = $('#olimc-progress-wrap');
            var $fill = $('#olimc-progress-fill');
            var $text = $('#olimc-progress-text');

            $btn.prop('disabled', true).text(self.str('restoring'));
            $progress.show();
            $fill.removeClass('olimc-indeterminate').css('width', '0%');
            $text.text(self.str('starting'));

            self.restoreAllBatch(0);
        },

        restoreAllBatch: function(totalStart) {
            var self = this;
            var $fill = $('#olimc-progress-fill');
            var $text = $('#olimc-progress-text');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_restore_all_batch',
                nonce: olimcObj.nonce
            }, function(res) {
                if (!res.success) {
                    self.toast(self.str('error_restoring'), 'error');
                    self.resetRestoreAllUI();
                    return;
                }

                if (totalStart === 0) totalStart = res.data.total || 1;
                var restored = totalStart - res.data.remaining;
                var pct = Math.min(100, Math.round((restored / totalStart) * 100));
                $fill.css('width', pct + '%');
                $text.text(self.format(self.str('restored_progress'), pct, restored, totalStart));

                // Update trash tab count live
                $('#olimc-trash-count').text('(' + res.data.remaining + ')');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text(self.format(self.str('done_restored'), totalStart));
                    self.toast(self.format(self.str('done_restored'), totalStart), 'success');
                    self.currentPage = 1;
                    self.loadResults();
                    setTimeout(function() {
                        $('#olimc-progress-wrap').fadeOut();
                        self.resetRestoreAllUI();
                    }, 3000);
                } else {
                    self.restoreAllBatch(totalStart);
                }
            }).fail(function() {
                self.toast(self.str('network_error'), 'error');
                self.resetRestoreAllUI();
            });
        },

        resetRestoreAllUI: function() {
            $('#olimc-restore-all-btn').prop('disabled', false).text(this.str('restore_all'));
        },

        emptyTrash: function() {
            var self = this;
            var $btn = $('#olimc-empty-trash-btn');
            var $progress = $('#olimc-progress-wrap');
            var $fill = $('#olimc-progress-fill');
            var $text = $('#olimc-progress-text');
            var totalStart = 0;

            $btn.prop('disabled', true).text(self.str('deleting'));
            $progress.show();
            $fill.css('width', '0%');
            $text.text(self.str('starting'));

            self.emptyTrashBatch(totalStart);
        },

        emptyTrashBatch: function(totalStart) {
            var self = this;
            var $fill = $('#olimc-progress-fill');
            var $text = $('#olimc-progress-text');

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_empty_trash_batch',
                nonce: olimcObj.nonce
            }, function(res) {
                if (!res.success) {
                    self.toast(self.str('error_deleting'), 'error');
                    self.resetEmptyTrashUI();
                    return;
                }

                if (totalStart === 0) totalStart = res.data.total || 1;
                var deleted = totalStart - res.data.remaining;
                var pct = Math.min(100, Math.round((deleted / totalStart) * 100));
                $fill.css('width', pct + '%');
                $text.text(self.format(self.str('deleted_progress'), pct, deleted, totalStart));

                // Update trash tab count live
                $('#olimc-trash-count').text('(' + res.data.remaining + ')');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text(self.format(self.str('done_deleted'), totalStart));
                    self.toast(self.format(self.str('done_deleted'), totalStart), 'success');
                    self.currentPage = 1;
                    self.loadResults();
                    setTimeout(function() {
                        $('#olimc-progress-wrap').fadeOut();
                        self.resetEmptyTrashUI();
                    }, 3000);
                } else {
                    self.emptyTrashBatch(totalStart);
                }
            }).fail(function() {
                self.toast(self.str('network_error'), 'error');
                self.resetEmptyTrashUI();
            });
        },

        resetEmptyTrashUI: function() {
            $('#olimc-empty-trash-btn').prop('disabled', false).text(this.str('empty_trash'));
        },

        saveCronSettings: function() {
            var self = this;
            var enabled = $('#olimc-cron-enabled').is(':checked');
            var frequency = $('#olimc-cron-frequency').val();

            $.post(olimcObj.ajaxurl, {
                action: 'olimc_save_cron_settings',
                nonce: olimcObj.nonce,
                enabled: enabled ? 1 : 0,
                frequency: frequency
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    $('#olimc-next-run').text(res.data.next_run);
                } else {
                    self.toast(res.data || self.str('error_saving'), 'error');
                }
            });
        },

        // ─── Helpers ────────────────────────────────────────────────

        getSelectedIds: function() {
            var ids = [];
            $('.olimc-item-cb:checked').each(function() {
                ids.push(parseInt($(this).val()));
            });
            return ids;
        },

        updateSelectedInfo: function() {
            var count = $('.olimc-item-cb:checked').length;
            var totalSize = 0;
            $('.olimc-item-cb:checked').each(function() {
                totalSize += parseInt($(this).data('size')) || 0;
            });
            if (count > 0) {
                $('#olimc-selected-info').text(this.format(this.str('selected_info'), count, this.formatSize(totalSize)));
            } else {
                $('#olimc-selected-info').text('');
            }
            this.updateActionButtons();
        },

        refreshAfterAction: function() {
            var self = this;
            // Quick stats refresh
            setTimeout(function() {
                self.loadResults();
            }, 500);
        },

        formatSize: function(bytes) {
            if (bytes === 0) return '0 B';
            var k = 1024;
            var sizes = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        },

        toast: function(message, type) {
            type = type || 'info';
            var $toast = $('<div class="olimc-toast ' + type + '">' + message + '</div>');
            $('body').append($toast);
            setTimeout(function() { $toast.addClass('show'); }, 10);
            setTimeout(function() {
                $toast.removeClass('show');
                setTimeout(function() { $toast.remove(); }, 300);
            }, 4000);
        }
    };

    $(document).ready(function() {
        if ($('#olimc-scan-btn').length) {
            OLIMC.init();
        }
    });

})(jQuery);
