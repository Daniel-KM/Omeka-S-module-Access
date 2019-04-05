$(document).ready(function()
{

    $('.request-access-resource').click(function(e) {
        e.preventDefault();
        $('.request-resource-access-form').toggle();
    });

    $('.request-resource-access-form .form-wrapper .form-block .block-title .block-close').click(function(e) {
        e.preventDefault();
        $('.request-resource-access-form').hide();
    });

    $('.request-resource-access-form .form-wrapper .form-block .resource-link').click(function(e) {
        e.preventDefault();
        const checkbox = $(this).closest('label').find('input[type="checkbox"]');
        checkbox.prop('checked',!checkbox.prop('checked'));
    });

    $('.request-resource-access-form form').submit(function(e) {
        e.preventDefault();
        const data = $(this).serializeArray();
        const url = $('.request-resource-access-form').data('uri');

        $.ajax({
            method: "POST",
            url: url,
            data: data,
            dataType: 'json'
        }).done(function( response ) {
            if (response.success) {
                $('.request-resource-access-form').hide();
            }
        });

    });
});