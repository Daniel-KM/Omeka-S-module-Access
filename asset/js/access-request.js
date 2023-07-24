$(document).ready(function() {

    $('.request-access-resource').on('click', function(e) {
        e.preventDefault();
        $('.access-request-form').toggle();
    });

    $('.access-request-form .form-wrapper .form-block .block-title .block-close').on('click', function(e) {
        e.preventDefault();
        $('.access-request-form').hide();
    });

    $('.access-request-form .form-wrapper .form-block .resource-link').on('click', function(e) {
        e.preventDefault();
        const checkbox = $(this).closest('label').find('input[type="checkbox"]');
        checkbox.prop('checked', !checkbox.prop('checked'));
    });

    $('.access-request-form form').on('submit', function(e) {
        e.preventDefault();
        const data = $(this).serializeArray();
        const url = $('.access-request-form').data('uri');

        $.ajax({
            method: "POST",
            url: url,
            data: data,
            dataType: 'json'
        }).done(function( response ) {
            if (response.status === 200) {
                $('.access-request-form').hide();
            }
        });
    });

});
