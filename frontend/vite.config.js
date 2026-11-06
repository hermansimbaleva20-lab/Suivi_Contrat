import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/*
 * Écrit l'origine de l'API dans la politique de contenu (CSP) de dist/.htaccess : le navigateur ne laisse alors l'application
 * appeler que cette API. Une adresse relative (« /api ») désigne le même site, déjà couvert par 'self'.
 */
function securityHeaders(apiUrl) {
  let outDir = 'dist';
  return {
    name: 'prise-security-headers',
    apply: 'build',
    configResolved(config) { outDir = config.build.outDir; },
    closeBundle() {
      const file = resolve(outDir, '.htaccess');
      if (!existsSync(file)) return;
      let origin = '';
      try { origin = new URL(apiUrl).origin; } catch { origin = ''; }
      writeFileSync(file, readFileSync(file, 'utf8').replace(' __API_ORIGIN__', origin ? ` ${origin}` : ''));
    },
  };
}

// L'API Laravel est appelée via VITE_API_URL (voir .env). En développement : http://127.0.0.1:8000/api
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  return {
    plugins: [react(), tailwindcss(), securityHeaders(env.VITE_API_URL || '')],
    server: { port: 5173 },
    build: { sourcemap: false, chunkSizeWarningLimit: 900 },
  };
});
