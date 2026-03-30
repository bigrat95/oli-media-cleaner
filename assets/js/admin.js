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

        init: function() {
            // Detect current tab from URL
            var params = new URLSearchParams(window.location.search);
            this.currentTab = params.get('tab') || 'unused';

            this.bindEvents();
            this.loadResults();
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
                    self.loadResults();
                    self.updateBulkBar();
                }
            });

            // Select all checkbox
            $(document).on('change', '#olimc-select-all, .olimc-select-all-header', function() {
                var checked = $(this).prop('checked');
                $('.olimc-item-cb').prop('checked', checked);
                $('#olimc-select-all, .olimc-select-all-header').prop('checked', checked);
                self.updateSelectedInfo();
            });

            // Individual checkbox
            $(document).on('change', '.olimc-item-cb', function() {
                self.updateSelectedInfo();
            });

            // Single actions
            $(document).on('click', '.olimc-trash-btn', function() {
                var id = $(this).data('id');
                if (confirm(olimcObj.strings.confirm_trash)) {
                    self.trashSingle(id, $(this).closest('tr'));
                }
            });

            $(document).on('click', '.olimc-delete-btn', function() {
                var id = $(this).data('id');
                if (confirm(olimcObj.strings.confirm_delete)) {
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
                if (!ids.length) { self.toast(olimcObj.strings.no_selection, 'info'); return; }
                if (confirm(olimcObj.strings.confirm_bulk_trash)) {
                    self.trashBulk(ids);
                }
            });

            $('#olimc-bulk-delete-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(olimcObj.strings.no_selection, 'info'); return; }
                if (confirm(olimcObj.strings.confirm_bulk_delete)) {
                    self.deleteBulk(ids);
                }
            });

            $('#olimc-bulk-whitelist-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(olimcObj.strings.no_selection, 'info'); return; }
                self.whitelistBulk(ids);
            });

            $('#olimc-bulk-remove-whitelist-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(olimcObj.strings.no_selection, 'info'); return; }
                if (confirm('Remove selected items from whitelist?')) {
                    self.removeWhitelistBulk(ids);
                }
            });

            $('#olimc-bulk-restore-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(olimcObj.strings.no_selection, 'info'); return; }
                self.restoreBulk(ids);
            });

            // Trash All
            $('#olimc-trash-all-btn').on('click', function() {
                if (!confirm(olimcObj.strings.confirm_trash_all)) return;
                self.trashAll();
            });

            // Empty Trash
            $('#olimc-empty-trash-btn').on('click', function() {
                if (!confirm(olimcObj.strings.confirm_empty_trash)) return;
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
                self.perPage = parseInt($(this).val());
                self.currentPage = 1;
                self.loadResults();
            });

            // Filter by type
            $('#olimc-filter-type').on('change', function() {
                self.filterType = $(this).val();
                self.currentPage = 1;
                self.loadResults();
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

            $btn.prop('disabled', true).text(olimcObj.strings.scanning);
            $progress.show();
            $fill.css('width', '0%');
            $text.text('0%');

            // Step 1: Start scan (collect used IDs)
            $.post(olimcObj.ajaxurl, {
                action: 'olimc_start_scan',
                nonce: olimcObj.nonce
            }, function(res) {
                if (!res.success) {
                    self.toast(res.data || 'Scan failed', 'error');
                    self.resetScanUI();
                    return;
                }

                $fill.css('width', '5%');
                $text.text('5%');

                // Step 2: Process batches
                self.scanBatch(0, res.data.total);
            }).fail(function() {
                self.toast('Network error', 'error');
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
                    self.toast('Batch scan error', 'error');
                    self.resetScanUI();
                    return;
                }

                var pct = Math.min(100, Math.round((res.data.processed / total) * 100));
                $fill.css('width', pct + '%');
                $text.text(pct + '% — ' + res.data.unused_found + ' unused found');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text(olimcObj.strings.scan_complete + ' ' + res.data.unused_found + ' unused files.');
                    self.toast(olimcObj.strings.scan_complete + ' Found ' + res.data.unused_found + ' unused files.', 'success');
                    self.scanning = false;
                    self.currentPage = 1;
                    self.loadResults();

                    setTimeout(function() {
                        $('#olimc-progress-wrap').fadeOut();
                        $('#olimc-scan-btn').prop('disabled', false).text('Re-Scan');
                    }, 3000);
                } else {
                    // Next batch
                    self.scanBatch(res.data.processed, total);
                }
            }).fail(function() {
                self.toast('Network error during scan', 'error');
                self.resetScanUI();
            });
        },

        resetScanUI: function() {
            this.scanning = false;
            $('#olimc-scan-btn').prop('disabled', false).text('Scan for Unused Media');
            $('#olimc-progress-wrap').hide();
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
                    self.buildPagination(res.data.total_pages || 0, res.data.total_items || 0);
                    self.updateTabCounts(res.data.trash_count);
                    $('#olimc-select-all, .olimc-select-all-header').prop('checked', false);
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
                if (totalItems > 0) $pag.append('<span class="olimc-page-info">' + totalItems + ' items</span>');
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
            $pag.append('<span class="olimc-page-info">' + totalItems + ' items</span>');
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
            // Show/hide relevant bulk buttons based on tab
            var tab = this.currentTab;
            $('#olimc-bulk-trash-btn, #olimc-bulk-whitelist-btn').toggle(tab === 'unused');
            $('#olimc-bulk-remove-whitelist-btn').toggle(tab === 'whitelist');
            $('#olimc-bulk-delete-btn, #olimc-bulk-restore-btn').toggle(tab === 'trash');
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

            $btn.prop('disabled', true).text('Trashing...');
            $progress.show();
            $fill.css('width', '0%');
            $text.text('Starting...');

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
                    self.toast('Error trashing files', 'error');
                    self.resetTrashAllUI();
                    return;
                }

                if (totalStart === 0) totalStart = res.data.total || 1;
                var trashed = totalStart - res.data.remaining;
                var pct = Math.min(100, Math.round((trashed / totalStart) * 100));
                $fill.css('width', pct + '%');
                $text.text(pct + '% — ' + trashed + ' / ' + totalStart + ' trashed');

                // Update tab counts live
                $('#olimc-unused-count').text('(' + res.data.remaining + ')');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text('Done! ' + totalStart + ' files moved to trash.');
                    self.toast(totalStart + ' files moved to trash.', 'success');
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
                self.toast('Network error', 'error');
                self.resetTrashAllUI();
            });
        },

        resetTrashAllUI: function() {
            $('#olimc-trash-all-btn').prop('disabled', false).text('Trash All Unused');
        },

        emptyTrash: function() {
            var self = this;
            var $btn = $('#olimc-empty-trash-btn');
            var $progress = $('#olimc-progress-wrap');
            var $fill = $('#olimc-progress-fill');
            var $text = $('#olimc-progress-text');
            var totalStart = 0;

            $btn.prop('disabled', true).text('Deleting...');
            $progress.show();
            $fill.css('width', '0%');
            $text.text('Starting...');

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
                    self.toast('Error deleting files', 'error');
                    self.resetEmptyTrashUI();
                    return;
                }

                if (totalStart === 0) totalStart = res.data.total || 1;
                var deleted = totalStart - res.data.remaining;
                var pct = Math.min(100, Math.round((deleted / totalStart) * 100));
                $fill.css('width', pct + '%');
                $text.text(pct + '% — ' + deleted + ' / ' + totalStart + ' permanently deleted');

                // Update trash tab count live
                $('#olimc-trash-count').text('(' + res.data.remaining + ')');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text('Done! ' + totalStart + ' files permanently deleted.');
                    self.toast(totalStart + ' files permanently deleted.', 'success');
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
                self.toast('Network error', 'error');
                self.resetEmptyTrashUI();
            });
        },

        resetEmptyTrashUI: function() {
            $('#olimc-empty-trash-btn').prop('disabled', false).text('Empty Trash');
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
                    self.toast(res.data || 'Error saving settings', 'error');
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
                $('#olimc-selected-info').text(count + ' selected (' + this.formatSize(totalSize) + ')');
            } else {
                $('#olimc-selected-info').text('');
            }
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
