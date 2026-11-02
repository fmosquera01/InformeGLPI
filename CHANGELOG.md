## 0.5.64
- Permite a técnicos con permiso CREATE guardar borradores incompletos; los campos obligatorios se validan al firmar.
- Valida antes de la firma los datos del informe y los datos actuales del activo GLPI: código, tipo, fabricante, modelo, serie, responsable, ubicación y fecha de compra.
- Mantiene los activos GLPI en solo lectura desde el plugin. El snapshot histórico del informe se actualiza desde la ficha actual del activo únicamente después de validar y justo antes de la firma técnica.
- Integra la firma técnica en el formulario de creación/edición y conserva la aprobación independiente del supervisor y el requisito de fotografía.
- Incorpora una bandeja personal del técnico para borradores, informes devueltos y seguimiento de informes enviados al supervisor; se conserva la bandeja de revisión del supervisor.

## 0.5.63
- Corrige el cálculo del espacio reservado para las firmas en PDF A4: contempla también el espacio de «Atentamente» y el margen entre fotografías y firmas, evitando rechazos aunque los bloques sí puedan caber en la página.

## 0.5.62
- Cambia el PDF institucional de tamaño Letter a A4 y calcula el ancho y límite inferior según las dimensiones reales de la página.
- Reduce moderadamente la altura de las imágenes de firma y el espacio reservado para ellas, liberando más área para ampliar las fotografías sin sacar las firmas de la página.

## 0.5.61
- Corrige el cálculo del espacio inferior del PDF Letter: restablece la reserva segura para los bloques de firma y evita que el validador rechace informes por el espacio adicional consumido al posicionar las firmas.

## 0.5.60
- Reduce la reserva inferior destinada a las firmas para ampliar aproximadamente 1 cm la altura disponible de las fotografías, manteniendo los bloques de firma dentro de la página Letter.

## 0.5.59
- Aprovecha el espacio inferior de la página Letter para ampliar la fotografía de evidencia y bajar ligeramente los bloques de firma, manteniendo el diseño en una sola página.

## 0.5.58
- Centra «Elaborador por» y «Supervisado por» sobre sus respectivos bloques de firma y cargo.
- Aumenta el espacio disponible para las fotografías y elimina las leyendas con nombres de archivo debajo de las imágenes.

## 0.5.57
- Sustituye la composición HTML del PDF por renderizado TCPDF con coordenadas para reproducir el formato institucional en papel Letter.
- Adapta destinatarios, activo, descripción, fecha de compra, valor en libros, componentes de Computer, información técnica, evidencias y firmas desde los datos reales del plugin.
- Conserva validación de firmas/fotografías, almacenamiento privado, SHA-256 y finalización sólo después de guardar correctamente el PDF; rechaza contenido que no quepa en una página.

## 0.5.56
- Ajusta el PDF al formato institucional de referencia: elimina bordes de cuadrícula, compacta filas, centra y resalta los títulos de sección y ordena el tipo de daño antes de las recomendaciones.

## 0.5.55
- Corrección crítica de sintaxis en ReportPdf.php: normaliza la referencia global a Dropdown para evitar error fatal al cargar el plugin.

## 0.5.54

- Reformat the generated PDF to follow the institutional technical-report template: formal two-line heading, report number, date/recipients/preparer/reviewer/subject, centered asset and technical sections, equipment photos, and the two signature blocks.
- Place purchase date and book value together inside **DESCRIPCIÓN DEL ACTIVO**, include asset description, and list processor, each RAM module, and each disk for Computer assets only.
- Map both registered GLPI assets and manually reported cellphones into the same PDF layout while preserving the one-page A4 limit, private storage, and SHA-256 verification.

## 0.5.53

- Add supervisor-only PDF generation after both report signatures.
- Store the one-page A4 PDF privately, verify its SHA-256 hash, and only then finalize the report.
- Resize and compress evidence images for PDF composition without modifying original evidence.
- Add permission-checked PDF streaming with integrity verification.

## 0.5.52

- Refine the plugin landing page with compact action cards, small leading icons, and clear button-style links for cellphone report creation, the supervisor inbox, and finalized reports.
- Reduce card spacing and improve responsive layout without changing permissions, counters, or destination pages.

## 0.5.51

- Fix incorrectly escaped namespace separators in the finalized reports page that caused a PHP syntax error during plugin loading.
- Correct the previous syntax repair by keeping PHP statements properly terminated in the supervisor inbox renderer.

## 0.5.50

- Fix a PHP syntax error in the supervisor inbox renderer that caused GLPI to return HTTP 500 while loading the plugin.

## 0.5.49

- Add return navigation from supervisor review to the assigned pending inbox and from finalized report detail to the finalized list.
- Present supervisor read-only report details in the same institutional document blocks and header layout as the creation form.
- Add an **Informes finalizados** dashboard option and entity-filtered finalized report list.

## 0.5.48

- Replace the two separate GLPI plugin submenu entries with one **Informe Técnico** landing page containing two visual actions: cellphone report creation and supervisor inbox.
- Show the supervisor's pending-review count on the inbox card, filtered by assigned reviewer and accessible entities; hide the count/action for users without review permission.
- Keep the existing cellphone form and inbox pages as the destinations of their respective dashboard actions.

## 0.5.47

- Render the supervisor inbox inside the standard GLPI page layout with the Plugins breadcrumb/header.
- Rename the review queue to **Bandeja de entrada** and remove the redundant cellphone-page link from the inbox; both destinations remain separate options under **Plugins > Informe Técnico**.

## 0.5.46

- Consolidate the manual cellphone report and supervisor review inbox under the single **Plugins > Informe Técnico** menu entry using GLPI 11 submenu options.
- Remove duplicate report-inbox includes and class registrations; retain the existing manual cellphone page as the default menu destination.

## 0.5.45

- Restrict report editing and evidence uploads to the assigned preparer while a report is in preparation or returned for correction; the assigned supervisor receives a read-only review view with only approve/sign or return actions. Enforce this on the server as well as the interface.
- Add a global GLPI Plugins > Informe Técnico > Bandeja de revisión entry listing only pending reports assigned to the logged-in supervisor and accessible entities.
- Keep reports in approved-pending-PDF after both signatures; finalization remains reserved for successful institutional PDF generation.

## 0.5.45

- Restrict report editing to the assigned preparer while a report is in preparation or returned for correction; the assigned supervisor always receives a read-only review view with only approve/sign or return actions.
- Add a global GLPI Plugins > Informe Técnico > Bandeja de revisión entry listing only pending reports assigned to the logged-in supervisor and accessible entities.
- Keep reports in approved-pending-PDF after both signatures; finalization remains reserved for successful institutional PDF generation.

## 0.5.44

- Fix workflow signature failure on existing installations by expanding history old_status/new_status columns to 40 characters, matching the report workflow states.
- Apply the same history column sizes to fresh installations and migrate existing tables safely.

## 0.5.43

- Add PIN-authorized technical and supervisor signature actions using each user's saved profile signature; preserve a private, hash-verified copy per report.
- Enforce assigned preparer/reviewer identity, supervisor validation right, entity access, PIN lockout, at least one evidence photo before technical signature, and audit history for each transition.
- Allow the assigned technician to edit a report returned by the supervisor; saving corrections returns it to preparation for a new technical signature.
- Add supervisor return-with-observations and an intermediate approved-pending-PDF state; reports are not marked final before PDF generation.
- Expand the report status column to 40 characters so all workflow states are stored without truncation.

## 0.5.42

- Display the authenticated user's previously saved personal signature in the GLPI user profile through a private, session-protected image endpoint.
- Verify the stored image path remains inside the user's private signature directory and validate its SHA-256 hash before serving it; do not expose filesystem paths.
- Keep signature registration, PIN handling, and existing report behavior unchanged.

## 0.5.41

- Add a personal-signature tab to the authenticated user's own GLPI user record; other users' records do not expose or permit editing that signature.
- Allow the user to register or replace a handwritten PNG signature and a six-digit personal PIN; replacing either requires the current PIN.
- Store signature images privately under GLPI_VAR_DIR, persist only a password hash for the PIN, and add temporary lockout after five failed PIN checks.
- Add a dedicated user-signature table through the existing non-destructive, idempotent plugin migration.

## 0.5.40

- Correct PHP namespace separators in the component catalog lookups introduced with the JOIN-free retrieval.

## 0.5.39

- Replace component JOIN queries with direct query-builder reads of association rows and individual component records, avoiding alias-dependent joins.
- Keep per-module RAM and disk labels, resolve memory/interface catalog names separately, and exclude USB/deleted/empty disks.

## 0.5.38

- Fix GLPI 11 query-builder JOIN foreign-key definitions by using table aliases and field names in the ON criteria for processor, memory, and hard-drive relations.
- Preserve the existing filters and component-label generation.

## 0.5.37

- Fix duplicated namespace separators that caused a PHP syntax error while loading the asset snapshot builder.
- Correct processor, memory, and hard-drive association filters to use the actual `$itemsId` parameter instead of an undefined variable.

## 0.5.36

- Replace prohibited direct SQL execution with GLPI 11's database query builder for processor, memory, and hard-drive relations.
- Preserve the existing component labels and filters while using iterable query results.

## 0.5.35

- Fix the PHP syntax error in the RAM manufacturer lookup that prevented GLPI from loading the plugin.
- Preserve the explicit SQL queries and component snapshot logic introduced in 0.5.34.

## 0.5.34

- Query computer processors, memory modules, and physical disks with explicit SQL joins matching the verified GLPI inventory query.
- Build one display label per RAM module and physical disk using association capacity/size and the related type/interface catalogs.
- Remove the previous component-record lookup through `getFromDB()` for computer hardware snapshots.

## 0.5.33

- Replace the unreliable GLPI association-object lookup with direct reads from the same processor, memory, and hard-drive relation tables used by the Components tab.
- Resolve each linked component through its catalog record, filter deleted associations when the column exists, and build one display line per RAM module and physical disk.
- Keep component details limited to Computer assets; retain purchase date, book value, and comments behavior.

## 0.5.32

- Fix component retrieval using GLPI's actual return contract: the native association method returns Item_Device relation objects.
- Read the component foreign key from the relation class metadata and filter returned objects by the requested relation class before loading the processor, memory, or hard-drive record.
- Keep technical component output limited to Computer assets.

## 0.5.31

- Read computer processors, RAM modules, and hard drives through GLPI's native `Item_Devices::getItemsAssociatedTo()` API, matching the association logic used by the Components tab.
- Keep technical component output restricted to assets whose itemtype is `Computer`; comments and purchase-date handling remain unchanged.

## 0.5.30

- Correct GLPI 11 component relation lookup for computers by querying the standard `itemtype`, `items_id`, and `is_deleted` columns.
- Resolve component classes and foreign-key fields through GLPI's `getDeviceType()` and `getForeignKeyField()` APIs instead of relying on relation metadata properties that were not resolving correctly.
- Preserve the purchase-date lookup from `glpi_infocoms.buy_date`, mapped to the report snapshot's `date_buy` field.

## 0.5.29

- Fix purchase-date retrieval for GLPI 11 by reading `buy_date` from `glpi_infocoms` and mapping it to the report snapshot field `date_buy`.
- Check that the table and column exist before querying, preventing the asset tab from crashing on installations with a different schema.

## 0.5.28

- Always display **FECHA DE COMPRA** directly above **VALOR EN LIBROS (USD)** inside **DESCRIPCIÓN DEL ACTIVO**, showing a dash when GLPI has no valid purchase date.
- Place the description block before the remaining registered-asset identification details in the creation and editing form.
- Apply the same purchase-date presentation in the report detail view.

## 0.5.27

- Place **VALOR EN LIBROS (USD)** inside the **DESCRIPCIÓN DEL ACTIVO** block for registered GLPI assets, alongside the GLPI purchase date and asset description.
- Keep book value editable and persist it on initial report creation and draft updates; preserve its audit user and timestamp.
- Remove the separate book-value row from the technical-information section for registered assets; leave the unregistered-cellphone form unchanged.
- Display description, purchase date and book value together in the report detail view.

## 0.5.26

- Configure **DESCRIPCIÓN DEL ACTIVO** as a required multiline text field in the manual cellphone form.
- Add the example placeholder “TELEFONO CELULAR A24” and a 255-character limit.
- Validate the description on both creation and draft editing.
- Preserve line breaks when displaying asset snapshot fields in the report.

## 0.5.25

- Add **Plugins → Informe Técnico** entry to create reports for cellular phones not registered in GLPI.
- Fix the asset type to **CELULAR**; collect asset code, description, purchase date, brand, model, IMEI/serial, responsible user and area manually.
- Keep the same annual report numbering, recipients, preparer/reviewer, technical fields, photographs and draft editing.
- Store manual cellphone data as an immutable-style report snapshot without creating or modifying a GLPI asset.
- Reject manual creation when the IMEI is already present in GLPI Phones, directing the technician to the registered asset record.

## 0.5.24

- Capture processor, RAM and storage hardware relations for GLPI Phone assets.
- Display these technical details for Phone types “Hand Held” and “Tablet”; continue omitting network-card entries.

## 0.5.23

- Present only relevant computer hardware details: processor, RAM, and storage; omit generic network-adapter entries.
- Format component descriptions using their technical designation and suppress placeholder asset codes such as “No Asset Information”.

## 0.5.22

- Show previously uploaded equipment photos in the report edit form; adding new photos no longer hides existing evidence.
- Improve the photo gallery, upload area, section headings, and form spacing for a cleaner, more compact layout.

## 0.5.21

- Correct the standalone edit page container closure after sharing the same renderer with the creation form.

## 0.5.20

- Remove the institutional report title from the interactive creation and edit forms; reserve it for the generated PDF.
- Reorder both forms to match the Word template: document number/date/recipients/preparer/reviewer/subject, asset information, technical information, and equipment photos.
- Use the same asset snapshot layout and shared form renderer for creation and editing, so both screens have identical structure and styling.
- Rename the technical section to “INFORMACIÓN TÉCNICA DEL ACTIVO” and show the stored asset snapshot while editing.

## 0.5.19

- Redesign the report form to more closely follow the institutional Word document, with a formal document heading, cleaner metadata rows, section bands, and refined spacing/typography.
- Highlight the active asset-tab view in yellow and match the Edit/View action button to the same accent.
- Apply the same visual system to both new and edit pages; fix the new-form document-number preview to use the report-number service directly.

## 0.5.18

- Open existing draft reports in a dedicated edit view so GLPI tab AJAX cannot drop the selected report ID and show a blank New form.
- Keep the user on the same edited report after saving.
- Improve tab styling, reduce form spacing, remove the explanatory alert, and move the document number to the top.

## 0.5.17

- Fix Computer asset snapshot when resolving the global GLPI `Item_Disk` class.
- Preserve the selected pending-review view after editing a report.
- Display a non-reserving preview of the next annual report number in the New form.

## 0.5.16

- Make the asset report tabs work with native CSS radio controls, without relying on JavaScript execution inside GLPI's AJAX-loaded tab content.

## 0.5.15

- Replace server-navigation report tabs with in-place tab panels so all three views work inside the GLPI asset tab.
- Add multi-image upload for JPG, PNG and WEBP, with mobile camera capture, local previews, MIME/size validation and private storage under GLPI_VAR_DIR.
- Add authenticated photo viewing and evidence audit entries; photos are associated with the report and displayed in its detail view.

## 0.5.14

- Bind the report preparer to the authenticated GLPI user; it can no longer be selected as another person's identity.
- Preserve the original preparer when an authorized user edits a draft.
- Rename the asset-tab lists to “Pendientes de revisión” and “Finalizados”.

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
