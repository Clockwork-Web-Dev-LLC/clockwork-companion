(function () {
    'use strict';

    var modal = null;
    var form  = null;
    var nonce = '';

    function init() {
        modal = document.getElementById('cwk-support-modal');
        if (!modal) return;

        form = document.getElementById('cwk-support-form');
        var data = window.cwkSupport || {};
        nonce = data.nonce || '';

        // Inject nonce into the hidden field
        var nonceField = document.getElementById('cwk-nonce-field');
        if (nonceField) nonceField.value = nonce;

        // Pre-fill user fields (only if blank — don't overwrite anything the
        // user has already typed on a second open)
        prefillIfEmpty('cwk-first-name', data.user && data.user.firstName);
        prefillIfEmpty('cwk-last-name',  data.user && data.user.lastName);
        prefillIfEmpty('cwk-email',      data.user && data.user.email);
        prefillIfEmpty('cwk-site-url',   data.siteUrl);

        // Delegate trigger clicks — works for triggers added after DOMContentLoaded
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('.cwk-support-trigger');
            if (!trigger) return;
            e.preventDefault();
            openModal();
        });

        modal.querySelector('.cwk-modal-overlay').addEventListener('click', closeModal);
        modal.querySelector('.cwk-modal-close').addEventListener('click', closeModal);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.style.display !== 'none') {
                closeModal();
            }
        });

        if (form) {
            form.addEventListener('submit', handleSubmit);
        }
    }

    function prefillIfEmpty(id, value) {
        var el = document.getElementById(id);
        if (el && !el.value && value) el.value = value;
    }

    function openModal() {
        // Reset to form view in case a previous submission reached success state
        var success = document.getElementById('cwk-form-success');
        var errorDiv = document.getElementById('cwk-form-error');
        if (success && success.style.display !== 'none') {
            success.style.display = 'none';
            if (form) form.style.display = '';
            if (form) form.reset();
            // Re-prefill after reset
            var data = window.cwkSupport || {};
            prefillIfEmpty('cwk-first-name', data.user && data.user.firstName);
            prefillIfEmpty('cwk-last-name',  data.user && data.user.lastName);
            prefillIfEmpty('cwk-email',      data.user && data.user.email);
            prefillIfEmpty('cwk-site-url',   data.siteUrl);
        }
        if (errorDiv) errorDiv.style.display = 'none';

        modal.style.display = 'block';
        document.body.classList.add('cwk-modal-open');
        document.body.style.overflow = 'hidden';

        // Focus first visible text input for accessibility
        var first = modal.querySelector('input[type="text"], input[type="email"]');
        if (first) setTimeout(function () { first.focus(); }, 60);
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.classList.remove('cwk-modal-open');
        document.body.style.overflow = '';
    }

    function handleSubmit(e) {
        e.preventDefault();

        var submitBtn = form.querySelector('.cwk-submit-btn');
        var label     = form.querySelector('.cwk-submit-label');
        var spinner   = form.querySelector('.cwk-submit-spinner');
        var errorDiv  = document.getElementById('cwk-form-error');

        submitBtn.disabled    = true;
        label.style.display   = 'none';
        spinner.style.display = '';
        if (errorDiv) { errorDiv.style.display = 'none'; errorDiv.innerHTML = ''; }

        var data     = window.cwkSupport || {};
        var formData = new FormData(form);
        formData.set('action', 'clockwork_support_submit');
        formData.set('nonce', nonce);

        fetch(data.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method:      'POST',
            body:        formData,
            credentials: 'same-origin',
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            if (json.success) {
                if (form) form.style.display = 'none';
                var success = document.getElementById('cwk-form-success');
                if (success) success.style.display = 'block';
            } else {
                var msg = (json.data && json.data.message) || 'Something went wrong. Please try again.';
                if (errorDiv) {
                    errorDiv.innerHTML = msg;
                    errorDiv.style.display = 'block';
                }
                resetButton(submitBtn, label, spinner);
            }
        })
        .catch(function () {
            if (errorDiv) {
                errorDiv.innerHTML = 'Network error. Please check your connection and try again.';
                errorDiv.style.display = 'block';
            }
            resetButton(submitBtn, label, spinner);
        });
    }

    function resetButton(btn, label, spinner) {
        btn.disabled       = false;
        label.style.display = '';
        spinner.style.display = 'none';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
