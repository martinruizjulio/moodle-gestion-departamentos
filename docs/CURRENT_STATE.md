# CURRENT_STATE — Moodle Gestión de Departamentos

Última consolidación: 2026-10-06.

Repositorio canónico: `martinruizjulio/moodle-gestion-departamentos`. GitHub es la fuente de verdad. Este repositorio corresponde exclusivamente a Moodle Gestión de Departamentos / Gestión HEE.

## Jerarquía Moodle acordada para el alumnado

La presentación HEE del curso queda fijada en este orden:

1. **Seminarios Tipo A**
   - primera subsección: **Calendario y resumen de seminarios** con la tabla HTML generada desde los datos canónicos;
   - después: **Seminario 01**, **Seminario 02**, etc., cada uno como subsección Moodle cuando `mod_subsection` está disponible.
2. **Seminarios Tipo B**
   - primera subsección: **Calendario y resumen de seminarios**;
   - después: **Seminario 01**, **Seminario 02**, etc.
3. **Autoevaluación final HEE**
   - sección independiente;
   - el alumnado no debe verla hasta alcanzar **54 horas reconocidas**.
4. **Ediciones anteriores de seminarios**
   - se conservan debajo de lo anterior;
   - permanecen ocultas al alumnado;
   - se mantienen para histórico, gestión, certificados y trazabilidad.

`classes/local/course_layout.php` centraliza esta jerarquía visual. Solo reordena las secciones propiedad de HEE y no reorganiza contenido ajeno al sistema HEE.

### Separación obligatoria Tipo A / Tipo B

- Una Edición de seminarios es de **un único tipo**: A o B.
- No se permite añadir manualmente un seminario B a una edición A ni un A a una edición B.
- La importación Excel rechaza una plantilla que mezcle filas A y B antes de crear la sección padre; deben hacerse dos importaciones.
- Una edición vacía adquiere su tipo al añadir el primer seminario.
- Una edición histórica heredada que ya contenga mezcla A/B no se modifica destructivamente; queda identificada como caso legacy y no admite nuevas mezclas.

Internamente se conservan los nombres históricos `workshop`, `workshop_series` y tablas `local_ga_*` para no romper compatibilidad. En la interfaz nueva se prioriza el término **seminario**.

## Ediciones de seminarios

- `classes/local/workshop_series.php` sigue siendo el modelo canónico de una edición: título, rango de fechas, sección padre, calendario y subsecciones.
- `local_ga_workshop_editions.seriesid` vincula explícitamente cada edición concreta con su Edición de seminarios; la resolución por fechas queda como respaldo legacy.
- Manual y Excel desembocan en los mismos objetos.
- Se impiden solapamientos de rango para reutilizaciones incompatibles del mismo seminario base.
- Las fechas de formularios/Excel se interpretan con la zona horaria del usuario mediante `date_helper`.
- Una Edición de seminarios se considera finalizada **solo cuando su estado se cambia expresamente a `finished`**; superar `dateto` no la finaliza ni la archiva automáticamente.
- Al finalizarla expresamente, la sección completa se oculta y la jerarquía HEE la coloca en el histórico inferior.
- Al reabrirla vuelve a la zona activa correspondiente A o B.

## Tipo A

- Arquitectura: Edición Tipo A → calendario → subsecciones de seminario → grupo/inscripción.
- **Actividad predeterminada para cualquier seminario Tipo A nuevo: Cuestionario Moodle.**
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
- La pantalla queda organizada como: resumen de horas → calificaciones HEE → Seminarios Tipo A → Seminarios Tipo B internos → Formación externa Tipo B → reconocimiento institucional → traspasos A→B → descargas PDF/ZIP.
- Tipo A ya no se describe como tarea por defecto: la interfaz indica que el **Cuestionario Moodle** es la actividad predeterminada de los seminarios nuevos, manteniendo compatibilidad con actividades históricas.
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
- `closed_full` significa lleno, no finalizado, por lo que permanece entre los seminarios vigentes.
- La caché del bloque se invalida al cerrar/reabrir series, archivar/borrar ediciones, reasignar docentes y cambiar reflexiones relevantes.
- `authorized_users.php` invalida explícitamente la caché docente al asignar o retirar un Profesor HEE, de modo que el bloque lateral refleja el cambio sin esperar al TTL.
- En la vista visual del seminario, Profesor HEE/Gestor HEE dispone de **Listado de apuntados**. Abre `edition_roster.php`, visible solo para quien puede gestionar esa edición concreta.
- El listado usa la misma fuente de inscritos que la gestión de asistencia, ordena por apellidos/nombre y ofrece una vista preparada para imprimir en papel con columnas de asistencia/firma y observaciones. No expone el listado al alumnado.

## Panel de Gestión HEE

- El panel mantiene sus opciones funcionales existentes; no se ha rediseñado ni reducido por el recuento histórico de “9 opciones”.
- Las descripciones visibles se han actualizado a la terminología y reglas actuales: **seminarios**, Tipo A con cuestionario predeterminado, Tipo B con reflexión Moodle, autoevaluación a 54 h, portafolio y validación externa Tipo B.
- La vista general del panel prioriza `seriesid` para resolver la edición concreta y usa las fechas solo como respaldo legacy.
- `closed_full` se interpreta como seminario completo pero todavía vigente, no como finalizado.
- `workshop_report.php` se ha actualizado para mostrar **Actividad / Estado actividad / Resultado / Asistencia**: cuestionario Moodle y nota reescalada en Tipo A, reflexión Moodle en Tipo B y compatibilidad con tareas Tipo A históricas.
- La nomenclatura visible del listado personalizado y del panel se ha cambiado a **seminario** sin renombrar internamente tablas/clases `workshop*`.
- `manager_downloads.php` se ha limpiado para el modelo actual: en Tipo A ya no muestra columnas heredadas de “tarea” como regla general, sino **Actividad / Estado-calificación / Resultado**. Los seminarios nuevos leen el Cuestionario Moodle real y las ediciones históricas conservan soporte para Tarea Moodle.
- Los listados/CSV administrativos usan la terminología **seminario**, **reflexión** y **Edición de seminarios**. El PDF del portafolio también usa la nomenclatura actual y el reconocimiento institucional Tipo A se presenta como **actividad evaluada**.
- La limpieza de informes no cambia el esquema de base de datos ni las reglas académicas; solo alinea lectura y presentación con las fuentes canónicas actuales.

## Bloque lateral Gestión HEE

- Usa el cálculo único de horas.
- Tipo B externo exige validación + reflexión.
- Los traspasos no cambian las horas totales.
- Los contadores de Profesor HEE y `my_workshops.php` comparten criterio de vigente/finalizado.
- Una Edición de seminarios padre finalizada mueve sus seminarios a finalizados aunque las ediciones hijas conserven datos históricos.
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
- `manager_downloads.php` evita asociar una edición concreta con series históricas distintas del mismo seminario base cuando existe `edition.seriesid`, en todos los listados que reutilizan esa unión;
- el ítem visible del libro de calificaciones y sus informes se denomina **Nota Seminarios A**;
- la prórroga de reflexión Tipo B exige una inscripción válida actual (`enrolled`, `attended` o `manual`);
- el acceso directo a una edición cerrada ya filtraba previamente estados de inscripción válidos en `workshop_view.php`; ese hallazgo preliminar queda descartado;
- `move_section_to()` se mantiene deliberadamente por compatibilidad y queda pendiente de validación/runtime Moodle 5 antes de migrar a una API distinta.

Esta auditoría es estática. Siguen siendo obligatorias las pruebas de runtime Moodle 5 UCV y el Plugin Reviewer antes de declarar el paquete listo para producción.

## Auditoría integral independiente de Claude — 2026-10-06

Punto de partida verificado: `e9ca208`, local 1.5.115-alpha (`2026100530`), bloque 1.0.24-alpha (`2026100508`). Correcciones (un commit por tema):

- **Bloqueante — creación de la sección de una Edición de seminarios**: `workshop_series::ensure_course_structure()` llamaba a `course_create_section($course, 0, true)`. Desde Moodle 4.4 (`sectionactions`) eso inserta una sección con número 0, que choca con la sección 0 del curso (índice único curso+sección): crear una edición nueva fallaba en Moodle 4.4/4.5/5.x. Ahora `course_create_section($course, 0)`.
- **Subsecciones (`mod_subsection`)**: Moodle mantiene las secciones delegadas al final. `course_layout`, el traslado de la autoevaluación, el generador de autoevaluación y el helper heredado de secciones usaban `MAX(section)` del curso entero, colocando o creando secciones HEE detrás de las delegadas. Ahora se usa la última sección regular (`course_layout::last_regular_section_number()`) y `course_create_section()` devuelve la sección real.
- **Seguridad — ficheros de alumnado**: `typeb_view.php` mostraba siempre en línea la evidencia Tipo B externa; las subidas anteriores al filepicker no se validaban en servidor (HTML/SVG → XSS almacenado contra gestores). Igual con las entregas internas vía `pluginfile`. Solo PDF/JPEG/PNG/GIF se muestran en línea; el resto se descarga.
- **Inscripción activa única**: `manager::is_active_enrolment()` / `active_enrolment_sql()` (= `enrolled`, `attended`, `manual`, más estado vacío legacy). Antes: plazas, tarjetas del curso y acceso a materiales ignoraban `manual`; una marca de asistencia antigua en un registro cancelado/rechazado seguía contando; el listado personalizado y los listados/CSV A/B incluían `blocked_repeat`, `over_places` o rechazados; volver a añadir a un alumno ya «attended» le devolvía a «enrolled» y una reincorporación manual repetida sumaba otra plaza.
- **Finalizar desde el formulario de la edición**: guardar con estado «Finalizada» no cerraba las reflexiones Tipo B ni refrescaba el bloque docente como el botón «Finalizar». Ahora comparten `apply_status_side_effects()`.
- **Resolución edición↔serie** en `dashboard.php`, `archive.php` y `workshops.php`: el respaldo por fechas (y el respaldo final a la edición principal) podía tomar una edición enlazada explícitamente a otra serie. Ahora solo se usan ediciones de esa serie o sin enlazar.
- **Bloque — modal de instrucciones**: el modal vivía dentro del cajón de bloques (con `transform`), por lo que quedaba recortado y tapado por su fondo. Se mueve a `<body>` al cargar y se añaden también los atributos de Bootstrap 4.
- **Terminología**: restos visibles de «Talleres A» → «Seminarios A» (nota final, exportación PDF de notas, informe de horas, portafolio del gestor, traspasos, importación institucional y strings de horas). No se tocan nombres internos ni la plantilla de certificado ya almacenada.

**Ampliación (petición de Julio):**
- **Baja de alumnos y plaza manual**: `edition_students.php` incorpora «Dar de baja» (POST + sesskey + confirmación) para Profesor HEE / Gestor HEE de la edición. `manager::unenrol_user_from_edition()` conserva el registro como `cancelled`, borra su asistencia, lo saca del grupo Moodle y actualiza el bloque; no permite la baja si ya tiene certificado. Las incorporaciones manuales que crean plaza extra se marcan (`reason = MANUAL_SEAT_REASON`) y, al darlas de baja, se resta esa plaza de forma atómica (nunca por debajo de 1). Las plazas manuales creadas antes de esta versión no llevan marca y no se restan.
- **Archivado de actividades**: el «hard archive» heredado sacaba la actividad de la secuencia de su sección; Moodle deja entonces de resolverla (sin acceso, sin revisión, sin enlace) y no la recupera al reconstruir la caché. Además se aplicaba a todo el curso, dejando inaccesibles los cuestionarios/reflexiones de otras ediciones activas al finalizar una edición o vincular una actividad, y la limpieza por nombre podía borrar apuntes de otras ediciones del mismo seminario base. Ahora: el contenido de una Edición de seminarios nunca se saca de la secuencia ni se borra por esas limpiezas; al finalizar una edición sus actividades se **ocultan y se mueven al final de su sección**; la reflexión Tipo B queda accesible (oculta en la página si el sitio permite actividades «stealth») para la prórroga individual; `move_workshop_modules()` repara módulos huérfanos de versiones anteriores. Las ediciones heredadas fuera de una serie mantienen el comportamiento anterior.
- Limitación: si se finaliza la Edición de seminarios completa, su sección queda oculta y el alumnado ya no puede abrir sus actividades, tampoco con prórroga.

`move_section_to()`: se mantiene. En Moodle 5.2 está deprecada (`#[deprecated]`, MDL-86862) y emite aviso de depuración, pero sigue funcionando como envoltorio de `core_courseformat\local\sectionactions::move_at()`; su retirada está prevista para Moodle 6.0 (MDL-87419). No se ha verificado que `move_at()` exista en Moodle 5.0/5.1, así que migrar ahora podría romper esas versiones: queda para cuando se fije la versión mínima.

Validación: `php -l` en todos los PHP, XML bien formado, sin métodos/funciones inexistentes del plugin, strings en/es completos en ambos plugins. APIs de Moodle contrastadas con el código fuente de Moodle (rama principal). **Sin ejecución en Moodle real.**

## Validaciones realizadas anteriormente

Las rondas anteriores de ChatGPT/Claude dejaron comprobados estáticamente PHP/XML y corrigieron, entre otros, permisos por edición, rutas de upgrade, DDL en ejecución, creación `auth=manual`, Bootstrap 5, observers, filepicker Tipo B, reflexión canónica y cálculo único de horas.

La nueva jerarquía A/B de `course_layout`, el listado imprimible, el modal de instrucciones, el valor predeterminado de cuestionario para Tipo A, la actualización del portafolio, el panel/listado personalizado y la limpieza de listados/descargas se han revisado contra el código actual, pero **todavía necesitan la prueba funcional en Moodle 5 real**. No declarar compatibilidad de producción solo por revisión estática.

## Pruebas Moodle 5 pendientes antes del ZIP final

- Instalación limpia y upgrade desde una instalación existente.
- Confirmar visualmente el orden exacto:
  1. sección activa Tipo A;
  2. calendario HTML A;
  3. subsecciones Seminario A;
  4. sección activa Tipo B;
  5. calendario HTML B;
  6. subsecciones Seminario B;
  7. Autoevaluación final HEE solo al alcanzar 54 h;
  8. ediciones anteriores ocultas debajo.
- Crear edición A manual e intentar añadir B: debe rechazarse; repetir a la inversa.
- Crear un seminario Tipo A manual nuevo y comprobar que queda configurado como **Cuestionario Moodle** por defecto; editar después sus datos y verificar que conserva el cuestionario.
- Importar Excel Tipo A con «Crear cuestionario = Sí» y comprobar duplicación/vínculo del modelo; importar otra fila Tipo A sin duplicarlo y verificar que la edición queda igualmente en modo `quiz`, pendiente de vincular/crear el cuestionario.
- Verificar que una edición histórica Tipo A que ya use tarea mantiene esa tarea al editar metadatos.
- Importar Excel solo A y solo B; un Excel mixto debe rechazarse antes de crear la sección.
- Finalizar/reabrir ediciones y comprobar movimiento/visibilidad real con `mod_subsection`.
- Comprobar que el calendario sigue siendo la primera subsección de cada edición.
- Verificar con 53,9 h que no aparece autoevaluación y con 54 h que aparece.
- Verificar cuestionario auto-generado y cuestionario existente seleccionado manualmente.
- Tipo A: cuestionario, nota mínima y certificados.
- Tipo B interno: reflexión texto/archivo, eliminación/reenvío, prórroga y certificado.
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

## Versiones actuales

- `local_gestion_actividades`: **1.5.117-alpha** (`2026100532`). Último savepoint de esquema: **2026100516**.
- `block_gestion_hee`: **1.0.25-alpha** (`2026100509`).
