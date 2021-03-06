<?php

/**
 *
 * @package    Mind
 * @version    Release: 4.8.9
 * @license    GPL3
 * @author     Ali YILMAZ <aliyilmaz.work@gmail.com>
 * @category   Php Framework, Design pattern builder for PHP.
 * @link       https://github.com/aliyilmaz/Mind
 *
 */

/**
 * Class Mind
 */
class Mind extends PDO
{
    private $sess_set       =  [
        'path'                  =>  './session/',
        'path_status'           =>  false,
        'status_session'        =>  true
    ];

    public  $post           =   [];
    public  $base_url;
    public  $allow_folders  =   'public';
    public  $page_current   =   '';
    public  $page_back      =   '';
    public  $timezone       =   'Europe/Istanbul';
    public  $timestamp;
    public  $lang           =   [
        'table'     =>  'translations',
        'column'    =>  'lang',
        'haystack'  =>  'name',
        'return'    =>  'text',
        'lang'      =>  'EN'
    ];

    public  $sms_conf       =   [];
    public  $error_status   =   false;
    public  $errors         =   [];

    private $db             =   [
        'drive'     =>  'mysql', // mysql, sqlite
        'host'      =>  'localhost',
        'dbname'    =>  'mydb', // mydb, app/migration/mydb.sqlite
        'username'  =>  'root',
        'password'  =>  '',
        'charset'   =>  'utf8mb4'
    ];
    
    /**
     * Mind constructor.
     * @param array $conf
     */
    public function __construct($conf = array()){
        ob_start();
        
        /* error settings */
        error_reporting(-1);
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        /* server limit settings */
        ini_set('memory_limit', '-1');
        (strpos(ini_get('disable_functions'), 'set_time_limit') === false) ?: set_time_limit(0);
        
        /* Creating the timestamp */
        date_default_timezone_set($this->timezone);
        $this->timestamp = date("Y-m-d H:i:s");

        /* Directory accesses */
        $this->allow_folders = (isset($conf['allow_folders'])) ? $conf['allow_folders'] : $this->allow_folders;

        /* Database connection */
        $this->dbConnect($conf);
    
        /* Interpreting Get, Post, and Files requests */
        $this->request();

        /* Enabling session management */
        $this->session_check();

        /* Activating the firewall */
        $this->firewall($conf);

        /* Providing translation settings */
        if(isset($conf['translate'])){
            $this->lang['table']    = (isset($conf['translate']['table'])) ?: $conf['translate']['table'];
            $this->lang['column']   = (isset($conf['translate']['column'])) ?: $conf['translate']['column'];
            $this->lang['haystack'] = (isset($conf['translate']['haystack'])) ?: $conf['translate']['haystack'];
            $this->lang['return']   = (isset($conf['translate']['return'])) ?: $conf['translate']['return'];
            $this->lang['lang']     = (isset($conf['translate']['lang'])) ?: $conf['translate']['lang'];
        }

        /* Providing sms settings */
        if(isset($conf['sms'])){ $this->sms_conf = (is_array($conf['sms'])) ?: $conf['sms']; }

        /* Determining the home directory path (Mind.php) */
        $baseDir = $this->get_absolute_path(dirname($_SERVER['SCRIPT_NAME']));
        $this->base_url = (empty($baseDir)) ? '/' : '/'.$baseDir.'/';

        /* Determining the previous page address */
        $this->page_back = (isset($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : $this->page_current;

    }

    public function __destruct()
    {
        if($this->error_status){ $this->abort('404', 'Not Found.'); exit(); }
    }

    /**
     * @param array|null $conf
     */
    public function dbConnect($conf = array()){

        if(isset($conf['db']['drive'])){ $this->db['drive'] = $conf['db']['drive'];}
        if(isset($conf['db']['host'])){ $this->db['host'] = $conf['db']['host'];}
        if(isset($conf['db']['dbname'])){ $this->db['dbname'] = $conf['db']['dbname'];}
        if(isset($conf['db']['username'])){ $this->db['username'] = $conf['db']['username'];}
        if(isset($conf['db']['password'])){ $this->db['password'] = $conf['db']['password'];}     
        if(isset($conf['db']['charset'])){ $this->db['charset'] = $conf['db']['charset'];}     

        try {

            switch ($this->db['drive']) {
                case 'mysql': 
                    parent::__construct($this->db['drive'].':host='.$this->db['host'].';charset='.$this->db['charset'].';', $this->db['username'], $this->db['password']);
                break;
                case 'sqlite': 
                    parent::__construct($this->db['drive'].':'.$this->db['dbname']);
                break;
            }

            if(!$this->is_db($this->db['dbname'])){ $this->dbCreate($this->db['dbname']); } 
            $this->selectDB($this->db['dbname']);           
            
        } catch ( PDOException $e ){
            print $e->getMessage();
        }

        return $this;
    }

    /**
     * Database selector.
     *
     * @param string $dbName
     * @return bool
     */
    public function selectDB($dbName){
        if($this->is_db($dbName)){

            switch ($this->db['drive']) {
                case 'mysql':                    
                    $this->exec("USE ".$dbName);
                break;
            }
        } else {
            return false;
        }
        
        $this->setAttribute( PDO::ATTR_EMULATE_PREPARES, true );
        $this->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
        $this->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
        $this->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        return true;
    }

    /**
     * Lists the databases.
     *
     * @return array
     */
    public function dbList(){

        $dbnames = array();

        switch ($this->db['drive']) {
            case 'mysql':
                $sql     = 'SHOW DATABASES';

                try{
                    $query = $this->query($sql, PDO::FETCH_ASSOC);

                    foreach ( $query as $database ) {
                        $dbnames[] = implode('', $database);
                    }

                    return $dbnames;

                } catch (Exception $e){
                    return $dbnames;
                }

            break;
            
            case 'sqlite':
                return $this->ffsearch('./', '*.sqlite');
            break;
        }
        
    }

    /**
     * Lists database tables.
     *
     * @param string $dbName
     * @return array
     */
    public function tableList($dbname=null){

        $query = [];
        $tblNames = array();

        try{

            switch ($this->db['drive']) {
                case 'mysql':
                    $dbParameter = (!is_null($dbname)) ? ' FROM '.$dbname : '';
                    $sql = 'SHOW TABLES'.$dbParameter;
                    $query = $this->query($sql, PDO::FETCH_ASSOC);
                    foreach ($query as $tblName){
                        $tblNames[] = implode('', $tblName);
                    }
                break;
                
                case 'sqlite':
                    $statement = $this->query("SELECT name FROM sqlite_master WHERE type='table';");
                    $query = $statement->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($query as $tblName){
                        $tblNames[] = $tblName['name'];
                    }
                break;
            }

        } catch (Exception $e){
            return $tblNames;
        }

        return $tblNames;
    }

    /**
     * Lists table columns.
     *
     * @param string $tblName
     * @return array
     */
    public function columnList($tblName){

        $columns = array();

        switch ($this->db['drive']) {
            case 'mysql':
                $sql = 'SHOW COLUMNS FROM `' . $tblName.'`';

                try{
                    $query = $this->query($sql, PDO::FETCH_ASSOC);

                    $columns = array();

                    foreach ( $query as $column ) {

                        $columns[] = $column['Field'];
                    }

                } catch (Exception $e){
                    return $columns;
                }
            break;
            case 'sqlite':
                
                $statement = $this->query('PRAGMA TABLE_INFO(`'. $tblName . '`)');
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $key => $column) {
                    $columns[] = $column['name'];
                }
            break;
        }

        return $columns;
        
    }

    /**
     * Creating a database.
     *
     * @param mixed $dbname
     * @return bool
     */
    public function dbCreate($dbname){

        $dbnames = array();
        $dbnames = (is_array($dbname)) ? $dbname : [$dbname];

        try{
            foreach ( $dbnames as $dbname ) {

                switch ($this->db['drive']) {
                    case 'mysql':
                        $sql = "CREATE DATABASE";
                        $sql .= " ".$dbname." DEFAULT CHARSET=".$this->db['charset'];
                        if(!$this->query($sql)){ return false; }
                    break;
                    
                    case 'sqlite':
                        if(!file_exists($dbname) AND $dbname !== $this->db['dbname']){
                            $this->dbConnect(['db'=>['dbname'=>$dbname]]);
                        }
                    break;
                }
                if($dbname === $this->db['dbname']){ 
                    $this->dbConnect(['db'=>['dbname'=>$dbname]]); 
                }

            }
            
        }catch (Exception $e){
            return false;
        }

        return true;
    }

    /**
     * Creating a table.
     *
     * @param string $tblName
     * @param array $scheme
     * @return bool
     */
    public function tableCreate($tblName, $scheme){

        if(is_array($scheme) AND !$this->is_table($tblName)){
            // switch
            $engine = '';
            switch ($this->db['drive']) {
                case 'mysql':
                    $engine = " ENGINE = INNODB";
                break;
            }
         
            try{

                $sql = "CREATE TABLE `".$tblName."` ";
                $sql .= "(\n\t";
                $sql .= implode(",\n\t", $this->columnSqlMaker($scheme));
                $sql .= "\n)".$engine.";";

                if(!$this->query($sql)){
                    return false;
                }
                return true;
            }catch (Exception $e){
                return false;
            }
        }

        return false;

    }

    /**
     * Creating a column.
     *
     * @param string $tblName
     * @param array $scheme
     * @return bool
     */
    public function columnCreate($tblName, $scheme){

        if($this->is_table($tblName)){

            try{

                $sql = "ALTER TABLE\n";
                $sql .= "\t`".$tblName."`\n";
                $sql .= implode(",\n\t", $this->columnSqlMaker($scheme, 'columnCreate'));

                if(!$this->query($sql)){
                    return false;
                } else {
                    return true;
                }

            }catch (Exception $e){
                return false;
            }
        }

        return false;
    }

    /**
     * Delete database.
     *
     * @param mixed $dbname
     * @return bool
     */
    public function dbDelete($dbname){

        $dbnames = array();

        if(is_array($dbname)){
            foreach ($dbname as $key => $value) {
                $dbnames[] = $value;
            }
        } else {
            $dbnames[] = $dbname;
        }
        foreach ($dbnames as $dbname) {

            if(!$this->is_db($dbname)){

                return false;

            }

            switch ($this->db['drive']) {
                case 'mysql':
                    try{

                        $sql = "DROP DATABASE";
                        $sql .= " ".$dbname;
        
                        $query = $this->query($sql);
                        if(!$query){
                            return false;
                        }
                    }catch (Exception $e){
                        return false;
                    }
                break;
                
                case 'sqlite':
                    if(file_exists($dbname)){
                        unlink($dbname);
                    } else {
                        return false;
                    }
                   
                break;
            }
            

        }
        return true;
    }

    /**
     * Table delete.
     *
     * @param mixed $tblName
     * @return bool
     */
    public function tableDelete($tblName){

        $tblNames = array();

        if(is_array($tblName)){
            foreach ($tblName as $key => $value) {
                $tblNames[] = $value;
            }
        } else {
            $tblNames[] = $tblName;
        }
        foreach ($tblNames as $tblName) {

            if(!$this->is_table($tblName)){

                return false;

            }

            try{

                $sql = "DROP TABLE";
                $sql .=" `".$tblName.'`';

                $query = $this->query($sql);
                if(!$query){
                    return false;
                }
            }catch (Exception $e){
                return false;
            }
        }
        return true;
    }

    /**
     * Column delete.
     *
     * @param string $tblName
     * @param mixed $column
     * @return bool
     */
    public function columnDelete($tblName, $column = null){

        $columnList = $this->columnList($tblName);

        $columns = array();
        $columns = (!is_null($column) AND is_array($column)) ? $column : $columns; // array
        $columns = (!is_null($column) AND !is_array($column)) ? [$column] : $columns; // string

        switch ($this->db['drive']) {
            case 'mysql':
                $sql = "ALTER TABLE `".$tblName."`";
                foreach ($columns as $column) {

                    if(!in_array($column, $columnList)){
                        return false;
                    }
                    $dropColumns[] = "DROP COLUMN `".$column."`";
                }

                try{
                    $sql .= " ".implode(', ', $dropColumns);
                    $query = $this->query($sql);
                    if(!$query){
                        return false;
                    }
                }catch (Exception $e){
                    return false;
                }
            break;
            
            case 'sqlite':
                $output = [];
                
                $data = $this->getData($tblName);
                foreach ($data as $key => $row) {
                    foreach ($columns as $key => $column) {
                        if(in_array($column, array_keys($row)) AND in_array($column, $columnList)){
                            unset($row[$column]);
                        }
                    }
                    $output['data'][] = $row;
                }

                try{
                    
                    $scheme = $this->tableInterpriter($tblName, $columns);
                    $this->tableDelete($tblName);
                    $this->tableCreate($tblName, $scheme);
                    if(!empty($output['data'])){
                        $this->insert($tblName, $output['data']);
                    }
                    
                }catch (Exception $e){
                    return false;
                }
                
            break;
        }
        
        return true;
    }

    /**
     * Clear database.
     *
     * @param mixed $dbName
     * @return bool
     * */
    public function dbClear($dbName){

        $dbNames = array();

        if(is_array($dbName)){
            foreach ($dbName as $db) {
                $dbNames[] = $db;
            }
        } else {
            $dbNames[] = $dbName;
        }

        foreach ( $dbNames as $dbName ) {

            $this->dbConnect($dbName);
            foreach ($this->tableList($dbName) as $tblName){
                if(!$this->tableClear($tblName)){
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Clear table.
     *
     * @param mixed $tblName
     * @return bool
     */
    public function tableClear($tblName){

        $tblNames = array();

        if(is_array($tblName)){
            foreach ($tblName as $value) {
                $tblNames[] = $value;
            }
        } else {
            $tblNames[] = $tblName;
        }

        foreach ($tblNames as $tblName) {

            $sql = '';
            switch ($this->db['drive']) {
                case 'mysql':
                    $sql = 'TRUNCATE `'.$tblName.'`';
                break;
                case 'sqlite':
                    $sql = 'DELETE FROM `'.$tblName.'`';
                break;
            }
            
            try{
                if($this->query($sql)){
                    return true;
                } else {
                    return false;
                }
            } catch (Exception $e){
                return false;
            }

        }
        return true;
    }

    /**
     * Clear column.
     *
     * @param string $tblName
     * @param mixed $column
     * @return bool
     */
    public function columnClear($tblName, $column=null){

        if(empty($column)){
            return false;
        }

        $columns = array();

        if(is_array($column)){
            foreach ($column as $col) {
                $columns[] = $col;
            }
        } else {
            $columns[] = $column;
        }

        $columns = array_intersect($columns, $this->columnList($tblName));

        foreach ($columns as $column) {

            $id   = $this->increments($tblName);
            $data = $this->getData($tblName);

            foreach ($data as $row) {
                $values = array(
                    $column => ''
                );
                $this->update($tblName, $values, $row[$id]);
            }
        }

        return true;

    }

    /**
     * Add new record.
     *
     * @param string $tblName
     * @param array $values
     * @return bool
     */
    public function insert($tblName, $values, $trigger=null){
        
        if(!isset($values[0])){
            $values = array($values);
        } 
        if(!isset($trigger[0]) AND !is_null($trigger)){
            $trigger = array($trigger);
        } 
        
        try {
            $this->beginTransaction();
            foreach ($values as $rows) {
                $sql = '';
                $columns = [];
                $values = [];
                $sql .= 'INSERT INTO `'.$tblName.'` ';
                foreach (array_keys($rows) as $col) {
                    $columns[] = $col;
                    $values[] = '?';
                }
                $sql .= '('.implode(', ', $columns).')';
                $sql .= ' VALUES ('.implode(', ', $values).')';
                $this->prepare($sql)->execute(array_values($rows));
            }
            if(!is_null($trigger)){
                foreach ($trigger as $row) {
                    foreach ($row as $table => $data) {
                        $sql = '';
                        $columns = [];
                        $values = [];
                        $sql .= 'INSERT INTO `'.$table.'` ';
                        foreach (array_keys($data) as $col) {
                            $columns[] = $col;
                            $values[] = '?';
                        }
                        $sql .= '('.implode(', ', $columns).')';
                        $sql .= ' VALUES ('.implode(', ', $values).')';
                        $this->prepare($sql)->execute(array_values($data));
                    }
                }
            }

            $this->commit();

            return true;

        } catch (Exception $e) {
            $this->rollback();
            echo $e->getMessage();
        }
        return false;
    }

    /**
     * Record update.
     *
     * @param string $tblName
     * @param array $values
     * @param string $needle
     * @param mixed $column
     * @return bool
     */
    public function update($tblName, $values, $needle, $column=null){

        if(empty($column)){

            $column = $this->increments($tblName);

            if(empty($column)){
                return false;
            }

        }

        $xColumns = array_keys($values);

        $columns = $this->columnList($tblName);

        $prepareArray = array();
        foreach ( $xColumns as $col ) {

            if(!in_array($col, $columns)){
                return false;
            }

            $prepareArray[] = $col.'=?';
        }

        $values[$column] = $needle;

        $values = array_values($values);

        $sql = implode(',', $prepareArray);
        $sql .= ' WHERE '.$column.'=?';
        try{
            $this->beginTransaction();
            $query = $this->prepare("UPDATE".' `'.$tblName.'` SET '.$sql);
            $query->execute($values);
            $this->commit();
            return true;
        }catch (Exception $e){
            $this->rollback();
            echo $e->getMessage();
        }
        return false;
    }

    /**
     * Record delete.
     *
     * @param string $tblName
     * @param mixed $needle
     * @param mixed $column
     * @return bool
     */
    public function delete($tblName, $needle, $column=null, $trigger=null, $force=null){

        $status = false;

        // status
        if(is_bool($column)){
            $status = $column;
            $column = $this->increments($tblName);
            if(empty($column)) return false;
        }

        if(empty($column)){

            $column = $this->increments($tblName);
            if(empty($column)) return false;

        }

        if(is_bool($trigger) AND is_array($column)){ 
            $status = $trigger; 
            $trigger = $column;
            $column = $this->increments($tblName);
            if(empty($column)) return false;
        }

        if(is_bool($trigger) AND is_string($column)){ 
            $status = $trigger; 
        }

        if(is_null($trigger) AND is_array($column)){
            $trigger = $column;
            $column = $this->increments($tblName);
            if(empty($column)) return false;
        }

        if(is_bool($force)){
            $status = $force;
        }

        if(!is_array($needle)){
            $needle = array($needle);
        }

        $sql = 'WHERE '.$column.'=?';
        try{
            $this->beginTransaction();

            if(!$status){
                foreach ($needle as $value) {
                    if(!$this->do_have($tblName, $value, $column)){
                        return false;
                    }
                }
            }

            if(is_null($trigger)){
                foreach ($needle as $value) {
                    $query = $this->prepare("DELETE FROM".' `'.$tblName.'` '.$sql);
                    $query->execute(array($value));
                }
            }
            
            if(!is_null($trigger)){
                foreach ($needle as $value) {
                    $sql = 'WHERE '.$column.'=?';
                    $query = $this->prepare("DELETE FROM".' `'.$tblName.'` '.$sql);
                    $query->execute(array($value));

                    if(is_array($trigger)){

                        foreach ($trigger as $table => $col) {
                            $sql = 'WHERE '.$col.'=?';
                            $query = $this->prepare("DELETE FROM".' `'.$table.'` '.$sql);
                            $query->execute(array($value));
                        }

                    }
                }
                
            }

            $this->commit();
            return true;
        }catch (Exception $e){
            $this->rollBack();
            return false;
        }
    }

    /**
     * Record reading.
     *
     * @param string $tblName
     * @param array $options
     * @return array
     */
    public function getData($tblName, $options=null){

        $sql = '';
        $andSql = '';
        $orSql = '';
        $keywordSql = '';
        $columns = $this->columnList($tblName);

        if(!empty($options['column'])){

            if(!is_array($options['column'])){
                $options['column']= array($options['column']);
            }

            $options['column'] = array_intersect($options['column'], $columns);
            $columns = array_values($options['column']);
        } 
        $sqlColumns = $tblName.'.'.implode(', '.$tblName.'.', $columns);

        $prefix = '';
        $suffix = ' = ?';
        if(!empty($options['search']['scope'])){
            $options['search']['scope'] = mb_strtoupper($options['search']['scope']);
            switch ($options['search']['scope']) {
                case 'LIKE':  $prefix = ''; $suffix = ' LIKE ?'; break;
            }
        }

        $prepareArray = array();
        $executeArray = array();

        if(isset($options['search']['keyword'])){

            if ( !is_array($options['search']['keyword']) ) {
                $keyword = array($options['search']['keyword']);
            } else {
                $keyword = $options['search']['keyword'];
            }

            $searchColumns = $columns;
            if(!empty($options['search']['column'])){

                if(!is_array($options['search']['column'])){
                    $searchColumns = array($options['search']['column']);
                } else {
                    $searchColumns = $options['search']['column'];
                }

                $searchColumns = array_intersect($searchColumns, $columns);
            }

            foreach ( $searchColumns as $column ) {

                foreach ( $keyword as $value ) {
                    $prepareArray[] = $prefix.$column.$suffix;
                    $executeArray[] = $value;
                }

            }

            $keywordSql .= '('.implode(' OR ', $prepareArray).')';

        }

        $delimiterArray = array('and', 'AND', 'or', 'OR');
        
        if(!empty($options['search']['delimiter']['and'])){
            if(in_array($options['search']['delimiter']['and'], $delimiterArray)){
                $options['search']['delimiter']['and'] = mb_strtoupper($options['search']['delimiter']['and']);
            } else {
                $options['search']['delimiter']['and'] = ' AND ';
            }
        } else {
            $options['search']['delimiter']['and'] = ' AND ';
        }

        if(!empty($options['search']['delimiter']['or'])){
            if(in_array($options['search']['delimiter']['or'], $delimiterArray)){
                $options['search']['delimiter']['or'] = mb_strtoupper($options['search']['delimiter']['or']);
            } else {
                $options['search']['delimiter']['or'] = ' OR ';
            }
        } else {
            $options['search']['delimiter']['or'] = ' OR ';
        }

        if(!empty($options['search']['or']) AND is_array($options['search']['or'])){

            if(!isset($options['search']['or'][0])){
                $options['search']['or'] = array($options['search']['or']);
            }

            foreach ($options['search']['or'] as $key => $row) {

                foreach ($row as $column => $value) {

                    $x[$key][] = $prefix.$column.$suffix;
                    $prepareArray[] = $prefix.$column.$suffix;
                    $executeArray[] = $value;
                }
                
                $orSql .= '('.implode(' OR ', $x[$key]).')';

                if(count($options['search']['or'])>$key+1){
                    $orSql .= ' '.$options['search']['delimiter']['or']. ' ';
                }
            }
        }

        if(!empty($options['search']['and']) AND is_array($options['search']['and'])){

            if(!isset($options['search']['and'][0])){
                $options['search']['and'] = array($options['search']['and']);
            }

            foreach ($options['search']['and'] as $key => $row) {

                foreach ($row as $column => $value) {

                    $x[$key][] = $prefix.$column.$suffix;
                    $prepareArray[] = $prefix.$column.$suffix;
                    $executeArray[] = $value;
                }
                
                $andSql .= '('.implode(' AND ', $x[$key]).')';

                if(count($options['search']['and'])>$key+1){
                    $andSql .= ' '.$options['search']['delimiter']['and']. ' ';
                }
            }

        }

        $delimiter = ' AND ';
        $sqlBox = array();

        if(!empty($keywordSql)){
            $sqlBox[] = $keywordSql;
        }

        if(!empty($andSql) AND !empty($orSql)){
            $sqlBox[] = '('.$andSql.$delimiter.$orSql.')';
        } else {
            if(!empty($andSql)){
                $sqlBox[] = '('.$andSql.')';
            }
            if(!empty($orSql)){
                $sqlBox[] = '('.$orSql.')';
            }
        }

        if(
            !empty($options['search']['or']) OR
            !empty($options['search']['and']) OR
            !empty($options['search']['keyword'])
        ){
            $sql = 'WHERE '.implode($delimiter, $sqlBox);            
        }

        if(!empty($options['sort'])){

            list($columnName, $sort) = explode(':', $options['sort']);
            if(in_array($sort, array('asc', 'ASC', 'desc', 'DESC'))){
                $sql .= ' ORDER BY '.$columnName.' '.strtoupper($sort);
            }

        }

        if(!empty($options['limit'])){

            if(!empty($options['limit']['start']) AND $options['limit']['start']>0){
                $start = $options['limit']['start'].',';
            } else {
                $start = '0,';
            }

            if(!empty($options['limit']['end']) AND $options['limit']['end']>0){
                $end = $options['limit']['end'];
            } else {
                $end     = $this->newId($tblName)-1;
            }

            $sql .= ' LIMIT '.$start.$end;

        }

        $result = array();
        
        $this->sql = 'SELECT '.$sqlColumns.' FROM `'.$tblName.'` '.$sql;

        try{

            $query = $this->prepare('SELECT '.$sqlColumns.' FROM `'.$tblName.'` '.$sql);
            $query->execute($executeArray);
            $result = $query->fetchAll(PDO::FETCH_ASSOC);

            if(isset($options['format'])){
                switch ($options['format']) {

                    case 'json':
                        $result = json_encode($result, JSON_UNESCAPED_UNICODE);
                        break;
                }
            }
            return $result;

        }catch (Exception $e){
            return $result;
        }
        
    }

    /**
     * Research assistant.
     *
     * @param string $tblName
     * @param array $map
     * @param mixed $column
     * @return array
     */
    public function samantha($tblName, $map, $column=null, $status=false)
    {
        $output = array();
        $columns = array();

        $scheme['search']['and'] = $map;

        // S??tun(lar) belirtilmi??se
        if (!empty($column)) {

            // bir s??tun belirtilmi??se
            if(!is_array($column)){
                $columns = array($column);
            } else {
                $columns = $column;
            }

            // tablo s??tunlar?? elde ediliyor
            $getColumns = $this->columnList($tblName);

            // belirtilen s??tun(lar) var m?? bak??l??yor
            foreach($columns as $column){

                // yoksa bo?? bir array geri d??nd??r??l??yor
                if(!in_array($column, $getColumns)){
                    return [];
                }

            }

            // izin verilen s??tun(lar) belirtiliyor
            $scheme['column'] = $columns;
        }

        $output = $this->getData($tblName, $scheme);

        return $output;
    }

    /**
     * Research assistant.
     * It serves to obtain a array.
     * 
     * @param string $tblName
     * @param array $map
     * @param mixed $column
     * @return array
     * 
     */
    public function theodore($tblName, $map, $column=null){

        $output = array();
        $columns = array();

        $scheme['search']['and'] = $map;

        // S??tun(lar) belirtilmi??se
        if (!empty($column)) {

            // bir s??tun belirtilmi??se
            if(!is_array($column)){
                $columns = array($column);
            } else {
                $columns = $column;
            }

            // tablo s??tunlar?? elde ediliyor
            $getColumns = $this->columnList($tblName);

            // belirtilen s??tun(lar) var m?? bak??l??yor
            foreach($columns as $column){

                // yoksa bo?? bir array geri d??nd??r??l??yor
                if(!in_array($column, $getColumns)){
                    return [];
                }

            }

            // izin verilen s??tun(lar) belirtiliyor
            $scheme['column'] = $columns;
        }

        $data = $this->getData($tblName, $scheme);

        if(count($data)==1 AND isset($data[0])){
            $output = $data[0];
        } else {
            $output = [];
        }

        return $output;
    }

    /**
     * Research assistant.
     * Used to obtain an element of an array
     * 
     * @param string $tblName
     * @param array $map
     * @param string $column
     * @return string
     * 
     */
    public function amelia($tblName, $map, $column){

        $output = '';

        $scheme['search']['and'] = $map;

        // S??tun string olarak g??nderilmemi??se
        if (!is_string($column)) {
            return $output;
        }

        // tablo s??tunlar?? elde ediliyor
        $getColumns = $this->columnList($tblName);

        // yoksa bo?? bir string geri d??nd??r??l??yor
        if(!in_array($column, $getColumns)){
            return $output;
        }

        // izin verilen s??tun belirtiliyor
        $scheme['column'] = $column;

        $data = $this->getData($tblName, $scheme);

        if(count($data)==1 AND isset($data[0])){
            $output = $data[0][$column];
        }

        return $output;
    }

    /**
     * Entity verification.
     *
     * @param string $tblName
     * @param mixed $value
     * @param mixed $column
     * @return bool
     */
    public function do_have($tblName, $value, $column=null){

        if(!is_array($value)){
            $options['search']['keyword'] = $value;
            if(!empty($column)){  $options['search']['column'] = $column;  }
        } else {
            $options['search']['and'] = $value;
        }

        if(!empty($this->getData($tblName, $options))){
            return true;
        }
        return false;
    }

    /**
     * Provides the number of the current record.
     * 
     * @param string $tblName
     * @param array $needle
     * @return int
     */
    public function getId($tblName, $needle){
        return $this->amelia($tblName, $needle, $this->increments($tblName));
    }
    /**
     * New id parameter.
     *
     * @param string $tblName
     * @return int
     */
    public function newId($tblName){

        $IDs = [];
        $length = 1;
        $needle = $this->increments($tblName);

        switch ($this->db['drive']) {
            case 'mysql':
                foreach ($this->getData($tblName, array('column'=>$needle)) as $row) {
                    if(!in_array($row[$needle], $IDs)){
                        $IDs[] = $row[$needle];
                    }
                }
            break;
            case 'sqlite':
                $getSqliteTable = $this->theodore('sqlite_sequence', array('name'=>$tblName));
                $IDs[] = $getSqliteTable['seq'];
            break;
            
        }
        
        if(!empty($IDs)){
            $length = max($IDs)+1;
        } else {
            $this->tableClear($tblName);
        }
        
        return $length;
        
    }

    /**
     * Auto increment column.
     *
     * @param string $tblName
     * @return string
     * */
    public function increments($tblName){

        $columns = '';
        
        try{
            
            switch ($this->db['drive']) {
                case 'mysql':
                    $query = $this->query('SHOW COLUMNS FROM `' . $tblName. '`', PDO::FETCH_ASSOC);
                    foreach ( $query as $column ) { 
                        if($column['Extra'] == 'auto_increment'){ $columns = $column['Field']; } 
                    }
                break;
                case 'sqlite':
                    $statement = $this->query("PRAGMA TABLE_INFO(`".$tblName."`)");
                    $row = $statement->fetchAll(PDO::FETCH_ASSOC); 
                    foreach ($row as $column) {
                        if((int) $column['pk'] === 1){ $columns = $column['name']; }
                    }   

                break;
            }
            
            return $columns;

        } catch (Exception $e){
            return $columns;
        }

    }

    /**
     * Table structure converter for Mind
     * 
     * @param string $tblName
     * @return array
     */
    public function tableInterpriter($tblName, $column = null){

        $result  = array();
        $columns = array();
        $columns = (!is_null($column) AND is_array($column)) ? $column : $columns; // array
        $columns = (!is_null($column) AND !is_array($column)) ? [$column] : $columns; // string
        
        try{

            switch ($this->db['drive']) {
                case 'mysql':
                    $sql  =  'SHOW COLUMNS FROM `' . $tblName . '`';
                break;
                case 'sqlite':
                    $sql  =  'PRAGMA TABLE_INFO(`'. $tblName . '`)';
                break;
            }

            $query = $this->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            foreach ( $query as $row ) {
                switch ($this->db['drive']) {
                    case 'mysql':
                        if(strstr($row['Type'], '(')){
                            $row['Length'] = implode('', $this->get_contents('(',')', $row['Type']));
                            $row['Type']   = explode('(', $row['Type'])[0];
                        }
                        
                    break;
                    case 'sqlite':                      
                        
                        // Field
                        $row['Field'] = $row['name'];

                        // Type, Length
                        if(strstr($row['type'], '(')){
                            $row['Length'] = implode('', $this->get_contents('(',')', $row['type']));
                            $row['Type']   = mb_strtolower(explode('(', $row['type'])[0]);
                        } else { $row['Type'] = mb_strtolower($row['type']); }

                        if($row['Type'] == 'integer') { $row['Type'] = 'int';}

                        $row['Null'] = ($row['notnull']==0) ? 'YES' : 'NO';
                        $row['Key'] = ($row['pk']==1) ? 'PRI' : '';
                        $row['Default'] = $row['dflt_value'];
                        $row['Extra'] = ($row['pk'] == 1) ? 'auto_increment' : '';
                        // remove old column name
                        unset($row['cid'], $row['pk'], $row['name'], $row['type'], $row['dflt_value'], $row['notnull']);
                    break;
                }

                if(!in_array($row['Field'], $columns)){
                    switch ($row['Type']) {
                        case 'int':
                            if($row['Extra'] == 'auto_increment'){
                                if(isset($row['Length'])){
                                    $row = $row['Field'].':increments@'.$row['Length'];
                                } else {
                                    $row = $row['Field'].':increments';
                                }
                            } else {
                                $row = $row['Field'].':int@'.$row['Length'];
                            }
                            break;
                        case 'varchar':
                            $row = $row['Field'].':string@'.$row['Length'];
                            break;
                        case 'text':
                            $row = $row['Field'].':small';
                            break;
                        case 'mediumtext':
                            $row = $row['Field'].':medium';
                            break;
                        case 'longtext':
                            $row = $row['Field'].':large';
                            break;
                        case 'decimal':
                            $row = $row['Field'].':decimal@'.$row['Length'];
                            break;
                    }
                    $result[] = $row;
                }
                
            }

            return $result;

        } catch (Exception $e){
            return $result;
        }
    }

    /**
     * Database backup method
     * 
     * @param string|array $dbnames
     * @param string $directory
     * @return json|export
     */
    public function backup($dbnames, $directory='')
    {
        $result = array();

        if(is_string($dbnames)){
            $dbnames = array($dbnames);
        }

        foreach ($dbnames as $dbname) {
            
            // database select
            $this->selectDB($dbname);
            // tabular data is obtained
            foreach ($this->tableList() as $tblName) {
                if($tblName != 'sqlite_sequence') {  // If it is not the table added by default to the sqlite database.
                    $incrementColumn = $this->increments($tblName);
                    if(!empty($incrementColumn)){
                        $increments = array(
                            'auto_increment'=>array(
                                'length'=>$this->newId($tblName)
                            )
                        );
                    }

                    $result[$dbname][$tblName]['config'] = $increments;
                    $result[$dbname][$tblName]['schema'] = $this->tableInterpriter($tblName);
                    $result[$dbname][$tblName]['data'] = $this->getData($tblName);
                }
            }

            
            
        }
        
        $data = json_encode($result, JSON_UNESCAPED_UNICODE);
        $backupFile = $this->db['drive'].'_backup_'.$this->permalink($this->timestamp, array('delimiter'=>'_')).'.json';
        if(!empty($directory)){
            if(is_dir($directory)){
                $this->write($data, $directory.'/'.$backupFile);
            } 
        } else {
            header('Access-Control-Allow-Origin: *');
            header("Content-type: application/json; charset=utf-8");
            header('Content-Disposition: attachment; filename="'.$backupFile.'"');
            echo $data;
        }
        return $result;
        
    }

    /**
     * Method of restoring database backup
     * 
     * @param string|array $paths
     * @return array
     */
    public function restore($paths){

        $result = array();
        
        if(is_string($paths)){
            $paths = array($paths);
        }

        foreach ($paths as $path) {
            if(file_exists($path)){
                foreach (json_decode(file_get_contents($path), true) as $dbname => $rows) {
                    foreach ($rows as $tblName => $row) {

                        $this->dbConnect(['db'=>['dbname'=>$dbname]]);
                        $this->tableCreate($tblName, $row['schema']);

                        switch ($this->db['drive']) {
                            case 'mysql':
                                if(!empty($row['config']['auto_increment']['length'])){
                                    $length = $row['config']['auto_increment']['length'];
                                    $sql = "ALTER TABLE `".$tblName."` AUTO_INCREMENT = ".$length;
                                    $this->query($sql);
                                }
                            break;
                        }
                        
                        if(!empty($row['data']) AND empty($this->getData($tblName))){
                            $this->insert($tblName, $row['data']);
                        }
                        $result[$dbname][$tblName] = $row;
                    }
                    
                }
            }
        }

        return $result;
    }

    /**
     * Paging method
     * 
     * @param string $tblName
     * @param array $options
     * @return json|array
     */
    public function pagination($tblName, $options=array()){

        $result = array();
        
        /* -------------------------------------------------------------------------- */
        /*                                   FORMAT                                   */
        /* -------------------------------------------------------------------------- */

        if(!isset($options['format'])){
            $format = '';
        } else {
            $format = $options['format'];
            unset($options['format']);
        }

        /* -------------------------------------------------------------------------- */
        /*                                    SORT                                    */
        /* -------------------------------------------------------------------------- */
        if(!isset($options['sort'])){
            $options['sort'] = '';
        } 

        /* -------------------------------------------------------------------------- */
        /*                                    LIMIT                                   */
        /* -------------------------------------------------------------------------- */
        $limit = 5;
        if(empty($options['limit'])){
            $options['limit'] = $limit;
        } else {
             if(!is_numeric($options['limit'])){
                $options['limit'] = $limit;
             }
        }
        $end = $options['limit'];

        /* -------------------------------------------------------------------------- */
        /*                                    PAGE                                    */
        /* -------------------------------------------------------------------------- */

        $page = 1;
        $prefix = 'p';
        if(!empty($options['prefix'])){
            if(!is_numeric($options['prefix'])){
                $prefix = $options['prefix'];
            }
        }
        
        if(empty($this->post[$prefix])){
            $this->post[$prefix] = $page;
        } else {
            if(is_numeric($this->post[$prefix])){
                $page = $this->post[$prefix];
            } else {
                $this->post[$prefix] = $page;
            }
        }


        /* -------------------------------------------------------------------------- */
        /*                                   COLUMN                                   */
        /* -------------------------------------------------------------------------- */

        if(!isset($options['column']) OR empty($options['column'])){
            $options['column'] = array();
        }

        /* -------------------------------------------------------------------------- */
        /*                                   SEARCH                                   */
        /* -------------------------------------------------------------------------- */

        if(!isset($options['search']) OR empty($options['search'])){
            $options['search'] = array();
        }

        if(!is_array($options['search'])){
            $options['search'] = array();
        }

        /* -------------------------------------------------------------------------- */
        /*            Finding the total number of pages and starting points           */
        /* -------------------------------------------------------------------------- */
        $data = $this->getData($tblName, $options);
        $totalRow = count($data);
        $totalPage = ceil($totalRow/$end);
        $start = ($page*$end)-$end;

        $result = array(
            'data'=>array_slice($data, $start, $end), 
            'prefix'=>$prefix,
            'limit'=>$end,
            'totalPage'=>$totalPage,
            'page'=>$page
        );

        switch ($format) {
            case 'json':
                return json_encode($result, JSON_PRETTY_PRINT); 
            break;
        }
        return $result;
    }

    /**
     * Translate
     * 
     * @param string $needle
     * @param string|null $lang
     * @return string
     */
    public function translate($needle, $lang=''){
        if(!in_array($lang, array_keys($this->languages()))){
            $lang = $this->lang['lang'];
        }

        $params = array(
            $this->lang['column']=>$lang, 
            $this->lang['haystack']=>$needle
        );
        return $this->amelia($this->lang['table'], $params, $this->lang['return']);
    }

    /**
     * Database verification.
     *
     * @param string $dbname
     * @return bool
     * */
    public function is_db($dbname){

        switch ($this->db['drive']) {
            case 'mysql':
                $sql     = 'SHOW DATABASES';

                try{
                    $query = $this->query($sql, PDO::FETCH_ASSOC);

                    $dbnames = array();

                    if ( $query->rowCount() ){
                        foreach ( $query as $item ) {
                            $dbnames[] = $item['Database'];
                        }
                    }

                    return in_array($dbname, $dbnames) ? true : false;

                } catch (Exception $e){
                    return false;
                }
            break;
            case 'sqlite':
                return (isset($dbname) AND file_exists($dbname)) ? true : false;
            break;
        }

        return false;

    }

    /**
     * Table verification.
     *
     * @param string $tblName
     * @return bool
     */
    public function is_table($tblName){

        $sql = '';

        switch ($this->db['drive']) {
            case 'mysql':
                $sql = 'DESCRIBE `'.$tblName.'`';
            break;
            case 'sqlite':
                $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name='".$tblName."';";
            break;
        }
        
        try{
            return $this->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e){
            return false;
        }

    }

    /**
     * Column verification.
     *
     * @param string $tblName
     * @param string $column
     * @return bool
     * */
    public function is_column($tblName, $column){

        $columns = $this->columnList($tblName);

        if(in_array($column, $columns)){
            return true;
        } else {
            return false;
        }        
    }

    /**
     * Phone verification.
     *
     * @param string $str
     * @return bool
     * */
    public function is_phone($str){

        return preg_match('/^\(?\+?([0-9]{1,4})\)?[-\. ]?(\d{3})[-\. ]?([0-9]{7})$/', implode('', explode(' ', $str))) ? true : false;

    }

    /**
     * Date verification.
     *
     * @param string $date
     * @param string $format
     * @return bool
     * */
    public function is_date($date, $format = 'Y-m-d H:i:s'){

        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) == $date;
    }

    /**
     * Mail verification.
     *
     * @param string $email
     * @return bool
     */
    public function is_email($email){

        if ( filter_var($email, FILTER_VALIDATE_EMAIL) ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Type verification.
     *
     * @param string $fileName
     * @param mixed $type
     * @return bool
     */
    public function is_type($fileName, $type){

        if( !empty($type) AND !is_array($fileName) ){

            $exc = $this->info($fileName, 'extension');

            if(!is_array($type)){
                $type = array($type);
            }

            return in_array($exc, $type) ? true : false;
        }
        return false;
    }

    /**
     * Size verification.
     *
     * @param mixed $first_size
     * @param string $second_size
     * @return bool
     * */
    public function is_size($first_size, $second_size){

        if(is_array($first_size)){
            if(isset($first_size['size'])){
                $first_size = $first_size['size'];
            }
        }

        if(strstr($first_size, ' ')){
            $first_size = $this->encodeSize($first_size);
        }

        if(strstr($second_size, ' ')){
            $second_size = $this->encodeSize($second_size);
        }

        if($first_size >= $second_size){
            return true;
        }
        
        return false;
    }

    /**
     * Color verification.
     *
     * @param string  $color
     * @return bool
     * */
    public function is_color($color){

        $colorArray = json_decode('["AliceBlue","AntiqueWhite","Aqua","Aquamarine","Azure","Beige","Bisque","Black","BlanchedAlmond","Blue","BlueViolet","Brown","BurlyWood","CadetBlue","Chartreuse","Chocolate","Coral","CornflowerBlue","Cornsilk","Crimson","Cyan","DarkBlue","DarkCyan","DarkGoldenRod","DarkGray","DarkGrey","DarkGreen","DarkKhaki","DarkMagenta","DarkOliveGreen","DarkOrange","DarkOrchid","DarkRed","DarkSalmon","DarkSeaGreen","DarkSlateBlue","DarkSlateGray","DarkSlateGrey","DarkTurquoise","DarkViolet","DeepPink","DeepSkyBlue","DimGray","DimGrey","DodgerBlue","FireBrick","FloralWhite","ForestGreen","Fuchsia","Gainsboro","GhostWhite","Gold","GoldenRod","Gray","Grey","Green","GreenYellow","HoneyDew","HotPink","IndianRed ","Indigo ","Ivory","Khaki","Lavender","LavenderBlush","LawnGreen","LemonChiffon","LightBlue","LightCoral","LightCyan","LightGoldenRodYellow","LightGray","LightGrey","LightGreen","LightPink","LightSalmon","LightSeaGreen","LightSkyBlue","LightSlateGray","LightSlateGrey","LightSteelBlue","LightYellow","Lime","LimeGreen","Linen","Magenta","Maroon","MediumAquaMarine","MediumBlue","MediumOrchid","MediumPurple","MediumSeaGreen","MediumSlateBlue","MediumSpringGreen","MediumTurquoise","MediumVioletRed","MidnightBlue","MintCream","MistyRose","Moccasin","NavajoWhite","Navy","OldLace","Olive","OliveDrab","Orange","OrangeRed","Orchid","PaleGoldenRod","PaleGreen","PaleTurquoise","PaleVioletRed","PapayaWhip","PeachPuff","Peru","Pink","Plum","PowderBlue","Purple","RebeccaPurple","Red","RosyBrown","RoyalBlue","SaddleBrown","Salmon","SandyBrown","SeaGreen","SeaShell","Sienna","Silver","SkyBlue","SlateBlue","SlateGray","SlateGrey","Snow","SpringGreen","SteelBlue","Tan","Teal","Thistle","Tomato","Turquoise","Violet","Wheat","White","WhiteSmoke","Yellow","YellowGreen"]', true);

        if(in_array($color, $colorArray)){
            return true;
        }

        if($color == 'transparent'){
            return true;
        }

        if(preg_match('/^#[a-f0-9]{6}$/i', mb_strtolower($color, 'utf-8'))){
            return true;
        }

        if(preg_match('/^rgb\((?:\s*\d+\s*,){2}\s*[\d]+\)$/', mb_strtolower($color, 'utf-8'))) {
            return true;
        }

        if(preg_match('/^rgba\((\s*\d+\s*,){3}[\d\.]+\)$/i', mb_strtolower($color, 'utf-8'))){
            return true;
        }

        if(preg_match('/^hsl\(\s*\d+\s*(\s*\,\s*\d+\%){2}\)$/i', mb_strtolower($color, 'utf-8'))){
            return true;
        }

        if(preg_match('/^hsla\(\s*\d+(\s*,\s*\d+\s*\%){2}\s*\,\s*[\d\.]+\)$/i', mb_strtolower($color, 'utf-8'))){
            return true;
        }

        return false;
    }

    /**
     * URL verification.
     *
     * @param string $url
     * @return bool
     */
    public function is_url($url=null){

        if(!is_string($url)){
            return false;
        }

        $temp_string = (!preg_match('#^(ht|f)tps?://#', $url)) // check if protocol not present
            ? 'http://' . $url // temporarily add one
            : $url; // use current

        if ( filter_var($temp_string, FILTER_VALIDATE_URL)) {
            return true;
        } else {
            return false;
        }

    }

    /**
     * HTTP checking.
     *
     * @param string $url
     * @return bool
     */
    public function is_http($url){
        if (substr($url, 0, 7) == "http://"){
            return true;
        } else {
            return false;
        }
    }

    /**
     * HTTPS checking.
     * @param string $url
     * @return bool
     */
    public function is_https($url){
        if (substr($url, 0, 8) == "https://"){
            return true;
        } else {
            return false;
        }
    }

    /**
     * Json control of a string
     *
     * @param string $scheme
     * @return bool
     */
    public function is_json($scheme){

        if(is_null($scheme) OR is_array($scheme)) {
            return false;
        }

        if(json_decode($scheme)){
            return true;
        }

        return false;
    }

    /**
     * is_age
     * @param string $date
     * @param string|int $age
     * @param string $type
     * @return bool
     * 
     */
    public function is_age($date, $age, $type='min'){
        
        $today = date("Y-m-d");
        $diff = date_diff(date_create($date), date_create($today));
    
        if($type === 'max'){
            if($age >= $diff->format('%y')){
                return true;
            }
        }
        if($type === 'min'){
            if($age <= $diff->format('%y')){
                return true;
            }
        }
        
        return false;
    }

    /**
     * International Bank Account Number verification
     *
     * @params string $iban
     * @param $iban
     * @return bool
     */
    public function is_iban($iban){
        // Normalize input (remove spaces and make upcase)
        $iban = strtoupper(str_replace(' ', '', $iban));

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $iban)) {
            $country = substr($iban, 0, 2);
            $check = intval(substr($iban, 2, 2));
            $account = substr($iban, 4);

            // To numeric representation
            $search = range('A','Z');
            foreach (range(10,35) as $tmp)
                $replace[]=strval($tmp);
            $numstr = str_replace($search, $replace, $account.$country.'00');

            // Calculate checksum
            $checksum = intval(substr($numstr, 0, 1));
            for ($pos = 1; $pos < strlen($numstr); $pos++) {
                $checksum *= 10;
                $checksum += intval(substr($numstr, $pos,1));
                $checksum %= 97;
            }

            return ((98-$checksum) == $check);
        } else
            return false;
    }

    /**
     * ipv4 verification
     *
     * @params string $ip
     * @return bool
     */
    public function is_ipv4($ip){
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * ipv6 verification
     *
     * @params string $ip
     * @return bool
     */
    public function is_ipv6($ip){
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Blood group verification
     *
     * @param $blood
     * @param string $donor
     * @return bool
     */
    public function is_blood($blood, $donor = null){

        $bloods = array(
            'AB+'=> array(
                'AB+', 'AB-', 'B+', 'B-', 'A+', 'A-', '0+', '0-'
            ),
            'AB-'=> array(
                'AB-', 'B-', 'A-', '0-'
            ),
            'B+'=> array(
                'B+', 'B2-', '0+', '0-'
            ),
            'B-'=> array(
                'B-', '0-'
            ),
            'A+'=> array(
                'A+', 'A-', '0+', '0-'
            ),
            'A-'=> array(
                'A-', '0-'
            ),
            '0+'=> array(
                '0+', '0-'
            ),
            '0-'=> array(
                '0-'
            )
        );

        $map = array_keys($bloods);

        //  hasta ve varsa don??r parametreleri filtreden ge??irilir
        $blood = str_replace(array('RH', ' '), '', mb_strtoupper($blood));
        if(!is_null($donor)) $donor = str_replace(array('RH', ' '), '', mb_strtoupper($donor));

        // Kan grubu kontrol??
        if(in_array($blood, $map) AND is_null($donor)){
            return true;
        }

        // Don??r uyumu kontrol??
        if(in_array($blood, $map) AND in_array($donor, $bloods[$blood]) AND !is_null($donor)){
            return true;
        }

        return false;

    }

    /**
     *  Validates a given Latitude
     * @param float|int|string $latitude
     * @return bool
     */
    public function is_latitude($latitude){
        $lat_pattern  = '/\A[+-]?(?:90(?:\.0{1,18})?|\d(?(?<=9)|\d?)\.\d{1,18})\z/x';

        if (preg_match($lat_pattern, $latitude)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     *  Validates a given longitude
     * @param float|int|string $longitude
     * @return bool
     */
    public function is_longitude($longitude){
        $long_pattern = '/\A[+-]?(?:180(?:\.0{1,18})?|(?:1[0-7]\d|\d{1,2})\.\d{1,18})\z/x';

        if (preg_match($long_pattern, $longitude)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Validates a given coordinate
     *
     * @param float|int|string $lat Latitude
     * @param float|int|string $long Longitude
     * @return bool `true` if the coordinate is valid, `false` if not
     */
    public function is_coordinate($lat, $long) {

        if ($this->is_latitude($lat) AND $this->is_longitude($long)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Distance verification
     */
    public function is_distance($point1, $point2, $options){

        $symbols = array('m', 'km', 'mi', 'ft', 'yd');

        // Option variable control
       if(empty($options)){
           return false;
       }

       if(!strstr($options, ':')){
           return false;
       }

       $options = explode(':', trim($options, ':'));

       if(count($options) != 2){
           return false;
       }

       list($range, $symbol) = $options;

       if(!in_array(mb_strtolower($symbol), $symbols)){
           return false;
       }

       // Points control
        if(empty($point1) OR empty($point2)){
            return false;
        }
        if(!is_array($point1) OR !is_array($point2)){
            return false;
        }

        if(count($point1) != 2 OR count($point2) != 2){
            return false;
        }

        if(isset($point1[0]) AND isset($point1[1]) AND isset($point2[0]) AND isset($point2[1])){
            $distance_range = $this->distanceMeter($point1[0], $point1[1], $point2[0], $point2[1], $symbol);
            if($distance_range <= $range){
                return true;
            }
        }

        return false;
    }

    /**
     * md5 hash checking method.
     * 
     * @param string $md5
     * @return bool
     */
    public function is_md5($md5 = ''){
        return strlen($md5) == 32 && ctype_xdigit($md5);
    }

    /**
	 * Determines if SSL is used.	 
	 * @return bool True if SSL, otherwise false.
	 */
    public function is_ssl() {
        if ( isset( $_SERVER['HTTPS'] ) ) {
            if ( 'on' === strtolower( $_SERVER['HTTPS'] ) ) {
                return true;
            }
     
            if ( '1' == $_SERVER['HTTPS'] ) {
                return true;
            }
        } elseif ( isset( $_SERVER['SERVER_PORT'] ) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
            return true;
        }
        return false;
    }

    /**
     * html special characters control
     * @param $code
     * @return bool
     */
    public function is_htmlspecialchars($code){
        if(strpos($code, '&lt;') OR strpos($code, '&gt;') OR strpos($code, '&quot;') OR strpos($code, '&#39;') OR strpos($code, '&amp;')){
            return true;    
        }
        return false;
    }

    /**
     * Morse code verification
     * @param string $morse
     * @return bool
     */
    public function is_morse($morse){

        $data = $this->morse_decode($morse);
        if(strstr($data, '#')){
            return false;
        }
        return true;
    }

    /**
     * Binary code verification
     * @param string|int $binary
     * @return bool
     */
    public function is_binary($binary) {
        if (preg_match('~^[01]+$~', str_replace(' ', '', $binary))) {
            return true;
        } 
        return false;
    }

    /**
     * Validation
     * 
     * @param array $rule
     * @param array $data
     * @param array $message
     * @return bool
     */
    public function validate($rule, $data, $message = array()){
      
        $extra = '';
        $limit = '';
        $rules = array();

        foreach($rule as $name => $value){
            
            if(strstr($value, '|')){
                foreach(explode('|', trim($value, '|')) as $val){
                    $rules[$name][] = $val;
                }
            } else {
                $rules[$name][] = $value;
            }

        }

        foreach($rules as $column => $rule){
            foreach($rule as $name){

                if(strstr($name, ':')){
                    $ruleData = explode(':', trim($name, ':'));
                    if(count($ruleData) == 2){
                        list($name, $extra) = $ruleData;
                    }
                    if(count($ruleData) == 3){
                        list($name, $extra, $limit) = $ruleData;
                    }
                    if(count($ruleData) == 4){
                        list($name, $extra, $knownuniqueColumn, $knownuniqueValue) = $ruleData;
                    }
                    // farkl?? zaman damgalar?? kontrol??ne m??saade edildi.
                    if(count($ruleData) > 2 AND strstr($name, ' ')){
                        $x = explode(' ', $name);
                        list($left, $right) = explode(' ', $name);
                        list($name, $date1) = explode(':', $left);
                        $extra = $date1.' '.$right;
                    }
                }

                if(!isset($data[$column])){
                    $data[$column] = @$data[$column];
                }

                // ??lgili kural??n mesaj?? yoksa kural ad?? mesaj olarak belirtilir.
                if(empty($message[$column][$name])){
                    $message[$column][$name] = $name;
                }
                
                switch ($name) {
                    // minimum say kural??
                    case 'min-num':
                        if(!is_numeric($data[$column])){
                            $this->errors[$column][$name] = 'Don\'t numeric.';
                        } else {
                            if($data[$column]<$extra){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }
                    break;
                    // maksimum say?? kural??
                    case 'max-num':
                        if(!is_numeric($data[$column])){
                            $this->errors[$column][$name] = 'Don\'t numeric.';
                        } else {
                            if($data[$column]>$extra){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }
                    break;
                    // minimum karakter kural??
                    case 'min-char':
                        if(strlen($data[$column])<$extra){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                        break;
                    // maksimum karakter kural??
                    case 'max-char':
                        if(strlen($data[$column])>$extra){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                        break;
                    // E-Posta adresi kural??
                    case 'email':
                        if(!$this->is_email($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // Zorunlu alan kural??
                    case 'required':
                        if(!isset($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } else {
                            if($data[$column] === ''){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }
                        
                    break;
                    // Telefon numaras?? kural??
                    case 'phone':
                        if(!$this->is_phone($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // Tarih kural??
                    case 'date':
                        if(empty($extra)){
                            $extra = 'Y-m-d';
                        }
                        if(!$this->is_date($data[$column], $extra)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // json kural?? 
                    case 'json':
                        if(!$this->is_json($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // Renk kural?? 
                    case 'color':
                        if(!$this->is_color($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // URL kural?? 
                    case 'url':
                        if(!$this->is_url($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // https kural?? 
                    case 'https':
                        if(!$this->is_https($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // http kural?? 
                    case 'http':
                        if(!$this->is_http($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // Numerik karakter kural?? 
                    case 'numeric':
                        if(!is_numeric($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // Minumum ya?? s??n??rlamas?? kural?? 
                    case 'min-age':
                        if(!$this->is_age($data[$column], $extra)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // Maksimum ya?? s??n??rlamas?? kural?? 
                    case 'max-age':
                        if(!$this->is_age($data[$column], $extra, 'max')){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // Benzersiz parametre kural?? 
                    case 'unique':

                        if(!$this->is_table($extra)){
                            $this->errors[$column][$name][] = 'Table not found.';
                        }
                        
                        if(!$this->is_column($extra, $column)){
                            $this->errors[$column][$name][] = 'Column not found.';
                        }

                        if($this->do_have($extra, $data[$column], $column)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } 

                    break;
                    // Benzeri olan parametre kural??
                    case 'available':
                        $availableColumn = $column;
                        if(isset($limit)){
                            $availableColumn = $limit;
                        }

                        if(!$this->is_table($extra)){
                            $this->errors[$column][$name][] = 'Table not found.';
                        }
                        
                        if(!$this->is_column($extra,$availableColumn)){
                            $this->errors[$column][$name][] = 'Column not found.';
                        }

                        if(!$this->do_have($extra, $data[$column],$availableColumn)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } 
                    break;
                    case 'knownunique':
                        if(!$this->is_table($extra)){
                            $this->errors[$column][$name][] = 'Table not found.';
                        }
                        
                        if(!$this->is_column($extra, $column)){
                            $this->errors[$column][$name][] = 'Column not found.';
                        }

                        if(!isset($knownuniqueColumn) AND !isset($knownuniqueValue) AND isset($limit)){
                            $knownuniqueColumn = $column;
                            $knownuniqueValue = $limit;
                        }

                        if(!isset($limit)){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } else {

                            $item = $this->theodore($extra, array($knownuniqueColumn=>$knownuniqueValue));
                            if(isset($item[$column])){
                                if($data[$column] != $item[$column] AND $this->do_have($extra, array($column=>$data[$column]))){
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }     
                            } else {
                                if($data[$column] != $knownuniqueValue AND $this->do_have($extra, array($column=>$data[$column]))){
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }    
                            }

                        }

                    break;
                    // Do??rulama kural?? 
                    case 'bool':
                        // Ge??erlilik kontrol??
                        $acceptable = array(true, false, 'true', 'false', 0, 1, '0', '1');
                        $wrongTypeMessage = 'True, false, 0 or 1 must be specified.';

                        if(isset($extra)){

                            if($extra === ''){
                                unset($extra);
                            }
                            
                        }

                        if(isset($data[$column]) AND isset($extra)){
                            if(in_array($data[$column], $acceptable, true) AND in_array($extra, $acceptable, true)){
                                if($data[$column] === 'true' OR $data[$column] === '1' OR $data[$column] === 1){
                                    $data[$column] = true;
                                }
                                if($data[$column] === 'false' OR $data[$column] === '0' OR $data[$column] === 0){
                                    $data[$column] = false;
                                }
    
                                if($extra === 'true' OR $extra === '1' OR $extra === 1){
                                    $extra = true;
                                }
                                if($extra === 'false' OR $extra === '0' OR $extra === 0){
                                    $extra = false;
                                }
    
                                if($data[$column] !== $extra){
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }
                                
                            } else {
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        } 

                        if(isset($data[$column]) AND !isset($extra)){
                            if(!in_array($data[$column], $acceptable, true)){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }

                        if(!isset($data[$column]) AND isset($extra)){
                            if(!in_array($extra, $acceptable, true)){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }

                        break;
                    // IBAN do??rulama kural??
                    case 'iban':
                        if(!$this->is_iban($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // ipv4 do??rulama kural??
                    case 'ipv4':
                        if(!$this->is_ipv4($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // ipv6 do??rulama kural??
                    case 'ipv6':
                        if(!$this->is_ipv6($data[$column])){
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                    break;
                    // kan grubu ve uyumu kural??
                    case 'blood':
                        if(!empty($extra)){
                            if(!$this->is_blood($data[$column], $extra)){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        } else {
                            if(!$this->is_blood($data[$column])){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        }
                    break;
                    // Koordinat kural??
                    case 'coordinate':

                        if(!strstr($data[$column], ',')){
                            $this->errors[$column][$name] = $message[$column][$name];
                        } else {

                            $coordinates = explode(',', $data[$column]);
                            if(count($coordinates)==2){

                                list($lat, $long) = $coordinates;

                                if(!$this->is_coordinate($lat, $long)){
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }

                            } else {
                                $this->errors[$column][$name] = $message[$column][$name];
                            }

                        }

                    break;
                    case 'distance':
                        if(strstr($data[$column], '@')){
                            $coordinates = explode('@', $data[$column]);
                            if(count($coordinates) == 2){

                                list($p1, $p2) = $coordinates;
                                $point1 = explode(',', $p1);
                                $point2 = explode(',', $p2);

                                if(strstr($extra, ' ')){
                                    $options = str_replace(' ', ':', $extra);
                                    if(!$this->is_distance($point1, $point2, $options)){
                                        $this->errors[$column][$name] = $message[$column][$name];
                                    }
                                } else {
                                    $this->errors[$column][$name] = $message[$column][$name];
                                }
                            } else {
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                        } else {
                            $this->errors[$column][$name] = $message[$column][$name];
                        }
                        break;
                        case 'languages':
                            if(!in_array($data[$column], array_keys($this->languages()))){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                            break;
                        case 'morse':
                            if(!$this->is_morse($data[$column])){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                            break;
                        case 'binary':
                            if(!$this->is_binary($data[$column])){
                                $this->errors[$column][$name] = $message[$column][$name];
                            }
                            break;
                    // Ge??ersiz kural engellendi.
                    default:
                        $this->errors[$column][$name] = 'Invalid rule has been blocked.';
                    break;
                }
                $extra = '';
            }
        }
       
        if(empty($this->errors)){
            return true;
        } else {
            return false;
        }
    }

    /**
     * Server policy maker
     */
    public function policyMaker(){

        $filename = '';
        $public_content = '';
        $deny_content = '';
        $allow_content = '';
        
        if(!empty($this->allow_folders)){
            if(!is_array($this->allow_folders)){
                $allow_folders = array($this->allow_folders);
            }
        }

        switch ($this->getSoftware()) {
            case ('Apache' || 'LiteSpeed'):
                $public_content = implode("\n", array(
                    'RewriteEngine On',
                    'RewriteCond %{REQUEST_FILENAME} -s [OR]',
                    'RewriteCond %{REQUEST_FILENAME} -l [OR]',
                    'RewriteCond %{REQUEST_FILENAME} -d',
                    'RewriteRule ^.*$ - [NC,L]',
                    'RewriteRule ^.*$ index.php [NC,L]'
                ));
                $deny_content = 'Deny from all';
                $allow_content = 'Allow from all';
                $filename = '.htaccess';
            break;
            case 'Microsoft-IIS':
                $public_content = implode("\n", array(
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>",
                "<configuration>",
                    "\t<system.webServer>",
                        "\t\t<rewrite>",
                        "\t\t\t<rules>",
                            "\t\t\t\t<rule name=\"Imported Rule 1\" stopProcessing=\"true\">",
                            "\t\t\t\t\t<match url=\"^(.*)$\" ignoreCase=\"false\" />",
                            "\t\t\t\t\t<conditions>",
                            "\t\t\t\t\t\t<add input=\"{REQUEST_FILENAME}\" matchType=\"IsFile\" ignoreCase=\"false\" negate=\"true\" />",
                            "\t\t\t\t\t\t<add input=\"{REQUEST_FILENAME}\" matchType=\"IsDirectory\" ignoreCase=\"false\" negate=\"true\" />",
                            "\t\t\t\t\t</conditions>",
                            "\t\t\t\t\t<action type=\"Rewrite\" url=\"index.php\" appendQueryString=\"true\" />",
                        "\t\t\t\t</rule>",
                        "\t\t\t</rules>",
                        "\t\t</rewrite>",
                   "\t</system.webServer>",
                '</configuration>'
            ));
            
            $deny_content = implode("\n", array(
                "<authorization>",
                "\t<deny users=\"?\"/>",
                "</authorization>"
            ));
            $allow_content = implode("\n", array(
                "<configuration>",
                "\t<system.webServer>",
                "\t\t<directoryBrowse enabled=\"true\" showFlags=\"Date,Time,Extension,Size\" />",
                "\t\t\t</system.webServer>",
                "</configuration>"
            ));
            $filename = 'web.config';
            break;
            
        }

        if($this->getSoftware() != 'Nginx'){

            if(!file_exists($filename)){
                $this->write($public_content, $filename);
            }

            $dirs = array_filter(glob('*'), 'is_dir');
            
            if(!empty($dirs)){
                foreach ($dirs as $dir){
    
                    if(!empty($allow_folders)){
                        foreach ($allow_folders as $allow_folder) {
                            if($allow_folder == $dir AND !file_exists($dir.'/'.$filename)){
                                $this->write($allow_content, $dir.'/'.$filename);
                            }
                        }
                    }
                    
                    if(!file_exists($dir.'/'.$filename)){
                        $this->write($deny_content, $dir.'/'.$filename);
                    }
    
                }
            }

        }
        
    }

    /**
     * Pretty Print
     * @param mixed $data
     * @return void
     */
    public function print_pre($data){
        
        if($this->is_json($data)){
            $data = json_encode(json_decode($data, true), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        }
        
        echo '<pre>';
        print_r($data);
        echo '</pre>';
    }

    /**
     * Array sorting function
     * 
     * @param mixed $data
     * @param string $sort
     * @param string|int $column
     * @return array|json
     */
    public function arraySort($data, $sort='ASC', $key='')
    {
        $is_json = FALSE;
        if($this->is_json($data)){
            $is_json = TRUE;
            $data = json_decode($data, TRUE);
        }

        $sort_name = SORT_DESC;
        if('ASC' === mb_strtoupper($sort, 'utf8')) $sort_name = SORT_ASC;

        if(!empty($key)){
            $keys = array_column($data, $key);
        } else {
            $keys = array_keys($data);
            asort($data);
        }
        
        array_multisort($keys, $sort_name, SORT_STRING, $data);

        if($is_json === TRUE){
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        return $data;

    }

    /**
     * Path information
     *
     * @param string $fileName
     * @param string $type
     * @return bool|string
     */
    public function info($fileName, $type){

        if(empty($fileName) AND isset($type)){
            return false;
        }

        $object = pathinfo($fileName);

        if($type == 'extension'){
            return strtolower($object[$type]);
        }

        if($type == 'dirname'){
            return $this->get_absolute_path($object[$type]);
        }
        
        return $object[$type];
    }

    /**
     * Request collector
     *
     * @return mixed
     */
    public function request(){

        if(isset($_POST) OR isset($_GET) OR isset($_FILES)){

            foreach (array_merge($_POST, $_GET, $_FILES) as $name => $value) {
                
                if(is_array($value)){
                    foreach($value as $key => $all ){

                        if(is_array($all)){
                            foreach($all as $i => $val ){
                                $this->post[$name][$i][$key] = $this->filter($val);
                            }
                        } else {
                            $this->post[$name][$key] = $this->filter($all);
                        }
                    }
                } else {
                    $this->post[$name] = $this->filter($value);
                }
            }
        }

        return $this->post;
    }

    /**
     * Filter
     * 
     * @param string $str
     * @return string
     */
    public function filter($str){
        return htmlspecialchars($str);
    }

    /**
     * Firewall
     * 
     * @param array $conf
     * @return string header()
     */
    public function firewall($conf=array()){

        $noiframe = "X-Frame-Options: SAMEORIGIN";
        $noxss = "X-XSS-Protection: 1; mode=block";
        $nosniff = "X-Content-Type-Options: nosniff";
        $ssl = "Set-Cookie: user=t=".$this->generateToken()."; path=/; Secure";
        $hsts = "Strict-Transport-Security: max-age=16070400; includeSubDomains; preload";

        if(isset($conf['firewall']['noiframe'])){
            if($conf['firewall']['noiframe']){
                header($noiframe);
            }
        } else {
            header($noiframe);
        }
        if(isset($conf['firewall']['noxss'])){
            if($conf['firewall']['noxss']){
                header($noxss);
            }
        } else {
            header($noxss);
        }
        if(isset($conf['firewall']['nosniff'])){
            if($conf['firewall']['nosniff']){
                header($nosniff);
            }
        } else {
            header($nosniff);
        }

        if($this->is_ssl()){

            if(isset($conf['firewall']['ssl'])){
                if($conf['firewall']['ssl']){
                    header($ssl);
                }
            } else {
                header($ssl);
            }
            if(isset($conf['firewall']['hsts'])){
                if($conf['firewall']['hsts']){
                    header($hsts);
                }
            } else {
                header($hsts);
            }

        }

        $limit = 200;
        $name = 'csrf_token';
        $status = true;

        if(!empty($conf)){

            if(isset($conf['firewall']['csrf'])){
                if(!empty($conf['firewall']['csrf']['name'])){
                    $name = $conf['firewall']['csrf']['name'];
                }
                if(!empty($conf['firewall']['csrf']['limit'])){
                    $limit = $conf['firewall']['csrf']['limit'];
                }
                if(is_bool($conf['firewall']['csrf'])){
                    $status = $conf['firewall']['csrf'];
                }
            }            
        }

        if($status){

            if($_SERVER['REQUEST_METHOD'] === 'POST'){
                if(isset($this->post[$name]) AND isset($_SESSION['csrf']['token'])){
                    if($this->post[$name] == $_SESSION['csrf']['token']){
                        unset($this->post[$name]);
                    } else {
                        $this->abort('401', 'A valid token could not be found.');
                        exit;
                    }
                } else {
                    $this->abort('400', 'Token not found.');
                    exit;
                }
            } 

            if($_SERVER['REQUEST_METHOD'] === 'GET'){
                $_SESSION['csrf'] = array(
                    'name'  =>  $name,
                    'token' =>  $this->generateToken($limit)                    
                );
                $_SESSION['csrf']['input'] = "<input type=\"hidden\" name=\"".$_SESSION['csrf']['name']."\" value=\"".$_SESSION['csrf']['token']."\">";
            }
            
        } else {
            if(isset($_SESSION['csrf'])){
                unset($_SESSION['csrf']);
            }
        }

        if($_SERVER['REQUEST_METHOD'] === 'POST'){

            if(isset($this->post['captcha']) AND isset($_SESSION['captcha'])){
                if($_SESSION['captcha']!= $this->post['captcha']){
                    $this->abort('401', 'Captcha validation failed.');
                    exit;
                } 
            } 
        }
    }

    /**
     * Redirect
     *
     * @param string $url
     * @param int $delay,
     * @param string $element
     */
    public function redirect($url = '', $delay = 0, $element=''){

        if(!$this->is_http($url) AND !$this->is_https($url) OR empty($url)){
            $url = $this->base_url.$url;
        }

        if(0 !== $delay){
            if(!empty($element)){
        ?>
            <script>
                let wait = 1000,
                    delay = <?=$delay;?>,
                    element = "<?=$element;?>";

                setInterval(function () {
                    elements = document.querySelectorAll(element);
                    if(delay !== 0){
                        
                        if(elements.length >= 1){

                            elements.forEach(function(element) {
                                if(element.value === undefined){
                                    element.textContent = delay;
                                } else {
                                    element.value = delay;
                                }
                            });
                        }
                    }
                    delay--;
                }, wait);
            </script>
        <?php
                }
            header('refresh:'.$delay.'; url='.$url);
        } else {
            header('Location: '.$url);
        }
        ob_end_flush();
    }

    /**
     * Permanent connection.
     *
     * @param string $str
     * @param array $options
     * @return string
     */
    public function permalink($str, $options = array()){

        $plainText = $str;
        $defaults = array(
            'delimiter' => '-',
            'limit' => null,
            'lowercase' => true,
            'replacements' => array(),
            'transliterate' => true,
            'unique' => array(
                'delimiter' => '-',
                'linkColumn' => 'link',
                'titleColumn' => 'title'
            )
        );

        $char_map = [

            // Latin
            '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'A', '??' => 'AE', '??' => 'C',
            '??' => 'E', '??' => 'E', '??' => 'E', '??' => 'E', '??' => 'I', '??' => 'I', '??' => 'I', '??' => 'I',
            '??' => 'D', '??' => 'N', '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'O', '??' => 'O',
            '??' => 'O', '??' => 'U', '??' => 'U', '??' => 'U', '??' => 'U', '??' => 'U', '??' => 'Y', '??' => 'TH',
            '??' => 'ss',
            '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'a', '??' => 'ae', '??' => 'c',
            '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'e', '??' => 'i', '??' => 'i', '??' => 'i', '??' => 'i',
            '??' => 'd', '??' => 'n', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o', '??' => 'o',
            '??' => 'o', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'u', '??' => 'y', '??' => 'th',
            '??' => 'y',

            // Latin symbols
            '??' => '(c)',

            // Greek
            '??' => 'A', '??' => 'B', '??' => 'G', '??' => 'D', '??' => 'E', '??' => 'Z', '??' => 'H', '??' => '8',
            '??' => 'I', '??' => 'K', '??' => 'L', '??' => 'M', '??' => 'N', '??' => '3', '??' => 'O', '??' => 'P',
            '??' => 'R', '??' => 'S', '??' => 'T', '??' => 'Y', '??' => 'F', '??' => 'X', '??' => 'PS', '??' => 'W',
            '??' => 'A', '??' => 'E', '??' => 'I', '??' => 'O', '??' => 'Y', '??' => 'H', '??' => 'W', '??' => 'I',
            '??' => 'Y',
            '??' => 'a', '??' => 'b', '??' => 'g', '??' => 'd', '??' => 'e', '??' => 'z', '??' => 'h', '??' => '8',
            '??' => 'i', '??' => 'k', '??' => 'l', '??' => 'm', '??' => 'n', '??' => '3', '??' => 'o', '??' => 'p',
            '??' => 'r', '??' => 's', '??' => 't', '??' => 'y', '??' => 'f', '??' => 'x', '??' => 'ps', '??' => 'w',
            '??' => 'a', '??' => 'e', '??' => 'i', '??' => 'o', '??' => 'y', '??' => 'h', '??' => 'w', '??' => 's',
            '??' => 'i', '??' => 'y', '??' => 'y', '??' => 'i',

            // Turkish
            '??' => 'S', '??' => 'I', '??' => 'G',
            '??' => 's', '??' => 'i', '??' => 'g',

            // Russian
            '??' => 'A', '??' => 'B', '??' => 'V', '??' => 'G', '??' => 'D', '??' => 'E', '??' => 'Yo', '??' => 'Zh',
            '??' => 'Z', '??' => 'I', '??' => 'J', '??' => 'K', '??' => 'L', '??' => 'M', '??' => 'N', '??' => 'O',
            '??' => 'P', '??' => 'R', '??' => 'S', '??' => 'T', '??' => 'U', '??' => 'F', '??' => 'H', '??' => 'C',
            '??' => 'Ch', '??' => 'Sh', '??' => 'Sh', '??' => '', '??' => 'Y', '??' => '', '??' => 'E', '??' => 'Yu',
            '??' => 'Ya',
            '??' => 'a', '??' => 'b', '??' => 'v', '??' => 'g', '??' => 'd', '??' => 'e', '??' => 'yo', '??' => 'zh',
            '??' => 'z', '??' => 'i', '??' => 'j', '??' => 'k', '??' => 'l', '??' => 'm', '??' => 'n', '??' => 'o',
            '??' => 'p', '??' => 'r', '??' => 's', '??' => 't', '??' => 'u', '??' => 'f', '??' => 'h', '??' => 'c',
            '??' => 'ch', '??' => 'sh', '??' => 'sh', '??' => '', '??' => 'y', '??' => '', '??' => 'e', '??' => 'yu',
            '??' => 'ya',

            // Ukrainian
            '??' => 'Ye', '??' => 'I', '??' => 'Yi', '??' => 'G',
            '??' => 'ye', '??' => 'i', '??' => 'yi', '??' => 'g',

            // Czech
            '??' => 'C', '??' => 'D', '??' => 'E', '??' => 'N', '??' => 'R', '??' => 'S', '??' => 'T', '??' => 'U',
            '??' => 'Z',
            '??' => 'c', '??' => 'd', '??' => 'e', '??' => 'n', '??' => 'r', '??' => 's', '??' => 't', '??' => 'u',
            '??' => 'z',

            // Polish
            '??' => 'A', '??' => 'C', '??' => 'e', '??' => 'L', '??' => 'N', '??' => 'S', '??' => 'Z',
            '??' => 'Z',
            '??' => 'a', '??' => 'c', '??' => 'e', '??' => 'l', '??' => 'n', '??' => 's', '??' => 'z',
            '??' => 'z',

            // Latvian
            '??' => 'A', '??' => 'E', '??' => 'G', '??' => 'i', '??' => 'k', '??' => 'L', '??' => 'N', '??' => 'u',
            '??' => 'a', '??' => 'e', '??' => 'g', '??' => 'i', '??' => 'k', '??' => 'l', '??' => 'n', '??' => 'u',
        ];

        $replacements = array();

        if(!empty($options['replacements']) AND is_array($options['replacements'])){
            $replacements = $options['replacements'];
        }

        if(isset($options['transliterate']) AND !$options['transliterate']){
            $char_map = array();
        }

        $options['replacements'] = array_merge($replacements, $char_map);

        if(!empty($options['replacements']) AND is_array($options['replacements'])){
            foreach ($options['replacements'] as $objName => $val) {
                $str = str_replace($objName, $val, $str);

            }
        }

        $options = array_merge($defaults, $options);
        $str = preg_replace('/[^\p{L}\p{Nd}_]+/u', $options['delimiter'], $str);
        $str = preg_replace('/(' . preg_quote($options['delimiter'], '/') . '){2,}/', '$1', $str);
        $str = mb_substr($str, 0, ($options['limit'] ? $options['limit'] : mb_strlen($str, 'UTF-8')), 'UTF-8');
        $str = trim($str, $options['delimiter']);
        $link = $options['lowercase'] ? mb_strtolower($str, 'UTF-8') : $str;

        if(!empty($options['unique']['tableName'])){

            $tableName = $options['unique']['tableName'];
            $delimiter = $defaults['unique']['delimiter'];
            $titleColumn = $defaults['unique']['titleColumn'];
            $linkColumn = $defaults['unique']['linkColumn'];

            if(!$this->is_table($options['unique']['tableName'])){
                return $link;
            } else {

                if(!empty($options['unique']['delimiter'])){
                    $delimiter = $options['unique']['delimiter'];
                }
                if(!empty($options['unique']['titleColumn'])){
                    $titleColumn = $options['unique']['titleColumn'];
                }
                if(!empty($options['unique']['linkColumn'])){
                    $linkColumn = $options['unique']['linkColumn'];
                }

                $data = $this->samantha($tableName, array($titleColumn => $plainText));

                if(!empty($data)){
                    $num = count($data)+1;
                } else {
                    $num = 1;
                }

                for ($i = 1; $i<=$num; $i++){

                    if(!$this->do_have($tableName, $link, $linkColumn)){
                        return $link;
                    } else {
                        if(!$this->do_have($tableName, $link.$delimiter.$i, $linkColumn)){
                            return $link.$delimiter.$i;
                        }
                    }
                }
                return $link.$delimiter.$num;
            }
        }

        if(!empty($options['unique']['directory'])){
            $param = $options['delimiter'];
            $list = glob($options['unique']['directory'].$link."*");
            $totalFiles = count($list);

            if($totalFiles == 1){
                $link = $link.$options['delimiter'].'1';
            } else {
                if($totalFiles > 1){
                    $param .= count($list)+1;
                } 
                if($totalFiles == 0 ){
                    $param = '';
                }
                $link = $link.$param;
            }
            
        }

        return $link;
    }

    /**
     * timeAgo
     * Indicates the elapsed time.
     * @param string $datetime
     * @param array|null $translations
     * @return string
     */
    public function timeAgo($datetime, $translations=[]) {

        $now = new DateTime();
        $ago = new DateTime((string)$datetime);
        $diff = $now->diff($ago);
    
        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;
    
        $translations['a'] = (isset($translations['a'])) ? $translations['a'] : 'ago';
        $translations['p'] = (isset($translations['p'])) ? $translations['p'] : 's';
        $translations['j'] = (isset($translations['j'])) ? $translations['j'] : 'just now';
        $translations['f'] = (isset($translations['f'])) ? $translations['f'] : false;

        $string = array(
            'y' => (!isset($translations['y'])) ? 'year' : $translations['y'],
            'm' => (!isset($translations['m'])) ? 'month' : $translations['m'],
            'w' => (!isset($translations['w'])) ? 'week' : $translations['w'],
            'd' => (!isset($translations['d'])) ? 'day' : $translations['d'],
            'h' => (!isset($translations['h'])) ? 'hour' : $translations['h'],
            'i' => (!isset($translations['i'])) ? 'minute' : $translations['i'],
            's' => (!isset($translations['s'])) ? 'second' : $translations['s'],
        );

        foreach ($string as $key => $val) {
            
            if ($diff->$key) {
                $string[$key] = $diff->$key . ' ' . $val . ($diff->$key > 1 ? $translations['p'] : '');
            } else {
                unset($string[$key]);
            }
        }
    
        if (!$translations['f']){
            $string = array_slice($string, 0, 1);
        } 

        return (!empty($string)) ? implode(', ', $string) . ' '.$translations['a'] : '-';
    }

    /**
     * Time zones.
     * List of supported time zones.
     * @return array
     */
    public function timezones(){
        return timezone_identifiers_list();
    }

    /**
     * Languages
     * Language abbreviations and country names (with local names)
     * @return array
     */
    public function languages(){
        return json_decode('
        {"AB":{"name":"Abkhaz","nativeName":"\u0430\u04a7\u0441\u0443\u0430"},"AA":{"name":"Afar","nativeName":"Afaraf"},"AF":{"name":"Afrikaans","nativeName":"Afrikaans"},"AK":{"name":"Akan","nativeName":"Akan"},"SQ":{"name":"Albanian","nativeName":"Shqip"},"AM":{"name":"Amharic","nativeName":"\u12a0\u121b\u122d\u129b"},"AR":{"name":"Arabic","nativeName":"\u0627\u0644\u0639\u0631\u0628\u064a\u0629"},"AN":{"name":"Aragonese","nativeName":"Aragon\u00e9s"},"HY":{"name":"Armenian","nativeName":"\u0540\u0561\u0575\u0565\u0580\u0565\u0576"},"AS":{"name":"Assamese","nativeName":"\u0985\u09b8\u09ae\u09c0\u09af\u09bc\u09be"},"AV":{"name":"Avaric","nativeName":"\u0430\u0432\u0430\u0440 \u043c\u0430\u0446\u04c0, \u043c\u0430\u0433\u04c0\u0430\u0440\u0443\u043b \u043c\u0430\u0446\u04c0"},"AE":{"name":"Avestan","nativeName":"avesta"},"AY":{"name":"Aymara","nativeName":"aymar aru"},"AZ":{"name":"Azerbaijani","nativeName":"az\u0259rbaycan dili"},"BM":{"name":"Bambara","nativeName":"bamanankan"},"BA":{"name":"Bashkir","nativeName":"\u0431\u0430\u0448\u04a1\u043e\u0440\u0442 \u0442\u0435\u043b\u0435"},"EU":{"name":"Basque","nativeName":"euskara, euskera"},"BE":{"name":"Belarusian","nativeName":"\u0411\u0435\u043b\u0430\u0440\u0443\u0441\u043a\u0430\u044f"},"BN":{"name":"Bengali","nativeName":"\u09ac\u09be\u0982\u09b2\u09be"},"BH":{"name":"Bihari","nativeName":"\u092d\u094b\u091c\u092a\u0941\u0930\u0940"},"BI":{"name":"Bislama","nativeName":"Bislama"},"BS":{"name":"Bosnian","nativeName":"bosanski jezik"},"BR":{"name":"Breton","nativeName":"brezhoneg"},"BG":{"name":"Bulgarian","nativeName":"\u0431\u044a\u043b\u0433\u0430\u0440\u0441\u043a\u0438 \u0435\u0437\u0438\u043a"},"MY":{"name":"Burmese","nativeName":"\u1017\u1019\u102c\u1005\u102c"},"CA":{"name":"Catalan; Valencian","nativeName":"Catal\u00e0"},"CH":{"name":"Chamorro","nativeName":"Chamoru"},"CE":{"name":"Chechen","nativeName":"\u043d\u043e\u0445\u0447\u0438\u0439\u043d \u043c\u043e\u0442\u0442"},"NY":{"name":"Chichewa; Chewa; Nyanja","nativeName":"chiChe\u0175a, chinyanja"},"ZH":{"name":"Chinese","nativeName":"\u4e2d\u6587 (Zh\u014dngw\u00e9n), \u6c49\u8bed, \u6f22\u8a9e"},"CV":{"name":"Chuvash","nativeName":"\u0447\u04d1\u0432\u0430\u0448 \u0447\u04d7\u043b\u0445\u0438"},"KW":{"name":"Cornish","nativeName":"Kernewek"},"CO":{"name":"Corsican","nativeName":"corsu, lingua corsa"},"CR":{"name":"Cree","nativeName":"\u14c0\u1426\u1403\u152d\u140d\u140f\u1423"},"HR":{"name":"Croatian","nativeName":"hrvatski"},"CS":{"name":"Czech","nativeName":"\u010desky, \u010de\u0161tina"},"DA":{"name":"Danish","nativeName":"dansk"},"DV":{"name":"Divehi; Dhivehi; Maldivian;","nativeName":"\u078b\u07a8\u0788\u07ac\u0780\u07a8"},"NL":{"name":"Dutch","nativeName":"Nederlands, Vlaams"},"EN":{"name":"English","nativeName":"English"},"EO":{"name":"Esperanto","nativeName":"Esperanto"},"ET":{"name":"Estonian","nativeName":"eesti, eesti keel"},"EE":{"name":"Ewe","nativeName":"E\u028begbe"},"FO":{"name":"Faroese","nativeName":"f\u00f8royskt"},"FJ":{"name":"Fijian","nativeName":"vosa Vakaviti"},"FI":{"name":"Finnish","nativeName":"suomi, suomen kieli"},"FR":{"name":"French","nativeName":"fran\u00e7ais, langue fran\u00e7aise"},"FF":{"name":"Fula; Fulah; Pulaar; Pular","nativeName":"Fulfulde, Pulaar, Pular"},"GL":{"name":"Galician","nativeName":"Galego"},"KA":{"name":"Georgian","nativeName":"\u10e5\u10d0\u10e0\u10d7\u10e3\u10da\u10d8"},"DE":{"name":"German","nativeName":"Deutsch"},"EL":{"name":"Greek, Modern","nativeName":"\u0395\u03bb\u03bb\u03b7\u03bd\u03b9\u03ba\u03ac"},"GN":{"name":"Guaran\u00ed","nativeName":"Ava\u00f1e\u1ebd"},"GU":{"name":"Gujarati","nativeName":"\u0a97\u0ac1\u0a9c\u0ab0\u0abe\u0aa4\u0ac0"},"HT":{"name":"Haitian; Haitian Creole","nativeName":"Krey\u00f2l ayisyen"},"HA":{"name":"Hausa","nativeName":"Hausa, \u0647\u064e\u0648\u064f\u0633\u064e"},"HE":{"name":"Hebrew (modern)","nativeName":"\u05e2\u05d1\u05e8\u05d9\u05ea"},"HZ":{"name":"Herero","nativeName":"Otjiherero"},"HI":{"name":"Hindi","nativeName":"\u0939\u093f\u0928\u094d\u0926\u0940, \u0939\u093f\u0902\u0926\u0940"},"HO":{"name":"Hiri Motu","nativeName":"Hiri Motu"},"HU":{"name":"Hungarian","nativeName":"Magyar"},"IA":{"name":"Interlingua","nativeName":"Interlingua"},"ID":{"name":"Indonesian","nativeName":"Bahasa Indonesia"},"IE":{"name":"Interlingue","nativeName":"Originally called Occidental; then Interlingue after WWII"},"GA":{"name":"Irish","nativeName":"Gaeilge"},"IG":{"name":"Igbo","nativeName":"As\u1ee5s\u1ee5 Igbo"},"IK":{"name":"Inupiaq","nativeName":"I\u00f1upiaq, I\u00f1upiatun"},"IO":{"name":"Ido","nativeName":"Ido"},"IS":{"name":"Icelandic","nativeName":"\u00cdslenska"},"IT":{"name":"Italian","nativeName":"Italiano"},"IU":{"name":"Inuktitut","nativeName":"\u1403\u14c4\u1483\u144e\u1450\u1466"},"JA":{"name":"Japanese","nativeName":"\u65e5\u672c\u8a9e (\u306b\u307b\u3093\u3054\uff0f\u306b\u3063\u307d\u3093\u3054)"},"JV":{"name":"Javanese","nativeName":"basa Jawa"},"KL":{"name":"Kalaallisut, Greenlandic","nativeName":"kalaallisut, kalaallit oqaasii"},"KN":{"name":"Kannada","nativeName":"\u0c95\u0ca8\u0ccd\u0ca8\u0ca1"},"KR":{"name":"Kanuri","nativeName":"Kanuri"},"KS":{"name":"Kashmiri","nativeName":"\u0915\u0936\u094d\u092e\u0940\u0930\u0940, \u0643\u0634\u0645\u064a\u0631\u064a\u200e"},"KK":{"name":"Kazakh","nativeName":"\u049a\u0430\u0437\u0430\u049b \u0442\u0456\u043b\u0456"},"KM":{"name":"Khmer","nativeName":"\u1797\u17b6\u179f\u17b6\u1781\u17d2\u1798\u17c2\u179a"},"KI":{"name":"Kikuyu, Gikuyu","nativeName":"G\u0129k\u0169y\u0169"},"RW":{"name":"Kinyarwanda","nativeName":"Ikinyarwanda"},"KY":{"name":"Kirghiz, Kyrgyz","nativeName":"\u043a\u044b\u0440\u0433\u044b\u0437 \u0442\u0438\u043b\u0438"},"KV":{"name":"Komi","nativeName":"\u043a\u043e\u043c\u0438 \u043a\u044b\u0432"},"KG":{"name":"Kongo","nativeName":"KiKongo"},"KO":{"name":"Korean","nativeName":"\ud55c\uad6d\uc5b4 (\u97d3\u570b\u8a9e), \uc870\uc120\ub9d0 (\u671d\u9bae\u8a9e)"},"KU":{"name":"Kurdish","nativeName":"Kurd\u00ee, \u0643\u0648\u0631\u062f\u06cc\u200e"},"KJ":{"name":"Kwanyama, Kuanyama","nativeName":"Kuanyama"},"LA":{"name":"Latin","nativeName":"latine, lingua latina"},"LB":{"name":"Luxembourgish, Letzeburgesch","nativeName":"L\u00ebtzebuergesch"},"LG":{"name":"Luganda","nativeName":"Luganda"},"LI":{"name":"Limburgish, Limburgan, Limburger","nativeName":"Limburgs"},"LN":{"name":"Lingala","nativeName":"Ling\u00e1la"},"LO":{"name":"Lao","nativeName":"\u0e9e\u0eb2\u0eaa\u0eb2\u0ea5\u0eb2\u0ea7"},"LT":{"name":"Lithuanian","nativeName":"lietuvi\u0173 kalba"},"LU":{"name":"Luba-Katanga","nativeName":""},"LV":{"name":"Latvian","nativeName":"latvie\u0161u valoda"},"GV":{"name":"Manx","nativeName":"Gaelg, Gailck"},"MK":{"name":"Macedonian","nativeName":"\u043c\u0430\u043a\u0435\u0434\u043e\u043d\u0441\u043a\u0438 \u0458\u0430\u0437\u0438\u043a"},"MG":{"name":"Malagasy","nativeName":"Malagasy fiteny"},"MS":{"name":"Malay","nativeName":"bahasa Melayu, \u0628\u0647\u0627\u0633 \u0645\u0644\u0627\u064a\u0648\u200e"},"ML":{"name":"Malayalam","nativeName":"\u0d2e\u0d32\u0d2f\u0d3e\u0d33\u0d02"},"MT":{"name":"Maltese","nativeName":"Malti"},"MI":{"name":"M\u0101ori","nativeName":"te reo M\u0101ori"},"MR":{"name":"Marathi (Mar\u0101\u1e6dh\u012b)","nativeName":"\u092e\u0930\u093e\u0920\u0940"},"MH":{"name":"Marshallese","nativeName":"Kajin M\u0327aje\u013c"},"MN":{"name":"Mongolian","nativeName":"\u043c\u043e\u043d\u0433\u043e\u043b"},"NA":{"name":"Nauru","nativeName":"Ekakair\u0169 Naoero"},"NV":{"name":"Navajo, Navaho","nativeName":"Din\u00e9 bizaad, Din\u00e9k\u02bceh\u01f0\u00ed"},"NB":{"name":"Norwegian Bokm\u00e5l","nativeName":"Norsk bokm\u00e5l"},"ND":{"name":"North Ndebele","nativeName":"isiNdebele"},"NE":{"name":"Nepali","nativeName":"\u0928\u0947\u092a\u093e\u0932\u0940"},"NG":{"name":"Ndonga","nativeName":"Owambo"},"NN":{"name":"Norwegian Nynorsk","nativeName":"Norsk nynorsk"},"NO":{"name":"Norwegian","nativeName":"Norsk"},"II":{"name":"Nuosu","nativeName":"\ua188\ua320\ua4bf Nuosuhxop"},"NR":{"name":"South Ndebele","nativeName":"isiNdebele"},"OC":{"name":"Occitan","nativeName":"Occitan"},"OJ":{"name":"Ojibwe, Ojibwa","nativeName":"\u140a\u14c2\u1511\u14c8\u142f\u14a7\u140e\u14d0"},"CU":{"name":"Old Church Slavonic, Church Slavic, Church Slavonic, Old Bulgarian, Old Slavonic","nativeName":"\u0469\u0437\u044b\u043a\u044a \u0441\u043b\u043e\u0432\u0463\u043d\u044c\u0441\u043a\u044a"},"OM":{"name":"Oromo","nativeName":"Afaan Oromoo"},"OR":{"name":"Oriya","nativeName":"\u0b13\u0b21\u0b3c\u0b3f\u0b06"},"OS":{"name":"Ossetian, Ossetic","nativeName":"\u0438\u0440\u043e\u043d \u00e6\u0432\u0437\u0430\u0433"},"PA":{"name":"Panjabi, Punjabi","nativeName":"\u0a2a\u0a70\u0a1c\u0a3e\u0a2c\u0a40, \u067e\u0646\u062c\u0627\u0628\u06cc\u200e"},"PI":{"name":"P\u0101li","nativeName":"\u092a\u093e\u0934\u093f"},"FA":{"name":"Persian","nativeName":"\u0641\u0627\u0631\u0633\u06cc"},"PL":{"name":"Polish","nativeName":"polski"},"PS":{"name":"Pashto, Pushto","nativeName":"\u067e\u069a\u062a\u0648"},"PT":{"name":"Portuguese","nativeName":"Portugu\u00eas"},"QU":{"name":"Quechua","nativeName":"Runa Simi, Kichwa"},"RM":{"name":"Romansh","nativeName":"rumantsch grischun"},"RN":{"name":"Kirundi","nativeName":"kiRundi"},"RO":{"name":"Romanian, Moldavian, Moldovan","nativeName":"rom\u00e2n\u0103"},"RU":{"name":"Russian","nativeName":"\u0440\u0443\u0441\u0441\u043a\u0438\u0439 \u044f\u0437\u044b\u043a"},"SA":{"name":"Sanskrit (Sa\u1e41sk\u1e5bta)","nativeName":"\u0938\u0902\u0938\u094d\u0915\u0943\u0924\u092e\u094d"},"SC":{"name":"Sardinian","nativeName":"sardu"},"SD":{"name":"Sindhi","nativeName":"\u0938\u093f\u0928\u094d\u0927\u0940, \u0633\u0646\u068c\u064a\u060c \u0633\u0646\u062f\u06be\u06cc\u200e"},"SE":{"name":"Northern Sami","nativeName":"Davvis\u00e1megiella"},"SM":{"name":"Samoan","nativeName":"gagana faa Samoa"},"SG":{"name":"Sango","nativeName":"y\u00e2ng\u00e2 t\u00ee s\u00e4ng\u00f6"},"SR":{"name":"Serbian","nativeName":"\u0441\u0440\u043f\u0441\u043a\u0438 \u0458\u0435\u0437\u0438\u043a"},"GD":{"name":"Scottish Gaelic; Gaelic","nativeName":"G\u00e0idhlig"},"SN":{"name":"Shona","nativeName":"chiShona"},"SI":{"name":"Sinhala, Sinhalese","nativeName":"\u0dc3\u0dd2\u0d82\u0dc4\u0dbd"},"SK":{"name":"Slovak","nativeName":"sloven\u010dina"},"SL":{"name":"Slovene","nativeName":"sloven\u0161\u010dina"},"SO":{"name":"Somali","nativeName":"Soomaaliga, af Soomaali"},"ST":{"name":"Southern Sotho","nativeName":"Sesotho"},"ES":{"name":"Spanish; Castilian","nativeName":"espa\u00f1ol, castellano"},"SU":{"name":"Sundanese","nativeName":"Basa Sunda"},"SW":{"name":"Swahili","nativeName":"Kiswahili"},"SS":{"name":"Swati","nativeName":"SiSwati"},"SV":{"name":"Swedish","nativeName":"svenska"},"TA":{"name":"Tamil","nativeName":"\u0ba4\u0bae\u0bbf\u0bb4\u0bcd"},"TE":{"name":"Telugu","nativeName":"\u0c24\u0c46\u0c32\u0c41\u0c17\u0c41"},"TG":{"name":"Tajik","nativeName":"\u0442\u043e\u04b7\u0438\u043a\u04e3, to\u011fik\u012b, \u062a\u0627\u062c\u06cc\u06a9\u06cc\u200e"},"TH":{"name":"Thai","nativeName":"\u0e44\u0e17\u0e22"},"TI":{"name":"Tigrinya","nativeName":"\u1275\u130d\u122d\u129b"},"BO":{"name":"Tibetan Standard, Tibetan, Central","nativeName":"\u0f56\u0f7c\u0f51\u0f0b\u0f61\u0f72\u0f42"},"TK":{"name":"Turkmen","nativeName":"T\u00fcrkmen, \u0422\u04af\u0440\u043a\u043c\u0435\u043d"},"TL":{"name":"Tagalog","nativeName":"Wikang Tagalog, \u170f\u1712\u1703\u1705\u1714 \u1706\u1704\u170e\u1713\u1704\u1714"},"TN":{"name":"Tswana","nativeName":"Setswana"},"TO":{"name":"Tonga (Tonga Islands)","nativeName":"faka Tonga"},"TR":{"name":"Turkish","nativeName":"T\u00fcrk\u00e7e"},"TS":{"name":"Tsonga","nativeName":"Xitsonga"},"TT":{"name":"Tatar","nativeName":"\u0442\u0430\u0442\u0430\u0440\u0447\u0430, tatar\u00e7a, \u062a\u0627\u062a\u0627\u0631\u0686\u0627\u200e"},"TW":{"name":"Twi","nativeName":"Twi"},"TY":{"name":"Tahitian","nativeName":"Reo Tahiti"},"UG":{"name":"Uighur, Uyghur","nativeName":"Uy\u01a3urq\u0259, \u0626\u06c7\u064a\u063a\u06c7\u0631\u0686\u06d5\u200e"},"UK":{"name":"Ukrainian","nativeName":"\u0443\u043a\u0440\u0430\u0457\u043d\u0441\u044c\u043a\u0430"},"UR":{"name":"Urdu","nativeName":"\u0627\u0631\u062f\u0648"},"UZ":{"name":"Uzbek","nativeName":"zbek, \u040e\u0437\u0431\u0435\u043a, \u0623\u06c7\u0632\u0628\u06d0\u0643\u200e"},"VE":{"name":"Venda","nativeName":"Tshiven\u1e13a"},"VI":{"name":"Vietnamese","nativeName":"Ti\u1ebfng Vi\u1ec7t"},"VO":{"name":"Volap\u00fck","nativeName":"Volap\u00fck"},"WA":{"name":"Walloon","nativeName":"Walon"},"CY":{"name":"Welsh","nativeName":"Cymraeg"},"WO":{"name":"Wolof","nativeName":"Wollof"},"FY":{"name":"Western Frisian","nativeName":"Frysk"},"XH":{"name":"Xhosa","nativeName":"isiXhosa"},"YI":{"name":"Yiddish","nativeName":"\u05d9\u05d9\u05b4\u05d3\u05d9\u05e9"},"YO":{"name":"Yoruba","nativeName":"Yor\u00f9b\u00e1"},"ZA":{"name":"Zhuang, Chuang","nativeName":"Sa\u026f cue\u014b\u0185, Saw cuengh"}}', true);
    }

    /**
     * currencies
     * Currencies and country names
     * @return array
     */
    public function currencies(){
        return array("AED" => "United Arab Emirates dirham","AFN" => "Afghan afghani","ALL" => "Albanian lek","AMD" => "Armenian dram","ANG" => "Netherlands Antillean guilder","AOA" => "Angolan kwanza","ARS" => "Argentine peso","AUD" => "Australian dollar","AWG" => "Aruban florin","AZN" => "Azerbaijani manat","BAM" => "Bosnia and Herzegovina convertible mark","BBD" => "Barbados dollar","BDT" => "Bangladeshi taka","BGN" => "Bulgarian lev","BHD" => "Bahraini dinar","BIF" => "Burundian franc","BMD" => "Bermudian dollar","BND" => "Brunei dollar","BOB" => "Boliviano","BRL" => "Brazilian real","BSD" => "Bahamian dollar","BTN" => "Bhutanese ngultrum","BWP" => "Botswana pula","BYN" => "New Belarusian ruble","BYR" => "Belarusian ruble","BZD" => "Belize dollar","CAD" => "Canadian dollar","CDF" => "Congolese franc","CHF" => "Swiss franc","CLF" => "Unidad de Fomento","CLP" => "Chilean peso","CNY" => "Renminbi|Chinese yuan","COP" => "Colombian peso","CRC" => "Costa Rican colon","CUC" => "Cuban convertible peso","CUP" => "Cuban peso","CVE" => "Cape Verde escudo","CZK" => "Czech koruna","DJF" => "Djiboutian franc","DKK" => "Danish krone","DOP" => "Dominican peso","DZD" => "Algerian dinar","EGP" => "Egyptian pound","ERN" => "Eritrean nakfa","ETB" => "Ethiopian birr","EUR" => "Euro","FJD" => "Fiji dollar","FKP" => "Falkland Islands pound","GBP" => "Pound sterling","GEL" => "Georgian lari","GHS" => "Ghanaian cedi","GIP" => "Gibraltar pound","GMD" => "Gambian dalasi","GNF" => "Guinean franc","GTQ" => "Guatemalan quetzal","GYD" => "Guyanese dollar","HKD" => "Hong Kong dollar","HNL" => "Honduran lempira","HRK" => "Croatian kuna","HTG" => "Haitian gourde","HUF" => "Hungarian forint","IDR" => "Indonesian rupiah","ILS" => "Israeli new shekel","INR" => "Indian rupee","IQD" => "Iraqi dinar","IRR" => "Iranian rial","ISK" => "Icelandic kr??na","JMD" => "Jamaican dollar","JOD" => "Jordanian dinar","JPY" => "Japanese yen","KES" => "Kenyan shilling","KGS" => "Kyrgyzstani som","KHR" => "Cambodian riel","KMF" => "Comoro franc","KPW" => "North Korean won","KRW" => "South Korean won","KWD" => "Kuwaiti dinar","KYD" => "Cayman Islands dollar","KZT" => "Kazakhstani tenge","LAK" => "Lao kip","LBP" => "Lebanese pound","LKR" => "Sri Lankan rupee","LRD" => "Liberian dollar","LSL" => "Lesotho loti","LYD" => "Libyan dinar","MAD" => "Moroccan dirham","MDL" => "Moldovan leu","MGA" => "Malagasy ariary","MKD" => "Macedonian denar","MMK" => "Myanmar kyat","MNT" => "Mongolian t??gr??g","MOP" => "Macanese pataca","MRO" => "Mauritanian ouguiya","MUR" => "Mauritian rupee","MVR" => "Maldivian rufiyaa","MWK" => "Malawian kwacha","MXN" => "Mexican peso","MXV" => "Mexican Unidad de Inversion","MYR" => "Malaysian ringgit","MZN" => "Mozambican metical","NAD" => "Namibian dollar","NGN" => "Nigerian naira","NIO" => "Nicaraguan c??rdoba","NOK" => "Norwegian krone","NPR" => "Nepalese rupee","NZD" => "New Zealand dollar","OMR" => "Omani rial","PAB" => "Panamanian balboa","PEN" => "Peruvian Sol","PGK" => "Papua New Guinean kina","PHP" => "Philippine peso","PKR" => "Pakistani rupee","PLN" => "Polish z??oty","PYG" => "Paraguayan guaran??","QAR" => "Qatari riyal","RON" => "Romanian leu","RSD" => "Serbian dinar","RUB" => "Russian ruble","RWF" => "Rwandan franc","SAR" => "Saudi riyal","SBD" => "Solomon Islands dollar","SCR" => "Seychelles rupee","SDG" => "Sudanese pound","SEK" => "Swedish krona","SGD" => "Singapore dollar","SHP" => "Saint Helena pound","SLL" => "Sierra Leonean leone","SOS" => "Somali shilling","SRD" => "Surinamese dollar","SSP" => "South Sudanese pound","STD" => "S??o Tom?? and Pr??ncipe dobra","SVC" => "Salvadoran col??n","SYP" => "Syrian pound","SZL" => "Swazi lilangeni","THB" => "Thai baht","TJS" => "Tajikistani somoni","TMT" => "Turkmenistani manat","TND" => "Tunisian dinar","TOP" => "Tongan pa??anga","TRY" => "Turkish lira","TTD" => "Trinidad and Tobago dollar","TWD" => "New Taiwan dollar","TZS" => "Tanzanian shilling","UAH" => "Ukrainian hryvnia","UGX" => "Ugandan shilling","USD" => "United States dollar","UYI" => "Uruguay Peso en Unidades Indexadas","UYU" => "Uruguayan peso","UZS" => "Uzbekistan som","VEF" => "Venezuelan bol??var","VND" => "Vietnamese ?????ng","VUV" => "Vanuatu vatu","WST" => "Samoan tala","XAF" => "Central African CFA franc","XCD" => "East Caribbean dollar","XOF" => "West African CFA franc","XPF" => "CFP franc","XXX" => "No currency","YER" => "Yemeni rial","ZAR" => "South African rand","ZMW" => "Zambian kwacha","ZWL" => "Zimbabwean dollar"
        );
    }

    /**
     * morsealphabet
     * @param array|null $morseDictionary
     * @return array
     */
    public function morsealphabet($morseDictionary = array()){
        if(!empty($morseDictionary)){
            return $morseDictionary;
        }
        return array(
             'a' => '.-', 'b' => '-...', 'c' => '-.-.', '??' => '-.-..', 'd' => '-..', 'e' => '.', 'f' => '..-.', 'g' => '--.', '??' => '--.-.', 'h' => '....', '??' => '..', 'i' => '.-..-', 'j' => '.---', 'k' => '-.-', 'l' => '.-..', 'm' => '--', 'n' => '-.', 'o' => '---', '??' => '---.', 'p' => '.--.', 'q' => '--.-', 'r' => '.-.', 's' => '...', '??' => '.--..', 't' => '-', 'u' => '..-', '??' => '..--', 'v' => '...-', 'w' => '.--', 'x' => '-..-', 'y' => '-.--', 'z' => '--..', '0' => '-----', '1' => '.----', '2' => '..---', '3' => '...--', '4' => '....-', '5' => '.....', '6' => '-....', '7' => '--...', '8' => '---..', '9' => '----.', '.' => '.-.-.-', ',' => '--..--', '?' => '..--..', '\'' => '.----.', '!'=> '-.-.--', '/'=> '-..-.', '(' => '-.--.', ')' => '-.--.-', '&' => '.-...', ':' => '---...', ';' => '-.-.-.', '=' => '-...-', '+' => '.-.-.', '-' => '-....-', '_' => '..--.-', '"' => '.-..-.', '$' => '...-..-', '@' => '.--.-.', '??' => '..-.-', '??' => '--...-', ' ' => '/',
        );
     }
     
    /**
     * Session checking.
     *
     * @return array $_SESSSION
     */
    public function session_check(){

        if($this->sess_set['status_session']){

            if($this->sess_set['path_status']){

                if(!is_dir($this->sess_set['path'])){
                    mkdir($this->sess_set['path']); 
                    chmod($this->sess_set['path'], 755);
                    $this->policyMaker();
                }

                if(is_dir($this->sess_set['path'])){
                    ini_set(
                        'session.save_path',
                        realpath(
                            dirname(__FILE__)
                        ).'/'.$this->sess_set['path']
                    );
                }

            }

            if(!isset($_SESSION)){
                session_start();
            }

        }

    }

    /**
     * Learns the size of the remote file.
     *
     * @param string $url
     * @return int
     */
    public function remoteFileSize($url){
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, TRUE);
        curl_setopt($ch, CURLOPT_NOBODY, TRUE);

        curl_exec($ch);

        $response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

        curl_close($ch);

        if(!in_array($response_code, array('200'))){
            return -1;
        }
        return $size;
    }

    /**
     * Layer installer
     *
     * @param string|array|null $file
     * @param string|array|null $cache
     */

    public function addLayer($file = null, $cache = null){
        
        // layer extension
        $ext = '.php';

        // layer set
        $layers = [];

        // temporary layers
        $tempLayers = [];

        // Cache layers are taken into account
        if(!is_null($cache) AND !is_array($cache)) $layers[] = $cache;
        if(!is_null($cache) AND is_array($cache)) foreach($cache as $c) { $layers[] = $c; }

        // File layers are taken into account
        if(!is_null($file) AND !is_array($file)) $layers[] = $file;
        if(!is_null($file) AND is_array($file)) foreach($file as $f) { $layers[] = $f; }

        // All layers are run sequentially
        foreach ($layers as $key => $layer) {
            $tempLayers[$key] = $this->wayMaker($layer);
        }

        // Layers are being processed
        foreach ($tempLayers as $layer) {
            
            // Checking for layer existence
            if(file_exists($layer['way'].$ext)) require_once($layer['way'].$ext);
            
            // The class name is extracted from the layer path
            $className = basename($layer['way']);

            // If the class exists, it is assigned to the variable
            if(class_exists($className)){ $class = new $className();
                
                // If the method exists, it is executed.
                if(isset($layer['params'])){ foreach ($layer['params'] as $param) { $class->$param(); } }

            }

        }

     }

    /**
     * Column sql syntax creator.
     *
     * @param array $scheme
     * @param string $funcName
     * @return array
     */
    public function columnSqlMaker($scheme, $funcName=null){

        $sql = [];
        $column = [];
        $primary_key = null;

        foreach (array_values($scheme) as $array_value) {
            
            $column = $this->wayMaker($array_value);
            $type = (isset($column['params'][0])) ? $column['params'][0] :  'small';
            
            switch ($type) {
                case 'int':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 11;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` INT('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` INT('.$value.') NULL DEFAULT NULL';
                        break;
                        case 'sqlite':  
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 11;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` INT('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` INT('.$value.') NULL DEFAULT NULL';
                        break;   
                    } 
                break;
                case 'decimal':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 6.2;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` DECIMAL('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` DECIMAL('.$value.') NULL DEFAULT NULL';
                        break;
                        case 'sqlite':  
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 6.2;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` DECIMAL('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` DECIMAL('.$value.') NULL DEFAULT NULL';
                        break;   
                    }  
                break;
                case 'string':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 255;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` VARCHAR('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` VARCHAR('.$value.') NULL DEFAULT NULL';
                        break;
                        case 'sqlite':  
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 255;                          
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` VARCHAR('.$value.') NULL DEFAULT NULL' : '`'.$column['way'].'` VARCHAR('.$value.') NULL DEFAULT NULL';
                        break;   
                    }                    
                break;
                case 'small':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` TEXT NULL DEFAULT NULL' : '`'.$column['way'].'` TEXT NULL DEFAULT NULL';
                        break;
                        case 'sqlite':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` TEXT NULL DEFAULT NULL' : '`'.$column['way'].'` TEXT NULL DEFAULT NULL';
                        break;   
                    }
                break;
                case 'medium':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` MEDIUMTEXT NULL DEFAULT NULL' : '`'.$column['way'].'` MEDIUMTEXT NULL DEFAULT NULL';
                        break;
                        case 'sqlite':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` MEDIUMTEXT NULL DEFAULT NULL' : '`'.$column['way'].'` MEDIUMTEXT NULL DEFAULT NULL';
                        break;   
                    }
                break;
                case 'large':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` LONGTEXT NULL DEFAULT NULL' : '`'.$column['way'].'` LONGTEXT NULL DEFAULT NULL'; 
                        break;
                        case 'sqlite':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` LONGTEXT NULL DEFAULT NULL' : '`'.$column['way'].'` LONGTEXT NULL DEFAULT NULL'; 
                        break;   
                    }
                break;
                case 'increments':
                    switch ($this->db['drive']) {
                        case 'mysql':
                            $value = (isset($column['params'][1])) ? $column['params'][1] : 11;
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD `'.$column['way'].'` INT('.$value.') NOT NULL AUTO_INCREMENT FIRST' : '`'.$column['way'].'` INT('.$value.') NOT NULL AUTO_INCREMENT';
                            $primary_key = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD PRIMARY KEY (`'.$column['way'].'`)' : 'PRIMARY KEY (`'.$column['way'].'`)';
                        break;
                        case 'sqlite':
                            $sql[] = (!is_null($funcName) AND $funcName == 'columnCreate') ? 'ADD COLUMN `'.$column['way'].'` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL' : '`'.$column['way'].'` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL';
                        break;
                            
                    }
                   
                break;
                
            }
        }
        
        // for mysql
        if(!is_null($primary_key)){ $sql[] = $primary_key; }

        return $sql;

        
    }

    /**
     * Layer and method parser.
     *
     * @param string $str
     * @return array
     */
    public function wayMaker($str=''){

        // The output variable is being created.
        $output = [];

        // The parameter variable is being created.
        $assets = [];

        // The layer and parameter are being parsed.
        if(strstr($str, ':')){ 
            $assets = explode(':', trim($str, ':')); } else { $assets[] = $str; }

        // If there is no layer parameter, only the layer is defined
        if(count($assets) == 1){ $output['way'] = $assets[0]; }

        // If there is a layer and its parameter, it is assigned to the variable.
        if(count($assets) == 2){ list($output['way'], $output['params']) = $assets; }

        // Parameters are obtained
        if(isset($output['params'])){
            $output['params'] = (strstr($output['params'], '@')) ? explode('@', trim($output['params'], '@')) : $output['params'] = [$output['params']];
        } else {
            $output['params'] = [];
        }
        
        return $output;
    }

    /**
     * Token generator
     * 
     * @param int $length
     * @return string
     */
    public function generateToken($length=100){
        $key = '';
        $keys = array_merge(range('A', 'Z'), range(0, 9), range('a', 'z'), range(0, 9));

        for ($i = 0; $i < $length; $i++) {
            $key .= $keys[array_rand($keys)];
        }

        return $key;
    }

    /**
     * Coordinates marker
     * 
     * @param string $element
     * @return string|null It interferes with html elements.
     */
    public function coordinatesMaker($element='#coordinates'){
        $element = $this->filter($element);
        ?>
        <script>
            

            function getLocation() {
                let = elements = document.querySelectorAll("<?=$element;?>");
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(redirectToPosition);
                } else { 
                    console.log("Geolocation is not supported by this browser.");
                    elements.forEach(function(element) {
                        element.value = null;
                    });
                }
            }

            function redirectToPosition(position) {
                let elements = document.querySelectorAll("<?=$element;?>");
                let coordinates = position.coords.latitude+','+position.coords.longitude;
                if(elements.length >= 1){

                    elements.forEach(function(element) {
                        if(element.value === undefined){
                            element.textContent = coordinates;
                        } else {
                            element.value = coordinates;
                        }
                    });
                } else {
                    console.log("The item was not found.");
                }
            }
            
            getLocation();
        </script>

        <?php
    }

    /**
     * Encode size
     * @param string|int $size
     * @param string|int $precision
     * @return string|bool
     */
    public function encodeSize($size, $precision = 2)
    {
        $sizeLibrary = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');

        if(isset($size['size'])){
            $size = $size['size'];
        }

        if(!strstr($size, ' ')){
            $exp = floor(log($size, 1024)) | 0;
            $exp = min($exp, count($sizeLibrary) - 1);
            return round($size / (pow(1024, $exp)), $precision).' '.$sizeLibrary[$exp];
        }

        return false;
    }

    /**
     * Encode size
     * @param string|int $size
     * @return int|bool
     */
    public function decodeSize($size)
    {
        $sizeLibrary = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');

        if(strstr($size, ' ')){

            if(count(explode(' ', $size)) === 2){
                list($number, $format) = explode(' ', $size);
                $id = array_search($format, $sizeLibrary);
                return $number*pow(1024, $id);
            } 
        }

        return false;

    }

    /**
     * @return string
     */
    public function getIPAddress(){
        if($_SERVER['REMOTE_ADDR'] === '::1'){
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        }
        return $_SERVER['REMOTE_ADDR'];
    }

    /**
     * @return string
     */
    public function getLang(){
        return mb_strtoupper(mb_substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));
    }

    /**
     * getAddressCode
     * 
     * @param string|array $address
     * @param string|array|null $status
     * @return array
     */
    public function getAddressCode($address, $status=null){

        $result = array();
        $statusList = array();
        if(!is_array($address)){
            $address = array($address);
        }

        if(!is_null($status)){
            if(!is_array($status)){
                $status = array($status);
            }

            foreach ($status as $key => $code) {
                if(!in_array($code, $this->addressCodeList())){
                    return $result;
                } else {
                    $statusList[] = $code;
                }
            }
        } else {
            $statusList = $this->addressCodeList();
        }
        
        $mh = curl_multi_init();
		foreach($address as $key => $value){
            $ch[$key] = curl_init($value);
			curl_setopt($ch[$key], CURLOPT_TIMEOUT, 1);
			curl_setopt($ch[$key], CURLOPT_HEADER, 0);
			curl_setopt($ch[$key], CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch[$key], CURLOPT_TIMEOUT, 1);
			curl_setopt($ch[$key], CURLOPT_VERBOSE, 0);
			curl_multi_add_handle($mh, $ch[$key]);
		}
		do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
          } while ($running > 0);

          foreach(array_keys($ch) as $key){
			$httpcode = curl_getinfo($ch[$key], CURLINFO_HTTP_CODE);
            if(in_array($httpcode, $statusList)){
                $result[$key] = array(
                    'code' => $httpcode,
                    'address' => $address[$key],
                    'timestamp' => $this->timestamp
                  );
            }
			curl_multi_remove_handle($mh, $ch[$key]);
		}
		curl_multi_close($mh);
        
        return $result;
    }
    
    /**
     * addressCodeList
     * @return array
     * 
     */
    public function addressCodeList(){
        // Codes collected from http://en.wikipedia.org/wiki/List_of_HTTP_status_codes
        return array(0,100,101,102,200,201,202,203,204,205,206,207,208,226,300,301,302,303,304,305,306,307,308,400,401,402,403,404,405,406,407,408,409,410,411,412,413,414,415,416,417,418,419,420,422,423,424,425,426,428,429,431,444,449,450,451,494,495,496,497,499,500,501,502,503,504,505,506,507,508,509,510,511,598,599);
    }

    /**
     * Address Generator
     * 
     */
    public function addressGenerator($start, $end, $type="ipv4"){

        $result = array();

        if(empty($type)){
            return $result;
        }

        switch ($type) {
            case 'ipv4':

                if(!$this->is_ipv4($start) OR !$this->is_ipv4($end)){
                    return $result;
                }

                if($start>$end){
                    $x = $start; $start = $end; $end = $x; unset($x);
                }

                list($aa, $bb, $cc, $dd) = explode('.', $start);
                for ($a=$aa; $a <= 255; $a++) { 
                    for ($b=$bb; $b <= 255; $b++) { 
                        for ($c=$cc; $c <= 255; $c++) { 
                            for ($d=$dd; $d <= 255; $d++) { 
                                if ($a.'.'.$b.'.'.$c.'.'.$d == $end) {	
                                    $result[] = $a.'.'.$b.'.'.$c.'.'.$d;			
                                    break;
                                }	
                                $result[] = $a.'.'.$b.'.'.$c.'.'.$d;
                                $dd = 0;
                            }
                            if ($a.'.'.$b.'.'.$c.'.'.$d == $end) {				
                                break;
                            }	
                            $cc = 0;
                        }
                        if ($a.'.'.$b.'.'.$c.'.'.$d == $end) {				
                            break;
                        }	
                        $bb = 0;
                    }
                    if ($a.'.'.$b.'.'.$c.'.'.$d == $end) {				
                        break;
                    }	
                    $aa = 0;
                }	
            break;
            
        }

        return $result;
        
    }

    
    /**
     * Detecting an operating system
     * @return string
     */
    public function getOS(){
        $os = PHP_OS;
        switch (true) {
            case stristr($os, 'dar'): return 'Darwin';
            case stristr($os, 'win'): return 'Windows';
            case stristr($os, 'lin'): return 'Linux';
            default : return 'Unknown';
        }
    }

    /**
     * Detecting an server software
     * @return string
     */
    public function getSoftware(){
        $software = $_SERVER['SERVER_SOFTWARE'];
        switch (true) {
            case stristr($software, 'apac'): return 'Apache';
            case stristr($software, 'micr'): return 'Microsoft-IIS';
            case stristr($software, 'lites'): return 'LiteSpeed';
            case stristr($software, 'nginx'): return 'Nginx';
            default : return 'Unknown';
        }
    }

    /**
     * Routing manager.
     *
     * @param string $uri
     * @param mixed $file
     * @param mixed $cache
     * @return bool
     */
    public function route($uri, $file, $cache=null){
        
        // Access directives are being created.
        $this->policyMaker();

        if(empty($file)){
            return false;
        }

        if($this->base_url != '/'){
            $request = str_replace($this->base_url, '', rawurldecode($_SERVER['REQUEST_URI']));
        } else {
            $request = trim(rawurldecode($_SERVER['REQUEST_URI']), '/');
        }

        $fields     = array();

        if(!empty($uri)){

            $uriData = $this->wayMaker($uri);
            if(!empty($uriData['way'])){
                $uri = $uriData['way'];
            }
            if(!empty($uriData['params'])){
                $fields = $uriData['params'];
            }
        }

        if($uri == '/'){
            $uri = $this->base_url;
        }

        $params = array();

        if($_SERVER['REQUEST_METHOD'] != 'POST'){

            if(strstr($request, '/')){
                $params = explode('/', $request);
                $UriParams = explode('/', $uri);

                if(count($params) >= count($UriParams)){
                    for ($key = 0; count($UriParams) > $key; $key++){
                        unset($params[$key]);
                    }
                }

                $params = array_values($params);
            }

            $this->post = array();

            if(!empty($fields) AND !empty($params)){

                foreach ($fields as $key => $field) {

                    if(isset($params[$key])){

                        if(!empty($params[$key]) OR $params[$key] == '0'){
                            $this->post[$field] = $params[$key];
                        }

                    }
                }
            } else {
                $this->post = array_diff($params, array('', ' '));
            }
        } 

        if(!empty($request)){

            if(!empty($params)){
                $uri .= '/'.implode('/', $params);
            }

            if($request == $uri){
                $this->error_status = false;
                $this->page_current = $uri;
                $this->addLayer($file, $cache);
                exit();
            }

            $this->error_status = true;

        } else {
            if($uri == $this->base_url) {
                $this->error_status = false;
                $this->page_current = $uri;
                $this->addLayer($file, $cache);
                exit();
            }

        }
    
    }

    /**
     * File writer.
     *
     * @param array $data
     * @param string $filePath
     * @param string $delimiter
     * @return bool
     */
    public function write($data, $filePath, $delimiter = ':') {

        if(is_array($data)){
            $content    = implode($delimiter, $data);
        } else {
            $content    = $data;
        }

        if(isset($content)){
            $dirPath = $this->info($filePath, 'dirname');
            if(!empty($dirPath)){
                if(!is_dir($dirPath)){
                    mkdir($dirPath, 0777, true);
                }
            }
            if(!file_exists($filePath)){ touch($filePath); }
            if(file_exists($filePath)){ 
                $fileName        = fopen($filePath, "a+");
                fwrite($fileName, $content."\r\n");
                fclose($fileName);
            }

            return true;
        }

        return false;
    }

    /**
     * File uploader.
     *
     * @param array $files
     * @param string $path
     * @param bool $force
     * @return array
     */
    public function upload($files, $path, $force=false){

        $result = array();

        if(isset($files['name'])){ $files = array($files);}
        if(!is_writable($path)){ return $result;}

        foreach ($files as $file) {

            #Path syntax correction for Windows.
            $tmp_name = str_replace('\\\\', '\\', $file['tmp_name']);
            $file['tmp_name'] = $tmp_name;

            $xtime      = gettimeofday();
            $xdat       = date('d-m-Y g:i:s').$xtime['usec'];
            $ext        = $this->info($file['name'], 'extension');
            if($force){
                $newpath    = $path.md5($xdat).'.'.$ext;
            } else {
                $options = array('unique'=>array('directory'=>$path));
                $newpath    = $path.$this->permalink($this->info($file['name'], 'filename'), $options).'.'.$ext;
            }

            if(move_uploaded_file($file['tmp_name'], $newpath)){
                $result[] = $newpath;
            }

        }

        return $result;
    }

    /**
     * File downloader.
     *
     * @param mixed $links
     * @param array $opt
     * @return array
     */
    public function download($links, $opt = array())
    {

        $result = array();
        $nLinks = array();

        if(empty($links)){
            return $result;
        }

        if(!is_array($links)){
            $links = array($links);
        }

        foreach($links as $link) {

            if($this->is_url($link)){
                if($this->remoteFileSize($link)>1){
                    $nLinks[] = $link;
                }
            }

            if(!$this->is_url($link)){
                if(!strstr($link, '://')){

                    if(file_exists($link)){
                        $nLinks[] = $link;
                    }

                }
            }

        }

        if(count($nLinks) != count($links)){
            return $result;
        }

        $path = '';
        if(!empty($opt['path'])){
            $path .= $opt['path'];

            if(!is_dir($path)){
                mkdir($path, 0777, true);
            }
        } else {
            $path .= './download';
        }

        foreach ($nLinks as $nLink) {

            $destination = $path;

            $other_path = $this->permalink($this->info($nLink, 'basename'));

            if(!is_dir($destination)){
                mkdir($destination, 0777, true);
            }

            if(file_exists($destination.'/'.$other_path)){

                $remote_file = $this->remoteFileSize($nLink);
                $local_file = filesize($destination.'/'.$other_path);

                if($remote_file != $local_file){
                    unlink($destination.'/'.$other_path);
                    copy($nLink, $destination.'/'.$other_path);

                }
            } else {
                copy($nLink, $destination.'/'.$other_path);
            }

            $result[] = $destination.'/'.$other_path;
        }

        return $result;
    }

    /**
     * Content researcher.
     *
     * @param string $left
     * @param string $right
     * @param string $url
     * @param array $options
     * @return array|string
     */
    public function get_contents($left, $right, $url, $options=array()){

        $result = array();

        if($this->is_url($url)) {
            $ch = curl_init();
            curl_setopt($ch,CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, false);

            $defaultHeader = array(
                "Accept-Language:".$_SERVER['HTTP_ACCEPT_LANGUAGE'],
                "Connection: keep-alive",
            );

            if(isset($options['header'])){
                foreach ($options['header'] as $column => $value) {
                    $defaultHeader[] = $column.':'.$value;
                }
            }
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $defaultHeader);
            
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            if($this->sess_set['status_session']){
                if(!$this->sess_set['path_status']){
                    $this->sess_set['path'] = sys_get_temp_dir().'/';
                }

                if(!stristr($this->getSoftware(), 'mic') AND strstr(dirname(__FILE__),'\\')){
                    curl_setopt($ch, CURLOPT_COOKIEJAR, dirname(__FILE__). '/'.$this->sess_set['path'].'cookie.txt');
                    curl_setopt($ch, CURLOPT_COOKIEFILE, dirname(__FILE__). '/'.$this->sess_set['path'].'cookie.txt');
                }else{
                    curl_setopt($ch, CURLOPT_COOKIEJAR, $this->sess_set['path'].'cookie.txt');
                    curl_setopt($ch, CURLOPT_COOKIEFILE, $this->sess_set['path'].'cookie.txt');
                }

            }
            if(!empty($options['post'])){
                curl_setopt($ch, CURLOPT_POST, true);
                if(is_array($options['post'])){
                    $options['post'] = http_build_query($options['post']);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['post']);
            }
            if(!empty($options['referer'])){
                curl_setopt($ch, CURLOPT_REFERER, $options['referer']);
            }
            curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
            $data = curl_exec($ch);
            curl_close($ch);
            
            if(empty($data)){
                $data = file_get_contents($url);
            }
        } else {
            $data = $url;
        }


        if($left === '' AND $right === ''){
            return $data;
        }

        $content = str_replace(array("\n", "\r", "\t"), '', $data);

        if(preg_match_all('/'.preg_quote($left, '/').'(.*?)'.preg_quote($right, '/').'/i', $content, $result)){

            if(!empty($result)){
                return $result[1];
            } else {
                return $result;
            }
        }

        if(is_array($result)){
            if(empty($result[0]) AND empty($result[1])){
                return [];
            }
        }

        return $result;
    }

    /**
     * Absolute path syntax
     *
     * @param string $path
     * @return string
     */
    public function get_absolute_path($path) {
        $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
        $absolutes = array();
        foreach ($parts as $part) {
            if ('.' == $part) continue;
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        $outputdir = implode(DIRECTORY_SEPARATOR, $absolutes);
        if(strstr($outputdir, '\\')){
            $outputdir = str_replace('\\', '/', $outputdir);
        }
        return $outputdir;
    }

    /**
     *
     * Calculates the distance between two points, given their
     * latitude and longitude, and returns an array of values
     * of the most common distance units
     * {m, km, mi, ft, yd}
     *
     * @param float|int|string $lat1 Latitude of the first point
     * @param float|int|string $lon1 Longitude of the first point
     * @param float|int|string $lat2 Latitude of the second point
     * @param float|int|string $lon2 Longitude of the second point
     * @return mixed {bool|array}
     */
    public function distanceMeter($lat1, $lon1, $lat2, $lon2, $type = '') {

        $output = array();

        // koordinat de??illerse false yan??t?? d??nd??r??l??r.
        if(!$this->is_coordinate($lat1, $lon1) OR !$this->is_coordinate($lat2, $lon2)){ return false; }

        // ayn?? koordinatlar belirtilmi?? ise false yan??t?? d??nd??r??l??r.
        if (($lat1 == $lat2) AND ($lon1 == $lon2)) { return false; }

        // dereceden radyana d??n????t??rme i??lemi
        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $lonDelta = $lonTo - $lonFrom;
        $a = pow(cos($latTo) * sin($lonDelta), 2) +
            pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
        $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

        $angle = atan2(sqrt($a), $b);

        $meters     = $angle * 6371000;
        $kilometers = $meters / 1000;
        $miles      = $meters * 0.00062137;
        $feet       = $meters * 3.2808399;
        $yards      = $meters * 1.0936;

        $data = array(
            'm'     =>  round($meters, 2),
            'km'    =>  round($kilometers, 2),
            'mi'    =>  round($miles, 2),
            'ft'    =>  round($feet, 2),
            'yd'    =>  round($yards, 2)
        );

        // e??er ??l???? birimi bo??sa t??m ??l????lerle yan??t verilir
        if(empty($type)){
            return $data;
        }

        // e??er ??l???? birimi string ise ve m??saade edilen bir ??l????yse diziye eklenir
        if(!is_array($type) AND in_array($type, array_keys($data))){
            $type = array($type);
        }

        // e??er ??l???? birimi dizi de??ilse ve m??saade edilen bir ??l???? de??ilse bo?? dizi geri d??nd??r??l??r
        if(!is_array($type) AND !in_array($type, array_keys($data))){
            return $output;
        }

        // g??nderilen t??m ??l???? birimlerinin do??rulu??u kontrol edilir
        foreach ($type as $name){
            if(!in_array($name, array_keys($data))){
                return $output;
            }
        }

        // g??nderilen ??l???? birimlerinin yan??tlar?? haz??rlan??r
        foreach ($type as $name){
            $output[$name] = $data[$name];
        }

        // tek bir ??l???? birimi g??nderilmi?? ise sadece onun de??eri geri d??nd??r??l??r
        if(count($type)==1){
            $name = implode('', $type);
            return $output[$name];
        }

        // birden ??ok ??l???? birimi yan??tlar?? geri d??nd??r??l??r
        return $output;
    }

    /**
     * It is used to run Php codes.
     * 
     * @param string $code
     * @return void
     */
    public function evalContainer($code){

        if($this->is_htmlspecialchars($code)){
            $code = htmlspecialchars_decode($code);
        }
        
        ob_start();
        eval('?>'. $code);
        $output = ob_get_contents();
        ob_end_clean();
        echo $output;
    }

    /**
     * safeContainer
     * 
     * @param string $str
     * @param string|array|null $rule
     * @return string
     */
    public function safeContainer($str, $rule=null){

        if($this->is_htmlspecialchars($str)){
            $str = htmlspecialchars_decode($str);
        }

        $rules = array();
        $rulesList = array('inlinejs', 'inlinecss', 'tagjs', 'tagcss', 'iframe');

        if(!is_null($rule)){
            if(!is_array($rule)){
                $rules = array($rule);
            } else {
                foreach($rule as $rul){
                    if(in_array($rul, $rulesList)){
                        $rules[] = $rul;
                    }
                }
            }
        }

        if(!in_array('inlinejs', $rules)){
            $str = preg_replace('/(<.+?)(?<=\s)on[a-z]+\s*=\s*(?:([\'"])(?!\2).+?\2|(?:\S+?\(.*?\)(?=[\s>])))(.*?>)/i', "$1$3", $str);
        }

        if(!in_array('inlinecss', $rules)){
            $str = preg_replace('/(<[^>]*) style=("[^"]+"|\'[^\']+\')([^>]*>)/i', '$1$3', $str);
        }

        if(!in_array('tagjs', $rules)){
            $str = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $str);
        }

        if(!in_array('tagcss', $rules)){
            $str = preg_replace('/<\s*style.+?<\s*\/\s*style.*?>/si', '', $str);
        }

        if(!in_array('iframe', $rules)){
            $str = preg_replace('/<iframe.*?\/iframe>/i','', $str);
        }

        return $str;
    }

    /**
     * SMS message sender
     * 
     * @param string $message
     * @param string $numbers
     * @param array|null conf
     * @return bool
     */
    public function sms($message, $numbers, $conf=null){
        
        if(!is_null($conf) OR is_array($conf)){
            $this->sms_conf = $conf;
        }

        foreach($this->sms_conf as $brand => $sms_conf){

            switch($brand){
            
                case 'mutlucell':
                    
                    $url = 'https://smsgw.mutlucell.com/smsgw-ws/sndblkex';

                    $charset = '';
                    if(!empty($sms_conf['charset'])){
                        if($sms_conf['charset'] === 'turkish'){
                            $charset = ' charset="'.$sms_conf['charset'].'"';
                        }
                    }
                    
                    $xml_data ='<?xml version="1.0" encoding="UTF-8"?>'.
                    '<smspack ka="'.$sms_conf['ka'].'" pwd="'.$sms_conf['pwd'].'" org="'.$sms_conf['org'].'"'.$charset.'>'.
                    '<mesaj>'.
                    
                        '<metin>'.$message.'</metin>'.
                    
                        '<nums>'.$numbers.'</nums>'.
                    
                    '</mesaj>'.
                    
                    '</smspack>';

                    $options = array(
                        'post'=>$xml_data
                    );

                    $output = $this->get_contents('', '', $url, $options);
                    if(!is_array($output)){
                        if(strstr($output, '$')){
                            return true;
                        } 
                    }
                    return false;

                    break;
            }
        }
    }

    /**
     * Morse encode
     * @param string $str
     * @param array|null $morseDictionary
     * @return string
     */
    public function morse_encode($str, $morseDictionary=array()){
 
        $output = '';    
        if(empty($morseDictionary)){
            $morseDictionary = $this->morsealphabet();
        } else {
            $morseDictionary = $this->morsealphabet($morseDictionary);
        }

        $str = mb_strtolower($str);        
        for ($i = 0; $i < mb_strlen($str); $i++) {
            $key = mb_substr($str, $i, 1);
            if(isset($morseDictionary[$key])){
                $output .= $morseDictionary[$key].' ';
            } else {
                $output .= '# ';
            }
        }
        return trim($output);
    }

    /**
     * Morse decode
     * @param string $morse
     * @param array|null $morseDictionary
     * @return string
     */
    public function morse_decode($morse, $morseDictionary=array()){
 
        $output = '';

        if($morse === ' '){
            return '/';
        }

        if(empty($morseDictionary)){
            $morseDictionary = array_flip($this->morsealphabet());
        } else {
            $morseDictionary = array_flip($this->morsealphabet($morseDictionary));
        }
        
        foreach (explode(' ', $morse) as $value) {
            if(isset($morseDictionary[$value])){
                $output .= $morseDictionary[$value];
            } else {
                $output .= '#';
            }
        }
        return $output;
    }   

    /**
     * String to Binary
     * @param string $string
     * @return int|string
     */
    public function stringToBinary($string){
        $characters = str_split($string);
 
        $binary = [];
        foreach ($characters as $character) {
            $data = unpack('H*', $character);
            $binary[] = base_convert($data[1], 16, 2);
        }
    
        return implode(' ', $binary);  
    }

    /**
     * Binary to String
     * @param int|string
     * @return string
     */
    public function binaryToString($binary){
        $binaries = explode(' ', $binary);
    
        $string = null;
        foreach ($binaries as $binary) {
            $string .= pack('H*', dechex(bindec($binary)));
        }
    
        return $string;    
    }

    /**
     * hexToBinary
     * @param string $hexstr
     * @return string
     */
    public function hexToBinary($hexstr) { 
		$n = strlen($hexstr); 
		$sbin="";   
		$i=0; 
		while($i<$n){       
			$a = substr($hexstr,$i,2);           
			$c = pack("H*",$a); 
			if ($i==0){
				$sbin=$c; 
			} else {
				$sbin.=$c;
			} 
		$i+=2; 
		} 
		return $sbin; 
	}

    /**
     * siyakat_encode
     * @param string $siyakat
     * @param array $miftah
     * @return string
     */
    public function siyakat_encode($siyakat, $miftah){
        if(empty($miftah)){
            return '';
        }
        
        for ($i=0; $i < count($miftah); $i++) { 
            $siyakat = bin2hex($siyakat); // 1
            $siyakat = $this->morse_encode($siyakat, $miftah[$i]); // 2
        }
        return $siyakat;
    }

    /**
     * siyakat_decode
     * @param string $siyakat
     * @param array $miftah
     * @return string
     */
    public function siyakat_decode($siyakat, $miftah){
        if(empty($miftah)){
            return '';
        }
        $miftah = array_reverse($miftah);
        for ($i=0; $i < count($miftah); $i++) { 
            $siyakat = $this->morse_decode($siyakat, $miftah[$i]);
            $siyakat = $this->hexToBinary($siyakat);           
        }
        return $siyakat;
    }

    /**
     * Abort Page
     * @param string $code
     * @param string message
     * @return void
     */
    public function abort($code, $message){
        echo '<!DOCTYPE html><html lang="en"><head> <meta charset="utf-8"> <meta name="viewport" content="width=device-width, initial-scale=1"> <title>'.$code.'</title> <style>html, body{background-color: #fff; color: #636b6f; font-family: Arial, Helvetica, sans-serif; font-weight: 100; height: 100vh; margin: 0;}.full-height{height: 100vh;}.flex-center{align-items: center; display: flex; justify-content: center;}.position-ref{position: relative;}.code{border-right: 2px solid; font-size: 26px; padding: 0 15px 0 15px; text-align: center;}.message{font-size: 18px; text-align: center;}div.buttons{position:absolute;margin-top: 60px;}a{color: #333;font-size: 14px;text-decoration: underline;}a:hover{text-decoration:none;}</style></head><body><div class="flex-center position-ref full-height"> <div class="buttons"><a href="'.$this->page_back.'">Back to Page</a>&nbsp;|&nbsp;<a href="'.$this->base_url.'">Home</a></div><div class="code"> '.$code.' </div><div class="message" style="padding: 10px;"> '.$message.' </div></div></body></html>';
    }

    /**
     * Captcha
     * @param string|int $level
     * @param string|int $length
     * @param string|int $width
     * @param string|int $height
     * @return void
     */
    public function captcha($level=3, $length=8, $width=320, $height=60){
        $_SESSION['captcha'] = $this->generateToken($length);

        $im=imagecreatetruecolor(ceil($width/2),ceil($height/2));
        $navy=imagecolorAllocate($im,0,0,0);
        
        $white=imagecolorallocate($im,255,255,255);
        $pixelColorList = array(
            imagecolorallocate($im, 125, 204, 130), // green
            imagecolorallocate($im, 0, 0, 255), // blue
            imagecolorallocate($im, 179, 179, 0), // yellow
        );

        $pixelColor = $pixelColorList[rand(0, count($pixelColorList)-1)];

        $text_width = imagefontwidth(5) * strlen($_SESSION['captcha']);
        $center = ceil($width / 4);
        $x = $center - ceil($text_width / 2);

        imagestring($im, 5, $x, ceil($height/8), $_SESSION['captcha'], $white);

        if($level != null){
            for($i=0;$i<$level*1000;$i++) {
                imagesetpixel($im,rand()%$width,rand()%$height,$pixelColor);
            }
        }

        ob_start();
        imagepng($im);
        $image_data = ob_get_contents();
        ob_end_clean();
        if(!empty($im)){
            imagedestroy($im);
        }
        ?>
        <div class="form-group">
            <label for="captcha"><img style="height:<?=$height;?>px; min-width:<?=$width;?>px; object-fit: cover;image-rendering:high-quality;image-rendering: auto;image-rendering: crisp-edges;image-rendering: pixelated;" src="data:image/png;base64,<?=base64_encode($image_data);?>"></label><br>
            <input type="text" id="captcha" name="captcha" class="form-control">
        </div>
        <?php

    }

    /**
     * Folder (including subfolders) and file eraser.
     * @param string $paths
     * @return bool
     */
    public function rm_r($paths) {

        if(!is_array($paths)){
            $paths = array($paths);
        }
        
        foreach ($paths as $path) {
            
            if(is_file($path)){
                return unlink($path);
            }
    
            if(is_dir($path)){
    
                $files = array_diff(scandir($path), array('.','..'));
                foreach ($files as $file) {
                    $this->rm_r($path.'/'.$file); 
                }
                return rmdir($path);

            } else {
                return false;
            }

        }
        
        return true;
    }

    /**
     * File and Folder searcher
     * @param string $dir
     * @param string $pattern
     * @param array $matches
     * @return array
     */
    public function ffsearch($dir, $pattern, $matches=array()){
        $dir_list = glob($dir . '*/');
        $pattern_match = glob($dir . $pattern);
    
        $matches = array_merge($matches, $pattern_match);
    
        foreach($dir_list as $directory){
            $matches = $this->ffsearch($directory, $pattern, $matches);
        }
    
        return $matches;
    }
}

