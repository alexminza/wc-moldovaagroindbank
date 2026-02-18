const maib_settings = window.wc.wcSettings.getSetting('moldovaagroindbank_data', {});
const maib_title = window.wp.htmlEntities.decodeEntities(maib_settings.title);

const maib_content = () => {
    return window.wp.htmlEntities.decodeEntities(maib_settings.description || '');
};

const maib_label = () => {
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

const maib_blockGateway = {
    name: maib_settings.id,
    label: window.wp.element.createElement(maib_label, null),
    icons: ['visa', 'mastercard'],
    content: window.wp.element.createElement(maib_content, null),
    edit: window.wp.element.createElement(maib_content, null),
    canMakePayment: () => true,
    ariaLabel: maib_title,
    supports: {
        features: maib_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(maib_blockGateway);
