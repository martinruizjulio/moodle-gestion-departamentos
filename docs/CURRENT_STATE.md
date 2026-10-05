# CURRENT_STATE — Moodle Gestión de Departamentos

Última consolidación: 2026-10-05.

Repositorio canónico del proyecto. Mantener aislamiento respecto a otros proyectos Moodle. Revisar HEAD/commits y código vigente antes de cada tarea. Registrar aquí cambios relevantes, validación y pendientes.

## Cambios recientes

### Ediciones de talleres como sección + subsecciones Moodle
- Añadida la entidad funcional **Edición de talleres** mediante `classes/local/workshop_series.php` y la pantalla `workshop_series.php`.
- Cada edición tiene curso, título editable, fecha de inicio, fecha de fin y estado activa/finalizada.
- La edición crea una sección principal Moodle con el título indicado.
- En Moodle con `mod_subsection` disponible, la sección contiene una primera subsección **Calendario y resumen de talleres** y una subsección real por cada taller, numerada por el orden de la edición: Taller 01, Taller 02, etc.
- Si `mod_subsection` no está disponible, se mantiene un modo de compatibilidad en el que el calendario se muestra en el resumen HTML de la sección principal sin romper el curso.
- El calendario HTML se genera siempre a partir de los datos canónicos de talleres/ediciones: nombre, fecha, hora, horas, profesor, plazas y cierre de inscripción.
- Al guardar o publicar manualmente un taller asociado a una edición se reconstruyen el calendario y la estructura de la edición automáticamente.
- Las actividades propias del taller (asistencia, tarea/cuestionario asociado, certificado y apuntes cuando proceda) se mueven a la subsección de ese taller.
- Una edición marcada como **Finalizada** queda oculta para el alumnado junto con sus subsecciones. También se oculta si se supera su fecha final al regenerar la estructura. Puede reabrirse desde la gestión de ediciones.
- La creación manual puede iniciarse desde una edición concreta y recibe automáticamente el orden y código sugerido `TALLER-01`, `TALLER-02`, etc.; después sigue el mismo formulario de datos académicos/edición que la creación normal.
- `workshops.php` expone ahora tres accesos claros: **Ediciones de talleres**, **Crear taller manualmente** y **Crear edición desde Excel**.
- Versión elevada a `1.5.88-alpha` (`2026100502`).

### Creación masiva de una edición desde Excel
- `workshop_bulk_import.php` crea ahora una **edición completa**, no una colección suelta de talleres.
- Antes de subir el Excel se indican: curso, título de la edición, fecha inicial y fecha final.
- Cada fila válida del Excel se convierte en una subsección siguiendo exactamente el orden de las filas.
- Se valida además que la fecha de cada taller esté comprendida dentro de las fechas de la edición.
- La importación continúa reutilizando `manager::save_workshop()` y `manager::save_workshop_edition()`, de modo que el taller generado es el mismo tipo de objeto que uno creado manualmente y puede editarse después.
- Grupo, inscripción/asignación de grupo, asistencia y publicación continúan pasando por la lógica existente del `manager`.
- Para Talleres Tipo A con cuestionario, se selecciona un **cuestionario Moodle modelo vacío** del mismo curso. La importación lo duplica mediante Moodle, lo renombra, aplica la fecha de cierre y lo restringe al grupo del taller.
- Las preguntas del cuestionario no se generan desde Excel: se crean o importan después por las herramientas convencionales de Moodle.
- Opcionalmente puede duplicarse un recurso/carpeta Moodle modelo para **Apuntes**, restringido igualmente al grupo y colocado en la subsección del taller.
- La plantilla conserva las columnas de código, nombre, tipo A/B, descripción, fecha, inicio, fin, horas, plazas, cierre de inscripción, profesor, apuntes, cuestionario, cierre del cuestionario y código de edición.

### Validación de formación externa como Taller Tipo B
- Reactivado el flujo existente de certificados Tipo B externos sin migraciones destructivas ni cambios de esquema.
- El alumno dispone de `typeb_upload.php` con el nombre funcional **Solicitar validación como Taller Tipo B**.
- Puede indicar nombre de la formación, fecha, horas, descripción/justificación y adjuntar certificado (PDF/JPG/PNG, máximo 20 MB).
- La solicitud se guarda como `pending`; las horas solo computan como Tipo B cuando un gestor la cambia a `validated`.
- El alumno puede consultar sus propias solicitudes y ver estado `Pendiente`, `Validado` o `Rechazado`, además del comentario del gestor.
- El bloque `gestion_hee` muestra el acceso **Solicitar validación Tipo B**.
- El panel de gestión enlaza directamente a las solicitudes Tipo B pendientes mediante `portfolio_admin.php?status=pending`.
- Se conserva el flujo de revisión ya existente: ver/descargar certificado, validar o rechazar y añadir comentario.
- Criterio definitivo: solo las solicitudes externas Tipo B con `status = validated` forman parte del expediente oficial del alumno.
- El PDF del portafolio incluye una sección **Formaciones externas reconocidas como Tipo B** con actividad, fecha, horas, justificación, comentario de validación y nombre del archivo acreditativo.
- Las solicitudes `pending` o `rejected` no aparecen en el PDF oficial.
- `portfolio_package_download.php` incluye físicamente el certificado aportado por el alumno dentro de `02_Tipo_B_Externos_Validados/` únicamente cuando ha sido validado; pendientes y rechazados quedan fuera del ZIP del expediente.

### Listado personalizado de talleres
- Añadido `gestion_actividades/workshop_report.php`.
- El gestor puede seleccionar una edición, varias o todas las ediciones disponibles.
- El resultado presenta por alumno: **Taller | Edición | Alumno | Horas | Nota de tarea | Asistencia**.
- Para Talleres Tipo B, la nota de tarea se muestra como `-` porque no corresponde tarea calificable Tipo A.
- La asistencia se expresa como `Presente`, `Ausente` o `Sin registrar`.
- El resultado puede descargarse como CSV separado por punto y coma y compatible con Excel.
- El panel de gestión incluye acceso directo **Listado personalizado de talleres**.

### Excepcionalidad / acceso de personal PAS sin cuenta UCVNet
- Se analizó la necesidad de permitir acceso operativo a personal PAS que no puede autenticarse en UCVNet mediante el SSO Microsoft institucional.
- No se mantiene ningún acceso paralelo dentro del plugin que permita leer o modificar datos HEE sin pasar por un mecanismo de autenticación/integración aprobado para Moodle.
- Se llegó a prototipar localmente un acceso propio con usuario/contraseña, pero se retiró antes de enlazarlo o consolidarlo porque suponía una vía de autenticación paralela al control institucional.
- Criterio para la siguiente implementación: resolver **Excepcionalidad** mediante una integración autorizada y limitada, preferentemente un servicio/API específico con permisos HEE mínimos, manteniendo al PAS fuera del resto del Campus Virtual.
- No se ha alterado el login Microsoft/UCV ni se han creado cuentas Moodle locales ocultas.

## Verificación realizada
- Revisados `AGENTS.md`, `CURRENT_STATE.md`, HEAD y commits recientes antes de modificar.
- Se ha mantenido una única estructura de talleres: la creación manual y la importación Excel terminan usando `manager::save_workshop()` y `manager::save_workshop_edition()`.
- Las ediciones de talleres no cambian autenticación, roles ni permisos.
- La clase `workshop_series` crea su esquema de forma defensiva si aún no existe para no depender de una apertura previa concreta de otra pantalla.
- El uso de subsecciones se detecta en tiempo de ejecución y solo se activa si el módulo `subsection` y sus tablas están disponibles; si no, se usa el modo de compatibilidad.
- El calendario se regenera desde datos almacenados; no se mantiene una segunda copia editable de fechas/nombres/profesores.
- Confirmado que las horas de certificados Tipo B externos solo se suman cuando `status = validated`.
- El cuestionario automático se basa en un cuestionario modelo Moodle y mantiene la edición posterior de preguntas por Moodle.
- No se ha realizado todavía una prueba funcional en el servidor Moodle UCV ni una instalación/actualización real del plugin. No declarar producción validada.

## Pendientes para siguientes cambios
- Seguir acumulando los cambios solicitados por Julio antes de preparar una descarga o paquete final.
- Probar en Moodle 5 real la creación mediante `mod_subsection`, el movimiento de módulos a la sección delegada y el orden visual Calendario → Taller 01 → Taller 02…
- Comparar visualmente el calendario generado con el calendario HTML de referencia mostrado por Julio y ajustar tipografía, distribución, bordes y densidad hasta dejarlo equivalente.
- Incorporar explícitamente la hora de fin del taller al modelo persistente para que el calendario muestre rangos completos de horario y coincida al 100 % entre flujo manual y Excel.
- Probar en el Moodle real que `duplicate_module()` devuelve el CMID esperado y que las restricciones de grupo quedan visibles exactamente como en el curso actual.
- Confirmar si el recurso de Apuntes se estandarizará como `folder` o como `resource` PDF para simplificar aún más la plantilla.
- Diseñar la función **Excepcionalidad** sobre una vía de integración aprobada/limitada que no eluda el SSO institucional.
- Hacer auditoría final de navegación, permisos, listados/exportaciones, paquetes masivos y consistencia de versiones antes de generar el paquete instalable.
