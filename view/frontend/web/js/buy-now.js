define(['jquery', 'jquery/ui'], function ($) {
    'use strict';

    $.widget('shopwhizzy.buyNow', {
        options: {
            checkoutUrl: null
        },

        /** @inheritdoc */
        _create: function () {
            this.element.on('click', $.proxy(this._onClick, this));
        },

        /**
         * Add a return_url pointing at checkout to the product add-to-cart form,
         * then submit it natively so the server-side redirect (return_url support
         * in Magento\Checkout\Controller\Cart::getBackUrl) sends the browser to
         * checkout after the item is added.
         */
        _onClick: function (event) {
            event.preventDefault();

            var form = this.element.closest('form'),
                input = form.find('input[name="return_url"]');

            if (!input.length) {
                input = $('<input>', {
                    type: 'hidden',
                    name: 'return_url'
                });
                form.append(input);
            }

            input.val(this.options.checkoutUrl);
            form.get(0).submit();
        }
    });

    return $.shopwhizzy.buyNow;
});
