<?php
class SQLCondition {
	function __construct($condition) {
		$this->condition = $condition;
	}
	
	function build(){
		return $this->condition;
	}
}

class SQLConditions extends SQLCondition{
	function __construct($logic,array $conditions=array()){
		$this->logic = strtoupper($logic);
		$this->conditions = $conditions;
	}
	
	function addCondition(SQLCondition $condition){
		$this->conditions[] = $condition;
		return $this;
	}
	
	function build(){
		if (!empty($this->conditions)){

			foreach($this->conditions as $condition){
				$where[] = $condition->build();
			}
			//echo JUtility::dump($where);
			$where = '('.implode(') '.$this->logic.' (',$where).')';
			return $where;
		}
		
	}
}

class SQLQuery {
	var $template = '{operation} {fields} {tables} {joins} {conditions} {grouping} {order}'; 
	function __construct($operation,$table,$options=array()){
		$options['conditionLogic'] = isset($options['conditionLogic'])?$options['conditionLogic']:'and';
		$options['fields'] = isset($options['fields'])?$options['fields']:(isset($options['tableAlias'])?$options['tableAlias'].'.*':'*');
		if (isset($options['resource'])) $this->_resource = $options['resource'];
		
		$this->setOperation($operation);
		$this->addTable($table,$options['tableAlias']);
		$this->addField($options['fields']);
		$this->where = new SQLConditions($options['conditionLogic']);
	}
	function setResource($resource){
		$this->_resource = $resource;
	}
	function setOperation($operation){
		$this->operation = strtoupper($operation);
		return $this;
	}
	function setTemplate($template) {
		$this->template = $template;
		return $this;
	}
	function addTable($table,$alias=NULL){
		$this->tables[] = $table;
		if ($alias)
			$tableKey = JFilterOutput::stringURLSafe($table);
			$this->alias[$tableKey] = $alias; 
		return $this;
	}
	function clearTables(){
		unset($this->tables);
		unset($this->alias);
		return $this;
	}
	function addField($field){
		$fields = explode(',',$field);
		if (is_null($this->fields))
			$this->fields = $fields;
		else 
			$this->fields = array_merge($this->fields,$fields);
		return $this;
	}
	function clearFields(){
		unset($this->fields);
		return $this;
	}
	function addOrder($order) {
		$this->orders[] = $order;
		return $this;
	}
	function addGroupField($fieldExp){
		if (is_null($this->grouping))
			$this->grouping = array();
		$this->grouping['fields'][] = $fieldExp;
		return $this;
	}
	function setGroupingDirection($direction){
		if (is_null($this->grouping))
			$this->grouping = array();
		$this->grouping['direction'] = $direction;
		return $this;
	}
	function setGroupingRollup($hasRollup){
		if (is_null($this->grouping))
			$this->grouping = array();
		$this->grouping['rollup'] = $hasRollup;
		return $this;
	}
	function addJoin($direction,$tableReference,$joinCondition){
		if (is_null($this->joins))
			$this->joins = array();
		$this->joins[] = strtoupper($direction).' JOIN '.$tableReference.' ON '.$joinCondition;
		return $this;
	}
	function buildOperation(){
		return $this->operation;
	}
	function buildFields(){
		if (!empty($this->fields))
			return implode(',',$this->fields);
	}
	function buildTables(){
		if (!empty($this->tables)){
			foreach($this->tables as $table){
				$tableKey = JFilterOutput::stringURLSafe($table);
				$alias = $this->alias[$tableKey];
				if ($alias)
					$tables[] = $table.' AS '.$alias;
				else {
					$tables[] = $table;
				}
			}
			return 'FROM '.implode(',',$tables);
		}
			
	}
	function buildJoins(){
		if (!empty($this->joins))
			return implode(' ',$this->joins);
	}
	function buildConditions(){
		$where = trim($this->where->build());
		if (!empty($where))
			return 'WHERE '.$where;
	}
	function buildOrder(){
		if (!empty($this->orders)){
			return 'ORDER BY '.implode(',',$this->orders);
		}
	}
	function buildGrouping(){
		if (!empty($this->grouping['fields'])){
			$grouping = 'GROUP BY '.implode(', ',$this->grouping['fields']);
			if (!empty($this->grouping['direction']))
				$grouping.=' '.$this->grouping['direction'];
			if ($this->grouping['rollup'])
				$grouping.=' '.$this->grouping['rollup'];
			return $grouping;
		}
	}
	function build(){
		$pattern = '/\{(.*?)\}/';
		$query = $this->template;
		if (preg_match_all($pattern,$this->template,$matches)){
			if (!empty($matches[1])){
				foreach($matches[1] as $i=>$placeHolder){
					$search[] = $matches[0][$i];
					$function = 'build'.ucfirst($placeHolder);
					$text[] = $this->$function();
				}
				$query = str_replace($search, $text, $this->template);
			}
		}
		return $query;
	}
	
	function getEscaped( $text, $extra = false )
    {
    	if ($this->_resource) {
    		$result = mysql_real_escape_string( $text, $this->_resource );
	        if ($extra) {
	            $result = addcslashes( $result, '%_' );
	        }
	        return $result;
    	}
        return $text;
    }
	
	function Quote( $text, $escaped = true )
    {
        return '\''.($escaped ? $this->getEscaped( $text ) : $text).'\'';
    }
}

class SQLDelete extends SQLQuery {
	function __construct($table,$options=array()){ 
		parent::__construct('delete',$table,$options);
		$this->setTemplate('{operation} {tables} {conditions}');
	}
}

class SQLInsert extends SQLQuery {
	function __construct($table,$options=array()){ 
		parent::__construct('insert',$table,$options);
		$this->tables = $table;
		$this->setTemplate('{operation} {tables} {fields} {values} {conditions} {duplicatekeyupdate}');
		$this->duplicatekeyupdates = array();
	}
	function addTable($table){
		return $this;
	}
	function bind($obj,$tkey=NULL) {
		unset($this->fields);
		unset($this->values);
		foreach($obj as $key=>$value){
			if (is_string($value))
				$value = $this->quote($value); 
			$this->fields[] = $key;
			$this->values[] = $value;
			if ($tkey) {
				if ($tkey!=$key) {
					$this->duplicatekeyupdates[$key] = $value; 
				}
			}
		}
		return $this;
	}
	function buildTables(){
		if (!empty($this->tables))
			return 'INTO '.$this->tables;
	}
	function buildFields(){
		if (!empty($this->fields))
			return '('.implode(',',$this->fields).')';
	}
	function buildValues(){
		if (!empty($this->values))
			return 'VALUES ('.implode(',',$this->values).')';
	}
	function duplicatekeyupdate($key,$value) {
		if (is_string($value))
			$value = $this->quote($value);
		$this->duplicatekeyupdates[$key] = $value; 
	}
	function buildDuplicatekeyupdate(){
		if (!empty($this->duplicatekeyupdates)) {
			foreach($this->duplicatekeyupdates as $key=>$value){
				$q[] = $key.'='.$value;
			}
			return 'ON DUPLICATE KEY UPDATE '.implode(',',$q); 
		}
	}
}

class SQLInsertList extends SQLInsert {
	function bind($list,$fields) {
		$this->fields  = $fields;
		foreach($list as $item){
			unset($fdata);
			foreach($fields as $field) {
				$value = $item->$field;
				if (is_string($value) || is_null($value))
					$value = $this->quote($value);
				$fdata[] = $value;
			}
			$this->values[] = '('.implode(',',$fdata).')';
		}
		return $this;
	}
	function buildValues(){
		if (!empty($this->values))
			return 'VALUES '.implode(',',$this->values).'';
	}
}
?>