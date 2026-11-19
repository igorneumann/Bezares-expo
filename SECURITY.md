# Informe de seguridad – Expo Bezares

Revisión manual del código completo (PHP, JS, `.htaccess`), 19/11/2026. Es una revisión estática: no se ha hecho
todavía una prueba de penetración contra el servidor real (ver "Pendiente" al final).

## Medidas implementadas

| Área | Medida |
|---|---|
| Acceso a la web | Sin la cookie `expo_device`, todas las peticiones (incluidos medios estáticos) se redirigen a bezares.com desde el `.htaccess`, y además desde PHP. Excepciones: `/.well-known/` (SSL), `/cookie.html` y `/cookie.php`. |
| Cookie de dispositivo | Token aleatorio de 192 bits, `HttpOnly`, `Secure`, `SameSite=Lax`. Se obtiene **automáticamente** al abrir `/cookie.html` (decisión del cliente: sin código, para no requerir intervención en los tablets). Se puede revocar para todos los dispositivos. |
| Caché sin conexión | Si la puerta redirige (cookie revocada), el service worker borra todo lo guardado. Sin conexión, lo guardado sólo se muestra 7 días desde la última autorización confirmada por el servidor. |
| Roles | Administrador (todo) y Editor de contenido (tablets, productos, galería). Configuración, seguridad y usuarios sólo para administradores, comprobado en el servidor. |
| Sesiones tras cambios | Versión de sesión por usuario: cambiar o recuperar la contraseña, o cambiar el rol, cierra todas las sesiones PHP y "mantener sesión" del usuario. |
| Cuotas de subida | Máx. por fichero, cuota total de galería, cuota diaria por usuario, cuota de temporales, 3 subidas simultáneas, espacio libre mínimo en disco, tamaño declarado verificado y bloqueo exclusivo por subida. |
| nginx | Las directivas nginx propuestas no contienen secretos: sólo bloquean rutas internas. La puerta la aplica Apache con el `.htaccess` regenerado automáticamente; la rotación del token lanza la verificación de seguridad. |
| Instalador | Exige una clave de un solo uso guardada en la carpeta de datos (sólo accesible por FTP / Administrador de archivos) y se desactiva al terminar. |
| Contraseñas | `password_hash` (bcrypt), mín. 10 caracteres, rehash automático. Bloqueo: 5 fallos por usuario / 10 por IP en 15 min. Mensajes genéricos (no revelan si el usuario existe) y hash ficticio para igualar tiempos. |
| Sesión | Cookie `HttpOnly` + `Secure` + `SameSite=Strict`, `use_strict_mode`, ID regenerado al entrar, caducidad por inactividad (2 h), sesiones guardadas fuera de `/tmp` compartido. |
| "Mantener sesión" | Tokens selector/validador (validador guardado como SHA‑256), rotación en cada uso, invalidación total si se detecta reutilización, 30 días. |
| Recuperar contraseña | Token de 256 bits guardado como hash, 1 h, un solo uso, invalida los "mantener sesión". El enlace usa la URL canónica de configuración (no la cabecera `Host`). Límite de 5 solicitudes/h por IP. |
| CSRF | Token por sesión en formularios y en la cabecera `X-CSRF-Token` de la API, comprobación de `Origin`, cookies `SameSite=Strict`. |
| Inyección SQL | Todas las consultas con parámetros preparados (PDO, sin emulación). |
| XSS | Todo el HTML del servidor se escapa con `htmlspecialchars`; el panel construye el DOM sin `innerHTML`. CSP estricta (`script-src 'self'`, sin `unsafe-inline` para scripts; el import map usa hash). |
| Subidas | Lista blanca de extensiones por tipo, comprobación del contenido real (cabeceras mágicas / `getimagesize`), nombres saneados sin dobles extensiones, SVG con contenido activo rechazado, subida por trozos con secuencia validada y límite de tamaño. |
| Carpeta `/media` | Nunca ejecuta código (`RemoveHandler`, denegación de `.php/.phtml/.phar/.html/.js…`), `nosniff`, CSP `sandbox` si se abre un fichero directamente. |
| Ficheros internos | Todo está en `httpdocs` (despliegue Plesk Git), con tres capas: `app/.htaccess` (`Require all denied`), reglas del `/.htaccess` generado (`app/`, ficheros ocultos, `.md`, `.sqlite`, `.log`, `.key`…) y directivas nginx propuestas. Cada PHP interno aborta si se ejecuta directamente. Los datos van a la carpeta `private` de Plesk (fuera de la web) cuando existe. |
| `.htaccess` y Git | `/.htaccess` no se versiona (contiene el token); se genera desde `app/htaccess.template` y se regenera solo si falta o cambia, así un despliegue nunca lo deja sin protección ni con un token antiguo. |
| Autodiagnóstico | Botón *Verificar seguridad*: el servidor se pide a sí mismo las rutas internas con la cookie (incluido un fichero testigo aleatorio) y comprueba la puerta sin cookie en páginas y estáticos. |
| Cabeceras | CSP, HSTS, `X-Frame-Options: DENY` + `frame-ancestors 'none'`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `COOP`, `X-Robots-Tag: noindex`. |
| Redirecciones | Las URLs de redirección se construyen a partir de valores validados (sin redirecciones abiertas ni inyección de cabeceras). |
| Clave OpenAI | Guardada en el servidor, nunca se envía al navegador. Texto de traducción tratado como datos en el prompt. Límite de 60 traducciones/h por usuario. |
| Errores | `display_errors` desactivado; errores en `app/data/logs/`. Registro de accesos, fallos de login, autorizaciones de dispositivos y cambios de configuración en `app.log`. |

## Problemas detectados durante la revisión y corregidos

1. **Inyección de cabecera `Host` en el email de recuperación**: el enlace se construía con `HTTP_HOST`. → Ahora usa `public_url` de configuración.
2. **Expresión regular de rutas aceptaba un salto de línea final** (`$` sin modificador `D`) y la redirección reutilizaba la ruta del usuario. → Modificador `D` y redirección construida con el prefijo y el número validados.
3. **Doble extensión en nombres de fichero** (`x.php.jpg`). → El nombre base ya no admite puntos.
4. **Instalador accesible para cualquiera** hasta completarse (riesgo de que un tercero cree el administrador). → Clave de instalación de un solo uso + límite de intentos.
5. **Código y datos dentro de `httpdocs` expuestos si nginx sirve estáticos.** → `app/.htaccess` propio, directivas nginx con `if … return 403` (se aplican antes que las `location` de PHP de Plesk), guardas en los PHP internos, datos en `private/` cuando existe y verificación automática desde el panel.
6. **Errores de PDO podían devolverse al navegador** (PDOException es RuntimeException). → Se capturan aparte y se devuelve un mensaje genérico.
7. ~~Código de acceso de dispositivos corto~~ → sustituido por autorización automática (ver abajo).

### Segunda auditoría (informe externo)

| # | Hallazgo | Estado |
|---|---|---|
| 01 ALTO | `cookie.php` autorizaba si faltaba el hash del código | Corregido primero (denegar por defecto). Después, a petición del cliente, `/cookie.html` autoriza **sin código**: el riesgo pasa a ser intencionado (ver residuales). |
| 02 MEDIO | Contenido sin conexión tras revocar | Borrado de caché al detectar la redirección de la puerta + caducidad de 7 días sin conexión. |
| 03 MEDIO | Agotamiento de almacenamiento por subidas | Cuotas global, diaria por usuario y de temporales; límite de simultáneas; comprobación de disco; tamaño declarado; `flock` por subida. |
| 04 MEDIO | Token sincronizado a mano entre Apache y nginx | El token ya no está en nginx; verificación automática tras cada rotación. |
| 05 BAJO | Sesiones activas tras cambio de contraseña | Versión de sesión por usuario. |
| 06 BAJO | Sin roles | Administrador / Editor de contenido. |

## Riesgos residuales / recomendaciones

- **Autorización sin código (decisión del cliente)**: cualquiera que conozca `https://expo.bezares.com/cookie.html` obtiene acceso de dispositivo. No la publiques ni la compartas fuera del equipo; si se difunde, *Revocar todos los dispositivos*.
- **nginx en Plesk**: si "Servir ficheros estáticos directamente mediante nginx" está activo, el `.htaccess` no protege `/media`. Desactívalo. La verificación de seguridad lo detecta.
- La cookie de dispositivo es la misma para todos los tablets: si se pierde uno, revoca todos. Un tablet desconectado conserva su contenido guardado hasta 7 días.
- El bloqueo por intentos fallidos permite a alguien con acceso de dispositivo bloquear temporalmente (15 min) un usuario conociendo su nombre.
- Usa contraseñas largas y únicas; da a cada persona el rol mínimo necesario.
- Mantén PHP actualizado desde Plesk.

## Pendiente: verificación en el servidor real

La mayor parte se comprueba con *Configuración > 🛡 Verificar seguridad*. Además, a mano:

Tras desplegar, comprobar desde un navegador **sin** cookie (ventana privada):

- `https://expo.bezares.com/` → redirige a bezares.com
- `https://expo.bezares.com/media/photos/<una-foto>.jpg` → redirige a bezares.com (si se ve la foto, nginx está sirviendo estáticos: ver arriba)
- `https://expo.bezares.com/.well-known/acme-challenge/x` → 404 del servidor (no redirige)
- `https://expo.bezares.com/cookie.html` → autoriza y lleva a la portada (en una ventana privada distinta de la anterior)
- `https://expo.bezares.com/install.php` → 404
- `https://expo.bezares.com/.htaccess` y `/_boot.php` → 403/redirección
- Comprobar las cabeceras en https://securityheaders.com (con un dispositivo autorizado no es posible desde fuera: aceptable que muestre la redirección).
