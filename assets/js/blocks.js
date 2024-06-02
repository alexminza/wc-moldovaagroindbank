const maib_settings = window.wc.wcSettings.getSetting('moldovaagroindbank_data', {});
const maib_title = window.wp.htmlEntities.decodeEntities(maib_settings.title);

const Content = () => {
    return window.wp.htmlEntities.decodeEntities(maib_settings.description || '');
};

const Label = () => {
    let icon = maib_settings.icon
        ? window.wp.element.createElement(
            'img',
            {
                alt: maib_title,
                title: maib_title,
                src: maib_settings.icon,
                style: { float: 'right', paddingRight: '1em' }
            }
        )
        : null;

    let label = window.wp.element.createElement(
        'span',
        icon ? { style: { width: '100%' } } : null,
        maib_title,
        icon
    );

    return label;
};

const maib_Block_Gateway = {
    name: maib_settings.id,
    label: Object(window.wp.element.createElement)(Label, null),
    icons: ['visa', 'mastercard'],
    content: Object(window.wp.element.createElement)(Content, null),
    edit: Object(window.wp.element.createElement)(Content, null),
    canMakePayment: () => true,
    ariaLabel: maib_title,
    supports: {
        features: maib_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(maib_Block_Gateway);
