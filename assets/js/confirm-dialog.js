/*
  Dialogo di conferma condiviso (sostituisce window.confirm con lo stile POS).
  Espone window.showConfirm(message, options) -> Promise<boolean>.
*/
(function (global) {
    'use strict';

    function showConfirm(message, options) {
        options = options || {};
        var title = options.title || 'Conferma';
        var confirmLabel = options.confirmLabel || 'Conferma';
        var cancelLabel = options.cancelLabel || 'Annulla';

        return new Promise(function (resolve) {
            var backdrop = document.createElement('div');
            backdrop.className = 'confirm-dialog-backdrop';

            var dialog = document.createElement('div');
            dialog.className = 'confirm-dialog';
            dialog.setAttribute('role', 'alertdialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', 'confirmDialogTitle');
            dialog.setAttribute('aria-describedby', 'confirmDialogMessage');

            var titleEl = document.createElement('h2');
            titleEl.id = 'confirmDialogTitle';
            titleEl.textContent = title;

            var messageEl = document.createElement('p');
            messageEl.id = 'confirmDialogMessage';
            messageEl.textContent = message;

            var actionsEl = document.createElement('div');
            actionsEl.className = 'confirm-dialog-actions';

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'confirm-dialog-btn confirm-dialog-btn--secondary';
            cancelBtn.textContent = cancelLabel;

            var confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = 'confirm-dialog-btn confirm-dialog-btn--danger';
            confirmBtn.textContent = confirmLabel;

            actionsEl.appendChild(cancelBtn);
            actionsEl.appendChild(confirmBtn);

            dialog.appendChild(titleEl);
            dialog.appendChild(messageEl);
            dialog.appendChild(actionsEl);
            backdrop.appendChild(dialog);
            document.body.appendChild(backdrop);

            var previouslyFocused = document.activeElement;

            function close(result) {
                document.removeEventListener('keydown', onKeydown);
                if (backdrop.parentNode) {
                    backdrop.parentNode.removeChild(backdrop);
                }
                if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                    previouslyFocused.focus();
                }
                resolve(result);
            }

            function onKeydown(event) {
                if (event.key === 'Escape') {
                    close(false);
                } else if (event.key === 'Enter') {
                    close(true);
                }
            }

            cancelBtn.addEventListener('click', function () { close(false); });
            confirmBtn.addEventListener('click', function () { close(true); });
            backdrop.addEventListener('click', function (event) {
                if (event.target === backdrop) {
                    close(false);
                }
            });
            document.addEventListener('keydown', onKeydown);

            confirmBtn.focus();
        });
    }

    global.showConfirm = showConfirm;
})(window);
