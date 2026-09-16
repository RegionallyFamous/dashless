import { defineConfig } from "astro/config";

const assetsPrefix = process.env.DASHLESS_ASSETS_PREFIX || undefined;

export default defineConfig({
  output: "static",
  trailingSlash: "always",
  build: {
    format: "directory",
    ...(assetsPrefix ? { assetsPrefix } : {}),
  },
});
