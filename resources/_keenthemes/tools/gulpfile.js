/**
 * CJS stub: re-run gulp with ESM gulpfile to avoid MODULE_TYPELESS_PACKAGE_JSON warning
 * without adding "type": "module" to package.json.
 */
const path = require('path');
const { spawnSync } = require('child_process');

const result = spawnSync(process.execPath, [
  path.join(__dirname, 'node_modules/gulp/bin/gulp.js'),
  ...process.argv.slice(2),
  '--gulpfile',
  path.join(__dirname, 'gulpfile.mjs'),
], { stdio: 'inherit', cwd: __dirname });

process.exit(result.status != null ? result.status : 1);
