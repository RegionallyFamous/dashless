import { defineConfig } from 'astro/config';

export default defineConfig({
  output: 'static',
  site: 'https://dashless.blog',
  build: { format: 'directory' },
});
