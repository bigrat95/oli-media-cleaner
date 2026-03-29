(function($) {
    'use strict';

    var OMC = {
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
            $('#omc-scan-btn').on('click', function() {
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
                    $('#omc-search').val('');
                    $('#omc-filter-type').val('');
                    self.loadResults();
                    self.updateBulkBar();
                }
            });

            // Select all checkbox
            $(document).on('change', '#omc-select-all, .omc-select-all-header', function() {
                var checked = $(this).prop('checked');
                $('.omc-item-cb').prop('checked', checked);
                $('#omc-select-all, .omc-select-all-header').prop('checked', checked);
                self.updateSelectedInfo();
            });

            // Individual checkbox
            $(document).on('change', '.omc-item-cb', function() {
                self.updateSelectedInfo();
            });

            // Single actions
            $(document).on('click', '.omc-trash-btn', function() {
                var id = $(this).data('id');
                if (confirm(omcObj.strings.confirm_trash)) {
                    self.trashSingle(id, $(this).closest('tr'));
                }
            });

            $(document).on('click', '.omc-delete-btn', function() {
                var id = $(this).data('id');
                if (confirm(omcObj.strings.confirm_delete)) {
                    self.deleteSingle(id, $(this).closest('tr'));
                }
            });

            $(document).on('click', '.omc-whitelist-btn', function() {
                var id = $(this).data('id');
                self.whitelistSingle(id, $(this).closest('tr'));
            });

            $(document).on('click', '.omc-remove-whitelist-btn', function() {
                var id = $(this).data('id');
                self.removeWhitelist(id, $(this).closest('tr'));
            });

            $(document).on('click', '.omc-restore-btn', function() {
                var id = $(this).data('id');
                self.restoreSingle(id, $(this).closest('tr'));
            });

            // Bulk actions
            $('#omc-bulk-trash-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(omcObj.strings.no_selection, 'info'); return; }
                if (confirm(omcObj.strings.confirm_bulk_trash)) {
                    self.trashBulk(ids);
                }
            });

            $('#omc-bulk-delete-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(omcObj.strings.no_selection, 'info'); return; }
                if (confirm(omcObj.strings.confirm_bulk_delete)) {
                    self.deleteBulk(ids);
                }
            });

            $('#omc-bulk-whitelist-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(omcObj.strings.no_selection, 'info'); return; }
                self.whitelistBulk(ids);
            });

            $('#omc-bulk-remove-whitelist-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(omcObj.strings.no_selection, 'info'); return; }
                if (confirm('Remove selected items from whitelist?')) {
                    self.removeWhitelistBulk(ids);
                }
            });

            $('#omc-bulk-restore-btn').on('click', function() {
                var ids = self.getSelectedIds();
                if (!ids.length) { self.toast(omcObj.strings.no_selection, 'info'); return; }
                self.restoreBulk(ids);
            });

            // Trash All
            $('#omc-trash-all-btn').on('click', function() {
                if (!confirm(omcObj.strings.confirm_trash_all)) return;
                self.trashAll();
            });

            // Empty Trash
            $('#omc-empty-trash-btn').on('click', function() {
                if (!confirm(omcObj.strings.confirm_empty_trash)) return;
                self.emptyTrash();
            });

            // Cron settings
            $('#omc-save-cron-btn').on('click', function() {
                self.saveCronSettings();
            });

            // Sort by column header
            $(document).on('click', '.omc-sortable', function() {
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
            $('#omc-per-page').on('change', function() {
                self.perPage = parseInt($(this).val());
                self.currentPage = 1;
                self.loadResults();
            });

            // Filter by type
            $('#omc-filter-type').on('change', function() {
                self.filterType = $(this).val();
                self.currentPage = 1;
                self.loadResults();
            });

            // Search
            $('#omc-search-btn').on('click', function() {
                self.searchTerm = $('#omc-search').val().trim();
                self.currentPage = 1;
                self.loadResults();
            });
            $('#omc-search').on('keypress', function(e) {
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
            $(document).on('click', '.omc-page-btn', function() {
                var page = $(this).data('page');
                if (page && page !== self.currentPage) {
                    self.currentPage = page;
                    self.loadResults();
                    $('html, body').animate({ scrollTop: $('#omc-results').offset().top - 50 }, 300);
                }
            });
        },

        // ─── Scan ───────────────────────────────────────────────────

        startScan: function() {
            if (this.scanning) return;
            this.scanning = true;

            var self = this;
            var $btn = $('#omc-scan-btn');
            var $progress = $('#omc-progress-wrap');
            var $fill = $('#omc-progress-fill');
            var $text = $('#omc-progress-text');

            $btn.prop('disabled', true).text(omcObj.strings.scanning);
            $progress.show();
            $fill.css('width', '0%');
            $text.text('0%');

            // Step 1: Start scan (collect used IDs)
            $.post(omcObj.ajaxurl, {
                action: 'omc_start_scan',
                nonce: omcObj.nonce
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
            var $fill = $('#omc-progress-fill');
            var $text = $('#omc-progress-text');

            $.post(omcObj.ajaxurl, {
                action: 'omc_scan_batch',
                nonce: omcObj.nonce,
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
                    $text.text(omcObj.strings.scan_complete + ' ' + res.data.unused_found + ' unused files.');
                    self.toast(omcObj.strings.scan_complete + ' Found ' + res.data.unused_found + ' unused files.', 'success');
                    self.scanning = false;
                    self.currentPage = 1;
                    self.loadResults();

                    setTimeout(function() {
                        $('#omc-progress-wrap').fadeOut();
                        $('#omc-scan-btn').prop('disabled', false).text('Re-Scan');
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
            $('#omc-scan-btn').prop('disabled', false).text('Scan for Unused Media');
            $('#omc-progress-wrap').hide();
        },

        // ─── Load results via AJAX ──────────────────────────────────

        loadResults: function() {
            var self = this;
            var $results = $('#omc-results');

            $results.css('opacity', '0.5');

            $.post(omcObj.ajaxurl, {
                action: 'omc_get_results',
                nonce: omcObj.nonce,
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
                    $('#omc-stats').html(res.data.stats);
                    self.buildPagination(res.data.total_pages || 0, res.data.total_items || 0);
                    self.updateTabCounts(res.data.trash_count);
                    $('#omc-select-all, .omc-select-all-header').prop('checked', false);
                    self.updateSelectedInfo();
                }
            });
        },

        buildPagination: function(totalPages, totalItems) {
            var $pag = $('#omc-pagination');
            if (!$pag.length) return;
            totalPages = totalPages || 0;
            totalItems = totalItems || 0;
            var currentPage = this.currentPage;

            $pag.empty();

            if (totalPages <= 1) {
                if (totalItems > 0) $pag.append('<span class="omc-page-info">' + totalItems + ' items</span>');
                return;
            }

            $pag.append('<button class="button omc-page-btn" data-page="' + (currentPage - 1) + '" ' + (currentPage <= 1 ? 'disabled' : '') + '>&laquo;</button>');

            var start = Math.max(1, currentPage - 2);
            var end = Math.min(totalPages, currentPage + 2);

            if (start > 1) {
                $pag.append('<button class="button omc-page-btn" data-page="1">1</button>');
                if (start > 2) $pag.append('<span class="omc-page-info">…</span>');
            }

            for (var i = start; i <= end; i++) {
                $pag.append('<button class="button omc-page-btn ' + (i === currentPage ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>');
            }

            if (end < totalPages) {
                if (end < totalPages - 1) $pag.append('<span class="omc-page-info">…</span>');
                $pag.append('<button class="button omc-page-btn" data-page="' + totalPages + '">' + totalPages + '</button>');
            }

            $pag.append('<button class="button omc-page-btn" data-page="' + (currentPage + 1) + '" ' + (currentPage >= totalPages ? 'disabled' : '') + '>&raquo;</button>');
            $pag.append('<span class="omc-page-info">' + totalItems + ' items</span>');
        },

        updateTabCounts: function(trashCount) {
            var $cells = $('#omc-stats .form-table td strong');
            var unused = $cells.eq(2).text().replace(/,/g, '') || '0';
            var whitelist = $cells.eq(4).text().replace(/,/g, '') || '0';
            $('#omc-unused-count').text('(' + unused + ')');
            $('#omc-whitelist-count').text('(' + whitelist + ')');
            if (typeof trashCount !== 'undefined') {
                $('#omc-trash-count').text('(' + trashCount + ')');
            }
        },

        updateBulkBar: function() {
            // Show/hide relevant bulk buttons based on tab
            var tab = this.currentTab;
            $('#omc-bulk-trash-btn, #omc-bulk-whitelist-btn').toggle(tab === 'unused');
            $('#omc-bulk-remove-whitelist-btn').toggle(tab === 'whitelist');
            $('#omc-bulk-delete-btn, #omc-bulk-restore-btn').toggle(tab === 'trash');
        },

        // ─── Actions ────────────────────────────────────────────────

        trashSingle: function(id, $row) {
            var self = this;
            $row.addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_trash_single',
                nonce: omcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        deleteSingle: function(id, $row) {
            var self = this;
            $row.addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_delete_single',
                nonce: omcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        whitelistSingle: function(id, $row) {
            var self = this;
            $row.addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_whitelist_single',
                nonce: omcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        removeWhitelist: function(id, $row) {
            var self = this;
            $row.addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_remove_whitelist',
                nonce: omcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        restoreSingle: function(id, $row) {
            var self = this;
            $row.addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_restore_single',
                nonce: omcObj.nonce,
                post_id: id
            }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    self.toast(res.data.message, 'success');
                    self.refreshAfterAction();
                } else {
                    $row.removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        trashBulk: function(ids) {
            var self = this;
            $('.omc-item-cb:checked').closest('tr').addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_trash_bulk',
                nonce: omcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.omc-loading').removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        deleteBulk: function(ids) {
            var self = this;
            $('.omc-item-cb:checked').closest('tr').addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_delete_bulk',
                nonce: omcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.omc-loading').removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        whitelistBulk: function(ids) {
            var self = this;
            $('.omc-item-cb:checked').closest('tr').addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_whitelist_bulk',
                nonce: omcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.omc-loading').removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        removeWhitelistBulk: function(ids) {
            var self = this;
            $('.omc-item-cb:checked').closest('tr').addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_remove_whitelist_bulk',
                nonce: omcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.omc-loading').removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        restoreBulk: function(ids) {
            var self = this;
            $('.omc-item-cb:checked').closest('tr').addClass('omc-loading');

            $.post(omcObj.ajaxurl, {
                action: 'omc_restore_bulk',
                nonce: omcObj.nonce,
                ids: ids
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    self.loadResults();
                } else {
                    $('.omc-loading').removeClass('omc-loading');
                    self.toast(res.data, 'error');
                }
            });
        },

        trashAll: function() {
            var self = this;
            var $btn = $('#omc-trash-all-btn');
            var $progress = $('#omc-progress-wrap');
            var $fill = $('#omc-progress-fill');
            var $text = $('#omc-progress-text');
            var totalStart = 0;

            $btn.prop('disabled', true).text('Trashing...');
            $progress.show();
            $fill.css('width', '0%');
            $text.text('Starting...');

            self.trashAllBatch(totalStart);
        },

        trashAllBatch: function(totalStart) {
            var self = this;
            var $fill = $('#omc-progress-fill');
            var $text = $('#omc-progress-text');

            $.post(omcObj.ajaxurl, {
                action: 'omc_trash_all_batch',
                nonce: omcObj.nonce
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
                $('#omc-unused-count').text('(' + res.data.remaining + ')');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text('Done! ' + totalStart + ' files moved to trash.');
                    self.toast(totalStart + ' files moved to trash.', 'success');
                    self.currentPage = 1;
                    self.loadResults();
                    setTimeout(function() {
                        $('#omc-progress-wrap').fadeOut();
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
            $('#omc-trash-all-btn').prop('disabled', false).text('Trash All Unused');
        },

        emptyTrash: function() {
            var self = this;
            var $btn = $('#omc-empty-trash-btn');
            var $progress = $('#omc-progress-wrap');
            var $fill = $('#omc-progress-fill');
            var $text = $('#omc-progress-text');
            var totalStart = 0;

            $btn.prop('disabled', true).text('Deleting...');
            $progress.show();
            $fill.css('width', '0%');
            $text.text('Starting...');

            self.emptyTrashBatch(totalStart);
        },

        emptyTrashBatch: function(totalStart) {
            var self = this;
            var $fill = $('#omc-progress-fill');
            var $text = $('#omc-progress-text');

            $.post(omcObj.ajaxurl, {
                action: 'omc_empty_trash_batch',
                nonce: omcObj.nonce
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
                $('#omc-trash-count').text('(' + res.data.remaining + ')');

                if (res.data.done) {
                    $fill.css('width', '100%');
                    $text.text('Done! ' + totalStart + ' files permanently deleted.');
                    self.toast(totalStart + ' files permanently deleted.', 'success');
                    self.currentPage = 1;
                    self.loadResults();
                    setTimeout(function() {
                        $('#omc-progress-wrap').fadeOut();
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
            $('#omc-empty-trash-btn').prop('disabled', false).text('Empty Trash');
        },

        saveCronSettings: function() {
            var self = this;
            var enabled = $('#omc-cron-enabled').is(':checked');
            var frequency = $('#omc-cron-frequency').val();

            $.post(omcObj.ajaxurl, {
                action: 'omc_save_cron_settings',
                nonce: omcObj.nonce,
                enabled: enabled ? 1 : 0,
                frequency: frequency
            }, function(res) {
                if (res.success) {
                    self.toast(res.data.message, 'success');
                    $('#omc-next-run').text(res.data.next_run);
                } else {
                    self.toast(res.data || 'Error saving settings', 'error');
                }
            });
        },

        // ─── Helpers ────────────────────────────────────────────────

        getSelectedIds: function() {
            var ids = [];
            $('.omc-item-cb:checked').each(function() {
                ids.push(parseInt($(this).val()));
            });
            return ids;
        },

        updateSelectedInfo: function() {
            var count = $('.omc-item-cb:checked').length;
            var totalSize = 0;
            $('.omc-item-cb:checked').each(function() {
                totalSize += parseInt($(this).data('size')) || 0;
            });
            if (count > 0) {
                $('#omc-selected-info').text(count + ' selected (' + this.formatSize(totalSize) + ')');
            } else {
                $('#omc-selected-info').text('');
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
            var $toast = $('<div class="omc-toast ' + type + '">' + message + '</div>');
            $('body').append($toast);
            setTimeout(function() { $toast.addClass('show'); }, 10);
            setTimeout(function() {
                $toast.removeClass('show');
                setTimeout(function() { $toast.remove(); }, 300);
            }, 4000);
        }
    };

    $(document).ready(function() {
        if ($('#omc-scan-btn').length) {
            OMC.init();
        }
    });

})(jQuery);
