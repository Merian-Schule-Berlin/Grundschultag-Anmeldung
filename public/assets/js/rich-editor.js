(function () {
    var lightTheme = {
        bg: '#ffffff',
        fg: '#212529',
        link: '#0d6efd'
    };
    var darkTheme = {
        bg: '#1a2420',
        fg: '#e8f0ec',
        link: '#8fd0b4'
    };

    function isDarkTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function themeColors() {
        return isDarkTheme() ? darkTheme : lightTheme;
    }

    function contentStyleForTheme() {
        var t = themeColors();
        return (
            'html, body { background-color: ' + t.bg + ' !important; color: ' + t.fg + ' !important; }' +
            'body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; font-size: 16px; line-height: 1.5; }' +
            'a { color: ' + t.link + ' !important; }'
        );
    }

    function applyContentTheme(editor) {
        try {
            var doc = editor.getDoc();
            var body = editor.getBody();
            if (!doc || !body) {
                return;
            }

            var t = themeColors();

            // Inline gewinnt zuverlässig gegen TinyMCE content.css
            doc.documentElement.style.backgroundColor = t.bg;
            doc.documentElement.style.color = t.fg;
            body.style.backgroundColor = t.bg;
            body.style.color = t.fg;

            var styleId = 'app-rich-editor-theme';
            var styleEl = doc.getElementById(styleId);
            if (!styleEl) {
                styleEl = doc.createElement('style');
                styleEl.id = styleId;
                doc.head.appendChild(styleEl);
            }
            styleEl.textContent = contentStyleForTheme();
        } catch (e) {
            // ignore iframe access races
        }
    }

    function applyThemeToAllEditors() {
        if (typeof tinymce === 'undefined') {
            return;
        }
        tinymce.editors.forEach(function (editor) {
            applyContentTheme(editor);
        });
    }

    function buildConfig() {
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
            // Immer Light-Skin: Dark Mode kommt per CSS + Content-Theme (live umschaltbar)
            skin: 'oxide',
            content_css: false,
            content_style: contentStyleForTheme(),
            setup: function (editor) {
                editor.on('init', function () {
                    applyContentTheme(editor);
                });
                editor.on('LoadContent SetContent', function () {
                    applyContentTheme(editor);
                });
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

        tinymce.init(buildConfig());
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

    document.addEventListener('admin-theme-change', function () {
        applyThemeToAllEditors();
    });

    window.AppRichEditor = {
        refresh: refreshVisibleEditors,
        applyTheme: applyThemeToAllEditors,
        reinit: applyThemeToAllEditors
    };
})();
