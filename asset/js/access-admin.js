'use strict';

/**
 * Requires common-dialog.js.
 */

(function($) {

    $(document).ready(function() {

        /**
         * Use common-dialog.js.
         *
         * @see Access, Comment, ContactUs, Contribute, Generate, Guest, Resa, SearchHistory, Selection, TwoFactorAuth.
         */

        // Reply to the requester: the dialog is opened by Common
        // (button-dialog-common); just avoid the anchor jumping to the top.
        $('#content').on('click', 'a.reply-message', function(e) {
            e.preventDefault();
        });

        // Close the reply dialog after a successful jSend send.
        document.addEventListener('o:jsend-success', function() {
            var dialog = document.querySelector('dialog.dialog-send-message.dialog-access');
            if (dialog && dialog.open) {
                dialog.close();
            }
        });

        /**
         * Direct deletion of an access.
         *
         * @todo Use CommonDialog.jSend?
         */
        $('#content').on('click', 'body.show a.o-icon-delete', function (e) {
            e.preventDefault();

            var button = $(this);
            var url = button.data('status-toggle-url');
            $.ajax(
                {
                    url: url,
                    method: 'POST',
                    beforeSend: function() {
                        button.removeClass('o-icon-delete');
                        CommonDialog.spinnerEnable(button[0]);
                    },
                })
                .done(function (response) {
                    button.parent().parent().remove();
                    if (response.message) {
                        alert(response.message);
                    }
                })
                .fail(CommonDialog.jSendFail)
                .always(function () {
                    button.addClass('o-icon-delete');
                    CommonDialog.spinnerDisable(button[0]);
                });
        });

        /**
         * Toggle the status of an access or a request.
         *
         * @todo Use CommonDialog.jSend?
         */
        $('#content').on('click', 'a.status-toggle-access-request', function (e) {
            e.preventDefault();

            var button = $(this);
            var url = button.data('status-toggle-url');
            var status = button.data('status');
            $.ajax(
                {
                    url: url,
                    method: 'POST',
                    beforeSend: function() {
                        button.removeClass('o-icon-' + status);
                        CommonDialog.spinnerEnable(button[0]);
                    },
                })
                .done(function (response) {
                    status = response.data.access_request['o:status'];
                    button.data('status', status);
                    if (response.message) {
                        alert(response.message);
                    }
                })
                .fail(CommonDialog.jSendFail)
                .always(function () {
                    button.addClass('o-icon-' + status);
                    CommonDialog.spinnerDisable(button[0]);
                });
        });

        // Improve request form.
        // TODO Create a specific form element.
        var move, field;
        move = $('#o-access-start-time');
        field = move.closest('.field');
        $('#o-access-start-date').after(move);
        field.remove();
        move = $('#o-access-end-time');
        field = move.closest('.field');
        $('#o-access-end-date').after(move);
        field.remove();

        // Batch edit form.

        const startDate = function () {
            if ($('input[name="access[embargo_start_update]"]:checked').val() === 'set') {
                $('#access_embargo_start_date').closest('.field').show(300);
            } else {
                $('#access_embargo_start_date').closest('.field').hide(300);
            }
        }

        const endDate = function () {
            if ($('input[name="access[embargo_end_update]"]:checked').val() === 'set') {
                $('#access_embargo_end_date').closest('.field').show(300);
            } else {
                $('#access_embargo_end_date').closest('.field').hide(300);
            }
        }

        $('.access').closest('.field')
            .wrapAll('<fieldset id="access" class="field-container">');
        $('#access')
            .prepend('<legend>' + Omeka.jsTranslate('Access') + '</legend>');
        var removeField = $('#access_embargo_start_time').closest('.field');
        $('#access_embargo_start_date')
            .after($('#access_embargo_start_time'));
        removeField.remove();
        removeField = $('#access_embargo_end_time').closest('.field');
        $('#access_embargo_end_date')
            .after($('#access_embargo_end_time'));
        removeField.remove();
        $('input[name="access[embargo_start_update]"]').on('click', startDate);
        $('input[name="access[embargo_end_update]"]').on('click', endDate);

        startDate();
        endDate();

        // Config form.

        const modeIp = function() {
            const element = $('input[name="access_modes[]"][value=ip]');
            const $rules = $('#access_ip_rules').closest('.field');
            if (element.prop('checked')) {
                $('#access_ip_proxy_trusted').closest('.field').show(300);
                $rules.show(300);
            } else {
                $('#access_ip_proxy_trusted').closest('.field').hide(300);
                $rules.hide(300);
            }
        }

        const modeAuthSsoIdp = function() {
            const element = $('input[name="access_modes[]"][value=auth_sso_idp]');
            const $rules = $('#access_auth_sso_idp_rules').closest('.field');
            if (element.prop('checked')) {
                $rules.show(300);
            } else {
                $rules.hide(300);
            }
        }

        const modeEmailRegex = function() {
            const element = $('input[name="access_modes[]"][value=email_regex]');
            if (element.prop('checked')) {
                $('#access_email_regex').closest('.field').show(300);
            } else {
                $('#access_email_regex').closest('.field').hide(300);
            }
        }

        const accessViaProperty = function() {
            const value = $('input[name=access_property]:checked').val();
            if (value === '1') {
                $('.access-property').closest('.field').show(300);
            } else {
                $('.access-property').closest('.field').hide(300);
            }
        }

        $('input[name="access_modes[]"][value=ip]').on('click', modeIp);
        $('input[name="access_modes[]"][value=auth_sso_idp]').on('click', modeAuthSsoIdp);
        $('input[name="access_modes[]"][value=email_regex]').on('click', modeEmailRegex);
        $('input[name=access_property]').on('click', accessViaProperty);

        modeIp();
        modeAuthSsoIdp();
        modeEmailRegex();
        accessViaProperty();

        /**
         * Access-scope rule collections (ip and sso idp): add / remove rows,
         * cloning the Laminas collection template (placeholder "__index__") and
         * re-initializing chosen selects on new rows.
         */
        const hasChosen = typeof $.fn.chosen !== 'undefined';
        const chosenOptions = { allow_single_deselect: true, disable_search_threshold: 8, width: '100%' };

        const initChosen = function($scope) {
            if (hasChosen) {
                $scope.find('.chosen-select').chosen(chosenOptions);
            }
        };

        // Per-rule toolbar: copy / paste the collections and remove the rule.
        // Text-only buttons (no icons) for consistency.
        const ruleButtonsHtml = '<div class="access-scope-buttons">'
            + '<button type="button" class="access-scope-copy button" aria-label="' + Omeka.jsTranslate('Copy item sets') + '">'
            + Omeka.jsTranslate('Copy item sets') + '</button>'
            + '<button type="button" class="access-scope-paste button" aria-label="' + Omeka.jsTranslate('Paste item sets') + '" disabled="disabled">'
            + Omeka.jsTranslate('Paste item sets') + '</button>'
            + '<button type="button" class="access-scope-remove button" aria-label="' + Omeka.jsTranslate('Remove') + '">'
            + Omeka.jsTranslate('Remove') + '</button>'
            + '</div>';

        // Clipboard of copied collections, shared across all rules.
        let scopeClipboard = null;

        const addRuleButtons = function($scope) {
            $scope.filter('fieldset').add($scope.find('> fieldset > fieldset')).each(function() {
                const $rule = $(this);
                if ($rule.attr('name') && !$rule.find('> .access-scope-buttons').length) {
                    $rule.append(ruleButtonsHtml);
                }
            });
            refreshPasteButtons();
        };

        const refreshPasteButtons = function() {
            $('.access-scope-paste').prop('disabled', scopeClipboard === null);
        };

        // Append a new empty rule (cloned from the collection template) and
        // return it, or null when there is no template.
        const addRow = function($collection) {
            const $wrap = $collection.find('> fieldset');
            const $template = $wrap.find('> span[data-template]');
            const template = $template.attr('data-template');
            if (!template) {
                return null;
            }
            let maxIndex = -1;
            $wrap.find('> fieldset').each(function() {
                const index = parseInt(($(this).attr('name') || '').replace(/\D+/g, ''), 10);
                if (!isNaN(index)) {
                    maxIndex = Math.max(maxIndex, index);
                }
            });
            const $new = $(template.split('__index__').join(maxIndex + 1));
            $new.insertBefore($template);
            addRuleButtons($new);
            initChosen($new);
            return $new;
        };

        // Text list view: serialize the rules to the legacy "source = ids"
        // format (an id prefixed with "-" is a forbidden item set), and parse
        // it back, so power users can edit every rule at once in one field. The
        // rule widgets stay the submitted source of truth: the text is synced
        // back into them on toggle and on submit.
        const serializeRules = function($collection) {
            const lines = [];
            $collection.find('> fieldset > fieldset').each(function() {
                const $r = $(this);
                let source = ($r.find('.access-scope-source-manual').val() || '').trim();
                if (!source) {
                    source = ($r.find('.access-scope-source').val() || '').toString().trim();
                }
                const allow = $r.find('.access-scope-allow').val() || [];
                const forbid = $r.find('.access-scope-forbid').val() || [];
                if (!source && !allow.length && !forbid.length) {
                    return;
                }
                const ids = allow.slice();
                forbid.forEach(function(id) { ids.push('-' + id); });
                lines.push(source + (ids.length ? ' = ' + ids.join(' ') : ''));
            });
            return lines.join('\n');
        };

        const parseText = function(text) {
            return (text || '').split('\n').map(function(l) { return l.trim(); })
                .filter(function(l) { return l.length; })
                .map(function(line) {
                    let source = line, idsStr = '';
                    const eq = line.indexOf('=');
                    if (eq >= 0) {
                        source = line.slice(0, eq).trim();
                        idsStr = line.slice(eq + 1);
                    }
                    const ids = (idsStr.match(/-?\d+/g) || []).map(Number);
                    return {
                        source: source,
                        allow: ids.filter(function(n) { return n > 0; }).map(String),
                        forbid: ids.filter(function(n) { return n < 0; }).map(function(n) { return String(-n); }),
                    };
                });
        };

        const applyRule = function($row, rule) {
            const $sel = $row.find('select.access-scope-source');
            if ($sel.length) {
                if (rule.source && $sel.find('option').filter(function() { return this.value === rule.source; }).length) {
                    $sel.val(rule.source).trigger('chosen:updated');
                } else if (rule.source) {
                    $row.find('.access-scope-source-manual').val(rule.source);
                }
            } else {
                $row.find('input.access-scope-source').val(rule.source);
            }
            $row.find('.access-scope-allow').val(rule.allow).trigger('chosen:updated');
            $row.find('.access-scope-forbid').val(rule.forbid).trigger('chosen:updated');
        };

        // Replace all rows of a collection with the ones parsed from the text.
        const rebuildRows = function($collection, rules) {
            $collection.find('> fieldset > fieldset').remove();
            rules.forEach(function(rule) {
                const $row = addRow($collection);
                if ($row) {
                    applyRule($row, rule);
                }
            });
        };

        const showTextView = function($field) {
            const $collection = $field.find('.access-scope-rules');
            $field.find('.access-scope-textarea').val(serializeRules($collection)).show();
            $collection.hide();
            $field.find('.access-scope-add').hide();
            $field.find('.access-scope-toggle').text(Omeka.jsTranslate('Edit as rules'));
            $field.data('scopeView', 'text');
        };

        const showRuleView = function($field) {
            const $collection = $field.find('.access-scope-rules');
            rebuildRows($collection, parseText($field.find('.access-scope-textarea').val()));
            $field.find('.access-scope-textarea').hide();
            $collection.show();
            $field.find('.access-scope-add').show();
            $field.find('.access-scope-toggle').text(Omeka.jsTranslate('Edit as a text list'));
            $field.data('scopeView', 'rules');
        };

        $('.access-scope-rules').each(function() {
            const $collection = $(this);
            addRuleButtons($collection);
            initChosen($collection);
            // Text list view textarea (hidden), and a footer with the text/rule
            // toggle on the left and "Add a rule" on the far right, on one
            // line. They live in the inputs column, after the rules. The
            // placeholder example depends on the source type (ip or idp).
            $collection.after('<textarea class="access-scope-textarea" rows="6" style="display:none;"'
                + ' placeholder="' + ($collection.attr('data-text-placeholder') || '') + '"></textarea>'
                + '<div class="access-scope-footer">'
                + '<button type="button" class="access-scope-toggle button">'
                + Omeka.jsTranslate('Edit as a text list') + '</button>'
                + '<button type="button" class="access-scope-add button o-icon-add">'
                + Omeka.jsTranslate('Add a rule') + '</button>'
                + '</div>');
            $collection.closest('.access-scope-field').data('scopeView', 'rules');
        });

        $('.access-scope-toggle').on('click', function() {
            const $field = $(this).closest('.access-scope-field');
            if ($field.data('scopeView') === 'text') {
                showRuleView($field);
            } else {
                showTextView($field);
            }
        });

        // On submit, sync any open text view back into the rule widgets so the
        // structured data (and not the stale hidden rows) is submitted.
        $('.access-scope-field').closest('form').on('submit', function() {
            $('.access-scope-field').each(function() {
                const $field = $(this);
                if ($field.data('scopeView') === 'text') {
                    rebuildRows($field.find('.access-scope-rules'), parseText($field.find('.access-scope-textarea').val()));
                }
            });
        });

        $('.access-scope-field').on('click', '.access-scope-add', function() {
            addRow($(this).closest('.access-scope-field').find('.access-scope-rules'));
        });

        $('.access-scope-rules').on('click', '.access-scope-remove', function() {
            $(this).closest('fieldset').remove();
        });

        // Copy this rule's collections (allowed and forbidden) to the shared
        // clipboard; paste them onto another rule to avoid retyping the same
        // list many times.
        $('.access-scope-rules').on('click', '.access-scope-copy', function() {
            const $rule = $(this).closest('fieldset');
            scopeClipboard = {
                allow: $rule.find('.access-scope-allow').val() || [],
                forbid: $rule.find('.access-scope-forbid').val() || [],
            };
            refreshPasteButtons();
        });

        $('.access-scope-rules').on('click', '.access-scope-paste', function() {
            if (scopeClipboard === null) {
                return;
            }
            const $rule = $(this).closest('fieldset');
            $rule.find('.access-scope-allow').val(scopeClipboard.allow).trigger('chosen:updated');
            $rule.find('.access-scope-forbid').val(scopeClipboard.forbid).trigger('chosen:updated');
        });

    });

})(jQuery);
