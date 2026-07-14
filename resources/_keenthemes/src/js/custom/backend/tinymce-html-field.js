"use strict";

/**
 * TinyMCE HTML Field — fretiq
 *
 * Initializes TinyMCE 5 on textarea[data-tinymce-html-field].
 * The textarea stays in the DOM (TinyMCE hides it) as the source of truth
 * for FormData and FormValidation — crud-form-handler.js builds
 * new FormData(form) in a bubble-phase click listener on '.submit', so this
 * module syncs editor → textarea continuously on edits AND once more in a
 * capture-phase click listener (runs first) as a safety net.
 *
 * Full-document email HTML (<html><head><style><table>…) is edited natively
 * via the fullpage plugin. Merge tags {{contact.name}}, {{company.name}},
 * {{unsubscribe_url}} are preserved (protect regex + convert_urls:false +
 * entity_encoding raw).
 *
 * Byte-fidelity rule: the textarea is ONLY rewritten after a real user edit
 * (editor.isDirty()). Opening + saving a template untouched posts the
 * original value byte-identical.
 */
var KTTinymceHtmlField = function () {

    const SELECTOR = 'textarea[data-tinymce-html-field]';
    const editors  = [];

    const syncToTextarea = (editor) => {
        const html = editor.getContent();
        // fullpage wraps even empty content in a full document — keep the
        // `required` semantics by treating text-empty content (no img/table)
        // as an empty string. Direct value assignment (not editor.save())
        // avoids touching the dirty flag, so successive edits keep syncing.
        const textEmpty = editor.getContent({ format: 'text' }).trim() === '';
        editor.targetElm.value =
            (textEmpty && !/<(img|table)\b/i.test(html)) ? '' : html;
        // Nudge FormValidation so a previous "champ requis" error clears.
        editor.targetElm.dispatchEvent(new Event('input', { bubbles: true }));
    };

    return {
        init: function () {
            if (!document.querySelector(SELECTOR) || typeof tinymce === 'undefined') return;

            tinymce.init({
                selector: SELECTOR,

                // Assets — bundle lives at /assets/plugins/custom/tinymce
                skin_url: tinymce.baseURL + '/skins/ui/oxide',
                content_css: false,
                branding: false,

                // Surface
                height: 700,
                menubar: false,
                plugins: 'fullpage code table lists link image paste preview fullscreen searchreplace',
                toolbar: 'undo redo | bold italic underline | forecolor backcolor | ' +
                         'alignleft aligncenter alignright | bullist numlist | ' +
                         'link image table | searchreplace | code preview fullscreen',
                toolbar_mode: 'sliding',

                // HTML fidelity — full-document Zoho email markup must survive
                verify_html: false,
                valid_elements: '*[*]',
                valid_children: '+body[style]',
                convert_urls: false,
                relative_urls: false,
                remove_script_host: false,
                entity_encoding: 'raw',
                protect: [ /\{\{[^}]+\}\}/g ],

                setup: function (editor) {
                    const isReadonly = editor.getElement().hasAttribute('data-tinymce-readonly');

                    editor.on('init', function () {
                        editors.push(editor);

                        if (isReadonly) {
                            editor.mode.set('readonly');
                            editor.getContainer().classList.add('tox-readonly-preview');
                        }
                    });

                    // Mirror editor → textarea on real edits only. isDirty() is
                    // false on initial load (TinyMCE does not dirty programmatic
                    // setContent), so opening + saving an untouched template posts
                    // the original server-rendered value byte-identical.
                    editor.on('input change undo redo ExecCommand', function () {
                        if (!isReadonly && editor.isDirty()) syncToTextarea(editor);
                    });
                }
            });

            // Capture-phase safety net: fires BEFORE crud-form-handler's
            // bubble-phase '.submit' listener (which validates, then builds
            // FormData). isDirty() guard keeps no-edit saves byte-identical.
            document.addEventListener('click', function (e) {
                if (!e.target.closest || !e.target.closest('.submit')) return;
                editors.forEach(function (editor) {
                    if (editor.isDirty()) syncToTextarea(editor);
                });
            }, true);
        }
    };
}();

KTUtil.onDOMContentLoaded(function () {
    KTTinymceHtmlField.init();
});

