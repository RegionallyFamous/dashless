import { defineConfig } from "astro/config";

const assetsPrefix = process.env.DASHLESS_ASSETS_PREFIX || undefined;

export default defineConfig({
  output: "static",
  trailingSlash: "always",
  // Keep navigation instant without eagerly fetching every page. Astro falls
  // back to a normal navigation when a browser or connection cannot prefetch.
  prefetch: {
    prefetchAll: false,
    defaultStrategy: "hover",
  },
  build: {
    format: "directory",
    ...(assetsPrefix ? { assetsPrefix } : {}),
  },
});
