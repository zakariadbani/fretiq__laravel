import gulp from "gulp";
import rename from "gulp-rename";
import rtlcss from "gulp-rtlcss";
import sass from "gulp-dart-sass";
import _ from "lodash";
import {build as buildMaster} from "./build.mjs";
import {argv, getDemo, getTheme, objectWalkRecursive, dotPath, pathOnly, outputFunc, bundle, getFolders, clearCollectedStreams, getCollectedStreams} from "./helpers.mjs";
import {cleanTask} from "./clean.mjs";
import fs from "fs";
import * as pathDir from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';

const __filename = fileURLToPath(import.meta.url);
const __dirname = pathDir.dirname(__filename);

// merge with default parameters
const args = Object.assign(
    {
        prod: false,
        sourcemap: false,
        rtl: "",
        exclude: "",
        theme: "",
        path: "",
        angular: false,
        react: false,
        vue: false,
        suffix: false,
    },
    argv
);

var build = buildMaster;
const demo = getDemo();

let theme = '';

const tasks = [];

// merge gulp.config.json in release version
if (fs.existsSync(`${__dirname}/../gulp.config.json`)) {
    var c = fs.readFileSync(`${__dirname}/../gulp.config.json`, 'utf8');
    build = _.merge(build, JSON.parse(c));
} else {
    // set theme
    theme = getTheme();

    // merge config for theme which have demo levels
    const themeConfPath = `./../../../../themes/${theme}/html/tools/gulp.config.json`;
    if (fs.existsSync(`${__dirname}/${themeConfPath}`)) {
        var c = fs.readFileSync(`${__dirname}/${themeConfPath}`, 'utf8');
        build = _.merge(build, JSON.parse(c));
    }

    // merge config for demo levels
    const demoConfPath = `./../../../../themes/${theme}/html/${demo}/gulp.config.json`;
    if (fs.existsSync(`${__dirname}/${demoConfPath}`)) {
        var c = fs.readFileSync(`${__dirname}/${demoConfPath}`, 'utf8');
        build = _.extend(build, JSON.parse(c));
    }
}


// docs build option
let docsOption = Object.keys(argv).find((value) => {
    return value.indexOf('docs-') !== -1;
});
if (typeof docsOption !== 'undefined') {
    theme = docsOption.replace('docs-', '');
    if (theme === 'asp') {
        theme += '.net-core'
    }
    build.config.path.src = '../../../themes/docs/{theme}/src';
    build.config.dist = ['../../../themes/docs/{theme}/assets'];
}


// set dist to demo folder (e.g. themes/metronic/html/demo1/assets)
const dist = [];
build.config.dist.forEach((d) => {
    const withTheme = d.replace("{theme}", theme);
    const withDemo = withTheme.replace(/\{demo\}\/?/g, demo ? demo + '/' : '');
    dist.push(withDemo);
});
build.config.dist = dist;

const path = {};
for (let key in build.config.path) {
    if (!build.config.path.hasOwnProperty(key)) {
        continue;
    }
    path[key] = build.config.path[key]
        .replace("{demo}", demo)
        .replace("{theme}", theme);
}
build.config.path = path;

if (args.prod !== false) {
    // force disable debug for production
    build.config.debug = false;
    // force assets minification for production
    build.config.compile.jsMinify = true;
    build.config.compile.cssMinify = true;
}

if (args.sourcemap !== false) {
    // force assets sourcemap
    // build.config.compile.jsSourcemaps = true;
    // build.config.compile.cssSourcemaps = true;
}

if (args.rtl) {
    build.config.compile.rtl.enabled = true;
}

const rtlTask = () => {
    const streams = [];
    let stream = null;
    objectWalkRecursive(
        build.build,
        (val, key, userdata) => {
            if (val.hasOwnProperty("src") && val.hasOwnProperty("dist")) {
                if (["custom", "media", "api", "misc"].indexOf(key) !== -1) {
                    if (userdata.indexOf(key) === -1 && typeof val.styles !== "undefined") {
                        // rtl conversion in each plugins
                        for (let i in val.styles) {
                            if (!val.styles.hasOwnProperty(i)) {
                                continue;
                            }

                            const toRtlFile = dotPath(val.styles[i]);
                            // exclude scss file for now
                            if (toRtlFile.indexOf(".scss") === -1 && !/\*/.test(toRtlFile)) {
                                stream = gulp.src(toRtlFile, {allowEmpty: true})
                                    .pipe(rtlcss())
                                    .pipe(rename({suffix: ".rtl"}))
                                    .pipe(gulp.dest(pathOnly(toRtlFile)));
                                streams.push(stream);

                                // convert rtl for minified
                                if (!/\.min\./i.test(toRtlFile)) {
                                    stream = gulp.src(toRtlFile, {allowEmpty: true})
                                        .pipe(
                                            sass({outputStyle: "compressed"}).on("error", sass.logError)
                                        )
                                        .pipe(rename({suffix: args.suffix ? ".min.rtl" : ".rtl"}))
                                        .pipe(gulp.dest(pathOnly(toRtlFile)));
                                    streams.push(stream);
                                }
                            }
                        }
                    }
                }
            }
        },
        build.config.compile.rtl.skip
    );
    if (streams.length === 0) return Promise.resolve();
    return Promise.all(streams.map(s => finished(s)));
};

// task to build CKEditor 5 bundles (esbuild; gulp-only, no webpack)
let buildCkeditorTask = (cb) => {
    const toolsDir = pathDir.join(__dirname, '..');
    const distPath = pathDir.resolve(toolsDir, build.config.dist[0]);
    try {
        execSync('node gulp/ckeditor-build.cjs', {
            cwd: toolsDir,
            env: { ...process.env, CKEDITOR_DIST: distPath },
            stdio: 'inherit',
        });
    } catch (e) {
        return cb(e);
    }
    cb();
};

// Wait for a single stream to finish (works with Gulp 5 / streamx: listen for end/finish/close)
function whenStreamDone(stream) {
    return new Promise((resolve, reject) => {
        const onDone = () => {
            cleanup();
            resolve();
        };
        const onError = (err) => {
            cleanup();
            reject(err);
        };
        const cleanup = () => {
            stream.off("end", onDone);
            stream.off("finish", onDone);
            stream.off("close", onDone);
            stream.off("error", onError);
        };
        stream.once("end", onDone);
        stream.once("finish", onDone);
        stream.once("close", onDone);
        stream.once("error", onError);
    });
}

// task to bundle js/css (Gulp v5: signal completion via callback so gulp reliably knows when we're done)
let buildBundleTask = (done) => {
    clearCollectedStreams();
    objectWalkRecursive(build.build, function (val, key) {
        if (val.hasOwnProperty("src") && val.hasOwnProperty("dist")) {
            if (["custom", "media", "api", "misc"].indexOf(key) !== -1) {
                outputFunc(val);
            } else {
                bundle(val);
            }
        }
    });
    const allStreams = getCollectedStreams();
    if (allStreams.length === 0) {
        done();
        return;
    }
    Promise.all(allStreams.map((s) => whenStreamDone(s)))
        .then(() => done())
        .catch(done);
};

// don't clean assets if compile only 1 type
if (!args.sass && !args.js && !args.media) {
    tasks.push(cleanTask);
}

if (typeof build.config.compile.rtl !== "undefined" && build.config.compile.rtl.enabled) {
    tasks.push(rtlTask);
}
tasks.push(buildCkeditorTask);
tasks.push(buildBundleTask);

if (args.presets && fs.existsSync(build.config.path.src + '/sass/presets')) {

    const presets = fs.readdirSync(build.config.path.src + '/sass/presets');

    objectWalkRecursive(build.build, function (val, key) {
        if (val.hasOwnProperty("src") && val.hasOwnProperty("dist")) {
            if (["custom", "media", "api", "misc"].indexOf(key) !== -1) {
            } else {
                // build for presets
                if (typeof val.src.styles !== 'undefined') {
                    if (val.src.styles[0].indexOf('style.scss') !== -1) {
                        presets.forEach(preset => {
                            const buildStylePresetTask = () => {
                                val.src.styles[0] = '{$config.path.src}/sass/presets/' + preset + '/style.scss';
                                val.dist.styles = '{$config.dist}/css/style.' + preset + '.bundle.css';
                                const streams = bundle(val);
                                return streams.length ? Promise.all(streams.map(s => finished(s))) : Promise.resolve();
                            };
                            tasks.push(buildStylePresetTask);
                        });
                    }
                }

                if (typeof val.src.override !== 'undefined' && val.src.override.styles[0].indexOf('plugins.scss') !== -1) {
                    presets.forEach(preset => {
                        const buildPluginPresetTask = () => {
                            val.src.styles.forEach((file, i) => {
                                if (file.indexOf('plugins.scss') !== -1) {
                                    val.src.styles[i] = '{$config.path.src}/sass/presets/' + preset + '/plugins.scss';
                                    val.dist.styles = '{$config.dist}/plugins/global/plugins.' + preset + '.bundle.css';
                                }
                            });
                            const streams = bundle(val);
                            return streams.length ? Promise.all(streams.map(s => finished(s))) : Promise.resolve();
                        };
                        tasks.push(buildPluginPresetTask);
                    });
                }
            }
        }
    }, build.build);
}

// entry point
const compileTask = gulp.series(...tasks);

// Exports
export {
    compileTask,
};
