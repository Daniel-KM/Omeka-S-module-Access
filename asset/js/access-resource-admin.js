$(document).ready(function() {

    /* Tagging a resource. */

// Add the selected tag to the edit panel.
    $('#tag-selector .selector-child').click(function (event) {
        event.preventDefault();

        $('#resource-tags').removeClass('empty');
        var tagName = $(this).data('child-search');

        if ($('#resource-tags').find('input[value="' + tagName.replace(/"/g, '\"').replace(/'/g, "\'") + '"]').length) {
            return;
        }

        var row = $($('#tag-template').data('template'));
        row.children('td.tag-name').text(tagName);
        row.find('td > input').val(tagName);
        $('#resource-tags > tbody:last').append(row);
    });

// Remove a tag from the edit panel.
// $('#content').on('click', '.o-icon-delete', function(event) {
//     event.preventDefault();
//
//     var button = $(this);
//     var url = button.data('status-toggle-url');
//     var removeLink = $(this);
//     var tagRow = $(this).closest('tr');
//     var tagInput = removeLink.closest('td').find('input');
//     tagInput.prop('disabled', true);
//
//     // Undo remove tag link.
//     var undoRemoveLink = $('<a>', {
//         href: '#',
//         class: 'fa fa-undo',
//         title: Omeka.jsTranslate('Undo remove tag'),
//         click: function(event) {
//             event.preventDefault();
//             tagRow.toggleClass('delete');
//             tagInput.prop('disabled', false);
//             removeLink.show();
//             $(this).remove();
//         },
//     });
//
//     tagRow.toggleClass('delete');
//     undoRemoveLink.insertAfter(removeLink);
//     removeLink.hide();
// });


    $('#content').on('click', 'a.o-icon-delete', function (e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('status-toggle-url');
        $.ajax({
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
                    alert(Omeka.jsTranslate('The resource or the tag doesn’t exist.'));
                } else {
                    alert(Omeka.jsTranslate('Something went wrong'));
                }
            })
            .always(function () {
                button.removeClass('o-icon-transmit').addClass('o-icon-delete');
            });
    });


    /* Update taggings. */

// Toggle the status of a tagging.
    $('#content').on('click', 'a.status-toggle-access-resource', function (e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('status-toggle-url');
        var status = button.data('status');
        $.ajax({
            url: url,
            method: "POST",
            beforeSend: function () {
                button.removeClass('o-icon-' + status).addClass('o-icon-transmit');
            }
        })
            .done(function (data) {
                if (!data.content) {
                    alert(Omeka.jsTranslate('Something went wrong'));
                } else {
                    status = data.content.status;
                    button.data('status', status);
                }
            })
            .fail(function (jqXHR, textStatus) {
                if (jqXHR.status == 404) {
                    alert(Omeka.jsTranslate('The resource or the tag doesn’t exist.'));
                } else {
                    alert(Omeka.jsTranslate('Something went wrong'));
                }
            })
            .always(function () {
                button.removeClass('o-icon-transmit').addClass('o-icon-' + status);
            });
    });

});