# Expo Bezares – sistema de fichas de producto para tablets

Sustituye a la web WordPress de **expo.bezares.com**. PHP 8.1+ (el servidor tiene PHP 8.5) y SQLite, sin dependencias de servidor externas.

## Estructura

La raíz del repositorio **es** `httpdocs`: Plesk lo despliega tal cual desde GitHub.

```
/ (httpdocs)
  index.php          → página principal y páginas de tablet
  cookie.php         → atiende /cookie.html: autoriza el tablet y redirige a la portada
  install.php        → instalador (sólo funciona una vez)
  sw.js              → service worker (caché sin conexión)
  _boot.php          → arranque común (no accesible por web)
  admin/             → panel de administración
  assets/            → CSS/JS y librerías (model-viewer, three.js, conversor STEP)
  media/             → vídeos, modelos 3D y fotos (panel o FTP; no se versionan)
  app/               → CÓDIGO INTERNO, nunca accesible por web
    .htaccess        → "Require all denied"
    htaccess.template→ plantilla del /.htaccess raíz
    bootstrap.php, lib/
    config.sample.php→ copiar como config.php para cambiar valores
    data/            → sólo si no existe la carpeta "private" de Plesk (ver abajo)
  .htaccess          → GENERADO automáticamente (no está en git)
```

**Datos (SQLite, sesiones, logs):** si existe la carpeta `private` de Plesk junto a `httpdocs`
(`/var/www/vhosts/<dominio>/private`), se guardan en `private/expo-data/`, fuera de la web y fuera del
despliegue Git. Si no existe, en `app/data/` (protegida, ignorada por git).

**`/.htaccess`** no se versiona porque contiene el token de la puerta. La aplicación lo genera desde
`app/htaccess.template` en la primera petición y lo regenera sola cada vez que la plantilla cambia o el fichero
falta (por ejemplo tras un despliegue).

## Despliegue con Plesk Git

1. **Copia de seguridad** del WordPress actual (Plesk > Copias de seguridad) y vacía `httpdocs` (salvo `.well-known`).
2. Plesk > dominio > **Git** > *Añadir repositorio*:
   - Repositorio remoto: la URL de GitHub (si es privado, añade en GitHub la clave SSH que muestra Plesk como *Deploy key*).
   - Modo de despliegue: **Automático** (con el webhook que indica Plesk en GitHub > Settings > Webhooks) o Manual.
   - Ruta de despliegue: **`httpdocs`**.
   - Acciones de despliegue adicionales: no hacen falta.
3. Plesk > *Hosting y DNS > Configuración de hosting*: activa **"Redirección 301 permanente de HTTP a HTTPS"**.
4. Asegura la carpeta (sección siguiente).
5. Abre **https://expo.bezares.com/install.php**. Eso crea el fichero `install.key` en la carpeta de datos
   (`private/expo-data/` o `httpdocs/app/data/`); ábrelo con el Administrador de archivos de Plesk o por FTP y copia la clave.
6. Completa el instalador: clave, usuario, email y contraseña. Al terminar
   autoriza tu navegador, te lleva a *Configuración* y se desactiva para siempre.
7. Pulsa **🛡 Verificar seguridad** en *Configuración*: todo debe salir en verde.

> Cualquier visitante sin la cookie de dispositivo es redirigido a https://bezares.com/.
> Los medios subidos (`media/`) y los datos no están en git: los despliegues no los tocan.

## Asegurar la carpeta en Plesk

Como todo el proyecto está dentro de `httpdocs`, lo interno (`app/`, base de datos, `.htaccess`, `README.md`…)
se protege en tres capas. Haz los pasos 1 y 2 una vez:

1. **Plesk > dominio > Configuración de Apache y nginx**
   - En *Configuración de nginx*, **desactiva "Servir ficheros estáticos directamente mediante nginx"**
     (si no, nginx entrega ficheros sin pasar por el `.htaccess`: la puerta de cookie y los bloqueos no se aplicarían a ellos).
   - Deja activado el **modo proxy** (Apache detrás de nginx) para que se lean los `.htaccess`.
2. **Directivas nginx adicionales** (misma página, abajo): pega el bloque que muestra el panel en
   *Configuración > Acceso de dispositivos > Directivas nginx para Plesk*. Bloquea `app/`, ficheros ocultos y
   extensiones sensibles directamente en nginx. **No contiene ningún secreto**: no hay que tocarlo nunca más,
   tampoco al revocar dispositivos (la puerta de cookie la aplica sólo Apache con el `.htaccess` generado).
3. Automático (no requiere nada): `app/.htaccess` con `Require all denied`, reglas del `/.htaccess` generado
   (`app/`, ficheros ocultos, `.md`, `.sqlite`, `.log`, `.key`…) y una comprobación en cada fichero PHP interno que
   impide ejecutarlo directamente.

Opcional pero recomendado: Plesk > *Configuración de PHP* > `open_basedir` = `{WEBSPACEROOT}{/}{:}{TMP}{/}` (valor por defecto).

### Verificar
*Configuración > 🛡 Verificar seguridad*: el servidor se pide a sí mismo, **con** la cookie (para que la redirección
no oculte nada), `app/bootstrap.php`, `app/lib/db.php`, `app/htaccess.template`, un fichero testigo con contenido
aleatorio, la base de datos y logs (si están en `httpdocs`), `/.htaccess`, `/.git/config`, `/README.md`…
y comprueba que **sin** cookie la página, el panel y un fichero estático redirigen a bezares.com y que
`/.well-known/` y `/cookie.html` siguen accesibles.

- **✖ Expuesto** en un fichero estático sin cookie → nginx sirve estáticos directamente (paso 1).
- **⚠ Error 500** en `app/*.php` → el `.htaccess` no se aplica (modo proxy desactivado o nginx procesa PHP): añade las directivas del paso 2.
- Repite la verificación tras cualquier cambio en Plesk.

## Uso diario

### Autorizar un tablet
En cada tablet abre **https://expo.bezares.com/cookie.html**: añade la cookie y redirige a la portada automáticamente,
sin que el usuario tenga que hacer nada. **Cualquiera que conozca esa dirección queda autorizado**, así que no la publiques.
La cookie dura 400 días (máximo permitido por los navegadores). Para revocar todos los tablets:
*Configuración > Revocar todos los dispositivos* (luego hay que volver a abrir /cookie.html en cada uno). La revocación
lanza la verificación de seguridad automáticamente. Los tablets conectados borran su contenido guardado al detectar la
revocación; sin conexión, el contenido guardado deja de mostrarse a los 7 días de la última autorización confirmada.

### Roles
- **Administrador**: todo (configuración, seguridad, usuarios, contenido).
- **Editor de contenido**: tablets, productos y galería; sólo puede cambiar su propia contraseña.

Cambiar una contraseña (o recuperarla, o cambiar el rol) cierra todas las sesiones abiertas de ese usuario.

### Límites de subida
Configurables en `app/config.php` (ver `config.sample.php`): 2 GB por fichero, 30 GB de galería en total,
10 GB por usuario y día, 4 GB de subidas en curso, 3 subidas simultáneas por usuario y 2 GB de disco libre mínimo.

Recomendado en cada tablet: abrir la web en Chrome y "Añadir a pantalla de inicio" (se abre a pantalla completa).

### Panel de administración
Enlace en el pie de la página principal → `/admin/` (usuario/contraseña, "Mantener la sesión iniciada" 30 días,
"¿Has olvidado la contraseña?" envía un enlace válido 1 hora — hay que abrirlo desde un dispositivo autorizado).

- **Tablets**: número, activo/inactivo, de 2 a 5 productos con orden, producto por defecto, flecha
  (izquierda / arriba / derecha) que indica dónde está el producto físico, foto del índice y 2 idiomas.
  A la derecha, **vista previa** del tablet seleccionado (toca su tarjeta) con la página real guardada: horizontal o
  vertical, botones para cada producto, recargar y abrir en otra pestaña. No cuenta en las estadísticas ni entra en reposo.
- **Productos**: nombre reducido, título / descripción / características en inglés + traducciones automáticas
  con ChatGPT (editables), modelo 3D, foto principal (si no hay 3D), vídeo (galería o YouTube) y fotos adicionales
  con tamaño pequeño / mediano / grande.
- **Galería**: Vídeos, Modelos 3D (GLB, glTF, OBJ, STEP/STP; posición, tamaño, luminosidad, sombra y velocidad de giro
  con vista previa en vivo) y Fotos. Botón **Escanear carpeta FTP**: registra lo subido por FTP a `/media` (cualquier
  subcarpeta) y **mueve todos los medios, también los ya importados, a su carpeta**: `media/videos/`, `media/photos/`,
  `media/models/` (GLB) y `media/models/_sources/` (originales STEP; los glTF/OBJ en una carpeta propia con sus .bin,
  .mtl y texturas). Las carpetas de importación que quedan vacías se eliminan y se listan los ficheros que sobran.
  Casillas de selección y "Seleccionar todo" para eliminar varios ficheros a la vez.
- **Eventos**: cada evento (feria) tiene sus propios tablets, título y temas, y un interruptor para **activarlo o
  desactivarlo**; puede haber **varios activos a la vez**. La página principal muestra un botón por evento activo (con uno
  solo, su título). Un evento nuevo empieza vacío o como copia de otro, y desactivado. Las páginas de un evento desactivado
  sólo se ven con la sesión del panel abierta (para prepararlo). Al eliminarlo se borran sus tablets, no los productos.
- **Configuración**: título de la web (pestaña del navegador), imagen de encabezado, logo de fondo del 3D, colores del apartado de texto (fondo de la
  franja del titular y del texto, y color de la letra: automático según el fondo o elegido), newsletter,
  clave de OpenAI, idiomas, usuarios y roles, seguridad.
- Debajo de **Tablets**, en el menú, aparecen los eventos (punto verde = activo): el elegido es el que se edita. Bloque
  **Evento**: título del evento (botón de la página principal) y **tema en horizontal** y **tema en vertical** de los tablets: Escaparate 3D o Tarjetas, claro u oscuro (pueden ser
  distintos; al girar el tablet la página cambia sola, también sin conexión).

### Páginas de tablet (escaparate 3D)
- El **modelo 3D** ocupa la pantalla sobre el logo; el **titular** va arriba a la izquierda y el **vídeo** en miniatura
  arriba a la derecha (pantalla completa al tocarlo).
- **Barra de productos** abajo con foto, nombre y **flecha de ubicación** grande ("On your right / A tu derecha"…).
- **Deslizar el dedo** a izquierda/derecha cambia de producto (sobre el modelo 3D el gesto lo gira).
- **More information / Más información** abre un panel con descripción, características y fotos.
- **Idioma**: la interfaz empieza en el idioma por defecto (inglés, configurable); la **banderita** de la esquina
  cambia al otro idioma del producto (Idioma 1 / Idioma 2 del tablet). Textos de la interfaz integrados en inglés,
  español, alemán, francés, italiano y portugués; para otros, se escriben o se generan con ChatGPT en Configuración.
- **Modo oscuro**: fondo azul noche, logo de fondo en blanco, barra y panel oscuros y azul claro de la marca para lo
  activo; el rojo de Newsletter y de las flechas no cambia. Los colores del panel elegidos en Configuración se respetan.
  Se elige por evento en *Tablets > Evento*.
- **Modo reposo**: tras el tiempo configurado sin tocar (120 s por defecto; 0 = desactivado) vuelve al producto y al
  idioma por defecto, sólo con el 3D girando y el aviso "Touch to explore".

### Páginas de tablet (tarjetas)
Alternativa al escaparate, evolución del diseño original, con la **misma barra de productos** fija abajo (con el texto de la
flecha también en vertical) y la **banderita** de idioma flotando encima, a la derecha. Contenido: **vídeo y 3D** en
tarjetas redondeadas (lado a lado en horizontal, apilados en vertical, con pantalla completa), **titular, descripción y
características** en el idioma activo (con los colores del apartado de texto de Configuración) y **fotos adicionales**.
Mantiene idioma por bandera, Newsletter, deslizar para cambiar de producto, modo reposo y estadísticas. Versión clara
(fondo gris claro, tarjetas blancas) u oscura (azul noche).

### Direcciones (URLs)
Automáticas: `/e<evento>-t<tablet>/` (producto por defecto) y `/e1-t1a/`, `/e1-t1b/`… (a = primer producto). Las direcciones
anteriores (p. ej. `/bbm-t1a/`) siguen abriendo el evento que las usaba, mientras esté activo.

### Paso a varios eventos (base de datos v5)
Se hace solo en la primera visita tras desplegar. Antes se guarda una copia en la carpeta de datos (`expo-antes-v5.sqlite`).
Lo que estaba en uso pasa a la feria en curso, que queda activa; los demás eventos guardados se conservan desactivados. Si la
copia guardada de la feria en curso era distinta de lo que estaba en uso, queda aparte como «… (copia guardada)».

### Modelos STEP / OBJ
Los tablets sólo descargan **GLB**. Al subir o escanear STEP/OBJ/glTF, el panel los convierte a GLB **en el navegador del
administrador** (el conversor STEP pesa ~7,7 MB y sólo se descarga en el panel). El original se conserva y puede reconvertirse.
Para OBJ con materiales, sube el `.mtl` con el mismo nombre en la misma carpeta (por FTP).

## Ahorro de ancho de banda

- Todos los recursos llevan `?v=<versión>` y se sirven con `Cache-Control: immutable` de 1 año.
- **Service worker**: guarda páginas, vídeos, modelos y fotos del tablet en el dispositivo; tras la primera carga funciona
  sin conexión y sólo pregunta al servidor si la página ha cambiado (respuesta 304 de pocos bytes).
- Al abrir un tablet se guardan en segundo plano, uno a uno, todos los productos de ese tablet (primero el vídeo en pantalla).
- **Vídeos**: se descargan una sola vez, por trozos de 2 MB; si se corta la red o se cambia de producto, la descarga continúa
  donde estaba. Hasta que el vídeo está guardado se ve su imagen con una barra de progreso. Si no se puede guardar
  (sin espacio, navegador sin soporte), se reproduce directo del servidor.
- **Indicador de contenido guardado**: punto arriba en el centro de cada tablet (verde = todo guardado, verde claro = todo salvo
  vídeos de YouTube, naranja = guardando, rojo = falta algo). Al tocarlo muestra el detalle. Compruébalo antes de la feria.
- Los tablets confirman su autorización con `ping.php` al abrir una página, cada 30 minutos y al recuperar la red.
- Fotos redimensionadas automáticamente a WebP (480/1024/1920 px) con `srcset`.
- Sin librerías externas ni CDNs: todo se sirve desde el propio dominio.
- Vídeos: mejor subirlos (MP4 H.264, 1080p, ~5–8 Mb/s) que usar YouTube, que no se puede guardar en caché.

## Seguridad

Ver [SECURITY.md](SECURITY.md).
