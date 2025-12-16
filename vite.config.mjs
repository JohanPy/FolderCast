import { createAppConfig } from "@nextcloud/vite-config";
import { join, resolve } from "path";

const target = process.env.TARGET || 'all';

const inputs = {};
if (target === 'main' || target === 'all') {
  inputs['foldercast-main'] = resolve(join("src", "main.js"));
}
if (target === 'files' || target === 'all') {
  inputs['foldercast-files'] = resolve(join("src", "files.js"));
}

export default createAppConfig(
  inputs,
  {
    createEmptyCSSEntryPoints: true,
    extractLicenseInformation: true,
    thirdPartyLicense: false,
    config: {
      build: {
        outDir: process.env.OUTDIR || '.', // Allow override
        emptyOutDir: true, // Safe to empty if we use separate dirs
        alias: {
          vue: 'vue/dist/vue.runtime.common.js'
        },
        rollupOptions: {
          output: {
            format: 'iife',
            inlineDynamicImports: true, // Now we can use this if we have single input!
            entryFileNames: "js/[name].js", // Revert to standard name, we'll build sequentially
            chunkFileNames: "js/chunks/[name]-[hash].js",
            assetFileNames: (assetInfo) => {
              if (assetInfo.name && assetInfo.name.endsWith('.css')) {
                return 'css/[name].css'; // Remove hash for predictable loading
              }
              return 'img/[name][extname]';
            }
          }
        }
      }
    }
  }
);
