$(document).ready(function() {

    $('.request-access-resource').click(function(e) {
        e.preventDefault();
        $('.access-resource-request-form').toggle();
    });

    $('.access-resource-request-form .form-wrapper .form-block .block-title .block-close').click(function(e) {
        e.preventDefault();
        $('.access-resource-request-form').hide();
    });

    $('.access-resource-request-form .form-wrapper .form-block .resource-link').click(function(e) {
        e.preventDefault();
        const checkbox = $(this).closest('label').find('input[type="checkbox"]');
        checkbox.prop('checked', !checkbox.prop('checked'));
    });

    $('.access-resource-request-form form').submit(function(e) {
        e.preventDefault();
        const data = $(this).serializeArray();
        const url = $('.access-resource-request-form').data('uri');

        $.ajax({
            method: "POST",
            url: url,
            data: data,
            dataType: 'json'
        }).done(function( response ) {
            if (response.success) {
                $('.access-resource-request-form').hide();
            }
        });
    });

});
