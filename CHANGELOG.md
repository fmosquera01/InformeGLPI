## 0.5.68
- Inicializa explícitamente AssetDefinitionManager con bootDefinitions() antes de enumerar los activos personalizados, para que la pestaña Informes técnicos se registre también en las clases dinámicas de Asset Definitions.
- Almacena en caché la lista de tipos de activos por solicitud para evitar reinicializar las definiciones en cada comprobación.
- Conserva sin cambios el registro de activos nativos y la lógica de informes existentes.

## 0.5.67
- Mejora la calidad de las fotografías en el PDF institucional: aumenta la resolución máxima de las copias temporales de 1000 a 2400 píxeles, eleva la calidad JPEG de 78 a 92 y configura TCPDF a 300 DPI para las evidencias. Los archivos originales almacenados no se modifican.

