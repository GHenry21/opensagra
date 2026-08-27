/*
  Toast condiviso (design "glass" rosso/verde/blu definito in pos-redesign.css).
  Espone window.showToast(message, type, options) per tutte le pagine POS:
  crea/riusa il contenitore #message e non richiede jQuery o Vue.
*/
(function (global) {
    'use strict';

    var DEFAULT_TITLES = {
        error: 'Errore',
        success: 'Successo',
        info: 'Informazione'
    };

    var DEFAULT_DURATION = 3200;
    var OUT_ANIMATION_MS = 220;

    function getContainer() {
        var container = document.getElementById('message');
        if (!container) {
            container = document.createElement('div');
            container.id = 'message';
            document.body.appendChild(container);
        }
        return container;
    }

    function showToast(message, type, options) {
        type = (type === 'error' || type === 'success' || type === 'info') ? type : 'info';
        options = options || {};

        var duration = options.duration != null ? options.duration : DEFAULT_DURATION;
        var title = options.title != null ? options.title : DEFAULT_TITLES[type];

        var toastEl = document.createElement('div');
        toastEl.className = 'toast ' + type;
        toastEl.setAttribute('role', 'status');
        toastEl.setAttribute('aria-live', 'polite');

        var iconEl = document.createElement('div');
        iconEl.className = 'toast-icon';
        toastEl.appendChild(iconEl);

        var contentEl = document.createElement('div');
        contentEl.className = 'toast-content';

        if (title) {
            var titleEl = document.createElement('div');
            titleEl.className = 'toast-title';
            titleEl.textContent = title;
            contentEl.appendChild(titleEl);
        }

        var messageEl = document.createElement('div');
        messageEl.className = 'toast-message';
        messageEl.textContent = message;
        contentEl.appendChild(messageEl);

        var action = options.action;
        if (action && action.label) {
            var actionsEl = document.createElement('div');
            actionsEl.className = 'toast-actions';

            var actionBtn = document.createElement(action.href ? 'a' : 'button');
            actionBtn.className = 'toast-action-btn';
            actionBtn.textContent = action.label;
            if (action.href) {
                actionBtn.href = action.href;
            } else {
                actionBtn.type = 'button';
            }
            if (typeof action.onClick === 'function') {
                actionBtn.addEventListener('click', action.onClick);
            }
            actionsEl.appendChild(actionBtn);
            contentEl.appendChild(actionsEl);
        }

        toastEl.appendChild(contentEl);

        function dismiss() {
            if (!toastEl.parentNode) {
                return;
            }
            toastEl.classList.add('toast-out');
            setTimeout(function () {
                if (toastEl.parentNode) {
                    toastEl.parentNode.removeChild(toastEl);
                }
            }, OUT_ANIMATION_MS);
        }

        if (duration > 0) {
            setTimeout(dismiss, duration);
        } else {
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'toast-close-btn';
            closeBtn.setAttribute('aria-label', 'Chiudi notifica');
            closeBtn.textContent = '×';
            closeBtn.addEventListener('click', dismiss);
            toastEl.appendChild(closeBtn);
        }

        getContainer().appendChild(toastEl);

        return dismiss;
    }

    global.showToast = showToast;
})(window);
