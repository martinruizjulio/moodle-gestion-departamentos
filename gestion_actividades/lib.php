<?php
// Library callbacks for Gestion_actividades.

defined('MOODLE_INTERNAL') || die();


function local_gestion_actividades_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if (!in_array($filearea, ['material', 'certificate', 'taskfile', 'tasksubmission'], true)) { return false; }
    require_login($course);
    global $USER, $DB;
    if (empty($args)) { return false; }
    $itemidpeek = (int)$args[0];

    if ($filearea === 'certificate') {
        $cert = $itemidpeek ? $DB->get_record('local_ga_certificates', ['id' => $itemidpeek], '*', IGNORE_MISSING) : false;
        if (!$cert) { return false; }
        $canmanage = \local_gestion_actividades\local\manager::can_manage_globally((int)$USER->id)
            || (!empty($cert->editionid) && \local_gestion_actividades\local\manager::can_manage_edition((int)$cert->editionid, (int)$USER->id));
        if ((int)$cert->userid !== (int)$USER->id && !$canmanage) { return false; }
    }

    if ($filearea === 'material') {
        $mat = $itemidpeek ? $DB->get_record('local_ga_materials', ['fileitemid' => $itemidpeek], '*', IGNORE_MISSING) : false;
        if (!$mat) { return false; }
        $workshop = $DB->get_record('local_ga_workshops', ['id' => (int)$mat->workshopid], '*', IGNORE_MISSING);
        if (!$workshop) { return false; }
        $editionid = !empty($mat->editionid) ? (int)$mat->editionid : 0;
        $canmanage = $editionid > 0
            ? \local_gestion_actividades\local\manager::can_manage_edition($editionid, (int)$USER->id)
            : \local_gestion_actividades\local\manager::can_manage_workshop_instance((int)$workshop->id, (int)$USER->id);
        if (!$canmanage && !\local_gestion_actividades\local\manager::user_can_access_workshop_resources($editionid, (int)$USER->id)) {
            return false;
        }
    }

    if ($filearea === 'taskfile') {
        $edition = $DB->get_record('local_ga_workshop_editions', ['taskfileitemid' => $itemidpeek], '*', IGNORE_MISSING);
        if (!$edition) { return false; }
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
        input.className = 'form-control form-control-sm mr-2 mb-1';
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
            details.className = 'local-ga-check-filter mr-2 mb-1 ' + cssclass;
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
                checkbox.className = 'mr-1';
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

            function sortColumn() {
                var ascending = header.getAttribute('data-local-ga-sort') !== 'asc';
                Array.prototype.forEach.call(headrow.cells, function(other) {
                    other.removeAttribute('data-local-ga-sort');
                    if (other.getAttribute('role') === 'button') {
                        other.setAttribute('aria-sort', 'none');
                    }
                    var oldindicator = other.querySelector('.local-ga-sort-indicator');
                    if (oldindicator) {
                        oldindicator.remove();
                    }
                });
                header.setAttribute('data-local-ga-sort', ascending ? 'asc' : 'desc');
                header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                var indicator = document.createElement('span');
                indicator.className = 'local-ga-sort-indicator ml-1';
                indicator.textContent = ascending ? '▲' : '▼';
                header.appendChild(indicator);

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
                $enrolment = \local_gestion_actividades\local\manager::get_edition_enrolment(
                    (int)$edition->id,
                    (int)$USER->id
                );
                $enrolled = $enrolment && in_array(
                    (string)($enrolment->status ?? ''),
                    ['enrolled', 'attended'],
                    true
                );
                $closed = \local_gestion_actividades\local\manager::is_edition_enrolment_closed($edition);
                $statuses[(string)(int)$edition->id] = [
                    'enrolled' => (bool)$enrolled,
                    'closed' => (bool)$closed,
                    'label' => $enrolled
                        ? get_string('enrolledbutton', 'local_gestion_actividades')
                        : ($closed
                            ? get_string('enrolmentclosed', 'local_gestion_actividades')
                            : get_string('enrolme', 'local_gestion_actividades')),
                ];
            }
        }
    } catch (\Throwable $e) {
        debugging('No se pudieron resolver los estados de inscripción HEE: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    $PAGE->requires->js_call_amd('local_gestion_actividades/card_status_v2', 'init', [$statuses]);
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

    $url = (new moodle_url('/local/gestion_actividades/card_status.php', ['courseid' => $courseid]))->out(false);
    $javascript = <<<JS
(function() {
    function applyVisualState(action, status) {
        action.classList.remove('btn-primary', 'btn-secondary', 'btn-success', 'btn-warning', 'disabled');
        action.style.borderColor = '';
        action.style.backgroundColor = '';
        action.style.color = '';
        action.removeAttribute('aria-disabled');

        if (status.enrolled) {
            action.classList.add('btn', 'disabled');
            action.style.backgroundColor = '#dff3e4';
            action.style.borderColor = '#9fd3ad';
            action.style.color = '#1f6b35';
            action.setAttribute('aria-disabled', 'true');
            action.removeAttribute('href');
        } else if (status.closed) {
            action.classList.add('btn', 'disabled');
            action.style.backgroundColor = '#fff0d5';
            action.style.borderColor = '#efbd68';
            action.style.color = '#8a4b00';
            action.setAttribute('aria-disabled', 'true');
            action.removeAttribute('href');
        } else {
            action.classList.add('btn', 'btn-primary');
        }
    }

    function updateCards() {
        var cards = document.querySelectorAll('.local-ga-card-actions[data-editionid]');
        if (!cards.length) { return; }
        fetch('$url', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Accept': 'application/json'}
        })
            .then(function(response) { return response.ok ? response.json() : null; })
            .then(function(data) {
                if (!data || !data.statuses) { return; }
                cards.forEach(function(card) {
                    var id = card.getAttribute('data-editionid');
                    var status = data.statuses[id];
                    if (!status) { return; }
                    var action = card.querySelector('.local-ga-enrol-status');
                    if (!action) { return; }
                    action.textContent = status.label;
                    applyVisualState(action, status);
                });
            })
            .catch(function() {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateCards, {once: true});
    } else {
        updateCards();
    }
})();
JS;
    $PAGE->requires->js_init_code($javascript);
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

    if (strpos((string)$PAGE->pagetype, 'course-view') !== 0 || empty($PAGE->course->id)) {
        return '';
    }

    $url = (new moodle_url('/local/gestion_actividades/card_status.php', [
        'courseid' => (int)$PAGE->course->id,
    ]))->out(false);
    $urljson = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return '<script>(function(){"use strict";'
        . 'var endpoint=' . $urljson . ',running=false,done=false,observer=null,retries=0;'
        . 'function paint(a,st){a.textContent=st.label||"";a.classList.remove("btn-primary","btn-secondary","btn-success","btn-warning","disabled");a.style.borderColor="";a.style.backgroundColor="";a.style.color="";a.removeAttribute("aria-disabled");'
        . 'if(st.enrolled){a.classList.add("btn","disabled");a.style.backgroundColor="#dff3e4";a.style.borderColor="#9fd3ad";a.style.color="#1f6b35";a.setAttribute("aria-disabled","true");a.removeAttribute("href");}'
        . 'else if(st.closed){a.classList.add("btn","disabled");a.style.backgroundColor="#fff0d5";a.style.borderColor="#efbd68";a.style.color="#8a4b00";a.setAttribute("aria-disabled","true");a.removeAttribute("href");}'
        . 'else{a.classList.add("btn","btn-primary");}}'
        . 'function update(){if(running||done){return;}var cards=document.querySelectorAll(".local-ga-card-actions[data-editionid]");if(!cards.length){if(retries++<40){setTimeout(update,250);}return;}running=true;'
        . 'fetch(endpoint,{credentials:"same-origin",cache:"no-store",headers:{Accept:"application/json"}}).then(function(r){if(!r.ok){throw new Error("HTTP "+r.status);}return r.json();}).then(function(data){if(!data||!data.statuses){throw new Error("Invalid response");}cards.forEach(function(card){var st=data.statuses[String(card.getAttribute("data-editionid"))]||data.statuses[card.getAttribute("data-editionid")];var a=card.querySelector(".local-ga-enrol-status");if(st&&a){paint(a,st);}});done=true;if(observer){observer.disconnect();}}).catch(function(){running=false;if(retries++<8){setTimeout(update,750);}});}'
        . 'function start(){update();observer=new MutationObserver(function(){if(!done){update();}});observer.observe(document.documentElement,{childList:true,subtree:true});setTimeout(update,0);setTimeout(update,1000);setTimeout(update,3000);}'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",start,{once:true});}else{start();}'
        . '})();</script>';
}

/**
 * Reliable footer fallback for course formats/themes that do not invoke the
 * course navigation callback early enough for js_init_code().
 */
function local_gestion_actividades_before_standard_footer_html(): string {
    global $PAGE;

    if (strpos((string)$PAGE->pagetype, 'course-view') !== 0 || empty($PAGE->course->id)) {
        return '';
    }

    $url = (new moodle_url('/local/gestion_actividades/card_status.php', [
        'courseid' => (int)$PAGE->course->id,
    ]))->out(false);
    $urljson = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return '<script>(function(){'
        . 'function run(){var cards=document.querySelectorAll(".local-ga-card-actions[data-editionid]");if(!cards.length){return;}'
        . 'fetch(' . $urljson . ',{credentials:"same-origin",cache:"no-store",headers:{Accept:"application/json"}})'
        . '.then(function(r){return r.ok?r.json():null;}).then(function(data){if(!data||!data.statuses){return;}'
        . 'cards.forEach(function(card){var st=data.statuses[card.getAttribute("data-editionid")];if(!st){return;}'
        . 'var a=card.querySelector(".local-ga-enrol-status");if(!a){return;}a.textContent=st.label;'
        . 'a.classList.remove("btn-primary","btn-secondary","btn-success","btn-warning","disabled");'
        . 'a.style.borderColor="";a.style.backgroundColor="";a.style.color="";a.removeAttribute("aria-disabled");'
        . 'if(st.enrolled){a.classList.add("btn","disabled");a.style.backgroundColor="#dff3e4";a.style.borderColor="#9fd3ad";a.style.color="#1f6b35";a.setAttribute("aria-disabled","true");a.removeAttribute("href");}'
        . 'else if(st.closed){a.classList.add("btn","disabled");a.style.backgroundColor="#fff0d5";a.style.borderColor="#efbd68";a.style.color="#8a4b00";a.setAttribute("aria-disabled","true");a.removeAttribute("href");}'
        . 'else{a.classList.add("btn","btn-primary");}});}).catch(function(){});}'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",run,{once:true});}else{run();}'
        . '})();</script>';
}
