import { defineConfig } from 'vite';
import { resolve } from 'path';

// Vite config untuk minify+hash asset Agenda Pimpinan.
// Build: npx vite build → output ke dist/
// Deploy: copy dist/*.css + dist/*.js ke assets/

export default defineConfig({
  build: {
    outDir: 'dist',
    assetsDir: '',
    manifest: true,
    rollupOptions: {
      input: {
        app: resolve(__dirname, 'src/css/app.css'),
      },
    },
    minify: 'esbuild',
    cssCodeSplit: false,
  },
});
