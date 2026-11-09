# ARAS

Tienda de cuidado personal, belleza y perfumería con catálogo administrable,
inventario, banners, bolsa de compras y solicitudes de entrega a domicilio.
El catálogo actual contiene 30 artículos demostrativos para pruebas, 10 por
categoría. Sus nombres y precios son ficticios y deben reemplazarse por datos
reales antes de vender. Las imágenes son fotografías de referencia reutilizables;
consulta sus fuentes y licencias en [Créditos de imágenes](./creditos-imagenes.html).

## Panel de administración

El proyecto requiere Apache y PHP de XAMPP, con las extensiones `fileinfo` y GD
(incluida WebP y `pdo_mysql`).
Las rutas públicas y del panel ocultan `.html` y `.php`; Apache debe tener
`mod_rewrite` habilitado y permitir reglas `.htaccess`.
El proyecto utiliza Composer para PHPMailer y el envío autenticado por SMTP.

### Seguridad del acceso administrativo

En producción, publica el panel y las páginas de recuperación únicamente por
HTTPS con un certificado TLS válido. Apache/PHP debe recibir una indicación
fiable de que la solicitud original usó HTTPS (`HTTPS=on`); si TLS termina en un
proxy, configura ese proxy para eliminar cualquier encabezado HTTPS enviado por
el cliente y establecer esa variable solo después de validar la conexión TLS.
No confíes directamente en `X-Forwarded-Proto` desde solicitudes públicas.

El panel redirige solicitudes GET/HEAD de producción a HTTPS, rechaza otros
métodos inseguros, envía HSTS en conexiones HTTPS y marca las cookies de sesión
de producción como `Secure`. Configura también la redirección HTTP→HTTPS (y el
rechazo de POST HTTP) en el servidor/proxy frontal, antes de que la solicitud
llegue a PHP. Los enlaces de restablecimiento de producción siempre usan HTTPS.
El uso de HTTP se mantiene solo para `localhost`, `127.0.0.1` y `::1` en XAMPP.

TLS cifra el transporte entre el navegador y el servidor/proxy que termina TLS;
no es cifrado de extremo a extremo estricto, ya que el servidor necesita recibir
las credenciales para validar el acceso. Antes de publicar, comprueba que
`http://TU-DOMINIO/admin` redirija a HTTPS y que el certificado HTTPS sea válido.

1. Inicia Apache desde el panel de control de XAMPP.
2. Configura las conexiones por separado; el panel elige el archivo según el
   servidor web (`localhost` usa local y cualquier otro nombre usa producción):
   - **XAMPP:** copia `storage/database.local.example.php` como
     `storage/database.local.php`. Ajusta la base, usuario y contraseña a tu
     MySQL local. XAMPP suele usar `root` sin contraseña, pero verifica tu
     propia configuración.
   - **Producción:** copia `storage/database.production.example.php` como
     `storage/database.production.php`. Sustituye los valores de ejemplo por
     el host, nombre, usuario y contraseña que muestra Hostinger.
   La carpeta `storage` está bloqueada para peticiones web. No publiques ni
   compartas los archivos de configuración reales. Edita el archivo real
   `database.production.php`, no solamente la plantilla `.example.php`.
   Instala las dependencias con `composer install --no-dev --optimize-autoloader`
   tanto en desarrollo como en producción.
3. ARAS utiliza una base de datos independiente llamada `aras` en cada entorno;
   no apuntes su configuración a la base de datos de otra tienda o aplicación.
   Crea la base local con el usuario MySQL de XAMPP. En producción, crea una
   base `aras` en el panel de hosting y concede acceso al usuario MySQL de ARAS;
   actualiza únicamente el nombre de base en `storage/database.production.php`
   si el hosting agrega un prefijo al nombre.
   Importa [`database/Base de datos.sql`](./database/Base%20de%20datos.sql) en
   la base `aras` local y en producción para crear la tabla `Usuarios` y las
   tablas de pedidos (`orders` y `order_items`) en cada entorno. Si la tabla `Usuarios`
   ya existía antes de agregar los datos personales, agrega únicamente los
   campos que aún no tenga. Si falta el correo, ejecuta:
   `ALTER TABLE Usuarios ADD COLUMN email VARCHAR(254) NULL;`
   Para los campos nuevos ejecuta:
   `ALTER TABLE Usuarios ADD COLUMN full_name VARCHAR(120) NULL;`
   `ALTER TABLE Usuarios ADD COLUMN phone VARCHAR(30) NULL;`
   `ALTER TABLE Usuarios ADD COLUMN national_id VARCHAR(15) NULL;`.
   Si la tabla `orders` ya estaba creada antes de habilitar la selección de
   ubicación de entrega, ejecuta una sola vez
   [`database/migrate-order-delivery-location.sql`](./database/migrate-order-delivery-location.sql)
   en cada entorno.
   Si `orders` ya existía antes de habilitar pagos, ejecuta una sola vez
   [`database/migrate-order-payment.sql`](./database/migrate-order-payment.sql)
   en cada entorno.
   Si la base ya existía antes de habilitar cuentas de clientes, ejecuta una
   sola vez [`database/migrate-customer-accounts.sql`](./database/migrate-customer-accounts.sql)
   en cada entorno. La plantilla principal ya crea la tabla `customers` y la
   columna `orders.customer_email` para instalaciones nuevas. Haz una copia de
   seguridad antes de migrar.
   Para habilitar permisos por sección y limitar a una sola cuenta
   Superadministrativo en una base ya existente, ejecuta una vez
   [`database/migrate-admin-permissions.sql`](./database/migrate-admin-permissions.sql)
   en cada entorno. Antes de aplicarla, comprueba que no haya más de una cuenta
   con rol `Superadministrativo`:
   `SELECT id, username FROM Usuarios WHERE role = 'Superadministrativo';`
   Si hay varias, conserva solo la cuenta principal y cambia las demás a
   `Administrativo` (con permisos definidos) o elimínalas antes de crear la
   restricción única. Haz una copia de seguridad antes de modificar datos.
   Para impedir correos duplicados en una tabla existente, primero comprueba
   que no haya duplicados con
   `SELECT LOWER(email), COUNT(*) FROM Usuarios WHERE email IS NOT NULL GROUP BY LOWER(email) HAVING COUNT(*) > 1;`.
   Resuelve cualquier duplicado antes de ejecutar
   `ALTER TABLE Usuarios ADD UNIQUE KEY uq_usuarios_email (email);`.
   Las tablas nuevas ya incluyen esta restricción.
   Para habilitar la recuperación en una base existente, importa también el
   bloque `CREATE TABLE password_reset_tokens` de ese archivo. Las tablas de
   pedidos existentes se conservan porque el esquema usa `IF NOT EXISTS`.
4. Crea la primera cuenta técnica desde la terminal:
   - XAMPP: `php admin/create-technical-user.php local`
   - Servidor de producción: `php admin/create-technical-user.php production`
   Elige un nombre de usuario cuando lo solicite. El comando genera una
   contraseña aleatoria y la muestra una sola vez; guárdala en un lugar seguro.
   Ejecuta el comando por separado en cada entorno, porque las bases de datos
   local y producción son independientes.
5. Inicia sesión en la URL local o publicada con la cuenta técnica. Esta cuenta
   solo puede crear la cuenta Superadministrativo inicial y no administra
   productos ni anuncios. Solo se permite una cuenta Superadministrativo por
   base de datos.
   Para renovar su contraseña desde la terminal, usa
   `php admin/reset-technical-password.php local Admin` en XAMPP o cambia
   `local` por `production` en el servidor; la nueva contraseña aparece una
   sola vez.
6. La cuenta Superadministrativo puede crear usuarios `Administrativo` desde
   la página administrativa `/admin/administrativos` y asignarles permisos por sección: banners,
   agregar productos y categorías, administrar productos, pedidos, y
   configuración e inventario. El resumen general siempre está disponible.
   Puede cambiar permisos o desactivar cuentas administrativas; estas no
   pueden crear usuarios ni asignar permisos. Las credenciales iniciales se
   envían por Gmail SMTP autenticado. No existe un registro público de usuarios.
7. El menú de cada cuenta permite actualizar nombre completo, correo, celular y
   cédula, además de modificar la contraseña. El nombre completo se muestra en
   negrita en el saludo del panel; el usuario de acceso no cambia. El correo no
   puede repetirse; celular y cédula son opcionales. Las contraseñas nuevas y
   las contraseñas iniciales elegidas manualmente requieren al menos 8
   caracteres.
   Si el cliente olvida la contraseña, puede solicitar un enlace temporal desde
   “¿Olvidaste tu contraseña?” en el acceso; el enlace vence en 30 minutos y
   solo se puede utilizar una vez.
8. Los usuarios administrativos solo verán y podrán modificar las secciones
   que el Superadministrador les haya asignado. El Superadministrador mantiene
   todos los accesos al panel.
   En Agregar producto, selecciona “Crear o administrar categorías” para añadir
   una categoría con su imagen. Las categorías se actualizan sin recargar el
   panel y aparecen como “Próximamente” hasta que tengan un producto publicado.
   Las galerías de productos muestran miniaturas y permiten cambiar el orden
   de las fotos; la primera será la imagen principal del producto.
   En Configuración se puede actualizar o eliminar el logo de la tienda; la
   eliminación solicita confirmación.
   En Banners de inicio, cada banner subido tiene una opción para mostrarlo u
   ocultarlo de la tienda y otra para eliminarlo. Los ejemplos se pueden
   habilitar u ocultar individualmente; solo se muestran cuando no hay banners
   subidos publicados. La selección de los ejemplos se conserva en
   `storage/hidden-demo-banners.json`.
   El resumen muestra las unidades vendidas y el valor de los productos en
   pedidos vendidos, sin incluir el costo de envío. Al desactivar el control de
   inventario, se ocultan los avisos y el umbral de pocas unidades.
9. El cliente crea el pedido y paga con el widget de Wompi. El pedido vence a
   las 24 horas si no se confirma el pago; el inventario solo se descuenta al
   recibir la aprobación de Wompi. Los pedidos vencidos no descuentan stock.
   Un pago recibido después del vencimiento queda marcado para revisión manual.
   Los envíos solo están habilitados para Medellín y otros municipios de
   Antioquia.

## Pagos Wompi

1. En desarrollo local, copia `storage/wompi.local.example.php` como
   `storage/wompi.local.php` y completa la llave pública Sandbox, el secreto de
   integridad y el secreto de eventos de Wompi Sandbox. En producción usa la
   plantilla `storage/wompi.production.example.php` como
   `storage/wompi.production.php` y configura exclusivamente llaves de
   producción.
2. Los archivos activos están dentro de `storage`, protegidos por Apache y
   excluidos del repositorio. No pegues llaves privadas en el código, en
   tickets ni en el chat. La tienda exige Sandbox al acceder por `localhost`
   y producción al acceder desde otro dominio.
3. En el panel de Wompi, configura como URL de eventos
   `https://TU-DOMINIO/api/pago-wompi`. Wompi necesita HTTPS público para
   notificar pagos; no puede enviar eventos directamente a XAMPP/localhost.
   Hasta publicar un staging con HTTPS, el flujo local permite probar el
   widget Sandbox, pero el estado final del pago requiere recibir el evento.
4. Programa `admin/expire-orders.php` cada cinco minutos para caducar pedidos
   incluso si no hay visitas. En XAMPP, usa el Programador de tareas de Windows
   para ejecutar `C:\xampp\php\php.exe` con los argumentos
   `"C:\xampp\htdocs\Sara_Creadora de contenido\admin\expire-orders.php" local`.
   En producción, configura una tarea cron equivalente ejecutando el archivo
   con el argumento `production`. Verifica la ruta real de PHP y del sitio.
5. Si Wompi informa una aprobación luego del vencimiento, el pedido no vuelve
   a reservar inventario automáticamente: el panel lo marca para revisión
   manual. El cliente podrá necesitar coordinar el reembolso o una nueva
   compra según el caso.

Las imágenes aceptadas son JPG, PNG y WebP, de hasta 5 MB y 6000 × 6000 píxeles.
El panel las procesa a WebP y las almacena en `storage/uploads`; el directorio
está protegido frente al acceso directo. Los productos publicados aparecen en
Inicio y Catálogo. El umbral para mostrar “Pocas unidades” se puede ajustar en
Configuración (por defecto, 5 unidades); cero unidades muestra “Agotado” y
bloquea la compra.

Las cuentas de clientes se registran en la tabla `customers`, con contraseñas
guardadas mediante `password_hash`; la sesión del cliente usa su propia cookie
HttpOnly, Secure en producción y SameSite=Strict, separada de la sesión
administrativa. Las cuentas son distintas: el registro público de clientes no
crea ni habilita acceso administrativo. Los pedidos creados mientras el
cliente inició sesión quedan vinculados a su correo y aparecen en Mi cuenta.
Los pedidos anteriores a esta asociación no pueden mostrarse automáticamente
porque no guardaban el correo del comprador.

El botón **Mi cuenta** abre la página independiente `mi-cuenta`, con inicio de
sesión, registro y seguimiento de pedidos. Usa la sesión de cliente; no comparte
el acceso administrativo.

En **Panel administrativo → Productos** puedes importar hasta 500 productos
desde [la plantilla CSV](./admin/plantilla-productos.csv). Conserva exactamente
las columnas `name,price,category,stock,active,is_new,image,variants`; usa la
clave de categoría existente y valores `1`/`0` para `active` e `is_new`. En el
formulario puedes adjuntar las fotos JPG, PNG o WebP (máximo 5 MB por foto y
25 MB en total); escribe el nombre exacto de cada archivo en la columna `image`.
El sistema convierte las imágenes a WebP. Si `image` se deja vacío, se reutiliza
la imagen predeterminada del catálogo. El CSV admite hasta 5 MB.

La importación valida cada fila por separado: agrega los productos correctos y
muestra cuántos se importaron, cuáles se omitieron y el motivo de cada fila.
Para tallas, colores o presentaciones, `variants` acepta JSON con hasta 3 grupos
y 30 opciones por grupo, por ejemplo:

```json
[{"name":"Tamaño","options":[{"label":"30 ml"},{"label":"50 ml"}]}]
```

Los colores pueden incluir hasta cuatro valores hexadecimales `colors` para
mostrar una muestra sólida o un degradado. Las variaciones son opcionales; el
precio y el inventario se manejan por producto completo, no por cada opción.
El panel también permite configurar las mismas variaciones al agregar o editar
un producto.

La carpeta `storage` debe permitir escritura a Apache y sus archivos `.htaccess`
deben estar habilitados. Los archivos `storage/database.local.php` y
`storage/database.production.php` contienen credenciales y deben mantenerse
fuera del control de versiones.
Para configurar el correo, copia `storage/mail.smtp.example.php` a
`storage/mail.smtp.php` en cada entorno y completa el correo de Gmail y una
contraseña de aplicación de Google (no la contraseña normal de Gmail).
Activa la verificación en dos pasos de esa cuenta para generar su contraseña
de aplicación desde la seguridad de la cuenta Google. En el archivo de
configuración, `username` debe ser la cuenta Gmail autenticada. `from_email`
puede usar esa misma cuenta o un alias previamente verificado en Gmail en
"Enviar como"; Gmail rechazará remitentes que no estén autorizados. Para la
tienda, usa el dominio real `entresueno.shop` (sin `ñ`), por ejemplo
`no-responder@entresueno.shop`; configura esa dirección como alias remitente
en Gmail o crea el buzón/alias en el proveedor del dominio. Las respuestas
quedarán dirigidas a `from_email`. El host, puerto y cifrado deben permanecer
como `smtp.gmail.com`, `587` y `tls`.
La contraseña de aplicación puede pegarse con o sin los espacios de
presentación; el sistema elimina los espacios antes de autenticar.
Ese archivo privado está excluido del control de versiones y protegido por
`storage/.htaccess`. En Hostinger, ejecuta Composer en la carpeta del sitio
después de publicar `composer.json` y `composer.lock`. Una vez configurado el
SMTP, los clientes que no recibieron el correo inicial pueden solicitar un
enlace de restablecimiento desde “¿Olvidaste tu contraseña?”.

Apache usa `.htaccess` para servir la página de error responsive [404.html](./404.html)
cuando una ruta del sitio no existe.

El favicon del sitio se encuentra en [`img/favicon.ico`](./img/favicon.ico).
