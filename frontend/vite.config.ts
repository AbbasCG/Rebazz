import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { viteSingleFile } from 'vite-plugin-singlefile';

// Modi:
//  - dev:    npm run dev            (/api gaat door naar Laravel op poort 8000)
//  - build:  npm run build          (naar backend/public/app, geserveerd door Laravel)
//  - share:  npx vite build --mode share  (één los HTML-bestand voor een deellink, zonder server)
export default defineConfig(({ command, mode }) => {
  if (mode === 'share') {
    return {
      base: './',
      plugins: [react(), viteSingleFile()],
      build: { outDir: 'dist-share', emptyOutDir: true }
    };
  }
  return {
    // In productie staan de bestanden in /app/ op de Laravel-server.
    base: command === 'build' ? '/app/' : '/',
    plugins: [react()],
    server: {
      port: 5173,
      proxy: {
        '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true }
      }
    },
    build: {
      outDir: '../backend/public/app',
      emptyOutDir: true,
      rolldownOptions: {
        output: {
          // Bibliotheken in eigen bestanden: de browser cachet ze apart en ze veranderen zelden bij een winkelupdate.
          codeSplitting: {
            groups: [
              { name: 'vendor-react', test: /node_modules[\\/](react|react-dom|react-router|react-router-dom|scheduler)[\\/]/ },
              { name: 'vendor-motion', test: /node_modules[\\/](motion|motion-dom|motion-utils|framer-motion)[\\/]/ },
              { name: 'vendor-gsap', test: /node_modules[\\/](gsap|@gsap)[\\/]/ }
            ]
          }
        }
      }
    }
  };
});
