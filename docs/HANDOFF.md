# Handoff: Transfer App — Estado al 18 mayo 2026

Documento para continuar el trabajo en Claude Code desktop.
Pega este archivo como contexto al inicio de la sesión.

---

## Repositorio

- **Fork**: https://github.com/jairodmorales/transfer
- **Rama de trabajo**: `claude/nextcloud-compatibility-review-Fy6Xe`
- **PR abierto**: #1 (draft) — rama → master
- **App original**: https://github.com/beleon/transfer

---

## Contexto del proyecto

La app "transfer" permite a usuarios de Nextcloud descargar archivos desde una URL
directamente al almacenamiento del servidor (sin pasar por el dispositivo del usuario).
Se instala como app de terceros en Nextcloud y aparece en el menú "+" de Files.

**Problema inicial**: La app fallaba silenciosamente al descargar URLs reales
(ej: PDFs de comunidad.madrid). Además había problemas de compatibilidad con
Nextcloud 32/33.

**Stack**:
- PHP 8.1+ con OCP (Nextcloud's public API)
- Vanilla JS + Vite (sin Vue, sin React)
- Base de datos vía `QBMapper` / `IDBConnection`
- Background jobs con `QueuedJob` / `TimedJob`

---

## Plan de mejoras — Estado

### ✅ Fase 0 — Corrección de descarga + Seguridad base
- Capturar todas las excepciones de red (antes solo `BadResponseException`)
- Agregar User-Agent, headers, `connect_timeout`, `allow_redirects`
- Migrar `@NoAdminRequired` a atributos PHP 8 `#[NoAdminRequired]`
- Agregar `#[UserRateLimit]` en los 4 endpoints
- Eliminar `@NoCSRFRequired` de probe (seguridad SSRF cross-site)
- `isValidRemoteUrl()` — rechaza `file://`, `gopher://`, etc.
- Validación de path: bloquea `..` y null bytes
- `hash_equals()` para comparación constante de checksums
- `allow_redirects['protocols'] => ['http','https']` a nivel Guzzle
- `sanitizeUrlForLog()` — elimina `user:pass@` antes de escribir a logs
- `declare(strict_types=1)` + constructor property promotion

### ✅ Fase 1 — Panel de estado flotante + tracking asíncrono
- Nueva tabla DB: `transfer_jobs` (token, user_id, url, path, status, error, timestamps)
- `TransferJobEntity` + `TransferJobMapper` (QBMapper)
- Migración: `lib/Migration/Version0800Date20260518000000.php`
- Token generado con `ISecureRandom` antes de encolar el job
- Endpoint `GET /ajax/status.php?since=<timestamp>` devuelve jobs del usuario
- `TransferService::transfer()` actualiza el estado en DB en cada etapa
- Frontend: panel flotante bottom-right con lista de jobs activos/completados
- Polling cada 3s mientras hay jobs activos; para solo cuando todos son terminales
- Jobs terminados (done/failed) se eliminan del mapa después de 30s
- Animación de spinner para jobs en curso
- Versión bumpeada a 0.8.0

### ✅ Fase 2 — Diálogo multi-URL + Admin Settings
- **Admin Settings**: `Administration → Additional → Transfer`
  - Campo "Maximum URLs per dialog" (1–10, default 3)
  - Campo "Job history retention" (1–365 días, default 30)
  - Guarda via `OCP.AppConfig` JS nativo (sin controller extra)
  - Clase: `lib/Settings/Admin.php`, template: `templates/admin.php`
- **Endpoint batch**: `POST /ajax/batch.php` (transfer#batch)
  - Acepta array de `{url, path, hashAlgo, hash}`
  - Validación atómica (dos pasadas): valida todo antes de insertar nada
  - Rechaza si `count > max_urls`
  - Devuelve array `{token, path}` por job
- **Diálogo multi-URL**:
  - Filas dinámicas: URL + filename por fila
  - Cada fila tiene probe de extensión independiente (debounce propio)
  - Botón "+ Add URL" se oculta al llegar al límite del admin
  - Checksum solo visible con 1 URL (se oculta en modo batch)
  - Diálogo se ensancha a 600px con 2+ filas
  - Botón "Upload" / "Upload N files"
  - Re-render solo en add/remove (no al escribir — preserva foco)

### ✅ Fase 3 — Carga de jobs al iniciar página
- Al abrir Files, `initPanel()` recupera jobs de la última hora via `/ajax/status.php?since=`
- Jobs activos (queued/running): se agregan al panel y arrancan el poll
- Jobs terminados recientes (done/failed): se muestran 30s y se eliminan
- Cubre transferencias iniciadas en otra pestaña o antes de recargar la página

### ✅ Fase 4 — Cleanup job + retention configurable
- `CleanupJob` (TimedJob semanal): llama `deleteOlderThan()` con ventana configurable
- Registrado en `Application.php` via `registerBackgroundJob()`
- Admin setting `retention_days` (1–365, default 30)
- `max(1, $retentionDays)` en CleanupJob para evitar cutoff en el futuro
- `validateTransferInput()` extrae la lógica de validación duplicada entre `transfer()` y `batch()`
- `generateFilePath` → `generateUrl` corregido en todas las llamadas AJAX del JS

### 🔲 Fase 5 — Notificaciones push + Tests + Admin avanzado
- `INotifier` para notificaciones push nativas de Nextcloud al completar/fallar
- Tests unitarios: `isValidRemoteUrl()`, `integrityCheckPasses()`, `sanitizeUrlForLog()`
- Admin settings adicionales: `max_size_mb`, blocklist de dominios
- Checksum por fila en modo batch (actualmente se oculta con 2+ URLs)

---

## Estructura de archivos clave

```
transfer/
├── appinfo/
│   ├── info.xml                          # version=0.8.0, min-version=29, max-version=33
│   └── routes.php                        # transfer, status, probe, batch
├── lib/
│   ├── AppInfo/Application.php           # Bootstrap, registra listener + CleanupJob
│   ├── BackgroundJob/
│   │   ├── CleanupJob.php                # TimedJob semanal, deleteOlderThan(retention_days)
│   │   └── TransferJob.php              # QueuedJob, pasa token a service
│   ├── Controller/TransferController.php # transfer(), status(?since=), probe(), batch()
│   ├── Db/
│   │   ├── TransferJobEntity.php         # STATUS_QUEUED/RUNNING/DONE/FAILED
│   │   └── TransferJobMapper.php         # findRecentByUser, updateStatus, deleteOlderThan
│   ├── Listeners/
│   │   └── LoadAdditionalScriptsListener.php  # Inyecta maxUrls + retentionDays initial state
│   ├── Migration/
│   │   └── Version0800Date20260518000000.php  # Crea tabla transfer_jobs
│   ├── Service/TransferService.php       # Descarga, verifica hash, guarda en FS
│   └── Settings/Admin.php               # ISettings para panel de admin
├── src/main.js                           # Dialog multi-URL + panel de estado
├── templates/admin.php                   # Formulario admin settings
└── docs/HANDOFF.md                       # Este archivo (.gitignore local)
```

---

## Endpoints disponibles

| Ruta | Verbo | Método | Rate limit | Descripción |
|------|-------|--------|-----------|-------------|
| `ajax/transfer.php` | POST | `transfer()` | 30/min | Encola un único job (legacy) |
| `ajax/batch.php` | POST | `batch()` | 20/min | Encola 1–N jobs (activo) |
| `ajax/status.php` | GET | `status(?since=)` | 120/min | Jobs del usuario; `since` acota la ventana |
| `ajax/probe.php` | GET | `probe()` | 60/min | HEAD request para detectar extensión |

---

## Lecciones aprendidas (críticas para no repetir)

### PHP / Nextcloud

1. **`IJobList::add()` devuelve `void`** — no hay job ID. La solución es generar
   el token ANTES de llamar `add()`, guardarlo en DB, y pasarlo como argumento al job.

2. **`IAppConfig` per-app vs global**: usar `OCP\AppFramework\Services\IAppConfig`
   (inyectado, sin necesitar el nombre de la app) en lugar de `OCP\IAppConfig`.

3. **`userFolder->get($dirPath)` puede devolver un File**, no solo Folder.
   Siempre verificar `instanceof Folder` antes de llamar `getNonExistingName()`/`newFile()`.

4. **`putContent()` puede lanzar excepciones** (`LockedException`, `NotPermittedException`).
   Envolver en try/catch/finally. El `finally` debe cerrar el stream.

5. **Mensajes de excepción de Guzzle pueden contener credenciales** (`user:pass@host`).
   Aplicar `sanitizeErrorMessage()` antes de guardar en DB o logs.

6. **Atributos PHP 8 vs docblocks**: NC 32+ prefiere `#[NoAdminRequired]` sobre
   `/** @NoAdminRequired */`. Los docblocks están deprecados en NC 32.

7. **`#[UserRateLimit]`** requiere `OCP\AppFramework\Http\Attribute\UserRateLimit`.
   Disponible desde NC 27.

8. **Validar `hash !== '' && hashAlgo === ''`** — sin esta validación el usuario
   puede enviar hash sin algo y el check se salta silenciosamente.

9. **Validación atómica en operaciones batch**: validar todos los ítems en una primera
   pasada antes de insertar ninguno. Si se mezcla validación con insert, un fallo en
   el ítem N deja filas huérfanas de los ítems 1..N-1 en la DB.

10. **`TimedJob::setInterval()`** acepta segundos. Usar constantes nombradas
    (`WEEK_IN_SECONDS = 7 * 24 * 3600`) en lugar de literales para legibilidad.

### Frontend (Vanilla JS)

11. **`innerHTML` + datos del servidor = XSS**. Siempre aplicar `esc()` a cualquier
    dato de la API antes de interpolar en template literals asignados a `innerHTML`.

12. **`loadState('appId', 'key', default)`** de `@nextcloud/initial-state` para
    leer datos inyectados desde PHP. Requiere `IInitialState::provideInitialState()`
    en el listener PHP.

13. **Re-render del form en cada keystroke destruye el foco**. Separar:
    - `renderDialog()` — reconstrucción completa solo en cambios estructurales
    - Patches puntuales (`input.placeholder`, `btn.disabled`) para cambios de validez

14. **Cada fila del multi-URL necesita su propio `probeTimer`** — si se comparte
    un timer global, los probes de diferentes filas se cancelan entre sí.

15. **`generateUrl('/apps/transfer/ajax/...')`** para rutas de controller.
    `generateFilePath('app', 'type', 'file.php')` es solo para archivos estáticos.

16. **Jobs en `trackedJobs` Map deben purgarse** después de terminal, si no el mapa
    crece indefinidamente y el polling nunca para. Solución: `scheduleJobPrune(token)`.

17. **Doble `OCP.AppConfig.setValue`**: usar un flag `failed` además del contador `saved`
    para evitar mostrar "Saved" si uno de los dos escrituras falló.

### Proceso / Skills

18. **Siempre correr los 3 skills de Nextcloud** tras cambios:
    `/security-review`, `/simplify`, `/review`. Son mandatorios.

19. **`git remote set-head origin <branch>`** — necesario si el repo no tiene
    un HEAD simbólico configurado (el security-review skill falla sin esto).

20. **PR body con heredoc en MCP** — la interpolación `$(cat <<'EOF'...EOF)` no
    funciona cuando se pasa como string a herramientas MCP. Usar strings planos.

---

## Instrucciones para continuar

### Setup inicial en desktop

```bash
git clone https://github.com/jairodmorales/transfer
cd transfer
git checkout claude/nextcloud-compatibility-review-Fy6Xe
npm install
npm run build
```

### Antes de cualquier cambio — verificar skills disponibles

Los skills de Nextcloud son obligatorios. Siempre ejecutar después de cambios:
- `/security-review` — vulnerabilidades de seguridad
- `/simplify` — calidad, reuso, eficiencia
- `/review` — revisión general del PR

### Regla de oro: validar con Nextcloud oficial

Siempre validar contra https://github.com/nextcloud para:
- Versiones de interfaces OCP
- Atributos disponibles por versión de NC
- Patrones de código de apps del core (calendar, contacts, etc.)

### Variables de configuración actuales

```php
// Leer desde PHP (IAppConfig inyectado como per-app)
$maxUrls       = $this->appConfig->getAppValueInt('max_urls', 3);
$retentionDays = $this->appConfig->getAppValueInt('retention_days', 30);

// Leer desde JS
import { loadState } from '@nextcloud/initial-state'
const maxUrls = loadState('transfer', 'maxUrls', 3)
```

---

## Commits en la rama (orden cronológico)

```
96f1aca  Fix download failures and add Nextcloud 32/33 compatibility
28ca94d  Security hardening (Phase 0)
4be671a  Simplify: unify activity events, add types, fix probeTimer leak
6648bf2  Phase 1: async job tracking, floating status panel, token-based polling
ed1ba9d  Security: fix stored XSS in status panel via unescaped server data
250b1f4  Simplify: remove dead state, skip re-render when nothing changed, prune terminal jobs
c360aad  Review fixes: folder type guard, putContent safety, credential sanitization
1fa9ad7  Phase 2 + Admin Settings: multi-URL dialog with admin-configurable limit
d28df8c  Add session handoff document
201156d  Phase 3: restore active/recent jobs on page load
e720f82  Simplify: extract status helpers, fix panelHidden bug, DRY job pruning
fb79979  Phase 4: cleanup job, retention setting, and review fixes
d670a61  Fix: clamp retention_days to minimum 1 in CleanupJob
13002bb  Simplify: extract validateTransferInput, fix dual-save race, remove dead code
```

---

## Cosas pendientes / deuda técnica conocida

- [ ] **Fase 5 — Notificaciones push**: implementar `INotifier` para que Nextcloud
  muestre una notificación nativa al completar o fallar una descarga (campana de NC).
- [ ] **Tests unitarios**: `isValidRemoteUrl()`, `integrityCheckPasses()`,
  `sanitizeUrlForLog()` — funciones puras, fáciles de testear sin stack de Nextcloud.
- [ ] **Admin: max_size_mb** — limitar el tamaño máximo de archivo descargable.
  Requiere leer el header `Content-Length` en `TransferService` y abortar si supera el límite.
- [ ] **Admin: domain blocklist** — lista de dominios prohibidos configurable por el admin.
- [ ] **Checksum en batch** — actualmente el modo multi-URL oculta el campo de checksum.
  Se podría agregar un campo hash por fila en una iteración futura.
- [ ] **PR #1 body** — el body del PR en GitHub muestra sintaxis de heredoc literal
  (bug conocido del MCP). Actualizarlo manualmente desde GitHub.

---

## Recursos

- **Nextcloud OCP API docs**: https://docs.nextcloud.com/server/latest/developer_manual/
- **Repositorio oficial NC**: https://github.com/nextcloud/server
- **Ejemplos de apps NC**: https://github.com/nextcloud/news, https://github.com/nextcloud/calendar
- **Guía de migración NC 32**: https://docs.nextcloud.com/server/latest/developer_manual/app_publishing_maintenance/upgrade.html
- **@nextcloud/initial-state**: https://github.com/nextcloud/nextcloud-initial-state
- **Vite config para NC**: https://github.com/nextcloud/nextcloud-vite-config
