<?php
// vim: set ai ts=4 sw=4 ft=php:
namespace FreePBX\modules;
#[\AllowDynamicProperties]
class Bulkhandler implements \BMO {
	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new \Exception("Not given a FreePBX Object");
		}
		$this->freepbx = $freepbx;
		$this->db = $freepbx->Database;
	}

	public function doConfigPageInit($page) {
	}

	public function install() {

	}
	public function uninstall() {

	}
	public function backup(){

	}
	public function restore($backup){
	}

	public function showPage() {
		if(!empty($_REQUEST['quietmode']) && $_REQUEST['activity'] == 'export') {
			$customfields = $_REQUEST;

			unset($customfields['display']);
			unset($customfields['quietmode']);
			unset($customfields['activity']);
			unset($customfields['export']);
			unset($customfields['extdisplay']);

			$this->export($_REQUEST['export'],$customfields);
		} else {
			$message = ''; 
			$activity = !empty($_REQUEST['activity']) ? $_REQUEST['activity'] : 'export';
			switch($activity) {
				case "validate":
					if(!empty($_FILES)) {
						$ret = $this->uploadFile();
						if(!$ret['status']) {
							$message = $ret['message'];
						} else {
							try {
								$array = $this->fileToArray($ret['localfilename'],$ret['extension']);
								//lets replace the some custom values if we have
								$customf = $_REQUEST;
								//unset Bulkhandler related key => vals
								unset($customf['display']);
								unset($customf['activity']);
								unset($customf['type']);
								unset($customf['extdisplay']);
								// we have the array with  all custom values here ; Note , all possible custom values should be there in the identifier
								$headers = $this->getHeaders($_REQUEST['type'],true);
								$arraynew = [];
								if(!is_array($customf)){
									$customf = [];
								}
								foreach ($array as $key => $value) {
									$row = [];
									foreach($value as $fkey => $val){
										 $fkey = trim((string) $this->removeBomUtf8($fkey));
										 if (array_key_exists($fkey,$customf)){
											 //if any value is there in csv we dont want to override
											$row[$fkey] = $val?trim((string) $val):$customf[$fkey];
										}else {
											$row[$fkey] = trim((string) $val);
										}
									}
									$arraynew[$key] = $row;
								}
								if(isset($_REQUEST['skip_validate']) && $_REQUEST['skip_validate'] =='Yes'){
									$totalnows = count($arraynew);
									return load_view(__DIR__."/views/direct_import.php",["request" => $_REQUEST, "type" => $_POST['type'], "activity" => $activity, "totalnows" => $totalnows, "localfilename" => $ret['localfilename'], 'filename' => $ret['filename'], 'extension' => $ret['extension'], "customfields" => $customf, "headers" => $headers]);
								}else {
									return load_view(__DIR__."/views/validate.php",["type" => $_POST['type'], "activity" => $activity, "imports" => $arraynew, "customfields" => $_REQUEST, "headers" => $headers]);
								}
							} catch(\Exception $e) {
								$activity = "import";
								$message = $e->getMessage();
							}
						}
					}
				//fallthrough if there are no files
				case "import":
					return load_view(__DIR__."/views/import.php",["message" => $message, "activity" => $activity, "customfields" => $this->getCustomField($activity), "types" => $this->getTypes($activity)]);
				break;
				case "export":
				default:
					$activity = 'export';
					return load_view(__DIR__."/views/export.php",["message" => $message, "activity" => $activity, "customfields" => $this->getCustomField($activity), "types" => $this->getTypes($activity)]);
				break;
			}
		}
	}

	public function removeBomUtf8($s){
		if(substr((string) $s,0,3)==chr(hexdec('EF')).chr(hexdec('BB')).chr(hexdec('BF'))){
			return substr((string) $s,3);
		}else{
			return $s;
		}
	}

	private function uploadFile() {
		$temp = sys_get_temp_dir() . "/bhimports";
		if(!file_exists($temp)) {
			if(!mkdir($temp)) {
				return ["status" => false, "message" => sprintf(_("Cant Create Temp Directory: %s"),$temp)];
			}
		}
		$error = $_FILES["import"]["error"];
		switch($error) {
			case UPLOAD_ERR_OK:
				$extension = pathinfo((string) $_FILES["import"]["name"], PATHINFO_EXTENSION);
				$extension = ($extension) ? strtolower((string) $extension) : '';
				if($extension == 'csv') {
					$tmp_name = $_FILES["import"]["tmp_name"];
					$dname = basename((string) $_FILES["import"]["name"]);
					$id = time();
					$name = pathinfo($dname,PATHINFO_FILENAME) . '-' . $id . '.' . $extension;
					move_uploaded_file($tmp_name, $temp."/".$name);
					if(!file_exists($temp."/".$name)) {
						return ["status" => false, "message" => _("Cant find uploaded file"), "localfilename" => $temp."/".$name];
					}
					return ["status" => true, "filename" => $dname, "localfilename" => $temp."/".$name, "id" => $id, "extension" => $extension];
				} else {
					return ["status" => false, "message" => _("Unsupported file format")];
					break;
				}
			break;
			case UPLOAD_ERR_INI_SIZE:
				return ["status" => false, "message" => _("The uploaded file exceeds the upload_max_filesize directive in php.ini")];
			break;
			case UPLOAD_ERR_FORM_SIZE:
				return ["status" => false, "message" => _("The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form")];
			break;
			case UPLOAD_ERR_PARTIAL:
				return ["status" => false, "message" => _("The uploaded file was only partially uploaded")];
			break;
			case UPLOAD_ERR_NO_FILE:
				return ["status" => false, "message" => _("No file was uploaded")];
			break;
			case UPLOAD_ERR_NO_TMP_DIR:
				return ["status" => false, "message" => _("Missing a temporary folder")];
			break;
			case UPLOAD_ERR_CANT_WRITE:
				return ["status" => false, "message" => _("Failed to write file to disk")];
			break;
			case UPLOAD_ERR_EXTENSION:
				return ["status" => false, "message" => _("A PHP extension stopped the file upload")];
			break;
		}
		return ["status" => false, "message" => _("Can Not Find Uploaded Files")];
	}

	public function fileToArray($file, $format='csv') {
		$rawData = [];
		$i = 0;
		switch($format) {
			case 'csv':
				$header = null;
				// ini_set("auto_detect_line_endings", true);
				$handle = fopen($file, "r");
				$headerc = 0;
				//http://php.net/manual/en/filesystem.configuration.php#ini.auto-detect-line-endings
				while ($row = fgetcsv($handle)) {
					if ($header === null) {
						// dump($row);exit;
						$header = ($row[0] != null) ? array_map('strtolower', $row) : '';
						$headerc = $header ? count($header) : 0;
						continue;
					}
					if($headerc != count($row)) {
						throw new \Exception(_("Header row and data row count do not match"));
					}
					if(!empty($row)){
						$rawData[] = array_combine($header, $row);
					}
				}
			break;
			default:
				throw new \Exception(_("Unsupported file format"));
			break;
		}
		
		if(isset($_REQUEST["type"]) && $_REQUEST["type"] == "extensions" && $this->freepbx->Modules->checkStatus("sysadmin")){
			$l = \FreePBX::Sysadmin()->get_sysadmin_extensions_limit();

			foreach($rawData as $ext){
				if(!empty($ext["tech"]) && $ext["tech"] != "virtual"){
					$i++;
				}
			}
			
			if($l > 0 && $l < $i) {
				throw new \Exception(sprintf(_("Too many extensions to import. The limit is: %d physical extensions."),$l)); 
			}			
		}
      
		if(empty($rawData)) {
			throw new \Exception(_("Unable to parse file"));
		}
		return $rawData;
	}

	private function arrayToFile($rawData, $type, $format='csv') {
		switch($format) {
			case 'csv':
			default:
				$filename = ($type ?: 'export') . '.csv';
				$out = fopen('php://output', 'w');
				header('Content-type: application/octet-stream');
				header('Content-Disposition: attachment; filename="' . $filename . '"');
				foreach($rawData as $row) {
					fputcsv($out,  $row);
				}
				fclose($out);
			break;
		}
	}

	public function getHeaders($type,$validation=false) {
		$headers = [];

		$modules = $this->freepbx->Hooks->processHooks($type);
		foreach ($modules as $key => $module) {
			if ($module) {
				$final = [];
				foreach($module as $key1 => $data1) {
					if(!$validation && isset($data1['display']) && !$data1['display']) {
						continue;
					}
					$final[$key1] = $data1;
				}
				if(!empty($final)) {
					$headers = array_merge($headers, $final);
				}
			}
		}

		return $headers;
	}
	public function getCustomField($type) {
		$fields = [];
		$modules = $this->freepbx->Hooks->processHooks($type);
		return $modules;
	}
	public function getTypes($activity='import') {
		$modules = $this->freepbx->Hooks->processHooks();
		$types = [];
		foreach($modules as $key => $module) {
			if(empty($module)) {
				continue;
			}
			switch($activity) {
				case "import":
					foreach($module as $type => $name) {
						if(!isset($types[$key."-".$type])) {
							$types[$key."-".$type] = ["name" => $name['name'], "description" => $name['description'], "mod" => $key, "type" => $type, "active" => !empty($_COOKIE['bulkhandler-display']) ? ($_COOKIE['bulkhandler-display'] == $key."-".$type) : (count($types) == 0), "headers" => $this->getHeaders($type,false)];
						}
					}
				break;
				case "export":
					foreach($module as $type => $name) {
						if(!isset($types[$key."-".$type])) {
							$types[$key."-".$type] = ["name" => $name['name'], "description" => $name['description'], "mod" => $key, "type" => $type, "active" => !empty($_COOKIE['bulkhandler-display']) ? ($_COOKIE['bulkhandler-display'] == $key."-".$type) : (count($types) == 0)];
						}
					}
				break;
			}
		}
		return $types;
	}

	public function ajaxRequest($req, &$setting) {
		return match ($req) {
      "import", "destinationdrawselect", "direct_import" => true,
      "import_finished" => true,
      default => false,
  };
	}

	public function ajaxHandler() {
		$ret = ["status" => true];
		switch ($_REQUEST['command']) {
			case "import":
				if($_POST['type'] == "extensions" && $this->freepbx->Modules->checkStatus("sysadmin")){
					$current_ext= [];
					$import_ext = [];

					$sql 		= 'SELECT DISTINCT user FROM devices ORDER BY user ASC';
					$sth 		= $this->db->prepare($sql);
					$sth->execute();
					$result 	= $sth->fetchAll(\PDO::FETCH_ASSOC);
	
					foreach($result as $ext){
						$current_ext[] = $ext["user"];
					}
	
					foreach($_POST['imports'] as $key => $val){
						if($key == "tech" && $val != "virtual"){
							$import_ext[] = $_POST['imports']["extension"];
						}
					}

					$sys_limit = \FreePBX::Sysadmin()->get_sysadmin_extensions_limit('remaining');

					switch($_POST["replace"]){
						case "0":
							if(array_search($_POST['imports']["extension"],$current_ext) !== false){
								return ["status" => false, "message" => _("Already exists")];
							}
							if($sys_limit < 1){
								return ["status" => false, "message" => "over"];
							}
	
							break;
						case "1":	
							if($sys_limit < 1 && array_search($_POST['imports']["extension"],$current_ext) === false){
								return ["status" => false, "message" => "over"];
							}
							break;
					}
				}
				$ret = $this->import($_POST['type'], [$_POST['imports']], (!empty($_POST['replace']) ? true : false));
			break;
			case "destinationdrawselect":
				global $active_modules;
				$active_modules = $this->freepbx->Modules->getActiveModules();
				$this->freepbx->Modules->getDestinations();
				$ret = ["status" => true, "destid" => $_POST['destid'], "html" => drawselects($_POST['value'],$_POST['id'], false, false)];
			break;
			case "direct_import":
				$ret = $this->readtempfile_for_import_status($_REQUEST['filename']);
				return $ret;
			break;
			case "import_finished":
				$ret = $this->importFinished($_POST['type']);
				return $ret;
			break;
			case "import_finished":
				$ret = $this->importFinished($_POST['type']);
				return $ret;
			break;
		}
		return $ret;
	}
	/*Read the temp file for bulk upload progress status*/
	public function readtempfile_for_import_status($filename){
		$return = [];
		if(!file_exists($filename)){
			$return['status'] = 'DONE';
			$return['COUNT'] = '';
			return $return;
		}else {
			$file = fopen($filename,"r");
			while(! feof($file)){
				$string = fgets($file);
				if(str_contains($string, '=')){
					$stringarr = explode('=',$string);
					$return[$stringarr[0]] = trim($stringarr[1],PHP_EOL);;
				}
			}
			fclose($file);
			// dbug(print_r($return,true));
			return $return;
			
		}
	}
			
	/**
	 * direct_import  sending all the rows to the module to handle it 
	 * @param  string $type            The type of data import
	 * @param  array $fullData         array of data to import
	 * @param  $request is the other paramters (custom fileds anything if they have)
	 */
	public function direct_import($type, $fullData, $request,$file) {
			try {
				$methods = $this->freepbx->Hooks->returnHooks();
			} catch(\Exception $e) {
				return ["status" => false, "message" => $e->getMessage()];
			}
			$methods = is_array($methods) ? $methods : [];
			foreach($methods as $method) {
				$mod = $method['module'];
				$meth = $method['method'];
				$ret = \FreePBX::$mod()->$meth($type, $fullData, $request,$file);
				if($ret['status'] === false) {
					return ["status" => false, "message" => sprintf("There was an error in %s, message: %s", $mod, $ret['message'])];
				}
			}
			return ["status" => true];
		
		return ["status" => false, "message" => $val['message']];
	}
	/**
	 * Import Data
	 * @param  string $type            The type of data import
	 * @param  array $rawData         Raw array of data to import
	 * @param  bool $replaceExisting Replace or Update existing data
	 */
	public function import($type, $rawData, $replaceExisting = false) {
		$val = $this->validate($type, $rawData);
		if($val['status'] === true){
			try {
				$methods = $this->freepbx->Hooks->returnHooks();
			} catch(\Exception $e) {
				return ["status" => false, "message" => $e->getMessage()];
			}
			$methods = is_array($methods) ? $methods : [];
			foreach($methods as $method) {
				$mod = $method['module'];
				$meth = $method['method'];
				$ret = \FreePBX::$mod()->$meth($type, $rawData, $replaceExisting);
				if(isset($ret['status']) && $ret['status'] === false) {
					return ["status" => false, "message" => sprintf("There was an error in %s, message: %s", $mod, $ret['message'])];
				}
			}
			return ["status" => true];
		}
		return ["status" => false, "message" => $val['message']];
	}

	/**
	 * Export Data
	 * @param  string $type The type of export
	 */
	public function export($type,$customfields) {
		if((is_countable($customfields) ? count($customfields) : 0)> 0) { // we have custom fields
			$customfields['moduletype'] = $type;
			$new_var_type = $customfields;
		}else {
			$new_var_type = $type;
		}
		$time_start = microtime(true);
		$modules = $this->freepbx->Hooks->processHooks($new_var_type);
		$rows = [];
		$headers = [];
		foreach($modules as $key => $module) {
			if(empty($module)) {
				continue;
			}
			foreach($module as $items) {
				foreach(array_keys($items) as $h) {
					if(!in_array($h,$headers)) {
						$headers[] = $h;
					}
				}
			}
		}

		foreach($modules as $module) {
			if(empty($module)) {
				continue;
			}
			foreach($module as $id => $items) {
				if(empty($rows[$id])) {
					$rows[$id] = array_fill(0, count($headers), "");
				}
				foreach($items as $key => $value) {
					$d = array_search($key,$headers);
					$rows[$id][$d] = $value;
				}
			}
		}
		array_unshift($rows,$headers);
		//dbug('Total execution time in seconds: ' . (microtime(true) - $time_start));
		// To work with  existing modules
		if(is_array($new_var_type)){
			$type_new = $new_var_type['moduletype'];
		} else {
			$type_new = $type;
		}
		$this->arrayToFile($rows, $type_new, 'csv');
	}

	public function validate($type, $rawData) {
		$methods = $this->freepbx->Hooks->processHooks($type, $rawData);
		$methods = is_array($methods) ? $methods : [];
		foreach($methods as $key => $method) {
			if(isset($method['status']) && $method['status'] === false){
				return ["status" => false, "message" => $method['message']];
				continue;
			}
		}
		return ["status" => true];
	}
	public function getActionBar($request) {
		$buttons = [];
		switch($request['activity']) {
			case "validate":
				$buttons = ['import' => ['name' => 'import', 'id' => 'import', 'value' => _('Import')], 'cancel' => ['name' => 'cancel', 'id' => 'cancel', 'value' => _('Cancel')]];
			break;
		}
		return $buttons;
	}

	/**
	 * Import Finished
	 * @param  string $type            The type of data import
	 */
	public function importFinished($type) {
		try {
			$methods = $this->freepbx->Hooks->returnHooks();
		} catch(\Exception $e) {
			return ["status" => false, "message" => $e->getMessage()];
		}
		$methods = is_array($methods) ? $methods : [];
		foreach($methods as $method) {
			$mod = $method['module'];
			$meth = $method['method'];
			$ret = \FreePBX::$mod()->$meth($type);
			if(isset($ret['status']) && $ret['status'] === false) {
				return ["status" => false, "message" => sprintf("There was an error in %s, message: %s", $mod, $ret['message'])];
			}
		}
		return ["status" => true];
	}
}
