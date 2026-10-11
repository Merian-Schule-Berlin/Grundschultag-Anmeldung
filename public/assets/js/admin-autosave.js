(function () {
    var DEBOUNCE_MS = 550;
    var CHECK_HIDE_MS = 1800;

    function csrfFrom(form) {
        var input = form.querySelector('input[name="_csrf"]');
        return input ? input.value : '';
    }

    function ensureToast() {
        var existing = document.getElementById('admin-autosave-toast');
        if (existing) {
            return existing;
        }
        var toast = document.createElement('div');
        toast.id = 'admin-autosave-toast';
        toast.className = 'autosave-toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML =
            '<span class="autosave-toast-icon" aria-hidden="true"><i class="bi bi-check-lg"></i></span>' +
            '<span class="autosave-toast-text">Gespeichert</span>';
        document.body.appendChild(toast);
        return toast;
    }

    function setToastState(toast, ok, message) {
        var icon = toast.querySelector('.autosave-toast-icon i');
        var text = toast.querySelector('.autosave-toast-text');
        toast.classList.toggle('is-error', !ok);
        if (icon) {
            icon.className = ok ? 'bi bi-check-lg' : 'bi bi-x-lg';
        }
        if (text) {
            text.textContent = message || (ok ? 'Gespeichert' : 'Fehler beim Speichern');
        }
    }

    function showToast(ok, message, hideMs) {
        var toast = ensureToast();
        toast.classList.remove('is-visible', 'is-error');
        setToastState(toast, ok, message);
        void toast.offsetWidth;
        toast.classList.add('is-visible');
        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(function () {
            toast.classList.remove('is-visible', 'is-error');
        }, hideMs || CHECK_HIDE_MS);
    }

    function showCheck() {
        showToast(true, 'Gespeichert', CHECK_HIDE_MS);
    }

    function showError(form, message) {
        showToast(false, message || 'Fehler beim Speichern', 3200);
        if (form && message) {
            form.dispatchEvent(new CustomEvent('autosave:error', { detail: { message: message } }));
        }
    }

    function syncRichEditors(form) {
        if (typeof tinymce === 'undefined') {
            return;
        }
        form.querySelectorAll('textarea.rich-editor').forEach(function (ta) {
            var ed = tinymce.get(ta.id);
            if (ed) {
                try {
                    ed.save();
                } catch (e) {
                    // ignore
                }
            }
        });
    }

    function saveForm(form, sourceEl) {
        if (form.dataset.autosaveBusy === '1') {
            form.dataset.autosaveQueued = '1';
            return Promise.resolve();
        }

        syncRichEditors(form);

        var action = form.getAttribute('action') || window.location.href;
        var method = (form.getAttribute('method') || 'post').toUpperCase();
        var body = new FormData(form);

        form.dataset.autosaveBusy = '1';
        form.classList.add('is-autosaving');

        return fetch(action, {
            method: method,
            body: body,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json'
            }
        })
            .then(function (res) {
                return res
                    .json()
                    .catch(function () {
                        return { ok: false, error: 'Speichern fehlgeschlagen.' };
                    })
                    .then(function (data) {
                        return { res: res, data: data };
                    });
            })
            .then(function (result) {
                if (!result.res.ok || !result.data || result.data.ok === false) {
                    var msg =
                        (result.data && result.data.error) ||
                        'Speichern fehlgeschlagen.';
                    showError(form, msg, sourceEl);
                    return;
                }
                showCheck(form, sourceEl);
                if (result.data.reload) {
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 350);
                }
                if (result.data.csrf) {
                    var csrfInput = form.querySelector('input[name="_csrf"]');
                    if (csrfInput) {
                        csrfInput.value = result.data.csrf;
                    }
                }
            })
            .catch(function () {
                showError(form, 'Netzwerkfehler beim Speichern.', sourceEl);
            })
            .finally(function () {
                form.dataset.autosaveBusy = '0';
                form.classList.remove('is-autosaving');
                if (form.dataset.autosaveQueued === '1') {
                    form.dataset.autosaveQueued = '0';
                    saveForm(form, sourceEl);
                }
            });
    }

    function schedule(form, sourceEl) {
        clearTimeout(form._autosaveTimer);
        form._autosaveTimer = setTimeout(function () {
            saveForm(form, sourceEl);
        }, DEBOUNCE_MS);
    }

    function shouldIgnore(el) {
        if (!el || !el.name) {
            return true;
        }
        if (el.name === '_csrf' || el.name === 'active_tab' || el.name === 'settings_section') {
            return true;
        }
        if (el.disabled || el.readOnly) {
            return true;
        }
        return false;
    }

    function bindForm(form) {
        if (!form || form.dataset.autosaveBound === '1') {
            return;
        }
        form.dataset.autosaveBound = '1';

        form.addEventListener('submit', function (e) {
            var submitter = e.submitter;
            if (
                submitter &&
                (submitter.getAttribute('formaction') ||
                    submitter.hasAttribute('data-autosave-skip'))
            ) {
                // echte Aktionen (z. B. Update prüfen) normal absenden
                return;
            }
            e.preventDefault();
            clearTimeout(form._autosaveTimer);
            saveForm(form, null);
        });

        form.addEventListener('change', function (e) {
            var el = e.target;
            if (shouldIgnore(el)) {
                return;
            }
            var tag = (el.tagName || '').toLowerCase();
            var type = (el.type || '').toLowerCase();
            if (tag === 'select' || type === 'checkbox' || type === 'radio' || type === 'file') {
                clearTimeout(form._autosaveTimer);
                saveForm(form, el);
                return;
            }
            schedule(form, el);
        });

        form.addEventListener('input', function (e) {
            var el = e.target;
            if (shouldIgnore(el)) {
                return;
            }
            var type = (el.type || '').toLowerCase();
            if (type === 'checkbox' || type === 'radio' || type === 'file') {
                return;
            }
            schedule(form, el);
        });
    }

    function bindAll(root) {
        (root || document).querySelectorAll('form[data-autosave]').forEach(bindForm);
    }

    function bindRichEditors() {
        if (typeof tinymce === 'undefined') {
            return;
        }
        tinymce.editors.forEach(function (editor) {
            if (editor._autosaveBound) {
                return;
            }
            var ta = editor.getElement();
            if (!ta) {
                return;
            }
            var form = ta.closest('form[data-autosave]');
            if (!form) {
                return;
            }
            editor._autosaveBound = true;
            editor.on('change input Undo Redo blur', function () {
                editor.save();
                schedule(form, ta);
            });
        });
    }

    function boot() {
        bindAll(document);
        bindRichEditors();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // TinyMCE may init slightly later
    window.setTimeout(bindRichEditors, 400);
    window.setTimeout(bindRichEditors, 1200);

    window.AdminAutosave = {
        bind: bindForm,
        bindAll: bindAll,
        save: saveForm,
        schedule: schedule,
        showCheck: showCheck,
        showError: showError,
        bindRichEditors: bindRichEditors,
        csrfFrom: csrfFrom
    };
})();
