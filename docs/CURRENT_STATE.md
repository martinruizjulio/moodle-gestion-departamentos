# CURRENT_STATE — Moodle Gestión de Departamentos

Última consolidación: 2026-10-05.

Repositorio canónico del proyecto. Mantener aislamiento respecto a otros proyectos Moodle. Revisar `AGENTS.md`, este documento, HEAD/commits y código vigente antes de cada tarea. GitHub es la fuente de verdad.

## Cambios recientes

### Profesor HEE — gestión limitada a talleres asignados
- Se formaliza la figura funcional **Profesor HEE** sin crear ni modificar un rol global de Moodle.
- El profesor mantiene su matrícula y autenticación institucional actuales, incluso cuando Moodle lo tenga como **Profesor sin permiso de edición**.
- El permiso HEE se concede exclusivamente mediante la relación existente `local_ga_edition_teachers`: profesor ↔ edición concreta de taller.
- Un Profesor HEE puede gestionar solo sus talleres asignados mediante las pantallas protegidas de Gestión HEE: alumnado, asistencia, materiales y actividad obligatoria/reflexión correspondiente.
- No obtiene acceso global al panel HEE, no puede administrar otros talleres ni adquiere permiso general para editar el curso Moodle.
- `authorized_users.php` mantiene separados dos niveles: **Gestor HEE global** y **Profesor HEE por taller/edición**.
- Desde `Usuarios autorizados` se puede seleccionar el curso y asignar/quitar profesores matriculados a cada edición concreta.
- La asignación acepta el rol institucional de profesor sin edición porque la detección de candidatos incluye el rol Moodle `teacher`, además de `editingteacher` y `manager`.
- `my_workshops.php` pasa a presentarse explícitamente como **Profesor HEE · Mis talleres**, con talleres vigentes y finalizados.
- El bloque `block_gestion_hee` muestra **Profesor HEE** y acceso directo a sus talleres cuando el usuario tiene al menos una edición asignada.
- El bloque revalida la tabla canónica de asignaciones cuando la caché docente aún contiene un cero antiguo, de modo que una nueva asignación debe aparecer sin esperar los 5 minutos de TTL.
- No se crea una cuenta alternativa, no se cambia autenticación y no se otorgan capacidades Moodle generales de edición.

### Talleres Tipo A y Tipo B unificados por Ediciones de talleres
- La unidad organizativa canónica es **Edición de talleres** (`classes/local/workshop_series.php`).
- Cada edición crea una sección Moodle con título y fechas editables.
- Dentro de la sección se crea primero **Calendario y resumen de talleres** y después una subsección real por taller, numerada visualmente **Taller 01, Taller 02…**, cuando `mod_subsection` está disponible.
- Si `mod_subsection` no está disponible se conserva un modo de compatibilidad sin romper el curso.
- Manual y Excel terminan en los mismos objetos (`manager::save_workshop()` + `manager::save_workshop_edition()`), por lo que no existen dos clases de talleres según su origen.
- El calendario HTML se reconstruye desde los datos canónicos de los talleres: nombre, fecha, inicio/fin, horas, profesor, plazas y cierre de inscripción. No mantiene una segunda copia editable.
- Al modificar un taller vinculado a una edición se regenera la estructura/calendario.
- Una edición finalizada/oculta deja de mostrarse al alumnado junto con sus subsecciones; sigue accesible para gestión y archivo.
- La vista principal `workshops.php`, la vista rápida del `dashboard.php` y `archive.php` trabajan por Ediciones de talleres y conservan aparte los registros antiguos no vinculados para no perder datos.

### Flujo Tipo A
- La edición/sección/subsecciones siguen el modelo común anterior.
- Cada Taller Tipo A puede contener asistencia, apuntes y actividad obligatoria.
- En la importación Excel puede seleccionarse un **cuestionario Moodle modelo vacío**; se duplica, renombra, restringe al grupo y se coloca en la subsección correspondiente.
- Las preguntas nunca se fabrican desde Excel: se crean o importan usando las herramientas convencionales de Moodle.
- La edición manual conserva un cuestionario previamente asociado; editar fecha, profesor, plazas, etc. no lo transforma en tarea.
- El certificado Tipo A se genera cuando se cumplen los requisitos configurados de asistencia + actividad obligatoria.

### Flujo Tipo B interno — asistencia + Tarea Moodle de reflexión
- Talleres Tipo B usan la **misma arquitectura de Edición de talleres / sección / calendario / subsecciones / grupo / inscripción** que Tipo A.
- La diferencia académica es la actividad final: Tipo B no usa cuestionario ni nota numérica.
- `classes/local/typeb_reflection_activity.php` crea/configura una **Tarea Moodle** de reflexión para cada edición Tipo B.
- La tarea permite **texto en línea** y **un archivo adjunto opcional**.
- El enunciado solicita un breve párrafo describiendo cómo ha sido el taller y las impresiones sobre la actividad.
- La actividad se configura sin calificación numérica y con finalización por entrega.
- Regla Tipo B interno: **asistencia confirmada + reflexión entregada = Apto**.
- `classes/local/typeb_certificate_policy.php` aplica esa regla antes de permitir la generación de un certificado Tipo B nuevo.
- `generate_certificates.php` usa la política específica Tipo B; ya no debe generar certificados Tipo B solo por asistencia.
- `teacher_view.php` muestra para Tipo B la Tarea Moodle de reflexión, asistencia, estado de entrega, resultado Apto/Pendiente y estado de certificado; no presenta nota numérica.
- `workshop_view.php` dirige al alumno a la Tarea Moodle de reflexión y muestra el estado de entrega y Apto/Pendiente.
- La creación manual (`edition_edit.php`) y la importación Excel (`bulk_workshops.php`) aseguran la misma tarea Moodle de reflexión en Tipo B.

### Reconocimiento externo Tipo B — certificado + validación + reflexión
- El alumno puede solicitar reconocimiento de una formación externa desde `typeb_upload.php`, indicando actividad, fecha, horas, descripción y adjuntando certificado PDF/JPG/PNG.
- Estado inicial: `pending`.
- Cuando el gestor valida el certificado, si todavía falta la reflexión el registro pasa a `validated_pending_reflection`.
- El alumno ve entonces un formulario de reflexión. Al guardarla, el estado pasa a `validated`.
- **Solo `validated` + reflexión no vacía computa horas Tipo B** (`portfolio_typeb::total_validated_hours()`).
- `portfolio_typeb::ensure_table()` añade defensivamente `reflectiontext` y `reflectiontime` en instalaciones existentes.
- `portfolio_admin.php` distingue: pendiente de validar / validado esperando reflexión / validado y completado / rechazado.
- El certificado externo aportado por el alumno sigue siendo la evidencia original. No se genera por defecto un segundo certificado interno para este reconocimiento externo.
- El PDF/expediente oficial debe considerar únicamente reconocimientos externos plenamente completados; pendientes de validación, rechazados o todavía pendientes de reflexión no deben aportar horas oficiales.

### Creación manual ↔ Excel ↔ calendario
- El formulario manual sigue la misma secuencia conceptual que el Excel: edición, código/nombre, tipo, descripción, fecha, inicio, fin, horas, plazas, cierre de inscripción, profesor y contenido Moodle.
- Inicio y fin se almacenan realmente; el calendario puede mostrar rangos como `12:30–14:30`.
- Fin debe ser posterior a inicio; cierre de inscripción anterior al inicio; el horario completo debe quedar dentro de la Edición de talleres.
- Una importación adicional continúa la numeración de la edición existente en lugar de reiniciar en Taller 01.
- Toda la plantilla se previsualiza/valida antes de crear la edición para evitar ediciones vacías por una fila errónea.
- La plantilla XLSX incluye ejemplo Tipo A y Tipo B. En Tipo B se ignora cualquier solicitud de cuestionario y se crea automáticamente la Tarea Moodle de reflexión.
- Opcionalmente puede duplicarse un recurso/carpeta modelo de apuntes.

### Panel de administración y vistas activa/archivo
- `dashboard.php` describe ya Tipo A y Tipo B según el modelo unificado.
- Tarjeta **Talleres Tipo A**: edición/sección, calendario, subsecciones, inscripción, asistencia, apuntes, cuestionario y certificados.
- Tarjeta **Talleres Tipo B**: edición/sección, calendario, subsecciones, inscripción, asistencia, Tarea Moodle de reflexión y certificado.
- Tarjeta **Validación externa Tipo B** explica que la validación habilita la reflexión y que las horas solo computan después de completarla.
- La vista general agrupa ediciones activas y sus talleres; no mezcla una lista plana de ediciones individuales.
- `archive.php` agrupa ediciones finalizadas/ocultas de más reciente a más antigua y mantiene aparte los registros antiguos sin Edición de talleres.
- Los talleres antiguos vinculados a una edición archivada no reaparecen como “sin edición” en la vista actual, ni los activos en el archivo legado.

### Gestión de Talleres Tipo A reconstruida por Ediciones de talleres
- `workshops.php` presenta cada Edición de talleres como bloque con curso, rango de fechas y estado.
- Dentro aparecen conjuntamente talleres creados manualmente e importados desde Excel.
- Cada fila muestra orden visible, nombre, fecha, horario, horas, plazas, inscritos, estado y acciones.
- Desde la edición: **Editar edición**, **Añadir taller manual**, **Abrir en el curso**.
- Desde el taller: **Editar taller**, **Alumnos / asistencia**.
- Los talleres antiguos/no vinculados se conservan en **Talleres sin Edición de talleres**.

### Listado personalizado de talleres
- `workshop_report.php` permite seleccionar una, varias o todas las ediciones.
- Resultado por alumno: **Taller | Edición | Alumno | Horas | Nota de tarea | Asistencia**.
- Tipo B mantiene nota de tarea `-` porque su reflexión no tiene calificación numérica.
- CSV separado por punto y coma y compatible con Excel.

### Reconocimiento institucional, traspasos y expedientes
- Reconocimiento institucional Tipo A/B desde Excel se conserva separado del flujo de creación de talleres.
- Traspasos A→B continúan disponibles y conservan su texto obligatorio.
- El portafolio/expediente combina certificados internos, reconocimientos válidos e historial de horas conforme a la lógica existente.

### Excepcionalidad / PAS sin cuenta UCVNet
- No se mantiene ningún acceso paralelo que eluda la autenticación institucional de Moodle.
- El prototipo de login propio fue retirado antes de consolidarlo.
- Próxima solución, si se retoma: integración autorizada y limitada/API específica con permisos mínimos HEE, sin exponer el resto del Campus Virtual.

## Versión actual
- `local_gestion_actividades`: **1.5.93-alpha** (`2026100507`).
- `block_gestion_hee`: **1.0.17-alpha** (`2026100501`).

## Verificación realizada
- Revisados `AGENTS.md`, `CURRENT_STATE.md`, HEAD y commits recientes antes del cambio Profesor HEE.
- El permiso Profesor HEE reutiliza la relación de profesores por edición ya existente; no añade tablas ni migraciones.
- Las protecciones existentes `can_manage_workshop_instance()` y `can_manage_edition()` ya aceptan a profesores expresamente asignados y siguen protegiendo las URLs directas.
- `material_edit.php` también utiliza `can_manage_workshop_instance()`, por lo que el Profesor HEE puede gestionar materiales de sus propios talleres desde Gestión HEE sin permiso general de edición del curso.
- `my_workshops.php` solo lista ediciones asignadas al usuario y vuelve a verificar `can_manage_edition()` en cada fila.
- No se ha modificado el rol Moodle institucional del profesor ni las capacidades globales de la asignatura.
- No se han eliminado talleres/ediciones existentes ni ejecutado migraciones destructivas.
- Las nuevas columnas de reflexión externa se crean de forma defensiva al entrar en el flujo de `portfolio_typeb`.
- La generación normal de certificados Tipo B pasa por `typeb_certificate_policy`, que comprueba asistencia + reflexión.
- Las vistas docente y alumno Tipo B ya no presentan el antiguo textarea interno como mecanismo canónico: la entrega se realiza mediante la Tarea Moodle estándar.
- El panel principal ha sido revisado para que sus textos y accesos representen el mismo modelo A/B.
- No se ha realizado todavía una prueba funcional en el servidor Moodle UCV ni una instalación/actualización real del plugin. No declarar producción validada.

## Pendientes antes del paquete final
- Probar en el Moodle UCV real un usuario matriculado como **Profesor sin permiso de edición**: asignarlo como Profesor HEE, confirmar que el bloque cambia a Profesor HEE y que solo ve/gestiona sus talleres.
- Probar que un Profesor HEE puede pasar asistencia, gestionar materiales y revisar la actividad/reflexión mediante las pantallas HEE, y que sigue sin poder editar el resto del curso.
- Probar en Moodle 5 real la creación de `mod_subsection`, el movimiento de actividades a la subsección y el orden Calendario → Taller 01 → Taller 02…
- Probar la Tarea Moodle Tipo B: texto en línea, archivo opcional, finalización por entrega, restricción al grupo y visibilidad dentro de la subsección.
- Probar que el certificado Tipo B solo se genera con asistencia + reflexión y revisar visualmente su plantilla PDF.
- Probar el reconocimiento externo completo: subir certificado → validar → reflexión → cómputo de horas/portafolio.
- Auditar por última vez `manager_downloads.php` para sustituir cualquier columna heredada “Texto alumno” de Tipo B interno por el estado de la Tarea Moodle y revisar que ZIP/CSV solo incluyan reconocimientos externos plenamente completados cuando corresponda.
- Ajustar visualmente el calendario generado hasta igualar la referencia facilitada por Julio.
- Revisar la política de códigos internos para ediciones sucesivas; la numeración visible depende de `sortorder`, por lo que puede mantenerse Taller 01–10 con códigos internos únicos.
- Confirmar si Apuntes se estandariza como `folder` o `resource` PDF.
- Hacer auditoría final de navegación, permisos, listados/exportaciones y versiones antes de generar el paquete instalable.
