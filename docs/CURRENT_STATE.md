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

### Listado personalizado de talleres
- Añadido `gestion_actividades/workshop_report.php`.
- El gestor puede seleccionar una edición, varias o todas las ediciones disponibles.
- El resultado presenta por alumno: **Taller | Edición | Alumno | Horas | Nota de tarea | Asistencia**.
- Para Talleres Tipo B, la nota de tarea se muestra como `-` porque no corresponde tarea calificable Tipo A.
- La asistencia se expresa como `Presente`, `Ausente` o `Sin registrar`.
- El resultado puede descargarse como CSV separado por punto y coma y compatible con Excel.
- El panel de gestión incluye acceso directo **Listado personalizado de talleres**.

## Verificación realizada
- Revisados `AGENTS.md`, `CURRENT_STATE.md`, HEAD y commits recientes antes de modificar.
- Confirmado que `local_ga_typeb_certs`, `portfolio_typeb::create_upload()`, `portfolio_typeb::set_status()` y el cómputo de horas validadas ya existían en el código, por lo que no se ha creado una segunda estructura paralela.
- Confirmado que las horas de certificados Tipo B externos solo se suman cuando `status = validated`.
- No se ha realizado todavía una prueba funcional en un servidor Moodle real ni una instalación/actualización del plugin. No declarar producción validada.

## Pendientes para siguientes cambios
- Seguir acumulando los cambios solicitados por Julio antes de preparar una descarga o paquete final.
- Revisar al cierre si los certificados Tipo B externos validados deben aparecer también como documento explícito dentro del PDF final del portafolio, además de computar sus horas y estar disponibles en el expediente ZIP.
- Hacer auditoría final de navegación, permisos, listados/exportaciones y consistencia de versiones antes de generar el paquete instalable.
