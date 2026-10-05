# CURRENT_STATE — Moodle Gestión de Departamentos

Última consolidación: 2026-10-05.

Repositorio canónico: `martinruizjulio/moodle-gestion-departamentos`. GitHub es la fuente de verdad. Mantener este proyecto aislado de otros Moodle del usuario.

## Arquitectura actual de talleres

### Ediciones de talleres
- La unidad organizativa principal es **Edición de talleres** (`classes/local/workshop_series.php`).
- Una edición tiene título y rango de fechas editables y crea una sección Moodle.
- Dentro se genera **Calendario y resumen de talleres** y, cuando `mod_subsection` está disponible, una subsección real por taller: Taller 01, Taller 02…
- Manual y Excel terminan en los mismos objetos y siguen el mismo orden de datos.
- El calendario se reconstruye desde los datos canónicos del taller: nombre, fecha, inicio/fin, horas, profesorado, plazas y cierre de inscripción.
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
- `typeb_certificate_policy` impide generar o regenerar un certificado Tipo B si falta asistencia o reflexión.

### Tipo B externo
- Alumno sube certificado externo → `pending`.
- Gestor valida → `validated_pending_reflection` si todavía falta reflexión.
- Alumno completa reflexión → `validated`.
- Solo `validated` + reflexión no vacía computa horas.
- El certificado externo sigue siendo la evidencia original; no se genera otro certificado interno por defecto.

## Profesor HEE
- Figura funcional propia de Gestión HEE, sin cambiar el rol institucional de Moodle.
- Un usuario puede seguir figurando como **Profesor sin permiso de edición** en Moodle.
- La asignación se guarda en `local_ga_edition_teachers` por edición concreta.
- Profesor HEE puede gestionar alumnado, asistencia, materiales y seguimiento de la actividad/reflexión de sus talleres asignados.
- No obtiene acceso global al panel HEE ni edición general del curso Moodle.
- `my_workshops.php` y `block_gestion_hee` muestran sus talleres vigentes/finalizados.
- La administración estructural de todas las ediciones (`editions.php`) queda reservada a gestores HEE globales; Profesor HEE entra por **Mis talleres**.

## Auditoría extremo a extremo — 2026-10-05

Se revisaron permisos, mutaciones, creación/edición, asistencia, materiales, certificados, Tipo B, navegación, privacidad, descargas, esquema DB e instalación.

### Correcciones aplicadas
- **Finalizar/archivar edición:** `finish_workshop.php` comprueba ahora `can_manage_edition()` y no el permiso amplio del taller base.
- **Generación de certificados:** `generate_certificates.php` queda limitada a la edición concreta asignada al Profesor HEE.
- **Listado de certificados:** `certificates.php` queda limitado a la edición concreta.
- **Descarga de certificado:** para personal HEE se valida la edición del certificado; el alumno conserva acceso solo a su propio certificado.
- **Regeneración de certificados Tipo B:** vuelve a comprobar asistencia + reflexión antes de borrar/regenerar y queda limitada a la edición asignada.
- **Tarea interna:** `internal_task.php` comprueba la edición concreta.
- **Materiales:** `material_edit.php` valida que el material pertenece al taller solicitado, que la edición pertenece al taller y que el Profesor HEE está asignado a esa edición. Se bloquea el cambio de edición mediante parámetros manipulados.
- **Asistencia:** `edition_students.php` valida que el registro de inscripción que se modifica pertenece realmente a la edición abierta.
- **Matriculación manual:** se rechaza añadir mediante URL manipulada a un usuario que no sea estudiante matriculado en el curso.
- **Navegación Profesor HEE:** `my_workshops.php` deja de usar `$COURSE` en contexto de sistema y vuelve de forma segura a “Mis cursos”.
- **Reparación de restricciones:** `repair_required_activity.php` ahora exige `sesskey` y permiso sobre la edición concreta; se cerró un vector CSRF.
- **Gestión de actividad Moodle:** `task_activity.php` valida la edición concreta y separa el permiso Profesor HEE del permiso institucional `moodle/course:manageactivities`; Profesor HEE no adquiere por el plugin capacidad general para crear/reconfigurar actividades Moodle.
- **Administración estructural de ediciones:** `editions.php` queda reservada a gestores HEE globales, evitando que un Profesor HEE de una edición navegue y administre otras ediciones del mismo taller base.
- **Privacidad:** se amplió `classes/privacy/provider.php` para declarar las tablas personales incorporadas por talleres, profesorado, certificados, Tipo B, traspasos, tareas y reconocimiento institucional.
- **Instalación limpia:** `db/install.xml` se alineó con el modelo actual. Incluye campos de asistencia, reflexión externa, reconocimiento institucional actual y las tablas `local_ga_workshop_series` / `local_ga_series_items`.

### Decisiones de seguridad mantenidas
- No se modifica la autenticación UCV/Microsoft.
- No se crea un login paralelo.
- No se concede automáticamente el rol Moodle de profesor con edición.
- Profesor HEE sigue siendo un permiso limitado de la aplicación.
- Las operaciones destructivas continúan reservadas a gestores globales donde ya lo estaban.

## Hallazgos que deben cerrarse antes del paquete final

Estos puntos se han identificado en la auditoría y **no deben darse por validados en producción todavía**:

1. **Upgrade formal de base de datos:** la instalación limpia ya está alineada, pero `db/upgrade.php` todavía debe incorporar una migración formal de las nuevas tablas/campos para sustituir como vía principal la creación defensiva en tiempo de ejecución.
2. **Listados y descargas:** `manager_downloads.php` conserva partes heredadas del Tipo B interno basadas en `local_ga_typeb_reflections`. Debe pasar a leer el estado de la Tarea Moodle de reflexión.
3. **ZIP Tipo B / expedientes completos:** la descarga masiva debe filtrar explícitamente reconocimientos externos totalmente completados (`validated` + reflexión), igual que el portafolio oficial.
4. **Orden de listados:** cerrar en todos los listados/CSV la regla acordada: edición/fecha más reciente primero y talleres agrupados por Edición de talleres.
5. **Últimos accesos heredados por taller base:** revisar `teacher_view.php`, `edition_edit.php` y el servicio de ficheros para que cualquier operación sensible con edición conocida use siempre `can_manage_edition()`.
6. **Calendario:** ajustar visualmente el HTML hasta aproximarlo a la tabla/calendario de referencia facilitada por Julio.
7. **Prueba Moodle 5 real:** subsecciones, movimiento de actividades, restricciones por grupo, Tarea Tipo B, certificado, Profesor HEE y flujo completo externo Tipo B.
8. **Privacidad completa:** el proveedor ya declara metadatos; si se van a implementar borrado/exportación GDPR de los datos del plugin habrá que definir primero la política de conservación porque implica eliminación de información académica.
9. **Auditoría final de plugin Moodle:** antes de crear ZIP, revisar estándares, seguridad, privacidad, estructura DB, strings y compatibilidad. No añadir al repositorio informes o artefactos específicos del revisor externo.

## Versión actual
- `local_gestion_actividades`: **1.5.94-alpha** (`2026100508`).
- `block_gestion_hee`: **1.0.17-alpha** (`2026100501`).

## Estado de validación
- Auditoría estática de código y coherencia realizada sobre GitHub actual.
- Se han corregido los problemas de alcance, manipulación de parámetros y CSRF indicados arriba.
- `db/install.xml` representa ya la estructura esperada para una instalación limpia.
- No se ha ejecutado todavía una instalación/upgrade real ni una prueba funcional completa en el Moodle UCV.
- No declarar la versión como producción final hasta cerrar los hallazgos pendientes y ejecutar la prueba real.
