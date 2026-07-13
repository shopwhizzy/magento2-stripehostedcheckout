/**
 * Deliberately vanilla JS (no jQuery/RequireJS dependency) so this works both on Luma
 * (which loads RequireJS/jQuery) and on Hyva-based themes (which do not load either on
 * the storefront by default). Loaded as a plain <script> tag rather than via
 * text/x-magento-init for the same reason.
 */
(function () {
    'use strict';

    function onClick(event) {
        var button = event.currentTarget,
            checkoutUrl = button.getAttribute('data-checkout-url'),
            form = button.closest('form'),
            input;

        if (!form || !checkoutUrl) {
            return;
        }

        event.preventDefault();

        input = form.querySelector('input[name="return_url"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'return_url';
            form.appendChild(input);
        }
        input.value = checkoutUrl;

        // Native submit (not a dispatched 'submit' event) so any AJAX add-to-cart
        // handler bound to the form is bypassed and the browser does a real
        // navigation, which is required for the server-side return_url redirect.
        HTMLFormElement.prototype.submit.call(form);
    }

    function init() {
        var buttons = document.querySelectorAll('.shopwhizzy-buy-now[data-checkout-url]'),
            i;

        for (i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', onClick);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
