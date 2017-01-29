<?php
/**
 * A database layer class that relies on the MySQL PHP extension.
 *
 * @copyright Copyright (C) 2008 PunBB, partially based on code copyright (C) 2008 FluxBB.org
 * @modified Copyright (C) 2014-2017 Flazy.org
 * @license http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 * @package Flazy
 */


// Make sure we have built in support for MySQL
if (!function_exists('mysql_connect'))
	die('Эта PHP среда не имеет встроенной поддержки MySQL. Она необходима, если вы хотите использовать базу данных MySQL для работы этого форума. Изучите PHP документацию для получения дополнительной информации.');

/**
 *  Абстрактная прослойка для работы PHP с базой данных MySQL InnoDB.
 */
class DBLayer
{
	var $prefix;
	var $link_id;
	var $query_result;
	var $in_transaction = 0;

	var $saved_queries = array();
	var $num_queries = 0;

	var $datatype_transformations = array(
		'/^SERIAL$/'	=>	'INT(10) UNSIGNED AUTO_INCREMENT'
	);


	function DBLayer($db_host, $db_username, $db_password, $db_name, $db_prefix, $p_connect)
	{
		$this->prefix = $db_prefix;

		if ($p_connect)
			$this->link_id = @mysql_pconnect($db_host, $db_username, $db_password);
		else
			$this->link_id = @mysql_connect($db_host, $db_username, $db_password);

		if ($this->link_id)
		{
			if (!@mysql_select_db($db_name, $this->link_id))
				error('Невозможно выбрать базу данных. MySQL сообщила: '.mysql_error(), __FILE__, __LINE__);
		}
		else
			error('Не удается установить соединение с MySQL сервером. MySQL сообщила: '.mysql_error(), __FILE__, __LINE__);

		// Setup the client-server character set (UTF-8)
		if (!defined('FORUM_NO_SET_NAMES'))
			$this->set_names('utf8');

		return $this->link_id;
	}


	function start_transaction()
	{
		++$this->in_transaction;

		$this->query('START TRANSACTION');
		return;
	}


	function end_transaction()
	{
		--$this->in_transaction;

		$this->query('COMMIT');
		return;
	}


	function query($sql, $unbuffered = false)
	{
		if (strlen($sql) > 140000)
			die('Безумно большой запрос. Прервано.');

		if (defined('FORUM_SHOW_QUERIES'))
			$q_start = get_microtime();

		if ($unbuffered)
			$this->query_result = @mysql_unbuffered_query($sql, $this->link_id);
		else
			$this->query_result = @mysql_query($sql, $this->link_id);

		if ($this->query_result)
		{
			if (defined('FORUM_SHOW_QUERIES'))
				$this->saved_queries[] = array($sql, sprintf('%.5f', get_microtime() - $q_start));

			++$this->num_queries;

			return $this->query_result;
		}
		else
		{
			if (defined('FORUM_SHOW_QUERIES'))
				$this->saved_queries[] = array($sql, 0);

			// Rollback transaction
			if ($this->in_transaction)
				$this->query('ROLLBACK');

			--$this->in_transaction;

			return false;
		}
	}


	function query_build($query, $return_query_string = false, $unbuffered = false)
	{
		$sql = '';

		if (isset($query['SELECT']))
		{
			$sql = 'SELECT '.$query['SELECT'].' FROM '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['FROM'];

			if (isset($query['JOINS']))
			{
				foreach ($query['JOINS'] as $cur_join)
					$sql .= ' '.key($cur_join).' '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).current($cur_join).' ON '.$cur_join['ON'];
			}

			if (!empty($query['WHERE']))
				$sql .= ' WHERE '.$query['WHERE'];
			if (!empty($query['GROUP BY']))
				$sql .= ' GROUP BY '.$query['GROUP BY'];
			if (!empty($query['HAVING']))
				$sql .= ' HAVING '.$query['HAVING'];
			if (!empty($query['ORDER BY']))
				$sql .= ' ORDER BY '.$query['ORDER BY'];
			if (!empty($query['LIMIT']))
				$sql .= ' LIMIT '.$query['LIMIT'];
		}
		else if (isset($query['INSERT']))
		{
			$sql = 'INSERT INTO '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['INTO'];

			if (!empty($query['INSERT']))
				$sql .= ' ('.$query['INSERT'].')';

			if (is_array($query['VALUES']))
				$sql .= ' VALUES('.implode('),(', $query['VALUES']).')';
			else
				$sql .= ' VALUES('.$query['VALUES'].')';
		}
		else if (isset($query['UPDATE']))
		{
			$query['UPDATE'] = (isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['UPDATE'];

			$sql = 'UPDATE '.$query['UPDATE'].' SET '.$query['SET'];

			if (!empty($query['WHERE']))
				$sql .= ' WHERE '.$query['WHERE'];
		}
		else if (isset($query['DELETE']))
		{
			$sql = 'DELETE FROM '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['DELETE'];

			if (!empty($query['WHERE']))
				$sql .= ' WHERE '.$query['WHERE'];
		}
		else if (isset($query['REPLACE']))
		{
			$sql = 'REPLACE INTO '.(isset($query['PARAMS']['NO_PREFIX']) ? '' : $this->prefix).$query['INTO'];

			if (!empty($query['REPLACE']))
				$sql .= ' ('.$query['REPLACE'].')';

			$sql .= ' VALUES('.$query['VALUES'].')';
		}

		return ($return_query_string) ? $sql : $this->query($sql, $unbuffered);
	}


	function result($query_id = 0, $row = 0, $col = 0)
	{
		return ($query_id) ? @mysql_result($query_id, $row, $col) : false;
	}


	function fetch_assoc($query_id = 0)
	{
		return ($query_id) ? @mysql_fetch_assoc($query_id) : false;
	}


	function fetch_row($query_id = 0)
	{
		return ($query_id) ? @mysql_fetch_row($query_id) : false;
	}


	function num_rows($query_id = 0)
	{
		return ($query_id) ? @mysql_num_rows($query_id) : false;
	}


	function affected_rows()
	{
		return ($this->link_id) ? @mysql_affected_rows($this->link_id) : false;
	}


	function insert_id()
	{
		return ($this->link_id) ? @mysql_insert_id($this->link_id) : false;
	}


	function get_num_queries()
	{
		return $this->num_queries;
	}


	function get_saved_queries()
	{
		return $this->saved_queries;
	}


	function free_result($query_id = false)
	{
		return ($query_id) ? @mysql_free_result($query_id) : false;
	}


	function escape($str)
	{
		if (is_array($str))
			return '';
		else
			return mysql_real_escape_string($str, $this->link_id);
	}


	function error()
	{
		$result['error_sql'] = @current(@end($this->saved_queries));
		$result['error_no'] = @mysql_errno($this->link_id);
		$result['error_msg'] = @mysql_error($this->link_id);

		return $result;
	}


	function close()
	{
		if ($this->link_id)
		{
			if ($this->query_result)
				@mysql_free_result($this->query_result);

			return @mysql_close($this->link_id);
		}
		else
			return false;
	}


	function set_names($names)
	{
		return $this->query('SET NAMES \''.$this->escape($names).'\'');
	}


	function get_version()
	{
		$result = $this->query('SELECT VERSION()');

		return array(
			'name'		=> 'MySQL Стандартная (InnoDB)',
			'version'	=> preg_replace('/^([^-]+).*$/', '\\1', $this->result($result))
		);
	}


	function table_exists($table_name, $no_prefix = false)
	{
		$result = $this->query('SHOW TABLES LIKE \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\'');
		return $this->num_rows($result) > 0;
	}


	function field_exists($table_name, $field_name, $no_prefix = false)
	{
		$result = $this->query('SHOW COLUMNS FROM '.($no_prefix ? '' : $this->prefix).$table_name.' LIKE \''.$this->escape($field_name).'\'');
		return $this->num_rows($result) > 0;
	}


	function index_exists($table_name, $index_name, $no_prefix = false)
	{
		$exists = false;

		$result = $this->query('SHOW INDEX FROM '.($no_prefix ? '' : $this->prefix).$table_name);
		while ($cur_index = $this->fetch_assoc($result))
		{
			if ($cur_index['Key_name'] == ($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name)
			{
				$exists = true;
				break;
			}
		}

		return $exists;
	}


	function create_table($table_name, $schema, $no_prefix = false)
	{
		if ($this->table_exists($table_name, $no_prefix))
			return;

		$query = 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$table_name." (\n";

		// Go through every schema element and add it to the query
		foreach ($schema['FIELDS'] as $field_name => $field_data)
		{
			$field_data['datatype'] = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_data['datatype']);

			$query .= $field_name.' '.$field_data['datatype'];

			if (isset($field_data['collation']))
				$query .= 'CHARACTER SET utf8 COLLATE utf8_'.$field_data['collation'];

			if (!$field_data['allow_null'])
				$query .= ' NOT NULL';

			if (isset($field_data['default']))
				$query .= ' DEFAULT '.$field_data['default'];

			$query .= ",\n";
		}

		// If we have a primary key, add it
		if (isset($schema['PRIMARY KEY']))
			$query .= 'PRIMARY KEY ('.implode(',', $schema['PRIMARY KEY']).'),'."\n";

		// Add unique keys
		if (isset($schema['UNIQUE KEYS']))
		{
			foreach ($schema['UNIQUE KEYS'] as $key_name => $key_fields)
				$query .= 'UNIQUE KEY '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$key_name.'('.implode(',', $key_fields).'),'."\n";
		}

		// Add indexes
		if (isset($schema['INDEXES']))
		{
			foreach ($schema['INDEXES'] as $index_name => $index_fields)
				$query .= 'KEY '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name.'('.implode(',', $index_fields).'),'."\n";
		}

		// We remove the last two characters (a newline and a comma) and add on the ending
		$query = substr($query, 0, strlen($query) - 2)."\n".') ENGINE='.(isset($schema['ENGINE']) ? $schema['ENGINE'] : 'InnoDB').' CHARACTER SET utf8';

		$this->query($query) or error(__FILE__, __LINE__);
	}


	function drop_table($table_name, $no_prefix = false)
	{
		if (!$this->table_exists($table_name, $no_prefix))
			return;

		$this->query('DROP TABLE '.($no_prefix ? '' : $this->prefix).$table_name) or error(__FILE__, __LINE__);
	}


	function add_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null, $no_prefix = false)
	{
		if ($this->field_exists($table_name, $field_name, $no_prefix))
			return;

		$field_type = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_type);

		if ($default_value !== null && !is_int($default_value) && !is_float($default_value))
			$default_value = '\''.$this->escape($default_value).'\'';

		$this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' ADD '.$field_name.' '.$field_type.($allow_null ? ' ' : ' NOT NULL').($default_value !== null ? ' DEFAULT '.$default_value : ' ').($after_field != null ? ' AFTER '.$after_field : '')) or error(__FILE__, __LINE__);
	}


	function alter_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null, $no_prefix = false)
	{
		if (!$this->field_exists($table_name, $field_name, $no_prefix))
			return;

		$field_type = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_type);

		if ($default_value !== null && !is_int($default_value) && !is_float($default_value))
			$default_value = '\''.$this->escape($default_value).'\'';

		$this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' MODIFY '.$field_name.' '.$field_type.($allow_null ? ' ' : ' NOT NULL').($default_value !== null ? ' DEFAULT '.$default_value : ' ').($after_field != null ? ' AFTER '.$after_field : '')) or error(__FILE__, __LINE__);
	}


	function rename_field($table_name, $field_name, $field_new_name, $field_type, $allow_null, $default_value = null, $no_prefix = false)
	{
		if (!$this->field_exists($table_name, $field_name, $no_prefix))
			return;

		$field_type = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_type);

		if ($default_value !== null && !is_int($default_value) && !is_float($default_value))
			$default_value = '\''.$this->escape($default_value).'\'';

		$this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' CHANGE '.$field_name.' '.$field_new_name.' '.$field_type.($allow_null ? ' ' : ' NOT NULL').($default_value !== null ? ' DEFAULT '.$default_value : ' ')) or error(__FILE__, __LINE__);
	}


	function drop_field($table_name, $field_name, $no_prefix = false)
	{
		if (!$this->field_exists($table_name, $field_name, $no_prefix))
			return;

		$this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' DROP '.$field_name) or error(__FILE__, __LINE__);
	}


	function add_index($table_name, $index_name, $index_fields, $unique = false, $no_prefix = false)
	{
		if ($this->index_exists($table_name, $index_name, $no_prefix))
			return;

		$this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' ADD '.($unique ? 'UNIQUE ' : '').'INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name.' ('.implode(',', $index_fields).')') or error(__FILE__, __LINE__);
	}


	function drop_index($table_name, $index_name, $no_prefix = false)
	{
		if (!$this->index_exists($table_name, $index_name, $no_prefix))
			return;

		$this->query('ALTER TABLE '.($no_prefix ? '' : $this->prefix).$table_name.' DROP INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name) or error(__FILE__, __LINE__);
	}
}
