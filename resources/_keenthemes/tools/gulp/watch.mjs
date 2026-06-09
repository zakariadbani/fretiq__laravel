import gulp from "gulp";
import connect from "gulp-connect";
import { build } from "./build.mjs";
import { compileTask } from "./compile.mjs";
import { getDemo } from "./helpers.mjs";

// localhost site
const localHostTask = (cb) => {
  connect.server({
    root: "..",
    livereload: true,
  });
  cb();
};

const reloadTask = (cb) => {
  connect.reload();
  cb();
};

const watchTask = () => {
  return gulp.watch(
    [build.config.path.src + "/**/*.js", build.config.path.src + "/**/*.scss"],
    gulp.series(compileTask)
  );
};

const watchSCSSTask = () => {
  return gulp.watch(
    build.config.path.src + "/**/*.scss",
    gulp.parallel(compileTask)
  );
};

const watchJSTask = () => {
  return gulp.watch(
    build.config.path.src + "/**/*.js",
    gulp.parallel(compileTask)
  );
};


// Exports
export {
  localHostTask,
  reloadTask, watchTask, watchSCSSTask, watchJSTask
};
