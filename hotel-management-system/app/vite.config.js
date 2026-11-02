import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// In XAMPP, proxy target should match your folder name under htdocs.
const PHP_API =
  process.env.VITE_PHP_API_PROXY ||
  "http://localhost/hotel-management-system-complete";

export default defineConfig({
  plugins: [react()],
  base: "./",
  server: {
    port: 5173,
    proxy: {
      "/api": {
        target: PHP_API,
        changeOrigin: true,
        rewrite: (p) => p.replace(/^\/api/, "/api"),
      },
    },
  },
  build: {
    outDir: "../public/spa",
    emptyOutDir: true,
  },
});
