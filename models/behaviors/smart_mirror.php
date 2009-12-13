<?php
// @todo integrate find options into actual find calls (contains for organizations, for example)
class SmartMirrorBehavior extends ModelBehavior {
    var $_smartMirrors = array();
    var $_queue = null;
    var $_stats = null;
    var $_log_level = 2;
    var $_with_models = false;
    var $_default_settings = array(
    'live' => false,
    'dbConfig' => null,
    'fieldMap' => array(),
    'findOptions' => array(),
    );

    function __construct() {
        if(!$this->_loadStats() OR !$this->_loadQueue()) { return false; }
        return true;
    }

    function setup( &$model, $settings = array() ) {
        if(empty($model)) { return true; } // must be doing an in place thing
        $this->_with_models = true;
        $this->models[$model->alias] = $model;
        if($model->alias != $model->name) { return false; } // we don't want any funky models
        if (!is_array($settings)) {
            $settings = $this->_default_settings;
        }else {
            $settings = array_merge($this->_default_settings, $settings);
        }
        if(isset($model->smartMirror)) {
            if(!is_array($model->smartMirror)) {
                $temp_model = false;
                $this->_smartMirrors[$model->alias] = array();
                $field_map = $this->generateMap($model->alias, $model->smartMirror);

                $this->_smartMirrors[$model->alias][$model->smartMirror] = array('model' => $model, 'mirrorModel' => $temp_model, 'fieldMap' => $field_map) + $settings;
            }else {
                $this->_smartMirrors[$model->alias] = $model->smartMirror;
                foreach($this->_smartMirrors[$model->alias] as $mmodel => $msettings) {
                    $msettings['mirrorModel'] = false;
                    $msettings['model'] = $model;
                    if(!isset($msettings['fieldMap'])) {
                        $msettings['fieldMap'] = $this->generateMap($model->alias, $mmodel);
                    }
                    $this->_smartMirrors[$model->alias][$mmodel] = array_merge($settings, $msettings);
                }
            }
            if(!isset($this->_stats[$model->alias])) {
                $this->_initModel($model->alias);
            }
        }
    }

    function __destruct() {
		/*
foreach($this->_smartMirrors as $model => $mirrors){
			foreach($mirrors as $mirror => $settings){
				unset($this->_smartMirrors[$model][$mirror]['model']);
				unset($this->_smartMirrors[$model][$mirror]['mirrorModel']);
			}
		}
		debug($this->_smartMirrors);exit;
*/
		/*
debug($this->_queue);
		debug($this->_stats);
*/
        if($this->_with_models === true) {
            $this->_saveQueue();
            $this->_saveStats();
        }
    }

    private function _log($msg, $min_level = 2, $force = false) {
        $levels = array('0' => 'Fatal Error', '1' => 'Error', '2' => 'Warning', '3' => 'Notice', '4' => 'Action');
        if($this->_log_level >= $min_level OR $force) {
            $caller = next(debug_backtrace());
            $msg = '('. $levels[$min_level] ." at {$caller['file']}:{$caller['function']}:{$caller['line']}) ". $msg;
            $msg .= "";
            $write = CakeLog::write('smart_mirror', $msg);
            if($min_level == 0) { die($msg); }
            return $write;
        }
        return true;
    }

	private function _doICare($model_name, $mirror_name, $id){
		if(empty($id)){
			$this->_log('Do I Care function ran with empty ID', 1);
			die("DoICare function ran with empty ID");
            return false;
		}
		if(empty($this->_smartMirrors[$model_name])) {
            $this->_log('Action called on a model ('. $model_name .') which hasn\'t been loaded', 1);
            return false;
        }
        $mirror_model = $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'];
        $primary_model = $this->_smartMirrors[$model_name][$mirror_name]['model'];
        if(empty($mirror_model) OR empty($primary_model)) {
            $this->_log('Either the main model or the mirrored model failed to load', 0);
        }
        $find_options = $this->_smartMirrors[$model_name][$mirror_name]['findOptions'];
        if(!isset($find_options['conditions'])) { $find_options['conditions'] = array(); }
        $find_options['conditions'][] = array($model_name .'.'. $primary_model->primaryKey => $id);
        $data_count = $primary_model->find('count', $find_options);
        if($data_count > 0){
        	return true;
       	}else{
       		return false;
       	}
	}
	
    private function _doAction($action, $model_name, $data, $time = null) {
        if(is_null($time)) { $time = time(); }
        if(empty($this->_smartMirrors[$model_name])) {
            $this->_log('Action called on a model ('. $model_name .') which hasn\'t been loaded', 1);
            return false;
        }

        $mirrors = $this->_smartMirrors[$model_name];
        $results = array();
        foreach($mirrors as $mirror_name => $settings) {
            if(empty($this->_smartMirrors[$model_name]['mirrorModel'])) {
                $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'] =& ClassRegistry::init($mirror_name);
            }

            if(!isset($this->_stats[$model_name][$mirror_name]['last_smart_mirror'])) { $this->_stats[$model_name][$mirror_name]['last_smart_mirror'] = 0; }
            if($this->_stats[$model_name][$mirror_name]['last_smart_mirror'] > $time) {
                $this->_log('Action with time of '. $time .' was skipped because there has been a more recent SmartMirror action', 3);
                continue;
            }
            $mirror_model = $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'];
            $primary_model = $this->_smartMirrors[$model_name][$mirror_name]['model'];
            
			if(!$this->_doICare($model_name, $mirror_name, $data[$model_name][$primary_model->primaryKey])){
				$this->_log("Action $action ($model_name -> $mirror_name for id#{$data[$model_name][$primary_model->primaryKey]} was skipped because we don't care about it.", 3);
				continue;
			}
            if(empty($mirror_model) OR empty($primary_model)) {
                $this->_log('Either the main model or the mirrored model failed to load', 0);
            }
            
            $primaryKey = $mirror_model->primaryKey;
            switch($action) {
                case 'create':
                    $packaged_data = $this->_buildDataPackage($model_name, $mirror_name, $data);
                    $mirror_model->create();
                    $mresult = $mirror_model->save($packaged_data);
                    if(!empty($mresult)){ $mresult = true; }else{ $mresult = false; }
                    $results[] = $mresult;
                    if($mresult) {
                        $this->_log("Successful $action action on $model_name -> $mirror_name", 4);
                    }else {
                        $this->_log("Error performing $action action on $model_name -> $mirror_name \n". print_r($data, true), 2);
                    }
                    break;
                case 'update':
                    if(isset($this->_smartMirrors[$model_name][$mirror_name]['reloadObject']) AND $this->_smartMirrors[$model_name][$mirror_name]['reloadObject'] === true) {
                        $find_options = $this->_smartMirrors[$model_name][$mirror_name]['findOptions'];
                        if(!isset($find_options['conditions'])) { $find_options['conditions'] = array(); }
                        $find_options[] = array($model_name .'.'. $primary_model->primaryKey => $data[$model_name][$primary_model->primaryKey]);
                        $data = $primary_model->find('first', $find_options);
                    }
                    if(!empty($data)) {
                        $packaged_data = $this->_buildDataPackage($model_name, $mirror_name, $data);
                        $mirror_model->$primaryKey = $packaged_data[$mirror_name][$primaryKey];
                        unset($packaged_data[$mirror_name][$primaryKey]);
                        $all_good = true;
                        foreach($packaged_data[$mirror_name] as $k => $v) {
                            $save_field_result = $mirror_model->saveField($k, $v, true);
                            if(empty($save_field_result)) { $save_field_result = false; }else { $save_field_result = true; }
                            if($all_good AND !$save_field_result) { $all_good = false; }
                            $results[] = $save_field_result;
                        }
                        if($all_good) {
                            $this->_log("Successful $action action on $model_name -> $mirror_name", 4);
                        }else {
                            $this->_log("Error performing $action action on $model_name -> $mirror_name \n". print_r($data, true), 2);
                        }
                    }else {
                        $this->_log("Error performing $action action on $model_name -> $mirror_name (data was empty!)", 3);
                    }
                    break;
                case 'delete':
                    $primary_key = $mirror_model->primaryKey;
                    $mirror_model->$primary_key = $data;
                    $result = $mirror_model->delete();
                    $results[] = $result;
                    if($result) {
                        $this->_log("Successful $action action on $model_name -> $mirror_name, id# $data", 4);
                    }else {
                        $this->_log("Error performing $action action on $model_name -> $mirror_name, id# $data", 2);
                    }
                    break;
                default:
                    $this->_log("An unknown action ($action) was given", 1);
                    $results[] = false;
                    break;
            }
        }
        if(empty($results)) { return true; }
        return min($results);
    }

    public function isMirrorIdentical(&$model, $model_name, $mirror_name) {
        if(empty($this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'])) {
            $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'] =& ClassRegistry::init($model_name);
        }

        $mirror_model = $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'];
        $model = $this->_smartMirrors[$model_name][$mirror_name]['model'];
        if(empty($mirror_model) OR empty($model)) {
            $this->_log('Either the main model or the mirrored model failed to load', 0);
        }
        $find_options = $this->_smartMirrors[$model_name][$mirror_name]['findOptions'];
        if(!isset($find_options['contain'])) { $find_options['contain'] = false; }
        $primary_results = $model->find('all', $find_options);
        $mirror_results = $mirror_model->find('all', array('contain' => false));
        if(count($primary_results) != count($mirror_results)) { return false; }
        $primary_result_ids = array();
        $field_map = $this->_smartMirrors[$model_name][$mirror_name]['fieldMap'];
        foreach($primary_results as $pr) {
            $result = $pr[$model->alias];
            $result_id = $result[$model->primaryKey];
            $primary_result_ids[] = $result_id;
            foreach($field_map as $primary_key => $mirror_key) {
                $result_primary = Set::classicExtract($primary_results, $result_id.'.'. $primary_key);
                $result_mirror = Set::classicExtract($mirror_results, $result_id.'.'. $mirror_key);
                if($result_primary !== $result_mirror) {
                    return false;
                }
            }
        }
        return true;
    }

    private function _queueAction($action, $model_name, $data) {
        if(is_null($this->_queue)) {
            $this->_loadQueue();
            if(!is_array($this->_queue)) { return false; }
        }
        $uid = uniqid();
        $this->_queue[$uid] = array('action' => $action, 'model' => $model_name, 'data' => $data, 'time' => time());
        if(isset($this->_smartMirrors[$model_name])) {
            $live = false;
            foreach($this->_smartMirrors[$model_name] as $mirror_name => $settings) {
                if($settings['live'] === true) { $live = true; }
            }
            if($live) { $this->runQueue(); }
        }
        return $uid;
    }

    public function runQueue() {
        if(is_null($this->_queue)) {
            $this->_loadQueue();
            if(!is_array($this->_queue)) {
                $this->_log('Queue has been corrupted', 0);
            }
        }
        if(empty($this->_queue)) {
            $this->_log("Queue ran successfully!", 4);
            return true;
        }
        reset($this->_queue);
        $uid = key($this->_queue);
        $next = array_shift($this->_queue);
        if(!isset($next['attempts'])) { $next['attempts'] = 0; }
        $next['attempts']++;
        $this->_queue = array($uid => $next) + $this->_queue;
        if($next['attempts'] > 5) {
            $this->_log('(Attempt: '. $next['attempts'] .') There is a stale task in the queue for SmartMirror', 2);
        }
        if($next['attempts'] > 30) {
            $this->_log('After '. $next['attempts'] .' attempts, SmartMirror has issued a SmartMirror action to check the database', 2);
            array_shift($this->_queue);
            return $this->smartMirrorAll($next['model']);
        }else {
            $result = $this->_doAction($next['action'], $next['model'], $next['data'], $next['time']);

            // if it worked, run the rest. if not, stop trying.
            if($result === true) {
                array_shift($this->_queue);
                return $this->runQueue();
            }
            $this->_log("Queue failed to run!", 3);
            return $result;
        }
    }

    public function isQueueEmpty() {
        if(is_null($this->_queue)) {
            $this->_loadQueue();
            if(!is_array($this->_queue)) {
                $this->_log('Queue has been corrupted', 0);
            }
        }
        if(empty($this->_queue)) { return true; }
        return false;
    }

    private function _smartMirror($model_name, $mirror_name, $truncate = false) {
    // force truncate!
    // $truncate = true;

        if(empty($this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'])) {
            $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'] =& ClassRegistry::init($model_name);
        }
        $mirror_model = $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'];
        $model = $this->_smartMirrors[$model_name][$mirror_name]['model'];
        if(empty($mirror_model) OR empty($model)) {
            $this->_log('Either the main model or the mirrored model failed to load', 0);
        }
        $errors = false;
        $find_options = $this->_smartMirrors[$model_name][$mirror_name]['findOptions'];
        //debug($find_options);
        if(!isset($find_options['contain'])) { $find_options['contain'] = false; }
        $primary_results = $model->find('all', $find_options);
        if($truncate) {
            $tresult = $mirror_model->query('TRUNCATE TABLE '. $mirror_model->tablePrefix.$mirror_model->table);
            $mirror_results = array();
            if(!$tresult) {
            	$this->_log('Failed to truncate table (model: '. $mirror_name.'): '. $mirror_model->tablePrefix.$mirror_model->table, 3);
            }
        }else {
            $mirror_results = $mirror_model->find('all', array('contain' => false));
        }

        $primary_result_ids = array();
        foreach($primary_results as $pr) {
            $result = $pr[$model->alias];
            $result_id = $result[$model->primaryKey];
            $primary_result_ids[] = $result_id;
            $do_update = true;
            $field_map = $this->_smartMirrors[$model_name][$mirror_name]['fieldMap'];
            if(!$truncate) {
                $do_update = false;
                foreach($field_map as $primary_key => $mirror_key) {
                    if($do_update) { continue; }
                    $result_primary = Set::classicExtract($primary_results, $result_id.'.'. $primary_key);
                    $result_mirror = Set::classicExtract($mirror_results, $result_id.'.'. $mirror_key);
                    if($result_primary !== $result_mirror) {
                        $do_update = true;
                    }
                }
            }
            if($do_update) {
                $build_data = $this->_buildDataPackage($model_name, $mirror_name, $pr);
                $mirror_model->create();
                if(!$mirror_model->save($build_data)) {
                    $errors = true;
                    $this->_log('Failed to save updated/new record during a SmartMirror task: '."\n".print_r($build_data, true), 3);
                }
            }
        }

        // delete old ones
        if(!$mirror_model->deleteAll(array($mirror_name .'.'. $model->primaryKey .' NOT' => $primary_result_ids), false)) {
            $errors = true;
            $this->_log('Failed to delete unused records during a SmartMirror task', 3);
        }

        if(!$errors) {
            $this->_stats[$model_name][$mirror_name]['last_smart_mirror'] = time();
        }
        return !$errors;
    }

    private function _buildDataPackage($model_name, $mirror_name, $primary_data = array()) {
        if(empty($primary_data)) { return false; }
        $data = array($mirror_name => array());
        $field_map = $this->_smartMirrors[$model_name][$mirror_name]['fieldMap'];
        foreach($field_map as $primary_key => $mirror_key) {
            $result_primary = Set::classicExtract($primary_data, $primary_key);
            $parts = explode('.', $mirror_key);
            $field_name = array_pop($parts);
            if(!(is_null($result_primary) OR empty($field_name))) {
                $data[$mirror_name][$field_name] = $result_primary;
            }
        }
        return $data;
    }

    public function resetModel(&$model, $model_name) {
        return $this->_initModel($model_name, true, true);
    }

    public function smartMirrorAll(&$model, $model_name = false, $force_truncate = false) {
        $results = array();
        if(empty($model_name)) {
            foreach($this->_smartMirrors as $model_name => $mirrors) {
                $results[] = $this->smartMirrorAll($model, $model_name, $force_truncate);
            }
            return min($results);
        }
        foreach($this->_smartMirrors[$model_name] as $mirror_name => $settings) {
            if(empty($this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'])) {
                $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'] =& ClassRegistry::init($mirror_name);
            }
            $mirror_model = $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'];
            $model = $this->_smartMirrors[$model_name][$mirror_name]['model'];
            if(empty($mirror_model) OR empty($model)) {
                $this->_log('Either the main model or the mirrored model failed to load', 0);
            }
            $mirror_results_count = $mirror_model->find('count');
            $results[] = $this->_smartMirror($model_name, $mirror_name, $force_truncate);
        }
        if(empty($results)){ return true; }
        return min($results);
    }

    private function _initModel($model_name, $force = false, $force_truncate = false) {
        if(isset($this->_stats[$model_name]) AND !$force) {
            return false;
        }
        $this->_stats[$model_name] = array();
        $results = array();
        foreach($this->_smartMirrors[$model_name] as $mirror_name => $settings) {
            $this->_stats[$model_name][$mirror_name] = array();
            if(empty($this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'])) {
                $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'] =& ClassRegistry::init($mirror_name);
            }
            $mirror_model = $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'];
            $model = $this->_smartMirrors[$model_name][$mirror_name]['model'];
            if(empty($mirror_model) OR empty($model)) {
                $this->_log('Either the main model or the mirrored model failed to load', 0);
            }
            $mirror_results_count = $mirror_model->find('count');
            $this->_stats[$model_name][$mirror_name]['initialized'] = time();

            if($mirror_results_count > 0) {
                $results[] = $this->_smartMirror($model_name, $mirror_name, $force_truncate);
            }else {
                $results[] = $this->_smartMirror($model_name, $mirror_name, true);
            }
        }
        return min($results);
    }

    private function _initStats($force = false) {
        if(!is_null($this->_stats) AND !$force) {
            return false;
        }
        $this->_stats = array();
        return $this->_saveStats();
    }

    private function _loadStats() {
        if(!is_null($this->_stats)) {
        // already loaded
            return true;
        }
        $result = Cache::read('stats', 'smart_mirror');
        if($result !== false) {
            $this->_stats = $result;
            return true;
        }else {
            return $this->_initStats();
        }
    }

    private function _saveStats() {
        if(is_null($this->_stats)) {
        // stats haven't been loaded yet
            return true;
        }
        return Cache::write('stats', $this->_stats, 'smart_mirror');
    }

    private function _initQueue($force = false) {
        if(!is_null($this->_queue) AND !$force) {
            return false;
        }
        $this->_queue = array();
        return $this->_saveQueue();
    }

    private function _loadQueue() {
        if(!is_null($this->_queue)) {
        // already loaded
            return true;
        }
        $result = Cache::read('queue', 'smart_mirror');
        if($result !== false) {
            $this->_queue = $result;
            return true;
        }else {
            return $this->_initQueue();
        }
    }

    private function _saveQueue() {
        if(is_null($this->_queue)) {
        // queue hasn't been loaded yet
            return true;
        }
        return Cache::write('queue', $this->_queue, 'smart_mirror');
    }

    function afterSave( &$model, $created  ) {
        if(empty($this->_smartMirrors[$model->alias])) { return true; }

        $current = $this->_smartMirrors[$model->alias];
        $result = null;
        $toQueue = array();
        $associations = $model->getAssociated();
        foreach($model->data as $association => $values) {
            if(isset($values[$association])) {
                $type = $associations[$association];
                $with = $model->{$type}[$association]['with'];
                if(isset($this->_smartMirrors[$with])) {
                    $values = $values[$association];

                    if(!isset($toQueue[$association])) {
                        $toQueue[$with] = array();
                    }
                    switch ($type) {
                        case 'hasOne':
                        //$values[$model->{$type}[$association]][$association_model_pk] = $association_model_pk_value;
                            $values[$model->{$type}[$association]['foreignKey']] = $model->id;
                            $toQueue[$with][] = array($with => $values[$i]);
                            break;
                        case 'hasMany':
                            foreach ($values as $i => $value) {
                            //$values[$model->{$type}[$association]][$association_model_pk] = $association_model_pk_value;
                                $values[$i][$model->{$type}[$association]['foreignKey']] =  $model->id;
                                $toQueue[$with][] = array($with => $values[$i]);
                            }
                            break;
                        case 'hasAndBelongsToMany':
                            foreach ($values as $i => $value) {
                                $new = array();
                                //$new[$association_model_pk] = $association_model_pk_value;
                                $new[$model->{$type}[$association]['foreignKey']] =  $model->id;
                                $new[$model->{$type}[$association]['associationForeignKey']] =  $value;
                                $toQueue[$with][] = array($with => $new);
                            }
                            break;
                    }
                }
            }
        }
        if(!isset($model->data[$model->alias][$model->primaryKey] )) {
            $primaryKey = $model->primaryKey;
            $model->data[$model->alias][$model->primaryKey] = $model->$primaryKey;
        }
        if($created) {
            $result = $this->_queueAction('create', $model->alias, array($model->alias => $model->data[$model->alias]));

            if($result AND !empty($toQueue)) {
                foreach($toQueue as $qmodel_name => $qmodel) {
                    foreach($qmodel as $qobject) {
                        $result = $this->_queueAction('create', $qmodel_name, $qobject);
                    }
                }
            }
        }else {
            $result = $this->_queueAction('update', $model->alias, array($model->alias => $model->data[$model->alias]));
        }
        return $result;
    }

    function beforeDelete( &$model , $cascade ) {
        if(empty($this->_smartMirrors[$model->alias])) { return true; }
        // really?
        return true;
    }

    function afterDelete( &$model  ) {
        if(empty($this->_smartMirrors[$model->alias])) { return true; }
        $result = null;
        $current = $this->_smartMirrors[$model->alias];
        $result = $this->_queueAction('delete', $model->alias, $model->id);
        return $result;
    }

    private function _isConnected($model_name) {
        if(isset($this->_smartMirrors[$model_name]['dbConfig'])) {
            if(in_array($this->_smartMirrors[$model_name]['dbConfig'], ConnectionManager::sourceList())) {
                return true;
            }
        }
        return false;
    }

    private function generateMap($primary, $secondary) {
        $primary_schema = $this->models[$primary]->schema();
        if(empty($primary_schema)) { return false; }
        $map = array();
        foreach(array_keys($primary_schema) as $field) {
            $map[$this->models[$primary]->alias .'.'. $field] = $secondary .'.'. $field;
        }
        return $map;
    }
    /**
     * Gets a reference to the ConnectionManger object instance
     *
     * @return object Instance
     * @access public
     * @static
     */
    function &getInstance() {
        static $instance = array();

        if (!$instance) {
            $instance[0] =& new SmartMirrorBehavior();
        }

        return $instance[0];
    }
}
?>
