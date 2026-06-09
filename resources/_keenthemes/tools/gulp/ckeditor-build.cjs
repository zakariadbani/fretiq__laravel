/**
 * Build CKEditor 5 bundles for gulp. Uses esbuild (no webpack).
 * Run from tools dir with env CKEDITOR_DIST set to theme assets path.
 */
const path = require('path');
const fs = require('fs');
const esbuild = require('esbuild');

const toolsDir = path.join(__dirname, '..');
const outDir = process.env.CKEDITOR_DIST || path.join(toolsDir, 'themes/metronic/html/assets');
const ckeditorOut = path.join(outDir, 'plugins', 'custom', 'ckeditor');

const entries = [
  { name: 'ckeditor-classic', path: 'webpack/plugins/custom/ckeditor/ckeditor-classic.js' },
  { name: 'ckeditor-balloon', path: 'webpack/plugins/custom/ckeditor/ckeditor-balloon.js' },
  { name: 'ckeditor-balloon-block', path: 'webpack/plugins/custom/ckeditor/ckeditor-balloon-block.js' },
  { name: 'ckeditor-inline', path: 'webpack/plugins/custom/ckeditor/ckeditor-inline.js' },
  { name: 'ckeditor-document', path: 'webpack/plugins/custom/ckeditor/ckeditor-document.js' },
];

fs.mkdirSync(ckeditorOut, { recursive: true });

const ckeditor5Css = path.join(toolsDir, 'node_modules/ckeditor5/dist/ckeditor5.css');
const sharedCss = fs.existsSync(ckeditor5Css) ? fs.readFileSync(ckeditor5Css, 'utf8') : '';

// Resolve @ckeditor/ckeditor5-editor-classic: npm may nest it under ckeditor5 or hoist to top-level
const ckeditorClassicNested = path.join(toolsDir, 'node_modules/ckeditor5/node_modules/@ckeditor/ckeditor5-editor-classic');
const ckeditorClassicHoisted = path.join(toolsDir, 'node_modules/@ckeditor/ckeditor5-editor-classic');
const ckeditorClassicPkg = fs.existsSync(ckeditorClassicNested) ? ckeditorClassicNested : ckeditorClassicHoisted;

for (const entry of entries) {
  const entryPath = path.join(toolsDir, entry.path);
  const result = esbuild.buildSync({
    entryPoints: [entryPath],
    bundle: true,
    format: 'iife',
    outdir: ckeditorOut,
    outbase: path.join(toolsDir, 'webpack/plugins/custom/ckeditor'),
    entryNames: '[name].bundle',
    loader: { '.css': 'file' },
    minify: true,
    sourcemap: false,
    logLevel: 'silent',
    write: false,
    alias: {
      '@ckeditor/ckeditor5-editor-classic': ckeditorClassicPkg,
    },
  });

  let wroteCss = false;
  if (result.outputFiles) {
    for (const file of result.outputFiles) {
      const ext = path.extname(file.path);
      const dest = path.join(ckeditorOut, entry.name + '.bundle' + ext);
      fs.writeFileSync(dest, file.contents);
      if (ext === '.css') wroteCss = true;
    }
  }
  if (!wroteCss && sharedCss) {
    fs.writeFileSync(path.join(ckeditorOut, entry.name + '.bundle.css'), sharedCss);
  }
}
