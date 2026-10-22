# Proxy Domains (Gate Proxy & Velocity) — Pelican Panel Plugin

This plugin for **Pelican Panel** allows each Minecraft server to have a **Proxy Domain** tab where users can choose their subdomain prefix (e.g., `point-zero` to get `point-zero.pelican.dev`), and automatically synchronizes all routes to the selected **Gate Proxy** (Lite mode) or **Velocity** server in the administration panel.

---

## Features

1. **Server Menu (`Proxy Domain`)**:
   - Displayed only on Minecraft containers (Proxy servers themselves and other games are automatically excluded).
   - Users modify **only the prefix** (e.g., `point-zero`), the suffix `.pelican.dev` is displayed automatically on the right side of the field.
   - **Save** button that checks if the name is available, saves it to the database, and updates the Proxy configuration file in real-time via the Wings API.

2. **Admin Menu (`Proxy Router`)**:
   - Located in the Admin Panel under **Advanced -> Proxy Router** (as well as in plugin settings).
   - Allows selecting the container running **Gate Proxy** or **Velocity** (only servers identified as Proxy appear in the list).
   - Allows setting the main domain (e.g., `pelican.dev`), proxy type (`Gate` or `Velocity`), and blacklisted subdomains.

3. **Automatic Synchronization without Downtime**:
   - **Gate Proxy (`config.yml`)**: Updates the `config.lite.routes` section in the exact expected format. Gate detects the file modification and hot-reloads routes **instantly without restarting** or disconnecting players.
   - **Velocity (`velocity.toml`)**: Updates the `[servers]` and `[forced-hosts]` sections, then automatically sends the `velocity reload` command to the proxy console if it's running.

---

## DNS Requirements (Important)

At your DNS provider (e.g., Cloudflare, OVH, etc.), create a **Wildcard** DNS record pointing to the IP address of the machine hosting Gate Proxy / Velocity:
- **Type `A`**: Name `@` (or `pelican.dev`) -> Public IP of the Proxy
- **Type `A` (or `CNAME`)**: Name `*` (i.e., `*.pelican.dev`) -> Public IP of the Proxy (with Cloudflare **Grey / DNS Only** cloud if you're not using Spectrum).

This way, any subdomain created in the panel (`point-zero.pelican.dev`, `greg-kev.pelican.dev`, etc.) will go directly to your Gate Proxy or Velocity!

---

## Installation on Pelican Panel

### Method 1: Via Admin Interface (ZIP File)
1. In the Pelican Admin Panel, go to **Plugins**.
2. Click **Import** in the top right and select the **`proxy-domains.zip`** file.
3. Click **Install** on the line for the **Proxy Domains** plugin.

### Method 2: Manually (SSH)
1. Copy the `proxy-domains` folder to `/var/www/pelican/plugins/proxy-domains`.
2. Run the following command in `/var/www/pelican`:
   ```bash
   php artisan p:plugin:install proxy-domains
   ```
---

## Languages

The plugin follows the language chosen by each user in their Pelican profile (**Profile → Language**), so every user sees the interface in their own language. 33 languages are included, matching the Pelican Panel community translations (folder names follow the Panel's `lang/` codes):

`en` English, `fr` Français, `es` Español, `de` Deutsch, `it` Italiano, `nl` Nederlands, `pt` Português, `pt_BR` Português (Brasil), `pl` Polski, `cs` Čeština, `sk` Slovenčina, `ro` Română, `hu` Magyar, `da` Dansk, `sv` Svenska, `no` Norsk, `fi` Suomi, `el` Ελληνικά, `tr` Türkçe, `ru` Русский, `uk` Українська, `be` Беларуская, `bg` Български, `sr` Српски (ћирилица), `lt` Lietuvių, `ar` العربية, `he` עברית, `id` Bahasa Indonesia, `vi` Tiếng Việt, `ja` 日本語, `ko` 한국어, `zh_CN` 简体中文, `zh_TW` 繁體中文.

To add a language, copy `lang/en/strings.php` to `lang/<code>/strings.php` and translate the values (keep the keys and the `:domain` placeholder untouched).

## Changelog

### 1.0.1
- French translation rewritten (the `fr` file was still in English).
- Added 31 translations (all languages of the Pelican Panel Crowdin project).
- Proxy type labels and the subdomain preview example are now translatable.
