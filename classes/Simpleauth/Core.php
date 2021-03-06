<?php namespace Simpleauth;

/**
 * Simple_Auth - user authorization library for KohanaPHP framework
 *
 * @package    SimpleAuth
 * @author      thejw23
 * @copyright    (c) 2010 thejw23
 * @license    http://www.opensource.org/licenses/isc-license.txt
 * @version    2.0
 * @last change
 *
 * based on KohanaPHP Auth and Simple_Modeler
 */

class Core
{

  // Session instance
  protected $session;

  // Configuration
  protected $config;

  /**
   * Creates a new class instance, loading the session and storing config.
   *
   * @param array $config configuration
   * @return void
   */
  public function __construct($config = 'simpleauth')
  {
    // Save the config in the object
    $this->config = \Hanariu::$config->load($config);
    $this->session = \Hanariu\Session::instance();
  }


  /**
   * Create an instance of Simple_Auth.
   *
   * @param array $config configuration
   * @return object
   */
  public static function factory($config = 'simpleauth')
  {
    return new Core($config);
  }

  /**
   * Return a static instance of Simple_Auth.
   *
   * @param array $config configuration
   * @return object
   */
  public static function instance($config = 'simpleauth')
  {
    static $instance;

    empty($instance) and $instance = new Core($config);

    return $instance;
  }

  /**
   * Return a static instance of Simple_Auth.
   *
   * @param array $config configuration
   * @return object
   */
  public function is_admin()
  {
    $is_admin = FALSE;

    $user = $this->session->get($this->config['session_key']);

    if (is_object($user) AND $user instanceof \Simpleuser\Core) {
      $is_admin = in_array($user->role_id, $this->config['is_admin']);
    }

    return $is_admin;
  }

  /**
   * Perform a hash, using the configured method.
   *
   * @param string $str password to hash
   * @return string
   */
  public function hash($str = '')
  {
    // on some servers hash() can be disabled :( then password is not encrypted
    if (empty($this->config['hash_method'])) {
      return $this->config['salt_prefix'] . $str . $this->config['salt_suffix'];
    } else {
      return hash($this->config['hash_method'], $this->config['salt_prefix'] . $str . $this->config['salt_suffix']);
    }
  }

  /**
   * Complete the login for a user by incrementing the logins and setting
   * session data
   *
   * @param object $user user model object
   * @return void
   */
  protected function complete_login($user = NULL)
  {
    if (!is_object($user) OR !$user instanceof \Model\Auth\Users)
      return FALSE;

    $user->timestamp = array('time_stamp');
    $user->logins += 1;
    $user->last_time_stamp = $user->time_stamp;
    $user->last_ip_address = $user->ip_address;
    $user->ip_address = $_SERVER['REMOTE_ADDR'];
    $user->save();

    $this->session->regenerate();

    $simple_user = new \Simpleuser\Core;
    $simple_user->set_user($user->as_array());

    $this->session->set($this->config['session_key'], $simple_user);

    return TRUE;
  }

  /**
   * Assign role to user, by default to current user
   *
   * @param array $role array role=>status, where role is admin/active/moderator
   *        and status is integer 0/1 or boolean
   * @param integer  user ID
   * @return boolean
   */
  public function set_role($role = array(), $user = 0)
  {
    if (!is_array($role))
      return FALSE;

    // role must be an array with only valid roles (by default: admin, active, moderator)
    $role = array_intersect_key($role, $this->config['roles']);

    // if no valid key, quit
    if (empty($role))
      return FALSE;


    $user_model = $this->get_user($user);
    /*elseif (( ! is_object($user)) AND (intval($user) === 0))
    {
      $user = $this->get_user();
    }

    $user_model = new Model_Auth_Users($user->id);*/

    if (!$user_model->loaded())
      return FALSE;

    foreach ($role as $key => $value) {
      $user_model->{$key} = intval($value);
    }

    return ($user_model->save()) ? TRUE : FALSE;
  }

  /**
   * Log a user out.
   *
   * @param boolean $destroy completely destroy the session
   * @return boolean
   */
  public function logout($destroy = FALSE)
  {
    if (!$this->logged_in())
      return FALSE;

    $user = $this->get_user();

    if (intval($user->{$this->config['primary_key']}) !== 0) {
      \Authmodeler\Core::factory('Auth\\User\\Tokens')->delete_user_tokens($user->{$this->config['primary_key']});
    }

    if ($destroy === TRUE) {
      \Hanariu\Session::instance()->destroy();
    } else {
      $this->session->delete($this->config['session_key']);
      $this->session->regenerate();
    }

    \Hanariu\Cookie::delete($this->config['cookie_key']);

    // Double check
    return !$this->logged_in();
  }

  /**
   * Checks if user has been already logged in
   *
   * @return boolean
   */
  public function logged_in()
  {
    $status = FALSE;

    $user = $this->session->get($this->config['session_key']);

    if (is_object($user) AND $user instanceof \Simpleuser\Core) {
      $status = TRUE;
    }

    if (!$status) $status = $this->auto_login();
    return $status;
  }


  /**
   * Gets the currently logged in user from the session.
   * Returns FALSE if no user is currently logged in.
   *
   * @param object|integer $user unique user to be loaded
   * @return mixed
   */
  public function get_user($user = 0)
  {
    $user = intval($user);
    if (($user === 0) AND ($this->logged_in()))
      return $this->session->get($this->config['session_key']);

    if ($user !== 0) {
      $user_model = \Authmodeler\Core::instance('Auth\\Users')->load($user);
      if ($user_model->loaded()) {
        return $user_model;
      }
    }

    return FALSE;
  }

  /**
   * Logs a user in, based on unique token stored in cookie.
   *
   * @return boolean
   */
  public function auto_login()
  {
    if ($token = \Hanariu\Cookie::get($this->config['cookie_key'])) {
      $token_model = \Authmodeler\Core::instance('Auth\\User\\Tokens')->load($token);

      if (!$token_model->loaded()) {
        return FALSE;
      }

      if ($token_model->user_agent === sha1(\Hanariu\Request::user_agent('browser'))) {
        $user = new \Model\Auth\Users($token_model->user_id);

        if (!$user->loaded() OR (intval($user->active) === 0)) {
          $token_model->delete_user_tokens($token_model->user_id, TRUE);
          return FALSE;
        }

        if (strtotime($user->active_to)) {
          $now = date('Y-m-d H:i:s');
          if ($user->active_to < $now) {
            return FALSE;
          }
        }

        $token_model->expires = date('Y-m-d H:i:s', mktime(date('H'), date('i'), date('s') + $this->config['lifetime'], date('m'), date('d'), date('Y')));
        $token_model->save();

        \Hanariu\Cookie::set($this->config['cookie_key'], $token_model->token, strtotime($token_model->expires) - time());

        $this->complete_login($user);

        return TRUE;
      }

      // Token is invalid
      $token_model->delete();
    }

    return FALSE;
  }

  /**
   * Creates new user
   *
   * @param array $user_data user data to add
   * @param string $second name of second unique field to verify
   * @return  boolean
   */
  public function create_user($user_data = NULL, $second = FALSE)
  {
    $password_field = $this->config['password'];

    if (empty($user_data) OR !$user_data instanceof \Model\Auth\Users)
      return FALSE;

    $user = new \Model\Auth\Users();

    if ($second) {
      $user_exist = $user->user_exists($user_data->{$this->config['unique']}, $user_data->{$this->config['unique_second']});
    } else {
      $user_exist = $user->user_exists($user_data->{$this->config['unique']});
    }

    if (!$user_exist) {
      if (isset($user_data->active_to) AND !strtotime($user->active_to)) {
        $user_data->active_to = '';
      }

      // to make sure that $user_data['admin']=true works the same as $user_data['admin']=1
      $roles = $this->config['roles'];
      foreach ($roles as $key => $value) {
        if (array_key_exists($key, $user_data)) {
          $user_data->{$key} = intval($user_data->{$key});
        }
      }

      $user->set_fields($user_data->as_array());
      //$user->{$password_field} = $this->hash($user->{$password_field});

      return ($result = $user->save()) ? $result : FALSE;
    }

    return FALSE;
  }

  /**
   * Deletes user from db
   *
   * @param integer $user unique user id
   * @return boolean
   */
  public function delete_user($id = 0)
  {
    $id = intval($id);
    if (intval($id) === 0)
      return FALSE;

    $user_model = new \Model\Auth\Users($id);
    if (!$user_model->loaded())
      return FALSE;

    return ($user_model->delete()) ? TRUE : FALSE;
  }

  /**
   * Modify user in db
   *
   * @param array $data data to change
   * @param integer $id optional user ID
   * @return boolean
   */
  public function modify_user($data = array(), $id = 0)
  {
    $result = FALSE;
    if (!$this->logged_in()) {
      return FALSE;
    }

    if (empty($id)) {
      $user = $this->get_user();
      $user_model = new \Model\Auth\Users(intval($user->id));

    } else {
      $user_model = new \Model\Auth\Users(intval($id));
    }

    if ($user_model->loaded()) {
      foreach ($data as $key => $value) {
        $user_model->$key = $value;
      }
      $result = $user_model->save();
    }

    if ($result) {
      $this->reload_user();
    }

    return $result;
  }

  /**
   * Reload user properties from db
   *
   * @return mixed
   */
  public function reload_user()
  {
    if ($this->logged_in()) {
      $user_data = $this->get_user();
      $user_model = new \Model\Auth\Users($user_data->{$this->config['primary_key']});
      if (!$user_model->loaded())
        return FALSE;

      if (intval($user_model->active) === 1) {
        $simple_user = new \Simpleuser\Core;
        $simple_user->set_user($user_model->as_array());
        $this->session->set($this->config['session_key'], $simple_user);
      } else {
        $this->logout();
      }
    }
  }


  /**
   * Attempt to log in a user.
   *
   * @param string $user username to log in
   * @param string $password password to check against
   * @param boolean $remember enable auto-login
   * @return boolean
   */
  public function login($login = '', $password = '', $remember = FALSE)
  {
    $password_field = $this->config['password'];

    if (empty($password) OR empty($login) OR !is_string($password) OR !is_string($login))
      return FALSE;

    $password = $this->hash($password);
    $user = new \Model\Auth\Users;
    $user->get_user($login, $password);

    if (!$user->loaded())
      return FALSE;

    if ((intval($user->active) === 1) AND ($user->{$password_field} == $password)) {
      if (strtotime($user->active_to) !== FALSE) {
        $now = date('Y-m-d H:i:s');
        if ($user->active_to < $now)
          return FALSE;
      }

      if ($remember === TRUE) {
        $token_model = new \Model\Auth\User\Tokens();
        $token_model->delete_user_tokens($user->{$this->config['primary_key']});
        $token_model->user_id = $user->{$this->config['primary_key']};
        $token_model->expires = date('Y-m-d H:i:s', mktime(date('H'), date('i'), date('s') + $this->config['lifetime'], date('m'), date('d'), date('Y')));
        $token_model->save();

        \Hanariu\Cookie::set($this->config['cookie_key'], $token_model->token, $this->config['lifetime']);
      }

      $this->complete_login($user);

      return TRUE;
    }

    // Login failed
    return FALSE;
  }

}
