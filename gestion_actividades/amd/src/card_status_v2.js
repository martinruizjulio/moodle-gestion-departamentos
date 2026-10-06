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
