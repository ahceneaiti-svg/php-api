# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Two Docker images plus a Kustomize bundle for local Minikube:

- `api/` — RESTful user-management API. PHP 8.3 / Symfony 7.4 on FrankenPHP, phpredis client. Published as `ahceneaiti/api-rest:1.0`.
- `redis/` — Redis 7.4-alpine + tuned `redis.conf` (AOF), the data store. Published as `ahceneaiti/redis:1.0`.
- `k8s/` — Kustomize bundle, namespace `user-api`.

There is no application framework skeleton on disk beyond the hand-written minimum; there is no test suite.

## Commands

```bash
# Build
docker build -t ahceneaiti/api-rest:1.0 ./api
docker build -t ahceneaiti/redis:1.0   ./redis

# Lint all PHP (no local php runtime assumed)
docker run --rm -v "$PWD/api":/app -w /app php:8.3-cli-alpine \
  sh -c 'for f in $(find . -name "*.php"); do php -l "$f" || exit 1; done'

# Run locally without k8s
docker compose up --build          # api on :8080, redis on :6379 (pass "devpass")

# Validate k8s manifests
kubectl kustomize k8s/
kubectl apply -k k8s/ --dry-run=client

# Deploy to Minikube (images pulled from Docker Hub, no local build needed)
minikube start && minikube addons enable ingress
kubectl apply -k k8s/
kubectl -n user-api rollout status deploy/redis
kubectl -n user-api rollout status deploy/user-api
```

Smoke-test the API (adjust host/port for compose `:8080`, port-forward, or `user-api.local` ingress):

```bash
curl -s localhost:8080/health
curl -s -XPOST localhost:8080/api/users -d '{"name":"Ada","email":"ada@example.com"}'
```

## Dependency handling — important

`api/composer.lock` is **not committed**. The API `Dockerfile` runs `composer update` at build time
(two layers: `composer.json` alone first for caching, then the code, then an authoritative autoload dump
and `cache:warmup`). Consequences:

- Changing any dependency or Symfony version means rebuilding the image; there is nothing to `composer install` against locally.
- Composer 2.8 **blocks packages with active security advisories at resolve time**. Symfony 7.1 is EOL and
  fails the build for this reason — the project is pinned to `7.4.*`. If a pinned version later gets an
  advisory, bump the constraint rather than disabling the policy.

## API architecture

Deliberately minimal Symfony, no `symfony/flex`, no `AbstractController`.

- `src/Kernel.php` uses `MicroKernelTrait`, which auto-loads `config/packages/*.yaml`, `config/services.yaml`,
  `config/routes.yaml`, and `config/bundles.php`. Only `FrameworkBundle` is registered.
- `config/routes.yaml` loads attribute routes from `src/Controller/`. Routes are declared with
  `#[Route]` from `Symfony\Component\Routing\Attribute\Route`.
- `config/services.yaml`: an `App\` glob autowires everything except `Kernel.php` and `Model/`; a second
  `App\Controller\` glob adds the `controller.service_arguments` tag so plain (non-`AbstractController`)
  controllers get `Request` and scalar route args (`int $id`) resolved. `RedisFactory` gets explicit env
  args (`REDIS_HOST`, `env(int:REDIS_PORT)`, `REDIS_PASSWORD`).

### Redis access pattern

`\Redis` is **not** injected as a service (avoids a lazy-proxy over a PHP internal class).
`App\Redis\RedisFactory::create()` builds a connected client with key prefix `user-api:`.
`App\Repository\UserRepository` takes the *factory*, connects lazily on first use, and caches the client.
`UserRepository::ping()` swallows connection errors so `/health` can report `503` instead of crashing.

### Data model in Redis

Keys carry the `user-api:` client prefix on top of:

| Key                   | Type   | Purpose                        |
|-----------------------|--------|--------------------------------|
| `user:seq`            | string | `INCR` id counter              |
| `users`               | set    | all existing ids               |
| `user:{id}`           | hash   | the user fields                |
| `user:email:{email}`  | string | id — unique index on email     |

### Endpoints

`GET /health/live` (always 200, liveness) · `GET /health` (checks Redis, 200/503, readiness) ·
`GET|POST /api/users` · `GET|PUT|PATCH|DELETE /api/users/{id}` (`{id}` is `\d+`).
Create/update validate `name` + `email`, return 409 on duplicate email, 422 on invalid body.

### Console

`php bin/console app:users:seed [--count=N] [--fresh]` (`src/Command/SeedUsersCommand.php`) generates
sample users through `UserRepository`. `--fresh` calls `UserRepository::flushAll()` (`FLUSHDB`, whole db)
first. Random names mean reruns without `--fresh` keep adding rows rather than being idempotent.
`k8s/seed-job.yaml` runs this command as a Job (`--count=50`), gated by an init container that waits on Redis.

### Runtime

FrankenPHP base image's own entrypoint serves `./public` on the port from `SERVER_NAME` (`:8080`).
No custom CMD. Config comes entirely from environment variables; `.env` only supplies dev defaults and
never overrides real env vars.

## Kubernetes bundle

`k8s/kustomization.yaml` is the entrypoint (`kubectl apply -k k8s/`). Bump image tags in its `images:`
block, not in the Deployment files.

- `seed-job.yaml` (Job `user-seed`) is part of the bundle. A completed Job is immutable, so re-running
  the seed needs `kubectl -n user-api delete job user-seed` before `kubectl apply -k k8s/` again
  (`ttlSecondsAfterFinished: 600` also reaps it automatically).

- The Redis password lives once in Secret `redis-auth`. Redis receives it as
  `--requirepass $(REDIS_PASSWORD)` (arg substitution from an env var sourced from the Secret);
  the API receives it as the `REDIS_PASSWORD` env var from the same Secret.
- `api-config` ConfigMap + `api-secret` Secret (`APP_SECRET`) feed the API via `envFrom`.
- `redis` Deployment is `strategy: Recreate` with a `ReadWriteOnce` PVC (`redis-data`, 1Gi, default StorageClass).
- API `startupProbe` + `readinessProbe` hit `/health` (gated on Redis); `livenessProbe` hits `/health/live`.
- Ingress host `user-api.local`, `ingressClassName: nginx` (needs `minikube addons enable ingress`).
- All Secrets in `k8s/*-secret.yaml` are local-dev placeholders.
