<?php
// Library callbacks for Gestion_actividades.

defined('MOODLE_INTERNAL') || die();


function local_gestion_actividades_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if (!in_array($filearea, ['material', 'certificate', 'taskfile', 'tasksubmission'], true)) { return false; }
    if ($context->contextlevel != CONTEXT_COURSE) { return false; }
    require_login($course);
    global $USER, $DB;
    if (empty($args)) { return false; }
    $itemidpeek = (int)$args[0];
    $courseid = (int)$context->instanceid;

    if ($filearea === 'certificate') {
        $cert = $itemidpeek ? $DB->get_record('local_ga_certificates', ['id' => $itemidpeek], '*', IGNORE_MISSING) : false;
        if (!$cert || (int)$cert->courseid !== $courseid) { return false; }
        $canmanage = \local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)
            || (!empty($cert->editionid) && \local_gestion_actividades\local\manager::can_manage_edition((int)$cert->editionid, (int)$USER->id));
        if ((int)$cert->userid !== (int)$USER->id && !$canmanage) { return false; }
    }

    if ($filearea === 'material') {
        $mat = $itemidpeek ? $DB->get_record('local_ga_materials', ['fileitemid' => $itemidpeek], '*', IGNORE_MISSING) : false;
        if (!$mat) { return false; }
        $workshop = $DB->get_record('local_ga_workshops', ['id' => (int)$mat->workshopid], '*', IGNORE_MISSING);
        if (!$workshop || (int)$workshop->courseid !== $courseid) { return false; }
        $editionid = !empty($mat->editionid) ? (int)$mat->editionid : 0;
        if ($editionid > 0) {
            if (!\local_gestion_actividades\local\manager::user_can_access_workshop_resources($editionid, (int)$USER->id)) {
                return false;
            }
            if (empty($mat->visible) && !\local_gestion_actividades\local\manager::can_manage_edition($editionid, (int)$USER->id)) {
                return false;
            }
        } else if (empty($mat->visible) && !\local_gestion_actividades\local\manager::can_manage_workshop_instance((int)$workshop->id, (int)$USER->id)) {
            return false;
        } else if (!\local_gestion_actividades\local\manager::user_can_access_shared_workshop_materials((int)$workshop->id, (int)$USER->id)) {
            return false;
        }
    }

    if ($filearea === 'taskfile') {
        $edition = $DB->get_record('local_ga_workshop_editions', ['taskfileitemid' => $itemidpeek], '*', IGNORE_MULTIPLE);
        if (!$edition) { return false; }
        if ((int)$DB->get_field('local_ga_workshops', 'courseid', ['id' => (int)$edition->workshopid]) !== $courseid) { return false; }
        if (!\local_gestion_actividades\local\manager::can_manage_edition((int)$edition->id, (int)$USER->id)
                && !\local_gestion_actividades\local\manager::user_can_access_workshop_resources((int)$edition->id, (int)$USER->id)) {
            return false;
        }
    }

    if ($filearea === 'tasksubmission') {
        $submission = $DB->get_record('local_ga_task_submissions', ['fileitemid' => $itemidpeek], '*', IGNORE_MISSING);
        if (!$submission) { return false; }
        $edition = $DB->get_record('local_ga_workshop_editions', ['id' => (int)$submission->editionid], '*', IGNORE_MISSING);
        if (!$edition) { return false; }
        if ((int)$DB->get_field('local_ga_workshops', 'courseid', ['id' => (int)$edition->workshopid]) !== $courseid) { return false; }
        $canmanage = \local_gestion_actividades\local\manager::can_manage_edition((int)$edition->id, (int)$USER->id);
        if (!$canmanage && (int)$submission->userid !== (int)$USER->id) {
            return false;
        }
    }

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = empty($args) ? '/' : '/' . implode('/', $args) . '/';
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_gestion_actividades', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) { return false; }
    // Task submissions are uploaded by students: never render active content
    // (HTML/SVG/...) inline in the Moodle origin.
    if ($filearea === 'tasksubmission'
            && !in_array($file->get_mimetype(), ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'], true)) {
        $forcedownload = true;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}


/**
 * Return the student's academic enrolment group without per-row queries.
 */
function local_gestion_actividades_student_group(int $userid): string {
    global $DB;
    static $groups = null;
    if ($groups === null) {
        $groups = [];
        if ($DB->get_manager()->table_exists(new xmldb_table('local_ga_institutional_hours'))) {
            $records = $DB->get_records_sql("SELECT userid, groupname FROM {local_ga_institutional_hours} WHERE userid > 0");
            foreach ($records as $record) {
                $groups[(int)$record->userid] = trim((string)($record->groupname ?? ''));
            }
        }
    }
    $group = trim((string)($groups[$userid] ?? ''));
    return $group !== '' ? $group : '-';
}

/**
 * Add course navigation links so nobody has to remember plugin URLs.
 */
function local_gestion_actividades_extend_navigation_course(navigation_node $parentnode, stdClass $course, context_course $context): void {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // The section summary is shared HTML, but this callback runs for the current
    // course user. Pass the already-resolved states directly to a versioned AMD
    // module: no secondary AJAX request and no dependency on theme footer hooks.
    local_gestion_actividades_require_card_status_v2((int)$course->id);

    try {
        $canmanage = \local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id);
    } catch (Throwable $e) {
        $canmanage = false;
    }

    if (!$canmanage) {
        // Security: the course "Más" menu must not expose Gestión HEE to ordinary teachers or students.
        return;
    }

    $node = $parentnode->add(
        'Gestión HEE',
        new moodle_url('/local/gestion_actividades/dashboard.php', ['courseid' => (int)$course->id]),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_gestion_actividades_dashboard',
        new pix_icon('i/settings', 'Gestión HEE')
    );
    $node->showinflatnavigation = true;
}

/**
 * Add a lightweight global navigation shortcut too.
 */
function local_gestion_actividades_extend_navigation(global_navigation $navigation): void {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Secondary guaranteed course-navigation path. The helper is idempotent.
    global $PAGE;
    if (!empty($PAGE->course->id) && strpos((string)$PAGE->pagetype, 'course-view') === 0) {
        local_gestion_actividades_require_card_status_v2((int)$PAGE->course->id);
    }

    try {
        $canmanage = \local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id);
    } catch (Throwable $e) {
        $canmanage = false;
    }

    if (!$canmanage) {
        return;
    }

    $node = $navigation->add(
        'Gestión HEE',
        new moodle_url('/local/gestion_actividades/dashboard.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_gestion_actividades_global_dashboard',
        new pix_icon('i/settings', 'Gestión HEE')
    );
    $node->showinflatnavigation = true;
}


/**
 * Invalidates the optional block_gestion_hee student-hours cache if the block is installed.
 * This is intentionally defensive: local_gestion_actividades must keep working even if the block
 * is disabled, missing, or being upgraded.
 */
function local_gestion_actividades_invalidate_block_gestion_hee_user_cache(int $userid): void {
    global $CFG;

    $userid = max(0, $userid);
    if ($userid <= 0) {
        return;
    }

    try {
        if (!function_exists('block_gestion_hee_invalidate_user_cache')) {
            $blocklib = $CFG->dirroot . '/blocks/gestion_hee/lib.php';
            if (is_readable($blocklib)) {
                require_once($blocklib);
            }
        }

        if (function_exists('block_gestion_hee_invalidate_user_cache')) {
            block_gestion_hee_invalidate_user_cache($userid);
        }
    } catch (Throwable $e) {
        if (function_exists('debugging')) {
            debugging('No se ha podido invalidar la caché del bloque Gestión HEE para el usuario ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}

/**
 * Invalidates several optional block_gestion_hee student-hours cache entries.
 */
function local_gestion_actividades_invalidate_block_gestion_hee_users_cache(array $userids): void {
    foreach (array_unique(array_map('intval', $userids)) as $userid) {
        local_gestion_actividades_invalidate_block_gestion_hee_user_cache($userid);
    }
}

/**
 * Add lightweight client-side filtering and sortable columns to rendered tables.
 *
 * The data is already present in the page, so this adds no database queries and
 * does not change the server-side export or permission model.
 */
function local_gestion_actividades_enable_interactive_tables(string $selector = '.generaltable'): void {
    global $PAGE;

    static $initialised = false;
    if ($initialised) {
        return;
    }
    $initialised = true;

    $selectorjson = json_encode($selector, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $javascript = <<<JS
(function() {
    var selector = {$selectorjson};

    function normalise(value) {
        var text = (value || '').toString().toLocaleLowerCase('es').trim();
        if (text.normalize) {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return text;
    }

    function numericValue(value) {
        var text = normalise(value).replace(/\s+/g, '').replace(',', '.');
        if (/^-?\d+(\.\d+)?(?:h|%)?$/.test(text)) {
            return parseFloat(text.replace(/(?:h|%)$/, ''));
        }
        return null;
    }

    function initialiseTable(table, tableindex) {
        if (!table || table.getAttribute('data-local-ga-interactive') === '1') {
            return;
        }
        var tbody = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
        var headrow = table.tHead && table.tHead.rows.length ? table.tHead.rows[0] : null;
        if (!tbody || !headrow || !tbody.rows.length) {
            return;
        }
        table.setAttribute('data-local-ga-interactive', '1');

        var toolbar = document.createElement('div');
        toolbar.className = 'local-ga-table-tools d-flex align-items-center flex-wrap mb-2';

        var input = document.createElement('input');
        input.type = 'search';
        input.className = 'form-control form-control-sm me-2 mb-1';
        input.style.maxWidth = '360px';
        input.placeholder = 'Filtrar este listado…';
        input.setAttribute('aria-label', 'Filtrar filas del listado');
        input.id = 'local-ga-table-filter-' + tableindex;

        var counter = document.createElement('span');
        counter.className = 'text-muted small mb-1';
        toolbar.appendChild(input);

        var headers = Array.prototype.map.call(headrow.cells, function(cell) {
            return normalise(cell.textContent);
        });
        var groupcolumn = headers.findIndex(function(label) {
            return label === 'grupo' || label.indexOf('grupo de matriculacion') !== -1 || label.indexOf('grupo academico') !== -1;
        });
        var yearcolumn = headers.findIndex(function(label) {
            return label.indexOf('curso academico') !== -1 || label.indexOf('ano academico') !== -1;
        });

        var rows = Array.prototype.slice.call(tbody.rows);
        var selectedgroups = {};
        var selectedyears = {};

        function uniqueColumnValues(columnindex) {
            if (columnindex < 0) {
                return [];
            }
            var values = {};
            rows.forEach(function(row) {
                var value = row.cells[columnindex] ? row.cells[columnindex].textContent.trim() : '';
                if (value !== '' && value !== '-' && normalise(value) !== 'sin grupo') {
                    values[value] = true;
                }
            });
            return Object.keys(values).sort(function(a, b) {
                return a.localeCompare(b, 'es', {numeric: true});
            });
        }

        function addChecklistFilter(label, values, selected, cssclass) {
            if (!values.length) {
                return;
            }
            var details = document.createElement('details');
            details.className = 'local-ga-check-filter me-2 mb-1 ' + cssclass;
            var summary = document.createElement('summary');
            summary.className = 'btn btn-outline-secondary btn-sm';
            summary.textContent = label + ' (todos)';
            details.appendChild(summary);

            var panel = document.createElement('div');
            panel.className = 'local-ga-check-filter-panel border rounded bg-white p-2';
            panel.style.position = 'absolute';
            panel.style.zIndex = '1050';
            panel.style.maxHeight = '260px';
            panel.style.overflowY = 'auto';
            panel.style.minWidth = '220px';

            values.forEach(function(value, index) {
                var option = document.createElement('label');
                option.className = 'd-block mb-1';
                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'me-1';
                checkbox.checked = true;
                checkbox.value = value;
                checkbox.id = 'local-ga-' + cssclass + '-' + tableindex + '-' + index;
                selected[normalise(value)] = true;
                checkbox.addEventListener('change', function() {
                    selected[normalise(value)] = checkbox.checked;
                    var checked = panel.querySelectorAll('input[type=checkbox]:checked').length;
                    summary.textContent = checked === values.length ? label + ' (todos)' : label + ' (' + checked + ')';
                    applyFilter();
                });
                option.appendChild(checkbox);
                option.appendChild(document.createTextNode(value));
                panel.appendChild(option);
            });
            details.appendChild(panel);
            toolbar.appendChild(details);
        }

        addChecklistFilter('Grupos', uniqueColumnValues(groupcolumn), selectedgroups, 'groups');
        addChecklistFilter('Cursos académicos', uniqueColumnValues(yearcolumn), selectedyears, 'years');
        toolbar.appendChild(counter);
        // Make the hidden features visible to whoever uses the list.
        var hint = document.createElement('div');
        hint.className = 'local-ga-table-hint w-100 text-muted small';
        hint.textContent = 'Consejo: pulsa el nombre de una columna (⇅) para ordenar la tabla por esa columna; vuelve a pulsarlo para invertir el orden. Escribe en «Filtrar este listado…» para ver solo las filas que contengan ese texto.';
        toolbar.appendChild(hint);
        table.parentNode.insertBefore(toolbar, table);

        rows.forEach(function(row, index) {
            row.setAttribute('data-local-ga-original-order', index.toString());
        });

        function applyFilter() {
            var query = normalise(input.value);
            var visible = 0;
            rows.forEach(function(row) {
                var show = query === '' || normalise(row.textContent).indexOf(query) !== -1;
                if (show && groupcolumn >= 0 && Object.keys(selectedgroups).length) {
                    var groupvalue = normalise(row.cells[groupcolumn] ? row.cells[groupcolumn].textContent : '');
                    show = groupvalue === '' || groupvalue === '-' || selectedgroups[groupvalue] === true;
                }
                if (show && yearcolumn >= 0 && Object.keys(selectedyears).length) {
                    var yearvalue = normalise(row.cells[yearcolumn] ? row.cells[yearcolumn].textContent : '');
                    show = yearvalue === '' || yearvalue === '-' || selectedyears[yearvalue] === true;
                }
                row.style.display = show ? '' : 'none';
                if (show) {
                    visible++;
                }
            });
            counter.textContent = visible + ' de ' + rows.length + ' filas';
        }
        input.addEventListener('input', applyFilter);
        applyFilter();

        Array.prototype.forEach.call(headrow.cells, function(header, columnindex) {
            var label = normalise(header.textContent);
            if (!label || label.indexOf('accion') !== -1) {
                return;
            }
            header.style.cursor = 'pointer';
            header.tabIndex = 0;
            header.setAttribute('role', 'button');
            header.setAttribute('aria-sort', 'none');
            header.title = 'Ordenar por esta columna';
            // Visible cue that the header can be clicked to sort.
            if (!header.querySelector('.local-ga-sort-indicator')) {
                var cue = document.createElement('span');
                cue.className = 'local-ga-sort-indicator ms-1 text-muted';
                cue.setAttribute('aria-hidden', 'true');
                cue.textContent = '⇅';
                header.appendChild(cue);
            }

            function sortColumn() {
                var ascending = header.getAttribute('data-local-ga-sort') !== 'asc';
                Array.prototype.forEach.call(headrow.cells, function(other) {
                    other.removeAttribute('data-local-ga-sort');
                    if (other.getAttribute('role') === 'button') {
                        other.setAttribute('aria-sort', 'none');
                    }
                    var oldindicator = other.querySelector('.local-ga-sort-indicator');
                    if (oldindicator) {
                        // Other sortable columns keep the neutral cue.
                        oldindicator.className = 'local-ga-sort-indicator ms-1 text-muted';
                        oldindicator.textContent = '⇅';
                    }
                });
                header.setAttribute('data-local-ga-sort', ascending ? 'asc' : 'desc');
                header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                var indicator = header.querySelector('.local-ga-sort-indicator');
                if (!indicator) {
                    indicator = document.createElement('span');
                    header.appendChild(indicator);
                }
                indicator.className = 'local-ga-sort-indicator ms-1';
                indicator.textContent = ascending ? '▲' : '▼';

                rows.sort(function(a, b) {
                    var avalue = a.cells[columnindex] ? a.cells[columnindex].textContent.trim() : '';
                    var bvalue = b.cells[columnindex] ? b.cells[columnindex].textContent.trim() : '';
                    var anumber = numericValue(avalue);
                    var bnumber = numericValue(bvalue);
                    var result;
                    if (anumber !== null && bnumber !== null) {
                        result = anumber - bnumber;
                    } else {
                        result = normalise(avalue).localeCompare(normalise(bvalue), 'es', {numeric: true});
                    }
                    if (result === 0) {
                        result = parseInt(a.getAttribute('data-local-ga-original-order'), 10)
                            - parseInt(b.getAttribute('data-local-ga-original-order'), 10);
                    }
                    return ascending ? result : -result;
                });
                rows.forEach(function(row) {
                    tbody.appendChild(row);
                });
                applyFilter();
            }

            header.addEventListener('click', sortColumn);
            header.addEventListener('keydown', function(event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    sortColumn();
                }
            });
        });
    }

    function start() {
        Array.prototype.forEach.call(document.querySelectorAll(selector), initialiseTable);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
JS;
    $PAGE->requires->js_init_code($javascript);
}

/**
 * Resolve and enqueue workshop-card states for the current user.
 *
 * Passing the state map as AMD arguments avoids the failing extra request used
 * by older builds. The callback is executed while Moodle builds the course
 * navigation, before the page is sent, and the versioned module waits for the
 * section summaries to be present.
 */
function local_gestion_actividades_require_card_status_v2(int $courseid): void {
    global $PAGE, $USER;

    static $loaded = [];
    if ($courseid <= 0 || !isloggedin() || isguestuser() || isset($loaded[$courseid])) {
        return;
    }
    $loaded[$courseid] = true;

    $statuses = [];
    try {
        foreach (['typea', 'typeb'] as $type) {
            $workshops = \local_gestion_actividades\local\manager::list_workshops($courseid, $type);
            foreach ($workshops as $workshop) {
                $edition = \local_gestion_actividades\local\manager::get_primary_workshop_edition((int)$workshop->id);
                if (!$edition) {
                    continue;
                }
                $statuses[(string)(int)$edition->id] = \local_gestion_actividades\local\manager::enrol_button_status(
                    $edition,
                    (int)$USER->id
                );
            }
        }
    } catch (\Throwable $e) {
        debugging('No se pudieron resolver los estados de inscripción HEE: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    $PAGE->requires->js_call_amd('local_gestion_actividades/card_status_v2', 'init', [$statuses]);
}

/**
 * Canonical course-page script for the Apuntarme / Desapuntarme buttons.
 *
 * Paints every `.local-ga-enrol-status[data-editionid]` button with the
 * current user's state and toggles the enrolment in place through
 * enrol_toggle.php (POST + sesskey), so the student stays on the course page.
 * Guarded by window.localGaCards: the several callbacks that inject it on
 * different themes never install it twice. Without JavaScript the button keeps
 * its plain link to the workshop page.
 */
function local_gestion_actividades_card_script(int $courseid): string {
    $status = json_encode((new moodle_url('/local/gestion_actividades/card_status.php', ['courseid' => $courseid]))->out(false),
        JSON_UNESCAPED_SLASHES);
    $toggle = json_encode((new moodle_url('/local/gestion_actividades/enrol_toggle.php'))->out(false), JSON_UNESCAPED_SLASHES);
    $js = <<<'JS'
(function(){"use strict";
if(window.localGaCards){return;}
var G=window.localGaCards={statuses:null,busy:{}};
var STATUS=__STATUS__,TOGGLE=__TOGGLE__;
function buttons(id){return document.querySelectorAll('.local-ga-enrol-status[data-editionid="'+id+'"]');}
function paint(a,st){
 var label=st.label||"";if(a.textContent!==label){a.textContent=label;} // Avoid feeding the MutationObserver.
 a.classList.remove("btn-primary","btn-secondary","btn-success","btn-warning","btn-outline-danger","disabled");
 a.style.borderColor="";a.style.backgroundColor="";a.style.color="";a.removeAttribute("aria-disabled");
 a.classList.add("btn");
 if(st.enrolled&&st.canunenrol){a.classList.add("btn-outline-danger");}
 else if(st.enrolled){a.classList.add("disabled");a.style.backgroundColor="#dff3e4";a.style.borderColor="#9fd3ad";a.style.color="#1f6b35";a.setAttribute("aria-disabled","true");}
 else if(st.closed){a.classList.add("disabled");a.style.backgroundColor="#fff0d5";a.style.borderColor="#efbd68";a.style.color="#8a4b00";a.setAttribute("aria-disabled","true");}
 else{a.classList.add("btn-primary");}
}
function paintAll(){
 if(!G.statuses){return;}
 document.querySelectorAll(".local-ga-enrol-status[data-editionid]").forEach(function(a){
  var st=G.statuses[String(a.getAttribute("data-editionid"))];if(st){paint(a,st);}
 });
 document.querySelectorAll(".local-ga-roster-btn[data-editionid]").forEach(function(r){
  var st=G.statuses[String(r.getAttribute("data-editionid"))],want=(st&&st.canmanage)?"inline-block":"none";
  if(r.style.display!==want){r.style.display=want;}
 });
}
function note(a,text,ok){
 var n=a.parentNode&&a.parentNode.querySelector(".local-ga-enrol-note");
 if(!n){n=document.createElement("span");n.className="local-ga-enrol-note ms-2";n.setAttribute("role","status");n.setAttribute("aria-live","polite");n.style.fontSize=".85rem";a.insertAdjacentElement("afterend",n);}
 n.textContent=text||"";n.style.color=ok?"#1f6b35":"#8a4b00";
 clearTimeout(n._t);n._t=setTimeout(function(){n.textContent="";},6000);
}
function load(retry){
 fetch(STATUS,{credentials:"same-origin",cache:"no-store",headers:{Accept:"application/json"}})
 .then(function(r){if(!r.ok){throw new Error("HTTP "+r.status);}return r.json();})
 .then(function(d){G.statuses={};var s=(d&&d.statuses)||{};Object.keys(s).forEach(function(k){G.statuses[String(k)]=s[k];});paintAll();})
 .catch(function(){if((retry||0)<3){setTimeout(function(){load((retry||0)+1);},1000);}});
}
document.addEventListener("click",function(ev){
 var a=ev.target&&ev.target.closest?ev.target.closest(".local-ga-enrol-status[data-editionid]"):null;
 if(!a){return;}
 var id=String(a.getAttribute("data-editionid")),st=G.statuses&&G.statuses[id];
 var key=(window.M&&M.cfg&&M.cfg.sesskey)?M.cfg.sesskey:"";
 if(!st||!key){return;} // State unknown: keep the plain link as fallback.
 ev.preventDefault();ev.stopPropagation();
 if(G.busy[id]||a.getAttribute("aria-disabled")==="true"){return;}
 var action;
 if(st.enrolled&&st.canunenrol){if(!window.confirm("¿Quieres desapuntarte de este taller? Tu plaza quedará libre.")){return;}action="unenrol";}
 else if(!st.enrolled&&!st.closed){action="enrol";}
 else{return;}
 G.busy[id]=true;var old=a.textContent;a.textContent="…";a.classList.add("disabled");
 var body="id="+encodeURIComponent(id)+"&action="+action+"&sesskey="+encodeURIComponent(key);
 fetch(TOGGLE,{method:"POST",credentials:"same-origin",cache:"no-store",headers:{"Content-Type":"application/x-www-form-urlencoded",Accept:"application/json"},body:body})
 .then(function(r){return r.json();})
 .then(function(d){
  if(d&&d.status){G.statuses[id]=d.status;}
  buttons(id).forEach(function(b){paint(b,G.statuses[id]);});
  var msg=(d&&d.message)||"";
  if(d&&d.success){
   // Reload so Moodle recalculates access to the workshop activities,
   // keeping the scroll position and showing the confirmation afterwards.
   msg=action==="enrol"?"Te has apuntado al taller.":"Te has desapuntado del taller.";
   note(a,msg,true);
   var idx=Array.prototype.indexOf.call(buttons(id),a);
   try{sessionStorage.setItem("localGaReload",JSON.stringify({y:window.scrollY,id:id,idx:idx,msg:msg}));}catch(e){}
   window.location.reload();
   return;
  }
  note(a,msg,false);
 })
 .catch(function(){a.textContent=old;a.classList.remove("disabled");note(a,"No se ha podido completar. Inténtalo de nuevo.",false);})
 .then(function(){G.busy[id]=false;});
},true);
function restore(){
 var r=null;try{r=JSON.parse(sessionStorage.getItem("localGaReload")||"null");sessionStorage.removeItem("localGaReload");}catch(e){}
 if(!r){return;}
 var go=function(){window.scrollTo(0,r.y||0);var bs=buttons(r.id),b=bs[r.idx>=0&&r.idx<bs.length?r.idx:0];if(b){note(b,r.msg,true);}};
 go();setTimeout(go,300);setTimeout(go,1200);
}
function start(){
 restore();
 load(0);
 var pending=false;
 var obs=new MutationObserver(function(){if(pending){return;}pending=true;setTimeout(function(){pending=false;paintAll();},150);});
 obs.observe(document.documentElement,{childList:true,subtree:true});
 setTimeout(function(){obs.disconnect();},15000);
}
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",start,{once:true});}else{start();}
})();
JS;
    return str_replace(['__STATUS__', '__TOGGLE__'], [$status, $toggle], $js);
}

/**
 * Loads the per-user status updater for workshop cards.
 *
 * The section summary is shared by every course user, so the initial HTML must
 * remain generic. This lightweight request changes only the enrolment control
 * after the page loads, without rebuilding sections or recalculating grades.
 */
function local_gestion_actividades_require_card_status_js(int $courseid): void {
    global $PAGE;

    static $loadedcourses = [];
    if ($courseid <= 0 || isset($loadedcourses[$courseid])) {
        return;
    }
    $loadedcourses[$courseid] = true;
    $PAGE->requires->js_init_code(local_gestion_actividades_card_script($courseid));
}

/**
 * Enqueue the card-status module before Moodle sends the page headers.
 * This is the most reliable point across Boost-derived and custom themes.
 */
function local_gestion_actividades_before_http_headers(): void {
    global $PAGE;
    if (!isloggedin() || isguestuser() || empty($PAGE->course->id)
            || strpos((string)$PAGE->pagetype, 'course-view') !== 0) {
        return;
    }
    $PAGE->requires->js_call_amd('local_gestion_actividades/card_status', 'init', [(int)$PAGE->course->id]);
}

/**
 * Fallback for themes that invoke the standard before-footer callback.
 */
function local_gestion_actividades_before_footer(): void {
    global $PAGE;
    if (strpos((string)$PAGE->pagetype, 'course-view') !== 0 || empty($PAGE->course->id)) {
        return;
    }
    local_gestion_actividades_require_card_status_js((int)$PAGE->course->id);
}



/**
 * Earliest reliable course-page hook for workshop enrolment states.
 *
 * Some themes do not execute navigation/footer callbacks consistently. This
 * head hook installs a MutationObserver before the course content is rendered,
 * then updates the shared section-summary cards with the current user's state.
 */
function local_gestion_actividades_before_standard_html_head(): string {
    global $PAGE;

    if (strpos((string)$PAGE->pagetype, 'course-view') !== 0 || empty($PAGE->course->id)
            || !isloggedin() || isguestuser()) {
        return '';
    }
    return '<script>' . local_gestion_actividades_card_script((int)$PAGE->course->id) . '</script>';
}

/**
 * Reliable footer fallback for course formats/themes that do not invoke the
 * course navigation callback early enough for js_init_code().
 */
function local_gestion_actividades_before_standard_footer_html(): string {
    global $PAGE;

    if (strpos((string)$PAGE->pagetype, 'course-view') !== 0 || empty($PAGE->course->id)
            || !isloggedin() || isguestuser()) {
        return '';
    }
    // Same guarded script: a no-op when the head callback already installed it.
    return '<script>' . local_gestion_actividades_card_script((int)$PAGE->course->id) . '</script>';
}
