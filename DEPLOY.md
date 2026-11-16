# Despliegue en servidor

Clonar **tu fork**, no el de Kevin:

```bash
git clone https://github.com/JYR-Carlos/Sistema-de-Registro-y-Prestamos.git
cd Sistema-de-Registro-y-Prestamos
```

## 1. Backend (Laravel)

```bash
cd Backend

cp .env.production.example .env
# Editar .env y completar:
#   DB_PASSWORD
#   MAIL_USERNAME / MAIL_PASSWORD
#   GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET   (rotados en Google Cloud Console)
#   APP_URL / GOOGLE_REDIRECT_URI / FRONTEND_URL si cambia el dominio

composer install --no-dev --optimize-autoloader

# Generar APP_KEY SOLO si es la primera vez en este servidor
# (si ya hay datos cifrados en la BD con el key filtrado, no lo regeneres)
php artisan key:generate

php artisan storage:link
php artisan migrate --force
# NO correr UsersTableSeeder en producción (trae contraseña admin123 hardcodeada)

php artisan config:cache
php artisan route:cache
```

Permisos de escritura para el usuario del servidor web:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### Actualizar un servidor existente

`Backend/vendor` ya **no** está en git: siempre se reconstruye con `composer install`.

```bash
cd Backend
php artisan down

# SOLO la primera vez tras sacar vendor/ de git: descartar los cambios que
# composer hizo sobre el vendor versionado, o el pull se niega a borrarlo.
git checkout -- vendor

git pull
composer install --no-dev --optimize-autoloader
# Falla si algún archivo no coincide con su clase (Linux distingue mayúsculas).
# Si falla, corregir antes de levantar el sitio.
composer dump-autoload --no-dev --optimize --strict-psr

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan up
```

## 2. Frontend (Angular)

```bash
cd ../Frontend
npm install
ng build --configuration production
```

Servir el contenido de `dist/` con nginx/apache. `environment.prod.ts` ya apunta a
`http://sgeq.utamed.uta.cl`, no requiere cambios salvo que cambie el dominio.

## 3. Google Cloud Console

- Verificar que el redirect URI autorizado sea `http://sgeq.utamed.uta.cl/auth/google/callback`.
- Si se rota el Client Secret (recomendado tras la filtración), el Client ID
  hardcodeado en `Frontend/src/app/components/auth/login/login.component.ts`
  no necesita cambiar. Si se crea un Client ID nuevo, sí hay que actualizarlo ahí.

## 4. Web server

- Document root → `Backend/public`
- PHP `^8.1`
- Vhost sirviendo `Frontend/dist/...` para las rutas del SPA
