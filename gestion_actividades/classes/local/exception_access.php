<?php
namespace local_gestion_actividades\local;

defined('MOODLE_INTERNAL') || die();

class exception_access {
    private const COOKIE_NAME = 'ga_exception_session';
    private const SESSION_TTL = 28800; // 8 hours.
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900; // 15 minutes.

    public static function ensure_tables(): void {
        global $DB;
        $dbman = $DB->get_manager();

        $users = new \xmldb_table('local_ga_exception_users');
        if (!$dbman->table_exists($users)) {
            $users->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $users->add_field('firstname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $users->add_field('lastname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $users->add_field('email', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $users->add_field('username', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $users->add_field('passwordhash', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
            $users->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $users->add_field('failedattempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $users->add_field('lockeduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $users->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $users->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $users->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $users->add_field('timelastlogin', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $users->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $users->add_index('email', XMLDB_INDEX_UNIQUE, ['email']);
            $users->add_index('username', XMLDB_INDEX_UNIQUE, ['username']);
            $users->add_index('active', XMLDB_INDEX_NOTUNIQUE, ['active']);
            $dbman->create_table($users);
        }

        $sessions = new \xmldb_table('local_ga_exception_sessions');
        if (!$dbman->table_exists($sessions)) {
            $sessions->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $sessions->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $sessions->add_field('tokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $sessions->add_field('csrftoken', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $sessions->add_field('ip', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $sessions->add_field('useragent', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $sessions->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $sessions->add_field('lastseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $sessions->add_field('expires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $sessions->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $sessions->add_index('tokenhash', XMLDB_INDEX_UNIQUE, ['tokenhash']);
            $sessions->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $sessions->add_index('expires', XMLDB_INDEX_NOTUNIQUE, ['expires']);
            $dbman->create_table($sessions);
        }

        $logs = new \xmldb_table('local_ga_exception_log');
        if (!$dbman->table_exists($logs)) {
            $logs->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $logs->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $logs->add_field('action', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
            $logs->add_field('targettype', XMLDB_TYPE_CHAR, '50', null, null, null, null);
            $logs->add_field('targetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $logs->add_field('details', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $logs->add_field('ip', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $logs->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $logs->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $logs->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $logs->add_index('action', XMLDB_INDEX_NOTUNIQUE, ['action']);
            $logs->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($logs);
        }
    }

    public static function list_users(): array {
        global $DB;
        self::ensure_tables();
        return $DB->get_records('local_ga_exception_users', null, 'lastname ASC, firstname ASC, id ASC');
    }

    public static function create_user(string $firstname, string $lastname, string $email, string $username, string $password, int $createdby): int {
        global $DB;
        self::ensure_tables();
        $firstname = trim($firstname);
        $lastname = trim($lastname);
        $email = \core_text::strtolower(trim($email));
        $username = \core_text::strtolower(trim($username));
        self::validate_identity($firstname, $lastname, $email, $username);
        self::validate_password($password);
        if ($DB->record_exists('local_ga_exception_users', ['email' => $email]) || $DB->record_exists('local_ga_exception_users', ['username' => $username])) {
            throw new \moodle_exception('invaliddata', 'error', '', 'Ya existe un usuario excepcional con ese correo o nombre de usuario.');
        }
        $now = time();
        return (int)$DB->insert_record('local_ga_exception_users', (object)[
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'username' => $username,
            'passwordhash' => password_hash($password, PASSWORD_DEFAULT),
            'active' => 1,
            'failedattempts' => 0,
            'lockeduntil' => 0,
            'createdby' => $createdby,
            'timecreated' => $now,
            'timemodified' => $now,
            'timelastlogin' => 0,
        ]);
    }

    public static function set_active(int $id, bool $active): void {
        global $DB;
        self::ensure_tables();
        $DB->set_field('local_ga_exception_users', 'active', $active ? 1 : 0, ['id' => $id]);
        $DB->set_field('local_ga_exception_users', 'timemodified', time(), ['id' => $id]);
        if (!$active) {
            $DB->delete_records('local_ga_exception_sessions', ['userid' => $id]);
        }
    }

    public static function reset_password(int $id, string $password): void {
        global $DB;
        self::ensure_tables();
        self::validate_password($password);
        $DB->update_record('local_ga_exception_users', (object)[
            'id' => $id,
            'passwordhash' => password_hash($password, PASSWORD_DEFAULT),
            'failedattempts' => 0,
            'lockeduntil' => 0,
            'timemodified' => time(),
        ]);
        $DB->delete_records('local_ga_exception_sessions', ['userid' => $id]);
    }

    public static function authenticate(string $identifier, string $password): bool {
        global $DB;
        self::ensure_tables();
        self::cleanup_sessions();
        $identifier = \core_text::strtolower(trim($identifier));
        $user = $DB->get_record_select('local_ga_exception_users', 'email = :id1 OR username = :id2', ['id1' => $identifier, 'id2' => $identifier], '*', IGNORE_MISSING);
        if (!$user || empty($user->active)) {
            usleep(250000);
            return false;
        }
        $now = time();
        if ((int)$user->lockeduntil > $now) {
            usleep(250000);
            return false;
        }
        if (!password_verify($password, (string)$user->passwordhash)) {
            $failed = (int)$user->failedattempts + 1;
            $lockeduntil = $failed >= self::MAX_FAILED_ATTEMPTS ? $now + self::LOCK_SECONDS : 0;
            if ($lockeduntil > 0) {
                $failed = 0;
            }
            $DB->update_record('local_ga_exception_users', (object)[
                'id' => $user->id,
                'failedattempts' => $failed,
                'lockeduntil' => $lockeduntil,
                'timemodified' => $now,
            ]);
            self::log((int)$user->id, 'login_failed', '', 0, 'Intento de acceso fallido.');
            usleep(250000);
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $csrftoken = bin2hex(random_bytes(32));
        $expires = $now + self::SESSION_TTL;
        $DB->insert_record('local_ga_exception_sessions', (object)[
            'userid' => (int)$user->id,
            'tokenhash' => hash('sha256', $token),
            'csrftoken' => $csrftoken,
            'ip' => self::client_ip(),
            'useragent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'timecreated' => $now,
            'lastseen' => $now,
            'expires' => $expires,
        ]);
        $DB->update_record('local_ga_exception_users', (object)[
            'id' => $user->id,
            'failedattempts' => 0,
            'lockeduntil' => 0,
            'timelastlogin' => $now,
            'timemodified' => $now,
        ]);
        self::set_cookie($token, $expires);
        self::log((int)$user->id, 'login_success', '', 0, 'Acceso excepcional iniciado.');
        return true;
    }

    public static function current_user(): ?\stdClass {
        global $DB;
        self::ensure_tables();
        $token = (string)($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($token === '') {
            return null;
        }
        $hash = hash('sha256', $token);
        $session = $DB->get_record('local_ga_exception_sessions', ['tokenhash' => $hash], '*', IGNORE_MISSING);
        if (!$session || (int)$session->expires < time()) {
            if ($session) {
                $DB->delete_records('local_ga_exception_sessions', ['id' => $session->id]);
            }
            self::clear_cookie();
            return null;
        }
        $user = $DB->get_record('local_ga_exception_users', ['id' => $session->userid, 'active' => 1], '*', IGNORE_MISSING);
        if (!$user) {
            $DB->delete_records('local_ga_exception_sessions', ['id' => $session->id]);
            self::clear_cookie();
            return null;
        }
        $DB->set_field('local_ga_exception_sessions', 'lastseen', time(), ['id' => $session->id]);
        $user->_exception_sessionid = (int)$session->id;
        $user->_exception_csrftoken = (string)$session->csrftoken;
        return $user;
    }

    public static function csrf_token(\stdClass $user): string {
        return (string)($user->_exception_csrftoken ?? '');
    }

    public static function validate_csrf(\stdClass $user, string $token): bool {
        $expected = self::csrf_token($user);
        return $expected !== '' && hash_equals($expected, $token);
    }

    public static function logout(): void {
        global $DB;
        self::ensure_tables();
        $token = (string)($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($token !== '') {
            $hash = hash('sha256', $token);
            $session = $DB->get_record('local_ga_exception_sessions', ['tokenhash' => $hash], '*', IGNORE_MISSING);
            if ($session) {
                self::log((int)$session->userid, 'logout', '', 0, 'Acceso excepcional cerrado.');
                $DB->delete_records('local_ga_exception_sessions', ['id' => $session->id]);
            }
        }
        self::clear_cookie();
    }

    public static function log(int $userid, string $action, string $targettype = '', int $targetid = 0, string $details = ''): void {
        global $DB;
        self::ensure_tables();
        $DB->insert_record('local_ga_exception_log', (object)[
            'userid' => $userid,
            'action' => substr($action, 0, 100),
            'targettype' => $targettype !== '' ? substr($targettype, 0, 50) : null,
            'targetid' => $targetid,
            'details' => $details,
            'ip' => self::client_ip(),
            'timecreated' => time(),
        ]);
    }

    public static function recent_logs(int $limit = 50): array {
        global $DB;
        self::ensure_tables();
        $sql = "SELECT l.*, u.firstname, u.lastname, u.email
                  FROM {local_ga_exception_log} l
                  JOIN {local_ga_exception_users} u ON u.id = l.userid
              ORDER BY l.timecreated DESC, l.id DESC";
        return $DB->get_records_sql($sql, [], 0, max(1, min(200, $limit)));
    }

    private static function validate_identity(string $firstname, string $lastname, string $email, string $username): void {
        if ($firstname === '' || $lastname === '' || !validate_email($email) || $username === '' || !preg_match('/^[a-z0-9._-]{3,100}$/', $username)) {
            throw new \moodle_exception('invaliddata', 'error', '', 'Nombre, apellidos, correo o usuario no válidos.');
        }
    }

    private static function validate_password(string $password): void {
        if (strlen($password) < 12 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            throw new \moodle_exception('invaliddata', 'error', '', 'La contraseña debe tener al menos 12 caracteres e incluir letras y números.');
        }
    }

    private static function cleanup_sessions(): void {
        global $DB;
        $DB->delete_records_select('local_ga_exception_sessions', 'expires < :now', ['now' => time()]);
    }

    private static function set_cookie(string $token, int $expires): void {
        global $CFG;
        $path = parse_url($CFG->wwwroot, PHP_URL_PATH) ?: '/';
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => $expires,
            'path' => $path,
            'secure' => strpos($CFG->wwwroot, 'https://') === 0,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clear_cookie(): void {
        global $CFG;
        $path = parse_url($CFG->wwwroot, PHP_URL_PATH) ?: '/';
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => $path,
            'secure' => strpos($CFG->wwwroot, 'https://') === 0,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function client_ip(): string {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
}
