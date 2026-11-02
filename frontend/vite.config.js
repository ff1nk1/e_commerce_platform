import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  build: {
    rollupOptions: {
      output: {
        // recharts is only used by lazily-loaded admin analytics; keeping it in
        // its own chunk guarantees it never lands in the shopper bundle.
        manualChunks(id) {
          if (id.includes("recharts") || id.includes("victory-vendor")) {
            return "recharts";
          }
          return undefined;
        },
      },
    },
  },
  server: {
    host: "0.0.0.0",
    port: 5173,
    allowedHosts: ["app.localhost", "localhost", "react_frontend"],
    hmr: {
      protocol: "wss",
      host: "app.localhost",
      clientPort: 443,
    },
  },
});
