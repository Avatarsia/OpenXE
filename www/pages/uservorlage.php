<?php
/*
**** COPYRIGHT & LICENSE NOTICE *** DO NOT REMOVE ****
* 
* Xentral (c) Xentral ERP Sorftware GmbH, Fuggerstrasse 11, D-86150 Augsburg, * Germany 2019
*
* This file is licensed under the Embedded Projects General Public License *Version 3.1. 
*
* You should have received a copy of this license from your vendor and/or *along with this file; If not, please visit www.wawision.de/Lizenzhinweis 
* to obtain the text of the corresponding license version.  
*
**** END OF COPYRIGHT & LICENSE NOTICE *** DO NOT REMOVE ****

Copyright (c) 2022 OpenXE project

*/
?>
<?php

use Xentral\Modules\RoleSurvey\SurveyGateway;
use Xentral\Modules\RoleSurvey\SurveyService;

use Xentral\Components\Database\Exception\QueryFailureException;

class Uservorlage
{
  function __construct($app, $intern = false)
  {
    $this->app=$app;
    if($intern)return;

    $this->app->ActionHandlerInit($this);

    $this->app->ActionHandler("create","UservorlageCreate");
    $this->app->ActionHandler("delete","UservorlageDelete");
    $this->app->ActionHandler("edit","UservorlageEdit");
    $this->app->ActionHandler("list","UservorlageList");
    $this->app->ActionHandler("chrights","UservorlageChangeRights");
    $this->app->ActionHandler("download","UservorlageDownload");


    $this->app->DefaultActionHandler("list");

    //$this->Templates = $this->GetTemplates();

    $this->app->ActionHandlerListen($app);
  }

  public function Install()
  {
  }

  function UservorlageDownload()
  {
    $id = $this->app->Secure->GetGET("id");
    if($id > 0)
    {
      $result = $this->app->DatabaseService->select("SELECT module,action FROM uservorlagerights WHERE vorlage = :id", ['id' => (int)$id]);

      $tmp['bezeichnung'] = $this->app->DatabaseService->selectValue("SELECT bezeichnung FROM uservorlage WHERE id = :id LIMIT 1", ['id' => (int)$id]);
      $tmp['beschreibung'] = $this->app->DatabaseService->selectValue("SELECT beschreibung FROM uservorlage WHERE id = :id LIMIT 1", ['id' => (int)$id]);
      $tmp['rechte']=$result;
      
      header('Content-Type: application/json');
      header('Content-disposition: attachment; filename="'.$tmp['bezeichnung'].'.json"');
      echo json_encode($tmp);
      exit;
    }
  }

  function UservorlageList()
  {
    $this->app->erp->MenuEintrag("index.php?module=uservorlage&action=list","&Uuml;bersicht");
    $this->app->erp->MenuEintrag("index.php?module=uservorlage&action=history","Historie");
    $this->app->erp->MenuEintrag("index.php?module=uservorlage&action=create","Neue Benutzervorlage anlegen");
    $this->app->erp->MenuEintrag("index.php?module=einstellungen&action=list","Zur&uuml;ck zur &Uuml;bersicht");

    $this->app->YUI->TableSearch('USER_TABLE',"usertemplatelist");
    $this->app->Tpl->Parse('PAGE', "uservorlage_list.tpl");

  }

  public function UservorlageDelete(): void
  {
    $id = (int)$this->app->Secure->GetGET('id');

    $benutzervorlage = $this->app->DatabaseService->selectValue("SELECT bezeichnung FROM uservorlage WHERE id = :id LIMIT 1", ['id' => (int)$id]);
    $users = $this->app->DatabaseService->selectValue("SELECT username FROM user WHERE vorlage = :vorlage", ['vorlage' => $benutzervorlage]);
    $prefix = "\"";
	if (!empty($users)) {		
		$usernames = "";
		if (is_array($users)) {
			foreach ($users as $user) {
				$usernames = $usernames.$prefix.$user[0]."\"";
				$prefix = ", \"";
			}
		} else {
			$usernames = $users;
		}

	      $this->app->Tpl->Set('MESSAGE', "<div class=\"error\">{|Benutzervorlage \"$benutzervorlage\" ist in Benutzung durch ".$usernames.".|}</div>");
	} else {
	        $this->app->DatabaseService->execute("DELETE FROM uservorlage WHERE id = :id", ['id' => (int)$id]);
	        $this->app->DatabaseService->execute("DELETE FROM uservorlagerights WHERE vorlage = :id", ['id' => (int)$id]);
	        $this->app->Tpl->Set('MESSAGE', "<div class=\"error\">Die Benutzervorlage \"$benutzervorlage\" wurde gel&ouml;scht.</div>");		
	}    

    $this->UservorlageList();
  }

  function UservorlageCreate()
  {
    $this->app->erp->MenuEintrag("index.php?module=uservorlage&action=list","Zur&uuml;ck zur &Uuml;bersicht");

    $input = $this->GetInput();
    $submit = $this->app->Secure->GetPOST('submituservorlage');

    $error = '';
    $maxlightuser = 0;

    if($submit!='') {

      if($input['bezeichnung']=='') {
	 $error .= 'Geben Sie bitte einen Vorlagennamen ein.<br>';		
      }
      if($this->app->DatabaseService->selectValue("SELECT '1' FROM uservorlage WHERE bezeichnung = :bez LIMIT 1", ['bez' => $input['bezeichnung']]) === '1') {
        $error .= "Es existiert bereits eine Vorlage mit diesem Namen";
      }

      if($error!=='')
        $this->app->Tpl->Set('MESSAGE', "<div class=\"error\">$error</div>");
      else {

        $id = $this->app->erp->CreateBenutzerVorlage($input);

        $msg = $this->app->erp->base64_url_encode("<div class=\"success\">Die Benutzervorlage wurde erfolgreich angelegt.</div>");
        header("Location: index.php?module=uservorlage&action=edit&id=$id&msg=$msg");
        exit;
      }
    }

    $this->SetInput($input);

    $this->app->Tpl->Set('ACTIVCHECKED',"checked");
    $this->app->Tpl->Set('VORRECHTE',"<!--");
    $this->app->Tpl->Set('NACHRECHTE',"-->");
    $extra = '
    if($(\'#hwtoken\').val() == \'4\' || $(\'#hwtoken\').val() == \'5\')
    {
      message = \'\';
    }
    ';
    $this->app->YUI->PasswordCheck('password', 'repassword', 'username', 'submit', $extra);
    $this->app->Tpl->Parse('PAGE', "uservorlage_edit.tpl");
  }

  function UservorlageEdit()
  {
    $id = $this->app->Secure->GetGET('id');
    $this->app->Tpl->Set('ID', $id);

	// JSON Upload
    $jsonvorlage = $_FILES['jsonvorlage']['tmp_name'];
    if($jsonvorlage!="")
    {
        $content = file_get_contents($jsonvorlage);
        $tmp = json_decode($content);
        $neuerechte=0;

        $anzahl = count($tmp->{'rechte'});
        for($i=0;$i<=$anzahl;$i++)
        {
          $tmpmodule  = $tmp->{'rechte'}[$i]->{'module'};
          $tmpaction = $tmp->{'rechte'}[$i]->{'action'};

          if($tmpmodule!="" && $tmpaction!="")
          {
            $check = $this->app->DatabaseService->selectValue(
              "SELECT id FROM uservorlagerights WHERE module = :module AND action = :action AND vorlage = :vorlage LIMIT 1",
              ['module' => $tmpmodule, 'action' => $tmpaction, 'vorlage' => (int)$id]
            );

            if($check > 0)
              $this->app->DatabaseService->execute(
                "UPDATE uservorlagerights SET permission=1 WHERE module = :module AND action = :action AND vorlage = :vorlage LIMIT 1",
                ['module' => $tmpmodule, 'action' => $tmpaction, 'vorlage' => (int)$id]
              );
            else {
              $neuerechte++;
              $this->app->DatabaseService->execute(
                "INSERT INTO uservorlagerights (id,module,action,vorlage,permission) VALUES ('', :module, :action, :vorlage, '1')",
                ['module' => $tmpmodule, 'action' => $tmpaction, 'vorlage' => (int)$id]
              );
            }
          }
        }
        $msg = $this->app->erp->base64_url_encode("<div class=\"success\">Es wurden $neuerechte neue Rechte der Vorlage hinzugefügt!</div>");
        header("Location: index.php?module=uservorlage&action=edit&id=$id&msg=$msg");
        exit;
    }
	// END JSON Upload
    
    $this->app->erp->MenuEintrag("index.php?module=uservorlage&action=edit&id=$id","Details");
    $this->app->erp->MenuEintrag("index.php?module=uservorlage&action=list","Zur&uuml;ck zur &Uuml;bersicht");
    $id = $this->app->Secure->GetGET('id');
    $input = $this->GetInput();
    $submit = $this->app->Secure->GetPOST('submituservorlage');

	// Input GET
    if(is_numeric($id) && $submit!='') {
      $error = '';
      if ($input['bezeichnung']=='') {
	 $error .= 'Geben Sie bitte eine Bezeichnung ein.<br>';
	}
	else {
          
          $this->app->DatabaseService->execute(
            "UPDATE uservorlage SET bezeichnung = :bez, beschreibung = :beschr WHERE id = :id LIMIT 1",
            ['bez' => $input['bezeichnung'], 'beschr' => $input['beschreibung'], 'id' => (int)$id]
          );

          $this->app->Tpl->Set('MESSAGE', "<div class=\"success\">Die Einstellungen wurden erfolgreich &uuml;bernommen.</div>");
        }	
    }	// END Input Get

    $benutzervorlage = $this->app->DatabaseService->selectValue("SELECT bezeichnung FROM uservorlage WHERE id = :id LIMIT 1", ['id' => (int)$id]);
    $beschreibung = $this->app->DatabaseService->selectValue("SELECT beschreibung FROM uservorlage WHERE id = :id LIMIT 1", ['id' => (int)$id]);
    $this->app->Tpl->Add('KURZUEBERSCHRIFT2',$benutzervorlage);
    $this->app->Tpl->Add('BEZEICHNUNG',$benutzervorlage);
    $this->app->Tpl->Add('BESCHREIBUNG',$beschreibung);

    $this->UserRights();      
    $this->app->Tpl->Parse('PAGE', "uservorlage_edit.tpl");
  }

  /**
   * @return array
   */
  public function GetInput(): array
  {
    $input = array();
    $input['bezeichnung'] = $this->app->Secure->GetPOST('bezeichnung');
    $input['beschreibung'] = $this->app->Secure->GetPOST('beschreibung');

    return $input;
  }

  function SetInput($input)
  {
    $this->app->Tpl->Set('BEZEICHNUNG', $input['bezeichnung']);
    $this->app->Tpl->Set('BESCHREIBUNG', $input['beschreibung']);
  }

  function UserRights()
  {
    $id = $this->app->Secure->GetGET('id');
    $template = $this->app->Secure->GetPOST('bezeichnung');
    $copytemplate = $this->app->Secure->GetPOST('copyusertemplate');

    $modules = $this->ScanModules();

    {

      if($template!='') {
        $mytemplate = $this->app->Conf->WFconf['permissions'][$template];
        $permissions = $this->app->DatabaseService->select("SELECT module,action FROM uservorlagerights WHERE vorlage = :id", ['id' => (int)$id]);
        $this->app->DatabaseService->execute("DELETE FROM uservorlagerights WHERE vorlage = :id", ['id' => (int)$id]);

        $modulecount = (!empty($modules)?count($modules):0);
        $curModule = 0;

        foreach($modules as $module=>$actions) {
          $lower_m = strtolower($module);	
          $curModule++;
          $actioncount = (!empty($actions)?count($actions):0);
          for($i=0;$i<$actioncount;$i++) {
            $delimiter = (($curModule<$modulecount || $i+1<$actioncount) ? ', ' : ';');  
            $active = ((isset($mytemplate[$lower_m]) && in_array($actions[$i], $mytemplate[$lower_m])) ? '1' : '0');
            if($active==1){
              $this->app->DatabaseService->execute(
                "INSERT INTO uservorlagerights (vorlage, module, action, permission) VALUES (:vorlage, :module, :action, :permission)",
                ['vorlage' => (int)$id, 'module' => $lower_m, 'action' => $actions[$i], 'permission' => $active]
              );
            }
          }
        }
      }

      if($copytemplate!='') {
        $ok = true;
        if($ok)
        {
          $permissions = $this->app->DatabaseService->select("SELECT module,action FROM uservorlagerights WHERE vorlage = :id", ['id' => (int)$id]);
          $this->app->DatabaseService->execute("DELETE FROM uservorlagerights WHERE vorlage = :id", ['id' => (int)$id]);
          $permissions = $this->app->DatabaseService->select("SELECT module,action FROM userrights WHERE vorlage = :copytemplate", ['copytemplate' => (int)$copytemplate]);
          $this->app->DatabaseService->execute(
            "INSERT INTO uservorlagerights (vorlage, module, action, permission) (SELECT :id, module, action, permission FROM uservorlagerights WHERE vorlage = :copytemplate)",
            ['id' => (int)$id, 'copytemplate' => (int)$copytemplate]
          );
        }
      }
    }

    $dbrights = $this->app->DatabaseService->select("SELECT module, action, permission FROM uservorlagerights WHERE vorlage = :id ORDER BY module", ['id' => (int)$id]);

    $rights = $this->app->Conf->WFconf['permissions'][$group];
    if ((!empty($dbrights)?count($dbrights):0)>0) {
	$rights = $this->AdaptRights($dbrights, $rights, $group);
	}

    $modules = $this->ScanModules();
    $table = $this->CreateTable($id, $modules, $rights);	

    $this->app->Tpl->Set('MODULES', $table);
  }

/*
	Ajax handler
*/
  function UservorlageChangeRights()
  {
    $vorlage = $this->app->Secure->GetGET('b_vorlage');
    $module = $this->app->Secure->GetGET('b_module');
    $action = $this->app->Secure->GetGET('b_action');
    $value = $this->app->Secure->GetGET('b_value');

    if(is_numeric($vorlage) && $module!='' && $action!='' && $value!='') {

      $id = $this->app->DatabaseService->selectValue(
        "SELECT id FROM uservorlagerights WHERE vorlage = :vorlage AND module = :module AND action = :action LIMIT 1",
        ['vorlage' => (int)$vorlage, 'module' => $module, 'action' => $action]
      );

      if(is_numeric($id) && $id>0)
      {
        if($value=="1")
        {
          $this->app->DatabaseService->execute(
            "UPDATE uservorlagerights SET permission=1 WHERE id = :id LIMIT 1",
            ['id' => (int)$id]
          );
        }
        else {
          $this->app->DatabaseService->execute(
            "DELETE FROM uservorlagerights WHERE vorlage = :vorlage AND module = :module AND action = :action",
            ['vorlage' => (int)$vorlage, 'module' => $module, 'action' => $action]
          );
        }
      }
      else
        $this->app->DatabaseService->execute(
          "INSERT INTO uservorlagerights (vorlage, module, action, permission) VALUES (:vorlage, :module, :action, :permission)",
          ['vorlage' => (int)$vorlage, 'module' => $module, 'action' => $action, 'permission' => $value]
        );
    }

    echo $this->app->DatabaseService->selectValue(
      "SELECT permission FROM uservorlagerights WHERE vorlage = :vorlage AND module = :module AND action = :action LIMIT 1",
      ['vorlage' => (int)$vorlage, 'module' => $module, 'action' => $action]
    );
    $this->app->erp->AbgleichBenutzerVorlagen(null, $id, $module, $action); // Update permissions for all users

    exit;
  }

  function AdaptRights($dbarr, $rights) 
  {
    $cnt = (!empty($dbarr)?count($dbarr):0);
    for($i=0;$i<$cnt;$i++) {
      $module = $dbarr[$i]['module'];
      $action = $dbarr[$i]['action'];
      $perm = $dbarr[$i]['permission'];

      if(isset($rights[$module])) {
        if($perm=='1' && !in_array($action, $rights[$module])) 
          $rights[$module][] = $action;

        if($perm=='0' && in_array($action, $rights[$module])) {
          $index = array_search($action, $rights[$module]);
          unset($rights[$module][$index]);
          $rights[$module] = array_values($rights[$module]);
        }
      }else if($perm=='1') $rights[$module][] = $action;
    }
    return $rights;
  }

  function CreateTable($user, $modules, $rights) 
  {
    $maxcols = 6;
    $width = 100 / $maxcols;
    $out = '';
    foreach($modules as $key=>$value) {
      if(strtolower($key) == 'api' || strtolower($key) == 'ajax')continue;
      $out .= "<tr><td class=\"name\">$key</td></tr>";

      $out .= "<tr><td><table class=\"action\">";
      $module = strtolower($key); 
      for($i=0;$i<$maxcols || $i<(!empty($value)?count($value):0);$i++) {
        if($i%$maxcols==0) $out .= "<tr>";

	if (gettype($rights[$module]) == 'array') {

	        if(isset($value[$i]) && in_array($value[$i], $rights[$module])) {
	          $class = 'class="blue"';
	          $active = '1';
	        }else{
	          $class = 'class="grey"';
	          $active = 0;
	        }
	} else {
          $class = 'class="grey"';
          $active = 0;
	}

        $class = ((isset($value[$i])) ? $class : '');

        $action = ((isset($value[$i])) ? strtolower($value[$i]) : '');
        $onclick = ((isset($value[$i])) ? "onclick=\"ChangeRights(this, '$user','$module','$action')\"" : '');
        $out .= "<td width=\"$width%\" $class value=\"$active\" $onclick>{$action}</td>";

        if($i%$maxcols==($maxcols-1)) $out .= "</tr>";
      }
      $out .= "</table></td></tr>";
    }

    return $out;
  }

  /**
   * @param string $page
   * @param array  $actions
   *
   * @return array
   */
  public function getActionsFromFile($page, $actions = [])
  {
    if(substr($page,-8) === '.src.php') {
      return $actions;
    }
    $content = file_get_contents($page);
    $foundItems = preg_match_all('/ActionHandler\([\"|\\\'][[:alnum:]].*[\"|\\\'],/', $content, $matches);
    if($foundItems <= 0) {
      return $actions;
    }
    $action = str_replace(array('ActionHandler("','ActionHandler(\'','",' , '\',' ),'', $matches[0]);
    if(empty($action) || !is_array($action)) {
      return $actions;
    }
    if(isset($actions)) {
      $actionsCount = $action ? count($action) : 0;
      for ($i = 0; $i < $actionsCount; $i++) {
        if(empty($action[$i])) {
          continue;
        }
        $found = false;
        foreach ($actions as $v) {
          if($v == $action[$i]){
            $found = true;
            break;
          }
        }
        if(!$found){
          $actions[] = $action[$i];
        }
      }
    }
    else{
      $actionsCount = $action ? count($action) : 0;
      for ($i = 0; $i < $actionsCount; $i++) {
        $actions[] = $action[$i];
      }
    }
    sort($actions);

    return $actions;
  }

  /**
   * @return array
   */
  public function ScanModules()
  {
    //$files = glob('./pages/*.php');
    $files = glob(__DIR__.'/*.php');
    $encodedActions = [];
    if(method_exists($this->app->erp,'getEncModullist')) {
      $encodedActions = $this->app->erp->getEncModullist();
    }
    if(empty($encodedActions)) {
      $encodedActions = [];
    }
    $modules = array();
    if(empty($files)) {
      return $encodedActions;
    }
    foreach($files as $page) {
      $name = ucfirst(str_replace('_custom','',basename($page,'.php')));
      if(substr($page,-8) === '.src.php') {
        continue;
      }

      $modules[$name] = $this->getActionsFromFile($page, isset($modules[$name]) ? $modules[$name]: []);

      if(!empty($encodedActions[$name]) && is_array($encodedActions[$name]) && count($encodedActions[$name]) > 0) {
        if(isset($modules[$name])) {
          $encodedActionsCount = $encodedActions[$name]?count($encodedActions[$name]):0;
          for($i=0;$i<$encodedActionsCount;$i++) {
            $found = false;
            foreach($modules[$name] as $moduleAction) {
              if($moduleAction == $encodedActions[$name][$i]) {
                $found = true;
                break;
              }
            }
            if(!$found) {
              $modules[$name][] = $encodedActions[$name][$i];
            }
          }
        }
        else{
          $modules[$name] = $encodedActions[$name];
        }
        sort($modules[$name]);
      }
    }

    foreach($modules as $name => $actions) {
      if(empty($actions)) {
        unset($modules[$name]);
      }
    }

    return $modules;	
  }

  function TemplateSelect()
  {
    $options = "<option value=\"\">-- Bitte ausw&auml;hlen --</option>";
    foreach($this->Templates as $key=>$value) {
      if($key!="web")
      $options .= "<option value=\"$key\">".ucfirst($key)."</option>";
     }

     return $options;
  }

  function GetTemplates()
  {
     return $this->app->Conf->WFconf['permissions'];
  }
}
