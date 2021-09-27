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
    $(document).trigger('o:prepare-value', ['resource', value, accessObject, namePrefix]);

});
