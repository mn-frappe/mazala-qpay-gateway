/**
 * WooQPay Blocks Integration
 * 
 * Registers WooQPay payment method with WooCommerce Blocks checkout.
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement } = window.wp.element;

const settings = getSetting('wooqpay_data', {});

const defaultLabel = 'qPay';
const label = decodeEntities(settings.title) || defaultLabel;

/**
 * Content component - displays payment method description
 */
const Content = () => {
    return decodeEntities(settings.description || '');
};

/**
 * Label component - displays payment method title with logo
 */
const Label = (props) => {
    const { PaymentMethodLabel } = props.components;
    
    const icon = settings.logo_url ? createElement('img', {
        src: settings.logo_url,
        alt: label,
        style: {
            height: '24px',
            marginRight: '8px',
            verticalAlign: 'middle'
        }
    }) : null;
    
    return createElement(
        'span',
        { style: { display: 'flex', alignItems: 'center' } },
        icon,
        createElement(PaymentMethodLabel, { text: label })
    );
};

/**
 * WooQPay payment method configuration for Blocks
 */
const WooQPayPaymentMethod = {
    name: 'wooqpay',
    label: createElement(Label, null),
    content: createElement(Content, null),
    edit: createElement(Content, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || ['products'],
    },
};

registerPaymentMethod(WooQPayPaymentMethod);
