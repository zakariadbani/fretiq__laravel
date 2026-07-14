"use strict";

/**
 * Quill HTML Field — fretiq
 *
 * Data-attribute-driven WYSIWYG initializer for [data-quill-html-field] roots.
 * Works alongside crud-form-handler.js — the original <textarea> stays in the DOM
 * (hidden) as the source of truth for FormData and FormValidation.
 *
 * Expected markup inside [data-quill-html-field]:
 *   [data-quill-toggle]       — toggle button (visual ↔ raw)
 *   [data-quill-editor-wrap]  — wraps the Quill mount div (hides toolbar+editor together)
 *   [data-quill-editor]       — Quill mounts here (inside the wrap div)
 *   <textarea>                — original textarea (source of truth)
 *
 * Initial mode decision:
 *   RAW   if textarea content matches /<\s*(html|head|body|style|table)/i
 *         (full-document email HTML — Quill's normalizer would mangle it)
 *   VISUAL otherwise (empty or simple fragment)
 */
var KTQuillHtmlField = function () {

    const FULL_DOC_RE = /<\s*(html|head|body|style|table)/i;

    const TOOLBAR_CONFIG = [
        ['bold', 'italic', 'underline'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link'],
        ['clean']
    ];

    const initField = (root) => {
        const toggleBtn   = root.querySelector('[data-quill-toggle]');
        const editorWrap  = root.querySelector('[data-quill-editor-wrap]');
        const editorMount = root.querySelector('[data-quill-editor]');
        const textarea    = root.querySelector('textarea');

        if (!toggleBtn || !editorWrap || !editorMount || !textarea) {
            console.warn('[KTQuillHtmlField] Missing required child element in root:', root);
            return;
        }

        const placeholder = root.getAttribute('data-quill-placeholder') || '';

        // ── Quill init ──────────────────────────────────────────────────────
        const quill = new Quill(editorMount, {
            theme: 'snow',
            modules: {
                toolbar: TOOLBAR_CONFIG
            },
            placeholder: placeholder
        });

        // ── Mode flag: 'visual' | 'raw' ─────────────────────────────────────
        // Determines initial mode; also guards sync so raw edits never get
        // overwritten by Quill's text-change event.
        // Start in raw mode so seeding Quill below never triggers the sync
        // handler — the textarea must only be rewritten by real user edits.
        let mode = 'raw';
        const startVisual = !FULL_DOC_RE.test(textarea.value);

        // ── Apply initial mode to DOM ────────────────────────────────────────
        if (!startVisual) {
            // Show textarea, hide Quill wrap; do NOT seed Quill yet
            editorWrap.classList.add('d-none');
            textarea.classList.remove('d-none');
            toggleBtn.classList.remove('active');
        } else {
            // Visual mode: seed Quill from textarea, hide textarea
            if (textarea.value.trim() !== '') {
                quill.clipboard.dangerouslyPasteHTML(textarea.value);
            }
            mode = 'visual';
            editorWrap.classList.remove('d-none');
            textarea.classList.add('d-none');
            toggleBtn.classList.add('active');
        }

        // ── Sync: Quill → textarea (only while in visual mode) ──────────────
        quill.on('text-change', function () {
            if (mode !== 'visual') return;

            // Quill's empty state is <p><br></p> — treat as empty string
            if (quill.getText().trim() === '' && quill.root.innerHTML.indexOf('<img') === -1) {
                textarea.value = '';
            } else {
                textarea.value = quill.root.innerHTML;
            }
        });

        // ── Toggle handler ───────────────────────────────────────────────────
        toggleBtn.addEventListener('click', function () {
            if (mode === 'visual') {
                // → switch to RAW
                mode = 'raw';
                editorWrap.classList.add('d-none');
                textarea.classList.remove('d-none');
                toggleBtn.classList.remove('active');
            } else {
                // → switch to VISUAL
                // Re-seed Quill while mode is still 'raw' — setContents and
                // dangerouslyPasteHTML fire text-change, and the sync guard
                // must block them or the textarea gets wiped before re-seed.
                const html = textarea.value;
                quill.setContents([]);
                if (html.trim() !== '') {
                    quill.clipboard.dangerouslyPasteHTML(html);
                }
                mode = 'visual';
                editorWrap.classList.remove('d-none');
                textarea.classList.add('d-none');
                toggleBtn.classList.add('active');
            }
        });
    };

    return {
        init: function () {
            document.querySelectorAll('[data-quill-html-field]').forEach(initField);
        }
    };

}();

KTUtil.onDOMContentLoaded(function () {
    KTQuillHtmlField.init();
});

