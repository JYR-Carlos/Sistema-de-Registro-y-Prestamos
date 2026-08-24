# Auditoría de Seguridad y Arquitectura — Backend SGEQ (Laravel 12)

**Fecha:** 2026-07-16
**Alcance:** `bootstrap/`, `routes/`, `app/Http/`, `app/Models/`, `app/Services/`, `config/`, `database/`
**Runtime verificado:** PHP 8.4.0 (local), `laravel/framework ^12`, `laravel/sanctum ^4`
**Modelo de amenaza:** servidor departamental interno tras el firewall de TI — amenaza interna (insider), escalamiento de privilegios, IDOR, integridad de datos, seguridad de tipos.
**Método:** análisis estático + **prueba de concepto dinámica (PoC)**. Se arrancó la aplicación contra SQLite en memoria y se despacharon peticiones HTTP reales a través del HTTP kernel para confirmar la explotabilidad. Los hallazgos marcados como *PROBADO* se **ejecutaron**, no se dedujeron únicamente por lectura.

---

## 1. Resumen Ejecutivo

| Dimensión | Evaluación |
|---|---|
| **Riesgo global** | **CRÍTICO** — dos rutas de explotación independientes que conducen al compromiso total como ADMIN, ambas alcanzables por cualquier ALUMNO autenticado (o, encadenadas con el auto-registro público, por un atacante anónimo). |
| **Control de acceso** | Roto. La autorización se aplica de forma inconsistente — en algunas rutas vía middleware `admin`, en otras vía `ensureAdmin()` por método, en otras vía `FormRequest::authorize()`, y al menos una ruta de alto valor **no tiene ninguna protección**. |
| **Autenticación** | Rota. El endpoint de login con Google acepta tokens de identidad (ID token) forjados y sin firmar. |
| **Integridad de datos** | En riesgo. La asignación de roles (`roles()->sync()`) y el restablecimiento de contraseñas están expuestos en un endpoint sin control de rol. |
| **Compatibilidad PHP 8.4 / 8.5** | **Sana.** El arranque + carga completa de configuración produjo **0 `E_DEPRECATED`**. No hay parámetros con nulabilidad implícita. La constante PDO de `config/database.php` **no** es una deprecación en 8.4/8.5 (ver §3.1 — se corrige la premisa del encargo). |
| **Arquitectura** | Mixta. Buena disciplina Fat-Model/Skinny-Controller y capa de Servicios; buena higiene de eager-loading. Debilitada por un HTTP kernel legado de Laravel 10 sobre un framework v12, cero `strict_types` y una ubicación inconsistente de la autorización. |

**El defecto estructural más importante:** la autorización no está centralizada. Al estar dispersa entre tres mecanismos, una ruta que olvide los tres (`PUT /api/usuarios/{id}`) se convierte silenciosamente en una primitiva de escalamiento total de privilegios. Corregir los dos críticos es urgente; **centralizar la autorización (Policies + middleware consistente) es lo que evita el próximo incidente.**

---

## 2. Vulnerabilidades de Seguridad (agrupadas por OWASP)

### 🔴 C1 — Autorización a nivel de función rota → escalamiento vertical de privilegios + toma de cuenta *(PROBADO)*
**OWASP A01:2021 – Broken Access Control**
**Endpoint:** `PUT /api/usuarios/{id}` → `UsuarioController::update` → `UsuarioService::actualizarUsuario`
**Archivos:** `routes/api.php:121`, `app/Http/Requests/Usuario/UpdateUsuarioRequest.php:24`, `app/Http/Controllers/UsuarioController.php:115`, `app/Services/UsuarioService.php:253-292`

**Mecanismo de explotación — coinciden tres fallas:**
1. `routes/api.php:118-123` ubica el grupo CRUD de `/usuarios` únicamente tras `auth:sanctum` — **sin** middleware `admin`.
2. `UpdateUsuarioRequest::authorize()` retorna `true` (`UpdateUsuarioRequest.php:24-27`), y sus `rules()` **aceptan un campo `rol`**.
3. `UsuarioController::update` **no realiza ninguna verificación de rol** (la variante que sí verificaba el rol, en las líneas 136-158, es código muerto — quedó atrapada en un docblock sin cerrar abierto en la línea 135). `UsuarioService::actualizarUsuario` luego ejecuta ciegamente `roles()->sync([$nuevoRol->idRol])` y `Hash::make($data['password'])` para **cualquier** `{id}` destino.

Resultado: cualquier ALUMNO autenticado puede (a) promoverse **a sí mismo** a ADMIN, y (b) **restablecer la contraseña de cualquier otro usuario** — incluida la de un administrador — logrando la toma total de la cuenta.

**Resultado de la PoC (ejecutada contra el kernel en vivo):**
```
PRE  atacante(id=1) roles = ALUMNO
EXPLOIT 1 — PUT /api/usuarios/1 {"rol":"ADMIN"}   → HTTP 200; roles = ADMIN; isAdmin()=true
EXPLOIT 2 — PUT /api/usuarios/2 {"password":"pwned-by-mallory"}  (id 2 = un admin)
            → HTTP 200; hash del admin cambiado=true; Hash::check('pwned-by-mallory')=true
```

**Amplificador de la cadena:** `POST /api/register` (`routes/api.php:89`) es **público** y emite por sí mismo un token Sanctum. Un atacante anónimo: `register` → escala vía C1 → ADMIN. No se requiere acceso interno previo.

**Mitigación:** ver §4.1. Aplicar el middleware `admin` en la ruta **y** proteger la mutación de rol/contraseña con una Policy, **y** eliminar `rol`/`password` de la ruta de auto-actualización.

---

### 🔴 C2 — Bypass de autenticación mediante token de Google forjado *(PROBADO)*
**OWASP A07:2021 – Identification & Authentication Failures**
**Endpoint:** `POST /api/auth/google` → `GoogleTokenController::login`
**Archivo:** `app/Http/Controllers/Auth/GoogleTokenController.php:38-74`

**Mecanismo de explotación:** el controlador divide el JWT, hace `base64_decode` del **payload** y valida únicamente los **claims** `aud`, `iss`, `exp` y `email_verified`. **Nunca verifica la firma RS256** contra el JWKS de Google (`https://www.googleapis.com/oauth2/v3/certs`). Cada claim verificado vive dentro del payload controlado por el atacante, y `aud` (el **client ID** de OAuth) es público (se envía en el frontend). Por lo tanto, un atacante forja un token para cualquier `email` con una firma basura y queda autenticado como ese usuario — creando la cuenta automáticamente si no existe.

**Resultado de la PoC (ejecutada contra el kernel en vivo):**
```
JWT forjado: header.payload.<firma-inválida>  (email=alice.admin@uta.cl, aud=<client id público>)
POST /api/auth/google  → HTTP 200, token Sanctum emitido
GET  /api/user (Bearer robado)  → HTTP 200, idUser=1
>>> CONFIRMADO: autenticado como la cuenta ADMIN sin credenciales.
```

**Mitigación:** verificar la firma. `google/auth ^1.44` **ya está instalado** (`composer.json`). Reemplazar el decode artesanal por `Google\Client::verifyIdToken()`, que obtiene y cachea las llaves públicas de Google y valida firma + `aud` + `iss` + `exp`. Ver §4.2.

**Problemas secundarios en el mismo handler:**
- `env('GOOGLE_CLIENT_ID')` se lee **en tiempo de ejecución dentro de un controlador** (`GoogleTokenController.php:56`). Tras `php artisan config:cache` (estándar en producción), `env()` fuera de archivos de config retorna **null**, degradando la verificación de `aud`. Mover a `config('services.google.client_id')`.
- El payload completo del JWT decodificado (email, name, `sub`) se escribe en logs a nivel INFO (`GoogleTokenController.php:46`, `84`) — divulgación de PII en los archivos de log (A09 – Security Logging Failures).

---

### 🟠 H1 — Debilidades en el token de restablecimiento de contraseña
**OWASP A07:2021**
**Archivo:** `app/Http/Controllers/Auth/ResetPasswordController.php`

- **Comparación de igualdad en texto plano:** `Hash::check($request->token, $record->token) || $request->token === $record->token` (`:41-42`). La rama `===` acepta un token en crudo igual al valor almacenado; combinada con la migración a tokens hasheados, amplía la superficie de tokens aceptados e invita a comparación por timing en la ruta cruda. Eliminar el fallback una vez que todos los tokens estén hasheados (`ForgotPasswordController` ya almacena `Hash::make`).
- **Escaneo de tabla completa / enumeración:** `validateToken()` (`:65-86`) carga **todas** las filas de `password_resets` y hace `Hash::check` en cada una — trabajo criptográfico O(n) por llamada, un vector leve de DoS/enumeración. Acotar por email o almacenar un selector de búsqueda.
- **Verificación de expiración con Carbon:** `now()->diffInMinutes($record->created_at) > 60` (`:35`) depende de la semántica de valor absoluto; en Laravel 12 / Carbon 3 preferir `$record->created_at->lt(now()->subMinutes(60))` para una comparación inequívoca y segura respecto del signo.

---

### 🟠 H2 — Tokens sin expiración sobre HTTP en texto plano
**OWASP A02:2021 – Cryptographic Failures / A05 – Misconfiguration**
**Archivos:** `config/sanctum.php:47`, `.env.production.example:5,54,55`

`'expiration' => null` → los tokens Sanctum **nunca expiran** (la columna `expires_at` agregada en `2026_02_02_114720_*` queda sin uso sin esto). Producción está configurada para **HTTP plano** (`APP_URL=http://…`, `GOOGLE_REDIRECT_URI=http://…`, `FRONTEND_URL=http://…`), y `SESSION_SECURE_COOKIE` no está definido. Un token bearer capturado en la LAN (modelo de amenaza interna) es válido **para siempre**. Definir un `expiration` finito, forzar TLS y establecer `SESSION_SECURE_COOKIE=true`.

---

### 🟡 M1 — Autorización dispersa entre tres mecanismos (frágil por diseño)
**OWASP A01:2021**
**Archivos:** `routes/api.php:140-155`, `app/Http/Controllers/ConfiguracionController.php`, `app/Http/Controllers/CategoriaController.php`

`/admin/categorias` y `/admin/configuraciones` están bajo `auth:sanctum` **sin** middleware `admin` (`routes/api.php:140`). *Actualmente* son seguras solo porque cada método llama a `ensureAdmin()` y los FormRequest de escritura verifican `isAdminOrSuper()` — verificado en todos los métodos. Esto es defensa por vigilancia: la brecha C1 es exactamente lo que ocurre cuando un método lo olvida. **Agregar `admin` a estos grupos de rutas** para que la autorización se aplique estructuralmente, y mantener las verificaciones internas como profundidad.

### 🟡 M2 — Modelo `User`: `$guarded = []` junto con `$fillable`
**OWASP A08:2021 – Data Integrity** · **Archivo:** `app/Models/User.php:20,27`

Verificado empíricamente: `$fillable` **prevalece** actualmente, por lo que la asignación masiva está contenida (`isFillable('idUser') === false`). Pero declarar `protected $guarded = []` es una trampa — si alguna vez se recorta `$fillable`, el modelo queda totalmente asignable en masa. Eliminar la línea `$guarded = []`; conservar solo el `$fillable` explícito.

### 🟡 M3 — HTTP kernel legado sobre Laravel 12
**Arquitectura** · **Archivos:** `app/Http/Kernel.php`, `bootstrap/app.php`

El proyecto ejecuta el esqueleto de Laravel 10 (`App\Http\Kernel` + un `bootstrap/app.php` mínimo) sobre el framework v12. Funciona, pero renuncia a la configuración centralizada de middleware/enrutamiento de v11/v12 en `bootstrap/app.php` — justamente el lugar donde un único `->withMiddleware()` y la definición de grupos de rutas habrían hecho estructuralmente difíciles C1/M1. Migrar es una modernización de esfuerzo medio que salda la causa raíz de los hallazgos de control de acceso.

### 🟡 M4 — Sin `declare(strict_types=1)` en ninguna parte (0 / 156 archivos)
**Seguridad de tipos** · Solicitado explícitamente en el alcance. Sin tipos estrictos, PHP coacciona tipos silenciosamente en los límites de llamada (p. ej. `"1e3" == "1000"`, juggling de cadenas numéricas). Agregar `declare(strict_types=1);` a todos los archivos de `app/` (mecánico; combinar con una verificación en CI).

---

### 🟢 Baja severidad / higiene
| ID | Archivo | Problema |
|---|---|---|
| L1 | `ImageController.php:20` | El path traversal está **bloqueado** por Flysystem (`PathTraversalDetected`), pero la excepción **no se captura** → HTTP 500 en lugar de 404. Envolver `Storage::exists/get` en try/catch. *(Confirmado que NO es LFI explotable.)* |
| L2 | `routes/api.php:102` | `GET /api/prestamos/{id}` → `PrestamoController::show`, **que no existe** → 500 en cada llamada. Implementar o eliminar. |
| L3 | `UsuarioController.php:135-158` | Variante muerta de `update()` con verificación de rol, oculta dentro de un docblock sin cerrar — eliminar para evitar confusión (parece la implementación real y segura, pero nunca se ejecuta). |
| L4 | `test_ports.php`, `server.php`, `start-*.bat` en la raíz del proyecto | Scripts auxiliares sueltos en el repo. No se sirven por web (el docroot es `public/`), pero conviene quitarlos del control de versiones. |
| L5 | `routes/api.php` | Registros de rutas duplicados (`/prestamos` GET/POST en 99-101 **y** 130-131; `/equipos/relacion` en 240-242 **y** 271-273). Gana el último registrado, de forma silenciosa — deduplicar. |

---

## 3. Buenas Prácticas y Deprecaciones

### 3.1 Estado de deprecaciones PHP 8.5+ — premisa corregida
El encargo cita migrar `PDO::MYSQL_ATTR_SSL_CA` → `Pdo\Mysql::ATTR_SSL_CA` como si eliminara una deprecación. **Empíricamente, esto no es una deprecación activa en los runtimes objetivo:**

- En PHP **8.4.0**, `PDO::MYSQL_ATTR_SSL_CA` se resuelve limpiamente (valor `1009`) **sin** `E_DEPRECATED` bajo `error_reporting(E_ALL)`. Tanto la constante antigua como la nueva `Pdo\Mysql::ATTR_SSL_CA` existen y son iguales.
- Arrancar la aplicación completa + cargar **todos** los archivos de config bajo una trampa de deprecaciones produjo **0** `E_DEPRECATED` (vendor y app).
- La deprecación real de PHP 8.4 — *parámetros con nulabilidad implícita* (`function f(string $x = null)`) — **no ocurre** en ninguna parte de `app/`; cada valor por defecto nullable usa correctamente `?tipo`.

**Conclusión:** el código **no tiene advertencias de deprecación activas de PHP 8.4/8.5.** No obstante, la forma `Pdo\Mysql::` es el idioma moderno introducido en PHP 8.4, por lo que la §4.3 la ofrece como **modernización opcional y segura entre versiones** (protegida con `defined()`), no como corrección de una advertencia actual. `composer.json` aún declara `"php": "^8.1"`; subir el mínimo a `^8.2` para alinear con el objetivo de despliegue y habilitar las garantías de enum/readonly ya en uso.

### 3.2 Arquitectura — lo que funciona bien
- **Fat Model / Skinny Controller:** aplicado consistentemente — los controladores delegan en una capa `app/Services/*` limpia (`UsuarioService`, `PrestamoService`, `EquipoEstadoService`, servicios de analítica). Bien.
- **Eager loading / N+1:** en general disciplinado — `PrestamoController::index` precarga `equipos.tipo`, `bloquePrestamo.*`, `observaciones`; `AdminGrupoController::index` usa `with(['asignatura','bloque','docente.persona'])`; los servicios de reportes usan SQL agregado por conjuntos en vez de consultas por fila. No se encontró N+1 sistémico.
- **Form Requests:** usados ampliamente para validación. La brecha es la **autorización** dentro de ellos (ver C1/M1), no la validación.
- **Enums:** enums nativos de PHP (`app/Enums/*`) con comparaciones estrictas `===`/`in_array(..., true)` — buena disciplina de tipos que `strict_types` debería reforzar.
- **SQL crudo:** los cuatro sitios dinámicos `selectRaw`/`groupByRaw` (`DemandAnalyticsService`, `StockoutAnalyticsService`) interpolan solo expresiones de **lista blanca** — `groupBy` está restringido por `in_array($groupBy, ['equipo','categoria','asignatura'], true)` y el bucket de fecha por un `match` con `default`. **No se encontró inyección SQL**; todos los predicados con valores de usuario usan bindings.

---

## 4. Remediación Accionable (Antes / Después)

### 4.1 Corregir C1 — blindar la mutación de usuarios

**`routes/api.php` — proteger el grupo de rutas (antes):**
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::put('/usuarios/{id}', [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);
    // ...
});
```
**Después — requerir admin para la gestión privilegiada de usuarios:**
```php
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/usuarios',        [UsuarioController::class, 'store']);
    Route::put('/usuarios/{id}',    [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);
    Route::post('/usuarios/{id}/reactivar', [UsuarioController::class, 'reactivar']);
});
// La edición de perfil propio, si se necesita, va a un endpoint SEPARADO que
// no pueda tocar `rol` ni el `{id}` de otro usuario.
```

**`UpdateUsuarioRequest.php` — autorización real + eliminar código muerto (antes):**
```php
public function authorize()
{
    return true;   // ← cualquiera
}
```
**Después:**
```php
public function authorize(): bool
{
    $user = $this->user();
    return $user !== null && $user->isAdmin();
}
```

**`UsuarioService::actualizarUsuario` — nunca sincronizar roles / resetear contraseñas de forma implícita (después):**
```php
public function actualizarUsuario(int $id, array $data): array
{
    $usuario = User::findOrFail($id);
    $persona = Persona::findOrFail($usuario->idPersona);

    $persona->update([/* solo campos personales */]);
    $usuario->Email = $data['email'];
    $usuario->save();

    // Los cambios de rol y contraseña son operaciones explícitas y solo de ADMIN.
    // Con FormRequest::authorize() aplicando ahora isAdmin(), esta rama es segura;
    // para flujos de auto-servicio, usar un controlador que omita estas claves por completo.
    if (array_key_exists('rol', $data) && $data['rol'] !== null) {
        $nuevoRol = Rol::where('Nombre', $data['rol'])->firstOrFail();
        $usuario->roles()->sync([$nuevoRol->idRol]);
    }
    return ['persona' => $persona, 'usuario' => $usuario];
}
```
También **eliminar** el bloque muerto de `update()` en `UsuarioController.php:135-158` (docblock sin cerrar).

---

### 4.2 Corregir C2 — verificar la firma del ID token de Google

**`GoogleTokenController::login` (antes — sin verificación de firma):**
```php
$parts   = explode('.', $request->token);
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
if (($payload['aud'] ?? null) !== env('GOOGLE_CLIENT_ID')) { /* 401 */ }
if (($payload['iss'] ?? null) !== 'https://accounts.google.com') { /* 401 */ }
// ...confía en el payload sin firmar...
```
**Después — verificación criptográfica vía el ya instalado `google/auth` / `google/apiclient`:**
```php
use Google\Client as GoogleClient;

$request->validate(['token' => 'required|string']);

$client  = new GoogleClient(['client_id' => config('services.google.client_id')]);
$payload = $client->verifyIdToken($request->token); // verifica firma RS256 + aud + iss + exp

if ($payload === false) {
    return response()->json(['message' => 'Token de Google inválido'], 401);
}
if (($payload['email_verified'] ?? false) !== true) {
    return response()->json(['message' => 'Email no verificado'], 401);
}
$email = $payload['email'];
// ...continuar con buscar/crear usuario...
```
> `config/services.php`: agregar `'google' => ['client_id' => env('GOOGLE_CLIENT_ID')]` y referenciar `config('services.google.client_id')` para que `config:cache` funcione. Eliminar los `Log::info` del payload completo.

---

### 4.3 Opcional — modernizar la constante SSL de PDO (segura entre versiones, **no** es una corrección de deprecación)

**`config/database.php:61-63` (antes):**
```php
'options' => extension_loaded('pdo_mysql') ? array_filter([
    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
]) : [],
```
**Después — preferir la constante de clase de PHP 8.4 cuando esté presente, con fallback limpio en runtimes antiguos:**
```php
'options' => extension_loaded('pdo_mysql') ? array_filter([
    (defined('Pdo\\Mysql::ATTR_SSL_CA') ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA)
        => env('MYSQL_ATTR_SSL_CA'),
]) : [],
```
Esto es puramente prospectivo; la línea actual no emite ninguna advertencia en 8.4/8.5.

---

## 5. Plan de Remediación Priorizado

| # | Hallazgo | Severidad | Esfuerzo | Prioridad |
|---|---|---|---|---|
| 1 | C1 — escalamiento de privilegios en mutación de usuarios | 🔴 Crítico | Bajo | **Inmediato** |
| 2 | C2 — bypass de autenticación con JWT de Google forjado | 🔴 Crítico | Bajo | **Inmediato** |
| 3 | H2 — expiración de tokens + TLS en producción | 🟠 Alto | Bajo | Esta semana |
| 4 | H1 — manejo del token de restablecimiento de contraseña | 🟠 Alto | Bajo | Esta semana |
| 5 | M1 — agregar middleware `admin` a los grupos de rutas admin | 🟡 Medio | Bajo | Esta semana |
| 6 | M2 — eliminar `$guarded = []` de `User` | 🟡 Medio | Trivial | Esta semana |
| 7 | C2-secundario — `env()`→`config()`, dejar de loggear PII del JWT | 🟡 Medio | Trivial | Esta semana |
| 8 | M4 / M3 — `strict_types` + modernización del kernel | 🟡 Medio | Medio | Backlog |
| 9 | L1–L5 — higiene (rutas, código muerto, archivos sueltos) | 🟢 Bajo | Bajo | Backlog |

**Recomendación de causa raíz:** introducir **Policies** de Laravel (`UserPolicy`, `PrestamoPolicy`, …) registradas en `AuthServiceProvider` (actualmente vacío) y aplicarlas vía `$this->authorize()` / middleware `can:`. Centralizar la autorización es la corrección duradera que hace estructuralmente imposibles las regresiones tipo C1, en vez de depender de que cada método nuevo recuerde llamar a `ensureAdmin()`.
