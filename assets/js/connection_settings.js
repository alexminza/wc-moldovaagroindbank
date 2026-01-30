/* global maib_connection_settings */
jQuery(function ($) {
    var $connectionBasicFields = $(maib_connection_settings.connection_basic_fields_ids).closest('tr');
    var $connectionAdvancedFields = $(maib_connection_settings.connection_advanced_fields_ids).closest('tr');

    $connectionBasicFields.hide();
    $connectionAdvancedFields.hide();

    $(maib_connection_settings.basic_settings_button_id).on('click', function (e) {
        e.preventDefault();
        $connectionAdvancedFields.hide();
        $connectionBasicFields.show();
    });

    $(maib_connection_settings.advanced_settings_button_id).on('click', function (e) {
        e.preventDefault();
        $connectionBasicFields.hide();
        $connectionAdvancedFields.show();
    });
});
