---
description: Reset the development environment to a clean and functional state
---

# Reset Environment Procedure

Use this workflow to fix "glitchy" states (missing files, caching issues, infinite loading due to old assets).

1. **Restart Containers** (Fixes Docker volume sync issues)
   ```bash
   docker compose restart app
   ```
   // turbo

2. **Fix Permissions** (Ensures web server can read new files)
   ```bash
   chmod -R 777 js css
   ```
   // turbo

3. **Clear Nextcloud Cache** (Fixes "Resource not found" errors)
   ```bash
   docker compose exec --user www-data app php occ maintenance:repair
   ```

4. **Rebuild Frontend** (Ensures JS matches source code)
   ```bash
   npm run build
   ```

5. **Verify**
   - Refresh page (Ctrl+F5)
   - Check if `js/` files exist: `ls -l js/`
