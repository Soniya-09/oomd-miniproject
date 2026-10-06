import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// The Frappe backend (schedunova site). Requests to /api are proxied there so the
// browser sees a single origin: session cookies just work and no CORS setup is needed.
const BACKEND = process.env.SCHEDUNOVA_BACKEND || "http://127.0.0.1:8002";

export default defineConfig({
  plugins: [react()],
  server: {
    host: true,
    port: 5173,
    proxy: {
      "/api": { target: BACKEND, changeOrigin: true },
    },
  },
});
