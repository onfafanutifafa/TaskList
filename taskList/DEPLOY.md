# DEPLOY.md — running Node PSP on the cloud (and the same image locally)

Node ships one Docker image (FrankenPHP + PHP 8.4) that plays two roles chosen by
the entrypoint: **web** (default) and **horizon** (queue worker). The exact image
you deploy to Render is the one you can run locally first.

> Sandbox vs real money: hosted or not, the app makes real HTTPS calls. Against the
> MTN **sandbox** those calls simulate transactions — no real money moves. Moving
> real money additionally needs production provider credentials **and** the
> regulated pieces (licensing, KYC/AML, PCI, settlement). The code is ready; those
> are business/legal steps.

---

## 1. Test the production image locally

Everything runs from `taskList/`. This uses the same `Dockerfile` Render builds.

```bash
cd taskList

# Build the image
docker build -t node-psp:local .

# Bring up the full stack: Postgres + Redis + app (web) + worker (Horizon)
docker compose --profile app up --build
```

- App: <http://127.0.0.1:8080>  (health: <http://127.0.0.1:8080/up>)
- The `app` service runs migrations on boot (`RUN_MIGRATIONS=true`) and serves on `:8080`.
- The `worker` service runs `php artisan horizon` against the same Postgres + Redis.

Seed a merchant / run any artisan command inside the image:

```bash
docker compose --profile app run --rm app artisan db:seed
docker compose --profile app run --rm app artisan momo:provision-sandbox --write
```

Then hit the API exactly as in [INSTRUCTIONS.md](INSTRUCTIONS.md), on port `8080`.

> Infra-only (app on the host instead of in Docker): `docker compose up -d` starts
> just Postgres + Redis; point `.env` at them and run `php artisan serve` +
> `php artisan horizon` on the host.

---

## 2. Deploy to Render (Blueprint)

The repo root has [`render.yaml`](../render.yaml) describing four resources: a
**web** service, a **worker** (Horizon), managed **Postgres**, and **Redis**.

1. Push this repo to GitHub (already done).
2. In Render: **New → Blueprint**, pick the repo. Render reads `render.yaml`.
3. Fill the `sync: false` secrets when prompted:
   - `APP_KEY` — generate locally: `php artisan key:generate --show` (the whole
     `base64:...` string).
   - `APP_URL` — your web service URL, e.g. `https://node-psp-web.onrender.com`.
   - `HORIZON_DASHBOARD_EMAILS` — comma-separated emails allowed to open `/horizon`.
   - MTN keys + `PSP_CRYPTO_WATCHER_SECRET` + `PSP_BANKING_WEBHOOK_SECRET` as needed.
4. Apply. Render builds the image once and runs both services from it; `DB_URL` and
   `REDIS_URL` are injected from the managed Postgres/Redis. The web service runs
   `php artisan migrate --force` as its **preDeploy** step.

Health check is `/up`. Logs go to `stderr` (`LOG_CHANNEL=stderr`) so they show in
Render's log stream.

### Public webhook URLs
Once live, your providers call these (set the matching `*_CALLBACK_URL` / provider
dashboards to your `APP_URL`):
- `POST {APP_URL}/webhooks/mtn-momo/collection/{ref}` and `/disbursement/{ref}`
- `POST {APP_URL}/webhooks/crypto/{ref}`
- `POST {APP_URL}/webhooks/banking/{account}`

---

## 3. Other hosts

The image is portable — anything that runs a container works:
- **Fly.io:** `fly launch` (Docker), add Fly Postgres + Upstash Redis, run the
  worker as a second process/machine, set the same env.
- **Railway / DigitalOcean App Platform / ECS/Fargate / Cloud Run:** deploy the
  image as a web service + a worker service, attach Postgres + Redis, set env.

Any host needs: the web service (bind `$PORT`), a **worker** running `horizon`, a
**cron** running `php artisan schedule:run` every minute, plus **Postgres** and
**Redis**.

---

## 4. Production checklist (beyond this guide)
- `APP_DEBUG=false`, unique `APP_KEY`, HTTPS only (already forced in prod).
- Upgrade Postgres/Redis off free plans; enable Postgres backups.
- Swap `php artisan serve`-style dev flows for the container (done here).
- Real provider credentials + the regulatory/licensing/liquidity work.
- Rotate the webhook secrets; restrict `HORIZON_DASHBOARD_EMAILS`.
