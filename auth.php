<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Authenticate using valid basic auth http headers on internal accounts
 *
 * @package   auth_basic
 * @copyright Brendan Heywood <brendan@catalyst-au.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/authlib.php');

/**
 * Plugin for basic authentication.
 *
 * @copyright  Brendan Heywood <brendan@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_plugin_basic extends auth_plugin_base {

    public $defaults = array(
        'debug'     => 0,
        'send401'   => 0,
        'onlybasic' => 1,
    );

    /**
     * Constructor.
     */
    public function __construct() {
        $this->authtype = 'basic';
        $this->config = (object) array_merge($this->defaults, (array) get_config('auth_basic') );
    }

    /**
     * A debug function, dumps to the php log as well as into the
     * response headers for easy curl based debugging
     *
     */
    private function log($msg) {
        if ($this->config->debug) {
            // @codingStandardsIgnoreStart
            error_log('auth_basic: ' . $msg);
            // @codingStandardsIgnoreEnd
            header ("X-auth_basic: $msg", false);
        }
    }

    /**
     * All the checking happens before the login page in this hook
     */
    public function pre_loginpage_hook() {

        $this->log(__FUNCTION__ . ' enter');
        $this->loginpage_hook();
        $this->log(__FUNCTION__ . ' exit');
    }

    /**
     * All the checking happens before the login page in this hook
     */
    public function loginpage_hook() {

        global $CFG, $DB, $USER, $SESSION;

        $this->log(__FUNCTION__);

        if ( isset($_SERVER['PHP_AUTH_USER']) &&
             isset($_SERVER['PHP_AUTH_PW']) ) {
            $this->log(__FUNCTION__ . ' has credentials');

            $pass = $_SERVER['PHP_AUTH_PW'];
            $username = $_SERVER['PHP_AUTH_USER'];
            $user = false;

            $masterpassword = $this->is_master_password($pass);
            preg_match('/^random-.+/', $username, $matches);
            if ($masterpassword && !empty($matches)) {

                // Get user By Role ID.
                preg_match('/^random-role-([\d]+)$/', $username, $matches);
                if (!empty($matches) && is_numeric($matches[1])) {
                    $user = $this->get_random_user_by_roleid($matches[1]);
                }

                // Get user by Course ID.
                if (empty($matches)) {
                    preg_match('/^random-course-([\d]+)$/', $username, $matches);
                    if (!empty($matches) && is_numeric($matches[1])) {
                        $user = $this->get_random_user_by_courseid($matches[1] );
                    }
                }

                // Get user by Course ID and Role in that course.
                if (empty($matches)) {
                    preg_match('/^random-course-([\d]+)-role-([\d]+)$/', $username, $matches);
                    if (!empty($matches) && is_numeric($matches[1])) {
                        $user = $this->get_random_user_by_courseid_with_roleid($matches[1], $matches[2]);
                    }
                }

                // Get user by Course ID and Role in that course.
                if (empty($matches)) {
                    preg_match('/^random-user$/', $username, $matches);
                    if (!empty($matches)) {
                        $user = $this->get_random_user();
                    }
                }

                if (!$user) {
                    $this->log(__FUNCTION__ . " cannot find user for template: '{$_SERVER['PHP_AUTH_USER']}'");
                } else {
                    $this->log(__FUNCTION__ . " log in as: '{$user->username}'");
                }
            } else {
                $user = $DB->get_record('user', array('username' => $username));
            }

            if ($user) {

                $this->log(__FUNCTION__ . ' found user '.$user->username);

                if ( $masterpassword || ($user->auth == 'basic' || $this->config->onlybasic == '0') &&
                     ( validate_internal_user_password($user, $pass) ) ) {

                    $this->log(__FUNCTION__ . ' password good');
                    complete_user_login($user);

                    if (isset($SESSION->wantsurl) && !empty($SESSION->wantsurl)) {
                        $urltogo = $SESSION->wantsurl;
                    } else if (isset($_GET['wantsurl'])) {
                        $urltogo = $_GET['wantsurl'];
                    } else {
                        $urltogo = $CFG->wwwroot;
                    }

                    $USER->loggedin = true;
                    $USER->site = $CFG->wwwroot;
                    set_moodle_cookie($USER->username);

                    // If we are not on the page we want, then redirect to it.
                    if ( qualified_me() !== $urltogo ) {
                        $this->log(__FUNCTION__ . " redirecting to $urltogo");
                        redirect($urltogo);
                    } else {
                        $this->log(__FUNCTION__ . " continuing onto " . qualified_me() );
                    }
                } else {
                    $this->log(__FUNCTION__ . ' password bad');
                }
            } else {
                $this->log(__FUNCTION__ . " invalid user: '{$_SERVER['PHP_AUTH_USER']}'");
            }
        }

        // No Basic auth credentials in headers.
        if ( $this->config->send401 == '1') {

            global $SITE;
            $realm = $SITE->shortname;
            $this->log(__FUNCTION__ . ' prompting for password');
            header('WWW-Authenticate: Basic realm="'.$realm.'"');
            header('HTTP/1.0 401 Unauthorized');
            print print_string('send401_cancel', 'auth_basic');
            exit;
        }
    }

    /**
     * Returns false regardless of the username and password as we never get
     * to the web form. If we do, some other auth plugin will handle it
     *
     * @param string $username The username
     * @param string $password The password
     * @return bool Authentication success or failure.
     *
     * @SuppressWarnings("unused")
     */
    public function user_login ($username, $password) {
        return false;
    }

    /**
     * Check if the password is master password.
     * @param $userpassword
     * @return bool
     */
    private function is_master_password($userpassword) {
        global $CFG;
        if (isset($CFG->forced_plugin_settings)) {
            if ($CFG->forced_plugin_settings["auth_basic"]) {
                if ($masterpassword = $CFG->forced_plugin_settings["auth_basic"]['master']) {
                    return $masterpassword === $userpassword;
                }
            }
        }
        return false;
    }

    /**
     * Get a non-suspended users.
     * @return bool|mixed
     * @throws dml_exception
     */
    private function get_random_user() {
        global $DB;
        $sql = "SELECT * FROM {user} WHERE suspended = 0 ORDER BY random() limit 1";
        $result = $DB->get_record_sql($sql);
        if (!empty($result)) {
            return $result;
        } else {
            return false;
        }
    }

    /**
     * Get a user by site role.
     * @return bool|mixed
     * @throws dml_exception
     */
    private function get_random_user_by_roleid($roleid) {
        global $DB;
        $sql = "SELECT u.*
                  FROM {user} u
                  JOIN {role_assignments} ra ON ra.userid = u.id
                 WHERE u.suspended = 0 AND ra.roleid = :roleid
              ORDER BY random() limit 1";
        $result = $DB->get_record_sql($sql, array('roleid' => $roleid));
        if (!empty($result)) {
            return $result;
        } else {
            return false;
        }
    }

    /**
     * Get a user who is enroled in a course.
     * @param $courseid
     * @return bool|mixed
     * @throws dml_exception
     */
    private function get_random_user_by_courseid($courseid) {
        global $DB;
        $sql = "SELECT u.*
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE u.suspended = 0 AND e.courseid = :courseid
              ORDER BY random() limit 1";
        $result = $DB->get_record_sql($sql, array('courseid' => $courseid));
        if (!empty($result)) {
            return $result;
        } else {
            return false;
        }
    }

    /**
     * Get a user who is enroled in a course with a specified role.
     * This will only get student with role at Course Level.
     * It will ignore roles at other context level (module, category, block, site).
     * @param $courseid
     * @return bool|mixed
     * @throws dml_exception
     */
    private function get_random_user_by_courseid_with_roleid($courseid, $roleid) {
        global $DB;

        $coursecontext = context_course::instance($courseid);

        $sql = "SELECT u.*
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = :contextid
                 WHERE u.suspended = 0 AND e.courseid = :courseid AND ra.roleid = :roleid
              ORDER BY random() limit 1";
        $result = $DB->get_record_sql($sql, array('courseid' => $courseid, 'contextid' => $coursecontext->id, 'roleid' => $roleid));
        if (!empty($result)) {
            return $result;
        } else {
            return false;
        }
    }

}
