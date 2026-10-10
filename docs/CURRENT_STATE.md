# CURRENT_STATE — Moodle Gestión de Departamentos

Última consolidación: 2026-10-06.

Repositorio canónico: `martinruizjulio/moodle-gestion-departamentos`. GitHub es la fuente de verdad. Este repositorio corresponde exclusivamente a Moodle Gestión de Departamentos / Gestión HEE.

## Jerarquía Moodle acordada para el alumnado

La presentación HEE del curso queda fijada en este orden:

1. **Talleres Tipo A**
   - primera subsección: **Calendario y resumen de talleres** con la tabla HTML generada desde los datos canónicos;
   - después: **TALLER 01: Nombre**, **TALLER 02: Nombre**, etc., cada uno como subsección Moodle cuando `mod_subsection` está disponible (título único definido en `workshop_series::subsection_title()`).
2. **Talleres Tipo B**
   - primera subsección: **Calendario y resumen de talleres**;
   - después: **TALLER 01: Nombre**, **TALLER 02: Nombre**, etc.
3. **Autoevaluación final HEE**
   - sección independiente;
   - el alumnado no debe verla hasta alcanzar **54 horas reconocidas**.
4. **Ediciones anteriores de talleres**
   - se conservan debajo de lo anterior;
   - permanecen ocultas al alumnado;
   - se mantienen para histórico, gestión, certificados y trazabilidad.

`classes/local/course_layout.php` centraliza esta jerarquía visual. Solo reordena las secciones propiedad de HEE y no reorganiza contenido ajeno al sistema HEE.

### Separación obligatoria Tipo A / Tipo B

- Una Edición de talleres es de **un único tipo**: A o B.
- No se permite añadir manualmente un taller B a una edición A ni un A a una edición B.
- La importación Excel rechaza una plantilla que mezcle filas A y B antes de crear la sección padre; deben hacerse dos importaciones.
- Una edición vacía adquiere su tipo al añadir el primer taller.
- Una edición histórica heredada que ya contenga mezcla A/B no se modifica destructivamente; queda identificada como caso legacy y no admite nuevas mezclas.

Internamente se conservan los nombres históricos `workshop`, `workshop_series` y tablas `local_ga_*` para no romper compatibilidad. En la interfaz nueva se prioriza el término **taller**.

## Ediciones de talleres

- `classes/local/workshop_series.php` sigue siendo el modelo canónico de una edición: título, rango de fechas, sección padre, calendario y subsecciones.
- `local_ga_workshop_editions.seriesid` vincula explícitamente cada edición concreta con su Edición de talleres; la resolución por fechas queda como respaldo legacy.
- Manual y Excel desembocan en los mismos objetos.
- Se impiden solapamientos de rango para reutilizaciones incompatibles del mismo taller base.
- Las fechas de formularios/Excel se interpretan con la zona horaria del usuario mediante `date_helper`.
- Una Edición de talleres se considera finalizada **solo cuando su estado se cambia expresamente a `finished`**; superar `dateto` no la finaliza ni la archiva automáticamente.
- Al finalizarla expresamente, la sección completa se oculta y la jerarquía HEE la coloca en el histórico inferior.
- Al reabrirla vuelve a la zona activa correspondiente A o B.

## Tipo A

- Arquitectura: Edición Tipo A → calendario → subsecciones de taller → grupo/inscripción.
- **Actividad predeterminada para cualquier taller Tipo A nuevo: Cuestionario Moodle.**
- La creación manual marca las nuevas ediciones como `quiz` desde el inicio lógico; editar una edición antigua conserva el tipo de actividad que ya tuviera configurado.
- La importación masiva también deja toda edición Tipo A nueva en modo `quiz` aunque en esa fila no se duplique todavía un cuestionario modelo; si «Crear cuestionario» = Sí, se duplica el modelo seleccionado y queda vinculado.
- Un gestor con permisos Moodle adecuados puede posteriormente vincular/cambiar la actividad obligatoria si existe una excepción; el valor por defecto sigue siendo cuestionario.
- Regla canónica del cuestionario: asistencia + cuestionario finalizado + nota mínima **5/10**, reescalada según la nota máxima real del cuestionario.
- Se mantiene compatibilidad con ediciones históricas Tipo A que ya usen una tarea; no se migran destructivamente ni se les cambia la actividad al editar otros datos.
- `teacher_view.php` distingue falta de asistencia, cuestionario no finalizado, pendiente de calificar, nota insuficiente y Apto.

## Tipo B interno

- Misma arquitectura visual que Tipo A, pero en su sección padre Tipo B.
- Actividad final predeterminada y canónica: **Tarea Moodle de reflexión**, sin nota numérica, con texto en línea y archivo opcional.
- Regla canónica: **asistencia confirmada + reflexión Moodle realmente entregada = Apto**.
- La última entrega Moodle en estado `submitted` es la fuente de verdad; la tabla legacy `local_ga_typeb_reflections` no puede rescatar una entrega eliminada.
- Al finalizar/archivar la edición se cierra la entrega de nuevas reflexiones.
- Profesor HEE o Gestor HEE puede conceder a un alumno concreto una prórroga de 7 días mediante la extensión nativa de `assign` y retirarla.
- Si la Edición de talleres ya está finalizada, la prórroga no reabre la edición: su sección padre queda técnicamente visible solo mientras exista una extensión vigente, restringida por el grupo de la edición y por la fecha límite. Así el enlace directo a la reflexión sigue funcionando sin exponer el histórico al resto del curso; al vencer la fecha, la disponibilidad temporal bloquea de nuevo el acceso.
- El certificado solo puede generarse/regenerarse cuando se cumplen asistencia y reflexión.

## Tipo B externo

Flujo canónico:

**certificado externo → validación del gestor → reflexión del alumno → cómputo de horas**.

- Subida mediante `moodleform` + `filepicker`.
- Formatos aceptados: PDF/JPG/PNG, máximo 20 MB.
- Tras validar sin reflexión: `validated_pending_reflection`.
- Solo `validated` + reflexión no vacía computa horas y entra en expediente/ZIP.
- No se genera un certificado interno duplicado.

## Cálculo de horas

`local_gestion_actividades\local\hours_calculator` es la fuente única de verdad para:
- bloque lateral del alumno;
- portafolio del alumno y del gestor;
- PDF;
- informe de horas;
- libro de calificaciones;
- desbloqueo de autoevaluación;
- traspasos A→B.

Reglas:
- historial de horas + certificados no consolidados, una vez por usuario/edición;
- Tipo B interno solo cuenta con reflexión Moodle entregada cuando la edición la exige;
- Tipo B externo solo cuenta validado + reflexión;
- se incluyen reconocimientos institucionales;
- los traspasos activos restan de A y suman a B sin alterar el total.

## Portafolio del alumno

- `portfolio.php` usa `hours_calculator` para que las cifras de Tipo A, Tipo B y total coincidan con bloque, informes y libro de calificaciones.
- La pantalla queda organizada como: resumen de horas → calificaciones HEE → Talleres Tipo A → Talleres Tipo B internos → Formación externa Tipo B → reconocimiento institucional → traspasos A→B → descargas PDF/ZIP.
- Tipo A ya no se describe como tarea por defecto: la interfaz indica que el **Cuestionario Moodle** es la actividad predeterminada de los talleres nuevos, manteniendo compatibilidad con actividades históricas.
- La formación externa Tipo B vuelve a mostrarse en el portafolio del alumno con todos sus estados: pendiente de validar, validado pendiente de reflexión, validado y completado, o rechazado.
- Cuando falta la reflexión de una formación externa ya validada, el portafolio muestra **Completar reflexión** y enlaza al flujo canónico `typeb_upload.php`.
- El alumno puede abrir el certificado externo aportado y consultar el comentario del gestor desde su propio portafolio.
- El reconocimiento institucional usa terminología neutra de **actividad evaluada** para no confundir datos históricos con el nuevo cuestionario Tipo A predeterminado.
- Las descargas del portafolio se mantienen al final de la pantalla y el PDF/ZIP siguen usando las fuentes canónicas existentes.

## Autoevaluación final HEE

- Umbral: **54 horas reconocidas**, calculadas por `hours_calculator`.
- `grades_report.php` permite seleccionar un cuestionario Moodle existente o crear automáticamente uno vacío llamado **Autoevaluación final HEE**.
- El generador está en `classes/local/selfassessment_quiz.php`.
- El cuestionario generado se vincula automáticamente como `selfassessmentcmid`.
- La restricción de 54 horas se aplica tanto al módulo como a su sección para que ni siquiera aparezca la sección antes del umbral.
- Las preguntas se crean/editan exclusivamente con las herramientas nativas de Moodle y el banco de preguntas.
- Crear una actividad nueva exige la capacidad Moodle `moodle/course:manageactivities`; Gestión HEE no amplía ese permiso.
- La creación automática es idempotente mediante `cmidnumber = gestion_hee_selfassessment`.
- `course_layout` coloca la autoevaluación después de las ediciones activas A/B y antes del histórico. También reconoce un cuestionario creado manualmente y después seleccionado en HEE.

## Profesor HEE

- Figura funcional de Gestión HEE sin cambiar el rol institucional Moodle.
- Asignación por edición concreta en `local_ga_edition_teachers`.
- Gestiona únicamente sus ediciones asignadas: alumnado, asistencia, materiales y seguimiento.
- No obtiene edición general del curso ni acceso global al panel.
- Los permisos sensibles y ficheros se validan contra la edición concreta.
- `closed_full` significa lleno, no finalizado, por lo que permanece entre los talleres vigentes.
- La caché del bloque se invalida al cerrar/reabrir series, archivar/borrar ediciones, reasignar docentes y cambiar reflexiones relevantes.
- `authorized_users.php` invalida explícitamente la caché docente al asignar o retirar un Profesor HEE, de modo que el bloque lateral refleja el cambio sin esperar al TTL.
- En la vista visual del taller, Profesor HEE/Gestor HEE dispone de **Listado de apuntados**. Abre `edition_roster.php`, visible solo para quien puede gestionar esa edición concreta.
- El listado usa la misma fuente de inscritos que la gestión de asistencia, ordena por apellidos/nombre y ofrece una vista preparada para imprimir en papel con columnas de asistencia/firma y observaciones. No expone el listado al alumnado.

## Panel de Gestión HEE

- El panel mantiene sus opciones funcionales existentes; no se ha rediseñado ni reducido por el recuento histórico de “9 opciones”.
- Las descripciones visibles se han actualizado a la terminología y reglas actuales: **talleres**, Tipo A con cuestionario predeterminado, Tipo B con reflexión Moodle, autoevaluación a 54 h, portafolio y validación externa Tipo B.
- La vista general del panel prioriza `seriesid` para resolver la edición concreta y usa las fechas solo como respaldo legacy.
- `closed_full` se interpreta como taller completo pero todavía vigente, no como finalizado.
- `workshop_report.php` se ha actualizado para mostrar **Actividad / Estado actividad / Resultado / Asistencia**: cuestionario Moodle y nota reescalada en Tipo A, reflexión Moodle en Tipo B y compatibilidad con tareas Tipo A históricas.
- La nomenclatura visible del listado personalizado y del panel se ha cambiado a **taller** sin renombrar internamente tablas/clases `workshop*`.
- `manager_downloads.php` se ha limpiado para el modelo actual: en Tipo A ya no muestra columnas heredadas de “tarea” como regla general, sino **Actividad / Estado-calificación / Resultado**. Los talleres nuevos leen el Cuestionario Moodle real y las ediciones históricas conservan soporte para Tarea Moodle.
- Los listados/CSV administrativos usan la terminología **taller**, **reflexión** y **Edición de talleres**. El PDF del portafolio también usa la nomenclatura actual y el reconocimiento institucional Tipo A se presenta como **actividad evaluada**.
- La limpieza de informes no cambia el esquema de base de datos ni las reglas académicas; solo alinea lectura y presentación con las fuentes canónicas actuales.

## Bloque lateral Gestión HEE

- Usa el cálculo único de horas.
- Tipo B externo exige validación + reflexión.
- Los traspasos no cambian las horas totales.
- Los contadores de Profesor HEE y `my_workshops.php` comparten criterio de vigente/finalizado.
- Una Edición de talleres padre finalizada mueve sus talleres a finalizados aunque las ediciones hijas conserven datos históricos.
- Debajo del resumen de horas del alumno aparece un botón destacado **INSTRUCCIONES PARA ALUMNOS** que abre un modal de apoyo sin abandonar la página.
- La guía explica inscripción («Apuntarme/Apuntado»), requisitos de Tipo A, Tipo B interno, reconocimiento Tipo B externo, certificados/portafolio, traspasos A→B y desbloqueo de la autoevaluación al alcanzar 54 horas.
- El contenido de ayuda está en strings `es`/`en` del bloque y el modal utiliza componentes Bootstrap 5 de Moodle; no almacena datos ni cambia reglas académicas.

## Seguridad y autenticación

- Acciones mutantes revisadas con `sesskey`/CSRF.
- `pluginfile` valida contexto, propietario, edición y visibilidad.
- Certificados legacy sin `editionid`: propietario o Gestor HEE global.
- Exportaciones con datos personales/notas restringidas a gestión global.
- El listado imprimible de inscritos exige `manager::can_manage_edition()` para la edición concreta y envía cabeceras `no-store` para evitar caché de datos personales.
- Gestión HEE **no crea cuentas Moodle `auth=manual`**.
- Las importaciones trabajan con cuentas institucionales ya existentes.
- No se modifica SSO, autenticación UCV/Microsoft ni roles institucionales.

## Base de datos / upgrades

- `db/install.xml` representa la instalación limpia actual.
- `db/upgrade.php` contiene migración formal no destructiva hasta el savepoint **2026100516**.
- La migración 2026100513 añadió el vínculo explícito `seriesid`.
- La migración 2026100516 reconcilia campos/tablas/índices que falten sin eliminar datos.
- El DDL se mantiene en instalación/upgrade, no en páginas de ejecución.
- La jerarquía A/B, la ordenación del curso, el listado imprimible, la ayuda emergente de alumnado, el cambio de valor predeterminado de Tipo A, la actualización visual del portafolio y la actualización del panel/listados **no requieren cambio de esquema**.

## Privacidad

- `classes/privacy/provider.php` implementa metadata provider, request provider y `core_userlist_provider`.
- Exporta inscripciones/asistencia, certificados, Tipo B, reflexiones, traspasos, entregas, horas institucionales e historial aplicable, incluyendo evidencias asociadas.
- No se borran automáticamente datos académicos hasta que la Universidad determine su política de conservación/borrado.

## Auditoría integral previa a Claude — 2026-10-06

Se cerró una revisión estática adicional del plugin principal y del bloque lateral antes de entregar el repositorio a Claude para auditoría independiente.

Correcciones confirmadas en esta ronda:
- política de finalización unificada: las fechas no archivan/finalizan automáticamente; solo el estado explícito `finished`;
- `workshops.php` y `archive.php` priorizan `edition.seriesid` y usan fechas solo como fallback legacy;
- `manager_downloads.php` evita asociar una edición concreta con series históricas distintas del mismo taller base cuando existe `edition.seriesid`, en todos los listados que reutilizan esa unión;
- el ítem visible del libro de calificaciones y sus informes se denomina **Nota Talleres A**;
- la prórroga de reflexión Tipo B exige una inscripción válida actual (`enrolled`, `attended` o `manual`);
- el acceso directo a una edición cerrada ya filtraba previamente estados de inscripción válidos en `workshop_view.php`; ese hallazgo preliminar queda descartado;
- `move_section_to()` se mantiene deliberadamente por compatibilidad y queda pendiente de validación/runtime Moodle 5 antes de migrar a una API distinta.

Esta auditoría es estática. Siguen siendo obligatorias las pruebas de runtime Moodle 5 UCV y el Plugin Reviewer antes de declarar el paquete listo para producción.

## Auditoría integral independiente de Claude — 2026-10-06

Punto de partida verificado: `e9ca208`, local 1.5.115-alpha (`2026100530`), bloque 1.0.24-alpha (`2026100508`). Correcciones (un commit por tema):

- **Bloqueante — creación de la sección de una Edición de talleres**: `workshop_series::ensure_course_structure()` llamaba a `course_create_section($course, 0, true)`. Desde Moodle 4.4 (`sectionactions`) eso inserta una sección con número 0, que choca con la sección 0 del curso (índice único curso+sección): crear una edición nueva fallaba en Moodle 4.4/4.5/5.x. Ahora `course_create_section($course, 0)`.
- **Subsecciones (`mod_subsection`)**: Moodle mantiene las secciones delegadas al final. `course_layout`, el traslado de la autoevaluación, el generador de autoevaluación y el helper heredado de secciones usaban `MAX(section)` del curso entero, colocando o creando secciones HEE detrás de las delegadas. Ahora se usa la última sección regular (`course_layout::last_regular_section_number()`) y `course_create_section()` devuelve la sección real.
- **Seguridad — ficheros de alumnado**: `typeb_view.php` mostraba siempre en línea la evidencia Tipo B externa; las subidas anteriores al filepicker no se validaban en servidor (HTML/SVG → XSS almacenado contra gestores). Igual con las entregas internas vía `pluginfile`. Solo PDF/JPEG/PNG/GIF se muestran en línea; el resto se descarga.
- **Inscripción activa única**: `manager::is_active_enrolment()` / `active_enrolment_sql()` (= `enrolled`, `attended`, `manual`, más estado vacío legacy). Antes: plazas, tarjetas del curso y acceso a materiales ignoraban `manual`; una marca de asistencia antigua en un registro cancelado/rechazado seguía contando; el listado personalizado y los listados/CSV A/B incluían `blocked_repeat`, `over_places` o rechazados; volver a añadir a un alumno ya «attended» le devolvía a «enrolled» y una reincorporación manual repetida sumaba otra plaza.
- **Finalizar desde el formulario de la edición**: guardar con estado «Finalizada» no cerraba las reflexiones Tipo B ni refrescaba el bloque docente como el botón «Finalizar». Ahora comparten `apply_status_side_effects()`.
- **Resolución edición↔serie** en `dashboard.php`, `archive.php` y `workshops.php`: el respaldo por fechas (y el respaldo final a la edición principal) podía tomar una edición enlazada explícitamente a otra serie. Ahora solo se usan ediciones de esa serie o sin enlazar.
- **Bloque — modal de instrucciones**: el modal vivía dentro del cajón de bloques (con `transform`), por lo que quedaba recortado y tapado por su fondo. Se mueve a `<body>` al cargar y se añaden también los atributos de Bootstrap 4.
- **Terminología**: restos visibles de la denominación antigua «… A» → «Talleres A» (nota final, exportación PDF de notas, informe de horas, portafolio del gestor, traspasos, importación institucional y strings de horas). No se tocan nombres internos ni la plantilla de certificado ya almacenada.

**Ampliación (petición de Julio):**
- **Baja de alumnos y plaza manual**: `edition_students.php` incorpora «Dar de baja» (POST + sesskey + confirmación) para Profesor HEE / Gestor HEE de la edición. `manager::unenrol_user_from_edition()` conserva el registro como `cancelled`, borra su asistencia, lo saca del grupo Moodle y actualiza el bloque; no permite la baja si ya tiene certificado. Las incorporaciones manuales que crean plaza extra se marcan (`reason = MANUAL_SEAT_REASON`) y, al darlas de baja, se resta esa plaza de forma atómica (nunca por debajo de 1). Las plazas manuales creadas antes de esta versión no llevan marca y no se restan.
- **Archivado de actividades**: el «hard archive» heredado sacaba la actividad de la secuencia de su sección; Moodle deja entonces de resolverla (sin acceso, sin revisión, sin enlace) y no la recupera al reconstruir la caché. Además se aplicaba a todo el curso, dejando inaccesibles los cuestionarios/reflexiones de otras ediciones activas al finalizar una edición o vincular una actividad, y la limpieza por nombre podía borrar apuntes de otras ediciones del mismo taller base. Ahora: el contenido de una Edición de talleres nunca se saca de la secuencia ni se borra por esas limpiezas; al finalizar una edición sus actividades se **ocultan y se mueven al final de su sección**; la reflexión Tipo B queda accesible (oculta en la página si el sitio permite actividades «stealth») para la prórroga individual; `move_workshop_modules()` repara módulos huérfanos de versiones anteriores. Las ediciones heredadas fuera de una serie mantienen el comportamiento anterior.
- **Ampliación automática de la Edición de talleres**: si al editar o añadir un taller su fecha/horario queda fuera del rango de su Edición, la Edición se amplía automáticamente (`workshop_series::extend_to_cover()`) con aviso, en lugar de rechazar el cambio. Aplica al formulario del taller, a `attach_workshop()` y a la importación Excel (la previsualización avisa de las filas que ampliarán la Edición). No cambia el estado de la Edición y sigue impidiendo solapamientos con otra Edición que contenga el mismo taller base.
- **Prórroga Tipo B tras finalizar la Edición — resuelta 2026-10-06:** Moodle bloquea una actividad si su sección padre está oculta, aunque exista `assign_user_flags.extensionduedate`. `workshop_series::section_access_state()` mantiene el histórico oculto normalmente, pero cuando hay una extensión vigente habilita técnicamente la sección con disponibilidad Moodle restringida por **grupo de la edición + fecha de fin de la extensión**. `typeb_certificate_policy::set_late_reflection_permission()` garantiza/sincroniza el grupo y refresca ese acceso al conceder o retirar el permiso. La edición sigue en estado `finished`; no vuelve a vigente. Pendiente de confirmar este flujo en Moodle 5 real.

**Correcciones tras la primera prueba Moodle 5 real (2026-10-06):**
- **Estructura visual de la Edición**: la sección padre usa exactamente el título de la Edición de talleres. La primera subsección es **Calendario y resumen de talleres** y después aparecen los talleres como subsecciones Moodle nativas con nombre `TALLER 01: …`, `TALLER 02: …`, etc.
- **Sin tarjeta legacy dentro de la subsección**: se elimina el resumen HTML duplicado del taller. Cada subsección actúa como contenedor visual y muestra directamente las actividades Moodle reales (asistencia, apuntes, cuestionario/reflexión, certificado cuando proceda), como en el diseño validado en la prueba.
- **Restricción por grupo**: la subsección del taller hereda disponibilidad por el grupo propio de la edición cuando existe, de modo que Moodle muestra la restricción de pertenencia al grupo y mantiene el contenido protegido.
- **Secciones legacy `TALLERES TIPO A/B`**: si el curso ya utiliza Ediciones modernas para ese tipo, la salida legacy se limpia y oculta. Esto corrige la falsa impresión de que al importar un Excel se cargan también talleres antiguos: no era el Excel, sino la reconstrucción del listado legacy de todos los talleres publicables.
- **Inscripción desde tarjeta legacy**: los módulos AMD añaden el `sesskey` actual al enlace de inscripción cuando esa superficie legacy siga existiendo en cursos antiguos. En cursos con Edición moderna esa superficie ya no se muestra.
- **Borrado de Edición**: `workshop_series.php` incorpora **Borrar edición** con POST + `sesskey` + confirmación. (Endurecido en la auditoría posterior: ver abajo.)
- Validación puntual tras estos cambios: `php -l` correcto en `manager.php`, `workshop_series.php` (clase y página), `course_layout.php` y `enrol.php`. Falta aún repetir la comprobación visual/funcional en el Moodle 5 real.

**Auditoría independiente de Claude tras la primera prueba real (2026-10-06, base `f9ec3ec`, local 1.5.120-alpha):**

*Correcciones aplicadas (local 1.5.121-alpha / `2026100536`, sin cambio de esquema; savepoint sigue en `2026100516`):*
- **Bloqueante — error «Un parámetro necesario (sesskey) faltaba» al pulsar «Inscribirme»**: `enrol.php` llamaba a `confirm_sesskey()` sin argumento, que internamente hace `required_param('sesskey')` y lanza la excepción cuando el enlace (tarjeta antigua guardada en el resumen de sección) no lleva sesskey. Ahora se lee con `optional_param()`: sin sesskey válido se muestra una página de confirmación (CSRF intacto) y, al confirmar, se inscribe. Vuelve a la vista de la edición exacta (`editionid`).
- **AMD sin compilar**: `amd/build/card_status*.min.js` seguían con el código anterior (Moodle sirve `build/` en producción), por lo que el cambio de `src/` que añade el `sesskey` no llegaba al navegador. Se sincronizan con `src/`.
- **Sin punto de inscripción en Ediciones modernas (bloqueante funcional)**: la subsección del taller no tiene resumen, está restringida al grupo y el calendario no tenía enlaces; la sección legacy quedaba oculta. El alumno no podía apuntarse desde el curso. El calendario incluye ahora la columna **Acceso** con «Ver / Inscribirme» → `workshop_view.php?id=…&editionid=…` (enlace no mutante; la página muestra el estado propio y el botón con sesskey). Etiqueta constante para que el HTML cacheado no quede obsoleto.
- **Autoinscripción en edición cerrada**: `enrol_user_in_edition()` rechaza ahora la autoinscripción (no la manual) en ediciones archivadas/finalizadas o de una Edición finalizada; antes solo `workshop_view.php` lo impedía y `enrol.php` era accesible directamente.
- **Títulos de subsección contradictorios**: `course_layout::rename_series_subsections()` renombraba a `<término anterior> 01 · …` y `ensure_course_structure()` a `TALLER 01: …` (cada sincronización deshacía la otra). Título único `workshop_series::subsection_title()`; además se alinea el nombre de la instancia `mod_subsection`.
- **Orden dentro de la sección padre**: no se garantizaba que el calendario fuera el primero ni el orden de talleres (dependía del orden de creación). `order_parent_section()` reordena solo la secuencia: calendario, TALLER 01, 02…; otros módulos añadidos por el docente quedan detrás sin moverse de sección.
- **Excel creaba la sección legacy `TALLERES TIPO A`**: al guardar cada edición importada (aún sin `seriesid`) se reconstruía la superficie legacy y, en la primera importación, se creaba la sección; la actividad obligatoria Tipo A/B también se creaba en `TALLERES TIPO A` (incluso para Tipo B). Ahora la edición se vincula a su Edición al crearse, la reconstrucción legacy se suspende durante la importación y `create_required_activity_for_edition()` crea la actividad en la sección de la Edición si existe (legacy sin cambios).
- **Cursos mixtos**: la versión anterior dejaba sin tarjeta a cualquier edición legacy abierta si el curso tenía ya una Edición moderna del mismo tipo. Ahora, en ese caso, solo se mantienen tarjetas de ediciones legacy con inscripción todavía abierta y sin Edición; los talleres antiguos no reaparecen. Cursos solo legacy: sin cambios.
- **Borrado de Edición conservador**: `delete_series()` se niega si alguna edición tiene inscripciones, horas, certificados, reflexiones, entregas o traspasos, e indica usar «Finalizar y ocultar». Solo una Edición vacía se retira (ediciones archivadas, sección oculta y renombrada «Eliminada · …», nunca se borran secciones ni actividades).
- **Terminología de gestión**: unificada en las páginas de gestión (después sustituida globalmente por «Edición de talleres»); el panel muestra `TALLER 01`.

*Validación estática:* `php -l` en todos los PHP de ambos plugins sin errores; XML bien formado; `node --check` en los AMD; strings es/en con las mismas claves en ambos plugins; sin ficheros temporales ni workflows en el repositorio. **Sin ejecución en Moodle real.**

*Riesgos/pendientes Moodle real:*
- Profesor HEE sin `moodle/site:accessallgroups` (p. ej. profesor sin permiso de edición) no es miembro del grupo de la edición: ve la subsección restringida en el curso; gestiona desde las páginas del plugin. No se añade a profesores al grupo para no contaminar listados ni sincronizaciones grupo→inscripción (sería un cambio de modelo, pendiente de decisión).
- Con prórroga Tipo B tras finalizar, la sección padre queda accesible a todo el grupo de la edición hasta la fecha de la extensión (no solo al alumno con prórroga); las actividades siguen ocultas/protegidas.
- `ensure_course_structure()` reconstruye las superficies legacy A y B en cada llamada (coste en cursos grandes).
- `extend_to_cover()` en la importación se ejecuta antes de la transacción: si la importación falla, la Edición puede quedar ampliada.

`move_section_to()`: se mantiene. En Moodle 5.2 está deprecada (`#[deprecated]`, MDL-86862) y emite aviso de depuración, pero sigue funcionando como envoltorio de `core_courseformat\local\sectionactions::move_at()`; su retirada está prevista para Moodle 6.0 (MDL-87419). No se ha verificado que `move_at()` exista en Moodle 5.0/5.1, así que migrar ahora podría romper esas versiones: queda para cuando se fije la versión mínima.

Validación: `php -l` en todos los PHP, XML bien formado, sin métodos/funciones inexistentes del plugin, strings en/es completos en ambos plugins. APIs de Moodle contrastadas con el código fuente de Moodle (rama principal). **Sin ejecución en Moodle real.**

## Validaciones realizadas anteriormente

Las rondas anteriores de ChatGPT/Claude dejaron comprobados estáticamente PHP/XML y corrigieron, entre otros, permisos por edición, rutas de upgrade, DDL en ejecución, creación `auth=manual`, Bootstrap 5, observers, filepicker Tipo B, reflexión canónica y cálculo único de horas.

La nueva jerarquía A/B de `course_layout`, el listado imprimible, el modal de instrucciones, el valor predeterminado de cuestionario para Tipo A, la actualización del portafolio, el panel/listado personalizado y la limpieza de listados/descargas se han revisado contra el código actual, pero **todavía necesitan la prueba funcional en Moodle 5 real**. No declarar compatibilidad de producción solo por revisión estática.

## Pruebas Moodle 5 pendientes antes del ZIP final

- Instalación limpia y upgrade desde una instalación existente.
- Confirmar visualmente el orden exacto y el diseño corregido:
  1. sección padre con el **título exacto de la Edición**;
  2. primera subsección **Calendario y resumen de talleres**;
  3. subsecciones `TALLER 01: …`, `TALLER 02: …` con las actividades Moodle reales dentro y sin tarjeta HTML legacy;
  4. no debe aparecer una sección paralela `TALLERES TIPO A/B` cuando el curso usa Ediciones modernas;
  5. repetir el mismo patrón para Tipo B;
  7. Autoevaluación final HEE solo al alcanzar 54 h;
  8. ediciones anteriores ocultas debajo.
- Pulsar «Inscribirme» desde una tarjeta antigua (sin sesskey): debe aparecer la confirmación, no el error; desde el calendario «Ver / Inscribirme» → inscribirse → la subsección del taller pasa a ser accesible.
- Importar un Excel en un curso nuevo: no debe aparecer ninguna sección `TALLERES TIPO A/B`; calendario primero y talleres en el orden del Excel con sus actividades dentro.
- Intentar «Borrar edición» con inscritos (debe negarse) y con una Edición vacía (debe ocultarse).
- Crear edición A manual e intentar añadir B: debe rechazarse; repetir a la inversa.
- Crear un taller Tipo A manual nuevo y comprobar que queda configurado como **Cuestionario Moodle** por defecto; editar después sus datos y verificar que conserva el cuestionario.
- Importar Excel Tipo A con «Crear cuestionario = Sí» y comprobar duplicación/vínculo del modelo; importar otra fila Tipo A sin duplicarlo y verificar que la edición queda igualmente en modo `quiz`, pendiente de vincular/crear el cuestionario.
- Verificar que una edición histórica Tipo A que ya use tarea mantiene esa tarea al editar metadatos.
- Importar Excel solo A y solo B; un Excel mixto debe rechazarse antes de crear la sección.
- Finalizar/reabrir ediciones y comprobar movimiento/visibilidad real con `mod_subsection`.
- Comprobar que el calendario sigue siendo la primera subsección de cada edición.
- Verificar con 53,9 h que no aparece autoevaluación y con 54 h que aparece.
- Verificar cuestionario auto-generado y cuestionario existente seleccionado manualmente.
- Tipo A: cuestionario, nota mínima y certificados.
- Tipo B interno: reflexión texto/archivo, eliminación/reenvío, prórroga y certificado. Probar expresamente una prórroga concedida **después de finalizar la Edición de talleres**: solo el grupo de la edición debe poder alcanzar la sección durante la ventana temporal y el alumno con extensión debe poder abrir/entregar la tarea; al vencer o retirar el permiso debe quedar de nuevo inaccesible.
- Tipo B externo: subida → validación → reflexión → horas.
- Portafolio alumno: comprobar que A/B/total coinciden con el bloque, que aparecen todos los estados Tipo B externo, que **Completar reflexión** abre el flujo correcto y que tras guardar la reflexión se actualizan horas/estado.
- Portafolio alumno: comprobar PDF y ZIP desde la nueva zona de descargas y que los documentos mantienen los mismos totales canónicos.
- Panel: comprobar que todas las opciones abren su destino correcto y que los textos corresponden a las reglas actuales.
- Listado personalizado: probar Tipo A con cuestionario aprobado/suspenso/pendiente, Tipo B con reflexión entregada/pendiente y una edición Tipo A histórica con tarea.
- Listados y descargas: comprobar en pantalla y CSV un Tipo A con cuestionario aprobado, suspendido, pendiente de realizar y pendiente de calificar; comprobar además una edición histórica con tarea y un reconocimiento institucional.
- Listados y descargas: comprobar Tipo B interno, Tipo B externo, traspasos, horas, portafolios y ZIP/PDF con la terminología y cómputo actuales.
- Profesor HEE real UCV sin permiso de edición general y comprobar actualización inmediata del bloque al asignar/retirar profesor.
- Comprobar que **Listado de apuntados** aparece solo a Profesor HEE/Gestor HEE de la edición, muestra exactamente los inscritos actuales y que la impresión en papel oculta la navegación Moodle y deja espacio suficiente para firma/asistencia.
- Verificar en el bloque del alumno que **INSTRUCCIONES PARA ALUMNOS** abre y cierra correctamente el modal en el tema Moodle 5 real, también en móvil, y que el contenido corresponde a las reglas actuales.
- Exportación de privacidad.
- Revisión externa final del ZIP con el servicio de Plugin Reviewer solicitado por la Universidad.

**Unificación terminológica (2026-10-06):**
- Toda la interfaz, ayuda, informes, CSV/PDF y documentación usa ahora **Taller / Talleres** de forma consistente.
- Se actualizaron también expresiones como **Edición de talleres**, **Talleres Tipo A/B**, **Nota Talleres A**, encabezados de tablas y nombres visibles de exportación.
- Los identificadores técnicos internos (`workshop_*`, tablas, clases y rutas) no se renombraron.
- Verificación real sobre el árbol del repositorio con búsqueda recursiva: **0 apariciones del término anterior** en `gestion_actividades`, `gestion_hee` y `docs`.

**Auditoría de Claude de la limpieza de pruebas y la unificación «Taller» (2026-10-06, base `bea5fe2`, local 1.5.123-alpha):**
- **Importante — la limpieza podía borrar actividades que no eran de HEE**: borraba todo módulo referenciado por una edición (`attendancecmid`, `certificatecmid`, `requiredcmid`…). Una actividad existente del curso vinculada con «Vincular actividad», una asistencia o certificado de todo el curso o una actividad compartida por varias ediciones se habrían eliminado; y al borrar la subsección `mod_subsection` Moodle borra también lo que contiene (incluido contenido añadido a mano por el docente). Ahora solo se borran las actividades propias de una sola edición; se conservan (desvinculadas) las compartidas, la autoevaluación final y los módulos `attendance`/`customcert`/`certificate`; antes de borrar cada subsección su contenido restante se mueve a la sección oculta «HEE · Actividades conservadas tras limpieza», y una subsección que no se pueda vaciar no se borra.
- **Grupos**: solo se borran los grupos con el patrón HEE «Taller …» (y su agrupación «Agrupación …» si queda vacía); un grupo del curso enlazado a mano se conserva.
- **Atomicidad**: el borrado de registros del plugin se hace en una transacción (todo o nada).
- **Permiso**: además de Gestor HEE global, la limpieza exige `moodle/course:manageactivities` en el curso elegido (borra actividades y grupos de ese curso).
- Unificación «Taller»: revisada; los ítems de calificación se localizan por `idnumber` (renombrarlos no duplica ítems) y las secciones por id, por lo que el cambio de textos no crea duplicados. Se corrigen tres frases del histórico de este documento que el reemplazo global dejó sin sentido.
- Validación estática: `php -l` en todos los PHP, XML, `node --check`, strings es/en. **Sin ejecución en Moodle real.**
- Pendiente Moodle real: ejecutar la limpieza en un curso de pruebas con (a) un cuestionario existente vinculado, (b) una actividad añadida a mano dentro de un TALLER y (c) un grupo propio del curso; deben conservarse.

**Limpieza masiva de datos de prueba HEE (2026-10-06):**
- Nueva pantalla `test_cleanup.php`, accesible desde **Talleres** y **Ediciones de talleres**.
- Permite seleccionar un curso y ver antes del borrado cuántas Ediciones, talleres, ediciones concretas, inscripciones, certificados, horas, reflexiones, entregas, traspasos y grupos HEE contiene.
- La ejecución exige POST + `sesskey` + escribir exactamente **BORRAR PRUEBAS** + confirmación del navegador.
- El borrado está acotado al curso seleccionado y elimina datos HEE de Talleres/Ediciones de prueba, módulos Moodle referenciados por esos registros, grupos propios de las ediciones y archivos del componente en el contexto del curso.
- **No** elimina usuarios Moodle, el curso, contenido ajeno a Gestión HEE, formaciones Tipo B externas ni reconocimiento institucional.
- Después de la limpieza se invalidan cachés de alumnos/profesores, se recalculan las notas HEE del curso y se actualiza la disponibilidad de la autoevaluación.
- Las secciones HEE padre solo se eliminan si quedan vacías; si Moodle detecta contenido restante, se conservan ocultas para no borrar contenido ajeno por error.
- Validación estática: `php -l` correcto en `manager.php`, `test_cleanup.php`, `workshop_series.php` y `workshops.php`.

**Revisión de los cambios de Claude (2026-10-06):**
- Se aceptan sus mejoras de la limpieza: conservación de actividades compartidas/curso, traslado del contenido restante a una sección oculta, borrado solo de grupos HEE, transacción para registros del plugin y requisito adicional `moodle/course:manageactivities`.
- Se detectó y corrigió un riesgo residual: una tarea o cuestionario Moodle preexistente, vinculado manualmente a una única Edición, podía seguir considerándose “propio” por tener una sola referencia y borrarse. Como no existe un marcador persistente fiable de propiedad, la limpieza **nunca borra módulos `assign` ni `quiz` vinculados**; se conservan/desvinculan y, si estaban dentro de una subsección HEE, se mueven a la sección oculta de conservación.
- No hay cambios de esquema ni savepoint nuevo.

**Corrección de limpieza visual del curso (2026-10-06):**
- La primera versión de la purga eliminaba los registros administrativos, pero podía dejar en Moodle secciones huérfanas creadas por versiones anteriores, especialmente tras usar «Borrar edición». Ejemplos observados en prueba real: `TALLERES TIPO A`, secciones `Eliminada · …`, calendario y subsecciones vacías.
- La purga identifica ahora también estructuras HEE huérfanas desde el árbol real de secciones Moodle, aunque ya no exista su fila en `local_ga_workshop_series`.
- Antes de borrar un contenedor HEE, cualquier actividad restante se mueve a la sección oculta de conservación; después se eliminan las subsecciones generadas y la sección padre si queda vacía.
- La pantalla de limpieza informa por separado de las secciones Moodle HEE y de las secciones huérfanas eliminadas.

**Corrección de la pantalla de limpieza con admin ya vacío (2026-10-06):**
- La purga ya podía eliminar estructuras Moodle HEE huérfanas, pero la interfaz ocultaba el botón cuando `workshops=0` y `series=0`. Esto impedía ejecutar precisamente la limpieza visual después de una purga administrativa previa.
- `course_test_data_summary()` cuenta ahora también **estructuras HEE huérfanas en el curso** (secciones legacy `TALLERES TIPO A/B`, secciones `Eliminada · …` y padres con calendario HEE).
- `test_cleanup.php` muestra ese recuento y permite ejecutar **BORRAR PRUEBAS** aunque todos los registros administrativos estén a cero, siempre que queden estructuras HEE Moodle.

**Prueba real de importación Excel — estructura funcional del taller (2026-10-06):**
- Las subsecciones modernas ya se crean correctamente, pero la prueba mostró que faltaban sus elementos funcionales por defecto.
- La importación crea ahora primero el grupo de la edición y después una actividad Moodle **Attendance** visible denominada `Asistencia TXX`, restringida al grupo del taller.
- Todo Taller Tipo A importado crea un **Cuestionario Moodle vacío** por defecto (`Cuestionario T-X`) aunque la fila no solicite duplicar un modelo. Si se solicita un modelo, se sigue duplicando el cuestionario indicado.
- Asistencia y cuestionario se mantienen visibles dentro de la subsección Moodle moderna; no quedan en modo stealth.
- El calendario usa ahora un control por alumno: **Inscribirme** cuando no está inscrito y **Desinscribirme** cuando ya lo está, además de **Ver taller**.
- La vista del taller también ofrece **Desinscribirme**. La baja voluntaria del alumno solo está disponible antes del inicio del taller; después debe realizarla Profesor/Gestor para proteger asistencia e histórico.
- Nuevo endpoint `unenrol.php` protegido con `sesskey`.
- Validación estática correcta: `php -l` sobre los PHP afectados y `node --check` sobre AMD source/build.

**Últimos ajustes de la prueba real (2026-10-06):**
- **Volver al curso** conserva ahora el curso Moodle de origen en sesión y ya no usa como fallback el último taller registrado.
- La descarga de la plantilla Excel carga explícitamente `lib/filelib.php` antes de llamar a `send_temp_file()`, corrigiendo el error de función no definida.
- El cuestionario Tipo A generado por HEE permanece visible en Ediciones modernas y se mueve a su subsección `TALLER XX`; solo el flujo legacy conserva el archivado histórico.
- Se añade una etiqueta Moodle real de **Apuntarme / Desapuntarme** antes de cada subsección de taller, fuera de la restricción de grupo, para que el alumno pueda entrar al grupo antes de acceder al contenido.
- Las actividades generadas por HEE quedan marcadas mediante `course_modules.idnumber` con prefijo `HEE_`; la limpieza usa esa propiedad y reconoce también restos legacy inequívocos como `Asistencia TXX` y `Cuestionario T-X`.
- La sección `HEE · Actividades conservadas tras limpieza` elimina automáticamente restos propios de HEE y solo se mantiene si contiene contenido realmente manual/ajeno.
- No hay cambios de esquema ni savepoint nuevo.

**Borrado de talleres — retirada real de la estructura Moodle (2026-10-06):**
- `delete_workshop()` retira ahora también la estructura moderna asociada al taller antes de borrar sus registros administrativos.
- Se eliminan el `local_ga_series_item` y su `mod_subsection`; si quedara contenido manual dentro, se mueve primero a la sección oculta de conservación.
- Al reconstruir una Edición, se podan automáticamente subsecciones HEE vacías y huérfanas dejadas por versiones anteriores, identificadas por nombre `TALLER XX` o por el resumen de inscripción `local-ga-enrol-*`.
- Esto corrige las cajas vacías con botón **Apuntarme** que quedaban visibles tras borrar talleres.

**Importación Tipo A — cuestionario vacío canónico (2026-10-06):**
- Todo Taller Tipo A crea siempre un cuestionario Moodle vacío por defecto; ya no depende de la columna Excel `Crear cuestionario` ni obliga a seleccionar un modelo.
- El selector se renombra a **Cuestionario modelo (opcional)** y su opción por defecto es **No duplicar modelo · crear cuestionario vacío automáticamente**.
- Si se selecciona expresamente un modelo, se duplica en lugar del cuestionario vacío, se fuerza visible, se marca como actividad HEE y se restringe al grupo de la edición.
- Los cuestionarios generados por HEE (`Cuestionario T-X` o `idnumber` con prefijo `HEE_`) ya no aparecen como posibles modelos.
- La previsualización muestra para Tipo A **Sí, vacío por defecto**.
- La plantilla XLSX descargable elimina la columna obsoleta **Crear cuestionario**; conserva **Cierre cuestionario**.
- No hay cambios de esquema ni savepoint nuevo.

**Unificación real de creación Tipo A (2026-10-06):**
- Creación manual e importación Excel usan ahora la misma rutina canónica `ensure_typea_default_activities()`.
- Todo Taller Tipo A moderno garantiza grupo Moodle + **Asistencia** + **Cuestionario vacío**; si ya existen, se reutilizan.
- En creación manual, primero se vincula el taller a su Edición y después se crean las actividades, evitando que caigan en el flujo legacy.
- En importación Excel se elimina la llamada legacy `ensure_workshop_course_visuals_safely()`.
- Tras cada importación Tipo A se valida explícitamente que el cuestionario CMID exista y esté vinculado; si no, la importación falla en vez de mostrar éxito incompleto.
- No hay cambios de esquema ni savepoint nuevo.

**Auditoría del ciclo grupo/actividades por taller (2026-10-06):**
- Cada **edición concreta de taller** moderno tiene un único grupo Moodle propio. Los nuevos grupos incluyen el ID de edición en el nombre (`HEE-E{id}`) para impedir reutilización accidental por coincidencia de código/nombre.
- Si se edita una edición existente se conserva su mismo grupo; si el mismo taller base se usa en otra edición, esa nueva edición recibe otro grupo.
- **Tipo A manual y Excel**: grupo + Attendance + cuestionario Moodle vacío (o duplicado de modelo si se selecciona expresamente).
- **Tipo B manual y Excel**: grupo + Attendance + tarea de reflexión.
- Asistencia, cuestionario/reflexión y recursos generados se restringen al grupo; además la propia subsección `TALLER XX` está restringida al mismo grupo, por lo que materiales que el profesor añada dentro heredan la barrera de acceso de la subsección.
- `Apuntarme` exige ahora que Moodle añada realmente al alumno al grupo antes de guardar la inscripción; una inscripción activa repara membresía faltante. `Desapuntarme` elimina la membresía del grupo.
- Corregido un orden de operaciones que volvía a poner el cuestionario Tipo A en `visibleoncoursepage=0`: ahora se aplica primero la restricción y después se restaura la visibilidad dentro de la subsección.
- La creación manual Tipo B se alinea con Excel y crea también Attendance.
- No hay cambios de esquema ni savepoint nuevo.

**Regresión real al crear cuestionarios en Moodle 5 (2026-10-06):**
- La importación podía abortar con `No se ha podido crear automáticamente la actividad requerida: Error escribiendo a la base de datos` y revertir toda la transacción, dejando el curso sin taller/grupo/asistencia.
- Causa: el `moduleinfo` usado para crear el quiz de Taller Tipo A era demasiado mínimo para Moodle 5 y omitía campos estándar que el generador de autoevaluación sí proporcionaba.
- El creador de quiz Tipo A usa ahora una configuración completa alineada con la autoevaluación: método de calificación, navegación, barajado, comportamiento, revisión, seguridad, finalización y demás valores requeridos.
- Se mantiene la regla: Tipo A manual/Excel = grupo propio + Attendance + cuestionario vacío visible/restringido; si falla cualquiera de estos pasos, la operación no se considera correcta.
- No hay cambios de esquema ni savepoint nuevo.

**Falso negativo al validar el quiz recién creado (2026-10-06):**
- Tras resolver el error de escritura del quiz, la importación podía abortar con `el cuestionario Tipo A no quedó creado/vinculado en Moodle` aunque el módulo acabara de crearse correctamente.
- Causa: la comprobación usaba `get_coursemodule_from_id()` dentro de la misma transacción y justo después de mover el quiz a una subsección delegada; el modinfo de Moodle podía estar todavía desfasado y devolver `false`.
- La rutina canónica Tipo A y la validación final de Excel comprueban ahora directamente `course_modules + modules + quiz` en la base de datos, evitando depender de caché durante la transacción.
- Si el registro quiz realmente no existe o el CMID no está vinculado, entonces sí se aborta.
- No hay cambios de esquema ni savepoint nuevo.

## Auditoría de Claude — creación de talleres con asistencia, inscripción y cuestionario por grupo (2026-10-06, base `ccedf9f`, local 1.5.135-alpha)

Objetivo auditado: al crear una Edición (manual o Excel) cada taller debe tener grupo propio, lista de asistencia, botón Apuntarme y cuestionario (Tipo A) o reflexión (Tipo B), todo restringido al grupo.

Comprobado correcto: grupo único por edición (nombre con `HEE-E<id>`) creado antes de las actividades; asistencia y cuestionario/reflexión restringidos al grupo (modo grupos separados + agrupación + disponibilidad); la inscripción exige alta en el grupo; botón Apuntarme en el resumen de cada subsección TALLER (Moodle muestra el resumen aunque la sección esté restringida) y en el calendario; desinscripción con `sesskey` y bloqueada al empezar el taller.

Correcciones (1.5.136-alpha / `2026100551`, sin cambio de esquema):
- ~~Asistencia opcional si falta `mod_attendance`~~: revertido en 1.5.137 (la asistencia es obligatoria y el Moodle de la universidad tiene el módulo).
- **Edición Tipo A histórica con tarea**: al guardarla dentro de una Edición se le creaba un cuestionario y se cambiaba su actividad obligatoria. Ahora se conserva la tarea.
- **Cada guardado renombraba el cuestionario a «Cuestionario T-n» y lo volvía visible**, deshaciendo cambios del profesor. Solo se nombra/configura al crearlo; nombre unificado `Cuestionario T01` (como `Asistencia T01`); la limpieza reconoce ambos formatos.
- **Grupo duplicado**: si una edición perdía su `groupid`, se creaba otro grupo con el mismo nombre; ahora se reutiliza el suyo.
- **Desapuntarme con un clic desde el curso**: `unenrol.php` pide confirmación; una vez empezado el taller el botón muestra «Apuntado».

Pendiente / decisión:
- Asistencia de `mod_attendance` y sesión automática: resuelto en 1.5.137 (ver sección siguiente).
- El guardado manual lanza el error después de guardar la edición si falla la creación de actividades (no es transaccional).

Validación estática: `php -l`, XML, `node --check`, strings es/en. **Sin ejecución en Moodle real.**

## Asistencia obligatoria desde la lista del taller (2026-10-06, 1.5.137-alpha / `2026100552`)

Decisiones de Julio: el Moodle de la universidad tiene `mod_attendance`; la asistencia es imprescindible para dar por bueno cualquier taller A o B; **solo cuenta «Presente»**; **la lista del taller manda**; la sesión se crea automáticamente.

- `mod_attendance` vuelve a ser **obligatorio** al crear talleres (se revierte la opción «opcional» de 1.5.136).
- Nueva clase `classes/local/attendance_sync.php`:
  - `ensure_session()`: crea una sesión con la fecha/hora y duración del taller (fin del Excel/formulario; si no hay, horas del taller) **solo para el grupo de la edición**; si cambia la fecha y nadie ha pasado lista, la sesión se mueve. Se llama al crear la asistencia, al guardar la edición y tras la importación.
  - «Presente» = estado con la nota más alta del conjunto de estados de la sesión (independiente del idioma; por defecto Presente 2, Retraso 1, Justificado 1, Falta 0).
  - `sync_edition()`: para cada inscrito activo con algún registro en la lista, asistido en HEE si y solo si algún registro es «Presente». Una corrección Presente→Falta retira la asistencia HEE salvo que ya tenga certificado. Alumnos sin registro conservan el marcado manual de «Alumnos / asistencia».
  - Lista compartida por varias ediciones (caso histórico): solo cuentan las sesiones del grupo de la edición.
- Sincronización: observadores de `\mod_attendance\event\attendance_taken` y `attendance_taken_by_student`; además al abrir «Alumnos / asistencia» y la vista del profesor, y antes de generar cada certificado.
- «Alumnos / asistencia»: para alumnos que figuran en la lista se muestra «Según lista de asistencia» y no se permite el marcado manual (se corrige en la lista).

Pendiente Moodle real: crear un taller → comprobar sesión creada para su grupo con fecha/duración correctas; pasar lista (Presente / Retraso / Falta) → solo Presente queda asistido en HEE; corregir Presente→Falta → se retira; con certificado emitido → no se retira; cambiar la fecha del taller antes de pasar lista → la sesión se mueve.

## Cuestionario/reflexión vinculados al taller y su grupo — causa raíz y prueba real (2026-10-06, 1.5.138-alpha / `2026100553`)

Problema real: al crear talleres (manual o Excel) el cuestionario no quedaba vinculado a su taller y grupo.

Causas encontradas y corregidas:
1. **Reutilización de un cuestionario ajeno (causa principal).** `get_required_activity_for_edition_by_type()` devolvía, si la edición no tenía actividad vinculada, cualquier cuestionario/tarea del curso cuyo nombre contuviera «cuestionario», «tarea» o «taller». Al crear el TALLER 02 se «encontraba» el cuestionario del TALLER 01: no se creaba uno nuevo, el TALLER 02 quedaba sin cuestionario y el del TALLER 01 se re-restringía al grupo del TALLER 02. Podía apropiarse también de un cuestionario propio del profesor. Ahora solo cuenta la actividad vinculada explícitamente a esa edición; las coincidencias por nombre quedan solo como sugerencia en «Vincular actividad».
2. **Reflexión Tipo B imposible en Moodle 4.5/5.x**: faltaban campos obligatorios de `assign` (`cutoffdate`, `gradingduedate`, `markingworkflow`…) → «Error writing to database». Añadidos, junto con la configuración de envío (texto en línea + 1 archivo).
3. **Transacción abierta tras un fallo**: `add_moduleinfo()` abre su propia transacción; al capturar el error y seguir, Moodle deshacía al final de la petición todo lo hecho después. Ahora se cierra (o, dentro de la importación Excel, se relanza para deshacer la importación completa).
4. **Tipo B manual**: la edición se vincula a su Edición antes de crear asistencia y reflexión (como el Excel), evitando la ruta antigua que sacaba la actividad de la página del curso.

**Prueba real ejecutada** (Moodle 5.0.11 + PostgreSQL 16 + mod_attendance MOODLE_500_STABLE, instalación limpia con ambos plugins): Tipo A y B manual y por Excel (2 talleres cada uno). 130 comprobaciones correctas: grupo propio, subsección TALLER con Apuntarme y restricción de grupo, asistencia y sesión del grupo con duración del taller, cuestionario/reflexión creados, distintos por taller, vinculados, dentro de su subsección, visibles y restringidos al grupo; alumno no inscrito sin acceso, inscrito con acceso; re-guardar no duplica ni renombra; asistencia (solo Presente, corrección Presente→Falta); calendario primero y orden TALLER 01/02; sin sección legacy; cuestionario propio del profesor intacto.

Pendiente: cursos donde ya se crearon talleres con versiones anteriores pueden tener cuestionarios cruzados (restringidos al grupo de otro taller) o ediciones sin cuestionario: revisar en «Vincular actividad» de cada taller; no se corrige automáticamente para no tocar datos existentes.

## Garantía por taller (2026-10-06, 1.5.139-alpha / `2026100554`)

Todo taller nuevo pertenece a una Edición (el formulario rechaza crear un taller suelto) y queda con: grupo creado expresamente (`… · HEE-E<id>`, no compartido), asistencia con sesión del grupo (grupos separados + agrupación del taller + restricción de grupo), cuestionario propio (Tipo A) o reflexión (Tipo B) con la misma vinculación al grupo, y botón Apuntarme/Desapuntarme en su subsección. Corregido además: el cuestionario duplicado desde un modelo en el Excel quedaba sin modo de grupos/agrupación.

Prueba real (Moodle 5.0.11): manual A, manual B, Excel A, Excel B y Excel A con cuestionario modelo — 239 comprobaciones correctas, incluidas apuntarse → entra al grupo y accede; desapuntarse → sale del grupo y pierde acceso; volver a apuntarse; 8 talleres con 8 grupos y 8 actividades distintas; botón de cada subsección asociado a su edición.

## Apuntarme / Desapuntarme sin salir del curso (2026-10-06, 1.5.140-alpha / `2026100555`)

- Nuevo `enrol_toggle.php` (AJAX, POST + `sesskey`): apunta o desapunta y devuelve el nuevo estado; mismas reglas que antes (edición finalizada, plazas, cierre, no desapuntarse una vez empezado el taller).
- Script único en `lib.php` (`local_gestion_actividades_card_script()`), protegido contra doble instalación (`window.localGaCards`): pinta todos los botones del taller (subsección y calendario), intercepta el clic, pide confirmación solo para desapuntarse y cambia el botón en el sitio con un aviso breve. Sin JavaScript el botón sigue llevando a la página del taller.
- Estado del botón centralizado en `manager::enrol_button_status()` (usado por `card_status.php`, la carga inicial y el nuevo endpoint).
- Los módulos AMD antiguos no actúan cuando el script único está presente.
- Corregido un bucle del observador de cambios del DOM que podía colgar la página.
- Tras apuntarse o desapuntarse con éxito la página se recarga sola (decisión de Julio, 1.5.141) para que Moodle recalcule el acceso a las actividades del grupo; se conserva la posición de desplazamiento y se muestra el aviso junto al botón pulsado. Prueba real en navegador: bloqueado → Apuntarme → recarga en la misma posición, aviso, asistencia y cuestionario/reflexión visibles → Desapuntarme → recarga y vuelve a bloquearse; sin errores JavaScript.

Prueba real en navegador (Chromium + Moodle 5.0.11, alumno real): Apuntarme → «Desapuntarme» sin cambiar de página, los 2 botones del taller cambian, se guarda inscripción y grupo; Desapuntarme con confirmación → vuelve a «Apuntarme»; cancelar no desapunta; el estado persiste al recargar; sin errores JavaScript.

## Validación en el Moodle real de Julio (2026-10-06)

Julio instaló los ZIP de `7467de6` (local 1.5.141-alpha, bloque 1.0.26-alpha) en su Moodle y confirma que el funcionamiento es correcto («Está perfecto»): creación de talleres con grupo, asistencia y cuestionario/reflexión; Apuntarme/Desapuntarme con recarga en el sitio.

## Carpeta de materiales por taller (2026-10-06, 1.5.142-alpha / `2026100557`)

Decisión de Julio: los archivos de un taller solo los ve el alumnado apuntado a ese taller, desde que el taller empieza hasta que termina (cierra) el cuestionario.

- Nueva clase `classes/local/materials_folder.php`: cada taller de una Edición recibe una carpeta Moodle «Materiales T0X» (idnumber `HEE_EDITION_<id>_MATERIALS`) dentro de su subsección TALLER; el profesor solo arrastra archivos.
- Disponibilidad: grupo del taller (oculta a los demás) Y desde el inicio del taller Y hasta el cierre del cuestionario (`quiz.timeclose`). Tipo B: hasta el plazo de la reflexión (`cutoffdate`, si no `duedate`). Sin fecha de cierre no hay límite final.
- Se recalcula en cada sincronización de la estructura (cambio de fecha del taller) y con el observador `\core\event\course_module_updated` cuando se cambian las fechas del cuestionario o de la reflexión.
- Prueba real (Moodle 5.0.11, 8 talleres A/B manual y Excel): carpeta creada en su subsección; apuntado antes del inicio → «Disponible a partir de…» sin acceso; taller empezado → acceso; alumno no apuntado → no la ve; al fijar/adelantar el cierre del cuestionario o del plazo de reflexión la carpeta se limita y, cerrado, deja de estar accesible. 303 comprobaciones correctas en total.
- Los materiales subidos por «Añadir material» de Gestión HEE mantienen su regla anterior (apuntado y desde el inicio, sin límite final).

## «Guardar cambios» del taller (2026-10-06, 1.5.143-alpha / `2026100558`)

Informe de Julio: «Guardar cambios» daba error y había que guardar, volver y pulsar «Publicar en curso».

- **Guardar ya publica y se queda en la misma pantalla**: tras guardar se ejecutan los mismos pasos que «Publicar en curso» y se vuelve al formulario del taller con «Cambios guardados y publicados en el curso.». «Publicar en curso» también vuelve al formulario.
- **Tipo B: cada guardado creaba una reflexión nueva** (`save_workshop_edition()` desvinculaba siempre la tarea de los Tipo B; después se creaba otra y las entregas quedaban en la anterior). Ahora se conserva la reflexión vinculada. Ediciones guardadas con versiones anteriores pueden tener tareas «Reflexión · …» duplicadas en su subsección: la vinculada es la última; revisar antes de borrar ninguna si ya hay entregas.
- **Avisos que rompían la redirección con depuración activada**: `cmidnumber` ausente al crear asistencia/cuestionario/reflexión/subsección (aviso PHP de `add_moduleinfo()`), campos de nombre incompletos en el profesorado del calendario (`fullname()`), y callbacks antiguos de `lib.php` que Moodle 4.4+ marca como obsoletos en cada página. Nuevos `db/hooks.php` + `classes/hook_callbacks.php` (Moodle 4.4+; en versiones anteriores siguen los callbacks de `lib.php`).
- Prueba real (Moodle 5.0.11, depuración DEVELOPER activada): guardar ediciones A y B repetidas veces → misma pantalla, mensaje de guardado y publicado, sin avisos y sin crear actividades duplicadas; prueba completa de 303 comprobaciones correcta.

## Certificados y horas automáticos al finalizar cada taller (2026-10-06, 1.5.144-alpha / `2026100559`)

Decisión de Julio: al finalizar cada taller se generan los certificados de todos los que tengan asistencia y nota de 5 o más en el cuestionario, y las horas se suman a la ficha de cada alumno.

- Nueva clase `classes/local/auto_certificates.php` y tarea programada `\local_gestion_actividades\task\generate_workshop_certificates` (cada 15 min, `db/tasks.php`).
- Fin del taller: cierre del cuestionario (Tipo A) o plazo de la reflexión (Tipo B); si no tienen fecha de cierre, el fin de la sesión del taller. Durante 30 días después se siguen recogiendo los que pasen a cumplir (nota corregida, asistencia marcada tarde, reflexión con prórroga).
- Regla: la misma de siempre (`user_is_certificate_eligible`): Tipo A asistencia «Presente» + cuestionario terminado con ≥ 5/10; Tipo B asistencia + reflexión entregada. Solo se envía el correo del certificado nuevo; no hay avisos repetidos.
- Horas: se registran en el historial de horas y se recalculan las notas HEE; la ficha del alumno (portafolio, bloque, notas) las toma de `hours_calculator`.
- «Finalizar» un taller y «Finalizar y ocultar» una Edición emiten antes los certificados pendientes (ya no exige generarlos a mano).
- Corregido: la protección de «Tipo A histórico con tarea» solo se aplica si hay una tarea realmente vinculada.
- Prueba real (Moodle 5.0.11): con el cuestionario abierto no se emite nada; al cerrarse, asistencia + 7/10 → certificado (PDF) y 2,5 h en su ficha; asistencia + 4/10 → nada; falta + 9/10 → nada; repetir no duplica; nota corregida a 6/10 → certificado en la siguiente ejecución; tarea registrada y ejecutada por `admin/cli/scheduled_task.php`. Prueba completa de 303 comprobaciones correcta.

## Limpieza de pruebas: error 500 (2026-10-06, 1.5.145-alpha / `2026100560`)

Informe de Julio: «Borrar pruebas» devolvía error 500. No reproducible en el Moodle de prueba (la limpieza terminaba bien), pero el 500 sin mensaje apunta a que PHP corta la petición por tiempo/memoria: cada actividad borrada pasaba por la copia de seguridad de la papelera de reciclaje del curso (con intentos, registros y archivos), que en el servidor real es lenta.

- `test_cleanup.php`: eleva el límite de tiempo y de memoria y continúa aunque se cierre el navegador.
- `purge_course_test_data()`: desactiva la papelera del curso solo durante esa petición (`$CFG->forced_plugin_settings`), sin tocar la configuración del sitio.
- Prueba (Moodle 5.0.11): limpieza de un curso con 8 talleres y 37 actividades: de 6,3 s a 1,8 s; la papelera no recibe copias; la configuración `tool_recyclebin/coursebinenable` sigue igual.
- Si persistiera el 500, hace falta el mensaje del registro de errores del servidor.

## Plantilla Excel vacía (2026-10-06, 1.5.146-alpha / `2026100561`)

Informe de Julio: la plantilla descargada venía con dos filas rellenas (un ejemplo Tipo A y otro Tipo B) y debía venir vacía.

- La hoja `TALLERES` sale solo con la cabecera (el importador solo lee esa hoja). Fechas y horas con formato texto para que Excel no las transforme; listas desplegables en «Tipo» (A/B) y «Crear apuntes» (Sí/No).
- Los ejemplos y las instrucciones pasan a una hoja aparte `INSTRUCCIONES`, que nunca se importa.
- Prueba (Moodle 5.0.11): descarga desde la página → hojas TALLERES e INSTRUCCIONES; plantilla sin rellenar → 0 talleres; rellenada con dos filas Tipo B → ambas válidas.

## Tipo B: reflexión solo en texto en línea (2026-10-06, local 1.5.147-alpha / bloque 1.0.27-alpha)

Decisión de Julio: los talleres Tipo B son como los A pero sin cuestionario; en su lugar tienen una tarea en línea (sin archivo adjunto) para escribir la valoración del taller.

- La tarea de reflexión se crea y se configura solo con «Texto en línea»; el envío de archivos queda desactivado (también en las ya existentes, al guardar su edición).
- Textos actualizados: introducción de la tarea, vista del profesor, vista del taller, formulario de edición, aviso de la importación y ayuda del bloque (ES/EN).
- Prueba real (Moodle 5.0.11) con la plantilla nueva: Excel con 2 talleres B → cada uno con Apuntarme, grupo propio, «Asistencia T0X», «Reflexión · …» solo texto en línea y restringida al grupo, «Materiales T0X», sin cuestionario. Alumna con asistencia + reflexión → certificado y 2 h Tipo B al cerrar el plazo; asistencia sin reflexión o reflexión sin asistencia → nada. En el navegador el alumno solo ve el editor de texto, sin selector de archivos.

## Códigos automáticos y nombre del profesorado en el calendario (2026-10-06, 1.5.148-alpha / `2026100563`)

Decisiones de Julio:
- **Códigos**: en las plantillas A y B la columna «Código» se numera 1, 2, 3… (o se deja vacía). El código interno se genera solo: `TA-E<id Edición>-NN` / `TB-E<id Edición>-NN` (código de edición `…_E1`). Nunca choca ni sobrescribe: dos importaciones del mismo Excel dan códigos distintos. Se elimina la columna «Código edición». El alta manual dentro de una Edición usa la misma regla (el formulario muestra el código que se generará).
- **Profesorado**: nuevas columnas «Nombre profesor» (lo que muestra el calendario) y «Email profesor» (vincula al profesor del curso como Profesor HEE; varios con «;»). El calendario muestra el nombre escrito y, si no hay, el nombre completo de los profesores vinculados; nunca el correo. En el formulario del taller hay un campo «Nombre del profesorado para el calendario».
- Esquema: nuevo campo opcional `local_ga_series_items.teachernames` (char 255), añadido en `install.xml` y en el paso de actualización `2026100563` (no destructivo).
- Prueba real (Moodle 5.0.11): Excel A y B importados dos veces cada uno con códigos 1 y 2 → `TA-E67-01/02`, `TA-E68-01/02`, `TB-E69-01/02`, `TB-E70-01/02`; profesor vinculado por correo; calendario con «Ana García» y «Luis Pérez», sin correos; alta manual en el navegador → `TB-E76-03` y nombre del profesorado guardado. Regresión: 303 + Tipo B correctos.

## Plantilla_Talleres_A / Plantilla_Talleres_B y Ediciones finalizadas plegadas (2026-10-06, 1.5.149-alpha / `2026100564`)

Decisiones de Julio: dos plantillas con nombre inequívoco y, al terminar una Edición, ocultarla y plegarla.

- **Plantillas**: la página de importación ofrece «Descargar Plantilla_Talleres_A» y «Descargar Plantilla_Talleres_B» (`Plantilla_Talleres_A.xlsx` / `Plantilla_Talleres_B.xlsx`). Hojas `TALLERES_A`/`INSTRUCCIONES_A` y `TALLERES_B`/`INSTRUCCIONES_B`; fila 1 con título de color «PLANTILLA TALLERES TIPO A/B», fila 2 cabecera, datos desde la fila 3. Sin columna «Tipo» (el tipo lo da la hoja); la B no lleva «Cierre cuestionario». El importador también acepta la plantilla única anterior con columna «Tipo».
- **Edición finalizada**: además de ocultarse a los alumnos e ir al histórico inferior, su sección se pliega (contenido e índice del curso) para todos los usuarios que ven secciones ocultas (profesores, gestores, administradores) mediante su preferencia `coursesectionspreferences_<curso>`; al reabrirla se despliega. Los certificados pendientes se emiten al finalizar también cuando se cambia el estado desde el formulario de la Edición.
- Prueba real (Moodle 5.0.11): ambas plantillas descargadas desde la página; vacías; rellenadas → 2 talleres A (`TA-E80-01/02`) y 2 B (`TB-E81-01/02`) con el tipo correcto; Edición A finalizada → oculta, plegada para profesor y administrador (comprobado en el navegador: contenido e índice plegados), reabierta → visible y desplegada. Regresión: 303 + Tipo B + certificados correctos.

## Botón «Lista de clase» en cada taller (2026-10-06, 1.5.150-alpha / `2026100565`)

Petición de Julio: junto a «Apuntarme», un botón naranja «Lista de clase» con el listado imprimible para pasar lista en papel (apellidos, nombre, correo y un campo vacío para firmar).

- La subsección TALLER incluye el botón naranja «Lista de clase» → `edition_roster.php`. Como el resumen es común a todos, va oculto y el script del curso solo lo muestra a quien gestiona esa edición (Gestor HEE y el Profesor HEE asignado; `enrol_button_status()` devuelve `canmanage`). La página vuelve a comprobar el permiso.
- `edition_roster.php` pasa a llamarse «Lista de clase»: columnas N.º, Apellidos, Nombre, Correo y Firma (vacía), ordenado por apellidos; al imprimir se oculta la navegación de Moodle.
- Prueba en navegador (Moodle 5.0.11): administrador ve los 8 botones, profesor asignado a 4 talleres ve solo esos 4, alumno ninguno; la lista se abre y en vista de impresión muestra la tabla con firma.
- En cursos ya creados el botón aparece en cuanto se actualiza la Edición (guardar un taller o «Actualizar»).

## Limpieza de pruebas por Edición (2026-10-06, 1.5.151-alpha / `2026100566`)

Informe de Julio: con dos Ediciones en el curso, «Limpieza de pruebas» las borraba todas sin poder elegir.

- `test_cleanup.php`: tras elegir el curso, paso «2. ¿Qué quieres borrar?» con una opción por Edición (título, tipo, n.º de talleres, fechas, finalizada) y la opción «Todas». La vista previa y la confirmación muestran exactamente lo que se borrará.
- `purge_course_test_data($courseid, [$seriesid])`: borra solo esa Edición (sus ediciones, inscripciones, horas, certificados y sus PDF, reflexiones, grupos HEE, actividades generadas incluida la carpeta de materiales, subsecciones y sección). Un taller base solo se borra si ninguna otra Edición lo usa. No toca las demás Ediciones ni hace las reparaciones globales del curso (estructuras huérfanas, sección de conservados), que quedan para «Todas». Después reordena el curso.
- Prueba en navegador (Moodle 5.0.11): curso con 4 Ediciones (2 A, 2 B) e inscritos en una de ellas → borrada solo «Edición A 1» (9 módulos, 2 grupos, su sección); las otras 3 Ediciones intactas con sus subsecciones, actividades y grupos. «Todas» sigue funcionando. Regresión 303 correcta.

## Versiones actuales

- `local_gestion_actividades`: **1.5.151-alpha** (`2026100566`). Último savepoint de esquema: **2026100563**.
- `block_gestion_hee`: **1.0.27-alpha** (`2026100511`).

## 2026-10-07 · Plugin temporal de demostración `local_hee_demo` 0.1.0-demo + local 1.5.152-alpha

**Cambios**
- Nueva carpeta `hee_demo/` → plugin **temporal** `local_hee_demo`, separado de Gestión HEE (no forma parte de la versión final; desinstalar tras la demo). Página Administración › Plugins › Locales › «Datos de demostración HEE» (solo `moodle/site:config`).
  - «Generar demostración»: en las Ediciones elegidas crea N alumnos ficticios (`heedemo001…`, idnumber `HEE_DEMO`, `@example.invalid`, emailstop, contraseña elegida y no guardada), los matricula en el curso, inscribe 40 por taller al azar (se repiten entre talleres), pasa lista (90 % Presente por defecto), crea intentos finalizados con nota en el cuestionario Tipo A (10 % suspensos) y entregas de reflexión en línea Tipo B (90 %). Idempotente: repetir no duplica. Opción de finalizar las Ediciones (emite certificados y horas).
  - «Borrar demostración»: elimina solo los alumnos demo y todo lo que generaron (certificados y PDF, horas, inscripciones, asistencia, intentos, entregas). Talleres y Ediciones no se tocan.
- Plantillas de demo `Demo_Plantilla_Talleres_A/B.xlsx` (4 talleres cada una, 13–16/10/2026, 45 plazas).
- Gestión HEE: `manager::reflection_plain()`; el portafolio, el traspaso A→B y las descargas de gestor mostraban la reflexión con etiquetas `<p>` visibles (el editor guarda HTML). Ahora texto limpio.

**Validación (Moodle 5.0.11 local)**: importación de ambas plantillas (8 talleres), generación 100 alumnos / 320 inscripciones en ~23 s, asistencia y notas coherentes, repetir sin duplicados, al finalizar certificados = elegibles exactos (252) sin duplicados, horas en fichas, login del alumno demo, borrado sin restos; capturas de la página del plugin y del portafolio del alumno.

**Pendiente**: probarlo en el Moodle de Julio; desinstalar `local_hee_demo` tras la presentación.

## 2026-10-07 · `local_hee_demo` 0.1.1-demo
- Acceso directo en el curso: menú «Más» › «Datos de demostración HEE» (solo administradores del sitio), con el curso ya elegido. Motivo: la página no aparece en la tabla «Gestionar plugins locales»; solo como enlace en Administración › Plugins › Plugins locales. Validado en Moodle 5.0.11 local.

## 2026-10-07 · local 1.5.153-alpha · Listado personalizado de talleres
- `workshop_report.php` (tarjeta «8. Listado personalizado de talleres»): columnas **Apellidos · Nombre · Correo · Taller · Edición (título de la Edición) · Horas · Calificación · Asistencia · Resultado**, en pantalla y en CSV. Calificación = nota /10 del cuestionario (Tipo A) o «Reflexión entregada/pendiente» (Tipo B); Resultado Apto/No apto. Orden: fecha del taller, código, apellidos.
- Validado en Moodle 5.0.11 local con la demo (320 filas, CSV descargado y revisado).

## 2026-10-07 · local 1.5.154-alpha · Estado de talleres en Ediciones finalizadas
- `workshops.php` y `dashboard.php`: la columna «Estado» mostraba «Abierto» (estado interno del taller) aunque su Edición estuviera finalizada/oculta. Ahora, si la Edición está finalizada, cada taller se muestra «Finalizado». Solo visual; no cambia datos. Validado en Moodle 5.0.11 local.

## 2026-10-07 · local 1.5.155-alpha · «Borrar pruebas»: corrección de seguridad
- **Fallo (reportado por Julio)**: en `test_cleanup.php` la opción «Todas» venía marcada por defecto y el formulario de confirmación llevaba el ámbito calculado al cargar la página. Marcar una Edición sin pulsar «Ver qué se borrará» y confirmar borraba TODAS las Ediciones del curso.
- **Corrección**: parámetro `scope` explícito (`s<ID>` o `all`), sin valor por defecto; sin elección no se muestra el formulario de borrado. Al marcar una opción la página se recarga sola con ese ámbito, así la confirmación siempre coincide con lo marcado. El servidor comprueba que la Edición pertenece al curso. Borrar «Todas» exige escribir **BORRAR TODO**; una sola Edición, **BORRAR PRUEBAS**. El aviso rojo nombra lo que se va a borrar.
- **Validado** (Moodle 5.0.11 local, navegador): sin opción marcada no hay formulario de borrado; marcar Edición A → borra solo A, B intacta (grupos y actividades); «Todas» + BORRAR PRUEBAS → rechazado sin borrar.

## 2026-10-07 · local 1.5.156-alpha · Email profesor vincula también a profesores sin edición
- `bulk_workshops::teacher_candidates()`: la columna «Email profesor» busca entre todos los matriculados del curso con rol de arquetipo profesor (con o sin edición) o gestor, además de quien tenga `moodle/course:update`. Antes solo se buscaba entre profesores con edición, así que el profesor que importa la universidad sin permiso de edición no se encontraba. El vínculo es «Profesor HEE» (`local_ga_edition_teachers`); no se cambia ningún rol de Moodle. Alumnos nunca se vinculan.
- Validado (Moodle 5.0.11 local): profesor sin edición en mayúsculas → vinculado; dos correos con «;» → ambos; correo de alumno → aviso y sin vínculo; el profesor sin edición ve «Lista de clase» solo en sus talleres.

## 2026-10-07 · block_gestion_hee 1.0.28-alpha · Vistas por perfil e instrucciones para profesores
- El bloque ya no muestra el resumen de horas de alumno (0 h / 54 h) a quien no es alumno:
  - Administrador / Gestor HEE (`can_manage_globally`): «Panel de Gestión HEE» + instrucciones para profesores + instrucciones para alumnos.
  - Profesor HEE (talleres asignados): sus talleres vigentes/finalizados + ambas instrucciones.
  - Profesor del curso (rol arquetipo editingteacher/teacher/manager o `moodle/course:update`; en el Área personal, ese rol en cualquier curso) sin talleres asignados: aviso + ambas instrucciones.
  - Alumno: igual que antes (horas, instrucciones para alumnos, traspaso, validación Tipo B, portafolio). No ve las instrucciones para profesores.
- Nueva ventana «INSTRUCCIONES PARA PROFESORES» (común, Tipo A, Tipo B, al terminar, dónde ver tus talleres). Modal genérico `render_help_modal()`.
- Instrucciones para alumnos actualizadas: botón Apuntarme/Desapuntarme con recarga, contenido visible solo tras apuntarse, baja hasta el inicio del taller, solo «Presente» cuenta, carpeta de materiales, reflexión solo texto en línea, certificados y horas automáticos al terminar, Ediciones finalizadas desaparecen del curso pero se conservan en el portafolio, autoevaluación al llegar a 54 h.
- No se cambia ningún rol ni permiso de Moodle.
- Validado (Moodle 5.0.11 local, navegador): admin, Profesor HEE sin edición, profesor sin talleres y alumno ven cada uno su vista; ambas ventanas se abren.

## 2026-10-07 · local 1.5.157-alpha · Reconocimiento institucional: instrucciones y plantilla
- `institutional_import.php`: panel «Cómo debe ser el Excel» bajo la subida (formato libre por nombre de encabezado, hoja TODOS o la primera, columnas reconocidas, obligatoriedad, revisión previa, re-importar sustituye y no suma, Tipo B requiere comentario del alumno) y botón «Descargar plantilla de reconocimiento» (`action=template`, sesskey) que genera `Plantilla_Reconocimiento_Institucional.xlsx` (hoja TODOS: Apellidos, Nombre, Email, Curso, Grupo, Horas Tipo A, Horas Tipo B, Nota Tipo A + hoja INSTRUCCIONES).
- Validado (Moodle 5.0.11 local, navegador): descarga de la plantilla, relleno con email en mayúsculas y decimales con coma → «Encontrado» con 12,50 h A / 4,00 h B / nota 7,80; email inexistente → «No encontrado».

## 2026-10-07 · local 1.5.158-alpha · Texto corregido del reconocimiento institucional
- El texto decía que las horas Tipo B institucionales no cuentan hasta el comentario del alumno. Según `hours_calculator`, cuentan desde la importación; el comentario es requisito para completar el portafolio (nota de Portafolio). Corregido en la página, en las instrucciones y en la hoja INSTRUCCIONES de la plantilla. Añadido que la nota Tipo A importada es una nota más en la media de Talleres A.

## 2026-10-07 · local 1.5.159-alpha · Autoevaluación: no se puede elegir el cuestionario de un taller
- **Fallo (visto por Julio)**: en «6. Notas de alumnos» el selector ofrecía todos los cuestionarios del curso, incluidos los de taller. En su curso de pruebas quedó seleccionado «Cuestionario T-10» como autoevaluación → ese taller (cuestionario y subsección) quedaba oculto a sus alumnos hasta 54 h, y el botón «Crear» desaparecía porque ya había uno seleccionado.
- **Corrección**: `grade_manager::workshop_quiz_cmids()` identifica cuestionarios de taller (requiredcmid/requiredquizcmid de ediciones del curso o idnumber `HEE_EDITION_*`); el selector ya no los ofrece y `save_selfassessment_quiz()` los rechaza con mensaje claro. Si el seleccionado es de un taller: aviso rojo + botón «Crear cuestionario de autoevaluación HEE y usarlo en lugar del seleccionado» (`create_and_link(..., replace=true)`), que crea/recupera el HEE, lo vincula y quita la restricción de 54 h del taller (cuestionario y sección). El botón Crear es la opción principal; el selector pasa a «Opción avanzada».
- Validado (Moodle 5.0.11 local): guardar un cuestionario de taller → bloqueado; error antiguo reproducido → al pulsar el botón se vincula «Autoevaluación final HEE» y el taller vuelve a estar sin restricción; capturas de la pantalla antes/después.

## 2026-10-07 · local 1.5.160-alpha · block 1.0.29-alpha · demo 0.1.2 · Talleres de dos días
- **Plantillas A y B**: columnas opcionales «Fecha 2», «Inicio 2», «Fin 2» (vacías = taller de un día; «Inicio 2/Fin 2» vacías = mismo horario que el día 1). «Horas» = total de ambos días. Hoja de instrucciones con ejemplo de un día y de dos días. Validación: el día 2 posterior al día 1; el cierre del cuestionario posterior al final del último día.
- **Datos**: `local_ga_series_items.session2date` y `session2enddate` (upgrade 2026100575, no destructivo). Helpers `workshop_series::set_item_second_day()`, `item_for_edition()`, `second_day()`, `last_end()`, `schedule_parts()`.
- **Asistencia**: `attendance_sync::ensure_session()` crea una sesión por día («… · Día 1», «… · Día 2»), mantiene alineadas las no tomadas y borra la «Día 2» no tomada si el taller pasa a un día. `list_map()` exige «Presente» en TODAS las sesiones del taller (un día sin pasar lista = no presente todavía). Un solo cuestionario/reflexión y un solo certificado.
- **Fin del taller**: `auto_certificates::finish_time()` usa el final del último día (si no hay cierre de cuestionario/plazo de reflexión). La Edición se amplía para cubrir el día 2.
- **Vistas**: calendario del curso, tablas de Talleres A/B, panel y archivo muestran las dos fechas; previsualización de importación con distintivo «2 días»; «Lista de clase» con «Firma día 1» y «Firma día 2»; el certificado pone «fecha1 y fecha2»; formulario manual del taller con apartado «Taller de dos días (opcional)».
- **Corrección previa encontrada**: la columna «Cierre cuestionario» solo se aplicaba al duplicar un cuestionario modelo; en la importación normal el cuestionario quedaba sin cierre. Ahora se aplica siempre.
- **Panel**: tarjeta «Plantillas de talleres» con descarga de Plantilla_Talleres_A / _B y acceso a «Crear Edición desde Excel».
- **Bloque**: instrucciones de profesores y alumnos mencionan los talleres de dos días.
- **Demo**: el generador pasa lista en todas las sesiones (los ausentes faltan un día).
- **Validado (Moodle 5.0.11 local)**: `dos_dias_test.php` ALL OK (importación A 6 talleres/2 de dos días y B 4/1 de dos días desde las plantillas descargadas del panel; 2 sesiones con fechas y duración correctas; Inicio 2/Fin 2 distintos y vacíos; cierre del cuestionario aplicado; A 2 días: ambos días + 8 → sí, falta día 2 → no, día 2 sin pasar → no, ambos + 4 → no; A 1 día sin cambios; B 2 días: ambos + reflexión → sí, falta día 2 → no, corrección → sí; certificados al finalizar exactamente los esperados; fechas dobles en el certificado; horas 4 + 2 = 6). Regresión: hee_e2e 303 OK, typeb, cert, codes, tpl_fold ALL OK. Capturas de tabla, formulario y lista de clase.

## 2026-10-07 · local 1.5.161-alpha · «Nota Talleres A» solo con talleres superados
- Decisión de Julio: en la media de «Nota Talleres A» (60 % de la nota final) solo cuentan los talleres A superados: «Presente» todos los días del taller (o certificado ya emitido) y nota ≥ 5/10. Si asiste y suspende, o aprueba sin asistir, ese taller no cuenta. La nota institucional importada se mantiene como una nota más.
- `grade_manager::get_typea_average_map()` filtra las notas por `local_ga_edition_enrolments.attended` (activo) o certificado + nota ≥ 5. Textos del portafolio y del ítem del cuaderno actualizados.
- Validado (Moodle 5.0.11 local, `nota_a_test.php`): 8 (2 días, ambos) + 7 → 7,5; 8 con falta día 2 y 9 sin asistir → sin nota A; 8 con día 2 sin pasar → no cuenta; 4 con ambos días → no cuenta. Regresión hee_e2e y cert ALL OK.

## 2026-10-07 · local 1.5.162-alpha · Repaso de todas las tarjetas del panel
- Recorrido automático con depuración DEVELOPER activada (Playwright): todas las tarjetas del panel y sus subpáginas (~714 páginas y 23 descargas por curso, en el curso de la demo y en el de talleres de dos días), más alumno (portafolio, certificados, Tipo B, traspasos) y profesor (mis talleres).
- **Corregido**:
  - Tarjeta 8: texto con las columnas actuales del listado personalizado.
  - Tarjeta 6 (`grades_report.php`): la columna Grupo leía `$row->userid` (inexistente) y salía siempre vacía + aviso PHP → `$row->id`.
  - Tarjeta 6 «Descargar PDF» (`grades_export.php`): sin `$PAGE->set_context()`; con depuración, el aviso previo rompía el PDF (TCPDF «Some data has already been output»). Contexto fijado. Igual en `certificate_download.php`, `portfolio_package_download.php`, `portfolio_pdf_all.php`, `portfolio_pdf_download.php`, `typeb_download.php`, `export.php`.
  - Tarjeta 3 (`manager_downloads.php`): `fullname()` sobre filas con solo nombre/apellidos imprimía avisos dentro de los CSV → `local_ga_dl_name()`. **Fallo de datos**: la clave única de los listados de talleres A/B (`w.id-e.id-u.id`) quedaba vacía cuando `e.id` era NULL y Moodle descartaba filas «duplicadas» → `COALESCE(e.id,0)`.
- Verificado: todas las descargas CSV/ZIP limpias (sin avisos), ZIP certificados A 31 MB en 2 s, expedientes completos 61 MB; hee_e2e ALL OK; nota_a_test ALL OK.
- `myhours.php` es una página antigua sin enlaces (deniega a alumnos por capacidad); no se toca.

## 2026-10-09 · local 1.5.163-alpha · block 1.0.30-alpha · «Volver al curso» desde la barra lateral
- **Fallo (Julio)**: el botón «Panel de Gestión HEE» del bloque lateral abría `dashboard.php` sin `courseid`; «Volver al curso» usaba el último curso recordado en sesión (p. ej. el de la simulación) en vez del curso desde el que se entró.
- **Corrección**: el bloque pasa el `courseid` de la página actual; `dashboard.php`, si no recibe `courseid`, usa el curso de la página de procedencia (`get_local_referer`, /course/view.php?id=) antes que el recordado en sesión.
- Validado (Moodle 5.0.11 local, navegador): con otro curso recordado, entrar desde la barra lateral del curso 40 → vuelve al 40; «Más › Gestión HEE» desde el 43 → 43; subpágina + «Volver al panel» → sigue en 43 y aterriza en el curso 43.

## 2026-10-09 · local 1.5.164-alpha · block 1.0.31-alpha · Tipo B externo con catálogo de talleres
- **Petición de la universidad**: cada certificado Tipo B externo debe ir asociado a un taller de un catálogo gestionado por administración (nombre + horas); al validarse, esas horas suben a la ficha.
- **Datos** (upgrade 2026100579, no destructivo): tabla `local_ga_typeb_catalog` (name, hours, active, usermodified, timecreated, timemodified) y `local_ga_typeb_certs.catalogid` (0 = solicitudes antiguas de texto libre, que se conservan tal cual) + índice (userid, catalogid).
- **Catálogo** (`typeb_catalog.php`, clase `typeb_catalog`): solo Gestor HEE. Añadir, editar (nombre/horas; las solicitudes pendientes o rechazadas de ese taller se actualizan, las validadas conservan sus horas), retirar/reactivar (retirado = fuera del desplegable, solicitudes intactas), eliminar solo si no tiene solicitudes. Nombre único. Accesos: tarjeta 7 del panel («Catálogo de talleres B») y página de revisión.
- **Alumno** (`typeb_upload.php`, `typeb_upload_form`): desplegable de talleres activos + fecha + certificado (sin nombre, horas ni descripción libres; título y horas del catálogo). No puede presentar dos veces el mismo taller (salvo rechazado). «Editar» mientras esté pendiente o rechazada (taller, fecha y, opcionalmente, certificado) → vuelve a pendiente. Mensaje si el catálogo está vacío o ya presentó todos.
- **Validación**: sin cambios de flujo (gestor valida → alumno escribe reflexión → horas Tipo B). Al validar se toman nombre y horas actuales del catálogo.
- **Bloque**: instrucciones del alumno (Tipo B externo) actualizadas.
- **Validado (Moodle 5.0.11 local)**: `typeb_cat_test.php` ALL OK (20 comprobaciones: duplicados de nombre, horas 0, desplegable, título/horas del catálogo, sin repetir taller, editar cambiando taller/fecha conservando certificado, rechazo → editar → pendiente, cambio de horas del catálogo en pendientes, validada no editable, sin reflexión no suben horas, con reflexión suben, horas validadas congeladas, retirar, eliminar). Navegador: alta en el catálogo, envío con selector de archivos, desaparición del taller del desplegable, edición de fecha, validación por el gestor, reflexión → 6,5 h externas en la ficha. Regresión hee_e2e y typeb ALL OK; recorrido del panel 773 páginas/24 descargas sin errores.

## 2026-10-09 · local 1.5.165-alpha · block 1.0.32-alpha · «Volver al curso» del alumno
- **Fallo (Julio)**: en «Mi portafolio HEE», sin `courseid`, «Volver al curso» hacía `history.back()` y devolvía a la página anterior (p. ej. «Solicitar validación Tipo B») en vez de al curso. Los botones del bloque no pasaban el curso.
- **Corrección**: `manager::return_courseid()` + `manager::back_to_course_button()` (orden: ?courseid → página de curso de procedencia → curso recordado en sesión → último curso con Ediciones en el que está matriculado → «Volver a Mis cursos»). Usado en portfolio.php, typeb_upload.php (añadido «Volver al curso» junto a «Volver al portafolio») y transfer_typeb.php. El bloque pasa `courseid` a portafolio, Tipo B y traspasos. Revisados el resto de «Volver al curso» (dashboard, teacher_view, workshop_view, authorized_users): ya usaban el curso correcto.
- Validado (navegador, alumno): bloque → portafolio → Tipo B → «Volver al portafolio» → «Volver al curso» aterriza en el curso; Tipo B y Traspasos → curso; sesión nueva entrando directo al portafolio → curso del alumno.

## 2026-10-09 · local 1.5.166-alpha · Panel simplificado
- Petición de Julio: panel menos laberíntico para varios gestores, conservando todas las opciones.
- `dashboard.php`: las 8 tarjetas numeradas + 3 de talleres + plantillas se sustituyen por:
  - Franja «Pendiente» (solo si hay algo): solicitudes Tipo B externo por validar; talleres ya celebrados con alumnado y alguna sesión de asistencia sin pasar (enlaces a «Alumnos / asistencia», máx. 5); Ediciones con fecha de fin pasada sin finalizar.
  - 4 bloques por tarea: 🗓️ Talleres (Tipo A, Tipo B, Crear Edición desde Excel, Plantilla A/B, Ediciones anteriores) · 🎓 Alumnos y horas (Validar Tipo B externo con contador, Catálogo de talleres B, Importar reconocimiento institucional, Ver traspasos A→B, Alumnos y ranking) · 📊 Notas e informes (Notas de la asignatura, Listado personalizado, Listados y descargas) · ⚙️ Configuración plegada (Usuarios autorizados y Profesores HEE, Portada del portafolio PDF, Limpieza de pruebas).
  - Sin numeración; la «Vista general de talleres» no cambia. Ninguna página interna cambia.
- Validado (navegador): la franja detecta los tres casos con datos preparados; los 17 botones abren sin errores (plantillas descargan XLSX).

## 2026-10-09 · block 1.0.33-alpha · Sin subtítulos duplicados
- El bloque mostraba «Gestión HEE» (título) y debajo «Gestor HEE» / «Profesor HEE» / «Profesorado». Eliminados esos subtítulos; queda solo el título del bloque. Validado con administrador, Profesor HEE, profesor sin talleres y alumno.

## 2026-10-09 · local 1.5.167-alpha · Traspasos y retorno tras validar
- Panel «Ver traspasos A→B» enlazaba `manager_downloads.php?view=view_transfers` (parámetro inexistente) y mostraba la portada de «Listados y descargas» → `?action=view_transfers` (abre el listado «Traspasos Tipo A a Tipo B»).
- `typeb_review.php`: tras Validar/Rechazar redirigía siempre a «Listados y descargas» con el mensaje «Taller B antiguo confirmado». Ahora vuelve a la página de origen (lista de solicitudes con su filtro; o el listado de «Listados y descargas» si se validó desde allí) con mensajes claros.
- Validado en navegador.

## 2026-10-09 · local 1.5.168-alpha · Tablas: aviso de ordenar y filtrar
- `local_gestion_actividades_enable_interactive_tables()` (todas las tablas interactivas: notas, listados, talleres…): línea «Consejo: pulsa el nombre de una columna (⇅) para ordenar…; escribe en «Filtrar este listado…»…» bajo el buscador, y cada encabezado ordenable muestra ⇅ (▲/▼ en la columna activa; el resto vuelve a ⇅). Validado en navegador, sin errores JS.

## 2026-10-09 · local 1.5.169-alpha
- Panel, bloque Configuración: texto «Recomendable solo para administradores: permisos y herramientas de mantenimiento.» (antes «Se usa poco…»).

## 2026-10-09 · local 1.5.170-alpha · Quitado «Sincronizar» (decisión de Julio)
- Botón «Sincronizar» (edition_sync.php: borraba las inscripciones del taller y las recreaba desde el grupo Moodle; herencia de cuando el grupo era la fuente) retirado de la tabla del panel y de editions.php. La página sigue existiendo sin acceso directo. Los inscritos se gestionan en «Alumnos / asistencia».

## 2026-10-09 · local 1.5.171-alpha
- Panel: caja «Configuración» en rojo pastel (fondo #fdecec, borde #f1b5b5, texto granate) para señalar herramientas delicadas; botón «Mostrar / ocultar opciones» legible abierto y cerrado.

## 2026-10-09 · local 1.5.172-alpha · Archivo de talleres plegado
- `archive.php`: cada Edición finalizada es un desplegable cerrado con su nombre, distintivo Tipo A/B, fechas, nº de talleres y curso («Abrir en el curso» en la cabecera). Orden: fecha de inicio más reciente primero y, en la misma fecha, Tipo A antes que Tipo B. Validado en navegador (15 Ediciones, todas plegadas; al pulsar muestra sus talleres).

## 2026-10-09 — local 1.5.173-alpha (2026100588): aviso de ordenación en todas las tablas
- Revisadas todas las páginas con tablas ordenables/filtrables: todas usan `local_gestion_actividades_enable_interactive_tables()`, que muestra «Consejo: pulsa el nombre de una columna (⇅)…» y la marca ⇅ en cabeceras.
- Añadido a las dos páginas que no lo tenían: `workshop_report.php` (Listado personalizado) y `typeb_catalog.php` (Catálogo Tipo B).
- Validación local (Moodle 5.0.11, Playwright): 23 páginas del panel, cada tabla ordenable con aviso y ⇅, sin errores. No validado en producción.

## 2026-10-09 — local 1.5.174-alpha (2026100589): orden en «Notas e informes»
- Panel: botones invertidos a petición de Julio → «Listados y descargas», «Listado personalizado de talleres», «Notas de la asignatura». Validado en local.

## 2026-10-09 — local 1.5.175-alpha (2026100590): curso académico en listados + colores de botones + orden «Alumnos y horas»
- **Curso académico** (formato `2026/2027`, cambia solo el 1 de septiembre): nueva clase `classes/local/academic_year.php`.
  - `local_ga_workshop_series.academicyear` (upgrade 2026100590, no destructivo: añade la columna y la rellena desde la fecha de inicio de cada Edición). Editable en «Editar edición» (opción «Automático según la fecha de inicio»).
  - Resto de registros: curso de su fecha (Tipo B externo y traspasos: fecha de solicitud; reconocimiento institucional: fecha de importación; certificados: Edición o fecha de emisión).
  - Selector «Curso académico» (parámetro `cursoac`, `all` = todos) en: Listados y descargas (portada + 7 listados + CSV/ZIP, con sufijo del curso en el nombre del CSV), Listado personalizado, Horas por alumno, Validar Tipo B (por defecto todos, para no ocultar pendientes), Talleres A/B (por defecto todos) y Ediciones anteriores (agrupadas por curso).
  - Columna «Curso académico» en los listados; en horas y portafolios, «Cursos con actividad». **Las horas siguen siendo acumuladas de toda la carrera**: el filtro solo selecciona alumnos con actividad en ese curso. No cambia ningún cálculo.
  - Panel: aviso «Curso académico actual: 2026/2027».
  - «Notas de la asignatura» no se filtra: ya va por curso Moodle.
- Corregido: el CSV de portafolios usaba `$hourssummarybyuser` antes de calcularlo (horas a 0 en el CSV).
- Botones «Volver al panel» / «Volver a listados» en gris tenue (`.local-ga-back-panel`) y «Volver al curso» en naranja tenue (`.local-ga-back-course`), en styles.css.
- Panel, bloque «Alumnos y horas»: orden Alumnos y ranking, Importar reconocimiento, Catálogo B, Validar Tipo B, Ver traspasos.
- Validación local (Moodle 5.0.11 + Playwright):
  - Edición puesta en 2025/2026 desde el formulario; los listados filtran correctamente (por ejemplo, certificados A: 131 / 2 / 133).
  - El CSV sale filtrado y con la columna; sin errores PHP ni JS.
  - Suites hee_e2e, cert, typeb_cat: todas OK.
  - dos_dias: 1 fallo, debido a horas acumuladas del usuario de prueba en ejecuciones previas; no está relacionado con este cambio.
  - No validado en producción.

## 2026-10-09 — local 1.5.176-alpha (2026100591): «Buscar alumno (historial completo)»
- Nueva página `student_search.php`, de solo lectura y solo para el gestor. Botón en el panel, bloque «Alumnos y horas», en primer lugar.
- Búsqueda por nombre, apellidos, correo, DNI/ID o usuario (varias palabras, máximo 100 resultados). Si hay un único resultado, abre su ficha directamente.
- La ficha muestra:
  - Datos del alumno, grupo y cursos con actividad.
  - Horas A/B, total y pendiente hasta 54 (cálculo canónico).
  - Enlaces a portafolio, PDF, ZIP y perfil.
  - Tablas de todos los talleres en que se inscribió (incluidas las bajas): curso académico, Edición, tipo, fecha, horas, inscripción, asistencia, calificación o actividad, resultado y certificado.
  - Tablas de Tipo B externo, traspasos y reconocimiento institucional.
- Diferencia con lo existente: el buscador de «Validar Tipo B externo» solo muestra certificados A y solicitudes B. Esta ficha reúne todo el historial.
- Validación local con Playwright (búsqueda múltiple, resultado único, sin resultados; ficha de dd1 con 12 talleres): sin errores.

## 2026-10-11 — local 1.5.177-alpha (2026100592): contexto seguro + edición de alumno + Tipo B PAT
- **Contexto seguro**: Talleres A/B, Archivo y Portafolio gestor abren por defecto en el curso académico actual; Edición de talleres e importación Excel preseleccionan el curso Moodle desde el que se abrió Gestión HEE mediante `$SESSION->local_ga_return_courseid`. Se puede cambiar manualmente.
- **Portada PDF**: editor HTML a la izquierda y previsualización en vivo a la derecha, con datos ficticios para las variables `{alumno}`, `{curso}`, `{horas_tipo_a}`, `{horas_tipo_b}`, `{horas_total}` y `{fecha_emision}`.
- **Buscar alumno**: sigue siendo de consulta por defecto y añade botones de edición que abren los controles canónicos de asistencia y actividad/calificación de la edición, evitando edición accidental inline.
- **Tipo B PAT**: nuevo botón **Importar talleres Tipo B PAT** en el panel. Acepta directamente el XLSX nativo exportado por la plataforma (Nombre, Apellido(s), Número de ID, Departamento/DNI, correo, columnas `Cuestionario:… (Real)` y una columna `Asistencia:… (Real)`).
- El importador detecta automáticamente todos los cuestionarios PAT, aplica la asistencia común a esos talleres y solicita al gestor las horas a reconocer por cada taller porque el XLSX exportado no contiene horas.
- Regla PAT: **asistencia = 100 + nota de cuestionario >= 5**. Solo entonces las horas se incorporan como Tipo B al cálculo canónico.
- Los alumnos pueden importarse aunque todavía no exista su cuenta Moodle. Se conserva identidad (Número ID, DNI/Departamento, correo, nombre y apellidos) y se vincula por identificador institucional/DNI o correo cuando la cuenta aparezca posteriormente. La reconciliación se ejecuta al calcular/consultar horas del usuario.
- Nueva tabla no destructiva `local_ga_typeb_pat`; upgrade `2026100592`. Reimportar el mismo alumno+taller+curso actualiza el registro en lugar de duplicarlo.
- Los registros PAT aparecen en **Buscar alumno**, en el portafolio del alumno y en el PDF del portafolio; sus horas se integran en `hours_calculator` y por tanto en bloque, totales, notas y desbloqueo de las 54 h.

- Validación final: `php -l` correcto en todos los PHP de `gestion_actividades` y `gestion_hee`; `install.xml` parseado correctamente. ZIPs generados desde el mismo HEAD. El bloque no requiere cambios funcionales y se mantiene en **1.0.33-alpha (2026100517)**.

## 2026-10-11 — local 1.5.178-alpha (2026100593) + block 1.0.34-alpha (2026100518): manuales, paneles laterales y contexto de alumno
- **Panel «Alumnos y horas»**: «Importar talleres Tipo B PAT» pasa al último lugar del bloque.
- **Buscar alumno → editar**: los botones de Asistencia y Actividad/Calificación conservan el alumno seleccionado. Las pantallas de destino se filtran a ese alumno y muestran «Volver al historial de …», evitando perderlo entre todos los inscritos.
- **Profesor HEE**: «Talleres vigentes» / «Mis talleres finalizados» conservan el curso Moodle de origen; desde el listado se vuelve al curso correcto.
- **Manual de alumnos (ES/EN)**: actualizado con una sección específica de **Tipo B PAT de 1.º**: vinculación histórica automática, asistencia completa + nota >=5, sin reflexión ni certificado a subir por el alumno.
- **Manual de profesores (ES/EN)**: actualizado para aclarar que los talleres finalizados siguen siendo revisables por Profesor HEE/Gestor para correcciones académicas; asistencia se corrige en Attendance, la calificación Tipo A en cuestionario/tarea histórica, y Tipo B no tiene nota numérica pero admite prórroga individual de reflexión. PAT queda fuera de la gestión del Profesor HEE.
- **Panel lateral de alumno**: no añade un botón PAT (es una importación de gestor), pero sus horas PAT ya entran en el cálculo canónico Tipo B y la caché del bloque se invalida al importar registros vinculados, por lo que el resumen se actualiza.
- **Panel lateral de profesor**: conserva el acceso a vigentes/finalizados y ahora mantiene el contexto del curso; las instrucciones reflejan el modelo actual.
- **Correcciones en talleres activos/finalizados**: la finalización oculta el taller al alumnado pero no convierte la ficha docente en solo lectura. Se mantienen las correcciones académicas según el tipo; tras cambios que afecten elegibilidad debe revisarse/regenerarse el certificado cuando corresponda.
- Validación final: `php -l` correcto en todos los PHP de ambos plugins y `gestion_actividades/db/install.xml` parseado correctamente. ZIPs generados desde el mismo HEAD funcional.

## 2026-10-11 — local 1.5.179-alpha (2026100594): auditoría de 1.5.177/1.5.178 (Claude)
Auditados los cambios de 1.5.177/1.5.178 desplegándolos en Moodle 5.0.11 local; antes solo se habían validado con `php -l`.

Correcciones:
- **Importador PAT, campo de horas**: `min=0.01 step=0.25` hacía que el navegador rechazara 1,5, 2 o 3 h (solo admitía 0,26, 0,51…). No se podía confirmar ninguna importación con horas habituales. Ahora `min=0.25 step=0.25`.
- **Vinculación PAT por correo**:
  - Era sensible a mayúsculas: un usuario Moodle con «Dd4@ucv.test» no se vinculaba con «dd4@ucv.test» del Excel, y sus horas no contaban.
  - Ahora la comparación ignora mayúsculas y espacios, tanto en la importación como en la vinculación posterior (correo, Número de ID o DNI).
  - Si hay varias cuentas candidatas, no se vincula a ninguna, en lugar de tomar una al azar.
- **Curso académico de PAT**: se calculaba con la fecha de importación (`timemodified`) en lugar del curso elegido al importar. Ahora usa `local_ga_typeb_pat.academicyear`.
- **Validar Tipo B «Pendientes»**:
  - Con el filtro por defecto en el curso actual, en septiembre se ocultaban solicitudes pendientes de agosto, aunque el contador del panel las contaba.
  - Con `status=pending`, la lista muestra ahora todos los cursos por defecto.
  - Además, aviso si quedan pendientes ocultos por el filtro.
- **Talleres A/B**: aviso con enlace cuando hay Ediciones de ese tipo en otros cursos académicos ocultas por el filtro (por ejemplo, las del curso siguiente preparadas en julio).
- **Portafolio del alumno y PDF**: la sección PAT solo aparece si el alumno tiene registros PAT. Antes mostraba «No constan…» a todos.
- **install.xml**: quitados `DEFAULT=""` en campos CHAR NOT NULL. Provocaban avisos de XMLDB en la comprobación de esquema. Sin cambios de datos.

Validación local:
- `pat_test.php` (13 comprobaciones: importación, vinculación por ID, correo y mayúsculas, regla asistencia 100 + nota ≥ 5, horas, reimportación sin duplicados, alumno creado después) ALL OK.
- Playwright:
  - Importación PAT desde la interfaz.
  - Buscar alumno con PAT y botones Editar filtrados con «Volver al historial».
  - 12 páginas tocadas sin errores; vista previa de portada; PDF generado.
  - Portafolio del alumno.
  - Bloque de profesor con `courseid`.
- hee_e2e, typeb_cat y cert: ALL OK.
- `check_database_schema` sin diferencias.

Pendiente de confirmar con datos reales: que la columna «Asistencia: … (Real)» del Excel de la plataforma sea un porcentaje 0–100. Si es una nota sobre otra escala, la regla «= 100» no aprobaría a nadie.

## 2026-10-11 — local 1.5.180-alpha (2026100595) + block 1.0.35-alpha (2026100519): anular importaciones PAT y escala del cuestionario
- **Anular importación PAT**:
  - Nueva tabla `local_ga_typeb_pat_imports` (lotes) y campo `local_ga_typeb_pat.importid`.
  - En «Importar talleres Tipo B PAT», la lista «Importaciones realizadas» muestra fecha, archivo, curso, talleres, alumnos y registros (aptos), con el botón «Anular importación» (con confirmación).
  - Anular borra los registros PAT de ese lote y recalcula las horas, invalidando la caché del bloque. Si un alumno y taller se reimportó después en otro lote, se conserva el posterior.
  - Upgrade 2026100595, no destructivo: las importaciones hechas con 1.5.177/178 se agrupan en un lote por archivo y curso, para poder anularlas también.
- **Escala del cuestionario**:
  - El Excel real de la plataforma (Tutorización 1C) tiene las notas sobre 5: máximo 5,0, y 45 de 56 alumnos sacan exactamente 5.
  - Nuevo campo por taller «Nota máxima del cuestionario» (por defecto 10). Aviso si la nota más alta del archivo es ≤ 5. Se guarda en `grademax`.
  - Regla: asistencia 100 y nota ≥ la mitad de la máxima (5/10, 2,5/5).
  - Las notas se muestran «x / máx» en Buscar alumno, portafolio y PDF. Textos de ayuda ES/EN actualizados.
- Formato del Excel real verificado: «Cuestionario:… (Real)» sin espacio tras los dos puntos; asistencia 0/50/75/100; Número de ID y Departamento (DNI) siempre presentes. El importador lo lee correctamente.
- Validación local:
  - Importación del Excel real desde la interfaz (curso 2025/2026, máximo 5, 2 h por taller): 112 registros, 90 aptos.
  - Anulación desde la interfaz: 112 eliminados.
  - Anulación de un lote con alumnos vinculados: sus horas Tipo B bajan de 5 a 0.
  - pat_test, hee_e2e, cert y typeb_cat: ALL OK. Esquema sin diferencias.
  - Los datos reales solo se usaron en el Moodle de prueba local, se anularon y la copia del Excel se borró.

## 2026-10-11 — local 1.5.181-alpha (2026100596): PAT en el portafolio como los Tipo B
- Julio confirma que el cuestionario PAT es sobre 5. El importador propone 5 como nota máxima por defecto y avisa si hay notas por encima de 5.
- En el portafolio del alumno y en su PDF, los talleres PAT se presentan igual que los Tipo B: solo los que cuentan (aptos). Columnas: Curso académico, Taller, Horas, Asistencia «Confirmada», «Cuestionario (en lugar de reflexión)» «Superado» y Resultado «Apto». Ya no se muestran porcentaje ni nota.
- «Buscar alumno» (gestor) sigue mostrando el detalle completo: asistencia, nota «x / 5» y apto o no apto.
- Validado en local: página del portafolio del alumno y texto del PDF.

## 2026-10-11 — block 1.0.36-alpha (2026100520): ayuda PAT para alumnos
- Ayuda del alumno (ES/EN), sección PAT: en el portafolio los PAT aparecen como los demás Tipo B (asistencia confirmada y cuestionario superado en lugar de la reflexión); solo suman horas y su nota no entra en la calificación (A 60 % + portafolio 30 % + autoevaluación 10 %).
- ZIPs finales entregados: local 1.5.181-alpha y block 1.0.36-alpha, generados desde el mismo HEAD.

## 2026-10-11 — local 1.5.182-alpha (2026100597) + block 1.0.37-alpha (2026100521): requisitos del revisor de plugins
Informe recibido del revisor externo (reviewer.learningsystemsstudio.com) sobre `gestion_hee`, de hace una semana: 2 BLOCKER, 16 MEDIUM y 3 LOW. Ese servicio web no se puede ejecutar desde esta sesión. Se ha usado en su lugar el comprobador oficial de Moodle (moodlehq/moodle-cs, phpcs 3.13.4, estándares `moodle` y `moodle-extra`), que aplica las mismas reglas del checklist del Plugins Directory.

- **BLOCKER Privacy API**: el bloque ya tenía `classes/privacy/provider.php` (null_provider) y la cadena `privacy:metadata` en ES/EN; el informe es anterior. El plugin local tiene su provider completo.
- **BLOCKER LICENSE**: añadido `LICENSE` (GPL v3, el mismo texto que Moodle) en la raíz de `gestion_hee`, `gestion_actividades` y `hee_demo`.
- **MEDIUM boilerplate**: cabecera GPL de Moodle y docblock de archivo (`@package`, `@copyright 2026 Julio Martín Ruiz`, `@license GPL v3 or later`) en los 124 PHP y AMD src de ambos plugins.
- **MEDIUM cadenas fijas** (bloque): 10 textos pasan a cadenas de idioma ES/EN (`requesttypeb`, `managerpanel`, `activeworkshops`, etc.).
- **LOW comentarios en español** (bloque): traducidos al inglés, igual que los mensajes de depuración.
- **Bloque**: 0 errores y 0 avisos con `moodle-extra`. Además: docblocks de todas las funciones, clases y constantes; líneas de más de 132 caracteres partidas; `MOODLE_INTERNAL` innecesarios quitados; catch vacío con `debugging()`.
- **Plugin local**: correcciones automáticas de estilo con phpcbf (espacios, llaves, comas; sin cambios de lógica). Quedan 842 errores y 684 avisos no bloqueantes: docblocks de funciones, líneas de más de 180 caracteres, catch vacíos y textos en español sin cadenas de idioma.
- Validación local: lint de todos los PHP; hee_e2e, cert, typeb_cat y pat_test ALL OK (dos_dias: el fallo conocido de horas acumuladas del usuario de prueba); recorrido Playwright de 1.160 páginas y 24 descargas sin errores; bloque correcto para gestor, profesor y alumno; auditoría PAT/Buscar alumno OK.
