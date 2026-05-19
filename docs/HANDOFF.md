# Handoff: Transfer App — Estado al 19 mayo 2026

Documento para continuar el trabajo en Claude Code desktop.
Pega este archivo como contexto al inicio de la sesión.

---

## Repositorio

- **Fork**: https://github.com/jairodmorales/transfer
- **Rama de trabajo**: `master`
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

## Entorno de producción (Homelab)

La app corre en un LXC Debian 12 (CT 123) en Proxmox:

| Servicio | Detalle |
|---------|---------|
| **IP** | 192.168.10.216 |
| **Nextcloud** | 32.0.9 |
| **PHP** | 8.2 + Apache |
| **DB** | MariaDB 10.11 |
| **App path** | `/var/www/html/nextcloud/apps/transfer/` |
| **Admin** | `admin / Mine0212.` |
| **Proxmox host** | `192.168.10.2` (root / Mine0212.) |

Para conectar desde Windows (sin sshpass):
```bash
PLINK='/c/Program Files/PuTTY/plink.exe'
HK_PVE="SHA256:bs1hscF2q038haZPq7TOQlVowWx4Hj0FzwP5dUvtQOs"
"$PLINK" -ssh -hostkey "$HK_PVE" -batch -pw 'Mine0212.' root@192.168.10.2 \
  "pct exec 123 -- <comando>"
```

Para subir archivos al CT (via Proxmox host):
```bash
PSCP='/c/Program Files/PuTTY/pscp.exe'
"$PSCP" -hostkey "$HK_PVE" -batch -pw 'Mine0212.' archivo.js root@192.168.10.2:/tmp/
# luego: pct push 123 /tmp/archivo.js /ruta/destino/en/ct
```

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

### ✅ Fase 2 — Diálogo multi-URL + Admin Settings
- **Admin Settings**: `Administration → Transfer` (tab propio, ver Fase 6)
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
  - Re-render solo en add/remove (no al escribir — preserva foco)

### ✅ Fase 3 — Carga de jobs al iniciar página
- Al abrir Files, `initPanel()` recupera jobs de la última hora via `/ajax/status.php?since=`
- Jobs activos (queued/running): se agregan al panel y arrancan el poll
- Jobs terminados recientes (done/failed): se muestran 30s y se eliminan

### ✅ Fase 4 — Cleanup job + retention configurable
- `CleanupJob` (TimedJob semanal): llama `deleteOlderThan()` con ventana configurable
- Admin setting `retention_days` (1–365, default 30)

### ✅ Fase 5 — Notificaciones push + Tests + Admin avanzado
- `INotifier` — notificación nativa en campana NC al completar/fallar
- `TransferUtils` — clase estática con funciones puras
- Tests unitarios PHPUnit 10: 35 tests / 37 assertions
- Admin `max_size_mb` y `domain_blocklist`

### ✅ Fase 6 — Compatibilidad NC32 en entorno real + UX Option B (19 mayo 2026)

Esta fase resuelve todos los errores encontrados al desplegar el código en
Nextcloud 32.0.9 real y añade la mejora de UX "Option B" del panel.

#### Correcciones de compatibilidad NC32

**`registerBackgroundJob()` eliminado de `IRegistrationContext`**
- NC32 eliminó `$context->registerBackgroundJob()` de la interfaz `IRegistrationContext`.
- Fix: eliminarlo de `Application.php` y registrar el job en `appinfo/info.xml`:
  ```xml
  <background-jobs>
      <job>OCA\Transfer\BackgroundJob\CleanupJob</job>
  </background-jobs>
  ```
- Registrar en `info.xml` es la forma correcta desde NC32 en adelante.

**`$UserId` nullable en `TransferController`**
- El constructor recibía `string $UserId` pero NC puede inyectar `null` para usuarios
  no autenticados, causando un `TypeError` que NC convertía en HTTP 500.
- Fix en dos lugares: propiedad `private ?string $userId` y argumento `?string $UserId`.
- Resultado: peticiones no autenticadas devuelven 401 correctamente en vez de 500.

**`fclose()` sobre stream ya consumido en `TransferService`**
- `putContent($stream)` cierra el stream internamente.
- El bloque `finally { fclose($stream) }` fallaba con `TypeError: not a valid stream resource`.
- Este error es un `TypeError`, no `\Exception`, por lo que no era capturado por el `catch`.
- El archivo sí se guardaba correctamente; el error era espurio.
- Fix: `if (is_resource($stream)) { fclose($stream); }` en el finally.

**Tab de administración propio (en vez de "Additional")**
- `Admin::getSection()` retornaba `'additional'` → los settings aparecían sepultados.
- Fix: crear `lib/Settings/Section.php` implementando `IIconSection`, registrarlo en `info.xml`:
  ```xml
  <settings>
      <admin>OCA\Transfer\Settings\Admin</admin>
      <admin-section>OCA\Transfer\Settings\Section</admin-section>
  </settings>
  ```
- Cambiar `Admin::getSection()` de `'additional'` a `'transfer'`.
- Resultado: Transfer aparece como tab propio en Administration sidebar.

**CSS panel roto en SPA navigation**
- Al navegar fuera y volver a Files, el panel perdía sus estilos o se fusionaba con el fondo.
- Causas: (1) los `<style>` inyectados se pierden en navegación SPA, (2) transforms en
  padres del SPA rompen `position: fixed`.
- Fixes aplicados:
  - `injectStyles()` llamado al inicio de `renderPanel()` — idempotente por el check de ID.
  - `position: fixed !important` + `top/left: auto !important` + `right/bottom: 16px !important`
    en `.transfer-panel` para sobrevivir transforms de padres.
  - Guard de re-attach: `if (panelEl.parentNode !== document.body) document.body.appendChild(panelEl)`.

**Vite genera `.mjs` pero NC espera `.js`**
- Vite buildea `js/transfer-main.mjs` pero el resource loader de NC registra `transfer-main.js`.
- Fix: symlink `js/transfer-main.js → transfer-main.mjs` (recrear tras cada build).
- El workflow correcto de rebuild:
  ```bash
  cd /var/www/html/nextcloud/apps/transfer
  npm run build
  rm -f js/transfer-main.js
  ln -s transfer-main.mjs js/transfer-main.js
  chown -h www-data:www-data js/transfer-main.js
  chown www-data:www-data js/transfer-main.mjs js/transfer-main.mjs.map
  ```

**Background jobs sin procesar (stuck en "Queued")**
- NC estaba en modo `cron` pero no había crontab instalado.
- Fix: instalar `cron` y agregar entrada para www-data:
  ```
  */5 * * * * php -f /var/www/html/nextcloud/cron.php
  ```

#### UX Option B — Minimize to badge

Reemplaza el único botón `[✕]` del panel por dos acciones independientes:

| Botón | Comportamiento |
|-------|---------------|
| `[−]` Minimize | Oculta el panel → muestra badge circular en la misma esquina |
| `[✕]` Close | Descarta todo (panel y badge). Un nuevo transfer reabre el panel |
| Badge (click) | Expande de vuelta al panel completo |

**Implementación** (`src/main.js`):
- Dos variables de estado mutuamente excluyentes: `panelHidden` y `panelMinimized`.
- `renderBadge()`: crea/actualiza botón circular `.transfer-badge` con ícono de upload
  y burbuja roja con el conteo de jobs activos. Aplica las mismas guardias que el panel:
  `!important` en posicionamiento y re-attach a `document.body` si el SPA lo removió.
- `trackJob()` resetea ambos estados + elimina badge → panel siempre resurface al encolarse un nuevo transfer.
- El badge re-renderiza su contenido en cada poll → el conteo se mantiene actualizado.

---

## Estructura de archivos clave

```
transfer/
├── appinfo/
│   ├── info.xml                          # version=0.9.0, min-version=29, max-version=33
│   │                                     # <background-jobs> y <settings> registrados aquí (NC32)
│   └── routes.php                        # transfer, status, probe, batch
├── lib/
│   ├── AppInfo/Application.php           # Bootstrap: listener + Notifier (sin registerBackgroundJob)
│   ├── BackgroundJob/
│   │   ├── CleanupJob.php                # TimedJob semanal — registrado en info.xml
│   │   └── TransferJob.php               # QueuedJob, pasa token a service
│   ├── Controller/TransferController.php # transfer(), status(?since=), probe(), batch()
│   │                                     # $userId es ?string (nullable — NC puede inyectar null)
│   ├── Db/
│   │   ├── TransferJobEntity.php         # STATUS_QUEUED/RUNNING/DONE/FAILED
│   │   └── TransferJobMapper.php         # findRecentByUser, updateStatus, deleteOlderThan
│   ├── Listeners/
│   │   └── LoadAdditionalScriptsListener.php  # Inyecta maxUrls via IInitialState
│   ├── Migration/
│   │   └── Version0800Date20260518000000.php  # Crea tabla transfer_jobs
│   ├── Notification/
│   │   └── Notifier.php                  # INotifier: SUBJECT_DONE / SUBJECT_FAILED
│   ├── Service/
│   │   ├── TransferService.php           # Descarga, verifica hash, guarda en FS, envía notifs
│   │   │                                 # finally: is_resource($stream) antes de fclose()
│   │   └── TransferUtils.php             # Funciones puras: isValidRemoteUrl, isDomainBlocked, etc.
│   └── Settings/
│       ├── Admin.php                     # ISettings: getSection() retorna 'transfer' (tab propio)
│       └── Section.php                   # IIconSection: define el tab "Transfer" en admin sidebar
├── src/main.js                           # Dialog multi-URL + panel con minimize/badge (Option B)
├── templates/admin.php                   # Formulario admin settings (4 campos)
├── tests/
│   ├── bootstrap.php
│   └── Unit/Service/TransferUtilsTest.php  # 35 tests PHPUnit 10
├── composer.json
├── phpunit.xml
└── docs/HANDOFF.md                       # Este archivo
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

4. **`putContent()` cierra el stream internamente** — el `finally { fclose($stream) }`
   lanza `TypeError: not a valid stream resource`, que NO hereda de `\Exception` y escapa
   el catch normal. Fix: `if (is_resource($stream)) { fclose($stream); }`.
   El archivo se guarda igual; el fclose duplicado es espurio pero hay que silenciarlo.

5. **Mensajes de excepción de Guzzle pueden contener credenciales** (`user:pass@host`).
   Aplicar `sanitizeErrorMessage()` antes de guardar en DB o logs.

6. **Atributos PHP 8 vs docblocks**: NC 32+ prefiere `#[NoAdminRequired]` sobre
   `/** @NoAdminRequired */`. Los docblocks están deprecados en NC 32.

7. **`#[UserRateLimit]`** requiere `OCP\AppFramework\Http\Attribute\UserRateLimit`.
   Disponible desde NC 27.

8. **Validar `hash !== '' && hashAlgo === ''`** — sin esta validación el usuario
   puede enviar hash sin algo y el check se salta silenciosamente.

9. **Validación atómica en operaciones batch**: validar todos los ítems en una primera
   pasada antes de insertar ninguno.

10. **`TimedJob::setInterval()`** acepta segundos. Usar constantes nombradas.

11. **`IRegistrationContext::registerBackgroundJob()` fue eliminado en NC32**.
    La forma correcta para NC32+ es registrar los jobs en `appinfo/info.xml`:
    ```xml
    <background-jobs>
        <job>OCA\MyApp\BackgroundJob\MyJob</job>
    </background-jobs>
    ```
    Si se llama a este método en NC32 se obtienen 100+ errores en los logs por página.

12. **Constructor injection de `$UserId` debe ser `?string`** — NC puede inyectar `null`
    para requests no autenticados. `string $UserId` causa `TypeError` → HTTP 500 en lugar
    de 401. Aplica tanto al argumento del constructor como a la propiedad de clase.

13. **`IIconSection`** (no `ISection`) para un tab propio en el sidebar de administración.
    Registrar en `info.xml` bajo `<settings><admin-section>`. El método `getIcon()` debe
    devolver una URL generada con `IURLGenerator::imagePath()`. El `getID()` debe coincidir
    con el valor retornado por `Admin::getSection()`.

14. **`INotifier::prepare()` debe lanzar `UnknownNotificationException`** (no `\InvalidArgumentException`)
    para subjects no reconocidos. Registrar via `registerNotifierService()`, no en `info.xml`.

15. **`OCP\Notification\IManager`** — diferente de `OCP\Activity\IManager`. Ambos usan
    el alias `IManager` — importar con alias: `use OCP\Notification\IManager as INotificationManager`.

16. **Funciones puras sin dependencias NC** deben extraerse a una clase estática.
    Permite tests PHPUnit sin el stack completo de Nextcloud.

17. **`hash_file()` emite `E_WARNING` con archivo inexistente** — PHPUnit convierte
    warnings en test warnings. Añadir `is_readable($path)` como guarda previa.

### Frontend (Vanilla JS + Vite + Nextcloud)

18. **Vite genera `.mjs`, NC espera `.js`** — el resource loader de Nextcloud registra
    el script como `transfer-main.js` pero Vite produce `transfer-main.mjs`.
    Fix: symlink `js/transfer-main.js → transfer-main.mjs`. Recrear tras cada build.

19. **`position: fixed` roto por transforms en padres del SPA** — NC SPA puede tener
    `transform: translateY(...)` en elementos padres, lo que crea un nuevo containing block
    y rompe `position: fixed`. Fix: `position: fixed !important` combinado con
    `top: auto !important; left: auto !important; right: Xpx !important; bottom: Xpx !important`.

20. **Los `<style>` inyectados se pierden en navegación SPA** — al salir y volver a
    la vista de Files, el head se puede recrear. Fix: llamar `injectStyles()` al inicio
    de `renderPanel()` (y de cualquier render). Hacerla idempotente con un check de ID:
    `if (document.getElementById('transfer-styles')) return`.

21. **Elementos flotantes (panel, badge) se desconectan del DOM en SPA navigation** —
    el SPA puede remover nodos que no administra. Fix: guard de re-attach en cada render:
    `if (el.parentNode !== document.body) document.body.appendChild(el)`.
    Aplica tanto al panel como al badge minimizado.

22. **Dos estados de visibilidad del panel son mutuamente excluyentes** — si el panel
    puede estar "cerrado" Y "minimizado", ambos flags deben resetearse coordinadamente.
    Nunca dejar ambos `true` a la vez. `trackJob()` es el punto centralizado para resetear ambos.

23. **`innerHTML` + datos del servidor = XSS**. Siempre aplicar `esc()` antes de
    interpolar en template literals asignados a `innerHTML`.

24. **`loadState('appId', 'key', default)`** de `@nextcloud/initial-state` para
    leer datos inyectados desde PHP. Requiere `IInitialState::provideInitialState()` en PHP.

25. **Re-render del form en cada keystroke destruye el foco**. Separar render completo
    (add/remove row) de patches puntuales (placeholder, disabled).

26. **Cada fila del multi-URL necesita su propio `probeTimer`** — un timer global
    haría que los probes de diferentes filas se cancelen entre sí.

27. **Jobs en `trackedJobs` Map deben purgarse** después de terminal (30s delay).
    Si no, el mapa crece indefinidamente y el polling nunca para.

28. **`generateUrl('/apps/transfer/ajax/...')`** para rutas de controller.
    `generateFilePath` es solo para archivos estáticos.

### Proceso / Entorno

29. **Siempre hacer backup antes de modificar** cualquier archivo en producción:
    `cp file file.bak` en el CT antes de subir cambios.

30. **Editar archivos con Python o scripts, no bash inline** — el quoting de PHP/JS
    con namespaces (`\OC\Memcache\APCu`) en comandos plink es una trampa.
    Subir el script con `pscp` y ejecutarlo es el patrón seguro.

31. **`pkill` sin target devuelve exit 1** — rompe scripts con `set -e`. Usar `pkill ... || true`.

32. **`git config --global --add safe.directory <path>`** necesario cuando el repo
    pertenece a otro usuario (www-data) y git se ejecuta como root.

33. **NC en modo `cron` necesita crontab real** — si `config.php` tiene
    `'backgroundjobs_mode' => 'cron'` y no hay crontab para www-data, los jobs
    quedan en "Queued" indefinidamente. Instalar `cron` y agregar:
    `*/5 * * * * php -f /var/www/html/nextcloud/cron.php`

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
7b121f3  Update HANDOFF.md: mark phases 0-4 complete, add Phase 5 and lessons 9-20
c4dbf54  Refactor: extract TransferUtils with pure-function utilities
4682abd  Phase 5a: INotifier — native Nextcloud push notifications
63c97ad  Phase 5b: Admin settings — max file size and domain blocklist
dcacc0f  Tests: add PHPUnit suite for TransferUtils (35 tests, 37 assertions)
84c2057  Bump version to 0.9.0
2a05e25  Transfer app v0.9.0: security hardening, async tracking, admin settings, notifications, tests
--- Fase 6 (19 mayo 2026) ---
<próximo commit>  Fix NC32 compatibility: registerBackgroundJob, nullable userId,
                  fclose guard, IIconSection admin tab, SPA CSS fixes, Option B minimize badge
```

---

## Cosas pendientes / deuda técnica conocida

- [ ] **Checksum en batch** — el campo de checksum se oculta en modo multi-URL.
  Agregar un campo hash por fila en versión futura.

- [ ] **Skills obligatorios** — ejecutar `/security-review`, `/simplify` y `/review`
  sobre los cambios de Fase 6 antes de una release formal.

- [ ] **Node version** — el CT tiene Node 20 LTS pero `@nextcloud/files@4.0.0`
  requiere Node ^24. Funciona con warnings (`EBADENGINE`). Considerar upgrade de Node
  en una próxima sesión.

---

## Recursos

- **Nextcloud OCP API docs**: https://docs.nextcloud.com/server/latest/developer_manual/
- **Repositorio oficial NC**: https://github.com/nextcloud/server
- **Ejemplos de apps NC**: https://github.com/nextcloud/news, https://github.com/nextcloud/calendar
- **Guía de migración NC 32**: https://docs.nextcloud.com/server/latest/developer_manual/app_publishing_maintenance/upgrade.html
- **@nextcloud/initial-state**: https://github.com/nextcloud/nextcloud-initial-state
- **Vite config para NC**: https://github.com/nextcloud/nextcloud-vite-config
