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


        $('.request-access').on('click', function(e) {
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

            $.ajax(
                {
                    method: "POST",
                    url: url,
                    data: data,
                    dataType: 'json'
                })
                .done(function (response) {
                    $('.access-request-form').hide();
                    if (response.message) {
                        alert(response.message);
                    }
                })
                .fail(function (jqXHR, textStatus) {
                    jsendFail(jqXHR.responseJSON, textStatus);
                });
        });

    });

})(jQuery);
