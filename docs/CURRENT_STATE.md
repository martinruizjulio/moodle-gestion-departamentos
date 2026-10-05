# CURRENT_STATE — Moodle Gestión de Departamentos

Última consolidación: 2026-10-05.

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
- Al finalizar una Edición de seminarios, la sección completa se oculta y la jerarquía HEE la coloca en el histórico inferior.
- Al reabrirla vuelve a la zona activa correspondiente A o B.

## Tipo A

- Arquitectura: Edición Tipo A → calendario → subsecciones de seminario → grupo/inscripción.
- Puede contener asistencia, apuntes y actividad obligatoria.
- La actividad obligatoria puede ser tarea interna HEE o cuestionario Moodle.
- Tarea interna: asistencia + entrega + nota mínima **5/10**.
- Cuestionario Moodle: asistencia + cuestionario finalizado + nota mínima **5/10**, reescalada según la nota máxima real del cuestionario.
- `teacher_view.php` distingue falta de asistencia, cuestionario no finalizado, pendiente de calificar, nota insuficiente y Apto.

## Tipo B interno

- Misma arquitectura visual que Tipo A, pero en su sección padre Tipo B.
- Actividad final: **Tarea Moodle de reflexión**, sin nota numérica, con texto en línea y archivo opcional.
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

## Bloque lateral Gestión HEE

- Usa el cálculo único de horas.
- Tipo B externo exige validación + reflexión.
- Los traspasos no cambian las horas totales.
- Los contadores de Profesor HEE y `my_workshops.php` comparten criterio de vigente/finalizado.
- Una Edición de seminarios padre finalizada mueve sus seminarios a finalizados aunque las ediciones hijas conserven datos históricos.

## Seguridad y autenticación

- Acciones mutantes revisadas con `sesskey`/CSRF.
- `pluginfile` valida contexto, propietario, edición y visibilidad.
- Certificados legacy sin `editionid`: propietario o Gestor HEE global.
- Exportaciones con datos personales/notas restringidas a gestión global.
- Gestión HEE **no crea cuentas Moodle `auth=manual`**.
- Las importaciones trabajan con cuentas institucionales ya existentes.
- No se modifica SSO, autenticación UCV/Microsoft ni roles institucionales.

## Base de datos / upgrades

- `db/install.xml` representa la instalación limpia actual.
- `db/upgrade.php` contiene migración formal no destructiva hasta el savepoint **2026100516**.
- La migración 2026100513 añadió el vínculo explícito `seriesid`.
- La migración 2026100516 reconcilia campos/tablas/índices que falten sin eliminar datos.
- El DDL se mantiene en instalación/upgrade, no en páginas de ejecución.
- La jerarquía A/B y la ordenación del curso introducidas ahora **no requieren cambio de esquema**.

## Privacidad

- `classes/privacy/provider.php` implementa metadata provider, request provider y `core_userlist_provider`.
- Exporta inscripciones/asistencia, certificados, Tipo B, reflexiones, traspasos, entregas, horas institucionales e historial aplicable, incluyendo evidencias asociadas.
- No se borran automáticamente datos académicos hasta que la Universidad determine su política de conservación/borrado.

## Validaciones realizadas anteriormente

Las rondas anteriores de ChatGPT/Claude dejaron comprobados estáticamente PHP/XML y corrigieron, entre otros, permisos por edición, rutas de upgrade, DDL en ejecución, creación `auth=manual`, Bootstrap 5, observers, filepicker Tipo B, reflexión canónica y cálculo único de horas.

La nueva jerarquía A/B de `course_layout` y sus integraciones se ha revisado contra el código actual, pero **todavía necesita la prueba funcional en Moodle 5 real**. No declarar compatibilidad de producción solo por revisión estática.

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
- Importar Excel solo A y solo B; un Excel mixto debe rechazarse antes de crear la sección.
- Finalizar/reabrir ediciones y comprobar movimiento/visibilidad real con `mod_subsection`.
- Comprobar que el calendario sigue siendo la primera subsección de cada edición.
- Verificar con 53,9 h que no aparece autoevaluación y con 54 h que aparece.
- Verificar cuestionario auto-generado y cuestionario existente seleccionado manualmente.
- Tipo A: tareas/cuestionarios, nota mínima y certificados.
- Tipo B interno: reflexión texto/archivo, eliminación/reenvío, prórroga y certificado.
- Tipo B externo: subida → validación → reflexión → horas.
- Profesor HEE real UCV sin permiso de edición general.
- Exportación de privacidad.
- Revisión externa final del ZIP con el servicio de Plugin Reviewer solicitado por la Universidad.

## Versiones actuales

- `local_gestion_actividades`: **1.5.108-alpha** (`2026100523`). Último savepoint de esquema: **2026100516**.
- `block_gestion_hee`: **1.0.22-alpha** (`2026100506`).
