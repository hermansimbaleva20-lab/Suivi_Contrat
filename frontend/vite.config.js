import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// L'API Laravel est appelée via VITE_API_URL (voir .env). En développement : http://127.0.0.1:8000/api
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: { port: 5173 },
  build: { sourcemap: false, chunkSizeWarningLimit: 900 },
});
