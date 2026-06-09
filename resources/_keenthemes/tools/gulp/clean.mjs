import { deleteAsync } from 'del';
import { build } from "./build.mjs";
import { getDemo } from "./helpers.mjs";

// task to clean and delete dist directory content
const getPaths = () => {
  const paths = ['!config'];
  const outputs = build.config.dist;
  outputs.forEach((output) => {
    paths.push(output.replace("{demo}", getDemo()));
  });

  const realpaths = [];
  paths.forEach((path) => {
    realpaths.push(path + "/*");
    // Exclude uploads directory from cleanup to preserve user-uploaded files
    realpaths.push("!" + path + "/uploads");
    realpaths.push("!" + path + "/uploads/**");
  });

  return realpaths;
};

export const cleanTask = () => {
  return deleteAsync(getPaths(), { force: true });
};
