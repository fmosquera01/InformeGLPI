# Informe Técnico - Songa

Plugin nativo para GLPI 11. Directorio interno: `informeglpi`. **Versión 0.5.92.**

## Objetivo

Gestionar el ciclo de vida del documento institucional **Informe Técnico de Ítems de Propiedad, Planta y Equipos**, vinculado al activo GLPI. Cada informe es un registro independiente y conserva una fotografía histórica de los datos del activo al momento de su elaboración.

## Reglas funcionales acordadas

- Un activo puede tener múltiples informes; el historial no sobrescribe documentos anteriores.
- El formato institucional aprobado debe reproducirse sin alterar encabezados, títulos, distribución, firmas ni estructura.
- Los datos del activo y sus componentes se guardan como snapshot histórico.
- El valor en libros se ingresa manualmente por el personal autorizado y conserva usuario y fecha de registro.
- Tipo de daño: **Daño a la propiedad** o **Desgaste**. Puede cambiarse durante el borrador y se bloquea al finalizar.
- Evidencias fotográficas múltiples, con categoría, autor y checksum.
- Firmas de técnico y supervisor/administrador, con usuario, fecha y checksum de la imagen.
- Flujo previsto: Borrador → Firma técnico → Firma supervisor/administrador → Finalizado.
- El PDF final se conserva con SHA-256 y no debe regenerarse ni modificarse después de finalizar.
- Los permisos se integran exclusivamente con perfiles y derechos nativos de GLPI; no existe ACL paralela.
- La desinstalación del plugin no elimina los informes ni sus tablas.

## Estructura actual

- `setup.php`: metadatos, inicialización y registro de pestañas.
- `hook.php`: creación idempotente de tablas y registro de derechos nativos.
- `src/Profile.php`: pestaña de derechos en Administración → Perfiles.
- `src/Report.php`: entidad CommonDBTM del informe.
- `src/AssetSnapshotBuilder.php`: captura los campos escalares del activo y, para computadores, procesadores, memorias, discos, tarjetas de red/MAC, gráficos, sonido, controladoras, baterías y sistemas operativos relacionados; para teléfonos de tipo Hand Held y Tablet, procesadores, memorias y almacenamiento.
- `src/ReportService.php`: creación transaccional, correlativo anual y snapshot histórico del activo y sus componentes.
- `front/report.form.php`: guarda el nuevo informe al enviar el formulario visible en la pestaña Nuevo.
- `front/report.edit.form.php`: actualización de los campos técnicos mientras el informe está en borrador.
- `src/ReportTab.php`: pestaña del activo con vistas Nuevo, Borradores y Aprobados; formulario nuevo y edición de borradores en línea.
- `front/report.php`: vista inicial de un informe.
- `README.md`, `CHANGELOG.md`.

## Modelo de datos inicial (v0.3.0)

- `glpi_plugin_informeglopi_sequences`: correlativo anual.
- `glpi_plugin_informeglopi_reports`: informe, estado, campos manuales, snapshot JSON y metadatos del PDF.
- `glpi_plugin_informeglopi_signatures`: firmas técnico/supervisor.
- `glpi_plugin_informeglopi_evidences`: fotografías y archivos de evidencia.
- `glpi_plugin_informeglopi_history`: auditoría de acciones y cambios.

Las relaciones se gestionan desde el plugin y no se crean claves foráneas hacia tablas GLPI, para mantener compatibilidad con el ciclo de vida de sus activos.

## Estado de desarrollo

**Versión 0.5.26 — datos técnicos específicos para computadores, Hand Held y Tablet.**

El formulario incorpora número correlativo, fecha automática de creación no editable, destinatarios múltiples de GLPI, técnico elaborador, revisor y asunto editable. Los usuarios se validan contra usuarios activos de GLPI. Código de activo usa el número de inventario; tipo, marca, modelo, serie, responsable, ubicación y fecha de compra se consultan desde el activo. El valor en libros permanece manual.

La carga de fotografías JPG/PNG/WEBP, su almacenamiento privado y su visualización en el detalle del informe ya están implementados. Las firmas, el flujo de aprobación y el PDF institucional siguen pendientes.

El formulario de creación y edición comparten la misma estructura, basada en el Word institucional: número, fecha, destinatarios, elaborador, revisor y asunto; información del activo; información técnica; fotografías. El título institucional completo se reserva para el PDF final. La pestaña seleccionada y las acciones de edición usan acento amarillo. El diseño se adapta a pantallas móviles. La carga de fotografías está disponible; las firmas, aprobación y PDF final continúan pendientes.



Implementado en repositorio:
- Registro de pestaña de informes para Computer, Phone, Printer, Monitor y NetworkEquipment.
- Pestaña de permisos en perfiles GLPI con derechos de consulta/edición y aprobación.
- Esquema inicial de informes, correlativos, firmas, evidencias y auditoría.
- Consulta de historial por activo.
- Creación de borradores desde activos compatibles.
- Numeración anual `ITSISAAAA-NNN` reservada dentro de una transacción.
- El contador de cada año inicia en cero en una base nueva; antes de producción debe inicializarse con el último número oficial ya utilizado para evitar duplicar informes históricos.
- Snapshot JSON de los campos escalares disponibles del activo al momento de crear el borrador.
- Registro de auditoría para la creación del informe.
- Vista de lectura del borrador con los datos capturados.
- Edición del borrador por su creador o por perfiles con derecho de actualización.
- Clasificación de daño/desgaste y registro del valor en libros con usuario y fecha.
- Conservación de tablas al desinstalar.

Pendiente:
- Snapshot ampliado de componentes y relaciones de hardware por tipo de activo.
- Integración del tipo de activo industrial personalizado.
- Gestión de evidencias fotográficas y sus checksums.
- Firmas, aprobación, bloqueo y generación del PDF institucional.
- Generación del PDF respetando exactamente la plantilla documental oficial.
- Pruebas de instalación y funcionamiento en una instancia GLPI 11.

## Requisito documental pendiente

Para reproducir el formato sin desviaciones, el desarrollo del PDF debe basarse en la plantilla institucional oficial en blanco (.docx), además de los dos ejemplos ya descritos. No se debe inventar ni rediseñar la plantilla.


## Versión 0.4.0 — Snapshot ampliado

El snapshot de los computadores ahora incluye las relaciones de hardware consultadas a través de las clases de relación nativas de GLPI. Cada componente guarda los datos de la relación y los campos escalares del dispositivo asociado. Los discos y sistemas operativos se capturan por separado. Esta información queda serializada en el informe al momento de crear el borrador; no se vuelve a consultar para representar informes históricos.

**Alcance pendiente:** validar la captura en una instancia GLPI 11.0.7 real con equipos que tengan componentes inventariados. La presencia de componentes depende de que GLPI Agent/inventario haya registrado esas relaciones. Los activos `industrial` y otros tipos personalizados todavía no están habilitados.


## Versión 0.5.1 — Ajustes de registro dinámico

Se corrige el registro de pestañas para usar el registro dinámico de tipos y se ajusta el acceso de retorno a la ficha del activo genérico.

## Versión 0.5.0 — Compatibilidad con todos los activos

La pestaña de informes deja de depender de una lista cerrada de cinco tipos. El registro de tipos resuelve los activos nativos de GLPI 11 y consulta `AssetDefinitionManager` para descubrir las clases concretas generadas por cada definición activa de Asset Definitions. La creación del informe valida contra ese registro común. Para los activos genéricos se conserva también la definición asociada y los campos personalizados que GLPI expone en el registro del activo.

La cobertura de componentes especializados continúa siendo específica de los activos que disponen de esas relaciones (por ahora, computadores). Para el resto se conserva el conjunto de campos y valores personalizados que GLPI proporciona. Debe validarse con definiciones reales y perfiles/entidades de la instancia de pruebas antes de producción.


## Integración con FML Telegram

InformeGLPI no administra tokens, Chat IDs ni conexiones directas con Telegram. Las notificaciones de firma técnica, aprobación, devolución y recordatorios se encolan mediante la API pública `GlpiPlugin\Telegram\NotificationService::enqueue()` del plugin central **FML Telegram** (`fmosquera01/telegram`).

Los destinos privados deben configurarse en FML Telegram y asociarse al usuario GLPI correspondiente. InformeGLPI identifica al destinatario por su ID de usuario; FML Telegram administra el bot, destinos, cola, reintentos e historial de entrega. Si FML Telegram no está instalado o el usuario no tiene un destino activo, el flujo de informes continúa normalmente.

La tarea de InformeGLPI únicamente prepara los resúmenes diarios para cada supervisor con informes pendientes (07:00 y 17:00, zona `America/Guayaquil` por defecto) y los entrega a la cola central. La tarea de envío de FML Telegram procesa la cola cada minuto.

Eventos publicados por InformeGLPI: `report.pending_signature`, `report.finalized`, `report.returned` y `report.pending_signature.reminder`. Se envían al destino privado FML Telegram asociado al usuario GLPI y, adicionalmente, a los destinos suscritos a cada evento.
