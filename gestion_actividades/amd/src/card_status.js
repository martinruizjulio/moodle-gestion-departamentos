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
 * @module     local_gestion_actividades/card_status
 * @copyright  2026 Julio Martín Ruiz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    function apply(action, status) {
        action.classList.remove('btn-primary', 'btn-secondary', 'btn-success', 'btn-warning', 'disabled');
        action.style.borderColor = '';
        action.style.backgroundColor = '';
        action.style.color = '';
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

    function update(courseid, attempt) {
        attempt = attempt || 0;
        var cards = document.querySelectorAll('.local-ga-card-actions[data-editionid]');
        if (!cards.length) {
            if (attempt < 40) {
                window.setTimeout(function() { update(courseid, attempt + 1); }, 250);
            }
            return;
        }
        var root = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
        fetch(root + '/local/gestion_actividades/card_status.php?courseid=' + encodeURIComponent(courseid) + '&_=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Accept': 'application/json'}
        }).then(function(response) {
            return response.ok ? response.json() : null;
        }).then(function(data) {
            if (!data || !data.statuses) {
                return;
            }
            cards.forEach(function(card) {
                var status = data.statuses[card.getAttribute('data-editionid')];
                var action = card.querySelector('.local-ga-enrol-status') || (card.classList.contains('local-ga-enrol-status') ? card : null);
                if (status && action) {
                    action.textContent = status.label;
                    apply(action, status);
                }
            });
        }).catch(function() {});
    }

    return {
        init: function(courseid) {
            if (window.localGaCards) {
                // The canonical in-place toggle script (lib.php) handles the buttons.
                return;
            }
            var run = function() { update(courseid, 0); };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run, {once: true});
            } else {
                run();
            }
        }
    };
});
