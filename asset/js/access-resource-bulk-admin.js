(function($) {

    $(document).ready(function() {

        // Batch edit form.

        $('.accessresource').closest('.field')
            .wrapAll('<fieldset id="accessresource" class="field-container">');
        $('#accessresource')
            .prepend('<legend>' + Omeka.jsTranslate('Access resource') + '</legend>');

    });

})(jQuery);
