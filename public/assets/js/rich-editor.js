(function () {
    var baseContentStyle =
        'body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; font-size: 16px; line-height: 1.5; background: #ffffff; color: #212529; }' +
        'a { color: #0d6efd; }';
    var darkContentStyle =
        'body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; font-size: 16px; line-height: 1.5; background: #1a2420; color: #e8f0ec; }' +
        'a { color: #8fd0b4; }';
    var reinitTimer = null;
    var activeTheme = null;

    function isDarkTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function currentThemeName() {
        return isDarkTheme() ? 'dark' : 'light';
    }

    /**
     * TinyMCE lässt Skin-/Content-CSS nach tinymce.remove() im Dokument
     * und im StyleSheetLoader-Cache. Beim Wechsel dark → light bleibt der
     * Editor sonst optisch dunkel.
     */
    function purgeTinymceStylesheets() {
        document.querySelectorAll(
            'link[href*="/skins/ui/"], link[href*="/skins/content/"], link[href*="skins/ui/"], link[href*="skins/content/"]'
        ).forEach(function (el) {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        });

        try {
            var loader = tinymce.DOM && tinymce.DOM.styleSheetLoader;
            if (!loader) {
                return;
            }
            if (loader._states && typeof loader._states === 'object') {
                Object.keys(loader._states).forEach(function (key) {
                    delete loader._states[key];
                });
            }
            if (loader._loaded && typeof loader._loaded === 'object') {
                Object.keys(loader._loaded).forEach(function (key) {
                    delete loader._loaded[key];
                });
            }
            if (typeof loader.reset === 'function') {
                loader.reset();
            }
        } catch (e) {
            // ignore private-API differences across TinyMCE builds
        }
    }

    function buildConfig() {
        var dark = isDarkTheme();
        var theme = dark ? 'dark' : 'light';
        return {
            selector: 'textarea.rich-editor',
            menubar: false,
            branding: false,
            promotion: false,
            statusbar: false,
            height: 280,
            convert_urls: false,
            entity_encoding: 'raw',
            // Enter = nächste Zeile (BR), Shift+Enter = neuer Absatz
            newline_behavior: 'linebreak',
            plugins: 'lists link autoresize',
            toolbar:
                'bold italic underline strikethrough | forecolor backcolor | fontsize | ' +
                'alignleft aligncenter alignright | bullist numlist | link | removeformat',
            font_size_formats: '12px 14px 16px 18px 20px 24px 28px 32px',
            skin: dark ? 'oxide-dark' : 'oxide',
            content_css: dark ? 'dark' : 'default',
            content_style: dark ? darkContentStyle : baseContentStyle,
            // Eigene Cache-Keys pro Theme, damit Skin-URLs nicht kollidieren
            cache_suffix: '?app-theme=' + theme,
            setup: function (editor) {
                editor.on('keydown', function (e) {
                    if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
                        e.preventDefault();
                        editor.execCommand('InsertLineBreak');
                    }
                });
                editor.on('change input Undo Redo', function () {
                    editor.save();
                });
            }
        };
    }

    function initRichEditors() {
        if (typeof tinymce === 'undefined') {
            return;
        }

        var nodes = document.querySelectorAll('textarea.rich-editor');
        if (!nodes.length) {
            return;
        }

        activeTheme = currentThemeName();
        tinymce.init(buildConfig());
    }

    function reinitRichEditors(force) {
        if (typeof tinymce === 'undefined') {
            return;
        }

        var nodes = document.querySelectorAll('textarea.rich-editor');
        if (!nodes.length) {
            return;
        }

        var nextTheme = currentThemeName();
        if (!force && activeTheme === nextTheme && tinymce.editors.length) {
            return;
        }

        tinymce.editors.slice().forEach(function (editor) {
            try {
                editor.save();
            } catch (e) {
                // ignore
            }
        });
        tinymce.remove();
        purgeTinymceStylesheets();
        activeTheme = nextTheme;
        tinymce.init(buildConfig());
    }

    function scheduleReinit() {
        if (reinitTimer) {
            window.clearTimeout(reinitTimer);
        }
        // Kurzer Delay: Theme-Attribut und UI sind gesetzt, Skin-CSS kann sauber neu geladen werden
        reinitTimer = window.setTimeout(function () {
            reinitTimer = null;
            reinitRichEditors(true);
        }, 30);
    }

    function refreshVisibleEditors() {
        if (typeof tinymce === 'undefined') {
            return;
        }
        tinymce.editors.forEach(function (editor) {
            try {
                editor.dispatch('ResizeEditor');
            } catch (e) {
                // ignore
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRichEditors);
    } else {
        initRichEditors();
    }

    document.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-settings-tab]');
        if (!tab) {
            return;
        }
        window.setTimeout(refreshVisibleEditors, 50);
    });

    document.addEventListener('admin-theme-change', scheduleReinit);

    window.AppRichEditor = {
        refresh: refreshVisibleEditors,
        reinit: function () {
            reinitRichEditors(true);
        }
    };
})();
