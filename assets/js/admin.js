jQuery(function($) {
    const config = window.TIMUImageSupportData || {};
    const pendingIds = Array.isArray(config.pendingIds) ? config.pendingIds.slice() : [];
    const strings = config.strings || {};
    const ajaxUrl = config.ajaxUrl || window.ajaxurl;
    const nonce = config.nonce || '';
    const action = config.action || '';
    const repairAction = config.repairAction || '';
    const seoAction = config.seoAction || '';
    const seoIds = Array.isArray(config.seoIds) ? config.seoIds.slice() : [];
    const batchSize = 25;

    let isCancelled = false;
    let completed = 0;
    let hasRunSync = false;
    const defaultStartText = $('#timu-ic-start').text();
    const defaultDryText = $('#timu-ic-dry-run').text();

    const updateProgress = (total) => {
        const pct = total > 0 ? Math.round((completed / total) * 100) : 100;
        $('#timu-ic-progress-bar').css('width', pct + '%');
        $('#timu-ic-progress-text').text(pct + '%');
    };

    const appendReport = (text) => {
        const $report = $('#timu-ic-report');
        const current = $report.html();
        $report.html(current + '<div style="padding:4px 0;">' + $('<div/>').text(text).html() + '</div>');
        $report.show();
    };

    const runMode = (dryRun) => {
        if (!pendingIds.length) {
            $('#timu-ic-report')
                .html('<div style="padding:4px 0;"><strong>No pending images.</strong> You can rerun this tool anytime; there are currently no updates to apply.</div>')
                .show();
            return;
        }

        const total = pendingIds.length;
        const queue = pendingIds.slice();

        isCancelled = false;
        completed = 0;
        hasRunSync = false;

        $('#timu-ic-report').hide().empty();
        $('#timu-ic-progress-container').show();
        $('#timu-ic-start').prop('disabled', true).text(dryRun ? defaultStartText : (strings.processing || 'Processing...'));
        $('#timu-ic-dry-run').prop('disabled', true).text(dryRun ? (strings.previewing || 'Previewing...') : defaultDryText);
        $('#timu-ic-cancel').show();

        updateProgress(total);

        const processNextBatch = () => {
            if (isCancelled || !queue.length) {
                $('#timu-ic-cancel').hide();
                $('#timu-ic-start').prop('disabled', false).text(defaultStartText);
                $('#timu-ic-dry-run').prop('disabled', false).text(defaultDryText);
                appendReport(strings.completed || 'Batch completed.');
                if (!dryRun && !isCancelled) {
                    window.location.reload();
                }
                return;
            }

            const currentBatch = queue.splice(0, batchSize);
            $.post(ajaxUrl, {
                action: action,
                nonce: nonce,
                attachment_ids: currentBatch,
                dry_run: dryRun ? 1 : 0,
                run_sync: hasRunSync ? 0 : 1
            }).done(function(res) {
                if (!res || !res.success || !res.data) {
                    appendReport('Batch request failed.');
                    isCancelled = true;
                    return;
                }

                hasRunSync = true;

                const sync = Array.isArray(res.data.sync) ? res.data.sync : [];
                sync.forEach(function(item) {
                    const modeVerb = dryRun ? 'Found' : 'Replaced';
                    appendReport(modeVerb + ' ' + item.file + ' in ' + item.dir);
                });

                const results = Array.isArray(res.data.results) ? res.data.results : [];
                results.forEach(function(item) {
                    if (item && item.message) {
                        appendReport(item.message);
                    }
                    if (item && item.id) {
                        completed += 1;
                        if (!dryRun) {
                            $('#fwo-row-' + item.id).remove();
                        }
                    }
                });

                updateProgress(total);
            }).fail(function() {
                appendReport('Request failed while processing batch.');
                isCancelled = true;
            }).always(processNextBatch);
        };

        processNextBatch();
    };

    $('#timu-ic-start').on('click', function() {
        runMode(false);
    });

    $('#timu-ic-dry-run').on('click', function() {
        runMode(true);
    });

    $('#timu-ic-cancel').on('click', function() {
        isCancelled = true;
    });

    $('#timu-ic-repair-links').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).text(strings.repairing || 'Scanning links...');

        $.post(ajaxUrl, {
            action: repairAction,
            nonce: nonce,
            dry_run: 0
        }).done(function(res) {
            if (!res || !res.success || !res.data) {
                appendReport('Broken-link repair failed.');
                return;
            }

            const data = res.data;
            appendReport('Link mappings checked: ' + (data.mappings_checked || 0));
            appendReport('Posts updated: ' + (data.posts_updated || 0));
            appendReport('Replacements made: ' + (data.replacements || 0));

            const notes = Array.isArray(data.notes) ? data.notes : [];
            if (!notes.length) {
                appendReport('No broken image links found.');
            } else {
                notes.forEach(function(note) {
                    appendReport(note);
                });
            }
        }).fail(function() {
            appendReport('Broken-link repair request failed.');
        }).always(function() {
            $btn.prop('disabled', false).text('Repair Broken Content Links');
        });
    });

    $('#timu-ic-reinforce-seo').on('click', function() {
        if (!seoIds.length) {
            appendReport('No image attachments available for ALT/SEO reinforcement.');
            return;
        }

        const queue = seoIds.slice();
        const total = queue.length;
        let seoProcessed = 0;

        $('#timu-ic-report').show();
        $('#timu-ic-progress-container').show();
        $('#timu-ic-reinforce-seo').prop('disabled', true).text(strings.reinforcing || 'Reinforcing SEO...');

        const runNextSeoBatch = () => {
            if (!queue.length) {
                appendReport('ALT/SEO reinforcement complete. Attachments processed: ' + seoProcessed);
                $('#timu-ic-reinforce-seo').prop('disabled', false).text('Reinforce ALT + SEO Metadata');
                updateProgress(total);
                return;
            }

            const batch = queue.splice(0, batchSize);
            $.post(ajaxUrl, {
                action: seoAction,
                nonce: nonce,
                attachment_ids: batch
            }).done(function(res) {
                if (!res || !res.success || !res.data) {
                    appendReport('ALT/SEO batch failed.');
                    return;
                }

                const processed = Array.isArray(res.data.processed_ids) ? res.data.processed_ids : [];
                seoProcessed += processed.length;
                completed = seoProcessed;
                updateProgress(total);
            }).fail(function() {
                appendReport('ALT/SEO reinforcement request failed.');
            }).always(runNextSeoBatch);
        };

        runNextSeoBatch();
    });

    // -------------------------------------------------------------------------
    // Audit tab — metadata curation (alt editing + title/caption/description
    // normalisation). These blocks no-op unless their markup is on the page.
    // -------------------------------------------------------------------------

    const curationActions = config.actions || {};
    const curationStrings = config.strings || {};

    const notice = (selector, type, message) => {
        $(selector).html(
            '<div class="notice notice-' + type + ' inline"><p>' +
            $('<div/>').text(message).html() +
            '</p></div>'
        );
    };

    // --- Inline alt editing ---------------------------------------------------

    if ($('#audit-alt-fix').length) {
        $('#timu-alt-check-all').on('change', function () {
            $('.timu-alt-cb').prop('checked', this.checked);
        });

        $('#timu-alt-source').on('change', function () {
            $('#timu-alt-template').toggle(this.value === 'template');
        });

        // Inline single-row save on blur or Enter.
        const saveAltRow = ($input) => {
            const id = parseInt($input.data('id'), 10);
            const $status = $input.closest('td').find('.timu-alt-status');
            $status.css('color', '#646970').text(curationStrings.saving || 'Saving…');

            $.post(ajaxUrl, {
                action: curationActions.saveAlt,
                nonce: nonce,
                attachment_id: id,
                alt: $input.val()
            }).done(function (res) {
                if (res && res.success) {
                    $status.css('color', '#008a20').text(curationStrings.saved || 'Saved');
                } else {
                    $status.css('color', '#d63638').text(curationStrings.saveFailed || 'Save failed');
                }
            }).fail(function () {
                $status.css('color', '#d63638').text(curationStrings.saveFailed || 'Save failed');
            });
        };

        $('#audit-alt-table').on('blur', '.timu-alt-input', function () {
            if ($(this).val().trim() !== '') {
                saveAltRow($(this));
            }
        });
        $('#audit-alt-table').on('keydown', '.timu-alt-input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $(this).trigger('blur');
            }
        });

        // Bulk fill (selected rows or every row on the page).
        const bulkFill = (ids, $btn) => {
            if (!ids.length) {
                notice('#timu-alt-result', 'warning', curationStrings.selectAtLeast || 'Select at least one image first.');
                return;
            }
            const source = $('#timu-alt-source').val();
            const template = $('#timu-alt-template').val() || '';
            const original = $btn.text();
            $btn.prop('disabled', true).text(curationStrings.working || 'Working…');

            $.post(ajaxUrl, {
                action: curationActions.bulkFillAlt,
                nonce: $btn.data('nonce'),
                attachment_ids: ids,
                source: source,
                template: template
            }).done(function (res) {
                if (res && res.success && res.data) {
                    notice('#timu-alt-result', 'success',
                        'Filled ' + res.data.filled + ', skipped ' + res.data.skipped +
                        ' of ' + res.data.processed + ' images.');
                    setTimeout(function () { window.location.reload(); }, 1200);
                } else {
                    notice('#timu-alt-result', 'error', curationStrings.requestFailed || 'Request failed.');
                }
            }).fail(function () {
                notice('#timu-alt-result', 'error', curationStrings.requestFailed || 'Request failed.');
            }).always(function () {
                $btn.prop('disabled', false).text(original);
            });
        };

        $('#timu-alt-fill-selected').on('click', function () {
            const ids = $('.timu-alt-cb:checked').map(function () {
                return parseInt(this.value, 10);
            }).get();
            bulkFill(ids, $(this));
        });

        $('#timu-alt-fill-all').on('click', function () {
            const ids = $('.timu-alt-cb').map(function () {
                return parseInt(this.value, 10);
            }).get();
            bulkFill(ids, $(this));
        });
    }

    // --- Title / caption / description normalisation --------------------------

    if ($('#audit-normalize').length) {
        $('#timu-norm-check-all').on('change', function () {
            $('.timu-norm-cb').prop('checked', this.checked);
        });

        const collectNormOpts = () => ({
            do_title: $('#timu-norm-do-title').is(':checked') ? 1 : 0,
            do_caption: $('#timu-norm-do-caption').is(':checked') ? 1 : 0,
            do_description: $('#timu-norm-do-description').is(':checked') ? 1 : 0,
            title_template: $('#timu-norm-title-template').val() || '',
            caption_template: $('#timu-norm-caption-template').val() || '',
            description_template: $('#timu-norm-description-template').val() || ''
        });

        const selectedNormIds = () => $('.timu-norm-cb:checked').map(function () {
            return parseInt(this.value, 10);
        }).get();

        const renderPreviewTable = (previews) => {
            if (!previews.length) {
                notice('#timu-norm-result', 'warning', curationStrings.noChanges || 'No changes to apply for the selected images.');
                $('#timu-norm-apply').prop('disabled', true);
                return;
            }
            let html = '<table class="widefat striped" style="margin-top:10px;"><thead><tr>' +
                '<th>ID</th><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
            previews.forEach(function (p) {
                ['title', 'caption', 'description'].forEach(function (field) {
                    if (p[field] && p[field].change) {
                        html += '<tr><td>#' + p.id + '</td><td>' + field + '</td>' +
                            '<td>' + $('<div/>').text(p[field].from || '(empty)').html() + '</td>' +
                            '<td><strong>' + $('<div/>').text(p[field].to || '(empty)').html() + '</strong></td></tr>';
                    }
                });
            });
            html += '</tbody></table>';
            $('#timu-norm-result').html(
                '<div class="notice notice-info inline"><p>' +
                'Previewing ' + previews.length + ' image(s) with changes. Review, then apply.' +
                '</p></div>' + html
            );
            $('#timu-norm-apply').prop('disabled', false);
        };

        $('#timu-norm-preview').on('click', function () {
            const ids = selectedNormIds();
            if (!ids.length) {
                notice('#timu-norm-result', 'warning', curationStrings.selectAtLeast || 'Select at least one image first.');
                return;
            }
            const $btn = $(this);
            const original = $btn.text();
            $btn.prop('disabled', true).text(curationStrings.working || 'Working…');

            $.post(ajaxUrl, $.extend({
                action: curationActions.normalizePreview,
                nonce: $btn.data('nonce'),
                attachment_ids: ids
            }, collectNormOpts())).done(function (res) {
                if (res && res.success && res.data) {
                    renderPreviewTable(res.data.previews || []);
                } else {
                    notice('#timu-norm-result', 'error', curationStrings.requestFailed || 'Request failed.');
                }
            }).fail(function () {
                notice('#timu-norm-result', 'error', curationStrings.requestFailed || 'Request failed.');
            }).always(function () {
                $btn.prop('disabled', false).text(original);
            });
        });

        $('#timu-norm-apply').on('click', function () {
            const ids = selectedNormIds();
            if (!ids.length) {
                notice('#timu-norm-result', 'warning', curationStrings.selectAtLeast || 'Select at least one image first.');
                return;
            }
            const $btn = $(this);
            const original = $btn.text();
            $btn.prop('disabled', true).text(curationStrings.working || 'Working…');

            $.post(ajaxUrl, $.extend({
                action: curationActions.normalizeApply,
                nonce: $btn.data('nonce'),
                attachment_ids: ids
            }, collectNormOpts())).done(function (res) {
                if (res && res.success && res.data) {
                    notice('#timu-norm-result', 'success',
                        'Updated ' + res.data.changed + ' of ' + res.data.processed + ' images.');
                    setTimeout(function () { window.location.reload(); }, 1400);
                } else {
                    notice('#timu-norm-result', 'error', curationStrings.requestFailed || 'Request failed.');
                }
            }).fail(function () {
                notice('#timu-norm-result', 'error', curationStrings.requestFailed || 'Request failed.');
            }).always(function () {
                $btn.prop('disabled', false).text(original);
            });
        });
    }

    // -------------------------------------------------------------------------
    // Optimize tab — session-level undo. Reverses a recorded cleanup run as a
    // unit. No-ops unless the Recent runs panel is on the page.
    // -------------------------------------------------------------------------

    if ($('#timu-recent-runs').length) {
        $('#timu-recent-runs').on('click', '.timu-undo-run', function () {
            const $btn = $(this);
            const runId = $btn.data('run');
            const confirmMsg = strings.confirmUndoRun ||
                'Undo this cleanup run? Every file, name, and content link it changed will be restored.';

            if (!window.confirm(confirmMsg)) {
                return;
            }

            const original = $btn.text();
            $btn.prop('disabled', true).text(strings.undoing || 'Undoing…');

            $.post(ajaxUrl, {
                action: curationActions.undoRun,
                nonce: $btn.data('nonce'),
                run_id: runId
            }).done(function (res) {
                if (res && res.success && res.data) {
                    let msg = 'Reversed ' + res.data.reversed + ' item(s).';
                    const failures = Array.isArray(res.data.failures) ? res.data.failures : [];
                    if (failures.length) {
                        msg += ' ' + failures.length + ' could not be reversed: ' + failures.join(' ');
                        notice('#timu-undo-result', 'warning', msg);
                    } else {
                        notice('#timu-undo-result', 'success', msg);
                    }
                    $('#timu-run-' + runId).fadeOut();
                    setTimeout(function () { window.location.reload(); }, 2000);
                } else {
                    notice('#timu-undo-result', 'error', (res && res.data) || strings.undoFailed || 'Undo failed.');
                    $btn.prop('disabled', false).text(original);
                }
            }).fail(function () {
                notice('#timu-undo-result', 'error', strings.undoFailed || 'Undo failed.');
                $btn.prop('disabled', false).text(original);
            });
        });
    }
});
