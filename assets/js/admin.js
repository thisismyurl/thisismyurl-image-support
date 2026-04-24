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
});
