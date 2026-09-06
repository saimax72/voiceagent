# VoiceAgent - AI Voice & Chat Agent SaaS

A multi-tenant SaaS platform where businesses train an AI assistant on their website and documents, customise a voice + text widget, and embed it with one line of code. Built in plain PHP 8.1+ and MySQL so it runs on shared hosting such as Hostinger without Composer, Node or a build step.

## Features

- **Knowledge base (RAG)**: website crawler (sitemaps, robots.txt, re-scan), PDF/DOCX/PPTX/TXT/MD/CSV/HTML uploads, FAQs, free text, single URLs. Chunking + embeddings (OpenAI or Voyage) with hybrid vector + full-text retrieval in MySQL. Falls back to keyword search when no embedding provider is configured.
- **AI assistant**: Claude (Anthropic Messages API, streaming, tool use, server-side refusal fallbacks) with OpenAI / OpenAI-compatible providers as alternatives. Answers only from approved knowledge, logs unanswered questions, captures leads through a `save_lead` tool.
- **Voice**: browser speech recognition or server transcription (OpenAI), spoken replies via OpenAI or ElevenLabs neural voices (with browser fallback), sentence-level streaming TTS, hands-free voice mode, animated listening / thinking / speaking states.
- **Widget**: shadow-DOM embed (`widget.js`) that works on any site (HTML, WordPress, Shopify, Wix, Webflow, ...). Fully customisable: icon/logo, size, position, popup size, colours, fonts, radius, avatar, all texts, suggested questions, auto-open, branding. Live preview in the dashboard.
- **Dashboard**: onboarding wizard, agents, knowledge, conversations & transcripts, leads, unanswered questions ("teach answer"), analytics, settings, billing.
- **SaaS**: registration/login/password reset, tenant isolation, plans & limits, usage metering, trials, Stripe Checkout + webhooks, super-admin panel (workspaces, plans, provider keys, jobs, logs).
- **Background jobs**: database queue processed by a cron worker, with browser-driven fallback so training works even without cron.

## Requirements

- PHP 8.1 or newer with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `dom`, `fileinfo` (plus `zip` for Office files and `gd` for image resizing)
- MySQL 5.7+ / MariaDB 10.3+
- Apache or LiteSpeed with `.htaccess` support (Hostinger default)
- API keys: Anthropic (assistant), OpenAI (embeddings, speech-to-text, voices) and optionally Voyage AI, ElevenLabs, Stripe

## Deploy on Hostinger (Git)

1. In hPanel open **Websites -> Manage -> Advanced -> Git**, add this repository (`https://github.com/saimax72/voiceagent`, branch `main`) and deploy it into `public_html` (or a subdirectory / subdomain such as `voiceagent.yourdomain.com`).
2. Create a MySQL database and user under **Databases -> MySQL Databases**.
3. Make sure PHP 8.1+ is selected under **Advanced -> PHP Configuration** and that the extensions above are enabled (they are by default).
4. Open `https://your-domain/install.php`, enter the database details, application URL and your admin account. The installer creates the tables, plans and your super-admin user, then writes `config/config.php`.
5. Add a cron job under **Advanced -> Cron Jobs**, running every minute:
   ```
   php /home/USERNAME/domains/your-domain.com/public_html/cron/worker.php
   ```
   and optionally once a day:
   ```
   php /home/USERNAME/domains/your-domain.com/public_html/cron/maintenance.php
   ```
   If your plan has no CLI cron, use a web cron service (or Hostinger's "URL" cron type) to call `https://your-domain/webcron/run?token=YOUR_CRON_TOKEN` every minute; the token is shown at the end of the installation and lives in `config/config.php`.
   (Without any cron, the dashboard still processes jobs while a page is open.)
6. Sign in, go to **Admin -> Settings -> AI providers** and add your Anthropic and OpenAI keys. Use the "Test" buttons to verify.
7. Delete `install.php` from the server.

Later pushes to `main` can be pulled with the "Deploy" button (or the Hostinger auto-deploy webhook). `config/config.php`, `storage/` and `uploads/` are not tracked in git, so deployments never overwrite your configuration or data.

### Manual upload

Upload all files to `public_html` (keep the folder structure), then follow steps 2-7 above.

## Local development

```
php -S 127.0.0.1:8080 -t . dev/router.php
```

Then open `http://127.0.0.1:8080/install.php`. Run the worker with `php cron/worker.php`.

## Project structure

```
index.php            Front controller (all routes)
widget.js            Embeddable widget (shadow DOM, voice + text)
install.php          Web installer
app/
  bootstrap.php      Autoloader, config loading, error handling
  routes.php         Routes and middleware (auth, csrf, cors, throttle)
  Core/              Router, Request/Response, DB (PDO), Auth, Session, Crypto, Http (cURL), Mailer, Validator ...
  Controllers/       Dashboard, agents, knowledge, customisation, conversations, leads, analytics, billing, admin, widget API
  Services/
    AI/              Anthropic + OpenAI providers, embeddings, speech (STT/TTS), prompt builder
    Knowledge/       Crawler, HTML/PDF/Office extraction, chunker, indexer, hybrid retriever
    Chat/            Chat engine (RAG + tools + streaming), conversations, leads, unanswered questions
    Jobs/            Database job queue and runner
    Billing/         Stripe (Checkout, portal, webhooks)
  Views/             PHP templates (dashboard, auth, admin, marketing site, emails)
assets/              CSS, JS (Alpine.js vendored), icons
database/migrations/ SQL migrations (applied by the installer and Admin -> Run updates)
cron/                worker.php (every minute), maintenance.php (daily)
storage/             Uploaded documents, TTS cache, logs (protected)
uploads/             Public images (logos/avatars)
```

## Security notes

- All queries use prepared statements; every dashboard query is scoped to the signed-in tenant.
- CSRF tokens on all dashboard forms and AJAX calls; sessions are HttpOnly + SameSite.
- Provider API keys are stored encrypted (AES-256-GCM with the app key from `config/config.php`).
- The widget API is public but rate limited, scoped to the agent's public ID, restricted to allowed domains (optional), and conversation tokens are HMAC-signed.
- The crawler blocks private/loopback addresses (SSRF protection) and respects robots.txt.
- Protected directories (`app`, `config`, `database`, `storage`, `cron`) are denied via `.htaccess`.

## License

Proprietary. All rights reserved.
