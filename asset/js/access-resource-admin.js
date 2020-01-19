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

});
