<?php defined('SYSPATH') or die('No direct script access.');

/**
* User Model
*
* @package		SimpleAuth
* @author			thejw23
* @copyright		(c) 2010 thejw23
* @license		http://www.opensource.org/licenses/isc-license.txt
* @version		2.0
* @last change	
* 
* based on KohanaPHP Auth and Simple_Modeler
*/

class Model_Auth_Users extends authmodeler {

	protected $table_name = 'auth_users';
	
	protected $unique_field = '';
	protected $second_field = '';
	protected $password_field = '';
		
	protected $data = array('id' => '',
						'username' => '',
						'password' => '',
						'email' => '',
						'logins' => '',
						'admin'=>'',
						'moderator'=>'',
						'active' => '',
						'active_to'=>'',
						'ip_address'=>'',
						'last_ip_address'=>'',
						'time_stamp'=>'',
						'last_time_stamp' => '',
						'time_stamp_created'=>''); 

	public $timestamp = array ();
	
	public function __construct($id = NULL)
	{
		parent::__construct($id);
		
		$auth_config =  Kohana::$config->load('simpleauth');
		
		$this->unique_field = $auth_config['unique'];
		$this->second_field =  $auth_config['unique_second'];
		$this->password_field =  $auth_config['password']; 
	}

	public function get_user($unique, $pass)
	{
		$data =  db::select('*')->from($this->table_name)->where($this->unique_field,'=',$unique)->and_where($this->password_field,'=',$pass)->execute();

		if (count($data) === 1 AND $data = $data->current())
		{
			$this->data_original = (array) $data;
			$this->data = $this->data_original; 
		}
	}
	
	/**
	 * Check if username exists in database.
	 *
	 * @param string $name username to check
	 * @param string $second second username to check 	 
	 * @return boolean
	 */
	public function user_exists($name = NULL, $second = NULL)
	{
		if (!empty($second))
		{
			return count(db::select('id')->from($this->table_name)->where($this->unique_field, '=' , $name)->or_where($this->second_field, '=', $second)->execute());
		}
		else
		{
			return count(db::select('id')->from($this->table_name)->where($this->unique_field, '=' ,$name)->execute());
		}
	}
	
	public function save()
	{
		if ($this->is_sha1($this->password_field))
		{
			$password_field = $this->password_field;
			$this->$password_field = SimpleAuth::Instance()->hash($this->$password_field);
		}
		if (!$this->is_sha1($this->{$this->password_field}))
		{
			$this->{$this->password_field} = SimpleAuth::Instance()->hash($this->{$this->password_field});
		}		
		return parent::save();
	}
	
	public static function is_sha1($str = '') 
	{
		return (bool) preg_match('/^[0-9a-f]{40}$/i', $str);
	}
	
} // End Auth Users Model
