# syntax=docker/dockerfile:1

FROM redis:7.4-alpine

COPY redis.conf /usr/local/etc/redis/redis.conf

EXPOSE 6379

# Keeps the base image's docker-entrypoint.sh; password is appended at runtime
# via `--requirepass` (see k8s/redis-deployment.yaml and docker-compose.yml).
CMD ["redis-server", "/usr/local/etc/redis/redis.conf"]
