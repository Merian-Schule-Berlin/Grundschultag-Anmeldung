(function () {
    var baseContentStyle =
        'body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; font-size: 16px; line-height: 1.5; background: #ffffff; color: #212529; }' +
        'a { color: #0d6efd; }';
    var darkContentStyle =
        'body { font-family: system-ui, -apple-system, Segoe UI, sans-serif; font-size: 16px; line-height: 1.5; background: #1a2420; color: #e8f0ec; }' +
        'a { color: #8fd0b4; }';

    function isDarkTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function buildConfig() {
        var dark = isDarkTheme();
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

    window.AppRichEditor = {
        refresh: refreshVisibleEditors
    };
})();
