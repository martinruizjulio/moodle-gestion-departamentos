# CURRENT_STATE — Moodle Gestión de Departamentos

Última consolidación: 2026-10-05, tras auditoría de ChatGPT, revisión independiente de Claude y corrección de los riesgos abiertos detectados.

Repositorio canónico: `martinruizjulio/moodle-gestion-departamentos`. GitHub es la fuente de verdad. Mantener este proyecto aislado de otros Moodle del usuario.

## Arquitectura actual de talleres

### Ediciones de talleres
- La unidad organizativa principal es **Edición de talleres** (`classes/local/workshop_series.php`).
- Una edición tiene título y rango de fechas editables y crea una sección Moodle.
- Dentro se genera **Calendario y resumen de talleres** y, cuando `mod_subsection` está disponible, una subsección real por taller: Taller 01, Taller 02…
- Manual y Excel terminan en los mismos objetos y siguen el mismo orden de datos.
- `local_ga_workshop_editions.seriesid` vincula explícitamente cada edición concreta con su Edición de talleres; la resolución por fechas queda únicamente como compatibilidad para registros históricos todavía no enlazados.
- Se impiden solapamientos de rango entre Ediciones de talleres que contengan el mismo taller base.
- Las fechas introducidas desde formularios/Excel se interpretan con la zona horaria del usuario mediante `date_helper`.
- Una edición finalizada/oculta deja de mostrarse al alumnado pero conserva sus datos para gestión y archivo.

### Tipo A
- Arquitectura: Edición → sección → calendario → subsección → grupo/inscripción.
- Puede contener asistencia, apuntes y actividad obligatoria.
- La actividad obligatoria puede ser tarea interna HEE o cuestionario Moodle.
- Tarea interna: asistencia + entrega + nota mínima 5/10.
- Cuestionario Moodle: asistencia + cuestionario finalizado con **nota mínima 5 sobre 10** (la nota del cuestionario se reescala a 10 según su nota máxima). Regla única en `manager::get_quiz_requirement()` / `quiz_missing_requirement()` (`QUIZ_PASS_MARK`).
- `teacher_view.php` muestra estado, nota, resultado y certificado del cuestionario cuando procede.

### Tipo B interno
- Misma arquitectura que Tipo A.
- Actividad final: **Tarea Moodle de reflexión**, sin nota numérica, con texto en línea y archivo opcional.
- Regla: **asistencia confirmada + reflexión entregada = Apto**.
- La Tarea Moodle es la fuente de verdad.
- Los observers de `mod_assign` resuelven correctamente al propietario y sincronizan el estado HEE.
- `local_ga_typeb_reflections` se mantiene como compatibilidad, pero nunca puede convertir en válida una reflexión eliminada de la Tarea Moodle.
- `typeb_certificate_policy` impide generar o regenerar un certificado si falta asistencia o reflexión real.

### Tipo B externo
- Alumno sube certificado externo → `pending`.
- Gestor valida → `validated_pending_reflection` si falta reflexión.
- Alumno completa reflexión → `validated`.
- Solo `validated` + reflexión no vacía computa horas y entra en expediente/ZIP.
- La subida usa `moodleform` + `filepicker`, acepta PDF/JPG/PNG y limita a 20 MB.
- El certificado externo es la evidencia original; no se genera un certificado interno duplicado.

## Profesor HEE
- Figura funcional de Gestión HEE sin cambiar el rol institucional Moodle.
- La asignación se guarda por edición concreta en `local_ga_edition_teachers`.
- Profesor HEE gestiona alumnado, asistencia, materiales y seguimiento únicamente de sus ediciones asignadas.
- No recibe edición general del curso ni acceso global al panel.
- Las operaciones sensibles, certificados y ficheros comprueban la edición concreta.
- Los materiales legacy sin edición son estructura compartida y solo los modifica Gestor HEE global.

## Seguridad y permisos
- Acciones mutantes revisadas con `sesskey`/CSRF.
- `pluginfile` valida contexto, propietario, edición y visibilidad antes de servir ficheros.
- Certificados legacy sin `editionid`: propietario o Gestor HEE global, sin heredar permisos por taller base.
- `export.php`, `gradehistory.php`, `index.php` y `view.php` quedan restringidos a `manager::can_manage_globally()` porque exponen información personal/notas.
- `db/access.php` declara `RISK_PERSONAL` / `RISK_CONFIG` según el tipo de capacidad.
- El bloque `gestion_hee` ya no registra observers inexistentes `core\event\file_created/file_deleted`; la caché se invalida desde los puntos funcionales que modifican datos.

## Usuarios y autenticación institucional
- **Gestión HEE no crea cuentas Moodle nuevas.**
- Se ha deshabilitado la creación automática de usuarios `auth=manual` desde CSV/importación de notas.
- Las importaciones solo enlazan/actualizan cuentas institucionales ya existentes cuando corresponda; un usuario inexistente queda como `notfound` y debe provisionarse por la administración institucional.
- No se modifica SSO, autenticación Microsoft/UCV ni roles institucionales.

## Base de datos e instalación
- `db/install.xml` representa la instalación limpia actual, incluyendo `local_ga_workshop_series`, `local_ga_series_items` y `local_ga_workshop_editions.seriesid`.
- `db/upgrade.php` contiene migración formal no destructiva hasta el savepoint **2026100516**.
- El savepoint 2026100516 reconcilia el esquema real con `install.xml` (solo añade tablas, campos e índices que falten; omite índices únicos sobre duplicados). Cubre, entre otros, `local_ga_typeb_certs.reflectiontext/reflectiontime`, que ya no tenían ruta de upgrade tras retirar el DDL en ejecución.
- La migración 2026100513 crea/normaliza las tablas de series, añade `seriesid` e índice y rellena el vínculo histórico usando la regla anterior por rango de fechas.
- El DDL de tablas/campos/índices queda en `db/install.xml` / `db/upgrade.php`; los `ensure_*` de ejecución son guardas de solo lectura y piden ejecutar el upgrade si falta el esquema.
- El índice único de reconocimiento institucional sigue evitando abortar una actualización si existen duplicados históricos: no borra datos y deja aviso para revisión manual.

## Listados, descargas y expediente
- Tipo B interno se informa desde la Tarea Moodle real.
- ZIP/expedientes Tipo B externos incluyen únicamente registros realmente computables (`validated` + reflexión).
- Listados y CSV identifican la Edición de talleres, más reciente primero, manteniendo Taller 01…N dentro de cada edición.
- Portafolio PDF y paquetes muestran certificados del más reciente al más antiguo.

## Privacidad
- `classes/privacy/provider.php` implementa metadata provider, request provider y `core_userlist_provider`.
- Exporta datos HEE del usuario en contexto de sistema/curso: inscripciones/asistencia, certificados, Tipo B, reflexiones, traspasos, entregas, horas institucionales e historial aplicable.
- **No se borran automáticamente datos académicos** en las funciones GDPR de eliminación. Es una decisión deliberada y conservadora hasta que la Universidad defina una política de conservación/borrado.

## Compatibilidad Moodle 5 / interfaz
- Se migraron las clases heredadas relevantes de Bootstrap 4 a Bootstrap 5 (`bg-*`, `me/ms-*`, `float-end`, flex, `form-select`, etc.).
- `workshop_view.php` acepta/resuelve una edición concreta y evita autoinscribir o mostrar materiales de otra edición reutilizada del mismo taller base.
- La creación/edición manual, importación masiva y tareas interpretan fechas con zona horaria del usuario.

## Validación estática final

Tras las correcciones anteriores se ejecutó una comprobación automática completa:
- `php -l` sobre todos los PHP de `gestion_actividades` y `gestion_hee`: **sin errores**.
- XML del repositorio: **bien formado**.
- Aserciones específicas: sin DDL en ejecución fuera de upgrade, sin creación `auth=manual`, sin clases Bootstrap 4 auditadas, sin observers inválidos y sin `$_FILES` en la subida externa Tipo B.
- Se comprobaron además soporte de cuestionario Tipo A, selección de edición concreta, privacidad, filepicker Tipo B, permisos de exportación, vínculo `seriesid` y reflexión Moodle canónica Tipo B.
- Confirmación de savepoint `2026100513` y `seriesid` en `install.xml`.
- Los workflows/scripts temporales usados para las comprobaciones no forman parte del árbol final.

## Riesgos / comprobaciones que siguen abiertas

No son fallos estáticos confirmados; requieren Moodle real o decisión institucional:
- Instalación limpia en Moodle 5 y upgrade desde una instalación existente.
- `mod_subsection`: creación, movimiento y orden real de módulos.
- Restricciones por grupo, inscripción y aforo con usuarios reales.
- Tipo A: tarea interna y cuestionario Moodle, notas mínimas y generación/descarga de certificado.
- Tipo B interno: entrega de texto, archivo, eliminación/reenvío y certificado.
- Tipo B externo: filepicker → validación → reflexión → cómputo de horas.
- Profesor HEE con un usuario UCV real como “Profesor sin permiso de edición”, verificando que no amplía permisos.
- Vínculo explícito edición↔serie y migración histórica 2026100513 sobre datos reales.
- Zona horaria de usuario distinta a la del servidor.
- Exportación de privacidad desde la herramienta de privacidad de Moodle.
- Política institucional antes de implementar cualquier borrado GDPR académico.
- Ajuste visual del calendario si el tema Moodle UCV requiere CSS/HTML adicional.
- Revisión externa final del ZIP con el servicio de Plugin Reviewer solicitado por la Universidad.

No declarar compatibilidad de producción únicamente por las comprobaciones estáticas.

## Segunda revisión de Claude (2026-10-05, noche)

Auditados los commits `c56a2e2..bb4df12` contra el código. Corregido:
- **Bloqueante**: la reescritura de `portfolio_typeb` eliminó `set_status()`, `total_uploaded_hours()` y los filtros de `list_all()`, todavía usados: `portfolio.php` (portafolio del alumno) y `typeb_review.php` (validar/rechazar Tipo B externo) daban error fatal, y `portfolio_admin.php` mostraba las solicitudes de todos los alumnos. Restaurados, con sincronización de notas tras revisar/reflexionar.
- **Bloqueante (upgrade)**: sin ruta de upgrade para `reflectiontext/reflectiontime` de `local_ga_typeb_certs` → savepoint 2026100516.
- **Fechas**: `date_helper::input_datetime()` quitaba el cero del día (valor inválido en `datetime-local`; la fecha límite de tarea se borraba al guardar). `edition_edit.php` y `workshop_series.php` mostraban con `date()` (zona del servidor) y guardaban en la del usuario.
- **Series**: calendario y movimiento de módulos usan ya `seriesid`; las fechas solo para ediciones sin vínculo.
- **`workshop_view.php`**: una edición finalizada lanzaba excepción incluso a su alumnado; ahora es de solo lectura para alumnado inscrito y su profesorado, sin inscripciones.
- **Cuestionario Tipo A**: se respeta la calificación para aprobar del cuestionario Moodle; nota buscada en el ítem principal (`itemnumber = 0`).
- **Usuarios CSV**: seguía exigiendo `moodle/user:create` aunque ya no crea cuentas; ahora solo `moodle/user:update` para actualizar.
- **Tipo B externo**: «1,5» horas se guardaba como 15 (`PARAM_FLOAT`); ahora elemento `float` de Moodle.
- **Privacidad**: la exportación incluye PDFs de certificados, entregas internas y evidencias Tipo B.
- Últimas clases `badge-*` de Bootstrap 4.

Validación: `php -l` (todos los PHP), XML bien formado, sin llamadas a métodos/funciones inexistentes del plugin, strings en/es completos. APIs de Moodle usadas (XMLDB, eventos de `mod_assign`, `userdate`) contrastadas con el código fuente de Moodle. **Sin ejecución en Moodle real.**

## Reglas confirmadas por Julio (2026-10-05)
- **Cuestionario Tipo A**: se aprueba con **5 sobre 10** (nota reescalada a 10). Constante `manager::QUIZ_PASS_MARK`.
- **Tipo B**: **asistencia y reflexión son imprescindibles**. La reflexión es la última entrega de la Tarea Moodle en estado `submitted` (`typeb_certificate_policy::has_reflection`); ni la finalización ni una nota la sustituyen. Si el alumno elimina la entrega o se devuelve a borrador, deja de contar (observers `submission_removed` y `submission_status_updated`).
- Las horas que se guardan al finalizar una edición (`refresh_completed_hours_for_edition`) usan la misma regla que los certificados.

## Versiones actuales
- `local_gestion_actividades`: **1.5.102-alpha** (`2026100517`). Último savepoint de esquema: **2026100516**.
- `block_gestion_hee`: **1.0.20-alpha** (`2026100504`).
