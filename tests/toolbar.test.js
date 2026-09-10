'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const adminJs = fs.readFileSync(path.join(__dirname, '../assets/js/admin.js'), 'utf8');
const jquerySrc = fs.readFileSync(require.resolve('jquery/dist/jquery.js'), 'utf8');

const unusedIds = ['olimc-bulk-trash-btn', 'olimc-bulk-whitelist-btn', 'olimc-trash-all-btn'];
const whitelistIds = ['olimc-bulk-remove-whitelist-btn'];
const trashIds = ['olimc-bulk-restore-btn', 'olimc-bulk-delete-btn', 'olimc-empty-trash-btn'];

function toolbarHtml(activeTab) {
    const hidden = (tab) => (tab === activeTab ? '' : ' style="display:none;"');
    return `
        <div class="wrap">
            <button type="button" id="olimc-scan-btn">Scan for Unused Media</button>
            <nav class="nav-tab-wrapper">
                <a href="upload.php?page=oli-media-cleaner&tab=unused" class="nav-tab ${activeTab === 'unused' ? 'nav-tab-active' : ''}">Unused</a>
                <a href="upload.php?page=oli-media-cleaner&tab=whitelist" class="nav-tab ${activeTab === 'whitelist' ? 'nav-tab-active' : ''}">Whitelist</a>
                <a href="upload.php?page=oli-media-cleaner&tab=trash" class="nav-tab ${activeTab === 'trash' ? 'nav-tab-active' : ''}">Trash</a>
            </nav>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <button type="button" class="button olimc-tab-unused" id="olimc-bulk-trash-btn"${hidden('unused')}>Trash Selected</button>
                    <button type="button" class="button olimc-tab-unused" id="olimc-bulk-whitelist-btn"${hidden('unused')}>Whitelist Selected</button>
                    <button type="button" class="button olimc-tab-unused" id="olimc-trash-all-btn"${hidden('unused')}>Trash All Unused</button>
                    <button type="button" class="button olimc-tab-whitelist" id="olimc-bulk-remove-whitelist-btn"${hidden('whitelist')}>Remove from Whitelist</button>
                    <button type="button" class="button olimc-tab-trash" id="olimc-bulk-restore-btn"${hidden('trash')}>Restore Selected</button>
                    <button type="button" class="button olimc-tab-trash" id="olimc-bulk-delete-btn"${hidden('trash')}>Delete Permanently</button>
                    <button type="button" class="button olimc-tab-trash" id="olimc-empty-trash-btn"${hidden('trash')}>Empty Trash</button>
                    <span id="olimc-selected-info"></span>
                </div>
                <div class="alignright">
                    <select id="olimc-filter-type"><option value="">All Types</option></select>
                    <input type="search" id="olimc-search">
                    <button type="button" id="olimc-search-btn">Search</button>
                </div>
            </div>
            <div id="olimc-results"></div>
            <div id="olimc-stats"><table class="form-table"><td><strong>0</strong></td><td><strong>0</strong></td><td><strong>0</strong></td><td><strong>0</strong></td><td><strong>0</strong></td></table></div>
            <div id="olimc-pagination"></div>
            <select id="olimc-per-page"><option value="20">20</option></select>
        </div>
    `;
}

function isVisible(el) {
    if (!el) return false;
    const style = el.ownerDocument.defaultView.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') return false;
    if (el.style.display === 'none') return false;
    return true;
}

function assertVisible(ids, expected, label) {
    const missing = ids.filter((id) => {
        const el = global.document.getElementById(id);
        return isVisible(el) !== expected;
    });
    if (missing.length) {
        throw new Error(
            `${label}: expected ${expected ? 'visible' : 'hidden'} but failed for ${missing.join(', ')}`
        );
    }
}

function mockAjax($) {
    $.post = function (url, data, callback) {
        const res = {
            success: true,
            data: {
                html: '<table class="widefat"><tbody></tbody></table>',
                stats: '<table class="form-table"><td><strong>0</strong></td><td><strong>0</strong></td><td><strong>1</strong></td><td><strong>0</strong></td><td><strong>2</strong></td></table>',
                total_pages: 0,
                total_items: 0,
                trash_count: 3,
            },
        };
        if (typeof callback === 'function') {
            callback(res);
        }
        return { fail() { return this; } };
    };
}

async function boot(activeTab) {
    const dom = new JSDOM(`<!DOCTYPE html><html><body>${toolbarHtml(activeTab)}</body></html>`, {
        url: `https://example.test/wp-admin/upload.php?page=oli-media-cleaner&tab=${activeTab}`,
        runScripts: 'dangerously',
        pretendToBeVisual: true,
    });

    global.window = dom.window;
    global.document = dom.window.document;

    dom.window.eval(jquerySrc);
    const $ = dom.window.jQuery;
    global.jQuery = $;
    global.$ = $;

    mockAjax($);
    dom.window.olimcObj = {
        ajaxurl: '/wp-admin/admin-ajax.php',
        nonce: 'test',
        strings: {
            scanning: 'Scanning...',
            scan_complete: 'Scan complete!',
            confirm_trash: 'Trash this file?',
            confirm_delete: 'Permanently delete this file?',
            confirm_bulk_trash: 'Trash all selected files?',
            confirm_bulk_delete: 'Permanently delete all selected files?',
            no_selection: 'No files selected.',
            confirm_trash_all: 'Trash ALL unused images?',
            confirm_empty_trash: 'Permanently delete ALL trashed files?',
        },
    };

    dom.window.eval(adminJs);
    await new Promise((resolve) => $(resolve));
    return { dom, $ };
}

function clickTab($, tab) {
    const href = `upload.php?page=oli-media-cleaner&tab=${tab}`;
    const $link = $(`.nav-tab[href="${href}"]`);
    if (!$link.length) {
        throw new Error(`Tab link not found for ${tab}`);
    }
    $link.trigger('click');
}

async function run() {
    let failed = 0;
    const cases = [];

    function test(name, fn) {
        cases.push({ name, fn });
    }

    test('starts on Unused with unused actions visible', async () => {
        await boot('unused');
        assertVisible(unusedIds, true, 'unused tab / unused actions');
        assertVisible(whitelistIds, false, 'unused tab / whitelist actions');
        assertVisible(trashIds, false, 'unused tab / trash actions');
    });

    test('switching Unused → Trash shows restore, delete, and empty trash', async () => {
        const { $ } = await boot('unused');
        clickTab($, 'trash');
        assertVisible(unusedIds, false, 'after switch to trash / unused actions');
        assertVisible(whitelistIds, false, 'after switch to trash / whitelist actions');
        assertVisible(trashIds, true, 'after switch to trash / trash actions');
    });

    test('switching Unused → Whitelist shows remove-from-whitelist only', async () => {
        const { $ } = await boot('unused');
        clickTab($, 'whitelist');
        assertVisible(unusedIds, false, 'after switch to whitelist / unused actions');
        assertVisible(whitelistIds, true, 'after switch to whitelist / whitelist actions');
        assertVisible(trashIds, false, 'after switch to whitelist / trash actions');
    });

    test('switching Trash → Unused restores unused actions', async () => {
        const { $ } = await boot('unused');
        clickTab($, 'trash');
        clickTab($, 'unused');
        assertVisible(unusedIds, true, 'back to unused / unused actions');
        assertVisible(whitelistIds, false, 'back to unused / whitelist actions');
        assertVisible(trashIds, false, 'back to unused / trash actions');
    });

    test('direct load of Trash tab shows trash actions', async () => {
        await boot('trash');
        assertVisible(unusedIds, false, 'direct trash / unused actions');
        assertVisible(whitelistIds, false, 'direct trash / whitelist actions');
        assertVisible(trashIds, true, 'direct trash / trash actions');
    });

    test('admin page always outputs every bulk action button', () => {
        const php = fs.readFileSync(path.join(__dirname, '../includes/class-admin.php'), 'utf8');
        const ids = unusedIds.concat(whitelistIds, trashIds);
        for (const id of ids) {
            if (!php.includes(`id="${id}"`)) {
                throw new Error(`class-admin.php is missing button id="${id}"`);
            }
        }
        if (php.includes("if ($tab === 'unused'):")) {
            throw new Error('toolbar is still gated on $tab, so AJAX tab switches cannot reveal other actions');
        }
    });

    for (const { name, fn } of cases) {
        try {
            await fn();
            console.log(`ok  - ${name}`);
        } catch (err) {
            failed += 1;
            console.error(`fail - ${name}`);
            console.error(`      ${err.message}`);
        }
    }

    if (failed) {
        console.error(`\n${failed} test(s) failed`);
        process.exit(1);
    }

    console.log(`\n${cases.length} tests passed`);
}

run();
