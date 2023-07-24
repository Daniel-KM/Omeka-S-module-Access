(function($) {

    $(document).ready(function() {

        // Deletion of an access.
        $('#content').on('click', 'a.o-icon-delete', function (e) {
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
                .done(function (data) {
                    button.parent().parent().remove();
                })
                .fail(function (jqXHR, textStatus) {
                    if (jqXHR.status == 404) {
                        alert(Omeka.jsTranslate('The resource or the access doesn’t exist.'));
                    } else {
                        alert(Omeka.jsTranslate('Something went wrong'));
                    }
                })
                .always(function () {
                    button.removeClass('o-icon-transmit').addClass('o-icon-delete');
                });
        });

        // Toggle the status of an access or a request.
        $('#content').on('click', 'a.status-toggle-access-resource', function (e) {
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
                    if (response.status === 200) {
                        status = response.data.status;
                        button.data('status', status);
                    } else {
                        alert(Omeka.jsTranslate('Something went wrong') + ' ' + response.message);
                    }
                })
                .fail(function (jqXHR, textStatus) {
                    if (jqXHR.status == 404) {
                        alert(Omeka.jsTranslate('The resource or the access doesn’t exist.'));
                    } else {
                        alert(Omeka.jsTranslate('Something went wrong'));
                    }
                })
                .always(function () {
                    button.removeClass('o-icon-transmit').addClass('o-icon-' + status);
                });
        });

        // Adapted from resource-form.js.

        $('.button.resource-select').on('click', function(e) {
            e.preventDefault();
            var selectButton = $(this);
            var sidebar = $('#select-resource');
            Omeka.populateSidebarContent(sidebar, selectButton.data('sidebar-content-url'));
            Omeka.openSidebar(sidebar);
        });

        $('#select-item a').on('o:resource-selected', function (e) {
            var value = $('.value.selecting-resource');
            var valueObj = $('.resource-details').data('resource-values');
            $(document).trigger('o:prepare-value', ['resource', value, valueObj]);
            Omeka.closeSidebar($('#select-resource'));
        });

        $(document).on('o:prepare-value', function(e, dataType, value, valueObj) {
            // Prepare simple single-value form inputs using data-value-key
            value.find(':input').each(function () {
                var valueKey = $(this).data('valueKey');
                if (!valueKey) {
                    return;
                }
                $(this).removeAttr('name')
                    .val(valueObj ? valueObj[valueKey] : null);
            });

            // Prepare the markup for the resource data types.
            var resourceDataTypes = [
                'resource',
                'resource:item',
                'resource:itemset',
                'resource:media',
                'resource:annotation',
            ];
            if (valueObj && -1 !== resourceDataTypes.indexOf(dataType)) {
                value.find('span.default').hide();
                var resource = value.find('.selected-resource');
                if (typeof valueObj['display_title'] === 'undefined') {
                    valueObj['display_title'] = Omeka.jsTranslate('[Untitled]');
                }
                resource.find('.o-title')
                    .removeClass() // remove all classes
                    .addClass('o-title ' + valueObj['value_resource_name'])
                    .html($('<a>', {href: valueObj['url'], text: valueObj['display_title']}));
                if (typeof valueObj['thumbnail_url'] !== 'undefined') {
                    resource.find('.o-title')
                        .prepend($('<img>', {src: valueObj['thumbnail_url']}));
                }
                resource.find('.value.to-require').val(valueObj['value_resource_id']);
            }
        });

        // The accessResource is set in the access/edit form.
        //  Should be triggered after preparation above.
        var value = $('form .value-resource');
        var namePrefix = value.data('name-prefix');
        if (typeof accessObject === 'undefined') {
            var accessObject = {};
        }
        $(document).trigger('o:prepare-value', ['resource', value, accessObject, namePrefix]);

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
            const element = $('input[name=accessresource_level_via_property]:checked');
            if (element.val() === 'level') {
                $('#accessresource_level_property').closest('.field').show(300);
                $('#accessresource_level_property_levels').closest('.field').show(300);
                $('#accessresource_hide_in_advanced_tab').closest('.field').show(300);
            } else {
                $('#accessresource_level_property').closest('.field').hide(300);
                $('#accessresource_level_property_levels').closest('.field').hide(300);
                if (!$('input[type=checkbox][name=accessresource_embargo_via_property]').prop('checked')) {
                    $('#accessresource_hide_in_advanced_tab').closest('.field').hide(300);
                }
            }
        }

        const embargoViaProperty = function () {
            const element = $('input[type=checkbox][name=accessresource_embargo_via_property]');
            if (element.prop('checked')) {
                $('#accessresource_embargo_property_start').closest('.field').show(300);
                $('#accessresource_embargo_property_end').closest('.field').show(300);
                $('#accessresource_hide_in_advanced_tab').closest('.field').show(300);
            } else {
                $('#accessresource_embargo_property_start').closest('.field').hide(300);
                $('#accessresource_embargo_property_end').closest('.field').hide(300);
                if ($('input[name=accessresource_level_via_property]:checked').val() !== 'level') {
                    $('#accessresource_hide_in_advanced_tab').closest('.field').hide(300);
                }
            }
        }

        $('input[name="accessresource_access_modes[]"][value=ip]').on('click', modeIp);
        $('input[name=accessresource_level_via_property]').on('click', accessViaProperty);
        $('input[type=checkbox][name=accessresource_embargo_via_property]').on('click', embargoViaProperty);

        modeIp();
        accessViaProperty();
        embargoViaProperty();

    });

})(jQuery);
