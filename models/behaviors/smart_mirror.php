<?php
/**
 * SmartMirror, CakePHP Behavior
 * 		Mirrors data from one model to another
 *
 *
 * Jacob Morrison <jomorrison gmail com>, http://projects.ofjacob.com/cakephp-smartmirror-behavior
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright	  Copyright 2010 Jacob Morrison <jomorrison gmail com>, http://projects.ofjacob.com
 * @license		  http://www.opensource.org/licenses/mit-license.php The MIT License
 */

class SmartMirrorBehavior extends ModelBehavior {
	/**
	 * Current log level
	 * 0: only critical errors, 1: severe errors, 2: warnings, 3: atypical informational, 4: informational
	 * @var integer
	 */
	var $_log_level = 2;

	/**
	 * Default settings to use for mirrors
	 *
	 * @var array
	 */
	var $_default_settings = array(
		'live' => false,	// do all actions instantly (hasn't been tested all that much)
		'dbConfig' => null, // force a specific database configuration to be used
		'fieldMap' => array(), // customize a fieldmap. like: array('PrimaryModelName.id' => 'MirrorModelName.id', 'PrimaryModelName.email' => 'MirrorModelName.electronic_mail')
		'findOptions' => array(), // if you want to specify options to your primary model's find (perhaps to pull out a subset of entries or get data from related models)
		'allowAlternativeAliases' => false, // in my setup, i didn't want model instances that had different aliases than their model names to do any smart mirroring
	);

	/**
	 * Array of tracked models and their mirrors
	 *
	 * @var array
	 */
	var $_smartMirrors = array();

	/**
	 * Loaded queue of actions
	 *
	 * @var array
	 */
	var $_queue = null;

	/**
	 * Loaded statistics of mirror actions
	 *
	 * @var array
	 */
	var $_stats = null;

	/**
	 * Is this instance part of a model?
	 *
	 * @var boolean
	 */
	var $_with_models = false;

	/**
	 * beforeDelete Cache Data
	 *
	 * @var array
	 */
	var $_before_delete_cache = array();

	/**
	 * Load stats and queue on startup
	 *
	 * @return boolean Result of initiating queue and stats
	 * @access private
	 */
	function __construct() {
		if(!$this->_loadStats() OR !$this->_loadQueue()) { return false; }
		return true;
	}

	/**
	 * Sets the model settings and prepares itself for tracking changes
	 *
	 * @param object $model Passed by setup callback caller
	 * @param array $settings Settings for the behavior, to be merged with defaults
	 * @return boolean Result of setting up new instance
	 * @access public
	 */
	function setup( &$model, $settings = array() ) {
		if(empty($model)) { return true; } // must be floating instance, no need to set anything up
		$this->_with_models = true;
		$this->models[$model->alias] = $model;
		if (!is_array($settings)) {
			$settings = $this->_default_settings;
		}else {
			$settings = array_merge($this->_default_settings, $settings);
		}

		if(empty($settings['allowAlternativeAliases']) AND $settings['allowAlternativeAliases'] === false AND ($model->alias != $model->name)) {
			$this->_log('The alias ('.$model->alias.') was different then the name ('.$model->name.')... not added.', 4);
			return false; // we don't really want models with aliases that are different than their names
		}
		$this->_log('Initializing '.$model->name.'...', 4);
		if(isset($model->smartMirror)) {
			$this->_log(''.$model->name.' will be smart mirrored...', 4);
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

	/**
	 * Save queue and stats as we deconstruct
	 *
	 * @return boolean Result of saving queue and stats
	 * @access private
	 */
	function __destruct() {
		if($this->_with_models === true) {
			$this->_saveQueue();
			$this->_saveStats();
		}
	}

	/**
	 * Especially when we have custom find options, sometimes we don't care about a specific ID
	 *
	 * @param string $model_name The name of the primary model
	 * @param string $mirror_name The name of the secondary model
	 * @param mixed $id The primary key value of the entry in question
	 * @return boolean Result of the question "Do we care about this entry?"
	 * @access private
	 */
	private function _doICare($model_name, $mirror_name, $id) {
		if(empty($id)) {
			$this->_log('Do I Care function ran with empty ID', 1);
			die("DoICare function ran with empty ID");
			return false;
		}
		if(empty($this->_smartMirrors[$model_name])) {
			$this->_log('Action called on a model ('. $model_name .') which hasn\'t been loaded', 1);
			return false;
		}
		//$mirror_model = $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'];
		$primary_model = $this->_smartMirrors[$model_name][$mirror_name]['model'];
		if(empty($primary_model)) {
			$this->_log('The main model failed to load', 0);
		}
		$find_options = $this->_smartMirrors[$model_name][$mirror_name]['findOptions'];
		if(!isset($find_options['conditions'])) { $find_options['conditions'] = array(); }
		$find_options['conditions'][] = array($model_name .'.'. $primary_model->primaryKey => $id);
		$data_count = $primary_model->find('count', $find_options);
		if($data_count > 0) {
			return true;
		}else {
			return false;
		}
	}

	/**
	 * Actually performs a specific action
	 *
	 * @param string $action The action we want to perform
	 * @param string $model_name The primary model name
	 * @param mixed $data Data for the action
	 * @param int $time Unix timestamp of action
	 * @return boolean Result of performing action
	 * @access private
	 */
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
			$uid = null;
			if(isset($data[$model_name][$primary_model->primaryKey])){
				$uid = $data[$model_name][$primary_model->primaryKey];
			}else{
				if($action == 'create' AND !empty($data[$model_name])){
					$raw_uid = $primary_model->find('first', array('contain'=>false, 'conditions'=>$data[$model_name], 'fields' => array($primary_model->primaryKey)));
					if(!empty($raw_uid[$model_name][$primary_model->primaryKey])){
						$uid = $raw_uid[$model_name][$primary_model->primaryKey];
					}
				}
			}
			if(empty($uid)){
				debug($data);exit;
				$this->_log("Action $action ($model_name -> $mirror_name was skipped because it has a corrupt data request.", 1);
				continue;
			}
			
			if($action != 'delete' AND !$this->_doICare($model_name, $mirror_name, $uid)) { // if it is a delete, we already checked
				$this->_log("Action $action ($model_name -> $mirror_name for id#{$uid} was skipped because we don't care about it. (A)", 3);
				continue;
			}
			if($action == 'delete' AND empty($data['doICare'][$mirror_name])){
				$this->_log("Action $action ($model_name -> $mirror_name for id#{$uid} was skipped because we don't care about it. (B)", 3);
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
					if(!empty($mresult)) { $mresult = true; }else { $mresult = false; }
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
					//$mirror_model->$primary_key = $uid;
					$result = $mirror_model->delete($uid);
					$results[] = $result;
					if($result) {
						$this->_log("Successful $action action on $model_name -> $mirror_name, id#$uid", 4);
					}else {
						$this->_log("Error performing $action action on $model_name -> $mirror_name, id#$uid", 2);
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

	/**
	 * Tests to see if a primary model is identical to a mirror model
	 * WARNING: This function has NOT been tested!!!
	 *
	 * @param object $model This should be passed when running it from within the model itself
	 * @param string $model_name The name of the primary model
	 * @param string $mirror_name The name of the mirror model
	 * @return boolean Result of testing if model_name is identical to mirror_name
	 * @access public
	 */
	public function isMirrorIdentical(&$model, $model_name, $mirror_name) {
		if(empty($this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'])) {
			$this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'] =& ClassRegistry::init($mirror_name);
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

			$primary_result = Set::extract('/'. $model_name .'['. $model->primaryKey .'='. $result_id .']', $mirror_results);
			$mirror_result = Set::extract('/'. $mirror_name .'['. $mirror_model->primaryKey .'='. $result_id .']', $mirror_results);
			if(empty($mirror_result)){ return false; };
			foreach($field_map as $primary_key => $mirror_key) {
				$result_primary = Set::classicExtract($primary_results, '0.'. $primary_key);
				$result_mirror = Set::classicExtract($mirror_results, '0.'. $mirror_key);
				if($result_primary !== $result_mirror) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Adds and action to the queue
	 *
	 * @param string $action Name of the action to queue
	 * @param string $model_name Name of the primary model
	 * @param mixed $data Data used in action
	 * @return string Unique identifier of action inside queue
	 * @access private
	 */
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

	/**
	 * Runs the queue of actions
	 *
	 * @return boolean Result of running the queue
	 * @access private
	 */
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
		//debug($this->_queue);exit;
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

	/**
	 * Is the queue empty?
	 *
	 * @return boolean Empty state of queue
	 * @access private
	 */
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

	/**
	 * Makes $mirror_name identical to $model_name
	 *
	 * @param string $model_name The name of the primary model
	 * @param string $mirror_name The name of the mirror model
	 * @param boolean $truncate Empty $mirror_name before (clean dump... no longer smart ;))
	 * @return boolean Result of smart mirror
	 * @access private
	 */
	private function _smartMirror($model_name, $mirror_name, $truncate = false) {
		if(empty($this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'])) {
			$this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'] =& ClassRegistry::init($mirror_name);
		}
		$mirror_model = $this->_smartMirrors[$model_name][$mirror_name]['mirrorModel'];
		$model = $this->_smartMirrors[$model_name][$mirror_name]['model'];
		if(empty($mirror_model) OR empty($model)) {
			$this->_log('Either the main model or the mirrored model failed to load', 0);
		}
		$errors = false;
		$find_options = $this->_smartMirrors[$model_name][$mirror_name]['findOptions'];
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
				$primary_result = Set::extract('/'. $model_name .'['. $model->primaryKey .'='. $result_id .']', $primary_results);
				$mirror_result = Set::extract('/'. $mirror_name .'['. $mirror_model->primaryKey .'='. $result_id .']', $mirror_results);
				if(empty($mirror_result)){ $do_update = true; };
				foreach($field_map as $primary_key => $mirror_key) {
					if($do_update) { continue; }
					$result_primary = Set::classicExtract($primary_result, '0.'. $primary_key);
					$result_mirror = Set::classicExtract($mirror_result, '0.'. $mirror_key);
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

	/**
	 * Builds package from $model_name to be shipped off to $mirror_name
	 *
	 * @param string $model_name The name of the primary model
	 * @param string $mirror_name The name of the mirror model
	 * @param array $primary_data Data to be packaged
	 * @return array Packaged data
	 * @access private
	 */
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

	/**
	 * This clears what we know about the $model_name's mirrors and remirrors all of them
	 *
	 * @param object $model Model to be sent from public caller
	 * @param string $model_name The name of the primary model
	 * @return boolean Result of resetting model
	 * @access public
	 */
	public function resetModel(&$model, $model_name) {
		return $this->_initModel($model_name, true, true);
	}

	/**
	 * Smart mirrors all models and all models' mirrors
	 *
	 * @param boolean $force Force initialization of queue even if it isn't NULL
	 * @return boolean Result of initiating queue
	 * @access private
	 */
	public function smartMirrorAll(&$model, $model_name = false, $force_truncate = false) {
		$results = array();
		if(empty($model_name)) {
			foreach($this->_smartMirrors as $model_name => $mirrors) {
				
				$smart_mirror_result = $this->smartMirrorAll($model, $model_name, $force_truncate);
				$results[] = $smart_mirror_result;
				if($smart_mirror_result){
					$this->_log("$model_name was successfully Smart Mirror'd!", 4);
				}else{
					$this->_log("Failed to Smart Mirror the model $model_name!", 4);
				}
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
		if(empty($results)) { return true; }
		return min($results);
	}

	/**
	 * If we aren't forcing it and we don't have any history of mirroring a model, start fresh with a
	 *		clean smart mirror
	 *
	 * @param string $model_name The name of the primary model
	 * @param boolean $force Force initialization of queue even if it isn't NULL
	 * @param boolean $force_truncate Force the clearing of the mirror data
	 * @return boolean Result of initiating model and all of its mirrors
	 * @access private
	 */
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

	/**
	 * Initiates stats array
	 *
	 * @param boolean $force Force initialization of stats even if it isn't NULL
	 * @return boolean Result of initiating queue
	 * @access private
	 */
	private function _initStats($force = false) {
		if(!is_null($this->_stats) AND !$force) {
			return false;
		}
		$this->_stats = array();
		return $this->_saveStats();
	}

	/**
	 * Loads stats from cache file
	 *
	 * @return boolean Result of loading stats
	 * @access private
	 */
	private function _loadStats() {
		if(!is_null($this->_stats)) {
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

	/**
	 * Saves statistics on push history for model
	 *
	 * @return boolean Result of saving stats
	 * @access private
	 */
	private function _saveStats() {
		if(is_null($this->_stats)) {
		// stats haven't been loaded yet
			return true;
		}
		return Cache::write('stats', $this->_stats, 'smart_mirror');
	}

	/**
	 * Initiates a queue of actions
	 *
	 * @param boolean $force Force initialization of queue even if it isn't NULL
	 * @return boolean Result of initiating queue
	 * @access private
	 */
	private function _initQueue($force = false) {
		if(!is_null($this->_queue) AND !$force) {
			return false;
		}
		$this->_queue = array();
		return $this->_saveQueue();
	}

	/**
	 * Loads a queue from the queue's cache file
	 *
	 * @return boolean Result of loading queue
	 * @access private
	 */
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

	/**
	 * Saves a queue to the queue's cache file
	 *
	 * @return boolean Result of saving queue
	 * @access private
	 */
	private function _saveQueue() {
		if(is_null($this->_queue)) {
		// queue hasn't been loaded yet
			return true;
		}
		return Cache::write('queue', $this->_queue, 'smart_mirror');
	}

	/**
	 * Callback for associated models when creating/updating an entry
	 *
	 * @param boolean $created If the save is a new entry
	 * @return boolean Result of adding command to queue
	 * @access public
	 */
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
								$values[$i][$model->{$type}[$association]['foreignKey']] =	$model->id;
								$toQueue[$with][] = array($with => $values[$i]);
							}
							break;
						case 'hasAndBelongsToMany':
							foreach ($values as $i => $value) {
								$new = array();
								//$new[$association_model_pk] = $association_model_pk_value;
								$new[$model->{$type}[$association]['foreignKey']] =	 $model->id;
								$new[$model->{$type}[$association]['associationForeignKey']] =	$value;
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

	/**
	 * Callback for associated models when deleting an entry
	 *
	 * @return boolean Result of adding delete record command to queue
	 * @access public
	 */
	function beforeDelete( &$model	) {
		if(empty($this->_smartMirrors[$model->alias])) { return true; }
		$model_name = $model->alias;
		if(empty($this->_smartMirrors[$model_name])) {
			$this->_log('Action called on a model ('. $model_name .') which hasn\'t been loaded', 1);
			return false;
		}

		$mirrors = $this->_smartMirrors[$model_name];
		$results = array();
		if(!isset($this->_before_delete_cache[$model_name])){
			$this->_before_delete_cache[$model_name] = array();
		}
		$this->_before_delete_cache[$model_name][$model->id] = array();
		foreach($mirrors as $mirror_name => $settings) {
			$this->_before_delete_cache[$model_name][$model->id][$mirror_name] = $this->_doICare($model_name, $mirror_name, $model->id);
		}
		return true;
	}
	/**
	 * Callback for associated models when deleting an entry
	 *
	 * @return boolean Result of adding command to queue
	 * @access public
	 */
	function afterDelete( &$model  ) {
		if(empty($this->_smartMirrors[$model->alias])) { return true; }
		$result = null;
		$current = $this->_smartMirrors[$model->alias];
		$data = array();
		$data[$model->alias] = array();
		$data[$model->alias][$model->primaryKey] = $model->id;
		$data['doICare'] = $this->_before_delete_cache[$model->alias][$model->id];
		$result = $this->_queueAction('delete', $model->alias, $data);
		return $result;
	}

	/**
	 * Tests if a model's database is connected
	 *
	 * @param string Name of model to test
	 * @return boolean $model_name Connection status of a model's database
	 * @access private
	 */
	private function _isConnected($model_name) {
		if(isset($this->_smartMirrors[$model_name]['dbConfig'])) {
			if(in_array($this->_smartMirrors[$model_name]['dbConfig'], ConnectionManager::sourceList())) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generates a basic field map (when one isn't included) from the primary to secondary model
	 *
	 * @param string $primary Name of primary model
	 * @param string $secondary Name of secondary model
	 * @return array Map of fields
	 * @access private
	 */
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
	 * Our log helper function
	 *
	 * @param string $msg The message we want to pass to the log file
	 * @param int $min_level The minimum log level that we want to report this message
	 * @return boolean Result of saving log
	 * @access private
	 */
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
