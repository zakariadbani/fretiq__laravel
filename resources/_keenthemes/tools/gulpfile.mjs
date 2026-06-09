import { cleanTask } from "./gulp/clean.mjs";
import {
  localHostTask,
  reloadTask,
  watchTask,
  watchSCSSTask,
  watchJSTask,
} from "./gulp/watch.mjs";
import {
  compileTask,
} from "./gulp/compile.mjs";

// Clean tasks:
export { cleanTask as clean };

// Watch tasks:
export { localHostTask as localhost };
export { reloadTask as reload };
export { watchTask as watch };
export { watchSCSSTask as watchSCSS };
export { watchJSTask as watchJS };

// Main tasks:
export { compileTask as compile };

// Entry point:
export default compileTask;
