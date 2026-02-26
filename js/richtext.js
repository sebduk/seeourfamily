/**
 * Lightweight rich-text editor.
 *
 * Replaces any <textarea data-richtext> with a contenteditable div
 * and a Gmail-style formatting toolbar.
 *
 * On form submit, the HTML content (with accented characters converted
 * to numeric entities) is synced back to the hidden textarea.
 *
 * No dependencies.
 */
(function () {
    'use strict';

    // ── Accent encoder ──────────────────────────────────────────────
    function encodeAccents(html) {
        return html.replace(/[^\x00-\x7F]/g, function (ch) {
            return '&#' + ch.codePointAt(0) + ';';
        });
    }

    // ── Toolbar definition ──────────────────────────────────────────
    var buttons = [
        { cmd: 'bold',           label: 'B',      title: 'Bold (Ctrl+B)',         style: 'font-weight:700' },
        { cmd: 'italic',         label: 'I',      title: 'Italic (Ctrl+I)',       style: 'font-style:italic' },
        { cmd: 'underline',      label: 'U',      title: 'Underline (Ctrl+U)',    style: 'text-decoration:underline' },
        { cmd: 'strikeThrough',  label: 'S',      title: 'Strikethrough',         style: 'text-decoration:line-through' },
        { sep: true },
        { cmd: 'createLink',     label: 'Link',   title: 'Insert link',           ask: true },
        { cmd: 'unlink',         label: 'Unlink', title: 'Remove link' },
        { sep: true },
        { cmd: 'insertUnorderedList', label: '\u2022 List', title: 'Bullet list' },
        { cmd: 'insertOrderedList',   label: '1. List',     title: 'Numbered list' },
        { sep: true },
        { cmd: 'removeFormat',   label: 'Clear',  title: 'Clear formatting' },
    ];

    // ── Build one toolbar ───────────────────────────────────────────
    function createToolbar() {
        var bar = document.createElement('div');
        bar.className = 'rt-toolbar';

        buttons.forEach(function (btn) {
            if (btn.sep) {
                var s = document.createElement('span');
                s.className = 'rt-sep';
                bar.appendChild(s);
                return;
            }
            var b = document.createElement('button');
            b.type = 'button';
            b.title = btn.title;
            b.innerHTML = btn.label;
            if (btn.style) b.style.cssText = btn.style;
            b.addEventListener('mousedown', function (e) {
                e.preventDefault();          // keep focus in editor
                if (btn.ask) {
                    var url = prompt('URL:', 'https://');
                    if (url) document.execCommand(btn.cmd, false, url);
                } else {
                    document.execCommand(btn.cmd, false, null);
                }
            });
            bar.appendChild(b);
        });

        return bar;
    }

    // ── Initialise one editor ───────────────────────────────────────
    function initEditor(textarea) {
        textarea.style.display = 'none';

        var wrap = document.createElement('div');
        wrap.className = 'rt-wrap';

        var toolbar = createToolbar();

        var editor = document.createElement('div');
        editor.className = 'rt-editor';
        editor.contentEditable = 'true';
        editor.innerHTML = textarea.value;

        // Match the textarea's approximate height
        var rows = parseInt(textarea.getAttribute('rows'), 10) || 8;
        editor.style.minHeight = (rows * 1.5) + 'em';

        wrap.appendChild(toolbar);
        wrap.appendChild(editor);
        textarea.parentNode.insertBefore(wrap, textarea);

        // Sync on form submit: sanitise accents then copy to textarea
        var form = textarea.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                textarea.value = encodeAccents(editor.innerHTML);
            });
        }
    }

    // ── Boot ────────────────────────────────────────────────────────
    document.querySelectorAll('textarea[data-richtext]').forEach(initEditor);
})();
