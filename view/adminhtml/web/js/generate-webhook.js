/**
 * Vanilla JS - the admin panel always uses Magento's own backend assets regardless of
 * which storefront theme (Luma, Hyva, etc.) is active, but there's no need for a
 * RequireJS widget just for one button.
 */
(function () {
    'use strict';

    function setResult(el, success, message) {
        el.classList.remove('shopwhizzy-success', 'shopwhizzy-error');
        el.classList.add(success ? 'shopwhizzy-success' : 'shopwhizzy-error');
        el.textContent = message;
        el.style.display = 'block';
    }

    function onClick(event) {
        var button = event.currentTarget,
            ajaxUrl = button.getAttribute('data-ajax-url'),
            secretKeyField = document.getElementById(button.getAttribute('data-secret-key-field')),
            webhookSecretField = document.getElementById(button.getAttribute('data-webhook-secret-field')),
            resultEl = document.getElementById('shopwhizzy-generate-webhook-result'),
            body = new URLSearchParams();

        event.preventDefault();

        body.set('secret_key', secretKeyField ? secretKeyField.value : '');
        body.set('form_key', window.FORM_KEY || '');

        button.disabled = true;
        setResult(resultEl, true, 'Generating…');
        resultEl.classList.remove('shopwhizzy-success', 'shopwhizzy-error');

        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    if (webhookSecretField) {
                        webhookSecretField.value = data.webhook_secret;
                    }
                    setResult(resultEl, true, data.message);
                } else {
                    setResult(resultEl, false, data.message);
                }
            })
            .catch(function () {
                setResult(resultEl, false, 'An unexpected error occurred. Please try again.');
            })
            .finally(function () {
                button.disabled = false;
            });
    }

    function init() {
        var button = document.getElementById('shopwhizzy-generate-webhook-button');
        if (button) {
            button.addEventListener('click', onClick);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
