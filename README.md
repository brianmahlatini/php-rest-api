# php-rest-api

A production-style REST API in **modern PHP 8.3 with no framework**: a small, fully tested HTTP kernel with middleware, JWT authentication, role-based access control, database-backed rate limiting, PDO repositories with migrations for SQLite and MySQL, and RFC 7807 error responses. It's the backend for [react-task-board](https://github.com/brianmahlatini/react-task-board) and [angular-admin-portal](https://github.com/brianmahlatini/angular-admin-portal).

```
$ vendor/bin/phpunit
OK (27 tests, 88 assertions)        # unit + full-HTTP integration tests
$ phpstan analyse                   # level 8, zero errors
```

## Why no framework?

Laravel and Symfony are the right choice for most products. This project instead shows the mechanics they hide: routing, middleware pipelines, dependency wiring, authentication, and safe database access. Every layer is small enough to read in one sitting and is covered by tests.

## Architecture

```
public/index.php ─► Kernel
                     ├─ ErrorHandler      every exception → RFC 7807 JSON, request id, no stack traces to clients
                     ├─ SecurityHeaders   nosniff, frame DENY, CSP, no-store
                     ├─ Cors              explicit origin allow-list, preflight handling
                     └─ Router ─► route middleware ─► controller
                                  ├─ RateLimit     token bucket in the DB (10/min on auth, 120 burst on API)
                                  ├─ Authenticate  HS256 JWT, fixed algorithm, constant-time compare
                                  └─ RequireRole   admin-only routes
controllers ─► Validator (whitelists fields) ─► repositories (PDO prepared statements)
```

| Endpoint | Access |
|---|---|
| `POST /api/auth/register`, `POST /api/auth/login` | public, rate limited |
| `GET /api/auth/me` | authenticated |
| `GET/POST /api/tasks`, `GET/PATCH/DELETE /api/tasks/{id}` | owner (admins can see all with `scope=all`) |
| `GET /api/admin/users`, `PATCH /api/admin/users/{id}` | admin |

Full contract in [`openapi.yaml`](openapi.yaml).

## Security decisions

- **No algorithm confusion.** The server decides the JWT algorithm (HS256). `alg: none` and forged tokens are rejected, signatures are compared with `hash_equals`, and expiry and not-before are enforced with a 30s clock-skew leeway.
- **No user enumeration.** Unknown email and wrong password return the same message *and take the same time*, because a dummy bcrypt hash is verified when the user doesn't exist.
- **No mass assignment.** The validator returns only declared fields, so a client sending `owner_id` or `role` can't set them.
- **No IDOR leaks.** Someone else's task returns 404, not 403, so the API doesn't confirm that an id exists.
- **No SQL injection surface.** Prepared statements with emulation disabled, `LIKE` wildcards escaped, and update columns from a fixed whitelist.
- **Brute-force protection that survives scaling.** Rate-limit state lives in the database, so limits hold across PHP-FPM workers and multiple containers.
- **Can't lock yourself out.** The last admin can't be demoted.
- **Secrets from the environment.** The JWT secret must be at least 32 bytes or the app refuses to start. The admin password is read from an env var, not argv (argv is visible in `ps`).

## Run it

```sh
docker compose up -d --build            # nginx + php-fpm + MySQL 8.4 on http://localhost:8080
docker compose exec app bin/console migrate
docker compose exec -e ADMIN_PASSWORD='choose-a-long-one' app bin/console create-admin admin@example.test Admin

curl -s -X POST localhost:8080/api/auth/register -H 'content-type: application/json' \
  -d '{"email":"me@example.test","password":"a-long-password-123","name":"Me"}'
```

Development: `composer install && vendor/bin/phpunit && phpstan analyse && php-cs-fixer fix --dry-run`.

## CI

PHP 8.3 and 8.4 matrix: `composer validate`, PHP-CS-Fixer (PER-CS 2.0), PHPStan level 8, PHPUnit with coverage, `composer audit`. Then an end-to-end job builds the Docker image, starts nginx + PHP-FPM + MySQL, runs migrations, and exercises auth and CRUD over real HTTP.

## License

MIT
