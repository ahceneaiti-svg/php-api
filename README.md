# user-api + redis

Two Docker images and a Kubernetes bundle for local Minikube.

| Piece      | Path    | Image                       | What                                                        |
|------------|---------|-----------------------------|------------------------------------------------------------|
| Image 1    | `api/`  | `ahceneaiti/api-rest:1.0`   | RESTful user-management API — PHP 8.3 / Symfony 7.4 on FrankenPHP. |
| Image 2    | `redis/`| `ahceneaiti/redis:1.0`      | Redis 7.4 (alpine) with AOF persistence — the data store.  |
| Deploy     | `k8s/`  | –                           | Kustomize bundle: Namespace, Secrets, PVC, Deployments, Services, Ingress. |

## API

Users are stored in Redis (hash per user, `SET` id index, unique email index).

| Method | Path              | Body                     | Result           |
|--------|-------------------|--------------------------|------------------|
| GET    | `/health/live`    | –                        | 200 always (liveness) |
| GET    | `/health`         | –                        | 200 / 503 (checks Redis) |
| GET    | `/api/users`      | –                        | 200 `[User]`     |
| POST   | `/api/users`      | `{"name","email"}`       | 201 `User` / 409 / 422 |
| GET    | `/api/users/{id}` | –                        | 200 `User` / 404 |
| PUT    | `/api/users/{id}` | `{"name","email"}`       | 200 `User` / 404 / 409 / 422 |
| DELETE | `/api/users/{id}` | –                        | 204 / 404        |

Env: `APP_SECRET`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`.

Data seeding: `php bin/console app:users:seed [--count=N] [--fresh]` (`--fresh` flushes the db first).
`k8s/seed-job.yaml` runs it as a Job on deploy.

> No `composer.lock` is committed; the API image runs `composer update` at build time.

## Option A — docker compose (no k8s)

```bash
docker compose up --build
curl -s localhost:8080/health
curl -s -XPOST localhost:8080/api/users -d '{"name":"Ada","email":"ada@example.com"}'
curl -s localhost:8080/api/users

# seed sample data
docker compose exec api php bin/console app:users:seed --count=50 --fresh
```

## Option B — Deploy on Kubernetes (Minikube)

The images live on Docker Hub (`ahceneaiti/api-rest:1.0`, `ahceneaiti/redis:1.0`), so a plain
`kubectl apply -k k8s/` is enough — no local build, no registry setup.

### 1. Prerequisites

| Tool       | Min version | Notes                                          |
|------------|-------------|------------------------------------------------|
| `minikube` | 1.32+       | any driver; examples use `--driver=docker`     |
| `kubectl`  | 1.28+       | must match the cluster within ±1 minor         |
| host       | –           | ~2 vCPU / ~2.2 GB free for the VM/container    |

Optional: `jq` for readable JSON, `stern` for multi-pod logs.

### 2. What the bundle creates

`k8s/kustomization.yaml` is the single entrypoint. Applying it renders every manifest, forces them all
into the `user-api` namespace, and rewrites the image tags from its `images:` block.

| Manifest                 | Object(s)                                   | Role                                                                 |
|--------------------------|---------------------------------------------|---------------------------------------------------------------------|
| `namespace.yaml`         | Namespace `user-api`                        | isolates everything below                                          |
| `redis-secret.yaml`      | Secret `redis-auth`                         | `REDIS_PASSWORD`, consumed by both Redis and the API               |
| `api-secret.yaml`        | Secret `api-secret`                         | `APP_SECRET` for Symfony                                           |
| `api-configmap.yaml`     | ConfigMap `api-config`                      | `APP_ENV`, `REDIS_HOST=redis`, `REDIS_PORT=6379`                  |
| `redis-pvc.yaml`         | PVC `redis-data` (1 Gi, RWO)               | AOF/RDB files, default StorageClass                                |
| `redis-deployment.yaml`  | Deployment `redis` (1 replica, `Recreate`) | Redis 7.4, `--requirepass $(REDIS_PASSWORD)`, probes via `redis-cli ping` |
| `redis-service.yaml`     | Service `redis` (ClusterIP :6379)          | stable in-cluster address `redis.user-api.svc`                     |
| `api-deployment.yaml`    | Deployment `user-api` (2 replicas)         | FrankenPHP on :8080; startup+readiness probes on `/health`, liveness on `/health/live` |
| `api-service.yaml`       | Service `user-api` (ClusterIP :80 → 8080)  | fronts the API pods                                               |
| `ingress.yaml`           | Ingress `user-api` (class `nginx`)         | host `user-api.local` → `user-api:80`                             |
| `seed-job.yaml`          | Job `user-seed`                             | initContainer waits for Redis, then `php bin/console app:users:seed --count=50` |

Startup ordering is enforced by probes, not by apply order: the API pods stay `0/1 Running` (NotReady)
until Redis answers `/health`, and the seed Job's initContainer blocks until Redis replies to `PING`.

### 3. Start the cluster

```bash
minikube start --cpus=2 --memory=2200 --driver=docker
minikube addons enable ingress

# wait until the ingress controller is Running (first enable pulls an image)
kubectl -n ingress-nginx get pods -w
```

### 4. Apply the bundle

```bash
# optional: see exactly what will be sent, without applying
kubectl kustomize k8s/ | less
kubectl apply -k k8s/ --dry-run=server

# apply for real
kubectl apply -k k8s/
```

Expected output:

```
namespace/user-api created
secret/redis-auth created
secret/api-secret created
configmap/api-config created
persistentvolumeclaim/redis-data created
service/redis created
service/user-api created
deployment.apps/redis created
deployment.apps/user-api created
job.batch/user-seed created
ingress.networking.k8s.io/user-api created
```

### 5. Watch it come up

```bash
kubectl -n user-api get all
kubectl -n user-api rollout status deploy/redis    --timeout=120s
kubectl -n user-api rollout status deploy/user-api  --timeout=120s
kubectl -n user-api get pods -w
```

All pods should reach `READY 1/1`; the Job pod ends as `Completed`.

### 6. Run / re-run the seed Job

The Job runs once automatically on first apply. To inspect it:

```bash
kubectl -n user-api get jobs
kubectl -n user-api logs job/user-seed -f
kubectl -n user-api logs job/user-seed -c wait-for-redis   # the init container
```

A finished Job is immutable, so re-seeding needs a delete first (it also self-deletes after
`ttlSecondsAfterFinished: 600`):

```bash
kubectl -n user-api delete job user-seed
kubectl apply -k k8s/
```

One-off custom run without touching the manifest:

```bash
PW=$(kubectl -n user-api get secret redis-auth -o jsonpath='{.data.REDIS_PASSWORD}' | base64 -d)
kubectl -n user-api run seed --rm -i --restart=Never \
  --image=ahceneaiti/api-rest:1.0 \
  --env=APP_SECRET=x --env=REDIS_HOST=redis --env=REDIS_PORT=6379 --env=REDIS_PASSWORD="$PW" \
  -- php bin/console app:users:seed --count=200 --fresh
```

### 7. Reach the API

**Option A — Ingress (closest to a real setup)**

```bash
echo "$(minikube ip) user-api.local" | sudo tee -a /etc/hosts
curl -s http://user-api.local/health
```

With the `docker` driver on macOS/Windows the cluster IP is not routable directly — keep a tunnel open
in another terminal and point the host entry at localhost instead:

```bash
minikube tunnel                       # keep running (asks for sudo)
echo "127.0.0.1 user-api.local" | sudo tee -a /etc/hosts
```

**Option B — port-forward (no hosts edit, no ingress)**

```bash
kubectl -n user-api port-forward svc/user-api 8080:80
curl -s localhost:8080/api/users
```

**Option C — minikube service**

```bash
minikube service user-api -n user-api --url
```

### 8. Smoke test

```bash
BASE=http://user-api.local            # or http://localhost:8080 with port-forward

curl -s $BASE/health
curl -s -XPOST $BASE/api/users -H 'Content-Type: application/json' \
  -d '{"name":"Ada Lovelace","email":"ada@example.com"}'
curl -s $BASE/api/users | jq 'length'
```

### 9. Inspect and debug

```bash
kubectl -n user-api get pods -o wide
kubectl -n user-api describe pod -l app=user-api
kubectl -n user-api logs -l app=user-api --tail=50 -f
kubectl -n user-api logs -l app=redis  --tail=50
kubectl -n user-api get events --sort-by=.lastTimestamp

# poke Redis directly
PW=$(kubectl -n user-api get secret redis-auth -o jsonpath='{.data.REDIS_PASSWORD}' | base64 -d)
kubectl -n user-api exec -it deploy/redis -- redis-cli -a "$PW" --no-auth-warning \
  --scan --pattern 'user-api:*'
kubectl -n user-api exec -it deploy/redis -- redis-cli -a "$PW" --no-auth-warning SCARD user-api:users
```

### 10. Update to a new image version

`imagePullPolicy: IfNotPresent` + a reused tag means the node keeps the cached layer — always publish a
**new tag** and bump `k8s/kustomization.yaml`:

```bash
docker build -t ahceneaiti/api-rest:1.1 ./api && docker push ahceneaiti/api-rest:1.1
# edit k8s/kustomization.yaml -> images: - name: ahceneaiti/api-rest / newTag: "1.1"
kubectl apply -k k8s/
kubectl -n user-api rollout status deploy/user-api
kubectl -n user-api rollout undo deploy/user-api      # roll back if needed
```

Rebuild Redis the same way (`ahceneaiti/redis:<tag>`) when `redis/redis.conf` or its Dockerfile changes.

### 11. Scale the API

```bash
kubectl -n user-api scale deploy/user-api --replicas=4
kubectl -n user-api get pods -l app=user-api -w
```

### 12. Teardown

```bash
kubectl delete -k k8s/     # removes the namespace and, with it, the PVC and all seeded data
minikube stop              # or: minikube delete   (wipes the whole cluster)
```

## Connect to Redis

Keys carry the `user-api:` client prefix (set in `RedisFactory`). Handy commands once connected:

```redis
PING
SCAN 0 MATCH user-api:* COUNT 100      # never use KEYS in anything real
SCARD  user-api:users                   # how many users
GET    user-api:user:seq               # last id handed out
HGETALL user-api:user:1                # one user
GET    user-api:user:email:ada@example.com
DBSIZE
INFO keyspace
```

### docker compose

Password is `devpass` (see `docker-compose.yml`).

```bash
# from inside the redis container
docker compose exec redis redis-cli -a devpass --no-auth-warning
# one-off command
docker compose exec redis redis-cli -a devpass --no-auth-warning SCARD user-api:users
# from the host, if you also published 6379 (compose does)
redis-cli -h 127.0.0.1 -p 6379 -a devpass --no-auth-warning
```

### Kubernetes

The password lives in Secret `redis-auth`. Pull it into a shell variable first:

```bash
PW=$(kubectl -n user-api get secret redis-auth -o jsonpath='{.data.REDIS_PASSWORD}' | base64 -d)
```

**A — exec into the running Redis pod (no extra tooling)**

```bash
kubectl -n user-api exec -it deploy/redis -- redis-cli -a "$PW" --no-auth-warning
# or a single command
kubectl -n user-api exec -it deploy/redis -- \
  redis-cli -a "$PW" --no-auth-warning HGETALL user-api:user:1
```

**B — port-forward, then use a local `redis-cli` / GUI**

```bash
kubectl -n user-api port-forward svc/redis 6379:6379    # keep running
# another terminal:
redis-cli -h 127.0.0.1 -p 6379 -a "$PW" --no-auth-warning
```

A GUI (RedisInsight, TablePlus, …) connects to `127.0.0.1:6379` with that password while the
port-forward is up.

**C — throwaway `redis-cli` pod in the cluster**

```bash
kubectl -n user-api run redis-cli --rm -it --restart=Never \
  --image=redis:7.4-alpine -- \
  redis-cli -h redis -p 6379 -a "$PW" --no-auth-warning
```

> `--no-auth-warning` only silences the "password on the command line" notice; it does not change auth.
> Inside the cluster the service DNS name is `redis` (short) or `redis.user-api.svc.cluster.local`.

## Notes

- Secrets in `k8s/*-secret.yaml` are local-dev placeholders — replace before any real use.
- The Redis password exists once, in Secret `redis-auth`. Redis receives it as
  `--requirepass $(REDIS_PASSWORD)` (env-var substitution in `args`); the API and the seed Job
  receive it as the `REDIS_PASSWORD` env var from the same Secret.
- `redis-data` (1 Gi PVC, default StorageClass) survives pod restarts and `rollout restart`; it is
  removed by `kubectl delete -k k8s/` and by `minikube delete`.

### Troubleshooting

| Symptom                                   | Likely cause / fix                                                                 |
|-------------------------------------------|-----------------------------------------------------------------------------------|
| `user-api` pods stuck `0/1 Running`        | Redis not ready or wrong password — `kubectl logs -l app=redis`; check `redis-auth`. |
| `ImagePullBackOff`                         | tag not pushed or misspelled — `docker push ahceneaiti/...`; check `kustomization.yaml`. |
| `curl user-api.local` refused / 404        | ingress addon not ready (`kubectl -n ingress-nginx get pods`), missing `/etc/hosts` entry, or no `minikube tunnel`. |
| Job `user-seed` never `Completed`          | init container waiting on Redis — `kubectl logs job/user-seed -c wait-for-redis`.  |
| PVC `redis-data` stays `Pending`           | no default StorageClass — `minikube addons enable storage-provisioner`.            |
| Applied a new image, pods unchanged        | same tag reused — bump the tag in `kustomization.yaml`, then `kubectl apply -k k8s/`. |
