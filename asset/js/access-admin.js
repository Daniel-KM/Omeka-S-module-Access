(function($) {

    $(document).ready(function() {

        const jsendFail = function(response, textStatus) {
            if (!response || (!response.message && !response.data)) {
                alert(Omeka.jsTranslate('Something went wrong' + ': ' + textStatus));
            } else if (response.message) {
                alert(response.message);
            } else if (response.data) {
                var msg = '';
                Object.values(response.data).forEach(value => {
                    if (value && typeof value === 'object') {
                        Object.values(value).forEach(val => {
                            if (val && typeof val === 'object') {
                                Object.values(val).forEach(va => {
                                    if (va && typeof va === 'object') {
                                        Object.values(va).forEach(v => {
                                            msg += "\n" + v;
                                        });
                                    } else {
                                        msg += "\n" + va;
                                    }
                                });
                            } else {
                                msg += "\n" + val;
                            }
                        });
                    } else {
                        msg += "\n" + value;
                    }
                });
                msg = msg.trim();
                alert(msg.length ? msg : Omeka.jsTranslate('Something went wrong'));
            }
        }

        // Direct deletion of an access.
        $('#content').on('click', 'body.show a.o-icon-delete', function (e) {
            e.preventDefault();

            var button = $(this);
            var url = button.data('status-toggle-url');
            $.ajax(
                {
                    url: url,
                    method: "POST",
                    beforeSend: function () {
                        button.removeClass('o-icon-delete').addClass('o-icon-transmit');
                    }
                })
                .done(function (response) {
                    button.parent().parent().remove();
                    if (response.message) {
                        alert(response.message);
                    }
                })
                .fail(function (jqXHR, textStatus) {
                    jsendFail(jqXHR.responseJSON, textStatus);
                })
                .always(function () {
                    button.removeClass('o-icon-transmit').addClass('o-icon-delete');
                });
        });

        // Toggle the status of an access or a request.
        $('#content').on('click', 'a.status-toggle-access-request', function (e) {
            e.preventDefault();

            var button = $(this);
            var url = button.data('status-toggle-url');
            var status = button.data('status');
            $.ajax(
                {
                    url: url,
                    method: "POST",
                    beforeSend: function () {
                        button.removeClass('o-icon-' + status).addClass('o-icon-transmit');
                    }
                })
                .done(function (response) {
                    status = response.data.access_request['o:status'];
                    button.data('status', status);
                    if (response.message) {
                        alert(response.message);
                    }
                })
                .fail(function (jqXHR, textStatus) {
                    jsendFail(jqXHR.responseJSON, textStatus);
                })
                .always(function () {
                    button.removeClass('o-icon-transmit').addClass('o-icon-' + status);
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
            if ($('input[name="accessresource[embargo_start_update]"]:checked').val() === 'set') {
                $('#accessresource_embargo_start_date').closest('.field').show(300);
            } else {
                $('#accessresource_embargo_start_date').closest('.field').hide(300);
            }
        }

        const endDate = function () {
            if ($('input[name="accessresource[embargo_end_update]"]:checked').val() === 'set') {
                $('#accessresource_embargo_end_date').closest('.field').show(300);
            } else {
                $('#accessresource_embargo_end_date').closest('.field').hide(300);
            }
        }

        $('.accessresource').closest('.field')
            .wrapAll('<fieldset id="accessresource" class="field-container">');
        $('#accessresource')
            .prepend('<legend>' + Omeka.jsTranslate('Access resources') + '</legend>');
        var removeField = $('#accessresource_embargo_start_time').closest('.field');
        $('#accessresource_embargo_start_date')
            .after($('#accessresource_embargo_start_time'));
        removeField.remove();
        removeField = $('#accessresource_embargo_end_time').closest('.field');
        $('#accessresource_embargo_end_date')
            .after($('#accessresource_embargo_end_time'));
        removeField.remove();
        $('input[name="accessresource[embargo_start_update]"]').on('click', startDate);
        $('input[name="accessresource[embargo_end_update]"]').on('click', endDate);

        startDate();
        endDate();

        // Config form.

        const modeIp = function() {
            const element = $('input[name="accessresource_access_modes[]"][value=ip]');
            if (element.prop('checked')) {
                $('#accessresource_ip_item_sets').closest('.field').show(300);
            } else {
                $('#accessresource_ip_item_sets').closest('.field').hide(300);
            }
        }

        const accessViaProperty = function() {
            const element = $('input[type=checkbox][name=accessresource_property]');
            if (element.prop('checked')) {
                $('.accessresource-property').closest('.field').show(300);
            } else {
                $('.accessresource-property').closest('.field').hide(300);
            }
        }

        $('input[name="accessresource_access_modes[]"][value=ip]').on('click', modeIp);
        $('input[name=accessresource_property]').on('click', accessViaProperty);

        modeIp();
        accessViaProperty();

    });

})(jQuery);
