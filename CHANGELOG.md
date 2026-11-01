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
