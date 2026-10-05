# CURRENT_STATE — Moodle Gestión de Departamentos

Última consolidación: 2026-10-05 (revisión independiente de Claude tras la auditoría de ChatGPT).

Repositorio canónico: `martinruizjulio/moodle-gestion-departamentos`. GitHub es la fuente de verdad. Mantener este proyecto aislado de otros Moodle del usuario.

## Arquitectura actual de talleres

### Ediciones de talleres
- La unidad organizativa principal es **Edición de talleres** (`classes/local/workshop_series.php`).
- Una edición tiene título y rango de fechas editables y crea una sección Moodle.
- Dentro se genera **Calendario y resumen de talleres** y, cuando `mod_subsection` está disponible, una subsección real por taller: Taller 01, Taller 02…
- Manual y Excel terminan en los mismos objetos y siguen el mismo orden de datos.
- El calendario se reconstruye desde los datos canónicos del taller: nombre, fecha, inicio/fin, horas, profesorado, plazas y cierre de inscripción.
- La resolución de la edición usada por el calendario se hace contra el rango de fechas de la Edición de talleres para no mezclar ediciones antiguas/nuevas de un mismo taller base.
- Una edición finalizada/oculta deja de mostrarse al alumnado pero conserva sus datos para gestión y archivo.

### Tipo A
- Misma arquitectura de Edición → sección → calendario → subsecciones → grupo/inscripción.
- Puede contener asistencia, apuntes y actividad obligatoria.
- El Excel puede duplicar un cuestionario Moodle modelo; las preguntas se crean/importan por las herramientas convencionales de Moodle.
- El certificado se genera al cumplir los requisitos configurados.

### Tipo B interno
- Misma arquitectura que Tipo A.
- Actividad final: **Tarea Moodle de reflexión**, sin nota numérica, con texto en línea y un archivo opcional.
- Regla: **asistencia confirmada + reflexión entregada = Apto**.
- La Tarea Moodle es la fuente de verdad.
- Los eventos `submission_created` y `submission_updated` sincronizan el estado HEE y recalculan el expediente del alumno.
- La tabla heredada `local_ga_typeb_reflections` se mantiene únicamente como compatibilidad para cálculos antiguos y se alimenta desde la Tarea Moodle real, incluyendo entregas solo con archivo.
- `typeb_certificate_policy` impide generar o regenerar un certificado Tipo B si falta asistencia o reflexión.

### Tipo B externo
- Alumno sube certificado externo → `pending`.
- Gestor valida → `validated_pending_reflection` si todavía falta reflexión.
- Alumno completa reflexión → `validated`.
- Solo `validated` + reflexión no vacía computa horas y entra en el expediente/ZIP oficial.
- El certificado externo sigue siendo la evidencia original; no se genera otro certificado interno por defecto.

## Profesor HEE
- Figura funcional propia de Gestión HEE, sin cambiar el rol institucional de Moodle.
- Un usuario puede seguir figurando como **Profesor sin permiso de edición** en Moodle.
- La asignación se guarda en `local_ga_edition_teachers` por edición concreta.
- Profesor HEE puede gestionar alumnado, asistencia, materiales y seguimiento de la actividad/reflexión únicamente de sus ediciones asignadas.
- No obtiene acceso global al panel HEE ni edición general del curso Moodle.
- `my_workshops.php` y `block_gestion_hee` muestran sus talleres vigentes/finalizados.
- La administración estructural de todas las ediciones (`editions.php`) queda reservada a gestores HEE globales; Profesor HEE entra por **Mis talleres**.
- Los materiales heredados sin edición son estructura compartida del taller base y solo pueden modificarlos gestores HEE globales.

## Auditoría extremo a extremo — consolidada

Se revisaron permisos, mutaciones, creación manual/Excel, secciones/subsecciones, grupos, inscripción, asistencia, materiales, Tipo A, Tipo B, certificados, portafolio, descargas, archivado, esquema DB, upgrade, privacidad y navegación de Profesor HEE.

### Seguridad y alcance corregidos
- `finish_workshop.php`, `generate_certificates.php`, `certificates.php`, `regenerate_certificate.php`, `internal_task.php`, `task_activity.php`, `repair_required_activity.php` y operaciones de asistencia/materiales comprueban la **edición concreta** cuando existe.
- `edition_students.php` valida que la inscripción modificada pertenece a la edición abierta.
- La matriculación manual rechaza usuarios que no sean estudiantes matriculados en el curso.
- Las acciones mutantes revisadas exigen `sesskey`/CSRF donde corresponde.
- Profesor HEE no recibe por el plugin `moodle/course:manageactivities` ni un rol Moodle de profesor con edición.
- `edition_edit.php` y la administración estructural permanecen reservadas a Gestor HEE global.
- `certificate_download.php`: si un certificado antiguo no tiene `editionid`, solo puede descargarlo su propietario o un Gestor HEE global; no se infiere permiso desde cualquier edición del taller base.
- `material_edit.php`: Profesor HEE solo puede modificar materiales de una edición asignada; materiales legacy sin edición quedan reservados a Gestor HEE global.
- `pluginfile` valida propietario/edición para certificados, tareas, entregas y materiales antes de servir archivos.

### Base de datos e instalación
- `db/install.xml` representa el modelo actual para instalación limpia: asistencia, certificados, Tipo B externo, reflexión, reconocimiento institucional y tablas `local_ga_workshop_series` / `local_ga_series_items`.
- `db/upgrade.php` contiene migración formal hasta `2026100510` y crea/normaliza las nuevas tablas/campos mediante XMLDB idempotente.
- La versión del plugin puede avanzar por encima del último savepoint cuando no hay cambio de esquema; no se necesita un savepoint vacío por cada corrección de PHP.

### Listados, descargas y expediente
- `manager_downloads.php` usa la **Tarea Moodle real** para informar del estado de reflexión Tipo B interno (`typeb_reflection_activity::submission_summary`).
- El CSV Tipo B interno muestra Asistencia + Reflexión entregada/Pendiente y texto en línea cuando existe; una entrega solo con archivo sigue siendo válida.
- Los ZIP Tipo B externos y expedientes completos filtran con `portfolio_typeb::is_countable()`: solo `validated` + reflexión.
- Listados y CSV de talleres se agrupan/identifican por **Edición de talleres** y se ordenan con la edición/fecha más reciente primero.
- Dentro de una Edición de talleres se conserva el orden Taller 01…N.
- Portafolio PDF, paquete individual y ZIP masivos presentan certificados del más reciente al más antiguo.
- La tabla heredada de ediciones muestra correctamente horas, plazas e inscritos en sus columnas correspondientes.

### Privacidad
- `classes/privacy/provider.php` declara las tablas personales actuales: alumnado, notas, profesorado asignado, asistencia, certificados, Tipo B, traspasos, tareas, reconocimiento institucional y configuración con usuario modificador.
- No se implementa borrado GDPR automático de información académica hasta que exista una política institucional de conservación; eliminar esos registros sería una decisión funcional/destructiva, no una simple corrección técnica.

### Comprobaciones estáticas realizadas
- Se ejecutó una pasada automática de `php -l` sobre **todos los PHP de `gestion_actividades` y `gestion_hee`** sin errores de sintaxis.
- `db/install.xml` y el resto de XML del repositorio superaron comprobación de XML bien formado.
- Se comprobó la presencia de la estructura mínima de ambos plugins.
- La comprobación temporal no forma parte del árbol final del repositorio.

## Revisión independiente (Claude) — 2026-10-05

Se revisó el HEAD `df60619` contra el código, sin dar por buena la auditoría anterior. Correcciones aplicadas (un commit por tema):

- **Observer Tipo B (Alta)**: `mod_assign` deja `relateduserid` vacío cuando el alumno entrega su propia tarea, y los eventos `submission_created/updated` se disparan antes de guardar el estado `submitted`. El observer no sincronizaba nada en el caso normal. Ahora resuelve el propietario desde `assign_submission` y escucha también `\mod_assign\event\assessable_submitted`.
- **Elegibilidad Tipo B (Alta)**: `manager::user_is_certificate_eligible()` seguía aceptando solo asistencia para Tipo B. Ahora delega en `typeb_certificate_policy`, igual que `certificate_missing_requirements()`.
- **Alcance por edición (Alta)**: `user_can_access_workshop_resources()` daba acceso de staff por cualquier edición del taller base. Ahora usa `can_manage_edition()`. `pluginfile` exige contexto de curso y comprueba que el registro pertenece a ese curso. Los materiales ocultos no se sirven al alumnado. Los materiales compartidos (`editionid = 0`), que antes daban 404 al alumnado, se sirven a quien tenga acceso a alguna edición del taller.
- **Cuentas Moodle (Alta)**: crear o actualizar usuarios desde CSV (y «crear usuarios que falten» en la importación de notas) exige `moodle/user:create` / `moodle/user:update`. Los administradores del sitio nunca se modifican. No se cambia el método de autenticación.
- **CSRF (Media)**: `enrol.php` inscribía con un GET sin sesskey; ahora pide confirmación si no hay sesskey. `repair_sections.php` y `repair_course_visuals.php` exigen sesskey.
- **Regenerar certificado (Media)**: se comprueba la elegibilidad antes de borrar y se elimina también el PDF antiguo, que antes quedaba huérfano.
- **Actividad obligatoria (Media)**: vincular o desvincular la actividad requerida queda reservado a Gestor HEE o a quien tenga `moodle/course:manageactivities`, igual que crearla.
- **Upgrade (Alta potencial)**: el índice único de `local_ga_institutional_hours.userid` no se crea si hay usuarios duplicados. Antes rompía el upgrade; ahora deja aviso y no borra datos.
- **Menores**: SQL con placeholder repetido en `workshop_series::series_for_edition()`; strings en inglés que faltaban; comparación int/string en `myhours.php`; no se reenvían correos de certificados ya emitidos; `block_gestion_hee` declara `null_provider` de privacidad.

Validación: `php -l` sobre los 101 PHP y parseo de todos los XML, sin errores. **No se ha ejecutado en Moodle.**

### Riesgos abiertos detectados (no corregidos, requieren decisión o prueba real)
- **Tipo A con cuestionario**: `user_is_certificate_eligible()` exige siempre una entrega en la tarea interna HEE con nota ≥ 5. Una edición Tipo A cuya actividad obligatoria sea solo un cuestionario Moodle nunca genera certificado. Hay que definir la regla (finalizado o nota mínima) antes de cambiarla.
- **Usuarios `auth=manual` con contraseña conocida**: quien tenga `moodle/user:create` puede seguir creando cuentas paralelas a la autenticación institucional. Decidir si deben crearse con la autenticación institucional o eliminar esa opción.
- **Asociación edición↔serie por fechas**: se resuelve por el rango `datefrom/dateto`. Si se editan fechas o se solapan series, una edición puede cambiar de serie. Las fechas se interpretan con `strtotime` en la zona horaria del servidor, no la del usuario.
- **`workshop_view.php`** usa la edición «primaria» del taller base para autoinscribir; con talleres reutilizados en varias ediciones debe comprobarse en real.
- **DDL en tiempo de ejecución** (`ensure_table`/`ensure_schema` en clases): un revisor de plugins lo marcará. Ya está en `upgrade.php`, así que puede retirarse de las páginas en una versión posterior.
- **Privacidad**: el plugin local solo declara metadatos (sin exportación ni borrado). Pendiente de política institucional.
- **Tabla heredada `local_ga_typeb_reflections`**: si el alumno elimina su entrega, la fila de compatibilidad no se borra (decisión conservadora: no borrar datos). Los cálculos canónicos usan la Tarea Moodle.
- **Moodle 5 / Bootstrap 5**: se usan clases BS4 (`badge-success`, `float-right`, `btn-block`, `mr-*`, `form-inline`). Funcionan con la capa de compatibilidad de Moodle 5.0, pero conviene migrarlas.
- `export.php` y `gradehistory.php` usan la capacidad de sistema `local/gestion_actividades:view` en lugar de `can_manage_globally()`.
- `typeb_upload.php` usa `$_FILES` directamente, sin validar el tipo de archivo (un revisor de Moodle pediría un filepicker).
- Los observers del bloque escuchan `\core\event\file_created/file_deleted`, que no existen en core: no se disparan nunca.

## Estado funcional actual

El código queda cerrado a nivel estático para los flujos diseñados:

1. Crear Edición de talleres manualmente o por Excel.
2. Crear sección + calendario + subsecciones y mover las actividades correspondientes.
3. Crear/asociar grupos e inscripción.
4. Tipo A: asistencia + actividad obligatoria + certificado.
5. Tipo B interno: asistencia + Tarea Moodle de reflexión + Apto + certificado.
6. Tipo B externo: certificado → validación → reflexión → horas reconocidas.
7. Profesor HEE: gestión limitada a sus ediciones sin permiso general de edición Moodle.
8. Finalizar/ocultar edición y conservarla en archivo.
9. Listados/CSV/ZIP/portafolio con criterios coherentes de edición, fecha y estado.

## Pendiente antes de declarar producción final

Quedan únicamente comprobaciones que no pueden certificarse desde el repositorio:

- **Prueba funcional en Moodle 5 real/UCV**: instalación limpia y upgrade sobre una instalación existente.
- Verificar visualmente `mod_subsection`, movimiento de actividades y orden dentro de una Edición de talleres.
- Probar con usuarios reales los grupos/restricciones, inscripción, Asistencia, Tarea Tipo B (texto y archivo), cuestionario Tipo A y generación/descarga de certificados.
- Probar Profesor HEE con un usuario institucional matriculado como “Profesor sin permiso de edición” y confirmar que solo gestiona sus ediciones.
- Probar el ciclo completo Tipo B externo y comprobar cómputo de horas/calificaciones tras cada cambio.
- Afinado visual final del calendario si la representación real del tema Moodle requiere pequeños ajustes de CSS/HTML.
- Definir política institucional de conservación si en el futuro se desea implementar exportación/borrado GDPR completo de datos académicos.

No declarar compatibilidad de producción únicamente por las comprobaciones estáticas anteriores.

## Versión actual
- `local_gestion_actividades`: **1.5.97-alpha** (`2026100512`). Último savepoint de esquema: `2026100510`.
- `block_gestion_hee`: **1.0.18-alpha** (`2026100502`).
