# CURRENT_STATE — Moodle Gestión de Departamentos

Última consolidación: 2026-10-05.

Repositorio canónico del proyecto. Mantener aislamiento respecto a otros proyectos Moodle. Revisar HEAD/commits y código vigente antes de cada tarea. Registrar aquí cambios relevantes, validación y pendientes.

## Cambios recientes

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

### Creación masiva de talleres desde Excel
- Se mantiene intacta la creación manual de talleres y se añade una segunda vía **Crear talleres desde Excel** en `workshops.php`.
- Añadido `gestion_actividades/workshop_bulk_import.php` con flujo de cuatro pasos: descargar plantilla XLSX, subir archivo, previsualizar/validar y confirmar creación.
- Añadida la clase `classes/local/bulk_workshops.php`.
- La plantilla contiene: código, nombre, tipo A/B, descripción, fecha, inicio, fin, horas, plazas, cierre de inscripción, email del profesor, creación de apuntes, creación de cuestionario, cierre de cuestionario y código de edición.
- Antes de crear se validan códigos duplicados, talleres ya existentes, fechas, plazas, horas y profesor asociado.
- La creación masiva reutiliza `manager::save_workshop()` y `manager::save_workshop_edition()`, por lo que el taller resultante usa la misma estructura que uno manual y queda editable posteriormente.
- Grupo, inscripción/asignación del grupo, asistencia y publicación visual siguen pasando por la lógica existente del `manager`.
- Para Talleres Tipo A con cuestionario, el gestor selecciona un **cuestionario Moodle modelo vacío** del mismo curso. La importación duplica ese módulo con la API estándar de Moodle, cambia el nombre a `Cuestionario <código>`, aplica la fecha de cierre indicada y restringe el acceso al grupo de la edición.
- Si la lógica existente acaba de crear una tarea obligatoria para esa nueva edición, la importación elimina únicamente esa tarea recién creada antes de asociar el cuestionario duplicado; no toca tareas o cuestionarios preexistentes.
- Las preguntas del cuestionario no se generan desde Excel: se crean o importan posteriormente por las vías convencionales de Moodle.
- Opcionalmente se puede seleccionar un recurso/carpeta Moodle modelo para **Apuntes**; se duplica y se restringe al grupo del taller.
- La plantilla XLSX se genera desde la propia pantalla mediante PhpSpreadsheet. La lectura de `.xlsx` también usa PhpSpreadsheet.
- Versión del plugin elevada a `1.5.87-alpha` (`2026100501`).

### Excepcionalidad / acceso de personal PAS sin cuenta UCVNet
- Se analizó la necesidad de permitir acceso operativo a personal PAS que no puede autenticarse en UCVNet mediante el SSO Microsoft institucional.
- No se mantiene ningún acceso paralelo dentro del plugin que permita leer o modificar datos HEE sin pasar por un mecanismo de autenticación/integración aprobado para Moodle.
- Se llegó a prototipar localmente un acceso propio con usuario/contraseña, pero se retiró antes de enlazarlo o consolidarlo porque suponía una vía de autenticación paralela al control institucional.
- Criterio para la siguiente implementación: resolver **Excepcionalidad** mediante una integración autorizada y limitada, preferentemente un servicio/API específico con permisos HEE mínimos, manteniendo al PAS fuera del resto del Campus Virtual.
- No se ha alterado el login Microsoft/UCV ni se han creado cuentas Moodle locales ocultas.

## Verificación realizada
- Revisados `AGENTS.md`, `CURRENT_STATE.md`, HEAD y commits recientes antes de modificar.
- Confirmado que `local_ga_typeb_certs`, `portfolio_typeb::create_upload()`, `portfolio_typeb::set_status()` y el cómputo de horas validadas ya existían en el código, por lo que no se ha creado una segunda estructura paralela.
- Confirmado que las horas de certificados Tipo B externos solo se suman cuando `status = validated`.
- Se detectó y corrigió que el ZIP individual del expediente incluía anteriormente certificados externos Tipo B de cualquier estado; ahora usa exclusivamente certificados validados.
- El portafolio PDF filtra igualmente los certificados externos a `validated` antes de mostrarlos.
- Se detectó una actualización concurrente de `portfolio_package_download.php`; se releyó HEAD y el cambio se aplicó sobre la versión más reciente, evitando sobrescribir trabajo ajeno.
- El prototipo de acceso excepcional paralelo fue eliminado del repositorio antes de quedar integrado; no queda endpoint externo activo de ese tipo.
- Para la importación masiva se ha reutilizado la lógica existente de talleres/ediciones y el soporte Moodle de duplicación de módulos; no se ha creado una segunda estructura de talleres.
- El cuestionario automático se basa en un cuestionario modelo Moodle y mantiene la edición posterior de preguntas por Moodle.
- No se ha realizado todavía una prueba funcional en un servidor Moodle real ni una instalación/actualización del plugin. No declarar producción validada.

## Pendientes para siguientes cambios
- Seguir acumulando los cambios solicitados por Julio antes de preparar una descarga o paquete final.
- Probar en el Moodle real que `duplicate_module()` devuelve el CMID esperado y que las restricciones de grupo quedan visibles exactamente como en el curso actual.
- Confirmar si el recurso de Apuntes se estandarizará como `folder` o como `resource` PDF para simplificar aún más la plantilla.
- Diseñar la función **Excepcionalidad** sobre una vía de integración aprobada/limitada que no eluda el SSO institucional.
- Hacer auditoría final de navegación, permisos, listados/exportaciones, paquetes masivos y consistencia de versiones antes de generar el paquete instalable.
