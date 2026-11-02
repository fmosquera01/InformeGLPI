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
