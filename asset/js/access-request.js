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
            // Add the class to trigger jSend automatically (no preventDefault()).
            $(this).addClass('form-jsend');
        });

    });

})(jQuery);
