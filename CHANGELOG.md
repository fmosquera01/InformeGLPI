## 0.5.13

- Add institutional document header fields: annual report number display, fixed creation date, GLPI recipients, preparer, reviewer, and editable subject.
- Validate active GLPI users and require at least one recipient plus two distinct signatories before reserving the report sequence.
- Resolve inventory number, asset type, manufacturer, model, assigned user, location, and purchase date from the asset record for the form.
- Improve component display for processor, memory, and disk information.
- Add non-destructive schema migration for document header metadata.

## 0.5.12

- Reorganize the inline form to follow the institutional Word layout: fixed labels on the left, values/inputs on the right, and centered section titles.
- Reduce form density with compact controls and responsive single-column layout on narrow screens.
- Remove non-template manual fields (activities and additional observations) from the form; retain the Word sections and damage classification.

## 0.5.11

- Replace the create-then-redirect flow with a visible New form inside the asset tab; a report is created only when the technician saves.
- Add three internal views in the asset tab: New, Drafts, and Approved.
- Open draft editing inline from the Drafts list without navigating to a separate report page.
- Keep existing report records and snapshots; no schema changes or destructive migration.

## 0.5.10

- Fix GLPI asset tab deep links by using the complete tab identifier (`ReportTab::$1`) when redirecting after creation and save.
- Use GLPI 11 plugin URLs (`/plugins/informeglpi/...`) instead of the deprecated `Plugin::getWebDir()` helper.
- Keep standalone CSRF tokens on POST forms; GLPI 11.0 listener validates `_glpi_csrf_token` for non-AJAX POST requests.

## 0.5.9

- Fix namespaced GLPI `Html` calls in the asset tab.
- Remove references to the unavailable `$CFG_GLPI['root_doc']` global and build plugin endpoints using the plugin web directory.
- Keep report creation, editing and return navigation on the asset tab.

## 0.5.8

- Move report creation, data entry and report history into the asset's Informe Técnico tab.
- Replace the intermediate creation page with a POST action that returns to the same asset and opens the newly created report.
- Use independent GLPI CSRF tokens for each inline form to avoid token collisions between forms and other GLPI requests.
- Present the internal draft state as “En preparación” in the interface.

## 0.5.7

- Replace deprecated direct SQL calls in draft creation with GLPI's database query builder.
- Keep annual sequence increments atomic and compatible with GLPI 11.

## 0.5.6

- Load profile rights using a native GLPI Profile instance, avoiding queries against a nonexistent plugin-specific profiles table.
- Render the rights matrix from the loaded native profile data.

## 0.5.5

- Load the selected GLPI profile and its saved rights before rendering the plugin permission matrix, preventing saved selections from appearing unchecked.

## 0.5.4

- Submit the plugin profile-rights matrix to GLPI's native Profile form endpoint instead of resolving the plugin subclass form URL.

## 0.5.3

- Make native profile-right registration idempotent to prevent duplicate-key errors during installation and upgrades.
- Preserve existing profile permission values and add only missing rights.

## 0.5.2

- Fix rendering of the Informe Técnico tab in GLPI profiles.
- Allow users with native profile-administration rights to configure plugin rights.
- Register plugin rights during installation and upgrade so Super-Admin can manage profile permissions.

# Changelog

## 0.5.1 - Correcciones de integración
- Se conecta el registro dinámico de activos con el registro de pestañas GLPI.
- Se corrige la referencia de la clase base Asset y la navegación de retorno a la ficha de activos genéricos.


## 0.5.0 - Activos nativos y Asset Definitions
- Se incorpora un registro dinámico de activos nativos y clases generadas por Asset Definitions activas.
- La pestaña de historial y el servicio de creación usan el mismo registro, eliminando la allowlist cerrada de cinco tipos.
- Se capturan la definición asociada y los campos personalizados de los activos genéricos.
- Se amplía la lista de tipos nativos contemplados en GLPI 11.


## 0.4.0 - Snapshot detallado de componentes
- Se incorpora `AssetSnapshotBuilder` para separar la captura histórica de datos del servicio de informes.
- En computadores se capturan procesadores, memorias, discos, tarjetas de red y MAC, gráficos, sonido, controladoras, baterías y sistemas operativos relacionados.
- Se conserva el registro de campos generales del activo y se amplía el snapshot a la versión de esquema 2.
- Se utilizan las clases de relación y tablas proporcionadas por GLPI, evitando asumir nombres de tablas manualmente.


## 0.3.0 - Creación de borradores
- Se agrega un servicio para crear borradores de forma transaccional.
- Se incorpora numeración anual correlativa con formato ITSISAAAA-NNN.
- Se captura un snapshot JSON de los campos escalares disponibles del activo.
- Se registra la creación en la tabla de auditoría.
- Se habilita el botón Nuevo informe desde la pestaña del activo.
- Se incorpora la vista de lectura del informe y su snapshot.
- Se agrega edición de borradores para el creador o perfiles con derecho de actualización.
- Se agregan campos de clasificación de daño, valor en libros, diagnóstico, antecedentes, causa, actividades y recomendaciones.
- Se registra auditoría de los cambios realizados.

## 0.2.0 - Base de datos y permisos GLPI
- Se crea el esquema inicial para informes, correlativos anuales, firmas, evidencias y auditoría.
- Se registran derechos nativos de GLPI para consulta/edición y aprobación.
- Se agrega pestaña de perfiles y pestaña de historial en activos principales.
- Se agrega la entidad CommonDBTM para informes.
- La desinstalación deja intactos los registros documentales.

## 0.1.0 - Base inicial
- Estructura inicial del plugin.
- Metadatos y requisitos GLPI/PHP.
- Documentación de requerimientos funcionales.
