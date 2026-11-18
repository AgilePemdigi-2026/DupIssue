# DupIssue - deployment via Coolify (panduan PPL PRAK)

**Sumber**: repo `AgilePemdigi-2026/DupIssue`, branch `main`, build pack `Docker Compose`, base directory `/`, compose file `/docker-compose.yaml`.

The archive contains NEW files only. Do not commit secrets or local testing scripts.

## 1. Integrasi file

Extract this archive to the ROOT of the DupIssue Git repository, not inside `backend`.

On PowerShell:

```powershell
cd 'C:\SEM5\PPL Prak\Projects\DupIssue\DupIssue'
git fetch origin
git switch -c chore/coolify-deployment origin/main
Expand-Archive -LiteralPath "$env:USERPROFILE\Downloads\DupIssue_Coolify_Deploy_Files.zip" -DestinationPath . -Force
git add -- docker-compose.yaml .dockerignore backend/Dockerfile nlp-service/Dockerfile deploy/apache/dupissue.conf deploy/entrypoint.sh README_DEPLOY_COOLIFY.md
git diff --cached --check
git diff --cached --stat
git commit -m 'chore(deploy): add Coolify Docker Compose stack'
git push -u origin chore/coolify-deployment
```

Open a PR `chore/coolify-deployment` -> `main`, have a teammate review, merge.

## 2. Set Coolify Git App resource

Project `PPL PRAK I1` > `production` > New Resource > Git Repository (with GitHub App) > supplied GitHub installation > repository `AgilePemdigi-2026/DupIssue` > branch `main` > build pack `Docker Compose`, base directory `/`, Docker Compose location `/docker-compose.yaml` > Continue.

If Coolify cannot find the file, check the deployment PR is merged into main.

In **Domains** -> Add Domain, select service **web**, use the professor-assigned hostname and internal port **80**. Do NOT expose mysql, nlp, or workers publicly.

## 3. Required secrets and variables in Coolify

Add via `Environment Variables` (do NOT put them in GitHub):

- `APP_KEY`: generate ONCE with `php artisan key:generate --show` in `backend`; keep stable across redeployments.
- `APP_URL`: `https://` followed by the EXACT professor-assigned domain.
- `DB_PASSWORD`: strong, randomly generated; must be distinct from MYSQL_ROOT_PASSWORD.
- `MYSQL_ROOT_PASSWORD`: different strong random password; stored in Coolify.
- `MIGRATE_ON_START`: `true` for empty initial DB. On later production releases, review migrations, have backups and consider changing to false and migrating manually.
- `MAIL_MAILER`: until SMTP is ready, `log` is placeholder ONLY; forgot-password OTP will intentionally FAIL in production.
- For SMTP configure `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` and if needed `MAIL_SCHEME` to provider settings. Never send these secrets in chat.

Other database env values already match the Compose file: host `mysql`, port `3306`, database `dupissue`, username `dupissue_app`; NLP URL `http://nlp:8000`.

## 4. Deploy

Check Coolify compose content recognizes 5 services: `mysql`, `nlp`, `web`, `worker-nlp`, `worker-default`.

Start Deploy. The NLP model installation and download is heavy and can take a while. Use Deployment Logs. If out-of-memory/disk, ask the infrastructure admin to check server capacity.

Watch runtime logs. The web starts only after MySQL is reachable and Laravel's pending migrations succeed. DO NOT run `migrate:fresh` on persistent data.

Smoke tests: landing page, Register, Login, Create/Join Project, Submit Issue -> NLP detection, Status, Vote/Unvote, Password Reset OTP (only with working SMTP). Check `/up` public health and the NLP container's `/health` internally.

## 5. Future GitHub changes

Git repository Compose supports manual deploy and webhooks. Automatic deploy requires **Auto deploy / Deploy on push** enabled on the Coolify resource and GitHub App webhooks correctly connected. For classroom production, manual deploy after CI and PR approval is safer; stage first if possible.

Persistent `mysql_data` is retained across app container updates, but test and back up before schema changes; image rollback does not restore a changed database.

## Notes

- Local Docker Desktop is not the Coolify server; the instructor's Coolify runs remotely.
- Do not publish Docker port mappings for MySQL or NLP.
- This stack builds Laravel PHP 8.3 + Apache and Vite assets, FastAPI Python 3.12, MySQL 8.0.46, 2 Laravel queue workers.
- Laravel APP_KEY must not change after first deployment.
- Coolify may need permissions/resources to build the NLP model dependencies and memory for the model.
