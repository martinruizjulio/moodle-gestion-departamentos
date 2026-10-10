// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AMD module for local_gestion_actividades.
 *
 * @module     local_gestion_actividades/card_status_v2
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    var initialized = false;

    function paint(action, status) {
        action.textContent = status.label || '';
        action.classList.remove('btn-primary', 'btn-secondary', 'btn-success', 'btn-warning', 'disabled');
        action.style.backgroundColor = '';
        action.style.borderColor = '';
        action.style.color = '';
        action.style.fontWeight = '600';
        action.removeAttribute('aria-disabled');

        if (status.enrolled) {
            action.classList.add('btn', 'btn-outline-danger');
            var editionid = action.getAttribute('data-editionid');
            var root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
            var key = (window.M && M.cfg && M.cfg.sesskey) ? M.cfg.sesskey : '';
            if (editionid && key) {
                action.setAttribute('href', root + '/local/gestion_actividades/unenrol.php?id='
                    + encodeURIComponent(editionid) + '&sesskey=' + encodeURIComponent(key));
            }
        } else if (status.closed) {
            action.classList.add('btn', 'disabled');
            action.style.backgroundColor = '#fff0d5';
            action.style.borderColor = '#efbd68';
            action.style.color = '#8a4b00';
            action.setAttribute('aria-disabled', 'true');
            action.removeAttribute('href');
        } else {
            action.classList.add('btn', 'btn-primary');
            var editionid = action.getAttribute('data-editionid');
            var root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
            var key = (window.M && M.cfg && M.cfg.sesskey) ? M.cfg.sesskey : '';
            if (editionid && key) {
                action.setAttribute('href', root + '/local/gestion_actividades/enrol.php?id='
                    + encodeURIComponent(editionid) + '&sesskey=' + encodeURIComponent(key));
            }
        }
    }

    function applyAll(statuses, attempt) {
        var cards = document.querySelectorAll('.local-ga-card-actions[data-editionid]');
        if (!cards.length) {
            if (attempt < 80) {
                window.setTimeout(function() { applyAll(statuses, attempt + 1); }, 125);
            }
            return;
        }
        cards.forEach(function(card) {
            var id = String(card.getAttribute('data-editionid'));
            var status = statuses[id];
            var action = card.querySelector('.local-ga-enrol-status');
            if (status && action) {
                paint(action, status);
            }
        });
    }

    return {
        init: function(statuses) {
            if (window.localGaCards) {
                // The canonical in-place toggle script (lib.php) handles the buttons.
                return;
            }
            if (initialized) {
                return;
            }
            initialized = true;
            var run = function() { applyAll(statuses || {}, 0); };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run, {once: true});
            } else {
                run();
            }
        }
    };
});
