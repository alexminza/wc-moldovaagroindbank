/* global maib_connection_settings */
jQuery(function () {
    var maib_connection_basic_fields = jQuery(maib_connection_settings.connection_basic_fields_ids).closest("tr");
    var maib_connection_advanced_fields = jQuery(maib_connection_settings.connection_advanced_fields_ids).closest("tr");

    maib_connection_basic_fields.hide();
    maib_connection_advanced_fields.hide();

    jQuery(maib_connection_settings.basic_settings_button_id).on("click", function (e) {
        e.preventDefault();
        maib_connection_advanced_fields.hide();
        maib_connection_basic_fields.show();
    });

    jQuery(maib_connection_settings.advanced_settings_button_id).on("click", function (e) {
        e.preventDefault();
        maib_connection_basic_fields.hide();
        maib_connection_advanced_fields.show();
    });
});
