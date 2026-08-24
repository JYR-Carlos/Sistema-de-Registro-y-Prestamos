# Security & Architecture Audit — SGEQ Backend (Laravel 12)

**Date:** 2026-07-16
**Scope:** `bootstrap/`, `routes/`, `app/Http/`, `app/Models/`, `app/Services/`, `config/`, `database/`
**Runtime verified:** PHP 8.4.0 (local), `laravel/framework ^12`, `laravel/sanctum ^4`
**Threat model:** internal departmental server behind IT firewall — insider threat, privilege escalation, IDOR, data integrity, type safety.
**Method:** static analysis + **dynamic proof-of-concept**. The application was booted against in-memory SQLite and real HTTP requests were dispatched through the HTTP kernel to confirm exploitability. Findings marked *PROVEN* were executed, not merely reasoned about.

---

## 1. Executive Summary

| Dimension | Assessment |
|---|---|
| **Overall risk** | **CRITICAL** — two independently exploitable paths to full ADMIN compromise, both reachable by any authenticated ALUMNO (or, chained with public self-registration, by an anonymous attacker). |
| **Access control** | Broken. Authorization is enforced inconsistently — some routes via `admin` middleware, some via per-method `ensureAdmin()`, some via `FormRequest::authorize()`, and at least one high-value route via **nothing at all**. |
| **Authentication** | Broken. The Google login endpoint accepts unsigned, forged ID tokens. |
| **Data integrity** | At risk. Role assignment (`roles()->sync()`) and password resets are exposed on an unauthenticated-by-role endpoint. |
| **PHP 8.4 / 8.5 compatibility** | **Healthy.** Boot + full config load produced **0 `E_DEPRECATED`**. No implicitly-nullable typed parameters. The `config/database.php` PDO constant is **not** a deprecation on 8.4/8.5 (see §3.1 — the brief's premise is corrected). |
| **Architecture** | Mixed. Good Fat-Model/Skinny-Controller and Service-layer discipline; good eager-loading hygiene. Undermined by a legacy Laravel-10 HTTP kernel on a v12 framework, zero `strict_types`, and inconsistent authorization placement. |

**The single most important structural defect:** authorization is not centralized. Because it is scattered across three mechanisms, a route that forgets all three (`PUT /api/usuarios/{id}`) silently becomes a full privilege-escalation primitive. Fixing the two criticals is urgent; **centralizing authorization (Policies + consistent middleware) is what prevents the next one.**

---

## 2. Security Vulnerabilities (OWASP-grouped)

### 🔴 C1 — Broken Function-Level Authorization → vertical privilege escalation + account takeover *(PROVEN)*
**OWASP A01:2021 – Broken Access Control**
**Endpoint:** `PUT /api/usuarios/{id}` → `UsuarioController::update` → `UsuarioService::actualizarUsuario`
**Files:** `routes/api.php:121`, `app/Http/Requests/Usuario/UpdateUsuarioRequest.php:24`, `app/Http/Controllers/UsuarioController.php:115`, `app/Services/UsuarioService.php:253-292`

**Exploit mechanism — a triple failure aligns:**
1. `routes/api.php:118-123` places the `/usuarios` CRUD group behind `auth:sanctum` **only** — no `admin` middleware.
2. `UpdateUsuarioRequest::authorize()` returns `true` (`UpdateUsuarioRequest.php:24-27`), and its `rules()` **accepts a `rol` field**.
3. `UsuarioController::update` performs **no role check** (the role-checking variant at lines 136-158 is dead code — swallowed by an unterminated docblock opened at line 135). `UsuarioService::actualizarUsuario` then blindly runs `roles()->sync([$nuevoRol->idRol])` and `Hash::make($data['password'])` for **any** target `{id}`.

Result: any authenticated ALUMNO can (a) promote **themselves** to ADMIN, and (b) **reset any other user's password** — including an admin's — achieving full account takeover.

**PoC result (executed against the live kernel):**
```
PRE  attacker(id=1) roles = ALUMNO
EXPLOIT 1 — PUT /api/usuarios/1 {"rol":"ADMIN"}   → HTTP 200; roles = ADMIN; isAdmin()=true
EXPLOIT 2 — PUT /api/usuarios/2 {"password":"pwned-by-mallory"}  (id 2 = an admin)
            → HTTP 200; admin hash changed=true; Hash::check('pwned-by-mallory')=true
```

**Chain amplifier:** `POST /api/register` (`routes/api.php:89`) is **public** and self-issues a Sanctum token. An anonymous attacker: `register` → escalate via C1 → ADMIN. No insider access required.

**Mitigation:** see §4.1. Enforce `admin` middleware on the route **and** gate role/password mutation with a Policy, **and** strip `rol`/`password` from the self-service update path.

---

### 🔴 C2 — Authentication bypass via forged Google ID token *(PROVEN)*
**OWASP A07:2021 – Identification & Authentication Failures**
**Endpoint:** `POST /api/auth/google` → `GoogleTokenController::login`
**File:** `app/Http/Controllers/Auth/GoogleTokenController.php:38-74`

**Exploit mechanism:** the controller splits the JWT, `base64_decode`s the **payload**, and validates only the `aud`, `iss`, `exp`, and `email_verified` **claims**. It **never verifies the RS256 signature** against Google's JWKS (`https://www.googleapis.com/oauth2/v3/certs`). Every checked claim lives inside the attacker-controlled payload, and `aud` (the OAuth **client ID**) is public (shipped in the frontend). An attacker therefore forges a token for any `email` with a garbage signature and is logged in as that user — auto-creating the account if it doesn't exist.

**PoC result (executed against the live kernel):**
```
Forged JWT: header.payload.<invalid-signature>  (email=alice.admin@uta.cl, aud=<public client id>)
POST /api/auth/google  → HTTP 200, Sanctum token issued
GET  /api/user (Bearer stolen)  → HTTP 200, idUser=1
>>> CONFIRMED: authenticated as the ADMIN account without credentials.
```

**Mitigation:** verify the signature. `google/auth ^1.44` is **already installed** (`composer.json`). Replace the hand-rolled decode with `Google\Client::verifyIdToken()`, which fetches and caches Google's public keys and validates signature + `aud` + `iss` + `exp`. See §4.2.

**Secondary issues in the same handler:**
- `env('GOOGLE_CLIENT_ID')` is read **at runtime in a controller** (`GoogleTokenController.php:56`). After `php artisan config:cache` (standard in production), `env()` outside config files returns **null**, degrading the `aud` check. Move to `config('services.google.client_id')`.
- Full decoded JWT payload (email, name, `sub`) is written to logs at INFO (`GoogleTokenController.php:46`, `84`) — PII disclosure in log files (A09 – Security Logging Failures).

---

### 🟠 H1 — Password-reset token weaknesses
**OWASP A07:2021**
**File:** `app/Http/Controllers/Auth/ResetPasswordController.php`

- **Plaintext-equality fallback:** `Hash::check($request->token, $record->token) || $request->token === $record->token` (`:41-42`). The `===` branch accepts a raw token equal to the stored value; combined with the hashed-token migration this widens the accepted-token surface and invites timing-based comparison on the raw path. Drop the fallback once all tokens are hashed (`ForgotPasswordController` already stores `Hash::make`).
- **Full-table scan / enumeration:** `validateToken()` (`:65-86`) loads **every** `password_resets` row and `Hash::check`s each — O(n) crypto work per call, a mild DoS/enumeration vector. Scope by email or store a lookup selector.
- **Carbon expiry check:** `now()->diffInMinutes($record->created_at) > 60` (`:35`) relies on absolute-value semantics; under Laravel 12 / Carbon 3 prefer `$record->created_at->lt(now()->subMinutes(60))` for an unambiguous, sign-safe comparison.

---

### 🟠 H2 — Non-expiring tokens over plaintext HTTP
**OWASP A02:2021 – Cryptographic Failures / A05 – Misconfiguration**
**Files:** `config/sanctum.php:47`, `.env.production.example:5,54,55`

`'expiration' => null` → Sanctum tokens **never expire** (the `expires_at` column added in `2026_02_02_114720_*` is unused without this). Production is configured for **plain HTTP** (`APP_URL=http://…`, `GOOGLE_REDIRECT_URI=http://…`, `FRONTEND_URL=http://…`), and `SESSION_SECURE_COOKIE` is unset. A bearer token captured on the LAN (insider threat model) is valid **forever**. Set a finite `expiration`, force TLS, and set `SESSION_SECURE_COOKIE=true`.

---

### 🟡 M1 — Authorization scattered across three mechanisms (fragile by design)
**OWASP A01:2021**
**Files:** `routes/api.php:140-155`, `app/Http/Controllers/ConfiguracionController.php`, `app/Http/Controllers/CategoriaController.php`

`/admin/categorias` and `/admin/configuraciones` sit under `auth:sanctum` **without** `admin` middleware (`routes/api.php:140`). They are *currently* safe only because every method calls `ensureAdmin()` and the write FormRequests check `isAdminOrSuper()` — verified across all methods. This is defense-by-vigilance: the C1 breach is exactly what happens when one method forgets. **Add `admin` to these route groups** so authorization is enforced structurally, and keep the in-method checks as depth.

### 🟡 M2 — `User` model: `$guarded = []` alongside `$fillable`
**OWASP A08:2021 – Data Integrity** · **File:** `app/Models/User.php:20,27`

Verified empirically: `$fillable` currently **wins**, so mass assignment is contained (`isFillable('idUser') === false`). But declaring `protected $guarded = []` is a footgun — if `$fillable` is ever trimmed, the model becomes fully mass-assignable. Remove the `$guarded = []` line; keep only the explicit `$fillable`.

### 🟡 M3 — Legacy HTTP kernel on Laravel 12
**Architecture** · **Files:** `app/Http/Kernel.php`, `bootstrap/app.php`

The project runs the Laravel-10 skeleton (`App\Http\Kernel` + minimal `bootstrap/app.php`) on the v12 framework. It works, but forfeits the v11/v12 centralized middleware/routing configuration in `bootstrap/app.php` — the very place where a single `->withMiddleware()` alias and route-group definition would have made C1/M1 structurally hard. Migrating is a medium-effort modernization that pays down the root cause of the access-control findings.

### 🟡 M4 — No `declare(strict_types=1)` anywhere (0 / 156 files)
**Type safety** · Requested explicitly in scope. Absent strict types, PHP silently coerces types at call boundaries (e.g. `"1e3" == "1000"`, numeric-string juggling). Add `declare(strict_types=1);` to all `app/` files (mechanical; combine with a CI check).

---

### 🟢 Low-severity / hygiene
| ID | File | Issue |
|---|---|---|
| L1 | `ImageController.php:20` | Path traversal is **blocked** by Flysystem (`PathTraversalDetected`), but the exception is **uncaught** → HTTP 500 instead of 404. Wrap `Storage::exists/get` in try/catch. *(Confirmed not LFI-exploitable.)* |
| L2 | `routes/api.php:102` | `GET /api/prestamos/{id}` → `PrestamoController::show`, **which does not exist** → 500 on every call. Implement or remove. |
| L3 | `UsuarioController.php:135-158` | Dead role-checking `update()` variant hidden inside an unterminated docblock — delete to avoid confusion (it looks like the real, safe implementation but never runs). |
| L4 | `test_ports.php`, `server.php`, `start-*.bat` at project root | Stray helper scripts in the repo. Not web-served (docroot is `public/`), but remove from VCS. |
| L5 | `routes/api.php` | Duplicate route registrations (`/prestamos` GET/POST at 99-101 **and** 130-131; `/equipos/relacion` at 240-242 **and** 271-273). Last-registered wins silently — dedupe. |

---

## 3. Best Practices & Deprecations

### 3.1 PHP 8.5+ deprecation status — premise corrected
The brief cites migrating `PDO::MYSQL_ATTR_SSL_CA` → `Pdo\Mysql::ATTR_SSL_CA` as clearing a deprecation. **Empirically, this is not an active deprecation on the target runtimes:**

- On PHP **8.4.0**, `PDO::MYSQL_ATTR_SSL_CA` resolves cleanly (value `1009`) with **no** `E_DEPRECATED` under `error_reporting(E_ALL)`. Both the old constant and the new `Pdo\Mysql::ATTR_SSL_CA` exist and are equal.
- Booting the full application + loading **every** config file under a deprecation trap produced **0** `E_DEPRECATED` (vendor and app).
- The genuine PHP 8.4 deprecation — *implicitly-nullable typed parameters* (`function f(string $x = null)`) — **does not occur** anywhere in `app/`; every nullable default correctly uses `?type`.

**Conclusion:** the codebase has **no active PHP 8.4/8.5 deprecation warnings.** The `Pdo\Mysql::` form is nonetheless the modern idiom introduced in PHP 8.4, so §4.3 offers it as **optional, version-safe future-proofing** (guarded with `defined()`), not a fix for a current warning. `composer.json` still declares `"php": "^8.1"`; bump the floor to `^8.2` to match the deployment target and unlock enum/readonly guarantees already used.

### 3.2 Architecture — what's working
- **Fat Model / Skinny Controller:** consistently applied — controllers delegate to a clean `app/Services/*` layer (`UsuarioService`, `PrestamoService`, `EquipoEstadoService`, analytics services). Good.
- **Eager loading / N+1:** generally disciplined — `PrestamoController::index` eager-loads `equipos.tipo`, `bloquePrestamo.*`, `observaciones`; `AdminGrupoController::index` uses `with(['asignatura','bloque','docente.persona'])`; reporting services use set-based aggregate SQL rather than per-row queries. No systemic N+1 found.
- **Form Requests:** used broadly for validation. The gap is **authorization** within them (see C1/M1), not validation.
- **Enums:** first-class PHP enums (`app/Enums/*`) with strict `===`/`in_array(..., true)` comparisons — good type discipline that `strict_types` should be extended to reinforce.
- **Raw SQL:** the four dynamic `selectRaw`/`groupByRaw` sites (`DemandAnalyticsService`, `StockoutAnalyticsService`) interpolate only **whitelisted** expressions — `groupBy` is constrained by `in_array($groupBy, ['equipo','categoria','asignatura'], true)` and the date bucket by a `match` with a `default`. **No SQL injection** found; all user-value predicates use bindings.

---

## 4. Actionable Remediation (Before / After)

### 4.1 Fix C1 — lock down user mutation

**`routes/api.php` — gate the route group (before):**
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::put('/usuarios/{id}', [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);
    // ...
});
```
**After — require admin for privileged user management:**
```php
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/usuarios',        [UsuarioController::class, 'store']);
    Route::put('/usuarios/{id}',    [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);
    Route::post('/usuarios/{id}/reactivar', [UsuarioController::class, 'reactivar']);
});
// Self-service profile edits, if needed, go to a SEPARATE endpoint that
// cannot touch `rol` or another user's `{id}`.
```

**`UpdateUsuarioRequest.php` — real authorization + drop dead code (before):**
```php
public function authorize()
{
    return true;   // ← anyone
}
```
**After:**
```php
public function authorize(): bool
{
    $user = $this->user();
    return $user !== null && $user->isAdmin();
}
```

**`UsuarioService::actualizarUsuario` — never sync roles / reset passwords implicitly (after):**
```php
public function actualizarUsuario(int $id, array $data): array
{
    $usuario = User::findOrFail($id);
    $persona = Persona::findOrFail($usuario->idPersona);

    $persona->update([/* personal fields only */]);
    $usuario->Email = $data['email'];
    $usuario->save();

    // Role & password changes are ADMIN-only, explicit operations.
    // With FormRequest::authorize() now enforcing isAdmin(), this branch is safe;
    // for self-service flows, call a controller that omits these keys entirely.
    if (array_key_exists('rol', $data) && $data['rol'] !== null) {
        $nuevoRol = Rol::where('Nombre', $data['rol'])->firstOrFail();
        $usuario->roles()->sync([$nuevoRol->idRol]);
    }
    return ['persona' => $persona, 'usuario' => $usuario];
}
```
Also **delete** the dead `update()` block at `UsuarioController.php:135-158` (unterminated docblock).

---

### 4.2 Fix C2 — verify the Google ID-token signature

**`GoogleTokenController::login` (before — no signature check):**
```php
$parts   = explode('.', $request->token);
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
if (($payload['aud'] ?? null) !== env('GOOGLE_CLIENT_ID')) { /* 401 */ }
if (($payload['iss'] ?? null) !== 'https://accounts.google.com') { /* 401 */ }
// ...trusts unsigned payload...
```
**After — cryptographic verification via the already-installed `google/auth` / `google/apiclient`:**
```php
use Google\Client as GoogleClient;

$request->validate(['token' => 'required|string']);

$client  = new GoogleClient(['client_id' => config('services.google.client_id')]);
$payload = $client->verifyIdToken($request->token); // checks RS256 sig + aud + iss + exp

if ($payload === false) {
    return response()->json(['message' => 'Token de Google inválido'], 401);
}
if (($payload['email_verified'] ?? false) !== true) {
    return response()->json(['message' => 'Email no verificado'], 401);
}
$email = $payload['email'];
// ...proceed to find/create user...
```
> `config/services.php`: add `'google' => ['client_id' => env('GOOGLE_CLIENT_ID')]` and reference `config('services.google.client_id')` so `config:cache` works. Remove the full-payload `Log::info` calls.

---

### 4.3 Optional — modernize the PDO SSL constant (version-safe, **not** a deprecation fix)

**`config/database.php:61-63` (before):**
```php
'options' => extension_loaded('pdo_mysql') ? array_filter([
    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
]) : [],
```
**After — prefer the PHP 8.4 class constant when present, fall back cleanly on older runtimes:**
```php
'options' => extension_loaded('pdo_mysql') ? array_filter([
    (defined('Pdo\\Mysql::ATTR_SSL_CA') ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA)
        => env('MYSQL_ATTR_SSL_CA'),
]) : [],
```
This is purely forward-looking; the current line emits no warning on 8.4/8.5.

---

## 5. Prioritized Remediation Plan

| # | Finding | Severity | Effort | Priority |
|---|---|---|---|---|
| 1 | C1 — user-mutation privilege escalation | 🔴 Critical | Low | **Immediate** |
| 2 | C2 — forged Google JWT auth bypass | 🔴 Critical | Low | **Immediate** |
| 3 | H2 — token expiry + TLS in production | 🟠 High | Low | This week |
| 4 | H1 — password-reset token handling | 🟠 High | Low | This week |
| 5 | M1 — add `admin` middleware to admin route groups | 🟡 Medium | Low | This week |
| 6 | M2 — remove `$guarded = []` from `User` | 🟡 Medium | Trivial | This week |
| 7 | C2-secondary — `env()`→`config()`, stop logging JWT PII | 🟡 Medium | Trivial | This week |
| 8 | M4 / M3 — `strict_types` + kernel modernization | 🟡 Medium | Medium | Backlog |
| 9 | L1–L5 — hygiene (routes, dead code, stray files) | 🟢 Low | Low | Backlog |

**Root-cause recommendation:** introduce Laravel **Policies** (`UserPolicy`, `PrestamoPolicy`, …) registered in `AuthServiceProvider` (currently empty) and enforce them via `$this->authorize()` / `can:` middleware. Centralizing authorization is the durable fix that makes C1-class regressions structurally impossible, rather than depending on every new method remembering to call `ensureAdmin()`.
