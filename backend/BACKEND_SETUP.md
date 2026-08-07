# CTMS Backend Setup & Development Guide

## Prerequisites
- Docker & Docker Compose
- PHP 8.3+ (for local CLI development without Docker)
- Composer 2.x

---

## Quick Start (Docker)

1. Clone the repository and navigate to the backend directory:
   ```bash
   cd backend
   ```

2. Copy environment file:
   ```bash
   cp .env.example .env
   ```

3. Build and start Docker services:
   ```bash
   docker-compose up -d --build
   ```

4. Run database migrations and seeders inside container:
   ```bash
   docker-compose exec app php artisan migrate --seed
   ```

5. Verify services are running:
   - Application API: `http://localhost:80/api/v1`
   - Health check: `http://localhost:80/up`

---

## Local Development (Without Docker)

1. Configure `.env` for local PostgreSQL and Redis connections:
   ```env
   DB_HOST=127.0.0.1
   REDIS_HOST=127.0.0.1
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Run migrations and seeders:
   ```bash
   php artisan migrate --seed
   ```

4. Run tests:
   ```bash
   php artisan test
   ```

---

## Troubleshooting
- **Database Connection Refused**: Verify PostgreSQL container is running on port 5432 or update DB_HOST in `.env`.
- **JWT Key Error**: Ensure `JWT_SECRET` is defined in `.env`.
